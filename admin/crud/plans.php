<?php
require_once __DIR__ . '/../../lib/crud.php';

/** Prix de base par formule (table plan_formule) + liens vers sous-gestion. */
function plan_extra_form(?array $ligne): string
{
    $base = base_url();
    $formules = db()->query('SELECT id, nom, niveau FROM formules ORDER BY niveau')->fetchAll();
    $prix = [];
    if ($ligne) {
        $stmt = db()->prepare('SELECT formule_id, prix_base FROM plan_formule WHERE plan_id = ?');
        $stmt->execute([(int) $ligne['id']]);
        foreach ($stmt as $r) {
            $prix[(int) $r['formule_id']] = $r['prix_base'];
        }
    }
    $html = '<div class="champs-perso"><p class="champs-perso-titre">Prix de base par collection</p>';
    foreach ($formules as $f) {
        $v = $prix[(int) $f['id']] ?? '';
        $html .= '<label class="form-field"><span>' . h($f['nom']) . ' (€)</span>'
              . '<input type="number" step="any" name="prix_formule[' . (int) $f['id'] . ']" value="' . h($v) . '"></label>';
    }
    $html .= '</div>';

    if ($ligne) {
        $id = (int) $ligne['id'];
        $html .= '<div class="champs-perso"><p class="champs-perso-titre">Éléments du plan</p>'
              . '<p><a class="btn-line" href="' . h($base) . '/admin/crud/pieces.php?plan=' . $id . '">Gérer les pièces →</a> '
              . '<a class="btn-line" href="' . h($base) . '/admin/crud/documents.php?plan=' . $id . '">Gérer les documents techniques →</a></p>'
              . '</div>';
    }
    return $html;
}

/** Enregistre les prix par formule et garantit la pièce « globale ». */
function plan_apres_ecrire(int $id, bool $creation): void
{
    $prix = $_POST['prix_formule'] ?? [];
    $upsert = db()->prepare(
        'INSERT INTO plan_formule (plan_id, formule_id, prix_base) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE prix_base = VALUES(prix_base)'
    );
    foreach ($prix as $formuleId => $valeur) {
        if ($valeur === '' || $valeur === null) {
            continue;
        }
        $upsert->execute([$id, (int) $formuleId, (float) $valeur]);
    }

    // Chaque plan doit posséder une pièce « globale » (choix « toute la villa »).
    if ($creation) {
        $chk = db()->prepare("SELECT COUNT(*) FROM pieces_plan WHERE plan_id = ? AND type_piece = 'globale'");
        $chk->execute([$id]);
        if ((int) $chk->fetchColumn() === 0) {
            db()->prepare(
                "INSERT INTO pieces_plan (plan_id, nom, type_piece, ordre_affichage)
                 VALUES (?, 'Toute la villa', 'globale', 0)"
            )->execute([$id]);
        }
    }
}

crud_page([
    'table'         => 'plans_villa',
    'menu'          => 'plans',
    'titre'         => 'Plans de villa',
    'singulier'     => 'un plan',
    'entite_champs' => 'plan_villa',
    'bouton_champ'  => true,
    'liste_sql'     => 'SELECT id, nom, dimensions, surface_m2, nombre_chambres,
                               CASE WHEN actif = 1 THEN \'Oui\' ELSE \'Non\' END AS actif_lbl
                        FROM plans_villa ORDER BY nom',
    'colonnes'      => [
        'nom'             => 'Nom',
        'dimensions'      => 'Dimensions',
        'surface_m2'      => 'Surface (m²)',
        'nombre_chambres' => 'Chambres',
        'actif_lbl'       => 'Actif',
    ],
    'champs' => [
        ['nom' => 'nom', 'label' => 'Nom', 'type' => 'text', 'requis' => true],
        ['nom' => 'description', 'label' => 'Description', 'type' => 'textarea'],
        ['nom' => 'image', 'label' => 'Image du plan', 'type' => 'image', 'aide' => 'Téléversez une image depuis votre ordinateur (jpg, png, webp…).'],
        ['nom' => 'dimensions', 'label' => 'Dimensions', 'type' => 'text', 'aide' => 'Ex : 15m x 12m'],
        ['nom' => 'surface_m2', 'label' => 'Surface (m²)', 'type' => 'number'],
        ['nom' => 'nombre_chambres', 'label' => 'Nombre de chambres', 'type' => 'number', 'step' => '1'],
        ['nom' => 'actif', 'label' => 'Actif (proposé aux clients)', 'type' => 'bool', 'defaut' => 1],
    ],
    'extra_form'   => 'plan_extra_form',
    'apres_ecrire' => 'plan_apres_ecrire',
]);
