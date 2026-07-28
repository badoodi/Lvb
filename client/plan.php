<?php
/**
 * Espace client — fiche détaillée d'un plan de villa.
 * Galerie de plusieurs images (couverture + galerie), description, dimensions,
 * puis bouton « Choisir ce plan » qui poursuit vers le choix de la collection.
 */
require_once __DIR__ . '/../lib/layout.php';
$client = exiger_client();
$base = base_url();

$planId = (int) ($_GET['plan'] ?? 0);
$stmt = db()->prepare('SELECT * FROM plans_villa WHERE id = ? AND actif = 1');
$stmt->execute([$planId]);
$plan = $stmt->fetch();
if (!$plan) {
    flash('Plan introuvable.', 'erreur');
    redirect($base . '/client/index.php');
}

// Galerie : image de couverture d'abord, puis les images supplémentaires.
$images = [];
if (!empty($plan['image'])) {
    $images[] = ['fichier' => $plan['image'], 'legende' => 'Vue principale'];
}
foreach (images_plan($planId) as $img) {
    $images[] = ['fichier' => $img['fichier'], 'legende' => $img['legende']];
}

layout_client_debut($plan['nom']);
?>
<section class="client-page wrap">
    <a class="back-link" href="<?= h($base) ?>/client/index.php">← Retour aux plans</a>

    <div class="plan-detail">
        <div class="plan-galerie">
            <?php if ($images): ?>
                <div class="plan-galerie-main">
                    <img id="plan-img-principale" src="<?= h($base . '/' . ltrim($images[0]['fichier'], '/')) ?>"
                         alt="<?= h($plan['nom']) ?>">
                </div>
                <?php if (count($images) > 1): ?>
                    <div class="plan-galerie-vignettes">
                        <?php foreach ($images as $i => $img): ?>
                            <button type="button" class="plan-vignette<?= $i === 0 ? ' actif' : '' ?>"
                                    data-src="<?= h($base . '/' . ltrim($img['fichier'], '/')) ?>">
                                <img src="<?= h($base . '/' . ltrim($img['fichier'], '/')) ?>" alt="<?= h($img['legende'] ?? '') ?>">
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="plan-galerie-main"><div class="plan-visuel-vide blueprint-grid"></div></div>
            <?php endif; ?>
        </div>

        <div class="plan-detail-infos">
            <div class="formule-badge"><span class="formule-badge-ic">✓</span> Plan de villa</div>
            <h1><?= h($plan['nom']) ?></h1>

            <?php if (!empty($plan['description'])): ?>
                <p class="plan-detail-desc"><?= nl2br(h($plan['description'])) ?></p>
            <?php endif; ?>

            <ul class="plan-specs plan-specs-detail">
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

            <a class="btn-envoyer" href="<?= h($base) ?>/client/formule.php?plan=<?= (int) $plan['id'] ?>"
               style="width:auto;">Choisir ce plan →</a>
        </div>
    </div>
</section>

<script>
(function () {
    var main = document.getElementById('plan-img-principale');
    if (!main) { return; }
    document.querySelectorAll('.plan-vignette').forEach(function (v) {
        v.addEventListener('click', function () {
            main.src = v.getAttribute('data-src');
            document.querySelectorAll('.plan-vignette').forEach(function (o) { o.classList.remove('actif'); });
            v.classList.add('actif');
        });
    });
})();
</script>
<?php layout_fin();
