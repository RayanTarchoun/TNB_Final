# TNB — Tarchoun Fruits & Légumes

[![CI](https://github.com/RayanTarchoun/TNB_Final/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/RayanTarchoun/TNB_Final/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4)
![Symfony](https://img.shields.io/badge/Symfony-6.4%20LTS-000000)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479a1)
![PHPStan](https://img.shields.io/badge/PHPStan-niveau%206-2a2a2a)

Application web de commande en ligne pour **Tarchoun Fruits & Légumes**, entreprise
familiale de vente de fruits et légumes sur les marchés.

Les clients consultent le catalogue et commandent **à l'avance, en fonction des
stocks réellement achetés** pour le prochain marché. Le paiement et le retrait
s'effectuent sur place : le périmètre numérique couvre l'anticipation et
l'organisation des commandes, pas le paiement en ligne.

Projet fil rouge du titre professionnel **Concepteur Développeur d'Applications**
(IPSSI, session 2026) — TARCHOUN Rayan.

---

## Sommaire

- [Fonctionnalités](#fonctionnalités)
- [Socle technique](#socle-technique)
- [Démarrage rapide (Docker)](#démarrage-rapide-docker)
- [Installation locale (sans Docker)](#installation-locale-sans-docker)
- [Comptes de démonstration](#comptes-de-démonstration)
- [Architecture](#architecture)
- [Base de données](#base-de-données)
- [Sécurité](#sécurité)
- [Tests](#tests)
- [Qualité de code](#qualité-de-code)
- [Mise en production](#mise-en-production)
- [Commandes utiles](#commandes-utiles)

---

## Fonctionnalités

### Côté client

| Fonctionnalité | Détail |
|---|---|
| Catalogue public | Recherche plein texte, filtre par catégorie, tri, pagination |
| Fiche produit | Prix à l'unité de vente, origine, stock restant, suggestions |
| Panier | Utilisable sans compte, contrôle du stock à chaque ajout |
| Commande | Validation avec blocage si le stock est insuffisant, e-mail de confirmation |
| Historique | Suivi du statut, annulation tant que la commande n'est pas récupérée |
| Profil | Modification des données, changement de mot de passe, suppression du compte (RGPD) |

### Back-office administrateur

| Fonctionnalité | Détail |
|---|---|
| Tableau de bord | Commandes du jour, stocks bas, produits actifs, clients, meilleures ventes |
| Produits | CRUD complet, activation/désactivation, garde-fou sur la suppression |
| Stocks | Saisie des quantités achetées, réapprovisionnement en un clic, jauge d'écoulement |
| Commandes | Filtre par statut, bon de préparation imprimable, workflow de statut |
| Catégories | Création et modification |

### Intégration externe

La page d'accueil affiche la **météo des prochains jours de marché** via
[Open-Meteo](https://open-meteo.com). L'information aide le gérant à doser ses
achats. Le service est conçu pour ne jamais casser la page : en cas
d'indisponibilité de l'API, l'incident est journalisé et le bloc disparaît.

**Gestion de la clé d'API.** L'offre gratuite d'Open-Meteo n'en demande aucune ;
l'offre commerciale exige un paramètre `apikey`. Le service le prend en charge
via la variable d'environnement `METEO_API_CLE`, jamais codée en dur ni
versionnée : `.env` ne porte que la variable vide, la valeur réelle se renseigne
dans `.env.local` en développement et par variable d'environnement du conteneur
en production.

Une subtilité mérite d'être signalée : les exceptions du client HTTP citent
l'URL appelée, clé comprise. Sans précaution, elle finirait en clair dans les
logs. Le service la **masque avant journalisation**, et un test le vérifie.

---

## Socle technique

| Couche | Technologie |
|---|---|
| Langage | PHP 8.4+ |
| Framework | Symfony 6.4 LTS (full-stack) |
| Vue | Twig + Bootstrap 5 (servi localement, aucun CDN requis) |
| ORM | Doctrine ORM 3 / DBAL 4 |
| Base de données | MySQL 8.0 (InnoDB, utf8mb4) |
| Conteneurisation | Docker + Docker Compose |
| Tests | PHPUnit 13 (unitaires, fonctionnels) + Panther (navigateur) |
| Qualité | PHPStan niveau 6, PHP CS Fixer (PSR-12) |
| CI | GitHub Actions |

---

## Démarrage rapide (Docker)

Prérequis : Docker Desktop (ou Docker Engine + Compose v2).

```bash
git clone <dépôt> tnb
cd tnb

# 1. Construction et démarrage des conteneurs
docker compose up -d --build

# 2. Installation des dépendances PHP
docker compose exec app composer install

# 3. Création du schéma de base de données
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# 4. Chargement du jeu de données de démonstration
docker compose exec app php bin/console doctrine:fixtures:load --no-interaction
```

| Service | URL |
|---|---|
| Application | <http://localhost:8080> |
| phpMyAdmin | <http://localhost:8081> (`tnb` / `tnb`) |
| MySQL | `127.0.0.1:3307` depuis l'hôte |

Arrêt : `docker compose down` (les données sont conservées dans un volume nommé).
Réinitialisation complète : `docker compose down -v`.

### En cas de conflit de port

Un serveur MySQL déjà installé sur le poste occupe souvent 3306 ou 3307, et
8080 peut être pris par un autre service. Les trois ports publiés sont
surchargeables sans modifier `docker-compose.yml` :

```bash
TNB_PORT_APP=8000 TNB_PORT_DB=3308 TNB_PORT_PHPMYADMIN=8081 docker compose up -d --build
```

Sous PowerShell :

```powershell
$env:TNB_PORT_APP='8000'; $env:TNB_PORT_DB='3308'
docker compose up -d --build
```

Vérifiez ensuite que la pile répond : `curl http://localhost:8000/sante`.

### Performance : dev ≠ prod

Le conteneur de développement monte le code depuis l'hôte et exécute Symfony en
mode debug. Sur un poste Windows, ce montage est lent : mesuré sur ce projet,
une page du catalogue met **environ 7,5 s**. Ce n'est pas représentatif de
l'application — c'est le coût du montage de fichiers.

La même page servie par l'**image de production**, où le code est embarqué et
OPcache préchargé, répond en **environ 30 ms** :

| Environnement | `/produits` |
|---|---|
| Conteneur de développement (bind mount Windows) | ~7 500 ms |
| Image de production (code embarqué) | ~32 ms |

> **Pour une démonstration, utilisez l'image de production**, jamais le
> conteneur de développement — ou lancez `symfony server:start` en local, qui
> ne souffre pas du montage.

```bash
docker build --target prod -t tnb:prod -f docker/php/Dockerfile .
docker run -d --name tnb_demo --network tnb_tnb -p 8100:80 \
  -e APP_ENV=prod -e APP_DEBUG=0 -e APP_SECRET=<32 caractères> \
  -e DATABASE_URL='mysql://tnb:tnb@database:3306/tnb?serverVersion=8.0.36&charset=utf8mb4' \
  -e DEFAULT_URI=http://localhost:8100 tnb:prod
```

---

## Installation locale (sans Docker)

Prérequis : PHP 8.4+ avec `intl`, `pdo_mysql`, `mbstring`, `zip`, `gd` ;
Composer 2 ; un serveur MySQL 8.0.

```bash
composer install
```

Créez ensuite la base et l'utilisateur applicatif :

```sql
CREATE DATABASE tnb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE tnb_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tnb'@'localhost' IDENTIFIED BY 'tnb';
GRANT ALL PRIVILEGES ON `tnb`.* TO 'tnb'@'localhost';
GRANT ALL PRIVILEGES ON `tnb\_test`.* TO 'tnb'@'localhost';
FLUSH PRIVILEGES;
```

Si votre serveur MySQL n'écoute pas sur le port 3306, créez un fichier
`.env.local` (non versionné) :

```dotenv
DATABASE_URL="mysql://tnb:tnb@127.0.0.1:3307/tnb?serverVersion=8.0.44&charset=utf8mb4"
```

> `.env.local` n'est **pas** chargé en environnement de test, afin que la suite
> reste reproductible. Pour surcharger la base de test sur votre poste, utilisez
> `.env.test.local`.

Puis initialisez la base et lancez le serveur :

```bash
composer bdd:init          # migrations + fixtures
symfony server:start       # ou : php -S 127.0.0.1:8000 -t public public/dev-router.php
```

---

## Comptes de démonstration

Créés par les fixtures (`composer bdd:init`) :

| Rôle | Identifiant | Mot de passe |
|---|---|---|
| Administrateur (le gérant) | `admin@tarchoun.fr` | `Admin1234!` |
| Client | `client@tarchoun.fr` | `Client1234!` |
| Client | `marc.dubois@example.fr` | `Client1234!` |
| Client | `aicha.benali@example.fr` | `Client1234!` |

Le jeu de données comprend 14 produits actifs (un désactivé pour vérifier le
filtrage) et 4 commandes couvrant tous les statuts.

---

## Guide utilisateur

### Parcours client

1. **Parcourir le catalogue** — depuis l'accueil, « Voir le catalogue ». Filtrez
   par catégorie, triez par prix, ou recherchez un produit. Chaque carte affiche
   le stock restant : « Plus que 2 kg ! » signale une fin de série.
2. **Ajouter au panier** — le bouton ajoute la quantité par défaut (500 g au
   kilo, 1 pour les pièces). Depuis la fiche produit, vous choisissez la
   quantité exacte. Le panier fonctionne sans compte.
3. **Ajuster le panier** — modifiez les quantités, retirez une ligne. Si le
   stock a baissé entre-temps, un bandeau propose d'aligner le panier en un clic.
4. **Valider** — la connexion est demandée à cette étape seulement. Vous pouvez
   laisser un mot au gérant, puis confirmer. Un e-mail récapitulatif est envoyé.
5. **Récupérer** — présentez votre référence (`TNB-20260822-A3F9`) au stand.
   **Le règlement se fait sur place**, aucun paiement en ligne n'est demandé.
6. **Suivre ou annuler** — « Mes commandes » affiche le statut. Une commande
   peut être annulée tant qu'elle n'a pas été récupérée ; les produits
   retournent aussitôt en vente.

### Parcours administrateur

Connectez-vous avec le compte gérant, puis « Back-office ».

| Écran | Usage quotidien |
|---|---|
| **Tableau de bord** | Commandes du jour, alertes de stock bas, meilleures ventes pour ajuster les achats |
| **Stocks** | Le matin du marché : saisissez la quantité achetée et cliquez « Réappro. ». Le stock restant repart à neuf |
| **Commandes** | Filtrez sur « En attente », ouvrez une commande, imprimez le bon de préparation, puis passez le statut à « Préparée » |
| **Produits** | Créez, modifiez, activez ou désactivez. Un produit déjà commandé est désactivé plutôt que supprimé, pour préserver l'historique |
| **Catégories** | Rarement utilisé : Fruit et Légume suffisent au démarrage |

Le statut suit un ordre strict : **En attente → Préparée → Récupérée**. Vous ne
pouvez pas sauter d'étape ; l'annulation, elle, remet les produits en stock.

---

## Architecture

Architecture **MVC en couches**, conforme au chapitre VIII du dossier.

```
Requête HTTP
   │
   ▼
Contrôleurs (src/Controller)      ── couche présentation
   │  aucune logique métier : ils orchestrent et répondent
   ▼
Services (src/Service)            ── couche métier
   │  PanierService · StockService · CommandeService
   │  MeteoMarcheService · NotificationCommandeService
   ▼
Repositories (src/Repository)     ── couche accès aux données
   │  requêtes DQL paramétrées
   ▼
Entités Doctrine (src/Entity) ──► MySQL 8.0
   │
   ▼
Vues Twig (templates/)            ── échappement automatique
```

Points structurants :

- **Toute règle de stock passe par `StockService`.** Le décrément est relu sous
  verrou pessimiste à l'intérieur de la transaction de commande : deux clients
  qui valident simultanément ne peuvent pas dépasser le stock réel.
- **`CommandeService` porte le workflow.** Les transitions autorisées sont
  définies une seule fois, dans l'énumération `StatutCommande` ; le contrôleur
  ne les connaît pas.
- **Le prix est figé à la commande** dans `LigneCommande`, indépendamment des
  modifications tarifaires ultérieures.
- **`CommandeVoter`** centralise le cloisonnement : un client ne voit et
  n'annule que ses propres commandes.

### Cycle de vie d'une commande

```
EN_ATTENTE ──► PREPAREE ──► RECUPEREE
     │              │
     └──────────────┴──────► ANNULEE   (restitue le stock)
```

`RECUPEREE` et `ANNULEE` sont des états finaux. Toute transition hors de ce
graphe est rejetée par `TransitionStatutInvalideException`, y compris si elle
est forgée par une requête HTTP directe.

---

## Base de données

Six tables issues du Modèle Physique de Données (Jalon 3) :
`utilisateur`, `categorie`, `produit`, `stock`, `commande`, `ligne_commande`.

Choix notables :

- `produit` ↔ `stock` en **1:1** (clé étrangère `UNIQUE`), pour découpler les
  données statiques du produit des quantités fréquemment mises à jour ;
- `ligne_commande` est une **entité associative** portant `prix_unitaire` et
  `sous_total` ;
- `ON DELETE CASCADE` sur `ligne_commande.commande_id` (composition) ;
- schéma en **3NF**, colonnes `INT UNSIGNED`, moteur InnoDB, `utf8mb4`.

### Note sur les colonnes ENUM

Le MPD impose des colonnes **`ENUM` natives MySQL** pour `produit.unite_vente`
et `commande.statut` : le SGBD refuse lui-même toute valeur hors domaine. Elles
sont déclarées via `columnDefinition` dans les entités.

Conséquence connue : le comparateur de schéma de Doctrine ne sait pas
introspecter une définition de colonne personnalisée et signale un écart
permanent sur ces deux colonnes. Les deux `ALTER TABLE` qu'il propose sont des
no-op (définition identique).

**En pratique**, on vérifie donc la cohérence du mapping avec :

```bash
php bin/console doctrine:schema:validate --skip-sync
```

C'est cette commande qui est utilisée dans la pipeline CI. Après un
`make:migration`, pensez à retirer ces deux `ALTER TABLE` parasites de la
migration générée.

---

## Sécurité

Mesures mises en œuvre (chapitre IX du dossier) :

| Menace | Mesure |
|---|---|
| Injection SQL | Doctrine ORM, requêtes DQL paramétrées, aucune concaténation |
| XSS | Échappement automatique de Twig |
| CSRF | Jeton sur tous les formulaires et toutes les actions POST |
| Force brute | `login_throttling` : 5 tentatives par 15 minutes |
| Mots de passe | `PasswordHasher` (Argon2id / bcrypt), jamais stockés en clair |
| Contrôle d'accès | `access_control` + `CommandeVoter` (ROLE_CLIENT / ROLE_ADMIN) |
| Énumération de comptes | Message de connexion volontairement générique |
| Comptes désactivés | `UtilisateurChecker` refuse l'authentification |
| Validation | Symfony Validator sur les entités et les formulaires |
| En-têtes HTTP | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` |

**RGPD** — collecte minimale (nom, prénom, e-mail, mot de passe, téléphone
facultatif), aucune donnée bancaire, droits d'accès, de rectification et
d'effacement accessibles depuis le profil. La suppression anonymise le compte
plutôt que de le détruire, afin de préserver la traçabilité des commandes déjà
honorées.

---

## Tests

**200 tests, 613 assertions.**

```bash
composer test              # toute la suite
composer test:unit         # logique métier isolée, sans base de données
composer test:fonctionnel  # parcours HTTP complets
composer test:navigateur   # tests d'interface dans un vrai Chrome
composer couverture        # rapport HTML dans var/couverture (nécessite Xdebug)
```

| Suite | Portée |
|---|---|
| `tests/Unit/Entity` | Invariants des entités (décrément de stock, calcul du total, prix figé) |
| `tests/Unit/Enum` | Workflow de statut : transitions autorisées et interdites |
| `tests/Unit/Service` | `StockService`, `CommandeService`, `PanierService`, `MeteoMarcheService` |
| `tests/Functional` | Catalogue, panier, commande, sécurité, back-office |
| `tests/Functional/SecuriteXssTest` | Injection de charges XSS réfléchies et stockées |
| `tests/Functional/PerformanceCatalogueTest` | Temps de réponse et garde-fou anti N+1 sur 214 produits |
| `tests/Panther` | Rendu responsive et JavaScript, dans un navigateur réel |

Les tests fonctionnels créent le schéma une fois par processus puis rechargent
les fixtures avant chaque test : chaque scénario part du même jeu de données,
sans dépendre de l'ordre d'exécution. Ils utilisent la base **`tnb_test`**, la
base de travail n'est jamais touchée.

### Tests navigateur (Panther)

Ils pilotent un vrai Chrome et nécessitent le driver correspondant :

```bash
composer pilotes   # télécharge drivers/chromedriver (non versionné)
```

Panther démarre son propre serveur web dans l'environnement `panther`, aiguillé
vers `tnb_test` par `config/packages/doctrine.yaml`. Ces tests vérifient ce que
le client HTTP simulé ne peut pas voir : repli du menu sur mobile, ouverture
effective du hamburger, adaptation de la grille, absence de débordement
horizontal et absence d'erreur JavaScript.

### Scénarios notables couverts

- Blocage d'une commande dont le stock a chuté entre l'ajout au panier et la validation.
- Restitution du stock à l'annulation, côté client comme côté administrateur.
- Refus d'une transition de statut forgée par requête HTTP directe.
- Cloisonnement des commandes entre clients (403) et du back-office (403).
- Déclenchement effectif de la limitation anti-force-brute.
- Échappement de charges XSS injectées via la recherche, le nom d'un produit,
  le commentaire d'une commande et le prénom du profil.
- Dégradation propre de l'API météo : erreur serveur, panne réseau et réponse
  illisible renvoient une liste vide sans casser la page d'accueil.
- Nombre de requêtes SQL constant que le catalogue contienne 14 ou 214 produits.

---

## Qualité de code

```bash
composer qualite      # rejoue localement les contrôles de la CI
composer style        # applique PSR-12
composer stan         # analyse statique PHPStan niveau 6
```

La pipeline **GitHub Actions** (`.github/workflows/ci.yml`) s'exécute à chaque
push sur `main`/`develop` et sur chaque Pull Request :

1. **Qualité** — PHP CS Fixer (PSR-12) puis PHPStan niveau 6.
2. **Tests** — service MySQL 8.0, validation du mapping Doctrine, PHPUnit.
3. **Image Docker** — construction de la cible `prod` (n'est lancée que si les
   deux premiers travaux réussissent).

---

## Mise en production

```bash
# 1. Récupérer le code
git clone https://github.com/RayanTarchoun/TNB_Final.git tnb && cd tnb

# 2. Renseigner les secrets (fichier non versionné)
cp .env.prod.dist .env.prod
#   puis compléter APP_SECRET, MYSQL_ROOT_PASSWORD, MYSQL_PASSWORD,
#   DEFAULT_URI et MAILER_DSN.
#   APP_SECRET : php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"

# 3. Construire et lancer
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build

# 4. Créer le schéma de base de données
docker compose -f docker-compose.prod.yml exec app \
    php bin/console doctrine:migrations:migrate --no-interaction

# 5. Créer le compte administrateur (première installation uniquement)
docker compose -f docker-compose.prod.yml exec app \
    php bin/console app:creer-administrateur gerant@tarchoun.fr Hamadi Tarchoun

# 6. Vérifier que l'application répond
curl -fsS https://commandes.tarchoun.fr/sante
#   -> {"statut":"ok","base_de_donnees":"ok"}
```

> **Ne chargez jamais les fixtures en production.** `doctrine:fixtures:load`
> **purge la base** avant d'insérer le jeu de démonstration. La commande
> `app:creer-administrateur` est faite pour ça : elle crée le compte du gérant,
> ou promeut un compte existant, sans toucher au reste des données.

### Publication de l'image

Pousser un tag `v*` déclenche la publication de l'image sur Docker Hub :

```bash
git tag -a v1.2 -m "Version 1.2" && git push origin v1.2
```

La publication est ignorée tant que les secrets `DOCKERHUB_USERNAME` et
`DOCKERHUB_TOKEN` ne sont pas renseignés dans les paramètres du dépôt — le
tag n'échoue pas pour autant.

### Supervision

`GET /sante` renvoie l'état de l'application et la joignabilité de la base.
Docker s'en sert comme `HEALTHCHECK` : un conteneur dont la base est
injoignable est marqué `unhealthy`. La réponse ne divulgue ni version ni
détail d'infrastructure.

L'image de production embarque le code et les dépendances (`composer install
--no-dev --classmap-authoritative`), désactive le mode debug et verrouille
OPcache avec préchargement. Aucun secret n'est écrit dans l'image : ils sont
injectés par variables d'environnement au démarrage.

**Stratégie retenue** — un déploiement manuel pendant une courte fenêtre de
maintenance suffit dans le contexte de TNB, une interruption de service n'étant
pas critique. Les stratégies bleu/vert et rolling update restent envisageables
si le trafic le justifie.

**À faire avant la vraie mise en ligne :** placer un terminaison TLS (Traefik,
Caddy ou reverse proxy Nginx) devant le conteneur applicatif — le service
n'expose aujourd'hui que HTTP en interne — et retirer l'exposition du port
MySQL.

---

## Commandes utiles

```bash
# Base de données
php bin/console doctrine:migrations:migrate      # appliquer les migrations
php bin/console doctrine:migrations:diff         # générer une migration
php bin/console doctrine:fixtures:load           # recharger les données de démo
php bin/console doctrine:schema:validate --skip-sync

# Diagnostic
php bin/console debug:router                     # lister les routes
php bin/console debug:container                  # lister les services
php bin/console lint:twig templates              # valider les gabarits
php bin/console cache:clear

# Docker
docker compose logs -f app                       # suivre les logs
docker compose exec app bash                     # ouvrir un shell
docker compose down -v                           # tout réinitialiser
```

---

## Structure du projet

```
config/          Configuration Symfony (sécurité, Doctrine, services…)
docker/          Dockerfile, vhost Apache, réglages PHP dev et prod
migrations/      Migrations Doctrine versionnées
public/          Contrôleur frontal, CSS de la charte, Bootstrap 5 local
src/
  Controller/    Points d'entrée HTTP (dont Admin/ pour le back-office)
  DataFixtures/  Jeu de données de démonstration
  Entity/        Les 6 entités du MPD
  Enum/          StatutCommande, UniteVente
  Exception/     Exceptions métier
  Form/          Types de formulaires
  Model/         Objets de transfert non persistés (LignePanier, PrevisionMeteo)
  Repository/    Accès aux données
  Security/      UserChecker et Voter
  Service/       Logique métier
  Twig/          Extension d'affichage
templates/       Gabarits Twig (dont admin/, email/, partials/)
tests/           Unit/ et Functional/
```

---

## Auteur

**TARCHOUN Rayan** — Concepteur Développeur d'Applications, IPSSI, session 2026.
Projet réalisé pour l'entreprise familiale Tarchoun Fruits & Légumes.
