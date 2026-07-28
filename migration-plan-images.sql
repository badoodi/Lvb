-- Migration : galerie d'images pour les plans de villa.
-- À exécuter une fois sur une base existante (missa2796059).
--   mysql -u root missa2796059 < migration-plan-images.sql

CREATE TABLE IF NOT EXISTS images_plan (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id         INT UNSIGNED NOT NULL,
    fichier         VARCHAR(255) NOT NULL,
    legende         VARCHAR(150) NULL,
    ordre_affichage INT NOT NULL DEFAULT 0,
    date_creation   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_img_plan FOREIGN KEY (plan_id) REFERENCES plans_villa(id) ON DELETE CASCADE
) ENGINE=InnoDB;
