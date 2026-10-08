<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Form\Extension;

use Mautic\CoreBundle\Helper\{CoreParametersHelper, UserHelper};
use Mautic\EmailBundle\Form\Type\ExampleSendType;
use MauticPlugin\MauticMultiMailBundle\Application\{ConnectionStore, ExampleRouting, TransportStatus};
use Symfony\Component\Form\{AbstractTypeExtension, FormBuilderInterface, FormEvent, FormEvents};
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExampleSendExtension extends AbstractTypeExtension
{
    public function __construct(private readonly UserHelper $users, private readonly ConnectionStore $store,
        private readonly CoreParametersHelper $parameters, private readonly ExampleRouting $routing,
        private readonly TranslatorInterface $translator) {}

    public static function getExtendedTypes(): iterable { return [ExampleSendType::class]; }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$this->users->getUser(true)?->isAdmin()) { return; }
        try { $connections = $this->store->overview()['connections']; }
        catch (\Throwable) { $connections = []; }
        $status = TransportStatus::describe($this->parameters->get('mailer_dsn'), $connections);
        $choices = [$this->translator->trans('mautic.multimail.example.default', ['%transport%' => $status['name'] ?? $status['scheme']]) => ExampleRouting::DEFAULT];
        foreach ($connections as $connection) {
            $choices[$connection['name'].' · '.ConnectionStore::PROVIDERS[$connection['provider']]['label'].' · '.$connection['from_email'].' · '.substr($connection['id'], 0, 6)] = $connection['id'];
        }
        $buttons = $builder->has('buttons') ? $builder->get('buttons') : null;
        if ($buttons) { $builder->remove('buttons'); }
        $builder->add('multimail_connection', ChoiceType::class, [
            'mapped' => false, 'required' => true, 'choices' => $choices, 'choice_translation_domain' => false,
            'placeholder' => 'mautic.multimail.example.choose', 'label' => 'mautic.multimail.example.connection',
            'help' => 'mautic.multimail.example.help', 'attr' => ['class' => 'form-control'],
            'invalid_message' => 'mautic.multimail.example.invalid',
            'constraints' => [new NotBlank(message: 'mautic.multimail.example.choose')],
        ]);
        if ($buttons) { $builder->add($buttons); }
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            if ($form->isSubmitted() && $form->isValid()) {
                $this->routing->activate((string) $form->get('multimail_connection')->getData());
            }
        }, -2048);
    }
}
