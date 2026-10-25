<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Helper\{UserHelper, CoreParametersHelper};
use MauticPlugin\MauticMultiMailBundle\Application\{ConnectionStore, ConnectionTester, TransportStatus};
use MauticPlugin\MauticMultiMailBundle\Conversation\{ConversationBridgeInterface, RoundTripTester};
use MauticPlugin\MauticMultiMailBundle\Imap\ImapStateStore;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Contracts\Translation\TranslatorInterface;

final class ConnectionsController extends CommonController
{
    public function index(Request $request, UserHelper $users, ConnectionStore $store, ConnectionTester $tester,
        CoreParametersHelper $parameters, TranslatorInterface $translator, ?ConversationBridgeInterface $bridge = null,
        ?ImapStateStore $states = null, ?RoundTripTester $roundTrip = null): Response
    {
        $user = $users->getUser(true);
        if (!$user?->isAdmin()) {
            throw $this->createAccessDeniedException();
        }
        $conversationAvailable = $bridge?->available() ?? false;
        if ($request->isMethod('POST') && $request->request->get('action') === 'roundtrip') {
            return $this->roundTrip($request, $roundTrip, $translator);
        }
        if ($request->isMethod('GET') && $request->query->get('usage') === '1') {
            try {
                $data = $store->overview();
                $connections = array_map(fn(array $connection): array => ['id' => $connection['id'], 'name' => $connection['name'],
                    'priority' => $connection['priority'], 'hourly' => $connection['hourly']], $data['connections']);
                return new JsonResponse(['connections' => $connections, 'checked_at' => gmdate(DATE_ATOM)], 200, ['Cache-Control' => 'private, no-store']);
            } catch (\Throwable) {
                return new JsonResponse(['message' => $translator->trans('mautic.multimail.quota.unavailable')], 503, ['Cache-Control' => 'private, no-store']);
            }
        }
        if ($request->isMethod('POST') && $request->request->get('action') === 'test') {
            if (!$this->isCsrfTokenValid('multimail_connections', $request->request->get('_token', ''))) {
                return new JsonResponse(['status' => 'invalid', 'message' => $translator->trans('mautic.multimail.test.csrf')], 403);
            }
            $input = $request->request->all();
            try {
                if (!is_string($input['revision'] ?? null) || !ctype_digit($input['revision'])
                    || !is_string($input['id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $input['id'])
                    || !is_string($input['recipient'] ?? null)) {
                    throw new \InvalidArgumentException('Invalid test input.');
                }
                $session = $request->getSession();
                if (microtime(true) - (float) $session->get('_multimail_last_test', 0) < 10) {
                    return new JsonResponse(['status' => 'limited', 'message' => $translator->trans('mautic.multimail.test.limited')], 429);
                }
                $session->set('_multimail_last_test', microtime(true));
                $result = $tester->send($input['id'], $input['recipient'], (int) $input['revision']);
                $result['message'] = $translator->trans('mautic.multimail.test.'.$result['status']);
                $result['details'] = [];
                if (isset($result['http_status'])) {
                    $result['details'][] = $translator->trans('mautic.multimail.test.http', ['%status%' => $result['http_status']]);
                }
                if (isset($result['provider_message_id'])) {
                    $result['details'][] = $translator->trans('mautic.multimail.test.provider_id', ['%id%' => $result['provider_message_id']]);
                }
                if (isset($result['error_code'])) {
                    $result['details'][] = $translator->trans('mautic.multimail.test.provider_error', ['%code%' => $result['error_code']]);
                }
                // Recover a completed result after a lost HTTP response, without sending again.
                // This session stores no recipient, message content or connection credentials.
                $session->set('_multimail_last_test_result', ['connection_id' => $input['id'],
                    'revision' => (int) $input['revision'], 'tested_at' => gmdate(DATE_ATOM), 'result' => $result]);
                return new JsonResponse($result, $result['status'] === 'accepted' ? 200 : ($result['status'] === 'quota' ? 429 : 502), ['Cache-Control' => 'private, no-store']);
            } catch (\DomainException) {
                $message = 'mautic.multimail.test.stale'; $code = 409;
            } catch (\InvalidArgumentException) {
                $message = 'mautic.multimail.test.invalid'; $code = 422;
            } catch (\Throwable) {
                $message = 'mautic.multimail.test.unavailable'; $code = 503;
            }
            return new JsonResponse(['status' => 'invalid', 'message' => $translator->trans($message)], $code, ['Cache-Control' => 'private, no-store']);
        }
        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('multimail_connections', $request->request->get('_token', ''))) {
                throw $this->createAccessDeniedException();
            }
            try {
                $input = $request->request->all();
                if (!isset($input['revision']) || !is_string($input['revision']) || !ctype_digit($input['revision'])) {
                    throw new \InvalidArgumentException('Recarregue a página antes de salvar.');
                }
                if (($input['action'] ?? '') === 'remove') {
                    $view = $store->remove((string) ($input['id'] ?? ''), (int) $input['revision']);
                } elseif (($input['action'] ?? '') === 'save') {
                    // The flag only means something with the Meta bundle behind it.
                    if (!$conversationAvailable && in_array($input['conversational'] ?? '0', ['1', 1, true], true)) {
                        throw new \InvalidArgumentException('mautic.multimail.conversation.unavailable');
                    }
                    $view = $store->save($input, (int) $input['revision'], (int) $user->getId());
                } else {
                    throw new \InvalidArgumentException('Ação inválida.');
                }
                // Assets follow the registry: created when the flag turns on, deactivated when it goes away.
                // The poller repeats this every pass, so a failure here heals by itself within a minute.
                try { $bridge?->synchronize($view['connections']); } catch (\Throwable) {}

                return $this->redirectToRoute('mautic_multimail_connections', ['saved' => 1], 303);
            } catch (\InvalidArgumentException $exception) {
                $error = $translator->trans($exception->getMessage()); $status = 422;
            } catch (\DomainException $exception) {
                $error = $exception->getMessage(); $status = 409;
            } catch (\Throwable) {
                $error = 'Não foi possível salvar a configuração privada. Tente novamente.'; $status = 503;
            }
        }
        try { $data = $store->overview(); }
        catch (\Throwable) { throw $this->createNotFoundException('Configuração de e-mail indisponível.'); }
        usort($data['connections'], fn(array $a, array $b): int => [$a['priority'], $a['id']] <=> [$b['priority'], $b['id']]);
        $editId = $request->query->get('edit', '');
        $editing = null;
        foreach ($data['connections'] as $connection) {
            if ($connection['id'] === $editId) { $editing = $connection; }
        }
        $provider = $editing['provider'] ?? $request->query->get('provider', 'smtp');
        if (!is_string($provider) || !isset(ConnectionStore::PROVIDERS[$provider])) { $provider = 'smtp'; }
        $transport = TransportStatus::describe($parameters->get('mailer_dsn'), $data['connections']);
        $testId = $request->query->get('test', $editId);
        $lastTest = $request->hasSession() ? $request->getSession()->get('_multimail_last_test_result') : null;
        if (!is_array($lastTest) || ($lastTest['connection_id'] ?? null) !== $testId
            || ($lastTest['revision'] ?? null) !== $data['revision']) { $lastTest = null; }
        $inbound = [];
        try {
            foreach ($states?->all() ?? [] as $id => $state) {
                $inbound[$id] = ['status' => $state['status'], 'auth_failed' => $state['auth_failed'], 'last_success_at' => $state['last_success_at'],
                    'last_error' => $state['last_error'], 'problem_since' => $state['problem_since']];
            }
        } catch (\Throwable) { /* Status is informative; the page still loads without it. */ }
        // Mautic's "Monitored Inbox" moves and flags messages: the same mailbox must not be both.
        $monitored = $parameters->get('monitored_email');
        $monitoredAddress = is_array($monitored) ? strtolower(trim((string) ($monitored['general']['address'] ?? ''))) : '';
        $response = $this->delegateView([
            'contentTemplate' => '@MauticMultiMail/Connections/index.html.twig',
            'passthroughVars' => ['mauticContent' => 'multimailconnections', 'route' => $this->generateUrl('mautic_multimail_connections')],
            'viewParameters' => ['data' => $data, 'editing' => $editing, 'provider' => $provider,
                'providers' => ConnectionStore::PROVIDERS, 'error' => $error, 'saved' => $request->query->get('saved') === '1',
                'transport' => $transport, 'testId' => $testId, 'testRecipient' => $user->getEmail(), 'lastTest' => $lastTest,
                'conversationAvailable' => $conversationAvailable, 'inbound' => $inbound, 'monitoredAddress' => $monitoredAddress],
        ]);
        $response->setStatusCode($status);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function roundTrip(Request $request, ?RoundTripTester $roundTrip, TranslatorInterface $translator): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        if (!$this->isCsrfTokenValid('multimail_connections', $request->request->get('_token', ''))) {
            return new JsonResponse(['status' => 'invalid', 'message' => $translator->trans('mautic.multimail.test.csrf')], 403, $headers);
        }
        $input = $request->request->all();
        if ($roundTrip === null || !is_string($input['revision'] ?? null) || !ctype_digit($input['revision'])
            || !is_string($input['id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $input['id'])) {
            return new JsonResponse(['status' => 'invalid', 'message' => $translator->trans('mautic.multimail.test.invalid')], 422, $headers);
        }
        $session = $request->getSession();
        if (microtime(true) - (float) $session->get('_multimail_last_roundtrip', 0) < 60) {
            return new JsonResponse(['status' => 'limited', 'message' => $translator->trans('mautic.multimail.roundtrip.limited')], 429, $headers);
        }
        $session->set('_multimail_last_roundtrip', microtime(true));
        // Up to a minute of IMAP polling: release the session lock so the admin can keep browsing.
        $session->save();
        set_time_limit(120);
        try {
            $result = $roundTrip->run($input['id'], (int) $input['revision']);
        } catch (\DomainException) {
            return new JsonResponse(['status' => 'invalid', 'message' => $translator->trans('mautic.multimail.test.stale')], 409, $headers);
        } catch (\InvalidArgumentException $exception) {
            $key = str_starts_with($exception->getMessage(), 'mautic.multimail.') ? $exception->getMessage() : 'mautic.multimail.test.invalid';
            return new JsonResponse(['status' => 'invalid', 'message' => $translator->trans($key)], 422, $headers);
        } catch (\Throwable) {
            return new JsonResponse(['status' => 'invalid', 'message' => $translator->trans('mautic.multimail.test.unavailable')], 503, $headers);
        }
        $details = [$translator->trans('mautic.multimail.roundtrip.address', ['%address%' => $result['address']])];
        if (isset($result['delivered_to'])) { $details[] = $translator->trans('mautic.multimail.roundtrip.delivered', ['%address%' => $result['delivered_to'], '%seconds%' => $result['seconds']]); }
        if (isset($result['reason']) && $result['reason'] !== '') { $details[] = $result['reason']; }

        return new JsonResponse(['status' => $result['status'], 'plus_addressing' => $result['plus_addressing'], 'reference' => $result['reference'],
            'message' => $translator->trans('mautic.multimail.roundtrip.'.$result['status']), 'details' => $details], 200, $headers);
    }
}
