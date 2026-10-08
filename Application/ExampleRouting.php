<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Application;

use Symfony\Component\HttpFoundation\RequestStack;

final class ExampleRouting
{
    public const ATTRIBUTE = '_multimail_validated_example_connection';
    public const DEFAULT = '__mautic_default__';

    public function __construct(private readonly RequestStack $requests) {}

    public function isExampleRequest(): bool
    {
        $request = $this->requests->getCurrentRequest();
        return $request !== null && $request->isMethod('POST')
            && $request->attributes->get('_route') === 'mautic_email_action'
            && $request->attributes->get('objectAction') === 'sendExample';
    }

    /** Called only after the native root form passed its CSRF and choice validation. */
    public function activate(string $selection): void
    {
        if ($this->isExampleRequest()) {
            $this->requests->getCurrentRequest()->attributes->set(self::ATTRIBUTE, $selection);
        }
    }

    public function selected(): ?string
    {
        if (!$this->isExampleRequest()) { return null; }
        $value = $this->requests->getCurrentRequest()->attributes->get(self::ATTRIBUTE);
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) ? $value : null;
    }

    public function appliesToEmail(?int $emailId): bool
    {
        $id = $this->requests->getCurrentRequest()?->attributes->get('objectId');
        return $this->isExampleRequest() && $emailId !== null && $emailId > 0
            && (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id === $emailId;
    }
}
