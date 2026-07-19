<?php
require_once __DIR__ . '/../../lib/crud.php';
$admin = admin_connecte();

crud_page([
    'table'     => 'clients',
    'menu'      => 'clients',
    'titre'     => 'Clients',
    'singulier' => 'un client',
    'liste_sql' => 'SELECT c.id, c.nom, c.identifiant, c.telephone,
                           DATE_FORMAT(c.date_creation, \'%d/%m/%Y\') AS cree_le
                    FROM clients c ORDER BY c.date_creation DESC',
    'colonnes'  => [
        'nom'         => 'Nom',
        'identifiant' => 'Identifiant',
        'telephone'   => 'Téléphone',
        'cree_le'     => 'Créé le',
    ],
    'champs' => [
        ['nom' => 'nom', 'label' => 'Nom complet', 'type' => 'text', 'requis' => true],
        ['nom' => 'identifiant', 'label' => 'Identifiant (email ou login)', 'type' => 'text', 'requis' => true],
        ['nom' => 'telephone', 'label' => 'Téléphone', 'type' => 'text'],
        ['nom' => 'mot_de_passe', 'label' => 'Mot de passe', 'type' => 'password',
         'requis' => true, 'aide' => 'À la modification, laisser vide pour conserver le mot de passe actuel.'],
    ],
    'avant_ecrire' => function (array &$valeurs, bool $creation) use ($admin) {
        if ($creation) {
            $valeurs['cree_par'] = $admin['id'];
        }
    },
]);
