<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Utilisateur;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuse l'acces aux comptes desactives.
 *
 * Le champ "actif" du dictionnaire de donnees sert a la fois de suppression
 * douce (droit RGPD a l'effacement, CDCF 3.6.2) et de mesure de securite.
 */
class UtilisateurChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof Utilisateur) {
            return;
        }

        if (!$user->isActif()) {
            throw new CustomUserMessageAccountStatusException('Ce compte a ete desactive. Contactez-nous sur le marche pour le reactiver.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
