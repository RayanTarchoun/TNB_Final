<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Categorie;
use App\Entity\Produit;
use App\Enum\UniteVente;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Creation et modification d'un produit par l'administrateur (CDCF 3.3.2).
 *
 * @extends AbstractType<Produit>
 */
class ProduitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom du produit',
                'attr' => ['placeholder' => 'Tomates grappe'],
            ])
            ->add('categorie', EntityType::class, [
                'label' => 'Categorie',
                'class' => Categorie::class,
                'choice_label' => 'nom',
                'placeholder' => 'Choisir une categorie',
            ])
            ->add('prix', MoneyType::class, [
                'label' => 'Prix',
                'currency' => 'EUR',
                'scale' => 2,
                'help' => 'Prix pour une unite de vente (kilo, piece, botte, barquette).',
            ])
            ->add('uniteVente', EnumType::class, [
                'label' => 'Unite de vente',
                'class' => UniteVente::class,
                'choice_label' => static fn (UniteVente $unite): string => $unite->libelle(),
            ])
            ->add('origine', TextType::class, [
                'label' => 'Origine',
                'required' => false,
                'attr' => ['placeholder' => 'France, Val de Loire...'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('imageUrl', UrlType::class, [
                'label' => 'URL de l\'image',
                'required' => false,
                'default_protocol' => 'https',
                'help' => 'Laissez vide pour afficher la vignette par defaut.',
            ])
            ->add('disponible', CheckboxType::class, [
                'label' => 'Produit actif (visible au catalogue)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Produit::class,
        ]);
    }
}
