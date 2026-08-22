<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Entity\Commande;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Socle des tests fonctionnels.
 *
 * Le schema est cree une fois par processus, puis les fixtures sont
 * rechargees avant chaque test : chaque scenario part donc du meme jeu de
 * donnees, sans dependre de l'ordre d'execution.
 *
 * La base utilisee est "tnb_test" (suffixe ajoute par doctrine.yaml en
 * environnement de test) : la base de travail n'est jamais touchee.
 */
abstract class AbstractFonctionnelTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;

    private static bool $schemaInitialise = false;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $conteneur = static::getContainer();
        $this->entityManager = $conteneur->get(EntityManagerInterface::class);

        if (!self::$schemaInitialise) {
            $outil = new SchemaTool($this->entityManager);
            $metadonnees = $this->entityManager->getMetadataFactory()->getAllMetadata();
            $outil->dropSchema($metadonnees);
            $outil->createSchema($metadonnees);

            self::$schemaInitialise = true;
        }

        $chargeur = new Loader();
        $chargeur->addFixture($conteneur->get(AppFixtures::class));

        $executeur = new ORMExecutor($this->entityManager, new ORMPurger($this->entityManager));
        $executeur->execute($chargeur->getFixtures());

        $this->reinitialiserLaLimitationDeConnexion();
    }

    /**
     * Vide le compteur de tentatives de connexion.
     *
     * La protection contre le brute force (security.yaml, login_throttling)
     * conserve son etat dans un pool de cache partage entre les tests : sans
     * cette remise a zero, un test qui echoue volontairement a se connecter
     * ferait blacklister les suivants.
     */
    protected function reinitialiserLaLimitationDeConnexion(): void
    {
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->entityManager->clear();
    }

    // ----- Raccourcis -----

    protected function connecter(string $email): Utilisateur
    {
        $utilisateur = $this->utilisateur($email);
        $this->client->loginUser($utilisateur);

        return $utilisateur;
    }

    protected function connecterClient(): Utilisateur
    {
        return $this->connecter('client@tarchoun.fr');
    }

    protected function connecterAdministrateur(): Utilisateur
    {
        return $this->connecter('admin@tarchoun.fr');
    }

    protected function utilisateur(string $email): Utilisateur
    {
        $utilisateur = $this->entityManager
            ->getRepository(Utilisateur::class)
            ->findOneBy(['email' => $email]);

        self::assertInstanceOf(Utilisateur::class, $utilisateur, \sprintf('Utilisateur "%s" introuvable.', $email));

        return $utilisateur;
    }

    protected function produit(string $nom): Produit
    {
        $produit = $this->entityManager
            ->getRepository(Produit::class)
            ->findOneBy(['nom' => $nom]);

        self::assertInstanceOf(Produit::class, $produit, \sprintf('Produit "%s" introuvable.', $nom));

        return $produit;
    }

    protected function commande(string $reference): Commande
    {
        $commande = $this->entityManager
            ->getRepository(Commande::class)
            ->findOneBy(['reference' => $reference]);

        self::assertInstanceOf(Commande::class, $commande, \sprintf('Commande "%s" introuvable.', $reference));

        return $commande;
    }

    /**
     * Quantite encore disponible pour un produit, relue depuis la base.
     */
    protected function stockDisponible(string $nom): float
    {
        $this->entityManager->clear();

        return $this->produit($nom)->getQuantiteDisponible();
    }

    /**
     * Ajoute un produit au panier en passant par le formulaire reel de la
     * fiche produit : le jeton CSRF est donc celui attendu par le controleur.
     */
    protected function ajouterAuPanier(Produit $produit, float $quantite): void
    {
        $crawler = $this->client->request('GET', \sprintf('/produits/%d', $produit->getId()));
        self::assertResponseIsSuccessful();

        $formulaire = $crawler->filter(\sprintf('form[action="/panier/ajouter/%d"]', $produit->getId()))->form();
        $formulaire['quantite'] = (string) $quantite;

        $this->client->submit($formulaire);
    }
}
