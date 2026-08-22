<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Produit;
use App\Entity\Stock;
use App\Enum\UniteVente;
use App\Exception\StockInsuffisantException;
use App\Repository\ProduitRepository;
use App\Repository\StockRepository;
use App\Service\PanierService;
use App\Service\StockService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Le panier vit en session : ces tests utilisent une session en memoire.
 *
 * Le depot de produits sert de bouchon (il rejoue un catalogue en memoire)
 * sans que chaque test ait a verifier ses appels.
 */
#[CoversClass(PanierService::class)]
#[AllowMockObjectsWithoutExpectations]
class PanierServiceTest extends TestCase
{
    private ProduitRepository&MockObject $produitRepository;
    private PanierService $panierService;

    /** @var array<int, Produit> */
    private array $catalogue = [];

    protected function setUp(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $requete = new Request();
        $requete->setSession($session);

        $pile = new RequestStack();
        $pile->push($requete);

        $this->produitRepository = $this->createMock(ProduitRepository::class);

        // Le service de stock est reel : sa logique de disponibilite ne
        // depend que de l'entite, pas de la base.
        $stockService = new StockService($this->createMock(StockRepository::class));

        $this->panierService = new PanierService($pile, $this->produitRepository, $stockService);

        $this->produitRepository
            ->method('findBy')
            ->willReturnCallback(function (array $criteres): array {
                $ids = (array) ($criteres['id'] ?? []);

                return array_values(array_filter(
                    $this->catalogue,
                    static fn (Produit $produit): bool => \in_array($produit->getId(), $ids, true)
                ));
            });
    }

    /**
     * Fabrique un produit avec un identifiant, que Doctrine assignerait
     * normalement a la persistance.
     */
    private function creerProduit(
        int $id,
        string $nom,
        float $prix,
        float $stock,
        bool $actif = true,
        UniteVente $unite = UniteVente::KG,
    ): Produit {
        $produit = (new Produit())
            ->setNom($nom)
            ->setPrix($prix)
            ->setUniteVente($unite)
            ->setDisponible($actif);

        $entiteStock = (new Stock())
            ->setQuantiteAchetee(max($stock, 1.0))
            ->setQuantiteDisponible($stock);

        $produit->setStock($entiteStock);

        $reflexion = new \ReflectionProperty(Produit::class, 'id');
        $reflexion->setValue($produit, $id);

        $this->catalogue[$id] = $produit;

        return $produit;
    }

    // ----- Ajout -----

    public function testLePanierEstVideAuDepart(): void
    {
        self::assertTrue($this->panierService->estVide());
        self::assertSame(0, $this->panierService->getNombreArticles());
        self::assertSame(0.0, $this->panierService->getTotal());
        self::assertSame([], $this->panierService->getContenu());
    }

    public function testAjouterCreeUneLigne(): void
    {
        $produit = $this->creerProduit(1, 'Tomates grappe', 2.50, 10.0);

        $quantite = $this->panierService->ajouter($produit, 2.0);

        self::assertSame(2.0, $quantite);
        self::assertSame(1, $this->panierService->getNombreArticles());
        self::assertSame(5.0, $this->panierService->getTotal());
    }

    public function testAjouterDeuxFoisCumuleLesQuantites(): void
    {
        $produit = $this->creerProduit(1, 'Tomates grappe', 2.50, 10.0);

        $this->panierService->ajouter($produit, 1.5);
        $quantite = $this->panierService->ajouter($produit, 2.0);

        self::assertSame(3.5, $quantite);
        self::assertSame(1, $this->panierService->getNombreArticles());
        self::assertSame(8.75, $this->panierService->getTotal());
    }

    public function testAjouterRefuseDeDepasserLeStock(): void
    {
        $produit = $this->creerProduit(1, 'Poires Conference', 3.10, 2.0);

        $this->expectException(StockInsuffisantException::class);

        $this->panierService->ajouter($produit, 3.0);
    }

    /**
     * Le controle porte sur le cumul, pas sur le seul ajout : deux ajouts
     * de 1,5 kg sur un stock de 2 kg doivent echouer au second.
     */
    public function testLeControleDeStockPorteSurLeCumul(): void
    {
        $produit = $this->creerProduit(1, 'Poires Conference', 3.10, 2.0);
        $this->panierService->ajouter($produit, 1.5);

        $this->expectException(StockInsuffisantException::class);

        $this->panierService->ajouter($produit, 1.0);
    }

    public function testAjouterRefuseUneQuantiteNulle(): void
    {
        $produit = $this->creerProduit(1, 'Carottes', 1.80, 10.0);

        $this->expectException(\InvalidArgumentException::class);

        $this->panierService->ajouter($produit, 0);
    }

    // ----- Modification et suppression -----

