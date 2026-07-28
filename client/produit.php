<?php
/**
 * Espace client — fiche détaillée d'un produit (bouton « Voir + »).
 * Affiche image, description, coloris, dimensions, caractéristiques, et
 * permet — comme sur la page de configuration — de choisir le produit et
 * son coloris directement depuis cette page (enregistrement automatique).
 */
require_once __DIR__ . '/../lib/layout.php';
$client = exiger_client();
$base = base_url();

$produitId = (int) ($_GET['produit'] ?? 0);
$configId  = (int) ($_GET['config'] ?? 0);

$stmt = db()->prepare(
    'SELECT p.*, f.nom AS collection_nom, f.niveau AS collection_niveau,
            cat.nom AS categorie_nom, cat.par_piece
     FROM produits p
     JOIN formules f            ON f.id = p.formule_id
     JOIN categories_produits cat ON cat.id = p.categorie_produit_id
     WHERE p.id = ?'
);
$stmt->execute([$produitId]);
$prod = $stmt->fetch();
if (!$prod) {
    flash('Produit introuvable.', 'erreur');
    redirect($base . '/client/index.php');
}

$catId      = (int) $prod['categorie_produit_id'];
$parPiece   = (int) $prod['par_piece'] === 1;
$couleurs   = array_filter(array_map('trim', explode(',', (string) $prod['couleurs'])));
$dimensions = array_filter(array_map('trim', preg_split('/\r?\n/', (string) $prod['dimensions'])));
$caracs     = produit_caracteristiques($produitId);
$img = !empty($prod['image']) ? ($base . '/' . ltrim($prod['image'], '/')) : '';
$retour = $configId ? ($base . '/client/config.php?config=' . $configId) : ($base . '/client/index.php');

/* --- Contexte de configuration : permet le choix depuis cette fiche --- */
$config = null;
$modifiable = false;
$piecesReelles = [];
$pieceGlobale = null;
$selections = [];   // piece_id => ['couleur'=>, 'dimension'=>]

if ($configId) {
    $cst = db()->prepare(
        'SELECT c.*, f.niveau AS formule_niveau
         FROM configurations c
         JOIN formules f ON f.id = c.formule_id
         WHERE c.id = ? AND c.client_id = ? AND c.statut <> \'annule_client\''
    );
    $cst->execute([$configId, $client['id']]);
    $config = $cst->fetch();

    if ($config) {
        $modifiable = $config['statut'] === 'en_cours';

        $pst = db()->prepare('SELECT * FROM pieces_plan WHERE plan_id = ? ORDER BY ordre_affichage, id');
        $pst->execute([(int) $config['plan_id']]);
        foreach ($pst->fetchAll() as $pc) {
            if ($pc['type_piece'] === 'globale') {
                $pieceGlobale = $pc;
            } else {
                $piecesReelles[] = $pc;
            }
        }
        if (!$pieceGlobale) {
            db()->prepare(
                "INSERT INTO pieces_plan (plan_id, nom, type_piece, ordre_affichage)
                 VALUES (?, 'Toute la villa', 'globale', 0)"
            )->execute([(int) $config['plan_id']]);
            $pieceGlobale = ['id' => (int) db()->lastInsertId(), 'nom' => 'Toute la villa',
                             'type_piece' => 'globale', 'etage' => null, 'description' => null];
        }

        // Sélections déjà enregistrées pour CE produit.
        $sst = db()->prepare(
            'SELECT piece_id, couleur, dimension FROM configuration_produits
             WHERE configuration_id = ? AND categorie_produit_id = ? AND produit_id = ?'
        );
        $sst->execute([$configId, $catId, $produitId]);
        foreach ($sst->fetchAll() as $r) {
            $selections[(int) $r['piece_id']] = $r;
        }
    }
}

$peutChoisir = $config && $modifiable;
// Valeurs par défaut du sélecteur (reprend un choix existant s'il y en a un).
$premiere = $selections ? reset($selections) : null;
$defCoul = ($premiere['couleur'] ?? '') ?: ($couleurs ? reset($couleurs) : '');
$defDim  = ($premiere['dimension'] ?? '') ?: ($dimensions ? reset($dimensions) : '');
$globaleId = $pieceGlobale ? (int) $pieceGlobale['id'] : 0;
$globaleChoisie = $globaleId && isset($selections[$globaleId]);

