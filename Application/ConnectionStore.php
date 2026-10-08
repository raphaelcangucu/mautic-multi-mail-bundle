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
        if ($id !== 'auto' && !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new \InvalidArgumentException('Conexão de envio inválida.');
        }

        return $this->transaction(function (array $data) use ($id, $revision): array {
            if ($revision !== null) { $this->checkRevision($data, $revision); }
            if ($id === 'auto') {
                $pool = array_values(array_filter($data['connections'], fn(array $connection): bool => $connection['provider'] !== 'native' && $connection['pool_enabled']));
                usort($pool, fn(array $a, array $b): int => [$a['priority'], $a['id']] <=> [$b['priority'], $b['id']]);
                foreach ($pool as $connection) { $this->validateProvider($connection); }
                return $pool;
            }
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
                'hourly_limit' => $this->integer($input['hourly_limit'] ?? ($previous['hourly_limit'] ?? 0), 1000000),
                'priority' => $this->integer($input['priority'] ?? ($previous['priority'] ?? 100), 9999),
                'pool_enabled' => $this->boolean($input['pool_enabled'] ?? ($previous['pool_enabled'] ?? ($provider !== 'native'))),
                'quota_group' => $input['quota_group'] ?? ($previous['quota_group'] ?? ''),
                'updated_by' => $actor, 'updated_at' => gmdate(DATE_ATOM),
            ];
            if (!is_string($connection['quota_group']) || !preg_match('/^[a-z0-9_-]{0,64}$/D', $connection['quota_group'])) {
                throw new \InvalidArgumentException('mautic.multimail.quota.invalid_group');
            }
            if ($provider === 'native' && ($connection['hourly_limit'] !== 0 || $connection['pool_enabled'] || $connection['quota_group'] !== '')) {
                throw new \InvalidArgumentException('mautic.multimail.quota.native_invalid');
            }
            if ($previous && $previous['quota_group'] !== $connection['quota_group']) {
                $usage = (new HourlyQuota($this->projectDir))->snapshot([$previous]);
                $previousUsage = $usage[$id];
                if ($previousUsage['used'] > 0) {
                    throw new \InvalidArgumentException('mautic.multimail.quota.group_busy');
                }
            }
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
        $usage = (new HourlyQuota($this->projectDir))->snapshot(array_values($data['connections']));
        foreach ($data['connections'] as $id => $connection) {
            $connection['secret_configured'] = array_fill_keys(array_keys($connection['secrets']), true);
            unset($connection['secrets']);
            $connection['hourly'] = $usage[$id];
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

    /** Reserve under the configuration lock, then the quota lock; no network operation holds either. */
    public function reserve(string $id, int $recipients): array
    {
        return $this->transaction(function (array $data) use ($id, $recipients): array {
            $connection = $data['connections'][$id] ?? throw new \RuntimeException('Mail connection unavailable.');
            if ($connection['provider'] === 'native') { throw new \InvalidArgumentException('Native quotas are not wrapped.'); }
            return (new HourlyQuota($this->projectDir))->reserve($connection, $recipients,
                HourlyQuota::effectiveLimit($connection, array_values($data['connections'])));
        });
    }

    public function finishReservation(string $token, string $outcome): void
    {
        (new HourlyQuota($this->projectDir))->finish($token, $outcome);
    }

    private function integer(mixed $value, int $maximum): int
    {
        if ((!is_int($value) && (!is_string($value) || !ctype_digit($value))) || strlen((string) $value) > 7 || (int) $value < 0 || (int) $value > $maximum) {
            throw new \InvalidArgumentException('mautic.multimail.quota.invalid_number');
        }
        return (int) $value;
    }

    private function boolean(mixed $value): bool
    {
        if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) { throw new \InvalidArgumentException('mautic.multimail.quota.invalid_number'); }
        return in_array($value, [true, 1, '1'], true);
    }

    private function transaction(callable $action, bool $write = false): array
    {
        return PrivateStorage::transaction($this->projectDir, 'connections.json', ['version' => 1, 'revision' => 0, 'connections' => []],
            function (array &$data) use ($action): array {
                if (!is_int($data['revision'] ?? null) || !is_array($data['connections'] ?? null)) { throw new \RuntimeException('Private mail configuration invalid.'); }
                foreach ($data['connections'] as &$connection) {
                    $connection += ['hourly_limit' => 0, 'priority' => 100, 'pool_enabled' => $connection['provider'] !== 'native', 'quota_group' => ''];
                }
                unset($connection);
                $this->validateGraph($data['connections']);
                return $action($data);
            }, $write);
    }
}
