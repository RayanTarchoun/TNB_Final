<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UniteVente;
use App\Repository\ProduitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Article vendable : fruit ou legume (dictionnaire 6.2.3).
 *
 * Les donnees "statiques" du produit sont volontairement separees des
 * quantites, portees par l'entite Stock en relation 1:1 (Jalon 3, 6.3).
 */
#[ORM\Entity(repositoryClass: ProduitRepository::class)]
#[ORM\Table(name: 'produit')]
#[ORM\Index(name: 'idx_produit_nom', columns: ['nom'])]
#[ORM\Index(name: 'idx_produit_disponible', columns: ['disponible'])]
#[ORM\HasLifecycleCallbacks]
class Produit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Le nom du produit est obligatoire.')]
    #[Assert\Length(max: 150)]
    private ?string $nom = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    #[Assert\NotBlank(message: 'Le prix est obligatoire.')]
    #[Assert\Positive(message: 'Le prix doit etre strictement positif.')]
    #[Assert\LessThanOrEqual(value: 999999.99, message: 'Le prix est trop eleve.')]
    private ?string $prix = null;

    /**
     * Colonne ENUM native, conformement au MPD (Jalon 3, 5.3) : le SGBD
     * refuse lui-meme toute valeur hors domaine.
     *
     * "columnDefinition" n'etant pas introspectable, le comparateur Doctrine
     * signale un ecart permanent sur cette colonne : la synchronisation se
     * verifie donc avec "doctrine:schema:validate --skip-sync" (cf. README).
     */
    #[ORM\Column(
        name: 'unite_vente',
        type: Types::STRING,
        enumType: UniteVente::class,
        columnDefinition: "ENUM('KG','PIECE','BOTTE','BARQUETTE') NOT NULL"
    )]
    #[Assert\NotNull(message: "L'unite de vente est obligatoire.")]
    private ?UniteVente $uniteVente = UniteVente::KG;

    #[ORM\Column(name: 'image_url', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $imageUrl = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $origine = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $disponible = true;

    #[ORM\ManyToOne(inversedBy: 'produits')]
    #[ORM\JoinColumn(name: 'categorie_id', nullable: false)]
    #[Assert\NotNull(message: 'La categorie est obligatoire.')]
    private ?Categorie $categorie = null;

    #[ORM\Column(name: 'date_creation', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dateCreation;

    #[ORM\Column(name: 'date_modification', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateModification = null;

    /**
     * Relation 1:1 : le stock porte la cle etrangere UNIQUE vers le produit.
     */
    #[ORM\OneToOne(mappedBy: 'produit', targetEntity: Stock::class, cascade: ['persist', 'remove'])]
    #[Assert\Valid]
    private ?Stock $stock = null;

    /**
     * @var Collection<int, LigneCommande>
     */
    #[ORM\OneToMany(mappedBy: 'produit', targetEntity: LigneCommande::class)]
    private Collection $lignesCommande;

    public function __construct()
    {
        $this->lignesCommande = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return (string) $this->nom;
    }

    #[ORM\PreUpdate]
    public function toucherDateModification(): void
    {
        $this->dateModification = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Doctrine expose les DECIMAL sous forme de chaine pour ne perdre
     * aucune precision : getPrixFloat() est fourni pour les calculs.
     */
    public function getPrix(): ?string
    {
        return $this->prix;
    }

    public function setPrix(string|float $prix): static
    {
        $this->prix = number_format((float) $prix, 2, '.', '');

        return $this;
    }

    public function getPrixFloat(): float
    {
        return (float) $this->prix;
    }

    public function getUniteVente(): ?UniteVente
    {
        return $this->uniteVente;
    }

    public function setUniteVente(UniteVente $uniteVente): static
    {
        $this->uniteVente = $uniteVente;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): static
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    public function getOrigine(): ?string
    {
        return $this->origine;
    }

    public function setOrigine(?string $origine): static
    {
        $this->origine = $origine;

        return $this;
    }

    /**
     * Drapeau d'activation pilote par l'administrateur (CDCF 3.3.2).
     * Different de estDisponible(), qui tient aussi compte du stock.
     */
    public function isDisponible(): bool
    {
        return $this->disponible;
    }

    public function setDisponible(bool $disponible): static
    {
        $this->disponible = $disponible;

        return $this;
    }

    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }

    public function setCategorie(?Categorie $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;

        return $this;
    }

    public function getDateModification(): ?\DateTimeImmutable
    {
        return $this->dateModification;
    }

    public function getStock(): ?Stock
    {
        return $this->stock;
    }

    public function setStock(?Stock $stock): static
    {
        if (null !== $stock && $stock->getProduit() !== $this) {
            $stock->setProduit($this);
        }

        $this->stock = $stock;

        return $this;
    }

    /**
     * Quantite encore commandable, 0 si aucun stock n'a ete saisi.
     */
    public function getQuantiteDisponible(): float
    {
        return $this->stock?->getQuantiteDisponibleFloat() ?? 0.0;
    }

    /**
     * Disponibilite au sens du glossaire CDCF 3.7 :
     * produit active ET stock restant strictement positif.
     */
    public function estDisponible(): bool
    {
        return $this->disponible && $this->getQuantiteDisponible() > 0;
    }

    /**
     * @return Collection<int, LigneCommande>
     */
    public function getLignesCommande(): Collection
    {
        return $this->lignesCommande;
    }
}