layout_client_debut($prod['nom']);
?>
<section class="client-page wrap">
    <a class="back-link" href="<?= h($retour) ?>">← Retour à la configuration</a>

    <div class="produit-detail">
        <div class="produit-detail-media">
            <?php if ($img): ?>
                <img src="<?= h($img) ?>" alt="<?= h($prod['nom']) ?>">
            <?php else: ?>
                <div class="produit-detail-vide">⌂</div>
            <?php endif; ?>
        </div>

        <div class="produit-detail-infos">
            <div class="formule-badge"><span class="formule-badge-ic">✓</span> Collection <?= h($prod['collection_nom']) ?></div>
            <h1><?= h($prod['nom']) ?></h1>
            <p class="produit-detail-cat"><?= h($prod['categorie_nom']) ?> · réf. <?= h($prod['reference']) ?></p>
            <p class="produit-detail-prix"><?= euros($prod['prix']) ?></p>

            <?php if (!empty($prod['description'])): ?>
                <p class="produit-detail-desc"><?= nl2br(h($prod['description'])) ?></p>
            <?php endif; ?>

            <?php if ($peutChoisir): /* ============ CHOIX INTERACTIF ============ */ ?>
                <form class="detail-choix" id="detail-choix"
                      data-cat="<?= $catId ?>" data-produit="<?= $produitId ?>"
                      data-config="<?= $configId ?>" data-parpiece="<?= $parPiece ? 1 : 0 ?>">
                    <?= csrf_input() ?>
                    <input type="hidden" id="dc-couleur" value="<?= h($defCoul) ?>">
                    <input type="hidden" id="dc-dimension" value="<?= h($defDim) ?>">

                    <?php if ($couleurs): ?>
                        <div class="detail-bloc">
                            <h3>Choisissez le coloris</h3>
                            <div class="vm-couleurs">
                                <?php foreach ($couleurs as $c): ?>
                                    <span class="vm-couleur<?= $c === $defCoul ? ' actif' : '' ?>"
                                          data-val="<?= h($c) ?>" title="<?= h(ucfirst($c)) ?>"
                                          style="background:<?= h(couleur_css($c)) ?>"></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($dimensions): ?>
                        <div class="detail-bloc">
                            <h3>Choisissez les dimensions</h3>
                            <div class="vm-dims">
                                <?php foreach ($dimensions as $d): ?>
                                    <button type="button" class="vm-dim<?= $d === $defDim ? ' actif' : '' ?>"
                                            data-val="<?= h($d) ?>"><?= h($d) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($parPiece): ?>
                        <div class="detail-bloc">
                            <h3>Dans quelles pièces&nbsp;?</h3>
                            <div class="detail-pieces-head">
                                <span class="field-aide">Le coloris et les dimensions choisis ci-dessus s'appliquent aux pièces cochées.</span>
                                <button type="button" class="btn-tout" id="dc-tout">Tout</button>
                            </div>
                            <div class="detail-pieces">
                                <?php foreach ($piecesReelles as $pc): $pcid = (int) $pc['id']; ?>
                                    <label class="piece-check">
                                        <input type="checkbox" class="dc-piece" value="<?= $pcid ?>"
                                               <?= isset($selections[$pcid]) ? 'checked' : '' ?>>
                                        <span class="piece-check-txt"><?= h($pc['nom']) ?><?php if (!empty($pc['etage'])): ?> <small><?= h($pc['etage']) ?></small><?php endif; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <button type="button" class="btn-envoyer" id="dc-choisir" style="width:auto;">
                            <?= $globaleChoisie ? '✓ Produit choisi — cliquez pour retirer' : 'Choisir ce produit pour toute la villa' ?>
                        </button>
                    <?php endif; ?>

                    <p class="autosave-note" id="dc-note">✓ Vos choix sont enregistrés automatiquement</p>
                </form>
            <?php else: /* ============ AFFICHAGE SEUL (config non modifiable) ============ */ ?>
                <?php if ($couleurs): ?>
                    <div class="detail-bloc">
                        <h3>Coloris disponibles</h3>
                        <div class="couleur-liste">
                            <?php foreach ($couleurs as $c): ?>
                                <span class="couleur-item">
                                    <span class="pastille" style="background:<?= h(couleur_css($c)) ?>"></span>
                                    <span><?= h(ucfirst($c)) ?></span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($dimensions): ?>
                    <div class="detail-bloc">
                        <h3>Dimensions disponibles</h3>
                        <ul class="detail-puces">
                            <?php foreach ($dimensions as $d): ?><li><?= h($d) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($caracs): ?>
                <div class="detail-bloc">
                    <h3>Caractéristiques</h3>
                    <table class="fiche-table">
                        <?php foreach ($caracs as $c): ?>
                            <tr><th><?= h($c['nom']) ?></th><td><?= h($c['valeur']) ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endif; ?>

            <a class="btn-line" href="<?= h($retour) ?>" style="width:auto;display:inline-flex;margin-top:14px;">← Retour à la configuration</a>
        </div>
    </div>
