<?php
/**
 * Moteur CRUD générique pour le back-office.
 * Gère liste + formulaire (ajout/édition) + suppression + champs dynamiques,
 * avec des points d'extension (hooks) pour les relations particulières.
 *
 * Config attendue :
 *   table         (string)  nom de la table
 *   menu          (string)  clé de menu active (layout)
 *   titre         (string)  titre pluriel affiché
 *   singulier     (string)  libellé singulier
 *   entite_champs (string?) type_entite pour les champs personnalisés
 *   colonnes      (array)   [colonne_sql|clé => libellé] pour la liste
 *   champs        (array)   définition des champs de formulaire :
 *       ['nom'=>, 'label'=>, 'type'=>text|textarea|number|bool|select|password,
 *        'requis'=>bool, 'options'=>array|callable, 'aide'=>string]
 *   tri           (string?) ORDER BY pour la liste (défaut : id DESC)
 *   liste_sql     (string?) requête liste personnalisée (sinon SELECT * )
 *   avant_ecrire  (callable?) fn(array &$valeurs, bool $creation)
 *   apres_ecrire  (callable?) fn(int $id, bool $creation)
 *   avant_supprimer (callable?) fn(int $id) : string|null (message d'erreur bloquant)
 *   extra_form    (callable?) fn(?array $ligne) => string HTML additionnel
 */

require_once __DIR__ . '/layout.php';

