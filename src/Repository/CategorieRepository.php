<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Categorie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Categorie>
 */
class CategorieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Categorie::class);
    }

    /**
     * Categories triees par nom, avec le nombre de produits actifs de chacune.
     * Alimente la sidebar de filtres du catalogue.
     *
     * @return list<array{categorie: Categorie, nbProduits: int}>
     */
    public function avecNombreDeProduits(): array
    {
        /** @var list<array{0: Categorie, nbProduits: string}> $lignes */
        $lignes = $this->createQueryBuilder('c')
            ->select('c', 'COUNT(p.id) AS nbProduits')
            ->leftJoin('c.produits', 'p', 'WITH', 'p.disponible = true')
            ->groupBy('c.id')
            ->orderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $ligne): array => [
                'categorie' => $ligne[0],
                'nbProduits' => (int) $ligne['nbProduits'],
            ],
            $lignes
        );
    }

    /**
     * @return list<Categorie>
     */
    public function findAllTrieesParNom(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Categorie $categorie, bool $flush = true): void
    {
        $this->getEntityManager()->persist($categorie);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
