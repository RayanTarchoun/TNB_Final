<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Produit;
use App\Entity\Stock;
use App\Exception\StockInsuffisantException;
use App\Repository\StockRepository;

/**
 * Regles metier liees au stock (CDCF 3.3.3).
 *
 * Toute la logique de disponibilite est centralisee ici plutot que dans les
 * controleurs : c'est le point unique ou l'on decide qu'une commande passe
 * ou non (responsabilite unique, dossier chap. VIII.3 et XIV.1).
 */
class StockService
{
    public function __construct(
        private readonly StockRepository $stockRepository,
    ) {
    }

    /**
     * Le produit peut-il etre commande dans cette quantite ?
     */
    public function estDisponible(Produit $produit, float $quantite): bool
    {
        if (!$produit->isDisponible() || $quantite <= 0) {
            return false;
        }

        $stock = $produit->getStock();

        return null !== $stock && $stock->estDisponiblePour($quantite);
    }

    /**
     * Verifie la disponibilite sans rien modifier.
     *
     * @throws StockInsuffisantException si le stock ne couvre pas la demande
     */
    public function verifierDisponibilite(Produit $produit, float $quantite): void
    {
        if ($this->estDisponible($produit, $quantite)) {
            return;
        }

        throw new StockInsuffisantException($produit, $quantite, $produit->getQuantiteDisponible());
    }

    /**
     * Retire une quantite du stock apres l'avoir revalidee.
     *
     * Le stock est relu depuis la base (avec verrou si une transaction est
     * ouverte) : la quantite controlee est donc la quantite reelle au moment
     * de l'ecriture, et non celle lue au debut de la requete.
     *
     * @throws StockInsuffisantException
     */
    public function decrementer(Produit $produit, float $quantite): Stock
    {
        $stock = $this->stockRepository->trouverPourMiseAJour($produit) ?? $produit->getStock();

        if (null === $stock || !$stock->estDisponiblePour($quantite)) {
            throw new StockInsuffisantException($produit, $quantite, $stock?->getQuantiteDisponibleFloat() ?? 0.0);
        }

        $stock->decrementer($quantite);

        return $stock;
    }

    /**
     * Remet une quantite en stock (annulation d'une commande).
     */
    public function restituer(Produit $produit, float $quantite): void
    {
        if ($quantite <= 0) {
            return;
        }

        $stock = $this->stockRepository->trouverPourMiseAJour($produit) ?? $produit->getStock();

        $stock?->incrementer($quantite);
    }

    /**
     * Saisie des quantites achetees pour un jour de marche (CDCF 3.3.3).
     *
     * Reapprovisionner remet la quantite disponible a la quantite achetee :
     * c'est le geste du gerant qui repart d'un stock neuf le matin du marche.
     */
    public function reapprovisionner(
        Stock $stock,
        float $quantiteAchetee,
        ?\DateTimeImmutable $dateMarche = null,
    ): void {
        $stock->setQuantiteAchetee($quantiteAchetee)
            ->setQuantiteDisponible($quantiteAchetee)
            ->setDateMarche($dateMarche ?? new \DateTimeImmutable('today'));

        $this->stockRepository->save($stock);
    }

    /**
     * Stocks sous le seuil d'alerte, pour le tableau de bord.
     *
     * @return list<Stock>
     */
    public function stocksBas(float $seuil): array
    {
        return $this->stockRepository->stocksBas($seuil);
    }
}
