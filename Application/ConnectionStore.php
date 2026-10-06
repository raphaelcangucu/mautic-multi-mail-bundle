<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Private connection registry, shared by the admin and native mail transport. */
final class ConnectionStore
{
    public const PROVIDERS = [
        'smtp' => ['label' => 'SMTP', 'settings' => ['host', 'port', 'encryption', 'username'], 'secrets' => ['password']],
        'ses' => ['label' => 'Amazon SES · API', 'settings' => ['region'], 'secrets' => ['access_key', 'secret_key']],
        'resend' => ['label' => 'Resend · API', 'settings' => [], 'secrets' => ['api_key']],
        'mailgun' => ['label' => 'Mailgun · API', 'settings' => ['domain', 'region'], 'secrets' => ['api_key']],
        'sendgrid' => ['label' => 'SendGrid · API', 'settings' => [], 'secrets' => ['api_key']],
        'postmark' => ['label' => 'Postmark · API', 'settings' => [], 'secrets' => ['api_key']],
        'brevo' => ['label' => 'Brevo · API', 'settings' => [], 'secrets' => ['api_key']],
        'mailjet' => ['label' => 'Mailjet · API', 'settings' => [], 'secrets' => ['api_key', 'secret_key']],
        'mailersend' => ['label' => 'MailerSend · API', 'settings' => [], 'secrets' => ['api_key']],
        'mandrill' => ['label' => 'Mandrill · Mailchimp Transactional', 'settings' => [], 'secrets' => ['api_key']],
        'sparkpost' => ['label' => 'SparkPost · API', 'settings' => ['region'], 'secrets' => ['api_key']],
        'smtp2go' => ['label' => 'SMTP2GO · API', 'settings' => [], 'secrets' => ['api_key']],
        'native' => ['label' => 'Mautic · transporte nativo / DSN', 'settings' => [], 'secrets' => ['dsn']],
    ];

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    public function overview(): array
    {
        return $this->transaction(fn(array $data): array => $this->publicData($data));
    }

