<?php

declare(strict_types=1);

/*
 * Routeur pour le serveur web integre de PHP :
 *   php -S 127.0.0.1:8000 -t public public/dev-router.php
 *
 * Il sert directement les fichiers existants (CSS, JS, images) et delegue
 * tout le reste au controleur frontal de Symfony. Utile comme solution de
 * repli quand le binaire "symfony" n'est pas disponible ; en production,
 * c'est Apache ou Nginx qui joue ce role (voir docker/).
 */

$chemin = parse_url((string) $_SERVER['REQUEST_URI'], \PHP_URL_PATH);

if ('/' !== $chemin && is_file(__DIR__.$chemin)) {
    return false;
}

require __DIR__.'/index.php';
