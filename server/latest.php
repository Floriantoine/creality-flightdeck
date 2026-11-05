<?php
// Donne les dernières alertes : au téléphone abonné (prouvé par son abonnement) ou avec le jeton.
require __DIR__ . '/lib.php';
cors();
$in = input();
$ok = false;
if (!empty($in['endpoint'])) {
    $subs = jread('subs.json', []);
    $ok = isset($subs[substr(sha1((string)$in['endpoint']), 0, 16)]);
}
if (!$ok) check_token($in);
$n = max(1, min(50, (int)($in['n'] ?? 20)));
out(200, ['messages' => array_slice(jread('messages.json', []), 0, $n), 'devices' => count(jread('subs.json', []))]);
