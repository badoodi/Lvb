<?php
/**
 * Génère le récapitulatif PDF d'une configuration (infos client + choix).
 * Réutilisé pour le téléchargement (client / admin) et la pièce jointe email.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/pdf.php';

function generer_pdf_configuration(int $configId): string
{
    $stmt = db()->prepare(
        'SELECT cfg.*, cl.nom AS client_nom, cl.identifiant AS client_login, cl.telephone,
                p.nom AS plan_nom, f.nom AS formule_nom, pf.prix_base
         FROM configurations cfg
         JOIN clients cl      ON cl.id = cfg.client_id
         JOIN plans_villa p   ON p.id = cfg.plan_id
         JOIN formules f      ON f.id = cfg.formule_id
         JOIN plan_formule pf ON pf.plan_id = cfg.plan_id AND pf.formule_id = cfg.formule_id
         WHERE cfg.id = ?'
    );
    $stmt->execute([$configId]);
    $cfg = $stmt->fetch();
    if (!$cfg) {
        throw new RuntimeException('Configuration introuvable pour le PDF.');
    }

    $opt = db()->prepare(
        'SELECT cp.prix_applique, cp.supplement,
                cat.nom AS categorie, pc.nom AS piece, pc.etage,
                pr.nom AS produit
         FROM configuration_produits cp
         JOIN categories_produits cat ON cat.id = cp.categorie_produit_id
         JOIN pieces_plan pc          ON pc.id = cp.piece_id
         JOIN produits pr             ON pr.id = cp.produit_id
         WHERE cp.configuration_id = ?
         ORDER BY cat.ordre_affichage, pc.ordre_affichage'
    );
    $opt->execute([$configId]);
    $lignes = $opt->fetchAll();

    $libelleStatut = [
        'en_cours' => 'En cours', 'en_attente' => 'En attente de validation',
        'validee' => 'Validée', 'annule_client' => 'Annulée par le client',
    ];

    $pdf = new SimplePDF();
    $pdf->title(config('marque') . ' — Récapitulatif de configuration');
    $pdf->text('Configuration n° ' . $configId . '  ·  généré le ' . date('d/m/Y à H:i'));

    $pdf->h2('Client');
    $pdf->kv('Nom', (string) $cfg['client_nom']);
    $pdf->kv('Identifiant', (string) $cfg['client_login']);
    $pdf->kv('Téléphone', $cfg['telephone'] !== null && $cfg['telephone'] !== '' ? $cfg['telephone'] : '—');

    $pdf->h2('Projet');
    $pdf->kv('Plan de villa', (string) $cfg['plan_nom']);
    $pdf->kv('Formule', (string) $cfg['formule_nom']);
    $pdf->kv('Statut', $libelleStatut[$cfg['statut']] ?? $cfg['statut']);
    if ($cfg['date_soumission']) {
        $pdf->kv('Validée par le client le', date('d/m/Y à H:i', strtotime($cfg['date_soumission'])));
    }
    $pdf->kv('Prix de base', euros($cfg['prix_base']));

    $pdf->h2('Choix par pièce');
    if (!$lignes) {
        $pdf->text('Aucun produit choisi.');
    } else {
        $widths = [120, 105, 150, 70]; // Catégorie / Pièce / Produit / Supplément
        $pdf->row(['Catégorie', 'Pièce', 'Produit', 'Supplément'], $widths, true);
        foreach ($lignes as $l) {
            $piece = $l['piece'] . ($l['etage'] ? ' (' . $l['etage'] . ')' : '');
            $supp = $l['supplement'] > 0 ? '+ ' . euros($l['supplement']) : '—';
            $pdf->row([$l['categorie'], $piece, $l['produit'], $supp], $widths);
        }
    }

    $pdf->totalLine('Total estimé', euros($cfg['prix_total']));

    $pdf->spacer(18);
    $pdf->text('Document généré automatiquement par ' . config('marque')
        . ' (' . config('sous_marque') . '). Ce récapitulatif reprend les choix '
        . 'enregistrés par le client au moment de la validation.', 9);

    return $pdf->output();
}
