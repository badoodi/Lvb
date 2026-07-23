<?php
/**
 * Page de connexion unique (admin + client).
 * Remplace l'ancienne page d'accueil de choix de formule.
 */
require_once __DIR__ . '/lib/layout.php';

// Déjà connecté ? On redirige vers l'espace correspondant.
// Garde anti-boucle : si on arrive avec ?err=..., c'est qu'une page protégée
// vient de nous rejeter. Si malgré tout la session prétend être connectée, on
// N'redirige PAS (sinon boucle) — on affiche la connexion.
$vientDunRejet = isset($_GET['err']);
if (!$vientDunRejet) {
    if (admin_connecte()) {
        redirect(base_url() . '/admin/index.php');
    }
    if (client_connecte()) {
        redirect(base_url() . '/client/index.php');
    }
}

$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $identifiant = trim($_POST['identifiant'] ?? '');
    $motdepasse  = (string) ($_POST['mot_de_passe'] ?? '');

    if ($identifiant === '' || $motdepasse === '') {
        $erreur = 'Merci de renseigner votre identifiant et votre mot de passe.';
    } else {
        // 1) Tentative admin
        $stmt = db()->prepare('SELECT * FROM administrateurs WHERE identifiant = ?');
        $stmt->execute([$identifiant]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($motdepasse, $admin['mot_de_passe'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = ['id' => (int) $admin['id'], 'identifiant' => $admin['identifiant']];
            redirect(base_url() . '/admin/index.php');
        }

        // 2) Tentative client
        $stmt = db()->prepare('SELECT * FROM clients WHERE identifiant = ?');
        $stmt->execute([$identifiant]);
        $client = $stmt->fetch();

        if ($client && password_verify($motdepasse, $client['mot_de_passe'])) {
            session_regenerate_id(true);
            $_SESSION['client'] = [
                'id'          => (int) $client['id'],
                'identifiant' => $client['identifiant'],
                'nom'         => $client['nom'],
            ];
            // Historique de connexion (dashboard admin).
            $v = db()->prepare('INSERT INTO visites (client_id, adresse_ip) VALUES (?, ?)');
            $v->execute([(int) $client['id'], $_SERVER['REMOTE_ADDR'] ?? null]);
            redirect(base_url() . '/client/index.php');
        }

        $erreur = 'Identifiant ou mot de passe incorrect.';
    }
}

if (($_GET['err'] ?? '') === 'admin') {
    $erreur = 'Merci de vous connecter avec un compte administrateur.';
} elseif (($_GET['err'] ?? '') === 'client') {
    $erreur = 'Merci de vous connecter à votre espace client.';
}

layout_public_debut('Connexion');
?>
<section class="auth">
    <div class="auth-grid wrap">
        <div class="auth-intro crop-marks">
            <div class="hero-eyebrow">Espace privé</div>
            <h1>Votre villa,<br><em>dessinée avec vous.</em></h1>
            <p>Connectez-vous pour choisir votre plan, votre formule et configurer chaque
               pièce de votre future villa. Administrateurs et clients utilisent la même entrée.</p>
            <div class="auth-note">
                Nouveau client&nbsp;? Activez votre compte avec votre
                <strong>numéro de devis</strong>.
                <a href="<?= h(base_url()) ?>/register.php">Créer mon compte →</a>
            </div>
        </div>

        <div class="auth-card">
            <div class="formule-code">// ACCÈS SÉCURISÉ</div>
            <h2 class="auth-card-title">Connexion</h2>

            <?php if ($erreur): ?>
                <div class="flash flash-erreur"><?= h($erreur) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= h(base_url()) ?>/index.php" class="auth-form">
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

            <a class="back-link" href="<?= h(base_url()) ?>/register.php">
                Je n'ai pas encore de compte
            </a>
        </div>
    </div>
</section>
<?php layout_fin();
