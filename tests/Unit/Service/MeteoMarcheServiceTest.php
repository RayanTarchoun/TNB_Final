<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Model\PrevisionMeteo;
use App\Service\MeteoMarcheService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Integration de l'API tierce Open-Meteo (exigence "API externe" du CDC
 * technique).
 *
 * Les reponses HTTP sont simulees : la suite reste deterministe et
 * executable hors ligne, sans dependre de la disponibilite du service.
 */
#[CoversClass(MeteoMarcheService::class)]
#[CoversClass(PrevisionMeteo::class)]
class MeteoMarcheServiceTest extends TestCase
{
    private const LATITUDE = 48.8566;
    private const LONGITUDE = 2.3522;

    /**
     * Charge utile representative de l'API Open-Meteo.
     */
    private function reponseApi(int $jours = 3): string
    {
        $donnees = [
            'latitude' => self::LATITUDE,
            'longitude' => self::LONGITUDE,
            'daily' => [
                'time' => \array_slice(['2026-06-17', '2026-06-18', '2026-06-19'], 0, $jours),
                'weather_code' => \array_slice([0, 61, 95], 0, $jours),
                'temperature_2m_max' => \array_slice([26.4, 19.8, 22.1], 0, $jours),
                'temperature_2m_min' => \array_slice([14.2, 12.5, 15.0], 0, $jours),
            ],
        ];

        return json_encode($donnees, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<MockResponse>|callable $reponses
     */
    private function creerService(array|callable $reponses, ?LoggerInterface $logger = null): MeteoMarcheService
    {
        return new MeteoMarcheService(
            new MockHttpClient($reponses, 'https://api.open-meteo.com/v1/forecast'),
            new ArrayAdapter(),
            $logger ?? new NullLogger(),
            self::LATITUDE,
            self::LONGITUDE,
        );
    }

    // ----- Cas nominal -----

    public function testLesPrevisionsSontConstruitesDepuisLaReponseApi(): void
    {
        $service = $this->creerService([new MockResponse($this->reponseApi())]);

        $previsions = $service->previsions(3);

        self::assertCount(3, $previsions);

        $premiere = $previsions[0];
        self::assertSame('2026-06-17', $premiere->date->format('Y-m-d'));
        self::assertSame(14.2, $premiere->temperatureMin);
        self::assertSame(26.4, $premiere->temperatureMax);
        self::assertSame(0, $premiere->codeMeteo);
    }

    public function testPrevisionDuJourRenvoieLaPremiereEcheance(): void
    {
        $service = $this->creerService([new MockResponse($this->reponseApi(1))]);

        $prevision = $service->previsionDuJour();

        self::assertInstanceOf(PrevisionMeteo::class, $prevision);
        self::assertSame('2026-06-17', $prevision->date->format('Y-m-d'));
    }

    /**
     * Les coordonnees du marche et les champs demandes doivent bien partir
     * dans la requete : c'est le contrat avec l'API.
     */
    public function testLaRequeteTransmetLesCoordonneesDuMarche(): void
    {
        $urlAppelee = null;

        $service = $this->creerService(
            function (string $methode, string $url) use (&$urlAppelee): MockResponse {
                $urlAppelee = $url;

                self::assertSame('GET', $methode);

                return new MockResponse($this->reponseApi());
            }
        );

        $service->previsions(3);

        self::assertIsString($urlAppelee);
        self::assertStringContainsString('latitude=48.8566', $urlAppelee);
        self::assertStringContainsString('longitude=2.3522', $urlAppelee);
        self::assertStringContainsString('timezone=Europe/Paris', $urlAppelee);
        self::assertStringContainsString('forecast_days=3', $urlAppelee);
        self::assertStringContainsString('weather_code', $urlAppelee);
    }

    /**
     * Une seule requete HTTP doit partir pour deux appels consecutifs :
     * les previsions sont mises en cache une heure.
     */
    public function testLesPrevisionsSontMisesEnCache(): void
    {
        $appels = 0;

        $service = $this->creerService(
            function () use (&$appels): MockResponse {
                ++$appels;

                return new MockResponse($this->reponseApi());
            }
        );

        $service->previsions(3);
        $service->previsions(3);

        self::assertSame(1, $appels, "L'API ne doit etre interrogee qu'une fois.");
    }

    // ----- Degradation en cas de panne -----

    /**
     * Exigence de robustesse : une API injoignable ne doit jamais casser la
     * page d'accueil.
     */
    public function testUneErreurServeurRenvoieUneListeVide(): void
    {
        $service = $this->creerService([new MockResponse('', ['http_code' => 500])]);

        self::assertSame([], $service->previsions());
        self::assertNull($service->previsionDuJour());
    }

    public function testUnePanneReseauRenvoieUneListeVide(): void
    {
        $service = $this->creerService([
            new MockResponse(['error' => new \RuntimeException('Connexion impossible')]),
        ]);

        self::assertSame([], $service->previsions());
    }

    public function testUneReponseIllisibleRenvoieUneListeVide(): void
    {
        $service = $this->creerService([
            new MockResponse('<html>maintenance</html>', [
                'response_headers' => ['content-type' => 'text/html'],
            ]),
        ]);

        self::assertSame([], $service->previsions());
    }

    public function testLIncidentEstJournalise(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('Meteo du marche indisponible'));

        $this->creerService([new MockResponse('', ['http_code' => 503])], $logger)->previsions();
    }

    // ----- Robustesse du parsing -----

    public function testUneReponseSansSectionDailyRenvoieUneListeVide(): void
    {
        $service = $this->creerService([new MockResponse('{"latitude":48.85}')]);

        self::assertSame([], $service->previsions());
    }

    /**
     * Une echeance incomplete est ignoree plutot que de produire une
     * prevision partielle.
     */
    public function testUneEcheanceIncompleteEstIgnoree(): void
    {
        $charge = json_encode([
            'daily' => [
                'time' => ['2026-06-17', '2026-06-18'],
                'weather_code' => [0],
                'temperature_2m_max' => [26.4],
                'temperature_2m_min' => [14.2],
            ],
        ], \JSON_THROW_ON_ERROR);

        $previsions = $this->creerService([new MockResponse($charge)])->previsions();

        self::assertCount(1, $previsions);
    }

    public function testLeNombreDeJoursEstBorneEntreUnEtSept(): void
    {
        $urls = [];

        $service = $this->creerService(
            function (string $_methode, string $url) use (&$urls): MockResponse {
                $urls[] = $url;

                return new MockResponse($this->reponseApi(1));
            }
        );

        $service->previsions(0);
        $service->previsions(30);

        self::assertStringContainsString('forecast_days=1', $urls[0]);
        self::assertStringContainsString('forecast_days=7', $urls[1]);
    }

    // ----- Traduction des codes WMO -----

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function fournirCodesMeteo(): iterable
    {
        yield 'ciel degage' => [0, 'Ciel degage'];
        yield 'peu nuageux' => [2, 'Peu nuageux'];
        yield 'couvert' => [3, 'Couvert'];
        yield 'brouillard' => [45, 'Brouillard'];
        yield 'bruine' => [51, 'Bruine'];
        yield 'pluie' => [63, 'Pluie'];
        yield 'neige' => [73, 'Neige'];
        yield 'orage' => [95, 'Orage'];
        yield 'inconnu' => [999, 'Variable'];
    }

    #[DataProvider('fournirCodesMeteo')]
    public function testLesCodesWmoSontTraduits(int $code, string $libelleAttendu): void
    {
        $prevision = new PrevisionMeteo(new \DateTimeImmutable('2026-06-17'), 12.0, 20.0, $code);

        self::assertSame($libelleAttendu, $prevision->libelle());
        self::assertNotSame('', $prevision->pictogramme());
    }

    public function testLesTemperaturesSontArrondiesPourLAffichage(): void
    {
        $prevision = new PrevisionMeteo(new \DateTimeImmutable('2026-06-17'), 14.2, 26.7, 0);

        self::assertSame(14, $prevision->temperatureMinArrondie());
        self::assertSame(27, $prevision->temperatureMaxArrondie());
    }
}
