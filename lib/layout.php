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

/**
 * Affiche la page de connexion complète (HTTP 200), sans aucune redirection.
 * Utilisée par index.php et par les gardes exiger_admin()/exiger_client()
 * lorsqu'un visiteur non authentifié atteint une page protégée : on évite ainsi
 * toute boucle de redirection.
 */
function rendre_connexion(?string $erreur = null, string $contexte = ''): void
{
    $base = base_url();
    layout_public_debut('Connexion');
    ?>
    <section class="auth">
        <div class="auth-grid wrap">
            <div class="auth-intro">
                <div class="hero-eyebrow">Espace privé</div>
                <h1>Votre villa,<br><em>dessinée avec vous.</em></h1>
                <p>Connectez-vous pour choisir votre plan, votre collection et configurer chaque
                   pièce de votre future villa. Administrateurs et clients utilisent la même entrée.</p>
                <div class="auth-note">
                    Nouveau client&nbsp;? Activez votre compte avec votre
                    <strong>numéro de devis</strong>.
                    <a href="<?= h($base) ?>/register.php">Créer mon compte →</a>
                </div>
            </div>

            <div class="auth-card">
                <div class="formule-code">// ACCÈS SÉCURISÉ</div>
                <h2 class="auth-card-title">Connexion</h2>

                <?php if ($erreur): ?>
                    <div class="flash flash-erreur"><?= h($erreur) ?></div>
                <?php endif; ?>

                <form method="post" action="<?= h($base) ?>/index.php" class="auth-form">
                    <?= csrf_input() ?>
                    <label class="form-field">
                        <span>Identifiant</span>
                        <input type="text" name="identifiant" autocomplete="username" required autofocus
                               value="<?= h($_POST['identifiant'] ?? '') ?>">
                    </label>
                    <label class="form-field">
                        <span>Mot de passe</span>
                        <input type="password" name="mot_de_passe" autocomplete="current-password" required>
                    </label>
                    <button type="submit" class="btn-envoyer">Se connecter</button>
                </form>

                <a class="back-link" href="<?= h($base) ?>/register.php">
                    Je n'ai pas encore de compte
                </a>
            </div>
        </div>
    </section>
    <?php
    layout_fin();
}

function layout_public_debut(string $titre): void
{
    layout_head($titre);
    $base = base_url();
    ?>
    <header class="site-header">
        <div class="brand-lockup">
            <span class="brand-logo" style="background-image:url('<?= h($base) ?>/assets/logo.jpg')"></span>
            <div>
                <div class="brand">Les Villas <span>Blanches</span></div>
                <div class="brand-tag"><?= h(config('sous_marque')) ?> · Atelier d'architecture</div>
            </div>
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
        <a href="<?= h($base) ?>/client/index.php" class="brand-lockup">
            <span class="brand-logo" style="background-image:url('<?= h($base) ?>/assets/logo.jpg')"></span>
            <div>
                <div class="brand">Les Villas <span>Blanches</span></div>
                <div class="brand-tag"><?= h(config('sous_marque')) ?></div>
            </div>
        </a>
        <button type="button" class="nav-toggle" aria-label="Ouvrir le menu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
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
    // Menu filtré selon les droits de l'admin connecté (webmaster = tout).
    $liens = [];
    foreach (admin_menus() as $cle => $lien) {
        if (admin_peut($cle)) {
            $liens[$cle] = $lien;
        }
    }
    ?>
    <div class="admin-shell">
        <aside class="admin-side">
            <div class="admin-brand">
                <div class="brand-lockup">
                    <span class="brand-logo" style="background-image:url('<?= h($base) ?>/assets/logo.jpg')"></span>
                    <div>
                        <div class="brand">Les Villas <span>Blanches</span></div>
                        <div class="brand-tag">Administration</div>
                    </div>
                </div>
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
        <div class="admin-side-overlay" hidden></div>
        <main class="admin-main">
            <div class="admin-topbar">
                <button type="button" class="admin-burger" aria-label="Ouvrir le menu">☰</button>
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
    <script>
    (function () {
        var b = document.querySelector('.admin-burger'),
            side = document.querySelector('.admin-side'),
            ov = document.querySelector('.admin-side-overlay');
        function ferme() { document.body.classList.remove('admin-menu-ouvert'); if (ov) { ov.hidden = true; } }
        if (b && side) {
            b.addEventListener('click', function () {
                var open = document.body.classList.toggle('admin-menu-ouvert');
                if (ov) { ov.hidden = !open; }
            });
        }
        if (ov) { ov.addEventListener('click', ferme); }
        document.querySelectorAll('.admin-nav a').forEach(function (a) { a.addEventListener('click', ferme); });
    })();
    </script>
    </body></html>
    <?php
}

function layout_fin(): void
{
    ?>
    <footer class="site-footer">
        <span>© <?= date('Y') ?> Les Villas Blanches — <?= h(config('sous_marque')) ?></span>
        <a class="footer-credit" href="https://missalpro.com" target="_blank" rel="noopener">
            <span>design by</span>
            <img src="<?= base_url() ?>/assets/missal.png" alt="Missal" class="footer-missal">
        </a>
    </footer>
    <script>
    (function () {
        var t = document.querySelector('.nav-toggle'), n = document.querySelector('.header-nav');
        if (t && n) {
            t.addEventListener('click', function () {
                var open = n.classList.toggle('ouvert');
                t.classList.toggle('actif', open);
                t.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }
    })();
    </script>
    </body></html>
    <?php
}
