<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Derniere etape avant la validation de la commande.
 *
 * Le formulaire porte surtout le jeton CSRF : c'est lui qui garantit que la
 * commande provient bien du recapitulatif affiche au client (chap. IX.2).
 *
 * @extends AbstractType<array<string, mixed>>
 */
class ValidationCommandeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('commentaire', TextareaType::class, [
            'label' => 'Un mot pour le gerant (facultatif)',
            'required' => false,
            'attr' => [
                'rows' => 3,
                'placeholder' => 'Ex. : je passerai vers 9h, merci de bien murir les avocats.',
                'maxlength' => 500,
            ],
            'constraints' => [
                new Assert\Length(
                    max: 500,
                    maxMessage: 'Le commentaire ne peut pas depasser {{ limit }} caracteres.'
                ),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_token_id' => 'valider_commande',
        ]);
    }
}
