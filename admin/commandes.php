<?php
/**
 * Admin — gestion des commandes (configurations).
 * Liste filtrable par statut, détail des choix, validation (en_attente -> validee)
 * déclenchant l'envoi des documents techniques au client.
 */
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/mail.php';
$admin = exiger_admin();
$base = base_url();

/* --- Validation d'une commande --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    if (($_POST['action'] ?? '') === 'valider') {
        $configId = (int) ($_POST['config_id'] ?? 0);
        $maj = db()->prepare(
            'UPDATE configurations
             SET statut = \'validee\', date_validation = NOW(), valide_par = ?
             WHERE id = ? AND statut = \'en_attente\''
        );
        $maj->execute([$admin['id'], $configId]);
        if ($maj->rowCount() === 1) {
            $envoye = envoyer_documents_au_client($configId);
            flash($envoye
                ? 'Commande validée. Documents techniques envoyés au client par email.'
                : 'Commande validée. Documents marqués comme transmis (email non expédié : voir logs / email client).');
        } else {
            flash('Cette commande n\'était pas en attente de validation.', 'erreur');
        }
        redirect($base . '/admin/commandes.php?config=' . $configId);
    }
    if (($_POST['action'] ?? '') === 'supprimer') {
        // Suppression définitive (réservée aux configurations annulées par le client).
        $configId = (int) ($_POST['config_id'] ?? 0);
        $sup = db()->prepare('DELETE FROM configurations WHERE id = ? AND statut = \'annule_client\'');
        $sup->execute([$configId]);
        flash($sup->rowCount()
            ? 'Configuration supprimée définitivement.'
            : 'Seule une configuration annulée par le client peut être supprimée ici.',
            $sup->rowCount() ? 'succes' : 'erreur');
        redirect($base . '/admin/commandes.php?statut=annule_client');
    }
}

$detailId = (int) ($_GET['config'] ?? 0);

/* =====================================================================
 * VUE DÉTAIL
 * ================================================================== */
