<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Produit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Produit>
 */
class ProduitRepository extends ServiceEntityRepository
{
    public const PAR_PAGE = 9;

    public const TRIS = [
        'prix_asc' => 'Prix croissant',
        'prix_desc' => 'Prix decroissant',
        'nom_asc' => 'Nom A-Z',
        'nouveaute' => 'Nouveautes',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Produit::class);
    }

    /**
     * Catalogue public : recherche plein texte, filtre par categories et tri
     * (CDCF 3.3.2, maquette catalogue Jalon 2, 4.2).
     *
     * @param list<int> $categorieIds
     *
     * @return Paginator<Produit>
     */
    public function rechercherCatalogue(
        ?string $recherche = null,
        array $categorieIds = [],
        string $tri = 'nom_asc',
        bool $uniquementEnStock = false,
        int $page = 1,
        int $parPage = self::PAR_PAGE,
    ): Paginator {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c', 's')
            ->innerJoin('p.categorie', 'c')
            ->leftJoin('p.stock', 's')
            ->andWhere('p.disponible = true');

        if (null !== $recherche && '' !== trim($recherche)) {
            $qb->andWhere('p.nom LIKE :recherche OR p.description LIKE :recherche OR p.origine LIKE :recherche')
                ->setParameter('recherche', '%'.trim($recherche).'%');
        }

        if ([] !== $categorieIds) {
            $qb->andWhere('c.id IN (:categories)')
                ->setParameter('categories', $categorieIds);
        }

        if ($uniquementEnStock) {
            $qb->andWhere('s.quantiteDisponible > 0');
        }

        $this->appliquerTri($qb, $tri);

        $page = max(1, $page);
        $qb->setFirstResult(($page - 1) * $parPage)
            ->setMaxResults($parPage);

        return new Paginator($qb->getQuery(), false);
    }

    private function appliquerTri(QueryBuilder $qb, string $tri): void
    {
        match ($tri) {
            'prix_asc' => $qb->orderBy('p.prix', 'ASC'),
            'prix_desc' => $qb->orderBy('p.prix', 'DESC'),
            'nouveaute' => $qb->orderBy('p.dateCreation', 'DESC'),
            default => $qb->orderBy('p.nom', 'ASC'),
        };

        $qb->addOrderBy('p.id', 'ASC');
    }

    /**
     * Produits mis en avant sur la page d'accueil : en stock, les plus recents.
     *
     * @return list<Produit>
     */
    public function produitsDuMoment(int $limite = 4): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('c', 's')
            ->innerJoin('p.categorie', 'c')
            ->innerJoin('p.stock', 's')
            ->andWhere('p.disponible = true')
            ->andWhere('s.quantiteDisponible > 0')
            ->orderBy('p.dateCreation', 'DESC')
            // Depart, plusieurs produits partagent la meme seconde de creation
            // (chargement des fixtures, import en lot) : sans ce second critere
            // l'ordre serait laisse au SGBD et la page d'accueil changerait
            // d'un affichage a l'autre.
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Chargement d'un produit avec sa categorie et son stock, pour la fiche
     * detaillee (evite les requetes N+1).
     */
    public function findAvecStock(int $id): ?Produit
    {
        return $this->createQueryBuilder('p')
            ->addSelect('c', 's')
            ->innerJoin('p.categorie', 'c')
            ->leftJoin('p.stock', 's')
            ->andWhere('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Suggestions affichees sur la fiche produit.
     *
     * @return list<Produit>
     */
    public function memeCategorie(Produit $produit, int $limite = 3): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('c', 's')
            ->innerJoin('p.categorie', 'c')
            ->leftJoin('p.stock', 's')
            ->andWhere('c.id = :categorie')
            ->andWhere('p.id != :id')
            ->andWhere('p.disponible = true')
            ->setParameter('categorie', $produit->getCategorie()?->getId())
            ->setParameter('id', $produit->getId())
            ->orderBy('p.nom', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Liste complete pour le back-office (produits actifs et desactives).
     *
     * @return list<Produit>
     */
    public function findPourAdministration(): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('c', 's')
            ->innerJoin('p.categorie', 'c')
            ->leftJoin('p.stock', 's')
            ->orderBy('c.nom', 'ASC')
            ->addOrderBy('p.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Le produit apparait-il dans au moins une commande ?
     *
     * Se poser la question avant de supprimer evite de declencher une
     * violation de cle etrangere, qui fermerait l'EntityManager et rendrait
     * impossible toute ecriture de repli.
     */
    public function estReferenceParUneCommande(Produit $produit): bool
    {
        $total = (int) $this->getEntityManager()
            ->createQuery('SELECT COUNT(l.id) FROM App\Entity\LigneCommande l WHERE l.produit = :produit')
            ->setParameter('produit', $produit)
            ->getSingleScalarResult();

        return $total > 0;
    }

    public function compterActifs(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.disponible = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(Produit $produit, bool $flush = true): void
    {
        $this->getEntityManager()->persist($produit);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Produit $produit, bool $flush = true): void
    {
        $this->getEntityManager()->remove($produit);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
