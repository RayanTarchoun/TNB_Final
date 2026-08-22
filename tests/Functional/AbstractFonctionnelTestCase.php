<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Commande;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Tests\Support\ChargementFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
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
    use ChargementFixturesTrait;

    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $conteneur = static::getContainer();
        $this->entityManager = $conteneur->get(EntityManagerInterface::class);

        $this->reinitialiserLaBase($this->entityManager, $conteneur);
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
