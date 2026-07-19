# Atelier Architecture — Plateforme de configuration de villa

## Le projet

Application web en **PHP / HTML / CSS** (pas de framework) pour un cabinet d'architecture.
Deux espaces : **admin** (gestion du catalogue et des commandes) et **client** (choix d'un plan de
villa puis configuration détaillée).

Base de données : MySQL, schéma complet dans `schema.sql` (source de vérité — le lire avant
toute modification de structure).

## Fichiers déjà créés

- `schema.sql` — structure complète de la base + données de départ (formules, catégories,
  produits, un plan de démo "Villa Baobab" avec ses pièces, un devis de démo).
- `index.php` — landing page de choix de formule (**version préliminaire, à refondre** : c'est
  maintenant une page de connexion, voir plus bas).
- `config.php` — page de configuration (**version préliminaire, à adapter** au choix par pièce
  et au système d'upgrade).
- `style.css` — direction artistique "papier de calque d'architecte" (grille de plan, bleu
  blueprint #2B4C7E, laiton #9C7A44 pour Signature, polices Fraunces / Inter / IBM Plex Mono).
  À conserver et étendre plutôt que recréer.

Ces trois fichiers PHP sont des maquettes de départ, pas branchées à la base de données —
le vrai travail de connexion à MySQL reste à faire.

## Comptes et connexion

- Une seule page de connexion pour admin et client (remplace l'ancienne page d'accueil).
- **Admin** : identifiant `vadmin`, mot de passe `myadmin` (hash bcrypt déjà en base dans
  `administrateurs`, compatible `password_verify()`).
- **Client** : deux façons de créer un compte —
  1. créé directement par l'admin depuis le dashboard ;
  2. auto-inscription : le client saisit identifiant + mot de passe + un **numéro de devis**
     (table `devis`). Si le numéro n'existe pas ou est déjà utilisé (`statut != 'disponible'`),
     l'inscription échoue. Une fois utilisé, le devis passe à `statut = 'utilise'` et se lie au
     nouveau `client_id`.
  - Devis de test en base : `DEV-2026-0001`.

## Parcours client

1. Connexion.
2. Choix d'un **plan de villa** (`plans_villa` : image, description, dimensions, surface,
   nombre de chambres).
3. Choix de la **formule** pour ce plan (Sérénité / Élégance / Signature — table `formules`,
   prix de base par plan+formule dans `plan_formule`).
4. Page de configuration : bannière avec "Vous avez choisi la formule [X]", puis les
   **grandes catégories** (`grandes_categories` : Gros œuvres / Œuvres secondaires, avec une
   checklist admin des formules concernées via `grande_categorie_formule`), puis les
   **catégories produits** de chaque grande catégorie.
5. **Choix par pièce** : chaque plan a des pièces définies par l'admin dans `pieces_plan`
   (Chambre 1, Cuisine, Salon...) + une pièce spéciale `type_piece = 'globale'` ("Toute la
   villa"). Le champ `categories_produits.par_piece` indique si le client choisit un produit
   par pièce (ex. Carrelage, Climatisation, Salle de bain, Portes intérieures) ou un seul choix
   pour toute la villa (ex. Fondations, Porte d'entrée, Escalier, Électricité). C'est modifiable
   par l'admin, pas figé dans le code.
6. **Upgrade produit** : sous chaque produit proposé (niveau de la formule choisie), un bouton
   "Voir d'autres produits" affiche 5 produits de la formule au-dessus et 5 de la formule
   du dessus de celle-ci (utiliser `formules.niveau` pour l'ordre), triés par prix croissant.
   Si le client choisit un produit d'une formule supérieure, on affiche le **supplément** =
   différence entre le prix choisi et le prix du produit de la formule de base
   (stocké dans `configuration_produits.supplement`).
7. Validation de la configuration → statut passe de `en_cours` à `en_attente`
   (`date_soumission` renseignée).

## Statuts de commande (`configurations.statut`)

- `en_cours` — le client configure encore (valeur par défaut).
- `en_attente` — le client a validé ses choix, en attente de validation admin.
- `validee` — l'admin a validé (`date_validation`, `valide_par` renseignés). **Déclenche
  l'envoi par email au client des documents techniques** (`documents_plan` : plan électrique,
  plan de plomberie, rattachés au plan de villa) — renseigner `plans_envoyes_le` une fois
  l'email parti. Le client doit aussi pouvoir les télécharger depuis son espace.

## Espace admin

Dashboard avec :
- Vue globale : visites clients (table `visites`, une ligne par connexion), résumé de chaque
  configuration client (plan, formule, options choisies par pièce, prix total).
- Gestion des commandes : liste filtrable par statut, action "valider" (en_attente → validee).
- CRUD complet (ajouter / modifier / supprimer) sur : formules, grandes catégories (avec
  checklist des formules associées), catégories produits (avec flag "par pièce"), produits
  (nom, description, prix, couleurs, **numero** — position, distinct de **reference** — SKU,
  disponibilité en checkbox, dimensions), plans de villa, pièces par plan, documents techniques
  par plan, devis.
- **Champs dynamiques** : un bouton "ajouter un champ" sur chaque type de fiche (formule,
  grande catégorie, catégorie produit, produit, plan) crée une ligne dans
  `definitions_champs_personnalises` (type texte/nombre/booléen/liste déroulante) qui apparaît
  ensuite automatiquement sur le formulaire de **toutes** les fiches de ce type. Les valeurs
  vont dans `valeurs_champs_personnalises`. Ne jamais modifier la structure des tables pour
  ajouter un champ métier — toujours passer par ce mécanisme.

## Conventions

- Tout le texte visible (labels, messages, emails) est en **français**.
- Mots de passe : bcrypt (`password_hash` / `password_verify`), jamais en clair.
- Direction visuelle à respecter : voir `style.css` (papier de calque, grille, bleu blueprint,
  Fraunces/Inter/IBM Plex Mono) — ne pas repartir sur un style générique.

## Prochaines étapes (pas encore construites)

1. Connexion à la base (PDO recommandé) + page de connexion unique admin/client.
2. Dashboard admin (vue globale + CRUD + champs dynamiques + validation commandes).
3. Parcours client complet (choix plan → formule → configuration par pièce → upgrade →
   validation).
4. Envoi d'email (documents techniques) à la validation admin.
