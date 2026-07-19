<?php
/**
 * Auto-inscription client via un numéro de devis.
 * Le devis doit exister et être « disponible ». Après activation il passe à
 * « utilise » et se lie au nouveau client (voir CLAUDE.md / schema.sql).
 * Devis de test : DEV-2026-0001.
 */
require_once __DIR__ . '/lib/layout.php';

if (client_connecte()) {
    redirect(base_url() . '/client/index.php');
}

$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $nom         = trim($_POST['nom'] ?? '');
    $identifiant = trim($_POST['identifiant'] ?? '');
    $motdepasse  = (string) ($_POST['mot_de_passe'] ?? '');
    $confirme    = (string) ($_POST['mot_de_passe_confirme'] ?? '');
    $numeroDevis = trim($_POST['numero_devis'] ?? '');

    if ($nom === '' || $identifiant === '' || $motdepasse === '' || $numeroDevis === '') {
        $erreur = 'Tous les champs sont obligatoires.';
    } elseif (strlen($motdepasse) < 6) {
        $erreur = 'Le mot de passe doit contenir au moins 6 caractères.';
    } elseif ($motdepasse !== $confirme) {
        $erreur = 'Les deux mots de passe ne correspondent pas.';
    } else {
        // Identifiant déjà pris ?
        $stmt = db()->prepare('SELECT id FROM clients WHERE identifiant = ?');
        $stmt->execute([$identifiant]);
        if ($stmt->fetch()) {
            $erreur = 'Cet identifiant est déjà utilisé, choisissez-en un autre.';
        } else {
            // Devis disponible ?
            $stmt = db()->prepare('SELECT * FROM devis WHERE numero_devis = ?');
            $stmt->execute([$numeroDevis]);
            $devis = $stmt->fetch();

            if (!$devis) {
                $erreur = 'Ce numéro de devis n\'existe pas.';
            } elseif ($devis['statut'] !== 'disponible') {
                $erreur = 'Ce numéro de devis a déjà été utilisé.';
            } else {
                // Création atomique du compte + rattachement du devis.
                $pdo = db();
                try {
                    $pdo->beginTransaction();

                    $ins = $pdo->prepare(
                        'INSERT INTO clients (nom, identifiant, mot_de_passe) VALUES (?, ?, ?)'
                    );
                    $ins->execute([$nom, $identifiant, password_hash($motdepasse, PASSWORD_BCRYPT)]);
                    $clientId = (int) $pdo->lastInsertId();

                    $maj = $pdo->prepare(
                        'UPDATE devis
                         SET statut = \'utilise\', client_id = ?, date_utilisation = NOW()
                         WHERE id = ? AND statut = \'disponible\''
                    );
                    $maj->execute([$clientId, (int) $devis['id']]);

                    if ($maj->rowCount() !== 1) {
                        // Le devis a été consommé entre-temps : on annule.
                        throw new RuntimeException('devis_indisponible');
                    }

                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $erreur = 'Ce numéro de devis n\'est plus disponible. Réessayez.';
                }

                if (!$erreur) {
                    session_regenerate_id(true);
                    $_SESSION['client'] = [
                        'id'          => $clientId,
                        'identifiant' => $identifiant,
                        'nom'         => $nom,
                    ];
                    $v = db()->prepare('INSERT INTO visites (client_id, adresse_ip) VALUES (?, ?)');
                    $v->execute([$clientId, $_SERVER['REMOTE_ADDR'] ?? null]);
                    flash('Bienvenue ! Votre compte est activé.');
                    redirect(base_url() . '/client/index.php');
                }
            }
        }
    }
}

layout_public_debut('Créer mon compte');
?>
<section class="auth">
    <div class="auth-grid wrap">
        <div class="auth-intro crop-marks">
            <div class="hero-eyebrow">Nouveau client</div>
            <h1>Activez votre <em>espace projet.</em></h1>
            <p>Votre devis vous a été remis par l'atelier. Saisissez son numéro pour
               activer votre compte et accéder à la configuration de votre villa.</p>
            <div class="auth-note">
                Déjà un compte&nbsp;?
                <a href="<?= h(base_url()) ?>/index.php">Se connecter →</a>
            </div>
        </div>

        <div class="auth-card">
            <div class="formule-code">// ACTIVATION VIA DEVIS</div>
            <h2 class="auth-card-title">Créer mon compte</h2>

            <?php if ($erreur): ?>
                <div class="flash flash-erreur"><?= h($erreur) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= h(base_url()) ?>/register.php" class="auth-form">
                <?= csrf_input() ?>
                <label class="form-field">
                    <span>Nom complet</span>
                    <input type="text" name="nom" required value="<?= h($_POST['nom'] ?? '') ?>">
                </label>
                <label class="form-field">
                    <span>Identifiant</span>
                    <input type="text" name="identifiant" autocomplete="username" required
                           value="<?= h($_POST['identifiant'] ?? '') ?>">
                </label>
                <label class="form-field">
                    <span>Mot de passe</span>
                    <input type="password" name="mot_de_passe" autocomplete="new-password" required>
                </label>
                <label class="form-field">
                    <span>Confirmer le mot de passe</span>
                    <input type="password" name="mot_de_passe_confirme" autocomplete="new-password" required>
                </label>
                <label class="form-field">
                    <span>Numéro de devis</span>
                    <input type="text" name="numero_devis" required placeholder="DEV-2026-0001"
                           value="<?= h($_POST['numero_devis'] ?? '') ?>">
                </label>
                <button type="submit" class="btn-envoyer">Activer mon compte</button>
            </form>
        </div>
    </div>
</section>
<?php layout_fin();
