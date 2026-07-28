<?php
/**
 * Gestion des définitions de champs dynamiques.
 * Un champ ajouté ici apparaît automatiquement sur TOUTES les fiches du type
 * d'entité choisi (formule, produit, plan…). Les valeurs sont stockées dans
 * valeurs_champs_personnalises — jamais de modification de structure de tables.
 */
require_once __DIR__ . '/../../lib/crud.php';

$typesEntite = [
    'formule'           => 'Collection',
    'grande_categorie'  => 'Grande catégorie',
    'categorie_produit' => 'Catégorie produit',
    'produit'           => 'Produit',
    'plan_villa'        => 'Plan de villa',
];
$typesValeur = [
    'texte'            => 'Texte',
    'nombre'           => 'Nombre',
    'booleen'          => 'Booléen (oui/non)',
    'liste_deroulante' => 'Liste déroulante',
];

crud_page([
    'table'     => 'definitions_champs_personnalises',
    'menu'      => 'champs',
    'titre'     => 'Champs dynamiques',
    'singulier' => 'un champ',
    'tri'       => 'type_entite, ordre_affichage, id',
    'colonnes'  => [
        'type_entite'     => 'Type de fiche',
        'nom_champ'       => 'Nom du champ',
        'type_valeur'     => 'Type de valeur',
        'ordre_affichage' => 'Ordre',
    ],
    'champs' => [
        ['nom' => 'type_entite', 'label' => 'Type de fiche concernée', 'type' => 'select',
         'options' => $typesEntite, 'requis' => true,
         'aide' => 'Le champ s\'affichera sur toutes les fiches de ce type.'],
        ['nom' => 'nom_champ', 'label' => 'Nom du champ', 'type' => 'text', 'requis' => true],
        ['nom' => 'type_valeur', 'label' => 'Type de valeur', 'type' => 'select',
         'options' => $typesValeur, 'requis' => true, 'defaut' => 'texte'],
        ['nom' => 'options_liste', 'label' => 'Options (si liste déroulante)', 'type' => 'text',
         'aide' => 'Valeurs séparées par des virgules.'],
        ['nom' => 'ordre_affichage', 'label' => 'Ordre d\'affichage', 'type' => 'number', 'step' => '1', 'defaut' => 0],
    ],
]);
