<?php
/**
 * Envoi des documents techniques au client à la validation d'une commande.
 * Utilise mail() si disponible ; à défaut journalise l'envoi (mode dev).
 * Renseigne toujours configurations.plans_envoyes_le.
 */
require_once __DIR__ . '/functions.php';

function envoyer_documents_au_client(int $configId): bool
{
    $stmt = db()->prepare(
        'SELECT c.id, c.plan_id, cl.nom AS client_nom, cl.identifiant AS client_email,
                p.nom AS plan_nom, f.nom AS formule_nom
         FROM configurations c
         JOIN clients cl    ON cl.id = c.client_id
         JOIN plans_villa p ON p.id = c.plan_id
         JOIN formules f    ON f.id = c.formule_id
         WHERE c.id = ?'
    );
    $stmt->execute([$configId]);
    $cfg = $stmt->fetch();
    if (!$cfg) {
        return false;
    }

    $docs = db()->prepare('SELECT * FROM documents_plan WHERE plan_id = ?');
    $docs->execute([(int) $cfg['plan_id']]);
    $documents = $docs->fetchAll();

    $marque = config('marque');
    $base = base_url();

    $liens = '';
    foreach ($documents as $d) {
        $liens .= '- ' . $d['nom'] . "\n";
    }
    if ($liens === '') {
        $liens = "(Documents en cours de préparation.)\n";
    }

    $sujet = "[$marque] Votre configuration est validée — documents techniques";
    $corps = "Bonjour {$cfg['client_nom']},\n\n"
        . "Votre configuration pour le plan « {$cfg['plan_nom']} » (formule {$cfg['formule_nom']}) "
        . "a été validée par l'atelier.\n\n"
        . "Vous trouverez ci-joints / en téléchargement vos documents techniques :\n"
        . $liens
        . "\nConnectez-vous à votre espace pour les télécharger.\n\n"
        . "Bien à vous,\n$marque — " . config('sous_marque') . "\n";

    $expediteur = config('email_expediteur');
    $entetes = 'From: ' . $expediteur . "\r\n" . 'Content-Type: text/plain; charset=utf-8';

    $envoye = false;
    // L'identifiant client peut être un email ; on n'envoie que dans ce cas.
    $destinataire = filter_var($cfg['client_email'], FILTER_VALIDATE_EMAIL) ? $cfg['client_email'] : null;

    if ($destinataire && function_exists('mail')) {
        $envoye = @mail($destinataire, $sujet, $corps, $entetes);
    }
    if (!$envoye) {
        // Mode dev / pas d'email valide : on journalise pour trace.
        error_log("[LVB] Email documents (config #$configId) -> "
            . ($destinataire ?? 'pas d\'email client') . " : " . str_replace("\n", ' ', $sujet));
    }

    // Dans tous les cas on marque l'envoi comme traité.
    $maj = db()->prepare('UPDATE configurations SET plans_envoyes_le = NOW() WHERE id = ?');
    $maj->execute([$configId]);

    return $envoye;
}
