<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\ProduitController;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Catalogue public : consultation, recherche et filtrage (CDCF 3.3.2).
 */
#[CoversClass(ProduitController::class)]
class ProduitControllerTest extends AbstractFonctionnelTestCase
{
    public function testLaPageAccueilAfficheDesProduits(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Commandez vos fruits et legumes');
        self::assertGreaterThan(0, $crawler->filter('.tnb-carte')->count());
    }

    public function testLeCatalogueEstAccessibleSansCompte(): void
    {
        $crawler = $this->client->request('GET', '/produits');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nos fruits et legumes');
        self::assertGreaterThan(0, $crawler->filter('.tnb-carte')->count());
    }

    public function testLeCatalogueMasqueLesProduitsDesactives(): void
    {
        $crawler = $this->client->request('GET', '/produits');

        // "Melon charentais" est cree desactive par les fixtures.
        self::assertStringNotContainsString('Melon charentais', $crawler->text());
    }

    public function testLaRechercheFiltreLesProduits(): void
    {
        $crawler = $this->client->request('GET', '/produits?q=Tomates');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.tnb-carte')->count());
        self::assertStringContainsString('Tomates grappe', $crawler->text());
    }

    public function testUneRechercheSansResultatAfficheUnMessage(): void
    {
        $crawler = $this->client->request('GET', '/produits?q=zzzzintrouvable');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.tnb-carte')->count());
        self::assertStringContainsString('Aucun produit ne correspond', $crawler->text());
    }

    public function testLeFiltreParCategorieNeRenvoieQueCetteCategorie(): void
    {
        $crawler = $this->client->request('GET', '/produits');
        $identifiantFruit = (int) $crawler
            ->filter('input[name="categorie[]"]')
            ->reduce(static fn ($noeud): bool => str_contains($noeud->ancestors()->first()->text(), 'Fruit'))
            ->attr('value');

        $crawler = $this->client->request('GET', '/produits?categorie[]='.$identifiantFruit);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.tnb-carte')->count());
        self::assertSame(
            $crawler->filter('.tnb-carte')->count(),
            $crawler->filter('.tnb-carte .badge-fruit')->count(),
            'Seuls des fruits doivent apparaitre.'
        );
    }

    public function testLeTriParPrixCroissantOrdonneLesResultats(): void
    {
        $crawler = $this->client->request('GET', '/produits?tri=prix_asc');

        self::assertResponseIsSuccessful();

        $prix = $crawler->filter('.tnb-carte .tnb-prix')->each(
            static fn ($noeud): float => (float) str_replace([',', ' EUR', ' '], ['.', '', ''], $noeud->text())
        );

        $attendus = $prix;
        sort($attendus);

        self::assertSame($attendus, $prix);
    }

    public function testLaPaginationLimiteLeNombreDeProduits(): void
    {
        $crawler = $this->client->request('GET', '/produits');

        // 14 produits actifs dans les fixtures, 9 par page.
        self::assertSame(9, $crawler->filter('.tnb-carte')->count());
        self::assertGreaterThan(0, $crawler->filter('.pagination')->count());

        $crawler = $this->client->request('GET', '/produits?page=2');

        self::assertResponseIsSuccessful();
        self::assertSame(5, $crawler->filter('.tnb-carte')->count());
    }

    public function testLaFicheProduitAfficheLePrixEtLeStock(): void
    {
        $produit = $this->produit('Tomates grappe');

        $crawler = $this->client->request('GET', \sprintf('/produits/%d', $produit->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tomates grappe');
        self::assertStringContainsString('2,50 EUR', $crawler->text());
        self::assertStringContainsString('kg disponibles', $crawler->text());
    }

    public function testUnProduitDesactiveRenvoieUne404(): void
    {
        $produit = $this->produit('Melon charentais');

        $this->client->request('GET', \sprintf('/produits/%d', $produit->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnProduitInexistantRenvoieUne404(): void
    {
        $this->client->request('GET', '/produits/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnProduitEnFaibleStockEstSignale(): void
    {
        // "Poires Conference" n'a que 3 unites en stock dans les fixtures.
        $produit = $this->produit('Poires Conference');

        $crawler = $this->client->request('GET', \sprintf('/produits/%d', $produit->getId()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Plus que', $crawler->text());
    }

    public function testLesPagesLegalesSontAccessibles(): void
    {
        $this->client->request('GET', '/mentions-legales');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/politique-de-confidentialite');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Politique de confidentialite');
    }
}
