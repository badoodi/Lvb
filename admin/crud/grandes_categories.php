<?php
require_once __DIR__ . '/../../lib/crud.php';

/** Cases à cocher des formules concernées par la grande catégorie. */
function grande_cat_checklist(?array $ligne): string
{
    $formules = db()->query('SELECT id, nom, niveau FROM formules ORDER BY niveau')->fetchAll();
    $coches = [];
    if ($ligne) {
        $stmt = db()->prepare('SELECT formule_id FROM grande_categorie_formule WHERE grande_categorie_id = ?');
        $stmt->execute([(int) $ligne['id']]);
        $coches = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $html = '<div class="champs-perso"><p class="champs-perso-titre">Collections concernées</p>';
    foreach ($formules as $f) {
        $c = in_array((int) $f['id'], $coches, true) ? ' checked' : '';
        $html .= '<label class="check-inline"><input type="checkbox" name="formules[]" value="' . (int) $f['id'] . '"' . $c . '> ' . h($f['nom']) . '</label>';
    }
    $html .= '</div>';
    return $html;
}

function grande_cat_enregistrer_checklist(int $id): void
{
    db()->prepare('DELETE FROM grande_categorie_formule WHERE grande_categorie_id = ?')->execute([$id]);
    $formules = $_POST['formules'] ?? [];
    $ins = db()->prepare('INSERT INTO grande_categorie_formule (grande_categorie_id, formule_id) VALUES (?, ?)');
    foreach ($formules as $fid) {
        $ins->execute([$id, (int) $fid]);
    }
}

crud_page([
    'table'         => 'grandes_categories',
    'menu'          => 'grandes_categories',
    'titre'         => 'Grandes catégories',
    'singulier'     => 'une grande catégorie',
    'entite_champs' => 'grande_categorie',
    'bouton_champ'  => true,
    'tri'           => 'ordre_affichage, id',
    'colonnes'      => ['ordre_affichage' => 'Ordre', 'nom' => 'Nom', 'description' => 'Description'],
    'champs'        => [
        ['nom' => 'nom', 'label' => 'Nom', 'type' => 'text', 'requis' => true],
        ['nom' => 'description', 'label' => 'Description', 'type' => 'textarea'],
        ['nom' => 'ordre_affichage', 'label' => 'Ordre d\'affichage', 'type' => 'number', 'step' => '1', 'defaut' => 0],
    ],
    'extra_form'   => 'grande_cat_checklist',
    'apres_ecrire' => fn(int $id, bool $c) => grande_cat_enregistrer_checklist($id),
]);
