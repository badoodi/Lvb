-- =========================================================
-- MIGRATION — annulation de configuration par le client
-- À exécuter sur une base EXISTANTE sans perte de données.
-- Base : missa2796059
-- =========================================================

USE missa2796059;

-- 1) Ajouter le statut « annule_client » à l'énumération.
ALTER TABLE configurations
    MODIFY COLUMN statut ENUM('en_cours','en_attente','validee','annule_client')
    NOT NULL DEFAULT 'en_cours';

-- 2) Ajouter la date d'annulation (pour la purge automatique à +15 jours).
ALTER TABLE configurations
    ADD COLUMN annule_le TIMESTAMP NULL AFTER plans_envoyes_le;
