<?php

declare(strict_types=1);

namespace App\Web\Link;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<RulesFormData>
 */
final class RulesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // a missing or empty hidden field must not reach a non-nullable property:
            // a partial or forged POST has to end in a 422, never a 500
            ->add('mode', HiddenType::class, ['empty_data' => RulesFormData::MODE_STRUCTURED])
            ->add('rows', CollectionType::class, [
                'entry_type' => RuleRowType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
                'required' => false,
            ])
            ->add('raw', TextareaType::class, [
                'label' => 'Rules document (JSON)',
                'required' => false,
                'attr' => ['rows' => 12, 'spellcheck' => 'false'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RulesFormData::class]);
    }
}
