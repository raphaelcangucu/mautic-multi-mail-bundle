<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use Symfony\Component\HttpFoundation\{Request, Response};

final class ConnectionsController extends CommonController
{
    public function index(Request $request, UserHelper $users, ConnectionStore $store): Response
    {
        $user = $users->getUser(true);
        if (!$user?->isAdmin()) {
            throw $this->createAccessDeniedException();
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
                    $store->remove((string) ($input['id'] ?? ''), (int) $input['revision']);
                } elseif (($input['action'] ?? '') === 'save') {
                    $store->save($input, (int) $input['revision'], (int) $user->getId());
                } else {
                    throw new \InvalidArgumentException('Ação inválida.');
                }

                return $this->redirectToRoute('mautic_multimail_connections', ['saved' => 1], 303);
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage(); $status = 422;
            } catch (\DomainException $exception) {
                $error = $exception->getMessage(); $status = 409;
            } catch (\Throwable) {
                $error = 'Não foi possível salvar a configuração privada. Tente novamente.'; $status = 503;
            }
        }
        try { $data = $store->overview(); }
        catch (\Throwable) { throw $this->createNotFoundException('Configuração de e-mail indisponível.'); }
        $editId = $request->query->get('edit', '');
        $editing = null;
        foreach ($data['connections'] as $connection) {
            if ($connection['id'] === $editId) { $editing = $connection; }
        }
        $provider = $editing['provider'] ?? $request->query->get('provider', 'smtp');
        if (!is_string($provider) || !isset(ConnectionStore::PROVIDERS[$provider])) { $provider = 'smtp'; }
        $response = $this->delegateView([
            'contentTemplate' => '@MauticMultiMail/Connections/index.html.twig',
            'passthroughVars' => ['mauticContent' => 'multimailconnections', 'route' => $this->generateUrl('mautic_multimail_connections')],
            'viewParameters' => ['data' => $data, 'editing' => $editing, 'provider' => $provider,
                'providers' => ConnectionStore::PROVIDERS, 'error' => $error, 'saved' => $request->query->get('saved') === '1'],
        ]);
        $response->setStatusCode($status);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
