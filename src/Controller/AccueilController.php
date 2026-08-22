<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProduitRepository;
use App\Service\MeteoMarcheService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vitrine publique (sitemap Jalon 2, 1.1).
 */
class AccueilController extends AbstractController
{
    #[Route('/', name: 'app_accueil', methods: ['GET'])]
    public function index(
        ProduitRepository $produitRepository,
        MeteoMarcheService $meteoMarcheService,
    ): Response {
        return $this->render('accueil/index.html.twig', [
            'produits' => $produitRepository->produitsDuMoment(4),
            // Bloc optionnel : absent si l'API externe est injoignable.
            'previsions' => $meteoMarcheService->previsions(3),
        ]);
    }
}
