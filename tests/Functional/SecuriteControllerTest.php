<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\InscriptionController;
use App\Controller\SecuriteController;
use App\Entity\Utilisateur;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Authentification, inscription et controle d'acces (chap. IX.3).
 */
#[CoversClass(SecuriteController::class)]
#[CoversClass(InscriptionController::class)]
class SecuriteControllerTest extends AbstractFonctionnelTestCase
{
    public function testLaPageDeConnexionEstAccessible(): void
    {
        $this->client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Connexion');
    }

    public function testUnClientPeutSeConnecter(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        $this->client->submit($crawler->selectButton('Se connecter')->form([
            'email' => 'client@tarchoun.fr',
            'motDePasse' => 'Client1234!',
        ]));

        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        // Le prenom du client apparait dans le menu utilisateur.
        self::assertSelectorTextContains('.navbar', 'Sophie');
    }

    /**
     * Le message d'erreur ne doit pas indiquer si c'est l'email ou le mot de
     * passe qui est errone (protection contre l'enumeration des comptes).
     */
    public function testUnMotDePasseErroneEstRefuse(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        $this->client->submit($crawler->selectButton('Se connecter')->form([
            'email' => 'client@tarchoun.fr',
            'motDePasse' => 'mauvais-mot-de-passe',
        ]));

        $crawler = $this->client->followRedirect();

        self::assertSelectorExists('.alert-danger');
        self::assertStringNotContainsString('mot de passe incorrect', mb_strtolower($crawler->text()));
    }

    public function testUnEmailInconnuEstRefuse(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        $this->client->submit($crawler->selectButton('Se connecter')->form([
            'email' => 'inconnu@example.fr',
            'motDePasse' => 'Client1234!',
        ]));

        $this->client->followRedirect();

        self::assertSelectorExists('.alert-danger');
    }

    public function testUnCompteDesactiveNePeutPasSeConnecter(): void
    {
        $utilisateur = $this->utilisateur('client@tarchoun.fr');
        $utilisateur->setActif(false);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/connexion');

        $this->client->submit($crawler->selectButton('Se connecter')->form([
            'email' => 'client@tarchoun.fr',
            'motDePasse' => 'Client1234!',
        ]));

        $crawler = $this->client->followRedirect();

        self::assertSelectorExists('.alert-danger');
        self::assertStringContainsString('desactive', $crawler->text());
    }

    /**
     * Protection contre le brute force (chap. IX.3) : au-dela de 5 tentatives
     * en 15 minutes, les essais suivants sont rejetes meme avec le bon mot
     * de passe.
     */
    public function testLesTentativesRepeteesSontBloquees(): void
    {
        for ($tentative = 1; $tentative <= 6; ++$tentative) {
            $crawler = $this->client->request('GET', '/connexion');
            $this->client->submit($crawler->selectButton('Se connecter')->form([
                'email' => 'client@tarchoun.fr',
                'motDePasse' => 'mauvais-mot-de-passe',
            ]));
            $this->client->followRedirect();
        }

        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            'email' => 'client@tarchoun.fr',
            'motDePasse' => 'Client1234!',
        ]));
        $crawler = $this->client->followRedirect();

        self::assertSelectorExists('.alert-danger');
        self::assertStringContainsString('Too many failed login attempts', $crawler->text());
    }

    public function testUnUtilisateurConnectePeutSeDeconnecter(): void
    {
        $this->connecterClient();

        $this->client->request('GET', '/profil');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/deconnexion');
        self::assertResponseRedirects();

        $this->client->request('GET', '/profil');
        self::assertResponseRedirects('/connexion');
    }

    // ----- Inscription -----

    public function testUnVisiteurPeutCreerUnCompte(): void
    {
        $crawler = $this->client->request('GET', '/inscription');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Creer mon compte')->form([
            'inscription[prenom]' => 'Camille',
            'inscription[nom]' => 'Moreau',
            'inscription[email]' => 'camille.moreau@example.fr',
            'inscription[telephone]' => '06 11 22 33 44',
            'inscription[motDePasse][first]' => 'Marche2026',
            'inscription[motDePasse][second]' => 'Marche2026',
            'inscription[accepterRgpd]' => true,
        ]));

        self::assertResponseRedirects();

        $utilisateur = $this->utilisateur('camille.moreau@example.fr');

        self::assertSame('Camille', $utilisateur->getPrenom());
        self::assertSame([Utilisateur::ROLE_CLIENT, 'ROLE_USER'], $utilisateur->getRoles());
        self::assertTrue($utilisateur->isActif());
    }

    /**
     * Exigence CDCF 3.6.2 : le mot de passe n'est jamais stocke en clair.
     */
    public function testLeMotDePasseEstHacheEtNonStockeEnClair(): void
    {
        $crawler = $this->client->request('GET', '/inscription');

        $this->client->submit($crawler->selectButton('Creer mon compte')->form([
            'inscription[prenom]' => 'Camille',
            'inscription[nom]' => 'Moreau',
            'inscription[email]' => 'camille.moreau@example.fr',
            'inscription[motDePasse][first]' => 'Marche2026',
            'inscription[motDePasse][second]' => 'Marche2026',
            'inscription[accepterRgpd]' => true,
        ]));

        $utilisateur = $this->utilisateur('camille.moreau@example.fr');
        $hache = (string) $utilisateur->getMotDePasse();

        self::assertNotSame('Marche2026', $hache);
        self::assertStringNotContainsString('Marche2026', $hache);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($utilisateur, 'Marche2026'));
    }

    public function testLInscriptionRefuseUnEmailDejaUtilise(): void
    {
        $crawler = $this->client->request('GET', '/inscription');

        $this->client->submit($crawler->selectButton('Creer mon compte')->form([
            'inscription[prenom]' => 'Doublon',
            'inscription[nom]' => 'Test',
            'inscription[email]' => 'client@tarchoun.fr',
            'inscription[motDePasse][first]' => 'Marche2026',
            'inscription[motDePasse][second]' => 'Marche2026',
            'inscription[accepterRgpd]' => true,
        ]));

        // Symfony renvoie 422 lorsqu'un formulaire soumis est invalide.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.form-error-message, .invalid-feedback');
    }

    public function testLInscriptionRefuseDeuxMotsDePasseDifferents(): void
    {
        $crawler = $this->client->request('GET', '/inscription');

        $this->client->submit($crawler->selectButton('Creer mon compte')->form([
            'inscription[prenom]' => 'Camille',
            'inscription[nom]' => 'Moreau',
            'inscription[email]' => 'camille.moreau@example.fr',
            'inscription[motDePasse][first]' => 'Marche2026',
            'inscription[motDePasse][second]' => 'AutreChose2026',
            'inscription[accepterRgpd]' => true,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertNull(
            $this->entityManager->getRepository(Utilisateur::class)
                ->findOneBy(['email' => 'camille.moreau@example.fr'])
        );
    }

    public function testLInscriptionRefuseUnMotDePasseTropFaible(): void
    {
        $crawler = $this->client->request('GET', '/inscription');

        $this->client->submit($crawler->selectButton('Creer mon compte')->form([
            'inscription[prenom]' => 'Camille',
            'inscription[nom]' => 'Moreau',
            'inscription[email]' => 'camille.moreau@example.fr',
            'inscription[motDePasse][first]' => 'court',
            'inscription[motDePasse][second]' => 'court',
            'inscription[accepterRgpd]' => true,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertNull(
            $this->entityManager->getRepository(Utilisateur::class)
                ->findOneBy(['email' => 'camille.moreau@example.fr'])
        );
    }
}