    /** Internal server use only; never expose this result through a controller. */
    public function transportChain(string $id, ?int $revision = null): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new \InvalidArgumentException('Conexão de envio inválida.');
        }

        return $this->transaction(function (array $data) use ($id, $revision): array {
            if ($revision !== null) { $this->checkRevision($data, $revision); }
            $result = [];
            $next = $id;
            $seen = [];
            while ($next !== '') {
                if (!isset($data['connections'][$next]) || isset($seen[$next])) {
                    throw new \RuntimeException('Mail connection unavailable.');
                }
                $connection = $data['connections'][$next];
                $this->validateProvider($connection);
                $result[] = $connection;
                $seen[$next] = true;
                $next = $connection['fallback'];
            }

            return $result;
        });
    }

    public function save(array $input, int $revision, int $actor): array
    {
        return $this->transaction(function (array &$data) use ($input, $revision, $actor): array {
            $this->checkRevision($data, $revision);
            $id = $input['id'] ?? '';
            if (!is_string($id) || ($id !== '' && !preg_match('/^[a-f0-9]{32}$/D', $id))) {
                throw new \InvalidArgumentException('Conexão inválida.');
            }
            if ($id !== '' && !isset($data['connections'][$id])) {
                throw new \InvalidArgumentException('A conexão não existe mais.');
            }
            if ($id === '' && count($data['connections']) >= 100) {
                throw new \InvalidArgumentException('Limite de 100 conexões atingido.');
            }
            $id = $id ?: bin2hex(random_bytes(16));
            $previous = $data['connections'][$id] ?? null;
            if (!is_array($input['settings'] ?? []) || !is_array($input['secrets'] ?? [])) {
                throw new \InvalidArgumentException('Dados de acesso inválidos.');
            }
            $provider = $this->text($input['provider'] ?? null, 'Provedor', 30);
            if (!isset(self::PROVIDERS[$provider]) || ($previous && $previous['provider'] !== $provider)) {
                throw new \InvalidArgumentException('Para trocar o provedor, crie outra conexão.');
            }
            $connection = [
                'id' => $id, 'provider' => $provider,
                'name' => $this->text($input['name'] ?? null, 'Nome', 100),
                'from_email' => $this->email($input['from_email'] ?? null, 'E-mail do remetente'),
                'from_name' => $this->text($input['from_name'] ?? null, 'Nome do remetente', 150),
                'reply_to' => empty($input['reply_to']) ? '' : $this->email($input['reply_to'], 'Responder para'),
                'fallback' => $input['fallback'] ?? '', 'settings' => [], 'secrets' => [],
                'updated_by' => $actor, 'updated_at' => gmdate(DATE_ATOM),
            ];
            if (!is_string($connection['fallback'])) {
                throw new \InvalidArgumentException('Fallback inválido.');
            }
            foreach (self::PROVIDERS[$provider]['settings'] as $key) {
                $connection['settings'][$key] = $this->text($input['settings'][$key] ?? null, 'Configuração do provedor', 253);
            }
            foreach (self::PROVIDERS[$provider]['secrets'] as $key) {
                $value = $input['secrets'][$key] ?? '';
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('Credencial inválida.');
                }
                // Empty password fields preserve an existing secret; public views never contain it.
                $value = $value === '' ? ($previous['secrets'][$key] ?? '') : $value;
                $connection['secrets'][$key] = $this->text($value, 'Credencial', $key === 'dsn' ? 16384 : 4096, false);
            }
            $this->validateProvider($connection);
            $data['connections'][$id] = $connection;
            $this->validateGraph($data['connections']);
            ++$data['revision'];

            return $this->publicData($data);
        }, true);
    }

    public function remove(string $id, int $revision): array
    {
        return $this->transaction(function (array &$data) use ($id, $revision): array {
            $this->checkRevision($data, $revision);
            if (!isset($data['connections'][$id])) {
                throw new \InvalidArgumentException('Conexão não encontrada.');
            }
            foreach ($data['connections'] as $connection) {
                if ($connection['fallback'] === $id) {
                    throw new \InvalidArgumentException('Troque o fallback das conexões que usam esta antes de removê-la.');
                }
            }
            unset($data['connections'][$id]);
            ++$data['revision'];

            return $this->publicData($data);
        }, true);
    }

    private function validateProvider(array $connection): void
    {
        $settings = $connection['settings'];
        switch ($connection['provider']) {
            case 'native':
                NativeDsnGuard::validate($connection['secrets']['dsn']);
                if ($connection['fallback'] !== '') {
                    throw new \InvalidArgumentException('Defina as reservas no próprio DSN nativo, usando failover(...).');
                }
                break;
            case 'smtp':
                if (!preg_match('/^(?=.{1,253}$)[a-z0-9]+(?:[a-z0-9.-]*[a-z0-9])?$/Di', $settings['host'])
                    || !in_array($settings['port'], ['465', '587', '2525'], true)
                    || $settings['encryption'] !== ($settings['port'] === '465' ? 'ssl' : 'tls')) {
                    throw new \InvalidArgumentException('Use SMTP com TLS: porta 465/SSL ou 587, 2525/STARTTLS.');
                }
                break;
            case 'ses':
                if (!preg_match('/^[a-z]{2}-(?:[a-z]+-)+[0-9]+$/D', $settings['region'])) {
                    throw new \InvalidArgumentException('Informe a região AWS, por exemplo sa-east-1.');
                }
                break;
            case 'mailgun':
                if (!preg_match('/^[a-z0-9]+(?:[a-z0-9.-]*[a-z0-9])?$/Di', $settings['domain'])
                    || !in_array($settings['region'], ['us', 'eu'], true)) {
                    throw new \InvalidArgumentException('Confira o domínio Mailgun e a região US ou EU.');
                }
                break;
            case 'resend':
                if (!preg_match('/^re_[A-Za-z0-9_-]{20,}$/D', $connection['secrets']['api_key'])) {
                    throw new \InvalidArgumentException('Chave Resend inválida.');
                }
                break;
            case 'sparkpost':
                if (!in_array($settings['region'], ['us', 'eu'], true)) {
                    throw new \InvalidArgumentException('Escolha a região SparkPost US ou EU.');
                }
                break;
        }
    }

    private function validateGraph(array $connections): void
    {
        foreach ($connections as $id => $connection) {
            $seen = [$id => true];
            $next = $connection['fallback'];
            while ($next !== '') {
                if (!isset($connections[$next])) {
                    throw new \InvalidArgumentException('Escolha uma conexão existente como fallback.');
                }
                if ($connections[$next]['provider'] === 'native') {
                    throw new \InvalidArgumentException('Transportes nativos mantêm sua própria cadeia de envio e não podem ser reserva de conexões Multi Mail.');
                }
                if (isset($seen[$next])) {
                    throw new \InvalidArgumentException('O fallback cria um ciclo. Escolha outra conexão.');
                }
                $seen[$next] = true;
                $next = $connections[$next]['fallback'];
            }
        }
    }

    private function publicData(array $data): array
    {
        $public = [];
        foreach ($data['connections'] as $id => $connection) {
            $connection['secret_configured'] = array_fill_keys(array_keys($connection['secrets']), true);
            unset($connection['secrets']);
            $connection['chain'] = [];
            $next = $connection['fallback'];
            $seen = [$id => true];
            while ($next !== '' && !isset($seen[$next]) && isset($data['connections'][$next])) {
                $connection['chain'][] = ['id' => $next, 'name' => $data['connections'][$next]['name']];
                $seen[$next] = true;
                $next = $data['connections'][$next]['fallback'];
            }
            $public[] = $connection;
        }

        return ['revision' => $data['revision'], 'connections' => $public];
    }

    private function checkRevision(array $data, int $revision): void
    {
        if ($revision !== $data['revision']) {
            throw new \DomainException('As conexões foram alteradas em outra janela. Recarregue a página antes de salvar.');
        }
    }

    private function text(mixed $value, string $label, int $max, bool $trim = true): string
    {
        if (!is_string($value) || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException($label.': valor inválido.');
        }
        $value = $trim ? trim($value) : $value;
        if ($value === '') {
            throw new \InvalidArgumentException($label.': preenchimento obrigatório.');
        }

        return $value;
    }

    private function email(mixed $value, string $label): string
    {
        $value = $this->text($value, $label, 254);
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException($label.': endereço inválido.');
        }

        return $value;
    }

    private function transaction(callable $action, bool $write = false): array
    {
        $resolvedProject = realpath($this->projectDir) ?: $this->projectDir;
        $parent = dirname($resolvedProject);
        // Atomic-release deployments keep persistent data beside releases, under shared.
        // A direct checkout uses a private sibling directory outside its document root.
        $directory = basename($parent) === 'releases' ? dirname($parent).'/shared/inbox-mail-private'
            : $parent.'/'.basename($resolvedProject).'-multimail-private';
        clearstatcache(true);
        if (!file_exists($directory) && !is_link($directory)) {
            $oldMask = umask(0077);
            try { @mkdir($directory, 0700); } finally { umask($oldMask); }
        }
        $this->privatePath($directory, true);
        $lockPath = $directory.'/connections.lock';
        $this->privatePath($lockPath);
        $oldMask = umask(0077);
        try { $lock = @fopen($lockPath, 'c+b'); } finally { umask($oldMask); }
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Private mail configuration unavailable.');
        }
        try {
            $this->privatePath($lockPath);
            $path = $directory.'/connections.json';
            $this->privatePath($path);
            $data = ['version' => 1, 'revision' => 0, 'connections' => []];
            if (file_exists($path)) {
                try { $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { throw new \RuntimeException('Private mail configuration invalid.'); }
                if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_int($data['revision'] ?? null)
                    || !is_array($data['connections'] ?? null)) {
                    throw new \RuntimeException('Private mail configuration invalid.');
                }
                $this->validateGraph($data['connections']);
            }
            $result = $action($data);
            if ($write) {
                $temporary = $path.'.tmp-'.bin2hex(random_bytes(8));
                $oldMask = umask(0077);
                try { $handle = @fopen($temporary, 'xb'); } finally { umask($oldMask); }
                if (!$handle) { throw new \RuntimeException('Private mail configuration unavailable.'); }
                try {
                    $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle) || !fsync($handle)) {
                        throw new \RuntimeException('Private mail configuration unavailable.');
                    }
                    fclose($handle); $handle = null;
                    $this->privatePath($path);
                    if (!rename($temporary, $path)) { throw new \RuntimeException('Private mail configuration unavailable.'); }
                } finally {
                    if (is_resource($handle)) { fclose($handle); }
                    if (file_exists($temporary)) { unlink($temporary); }
                }
            }

            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function privatePath(string $path, bool $directory = false): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && (($directory ? !is_dir($path) : !is_file($path)) || (fileperms($path) & 0077) !== 0))
            || ($directory && !is_dir($path))) {
            throw new \RuntimeException('Private mail configuration unavailable.');
        }
    }
}
