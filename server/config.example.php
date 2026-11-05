<?php
// =====================================================================
//  K1 Alertes — configuration
//  Copie ce fichier en config.php, puis modifie-le (cPanel > Gestionnaire de fichiers).
// =====================================================================
return [

    // Jeton secret : le tableau de bord et ton téléphone doivent donner ce jeton.
    // Garde-le pour toi. Tu peux le changer pour n'importe quelle longue chaîne.
    'token' => '__CHANGE_MOI__',

    // Adresse d'expéditeur des courriels. Mets une adresse de TON domaine
    // (crée-la dans cPanel > Comptes de messagerie), sinon les courriels finissent en spam.
    'from' => 'alertes@ton-domaine.com',

    // Courriels qui reçoivent les alertes (laisser vide [] pour aucun).
    'emails' => [
        // 'toi@gmail.com',
    ],

    // SMS via la passerelle courriel → SMS de ton opérateur (laisser vide [] pour aucun).
    // Exemples (à vérifier avec ton opérateur) :
    //   Bell : 4185551234@txt.bell.ca      Vidéotron : 4185551234@texto.videotron.ca
    //   Telus : 4185551234@msg.telus.com   Rogers : 4185551234@pcs.rogers.com
    //   Fido : 4185551234@fido.ca          Koodo : 4185551234@msg.koodomobile.com
    'sms' => [
        // '4185551234@txt.bell.ca',
    ],

    // Limite anti-abus : nombre maximum d'alertes par heure.
    'max_per_hour' => 30,

    // (Optionnel) Dossier des clés et abonnements, idéalement HORS de public_html.
    // Obligatoire si ton serveur est nginx (les .htaccess y sont ignorés).
    // 'data_dir' => '/home/TON_COMPTE/k1-alertes-data',
];