    public function testDefinirQuantiteRemplaceLaQuantite(): void
    {
        $produit = $this->creerProduit(1, 'Tomates grappe', 2.50, 10.0);
        $this->panierService->ajouter($produit, 2.0);

        $this->panierService->definirQuantite($produit, 4.0);

        self::assertSame(4.0, $this->panierService->getQuantite($produit));
        self::assertSame(10.0, $this->panierService->getTotal());
    }

    public function testDefinirUneQuantiteNulleRetireLaLigne(): void
    {
        $produit = $this->creerProduit(1, 'Tomates grappe', 2.50, 10.0);
        $this->panierService->ajouter($produit, 2.0);

        $this->panierService->definirQuantite($produit, 0);

        self::assertTrue($this->panierService->estVide());
    }

    public function testSupprimerRetireLaLigne(): void
    {
        $produit = $this->creerProduit(1, 'Tomates grappe', 2.50, 10.0);
        $this->panierService->ajouter($produit, 2.0);

        $this->panierService->supprimer(1);

        self::assertTrue($this->panierService->estVide());
    }

    public function testViderSupprimeToutesLesLignes(): void
    {
        $this->panierService->ajouter($this->creerProduit(1, 'Tomates grappe', 2.50, 10.0), 1.0);
        $this->panierService->ajouter($this->creerProduit(2, 'Carottes', 1.80, 10.0), 1.0);

        $this->panierService->vider();

        self::assertTrue($this->panierService->estVide());
    }

    // ----- Contenu et coherence -----

    public function testLeContenuExposeLesSousTotaux(): void
    {
        $this->panierService->ajouter($this->creerProduit(1, 'Tomates grappe', 2.50, 10.0), 2.0);
        $this->panierService->ajouter($this->creerProduit(2, 'Pommes Gala', 3.20, 10.0), 1.5);

        $contenu = $this->panierService->getContenu();

        self::assertCount(2, $contenu);
        self::assertSame(5.0, $contenu[0]->getSousTotal());
        self::assertSame(4.8, $contenu[1]->getSousTotal());
        self::assertSame(9.8, $this->panierService->getTotal());
    }

    /**
     * Un produit retire de la vente entre-temps disparait du panier plutot
     * que de provoquer une erreur a la validation.
     */
    public function testUnProduitDesactiveEstRetireDuPanier(): void
    {
        $produit = $this->creerProduit(1, 'Melon charentais', 3.90, 10.0);
        $this->panierService->ajouter($produit, 2.0);

        $produit->setDisponible(false);

        self::assertSame([], $this->panierService->getContenu());
        self::assertTrue($this->panierService->estVide());
    }

    public function testUnProduitSupprimeDisparaitDuPanier(): void
    {
        $produit = $this->creerProduit(1, 'Tomates grappe', 2.50, 10.0);
        $this->panierService->ajouter($produit, 2.0);

        unset($this->catalogue[1]);

        self::assertSame([], $this->panierService->getContenu());
    }

    public function testUneBaisseDeStockRendLaLigneIndisponible(): void
    {
        $produit = $this->creerProduit(1, 'Tomates grappe', 2.50, 10.0);
        $this->panierService->ajouter($produit, 6.0);

        $produit->getStock()?->setQuantiteDisponible(2.0);

        $indisponibles = $this->panierService->lignesIndisponibles();

        self::assertCount(1, $indisponibles);
        self::assertSame('Tomates grappe', $indisponibles[0]->produit->getNom());
    }

    public function testAjusterAuStockRameneLesQuantitesAuDisponible(): void
    {
        $produit = $this->creerProduit(1, 'Tomates grappe', 2.50, 10.0);
        $this->panierService->ajouter($produit, 6.0);
        $produit->getStock()?->setQuantiteDisponible(2.0);

        $ajustes = $this->panierService->ajusterAuStock();

        self::assertSame(['Tomates grappe'], $ajustes);
        self::assertSame(2.0, $this->panierService->getQuantite($produit));
        self::assertSame([], $this->panierService->lignesIndisponibles());
    }

    public function testAjusterAuStockRetireUnProduitEpuise(): void
    {
        $produit = $this->creerProduit(1, 'Tomates grappe', 2.50, 10.0);
        $this->panierService->ajouter($produit, 6.0);
        $produit->getStock()?->setQuantiteDisponible(0.0);

        $this->panierService->ajusterAuStock();

        self::assertTrue($this->panierService->estVide());
    }

    public function testContientIndiqueLaPresenceDUnProduit(): void
    {
        $tomates = $this->creerProduit(1, 'Tomates grappe', 2.50, 10.0);
        $carottes = $this->creerProduit(2, 'Carottes', 1.80, 10.0);

        $this->panierService->ajouter($tomates, 1.0);

        self::assertTrue($this->panierService->contient($tomates));
        self::assertFalse($this->panierService->contient($carottes));
    }
}
