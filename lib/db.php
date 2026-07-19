<?php
/**
 * Connexion PDO à la base MySQL (source de vérité : schema.sql).
 * Renvoie une instance PDO unique réutilisée sur toute la requête.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/config.php';
    $db = $config['db'];

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );

    try {
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        // Message volontairement générique côté public ; détail dans les logs.
        error_log('Connexion base impossible : ' . $e->getMessage());
        exit('<!doctype html><html lang="fr"><meta charset="utf-8">'
            . '<title>Base indisponible</title>'
            . '<body style="font-family:sans-serif;max-width:600px;margin:80px auto;color:#1C1F1D">'
            . '<h1>Base de données indisponible</h1>'
            . '<p>Impossible de se connecter à la base <code>' . htmlspecialchars($db['name']) . '</code>. '
            . 'Vérifiez que MySQL tourne et que <code>schema.sql</code> a bien été importé, '
            . 'puis renseignez les identifiants dans <code>lib/config.php</code> '
            . '(ou les variables d\'environnement DB_HOST, DB_NAME, DB_USER, DB_PASS).</p>'
            . '</body></html>');
    }

    return $pdo;
}
