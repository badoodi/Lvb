<?php
/**
 * Espace client — page de configuration d'une villa.
 *
 * Affiche, pour la formule choisie, les grandes catégories concernées puis les
 * catégories produits. Pour chaque catégorie :
 *   - « par pièce » -> un choix par pièce réelle du plan ;
 *   - sinon         -> un seul choix rattaché à la pièce « globale ».
 *
 * Upgrade (voir CLAUDE.md) : la liste principale montre les produits de la
 * formule choisie ; le volet « Voir d'autres produits » révèle les produits de
 * la formule immédiatement supérieure (niveau + 1), triés par prix croissant.
 * Choisir un produit supérieur génère un « supplément » = prix choisi − prix du
 * produit d'entrée de la formule de base (le moins cher de la catégorie pour
 * cette formule).
 */
require_once __DIR__ . '/../lib/layout.php';
$client = exiger_client();
$base = base_url();

$configId = (int) ($_GET['config'] ?? 0);
$stmt = db()->prepare(
    'SELECT c.*, p.nom AS plan_nom, f.nom AS formule_nom, f.niveau AS formule_niveau,
            pf.prix_base
     FROM configurations c
     JOIN plans_villa p ON p.id = c.plan_id
     JOIN formules f    ON f.id = c.formule_id
     JOIN plan_formule pf ON pf.plan_id = c.plan_id AND pf.formule_id = c.formule_id
     WHERE c.id = ? AND c.client_id = ?'
);
$stmt->execute([$configId, $client['id']]);
$config = $stmt->fetch();
if (!$config) {
    flash('Configuration introuvable.', 'erreur');
    redirect($base . '/client/index.php');
}

$modifiable   = $config['statut'] === 'en_cours';
$niveauChoisi = (int) $config['formule_niveau'];
$prixBase     = (float) $config['prix_base'];

/* --- Pièces du plan --- */
$stmt = db()->prepare('SELECT * FROM pieces_plan WHERE plan_id = ? ORDER BY ordre_affichage, id');
$stmt->execute([(int) $config['plan_id']]);
$piecesPlan = $stmt->fetchAll();
$pieceGlobale = null;
$piecesReelles = [];
foreach ($piecesPlan as $pc) {
    if ($pc['type_piece'] === 'globale') {
        $pieceGlobale = $pc;
    } else {
        $piecesReelles[] = $pc;
    }
}

/** Pièces concernées par une catégorie selon son flag par_piece. */
function pieces_de_categorie(array $categorie, array $piecesReelles, ?array $pieceGlobale): array
{
    if ((int) $categorie['par_piece'] === 1) {
        return $piecesReelles;
    }
    return $pieceGlobale ? [$pieceGlobale] : [];
}

/* --- Grandes catégories concernées par la formule --- */
$stmt = db()->prepare(
    'SELECT gc.*
     FROM grandes_categories gc
     JOIN grande_categorie_formule gcf ON gcf.grande_categorie_id = gc.id
     WHERE gcf.formule_id = ?
     ORDER BY gc.ordre_affichage, gc.id'
);
$stmt->execute([(int) $config['formule_id']]);
$grandesCats = $stmt->fetchAll();

/* --- Catégories produits par grande catégorie --- */
$catStmt = db()->prepare(
    'SELECT * FROM categories_produits WHERE grande_categorie_id = ? ORDER BY ordre_affichage, id'
);

/* --- Produits d'une catégorie (tous niveaux), avec niveau de formule --- */
$prodStmt = db()->prepare(
    'SELECT p.*, f.niveau AS formule_niveau, f.nom AS formule_nom
     FROM produits p
     JOIN formules f ON f.id = p.formule_id
     WHERE p.categorie_produit_id = ? AND p.disponible = 1
     ORDER BY p.prix, p.numero'
);

/**
 * Renvoie pour une catégorie :
 *  - 'proposes'  : produits de la formule choisie (max 5, prix croissant)
 *  - 'upgrades'  : produits de la formule niveau+1 (max 5, prix croissant)
 *  - 'ref'       : prix de référence (produit d'entrée de la formule choisie)
 *  - 'autorises' : [produit_id => prix] de tous les choix permis
 */
function options_categorie(PDOStatement $prodStmt, int $catId, int $niveauChoisi): array
{
    $prodStmt->execute([$catId]);
    $tous = $prodStmt->fetchAll();

    $proposes = $upgrades = [];
    foreach ($tous as $p) {
        $n = (int) $p['formule_niveau'];
        if ($n === $niveauChoisi) {
            $proposes[] = $p;
        } elseif ($n === $niveauChoisi + 1) {
            $upgrades[] = $p;
        }
    }
    $ref = $proposes ? (float) $proposes[0]['prix'] : 0.0; // déjà triés par prix
    $proposes = array_slice($proposes, 0, 5);
    $upgrades = array_slice($upgrades, 0, 5);

    $autorises = [];
    foreach (array_merge($proposes, $upgrades) as $p) {
        $autorises[(int) $p['id']] = (float) $p['prix'];
    }
    return ['proposes' => $proposes, 'upgrades' => $upgrades, 'ref' => $ref, 'autorises' => $autorises];
}

