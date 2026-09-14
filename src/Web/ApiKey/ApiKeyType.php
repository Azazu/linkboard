<?php

declare(strict_types=1);

namespace App\Web\ApiKey;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ApiKeyFormData>
 */
final class ApiKeyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'help' => 'What this key is for, so it can be recognised later.',
                'empty_data' => '',
            ])
            ->add('expiresAt', DateTimeType::class, [
                'label' => 'Expires at (UTC)',
                'widget' => 'single_text',
                'required' => false,
                'input' => 'datetime_immutable',
                'view_timezone' => 'UTC',
                'model_timezone' => 'UTC',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ApiKeyFormData::class]);
    }
}
