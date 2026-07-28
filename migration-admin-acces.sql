-- Migration : niveaux d'accès administrateurs.
-- À exécuter une fois sur une base existante (missa2796059).
--   mysql -u root missa2796059 < migration-admin-acces.sql

ALTER TABLE administrateurs
    ADD COLUMN est_webmaster TINYINT(1) NOT NULL DEFAULT 0 AFTER mot_de_passe;

CREATE TABLE IF NOT EXISTS admin_acces (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT UNSIGNED NOT NULL,
    menu_cle    VARCHAR(40)  NOT NULL,
    UNIQUE KEY uq_admin_menu (admin_id, menu_cle),
    CONSTRAINT fk_acces_admin FOREIGN KEY (admin_id) REFERENCES administrateurs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Le compte historique devient webmaster (accès total).
UPDATE administrateurs SET est_webmaster = 1 WHERE identifiant = 'vadmin';
