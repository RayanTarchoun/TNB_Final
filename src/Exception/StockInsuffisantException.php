<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\Produit;

/**
 * Levee lorsqu'une quantite demandee depasse le stock restant.
 *
 * C'est le mecanisme de "blocage de la commande si le stock est insuffisant"
 * exige par le CDCF 3.3.3 : l'exception interrompt le processus de validation
 * avant toute persistance (diagramme de sequence 7.2.2).
 */
class StockInsuffisantException extends \RuntimeException
{
    public function __construct(
        private readonly Produit $produit,
        private readonly float $quantiteDemandee,
        private readonly float $quantiteDisponible,
    ) {
        parent::__construct(\sprintf(
            'Stock insuffisant pour "%s" : %s demande(s), %s disponible(s).',
            $produit->getNom(),
            self::formater($quantiteDemandee),
            self::formater($quantiteDisponible)
        ));
    }

    public function getProduit(): Produit
    {
        return $this->produit;
    }

    public function getQuantiteDemandee(): float
    {
        return $this->quantiteDemandee;
    }

    public function getQuantiteDisponible(): float
    {
        return $this->quantiteDisponible;
    }

    /**
     * Message destine a l'utilisateur final, affiche en rouge dans l'interface
     * (charte graphique : rouge #ED2939 pour "stock insuffisant").
     */
    public function messageUtilisateur(): string
    {
        $unite = $this->produit->getUniteVente()?->abreviation() ?? '';

        if ($this->quantiteDisponible <= 0) {
            return \sprintf('"%s" est epuise pour ce marche.', $this->produit->getNom());
        }

        return \sprintf(
            'Stock insuffisant pour "%s" : il ne reste que %s %s.',
            $this->produit->getNom(),
            self::formater($this->quantiteDisponible),
            $unite
        );
    }

    private static function formater(float $quantite): string
    {
        return rtrim(rtrim(number_format($quantite, 2, ',', ' '), '0'), ',');
    }
}
