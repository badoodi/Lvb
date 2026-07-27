<?php
/**
 * Admin — gestion des pièces d'un plan (?plan=ID).
 * Chaque plan garde une pièce « globale » (choix « toute la villa »).
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

$typesPiece = [
    'chambre'       => 'Chambre',
    'cuisine'       => 'Cuisine',
    'salon'         => 'Salon',
    'salle_de_bain' => 'Salle de bain',
    'globale'       => 'Globale (toute la villa)',
    'autre'         => 'Autre',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        // Empêcher la suppression de la dernière pièce globale.
        $p = db()->prepare('SELECT type_piece FROM pieces_plan WHERE id = ? AND plan_id = ?');
        $p->execute([$id, $planId]);
        $tp = $p->fetchColumn();
        if ($tp === 'globale') {
            $c = db()->prepare("SELECT COUNT(*) FROM pieces_plan WHERE plan_id = ? AND type_piece = 'globale'");
            $c->execute([$planId]);
            if ((int) $c->fetchColumn() <= 1) {
                flash('Impossible de supprimer la seule pièce globale du plan.', 'erreur');
                redirect($base . '/admin/crud/pieces.php?plan=' . $planId);
            }
        }
        try {
            db()->prepare('DELETE FROM pieces_plan WHERE id = ? AND plan_id = ?')->execute([$id, $planId]);
            flash('Pièce supprimée.');
        } catch (PDOException $e) {
            flash('Suppression impossible : cette pièce est utilisée dans une configuration.', 'erreur');
        }
        redirect($base . '/admin/crud/pieces.php?plan=' . $planId);
    }

    $id = (int) ($_POST['id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    $type = $_POST['type_piece'] ?? 'autre';
    $etage = trim($_POST['etage'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $dimensions = trim($_POST['dimensions'] ?? '');
    $ordre = (int) ($_POST['ordre_affichage'] ?? 0);
    if (!isset($typesPiece[$type])) {
        $type = 'autre';
    }
    if ($nom === '') {
        flash('Le nom de la pièce est obligatoire.', 'erreur');
        redirect($base . '/admin/crud/pieces.php?plan=' . $planId);
    }
    if ($id) {
        db()->prepare('UPDATE pieces_plan SET nom = ?, type_piece = ?, etage = ?, description = ?, dimensions = ?, ordre_affichage = ? WHERE id = ? AND plan_id = ?')
            ->execute([$nom, $type, ($etage ?: null), ($description ?: null), ($dimensions ?: null), $ordre, $id, $planId]);
        flash('Pièce modifiée.');
    } else {
        db()->prepare('INSERT INTO pieces_plan (plan_id, nom, type_piece, etage, description, dimensions, ordre_affichage) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$planId, $nom, $type, ($etage ?: null), ($description ?: null), ($dimensions ?: null), $ordre]);
        $id = (int) db()->lastInsertId();
        flash('Pièce ajoutée.');
    }
    // Caractéristiques de la pièce (remplacement complet).
    db()->prepare('DELETE FROM piece_caracteristiques WHERE piece_id = ?')->execute([$id]);
    $cnoms = (array) ($_POST['carac_nom'] ?? []);
    $cvals = (array) ($_POST['carac_val'] ?? []);
    $insC = db()->prepare('INSERT INTO piece_caracteristiques (piece_id, nom, valeur) VALUES (?, ?, ?)');
    foreach ($cnoms as $i => $cn) {
        $cn = trim((string) $cn);
        if ($cn !== '') {
            $insC->execute([$id, $cn, trim((string) ($cvals[$i] ?? ''))]);
        }
    }
    redirect($base . '/admin/crud/pieces.php?plan=' . $planId);
}

$pieces = db()->prepare('SELECT * FROM pieces_plan WHERE plan_id = ? ORDER BY ordre_affichage, id');
$pieces->execute([$planId]);
$pieces = $pieces->fetchAll();

$edit = null;
if (isset($_GET['edit'])) {
    foreach ($pieces as $p) {
        if ((int) $p['id'] === (int) $_GET['edit']) {
            $edit = $p;
        }
    }
}

layout_admin_debut('Pièces — ' . $plan['nom'], 'plans');
?>
<a class="back-link" href="<?= h($base) ?>/admin/crud/plans.php?form=<?= $planId ?>">← Retour au plan</a>

<div class="admin-cols">
    <div class="admin-panel">
        <div class="panel-head"><h2>Pièces du plan</h2></div>
        <?php if (!$pieces): ?>
            <p class="vide">Aucune pièce.</p>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Ordre</th><th>Nom</th><th>Étage</th><th>Type</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($pieces as $p): ?>
                    <tr>
                        <td><?= (int) $p['ordre_affichage'] ?></td>
                        <td><?= h($p['nom']) ?><?php if (!empty($p['description'])): ?><br><small style="color:var(--ink-soft)"><?= h($p['description']) ?></small><?php endif; ?></td>
                        <td><?= h($p['etage'] ?? '—') ?></td>
                        <td><?= h($typesPiece[$p['type_piece']] ?? $p['type_piece']) ?></td>
                        <td class="row-actions">
                            <a class="btn-line" href="?plan=<?= $planId ?>&edit=<?= (int) $p['id'] ?>">Modifier</a>
                            <form method="post" class="inline-form" onsubmit="return confirm('Supprimer cette pièce ?');">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
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
        <div class="panel-head"><h2><?= $edit ? 'Modifier la pièce' : 'Ajouter une pièce' ?></h2></div>
        <form method="post" class="crud-form">
            <?= csrf_input() ?>
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <label class="form-field"><span>Nom</span>
                <input type="text" name="nom" required value="<?= h($edit['nom'] ?? '') ?>"></label>
            <label class="form-field"><span>Type</span>
                <select name="type_piece">
                    <?php foreach ($typesPiece as $v => $l): ?>
                        <option value="<?= h($v) ?>" <?= ($edit['type_piece'] ?? '') === $v ? 'selected' : '' ?>><?= h($l) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <?php
            $etagesDispo = ['Rez-de-chaussée'];
            for ($n = 1; $n <= 10; $n++) { $etagesDispo[] = 'Étage ' . $n; }
            $etageCourant = $edit['etage'] ?? '';
            // Conserver une valeur existante hors liste.
            if ($etageCourant !== '' && !in_array($etageCourant, $etagesDispo, true)) {
                array_unshift($etagesDispo, $etageCourant);
            }
            ?>
            <label class="form-field"><span>Étage</span>
                <select name="etage">
                    <option value="">—</option>
                    <?php foreach ($etagesDispo as $et): ?>
                        <option value="<?= h($et) ?>" <?= $etageCourant === $et ? 'selected' : '' ?>><?= h($et) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label class="form-field"><span>Description (pour situer la pièce)</span>
                <textarea name="description" rows="2" placeholder="Ex : la chambre près du salon"><?= h($edit['description'] ?? '') ?></textarea></label>
            <label class="form-field"><span>Dimensions</span>
                <input type="text" name="dimensions" value="<?= h($edit['dimensions'] ?? '') ?>" placeholder="Ex : 4m x 3.5m"></label>
            <?php
            $caracs = $edit ? piece_caracteristiques((int) $edit['id']) : [];
            $cs = $caracs ?: [['nom' => '', 'valeur' => '']];
            ?>
            <div class="champs-perso">
                <p class="champs-perso-titre">Caractéristiques</p>
                <div id="pcarac-liste">
                    <?php foreach ($cs as $c): ?>
                        <div class="repeat-row repeat-duo">
                            <input type="text" name="carac_nom[]" value="<?= h($c['nom']) ?>" placeholder="Caractéristique (ex : Exposition)">
                            <input type="text" name="carac_val[]" value="<?= h($c['valeur']) ?>" placeholder="Valeur (ex : Sud)">
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn-line" onclick="var d=document.createElement('div');d.className='repeat-row repeat-duo';d.innerHTML='<input type=\'text\' name=\'carac_nom[]\' placeholder=\'Caractéristique\'><input type=\'text\' name=\'carac_val[]\' placeholder=\'Valeur\'>';document.getElementById('pcarac-liste').appendChild(d);">+ Ajouter une caractéristique</button>
            </div>
            <label class="form-field"><span>Ordre d'affichage</span>
                <input type="number" step="1" name="ordre_affichage" value="<?= h($edit['ordre_affichage'] ?? 0) ?>"></label>
            <div class="crud-form-actions">
                <button class="btn-envoyer"><?= $edit ? 'Enregistrer' : 'Ajouter' ?></button>
                <?php if ($edit): ?><a class="btn-line" href="?plan=<?= $planId ?>">Annuler</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php layout_admin_fin();
