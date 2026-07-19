<?php
require_once __DIR__ . '/../../lib/crud.php';

$optionsGrandesCats = function () {
    $out = [];
    foreach (db()->query('SELECT id, nom FROM grandes_categories ORDER BY ordre_affichage, id') as $r) {
        $out[$r['id']] = $r['nom'];
    }
    return $out;
};

crud_page([
    'table'         => 'categories_produits',
    'menu'          => 'categories',
    'titre'         => 'Catégories produits',
    'singulier'     => 'une catégorie',
    'entite_champs' => 'categorie_produit',
    'bouton_champ'  => true,
    'liste_sql'     => 'SELECT cp.id, cp.nom, gc.nom AS grande_categorie,
                               CASE WHEN cp.par_piece = 1 THEN \'Par pièce\' ELSE \'Toute la villa\' END AS par_piece_lbl,
                               cp.ordre_affichage
                        FROM categories_produits cp
                        JOIN grandes_categories gc ON gc.id = cp.grande_categorie_id
                        ORDER BY gc.ordre_affichage, cp.ordre_affichage',
    'colonnes'      => [
        'nom'              => 'Nom',
        'grande_categorie' => 'Grande catégorie',
        'par_piece_lbl'    => 'Choix',
        'ordre_affichage'  => 'Ordre',
    ],
    'champs' => [
        ['nom' => 'nom', 'label' => 'Nom', 'type' => 'text', 'requis' => true],
        ['nom' => 'grande_categorie_id', 'label' => 'Grande catégorie', 'type' => 'select',
         'options' => $optionsGrandesCats, 'requis' => true],
        ['nom' => 'par_piece', 'label' => 'Choix par pièce', 'type' => 'bool',
         'aide' => 'Coché : le client choisit un produit pour chaque pièce (ex. Carrelage). Décoché : un seul choix pour toute la villa (ex. Fondations).'],
        ['nom' => 'ordre_affichage', 'label' => 'Ordre d\'affichage', 'type' => 'number', 'step' => '1', 'defaut' => 0],
    ],
]);
