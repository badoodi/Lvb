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

/**
 * Notifie l'admin (adresse de test badaradiaw@gmail.com) qu'un client vient de
 * valider sa configuration, avec le récapitulatif PDF en pièce jointe.
 */
function notifier_admin_validation(int $configId, string $pdfBytes): bool
{
    $stmt = db()->prepare(
        'SELECT cl.nom AS client_nom, p.nom AS plan_nom, f.nom AS formule_nom, cfg.prix_total
         FROM configurations cfg
         JOIN clients cl    ON cl.id = cfg.client_id
         JOIN plans_villa p ON p.id = cfg.plan_id
         JOIN formules f    ON f.id = cfg.formule_id
         WHERE cfg.id = ?'
    );
    $stmt->execute([$configId]);
    $c = $stmt->fetch();
    if (!$c) {
        return false;
    }

    $marque = config('marque');
    $destinataire = 'badaradiaw@gmail.com'; // adresse de test (admin)
    $sujet = "[$marque] Configuration validée par un client";

    $texte = "Bonjour,\n\n"
        . "Le client « {$c['client_nom']} » vient de valider sa configuration :\n"
        . "- Plan : {$c['plan_nom']}\n"
        . "- Formule : {$c['formule_nom']}\n"
        . "- Total estimé : " . number_format((float) $c['prix_total'], 2, ',', ' ') . " EUR\n\n"
        . "Le récapitulatif détaillé est en pièce jointe (PDF).\n"
        . "Connectez-vous au tableau de bord pour valider la commande.\n\n"
        . "$marque\n";

    $filename = "recap-configuration-$configId.pdf";
    $boundary = '=_lvb_' . md5(uniqid('', true));

    $headers  = 'From: ' . config('email_expediteur') . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

    $corps  = "--$boundary\r\n";
    $corps .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $corps .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $corps .= $texte . "\r\n";
    $corps .= "--$boundary\r\n";
    $corps .= "Content-Type: application/pdf; name=\"$filename\"\r\n";
    $corps .= "Content-Transfer-Encoding: base64\r\n";
    $corps .= "Content-Disposition: attachment; filename=\"$filename\"\r\n\r\n";
    $corps .= chunk_split(base64_encode($pdfBytes)) . "\r\n";
    $corps .= "--$boundary--";

    $envoye = function_exists('mail') ? @mail($destinataire, $sujet, $corps, $headers) : false;
    if (!$envoye) {
        error_log("[LVB] Notification admin (config #$configId) -> $destinataire : envoi mail() indisponible.");
    }
    return $envoye;
}
