<?php
require_once __DIR__ . '/../../lib/crud.php';
$admin = admin_connecte();

crud_page([
    'table'     => 'devis',
    'menu'      => 'devis',
    'titre'     => 'Devis',
    'singulier' => 'un devis',
    'liste_sql' => 'SELECT d.id, d.numero_devis, d.nom_prospect, d.email_prospect, d.statut,
                           c.nom AS client
                    FROM devis d LEFT JOIN clients c ON c.id = d.client_id
                    ORDER BY d.date_creation DESC',
    'colonnes'  => [
        'numero_devis'   => 'Numéro',
        'nom_prospect'   => 'Prospect',
        'email_prospect' => 'Email',
        'statut'         => 'Statut',
        'client'         => 'Client rattaché',
    ],
    'champs' => [
        ['nom' => 'numero_devis', 'label' => 'Numéro de devis', 'type' => 'text', 'requis' => true, 'aide' => 'Ex : DEV-2026-0001'],
        ['nom' => 'nom_prospect', 'label' => 'Nom du prospect', 'type' => 'text', 'requis' => true],
        ['nom' => 'email_prospect', 'label' => 'Email du prospect', 'type' => 'text'],
        ['nom' => 'statut', 'label' => 'Statut', 'type' => 'select',
         'options' => ['disponible' => 'Disponible', 'utilise' => 'Utilisé'], 'defaut' => 'disponible'],
    ],
    'avant_ecrire' => function (array &$valeurs, bool $creation) use ($admin) {
        if ($creation) {
            $valeurs['cree_par'] = $admin['id'];
        }
    },
]);
