<?php
/**
 * Admin — fiche détaillée d'un client : coordonnées, devis, dernière connexion,
 * et l'ensemble de ses configurations (plan, formule, choix par pièce, prix).
 */
require_once __DIR__ . '/../../lib/layout.php';
exiger_admin();
$base = base_url();

$clientId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT c.*, a.identifiant AS cree_par_nom
     FROM clients c LEFT JOIN administrateurs a ON a.id = c.cree_par
     WHERE c.id = ?'
);
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) {
    flash('Client introuvable.', 'erreur');
    redirect($base . '/admin/crud/clients.php');
}

// Devis rattaché(s).
$dv = db()->prepare('SELECT * FROM devis WHERE client_id = ? ORDER BY date_creation DESC');
$dv->execute([$clientId]);
$devis = $dv->fetchAll();

// Connexions (visites).
$vi = db()->prepare('SELECT COUNT(*) AS nb, MAX(date_heure) AS derniere FROM visites WHERE client_id = ?');
$vi->execute([$clientId]);
$visite = $vi->fetch();

// Configurations du client.
$cf = db()->prepare(
    'SELECT cfg.*, p.nom AS plan_nom, f.nom AS formule_nom
     FROM configurations cfg
     JOIN plans_villa p ON p.id = cfg.plan_id
     JOIN formules f    ON f.id = cfg.formule_id
     WHERE cfg.client_id = ?
     ORDER BY cfg.date_creation DESC'
);
$cf->execute([$clientId]);
$configs = $cf->fetchAll();

// Options d'une configuration (préparé, réutilisé en boucle).
$optStmt = db()->prepare(
    'SELECT cp.prix_applique, cp.supplement,
            cat.nom AS categorie, pc.nom AS piece, pc.etage,
            pr.nom AS produit, pr.reference
     FROM configuration_produits cp
     JOIN categories_produits cat ON cat.id = cp.categorie_produit_id
     JOIN pieces_plan pc          ON pc.id = cp.piece_id
     JOIN produits pr             ON pr.id = cp.produit_id
     WHERE cp.configuration_id = ?
     ORDER BY cat.ordre_affichage, pc.ordre_affichage'
);

$libelleStatut = ['en_cours' => 'En cours', 'en_attente' => 'En attente', 'validee' => 'Validée'];

layout_admin_debut('Client — ' . $client['nom'], 'clients');
?>
<a class="back-link" href="<?= h($base) ?>/admin/crud/clients.php">← Tous les clients</a>

<div class="admin-cols">
    <div class="admin-panel">
        <div class="panel-head"><h2>Coordonnées</h2></div>
        <table class="fiche-table">
            <tr><th>Nom</th><td><?= h($client['nom']) ?></td></tr>
            <tr><th>Identifiant</th><td><?= h($client['identifiant']) ?></td></tr>
            <tr><th>Téléphone</th><td><?= h($client['telephone'] ?: '—') ?></td></tr>
            <tr><th>Compte créé le</th><td><?= h(date('d/m/Y', strtotime($client['date_creation']))) ?></td></tr>
            <tr><th>Origine</th><td><?= $client['cree_par_nom'] ? 'Créé par ' . h($client['cree_par_nom']) : 'Auto-inscription (devis)' ?></td></tr>
        </table>
    </div>

    <div class="admin-panel">
        <div class="panel-head"><h2>Activité</h2></div>
        <table class="fiche-table">
            <tr><th>Dernière connexion</th><td><?= $visite['derniere'] ? h(date('d/m/Y H:i', strtotime($visite['derniere']))) : 'Jamais' ?></td></tr>
            <tr><th>Nombre de connexions</th><td><?= (int) $visite['nb'] ?></td></tr>
        </table>
        <div class="panel-head" style="margin-top:18px"><h2>Devis</h2></div>
        <?php if (!$devis): ?>
            <p class="vide">Aucun devis rattaché.</p>
        <?php else: ?>
            <table class="fiche-table">
                <?php foreach ($devis as $d): ?>
                    <tr>
                        <th><?= h($d['numero_devis']) ?></th>
                        <td><span class="badge badge-<?= $d['statut'] === 'utilise' ? 'validee' : 'en_cours' ?>"><?= h($d['statut']) ?></span>
                            <?php if ($d['date_utilisation']): ?> · utilisé le <?= h(date('d/m/Y', strtotime($d['date_utilisation']))) ?><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="admin-panel">
    <div class="panel-head"><h2>Configurations (<?= count($configs) ?>)</h2></div>
    <?php if (!$configs): ?>
        <p class="vide">Ce client n'a pas encore démarré de configuration.</p>
    <?php else: ?>
        <?php foreach ($configs as $cfg):
            $optStmt->execute([(int) $cfg['id']]);
            $lignes = $optStmt->fetchAll(); ?>
            <div class="config-detail">
                <div class="config-detail-head">
                    <h3><?= h($cfg['plan_nom']) ?> · Formule <strong><?= h($cfg['formule_nom']) ?></strong></h3>
                    <span class="badge badge-<?= h($cfg['statut']) ?>"><?= h($libelleStatut[$cfg['statut']] ?? $cfg['statut']) ?></span>
                </div>
                <div class="detail-meta">
                    <span>Créée le <?= h(date('d/m/Y', strtotime($cfg['date_creation']))) ?></span>
                    <?php if ($cfg['date_soumission']): ?><span>Soumise le <?= h(date('d/m/Y', strtotime($cfg['date_soumission']))) ?></span><?php endif; ?>
                    <?php if ($cfg['date_validation']): ?><span>Validée le <?= h(date('d/m/Y', strtotime($cfg['date_validation']))) ?></span><?php endif; ?>
                    <span>Total : <strong><?= euros($cfg['prix_total']) ?></strong></span>
                </div>
                <?php if (!$lignes): ?>
                    <p class="vide">Aucune option choisie pour l'instant.</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead><tr><th>Catégorie</th><th>Pièce</th><th>Étage</th><th>Produit</th><th>Réf.</th><th>Prix</th><th>Suppl.</th></tr></thead>
                        <tbody>
                            <?php foreach ($lignes as $l): ?>
                                <tr>
                                    <td><?= h($l['categorie']) ?></td>
                                    <td><?= h($l['piece']) ?></td>
                                    <td><?= h($l['etage'] ?: '—') ?></td>
                                    <td><?= h($l['produit']) ?></td>
                                    <td><?= h($l['reference']) ?></td>
                                    <td><?= euros($l['prix_applique']) ?></td>
                                    <td><?= $l['supplement'] > 0 ? '+ ' . euros($l['supplement']) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <?php if ($cfg['statut'] === 'en_attente'): ?>
                    <a class="btn-line" href="<?= h($base) ?>/admin/commandes.php?config=<?= (int) $cfg['id'] ?>">Gérer / valider cette commande →</a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php layout_admin_fin();
