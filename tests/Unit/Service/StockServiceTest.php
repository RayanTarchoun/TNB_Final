<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Produit;
use App\Entity\Stock;
use App\Enum\UniteVente;
use App\Exception\StockInsuffisantException;
use App\Repository\StockRepository;
use App\Service\StockService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests du service qui porte la regle "blocage de la commande si le stock
 * est insuffisant" (CDCF 3.3.3).
 *
 * Le depot est monte une seule fois dans setUp() et sert selon les cas de
 * simple bouchon (willReturn) ou de mock verifie (expects) ; l'attribut
 * ci-dessous indique a PHPUnit que ce double usage est deliberé.
 */
#[CoversClass(StockService::class)]
#[AllowMockObjectsWithoutExpectations]
class StockServiceTest extends TestCase
{
    private StockRepository&MockObject $stockRepository;
    private StockService $stockService;

    protected function setUp(): void
    {
        $this->stockRepository = $this->createMock(StockRepository::class);
        $this->stockService = new StockService($this->stockRepository);
    }

    private function creerProduit(float $quantiteDisponible, bool $actif = true): Produit
    {
        $produit = (new Produit())
            ->setNom('Tomates grappe')
            ->setPrix(2.50)
            ->setUniteVente(UniteVente::KG)
            ->setDisponible($actif);

        $stock = (new Stock())
            ->setQuantiteAchetee(max($quantiteDisponible, 10.0))
            ->setQuantiteDisponible($quantiteDisponible);

        $produit->setStock($stock);

        return $produit;
    }

    // ----- estDisponible / verifierDisponibilite -----

    public function testEstDisponibleQuandLeStockCouvreLaDemande(): void
    {
        $produit = $this->creerProduit(5.0);

        self::assertTrue($this->stockService->estDisponible($produit, 5.0));
        self::assertTrue($this->stockService->estDisponible($produit, 2.5));
    }

    public function testNEstPasDisponibleQuandLaDemandeDepasseLeStock(): void
    {
        $produit = $this->creerProduit(5.0);

        self::assertFalse($this->stockService->estDisponible($produit, 5.5));
    }

    public function testUnProduitDesactiveNEstJamaisDisponible(): void
    {
        $produit = $this->creerProduit(50.0, actif: false);

        self::assertFalse($this->stockService->estDisponible($produit, 1.0));
    }

    public function testUnProduitSansStockNEstPasDisponible(): void
    {
        $produit = (new Produit())
            ->setNom('Melon')
            ->setPrix(3.90)
            ->setUniteVente(UniteVente::PIECE);

        self::assertFalse($this->stockService->estDisponible($produit, 1.0));
    }

    public function testVerifierDisponibiliteNeLevePasDExceptionSiLeStockSuffit(): void
    {
        $produit = $this->creerProduit(4.0);

        $this->stockService->verifierDisponibilite($produit, 4.0);

        $this->addToAssertionCount(1);
    }

    public function testVerifierDisponibiliteLeveUneExceptionDetaillee(): void
    {
        $produit = $this->creerProduit(1.5);

        try {
            $this->stockService->verifierDisponibilite($produit, 4.0);
            self::fail('Une StockInsuffisantException etait attendue.');
        } catch (StockInsuffisantException $exception) {
            self::assertSame($produit, $exception->getProduit());
            self::assertSame(4.0, $exception->getQuantiteDemandee());
            self::assertSame(1.5, $exception->getQuantiteDisponible());
            self::assertStringContainsString('Tomates grappe', $exception->messageUtilisateur());
        }
    }

    public function testLeMessageUtilisateurSignaleUnProduitEpuise(): void
    {
        $produit = $this->creerProduit(0.0);

        $exception = new StockInsuffisantException($produit, 1.0, 0.0);

        self::assertStringContainsString('epuise', $exception->messageUtilisateur());
    }

    // ----- decrementer -----

    public function testDecrementerReduitLaQuantiteDisponible(): void
    {
        $produit = $this->creerProduit(10.0);

        $this->stockRepository
            ->expects(self::once())
            ->method('trouverPourMiseAJour')
            ->with($produit)
            ->willReturn($produit->getStock());

        $stock = $this->stockService->decrementer($produit, 3.5);

        self::assertSame(6.5, $stock->getQuantiteDisponibleFloat());
    }

    public function testDecrementerLeveUneExceptionSiLaDemandeDepasseLeStock(): void
    {
        $produit = $this->creerProduit(2.0);

        $this->stockRepository
            ->method('trouverPourMiseAJour')
            ->willReturn($produit->getStock());

        $this->expectException(StockInsuffisantException::class);

        $this->stockService->decrementer($produit, 2.5);
    }

    /**
     * Le stock relu en base fait foi, meme si l'objet deja charge en memoire
     * annonce une quantite plus confortable : c'est ce qui protege des
     * validations concurrentes.
     */
    public function testDecrementerSAppuieSurLeStockRelu(): void
    {
        $produit = $this->creerProduit(10.0);

        $stockAJour = (new Stock())
            ->setQuantiteAchetee(10.0)
            ->setQuantiteDisponible(1.0);
        $stockAJour->setProduit($produit);

        $this->stockRepository
            ->method('trouverPourMiseAJour')
            ->willReturn($stockAJour);

        $this->expectException(StockInsuffisantException::class);

        $this->stockService->decrementer($produit, 4.0);
    }

    public function testDecrementerEchoueSansStockEnBase(): void
    {
        $produit = (new Produit())
            ->setNom('Melon')
            ->setPrix(3.90)
            ->setUniteVente(UniteVente::PIECE);

        $this->stockRepository
            ->method('trouverPourMiseAJour')
            ->willReturn(null);

        $this->expectException(StockInsuffisantException::class);

        $this->stockService->decrementer($produit, 1.0);
    }

    // ----- restituer -----

    public function testRestituerRemetLaQuantiteEnStock(): void
    {
        $produit = $this->creerProduit(2.0);
        $produit->getStock()?->setQuantiteAchetee(10.0);

        $this->stockRepository
            ->method('trouverPourMiseAJour')
            ->willReturn($produit->getStock());

        $this->stockService->restituer($produit, 3.0);

        self::assertSame(5.0, $produit->getQuantiteDisponible());
    }

    public function testRestituerIgnoreLesQuantitesNulles(): void
    {
        $produit = $this->creerProduit(2.0);

        $this->stockRepository
            ->expects(self::never())
            ->method('trouverPourMiseAJour');

        $this->stockService->restituer($produit, 0.0);

        self::assertSame(2.0, $produit->getQuantiteDisponible());
    }

    // ----- reapprovisionner -----

    public function testReapprovisionnerRemetLeStockANeuf(): void
    {
        $produit = $this->creerProduit(1.0);
        $stock = $produit->getStock();
        self::assertNotNull($stock);

        $this->stockRepository->expects(self::once())->method('save')->with($stock);

        $this->stockService->reapprovisionner($stock, 25.0);

        self::assertSame(25.0, $stock->getQuantiteAcheteeFloat());
        self::assertSame(25.0, $stock->getQuantiteDisponibleFloat());
        self::assertSame(
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            $stock->getDateMarche()?->format('Y-m-d')
        );
    }
}
