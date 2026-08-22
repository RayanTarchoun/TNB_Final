<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Commande;
use App\Enum\StatutCommande;
use App\Exception\TransitionStatutInvalideException;
use App\Repository\CommandeRepository;
use App\Service\CommandeService;
use App\Service\NotificationCommandeService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Suivi des commandes et gestion de leur statut (CDCF 3.3.5).
 *
 * Le controleur ne connait pas le workflow : il transmet la cible a
 * CommandeService, seul juge de la validite de la transition
 * (diagramme de sequence 7.2.3).
 */
#[Route('/admin/commandes')]
#[IsGranted('ROLE_ADMIN')]
class CommandeController extends AbstractController
{
    #[Route('', name: 'app_admin_commande_index', methods: ['GET'])]
    public function index(Request $request, CommandeRepository $commandeRepository): Response
    {
        $filtre = $request->query->getString('statut');
        $statut = StatutCommande::tryFrom($filtre);

        return $this->render('admin/commande/index.html.twig', [
            'commandes' => $commandeRepository->findPourAdministration($statut),
            'statutActif' => $statut,
            'repartition' => $commandeRepository->compterParStatut(),
        ]);
    }

    #[Route('/{reference}', name: 'app_admin_commande_show', methods: ['GET'])]
    public function show(
        #[MapEntity(mapping: ['reference' => 'reference'])] Commande $commande,
    ): Response {
        return $this->render('admin/commande/show.html.twig', [
            'commande' => $commande,
        ]);
    }

    #[Route('/{reference}/statut', name: 'app_admin_commande_statut', methods: ['POST'])]
    public function changerStatut(
        #[MapEntity(mapping: ['reference' => 'reference'])] Commande $commande,
        Request $request,
        CommandeService $commandeService,
        NotificationCommandeService $notification,
    ): Response {
        if (!$this->isCsrfTokenValid('statut_commande'.$commande->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Action non autorisee, merci de reessayer.');

            return $this->redirectToRoute('app_admin_commande_show', ['reference' => $commande->getReference()]);
        }

        $cible = StatutCommande::tryFrom($request->request->getString('statut'));

        if (null === $cible) {
            $this->addFlash('danger', 'Statut inconnu.');

            return $this->redirectToRoute('app_admin_commande_show', ['reference' => $commande->getReference()]);
        }

        try {
            $commandeService->changerStatut($commande, $cible);
            $notification->changementDeStatut($commande);

            $this->addFlash('success', \sprintf(
                'Commande %s : statut passe a "%s".',
                $commande->getReference(),
                $cible->libelle()
            ));
        } catch (TransitionStatutInvalideException $exception) {
            $this->addFlash('danger', $exception->messageUtilisateur());
        }

        return $this->redirectToRoute('app_admin_commande_show', ['reference' => $commande->getReference()]);
    }
}
