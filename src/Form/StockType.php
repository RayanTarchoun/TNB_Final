<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Stock;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Saisie et mise a jour manuelle des stocks (CDCF 3.3.3).
 *
 * @extends AbstractType<Stock>
 */
class StockType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('quantiteAchetee', NumberType::class, [
                'label' => 'Quantite achetee',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.5', 'min' => '0'],
                'help' => 'Quantite prevue pour ce jour de marche.',
            ])
            ->add('quantiteDisponible', NumberType::class, [
                'label' => 'Quantite restante',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.5', 'min' => '0'],
                'help' => 'Diminue automatiquement a chaque commande validee.',
            ])
            ->add('dateMarche', DateType::class, [
                'label' => 'Jour de marche',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Stock::class,
        ]);
    }
}
