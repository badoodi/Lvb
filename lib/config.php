<?php
/**
 * Les Villas Blanches — Configuration de l'application.
 *
 * Les identifiants de connexion à la base peuvent être surchargés par des
 * variables d'environnement (pratique en déploiement). À défaut, valeurs de
 * développement local pointant sur la base `missa2796059` (schema.sql).
 */

return [
    'db' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => getenv('DB_PORT') ?: '3306',
        'name'    => getenv('DB_NAME') ?: 'missa2796059',
        'user'    => getenv('DB_USER') ?: 'root',
        'pass'    => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        'charset' => 'utf8mb4',
    ],

    // Nom de la marque affiché dans toute l'interface.
    'marque'      => 'Les Villas Blanches',
    'sous_marque' => 'Vincent Bénard',

    // Adresse expéditrice des emails (envoi des plans techniques à validation).
    'email_expediteur' => getenv('MAIL_FROM') ?: 'atelier@lesvillasblanches.fr',

    // Répertoire des documents techniques (relatif à la racine du site).
    'dossier_documents' => 'documents',
    'dossier_uploads'   => 'uploads',
];
