<?php
/**
 * Dashboard admin — vue globale : indicateurs, visites clients, résumé des
 * configurations.
 */
require_once __DIR__ . '/../lib/layout.php';
exiger_admin();
$base = base_url();

$pdo = db();

// Purge automatique : configurations annulées par le client il y a plus de
// 15 jours et non supprimées par l'admin. S'exécute à chaque visite du dashboard.
$pdo->query(
    "DELETE FROM configurations
     WHERE statut = 'annule_client'
       AND annule_le IS NOT NULL
       AND annule_le < NOW() - INTERVAL 15 DAY"
);

$compte = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();

$stats = [
    'clients'    => $compte('SELECT COUNT(*) FROM clients'),
    'plans'      => $compte('SELECT COUNT(*) FROM plans_villa'),
    'produits'   => $compte('SELECT COUNT(*) FROM produits'),
    'en_attente' => $compte("SELECT COUNT(*) FROM configurations WHERE statut = 'en_attente'"),
    'validees'   => $compte("SELECT COUNT(*) FROM configurations WHERE statut = 'validee'"),
    'en_cours'   => $compte("SELECT COUNT(*) FROM configurations WHERE statut = 'en_cours'"),
];

// Dernières visites clients.
$visites = $pdo->query(
    'SELECT v.date_heure, v.adresse_ip, c.nom, c.identifiant
     FROM visites v JOIN clients c ON c.id = v.client_id
     ORDER BY v.date_heure DESC LIMIT 12'
)->fetchAll();

// Résumé des configurations (toutes).
$configs = $pdo->query(
    'SELECT cfg.id, cfg.statut, cfg.prix_total, cfg.date_creation,
            cl.nom AS client_nom, p.nom AS plan_nom, f.nom AS formule_nom,
            (SELECT COUNT(*) FROM configuration_produits cp WHERE cp.configuration_id = cfg.id) AS nb_options
     FROM configurations cfg
     JOIN clients cl    ON cl.id = cfg.client_id
     JOIN plans_villa p ON p.id = cfg.plan_id
     JOIN formules f    ON f.id = cfg.formule_id
     ORDER BY cfg.date_creation DESC LIMIT 20'
)->fetchAll();

$libelleStatut = [
    'en_cours'      => 'En cours',
    'en_attente'    => 'En attente',
    'validee'       => 'Validée',
    'annule_client' => 'Annulé par le client',
];

layout_admin_debut('Vue globale', '');
?>
<div class="stat-row">
    <div class="stat-card"><span class="stat-label">Clients</span><span class="stat-value"><?= $stats['clients'] ?></span></div>
    <div class="stat-card"><span class="stat-label">Plans</span><span class="stat-value"><?= $stats['plans'] ?></span></div>
    <div class="stat-card"><span class="stat-label">Produits</span><span class="stat-value"><?= $stats['produits'] ?></span></div>
    <div class="stat-card accent"><span class="stat-label">En attente</span><span class="stat-value"><?= $stats['en_attente'] ?></span></div>
    <div class="stat-card"><span class="stat-label">Validées</span><span class="stat-value"><?= $stats['validees'] ?></span></div>
    <div class="stat-card"><span class="stat-label">En cours</span><span class="stat-value"><?= $stats['en_cours'] ?></span></div>
</div>

<div class="admin-cols">
    <div class="admin-panel">
        <div class="panel-head">
            <h2>Configurations clients</h2>
            <a class="btn-line" href="<?= h($base) ?>/admin/commandes.php">Gérer les commandes →</a>
        </div>
        <?php if (!$configs): ?>
            <p class="vide">Aucune configuration pour le moment.</p>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Client</th><th>Plan</th><th>Collection</th><th>Options</th><th>Total</th><th>Statut</th></tr></thead>
            <tbody>
                <?php foreach ($configs as $c): ?>
                    <tr>
                        <td><?= h($c['client_nom']) ?></td>
                        <td><?= h($c['plan_nom']) ?></td>
                        <td><?= h($c['formule_nom']) ?></td>
                        <td><?= (int) $c['nb_options'] ?></td>
                        <td><?= euros($c['prix_total']) ?></td>
                        <td><span class="badge badge-<?= h($c['statut']) ?>"><?= h($libelleStatut[$c['statut']] ?? $c['statut']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="admin-panel">
        <div class="panel-head"><h2>Dernières visites</h2></div>
        <?php if (!$visites): ?>
            <p class="vide">Aucune visite enregistrée.</p>
        <?php else: ?>
        <ul class="visite-list">
            <?php foreach ($visites as $v): ?>
                <li>
                    <span class="visite-nom"><?= h($v['nom']) ?></span>
                    <span class="visite-date"><?= h(date('d/m/Y H:i', strtotime($v['date_heure']))) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?php layout_admin_fin();
