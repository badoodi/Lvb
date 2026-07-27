-- =========================================================
-- MIGRATION — enrichissement produits & pièces
-- Couleurs / dimensions multiples / caractéristiques / fournisseur
-- + couleur & dimension choisies par le client.
-- Base : missa2796059  (sans perte de données)
-- =========================================================

USE missa2796059;

-- --- Produits ---
ALTER TABLE produits
    ADD COLUMN fournisseur VARCHAR(150) NULL AFTER reference;

-- dimensions : passe en TEXT pour stocker plusieurs valeurs (une par ligne)
ALTER TABLE produits
    MODIFY COLUMN dimensions TEXT NULL;

-- Caractéristiques libres d'un produit (ex : Matériaux = Marbre)
CREATE TABLE IF NOT EXISTS produit_caracteristiques (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    produit_id  INT UNSIGNED NOT NULL,
    nom         VARCHAR(150) NOT NULL,
    valeur      VARCHAR(255) NULL,
    CONSTRAINT fk_pcar_produit FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --- Pièces ---
ALTER TABLE pieces_plan
    ADD COLUMN dimensions VARCHAR(150) NULL AFTER description;

CREATE TABLE IF NOT EXISTS piece_caracteristiques (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    piece_id    INT UNSIGNED NOT NULL,
    nom         VARCHAR(150) NOT NULL,
    valeur      VARCHAR(255) NULL,
    CONSTRAINT fk_pccar_piece FOREIGN KEY (piece_id) REFERENCES pieces_plan(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --- Choix du client : couleur / dimension retenues ---
ALTER TABLE configuration_produits
    ADD COLUMN couleur   VARCHAR(60)  NULL AFTER produit_id,
    ADD COLUMN dimension VARCHAR(150) NULL AFTER couleur;
