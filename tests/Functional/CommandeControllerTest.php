<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\CommandeController;
use App\Entity\Commande;
use App\Enum\StatutCommande;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Mime\Email;

/**
 * Scenario central : validation d'une commande, historique et annulation
 * (CDCF 3.3.4, diagramme de sequence 7.2.2).
 */
#[CoversClass(CommandeController::class)]
class CommandeControllerTest extends AbstractFonctionnelTestCase
{
    /**
     * Exigence du parcours utilisateur (Jalon 2, 5.2 etape 4).
     */
    public function testUnVisiteurNonConnecteEstRedirigeVersLaConnexion(): void
    {
        $this->ajouterAuPanier($this->produit('Tomates grappe'), 2.0);
        $this->client->followRedirect();

        $this->client->request('GET', '/commande/valider');

        self::assertResponseRedirects();
        self::assertStringContainsString('/connexion', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testLHistoriqueExigeUneConnexion(): void
    {
        $this->client->request('GET', '/mes-commandes');

        self::assertResponseRedirects();
        self::assertStringContainsString('/connexion', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testUnPanierVideRenvoieAuCatalogue(): void
    {
        $this->connecterClient();

        $this->client->request('GET', '/commande/valider');

        self::assertResponseRedirects('/produits');
    }

    public function testLaPageDeValidationAfficheLeRecapitulatif(): void
    {
        $this->connecterClient();
        $this->ajouterAuPanier($this->produit('Tomates grappe'), 2.0);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/commande/valider');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Valider ma commande');
        self::assertStringContainsString('Tomates grappe', $crawler->text());
        self::assertStringContainsString('5,00 EUR', $crawler->text());
    }

    public function testUnClientPeutValiderSaCommande(): void
    {
        $this->connecterClient();
        $stockAvant = $this->stockDisponible('Tomates grappe');

        $this->ajouterAuPanier($this->produit('Tomates grappe'), 2.0);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/commande/valider');
        $this->client->submit($crawler->selectButton('Confirmer ma commande')->form([
            'validation_commande[commentaire]' => 'Je passerai vers 9h.',
        ]));

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('votre commande est enregistree', mb_strtolower($crawler->text()));

        // Le stock a bien ete decremente (CDCF 3.3.3).
        self::assertSame($stockAvant - 2.0, $this->stockDisponible('Tomates grappe'));

        // Le panier est vide apres validation.
        $crawler = $this->client->request('GET', '/panier');
        self::assertStringContainsString('Votre panier est vide', $crawler->text());
    }

    public function testLaCommandeValideeEstPersisteeAvecSesLignes(): void
    {
        $client = $this->connecterClient();

        $this->ajouterAuPanier($this->produit('Tomates grappe'), 2.0);
        $this->client->followRedirect();
        $this->ajouterAuPanier($this->produit('Carottes'), 3.0);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/commande/valider');
        $this->client->submit($crawler->selectButton('Confirmer ma commande')->form());
        $this->client->followRedirect();

        $this->entityManager->clear();

        // Tri sur l'id : une commande des fixtures partage la meme seconde
        // que celle qui vient d'etre creee, le tri par date serait ambigu.
        $commandes = $this->entityManager->getRepository(Commande::class)
            ->findBy(['utilisateur' => $client->getId()], ['id' => 'DESC']);

        $derniere = $commandes[0];

        self::assertCount(2, $derniere->getLignes());
        self::assertSame(StatutCommande::EN_ATTENTE, $derniere->getStatut());
        // 2 x 2,50 + 3 x 1,80 = 10,40
        self::assertSame('10.40', $derniere->getMontantTotal());
        self::assertMatchesRegularExpression('/^TNB-\d{8}-[0-9A-F]{4}$/', (string) $derniere->getReference());
    }

    public function testUnEmailDeConfirmationEstEnvoye(): void
    {
        $this->connecterClient();
        $this->ajouterAuPanier($this->produit('Tomates grappe'), 1.0);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/commande/valider');
        $this->client->submit($crawler->selectButton('Confirmer ma commande')->form());

        self::assertEmailCount(1);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertStringContainsString('commande', mb_strtolower((string) $email->getSubject()));
        self::assertSame('client@tarchoun.fr', $email->getTo()[0]->getAddress());
    }

    /**
     * Si le stock s'epuise entre l'ajout au panier et la validation, la
     * commande est refusee et le client renvoye vers son panier.
     */
    public function testUneCommandeEstBloqueeSiLeStockAChute(): void
    {
        $this->connecterClient();

        $this->ajouterAuPanier($this->produit('Tomates grappe'), 6.0);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/commande/valider');
        $formulaire = $crawler->selectButton('Confirmer ma commande')->form();

        // Un autre client a vide le stock entre-temps.
        $produit = $this->produit('Tomates grappe');
        $produit->getStock()?->setQuantiteDisponible(1.0);
        $this->entityManager->flush();

        $this->client->submit($formulaire);

        self::assertResponseRedirects('/panier');
        $crawler = $this->client->followRedirect();

        self::assertSelectorExists('.alert-danger');
        self::assertStringContainsString('Stock insuffisant', $crawler->text());

        // Aucune commande n'a ete creee et le stock n'a pas bouge.
        self::assertSame(1.0, $this->stockDisponible('Tomates grappe'));
    }

    // ----- Historique et cloisonnement -----

    public function testLHistoriqueNAfficheQueSesPropresCommandes(): void
    {
        $this->connecterClient();

        $crawler = $this->client->request('GET', '/mes-commandes');

        self::assertResponseIsSuccessful();
        // Les fixtures donnent 2 commandes a Sophie Bernard.
        self::assertSame(2, $crawler->filter('tbody tr')->count());
    }

    public function testUnClientNePeutPasVoirLaCommandeDunAutre(): void
    {
        $this->connecterClient();

        // Commande appartenant a Marc Dubois dans les fixtures.
        $commande = $this->entityManager->getRepository(Commande::class)
            ->findOneBy(['statut' => StatutCommande::PREPAREE->value]);

        self::assertNotNull($commande);

        $this->client->request('GET', '/mes-commandes/'.$commande->getReference());

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnClientVoitLeDetailDeSaCommande(): void
    {
        $client = $this->connecterClient();
        $commande = $client->getCommandes()->first();

        $crawler = $this->client->request('GET', '/mes-commandes/'.$commande->getReference());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', (string) $commande->getReference());
        self::assertStringContainsString($commande->getMontantTotal(), str_replace(',', '.', $crawler->text()));
    }

    // ----- Annulation -----

    public function testUnClientPeutAnnulerUneCommandeEnAttente(): void
    {
        $client = $this->connecterClient();

        $commande = $this->entityManager->getRepository(Commande::class)
            ->findOneBy(['utilisateur' => $client->getId(), 'statut' => StatutCommande::EN_ATTENTE->value]);

        self::assertNotNull($commande);
        $reference = (string) $commande->getReference();
        $stockAvant = $this->stockDisponible('Tomates grappe');

        $crawler = $this->client->request('GET', '/mes-commandes/'.$reference);
        $this->client->submit($crawler->selectButton('Annuler ma commande')->form());

        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertSame(StatutCommande::ANNULEE, $this->commande($reference)->getStatut());

        // Les 2 kg de tomates de cette commande sont remis en vente.
        self::assertSame($stockAvant + 2.0, $this->stockDisponible('Tomates grappe'));
    }

    public function testUneCommandeRecupereeNestPlusAnnulable(): void
    {
        $client = $this->connecterClient();

        $commande = $this->entityManager->getRepository(Commande::class)
            ->findOneBy(['utilisateur' => $client->getId(), 'statut' => StatutCommande::RECUPEREE->value]);

        self::assertNotNull($commande);

        $crawler = $this->client->request('GET', '/mes-commandes/'.$commande->getReference());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectButton('Annuler ma commande'));
    }
}
