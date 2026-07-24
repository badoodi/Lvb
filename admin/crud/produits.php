<?php
require_once __DIR__ . '/../../lib/crud.php';

$optionsCategories = function () {
    $out = [];
    $sql = 'SELECT cp.id, CONCAT(gc.nom, \' › \', cp.nom) AS lbl
            FROM categories_produits cp JOIN grandes_categories gc ON gc.id = cp.grande_categorie_id
            ORDER BY gc.ordre_affichage, cp.ordre_affichage';
    foreach (db()->query($sql) as $r) {
        $out[$r['id']] = $r['lbl'];
    }
    return $out;
};
$optionsFormules = function () {
    $out = [];
    foreach (db()->query('SELECT id, nom FROM formules ORDER BY niveau') as $r) {
        $out[$r['id']] = $r['nom'];
    }
    return $out;
};

crud_page([
    'table'         => 'produits',
    'menu'          => 'produits',
    'titre'         => 'Produits',
    'singulier'     => 'un produit',
    'entite_champs' => 'produit',
    'bouton_champ'  => true,
    'liste_sql'     => 'SELECT p.id, p.numero, p.nom, p.reference,
                               f.nom AS formule, cp.nom AS categorie,
                               FORMAT(p.prix, 2) AS prix,
                               CASE WHEN p.disponible = 1 THEN \'Oui\' ELSE \'Non\' END AS dispo
                        FROM produits p
                        JOIN formules f ON f.id = p.formule_id
                        JOIN categories_produits cp ON cp.id = p.categorie_produit_id
                        ORDER BY p.numero',
    'colonnes'      => [
        'numero'    => 'N°',
        'nom'       => 'Nom',
        'categorie' => 'Catégorie',
        'formule'   => 'Formule',
        'reference' => 'Référence',
        'prix'      => 'Prix (€)',
        'dispo'     => 'Dispo.',
    ],
    'champs' => [
        ['nom' => 'categorie_produit_id', 'label' => 'Catégorie', 'type' => 'select', 'options' => $optionsCategories, 'requis' => true],
        ['nom' => 'formule_id', 'label' => 'Formule (niveau du produit)', 'type' => 'select', 'options' => $optionsFormules, 'requis' => true],
        ['nom' => 'nom', 'label' => 'Nom', 'type' => 'text', 'requis' => true],
        ['nom' => 'description', 'label' => 'Description', 'type' => 'textarea'],
        ['nom' => 'prix', 'label' => 'Prix (€)', 'type' => 'number', 'defaut' => 0],
        ['nom' => 'couleurs', 'label' => 'Couleurs', 'type' => 'text', 'aide' => 'Liste séparée par des virgules, ex : Blanc,Gris,Sable'],
        ['nom' => 'reference', 'label' => 'Référence (SKU)', 'type' => 'text', 'requis' => true, 'aide' => 'Code produit unique, distinct du numéro de position.'],
        ['nom' => 'dimensions', 'label' => 'Dimensions', 'type' => 'text'],
        ['nom' => 'disponible', 'label' => 'Disponible', 'type' => 'bool', 'defaut' => 1],
        ['nom' => 'image', 'label' => 'Image du produit', 'type' => 'image',
         'aide' => 'Téléversez une image depuis votre ordinateur (jpg, png, webp…). Le fichier est renommé avec le nom de la formule.',
         // Renomme automatiquement le fichier avec « formule-<nom de la formule> ».
         'prefixe' => function (array $post) {
             $fid = (int) ($post['formule_id'] ?? 0);
             if ($fid) {
                 $st = db()->prepare('SELECT nom FROM formules WHERE id = ?');
                 $st->execute([$fid]);
                 $nom = $st->fetchColumn();
                 if ($nom) {
                     return 'formule-' . $nom;
                 }
             }
             return 'produit';
         }],
    ],
    // Numéro de position auto-assigné à la création (MAX+1), distinct de la référence.
    'avant_ecrire' => function (array &$valeurs, bool $creation) {
        if ($creation) {
            $max = (int) db()->query('SELECT COALESCE(MAX(numero), 0) FROM produits')->fetchColumn();
            $valeurs['numero'] = $max + 1;
        }
    },
]);
