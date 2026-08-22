<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UtilisateurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Client ou administrateur du systeme (dictionnaire de donnees 6.2.1).
 *
 * Conformement au MPD, le role est stocke dans une simple colonne VARCHAR(20)
 * plutot que dans un tableau JSON : l'application ne connait que deux profils,
 * ROLE_CLIENT et ROLE_ADMIN.
 */
#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[ORM\Table(name: 'utilisateur')]
#[ORM\Index(name: 'idx_utilisateur_role', columns: ['role'])]
#[ORM\UniqueConstraint(name: 'uniq_utilisateur_email', columns: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe deja avec cette adresse email.')]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_CLIENT = 'ROLE_CLIENT';
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 50, maxMessage: 'Le nom ne peut pas depasser {{ limit }} caracteres.')]
    private ?string $nom = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Le prenom est obligatoire.')]
    #[Assert\Length(max: 50, maxMessage: 'Le prenom ne peut pas depasser {{ limit }} caracteres.')]
    private ?string $prenom = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank(message: "L'adresse email est obligatoire.")]
    #[Assert\Email(message: "L'adresse email {{ value }} n'est pas valide.")]
    #[Assert\Length(max: 255)]
    private ?string $email = null;

    /**
     * Mot de passe hache (bcrypt / Argon2id) : jamais stocke en clair.
     */
    #[ORM\Column(name: 'mot_de_passe', length: 255)]
    private ?string $motDePasse = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    #[Assert\Regex(
        pattern: '/^[0-9 +().-]{6,20}$/',
        message: 'Le numero de telephone n\'est pas valide.'
    )]
    private ?string $telephone = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $adresse = null;

    #[ORM\Column(length: 20, options: ['default' => self::ROLE_CLIENT])]
    #[Assert\Choice(choices: [self::ROLE_CLIENT, self::ROLE_ADMIN])]
    private string $role = self::ROLE_CLIENT;

    #[ORM\Column(name: 'date_inscription', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dateInscription;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    /**
     * @var Collection<int, Commande>
     */
    #[ORM\OneToMany(mappedBy: 'utilisateur', targetEntity: Commande::class)]
    #[ORM\OrderBy(['dateCommande' => 'DESC'])]
    private Collection $commandes;

    public function __construct()
    {
        $this->commandes = new ArrayCollection();
        $this->dateInscription = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->getNomComplet();
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

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getNomComplet(): string
    {
        return trim(($this->prenom ?? '').' '.($this->nom ?? ''));
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getMotDePasse(): ?string
    {
        return $this->motDePasse;
    }

    public function setMotDePasse(string $motDePasse): static
    {
        $this->motDePasse = $motDePasse;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function estAdministrateur(): bool
    {
        return self::ROLE_ADMIN === $this->role;
    }

    public function getDateInscription(): \DateTimeImmutable
    {
        return $this->dateInscription;
    }

    public function setDateInscription(\DateTimeImmutable $dateInscription): static
    {
        $this->dateInscription = $dateInscription;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    /**
     * @return Collection<int, Commande>
     */
    public function getCommandes(): Collection
    {
        return $this->commandes;
    }

    public function addCommande(Commande $commande): static
    {
        if (!$this->commandes->contains($commande)) {
            $this->commandes->add($commande);
            $commande->setUtilisateur($this);
        }

        return $this;
    }

    public function removeCommande(Commande $commande): static
    {
        $this->commandes->removeElement($commande);

        return $this;
    }

    // ----- Implementation de UserInterface / PasswordAuthenticatedUserInterface -----

    /**
     * Identifiant unique de connexion : l'email (CDCF 3.1).
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        // ROLE_USER est toujours accorde, comme le veut la convention Symfony.
        return array_values(array_unique([$this->role, 'ROLE_USER']));
    }

    public function getPassword(): ?string
    {
        return $this->motDePasse;
    }

    /**
     * Aucune donnee sensible temporaire n'est conservee sur l'entite.
     */
    public function eraseCredentials(): void
    {
    }
}
