<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Connexion et deconnexion (CDCF 3.3.1).
 *
 * L'authentification elle-meme est prise en charge par le composant Security
 * de Symfony : le controleur ne fait qu'afficher le formulaire et l'eventuel
 * message d'erreur (diagramme de sequence 7.2.1).
 */
class SecuriteController extends AbstractController
{
    #[Route('/connexion', name: 'app_connexion', methods: ['GET', 'POST'])]
    public function connexion(AuthenticationUtils $authenticationUtils): Response
    {
        // Un utilisateur deja connecte n'a rien a faire sur cette page.
        if ($this->getUser()) {
            return $this->redirectToRoute('app_produit_index');
        }

        return $this->render('securite/connexion.html.twig', [
            'dernierEmail' => $authenticationUtils->getLastUsername(),
            // Le message reste volontairement generique : il ne revele pas
            // si c'est l'email ou le mot de passe qui est errone
            // (protection contre l'enumeration des comptes, chap. IX.3).
            'erreur' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/deconnexion', name: 'app_deconnexion', methods: ['GET', 'POST'])]
    public function deconnexion(): never
    {
        // Interceptee par le pare-feu : ce code n'est jamais atteint.
        throw new \LogicException('Cette methode est interceptee par la configuration logout du pare-feu.');
    }
}
