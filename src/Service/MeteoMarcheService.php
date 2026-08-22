<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\PrevisionMeteo;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Integration d'une API externe : la meteo du prochain jour de marche
 * (Open-Meteo, sans cle d'API).
 *
 * L'information est directement utile au metier : elle aide le gerant a
 * doser ses achats et renseigne le client sur les conditions du marche.
 *
 * Le service est concu pour ne jamais casser la page : en cas d'indisponibilite
 * de l'API, il journalise l'incident et renvoie null, le bloc meteo
 * disparaissant simplement de l'affichage.
 */
class MeteoMarcheService
{
    private const DUREE_CACHE = 3600; // 1 heure
    private const CLE_CACHE = 'meteo_marche_previsions';

    public function __construct(
        private readonly HttpClientInterface $meteoClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly float $marcheLatitude,
        private readonly float $marcheLongitude,
    ) {
    }

    /**
     * Previsions des prochains jours.
     *
     * @return list<PrevisionMeteo>
     */
    public function previsions(int $nombreDeJours = 3): array
    {
        $nombreDeJours = max(1, min(7, $nombreDeJours));

        try {
            /** @var list<PrevisionMeteo> $previsions */
            $previsions = $this->cache->get(
                self::CLE_CACHE.'_'.$nombreDeJours,
                function (ItemInterface $item) use ($nombreDeJours): array {
                    $item->expiresAfter(self::DUREE_CACHE);

                    return $this->interroger($nombreDeJours);
                }
            );

            return $previsions;
        } catch (\Throwable $exception) {
            $this->logger->warning('Meteo du marche indisponible : {message}', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return [];
        }
    }

    /**
     * Prevision du jour, ou null si l'API est injoignable.
     */
    public function previsionDuJour(): ?PrevisionMeteo
    {
        return $this->previsions(1)[0] ?? null;
    }

    /**
     * @return list<PrevisionMeteo>
     *
     * @throws HttpExceptionInterface
     */
    private function interroger(int $nombreDeJours): array
    {
        $reponse = $this->meteoClient->request('GET', '', [
            'query' => [
                'latitude' => $this->marcheLatitude,
                'longitude' => $this->marcheLongitude,
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min',
                'timezone' => 'Europe/Paris',
                'forecast_days' => $nombreDeJours,
            ],
        ]);

        /** @var array{daily?: array{time?: list<string>, weather_code?: list<int>, temperature_2m_max?: list<float>, temperature_2m_min?: list<float>}} $donnees */
        $donnees = $reponse->toArray();
        $quotidien = $donnees['daily'] ?? [];

        $dates = $quotidien['time'] ?? [];
        $codes = $quotidien['weather_code'] ?? [];
        $maxima = $quotidien['temperature_2m_max'] ?? [];
        $minima = $quotidien['temperature_2m_min'] ?? [];

        $previsions = [];

        foreach ($dates as $index => $date) {
            if (!isset($codes[$index], $maxima[$index], $minima[$index])) {
                continue;
            }

            $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if (false === $jour) {
                continue;
            }

            $previsions[] = new PrevisionMeteo(
                $jour,
                (float) $minima[$index],
                (float) $maxima[$index],
                (int) $codes[$index],
            );
        }

        return $previsions;
    }
}
