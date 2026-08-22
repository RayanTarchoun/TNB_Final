<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\SanteController;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Point de controle de sante utilise par Docker et la supervision.
 */
#[CoversClass(SanteController::class)]
class SanteControllerTest extends AbstractFonctionnelTestCase
{
    public function testLeControleDeSanteRepondOkQuandLaBaseEstJoignable(): void
    {
        $this->client->request('GET', '/sante');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(['statut' => 'ok', 'base_de_donnees' => 'ok'], $charge);
    }

    public function testIlEstAccessibleSansAuthentification(): void
    {
        $this->client->request('GET', '/sante');

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Un cache intermediaire renverrait un etat perime : la reponse doit
     * explicitement l'interdire.
     */
    public function testLaReponseNestJamaisMiseEnCache(): void
    {
        $this->client->request('GET', '/sante');

        // Symfony reorganise et complete la directive : on verifie la
        // propriete qui compte plutot qu'une chaine exacte.
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');

        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('max-age=0', $cacheControl);
    }

    /**
     * Le controle ne doit rien reveler de l'infrastructure : ni version, ni
     * nom de serveur, ni chemin.
     */
    public function testIlNeDivulgueAucunDetailDInfrastructure(): void
    {
        $this->client->request('GET', '/sante');

        $contenu = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('mysql', mb_strtolower($contenu));
        self::assertStringNotContainsString('symfony', mb_strtolower($contenu));
        self::assertStringNotContainsString('8.4', $contenu);
        self::assertStringNotContainsString('/var/www', $contenu);
    }

    /**
     * Les robots ne doivent indexer que la vitrine publique.
     */
    public function testLeFichierRobotsProtegeLesEspacesPrives(): void
    {
        $chemin = \dirname(__DIR__, 2).'/public/robots.txt';

        self::assertFileExists($chemin);

        $contenu = (string) file_get_contents($chemin);

        foreach (['/admin', '/panier', '/commande', '/mes-commandes', '/profil', '/sante'] as $prive) {
            self::assertStringContainsString('Disallow: '.$prive, $contenu);
        }
    }
}