/* --- Sélections existantes : [categorie_id][piece_id] = produit_id --- */
function charger_selections(int $configId): array
{
    $stmt = db()->prepare(
        'SELECT categorie_produit_id, piece_id, produit_id
         FROM configuration_produits WHERE configuration_id = ?'
    );
    $stmt->execute([$configId]);
    $map = [];
    foreach ($stmt as $r) {
        $map[(int) $r['categorie_produit_id']][(int) $r['piece_id']] = (int) $r['produit_id'];
    }
    return $map;
}

/* =====================================================================
 * TRAITEMENT POST (enregistrer / valider)
 * ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $modifiable) {
    csrf_verifier();
    $action = $_POST['action'] ?? 'save';
    $selPostees = $_POST['sel'] ?? [];

    $pdo = db();
    $pdo->beginTransaction();

    $upsert = $pdo->prepare(
        'INSERT INTO configuration_produits
            (configuration_id, categorie_produit_id, piece_id, produit_id, prix_applique, supplement)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            produit_id = VALUES(produit_id),
            prix_applique = VALUES(prix_applique),
            supplement = VALUES(supplement)'
    );
    $suppr = $pdo->prepare(
        'DELETE FROM configuration_produits
         WHERE configuration_id = ? AND categorie_produit_id = ? AND piece_id = ?'
    );

    $totalSupplement = 0.0;

    foreach ($grandesCats as $gc) {
        $catStmt->execute([(int) $gc['id']]);
        foreach ($catStmt->fetchAll() as $cat) {
            $opts = options_categorie($prodStmt, (int) $cat['id'], $niveauChoisi);
            $pieces = pieces_de_categorie($cat, $piecesReelles, $pieceGlobale);
            foreach ($pieces as $piece) {
                $choix = (int) ($selPostees[$cat['id']][$piece['id']] ?? 0);
                if ($choix > 0 && isset($opts['autorises'][$choix])) {
                    $prix = $opts['autorises'][$choix];
                    $supp = max(0.0, $prix - $opts['ref']);
                    $upsert->execute([
                        $configId, (int) $cat['id'], (int) $piece['id'], $choix, $prix, $supp,
                    ]);
                    $totalSupplement += $supp;
                } else {
                    // Aucun choix (ou choix invalide) : on retire l'éventuelle ligne.
                    $suppr->execute([$configId, (int) $cat['id'], (int) $piece['id']]);
                }
            }
        }
    }

    $prixTotal = $prixBase + $totalSupplement;

    if ($action === 'valider') {
        $maj = $pdo->prepare(
            'UPDATE configurations
             SET prix_total = ?, statut = \'en_attente\', date_soumission = NOW()
             WHERE id = ? AND client_id = ? AND statut = \'en_cours\''
        );
        $maj->execute([$prixTotal, $configId, $client['id']]);
        $pdo->commit();
        flash('Votre configuration a été validée et transmise à l\'atelier.');
        redirect($base . '/client/index.php');
    } else {
        $maj = $pdo->prepare('UPDATE configurations SET prix_total = ? WHERE id = ?');
        $maj->execute([$prixTotal, $configId]);
        $pdo->commit();
        flash('Vos choix ont été enregistrés.');
        redirect($base . '/client/config.php?config=' . $configId);
    }
}

/* =====================================================================
 * AFFICHAGE
 * ================================================================== */
$selections = charger_selections($configId);
$nbChoix = 0;
foreach ($selections as $parPiece) {
    $nbChoix += count($parPiece);
}

layout_client_debut('Configuration — ' . $config['plan_nom']);
?>
<section class="banner">
    <div class="banner-illustration blueprint-grid"></div>
    <div class="banner-overlay">
        <div class="banner-eyebrow"><?= h($config['plan_nom']) ?> · <?= h(ucfirst(str_replace('_', ' ', $config['statut']))) ?></div>
        <h1>Vous avez choisi la formule <em><?= h($config['formule_nom']) ?></em></h1>
    </div>
</section>

