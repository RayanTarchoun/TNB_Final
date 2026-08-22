<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Charge .env, puis .env.test et .env.test.local (APP_ENV vaut "test",
// force par phpunit.dist.xml). .env.local est volontairement ignore.
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

/*
 * Rend le pilote de navigateur telechargeable par "vendor/bin/bdi detect
 * drivers" visible des tests Panther.
 *
 * Panther localise chromedriver avec ExecutableFinder, qui parcourt le PATH
 * puis le dossier relatif "./drivers". Sous Windows, ce chemin relatif est
 * renvoye tel quel et cmd refuse de l'executer. Ajouter le dossier au PATH
 * en absolu resout le probleme, sans effet sur les environnements ou
 * chromedriver est deja installe (Linux, CI).
 */
$dossierPilotes = dirname(__DIR__).\DIRECTORY_SEPARATOR.'drivers';

if (is_dir($dossierPilotes)) {
    $chemin = getenv('PATH') ?: getenv('Path') ?: '';

    if (!str_contains($chemin, $dossierPilotes)) {
        putenv('PATH='.$dossierPilotes.\PATH_SEPARATOR.$chemin);
    }
}
