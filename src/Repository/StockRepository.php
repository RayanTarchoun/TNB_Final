<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Produit;
use App\Entity\Stock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Stock>
 */
class StockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stock::class);
    }

    /**
     * Recharge le stock d'un produit en vue d'une ecriture.
     *
     * Deux clients qui valident leur commande en meme temps liraient sinon
     * la meme quantite disponible et pourraient tous deux passer le controle.
     * Un verrou pessimiste serialise les deux transactions (chap. XIV.1) ; il
     * n'est pose que si une transaction est ouverte, MySQL n'acceptant pas
     * "SELECT ... FOR UPDATE" en dehors de ce contexte.
     */
    public function trouverPourMiseAJour(Produit $produit): ?Stock
    {
        $requete = $this->createQueryBuilder('s')
            ->andWhere('s.produit = :produit')
            ->setParameter('produit', $produit)
            ->getQuery();

        if ($this->getEntityManager()->getConnection()->isTransactionActive()) {
            $requete->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        return $requete->getOneOrNullResult();
    }

    /**
     * Stocks passes sous le seuil d'alerte, pour le tableau de bord.
     *
     * @return list<Stock>
     */
    public function stocksBas(float $seuil): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('p', 'c')
            ->innerJoin('s.produit', 'p')
            ->innerJoin('p.categorie', 'c')
            ->andWhere('s.quantiteDisponible <= :seuil')
            ->andWhere('p.disponible = true')
            ->setParameter('seuil', $seuil)
            ->orderBy('s.quantiteDisponible', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tous les stocks, pour l'ecran de saisie du back-office.
     *
     * @return list<Stock>
     */
    public function findPourAdministration(): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('p', 'c')
            ->innerJoin('s.produit', 'p')
            ->innerJoin('p.categorie', 'c')
            ->orderBy('c.nom', 'ASC')
            ->addOrderBy('p.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function compterEpuises(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->innerJoin('s.produit', 'p')
            ->andWhere('s.quantiteDisponible <= 0')
            ->andWhere('p.disponible = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(Stock $stock, bool $flush = true): void
    {
        $this->getEntityManager()->persist($stock);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
