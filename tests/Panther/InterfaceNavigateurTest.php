<?php

declare(strict_types=1);

namespace App\Tests\Panther;

use App\Tests\Support\ChargementFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\WebDriverDimension;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

/**
 * Tests d'interface dans un vrai navigateur (Chrome pilote par Panther).
 *
 * Ils verifient ce que le client HTTP simule ne peut pas voir : l'execution
 * effective du JavaScript de Bootstrap et le rendu responsive exige par le
 * CDC technique ("l'interface devra etre responsive, adaptee desktop et
 * mobile").
 *
 * Panther demarre son propre serveur web dans l'environnement "panther",
 * qui vise la base tnb_test (config/packages/doctrine.yaml).
 */
class InterfaceNavigateurTest extends PantherTestCase
{
    use ChargementFixturesTrait;

    private const MOBILE = [375, 812];   // iPhone X, largeur de reference du Jalon 2
    private const DESKTOP = [1440, 900]; // largeur de reference des maquettes

    protected function setUp(): void
    {
        // Le noyau de test sert uniquement a preparer les donnees ; le
        // navigateur, lui, dialogue avec le serveur lance par Panther.
        self::bootKernel();

        $conteneur = static::getContainer();
        $this->reinitialiserLaBase($conteneur->get(EntityManagerInterface::class), $conteneur);

        self::ensureKernelShutdown();
    }

    /**
     * @param array{int, int} $dimensions
     */
    private function naviguer(array $dimensions, string $url): Client
    {
        $client = static::createPantherClient([
            'browser' => static::CHROME,
            'connection_timeout_in_ms' => 30000,
            'request_timeout_in_ms' => 30000,
        ]);

        $client->manage()->window()->setSize(new WebDriverDimension($dimensions[0], $dimensions[1]));
        $client->request('GET', $url);

        return $client;
    }

    // ----- Rendu responsive -----

    /**
     * Sur mobile, la navigation est repliee derriere le bouton hamburger ;
     * sur desktop, elle est deployee (Jalon 2, 1.2).
     */
    public function testLaNavigationEstReplieeSurMobileEtDeployeeSurDesktop(): void
    {
        $client = $this->naviguer(self::MOBILE, '/');
        $client->waitForVisibility('.navbar-toggler');

        self::assertTrue(
            $client->getCrawler()->filter('.navbar-toggler')->getElement(0)->isDisplayed(),
            'Le bouton hamburger doit etre visible sur mobile.'
        );
        self::assertFalse(
            $client->getCrawler()->filter('#navigationPrincipale')->getElement(0)->isDisplayed(),
            'La navigation doit etre repliee sur mobile.'
        );

        $client->manage()->window()->setSize(new WebDriverDimension(...self::DESKTOP));
        $client->refreshCrawler();
        $client->waitForVisibility('#navigationPrincipale');

        self::assertTrue(
            $client->getCrawler()->filter('#navigationPrincipale')->getElement(0)->isDisplayed(),
            'La navigation doit etre deployee sur desktop.'
        );
    }

    /**
     * Le JavaScript de Bootstrap est bien charge et fonctionnel : cliquer
     * sur le hamburger deploie reellement le menu.
     */
    public function testLeMenuHamburgerSeDeploieAuClic(): void
    {
        $client = $this->naviguer(self::MOBILE, '/');
        $client->waitForVisibility('.navbar-toggler');

        $client->getCrawler()->filter('.navbar-toggler')->getElement(0)->click();
        $client->waitForVisibility('#navigationPrincipale', 5);

        self::assertTrue(
            $client->getCrawler()->filter('#navigationPrincipale')->getElement(0)->isDisplayed()
        );
    }

    /**
     * La grille du catalogue passe de trois colonnes sur desktop a deux sur
     * mobile (Jalon 2, 3.5).
     */
    public function testLaGrilleDuCatalogueSAdapteALaLargeur(): void
    {
        $client = $this->naviguer(self::DESKTOP, '/produits');
        $client->waitForVisibility('.tnb-carte');

        $largeurDesktop = $client->getCrawler()->filter('.tnb-carte')->getElement(0)->getSize()->getWidth();

        $client->manage()->window()->setSize(new WebDriverDimension(...self::MOBILE));
        $client->refreshCrawler();
        $client->waitForVisibility('.tnb-carte');

        $largeurMobile = $client->getCrawler()->filter('.tnb-carte')->getElement(0)->getSize()->getWidth();

        self::assertLessThan(
            $largeurDesktop,
            $largeurMobile,
            'Les cartes produit doivent retrecir sur mobile.'
        );
    }

    /**
     * Aucune barre de defilement horizontale : le contenu ne deborde jamais
     * de la fenetre, y compris sur la plus petite largeur cible.
     */
    public function testAucunDebordementHorizontalSurMobile(): void
    {
        $client = $this->naviguer(self::MOBILE, '/produits');
        $client->waitForVisibility('.tnb-carte');

        $debordement = $client->executeScript(
            'return document.documentElement.scrollWidth - document.documentElement.clientWidth;'
        );

        self::assertLessThanOrEqual(
            1, // tolerance d'un pixel pour les arrondis de rendu
            (int) $debordement,
            'La page ne doit pas defiler horizontalement sur mobile.'
        );
    }

    // ----- Parcours reel dans le navigateur -----

    /**
     * Ajout au panier de bout en bout : le badge du header est bien
     * incremente apres le rechargement.
     */
    public function testAjouterUnProduitMetAJourLeBadgeDuPanier(): void
    {
        $client = $this->naviguer(self::DESKTOP, '/produits');
        $client->waitForVisibility('.tnb-carte');

        self::assertSame('0', trim($client->getCrawler()->filter('.tnb-badge-panier')->first()->text()));

        $client->getCrawler()->filter('.tnb-carte button[type="submit"]')->getElement(0)->click();
        $client->waitForVisibility('.alert-success', 10);

        self::assertSame('1', trim($client->getCrawler()->filter('.tnb-badge-panier')->first()->text()));
    }

    /**
     * Le navigateur ne doit remonter aucune erreur JavaScript issue de
     * l'application : une exception non capturee casserait le menu ou les
     * alertes.
     *
     * Les entrees provenant d'un domaine tiers sont ecartees : la page
     * charge ses polices depuis Google Fonts, et une coupure reseau sur ce
     * service — frequente sur un runner d'integration continue — produirait
     * une erreur SEVERE sans rapport avec le code teste. Les polices ont de
     * toute facon une pile de repli.
     */
    public function testAucuneErreurJavascriptSurLesPagesPrincipales(): void
    {
        $client = $this->naviguer(self::DESKTOP, '/');

        foreach (['/produits', '/panier', '/connexion'] as $url) {
            $client->request('GET', $url);
            $client->waitForVisibility('main');
        }

        $erreurs = array_filter(
            $client->getWebDriver()->manage()->getLog('browser'),
            static function (array $entree): bool {
                if ('SEVERE' !== ($entree['level'] ?? '')) {
                    return false;
                }

                $message = (string) ($entree['message'] ?? '');

                return !preg_match('#https?://(?!localhost|127\.0\.0\.1)#i', $message);
            }
        );

        self::assertSame(
            [],
            array_values(array_map(static fn (array $e): string => (string) $e['message'], $erreurs)),
            'Le navigateur a remonte des erreurs JavaScript provenant de l\'application.'
        );
    }
}
