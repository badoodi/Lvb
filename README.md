# Les Villas Blanches — Plateforme de configuration de villa

Application PHP / MySQL (sans framework) pour l'atelier d'architecture
**Les Villas Blanches** (Vincent Bénard). Deux espaces : **admin** (catalogue +
commandes) et **client** (choix d'un plan puis configuration détaillée par pièce).

## Mise en route

1. **Base de données** — importer le schéma (structure + données de départ) :
   ```bash
   mysql < schema.sql
   ```
   Cela vide puis (re)crée les tables de la base `missa2796059` avec le compte admin, les 3 formules,
   les catégories, le plan de démo « Villa Baobab » et le devis de test.

2. **Configuration** — renseigner les identifiants MySQL dans `lib/config.php`,
   ou via variables d'environnement : `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`,
   `DB_PASS`, `MAIL_FROM`.

3. **Lancer** — pointer un serveur web (Apache/Nginx + PHP 8.1+) sur la racine,
   ou en local :
   ```bash
   php -S 127.0.0.1:8000
   ```
   puis ouvrir <http://127.0.0.1:8000/>.

## Comptes

- **Admin** : `vadmin` / `myadmin`
- **Client** : auto-inscription avec le devis de test **`DEV-2026-0001`**
  (ou compte créé par l'admin depuis le dashboard).

## Parcours

- **Client** : connexion → choix d'un plan → choix de la formule → configuration
  par pièce (avec upgrades vers la formule supérieure et calcul du supplément) →
  validation. Une fois la commande validée par l'admin, les documents techniques
  sont envoyés par email et téléchargeables depuis l'espace client.
- **Admin** : vue globale (visites, résumé des configurations), gestion des
  commandes (validation → email), CRUD complet (plans, pièces, documents,
  formules, grandes catégories, catégories, produits, devis, clients) et
  **champs dynamiques** ajoutables sur chaque type de fiche sans toucher au schéma.

## Organisation du code

| Chemin | Rôle |
|--------|------|
| `index.php`, `register.php`, `logout.php` | Connexion unique, auto-inscription, déconnexion |
| `lib/config.php`, `lib/db.php` | Configuration + connexion PDO |
| `lib/functions.php` | Session, CSRF, auth, helpers, champs dynamiques |
| `lib/layout.php` | Gabarits (public / client / admin) |
| `lib/crud.php` | Moteur CRUD générique du back-office |
| `lib/mail.php` | Envoi des documents techniques à la validation |
| `client/` | Parcours client (plans, formule, configuration, documents) |
| `admin/`, `admin/crud/` | Dashboard, commandes et écrans de gestion |
| `style.css` | Direction artistique « papier de calque » |
| `schema.sql` | Structure de la base — **source de vérité** |

Tout le texte visible est en français ; les mots de passe sont hachés en bcrypt.
