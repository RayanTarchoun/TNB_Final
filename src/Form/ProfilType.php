<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Utilisateur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Modification des donnees personnelles.
 *
 * Materialise le droit de rectification prevu par le RGPD (CDCF 3.6.2).
 *
 * @extends AbstractType<Utilisateur>
 */
class ProfilType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, ['label' => 'Prenom'])
            ->add('nom', TextType::class, ['label' => 'Nom'])
            ->add('email', EmailType::class, [
                'label' => 'Adresse email',
                'help' => 'Modifier cette adresse change aussi votre identifiant de connexion.',
            ])
            ->add('telephone', TelType::class, [
                'label' => 'Telephone',
                'required' => false,
            ])
            ->add('adresse', TextType::class, [
                'label' => 'Adresse postale',
                'required' => false,
                'help' => 'Facultative : les commandes sont recuperees sur le marche.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Utilisateur::class,
        ]);
    }
}