if ($detailId) {
    $stmt = db()->prepare(
        'SELECT cfg.*, cl.nom AS client_nom, cl.identifiant AS client_id_login,
                p.nom AS plan_nom, f.nom AS formule_nom
         FROM configurations cfg
         JOIN clients cl    ON cl.id = cfg.client_id
         JOIN plans_villa p ON p.id = cfg.plan_id
         JOIN formules f    ON f.id = cfg.formule_id
         WHERE cfg.id = ?'
    );
    $stmt->execute([$detailId]);
    $cfg = $stmt->fetch();
    if (!$cfg) {
        flash('Commande introuvable.', 'erreur');
        redirect($base . '/admin/commandes.php');
    }

    $opts = db()->prepare(
        'SELECT cp.prix_applique, cp.supplement,
                cat.nom AS categorie, pc.nom AS piece,
                pr.nom AS produit, pr.reference
         FROM configuration_produits cp
         JOIN categories_produits cat ON cat.id = cp.categorie_produit_id
         JOIN pieces_plan pc          ON pc.id = cp.piece_id
         JOIN produits pr             ON pr.id = cp.produit_id
         WHERE cp.configuration_id = ?
         ORDER BY cat.ordre_affichage, pc.ordre_affichage'
    );
    $opts->execute([$detailId]);
    $lignes = $opts->fetchAll();

    layout_admin_debut('Commande #' . $detailId, 'commandes');
    ?>
    <a class="back-link" href="<?= h($base) ?>/admin/commandes.php">← Toutes les commandes</a>
    <div class="admin-panel">
        <div class="panel-head">
            <h2><?= h($cfg['client_nom']) ?> · <?= h($cfg['plan_nom']) ?> · Formule <?= h($cfg['formule_nom']) ?></h2>
            <span class="badge badge-<?= h($cfg['statut']) ?>"><?= h($cfg['statut']) ?></span>
        </div>
        <div class="detail-meta">
            <span>Créée le <?= h(date('d/m/Y', strtotime($cfg['date_creation']))) ?></span>
            <?php if ($cfg['date_soumission']): ?><span>Soumise le <?= h(date('d/m/Y', strtotime($cfg['date_soumission']))) ?></span><?php endif; ?>
            <?php if ($cfg['date_validation']): ?><span>Validée le <?= h(date('d/m/Y', strtotime($cfg['date_validation']))) ?></span><?php endif; ?>
            <?php if ($cfg['plans_envoyes_le']): ?><span>Documents envoyés le <?= h(date('d/m/Y', strtotime($cfg['plans_envoyes_le']))) ?></span><?php endif; ?>
        </div>

        <?php if (!$lignes): ?>
            <p class="vide">Aucune option choisie.</p>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Catégorie</th><th>Pièce</th><th>Produit</th><th>Réf.</th><th>Prix</th><th>Supplément</th></tr></thead>
            <tbody>
                <?php foreach ($lignes as $l): ?>
                    <tr>
                        <td><?= h($l['categorie']) ?></td>
                        <td><?= h($l['piece']) ?></td>
                        <td><?= h($l['produit']) ?></td>
                        <td><?= h($l['reference']) ?></td>
                        <td><?= euros($l['prix_applique']) ?></td>
                        <td><?= $l['supplement'] > 0 ? '+ ' . euros($l['supplement']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="detail-total">Prix total : <strong><?= euros($cfg['prix_total']) ?></strong></div>

        <?php if ($cfg['statut'] === 'en_attente'): ?>
            <form method="post" class="detail-actions">
                <?= csrf_input() ?>
                <input type="hidden" name="config_id" value="<?= $detailId ?>">
                <button type="submit" name="action" value="valider" class="btn-envoyer"
                        onclick="return confirm('Valider cette commande et envoyer les documents techniques au client ?');">
                    Valider la commande &amp; envoyer les documents
                </button>
            </form>
        <?php elseif ($cfg['statut'] === 'annule_client'): ?>
            <div class="flash flash-info">
                Cette configuration a été <strong>annulée par le client</strong>
                <?php if ($cfg['annule_le']): ?>le <?= h(date('d/m/Y', strtotime($cfg['annule_le']))) ?><?php endif; ?>.
                Sans suppression de votre part, elle sera automatiquement supprimée 15 jours après l'annulation.
            </div>
            <form method="post" class="detail-actions">
                <?= csrf_input() ?>
                <input type="hidden" name="config_id" value="<?= $detailId ?>">
                <button type="submit" name="action" value="supprimer" class="btn-line btn-danger"
                        onclick="return confirm('Supprimer DÉFINITIVEMENT cette configuration ? Action irréversible.');">
                    Supprimer définitivement
                </button>
            </form>
        <?php endif; ?>
    </div>
    <?php
    layout_admin_fin();
    return;
}

/* =====================================================================
 * VUE LISTE
 * ================================================================== */
$filtre = $_GET['statut'] ?? 'tous';
$statutsValides = ['en_cours', 'en_attente', 'validee', 'annule_client'];
$libelleStatut = [
    'en_cours' => 'En cours', 'en_attente' => 'En attente',
    'validee' => 'Validée', 'annule_client' => 'Annulé par le client',
];
$where = '';
$params = [];
if (in_array($filtre, $statutsValides, true)) {
    $where = 'WHERE cfg.statut = ?';
    $params[] = $filtre;
}

$stmt = db()->prepare(
    "SELECT cfg.id, cfg.statut, cfg.prix_total, cfg.date_creation,
            cl.nom AS client_nom, p.nom AS plan_nom, f.nom AS formule_nom
     FROM configurations cfg
     JOIN clients cl    ON cl.id = cfg.client_id
     JOIN plans_villa p ON p.id = cfg.plan_id
     JOIN formules f    ON f.id = cfg.formule_id
     $where
     ORDER BY FIELD(cfg.statut,'en_attente','en_cours','validee'), cfg.date_creation DESC"
);
$stmt->execute($params);
$commandes = $stmt->fetchAll();

$onglets = ['tous' => 'Toutes', 'en_attente' => 'En attente', 'en_cours' => 'En cours', 'validee' => 'Validées', 'annule_client' => 'Annulées'];

layout_admin_debut('Commandes', 'commandes');
?>
<div class="filtre-onglets">
    <?php foreach ($onglets as $cle => $lbl): ?>
        <a href="<?= h($base) ?>/admin/commandes.php?statut=<?= h($cle) ?>"
           class="<?= $filtre === $cle ? 'actif' : '' ?>"><?= h($lbl) ?></a>
    <?php endforeach; ?>
</div>

<div class="admin-panel">
    <?php if (!$commandes): ?>
        <p class="vide">Aucune commande dans cette catégorie.</p>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>#</th><th>Client</th><th>Plan</th><th>Formule</th><th>Total</th><th>Statut</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($commandes as $c): ?>
                <tr>
                    <td><?= (int) $c['id'] ?></td>
                    <td><?= h($c['client_nom']) ?></td>
                    <td><?= h($c['plan_nom']) ?></td>
                    <td><?= h($c['formule_nom']) ?></td>
                    <td><?= euros($c['prix_total']) ?></td>
                    <td><span class="badge badge-<?= h($c['statut']) ?>"><?= h($libelleStatut[$c['statut']] ?? $c['statut']) ?></span></td>
                    <td><a class="btn-line" href="<?= h($base) ?>/admin/commandes.php?config=<?= (int) $c['id'] ?>">Détail</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php layout_admin_fin();
