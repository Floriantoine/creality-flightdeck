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
