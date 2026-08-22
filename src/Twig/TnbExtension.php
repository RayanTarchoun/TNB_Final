<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\StatutCommande;
use App\Service\PanierService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Helpers d'affichage propres au projet.
 *
 * Le formatage des montants et des quantites est centralise ici pour que
 * toutes les vues presentent les memes conventions francaises.
 */
class TnbExtension extends AbstractExtension
{
    public function __construct(
        private readonly PanierService $panierService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('panier_nombre_articles', $this->nombreArticles(...)),
            new TwigFunction('statuts_commande', $this->statutsCommande(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('prix', $this->prix(...)),
            new TwigFilter('quantite', $this->quantite(...)),
        ];
    }

    /**
     * Alimente le badge du header (Jalon 2, 1.2).
     */
    public function nombreArticles(): int
    {
        return $this->panierService->getNombreArticles();
    }

    /**
     * Les statuts de commande, dans l'ordre du workflow.
     *
     * Evite de dupliquer la liste dans les vues du back-office : elle reste
     * definie a un seul endroit, l'enumeration StatutCommande.
     *
     * @return list<StatutCommande>
     */
    public function statutsCommande(): array
    {
        return StatutCommande::cases();
    }

    /**
     * "2.50" -> "2,50 EUR".
     */
    public function prix(string|float|null $montant): string
    {
        return number_format((float) $montant, 2, ',', ' ').' EUR';
    }

    /**
     * "2.50" -> "2,5" ; "3.00" -> "3" (les zeros inutiles sont retires).
     */
    public function quantite(string|float|null $valeur): string
    {
        $formate = number_format((float) $valeur, 2, ',', ' ');

        if (str_contains($formate, ',')) {
            $formate = rtrim(rtrim($formate, '0'), ',');
        }

        return '' === $formate ? '0' : $formate;
    }
}
