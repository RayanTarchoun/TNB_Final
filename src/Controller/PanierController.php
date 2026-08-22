<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Produit;
use App\Exception\StockInsuffisantException;
use App\Service\PanierService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Panier client (CDCF 3.3.4).
 *
 * Le panier est accessible sans etre connecte : l'authentification n'est
 * exigee qu'a la validation de la commande (parcours Jalon 2, 5.2 etape 4).
 * Toutes les actions modifiantes passent en POST et sont protegees par un
 * jeton CSRF.
 */
class PanierController extends AbstractController
{
    #[Route('/panier', name: 'app_panier', methods: ['GET'])]
    public function index(PanierService $panierService): Response
    {
        return $this->render('panier/index.html.twig', [
            'lignes' => $panierService->getContenu(),
            'total' => $panierService->getTotal(),
            'indisponibles' => $panierService->lignesIndisponibles(),
        ]);
    }

    #[Route('/panier/ajouter/{id<\d+>}', name: 'app_panier_ajouter', methods: ['POST'])]
    public function ajouter(
        #[MapEntity(id: 'id')] Produit $produit,
        Request $request,
        PanierService $panierService,
    ): Response {
        if (!$this->isCsrfTokenValid('panier_ajouter'.$produit->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Action non autorisee, merci de reessayer.');

            return $this->redirectToRoute('app_produit_index');
        }

        $quantite = (float) $request->request->get(
            'quantite',
            $produit->getUniteVente()?->pasDeQuantite() ?? 1.0
        );

        try {
            $panierService->ajouter($produit, $quantite);
            $this->addFlash('success', \sprintf('"%s" a ete ajoute a votre panier.', $produit->getNom()));
        } catch (StockInsuffisantException $exception) {
            $this->addFlash('danger', $exception->messageUtilisateur());
        } catch (\InvalidArgumentException) {
            $this->addFlash('danger', 'La quantite demandee n\'est pas valide.');
        }

        return $this->redirect($this->pageDeRetour($request));
    }

    #[Route('/panier/modifier/{id<\d+>}', name: 'app_panier_modifier', methods: ['POST'])]
    public function modifier(
        #[MapEntity(id: 'id')] Produit $produit,
        Request $request,
        PanierService $panierService,
    ): Response {
        if (!$this->isCsrfTokenValid('panier_modifier'.$produit->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Action non autorisee, merci de reessayer.');

            return $this->redirectToRoute('app_panier');
        }

        $quantite = (float) $request->request->get('quantite', 0);

        try {
            $panierService->definirQuantite($produit, $quantite);

            $this->addFlash('success', $quantite > 0
                ? 'Quantite mise a jour.'
                : \sprintf('"%s" a ete retire de votre panier.', $produit->getNom()));
        } catch (StockInsuffisantException $exception) {
            $this->addFlash('danger', $exception->messageUtilisateur());
        }

        return $this->redirectToRoute('app_panier');
    }

    #[Route('/panier/supprimer/{id<\d+>}', name: 'app_panier_supprimer', methods: ['POST'])]
    public function supprimer(
        #[MapEntity(id: 'id')] Produit $produit,
        Request $request,
        PanierService $panierService,
    ): Response {
        if ($this->isCsrfTokenValid('panier_supprimer'.$produit->getId(), (string) $request->request->get('_token'))) {
            $panierService->supprimer((int) $produit->getId());
            $this->addFlash('success', \sprintf('"%s" a ete retire de votre panier.', $produit->getNom()));
        }

        return $this->redirectToRoute('app_panier');
    }

    #[Route('/panier/vider', name: 'app_panier_vider', methods: ['POST'])]
    public function vider(Request $request, PanierService $panierService): Response
    {
        if ($this->isCsrfTokenValid('panier_vider', (string) $request->request->get('_token'))) {
            $panierService->vider();
            $this->addFlash('success', 'Votre panier a ete vide.');
        }

        return $this->redirectToRoute('app_panier');
    }

    /**
     * Ajuste toutes les lignes sur le stock reellement disponible.
     */
    #[Route('/panier/ajuster', name: 'app_panier_ajuster', methods: ['POST'])]
    public function ajuster(Request $request, PanierService $panierService): Response
    {
        if ($this->isCsrfTokenValid('panier_ajuster', (string) $request->request->get('_token'))) {
            $ajustes = $panierService->ajusterAuStock();

            if ([] === $ajustes) {
                $this->addFlash('info', 'Votre panier etait deja a jour.');
            } else {
                $this->addFlash('warning', \sprintf(
                    'Panier ajuste au stock disponible : %s.',
                    implode(', ', $ajustes)
                ));
            }
        }

        return $this->redirectToRoute('app_panier');
    }

    /**
     * Renvoie le client la ou il se trouvait, en refusant toute URL externe.
     */
    private function pageDeRetour(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');

        if ('' !== $referer && str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return $referer;
        }

        return $this->generateUrl('app_produit_index');
    }
}
