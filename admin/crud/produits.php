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

/** Bloc formulaire : coloris (cercles), dimensions multiples, caractéristiques. */
function produit_extra_form(?array $ligne): string
{
    $id = (int) ($ligne['id'] ?? 0);
    $couleurs = array_filter(array_map('trim', explode(',', (string) ($ligne['couleurs'] ?? ''))));
    $dimensions = array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($ligne['dimensions'] ?? ''))));
    $caracs = $id ? produit_caracteristiques($id) : [];

    ob_start(); ?>
    <div class="champs-perso">
        <p class="champs-perso-titre">Coloris disponibles</p>
        <div class="palette-choix">
            <?php foreach (palette_couleurs() as $jeton => $hex):
                $coche = in_array($jeton, $couleurs, true); ?>
                <label class="pastille-couleur" title="<?= h(ucfirst($jeton)) ?>">
                    <input type="checkbox" name="couleurs[]" value="<?= h($jeton) ?>" <?= $coche ? 'checked' : '' ?>>
                    <span class="pastille" style="background:<?= h($hex) ?>"></span>
                    <span class="pastille-nom"><?= h($jeton) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="champs-perso">
        <p class="champs-perso-titre">Dimensions disponibles</p>
        <div id="dim-liste">
            <?php $dims = $dimensions ?: ['']; foreach ($dims as $d): ?>
                <div class="repeat-row"><input type="text" name="dim[]" value="<?= h($d) ?>" placeholder="ex : 60 x 60 cm"></div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn-line" onclick="ajouterLigne('dim-liste','dim','ex : 60 x 60 cm')">+ Ajouter une dimension</button>
    </div>

    <div class="champs-perso">
        <p class="champs-perso-titre">Caractéristiques</p>
        <div id="carac-liste">
            <?php $cs = $caracs ?: [['nom' => '', 'valeur' => '']]; foreach ($cs as $c): ?>
                <div class="repeat-row repeat-duo">
                    <input type="text" name="carac_nom[]" value="<?= h($c['nom']) ?>" placeholder="Caractéristique (ex : Matériaux)">
                    <input type="text" name="carac_val[]" value="<?= h($c['valeur']) ?>" placeholder="Valeur (ex : Marbre)">
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn-line" onclick="ajouterCarac()">+ Ajouter une caractéristique</button>
    </div>

    <script>
    function ajouterLigne(zoneId, name, ph) {
        var d = document.createElement('div'); d.className = 'repeat-row';
        d.innerHTML = '<input type="text" name="' + name + '[]" placeholder="' + ph + '">';
        document.getElementById(zoneId).appendChild(d);
    }
    function ajouterCarac() {
        var d = document.createElement('div'); d.className = 'repeat-row repeat-duo';
        d.innerHTML = '<input type="text" name="carac_nom[]" placeholder="Caractéristique (ex : Matériaux)">'
                    + '<input type="text" name="carac_val[]" placeholder="Valeur (ex : Marbre)">';
        document.getElementById('carac-liste').appendChild(d);
    }
    </script>
    <?php
    return ob_get_clean();
}

/** Enregistre coloris / dimensions / caractéristiques après le produit. */
function produit_apres_ecrire(int $id, bool $creation): void
{
    $couleurs = array_filter(array_map('trim', (array) ($_POST['couleurs'] ?? [])));
    $dims = array_filter(array_map('trim', (array) ($_POST['dim'] ?? [])), fn($v) => $v !== '');

    $maj = db()->prepare('UPDATE produits SET couleurs = ?, dimensions = ? WHERE id = ?');
    $maj->execute([implode(',', $couleurs) ?: null, implode("\n", $dims) ?: null, $id]);

    db()->prepare('DELETE FROM produit_caracteristiques WHERE produit_id = ?')->execute([$id]);
    $noms = (array) ($_POST['carac_nom'] ?? []);
    $vals = (array) ($_POST['carac_val'] ?? []);
    $ins = db()->prepare('INSERT INTO produit_caracteristiques (produit_id, nom, valeur) VALUES (?, ?, ?)');
    foreach ($noms as $i => $nom) {
        $nom = trim((string) $nom);
        if ($nom === '') {
            continue;
        }
        $ins->execute([$id, $nom, trim((string) ($vals[$i] ?? ''))]);
    }
}

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
        'formule'   => 'Collection',
        'reference' => 'Référence',
        'prix'      => 'Prix (€)',
        'dispo'     => 'Dispo.',
    ],
    'champs' => [
        ['nom' => 'categorie_produit_id', 'label' => 'Catégorie', 'type' => 'select', 'options' => $optionsCategories, 'requis' => true],
        ['nom' => 'formule_id', 'label' => 'Collection (niveau du produit)', 'type' => 'select', 'options' => $optionsFormules, 'requis' => true],
        ['nom' => 'nom', 'label' => 'Nom', 'type' => 'text', 'requis' => true],
        ['nom' => 'description', 'label' => 'Description', 'type' => 'textarea'],
        ['nom' => 'prix', 'label' => 'Prix (€)', 'type' => 'number', 'defaut' => 0],
        ['nom' => 'reference', 'label' => 'Référence (SKU)', 'type' => 'text', 'requis' => true, 'aide' => 'Code produit unique, distinct du numéro de position.'],
        ['nom' => 'fournisseur', 'label' => 'Fournisseur', 'type' => 'text'],
        ['nom' => 'disponible', 'label' => 'Disponible', 'type' => 'bool', 'defaut' => 1],
        ['nom' => 'image', 'label' => 'Image du produit', 'type' => 'image',
         'aide' => 'Téléversez une image depuis votre ordinateur (jpg, png, webp…). Le fichier est renommé avec le nom de la collection.',
         'prefixe' => function (array $post) {
             $fid = (int) ($post['formule_id'] ?? 0);
             if ($fid) {
                 $st = db()->prepare('SELECT nom FROM formules WHERE id = ?');
                 $st->execute([$fid]);
                 $nom = $st->fetchColumn();
                 if ($nom) {
                     return 'collection-' . $nom;
                 }
             }
             return 'produit';
         }],
    ],
    'avant_ecrire' => function (array &$valeurs, bool $creation) {
        if ($creation) {
            $max = (int) db()->query('SELECT COALESCE(MAX(numero), 0) FROM produits')->fetchColumn();
            $valeurs['numero'] = $max + 1;
        }
    },
    'extra_form'   => 'produit_extra_form',
    'apres_ecrire' => 'produit_apres_ecrire',
]);
