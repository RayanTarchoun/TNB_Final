<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Twig\Environment;

/**
 * La page d'erreur personnalisee ne s'affiche qu'en production
 * (kernel.debug = false) : elle echappe donc aux tests de navigation.
 *
 * Ces tests la rendent directement pour garantir qu'elle compile et qu'elle
 * n'expose aucun detail technique.
 */
class PageErreurTest extends AbstractFonctionnelTestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function fournirCodesErreur(): iterable
    {
        yield '404' => [404, "Cette page n'existe pas"];
        yield '403' => [403, 'Acces refuse'];
        yield '500' => [500, 'Une erreur est survenue'];
    }

    #[DataProvider('fournirCodesErreur')]
    public function testLaPageErreurAfficheUnMessageAdapte(int $code, string $attendu): void
    {
        // Une requete prealable fournit un contexte de requete a Twig
        // (chemins, session), comme lors d'un rendu reel.
        $this->client->request('GET', '/');

        $twig = static::getContainer()->get(Environment::class);

        $html = $twig->render('bundles/TwigBundle/Exception/error.html.twig', [
            'status_code' => $code,
            'status_text' => 'Erreur',
            'exception' => null,
        ]);

        self::assertStringContainsString($attendu, $html);
        self::assertStringContainsString('Voir le catalogue', $html);
        self::assertStringContainsString((string) $code, $html);
    }

    public function testLaPageErreurNExposeAucunDetailTechnique(): void
    {
        $this->client->request('GET', '/');

        $twig = static::getContainer()->get(Environment::class);

        $html = $twig->render('bundles/TwigBundle/Exception/error.html.twig', [
            'status_code' => 500,
            'status_text' => 'Internal Server Error',
            'exception' => null,
        ]);

        // Ni trace d'exception, ni chemin de fichier du serveur, ni nom de
        // classe interne. ("/vendor/bootstrap/..." est une URL publique
        // legitime : ce n'est pas une fuite de chemin.)
        self::assertStringNotContainsString('Stack Trace', $html);
        self::assertStringNotContainsString('.php on line', $html);
        self::assertStringNotContainsString('Symfony\\Component', $html);
        self::assertStringNotContainsString('App\\Controller', $html);
        self::assertDoesNotMatchRegularExpression('#[A-Z]:\\\\|/var/www/#', $html);
    }
}
