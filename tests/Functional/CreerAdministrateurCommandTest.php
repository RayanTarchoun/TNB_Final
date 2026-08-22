<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Command\CreerAdministrateurCommand;
use App\Entity\Utilisateur;
use App\Tests\Support\ChargementFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Commande de creation du compte administrateur.
 *
 * Elle remplace le chargement des fixtures lors d'une premiere mise en
 * production : charger les fixtures purgerait la base.
 */
#[CoversClass(CreerAdministrateurCommand::class)]
class CreerAdministrateurCommandTest extends KernelTestCase
{
    use ChargementFixturesTrait;

    private CommandTester $commande;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $conteneur = static::getContainer();
        $this->entityManager = $conteneur->get(EntityManagerInterface::class);
        $this->reinitialiserLaBase($this->entityManager, $conteneur);

        $application = new Application(self::$kernel);
        $this->commande = new CommandTester($application->find('app:creer-administrateur'));
    }

    private function utilisateur(string $email): ?Utilisateur
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    public function testElleCreeUnAdministrateur(): void
    {
        $code = $this->commande->execute([
            'email' => 'gerant@tarchoun.fr',
            'prenom' => 'Hamadi',
            'nom' => 'Tarchoun',
            '--mot-de-passe' => 'Marche2026',
        ]);

        self::assertSame(Command::SUCCESS, $code);
        $this->commande->assertCommandIsSuccessful();

        $utilisateur = $this->utilisateur('gerant@tarchoun.fr');

        self::assertNotNull($utilisateur);
        self::assertSame('Hamadi', $utilisateur->getPrenom());
        self::assertSame(Utilisateur::ROLE_ADMIN, $utilisateur->getRole());
        self::assertTrue($utilisateur->isActif());
        self::assertContains('ROLE_ADMIN', $utilisateur->getRoles());
    }

    public function testLeMotDePasseEstHacheEtUtilisable(): void
    {
        $this->commande->execute([
            'email' => 'gerant@tarchoun.fr',
            '--mot-de-passe' => 'Marche2026',
        ]);

        $utilisateur = $this->utilisateur('gerant@tarchoun.fr');
        self::assertNotNull($utilisateur);

        self::assertNotSame('Marche2026', $utilisateur->getMotDePasse());

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($utilisateur, 'Marche2026'));
    }

    /**
     * Relancer la commande sur un compte client existant le promeut plutot
     * que d'echouer sur la contrainte d'unicite de l'email.
     */
    public function testElleFaitMonterUnCompteExistantEnAdministrateur(): void
    {
        $code = $this->commande->execute([
            'email' => 'client@tarchoun.fr',
            '--mot-de-passe' => 'NouveauPass2026',
        ]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('promu administrateur', $this->commande->getDisplay());

        $utilisateur = $this->utilisateur('client@tarchoun.fr');
        self::assertNotNull($utilisateur);
        self::assertSame(Utilisateur::ROLE_ADMIN, $utilisateur->getRole());

        // Le prenom d'origine n'est pas ecrase.
        self::assertSame('Sophie', $utilisateur->getPrenom());
    }

    public function testElleReactiveUnCompteDesactive(): void
    {
        $compte = $this->utilisateur('client@tarchoun.fr');
        self::assertNotNull($compte);
        $compte->setActif(false);
        $this->entityManager->flush();

        $this->commande->execute([
            'email' => 'client@tarchoun.fr',
            '--mot-de-passe' => 'Marche2026',
        ]);

        $utilisateur = $this->utilisateur('client@tarchoun.fr');
        self::assertNotNull($utilisateur);
        self::assertTrue($utilisateur->isActif());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fournirMotsDePasseFaibles(): iterable
    {
        yield 'trop court' => ['Court1'];
        yield 'sans chiffre' => ['MotDePasseSansChiffre'];
        yield 'sans lettre' => ['12345678'];
    }

    #[DataProvider('fournirMotsDePasseFaibles')]
    public function testElleRefuseUnMotDePasseFaible(string $motDePasse): void
    {
        $code = $this->commande->execute([
            'email' => 'gerant@tarchoun.fr',
            '--mot-de-passe' => $motDePasse,
        ]);

        self::assertSame(Command::INVALID, $code);
        self::assertNull($this->utilisateur('gerant@tarchoun.fr'));
    }

    public function testElleRefuseUnEmailInvalide(): void
    {
        $code = $this->commande->execute([
            'email' => 'pas-une-adresse',
            '--mot-de-passe' => 'Marche2026',
        ]);

        self::assertSame(Command::INVALID, $code);
        self::assertStringContainsString('invalide', $this->commande->getDisplay());
    }

    /**
     * En mode non interactif (script de deploiement), l'omission du mot de
     * passe doit echouer explicitement plutot que d'attendre une saisie.
     */
    public function testElleExigeLeMotDePasseEnModeNonInteractif(): void
    {
        $code = $this->commande->execute(
            ['email' => 'gerant@tarchoun.fr'],
            ['interactive' => false]
        );

        self::assertSame(Command::INVALID, $code);
        self::assertStringContainsString('--mot-de-passe', $this->commande->getDisplay());
    }

    /**
     * En interactif, un mot de passe vide ne doit jamais creer de compte.
     *
     * Le chemin interactif complet n'est pas automatise : CommandTester ne
     * peut pas alimenter une saisie masquee de maniere identique sous
     * Windows et sous Linux, ce qui rendrait le test dependant de la
     * plateforme. C'est la garantie de securite qui est verifiee ici.
     */
    public function testElleNeCreeAucunCompteSansMotDePasse(): void
    {
        $code = $this->commande->execute([
            'email' => 'gerant@tarchoun.fr',
            '--mot-de-passe' => '',
        ]);

        self::assertSame(Command::INVALID, $code);
        self::assertNull($this->utilisateur('gerant@tarchoun.fr'));
    }

    /**
     * Le mot de passe fourni en option ne doit pas etre reaffiche dans la
     * sortie de la commande.
     */
    public function testLeMotDePasseNestJamaisAffiche(): void
    {
        $this->commande->execute([
            'email' => 'gerant@tarchoun.fr',
            '--mot-de-passe' => 'Marche2026',
        ]);

        self::assertStringNotContainsString('Marche2026', $this->commande->getDisplay());
    }
}
