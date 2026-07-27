<?php
/**
 * Les Villas Blanches — Fonctions partagées.
 * Bootstrap commun : session, config, helpers d'affichage, authentification,
 * CSRF et gestion des champs personnalisés (dynamiques).
 */

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    // Repli : si le dossier d'enregistrement des sessions par défaut n'est pas
    // inscriptible (fréquent en mutualisé), on bascule sur un dossier local
    // « sessions/ » dans l'application, sinon les sessions ne persistent pas.
    $saveDefaut = session_save_path();
    if ($saveDefaut === '' || !is_dir($saveDefaut) || !is_writable($saveDefaut)) {
        $saveLocal = dirname(__DIR__) . '/sessions';
        if (!is_dir($saveLocal)) {
            @mkdir($saveLocal, 0700, true);
        }
        if (is_dir($saveLocal) && is_writable($saveLocal)) {
            session_save_path($saveLocal);
        }
    }

    // Cookie de session forcé sur le chemin « / » pour qu'il soit UNIQUE et
    // partagé entre la racine (index.php), /client et /admin.
    session_set_cookie_params([
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

/* -----------------------------------------------------------------------
 * Configuration
 * -------------------------------------------------------------------- */

function config(?string $cle = null)
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    if ($cle === null) {
        return $config;
    }
    return $config[$cle] ?? null;
}

/* -----------------------------------------------------------------------
 * Affichage / formatage
 * -------------------------------------------------------------------- */

/** Échappement HTML systématique de tout texte affiché. */
function h($valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES, 'UTF-8');
}

/** Formatage monétaire en euros, format français. */
function euros($montant): string
{
    return number_format((float) $montant, 2, ',', ' ') . ' €';
}

/** Palette de coloris disponibles : [jeton => couleur CSS]. */
function palette_couleurs(): array
{
    return [
        'noir' => '#1C1C1C', 'blanc' => '#FFFFFF', 'gris' => '#9AA0A6', 'anthracite' => '#3A3F44',
        'beige' => '#E4D6B8', 'sable' => '#D8C79E', 'marron' => '#7A4E2D', 'taupe' => '#8B7E6A',
        'rouge' => '#C0392B', 'bordeaux' => '#7B1E2B', 'orange' => '#E67E22', 'jaune' => '#F1C40F',
        'vert' => '#27AE60', 'bleu' => '#2E5E8C', 'turquoise' => '#1ABC9C',
    ];
}

/** Couleur CSS d'un jeton de la palette (gère aussi une valeur hex libre). */
function couleur_css(string $jeton): string
{
    $p = palette_couleurs();
    $k = strtolower(trim($jeton));
    if (isset($p[$k])) {
        return $p[$k];
    }
    return preg_match('/^#?[0-9a-fA-F]{3,8}$/', $jeton) ? (str_starts_with($jeton, '#') ? $jeton : '#' . $jeton) : '#CCCCCC';
}

/** Caractéristiques d'un produit : [['nom'=>, 'valeur'=>], ...]. */
function produit_caracteristiques(int $produitId): array
{
    $stmt = db()->prepare('SELECT nom, valeur FROM produit_caracteristiques WHERE produit_id = ? ORDER BY id');
    $stmt->execute([$produitId]);
    return $stmt->fetchAll();
}

/** Caractéristiques d'une pièce. */
function piece_caracteristiques(int $pieceId): array
{
    $stmt = db()->prepare('SELECT nom, valeur FROM piece_caracteristiques WHERE piece_id = ? ORDER BY id');
    $stmt->execute([$pieceId]);
    return $stmt->fetchAll();
}

/** Transforme un texte en identifiant URL/fichier : minuscules, sans accents. */
function slug(string $texte): string
{
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $texte);
    if ($t === false) {
        $t = $texte;
    }
    $t = strtolower($t);
    $t = preg_replace('/[^a-z0-9]+/', '-', $t);
    return trim($t, '-') ?: 'x';
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** URL de base du site (dossier racine), pour construire des liens absolus. */
function base_url(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    // Si on est dans /admin ou /client, remonter à la racine.
    $dir = preg_replace('#/(admin|client)(/.*)?$#', '', $dir);
    return rtrim($dir, '/');
}

/* -----------------------------------------------------------------------
 * Messages flash
 * -------------------------------------------------------------------- */

