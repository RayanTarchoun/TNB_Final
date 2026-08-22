<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\Utilisateur;
use App\Enum\StatutCommande;
use App\Exception\PanierVideException;
use App\Exception\StockInsuffisantException;
use App\Exception\TransitionStatutInvalideException;
use App\Model\LignePanier;
use App\Repository\CommandeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Regles metier des commandes (CDCF 3.3.4 et 3.3.5).
 *
 * Le service porte deux responsabilites cles :
 *  - transformer un panier en commande, en figeant les prix et en decrementant
 *    les stocks dans une transaction unique (diagramme de sequence 7.2.2) ;
 *  - faire evoluer le statut en respectant le workflow (sequence 7.2.3).
 */
class CommandeService
{
    private const PREFIXE_REFERENCE = 'TNB';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CommandeRepository $commandeRepository,
        private readonly StockService $stockService,
    ) {
    }

    /**
     * Transforme le contenu du panier en commande persistee.
     *
     * Le tout est enveloppe dans une transaction : si une seule ligne echoue
     * le controle de stock, aucune ecriture n'est conservee.
     *
     * @param list<LignePanier> $lignesPanier
     *
     * @throws PanierVideException si le panier ne contient aucun article
     * @throws StockInsuffisantException si une ligne depasse le stock restant
     */
    public function creerCommande(
        Utilisateur $utilisateur,
        array $lignesPanier,
        ?string $commentaire = null,
    ): Commande {
        if ([] === $lignesPanier) {
            throw new PanierVideException();
        }

        // 1re passe : on echoue au plus tot, avant toute ecriture.
        foreach ($lignesPanier as $ligne) {
            $this->stockService->verifierDisponibilite($ligne->produit, $ligne->quantite);
        }

        $this->entityManager->beginTransaction();

        try {
            $commande = (new Commande())
                ->setUtilisateur($utilisateur)
                ->setReference($this->genererReference())
                ->setStatut(StatutCommande::EN_ATTENTE)
                ->setCommentaire($commentaire);

            foreach ($lignesPanier as $ligne) {
                $ligneCommande = (new LigneCommande())
                    ->setProduit($ligne->produit)
                    // Le prix est fige a la commande : une modification
                    // ulterieure du tarif ne change pas l'historique.
                    ->setPrixUnitaire($ligne->produit->getPrixFloat())
                    ->setQuantite($ligne->quantite);

                $commande->addLigne($ligneCommande);
            }

            $commande->rafraichirMontantTotal();

            $this->entityManager->persist($commande);

            // 2e passe : decrement sous verrou, seule ecriture qui fait foi.
            foreach ($lignesPanier as $ligne) {
                $this->stockService->decrementer($ligne->produit, $ligne->quantite);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $commande;
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }
    }

    /**
     * Fait evoluer le statut d'une commande selon le workflow autorise.
     *
     * Annuler une commande restitue les quantites au stock : les produits
     * redeviennent immediatement commandables par d'autres clients.
     *
     * @throws TransitionStatutInvalideException si la transition est interdite
     */
    public function changerStatut(Commande $commande, StatutCommande $cible): Commande
    {
        $actuel = $commande->getStatut();

        if (!$actuel->peutEvoluerVers($cible)) {
            throw new TransitionStatutInvalideException($actuel, $cible);
        }

        $this->entityManager->beginTransaction();

        try {
            if ($cible->libereLeStock()) {
                foreach ($commande->getLignes() as $ligne) {
                    $produit = $ligne->getProduit();

                    if (null !== $produit) {
                        $this->stockService->restituer($produit, $ligne->getQuantiteFloat());
                    }
                }
            }

            $commande->setStatut($cible);

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $commande;
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }
    }

    /**
     * Annulation a l'initiative du client, limitee a ses propres commandes.
     *
     * @throws \LogicException si la commande appartient a un autre client
     * @throws TransitionStatutInvalideException si elle n'est plus annulable
     */
    public function annulerParClient(Commande $commande, Utilisateur $utilisateur): Commande
    {
        if ($commande->getUtilisateur() !== $utilisateur) {
            throw new \LogicException("Cette commande n'appartient pas a cet utilisateur.");
        }

        return $this->changerStatut($commande, StatutCommande::ANNULEE);
    }

    /**
     * Reference lisible du type "TNB-20260822-A3F9", unique en base.
     *
     * Format court (17 caracteres) compatible avec la colonne VARCHAR(20)
     * du MPD, et lisible a voix haute sur le marche.
     */
    public function genererReference(?\DateTimeImmutable $date = null): string
    {
        $date ??= new \DateTimeImmutable();

        do {
            $reference = \sprintf(
                '%s-%s-%s',
                self::PREFIXE_REFERENCE,
                $date->format('Ymd'),
                strtoupper(bin2hex(random_bytes(2)))
            );
        } while ($this->commandeRepository->referenceExiste($reference));

        return $reference;
    }

    /**
     * Total recalcule a partir des lignes (diagramme de classes 7.5).
     */
    public function calculerTotal(Commande $commande): float
    {
        return $commande->calculerTotal();
    }
}
