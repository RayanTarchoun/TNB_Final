<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Prevision meteo d'un jour de marche, issue de l'API externe Open-Meteo.
 */
final readonly class PrevisionMeteo
{
    public function __construct(
        public \DateTimeImmutable $date,
        public float $temperatureMin,
        public float $temperatureMax,
        public int $codeMeteo,
    ) {
    }

    /**
     * Traduction des codes WMO renvoyes par Open-Meteo.
     *
     * @see https://open-meteo.com/en/docs
     */
    public function libelle(): string
    {
        return match (true) {
            0 === $this->codeMeteo => 'Ciel degage',
            \in_array($this->codeMeteo, [1, 2], true) => 'Peu nuageux',
            3 === $this->codeMeteo => 'Couvert',
            \in_array($this->codeMeteo, [45, 48], true) => 'Brouillard',
            \in_array($this->codeMeteo, [51, 53, 55, 56, 57], true) => 'Bruine',
            \in_array($this->codeMeteo, [61, 63, 65, 66, 67, 80, 81, 82], true) => 'Pluie',
            \in_array($this->codeMeteo, [71, 73, 75, 77, 85, 86], true) => 'Neige',
            \in_array($this->codeMeteo, [95, 96, 99], true) => 'Orage',
            default => 'Variable',
        };
    }

    /**
     * Pictogramme unicode, sans dependance a une librairie d'icones.
     */
    public function pictogramme(): string
    {
        return match (true) {
            0 === $this->codeMeteo => "\u{2600}",          // soleil
            \in_array($this->codeMeteo, [1, 2], true) => "\u{26C5}", // soleil derriere nuage
            3 === $this->codeMeteo => "\u{2601}",          // nuage
            \in_array($this->codeMeteo, [45, 48], true) => "\u{1F32B}",
            \in_array($this->codeMeteo, [51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82], true) => "\u{1F327}",
            \in_array($this->codeMeteo, [71, 73, 75, 77, 85, 86], true) => "\u{2744}",
            \in_array($this->codeMeteo, [95, 96, 99], true) => "\u{26C8}",
            default => "\u{1F324}",
        };
    }

    public function temperatureMinArrondie(): int
    {
        return (int) round($this->temperatureMin);
    }

    public function temperatureMaxArrondie(): int
    {
        return (int) round($this->temperatureMax);
    }
}
