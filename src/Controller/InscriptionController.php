<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Form\InscriptionType;
use App\Repository\UtilisateurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Creation de comptes clients particuliers (CDCF 3.3.1).
 */
class InscriptionController extends AbstractController
{
    #[Route('/inscription', name: 'app_inscription', methods: ['GET', 'POST'])]
    public function inscription(
        Request $request,
        UserPasswordHasherInterface $hasher,
        UtilisateurRepository $utilisateurRepository,
        Security $security,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_produit_index');
        }

        $utilisateur = new Utilisateur();
        $formulaire = $this->createForm(InscriptionType::class, $utilisateur);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            // Le mot de passe est hache avant toute persistance : il n'est
            // jamais stocke en clair (CDCF 3.6.2).
            $utilisateur->setMotDePasse(
                $hasher->hashPassword($utilisateur, (string) $formulaire->get('motDePasse')->getData())
            );
            $utilisateur->setRole(Utilisateur::ROLE_CLIENT);

            $utilisateurRepository->save($utilisateur);

            $this->addFlash('success', \sprintf(
                'Bienvenue %s ! Votre compte est cree.',
                $utilisateur->getPrenom()
            ));

            // Connexion automatique : le client enchaine sur sa commande
            // sans avoir a ressaisir ses identifiants.
            return $security->login($utilisateur, 'form_login', 'main')
                ?? $this->redirectToRoute('app_produit_index');
        }

        return $this->render('inscription/index.html.twig', [
            'formulaire' => $formulaire,
        ]);
    }
}
