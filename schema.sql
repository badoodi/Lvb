-- =========================================================
-- ATELIER ARCHITECTURE — Structure de la base de données
-- Moteur : InnoDB / Charset : utf8mb4
-- =========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Base de données de la plateforme.
-- Sur un hébergement mutualisé, la base « missa2796059 » existe déjà : la ligne
-- CREATE DATABASE ci-dessous est alors sans effet (IF NOT EXISTS) — vous pouvez
-- la retirer si votre compte n'a pas le droit de créer des bases.
CREATE DATABASE IF NOT EXISTS missa2796059
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE missa2796059;

-- =========================================================
-- 0. PURGE — on vide la base avant de (re)créer les tables,
--    pour que ce script soit rejouable sans erreur.
-- =========================================================
DROP TABLE IF EXISTS configuration_produits;
DROP TABLE IF EXISTS configurations;
DROP TABLE IF EXISTS valeurs_champs_personnalises;
DROP TABLE IF EXISTS definitions_champs_personnalises;
DROP TABLE IF EXISTS produits;
DROP TABLE IF EXISTS categories_produits;
DROP TABLE IF EXISTS grande_categorie_formule;
DROP TABLE IF EXISTS grandes_categories;
DROP TABLE IF EXISTS plan_formule;
DROP TABLE IF EXISTS documents_plan;
DROP TABLE IF EXISTS pieces_plan;
DROP TABLE IF EXISTS plans_villa;
DROP TABLE IF EXISTS formules;
DROP TABLE IF EXISTS visites;
DROP TABLE IF EXISTS devis;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS administrateurs;

-- =========================================================
-- 1. COMPTES
-- =========================================================