</section>

<?php if ($peutChoisir): ?>
<script>
(function () {
    var form = document.getElementById('detail-choix');
    if (!form) { return; }
    var CSRF = (form.querySelector('input[name=_csrf]') || {}).value || '';
    var CFG = form.getAttribute('data-config');
    var CAT = form.getAttribute('data-cat');
    var PROD = form.getAttribute('data-produit');
    var PAR_PIECE = form.getAttribute('data-parpiece') === '1';
    var GLOBALE = <?= $globaleId ?>;
    var coulH = document.getElementById('dc-couleur');
    var dimH = document.getElementById('dc-dimension');
    var note = document.getElementById('dc-note');
    var choisiGlobal = <?= $globaleChoisie ? 'true' : 'false' ?>;   // état du choix « toute la villa »

    // File d'attente : une requête d'enregistrement à la fois.
    var queue = [], enVol = false;
    function traiter() {
        if (enVol || !queue.length) { return; }
        enVol = true;
        var body = queue.shift();
        fetch('<?= $base ?>/client/config.php?config=' + encodeURIComponent(CFG), {
            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body, credentials: 'same-origin'
        }).then(function (r) { return r.text(); }).then(function (t) {
            var d; try { d = JSON.parse(t); } catch (e) { d = null; }
            if (note) {
                if (d && d.ok) {
                    note.classList.remove('erreur');
                    note.textContent = '✓ Enregistré — total ' + d.total;
                } else {
                    note.classList.add('erreur');
                    note.textContent = '⚠ Échec' + (d && d.error ? ' : ' + d.error : '');
                }
            }
        }).catch(function () {
            if (note) { note.classList.add('erreur'); note.textContent = '⚠ Échec réseau'; }
        }).then(function () { enVol = false; traiter(); });
    }
    function envoyer(piece, produit) {
        var b = new URLSearchParams();
        b.set('_csrf', CSRF); b.set('action', 'ajax_set');
        b.set('cat', CAT); b.set('piece', piece); b.set('produit', produit);
        b.set('couleur', coulH.value); b.set('dimension', dimH.value);
        queue.push(b.toString()); traiter();
    }
    // Ré-enregistre toutes les pièces actuellement affectées (après changement de coloris/dim).
    function resauver() {
        if (PAR_PIECE) {
            form.querySelectorAll('.dc-piece:checked').forEach(function (c) { envoyer(c.value, PROD); });
        } else if (choisiGlobal) {
            envoyer(GLOBALE, PROD);
        }
    }

    // Coloris
    form.querySelectorAll('.vm-couleur').forEach(function (el) {
        el.addEventListener('click', function () {
            coulH.value = el.getAttribute('data-val');
            form.querySelectorAll('.vm-couleur').forEach(function (o) { o.classList.remove('actif'); });
            el.classList.add('actif');
            resauver();
        });
    });
    // Dimensions
    form.querySelectorAll('.vm-dim').forEach(function (el) {
        el.addEventListener('click', function () {
            dimH.value = el.getAttribute('data-val');
            form.querySelectorAll('.vm-dim').forEach(function (o) { o.classList.remove('actif'); });
            el.classList.add('actif');
            resauver();
        });
    });

    if (PAR_PIECE) {
        // Choix par pièce.
        form.querySelectorAll('.dc-piece').forEach(function (c) {
            c.addEventListener('change', function () {
                envoyer(c.value, c.checked ? PROD : 0);
            });
        });
        var tout = document.getElementById('dc-tout');
        tout.addEventListener('click', function () {
            var actif = tout.classList.contains('actif');
            form.querySelectorAll('.dc-piece').forEach(function (c) {
                if (c.checked === actif) { c.checked = !actif; c.dispatchEvent(new Event('change')); }
            });
            tout.classList.toggle('actif');
            tout.textContent = tout.classList.contains('actif') ? 'Aucune' : 'Tout';
        });
    } else {
        // Choix « toute la villa » : bouton bascule.
        var btn = document.getElementById('dc-choisir');
        btn.classList.toggle('actif', choisiGlobal);
        btn.addEventListener('click', function () {
            choisiGlobal = !choisiGlobal;
            envoyer(GLOBALE, choisiGlobal ? PROD : 0);
            btn.textContent = choisiGlobal ? '✓ Produit choisi — cliquez pour retirer'
                                           : 'Choisir ce produit pour toute la villa';
            btn.classList.toggle('actif', choisiGlobal);
        });
    }
})();
</script>
<?php endif; ?>
<?php layout_fin();
