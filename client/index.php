<?php
/**
 * Espace client — accueil : mes projets en cours + choix d'un plan de villa.
 */
require_once __DIR__ . '/../lib/layout.php';
$client = exiger_client();
$base = base_url();

// Annulation d'une configuration par le client (masquée pour lui, conservée
// pour l'admin en statut « annulé par le client »).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'annuler') {
    csrf_verifier();
    $configId = (int) ($_POST['config_id'] ?? 0);
    $maj = db()->prepare(
        'UPDATE configurations
         SET statut = \'annule_client\', annule_le = NOW()
         WHERE id = ? AND client_id = ? AND statut IN (\'en_cours\', \'en_attente\')'
    );
    $maj->execute([$configId, $client['id']]);
    flash($maj->rowCount() ? 'Configuration annulée.' : 'Cette configuration ne peut pas être annulée.',
          $maj->rowCount() ? 'succes' : 'erreur');
    redirect($base . '/client/index.php');
}

// Projets (configurations) déjà démarrés par ce client (hors annulées).
$stmt = db()->prepare(
    'SELECT c.*, p.nom AS plan_nom, p.image AS plan_image, f.nom AS formule_nom, f.niveau AS formule_niveau
     FROM configurations c
     JOIN plans_villa p ON p.id = c.plan_id
     JOIN formules f    ON f.id = c.formule_id
     WHERE c.client_id = ? AND c.statut <> \'annule_client\'
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
<section class="welcome-banner">
    <div class="welcome-inner">
        <div class="welcome-eyebrow">Espace client</div>
        <h1>Bienvenue <em><?= h($client['nom'] ?? $client['identifiant']) ?></em></h1>
    </div>
</section>
<section class="client-page wrap">

    <?php if ($projets): ?>
    <div class="section-head">
        <h2>Mes projets</h2>
        <span class="count"><?= count($projets) ?> projet(s)</span>
    </div>
    <div class="projet-list">
        <?php foreach ($projets as $pr): ?>
            <?php
            $niveau = (int) $pr['formule_niveau'];
            $villaImg = $base . '/assets/formule-' . (in_array($niveau, [1, 2, 3], true) ? $niveau : 1) . '.jpg';
            $planImg = !empty($pr['plan_image']) ? ($base . '/' . ltrim($pr['plan_image'], '/')) : '';
            $pct = progression_configuration((int) $pr['id'], (int) $pr['plan_id'], (int) $pr['formule_id'], $pr['statut']);
            ?>
            <div class="projet-card projet-card-wide">
                <div class="projet-villa" style="background-image:url('<?= h($villaImg) ?>')"></div>
                <div class="projet-milieu">
                    <div class="projet-meta">
                        <span class="badge badge-<?= h($pr['statut']) ?>"><?= h($libelleStatut[$pr['statut']]) ?></span>
                        <span class="projet-date">Créé le <?= h(date('d/m/Y', strtotime($pr['date_creation']))) ?></span>
                    </div>
                    <h3><?= h($pr['plan_nom']) ?></h3>
                    <p class="projet-formule">Collection <strong><?= h($pr['formule_nom']) ?></strong></p>
                    <p class="projet-prix"><?= euros($pr['prix_total']) ?></p>
                    <div class="projet-progress">
                        <div class="progress-head"><span>Avancement de la configuration</span><span><?= $pct ?>%</span></div>
                        <div class="progress-bar"><span style="width:<?= $pct ?>%"></span></div>
                    </div>
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
                    <?php if (in_array($pr['statut'], ['en_attente', 'validee'], true)): ?>
                        <a class="btn-line" href="<?= h($base) ?>/pdf_recap.php?config=<?= (int) $pr['id'] ?>">
                            Récapitulatif (PDF)
                        </a>
                    <?php endif; ?>
                    <?php if ($pr['statut'] === 'validee'): ?>
                        <a class="btn-line" href="<?= h($base) ?>/client/documents.php?config=<?= (int) $pr['id'] ?>">
                            Documents techniques
                        </a>
                    <?php endif; ?>
                    <?php if (in_array($pr['statut'], ['en_cours', 'en_attente'], true)): ?>
                        <form method="post" onsubmit="return confirm('Supprimer définitivement cette configuration ? Cette action est irréversible.');">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="annuler">
                            <input type="hidden" name="config_id" value="<?= (int) $pr['id'] ?>">
                            <button type="submit" class="btn-line btn-danger btn-full">Supprimer cette configuration</button>
                        </form>
                    <?php endif; ?>
                    </div>
                </div>
                <div class="projet-plan">
                    <span class="projet-plan-lbl">Plan de la villa</span>
                    <?php if ($planImg): ?>
                        <img src="<?= h($planImg) ?>" alt="Plan <?= h($pr['plan_nom']) ?>">
                    <?php else: ?>
                        <div class="projet-plan-vide">⌂</div>
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
    <p class="section-intro">
        <strong>Étape 1 — choisissez le plan de votre villa</strong> parmi ceux proposés ci-dessous.
        Vous sélectionnerez ensuite votre collection, puis vous configurerez chaque pièce
        (matériaux, climatisation, carrelage…) pour composer votre villa sur-mesure.
    </p>

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
