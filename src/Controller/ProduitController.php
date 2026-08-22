<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Produit;
use App\Repository\CategorieRepository;
use App\Repository\ProduitRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Catalogue public : consultation, recherche et filtrage (CDCF 3.3.2).
 */
class ProduitController extends AbstractController
{
    #[Route('/produits', name: 'app_produit_index', methods: ['GET'])]
    public function index(
        Request $request,
        ProduitRepository $produitRepository,
        CategorieRepository $categorieRepository,
    ): Response {
        // Les filtres transitent par la query string : les resultats sont
        // ainsi partageables et gardes dans l'historique du navigateur.
        $recherche = trim((string) $request->query->get('q', ''));
        $tri = (string) $request->query->get('tri', 'nom_asc');
        $enStock = $request->query->getBoolean('en_stock');
        $page = max(1, $request->query->getInt('page', 1));

        /** @var list<int> $categorieIds */
        $categorieIds = array_values(array_filter(array_map(
            static fn ($valeur): int => (int) $valeur,
            (array) $request->query->all('categorie')
        )));

        if (!\array_key_exists($tri, ProduitRepository::TRIS)) {
            $tri = 'nom_asc';
        }

        $paginateur = $produitRepository->rechercherCatalogue(
            '' !== $recherche ? $recherche : null,
            $categorieIds,
            $tri,
            $enStock,
            $page
        );

        $total = \count($paginateur);
        $nombreDePages = max(1, (int) ceil($total / ProduitRepository::PAR_PAGE));

        return $this->render('produit/index.html.twig', [
            'produits' => iterator_to_array($paginateur),
            'categories' => $categorieRepository->avecNombreDeProduits(),
            'total' => $total,
            'page' => $page,
            'nombreDePages' => $nombreDePages,
            'filtres' => [
                'q' => $recherche,
                'tri' => $tri,
                'categorie' => $categorieIds,
                'en_stock' => $enStock,
            ],
            'tris' => ProduitRepository::TRIS,
        ]);
    }

    #[Route('/produits/{id<\d+>}', name: 'app_produit_show', methods: ['GET'])]
    public function show(
        #[MapEntity(id: 'id')] Produit $produit,
        ProduitRepository $produitRepository,
    ): Response {
        // Un produit desactive n'est plus consultable publiquement.
        if (!$produit->isDisponible() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException("Ce produit n'est pas disponible.");
        }

        return $this->render('produit/show.html.twig', [
            'produit' => $produit,
            'suggestions' => $produitRepository->memeCategorie($produit, 3),
        ]);
    }
}
