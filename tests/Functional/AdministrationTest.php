<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\Admin\CommandeController;
use App\Controller\Admin\DashboardController;
use App\Controller\Admin\ProduitController;
use App\Controller\Admin\StockController;
use App\Entity\Categorie;
use App\Entity\Commande;
use App\Entity\Produit;
use App\Enum\StatutCommande;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Back-office : cloisonnement des acces (CDCF 3.3.6) et gestion des
 * produits, stocks et statuts (CDCF 3.3.5).
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(ProduitController::class)]
#[CoversClass(StockController::class)]
#[CoversClass(CommandeController::class)]
class AdministrationTest extends AbstractFonctionnelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function fournirRoutesAdmin(): iterable
    {
        yield 'tableau de bord' => ['/admin'];
        yield 'produits' => ['/admin/produits'];
        yield 'stocks' => ['/admin/stocks'];
        yield 'commandes' => ['/admin/commandes'];
        yield 'categories' => ['/admin/categories'];
    }

    #[DataProvider('fournirRoutesAdmin')]
    public function testUnVisiteurAnonymeEstRedirigeVersLaConnexion(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseRedirects();
        self::assertStringContainsString('/connexion', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * Exigence explicite du dossier (chap. X.2) : un client ne peut pas
     * atteindre le back-office.
     */
    #[DataProvider('fournirRoutesAdmin')]
    public function testUnClientNAccedePasAuBackOffice(string $url): void
    {
        $this->connecterClient();

        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('fournirRoutesAdmin')]
    public function testLAdministrateurAccedeAToutesLesPages(string $url): void
    {
        $this->connecterAdministrateur();

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    // ----- Tableau de bord -----

    public function testLeTableauDeBordAfficheLesIndicateurs(): void
    {
        $this->connecterAdministrateur();

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSame(4, $crawler->filter('.tnb-indicateur')->count());
        self::assertStringContainsString('Commandes du jour', $crawler->text());
        self::assertStringContainsString('Stocks bas', $crawler->text());
    }

    public function testLeTableauDeBordSignaleLesStocksBas(): void
    {
        $this->connecterAdministrateur();

        // "Poires Conference" a 3 unites, sous le seuil d'alerte de 5.
        $crawler = $this->client->request('GET', '/admin');

        self::assertStringContainsString('Stocks a reapprovisionner', $crawler->text());
        self::assertStringContainsString('Poires Conference', $crawler->text());
    }

    // ----- Produits -----

    public function testLAdministrateurPeutCreerUnProduit(): void
    {
        $this->connecterAdministrateur();

        $crawler = $this->client->request('GET', '/admin/produits/nouveau');
        self::assertResponseIsSuccessful();

        $categorie = $this->entityManager->getRepository(Categorie::class)->findOneBy(['nom' => 'Legume']);
        self::assertNotNull($categorie);

        $this->client->submit($crawler->selectButton('Creer le produit')->form(), [
            'produit[nom]' => 'Aubergines',
            'produit[categorie]' => (string) $categorie->getId(),
            'produit[prix]' => '3.40',
            'produit[uniteVente]' => 'KG',
            'produit[origine]' => 'Provence',
            'produit[description]' => 'Aubergines fermes et brillantes.',
            'produit[disponible]' => true,
        ]);

        self::assertResponseRedirects();

        $produit = $this->entityManager->getRepository(Produit::class)->findOneBy(['nom' => 'Aubergines']);

        self::assertNotNull($produit);
        self::assertSame('3.40', $produit->getPrix());
        // Un stock vide est cree avec le produit (relation 1:1 obligatoire).
        self::assertNotNull($produit->getStock());
    }

    public function testLAdministrateurPeutModifierUnProduit(): void
    {
        $this->connecterAdministrateur();
        $produit = $this->produit('Carottes');

        $crawler = $this->client->request('GET', \sprintf('/admin/produits/%d/modifier', $produit->getId()));
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Enregistrer les modifications')->form([
            'produit[prix]' => '2.20',
        ]));

        self::assertResponseRedirects('/admin/produits');
        $this->entityManager->clear();

        self::assertSame('2.20', $this->produit('Carottes')->getPrix());
    }

    public function testLAdministrateurPeutDesactiverUnProduit(): void
    {
        $this->connecterAdministrateur();
        $produit = $this->produit('Carottes');

        $crawler = $this->client->request('GET', '/admin/produits');
        $this->client->submit(
            $crawler->filter(\sprintf('form[action="/admin/produits/%d/basculer"]', $produit->getId()))->form()
        );

        self::assertResponseRedirects('/admin/produits');
        $this->entityManager->clear();

        self::assertFalse($this->produit('Carottes')->isDisponible());

        // Le produit disparait aussitot du catalogue public.
        $crawler = $this->client->request('GET', '/produits');
        self::assertStringNotContainsString('Carottes', $crawler->filter('.tnb-carte')->text());
    }

    /**
     * Supprimer un produit deja commande effacerait l'historique : il est
     * desactive a la place.
     */
    public function testSupprimerUnProduitCommandeLeDesactive(): void
    {
        $this->connecterAdministrateur();
        $produit = $this->produit('Tomates grappe');

        $crawler = $this->client->request('GET', '/admin/produits');
        $this->client->submit(
            $crawler->filter(\sprintf('form[action="/admin/produits/%d/supprimer"]', $produit->getId()))->form()
        );

        $crawler = $this->client->followRedirect();
        $this->entityManager->clear();

        self::assertSelectorExists('.alert-warning');
        self::assertStringContainsString('desactive plutot que supprime', $crawler->text());
        self::assertFalse($this->produit('Tomates grappe')->isDisponible());
    }

    // ----- Stocks -----

    public function testLAdministrateurPeutSaisirUnStock(): void
    {
        $this->connecterAdministrateur();
        $stock = $this->produit('Carottes')->getStock();
        self::assertNotNull($stock);

        $crawler = $this->client->request('GET', \sprintf('/admin/stocks/%d/modifier', $stock->getId()));
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'stock[quantiteAchetee]' => '50',
            'stock[quantiteDisponible]' => '45',
        ]));

        self::assertResponseRedirects('/admin/stocks');
        $this->entityManager->clear();

        self::assertSame(45.0, $this->produit('Carottes')->getQuantiteDisponible());
    }

    public function testLeReapprovisionnementRemetLeStockANeuf(): void
    {
        $this->connecterAdministrateur();
        $stock = $this->produit('Poires Conference')->getStock();
        self::assertNotNull($stock);

        $crawler = $this->client->request('GET', '/admin/stocks');
        $formulaire = $crawler
            ->filter(\sprintf('form[action="/admin/stocks/%d/reapprovisionner"]', $stock->getId()))
            ->form();
        $formulaire['quantite'] = '30';

        $this->client->submit($formulaire);

        self::assertResponseRedirects('/admin/stocks');
        $this->entityManager->clear();

        self::assertSame(30.0, $this->produit('Poires Conference')->getQuantiteDisponible());
    }

    // ----- Commandes et workflow -----

    public function testLAdministrateurVoitToutesLesCommandes(): void
    {
        $this->connecterAdministrateur();

        $crawler = $this->client->request('GET', '/admin/commandes');

        self::assertResponseIsSuccessful();
        // Les fixtures creent 4 commandes, tous clients confondus.
        self::assertSame(4, $crawler->filter('tbody tr')->count());
    }

    public function testLeFiltreParStatutFonctionne(): void
    {
        $this->connecterAdministrateur();

        $crawler = $this->client->request('GET', '/admin/commandes?statut=RECUPEREE');

        self::assertResponseIsSuccessful();
        self::assertSame(2, $crawler->filter('tbody tr')->count());
    }

    public function testLAdministrateurFaitEvoluerLeStatut(): void
    {
        $this->connecterAdministrateur();

        $commande = $this->entityManager->getRepository(Commande::class)
            ->findOneBy(['statut' => StatutCommande::EN_ATTENTE->value]);
        self::assertNotNull($commande);
        $reference = (string) $commande->getReference();

        $crawler = $this->client->request('GET', '/admin/commandes/'.$reference);
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Marquer comme « Preparee »')->form());

        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertSame(StatutCommande::PREPAREE, $this->commande($reference)->getStatut());
    }

    /**
     * Le workflow interdit de sauter l'etape de preparation : le bouton
     * correspondant n'est meme pas propose.
     */
    public function testLesTransitionsInvalidesNeSontPasProposees(): void
    {
        $this->connecterAdministrateur();

        $commande = $this->entityManager->getRepository(Commande::class)
            ->findOneBy(['statut' => StatutCommande::EN_ATTENTE->value]);
        self::assertNotNull($commande);

        $crawler = $this->client->request('GET', '/admin/commandes/'.$commande->getReference());

        self::assertCount(1, $crawler->selectButton('Marquer comme « Preparee »'));
        self::assertCount(0, $crawler->selectButton('Marquer comme « Recuperee »'));
    }

    /**
     * Meme forcee par une requete directe, une transition invalide est
     * refusee par le service metier.
     */
    public function testUneTransitionInvalideForceeEstRefusee(): void
    {
        $this->connecterAdministrateur();

        $commande = $this->entityManager->getRepository(Commande::class)
            ->findOneBy(['statut' => StatutCommande::EN_ATTENTE->value]);
        self::assertNotNull($commande);
        $reference = (string) $commande->getReference();

        $crawler = $this->client->request('GET', '/admin/commandes/'.$reference);
        $jeton = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/commandes/'.$reference.'/statut', [
            '_token' => $jeton,
            'statut' => StatutCommande::RECUPEREE->value,
        ]);

        $crawler = $this->client->followRedirect();

        self::assertSelectorExists('.alert-danger');
        self::assertStringContainsString('Passage impossible', $crawler->text());
        self::assertSame(StatutCommande::EN_ATTENTE, $this->commande($reference)->getStatut());
    }

    public function testAnnulerUneCommandeRemetLesProduitsEnStock(): void
    {
        $this->connecterAdministrateur();

        $commande = $this->entityManager->getRepository(Commande::class)
            ->findOneBy(['statut' => StatutCommande::PREPAREE->value]);
        self::assertNotNull($commande);
        $reference = (string) $commande->getReference();

        // Cette commande contient 3 kg de carottes (fixtures).
        $stockAvant = $this->stockDisponible('Carottes');

        $crawler = $this->client->request('GET', '/admin/commandes/'.$reference);
        $this->client->submit($crawler->selectButton('Marquer comme « Annulee »')->form());
        $this->client->followRedirect();

        self::assertSame(StatutCommande::ANNULEE, $this->commande($reference)->getStatut());
        self::assertSame($stockAvant + 3.0, $this->stockDisponible('Carottes'));
    }

    public function testUnChangementDeStatutNotifieLeClient(): void
    {
        $this->connecterAdministrateur();

        $commande = $this->entityManager->getRepository(Commande::class)
            ->findOneBy(['statut' => StatutCommande::EN_ATTENTE->value]);
        self::assertNotNull($commande);

        $crawler = $this->client->request('GET', '/admin/commandes/'.$commande->getReference());
        $this->client->submit($crawler->selectButton('Marquer comme « Preparee »')->form());

        self::assertEmailCount(1);
    }
}
