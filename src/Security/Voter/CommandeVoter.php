<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Commande;
use App\Entity\Utilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Cloisonnement des commandes (CDCF 3.3.6).
 *
 * Un client ne voit et n'annule que ses propres commandes ; l'administrateur
 * a une vue complete. Centraliser la regle dans un voteur evite de la
 * dupliquer dans chaque controleur.
 *
 * @extends Voter<string, Commande>
 */
class CommandeVoter extends Voter
{
    public const VOIR = 'COMMANDE_VOIR';
    public const ANNULER = 'COMMANDE_ANNULER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VOIR, self::ANNULER], true)
            && $subject instanceof Commande;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $utilisateur = $token->getUser();

        // supports() a deja garanti que le sujet est une Commande.
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        return match ($attribute) {
            self::VOIR => $this->peutVoir($subject, $utilisateur),
            self::ANNULER => $this->peutAnnuler($subject, $utilisateur),
            default => false,
        };
    }

    private function peutVoir(Commande $commande, Utilisateur $utilisateur): bool
    {
        return $utilisateur->estAdministrateur()
            || $commande->getUtilisateur() === $utilisateur;
    }

    /**
     * L'annulation par le client suppose a la fois la propriete de la
     * commande et un statut encore annulable.
     */
    private function peutAnnuler(Commande $commande, Utilisateur $utilisateur): bool
    {
        return $commande->getUtilisateur() === $utilisateur
            && $commande->estAnnulable();
    }
}
