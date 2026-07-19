<?php
/**
 * Espace client — accueil : mes projets en cours + choix d'un plan de villa.
 */
require_once __DIR__ . '/../lib/layout.php';
$client = exiger_client();
$base = base_url();

// Projets (configurations) déjà démarrés par ce client.
$stmt = db()->prepare(
    'SELECT c.*, p.nom AS plan_nom, f.nom AS formule_nom
     FROM configurations c
     JOIN plans_villa p ON p.id = c.plan_id
     JOIN formules f    ON f.id = c.formule_id
     WHERE c.client_id = ?
     ORDER BY c.date_creation DESC'
);
$stmt->execute([$client['id']]);
$projets = $stmt->fetchAll();

// Plans actifs proposés au choix.
$plans = db()->query(
    'SELECT * FROM plans_villa WHERE actif = 1 ORDER BY nom'
)->fetchAll();

$libelleStatut = [
    'en_cours'   => 'En cours de configuration',
    'en_attente' => 'En attente de validation',
    'validee'    => 'Validée',
];

layout_client_debut('Mes plans');
?>
<section class="client-page wrap">

    <?php if ($projets): ?>
    <div class="section-head">
        <h2>Mes projets</h2>
        <span class="count"><?= count($projets) ?> projet(s)</span>
    </div>
    <div class="projet-list">
        <?php foreach ($projets as $pr): ?>
            <div class="projet-card">
                <div class="projet-meta">
                    <span class="badge badge-<?= h($pr['statut']) ?>"><?= h($libelleStatut[$pr['statut']]) ?></span>
                    <span class="projet-date">Créé le <?= h(date('d/m/Y', strtotime($pr['date_creation']))) ?></span>
                </div>
                <h3><?= h($pr['plan_nom']) ?></h3>
                <p class="projet-formule">Formule <strong><?= h($pr['formule_nom']) ?></strong></p>
                <p class="projet-prix"><?= euros($pr['prix_total']) ?></p>
                <div class="projet-actions">
                    <?php if ($pr['statut'] === 'en_cours'): ?>
                        <a class="btn-line" href="<?= h($base) ?>/client/config.php?config=<?= (int) $pr['id'] ?>">
                            Continuer la configuration →
                        </a>
                    <?php else: ?>
                        <a class="btn-line" href="<?= h($base) ?>/client/config.php?config=<?= (int) $pr['id'] ?>">
                            Voir le détail
                        </a>
                    <?php endif; ?>
                    <?php if ($pr['statut'] === 'validee'): ?>
                        <a class="btn-line" href="<?= h($base) ?>/client/documents.php?config=<?= (int) $pr['id'] ?>">
                            Documents techniques
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="section-head">
        <h2>Démarrer un nouveau projet</h2>
        <span class="count">Choisissez un plan</span>
    </div>

    <?php if (!$plans): ?>
        <p class="vide">Aucun plan de villa n'est disponible pour le moment.</p>
    <?php else: ?>
    <div class="plan-grid">
        <?php foreach ($plans as $plan): ?>
            <a class="plan-card" href="<?= h($base) ?>/client/formule.php?plan=<?= (int) $plan['id'] ?>">
                <div class="plan-visuel">
                    <?php if (!empty($plan['image'])): ?>
                        <img src="<?= h($base . '/' . ltrim($plan['image'], '/')) ?>" alt="<?= h($plan['nom']) ?>">
                    <?php else: ?>
                        <div class="plan-visuel-vide blueprint-grid"></div>
                    <?php endif; ?>
                </div>
                <div class="plan-info">
                    <h3><?= h($plan['nom']) ?></h3>
                    <p><?= h($plan['description']) ?></p>
                    <ul class="plan-specs">
                        <?php if ($plan['surface_m2'] !== null): ?>
                            <li><span>Surface</span><span><?= h(rtrim(rtrim(number_format($plan['surface_m2'], 2, ',', ' '), '0'), ',')) ?> m²</span></li>
                        <?php endif; ?>
                        <?php if ($plan['dimensions']): ?>
                            <li><span>Dimensions</span><span><?= h($plan['dimensions']) ?></span></li>
                        <?php endif; ?>
                        <?php if ($plan['nombre_chambres'] !== null): ?>
                            <li><span>Chambres</span><span><?= (int) $plan['nombre_chambres'] ?></span></li>
                        <?php endif; ?>
                    </ul>
                    <span class="plan-cta">Choisir ce plan →</span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</section>
<?php layout_fin();
