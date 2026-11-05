<?php
// Reçoit une alerte du tableau de bord et la distribue : push Android, courriels, SMS.
require __DIR__ . '/lib.php';
cors();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['error' => 'POST seulement']);
$in = input();
check_token($in);

$title = mb_substr(trim((string)($in['title'] ?? 'K1 Max')), 0, 120);
$body  = mb_substr(trim((string)($in['body'] ?? '')), 0, 1000);
$urgent = ($in['priority'] ?? 'high') !== 'normal';
$test = !empty($in['test']);

// Anti-abus : max N alertes par heure
$rate = array_filter(jread('rate.json', []), fn($t) => $t > time() - 3600);
if (count($rate) >= (cfg()['max_per_hour'] ?? 30)) out(429, ['error' => 'Trop d\'alertes cette heure-ci']);
$rate[] = time(); jwrite('rate.json', array_values($rate));

// Capture jointe (image JPEG/PNG en data URL, max 3 Mo)
$imgFile = null; $imgBin = null;
if (!empty($in['image']) && is_string($in['image']) && preg_match('#^data:image/(jpeg|png);base64,#', $in['image'], $mm)) {
    $imgBin = base64_decode(substr($in['image'], strpos($in['image'], ',') + 1), true);
    $isJpg = $imgBin && substr($imgBin, 0, 3) === "\xFF\xD8\xFF";
    $isPng = $imgBin && substr($imgBin, 0, 8) === "\x89PNG\r\n\x1a\n";
    if ($imgBin && strlen($imgBin) <= 3 * 1024 * 1024 && ($isJpg || $isPng)) {
        $dir = __DIR__ . '/captures';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $imgFile = 'captures/' . bin2hex(random_bytes(16)) . ($isJpg ? '.jpg' : '.png');   // nom impossible à deviner
        file_put_contents(__DIR__ . '/' . $imgFile, $imgBin);
        // On garde seulement les 30 dernières captures
        $all = array_merge(glob($dir . '/*.jpg') ?: [], glob($dir . '/*.png') ?: []);
        usort($all, fn($a, $b) => filemtime($b) - filemtime($a));
        foreach (array_slice($all, 30) as $old) @unlink($old);
    } else $imgBin = null;
}

// Historique (lu par le téléphone quand il reçoit le « réveil »)
$msg = ['id' => bin2hex(random_bytes(6)), 'time' => time(), 'title' => $title, 'body' => $body,
        'priority' => $urgent ? 'high' : 'normal', 'test' => $test, 'image' => $imgFile];
$msgs = jread('messages.json', []);
array_unshift($msgs, $msg);
jwrite('messages.json', array_slice($msgs, 0, 50));

$res = ['push' => [], 'email' => [], 'sms' => []];

// 1) Push vers chaque téléphone abonné
$subs = jread('subs.json', []);
if ($subs) {
    $vapid = vapid();
    foreach ($subs as $id => $s) {
        $code = push_one($s, $vapid, $urgent);
        $res['push'][] = ['name' => $s['name'] ?? $id, 'code' => $code];
        if ($code == 404 || $code == 410) unset($subs[$id]);          // abonnement expiré
    }
    jwrite('subs.json', $subs);
}

// 2) Courriels
foreach (cfg()['emails'] ?? [] as $to)
    $res['email'][] = ['to' => $to, 'ok' => send_mail($to, $title, $body . "\n\n— " . date('Y-m-d H:i:s'), $imgBin, $imgFile ? basename($imgFile) : null)];

// 3) SMS (texte court, la passerelle de l'opérateur ignore souvent le sujet)
foreach (cfg()['sms'] ?? [] as $to)
    $res['sms'][] = ['to' => $to, 'ok' => send_mail($to, 'K1', mb_substr("$title: $body", 0, 150))];

out(200, ['ok' => true, 'id' => $msg['id'], 'results' => $res]);
