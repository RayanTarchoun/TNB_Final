<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StatutCommande;
use App\Repository\CommandeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Demande de produits passee par un client (dictionnaire 6.2.5).
 *
 * La commande est composee de lignes (composition : les lignes n'existent
 * pas sans leur commande, d'ou le ON DELETE CASCADE du MPD).
 */
#[ORM\Entity(repositoryClass: CommandeRepository::class)]
#[ORM\Table(name: 'commande')]
#[ORM\Index(name: 'idx_commande_statut', columns: ['statut'])]
#[ORM\Index(name: 'idx_commande_date', columns: ['date_commande'])]
#[ORM\UniqueConstraint(name: 'uniq_commande_reference', columns: ['reference'])]
#[ORM\HasLifecycleCallbacks]
class Commande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    /**
     * Identifiant lisible communique au client, distinct de l'id technique.
     */
    #[ORM\Column(length: 20, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    private ?string $reference = null;

    #[ORM\ManyToOne(inversedBy: 'commandes')]
    #[ORM\JoinColumn(name: 'utilisateur_id', nullable: false)]
    #[Assert\NotNull]
    private ?Utilisateur $utilisateur = null;

    /**
     * Colonne ENUM native (MPD 5.5) : l'integrite du workflow est garantie
     * au niveau du SGBD autant qu'au niveau applicatif.
     *
     * Voir la note de Produit::$uniteVente concernant "--skip-sync".
     */
    #[ORM\Column(
        type: Types::STRING,
        enumType: StatutCommande::class,
        columnDefinition: "ENUM('EN_ATTENTE','PREPAREE','RECUPEREE','ANNULEE') NOT NULL DEFAULT 'EN_ATTENTE'"
    )]
    private StatutCommande $statut = StatutCommande::EN_ATTENTE;

    #[ORM\Column(name: 'date_commande', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dateCommande;

    #[ORM\Column(name: 'date_mise_a_jour', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateMiseAJour = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500, maxMessage: 'Le commentaire ne peut pas depasser {{ limit }} caracteres.')]
    private ?string $commentaire = null;

    #[ORM\Column(name: 'montant_total', type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $montantTotal = '0.00';

    /**
     * @var Collection<int, LigneCommande>
     */
    #[ORM\OneToMany(
        mappedBy: 'commande',
        targetEntity: LigneCommande::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[Assert\Count(min: 1, minMessage: 'Une commande doit contenir au moins une ligne.')]
    #[Assert\Valid]
    private Collection $lignes;

    public function __construct()
    {
        $this->lignes = new ArrayCollection();
        $this->dateCommande = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return (string) $this->reference;
    }

    #[ORM\PreUpdate]
    public function toucherDateMiseAJour(): void
    {
        $this->dateMiseAJour = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    public function getStatut(): StatutCommande
    {
        return $this->statut;
    }

    public function setStatut(StatutCommande $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDateCommande(): \DateTimeImmutable
    {
        return $this->dateCommande;
    }

    public function setDateCommande(\DateTimeImmutable $dateCommande): static
    {
        $this->dateCommande = $dateCommande;

        return $this;
    }

    public function getDateMiseAJour(): ?\DateTimeImmutable
    {
        return $this->dateMiseAJour;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): static
    {
        $this->commentaire = $commentaire;

        return $this;
    }

    public function getMontantTotal(): ?string
    {
        return $this->montantTotal;
    }

    public function getMontantTotalFloat(): float
    {
        return (float) $this->montantTotal;
    }

    public function setMontantTotal(string|float $montant): static
    {
        $this->montantTotal = number_format((float) $montant, 2, '.', '');

        return $this;
    }

    /**
     * @return Collection<int, LigneCommande>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LigneCommande $ligne): static
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setCommande($this);
        }

        return $this;
    }

    public function removeLigne(LigneCommande $ligne): static
    {
        if ($this->lignes->removeElement($ligne) && $ligne->getCommande() === $this) {
            $ligne->setCommande(null);
        }

        return $this;
    }

    // ----- Comportement metier -----

    /**
     * Somme des sous-totaux des lignes (diagramme de classes, figure 7.5).
     */
    public function calculerTotal(): float
    {
        $total = 0.0;
        foreach ($this->lignes as $ligne) {
            $total += $ligne->getSousTotalFloat();
        }

        return round($total, 2);
    }

    /**
     * Recalcule puis memorise le montant total.
     */
    public function rafraichirMontantTotal(): static
    {
        return $this->setMontantTotal($this->calculerTotal());
    }

    /**
     * Nombre total d'articles distincts.
     */
    public function getNombreArticles(): int
    {
        return $this->lignes->count();
    }

    public function estAnnulable(): bool
    {
        return $this->statut->peutEvoluerVers(StatutCommande::ANNULEE);
    }
}
