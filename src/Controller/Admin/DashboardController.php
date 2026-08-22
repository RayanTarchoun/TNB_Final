<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\CommandeRepository;
use App\Repository\ProduitRepository;
use App\Repository\StockRepository;
use App\Repository\UtilisateurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Vue d'ensemble du back-office (CDCF 3.3.5).
 *
 * Reprend les quatre indicateurs du wireframe (Jalon 2, 3.4) : commandes du
 * jour, stocks bas, produits actifs et clients.
 */
#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    #[Route('', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(
        CommandeRepository $commandeRepository,
        ProduitRepository $produitRepository,
        StockRepository $stockRepository,
        UtilisateurRepository $utilisateurRepository,
        float $seuilAlerteStock,
    ): Response {
        return $this->render('admin/dashboard/index.html.twig', [
            'commandesDuJour' => $commandeRepository->compterDuJour(),
            'montantDuJour' => $commandeRepository->montantDuJour(),
            'produitsActifs' => $produitRepository->compterActifs(),
            'clientsActifs' => $utilisateurRepository->compterClientsActifs(),
            'stocksBas' => $stockRepository->stocksBas($seuilAlerteStock),
            'seuilAlerte' => $seuilAlerteStock,
            'repartition' => $commandeRepository->compterParStatut(),
            'dernieresCommandes' => $commandeRepository->dernieresCommandes(6),
            'meilleuresVentes' => $commandeRepository->produitsLesPlusCommandes(5),
        ]);
    }
}
