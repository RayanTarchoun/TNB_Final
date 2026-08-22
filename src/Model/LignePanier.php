<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Produit;

/**
 * Ligne du panier en session.
 *
 * Objet de transfert (non persiste) : le panier n'existe en base qu'une fois
 * la commande validee, sous forme de LigneCommande.
 */
final readonly class LignePanier
{
    public function __construct(
        public Produit $produit,
        public float $quantite,
    ) {
    }

    public function getSousTotal(): float
    {
        return round($this->quantite * $this->produit->getPrixFloat(), 2);
    }

    public function getQuantiteDisponible(): float
    {
        return $this->produit->getQuantiteDisponible();
    }

    /**
     * La quantite demandee est-elle encore couverte par le stock ?
     *
     * Le stock a pu baisser depuis l'ajout au panier : le panier est
     * revalide a chaque affichage et a la validation de la commande.
     */
    public function estDisponible(): bool
    {
        return $this->produit->isDisponible()
            && $this->getQuantiteDisponible() + 0.001 >= $this->quantite;
    }

    /**
     * Quantite maximale que le client peut encore commander.
     */
    public function getQuantiteMaximale(): float
    {
        return max($this->quantite, $this->getQuantiteDisponible());
    }

    public function getQuantiteLibelle(): string
    {
        $abreviation = $this->produit->getUniteVente()?->abreviation() ?? '';

        return rtrim(rtrim(number_format($this->quantite, 2, ',', ' '), '0'), ',').' '.$abreviation;
    }
}
