<?php
/**
 * Les Villas Blanches — Fonctions partagées.
 * Bootstrap commun : session, config, helpers d'affichage, authentification,
 * CSRF et gestion des champs personnalisés (dynamiques).
 */

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    // Cookie de session forcé sur le chemin « / » pour qu'il soit UNIQUE et
    // partagé entre la racine (index.php), /client et /admin. Sans ça, certains
    // hébergements créent un cookie par dossier : la racine croit l'utilisateur
    // connecté et redirige vers /client, qui ne voit pas la session et renvoie
    // vers index.php?err=client -> boucle de redirections.
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
        redirect(base_url() . '/index.php?err=admin');
    }
    return $admin;
}

function exiger_client(): array
{
    $client = client_connecte();
    if (!$client) {
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
