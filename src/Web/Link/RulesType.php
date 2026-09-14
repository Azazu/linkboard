<?php

declare(strict_types=1);

namespace App\Web\Link;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The two views of the routing rules, and the switch between them.
 *
 * Switching is a submission the **server** answers (Gate 2 confirmation 1,
 * finding 1): it reads the view a person filled in, converts the document with
 * the same mapper that stores it, and re-renders the other view carrying that
 * document. Nothing is transferred by the browser, so there is no second
 * implementation of the mapping to drift, and the switch works with JavaScript
 * off. `mode` is therefore a hidden field the server maintains, and only one
 * view is on the page at a time.
 *
 * @extends AbstractType<RulesFormData>
 */
final class RulesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
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
            ])
            ->add('addRow', SubmitType::class, [
                'label' => 'Add rule',
                'validation_groups' => false,
                'attr' => ['class' => 'secondary', 'formnovalidate' => 'formnovalidate'],
            ])
            ->add('toJson', SubmitType::class, [
                'label' => 'Edit as JSON',
                'validation_groups' => false,
                'attr' => ['class' => 'secondary outline', 'formnovalidate' => 'formnovalidate'],
            ])
            ->add('toFields', SubmitType::class, [
                'label' => 'Edit as fields',
                'validation_groups' => false,
                'attr' => ['class' => 'secondary outline', 'formnovalidate' => 'formnovalidate'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RulesFormData::class]);
    }
}
