<?php
/**
 * Espace client — documents techniques (plan électrique, plomberie…).
 * Disponibles uniquement pour les configurations validées par l'admin.
 */
require_once __DIR__ . '/../lib/layout.php';
$client = exiger_client();
$base = base_url();

// Configurations validées du client + leur plan.
$stmt = db()->prepare(
    'SELECT c.id, c.plan_id, c.date_validation, p.nom AS plan_nom, f.nom AS formule_nom
     FROM configurations c
     JOIN plans_villa p ON p.id = c.plan_id
     JOIN formules f    ON f.id = c.formule_id
     WHERE c.client_id = ? AND c.statut = \'validee\'
     ORDER BY c.date_validation DESC'
);
$stmt->execute([$client['id']]);
$configsValidees = $stmt->fetchAll();

$docStmt = db()->prepare('SELECT * FROM documents_plan WHERE plan_id = ? ORDER BY type_document');

layout_client_debut('Documents techniques');
?>
<section class="client-page wrap">
    <div class="section-head">
        <h2>Documents techniques</h2>
        <span class="count"><?= count($configsValidees) ?> projet(s) validé(s)</span>
    </div>

    <?php if (!$configsValidees): ?>
        <p class="vide">
            Vos documents techniques (plan électrique, plan de plomberie) apparaîtront ici
            dès qu'une de vos configurations aura été validée par l'atelier.
        </p>
    <?php else: ?>
        <?php foreach ($configsValidees as $cfg):
            $docStmt->execute([(int) $cfg['plan_id']]);
            $docs = $docStmt->fetchAll(); ?>
            <div class="doc-bloc">
                <div class="doc-bloc-head">
                    <h3><?= h($cfg['plan_nom']) ?> · Formule <?= h($cfg['formule_nom']) ?></h3>
                    <span class="projet-date">Validée le <?= h(date('d/m/Y', strtotime($cfg['date_validation']))) ?></span>
                </div>
                <?php if (!$docs): ?>
                    <p class="vide">Aucun document rattaché à ce plan pour l'instant.</p>
                <?php else: ?>
                    <ul class="doc-list">
                        <?php foreach ($docs as $doc): ?>
                            <li>
                                <span class="doc-type"><?= h(str_replace('_', ' ', $doc['type_document'])) ?></span>
                                <span class="doc-nom"><?= h($doc['nom']) ?></span>
                                <a class="btn-line" href="<?= h($base . '/' . ltrim($doc['fichier'], '/')) ?>" download>
                                    Télécharger
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php layout_fin();
