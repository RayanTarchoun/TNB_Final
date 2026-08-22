<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DataFixtures\AppFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Container\ContainerInterface;

/**
 * Remise a zero de la base de test.
 *
 * Le schema est cree une fois par processus, puis les fixtures sont
 * rechargees avant chaque test : chaque scenario part du meme jeu de
 * donnees, sans dependre de l'ordre d'execution.
 *
 * Partage entre les tests fonctionnels (client HTTP simule) et les tests
 * navigateur Panther, qui visent la meme base "tnb_test".
 */
trait ChargementFixturesTrait
{
    private static bool $schemaInitialise = false;

    protected function reinitialiserLaBase(
        EntityManagerInterface $entityManager,
        ContainerInterface $conteneur,
    ): void {
        if (!self::$schemaInitialise) {
            $outil = new SchemaTool($entityManager);
            $metadonnees = $entityManager->getMetadataFactory()->getAllMetadata();
            $outil->dropSchema($metadonnees);
            $outil->createSchema($metadonnees);

            self::$schemaInitialise = true;
        }

        $chargeur = new Loader();
        $chargeur->addFixture($conteneur->get(AppFixtures::class));

        (new ORMExecutor($entityManager, new ORMPurger($entityManager)))
            ->execute($chargeur->getFixtures());
    }
}
