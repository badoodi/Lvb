<?php
/**
 * Gestion des administrateurs (réservée au webmaster).
 * Crée/modifie/supprime des comptes admin, définit le statut webmaster et,
 * pour les admins standard, coche les rubriques du menu qui leur sont ouvertes.
 */
require_once __DIR__ . '/../../lib/crud.php';
$moi = admin_connecte();
$moiId = (int) ($moi['id'] ?? 0);

/** Checklist des rubriques accessibles (ignorée si le compte est webmaster). */
function admins_extra_form(?array $ligne): string
{
    $actuelles = $ligne ? admin_acces_cles((int) $ligne['id']) : [];
    $html = '<div class="champs-perso"><p class="champs-perso-titre">Rubriques accessibles (admin standard)</p>'
          . '<p class="field-aide">Cochez les rubriques du menu que ce compte pourra voir. '
          . 'Ignoré si « Webmaster » est activé (accès total).</p><div class="acces-liste">';
    foreach (admin_menus_attribuables() as $cle => [$url, $label]) {
        $coche = in_array($cle, $actuelles, true) ? ' checked' : '';
        $html .= '<label class="acces-item"><input type="checkbox" name="acces[]" value="' . h($cle) . '"' . $coche . '> '
               . h($label) . '</label>';
    }
    $html .= '</div></div>';
    return $html;
}

/** Enregistre les rubriques autorisées après écriture de l'admin. */
function admins_apres_ecrire(int $id, bool $creation): void
{
    $webmaster = (int) ($_POST['est_webmaster'] ?? 0) === 1;
    db()->prepare('DELETE FROM admin_acces WHERE admin_id = ?')->execute([$id]);
    if (!$webmaster) {
        $valides = array_keys(admin_menus_attribuables());
        $ins = db()->prepare('INSERT INTO admin_acces (admin_id, menu_cle) VALUES (?, ?)');
        foreach (($_POST['acces'] ?? []) as $cle) {
            if (in_array($cle, $valides, true)) {
                $ins->execute([$id, (string) $cle]);
            }
        }
    }
}

crud_page([
    'table'         => 'administrateurs',
    'menu'          => 'admins',
    'titre'         => 'Administrateurs',
    'singulier'     => 'un administrateur',
    'liste_sql'     => 'SELECT id, identifiant,
                               CASE WHEN est_webmaster = 1 THEN \'Webmaster\' ELSE \'Admin standard\' END AS role,
                               DATE_FORMAT(date_creation, \'%d/%m/%Y\') AS cree_le
                        FROM administrateurs ORDER BY est_webmaster DESC, identifiant',
    'colonnes'      => [
        'identifiant' => 'Identifiant',
        'role'        => 'Rôle',
        'cree_le'     => 'Créé le',
    ],
    'champs' => [
        ['nom' => 'identifiant', 'label' => 'Identifiant', 'type' => 'text', 'requis' => true],
        ['nom' => 'mot_de_passe', 'label' => 'Mot de passe', 'type' => 'password', 'requis' => true,
         'aide' => 'À la modification, laisser vide pour conserver le mot de passe actuel.'],
        ['nom' => 'est_webmaster', 'label' => 'Webmaster (accès total + gestion des admins)', 'type' => 'bool', 'defaut' => 0],
    ],
    'extra_form'   => 'admins_extra_form',
    'apres_ecrire' => 'admins_apres_ecrire',
    'avant_ecrire' => function (array &$valeurs, bool $creation) {
        if ($creation && empty($valeurs['mot_de_passe'])) {
            flash('Le mot de passe est obligatoire à la création d\'un administrateur.', 'erreur');
            redirect(base_url() . '/admin/crud/admins.php?form=new');
        }
        // Ne pas retirer le statut au dernier webmaster (risque de blocage total).
        $id = (int) ($_POST['id'] ?? 0);
        $webmaster = (int) ($_POST['est_webmaster'] ?? 0) === 1;
        if ($id && !$webmaster) {
            $etait = (int) db()->query('SELECT est_webmaster FROM administrateurs WHERE id = ' . $id)->fetchColumn();
            $nb = (int) db()->query('SELECT COUNT(*) FROM administrateurs WHERE est_webmaster = 1')->fetchColumn();
            if ($etait === 1 && $nb <= 1) {
                flash('Impossible de retirer le statut au dernier webmaster.', 'erreur');
                redirect(base_url() . '/admin/crud/admins.php?form=' . $id);
            }
        }
    },
    'avant_supprimer' => function (int $id) use ($moiId) {
        if ($id === $moiId) {
            return 'Vous ne pouvez pas supprimer votre propre compte.';
        }
        $wm = (int) db()->query('SELECT est_webmaster FROM administrateurs WHERE id = ' . (int) $id)->fetchColumn();
        if ($wm === 1) {
            $nb = (int) db()->query('SELECT COUNT(*) FROM administrateurs WHERE est_webmaster = 1')->fetchColumn();
            if ($nb <= 1) {
                return 'Impossible de supprimer le dernier webmaster.';
            }
        }
        return null;
    },
]);
