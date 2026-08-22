<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Commande;
use App\Entity\Utilisateur;
use App\Enum\StatutCommande;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Commande>
 */
class CommandeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Commande::class);
    }

    /**
     * Historique d'un client : il ne voit que ses propres commandes
     * (cloisonnement CDCF 3.3.6).
     *
     * @return list<Commande>
     */
    public function historiquePour(Utilisateur $utilisateur): array
    {
        return $this->createQueryBuilder('cmd')
            ->addSelect('l', 'p')
            ->leftJoin('cmd.lignes', 'l')
            ->leftJoin('l.produit', 'p')
            ->andWhere('cmd.utilisateur = :utilisateur')
            ->setParameter('utilisateur', $utilisateur)
            ->orderBy('cmd.dateCommande', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Chargement complet d'une commande par sa reference lisible.
     */
    public function findParReference(string $reference): ?Commande
    {
        return $this->createQueryBuilder('cmd')
            ->addSelect('l', 'p', 'u')
            ->leftJoin('cmd.lignes', 'l')
            ->leftJoin('l.produit', 'p')
            ->innerJoin('cmd.utilisateur', 'u')
            ->andWhere('cmd.reference = :reference')
            ->setParameter('reference', $reference)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Back-office : toutes les commandes, filtrables par statut.
     *
     * @return list<Commande>
     */
    public function findPourAdministration(?StatutCommande $statut = null): array
    {
        $qb = $this->createQueryBuilder('cmd')
            ->addSelect('u')
            ->innerJoin('cmd.utilisateur', 'u')
            ->orderBy('cmd.dateCommande', 'DESC');

        if (null !== $statut) {
            $qb->andWhere('cmd.statut = :statut')
                ->setParameter('statut', $statut->value);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Dernieres commandes affichees sur le tableau de bord.
     *
     * @return list<Commande>
     */
    public function dernieresCommandes(int $limite = 5): array
    {
        return $this->createQueryBuilder('cmd')
            ->addSelect('u')
            ->innerJoin('cmd.utilisateur', 'u')
            ->orderBy('cmd.dateCommande', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Repartition des commandes par statut.
     *
     * @return array<string, int> valeur du statut => nombre de commandes
     */
    public function compterParStatut(): array
    {
        // Selon la version de Doctrine, une colonne enumType lue en mode
        // scalaire revient soit convertie en enumeration, soit brute.
        /** @var list<array{statut: StatutCommande|string, total: int|string}> $lignes */
        $lignes = $this->createQueryBuilder('cmd')
            ->select('cmd.statut AS statut', 'COUNT(cmd.id) AS total')
            ->groupBy('cmd.statut')
            ->getQuery()
            ->getResult();

        $repartition = [];
        foreach (StatutCommande::cases() as $statut) {
            $repartition[$statut->value] = 0;
        }

        foreach ($lignes as $ligne) {
            $cle = $ligne['statut'] instanceof StatutCommande
                ? $ligne['statut']->value
                : (string) $ligne['statut'];
            $repartition[$cle] = (int) $ligne['total'];
        }

        return $repartition;
    }

    public function compterDuJour(): int
    {
        return (int) $this->createQueryBuilder('cmd')
            ->select('COUNT(cmd.id)')
            ->andWhere('cmd.dateCommande >= :debut')
            ->setParameter('debut', new \DateTimeImmutable('today'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Chiffre d'affaires des commandes non annulees du jour.
     */
    public function montantDuJour(): float
    {
        return (float) $this->createQueryBuilder('cmd')
            ->select('COALESCE(SUM(cmd.montantTotal), 0)')
            ->andWhere('cmd.dateCommande >= :debut')
            ->andWhere('cmd.statut != :annulee')
            ->setParameter('debut', new \DateTimeImmutable('today'))
            ->setParameter('annulee', StatutCommande::ANNULEE->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Produits les plus commandes, pour la vue statistique de l'administrateur.
     *
     * @return list<array{nom: string, quantite: float, nbCommandes: int}>
     */
    public function produitsLesPlusCommandes(int $limite = 5): array
    {
        /** @var list<array{nom: string, quantite: string, nbCommandes: string}> $lignes */
        $lignes = $this->createQueryBuilder('cmd')
            ->select('p.nom AS nom', 'SUM(l.quantite) AS quantite', 'COUNT(DISTINCT cmd.id) AS nbCommandes')
            ->innerJoin('cmd.lignes', 'l')
            ->innerJoin('l.produit', 'p')
            ->andWhere('cmd.statut != :annulee')
            ->setParameter('annulee', StatutCommande::ANNULEE->value)
            ->groupBy('p.id')
            ->orderBy('quantite', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $ligne): array => [
                'nom' => $ligne['nom'],
                'quantite' => (float) $ligne['quantite'],
                'nbCommandes' => (int) $ligne['nbCommandes'],
            ],
            $lignes
        );
    }

    /**
     * Reference deja attribuee ? Utilise par le generateur de references.
     */
    public function referenceExiste(string $reference): bool
    {
        return null !== $this->findOneBy(['reference' => $reference]);
    }

    public function save(Commande $commande, bool $flush = true): void
    {
        $this->getEntityManager()->persist($commande);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
