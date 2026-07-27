<?php
/**
 * Espace client — fiche détaillée d'un produit (bouton « Voir + »).
 * Affiche image, description, coloris, dimensions et caractéristiques.
 */
require_once __DIR__ . '/../lib/layout.php';
$client = exiger_client();
$base = base_url();

$produitId = (int) ($_GET['produit'] ?? 0);
$configId  = (int) ($_GET['config'] ?? 0);

$stmt = db()->prepare(
    'SELECT p.*, f.nom AS collection_nom, cat.nom AS categorie_nom
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

$couleurs = array_filter(array_map('trim', explode(',', (string) $prod['couleurs'])));
$dimensions = array_filter(array_map('trim', preg_split('/\r?\n/', (string) $prod['dimensions'])));
$caracs = produit_caracteristiques($produitId);
$img = !empty($prod['image']) ? ($base . '/' . ltrim($prod['image'], '/')) : '';
$retour = $configId ? ($base . '/client/config.php?config=' . $configId) : ($base . '/client/index.php');

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

            <a class="btn-envoyer" href="<?= h($retour) ?>" style="width:auto;display:inline-flex">← Retour pour choisir</a>
        </div>
    </div>
</section>
<?php layout_fin();
