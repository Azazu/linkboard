<?php

declare(strict_types=1);

namespace App\Web\Link;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The two views of the routing rules and the choice between them.
 *
 * `mode` is a visible radio, not a hidden field (Gate 2 round 1, finding 1):
 * it is what the server reads to decide which view the submission meant, so a
 * person without JavaScript — who sees both views — must be able to say which
 * one they filled in, and a person with JavaScript must never be able to type
 * a document into a view the server then ignores.
 *
 * @extends AbstractType<RulesFormData>
 */
final class RulesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('mode', ChoiceType::class, [
                'label' => 'Edit the rules as',
                'choices' => ['Fields' => RulesFormData::MODE_STRUCTURED, 'JSON document' => RulesFormData::MODE_RAW],
                'expanded' => true,
                'multiple' => false,
                'required' => false,
                // no empty third radio: the two views are the only answers
                'placeholder' => false,
            ])
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
