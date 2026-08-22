<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Form\ChangerMotDePasseType;
use App\Form\ProfilType;
use App\Repository\CommandeRepository;
use App\Repository\UtilisateurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace personnel du client (CDCF 3.3.6 "Profil").
 *
 * Regroupe les droits RGPD d'acces, de rectification et d'effacement
 * (CDCF 3.6.2).
 */
#[IsGranted('ROLE_CLIENT')]
class ProfilController extends AbstractController
{
    #[Route('/profil', name: 'app_profil', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        #[CurrentUser] Utilisateur $utilisateur,
        UtilisateurRepository $utilisateurRepository,
        CommandeRepository $commandeRepository,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $formulaireProfil = $this->createForm(ProfilType::class, $utilisateur);
        $formulaireProfil->handleRequest($request);

        if ($formulaireProfil->isSubmitted() && $formulaireProfil->isValid()) {
            $utilisateurRepository->save($utilisateur);
            $this->addFlash('success', 'Vos informations ont ete mises a jour.');

            return $this->redirectToRoute('app_profil');
        }

        $formulaireMotDePasse = $this->createForm(ChangerMotDePasseType::class);
        $formulaireMotDePasse->handleRequest($request);

        if ($formulaireMotDePasse->isSubmitted() && $formulaireMotDePasse->isValid()) {
            $utilisateur->setMotDePasse($hasher->hashPassword(
                $utilisateur,
                (string) $formulaireMotDePasse->get('nouveauMotDePasse')->getData()
            ));

            $utilisateurRepository->save($utilisateur);
            $this->addFlash('success', 'Votre mot de passe a ete modifie.');

            return $this->redirectToRoute('app_profil');
        }

        return $this->render('profil/index.html.twig', [
            'utilisateur' => $utilisateur,
            'formulaireProfil' => $formulaireProfil,
            'formulaireMotDePasse' => $formulaireMotDePasse,
            'commandes' => $commandeRepository->historiquePour($utilisateur),
        ]);
    }

    /**
     * Droit a l'effacement (RGPD).
     *
     * Le compte est desactive et anonymise plutot que supprime : les
     * commandes deja honorees doivent rester tracables pour l'entreprise,
     * mais plus aucune donnee identifiante n'y est rattachee.
     */
    #[Route('/profil/supprimer', name: 'app_profil_supprimer', methods: ['POST'])]
    public function supprimer(
        Request $request,
        #[CurrentUser] Utilisateur $utilisateur,
        UtilisateurRepository $utilisateurRepository,
        TokenStorageInterface $tokenStorage,
    ): Response {
        if (!$this->isCsrfTokenValid('supprimer_compte'.$utilisateur->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Action non autorisee, merci de reessayer.');

            return $this->redirectToRoute('app_profil');
        }

        $utilisateur
            ->setActif(false)
            ->setEmail(\sprintf('anonyme+%d@supprime.local', $utilisateur->getId()))
            ->setNom('Compte')
            ->setPrenom('supprime')
            ->setTelephone(null)
            ->setAdresse(null);

        $utilisateurRepository->save($utilisateur);

        // Deconnexion immediate : le jeton et la session sont detruits.
        $tokenStorage->setToken(null);
        $request->getSession()->invalidate();

        $this->addFlash('success', 'Votre compte a ete supprime. Merci de votre confiance.');

        return $this->redirectToRoute('app_accueil');
    }
}
