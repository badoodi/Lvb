<?php
require_once __DIR__ . '/../../lib/crud.php';

crud_page([
    'table'         => 'formules',
    'menu'          => 'formules',
    'titre'         => 'Collections',
    'singulier'     => 'une collection',
    'entite_champs' => 'formule',
    'bouton_champ'  => true,
    'tri'           => 'niveau',
    'colonnes'      => ['niveau' => 'Niveau', 'nom' => 'Nom', 'description' => 'Description'],
    'champs'        => [
        ['nom' => 'nom', 'label' => 'Nom', 'type' => 'text', 'requis' => true],
        ['nom' => 'description', 'label' => 'Description', 'type' => 'textarea'],
        ['nom' => 'niveau', 'label' => 'Niveau', 'type' => 'number', 'step' => '1', 'requis' => true,
         'aide' => '1 = base (Sérénité), 2 = milieu (Élégance), 3 = haut de gamme (Signature). Sert au calcul des upgrades.'],
    ],
]);
