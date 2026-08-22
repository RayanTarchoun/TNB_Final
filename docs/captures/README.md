# Captures pour le dossier de projet

Éléments produits pour l'annexe I du dossier (« Éléments à joindre à la version
finale »). Chaque fichier indique le chapitre où l'insérer.

Les captures d'écran sont prises sur l'**image de production**, jamais sur le
conteneur de développement : en mode debug, Symfony injecte sa barre d'outils en
bas de chaque page, ce qui n'a pas sa place dans un document remis au jury.

## Interface de l'application

| Fichier | Contenu | Chapitre |
|---|---|---|
| `05_accueil_desktop.png` | Accueil desktop, hero, produits du moment, bloc météo | V |
| `05_accueil_mobile.png` | Accueil mobile : hamburger, grille 2 colonnes | V |
| `05_catalogue_desktop.png` | Catalogue : sidebar de filtres, grille 3 colonnes, tri | V |
| `05_catalogue_mobile.png` | Catalogue mobile | V |
| `05_fiche_produit.png` | Fiche produit : prix à l'unité, stock, origine, suggestions | V |
| `05_panier.png` | Panier et récapitulatif | V |
| `05_mes_commandes.png` | Historique client avec statuts colorés | V |
| `05_profil.png` | Profil, changement de mot de passe, suppression RGPD | V et IX |
| `05_admin_dashboard.png` | Tableau de bord : 4 indicateurs, alertes stocks bas | V |
| `05_admin_produits.png` | CRUD produits, activation/désactivation | V |
| `05_admin_stocks.png` | Stocks : jauge d'écoulement, réapprovisionnement | V |
| `05_admin_commandes.png` | Commandes filtrables, statuts colorés | V |
| `05_admin_categories.png` | Gestion des catégories | V |
| `09_connexion.png` | Page de connexion | IX |
| `09_inscription.png` | Page d'inscription, mention RGPD | IX |

## Tests et qualité de code

| Fichier | Contenu | Chapitre |
|---|---|---|
| `10_tests_phpunit.txt` / `.png` | Suite complète : 221 tests, 671 assertions, au vert | X |
| `10_phpstan.txt` / `.png` | Analyse statique niveau 6 : aucune erreur | X |
| `10_cs-fixer.txt` / `.png` | PSR-12 : aucun fichier à corriger | X |
| `10_ci_github_actions.png` | Pipeline verte, 4 jobs dont la publication Docker Hub | IV et X |

Les fichiers `.txt` sont la source de vérité ; les `.png` sont les mêmes sorties
mises en forme, prêtes à être insérées dans le document.

## Reproduire ces captures

```bash
# 1. Base de données et jeu de démonstration
docker compose up -d --build
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console doctrine:fixtures:load --no-interaction
docker compose exec app php bin/console app:creer-administrateur admin@tarchoun.fr Hamadi Tarchoun

# 2. Image de production, branchée sur la même base
docker build --target prod -t tnb:prod -f docker/php/Dockerfile .
docker run -d --name tnb_captures --network tnb_tnb -p 8100:80 \
  -e APP_ENV=prod -e APP_DEBUG=0 -e APP_SECRET=<32 caractères> \
  -e DATABASE_URL='mysql://tnb:tnb@database:3306/tnb?serverVersion=8.0.36&charset=utf8mb4' \
  -e DEFAULT_URI=http://localhost:8100 tnb:prod

# 3. Captures
npm install
TNB_URL=http://localhost:8100 node tools/capture-ecrans.mjs

# 4. Sorties de tests et de qualité
php bin/phpunit --no-coverage            > docs/captures/10_tests_phpunit.txt
vendor/bin/phpstan analyse --no-progress > docs/captures/10_phpstan.txt
vendor/bin/php-cs-fixer fix --dry-run    > docs/captures/10_cs-fixer.txt
node tools/capture-sorties.mjs
```

## Note sur les tests navigateur

La suite complète (221 tests) inclut six tests Panther qui pilotent un vrai
Chrome. Ils s'exécutent sur la machine de développement et sur les runners
GitHub Actions, mais **pas dans le conteneur applicatif**, qui n'embarque pas de
navigateur — l'image de production n'a aucune raison d'en contenir un.
