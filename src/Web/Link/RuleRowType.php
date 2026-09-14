<?php

declare(strict_types=1);

namespace App\Web\Link;

use App\Link\Rules\RulesDocument;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<RuleRowFormData>
 */
final class RuleRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('matchKey', ChoiceType::class, [
                'label' => 'Matches on',
                'choices' => array_combine(RulesDocument::MATCH_KEYS, RulesDocument::MATCH_KEYS),
                'placeholder' => 'Choose…',
                'required' => false,
            ])
            ->add('values', TextType::class, [
                'label' => 'Values',
                'help' => 'Comma separated, e.g. smartphone, tablet or DE, AT',
                'required' => false,
            ])
            ->add('target', TextType::class, [
                'label' => 'Target URL',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RuleRowFormData::class]);
    }
}
