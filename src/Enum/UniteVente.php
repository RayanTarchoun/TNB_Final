<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Unite de vente d'un produit (dictionnaire de donnees 6.2.3).
 *
 * Correspond a la colonne MySQL ENUM('KG','PIECE','BOTTE','BARQUETTE').
 */
enum UniteVente: string
{
    case KG = 'KG';
    case PIECE = 'PIECE';
    case BOTTE = 'BOTTE';
    case BARQUETTE = 'BARQUETTE';

    /**
     * Libelle affiche a cote du prix, par exemple "2,50 EUR /kg".
     */
    public function suffixePrix(): string
    {
        return match ($this) {
            self::KG => '/kg',
            self::PIECE => '/piece',
            self::BOTTE => '/botte',
            self::BARQUETTE => '/barquette',
        };
    }

    public function libelle(): string
    {
        return match ($this) {
            self::KG => 'Kilogramme',
            self::PIECE => 'Piece',
            self::BOTTE => 'Botte',
            self::BARQUETTE => 'Barquette',
        };
    }

    /**
     * Unite affichee apres une quantite, par exemple "2,5 kg".
     */
    public function abreviation(): string
    {
        return match ($this) {
            self::KG => 'kg',
            self::PIECE => 'piece(s)',
            self::BOTTE => 'botte(s)',
            self::BARQUETTE => 'barquette(s)',
        };
    }

    /**
     * Les produits vendus au kilo se commandent par pas de 500 g,
     * les autres a l'unite.
     */
    public function pasDeQuantite(): float
    {
        return self::KG === $this ? 0.5 : 1.0;
    }

    /**
     * @return array<string, string> libelle => valeur, pour les formulaires
     */
    public static function choix(): array
    {
        $choix = [];
        foreach (self::cases() as $case) {
            $choix[$case->libelle()] = $case->value;
        }

        return $choix;
    }
}
