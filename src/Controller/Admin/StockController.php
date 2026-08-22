<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Stock;
use App\Form\StockType;
use App\Repository\StockRepository;
use App\Service\StockService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Saisie et mise a jour des stocks (CDCF 3.3.3).
 */
#[Route('/admin/stocks')]
#[IsGranted('ROLE_ADMIN')]
class StockController extends AbstractController
{
    #[Route('', name: 'app_admin_stock_index', methods: ['GET'])]
    public function index(
        StockRepository $stockRepository,
        float $seuilAlerteStock,
    ): Response {
        return $this->render('admin/stock/index.html.twig', [
            'stocks' => $stockRepository->findPourAdministration(),
            'seuilAlerte' => $seuilAlerteStock,
        ]);
    }

    #[Route('/{id<\d+>}/modifier', name: 'app_admin_stock_modifier', methods: ['GET', 'POST'])]
    public function modifier(
        #[MapEntity(id: 'id')] Stock $stock,
        Request $request,
        StockRepository $stockRepository,
    ): Response {
        $formulaire = $this->createForm(StockType::class, $stock);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $stockRepository->save($stock);

            $this->addFlash('success', \sprintf(
                'Stock de "%s" mis a jour.',
                $stock->getProduit()?->getNom()
            ));

            return $this->redirectToRoute('app_admin_stock_index');
        }

        return $this->render('admin/stock/form.html.twig', [
            'formulaire' => $formulaire,
            'stock' => $stock,
        ]);
    }

    /**
     * Remet le stock restant au niveau de la quantite achetee.
     *
     * C'est le geste du matin de marche : le gerant repart d'un stock neuf.
     */
    #[Route('/{id<\d+>}/reapprovisionner', name: 'app_admin_stock_reapprovisionner', methods: ['POST'])]
    public function reapprovisionner(
        #[MapEntity(id: 'id')] Stock $stock,
        Request $request,
        StockService $stockService,
    ): Response {
        if (!$this->isCsrfTokenValid('reappro_stock'.$stock->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_admin_stock_index');
        }

        $quantite = (float) $request->request->get('quantite', $stock->getQuantiteAcheteeFloat());

        if ($quantite < 0) {
            $this->addFlash('danger', 'La quantite achetee ne peut pas etre negative.');

            return $this->redirectToRoute('app_admin_stock_index');
        }

        $stockService->reapprovisionner($stock, $quantite);

        $this->addFlash('success', \sprintf(
            'Stock de "%s" reapprovisionne pour le marche du jour.',
            $stock->getProduit()?->getNom()
        ));

        return $this->redirectToRoute('app_admin_stock_index');
    }
}
