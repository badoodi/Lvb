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
     WHERE c.id = ? AND c.client_id = ? AND c.statut <> \'annule_client\''
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

// Auto-réparation : tout plan doit posséder une pièce « globale » (choix « toute
// la villa »). Sans elle, les produits globaux tenteraient d'utiliser piece_id=0
// -> violation de clé étrangère. On la crée si elle manque.
if (!$pieceGlobale) {
    db()->prepare(
        "INSERT INTO pieces_plan (plan_id, nom, type_piece, ordre_affichage)
         VALUES (?, 'Toute la villa', 'globale', 0)"
    )->execute([(int) $config['plan_id']]);
    $gid = (int) db()->lastInsertId();
    $pieceGlobale = ['id' => $gid, 'nom' => 'Toute la villa', 'type_piece' => 'globale',
                     'etage' => null, 'description' => null];
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

/* --- Rendu d'une carte produit (catégorie « par pièce ») --- */
function carte_assign(array $ch, int $catId, array $etages, array $selCat): string
{
    global $configId;
    $prod = $ch['prod'];
    $pid = (int) $prod['id'];
    $img = !empty($prod['image']) ? (base_url() . '/' . ltrim($prod['image'], '/')) : '';
    $urlVoir = base_url() . '/client/produit.php?produit=' . $pid . '&config=' . (int) $configId;
    ob_start(); ?>
    <div class="produit-card produit-assign" data-cat="<?= $catId ?>" data-product="<?= $pid ?>" role="button" tabindex="0">
        <div class="produit-img<?= $img ? '' : ' produit-img-vide' ?>"<?= $img ? ' style="background-image:url(\'' . h($img) . '\')"' : '' ?>>
            <span class="produit-tag<?= $ch['upgrade'] ? ' supp' : '' ?>"><?= $ch['upgrade'] ? '+ ' . euros($ch['supp']) : euros($prod['prix']) ?></span>
            <span class="produit-count" hidden></span>
        </div>
        <div class="produit-body">
            <div class="produit-nom"><?= h($prod['nom']) ?></div>
            <?php if (!empty($prod['description'])): ?><div class="produit-desc"><?= h($prod['description']) ?></div><?php endif; ?>
            <div class="produit-actions">
                <span class="btn-choisir-p">Choisir</span>
                <a class="btn-voir-p" href="<?= h($urlVoir) ?>" onclick="event.stopPropagation()">Voir +</a>
            </div>
        </div>
        <div class="piece-menu" hidden>
            <div class="piece-menu-head">Affecter « <?= h($prod['nom']) ?> » à&nbsp;:</div>
            <div class="etage-accordion">
                <?php foreach ($etages as $etNom => $piecesEt): ?>
                    <div class="etage-item">
                        <button type="button" class="etage-head"><?= h($etNom) ?><span class="chev">＋</span></button>
                        <div class="etage-pieces" hidden>
                            <?php foreach ($piecesEt as $pc): $pcid = (int) $pc['id']; ?>
                                <label class="piece-check">
                                    <input type="checkbox" class="assign-check" data-cat="<?= $catId ?>" data-piece="<?= $pcid ?>" value="<?= $pid ?>" <?= ((int) ($selCat[$pcid] ?? 0) === $pid) ? 'checked' : '' ?>>
                                    <span class="piece-check-txt"><?= h($pc['nom']) ?><?php if (!empty($pc['description'])): ?><small><?= h($pc['description']) ?></small><?php endif; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
}

/* --- Rendu d'une carte produit (catégorie « toute la villa », choix radio) --- */
function carte_choix(array $ch, string $groupe, int $selPiece, bool $modifiable): string
{
    global $configId;
    $prod = $ch['prod'];
    $pid = (int) $prod['id'];
    $img = !empty($prod['image']) ? (base_url() . '/' . ltrim($prod['image'], '/')) : '';
    $urlVoir = base_url() . '/client/produit.php?produit=' . $pid . '&config=' . (int) $configId;
    preg_match('/sel\[(\d+)\]\[(\d+)\]/', $groupe, $mgp);
    $dCat = $mgp[1] ?? 0; $dPiece = $mgp[2] ?? 0;
    ob_start(); ?>
    <label class="produit-card produit-choix">
        <input type="radio" class="produit-radio" name="<?= h($groupe) ?>" value="<?= $pid ?>"
               data-cat="<?= $dCat ?>" data-piece="<?= $dPiece ?>"
               <?= $selPiece === $pid ? 'checked' : '' ?> <?= $modifiable ? '' : 'disabled' ?>>
        <div class="produit-img<?= $img ? '' : ' produit-img-vide' ?>"<?= $img ? ' style="background-image:url(\'' . h($img) . '\')"' : '' ?>>
            <span class="produit-tag<?= $ch['upgrade'] ? ' supp' : '' ?>"><?= $ch['upgrade'] ? '+ ' . euros($ch['supp']) : euros($prod['prix']) ?></span>
            <span class="produit-check">✓</span>
        </div>
        <div class="produit-body">
            <div class="produit-nom"><?= h($prod['nom']) ?></div>
            <?php if (!empty($prod['description'])): ?><div class="produit-desc"><?= h($prod['description']) ?></div><?php endif; ?>
            <div class="produit-actions">
                <span class="btn-choisir-p">Choisir</span>
                <a class="btn-voir-p" href="<?= h($urlVoir) ?>" onclick="event.stopPropagation()">Voir +</a>
            </div>
        </div>
    </label>
    <?php return ob_get_clean();
}

/* --- Rendu du bloc « Voir d'autres options » (produits de la formule supérieure) --- */
function bloc_upgrades(string $cartesHtml, string $nomFormuleSup, bool $ouvert): string
{
    ob_start(); ?>
    <div class="upgrade-zone">
        <button type="button" class="voir-options<?= $ouvert ? ' actif' : '' ?>">
            <span class="vo-plus">＋ Voir d'autres options</span><span class="vo-moins">－ Masquer les options</span>
        </button>
        <div class="upgrade-panel"<?= $ouvert ? '' : ' hidden' ?>>
            <div class="upgrade-titre">Options de la formule <?= h($nomFormuleSup) ?></div>
            <div class="produit-liste"><?= $cartesHtml ?></div>
        </div>
    </div>
    <?php return ob_get_clean();
}

/* =====================================================================
 * TRAITEMENT POST (enregistrer / valider)
 * ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $estAjax = ($action === 'ajax_set');

    // Configuration non modifiable (déjà validée / annulée) : on le dit clairement.
    if (!$modifiable) {
        if ($estAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Configuration non modifiable (statut : ' . $config['statut'] . ')']);
            exit;
        }
        flash('Cette configuration n\'est plus modifiable (statut : ' . $config['statut'] . ').', 'erreur');
        redirect($base . '/client/config.php?config=' . $configId);
    }

    csrf_verifier();

    // --- Sauvegarde automatique d'un seul choix (AJAX) ---
    if ($action === 'ajax_set') {
        header('Content-Type: application/json; charset=utf-8');
        try {
        $cat = (int) ($_POST['cat'] ?? 0);
        $piece = (int) ($_POST['piece'] ?? 0);
        $produit = (int) ($_POST['produit'] ?? 0);
        $opts = options_categorie($prodStmt, $cat, $niveauChoisi);
        if ($produit > 0 && isset($opts['autorises'][$produit])) {
            $prix = $opts['autorises'][$produit];
            $supp = max(0.0, $prix - $opts['ref']);
            db()->prepare(
                'INSERT INTO configuration_produits
                    (configuration_id, categorie_produit_id, piece_id, produit_id, prix_applique, supplement)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE produit_id = VALUES(produit_id),
                    prix_applique = VALUES(prix_applique), supplement = VALUES(supplement)'
            )->execute([$configId, $cat, $piece, $produit, $prix, $supp]);
        } else {
            db()->prepare(
                'DELETE FROM configuration_produits WHERE configuration_id = ? AND categorie_produit_id = ? AND piece_id = ?'
            )->execute([$configId, $cat, $piece]);
        }
        $s = db()->prepare('SELECT COALESCE(SUM(supplement),0), COUNT(*) FROM configuration_produits WHERE configuration_id = ?');
        $s->execute([$configId]);
        [$totalSupp, $nb] = $s->fetch(PDO::FETCH_NUM);
        $total = $prixBase + (float) $totalSupp;
        db()->prepare('UPDATE configurations SET prix_total = ? WHERE id = ?')->execute([$total, $configId]);
        } catch (Throwable $e) {
            error_log('[LVB] ajax_set config #' . $configId . ' : ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Erreur base de données : ' . $e->getMessage()]);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'total' => euros($total),
            'supplements' => euros((float) $totalSupp),
            'nb' => (int) $nb,
        ]);
        exit;
    }

    $selPostees = $_POST['sel'] ?? [];

    $pdo = db();
    try {
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
        // Récapitulatif PDF + notification email à l'admin (avec la pièce jointe).
        try {
            require_once __DIR__ . '/../lib/recap.php';
            require_once __DIR__ . '/../lib/mail.php';
            $pdf = generer_pdf_configuration($configId);
            notifier_admin_validation($configId, $pdf);
        } catch (Throwable $e) {
            error_log('[LVB] PDF/notif validation config #' . $configId . ' : ' . $e->getMessage());
        }
        flash('Votre configuration a été validée et transmise à l\'atelier. Vous pouvez télécharger votre récapitulatif (PDF).');
        redirect($base . '/client/index.php');
    } else {
        $maj = $pdo->prepare('UPDATE configurations SET prix_total = ? WHERE id = ?');
        $maj->execute([$prixTotal, $configId]);
        $pdo->commit();
        flash('Vos choix ont été enregistrés.');
        redirect($base . '/client/config.php?config=' . $configId);
    }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[LVB] save config #' . $configId . ' : ' . $e->getMessage());
        flash('Erreur lors de l\'enregistrement : ' . $e->getMessage(), 'erreur');
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
<section class="banner niveau-<?= $niveauChoisi ?>">
    <div class="banner-sheen"></div>
    <div class="banner-overlay wrap">
        <div class="formule-badge"><span class="formule-badge-ic">✓</span> <?= h($config['plan_nom']) ?> · <?= h(ucfirst(str_replace('_', ' ', $config['statut']))) ?></div>
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
                        $catId = (int) $cat['id'];
                        $estParPiece = (int) $cat['par_piece'] === 1;
                        // Produits sélectionnables : proposés (formule) + upgrades (formule supérieure).
                        $produitsChoix = [];
                        foreach ($opts['proposes'] as $p) {
                            $produitsChoix[] = ['prod' => $p, 'supp' => 0.0, 'upgrade' => false];
                        }
                        foreach ($opts['upgrades'] as $p) {
                            $produitsChoix[] = ['prod' => $p, 'supp' => max(0.0, (float) $p['prix'] - $opts['ref']), 'upgrade' => true];
                        }
                        $selCat = $selections[$catId] ?? [];
                        // Base = produits de la formule choisie ; options = formule supérieure (max 5, jamais inférieure).
                        $base = array_values(array_filter($produitsChoix, fn($c) => !$c['upgrade']));
                        $ups  = array_values(array_filter($produitsChoix, fn($c) => $c['upgrade']));
                        $nomFormuleSup = $ups ? $ups[0]['prod']['formule_nom'] : '';
                        $upIds = array_map(fn($c) => (int) $c['prod']['id'], $ups);
                        ?>
                        <div class="category">
                            <div class="category-head">
                                <span class="category-tag">Cat.</span>
                                <h2><?= h($cat['nom']) ?></h2>
                                <span class="category-sub"><?= $estParPiece ? 'choix par pièce' : 'toute la villa' ?></span>
                            </div>

                            <?php if (!$produitsChoix): ?>
                                <p class="vide">Aucun produit disponible pour cette formule.</p>

                            <?php elseif ($estParPiece): ?>
                                <?php
                                // Regroupement des pièces réelles par étage (ordre conservé).
                                $etages = [];
                                foreach ($piecesReelles as $pc) {
                                    $et = ($pc['etage'] !== null && $pc['etage'] !== '') ? $pc['etage'] : 'Autres pièces';
                                    $etages[$et][] = $pc;
                                }
                                ?>
                                <?php if ($modifiable): ?>
                                    <?php // Valeurs canoniques lues par le serveur : un produit par pièce. ?>
                                    <?php foreach ($piecesReelles as $pc): ?>
                                        <input type="hidden" class="sel-hidden" data-cat="<?= $catId ?>" data-piece="<?= (int) $pc['id'] ?>"
                                               name="sel[<?= $catId ?>][<?= (int) $pc['id'] ?>]" value="<?= (int) ($selCat[(int) $pc['id']] ?? 0) ?>">
                                    <?php endforeach; ?>

                                    <div class="produit-liste piece-first" data-cat="<?= $catId ?>">
                                        <?php foreach ($base as $ch) { echo carte_assign($ch, $catId, $etages, $selCat); } ?>
                                    </div>
                                    <?php if ($ups):
                                        // Ouvre le panneau si une pièce a déjà reçu un produit de la formule supérieure.
                                        $ouvert = (bool) array_intersect(array_map('intval', $selCat), $upIds);
                                        $cartes = '';
                                        foreach ($ups as $ch) { $cartes .= carte_assign($ch, $catId, $etages, $selCat); }
                                        echo bloc_upgrades($cartes, $nomFormuleSup, $ouvert);
                                    endif; ?>

                                <?php else: /* lecture seule : récap pièce -> produit */ ?>
                                    <ul class="assign-recap">
                                        <?php foreach ($piecesReelles as $pc):
                                            $pcid = (int) $pc['id']; $chosen = (int) ($selCat[$pcid] ?? 0);
                                            $nomProd = '— non choisi —';
                                            foreach ($produitsChoix as $ch) { if ((int) $ch['prod']['id'] === $chosen) { $nomProd = $ch['prod']['nom']; } }
                                            ?>
                                            <li><span><?= h($pc['nom']) ?></span><span class="recap-count"><?= h($nomProd) ?></span></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>

                            <?php else:
                                // Catégorie « toute la villa » : un seul choix (pièce globale).
                                $pid = $pieceGlobale ? (int) $pieceGlobale['id'] : 0;
                                $groupe = 'sel[' . $catId . '][' . $pid . ']';
                                $selPiece = $selections[$catId][$pid] ?? 0;
                                ?>
                                <div class="produit-liste" data-cat="<?= $catId ?>">
                                    <?php foreach ($base as $ch) { echo carte_choix($ch, $groupe, $selPiece, $modifiable); } ?>
                                    <?php if ($modifiable): ?>
                                        <label class="produit-card produit-vide">
                                            <input type="radio" class="produit-radio" name="<?= h($groupe) ?>" value="0"
                                                   data-cat="<?= $catId ?>" data-piece="<?= $pid ?>"
                                                   <?= $selPiece === 0 ? 'checked' : '' ?>>
                                            <div class="produit-vide-inner">Ne pas<br>choisir</div>
                                        </label>
                                    <?php endif; ?>
                                </div>
                                <?php if ($ups):
                                    $ouvert = in_array($selPiece, $upIds, true);
                                    $cartes = '';
                                    foreach ($ups as $ch) { $cartes .= carte_choix($ch, $groupe, $selPiece, $modifiable); }
                                    echo bloc_upgrades($cartes, $nomFormuleSup, $ouvert);
                                endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <aside class="recap">
            <div class="recap-eyebrow">Récapitulatif</div>
            <h3><?= h($config['plan_nom']) ?></h3>
            <div class="recap-row"><span>Collection</span><span class="recap-count"><?= h($config['formule_nom']) ?></span></div>
            <div class="recap-row"><span>Prix de base</span><span class="recap-count"><?= euros($prixBase) ?></span></div>
            <div class="recap-row"><span>Options choisies</span><span class="recap-count" id="recap-nb"><?= $nbChoix ?></span></div>
            <div class="recap-row"><span>Suppléments</span><span class="recap-count" id="recap-supp"><?= euros(max(0, $config['prix_total'] - $prixBase)) ?></span></div>
            <div class="recap-row recap-total"><span>Total estimé</span><span class="recap-count" id="recap-total"><?= euros($config['prix_total']) ?></span></div>
            <?php if ($modifiable): ?>
                <div class="autosave-note" id="autosave-note">✓ Vos choix sont enregistrés automatiquement</div>
            <?php endif; ?>

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
            <a class="back-link" href="index.php">← Mes projets</a>
        </aside>
    </form>
</div>

<script>
(function () {
    var CSRF = (document.querySelector('input[name=_csrf]') || {}).value || '';
    var CFG = new URLSearchParams(location.search).get('config') || '';
    var note = document.getElementById('autosave-note');

    // Sauvegarde automatique d'un choix (AJAX).
    function autosave(cat, piece, produit) {
        var body = new URLSearchParams();
        body.set('_csrf', CSRF); body.set('action', 'ajax_set');
        body.set('cat', cat); body.set('piece', piece); body.set('produit', produit);
        fetch('config.php?config=' + encodeURIComponent(CFG), {
            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString(), credentials: 'same-origin'
        }).then(function (r) { return r.text(); }).then(function (txt) {
            var d;
            try { d = JSON.parse(txt); } catch (e) { d = null; }
            if (!d || !d.ok) {
                if (note) {
                    note.textContent = '⚠ Échec de l\'enregistrement' + (d && d.error ? ' : ' + d.error : ' (session ou serveur)');
                    note.classList.add('erreur');
                }
                return;
            }
            var t = document.getElementById('recap-total'); if (t) { t.textContent = d.total; }
            var s = document.getElementById('recap-supp'); if (s) { s.textContent = d.supplements; }
            var n = document.getElementById('recap-nb'); if (n) { n.textContent = d.nb; }
            if (note) {
                note.classList.remove('erreur');
                note.textContent = '✓ Vos choix sont enregistrés automatiquement';
                note.classList.add('flash-on'); setTimeout(function () { note.classList.remove('flash-on'); }, 1200);
            }
        }).catch(function () {
            if (note) { note.textContent = '⚠ Échec de l\'enregistrement (réseau)'; note.classList.add('erreur'); }
        });
    }
    window.__autosave = autosave;

    // Compte le nombre de pièces affectées à chaque produit d'une catégorie.
    function refresh(cat) {
        var c = {};
        document.querySelectorAll('.sel-hidden[data-cat="' + cat + '"]').forEach(function (h) {
            if (h.value && h.value !== '0') { c[h.value] = (c[h.value] || 0) + 1; }
        });
        document.querySelectorAll('.produit-assign[data-cat="' + cat + '"]').forEach(function (card) {
            var n = c[card.getAttribute('data-product')] || 0;
            var span = card.querySelector('.produit-count');
            if (n) { span.hidden = false; span.textContent = n + ' pièce' + (n > 1 ? 's' : ''); }
            else { span.hidden = true; }
            card.classList.toggle('actif', n > 0);
        });
    }
    function closeMenus() {
        document.querySelectorAll('.piece-menu').forEach(function (m) { m.hidden = true; });
    }
    // Bouton « Voir d'autres options » -> révèle les produits de la formule supérieure.
    document.querySelectorAll('.voir-options').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var panel = btn.nextElementSibling;
            var show = panel.hidden;
            panel.hidden = !show;
            btn.classList.toggle('actif', show);
        });
    });
    // Clic n'importe où sur la carte produit -> ouvre/ferme son menu de pièces.
    document.querySelectorAll('.produit-assign').forEach(function (card) {
        card.addEventListener('click', function (e) {
            if (e.target.closest('.piece-menu')) { return; }
            e.stopPropagation();
            var menu = card.querySelector('.piece-menu');
            var open = menu.hidden;
            closeMenus();
            menu.hidden = !open;
        });
    });
    // Accordéon des étages : un seul étage ouvert à la fois.
    document.querySelectorAll('.etage-head').forEach(function (head) {
        head.addEventListener('click', function (e) {
            e.stopPropagation();
            var body = head.nextElementSibling;
            var acc = head.closest('.etage-accordion');
            var open = body.hidden;
            acc.querySelectorAll('.etage-pieces').forEach(function (b) { b.hidden = true; });
            acc.querySelectorAll('.etage-head').forEach(function (h) { h.classList.remove('ouvert'); });
            if (open) { body.hidden = false; head.classList.add('ouvert'); }
        });
    });
    // Affectation d'un produit à une pièce (une seule affectation par pièce).
    document.querySelectorAll('.assign-check').forEach(function (chk) {
        chk.addEventListener('change', function () {
            var cat = chk.getAttribute('data-cat'), piece = chk.getAttribute('data-piece'), prod = chk.value;
            var hidden = document.querySelector('.sel-hidden[data-cat="' + cat + '"][data-piece="' + piece + '"]');
            if (chk.checked) {
                document.querySelectorAll('.assign-check[data-cat="' + cat + '"][data-piece="' + piece + '"]').forEach(function (o) {
                    if (o !== chk) { o.checked = false; }
                });
                hidden.value = prod;
            } else if (hidden.value === prod) {
                hidden.value = '0';
            }
            refresh(cat);
            autosave(cat, piece, hidden.value);   // sauvegarde immédiate
        });
    });
    // Choix « toute la villa » (radios) : sauvegarde immédiate au changement.
    document.querySelectorAll('.produit-radio').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (!radio.checked) { return; }
            autosave(radio.getAttribute('data-cat'), radio.getAttribute('data-piece'), radio.value);
        });
    });
    // Clic à l'extérieur : on referme les menus.
    document.querySelectorAll('.piece-menu').forEach(function (m) {
        m.addEventListener('click', function (e) { e.stopPropagation(); });
    });
    document.addEventListener('click', closeMenus);
    // Compteurs initiaux.
    document.querySelectorAll('.produit-liste.piece-first').forEach(function (l) {
        refresh(l.getAttribute('data-cat'));
    });
})();
</script>
<?php layout_fin();
