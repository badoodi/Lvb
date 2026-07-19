<?php
/**
 * Admin — documents techniques d'un plan (?plan=ID).
 * Le fichier peut être téléversé (stocké dans /documents) ou saisi comme chemin.
 */
require_once __DIR__ . '/../../lib/layout.php';
exiger_admin();
$base = base_url();

$planId = (int) ($_GET['plan'] ?? 0);
$stmt = db()->prepare('SELECT * FROM plans_villa WHERE id = ?');
$stmt->execute([$planId]);
$plan = $stmt->fetch();
if (!$plan) {
    flash('Plan introuvable.', 'erreur');
    redirect($base . '/admin/crud/plans.php');
}

$typesDoc = [
    'plan_electrique' => 'Plan électrique',
    'plan_plomberie'  => 'Plan de plomberie',
    'autre'           => 'Autre',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        db()->prepare('DELETE FROM documents_plan WHERE id = ? AND plan_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $planId]);
        flash('Document supprimé.');
        redirect($base . '/admin/crud/documents.php?plan=' . $planId);
    }

    $id = (int) ($_POST['id'] ?? 0);
    $type = $_POST['type_document'] ?? 'autre';
    $nom = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $fichier = trim($_POST['fichier'] ?? '');
    if (!isset($typesDoc[$type])) {
        $type = 'autre';
    }

    // Téléversement éventuel (prioritaire sur le chemin saisi).
    if (!empty($_FILES['fichier_upload']['name']) && is_uploaded_file($_FILES['fichier_upload']['tmp_name'])) {
        $dossier = config('dossier_documents');
        $reel = __DIR__ . '/../../' . $dossier;
        if (!is_dir($reel)) {
            @mkdir($reel, 0775, true);
        }
        $nomFichier = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['fichier_upload']['name']));
        $nomFichier = time() . '-' . $nomFichier;
        if (move_uploaded_file($_FILES['fichier_upload']['tmp_name'], $reel . '/' . $nomFichier)) {
            $fichier = $dossier . '/' . $nomFichier;
        } else {
            flash('Le téléversement a échoué.', 'erreur');
        }
    }

    if ($nom === '' || $fichier === '') {
        flash('Le nom et le fichier (téléversé ou chemin) sont obligatoires.', 'erreur');
    } elseif ($id) {
        db()->prepare('UPDATE documents_plan SET type_document = ?, nom = ?, fichier = ?, description = ? WHERE id = ? AND plan_id = ?')
            ->execute([$type, $nom, $fichier, $description, $id, $planId]);
        flash('Document modifié.');
    } else {
        db()->prepare('INSERT INTO documents_plan (plan_id, type_document, nom, fichier, description) VALUES (?, ?, ?, ?, ?)')
            ->execute([$planId, $type, $nom, $fichier, $description]);
        flash('Document ajouté.');
    }
    redirect($base . '/admin/crud/documents.php?plan=' . $planId);
}

$docs = db()->prepare('SELECT * FROM documents_plan WHERE plan_id = ? ORDER BY type_document');
$docs->execute([$planId]);
$docs = $docs->fetchAll();

$edit = null;
if (isset($_GET['edit'])) {
    foreach ($docs as $d) {
        if ((int) $d['id'] === (int) $_GET['edit']) {
            $edit = $d;
        }
    }
}

layout_admin_debut('Documents — ' . $plan['nom'], 'plans');
?>
<a class="back-link" href="<?= h($base) ?>/admin/crud/plans.php?form=<?= $planId ?>">← Retour au plan</a>

<div class="admin-cols">
    <div class="admin-panel">
        <div class="panel-head"><h2>Documents techniques</h2></div>
        <?php if (!$docs): ?>
            <p class="vide">Aucun document.</p>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Type</th><th>Nom</th><th>Fichier</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td><?= h($typesDoc[$d['type_document']] ?? $d['type_document']) ?></td>
                        <td><?= h($d['nom']) ?></td>
                        <td><code><?= h($d['fichier']) ?></code></td>
                        <td class="row-actions">
                            <a class="btn-line" href="?plan=<?= $planId ?>&edit=<?= (int) $d['id'] ?>">Modifier</a>
                            <form method="post" class="inline-form" onsubmit="return confirm('Supprimer ce document ?');">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                <button class="btn-line btn-danger">Suppr.</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="admin-panel">
        <div class="panel-head"><h2><?= $edit ? 'Modifier le document' : 'Ajouter un document' ?></h2></div>
        <form method="post" class="crud-form" enctype="multipart/form-data">
            <?= csrf_input() ?>
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <label class="form-field"><span>Type</span>
                <select name="type_document">
                    <?php foreach ($typesDoc as $v => $l): ?>
                        <option value="<?= h($v) ?>" <?= ($edit['type_document'] ?? '') === $v ? 'selected' : '' ?>><?= h($l) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label class="form-field"><span>Nom</span>
                <input type="text" name="nom" required value="<?= h($edit['nom'] ?? '') ?>"></label>
            <label class="form-field"><span>Téléverser un fichier</span>
                <input type="file" name="fichier_upload"></label>
            <label class="form-field"><span>… ou chemin du fichier</span>
                <input type="text" name="fichier" value="<?= h($edit['fichier'] ?? '') ?>" placeholder="documents/plan.pdf"></label>
            <label class="form-field"><span>Description</span>
                <textarea name="description" rows="2"><?= h($edit['description'] ?? '') ?></textarea></label>
            <div class="crud-form-actions">
                <button class="btn-envoyer"><?= $edit ? 'Enregistrer' : 'Ajouter' ?></button>
                <?php if ($edit): ?><a class="btn-line" href="?plan=<?= $planId ?>">Annuler</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php layout_admin_fin();
