<?php
/**
 * Gabarits d'affichage partagés (papier de calque — voir style.css).
 * Trois zones : public (connexion), client (parcours), admin (dashboard).
 */

require_once __DIR__ . '/functions.php';

function layout_head(string $titre): void
{
    $marque = config('marque');
    $base = base_url();
    ?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($titre) ?> — <?= h($marque) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;1,9..144,400;1,9..144,500&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= h($base) ?>/style.css">
</head>
<body class="blueprint-grid"><?php
}

function layout_flashs(): void
{
    $flashs = flashs();
    if (!$flashs) {
        return;
    }
    echo '<div class="flash-zone wrap">';
    foreach ($flashs as $f) {
        echo '<div class="flash flash-' . h($f['type']) . '">' . h($f['message']) . '</div>';
    }
    echo '</div>';
}

/* --------------------------- ZONE PUBLIQUE --------------------------- */

function layout_public_debut(string $titre): void
{
    layout_head($titre);
    $base = base_url();
    ?>
    <header class="site-header">
        <div>
            <div class="brand">Les Villas <span>Blanches</span></div>
            <div class="brand-tag"><?= h(config('sous_marque')) ?> · Atelier d'architecture</div>
        </div>
    </header>
    <?php layout_flashs();
}

/* ---------------------------- ZONE CLIENT --------------------------- */

function layout_client_debut(string $titre): void
{
    $client = client_connecte();
    layout_head($titre);
    $base = base_url();
    ?>
    <header class="site-header">
        <div>
            <a href="<?= h($base) ?>/client/index.php" class="brand">Les Villas <span>Blanches</span></a>
            <div class="brand-tag"><?= h(config('sous_marque')) ?></div>
        </div>
        <nav class="header-nav">
            <a href="<?= h($base) ?>/client/index.php">Mes plans</a>
            <a href="<?= h($base) ?>/client/documents.php">Documents</a>
            <span class="header-user"><?= h($client['nom'] ?? $client['identifiant'] ?? '') ?></span>
            <a class="header-logout" href="<?= h($base) ?>/logout.php">Déconnexion</a>
        </nav>
    </header>
    <?php layout_flashs();
}

/* ---------------------------- ZONE ADMIN --------------------------- */

function layout_admin_debut(string $titre, string $actif = ''): void
{
    $admin = admin_connecte();
    layout_head($titre);
    $base = base_url();
    $liens = [
        ''                   => ['index.php',               'Vue globale'],
        'commandes'          => ['commandes.php',           'Commandes'],
        'plans'              => ['crud/plans.php',          'Plans de villa'],
        'formules'           => ['crud/formules.php',       'Formules'],
        'grandes_categories' => ['crud/grandes_categories.php', 'Grandes catégories'],
        'categories'         => ['crud/categories.php',     'Catégories produits'],
        'produits'           => ['crud/produits.php',       'Produits'],
        'devis'              => ['crud/devis.php',           'Devis'],
        'clients'            => ['crud/clients.php',         'Clients'],
        'champs'             => ['crud/champs.php',          'Champs dynamiques'],
    ];
    ?>
    <div class="admin-shell">
        <aside class="admin-side">
            <div class="admin-brand">
                <div class="brand">LVB<span>.</span></div>
                <div class="brand-tag">Administration</div>
            </div>
            <nav class="admin-nav">
                <?php foreach ($liens as $cle => [$url, $label]): ?>
                    <a href="<?= h($base) ?>/admin/<?= h($url) ?>"
                       class="<?= $cle === $actif ? 'actif' : '' ?>"><?= h($label) ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="admin-side-foot">
                <div class="admin-who"><?= h($admin['identifiant'] ?? '') ?></div>
                <a href="<?= h($base) ?>/logout.php">Déconnexion</a>
            </div>
        </aside>
        <main class="admin-main">
            <div class="admin-topbar">
                <h1><?= h($titre) ?></h1>
            </div>
            <?php layout_flashs(); ?>
            <div class="admin-content">
    <?php
}

function layout_admin_fin(): void
{
    ?>
            </div>
        </main>
    </div>
    </body></html>
    <?php
}

function layout_fin(): void
{
    ?>
    <footer class="site-footer">
        <span>© <?= date('Y') ?> Les Villas Blanches — <?= h(config('sous_marque')) ?></span>
        <span>Atelier d'architecture</span>
    </footer>
    </body></html>
    <?php
}
