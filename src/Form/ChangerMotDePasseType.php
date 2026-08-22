<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints as SecurityAssert;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changement de mot de passe depuis l'espace profil.
 *
 * Le formulaire n'est lie a aucune entite : le nouveau mot de passe est
 * hache par le controleur avant d'etre affecte a l'utilisateur.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class ChangerMotDePasseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('motDePasseActuel', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Saisissez votre mot de passe actuel.'),
                    // Confronte la saisie au hache stocke, sans jamais le dechiffrer.
                    new SecurityAssert\UserPassword(message: 'Le mot de passe actuel est incorrect.'),
                ],
            ])
            ->add('nouveauMotDePasse', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'first_options' => [
                    'label' => 'Nouveau mot de passe',
                    'attr' => ['autocomplete' => 'new-password'],
                    'help' => 'Au moins 8 caracteres, dont une lettre et un chiffre.',
                ],
                'second_options' => [
                    'label' => 'Confirmez le nouveau mot de passe',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'Le nouveau mot de passe est obligatoire.'),
                    new Assert\Length(
                        min: 8,
                        max: 4096,
                        minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caracteres.'
                    ),
                    new Assert\Regex(
                        pattern: '/^(?=.*[A-Za-z])(?=.*\d).+$/',
                        message: 'Le mot de passe doit contenir au moins une lettre et un chiffre.'
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
