<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Produit;
use App\Entity\Stock;
use App\Enum\UniteVente;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * L'entite Stock porte l'invariant "quantite_disponible >= 0".
 */
#[CoversClass(Stock::class)]
class StockTest extends TestCase
{
    private function creerStock(float $achetee, ?float $disponible = null): Stock
    {
        $produit = (new Produit())
            ->setNom('Tomates grappe')
            ->setPrix(2.50)
            ->setUniteVente(UniteVente::KG);

        $stock = (new Stock())
            ->setQuantiteAchetee($achetee)
            ->setQuantiteDisponible($disponible ?? $achetee);

        $stock->setProduit($produit);

        return $stock;
    }

    public function testDecrementerReduitLaQuantiteDisponible(): void
    {
        $stock = $this->creerStock(10.0);

        $stock->decrementer(2.5);

        self::assertSame(7.5, $stock->getQuantiteDisponibleFloat());
        // La quantite achetee n'est jamais modifiee par une commande.
        self::assertSame(10.0, $stock->getQuantiteAcheteeFloat());
    }

    public function testDecrementerRefuseUneQuantiteSuperieureAuStock(): void
    {
        $stock = $this->creerStock(3.0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Stock insuffisant');

        $stock->decrementer(3.5);
    }

    public function testDecrementerRefuseUneQuantiteNulleOuNegative(): void
    {
        $stock = $this->creerStock(5.0);

        $this->expectException(\InvalidArgumentException::class);

        $stock->decrementer(0);
    }

    public function testOnPeutVidercompletementLeStock(): void
    {
        $stock = $this->creerStock(4.0);

        $stock->decrementer(4.0);

        self::assertSame(0.0, $stock->getQuantiteDisponibleFloat());
        self::assertTrue($stock->estEpuise());
    }

    public function testIncrementerRestitueLaQuantiteApresUneAnnulation(): void
    {
        $stock = $this->creerStock(10.0, 4.0);

        $stock->incrementer(3.0);

        self::assertSame(7.0, $stock->getQuantiteDisponibleFloat());
    }

    public function testIncrementerNeDepasseJamaisLaQuantiteAchetee(): void
    {
        $stock = $this->creerStock(10.0, 8.0);

        $stock->incrementer(5.0);

        self::assertSame(10.0, $stock->getQuantiteDisponibleFloat());
    }

    public function testEstDisponiblePourCompareAuStockRestant(): void
    {
        $stock = $this->creerStock(2.0);

        self::assertTrue($stock->estDisponiblePour(1.0));
        self::assertTrue($stock->estDisponiblePour(2.0));
        self::assertFalse($stock->estDisponiblePour(2.5));
        self::assertFalse($stock->estDisponiblePour(0));
    }

    public function testQuantiteReserveeEtPourcentageEcoule(): void
    {
        $stock = $this->creerStock(20.0, 5.0);

        self::assertSame(15.0, $stock->getQuantiteReservee());
        self::assertSame(75, $stock->getPourcentageEcoule());
    }

    public function testPourcentageEcouleVautZeroSansQuantiteAchetee(): void
    {
        $stock = $this->creerStock(0.0);

        self::assertSame(0, $stock->getPourcentageEcoule());
    }

    /**
     * Les DECIMAL(10,2) etant lus comme des chaines, les quantites sont
     * systematiquement normalisees a deux decimales.
     */
    public function testLesQuantitesSontNormaliseesADeuxDecimales(): void
    {
        $stock = $this->creerStock(1.005);

        self::assertSame('1.01', $stock->getQuantiteAchetee());
    }
}
