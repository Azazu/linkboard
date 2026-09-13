<?php

declare(strict_types=1);

namespace App\Web\Link;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The create and edit form. `creating` decides the two fields that differ: a
 * slug can be chosen once and never changed, and only an existing link can be
 * deactivated.
 *
 * @extends AbstractType<LinkFormData>
 */
final class LinkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('targetUrl', UrlType::class, [
                'label' => 'Target URL',
                'default_protocol' => null,
                // as above: an absent field is an empty value to validate, not a type error
                'empty_data' => '',
            ]);

        if (true === $options['creating']) {
            $builder->add('slug', TextType::class, [
                'label' => 'Custom slug',
                'help' => 'Optional: 3 to 32 letters, digits, hyphen or underscore. Left empty, one is generated.',
                'required' => false,
            ]);
        }

        $builder
            ->add('utmSource', TextType::class, ['label' => 'utm_source', 'required' => false])
            ->add('utmMedium', TextType::class, ['label' => 'utm_medium', 'required' => false])
            ->add('utmCampaign', TextType::class, ['label' => 'utm_campaign', 'required' => false])
            ->add('utmTerm', TextType::class, ['label' => 'utm_term', 'required' => false])
            ->add('utmContent', TextType::class, ['label' => 'utm_content', 'required' => false])
            ->add('expiresAt', DateTimeType::class, [
                'label' => 'Expires at (UTC)',
                'widget' => 'single_text',
                'required' => false,
                'input' => 'datetime_immutable',
                'view_timezone' => 'UTC',
                'model_timezone' => 'UTC',
            ])
            ->add('maxClicks', IntegerType::class, [
                'label' => 'Click limit',
                'required' => false,
            ])
            ->add('rules', RulesType::class, ['label' => false]);

        if (true !== $options['creating']) {
            $builder->add('isActive', CheckboxType::class, [
                'label' => 'Active',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => LinkFormData::class, 'creating' => false])
            ->setAllowedTypes('creating', 'bool');
    }
}
