<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Quantites achetees et restantes pour un produit et un jour de marche
 * (dictionnaire 6.2.4).
 *
 * La cle etrangere produit_id porte une contrainte UNIQUE : c'est ce qui
 * materialise la relation 1:1 du MCD (Jalon 3, 4.2).
 */
#[ORM\Entity(repositoryClass: StockRepository::class)]
#[ORM\Table(name: 'stock')]
#[ORM\Index(name: 'idx_stock_date_marche', columns: ['date_marche'])]
#[ORM\UniqueConstraint(name: 'uniq_stock_produit', columns: ['produit_id'])]
#[ORM\HasLifecycleCallbacks]
class Stock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'stock', targetEntity: Produit::class)]
    #[ORM\JoinColumn(name: 'produit_id', nullable: false, unique: true)]
    private ?Produit $produit = null;

    #[ORM\Column(name: 'quantite_achetee', type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank(message: 'La quantite achetee est obligatoire.')]
    #[Assert\PositiveOrZero(message: 'La quantite achetee ne peut pas etre negative.')]
    private ?string $quantiteAchetee = '0.00';

    #[ORM\Column(name: 'quantite_disponible', type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank(message: 'La quantite disponible est obligatoire.')]
    #[Assert\PositiveOrZero(message: 'La quantite disponible ne peut pas etre negative.')]
    private ?string $quantiteDisponible = '0.00';

    #[ORM\Column(name: 'date_marche', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Le jour de marche est obligatoire.')]
    private ?\DateTimeImmutable $dateMarche = null;

    #[ORM\Column(name: 'date_mise_a_jour', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dateMiseAJour;

    public function __construct()
    {
        $this->dateMarche = new \DateTimeImmutable('today');
        $this->dateMiseAJour = new \DateTimeImmutable();
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

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): static
    {
        $this->produit = $produit;

        return $this;
    }

    public function getQuantiteAchetee(): ?string
    {
        return $this->quantiteAchetee;
    }

    public function getQuantiteAcheteeFloat(): float
    {
        return (float) $this->quantiteAchetee;
    }

    public function setQuantiteAchetee(string|float $quantite): static
    {
        $this->quantiteAchetee = number_format((float) $quantite, 2, '.', '');

        return $this;
    }

    public function getQuantiteDisponible(): ?string
    {
        return $this->quantiteDisponible;
    }

    public function getQuantiteDisponibleFloat(): float
    {
        return (float) $this->quantiteDisponible;
    }

    public function setQuantiteDisponible(string|float $quantite): static
    {
        $this->quantiteDisponible = number_format((float) $quantite, 2, '.', '');

        return $this;
    }

    public function getDateMarche(): ?\DateTimeImmutable
    {
        return $this->dateMarche;
    }

    public function setDateMarche(\DateTimeImmutable $dateMarche): static
    {
        $this->dateMarche = $dateMarche;

        return $this;
    }

    public function getDateMiseAJour(): \DateTimeImmutable
    {
        return $this->dateMiseAJour;
    }

    // ----- Comportement metier -----

    /**
     * Le stock couvre-t-il la quantite demandee ?
     *
     * Une tolerance de 0,001 absorbe les erreurs d'arrondi des DECIMAL
     * lors des comparaisons en virgule flottante.
     */
    public function estDisponiblePour(float $quantite): bool
    {
        return $quantite > 0 && $this->getQuantiteDisponibleFloat() + 0.001 >= $quantite;
    }

    /**
     * Retire une quantite du stock restant.
     *
     * L'entite garantit l'invariant "quantite_disponible >= 0" ; la regle
     * metier de blocage de la commande, elle, est portee par StockService.
     *
     * @throws \InvalidArgumentException si la quantite demandee depasse le stock
     */
    public function decrementer(float $quantite): static
    {
        if ($quantite <= 0) {
            throw new \InvalidArgumentException('La quantite a decrementer doit etre strictement positive.');
        }

        if (!$this->estDisponiblePour($quantite)) {
            throw new \InvalidArgumentException(\sprintf('Stock insuffisant : %.2f demande(s) pour %.2f disponible(s).', $quantite, $this->getQuantiteDisponibleFloat()));
        }

        return $this->setQuantiteDisponible(max(0.0, $this->getQuantiteDisponibleFloat() - $quantite));
    }

    /**
     * Remet une quantite en stock (annulation d'une commande).
     * Le stock restant ne peut jamais depasser la quantite achetee.
     */
    public function incrementer(float $quantite): static
    {
        if ($quantite <= 0) {
            throw new \InvalidArgumentException('La quantite a incrementer doit etre strictement positive.');
        }

        $nouvelle = min(
            $this->getQuantiteAcheteeFloat(),
            $this->getQuantiteDisponibleFloat() + $quantite
        );

        return $this->setQuantiteDisponible($nouvelle);
    }

    /**
     * Quantite deja reservee par des commandes.
     */
    public function getQuantiteReservee(): float
    {
        return max(0.0, $this->getQuantiteAcheteeFloat() - $this->getQuantiteDisponibleFloat());
    }

    /**
     * Part du stock deja ecoulee, en pourcentage (barre de progression admin).
     */
    public function getPourcentageEcoule(): int
    {
        $achetee = $this->getQuantiteAcheteeFloat();
        if ($achetee <= 0) {
            return 0;
        }

        return (int) round($this->getQuantiteReservee() / $achetee * 100);
    }

    public function estEpuise(): bool
    {
        return $this->getQuantiteDisponibleFloat() <= 0;
    }
}
