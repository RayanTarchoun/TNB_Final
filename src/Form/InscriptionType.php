<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Utilisateur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creation d'un compte client (CDCF 3.3.1).
 *
 * La collecte est volontairement minimale (RGPD, CDCF 3.6.2) : seuls le nom,
 * le prenom, l'email et le mot de passe sont obligatoires.
 *
 * @extends AbstractType<Utilisateur>
 */
class InscriptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, [
                'label' => 'Prenom',
                'attr' => ['placeholder' => 'Sophie', 'autocomplete' => 'given-name'],
            ])
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'attr' => ['placeholder' => 'Bernard', 'autocomplete' => 'family-name'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse email',
                'attr' => ['placeholder' => 'sophie.bernard@example.fr', 'autocomplete' => 'email'],
                'help' => 'Elle vous servira d\'identifiant de connexion.',
            ])
            ->add('telephone', TelType::class, [
                'label' => 'Telephone (facultatif)',
                'required' => false,
                'attr' => ['placeholder' => '06 12 34 56 78', 'autocomplete' => 'tel'],
                'help' => 'Utile si nous devons vous joindre au sujet d\'une commande.',
            ])
            ->add('motDePasse', RepeatedType::class, [
                // Le mot de passe en clair ne doit jamais toucher l'entite :
                // le controleur le hache avant de l'affecter.
                'mapped' => false,
                'type' => PasswordType::class,
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'first_options' => [
                    'label' => 'Mot de passe',
                    'attr' => ['autocomplete' => 'new-password'],
                    'help' => 'Au moins 8 caracteres, dont une lettre et un chiffre.',
                ],
                'second_options' => [
                    'label' => 'Confirmez le mot de passe',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'Le mot de passe est obligatoire.'),
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
            ])
            ->add('accepterRgpd', CheckboxType::class, [
                'mapped' => false,
                'label' => 'J\'accepte que mes donnees soient utilisees pour traiter mes commandes.',
                'constraints' => [
                    new Assert\IsTrue(message: 'Vous devez accepter la politique de confidentialite.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Utilisateur::class,
        ]);
    }
}
