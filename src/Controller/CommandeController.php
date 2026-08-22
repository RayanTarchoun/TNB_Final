<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Commande;
use App\Entity\Utilisateur;
use App\Exception\PanierVideException;
use App\Exception\StockInsuffisantException;
use App\Exception\TransitionStatutInvalideException;
use App\Form\ValidationCommandeType;
use App\Repository\CommandeRepository;
use App\Security\Voter\CommandeVoter;
use App\Service\CommandeService;
use App\Service\NotificationCommandeService;
use App\Service\PanierService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Validation des commandes et historique client (CDCF 3.3.4).
 *
 * Le controleur reste mince : il recupere le panier puis delegue la regle
 * metier a CommandeService (dossier chap. VII.2.2 et VIII.1).
 */
#[IsGranted('ROLE_CLIENT')]
class CommandeController extends AbstractController
{
    #[Route('/commande/valider', name: 'app_commande_valider', methods: ['GET', 'POST'])]
    public function valider(
        Request $request,
        #[CurrentUser] Utilisateur $utilisateur,
        PanierService $panierService,
        CommandeService $commandeService,
        NotificationCommandeService $notification,
    ): Response {
        $lignes = $panierService->getContenu();

        if ([] === $lignes) {
            $this->addFlash('info', 'Votre panier est vide : ajoutez des produits avant de valider.');

            return $this->redirectToRoute('app_produit_index');
        }

        $formulaire = $this->createForm(ValidationCommandeType::class);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            try {
                $commande = $commandeService->creerCommande(
                    $utilisateur,
                    $lignes,
                    $formulaire->get('commentaire')->getData()
                );

                $panierService->vider();
                $notification->confirmationCommande($commande);

                $this->addFlash('success', \sprintf(
                    'Commande %s validee. Un email de confirmation vous a ete envoye.',
                    $commande->getReference()
                ));

                return $this->redirectToRoute('app_commande_confirmation', [
                    'reference' => $commande->getReference(),
                ]);
            } catch (StockInsuffisantException $exception) {
                // Le stock a change depuis l'affichage du panier : on informe
                // le client et on lui reaffiche le recapitulatif a jour.
                $this->addFlash('danger', $exception->messageUtilisateur());

                return $this->redirectToRoute('app_panier');
            } catch (PanierVideException $exception) {
                $this->addFlash('info', $exception->getMessage());

                return $this->redirectToRoute('app_produit_index');
            }
        }

        return $this->render('commande/valider.html.twig', [
            'lignes' => $lignes,
            'total' => $panierService->getTotal(),
            'indisponibles' => $panierService->lignesIndisponibles(),
            'formulaire' => $formulaire,
        ]);
    }

    #[Route('/commande/confirmation/{reference}', name: 'app_commande_confirmation', methods: ['GET'])]
    public function confirmation(
        #[MapEntity(mapping: ['reference' => 'reference'])] Commande $commande,
    ): Response {
        $this->denyAccessUnlessGranted(CommandeVoter::VOIR, $commande);

        return $this->render('commande/confirmation.html.twig', [
            'commande' => $commande,
        ]);
    }

    #[Route('/mes-commandes', name: 'app_commande_historique', methods: ['GET'])]
    public function historique(
        #[CurrentUser] Utilisateur $utilisateur,
        CommandeRepository $commandeRepository,
    ): Response {
        return $this->render('commande/historique.html.twig', [
            'commandes' => $commandeRepository->historiquePour($utilisateur),
        ]);
    }

    #[Route('/mes-commandes/{reference}', name: 'app_commande_show', methods: ['GET'])]
    public function show(
        #[MapEntity(mapping: ['reference' => 'reference'])] Commande $commande,
    ): Response {
        $this->denyAccessUnlessGranted(CommandeVoter::VOIR, $commande);

        return $this->render('commande/show.html.twig', [
            'commande' => $commande,
        ]);
    }

    #[Route('/mes-commandes/{reference}/annuler', name: 'app_commande_annuler', methods: ['POST'])]
    public function annuler(
        #[MapEntity(mapping: ['reference' => 'reference'])] Commande $commande,
        Request $request,
        #[CurrentUser] Utilisateur $utilisateur,
        CommandeService $commandeService,
        NotificationCommandeService $notification,
    ): Response {
        $this->denyAccessUnlessGranted(CommandeVoter::ANNULER, $commande);

        if (!$this->isCsrfTokenValid('annuler_commande'.$commande->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Action non autorisee, merci de reessayer.');

            return $this->redirectToRoute('app_commande_show', ['reference' => $commande->getReference()]);
        }

        try {
            $commandeService->annulerParClient($commande, $utilisateur);
            $notification->changementDeStatut($commande);

            $this->addFlash('success', \sprintf(
                'Commande %s annulee. Les produits sont remis en vente.',
                $commande->getReference()
            ));
        } catch (TransitionStatutInvalideException $exception) {
            $this->addFlash('danger', $exception->messageUtilisateur());
        }

        return $this->redirectToRoute('app_commande_show', ['reference' => $commande->getReference()]);
    }
}