function flash(string $message, string $type = 'succes'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function flashs(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* -----------------------------------------------------------------------
 * CSRF
 * -------------------------------------------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

/** À appeler en tête de tout traitement POST. Interrompt si jeton invalide. */
function csrf_verifier(): void
{
    $envoye = $_POST['_csrf'] ?? '';
    $attendu = $_SESSION['csrf'] ?? '';
    if (!is_string($envoye) || $envoye === '' || $attendu === '' || !hash_equals($attendu, $envoye)) {
        http_response_code(419);
        exit('Jeton de sécurité invalide ou expiré. Revenez en arrière et réessayez.');
    }
}

/* -----------------------------------------------------------------------
 * Authentification
 * -------------------------------------------------------------------- */

function admin_connecte(): ?array
{
    return $_SESSION['admin'] ?? null;
}

function client_connecte(): ?array
{
    return $_SESSION['client'] ?? null;
}

function exiger_admin(): array
{
    $admin = admin_connecte();
    if (!$admin) {
        // On N'REDIRIGE PAS (évite toute boucle de redirection) : on affiche la
        // page de connexion directement, sur place, en HTTP 200.
        if (function_exists('rendre_connexion')) {
            rendre_connexion('Merci de vous connecter avec un compte administrateur.', 'admin');
            exit;
        }
        redirect(base_url() . '/index.php?err=admin');
    }
    return $admin;
}

function exiger_client(): array
{
    $client = client_connecte();
    if (!$client) {
        if (function_exists('rendre_connexion')) {
            rendre_connexion('Merci de vous connecter à votre espace client.', 'client');
            exit;
        }
        redirect(base_url() . '/index.php?err=client');
    }
    return $client;
}

/* -----------------------------------------------------------------------
 * Champs personnalisés (dynamiques)
 * Mécanisme : definitions_champs_personnalises + valeurs_champs_personnalises.
 * NE JAMAIS modifier la structure des tables pour un champ métier.
 * -------------------------------------------------------------------- */

/** Définitions de champs pour un type d'entité (formule, produit, plan_villa...). */
function champs_definitions(string $typeEntite): array
{
    $stmt = db()->prepare(
        'SELECT * FROM definitions_champs_personnalises
         WHERE type_entite = ? ORDER BY ordre_affichage, id'
    );
    $stmt->execute([$typeEntite]);
    return $stmt->fetchAll();
}

/** Valeurs saisies pour une fiche précise : [definition_id => valeur]. */
function champs_valeurs(string $typeEntite, int $entiteId): array
{
    $stmt = db()->prepare(
        'SELECT v.definition_id, v.valeur
         FROM valeurs_champs_personnalises v
         JOIN definitions_champs_personnalises d ON d.id = v.definition_id
         WHERE d.type_entite = ? AND v.entite_id = ?'
    );
    $stmt->execute([$typeEntite, $entiteId]);
    $out = [];
    foreach ($stmt as $ligne) {
        $out[(int) $ligne['definition_id']] = $ligne['valeur'];
    }
    return $out;
}

/** Rendu HTML des champs dynamiques d'un formulaire d'édition. */
function champs_formulaire(string $typeEntite, int $entiteId = 0): string
{
    $defs = champs_definitions($typeEntite);
    if (!$defs) {
        return '';
    }
    $valeurs = $entiteId ? champs_valeurs($typeEntite, $entiteId) : [];
    $html = '<div class="champs-perso"><p class="champs-perso-titre">Champs personnalisés</p>';
    foreach ($defs as $def) {
        $id = (int) $def['id'];
        $name = 'champ_perso[' . $id . ']';
        $val = $valeurs[$id] ?? '';
        $html .= '<label class="form-field"><span>' . h($def['nom_champ']) . '</span>';
        switch ($def['type_valeur']) {
            case 'nombre':
                $html .= '<input type="number" step="any" name="' . h($name) . '" value="' . h($val) . '">';
                break;
            case 'booleen':
                $coche = $val ? ' checked' : '';
                $html .= '<input type="hidden" name="' . h($name) . '" value="0">';
                $html .= '<input type="checkbox" name="' . h($name) . '" value="1"' . $coche . '>';
                break;
            case 'liste_deroulante':
                $options = array_filter(array_map('trim', explode(',', (string) $def['options_liste'])));
                $html .= '<select name="' . h($name) . '"><option value="">—</option>';
                foreach ($options as $opt) {
                    $sel = ($opt === $val) ? ' selected' : '';
                    $html .= '<option value="' . h($opt) . '"' . $sel . '>' . h($opt) . '</option>';
                }
                $html .= '</select>';
                break;
            default: // texte
                $html .= '<input type="text" name="' . h($name) . '" value="' . h($val) . '">';
        }
        $html .= '</label>';
    }
    $html .= '</div>';
    return $html;
}

/** Enregistre les valeurs des champs dynamiques postés pour une fiche. */
function champs_enregistrer(string $typeEntite, int $entiteId): void
{
    $defs = champs_definitions($typeEntite);
    if (!$defs) {
        return;
    }
    $postes = $_POST['champ_perso'] ?? [];
    $stmt = db()->prepare(
        'INSERT INTO valeurs_champs_personnalises (definition_id, entite_id, valeur)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)'
    );
    foreach ($defs as $def) {
        $id = (int) $def['id'];
        $valeur = isset($postes[$id]) ? (string) $postes[$id] : '';
        $stmt->execute([$id, $entiteId, $valeur]);
    }
}

/** Valeurs dynamiques prêtes à l'affichage : [nom_champ => valeur formatée]. */
function champs_affichage(string $typeEntite, int $entiteId): array
{
    $defs = champs_definitions($typeEntite);
    if (!$defs) {
        return [];
    }
    $valeurs = champs_valeurs($typeEntite, $entiteId);
    $out = [];
    foreach ($defs as $def) {
        $val = $valeurs[(int) $def['id']] ?? '';
        if ($def['type_valeur'] === 'booleen') {
            $val = $val ? 'Oui' : 'Non';
        }
        if ($val !== '' && $val !== null) {
            $out[$def['nom_champ']] = $val;
        }
    }
    return $out;
}
