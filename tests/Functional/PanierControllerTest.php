<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\PanierController;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Gestion du panier (CDCF 3.3.4) et blocage sur stock insuffisant (3.3.3).
 */
#[CoversClass(PanierController::class)]
class PanierControllerTest extends AbstractFonctionnelTestCase
{
    public function testLePanierEstAccessibleSansEtreConnecte(): void
    {
        $this->client->request('GET', '/panier');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mon panier');
        self::assertSelectorTextContains('.tnb-vide', 'Votre panier est vide');
    }

    public function testAjouterUnProduitMetAJourLeRecapitulatif(): void
    {
        $this->ajouterAuPanier($this->produit('Tomates grappe'), 2.0);

        $crawler = $this->client->followRedirect();

        self::assertSelectorExists('.alert-success');
        self::assertStringContainsString('ajoute a votre panier', $crawler->text());

        $crawler = $this->client->request('GET', '/panier');

        self::assertStringContainsString('Tomates grappe', $crawler->text());
        // 2 kg x 2,50 EUR
        self::assertStringContainsString('5,00 EUR', $crawler->filter('.tnb-total')->text());
    }

    public function testLeBadgeDuHeaderRefleteLeNombreDArticles(): void
    {
        $crawler = $this->client->request('GET', '/produits');
        self::assertSame('0', trim($crawler->filter('.tnb-badge-panier')->first()->text()));

        $this->ajouterAuPanier($this->produit('Tomates grappe'), 1.0);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/produits');
        self::assertSame('1', trim($crawler->filter('.tnb-badge-panier')->first()->text()));
    }

    public function testAjouterDeuxProduitsCreeDeuxLignes(): void
    {
        $this->ajouterAuPanier($this->produit('Tomates grappe'), 2.0);
        $this->client->followRedirect();

        $this->ajouterAuPanier($this->produit('Carottes'), 3.0);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/panier');

        self::assertSame(2, $crawler->filter('tbody tr')->count());
        // 2 x 2,50 + 3 x 1,80 = 10,40
        self::assertStringContainsString('10,40 EUR', $crawler->filter('.tnb-total')->text());
    }

    /**
     * Regle centrale du CDCF 3.3.3 : impossible de commander plus que le stock.
     */
    public function testAjouterPlusQueLeStockEstRefuse(): void
    {
        // "Poires Conference" : 3 unites en stock.
        $this->ajouterAuPanier($this->produit('Poires Conference'), 10.0);

        $crawler = $this->client->followRedirect();

        self::assertSelectorExists('.alert-danger');
        self::assertStringContainsString('Stock insuffisant', $crawler->text());

        $crawler = $this->client->request('GET', '/panier');
        self::assertStringContainsString('Votre panier est vide', $crawler->text());
    }

    public function testLeControleDeStockPorteSurLeCumulDesAjouts(): void
    {
        $poires = $this->produit('Poires Conference');

        $this->ajouterAuPanier($poires, 2.0);
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-success');

        // 2 + 2 = 4 > 3 disponibles
        $this->ajouterAuPanier($poires, 2.0);
        $crawler = $this->client->followRedirect();

        self::assertSelectorExists('.alert-danger');
        self::assertStringContainsString('il ne reste que', $crawler->text());
    }

    public function testModifierLaQuantiteRecalculeLeTotal(): void
    {
        $produit = $this->produit('Tomates grappe');
        $this->ajouterAuPanier($produit, 2.0);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/panier');
        $formulaire = $crawler->filter(\sprintf('form[action="/panier/modifier/%d"]', $produit->getId()))->form();
        $formulaire['quantite'] = '4';

        $this->client->submit($formulaire);
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('10,00 EUR', $crawler->filter('.tnb-total')->text());
    }

    public function testMettreLaQuantiteAZeroRetireLaLigne(): void
    {
        $produit = $this->produit('Tomates grappe');
        $this->ajouterAuPanier($produit, 2.0);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/panier');
        $formulaire = $crawler->filter(\sprintf('form[action="/panier/modifier/%d"]', $produit->getId()))->form();
        $formulaire['quantite'] = '0';

        $this->client->submit($formulaire);
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('Votre panier est vide', $crawler->text());
    }

    public function testSupprimerUneLigne(): void
    {
        $produit = $this->produit('Tomates grappe');
        $this->ajouterAuPanier($produit, 2.0);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/panier');
        $this->client->submit(
            $crawler->filter(\sprintf('form[action="/panier/supprimer/%d"]', $produit->getId()))->form()
        );
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('Votre panier est vide', $crawler->text());
    }

    public function testViderLePanier(): void
    {
        $this->ajouterAuPanier($this->produit('Tomates grappe'), 2.0);
        $this->client->followRedirect();
        $this->ajouterAuPanier($this->produit('Carottes'), 1.0);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/panier');
        $this->client->submit($crawler->selectButton('Vider le panier')->form());
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('Votre panier est vide', $crawler->text());
    }

    /**
     * Le stock peut baisser entre l'ajout au panier et la consultation :
     * le panier signale la ligne et propose de l'ajuster.
     */
    public function testUneBaisseDeStockEstSignaleeDansLePanier(): void
    {
        $produit = $this->produit('Tomates grappe');
        $this->ajouterAuPanier($produit, 6.0);
        $this->client->followRedirect();

        $produit = $this->produit('Tomates grappe');
        $produit->getStock()?->setQuantiteDisponible(2.0);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/panier');

        self::assertSelectorExists('.alert-warning');
        self::assertStringContainsString('Le stock a change', $crawler->text());

        $this->client->submit($crawler->selectButton('Ajuster au stock disponible')->form());
        $crawler = $this->client->followRedirect();

        self::assertSelectorExists('.alert-warning');
        // 2 kg x 2,50 EUR
        self::assertStringContainsString('5,00 EUR', $crawler->filter('.tnb-total')->text());
    }

    public function testUnJetonCsrfInvalideEstRejete(): void
    {
        $produit = $this->produit('Tomates grappe');

        $this->client->request('POST', \sprintf('/panier/ajouter/%d', $produit->getId()), [
            '_token' => 'jeton-invalide',
            'quantite' => '2',
        ]);

        $this->client->followRedirect();

        self::assertSelectorExists('.alert-danger');

        $crawler = $this->client->request('GET', '/panier');
        self::assertStringContainsString('Votre panier est vide', $crawler->text());
    }

    /**
     * Le panier ne consomme aucun stock : celui-ci n'est decremente qu'a la
     * validation de la commande.
     */
    public function testLePanierNeDecrementePasLeStock(): void
    {
        $stockAvant = $this->stockDisponible('Tomates grappe');

        $this->ajouterAuPanier($this->produit('Tomates grappe'), 3.0);
        $this->client->followRedirect();

        self::assertSame($stockAvant, $this->stockDisponible('Tomates grappe'));
    }
}
