<?php
/**
 * Téléchargement du récapitulatif PDF d'une configuration.
 * Accessible à l'admin (toute config) et au client propriétaire (une fois sa
 * configuration validée : statut en_attente ou validee).
 */
require_once __DIR__ . '/lib/layout.php';   // pour rendre_connexion()
require_once __DIR__ . '/lib/recap.php';

$configId = (int) ($_GET['config'] ?? 0);
$admin  = admin_connecte();
$client = client_connecte();

if (!$admin && !$client) {
    rendre_connexion('Merci de vous connecter pour télécharger ce document.');
    exit;
}

$stmt = db()->prepare('SELECT client_id, statut FROM configurations WHERE id = ?');
$stmt->execute([$configId]);
$cfg = $stmt->fetch();

$autorise = false;
if ($cfg) {
    if ($admin) {
        $autorise = true;
    } elseif ($client
        && (int) $cfg['client_id'] === (int) $client['id']
        && in_array($cfg['statut'], ['en_attente', 'validee'], true)) {
        $autorise = true;
    }
}

if (!$autorise) {
    http_response_code(403);
    exit('Accès refusé à ce document.');
}

$pdf = generer_pdf_configuration($configId);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="recap-configuration-' . $configId . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
