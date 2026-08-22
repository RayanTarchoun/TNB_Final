<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Categorie;
use App\Entity\Produit;
use App\Entity\Stock;
use App\Enum\UniteVente;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;

/**
 * Tests de performance du catalogue sur un jeu de donnees representatif.
 *
 * Le CDCF 3.5 fixe une exigence non fonctionnelle mesurable : "temps de
 * reponse des pages principales inferieur a 2 secondes en usage nominal".
 *
 * Deux mesures complementaires sont prises :
 *   - le temps de rendu, compare au seuil du CDCF ;
 *   - le nombre de requetes SQL, qui doit rester constant quel que soit le
 *     nombre de produits. C'est cette seconde mesure qui detecte vraiment
 *     une regression : une requete N+1 passe inapercue sur 14 produits et
 *     s'effondre sur plusieurs centaines.
 */
class PerformanceCatalogueTest extends AbstractFonctionnelTestCase
{
    private const SEUIL_SECONDES = 2.0;
    private const VOLUME = 200;

    /**
     * Ajoute un volume realiste de produits au catalogue existant.
     */
    private function genererCatalogue(int $nombre): void
    {
        /** @var list<Categorie> $categories */
        $categories = $this->entityManager->getRepository(Categorie::class)->findAll();
        self::assertNotEmpty($categories);

        for ($i = 1; $i <= $nombre; ++$i) {
            $produit = (new Produit())
                ->setNom(\sprintf('Produit de charge %03d', $i))
                ->setDescription('Produit genere pour la mesure de performance du catalogue.')
                ->setPrix(round(1 + ($i % 40) * 0.25, 2))
                ->setUniteVente(UniteVente::KG)
                ->setOrigine('France')
                ->setCategorie($categories[$i % \count($categories)])
                ->setDisponible(true);

            $stock = (new Stock())
                ->setQuantiteAchetee(50.0)
                ->setQuantiteDisponible(50.0);

            $produit->setStock($stock);

            $this->entityManager->persist($produit);
            $this->entityManager->persist($stock);

            // Vidage periodique : evite de garder 200 entites en memoire.
            if (0 === $i % 50) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * Nombre de requetes SQL emises par une requete HTTP, mesure seule.
     *
     * Le collecteur Doctrine cumule egalement les requetes emises hors
     * cycle HTTP (chargement des fixtures, generation du catalogue par le
     * code de test). Une requete de rappel absorbe ce cumul, de sorte que
     * la mesure qui suit ne porte que sur le rendu de la page.
     */
    private function mesurerRequetesSql(string $url): int
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->client->enableProfiler();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $profil = $this->client->getProfile();

        if (false === $profil) {
            self::markTestSkipped("Le profileur n'a pas collecte de donnees.");
        }

        $collecteur = $profil->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collecteur);

        return $collecteur->getQueryCount();
    }

    public function testLeCatalogueRepondSousLeSeuilDuCdcf(): void
    {
        $this->genererCatalogue(self::VOLUME);

        $debut = microtime(true);
        $this->client->request('GET', '/produits');
        $duree = microtime(true) - $debut;

        self::assertResponseIsSuccessful();
        self::assertLessThan(
            self::SEUIL_SECONDES,
            $duree,
            \sprintf(
                'Le catalogue a mis %.3f s a repondre sur %d produits, au-dela du seuil de %.1f s (CDCF 3.5).',
                $duree,
                self::VOLUME,
                self::SEUIL_SECONDES
            )
        );
    }

    public function testLaFicheProduitRepondSousLeSeuilDuCdcf(): void
    {
        $this->genererCatalogue(self::VOLUME);
        $produit = $this->produit('Tomates grappe');

        $debut = microtime(true);
        $this->client->request('GET', \sprintf('/produits/%d', $produit->getId()));
        $duree = microtime(true) - $debut;

        self::assertResponseIsSuccessful();
        self::assertLessThan(self::SEUIL_SECONDES, $duree);
    }

    /**
     * Garde-fou anti N+1 : le nombre de requetes doit etre identique que le
     * catalogue contienne 14 ou 214 produits.
     */
    public function testLeNombreDeRequetesSqlNeDependPasDuVolume(): void
    {
        $requetesPetitCatalogue = $this->mesurerRequetesSql('/produits');

        $this->genererCatalogue(self::VOLUME);

        $requetesGrandCatalogue = $this->mesurerRequetesSql('/produits');

        self::assertSame(
            $requetesPetitCatalogue,
            $requetesGrandCatalogue,
            \sprintf(
                'Requete N+1 detectee : %d requetes sur 14 produits contre %d sur %d.',
                $requetesPetitCatalogue,
                $requetesGrandCatalogue,
                self::VOLUME + 14
            )
        );
    }

    /**
     * Le catalogue joint categorie et stock en une seule requete paginee,
     * plus le comptage du paginateur : une poignee de requetes suffit.
     */
    public function testLeCatalogueResteSobreEnRequetes(): void
    {
        $this->genererCatalogue(self::VOLUME);

        self::assertLessThanOrEqual(
            10,
            $this->mesurerRequetesSql('/produits'),
            'Le rendu du catalogue devrait tenir en moins de dix requetes SQL.'
        );
    }

    /**
     * La pagination borne le travail : une page ne rend jamais plus de
     * produits que la taille de page, quel que soit le volume en base.
     */
    public function testLaPaginationBorneLeNombreDeProduitsRendus(): void
    {
        $this->genererCatalogue(self::VOLUME);

        $crawler = $this->client->request('GET', '/produits');

        self::assertResponseIsSuccessful();
        self::assertSame(9, $crawler->filter('.tnb-carte')->count());
    }
}