CREATE TABLE administrateurs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifiant     VARCHAR(50)  NOT NULL UNIQUE,
    mot_de_passe    VARCHAR(255) NOT NULL,        -- hash bcrypt (password_hash / password_verify)
    date_creation   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE clients (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom             VARCHAR(150) NOT NULL,
    identifiant     VARCHAR(100) NOT NULL UNIQUE, -- email ou nom d'utilisateur
    mot_de_passe    VARCHAR(255) NOT NULL,        -- hash bcrypt
    telephone       VARCHAR(30)  NULL,
    cree_par        INT UNSIGNED NULL,            -- admin qui a créé le compte (NULL si auto-inscription via devis)
    date_creation   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_client_admin FOREIGN KEY (cree_par) REFERENCES administrateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Devis : un numéro doit exister ici pour qu'un client puisse s'auto-inscrire.
-- L'admin crée le devis (numéro + nom du prospect) ; le client saisit ensuite
-- son identifiant/mot de passe + ce numéro pour activer son compte.
CREATE TABLE devis (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero_devis    VARCHAR(50)  NOT NULL UNIQUE,
    nom_prospect    VARCHAR(150) NOT NULL,
    email_prospect  VARCHAR(150) NULL,
    statut          ENUM('disponible','utilise') NOT NULL DEFAULT 'disponible',
    client_id       INT UNSIGNED NULL,             -- rempli au moment de l'activation du compte
    cree_par        INT UNSIGNED NULL,             -- admin qui a émis le devis
    date_creation   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_utilisation TIMESTAMP   NULL,
    CONSTRAINT fk_devis_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_devis_admin  FOREIGN KEY (cree_par)  REFERENCES administrateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Historique des connexions client, pour le dashboard admin
CREATE TABLE visites (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    date_heure      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    adresse_ip      VARCHAR(45)  NULL,
    CONSTRAINT fk_visite_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_visites_client (client_id, date_heure)
) ENGINE=InnoDB;

-- =========================================================
-- 2. PLANS DE VILLA  (choisis en premier par le client)
-- =========================================================

CREATE TABLE plans_villa (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom             VARCHAR(150) NOT NULL,
    image           VARCHAR(255) NULL,
    description     TEXT NULL,
    dimensions      VARCHAR(100) NULL,             -- ex : "12m x 15m"
    surface_m2      DECIMAL(8,2) NULL,
    nombre_chambres TINYINT UNSIGNED NULL,
    actif           TINYINT(1)   NOT NULL DEFAULT 1,
    date_creation   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Les pièces réelles d'un plan (Chambre 1, Cuisine, Salon...), définies par l'admin.
-- Chaque plan a aussi une pièce spéciale "globale" pour les catégories qui
-- concernent toute la villa (fondations, porte d'entrée, escalier...) plutôt
-- qu'une pièce précise — ça évite de gérer des cas NULL dans les choix du client.
CREATE TABLE pieces_plan (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id         INT UNSIGNED NOT NULL,
    nom             VARCHAR(100) NOT NULL,          -- ex : "Chambre 1", "Cuisine", "Toute la villa"
    type_piece      ENUM('chambre','cuisine','salon','salle_de_bain','globale','autre') NOT NULL DEFAULT 'autre',
    etage           VARCHAR(80)  NULL,              -- ex : "Rez-de-chaussée", "Étage 1" (regroupement dans l'accordéon client)
    description     VARCHAR(255) NULL,              -- ex : "La chambre près du salon" — pour situer précisément la pièce
    dimensions      VARCHAR(150) NULL,              -- ex : "4m x 3.5m"
    ordre_affichage INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_piece_plan FOREIGN KEY (plan_id) REFERENCES plans_villa(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Caractéristiques libres d'une pièce
CREATE TABLE piece_caracteristiques (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    piece_id    INT UNSIGNED NOT NULL,
    nom         VARCHAR(150) NOT NULL,
    valeur      VARCHAR(255) NULL,
    CONSTRAINT fk_pccar_piece FOREIGN KEY (piece_id) REFERENCES pieces_plan(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Documents techniques du plan (plan électrique, plan de plomberie, et tout futur
-- type de plan). Rattachés au plan de villa, visibles par le client une fois son
-- plan choisi. type_document = 'autre' + nom libre pour les cas non prévus.
CREATE TABLE documents_plan (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id         INT UNSIGNED NOT NULL,
    type_document   ENUM('plan_electrique','plan_plomberie','autre') NOT NULL DEFAULT 'autre',
    nom             VARCHAR(150) NOT NULL,
    fichier         VARCHAR(255) NOT NULL,          -- chemin du fichier (image ou PDF)
    description     TEXT NULL,
    date_creation   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_doc_plan FOREIGN KEY (plan_id) REFERENCES plans_villa(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================================================
-- 3. FORMULES  (sérénité / élégance / signature)
-- =========================================================

CREATE TABLE formules (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom             VARCHAR(100) NOT NULL,
    description     TEXT NULL,
    niveau          TINYINT UNSIGNED NOT NULL UNIQUE, -- 1=base, 2=milieu, 3=haut de gamme (sert à l'upgrade)
    date_creation   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Un plan est proposé dans une ou plusieurs formules, avec un prix de base propre à chaque combinaison
CREATE TABLE plan_formule (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id         INT UNSIGNED NOT NULL,
    formule_id      INT UNSIGNED NOT NULL,
    prix_base       DECIMAL(12,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_pf_plan    FOREIGN KEY (plan_id) REFERENCES plans_villa(id) ON DELETE CASCADE,
    CONSTRAINT fk_pf_formule FOREIGN KEY (formule_id) REFERENCES formules(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_plan_formule (plan_id, formule_id)
) ENGINE=InnoDB;

-- =========================================================
-- 4. GRANDES CATÉGORIES DE CONSTRUCTION (gros œuvres / œuvres secondaires)
-- =========================================================

CREATE TABLE grandes_categories (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom             VARCHAR(150) NOT NULL,
    description     TEXT NULL,
    ordre_affichage INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- Checklist admin : à quelles formules cette grande catégorie s'applique
CREATE TABLE grande_categorie_formule (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    grande_categorie_id INT UNSIGNED NOT NULL,
    formule_id          INT UNSIGNED NOT NULL,
    CONSTRAINT fk_gcf_gc      FOREIGN KEY (grande_categorie_id) REFERENCES grandes_categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_gcf_formule FOREIGN KEY (formule_id) REFERENCES formules(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_gc_formule (grande_categorie_id, formule_id)
) ENGINE=InnoDB;

-- =========================================================
-- 5. CATÉGORIES PRODUITS (BRIQUES FONDATIONS, CARRELAGE, etc.)
-- =========================================================

CREATE TABLE categories_produits (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom                 VARCHAR(150) NOT NULL,
    grande_categorie_id INT UNSIGNED NOT NULL,
    par_piece           TINYINT(1) NOT NULL DEFAULT 0, -- 1 = le client choisit un produit par pièce (ex: carrelage) ; 0 = un seul choix pour toute la villa
    ordre_affichage     INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_cp_gc FOREIGN KEY (grande_categorie_id) REFERENCES grandes_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================================================
-- 6. PRODUITS (Briques pleines, Béton blanc, etc.)
-- =========================================================

CREATE TABLE produits (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero              INT UNSIGNED NOT NULL UNIQUE, -- numéro de position, distinct de la référence ; assigné par l'appli (MAX(numero)+1)
    categorie_produit_id INT UNSIGNED NOT NULL,
    formule_id          INT UNSIGNED NOT NULL,      -- niveau explicite du produit (confirmé)
    nom                 VARCHAR(150) NOT NULL,
    description         TEXT NULL,
    prix                DECIMAL(12,2) NOT NULL DEFAULT 0,
    couleurs            VARCHAR(255) NULL,           -- coloris disponibles : liste séparée par virgules (jetons de palette)
    reference           VARCHAR(100) NOT NULL UNIQUE, -- code produit (SKU), différent du numéro
    fournisseur         VARCHAR(150) NULL,
    dimensions          TEXT NULL,                    -- dimensions disponibles : une par ligne
    disponible          TINYINT(1) NOT NULL DEFAULT 1,
    image               VARCHAR(255) NULL,
    date_creation       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_produit_cp      FOREIGN KEY (categorie_produit_id) REFERENCES categories_produits(id) ON DELETE CASCADE,
    CONSTRAINT fk_produit_formule FOREIGN KEY (formule_id) REFERENCES formules(id) ON DELETE RESTRICT,
    INDEX idx_produits_cp_formule (categorie_produit_id, formule_id)
) ENGINE=InnoDB;

-- Caractéristiques libres d'un produit (ex : Matériaux = Marbre)
CREATE TABLE produit_caracteristiques (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    produit_id  INT UNSIGNED NOT NULL,
    nom         VARCHAR(150) NOT NULL,
    valeur      VARCHAR(255) NULL,
    CONSTRAINT fk_pcar_produit FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================================================
-- 7. CHAMPS DYNAMIQUES
-- Permet à l'admin d'ajouter un nouveau champ à un type de fiche
-- (formule, grande catégorie, catégorie produit, produit ou plan)
-- sans jamais modifier la structure des tables.
-- =========================================================

CREATE TABLE definitions_champs_personnalises (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_entite     ENUM('formule','grande_categorie','categorie_produit','produit','plan_villa') NOT NULL,
    nom_champ       VARCHAR(100) NOT NULL,
    type_valeur     ENUM('texte','nombre','booleen','liste_deroulante') NOT NULL DEFAULT 'texte',
    options_liste   TEXT NULL,          -- valeurs séparées par virgules, si type_valeur = liste_deroulante
    ordre_affichage INT NOT NULL DEFAULT 0,
    date_creation   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_champ_par_type (type_entite, nom_champ)
) ENGINE=InnoDB;

-- Valeur du champ dynamique pour une fiche précise (ex: le produit n°12)
CREATE TABLE valeurs_champs_personnalises (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    definition_id   INT UNSIGNED NOT NULL,
    entite_id       INT UNSIGNED NOT NULL,   -- id de la formule / produit / etc. concernée
    valeur          TEXT NULL,
    CONSTRAINT fk_vcp_definition FOREIGN KEY (definition_id) REFERENCES definitions_champs_personnalises(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_valeur_par_fiche (definition_id, entite_id)
) ENGINE=InnoDB;

-- =========================================================
-- 8. CONFIGURATIONS CLIENT (la villa que le client construit)
-- =========================================================

CREATE TABLE configurations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    plan_id         INT UNSIGNED NOT NULL,
    formule_id      INT UNSIGNED NOT NULL,          -- formule de base choisie pour ce plan
    statut          ENUM('en_cours','en_attente','validee','annule_client') NOT NULL DEFAULT 'en_cours',
    -- en_cours      : le client configure encore sa villa
    -- en_attente    : le client a validé ses choix, en attente de validation admin
    -- validee       : l'admin a validé la commande -> les plans (électrique/plomberie) sont envoyés au client
    -- annule_client : le client a annulé ; invisible pour lui, visible par l'admin ;
    --                 supprimée automatiquement 15 jours après annule_le si l'admin ne l'a pas fait avant
    prix_total      DECIMAL(14,2) NOT NULL DEFAULT 0,
    date_creation   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_soumission TIMESTAMP NULL,                 -- passage en_cours -> en_attente (validation client)
    date_validation TIMESTAMP NULL,                 -- passage en_attente -> validee (validation admin)
    valide_par      INT UNSIGNED NULL,               -- admin qui a validé la commande
    plans_envoyes_le TIMESTAMP NULL,                 -- date d'envoi des plans électrique/plomberie par mail
    annule_le        TIMESTAMP NULL,                 -- date d'annulation par le client (purge auto à +15 jours)
    CONSTRAINT fk_config_client  FOREIGN KEY (client_id)  REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_config_plan    FOREIGN KEY (plan_id)    REFERENCES plans_villa(id) ON DELETE RESTRICT,
    CONSTRAINT fk_config_formule FOREIGN KEY (formule_id) REFERENCES formules(id) ON DELETE RESTRICT,
    CONSTRAINT fk_config_admin   FOREIGN KEY (valide_par) REFERENCES administrateurs(id) ON DELETE SET NULL,
    INDEX idx_config_client (client_id),
    INDEX idx_config_statut (statut)
) ENGINE=InnoDB;

-- Chaque option choisie par le client dans sa configuration, pour une pièce donnée
-- (produit_id peut être un produit d'une formule supérieure = upgrade).
-- piece_id pointe soit sur une vraie pièce (Chambre 1, Cuisine...) pour les
-- catégories "par_piece", soit sur la pièce "globale" du plan sinon.
CREATE TABLE configuration_produits (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    configuration_id      INT UNSIGNED NOT NULL,
    categorie_produit_id  INT UNSIGNED NOT NULL,
    piece_id              INT UNSIGNED NOT NULL,
    produit_id            INT UNSIGNED NOT NULL,
    couleur               VARCHAR(60)  NULL,          -- coloris retenu par le client
    dimension             VARCHAR(150) NULL,          -- dimension retenue par le client
    prix_applique         DECIMAL(12,2) NOT NULL,     -- prix du produit choisi
    supplement            DECIMAL(12,2) NOT NULL DEFAULT 0,  -- écart vs le produit de la formule de base
    date_ajout            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cp_config   FOREIGN KEY (configuration_id) REFERENCES configurations(id) ON DELETE CASCADE,
    CONSTRAINT fk_cp_catprod  FOREIGN KEY (categorie_produit_id) REFERENCES categories_produits(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cp_piece    FOREIGN KEY (piece_id) REFERENCES pieces_plan(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cp_produit  FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE RESTRICT,
    UNIQUE KEY uniq_config_cat_piece (configuration_id, categorie_produit_id, piece_id) -- un seul produit par catégorie ET par pièce
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- DONNÉES INITIALES
-- =========================================================

-- Compte admin : identifiant "vadmin" / mot de passe "myadmin" (hash bcrypt ci-dessous)
INSERT INTO administrateurs (identifiant, mot_de_passe) VALUES
('vadmin', '$2b$12$k.0OLz/EODRgyxBimVEZ3uH8M3xonzL6EjRJym0gmjyqb26fzhTz2');

-- Les 3 formules
INSERT INTO formules (id, nom, description, niveau) VALUES
(1, 'Sérénité',  'La formule de base : l\'essentiel bien construit, sans superflu.', 1),
(2, 'Élégance',  'Un équilibre entre caractère et raison, matériaux et finitions soignés.', 2),
(3, 'Signature', 'Le haut de gamme du cabinet, sur-mesure du gros œuvre aux finitions.', 3);

-- Les 2 grandes catégories, appliquées aux 3 formules par défaut
INSERT INTO grandes_categories (id, nom, ordre_affichage) VALUES
(1, 'Gros œuvres', 1),
(2, 'Œuvres secondaires', 2);

INSERT INTO grande_categorie_formule (grande_categorie_id, formule_id) VALUES
(1,1),(1,2),(1,3),
(2,1),(2,2),(2,3);

-- Catégories produits — Gros œuvres (toutes "globales", pas de choix par pièce)
INSERT INTO categories_produits (id, nom, grande_categorie_id, par_piece, ordre_affichage) VALUES
(1, 'Briques fondations',  1, 0, 1),
(2, 'Briques élévations',  1, 0, 2),
(3, 'Hourdis',             1, 0, 3),
(4, 'Fer',                 1, 0, 4),
(5, 'Béton',               1, 0, 5);

-- Catégories produits — Œuvres secondaires
-- par_piece = 1 : le client choisit un produit pour CHAQUE pièce (ex: le carrelage de la cuisine, puis celui de chaque chambre)
INSERT INTO categories_produits (id, nom, grande_categorie_id, par_piece, ordre_affichage) VALUES
(6,  'Climatisation',        2, 1, 1),
(7,  'Carrelage',            2, 1, 2),
(8,  'Escalier',             2, 0, 3),
(9,  'Salle de bain',        2, 1, 4),
(10, 'Portes intérieures',   2, 1, 5),
(11, 'Porte d\'entrée',      2, 0, 6),
(12, 'Garde-corps',          2, 0, 7),
(13, 'Électricité',          2, 0, 8);

-- Produits de départ (formule Sérénité = niveau de base)
INSERT INTO produits (numero, categorie_produit_id, formule_id, nom, description, prix, reference, disponible) VALUES
(1, 1, 1, 'Briques pleines',   'Brique pleine standard pour fondations.', 0, 'BRQ-FOND-001', 1),
(2, 2, 1, 'Briques artisanales','Brique artisanale pour élévations.',      0, 'BRQ-ELEV-001', 1),
(3, 3, 1, 'Hourdis artisanal', 'Hourdis fabriqué de manière artisanale.',  0, 'HOU-STD-001',  1),
(4, 4, 1, 'Fer local',         'Fer d\'armature de production locale.',    0, 'FER-LOC-001',  1),
(5, 5, 1, 'Béton blanc',       'Béton teinté blanc pour éléments visibles.', 0, 'BET-BLC-001', 1),
(6, 5, 1, 'Bétonnière',        'Prestation de malaxage sur site.',         0, 'BET-MIX-001',  1);

-- NOTE : les prix ci-dessus sont à 0 par défaut — à compléter depuis le dashboard admin.
-- Les catégories "Œuvres secondaires" n'ont volontairement aucun produit pré-rempli :
-- elles seront alimentées par l'admin (climatisation, carrelage, etc. avec leurs 3 niveaux).

-- Devis de démonstration : permet de tester l'auto-inscription client
-- (identifiant libre + mot de passe libre + numéro de devis "DEV-2026-0001")
INSERT INTO devis (numero_devis, nom_prospect, statut) VALUES
('DEV-2026-0001', 'Client de démonstration', 'disponible');

-- Plan de démonstration + ses pièces (dont la pièce "globale" obligatoire)
INSERT INTO plans_villa (id, nom, description, dimensions, surface_m2, nombre_chambres) VALUES
(1, 'Villa Baobab', 'Plan de villa à démonstration, 3 chambres.', '15m x 12m', 180.00, 3);

INSERT INTO plan_formule (plan_id, formule_id, prix_base) VALUES
(1, 1, 0), (1, 2, 0), (1, 3, 0);

INSERT INTO pieces_plan (plan_id, nom, type_piece, etage, description, ordre_affichage) VALUES
(1, 'Toute la villa', 'globale',       NULL,               'Choix appliqué à l''ensemble de la villa.',        0),
(1, 'Salon',          'salon',         'Rez-de-chaussée',  'Le grand salon à l''entrée de la villa.',          1),
(1, 'Cuisine',        'cuisine',       'Rez-de-chaussée',  'La cuisine ouverte, attenante au salon.',          2),
(1, 'Chambre 1',      'chambre',       'Rez-de-chaussée',  'La chambre du rez-de-chaussée, près du salon.',    3),
(1, 'Salle de bain',  'salle_de_bain', 'Rez-de-chaussée',  'La salle de bain du rez-de-chaussée.',             4),
(1, 'Chambre 2',      'chambre',       'Étage 1',          'La chambre à l''étage, côté jardin.',              5),
(1, 'Chambre 3',      'chambre',       'Étage 1',          'La chambre à l''étage, côté rue.',                 6);

INSERT INTO documents_plan (plan_id, type_document, nom, fichier) VALUES
(1, 'plan_electrique', 'Plan électrique — Villa Baobab', 'documents/villa-baobab-electrique.pdf'),
(1, 'plan_plomberie',  'Plan de plomberie — Villa Baobab', 'documents/villa-baobab-plomberie.pdf');
