<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LigneCommandeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Detail d'un produit au sein d'une commande (dictionnaire 6.2.6).
 *
 * Entite associative decomposant la relation N:N entre COMMANDE et PRODUIT.
 * Le prix unitaire y est fige au moment de la commande : une hausse de prix
 * ulterieure ne modifie pas les commandes deja passees (Jalon 3, 6.3).
 */
#[ORM\Entity(repositoryClass: LigneCommandeRepository::class)]
#[ORM\Table(name: 'ligne_commande')]
class LigneCommande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lignes')]
    #[ORM\JoinColumn(name: 'commande_id', nullable: false, onDelete: 'CASCADE')]
    private ?Commande $commande = null;

    #[ORM\ManyToOne(inversedBy: 'lignesCommande')]
    #[ORM\JoinColumn(name: 'produit_id', nullable: false)]
    #[Assert\NotNull]
    private ?Produit $produit = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\Positive(message: 'La quantite doit etre strictement positive.')]
    private ?string $quantite = '0.00';

    #[ORM\Column(name: 'prix_unitaire', type: Types::DECIMAL, precision: 8, scale: 2)]
    #[Assert\PositiveOrZero]
    private ?string $prixUnitaire = '0.00';

    #[ORM\Column(name: 'sous_total', type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private ?string $sousTotal = '0.00';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommande(): ?Commande
    {
        return $this->commande;
    }

    public function setCommande(?Commande $commande): static
    {
        $this->commande = $commande;

        return $this;
    }

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    /**
     * Associer un produit fige egalement son prix courant.
     */
    public function setProduit(?Produit $produit): static
    {
        $this->produit = $produit;

        if (null !== $produit && '0.00' === $this->prixUnitaire) {
            $this->setPrixUnitaire($produit->getPrixFloat());
        }

        return $this;
    }

    public function getQuantite(): ?string
    {
        return $this->quantite;
    }

    public function getQuantiteFloat(): float
    {
        return (float) $this->quantite;
    }

    public function setQuantite(string|float $quantite): static
    {
        $this->quantite = number_format((float) $quantite, 2, '.', '');

        return $this->recalculerSousTotal();
    }

    public function getPrixUnitaire(): ?string
    {
        return $this->prixUnitaire;
    }

    public function getPrixUnitaireFloat(): float
    {
        return (float) $this->prixUnitaire;
    }

    public function setPrixUnitaire(string|float $prixUnitaire): static
    {
        $this->prixUnitaire = number_format((float) $prixUnitaire, 2, '.', '');

        return $this->recalculerSousTotal();
    }

    public function getSousTotal(): ?string
    {
        return $this->sousTotal;
    }

    public function getSousTotalFloat(): float
    {
        return (float) $this->sousTotal;
    }

    /**
     * sous_total = quantite x prix_unitaire.
     *
     * Le champ est derive : il n'existe pas de setter public, ce qui garantit
     * qu'il reste toujours coherent avec les deux autres colonnes.
     */
    public function recalculerSousTotal(): static
    {
        $this->sousTotal = number_format(
            round($this->getQuantiteFloat() * $this->getPrixUnitaireFloat(), 2),
            2,
            '.',
            ''
        );

        return $this;
    }

    /**
     * Libelle de la quantite, par exemple "2,5 kg" ou "3 piece(s)".
     */
    public function getQuantiteLibelle(): string
    {
        $abreviation = $this->produit?->getUniteVente()?->abreviation() ?? '';

        return rtrim(rtrim(number_format($this->getQuantiteFloat(), 2, ',', ' '), '0'), ',')
            .' '.$abreviation;
    }
}
