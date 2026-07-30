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

/* --- Sections Couleur / Dimensions (propres à UNE pièce) + Caractéristiques --- */
function variantes_html(array $prod, int $catId, int $pieceId, int $pid, bool $avecCaracs = true): string
{
    $couleurs = array_filter(array_map('trim', explode(',', (string) ($prod['couleurs'] ?? ''))));
    $dims = array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($prod['dimensions'] ?? ''))));
    $caracs = $avecCaracs ? produit_caracteristiques($pid) : [];
    if (!$couleurs && !$dims && !$caracs) {
        return '';
    }
    $meta = $GLOBALS['selMeta'][$catId][$pieceId] ?? [];
    $defCoul = ($meta['couleur'] ?? '') ?: ($couleurs ? reset($couleurs) : '');
    $defDim = ($meta['dimension'] ?? '') ?: ($dims ? reset($dims) : '');
    $d = 'data-cat="' . $catId . '" data-piece="' . $pieceId . '" data-product="' . $pid . '"';

    ob_start(); ?>
    <input type="hidden" class="coul-hidden" data-cat="<?= $catId ?>" data-piece="<?= $pieceId ?>" data-product="<?= $pid ?>" value="<?= h($defCoul) ?>">
    <input type="hidden" class="dim-hidden" data-cat="<?= $catId ?>" data-piece="<?= $pieceId ?>" data-product="<?= $pid ?>" value="<?= h($defDim) ?>">
    <?php if ($couleurs): ?>
        <div class="vm-titre">Couleur</div>
        <div class="vm-couleurs">
            <?php foreach ($couleurs as $c): ?>
                <span class="vm-couleur<?= $c === $defCoul ? ' actif' : '' ?>" <?= $d ?>
                      data-val="<?= h($c) ?>" title="<?= h(ucfirst($c)) ?>" style="background:<?= h(couleur_css($c)) ?>"></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($dims): ?>
        <div class="vm-titre">Dimensions</div>
        <div class="vm-dims">
            <?php foreach ($dims as $dd): ?>
                <button type="button" class="vm-dim<?= $dd === $defDim ? ' actif' : '' ?>" <?= $d ?> data-val="<?= h($dd) ?>"><?= h($dd) ?></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($caracs): ?>
        <div class="vm-titre">Caractéristiques</div>
        <ul class="vm-caracs">
            <?php foreach ($caracs as $c): ?><li><strong><?= h($c['nom']) ?></strong> : <?= h($c['valeur']) ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php
    return ob_get_clean();
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
        <div class="piece-menu" hidden role="dialog" aria-modal="true">
            <button type="button" class="piece-menu-close" aria-label="Fermer">&times;</button>
            <div class="piece-menu-titre"><?= h($prod['nom']) ?></div>
            <p class="piece-menu-desc">Choisissez les pièces où vous désirez mettre ce produit.</p>
            <?php $caracs = produit_caracteristiques($pid); if ($caracs): ?>
                <div class="vm-titre">Caractéristiques</div>
                <ul class="vm-caracs">
                    <?php foreach ($caracs as $c): ?><li><strong><?= h($c['nom']) ?></strong> : <?= h($c['valeur']) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="piece-menu-head">
                <span>Pièces de la villa</span>
                <button type="button" class="btn-tout" data-cat="<?= $catId ?>" data-product="<?= $pid ?>">Tout</button>
            </div>
            <?php
            $aCouleurs = trim((string) ($prod['couleurs'] ?? '')) !== '';
            $aDims = trim((string) ($prod['dimensions'] ?? '')) !== '';
            if ($aCouleurs || $aDims): ?>
                <p class="piece-menu-aide">
                    Cochez une pièce pour choisir
                    <?= $aCouleurs && $aDims ? 'son coloris et ses dimensions'
                        : ($aCouleurs ? 'son coloris' : 'ses dimensions') ?>.
                </p>
            <?php endif; ?>
            <div class="etage-accordion">
                <?php foreach ($etages as $etNom => $piecesEt): ?>
                    <div class="etage-item">
                        <button type="button" class="etage-head"><?= h($etNom) ?><span class="chev">⌄</span></button>
                        <div class="etage-pieces" hidden>
                            <?php foreach ($piecesEt as $pc): $pcid = (int) $pc['id']; ?>
                                <div class="piece-row">
                                    <label class="piece-check">
                                        <input type="checkbox" class="assign-check" data-cat="<?= $catId ?>" data-piece="<?= $pcid ?>" value="<?= $pid ?>" <?= ((int) ($selCat[$pcid] ?? 0) === $pid) ? 'checked' : '' ?>>
                                        <span class="piece-check-txt"><?= h($pc['nom']) ?><?php if (!empty($pc['description'])): ?><small><?= h($pc['description']) ?></small><?php endif; ?></span>
                                    </label>
                                    <?php $vh = variantes_html($prod, $catId, $pcid, $pid, false); if ($vh !== ''): ?>
                                        <div class="piece-variantes"><?= $vh ?></div>
                                    <?php endif; ?>
                                </div>
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
    $vh = $modifiable ? variantes_html($prod, (int) $dCat, (int) $dPiece, $pid) : '';
    $avecModale = ($vh !== '');
    $tag = '<span class="produit-tag' . ($ch['upgrade'] ? ' supp' : '') . '">'
         . ($ch['upgrade'] ? '+ ' . euros($ch['supp']) : euros($prod['prix'])) . '</span>';
    $imgStyle = $img ? ' style="background-image:url(\'' . h($img) . '\')"' : '';
    $imgCls = $img ? '' : ' produit-img-vide';

    ob_start();
    if (!$avecModale): /* --- produit sans variante : sélection directe au clic --- */ ?>
        <label class="produit-card produit-choix">
            <input type="radio" class="produit-radio" name="<?= h($groupe) ?>" value="<?= $pid ?>"
                   data-cat="<?= $dCat ?>" data-piece="<?= $dPiece ?>"
                   <?= $selPiece === $pid ? 'checked' : '' ?> <?= $modifiable ? '' : 'disabled' ?>>
            <div class="produit-img<?= $imgCls ?>"<?= $imgStyle ?>><?= $tag ?><span class="produit-check">✓</span></div>
            <div class="produit-body">
                <div class="produit-nom"><?= h($prod['nom']) ?></div>
                <?php if (!empty($prod['description'])): ?><div class="produit-desc"><?= h($prod['description']) ?></div><?php endif; ?>
                <div class="produit-actions">
                    <span class="btn-choisir-p">Choisir</span>
                    <a class="btn-voir-p" href="<?= h($urlVoir) ?>" onclick="event.stopPropagation()">Voir +</a>
                </div>
            </div>
        </label>
    <?php else: /* --- produit avec coloris/caractéristiques : choix dans une modale --- */ ?>
        <div class="produit-card produit-choix produit-choix-modal" role="button" tabindex="0">
            <input type="radio" class="produit-radio" name="<?= h($groupe) ?>" value="<?= $pid ?>"
                   data-cat="<?= $dCat ?>" data-piece="<?= $dPiece ?>" <?= $selPiece === $pid ? 'checked' : '' ?> hidden>
            <div class="produit-img<?= $imgCls ?>"<?= $imgStyle ?>><?= $tag ?><span class="produit-check">✓</span></div>
            <div class="produit-body">
                <div class="produit-nom"><?= h($prod['nom']) ?></div>
                <?php if (!empty($prod['description'])): ?><div class="produit-desc"><?= h($prod['description']) ?></div><?php endif; ?>
                <div class="produit-actions">
                    <span class="btn-choisir-p">Choisir</span>
                    <a class="btn-voir-p" href="<?= h($urlVoir) ?>" onclick="event.stopPropagation()">Voir +</a>
                </div>
            </div>
            <div class="piece-menu" hidden role="dialog" aria-modal="true">
                <button type="button" class="piece-menu-close" aria-label="Fermer">&times;</button>
                <div class="piece-menu-titre"><?= h($prod['nom']) ?></div>
                <p class="piece-menu-desc">Choisissez le coloris et les caractéristiques de ce produit.</p>
                <?= $vh ?>
                <button type="button" class="btn-envoyer btn-choisir-global" style="width:auto;">Choisir ce produit</button>
            </div>
        </div>
    <?php endif;
    return ob_get_clean();
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
            <div class="upgrade-titre">Options de la collection <?= h($nomFormuleSup) ?></div>
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
        $couleur = trim((string) ($_POST['couleur'] ?? ''));
        $dimension = trim((string) ($_POST['dimension'] ?? ''));
        $opts = options_categorie($prodStmt, $cat, $niveauChoisi);
        if ($produit > 0 && isset($opts['autorises'][$produit])) {
            $prix = $opts['autorises'][$produit];
            $supp = max(0.0, $prix - $opts['ref']);
            db()->prepare(
                'INSERT INTO configuration_produits
                    (configuration_id, categorie_produit_id, piece_id, produit_id, couleur, dimension, prix_applique, supplement)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE produit_id = VALUES(produit_id),
                    couleur = VALUES(couleur), dimension = VALUES(dimension),
                    prix_applique = VALUES(prix_applique), supplement = VALUES(supplement)'
            )->execute([$configId, $cat, $piece, $produit, ($couleur ?: null), ($dimension ?: null), $prix, $supp]);
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

// Couleur / dimension déjà retenues PAR PIÈCE (pour pré-sélection).
$GLOBALS['selMeta'] = [];
$vst = db()->prepare('SELECT categorie_produit_id, piece_id, couleur, dimension FROM configuration_produits WHERE configuration_id = ?');
$vst->execute([$configId]);
foreach ($vst as $vr) {
    $GLOBALS['selMeta'][(int) $vr['categorie_produit_id']][(int) $vr['piece_id']] =
        ['couleur' => $vr['couleur'], 'dimension' => $vr['dimension']];
}

layout_client_debut('Configuration — ' . $config['plan_nom']);
?>
<section class="banner niveau-<?= $niveauChoisi ?>">
    <div class="banner-sheen"></div>
    <div class="banner-overlay wrap">
        <div class="formule-badge"><span class="formule-badge-ic">✓</span> <?= h($config['plan_nom']) ?> · <?= h(ucfirst(str_replace('_', ' ', $config['statut']))) ?></div>
        <h1>Vous avez choisi la collection <em><?= h($config['formule_nom']) ?></em></h1>
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

            <?php $totalGc = count($grandesCats); ?>
            <?php if ($totalGc > 1): ?>
            <div class="gc-tabs" role="tablist">
                <?php foreach ($grandesCats as $i => $gc): ?>
                    <button type="button" class="gc-tab<?= $i === 0 ? ' actif' : '' ?>" data-gc="<?= $i ?>" role="tab">
                        <span class="gc-tab-num"><?= $i + 1 ?></span><?= h($gc['nom']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="gc-panels">
            <?php foreach ($grandesCats as $i => $gc):
                $catStmt->execute([(int) $gc['id']]);
                $cats = $catStmt->fetchAll(); ?>
                <section class="gc-panel<?= $i === 0 ? ' actif' : '' ?>" data-gc="<?= $i ?>">
                <div class="grande-cat">
                    <div class="grande-cat-head">
                        <span class="category-tag">Étape <?= $i + 1 ?> / <?= $totalGc ?></span>
                        <h2><?= h($gc['nom']) ?></h2>
                        <?php if (!empty($gc['description'])): ?>
                            <p class="gc-desc"><?= h($gc['description']) ?></p>
                        <?php endif; ?>
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
                                <p class="vide">Aucun produit disponible pour cette collection.</p>

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
                </div><!-- .grande-cat -->
                <?php if ($i < $totalGc - 1): ?>
                    <div class="gc-suivant-zone">
                        <button type="button" class="gc-suivant" data-next="<?= $i + 1 ?>">
                            Suivant : <?= h($grandesCats[$i + 1]['nom']) ?> →
                        </button>
                    </div>
                <?php endif; ?>
                </section><!-- .gc-panel -->
            <?php endforeach; ?>
            </div><!-- .gc-panels -->
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

<div class="modal-backdrop" hidden></div>

<script>
(function () {
    var CSRF = (document.querySelector('input[name=_csrf]') || {}).value || '';
    var CFG = new URLSearchParams(location.search).get('config') || '';
    var note = document.getElementById('autosave-note');

    // Reprise de la position de défilement : on mémorise où le client a scrollé
    // avant d'ouvrir une fiche produit, et on y revient quand il fait « Retour ».
    var scrollKey = 'cfgScroll_' + CFG;
    window.addEventListener('pagehide', function () {
        try { sessionStorage.setItem(scrollKey, String(window.scrollY)); } catch (e) {}
    });
    if (document.referrer.indexOf('produit.php') >= 0) {
        var yPrec = parseInt(sessionStorage.getItem(scrollKey) || '0', 10);
        if (yPrec > 0) {
            // La hauteur de la page peut grandir après coup (polices, reflow) : on
            // replace le défilement à chaque frame tant que la cible n'est pas
            // atteinte, pendant 1,5 s maximum.
            var limite = Date.now() + 1500;
            (function replacer() {
                window.scrollTo(0, yPrec);
                if (Math.abs(window.scrollY - yPrec) > 2 && Date.now() < limite) {
                    requestAnimationFrame(replacer);
                }
            })();
        }
    }

    // File d'attente : une seule requête d'enregistrement en vol à la fois
    // (évite de saturer le serveur et les conflits d'écriture quand « Tout »
    // affecte plusieurs pièces d'un coup).
    var queue = [], enVol = false;
    function traiterFile() {
        if (enVol || !queue.length) { return; }
        enVol = true;
        var job = queue.shift();
        fetch('config.php?config=' + encodeURIComponent(CFG), {
            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: job.toString(), credentials: 'same-origin'
        }).then(function (r) { return r.text(); }).then(function (txt) {
            var d;
            try { d = JSON.parse(txt); } catch (e) { d = null; }
            if (!d || !d.ok) {
                if (note) {
                    note.textContent = '⚠ Échec de l\'enregistrement' + (d && d.error ? ' : ' + d.error : ' (session ou serveur)');
                    note.classList.add('erreur');
                }
            } else {
                var t = document.getElementById('recap-total'); if (t) { t.textContent = d.total; }
                var s = document.getElementById('recap-supp'); if (s) { s.textContent = d.supplements; }
                var n = document.getElementById('recap-nb'); if (n) { n.textContent = d.nb; }
                if (note) {
                    note.classList.remove('erreur');
                    note.textContent = '✓ Vos choix sont enregistrés automatiquement';
                    note.classList.add('flash-on'); setTimeout(function () { note.classList.remove('flash-on'); }, 1200);
                }
            }
        }).catch(function () {
            if (note) { note.textContent = '⚠ Échec de l\'enregistrement (réseau)'; note.classList.add('erreur'); }
        }).then(function () { enVol = false; traiterFile(); });
    }

    // Sauvegarde automatique d'un choix (AJAX, mise en file).
    function autosave(cat, piece, produit) {
        var sel = '[data-cat="' + cat + '"][data-piece="' + piece + '"][data-product="' + produit + '"]';
        var cEl = document.querySelector('.coul-hidden' + sel);
        var dEl = document.querySelector('.dim-hidden' + sel);
        var body = new URLSearchParams();
        body.set('_csrf', CSRF); body.set('action', 'ajax_set');
        body.set('cat', cat); body.set('piece', piece); body.set('produit', produit);
        body.set('couleur', cEl ? cEl.value : ''); body.set('dimension', dEl ? dEl.value : '');
        queue.push(body);
        traiterFile();
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
    var backdrop = document.querySelector('.modal-backdrop');
    // Ferme la fenêtre (modale) de choix des pièces + l'overlay.
    function closeMenus() {
        document.querySelectorAll('.piece-menu').forEach(function (m) { m.classList.remove('ouvert'); m.hidden = true; });
        if (backdrop) { backdrop.hidden = true; }
        document.body.classList.remove('modal-ouvert');
    }
    // Ouvre la modale d'une carte produit au centre de l'écran, sur fond assombri.
    function openMenu(menu) {
        closeMenus();
        if (backdrop) { backdrop.hidden = false; }
        menu.hidden = false; menu.classList.add('ouvert');
        document.body.classList.add('modal-ouvert');
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
    // Clic sur la carte produit (ou son bouton « Choisir ») -> ouvre la modale de choix.
    document.querySelectorAll('.produit-assign, .produit-choix-modal').forEach(function (card) {
        card.addEventListener('click', function (e) {
            if (e.target.closest('.piece-menu') || e.target.closest('.btn-voir-p')) { return; }
            e.stopPropagation();
            openMenu(card.querySelector('.piece-menu'));
        });
    });
    // « Toute la villa » : bouton « Choisir ce produit » dans la modale -> coche
    // le produit (radio) et enregistre, puis ferme.
    document.querySelectorAll('.btn-choisir-global').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var card = btn.closest('.produit-choix-modal');
            var radio = card.querySelector('.produit-radio');
            if (radio && !radio.checked) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
            closeMenus();
        });
    });
    // Bouton de fermeture de la modale.
    document.querySelectorAll('.piece-menu-close').forEach(function (b) {
        b.addEventListener('click', function (e) { e.stopPropagation(); closeMenus(); });
    });
    // Clic sur l'overlay -> ferme.
    if (backdrop) { backdrop.addEventListener('click', closeMenus); }
    // Touche Échap -> ferme.
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeMenus(); } });
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
    // Cette pièce est-elle actuellement affectée à ce produit ?
    function pieceAffectee(cat, piece, prod) {
        var h = document.querySelector('.sel-hidden[data-cat="' + cat + '"][data-piece="' + piece + '"]');
        if (h && h.value === String(prod)) { return true; }
        var r = document.querySelector('.produit-radio[data-cat="' + cat + '"][data-piece="' + piece + '"][value="' + prod + '"]:checked');
        return !!r;
    }
    // Sélection d'une couleur (pastille) — propre à une pièce.
    document.querySelectorAll('.vm-couleur').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.stopPropagation(); e.preventDefault();
            var cat = el.getAttribute('data-cat'), piece = el.getAttribute('data-piece'),
                prod = el.getAttribute('data-product'), val = el.getAttribute('data-val');
            var hid = document.querySelector('.coul-hidden[data-cat="' + cat + '"][data-piece="' + piece + '"][data-product="' + prod + '"]');
            if (hid) { hid.value = val; }
            el.parentElement.querySelectorAll('.vm-couleur').forEach(function (o) { o.classList.remove('actif'); });
            el.classList.add('actif');
            if (pieceAffectee(cat, piece, prod)) { autosave(cat, piece, prod); }
        });
    });
    // Sélection d'une dimension — propre à une pièce.
    document.querySelectorAll('.vm-dim').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.stopPropagation(); e.preventDefault();
            var cat = el.getAttribute('data-cat'), piece = el.getAttribute('data-piece'),
                prod = el.getAttribute('data-product'), val = el.getAttribute('data-val');
            var hid = document.querySelector('.dim-hidden[data-cat="' + cat + '"][data-piece="' + piece + '"][data-product="' + prod + '"]');
            if (hid) { hid.value = val; }
            el.parentElement.querySelectorAll('.vm-dim').forEach(function (o) { o.classList.remove('actif'); });
            el.classList.add('actif');
            if (pieceAffectee(cat, piece, prod)) { autosave(cat, piece, prod); }
        });
    });
    // Bouton « Tout » : affecte le produit à toutes les pièces de la catégorie.
    document.querySelectorAll('.btn-tout').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var cat = btn.getAttribute('data-cat'), prod = btn.getAttribute('data-product');
            var toutCoche = btn.classList.contains('actif');
            document.querySelectorAll('.assign-check[data-cat="' + cat + '"][value="' + prod + '"]').forEach(function (chk) {
                if (chk.checked === toutCoche) {
                    chk.checked = !toutCoche;
                    chk.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            btn.classList.toggle('actif');
            btn.textContent = btn.classList.contains('actif') ? 'Aucune' : 'Tout';
        });
    });
    // Clic à l'extérieur : on referme les menus.
    document.querySelectorAll('.piece-menu').forEach(function (m) {
        m.addEventListener('click', function (e) { e.stopPropagation(); });
    });
    document.addEventListener('click', closeMenus);

    // --- Menu horizontal des grandes catégories (Gros œuvres / Œuvres secondaires) ---
    // Bascule d'un panneau à l'autre avec une animation fondu + mouvement.
    function activerGc(idx) {
        idx = String(idx);
        var cible = document.querySelector('.gc-panel[data-gc="' + idx + '"]');
        var courant = document.querySelector('.gc-panel.actif');
        if (!cible || cible === courant) { return; }
        document.querySelectorAll('.gc-tab').forEach(function (t) {
            t.classList.toggle('actif', t.getAttribute('data-gc') === idx);
        });
        var afficher = function () { cible.classList.add('actif'); };
        if (courant) {
            courant.classList.remove('actif');
            courant.classList.add('sortie');
            setTimeout(function () { courant.classList.remove('sortie'); afficher(); }, 230);
        } else {
            afficher();
        }
        // On remonte en haut du menu des étapes.
        var repere = document.querySelector('.gc-tabs') || cible;
        var y = repere.getBoundingClientRect().top + window.scrollY - 90;
        window.scrollTo({ top: y < 0 ? 0 : y, behavior: 'smooth' });
    }
    document.querySelectorAll('.gc-tab').forEach(function (t) {
        t.addEventListener('click', function () { activerGc(t.getAttribute('data-gc')); });
    });
    document.querySelectorAll('.gc-suivant').forEach(function (b) {
        b.addEventListener('click', function () { activerGc(b.getAttribute('data-next')); });
    });

    // --- Accordéon des catégories (mobile) : le titre ouvre/ferme le contenu. ---
    function estMobile() { return window.matchMedia('(max-width: 760px)').matches; }
    document.querySelectorAll('.category-head').forEach(function (head) {
        head.addEventListener('click', function () {
            if (estMobile()) { head.parentElement.classList.toggle('ouvert'); }
        });
    });

    // Compteurs initiaux.
    document.querySelectorAll('.produit-liste.piece-first').forEach(function (l) {
        refresh(l.getAttribute('data-cat'));
    });
})();
</script>
<?php layout_fin();
