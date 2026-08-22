<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\Produit;
use App\Enum\StatutCommande;
use App\Enum\UniteVente;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Commande::class)]
#[CoversClass(LigneCommande::class)]
class CommandeTest extends TestCase
{
    private function creerProduit(string $nom, float $prix): Produit
    {
        return (new Produit())
            ->setNom($nom)
            ->setPrix($prix)
            ->setUniteVente(UniteVente::KG);
    }

    private function creerLigne(Produit $produit, float $quantite): LigneCommande
    {
        return (new LigneCommande())
            ->setProduit($produit)
            ->setPrixUnitaire($produit->getPrixFloat())
            ->setQuantite($quantite);
    }

    public function testSousTotalEstLeProduitDeLaQuantiteEtDuPrix(): void
    {
        $ligne = $this->creerLigne($this->creerProduit('Tomates grappe', 2.50), 3.0);

        self::assertSame(7.5, $ligne->getSousTotalFloat());
    }

    public function testSousTotalEstRecalculeQuandLaQuantiteChange(): void
    {
        $ligne = $this->creerLigne($this->creerProduit('Carottes', 1.80), 2.0);
        self::assertSame(3.6, $ligne->getSousTotalFloat());

        $ligne->setQuantite(5.0);

        self::assertSame(9.0, $ligne->getSousTotalFloat());
    }

    public function testCalculerTotalAdditionneLesSousTotaux(): void
    {
        $commande = new Commande();
        $commande->addLigne($this->creerLigne($this->creerProduit('Tomates grappe', 2.50), 2.0)); // 5.00
        $commande->addLigne($this->creerLigne($this->creerProduit('Pommes Gala', 3.20), 1.5));    // 4.80
        $commande->addLigne($this->creerLigne($this->creerProduit('Carottes', 1.80), 3.0));       // 5.40

        self::assertSame(15.2, $commande->calculerTotal());
        self::assertSame(3, $commande->getNombreArticles());
    }

    public function testCalculerTotalVautZeroSansLigne(): void
    {
        self::assertSame(0.0, (new Commande())->calculerTotal());
    }

    public function testRafraichirMontantTotalMemoriseLeCalcul(): void
    {
        $commande = new Commande();
        $commande->addLigne($this->creerLigne($this->creerProduit('Bananes', 1.90), 2.5)); // 4.75

        $commande->rafraichirMontantTotal();

        self::assertSame('4.75', $commande->getMontantTotal());
        self::assertSame(4.75, $commande->getMontantTotalFloat());
    }

    /**
     * Les arrondis au centime doivent rester stables : sans normalisation,
     * l'addition de flottants ferait deriver le total.
     */
    public function testLeTotalResteJusteSurDesPrixNonRonds(): void
    {
        $commande = new Commande();

        for ($i = 0; $i < 3; ++$i) {
            $commande->addLigne($this->creerLigne($this->creerProduit('Citrons', 3.33), 0.1));
        }

        self::assertSame(0.99, $commande->calculerTotal());
    }

    public function testAjouterUneLigneEtablitLaRelationInverse(): void
    {
        $commande = new Commande();
        $ligne = $this->creerLigne($this->creerProduit('Poireaux', 2.10), 1.0);

        $commande->addLigne($ligne);

        self::assertSame($commande, $ligne->getCommande());
        self::assertCount(1, $commande->getLignes());
    }

    public function testRetirerUneLigneRompLaRelationInverse(): void
    {
        $commande = new Commande();
        $ligne = $this->creerLigne($this->creerProduit('Poireaux', 2.10), 1.0);
        $commande->addLigne($ligne);

        $commande->removeLigne($ligne);

        self::assertCount(0, $commande->getLignes());
        self::assertNull($ligne->getCommande());
    }

    public function testUneCommandeEstAnnulableSelonSonStatut(): void
    {
        $commande = new Commande();
        self::assertSame(StatutCommande::EN_ATTENTE, $commande->getStatut());
        self::assertTrue($commande->estAnnulable());

        $commande->setStatut(StatutCommande::RECUPEREE);
        self::assertFalse($commande->estAnnulable());
    }

    /**
     * Le prix unitaire est fige : modifier le tarif du produit apres coup
     * ne doit pas alterer la commande (Jalon 3, 6.3).
     */
    public function testLePrixUnitaireEstFigeALaCommande(): void
    {
        $produit = $this->creerProduit('Fraises Gariguette', 5.90);
        $ligne = $this->creerLigne($produit, 2.0);

        $produit->setPrix(7.50);

        self::assertSame(5.90, $ligne->getPrixUnitaireFloat());
        self::assertSame(11.8, $ligne->getSousTotalFloat());
    }
}
