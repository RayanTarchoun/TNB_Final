<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema initial du projet TNB.
 *
 * Traduction du Modele Physique de Donnees (Jalon 3, chapitre 5) : six tables
 * InnoDB / utf8mb4, cles primaires INT UNSIGNED auto-incrementees, colonnes
 * ENUM pour l'unite de vente et le statut de commande, contrainte UNIQUE sur
 * stock.produit_id (relation 1:1) et suppression en cascade des lignes de
 * commande.
 */
final class Version20260822153147 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schema initial TNB : utilisateur, categorie, produit, stock, commande, ligne_commande.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE categorie (id INT UNSIGNED AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, image_url VARCHAR(255) DEFAULT NULL, UNIQUE INDEX uniq_categorie_nom (nom), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE commande (id INT UNSIGNED AUTO_INCREMENT NOT NULL, reference VARCHAR(20) NOT NULL, statut ENUM(\'EN_ATTENTE\',\'PREPAREE\',\'RECUPEREE\',\'ANNULEE\') NOT NULL DEFAULT \'EN_ATTENTE\', date_commande DATETIME NOT NULL, date_mise_a_jour DATETIME DEFAULT NULL, commentaire LONGTEXT DEFAULT NULL, montant_total NUMERIC(10, 2) NOT NULL, utilisateur_id INT UNSIGNED NOT NULL, INDEX IDX_6EEAA67DFB88E14F (utilisateur_id), INDEX idx_commande_statut (statut), INDEX idx_commande_date (date_commande), UNIQUE INDEX uniq_commande_reference (reference), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_commande (id INT UNSIGNED AUTO_INCREMENT NOT NULL, quantite NUMERIC(10, 2) NOT NULL, prix_unitaire NUMERIC(8, 2) NOT NULL, sous_total NUMERIC(10, 2) NOT NULL, commande_id INT UNSIGNED NOT NULL, produit_id INT UNSIGNED NOT NULL, INDEX IDX_3170B74B82EA2E54 (commande_id), INDEX IDX_3170B74BF347EFB (produit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE produit (id INT UNSIGNED AUTO_INCREMENT NOT NULL, nom VARCHAR(150) NOT NULL, description LONGTEXT DEFAULT NULL, prix NUMERIC(8, 2) NOT NULL, unite_vente ENUM(\'KG\',\'PIECE\',\'BOTTE\',\'BARQUETTE\') NOT NULL, image_url VARCHAR(255) DEFAULT NULL, origine VARCHAR(50) DEFAULT NULL, disponible TINYINT DEFAULT 1 NOT NULL, date_creation DATETIME NOT NULL, date_modification DATETIME DEFAULT NULL, categorie_id INT UNSIGNED NOT NULL, INDEX IDX_29A5EC27BCF5E72D (categorie_id), INDEX idx_produit_nom (nom), INDEX idx_produit_disponible (disponible), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stock (id INT UNSIGNED AUTO_INCREMENT NOT NULL, quantite_achetee NUMERIC(10, 2) NOT NULL, quantite_disponible NUMERIC(10, 2) NOT NULL, date_marche DATE NOT NULL, date_mise_a_jour DATETIME NOT NULL, produit_id INT UNSIGNED NOT NULL, INDEX idx_stock_date_marche (date_marche), UNIQUE INDEX uniq_stock_produit (produit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE utilisateur (id INT UNSIGNED AUTO_INCREMENT NOT NULL, nom VARCHAR(50) NOT NULL, prenom VARCHAR(50) NOT NULL, email VARCHAR(255) NOT NULL, mot_de_passe VARCHAR(255) NOT NULL, telephone VARCHAR(20) DEFAULT NULL, adresse VARCHAR(255) DEFAULT NULL, role VARCHAR(20) DEFAULT \'ROLE_CLIENT\' NOT NULL, date_inscription DATETIME NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, INDEX idx_utilisateur_role (role), UNIQUE INDEX uniq_utilisateur_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67DFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE ligne_commande ADD CONSTRAINT FK_3170B74B82EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_commande ADD CONSTRAINT FK_3170B74BF347EFB FOREIGN KEY (produit_id) REFERENCES produit (id)');
        $this->addSql('ALTER TABLE produit ADD CONSTRAINT FK_29A5EC27BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id)');
        $this->addSql('ALTER TABLE stock ADD CONSTRAINT FK_4B365660F347EFB FOREIGN KEY (produit_id) REFERENCES produit (id)');
    }

    public function down(Schema $schema): void
    {
        // Les cles etrangeres sont retirees avant les tables qu'elles referencent.
        $this->addSql('ALTER TABLE commande DROP FOREIGN KEY FK_6EEAA67DFB88E14F');
        $this->addSql('ALTER TABLE ligne_commande DROP FOREIGN KEY FK_3170B74B82EA2E54');
        $this->addSql('ALTER TABLE ligne_commande DROP FOREIGN KEY FK_3170B74BF347EFB');
        $this->addSql('ALTER TABLE produit DROP FOREIGN KEY FK_29A5EC27BCF5E72D');
        $this->addSql('ALTER TABLE stock DROP FOREIGN KEY FK_4B365660F347EFB');
        $this->addSql('DROP TABLE categorie');
        $this->addSql('DROP TABLE commande');
        $this->addSql('DROP TABLE ligne_commande');
        $this->addSql('DROP TABLE produit');
        $this->addSql('DROP TABLE stock');
        $this->addSql('DROP TABLE utilisateur');
    }
}
