<?php
// Enregistre (ou retire) un téléphone qui veut recevoir les notifications.
require __DIR__ . '/lib.php';
cors();
$in = input();

// GET ?key → clé publique VAPID (nécessaire au téléphone pour s'abonner)
if ($_SERVER['REQUEST_METHOD'] === 'GET') out(200, ['publicKey' => vapid()['public']]);

check_token($in);
$sub = $in['subscription'] ?? null;
if (!is_array($sub) || empty($sub['endpoint']) || !preg_match('#^https://#', $sub['endpoint']))
    out(400, ['error' => 'Abonnement invalide']);
// Seulement les services push des navigateurs (empêche d'utiliser le serveur pour contacter d'autres adresses)
$host = strtolower(parse_url($sub['endpoint'], PHP_URL_HOST) ?: '');
if (!preg_match('/(^|\.)(fcm\.googleapis\.com|push\.services\.mozilla\.com|notify\.windows\.com|push\.apple\.com)$/', $host))
    out(400, ['error' => 'Service push non reconnu']);

$subs = jread('subs.json', []);
$id = substr(sha1($sub['endpoint']), 0, 16);
if (!empty($in['remove'])) {
    unset($subs[$id]);
    jwrite('subs.json', $subs);
    out(200, ['ok' => true, 'removed' => $id]);
}
$subs[$id] = ['endpoint' => $sub['endpoint'], 'name' => mb_substr((string)($in['name'] ?? 'Téléphone'), 0, 40), 'since' => time()];
jwrite('subs.json', $subs);
out(200, ['ok' => true, 'id' => $id, 'devices' => count($subs)]);
