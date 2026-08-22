<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Produit;
use App\Entity\Stock;
use App\Enum\UniteVente;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verification concrete de la protection contre les injections de scripts
 * (chap. IX.2 : "echappement automatique des sorties").
 *
 * Le CDC technique demande explicitement de tester en injectant des
 * <script> dans les formulaires pour verifier qu'ils ne s'executent pas.
 * Ces tests couvrent les deux formes classiques :
 *   - XSS reflechi   : la charge revient dans la page qui l'a recue ;
 *   - XSS stocke     : la charge est persistee puis reaffichee ailleurs.
 */
class SecuriteXssTest extends AbstractFonctionnelTestCase
{
    private const CHARGE = '<script>alert("xss")</script>';

    /**
     * @return iterable<string, array{string}>
     */
    public static function fournirChargesXss(): iterable
    {
        yield 'balise script' => ['<script>alert(1)</script>'];
        yield 'gestionnaire onerror' => ['<img src=x onerror="alert(1)">'];
        yield 'javascript: dans un lien' => ['<a href="javascript:alert(1)">clic</a>'];
        yield 'balise fermante injectee' => ['"><script>alert(1)</script>'];
    }

    /**
     * XSS reflechi : le terme recherche est reaffiche dans la page de
     * resultats.
     */
    #[DataProvider('fournirChargesXss')]
    public function testLaRechercheEchappeLesChargesXss(string $charge): void
    {
        $this->client->request('GET', '/produits?q='.urlencode($charge));

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        // La charge brute ne doit jamais apparaitre telle quelle...
        self::assertStringNotContainsString($charge, $html);
        // ...alors que le texte, lui, est bien restitue sous forme echappee.
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('onerror="alert', $html);
    }

    public function testLaRechercheAfficheLeTermeSousFormeEchappee(): void
    {
        $this->client->request('GET', '/produits?q='.urlencode(self::CHARGE));

        $html = (string) $this->client->getResponse()->getContent();

        // Twig a converti les chevrons en entites HTML.
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * XSS stocke : un nom de produit malveillant saisi au back-office ne
     * doit pas s'executer sur le catalogue public.
     */
    public function testUnNomDeProduitMalveillantEstEchappeAuCatalogue(): void
    {
        $categorie = $this->produit('Carottes')->getCategorie();
        self::assertNotNull($categorie);

        $produit = (new Produit())
            ->setNom(self::CHARGE)
            ->setPrix(2.00)
            ->setUniteVente(UniteVente::KG)
            ->setCategorie($categorie)
            ->setDisponible(true);

        $stock = (new Stock())
            ->setQuantiteAchetee(10.0)
            ->setQuantiteDisponible(10.0);
        $produit->setStock($stock);

        $this->entityManager->persist($produit);
        $this->entityManager->persist($stock);
        $this->entityManager->flush();

        $this->client->request('GET', \sprintf('/produits/%d', $produit->getId()));

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString(self::CHARGE, $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * XSS stocke via le commentaire de commande, saisi par le client et
     * relu par l'administrateur dans le back-office.
     */
    public function testUnCommentaireDeCommandeMalveillantEstEchappe(): void
    {
        $this->connecterClient();
        $this->ajouterAuPanier($this->produit('Tomates grappe'), 1.0);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/commande/valider');
        $this->client->submit($crawler->selectButton('Confirmer ma commande')->form([
            'validation_commande[commentaire]' => self::CHARGE,
        ]));

        $this->client->followRedirect();

        // Cote client
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::CHARGE, $html);

        // Cote administrateur : la charge traverse la base sans s'executer.
        $this->connecterAdministrateur();
        $this->client->request('GET', '/admin/commandes');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::CHARGE, $html);
        self::assertResponseIsSuccessful();
    }

    /**
     * Le profil est un autre point d'entree persistant : le prenom est
     * reaffiche dans la barre de navigation de chaque page.
     */
    public function testUnPrenomMalveillantEstEchappeDansLaNavigation(): void
    {
        $utilisateur = $this->connecterClient();
        $utilisateur->setPrenom(self::CHARGE);
        $this->entityManager->flush();

        $this->client->request('GET', '/produits');

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString(self::CHARGE, $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Defense en profondeur : les en-tetes de securite sont poses par le
     * vhost Apache (docker/apache/vhost.conf), pas par l'application. Ce
     * test documente la frontiere entre les deux responsabilites.
     */
    public function testLesCookiesDeSessionSontInaccessiblesAuJavaScript(): void
    {
        $this->connecterClient();
        $this->client->request('GET', '/panier');

        $cookie = $this->client->getCookieJar()->get('MOCKSESSID')
            ?? $this->client->getCookieJar()->get('PHPSESSID');

        if (null === $cookie) {
            self::markTestSkipped('Aucun cookie de session emis par le client de test.');
        }

        self::assertTrue($cookie->isHttpOnly(), 'Le cookie de session doit etre HttpOnly.');
    }
}