function crud_page(array $cfg): void
{
    exiger_admin();
    $base = base_url();
    $pdo = db();
    $table = $cfg['table'];
    $champs = $cfg['champs'];
    $entiteChamps = $cfg['entite_champs'] ?? null;
    $urlSelf = $_SERVER['SCRIPT_NAME'];

    $resoudreOptions = function ($opt) {
        if (is_callable($opt)) {
            return $opt();
        }
        return $opt ?? [];
    };

    /* ---------------- TRAITEMENT POST ---------------- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verifier();
        $action = $_POST['action'] ?? 'save';

        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $err = null;
            if (!empty($cfg['avant_supprimer'])) {
                $err = ($cfg['avant_supprimer'])($id);
            }
            if ($err) {
                flash($err, 'erreur');
            } else {
                try {
                    $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
                    flash('Élément supprimé.');
                } catch (PDOException $e) {
                    flash('Suppression impossible : cet élément est utilisé ailleurs.', 'erreur');
                }
            }
            redirect($urlSelf);
        }

        // Création / mise à jour
        $id = (int) ($_POST['id'] ?? 0);
        $creation = $id === 0;

        $valeurs = [];
        foreach ($champs as $c) {
            $nom = $c['nom'];
            $type = $c['type'] ?? 'text';
            if ($type === 'password') {
                // Mot de passe : ne mettre à jour que si renseigné.
                $brut = (string) ($_POST[$nom] ?? '');
                if ($brut !== '') {
                    $valeurs[$nom] = password_hash($brut, PASSWORD_BCRYPT);
                }
                continue;
            }
            if ($type === 'image') {
                // Chemin actuel conservé par défaut (champ caché), écrasé si un
                // fichier est téléversé depuis l'ordinateur (stocké dans /uploads).
                $courant = trim((string) ($_POST[$nom] ?? ''));
                $fichier = $_FILES[$nom . '_upload'] ?? null;
                $codeErreur = $fichier['error'] ?? UPLOAD_ERR_NO_FILE;
                if ($fichier && $codeErreur === UPLOAD_ERR_OK && is_uploaded_file($fichier['tmp_name'])) {
                    $erreurImg = '';
                    // Préfixe de nommage (ex: nom de la formule) fourni par le champ.
                    $prefixe = '';
                    if (isset($c['prefixe'])) {
                        $prefixe = is_callable($c['prefixe']) ? (string) ($c['prefixe'])($_POST) : (string) $c['prefixe'];
                    }
                    $chemin = crud_televerser_image($fichier, $erreurImg, $prefixe);
                    if ($chemin !== null) {
                        $courant = $chemin;
                    } else {
                        flash($erreurImg, 'erreur');
                    }
                } elseif ($codeErreur === UPLOAD_ERR_INI_SIZE || $codeErreur === UPLOAD_ERR_FORM_SIZE) {
                    flash('Image trop volumineuse pour le serveur (limite upload_max_filesize / post_max_size).', 'erreur');
                } elseif ($codeErreur !== UPLOAD_ERR_NO_FILE) {
                    flash('Le téléversement de l\'image a échoué (code ' . (int) $codeErreur . ').', 'erreur');
                }
                $valeurs[$nom] = ($courant === '') ? null : $courant;
                continue;
            }
            $brut = $_POST[$nom] ?? null;
            if ($type === 'bool') {
                $valeurs[$nom] = $brut ? 1 : 0;
            } elseif ($type === 'number') {
                $valeurs[$nom] = ($brut === '' || $brut === null) ? null : $brut + 0;
            } else {
                $valeurs[$nom] = ($brut === '') ? null : $brut;
            }
        }

        if (!empty($cfg['avant_ecrire'])) {
            ($cfg['avant_ecrire'])($valeurs, $creation);
        }

        if ($valeurs) {
            if ($creation) {
                $cols = array_keys($valeurs);
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $sql = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES ($ph)";
                $pdo->prepare($sql)->execute(array_values($valeurs));
                $id = (int) $pdo->lastInsertId();
            } else {
                $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($valeurs)));
                $sql = "UPDATE $table SET $set WHERE id = ?";
                $pdo->prepare($sql)->execute(array_merge(array_values($valeurs), [$id]));
            }
        } elseif ($creation) {
            // Rien à insérer directement (cas rare) — on insère une ligne vide.
            $pdo->prepare("INSERT INTO $table () VALUES ()")->execute();
            $id = (int) $pdo->lastInsertId();
        }

        if ($entiteChamps) {
            champs_enregistrer($entiteChamps, $id);
        }
        if (!empty($cfg['apres_ecrire'])) {
            ($cfg['apres_ecrire'])($id, $creation);
        }

        flash($creation ? ucfirst($cfg['singulier']) . ' ajouté(e).' : 'Modifications enregistrées.');
        redirect($urlSelf);
    }

    /* ---------------- MODE FORMULAIRE ---------------- */
    $mode = $_GET['form'] ?? null;   // 'new' ou id
    $ligne = null;
    if ($mode !== null && $mode !== 'new') {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([(int) $mode]);
        $ligne = $stmt->fetch() ?: null;
    }

    if ($mode !== null) {
        layout_admin_debut(($ligne ? 'Modifier' : 'Ajouter') . ' — ' . $cfg['singulier'], $cfg['menu']);
        echo '<a class="back-link" href="' . h($urlSelf) . '">← Retour à la liste</a>';
        echo '<div class="admin-panel"><form method="post" class="crud-form" enctype="multipart/form-data">';
        echo csrf_input();
        echo '<input type="hidden" name="id" value="' . (int) ($ligne['id'] ?? 0) . '">';

        foreach ($champs as $c) {
            $nom = $c['nom'];
            $type = $c['type'] ?? 'text';
            $label = $c['label'];
            $val = $ligne[$nom] ?? ($c['defaut'] ?? '');
            $requis = !empty($c['requis']) ? ' required' : '';
            echo '<label class="form-field"><span>' . h($label) . '</span>';
            switch ($type) {
                case 'textarea':
                    echo '<textarea name="' . h($nom) . '" rows="3"' . $requis . '>' . h($val) . '</textarea>';
                    break;
                case 'number':
                    $step = $c['step'] ?? 'any';
                    echo '<input type="number" step="' . h($step) . '" name="' . h($nom) . '" value="' . h($val) . '"' . $requis . '>';
                    break;
                case 'bool':
                    echo '<input type="hidden" name="' . h($nom) . '" value="0">';
                    echo '<input type="checkbox" name="' . h($nom) . '" value="1"' . ($val ? ' checked' : '') . '>';
                    break;
                case 'password':
                    $ph = $ligne ? 'Laisser vide pour ne pas changer' : '';
                    echo '<input type="password" name="' . h($nom) . '" placeholder="' . h($ph) . '"' . ($ligne ? '' : $requis) . '>';
                    break;
                case 'select':
                    $options = $resoudreOptions($c['options'] ?? []);
                    echo '<select name="' . h($nom) . '"' . $requis . '><option value="">—</option>';
                    foreach ($options as $ov => $ol) {
                        $sel = ((string) $ov === (string) $val) ? ' selected' : '';
                        echo '<option value="' . h($ov) . '"' . $sel . '>' . h($ol) . '</option>';
                    }
                    echo '</select>';
                    break;
                case 'image':
                    // Grand bloc « dropzone » : clic ou glisser-déposer, aperçu en fond.
                    $bg = $val ? (base_url() . '/' . ltrim((string) $val, '/')) : '';
                    $cls = 'image-dropzone' . ($bg ? ' has-image' : '');
                    $style = $bg ? ' style="background-image:url(\'' . h($bg) . '\')"' : '';
                    echo '<div class="' . $cls . '"' . $style . '>';
                    echo '<input type="file" class="image-input" name="' . h($nom) . '_upload" accept="image/*">';
                    echo '<div class="image-hint"><span class="image-hint-ic">＋</span>'
                       . 'Cliquez ou glissez une image ici</div>';
                    echo '</div>';
                    // Chemin actuel conservé si aucun nouveau fichier n'est envoyé.
                    echo '<input type="hidden" name="' . h($nom) . '" value="' . h($val) . '">';
                    break;
                default:
                    echo '<input type="text" name="' . h($nom) . '" value="' . h($val) . '"' . $requis . '>';
            }
            if (!empty($c['aide'])) {
                echo '<small class="field-aide">' . h($c['aide']) . '</small>';
            }
            echo '</label>';
        }

        if (!empty($cfg['extra_form'])) {
            echo ($cfg['extra_form'])($ligne);
        }
        if ($entiteChamps) {
            echo champs_formulaire($entiteChamps, (int) ($ligne['id'] ?? 0));
        }

        echo '<div class="crud-form-actions">';
        echo '<button type="submit" class="btn-envoyer">' . ($ligne ? 'Enregistrer' : 'Ajouter') . '</button>';
        echo '</div></form></div>';
        // Aperçu instantané + retour visuel du glisser-déposer sur les dropzones.
        echo <<<'JS'
<script>
document.querySelectorAll('.image-dropzone').forEach(function (zone) {
    var inp = zone.querySelector('.image-input');
    inp.addEventListener('change', function () {
        var f = inp.files && inp.files[0];
        if (!f) { return; }
        var r = new FileReader();
        r.onload = function (e) {
            zone.style.backgroundImage = "url('" + e.target.result + "')";
            zone.classList.add('has-image');
        };
        r.readAsDataURL(f);
    });
    ['dragenter', 'dragover'].forEach(function (ev) {
        zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('drag'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        zone.addEventListener(ev, function () { zone.classList.remove('drag'); });
    });
});
</script>
JS;
        layout_admin_fin();
        return;
    }

    /* ---------------- MODE LISTE ---------------- */
    if (!empty($cfg['liste_sql'])) {
        $lignes = $pdo->query($cfg['liste_sql'])->fetchAll();
    } else {
        $tri = $cfg['tri'] ?? 'id DESC';
        $lignes = $pdo->query("SELECT * FROM $table ORDER BY $tri")->fetchAll();
    }

    layout_admin_debut($cfg['titre'], $cfg['menu']);
    echo '<div class="panel-head crud-head">';
    echo '<div class="crud-actions-top">';
    if (!empty($cfg['bouton_champ']) && $entiteChamps) {
        echo '<a class="btn-line" href="' . h($base) . '/admin/crud/champs.php?type=' . h($entiteChamps) . '">+ Ajouter un champ</a>';
    }
    echo '<a class="btn-envoyer btn-inline" href="' . h($urlSelf) . '?form=new">+ Ajouter ' . h($cfg['singulier']) . '</a>';
    echo '</div></div>';

    echo '<div class="admin-panel">';
    if (!$lignes) {
        echo '<p class="vide">Aucun élément. Cliquez sur « Ajouter ».</p>';
    } else {
        echo '<table class="data-table"><thead><tr>';
        foreach ($cfg['colonnes'] as $lbl) {
            echo '<th>' . h($lbl) . '</th>';
        }
        echo '<th></th></tr></thead><tbody>';
        foreach ($lignes as $l) {
            echo '<tr>';
            foreach ($cfg['colonnes'] as $col => $lbl) {
                $v = $l[$col] ?? '';
                echo '<td>' . h($v) . '</td>';
            }
            echo '<td class="row-actions">';
            if (!empty($cfg['lien_voir'])) {
                echo '<a class="btn-line btn-voir" href="' . h(($cfg['lien_voir'])($l)) . '">Voir</a> ';
            }
            echo '<a class="btn-line" href="' . h($urlSelf) . '?form=' . (int) $l['id'] . '">Modifier</a> ';
            echo '<form method="post" class="inline-form" onsubmit="return confirm(\'Supprimer cet élément ?\');">';
            echo csrf_input();
            echo '<input type="hidden" name="action" value="delete">';
            echo '<input type="hidden" name="id" value="' . (int) $l['id'] . '">';
            echo '<button type="submit" class="btn-line btn-danger">Suppr.</button>';
            echo '</form>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    layout_admin_fin();
}

/**
 * Téléverse une image dans le dossier /uploads et renvoie son chemin relatif
 * (ex : "uploads/1737-plan.jpg"), ou null en cas d'échec ($erreur est alors
 * renseigné avec un message explicite).
 */
function crud_televerser_image(array $fichier, string &$erreur = '', string $prefixe = ''): ?string
{
    $extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
    $ext = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $extensions, true)) {
        $erreur = 'Image ignorée : format non autorisé (jpg, png, webp, gif, svg).';
        return null;
    }
    $dossier = config('dossier_uploads') ?: 'uploads';
    $reel = dirname(__DIR__) . '/' . $dossier;
    if (!is_dir($reel)) {
        @mkdir($reel, 0775, true);
    }
    if (!is_dir($reel) || !is_writable($reel)) {
        $erreur = 'Le dossier « ' . $dossier . ' » est introuvable ou non inscriptible sur le serveur. '
                . 'Créez-le à la racine du site et donnez-lui les droits d\'écriture (chmod 755 ou 775).';
        return null;
    }
    // Nom de fichier : [préfixe (ex: formule-elegance)-]<nom-original>-<horodatage>.<ext>
    $base = slug(pathinfo($fichier['name'], PATHINFO_FILENAME));
    $prefixe = $prefixe !== '' ? slug($prefixe) . '-' : '';
    $nomFichier = $prefixe . substr($base, 0, 50) . '-' . time() . '.' . $ext;
    if (!move_uploaded_file($fichier['tmp_name'], $reel . '/' . $nomFichier)) {
        $erreur = 'Impossible d\'enregistrer l\'image dans « ' . $dossier . ' » (droits d\'écriture ?).';
        return null;
    }
    return $dossier . '/' . $nomFichier;
}
