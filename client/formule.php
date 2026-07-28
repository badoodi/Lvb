<?php
/**
 * Espace client — choix de la formule pour un plan donné.
 * À la validation, crée (ou reprend) une configuration en_cours puis redirige
 * vers la page de configuration.
 */
require_once __DIR__ . '/../lib/layout.php';
$client = exiger_client();
$base = base_url();

$planId = (int) ($_GET['plan'] ?? 0);
$stmt = db()->prepare('SELECT * FROM plans_villa WHERE id = ? AND actif = 1');
$stmt->execute([$planId]);
$plan = $stmt->fetch();
if (!$plan) {
    flash('Plan introuvable.', 'erreur');
    redirect($base . '/client/index.php');
}

// Formules proposées pour ce plan, avec prix de base.
$stmt = db()->prepare(
    'SELECT f.*, pf.prix_base
     FROM plan_formule pf
     JOIN formules f ON f.id = pf.formule_id
     WHERE pf.plan_id = ?
     ORDER BY f.niveau'
);
$stmt->execute([$planId]);
$formules = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $formuleId = (int) ($_POST['formule_id'] ?? 0);

    // Vérifier que la formule est bien proposée pour ce plan.
    $ok = null;
    foreach ($formules as $f) {
        if ((int) $f['id'] === $formuleId) {
            $ok = $f;
            break;
        }
    }
    if (!$ok) {
        flash('Collection invalide.', 'erreur');
        redirect($base . '/client/formule.php?plan=' . $planId);
    }

    // Reprendre une configuration en_cours identique si elle existe.
    $stmt = db()->prepare(
        'SELECT id FROM configurations
         WHERE client_id = ? AND plan_id = ? AND formule_id = ? AND statut = \'en_cours\'
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$client['id'], $planId, $formuleId]);
    $existant = $stmt->fetch();

    if ($existant) {
        redirect($base . '/client/config.php?config=' . (int) $existant['id']);
    }

    $ins = db()->prepare(
        'INSERT INTO configurations (client_id, plan_id, formule_id, statut, prix_total)
         VALUES (?, ?, ?, \'en_cours\', ?)'
    );
    $ins->execute([$client['id'], $planId, $formuleId, $ok['prix_base']]);
    $configId = (int) db()->lastInsertId();

    redirect($base . '/client/config.php?config=' . $configId);
}

// Classe de style par niveau.
$classeNiveau = [1 => 'selenite', 2 => '', 3 => 'signature'];

layout_client_debut('Choisir une collection');
?>
<section class="client-page wrap">
    <a class="back-link" href="<?= h($base) ?>/client/index.php">← Retour aux plans</a>
    <div class="section-head">
        <h2>Plan <?= h($plan['nom']) ?> — choisissez votre collection</h2>
        <span class="count"><?= count($formules) ?> collection(s)</span>
    </div>

    <?php if (!$formules): ?>
        <p class="vide">Aucune collection n'est configurée pour ce plan.</p>
    <?php else: ?>
    <form method="post" class="formule-grid">
        <?= csrf_input() ?>
        <?php foreach ($formules as $f):
            $cls = $classeNiveau[(int) $f['niveau']] ?? ''; ?>
            <div class="formule-card niveau-<?= (int) $f['niveau'] ?> <?= h($cls) ?>">
                <div class="formule-sheen"></div>
                <div class="formule-overlay">
                    <div class="formule-badge">
                        <span class="formule-badge-ic">✓</span> Collection niveau <?= (int) $f['niveau'] ?>
                    </div>
                    <div class="formule-name"><?= h($f['nom']) ?></div>
                    <p class="formule-desc"><?= h($f['description']) ?></p>
                    <div class="formule-foot">
                        <div class="formule-prix">
                            <span>À partir de</span>
                            <strong><?= euros($f['prix_base']) ?></strong>
                        </div>
                        <button type="submit" name="formule_id" value="<?= (int) $f['id'] ?>" class="btn-choisir">
                            Choisir <span>+</span>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </form>
    <?php endif; ?>
</section>
<?php layout_fin();