<div class="config-body">
    <form method="post" class="config-layout" action="<?= h($base) ?>/client/config.php?config=<?= $configId ?>">
        <?= csrf_input() ?>
        <div class="config-cats">

            <?php if (!$modifiable): ?>
                <div class="flash flash-info">
                    Cette configuration est <strong><?= h($config['statut']) ?></strong> : elle n'est plus modifiable.
                </div>
            <?php endif; ?>

            <?php foreach ($grandesCats as $gc):
                $catStmt->execute([(int) $gc['id']]);
                $cats = $catStmt->fetchAll(); ?>
                <div class="grande-cat">
                    <div class="grande-cat-head">
                        <span class="category-tag">Gros bloc</span>
                        <h2><?= h($gc['nom']) ?></h2>
                    </div>

                    <?php foreach ($cats as $cat):
                        $opts = options_categorie($prodStmt, (int) $cat['id'], $niveauChoisi);
                        $pieces = pieces_de_categorie($cat, $piecesReelles, $pieceGlobale);
                        ?>
                        <div class="category">
                            <div class="category-head">
                                <span class="category-tag">Cat.</span>
                                <h2><?= h($cat['nom']) ?></h2>
                                <span class="category-sub">
                                    <?= ((int) $cat['par_piece'] === 1) ? 'choix par pièce' : 'toute la villa' ?>
                                </span>
                            </div>

                            <?php if (!$opts['proposes'] && !$opts['upgrades']): ?>
                                <p class="vide">Aucun produit disponible pour cette formule.</p>
                            <?php else: ?>
                                <?php foreach ($pieces as $piece):
                                    $groupe = 'sel[' . (int) $cat['id'] . '][' . (int) $piece['id'] . ']';
                                    $selPiece = $selections[(int) $cat['id']][(int) $piece['id']] ?? 0;
                                    ?>
                                    <div class="piece-bloc">
                                        <?php if ((int) $cat['par_piece'] === 1): ?>
                                            <div class="piece-titre"><?= h($piece['nom']) ?></div>
                                        <?php endif; ?>

                                        <div class="option-list">
                                            <?php $i = 1; foreach ($opts['proposes'] as $prod): ?>
                                                <label class="option-row">
                                                    <span class="option-index"><?= str_pad((string) $i++, 2, '0', STR_PAD_LEFT) ?></span>
                                                    <span class="option-radio">
                                                        <input type="radio" name="<?= h($groupe) ?>"
                                                               value="<?= (int) $prod['id'] ?>"
                                                               <?= $selPiece === (int) $prod['id'] ? 'checked' : '' ?>
                                                               <?= $modifiable ? '' : 'disabled' ?>>
                                                    </span>
                                                    <span class="option-label">
                                                        <?= h($prod['nom']) ?>
                                                        <span class="option-note">réf. <?= h($prod['reference']) ?> · formule <?= h($prod['formule_nom']) ?></span>
                                                    </span>
                                                    <span class="option-level"><?= euros($prod['prix']) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>

                                        <?php if ($opts['upgrades']): ?>
                                            <details class="upgrade" <?= ($selPiece && !in_array($selPiece, array_map(fn($p) => (int) $p['id'], $opts['proposes']), true)) ? 'open' : '' ?>>
                                                <summary>Voir d'autres produits (formule supérieure)</summary>
                                                <div class="option-list upgrade-list">
                                                    <?php foreach ($opts['upgrades'] as $prod):
                                                        $supp = max(0.0, (float) $prod['prix'] - $opts['ref']); ?>
                                                        <label class="option-row">
                                                            <span class="option-radio">
                                                                <input type="radio" name="<?= h($groupe) ?>"
                                                                       value="<?= (int) $prod['id'] ?>"
                                                                       <?= $selPiece === (int) $prod['id'] ? 'checked' : '' ?>
                                                                       <?= $modifiable ? '' : 'disabled' ?>>
                                                            </span>
                                                            <span class="option-label">
                                                                <?= h($prod['nom']) ?>
                                                                <span class="option-note">réf. <?= h($prod['reference']) ?> · formule <?= h($prod['formule_nom']) ?></span>
                                                            </span>
                                                            <span class="option-supp">+ <?= euros($supp) ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </details>
                                        <?php endif; ?>

                                        <?php if ($modifiable): ?>
                                            <label class="option-vider">
                                                <input type="radio" name="<?= h($groupe) ?>" value="0"
                                                       <?= $selPiece === 0 ? 'checked' : '' ?>>
                                                Ne pas choisir
                                            </label>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <aside class="recap">
            <div class="recap-eyebrow">Récapitulatif</div>
            <h3><?= h($config['plan_nom']) ?></h3>
            <div class="recap-row"><span>Formule</span><span class="recap-count"><?= h($config['formule_nom']) ?></span></div>
            <div class="recap-row"><span>Prix de base</span><span class="recap-count"><?= euros($prixBase) ?></span></div>
            <div class="recap-row"><span>Options choisies</span><span class="recap-count"><?= $nbChoix ?></span></div>
            <div class="recap-row"><span>Suppléments</span><span class="recap-count"><?= euros(max(0, $config['prix_total'] - $prixBase)) ?></span></div>
            <div class="recap-row recap-total"><span>Total estimé</span><span class="recap-count"><?= euros($config['prix_total']) ?></span></div>

            <?php if ($modifiable): ?>
                <button type="submit" name="action" value="save" class="btn-line btn-full">Enregistrer</button>
                <button type="submit" name="action" value="valider" class="btn-envoyer"
                        onclick="return confirm('Valider et transmettre votre configuration à l\'atelier ? Elle ne sera plus modifiable.');">
                    Valider ma configuration
                </button>
            <?php elseif ($config['statut'] === 'validee'): ?>
                <a class="btn-envoyer" href="<?= h($base) ?>/client/documents.php?config=<?= $configId ?>">
                    Documents techniques
                </a>
            <?php endif; ?>
            <a class="back-link" href="<?= h($base) ?>/client/index.php">← Mes projets</a>
        </aside>
    </form>
</div>
<?php layout_fin();
