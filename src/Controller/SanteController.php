<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Point de controle de sante, consomme par Docker et la supervision.
 *
 * La reponse reste volontairement pauvre : elle indique si l'application
 * repond et si la base est joignable, sans divulguer de version ni de
 * detail d'infrastructure exploitable par un attaquant.
 */
class SanteController extends AbstractController
{
    #[Route('/sante', name: 'app_sante', methods: ['GET'])]
    public function index(Connection $connexion, LoggerInterface $logger): JsonResponse
    {
        $baseJoignable = true;

        try {
            $connexion->executeQuery('SELECT 1')->free();
        } catch (\Throwable $exception) {
            $baseJoignable = false;

            $logger->error('Controle de sante : base de donnees injoignable ({message})', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }

        return new JsonResponse(
            [
                'statut' => $baseJoignable ? 'ok' : 'degrade',
                'base_de_donnees' => $baseJoignable ? 'ok' : 'injoignable',
            ],
            $baseJoignable ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
            // Ce controle ne doit jamais etre servi depuis un cache.
            ['Cache-Control' => 'no-store, max-age=0']
        );
    }
}
