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
            $estWebmaster = (int) ($admin['est_webmaster'] ?? 0) === 1;
            $_SESSION['admin'] = [
                'id'         => (int) $admin['id'],
                'identifiant' => $admin['identifiant'],
                'webmaster'  => $estWebmaster,
                'acces'      => $estWebmaster ? [] : admin_acces_cles((int) $admin['id']),
            ];
            // Atterrissage : première rubrique autorisée (le webmaster va au tableau de bord).
            $dest = base_url() . '/admin/index.php';
            if (!$estWebmaster) {
                foreach (admin_menus() as $cle => [$url, $label]) {
                    if ($cle !== 'admins' && admin_peut($cle)) {
                        $dest = base_url() . '/admin/' . $url;
                        break;
                    }
                }
            }
            redirect($dest);
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

rendre_connexion($erreur);
