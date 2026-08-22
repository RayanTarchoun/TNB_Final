<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Categorie;
use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\Produit;
use App\Entity\Stock;
use App\Entity\Utilisateur;
use App\Enum\StatutCommande;
use App\Enum\UniteVente;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de donnees de demonstration.
 *
 * Reprend les produits et les prix des maquettes haute fidelite (Jalon 2,
 * section 4) afin que l'application demarre sur un catalogue credible.
 *
 * Chargement : php bin/console doctrine:fixtures:load
 */
class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $categories = $this->chargerCategories($manager);
        $produits = $this->chargerProduits($manager, $categories);
        $utilisateurs = $this->chargerUtilisateurs($manager);

        $manager->flush();

        $this->chargerCommandes($manager, $utilisateurs, $produits);

        $manager->flush();
    }

    /**
     * @return array<string, Categorie>
     */
    private function chargerCategories(ObjectManager $manager): array
    {
        $definitions = [
            'Fruit' => 'Fruits de saison, murs a point, selectionnes chaque matin.',
            'Legume' => 'Legumes frais du marche, en direct des producteurs.',
        ];

        $categories = [];

        foreach ($definitions as $nom => $description) {
            $categorie = (new Categorie())
                ->setNom($nom)
                ->setDescription($description);

            $manager->persist($categorie);
            $categories[$nom] = $categorie;
        }

        return $categories;
    }

    /**
     * @param array<string, Categorie> $categories
     *
     * @return array<string, Produit>
     */
    private function chargerProduits(ObjectManager $manager, array $categories): array
    {
        // [nom, categorie, prix, unite, origine, quantite achetee, description]
        $definitions = [
            ['Tomates grappe', 'Legume', 2.50, UniteVente::KG, 'France', 18.0, 'Tomates en grappe cultivees en pleine terre, parfumees et fermes.'],
            ['Pommes Gala', 'Fruit', 3.20, UniteVente::KG, 'Val de Loire', 25.0, 'Pommes Gala croquantes et sucrees, ideales pour les enfants.'],
            ['Carottes', 'Legume', 1.80, UniteVente::KG, 'Normandie', 30.0, 'Carottes de sable, douces et croquantes.'],
            ['Bananes', 'Fruit', 1.90, UniteVente::KG, 'Martinique', 22.0, 'Bananes de Martinique, cueillies a maturite.'],
            ['Poireaux', 'Legume', 2.10, UniteVente::BOTTE, 'Bretagne', 15.0, 'Botte de poireaux fondants, parfaits pour les soupes.'],
            ['Oranges a jus', 'Fruit', 2.80, UniteVente::KG, 'Espagne', 28.0, 'Oranges juteuses, excellentes pressees le matin.'],
            ['Courgettes', 'Legume', 2.40, UniteVente::KG, 'Provence', 16.0, 'Courgettes vertes tendres, a poeler ou a farcir.'],
            ['Fraises Gariguette', 'Fruit', 5.90, UniteVente::BARQUETTE, 'Perigord', 12.0, 'Barquette de 250 g de Gariguette, parfumee et delicate.'],
            ['Pommes de terre Charlotte', 'Legume', 1.60, UniteVente::KG, 'Picardie', 40.0, 'Chair ferme, ideale vapeur ou rissolee.'],
            ['Citrons', 'Fruit', 3.40, UniteVente::KG, 'Menton', 10.0, 'Citrons non traites, zeste utilisable en patisserie.'],
            ['Salade batavia', 'Legume', 1.20, UniteVente::PIECE, 'Ile-de-France', 24.0, 'Batavia croquante, recoltee le matin meme.'],
            ['Raisin Muscat', 'Fruit', 4.50, UniteVente::KG, 'Provence', 9.0, 'Muscat blanc tres parfume, grains fermes.'],
            ['Radis roses', 'Legume', 1.40, UniteVente::BOTTE, 'Val de Loire', 14.0, 'Botte de radis croquants, legerement piquants.'],
            ['Poires Conference', 'Fruit', 3.10, UniteVente::KG, 'France', 3.0, 'Poires fondantes et juteuses, a consommer rapidement.'],
        ];

        $produits = [];

        foreach ($definitions as [$nom, $categorie, $prix, $unite, $origine, $quantite, $description]) {
            $produit = (new Produit())
                ->setNom($nom)
                ->setDescription($description)
                ->setPrix($prix)
                ->setUniteVente($unite)
                ->setOrigine($origine)
                ->setCategorie($categories[$categorie])
                ->setDisponible(true);

            $stock = (new Stock())
                ->setQuantiteAchetee($quantite)
                ->setQuantiteDisponible($quantite)
                ->setDateMarche(new \DateTimeImmutable('today'));

            $produit->setStock($stock);

            $manager->persist($produit);
            $manager->persist($stock);

            $produits[$nom] = $produit;
        }

        // Un produit desactive, pour verifier qu'il n'apparait pas au catalogue.
        $horsSaison = (new Produit())
            ->setNom('Melon charentais')
            ->setDescription('Hors saison : reviendra cet ete.')
            ->setPrix(3.90)
            ->setUniteVente(UniteVente::PIECE)
            ->setOrigine('Charentes')
            ->setCategorie($categories['Fruit'])
            ->setDisponible(false);

        $stockHorsSaison = (new Stock())
            ->setQuantiteAchetee(0)
            ->setQuantiteDisponible(0)
            ->setDateMarche(new \DateTimeImmutable('today'));

        $horsSaison->setStock($stockHorsSaison);
        $manager->persist($horsSaison);
        $manager->persist($stockHorsSaison);
        $produits['Melon charentais'] = $horsSaison;

        return $produits;
    }

    /**
     * @return array<string, Utilisateur>
     */
    private function chargerUtilisateurs(ObjectManager $manager): array
    {
        // [email, prenom, nom, role, mot de passe, telephone]
        $definitions = [
            ['admin@tarchoun.fr', 'Hamadi', 'Tarchoun', Utilisateur::ROLE_ADMIN, 'Admin1234!', '01 23 45 67 89'],
            ['client@tarchoun.fr', 'Sophie', 'Bernard', Utilisateur::ROLE_CLIENT, 'Client1234!', '06 12 34 56 78'],
            ['marc.dubois@example.fr', 'Marc', 'Dubois', Utilisateur::ROLE_CLIENT, 'Client1234!', '06 98 76 54 32'],
            ['aicha.benali@example.fr', 'Aicha', 'Benali', Utilisateur::ROLE_CLIENT, 'Client1234!', null],
        ];

        $utilisateurs = [];

        foreach ($definitions as [$email, $prenom, $nom, $role, $motDePasse, $telephone]) {
            $utilisateur = (new Utilisateur())
                ->setEmail($email)
                ->setPrenom($prenom)
                ->setNom($nom)
                ->setRole($role)
                ->setTelephone($telephone)
                ->setActif(true);

            $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, $motDePasse));

            $manager->persist($utilisateur);
            $utilisateurs[$email] = $utilisateur;
        }

        return $utilisateurs;
    }

    /**
     * Quelques commandes couvrant les differents statuts, pour que le
     * back-office ne soit pas vide a la premiere connexion.
     *
     * @param array<string, Utilisateur> $utilisateurs
     * @param array<string, Produit> $produits
     */
    private function chargerCommandes(ObjectManager $manager, array $utilisateurs, array $produits): void
    {
        $definitions = [
            [
                'client' => 'client@tarchoun.fr',
                'statut' => StatutCommande::EN_ATTENTE,
                'jours' => 0,
                'commentaire' => 'Je passerai vers 9h, merci !',
                'lignes' => [['Tomates grappe', 2.0], ['Pommes Gala', 1.5], ['Salade batavia', 1.0]],
            ],
            [
                'client' => 'marc.dubois@example.fr',
                'statut' => StatutCommande::PREPAREE,
                'jours' => 1,
                'commentaire' => null,
                'lignes' => [['Carottes', 3.0], ['Poireaux', 2.0]],
            ],
            [
                'client' => 'aicha.benali@example.fr',
                'statut' => StatutCommande::RECUPEREE,
                'jours' => 4,
                'commentaire' => null,
                'lignes' => [['Bananes', 2.0], ['Oranges a jus', 3.0], ['Fraises Gariguette', 2.0]],
            ],
            [
                'client' => 'client@tarchoun.fr',
                'statut' => StatutCommande::RECUPEREE,
                'jours' => 7,
                'commentaire' => 'Commande de la semaine derniere.',
                'lignes' => [['Pommes de terre Charlotte', 5.0], ['Citrons', 1.0]],
            ],
        ];

        $compteur = 0;

        foreach ($definitions as $definition) {
            $date = new \DateTimeImmutable(\sprintf('-%d days', $definition['jours']));

            $commande = (new Commande())
                ->setUtilisateur($utilisateurs[$definition['client']])
                ->setReference(\sprintf('TNB-%s-%04d', $date->format('Ymd'), ++$compteur))
                ->setStatut($definition['statut'])
                ->setDateCommande($date)
                ->setCommentaire($definition['commentaire']);

            foreach ($definition['lignes'] as [$nomProduit, $quantite]) {
                $produit = $produits[$nomProduit];

                $commande->addLigne(
                    (new LigneCommande())
                        ->setProduit($produit)
                        ->setPrixUnitaire($produit->getPrixFloat())
                        ->setQuantite($quantite)
                );

                // Une commande non annulee mobilise le stock correspondant.
                if (!$definition['statut']->libereLeStock()) {
                    $produit->getStock()?->decrementer($quantite);
                }
            }

            $commande->rafraichirMontantTotal();
            $manager->persist($commande);
        }
    }
}
