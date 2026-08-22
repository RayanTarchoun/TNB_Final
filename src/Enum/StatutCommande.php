<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Cycle de vie d'une commande (glossaire CDCF 3.7).
 *
 * Le workflow nominal est EN_ATTENTE -> PREPAREE -> RECUPEREE.
 * Une commande peut etre annulee tant qu'elle n'a pas ete recuperee.
 */
enum StatutCommande: string
{
    case EN_ATTENTE = 'EN_ATTENTE';
    case PREPAREE = 'PREPAREE';
    case RECUPEREE = 'RECUPEREE';
    case ANNULEE = 'ANNULEE';

    public function libelle(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::PREPAREE => 'Preparee',
            self::RECUPEREE => 'Recuperee',
            self::ANNULEE => 'Annulee',
        };
    }

    /**
     * Classe CSS du badge, alignee sur la charte graphique (Jalon 2, 2.2) :
     * jaune = en attente, bleu = preparee, vert = recuperee, rouge = annulee.
     */
    public function classeBadge(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'badge-statut-attente',
            self::PREPAREE => 'badge-statut-preparee',
            self::RECUPEREE => 'badge-statut-recuperee',
            self::ANNULEE => 'badge-statut-annulee',
        };
    }

    /**
     * Statuts vers lesquels la commande peut evoluer.
     *
     * Cette table est la seule source de verite du workflow : elle est
     * utilisee par CommandeService::changerStatut() pour rejeter les
     * transitions incoherentes (dossier chap. VII.2.3).
     *
     * @return list<self>
     */
    public function transitionsAutorisees(): array
    {
        return match ($this) {
            self::EN_ATTENTE => [self::PREPAREE, self::ANNULEE],
            self::PREPAREE => [self::RECUPEREE, self::ANNULEE],
            self::RECUPEREE, self::ANNULEE => [],
        };
    }

    public function peutEvoluerVers(self $cible): bool
    {
        return \in_array($cible, $this->transitionsAutorisees(), true);
    }

    /**
     * Un statut final n'accepte plus aucune transition.
     */
    public function estFinal(): bool
    {
        return [] === $this->transitionsAutorisees();
    }

    /**
     * L'annulation d'une commande doit reapprovisionner le stock reserve.
     */
    public function libereLeStock(): bool
    {
        return self::ANNULEE === $this;
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
