-- =========================================================
-- MIGRATION — ajoute étage + description aux pièces
-- À exécuter sur une base EXISTANTE sans perdre les données
-- (contrairement à schema.sql qui vide tout).
-- Base : missa2796059
-- =========================================================

USE missa2796059;

ALTER TABLE pieces_plan
    ADD COLUMN etage       VARCHAR(80)  NULL AFTER type_piece,
    ADD COLUMN description VARCHAR(255) NULL AFTER etage;

-- (Optionnel) Renseigner l'étage + une description sur les pièces du plan de démo.
-- Adaptez les noms/étages à vos vrais plans.
UPDATE pieces_plan SET etage = 'Rez-de-chaussée', description = 'Le grand salon à l''entrée de la villa.'       WHERE plan_id = 1 AND nom = 'Salon';
UPDATE pieces_plan SET etage = 'Rez-de-chaussée', description = 'La cuisine ouverte, attenante au salon.'         WHERE plan_id = 1 AND nom = 'Cuisine';
UPDATE pieces_plan SET etage = 'Rez-de-chaussée', description = 'La chambre du rez-de-chaussée, près du salon.'    WHERE plan_id = 1 AND nom = 'Chambre 1';
UPDATE pieces_plan SET etage = 'Rez-de-chaussée', description = 'La salle de bain du rez-de-chaussée.'             WHERE plan_id = 1 AND nom = 'Salle de bain';
UPDATE pieces_plan SET etage = 'Étage 1',         description = 'La chambre à l''étage, côté jardin.'             WHERE plan_id = 1 AND nom = 'Chambre 2';
UPDATE pieces_plan SET etage = 'Étage 1',         description = 'La chambre à l''étage, côté rue.'                WHERE plan_id = 1 AND nom = 'Chambre 3';
