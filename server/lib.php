<?php
// K1 Alertes — fonctions communes (pas besoin de modifier ce fichier)

const DATA = __DIR__ . '/data';

function cfg() {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function jread($f, $def) {
    $p = DATA . "/$f";
    if (!is_file($p)) return $def;
    $j = json_decode(file_get_contents($p), true);
    return $j === null ? $def : $j;
}

function jwrite($f, $v) {
    if (!is_dir(DATA)) mkdir(DATA, 0700, true);
    file_put_contents(DATA . "/$f", json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// Autorise le tableau de bord (ouvert depuis ton PC) à appeler ce serveur
function cors() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, X-Token');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

function out($code, $arr) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input() {
    $j = json_decode(file_get_contents('php://input'), true);
    return is_array($j) ? $j : $_POST;
}

function check_token($in) {
    $t = cfg()['token'] ?? '';
    if (!$t || strlen($t) < 12 || strpos($t, '__') === 0) out(500, ['error' => 'Configure un jeton dans config.php']);
    $given = $_SERVER['HTTP_X_TOKEN'] ?? ($in['token'] ?? '');
    if (!is_string($given) || !hash_equals($t, $given)) { usleep(400000); out(403, ['error' => 'Jeton invalide']); }
}

/* ---------------- Clés VAPID (créées automatiquement au premier appel) ---------------- */
function vapid() {
    $v = jread('vapid.json', null);
    if ($v) return $v;
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k) out(500, ['error' => 'OpenSSL ne peut pas créer de clé EC sur ce serveur']);
    openssl_pkey_export($k, $pem);
    $d = openssl_pkey_get_details($k);
    $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $v = ['private_pem' => $pem, 'public' => b64u($pub)];
    jwrite('vapid.json', $v);
    return $v;
}

// Signature OpenSSL (format DER) → format brut r||s de 64 octets exigé par JWT ES256
function der2raw($der) {
    $o = 2;
    if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
    $raw = '';
    for ($i = 0; $i < 2; $i++) {
        $o++;                              // octet 0x02 (INTEGER)
        $len = ord($der[$o]); $o++;
        $v = ltrim(substr($der, $o, $len), "\0");
        $o += $len;
        $raw .= str_pad($v, 32, "\0", STR_PAD_LEFT);
    }
    return $raw;
}

function vapid_jwt($endpoint, $vapid) {
    $u = parse_url($endpoint);
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $from = cfg()['from'] ?? 'alertes@example.com';
    $h = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $p = b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . $from]));
    openssl_sign("$h.$p", $der, $vapid['private_pem'], OPENSSL_ALGO_SHA256);
    return "$h.$p." . b64u(der2raw($der));
}

// Envoie un « réveil » sans contenu ; le téléphone va ensuite lire le message sur latest.php.
// (Pas de contenu = pas de chiffrement compliqué à faire en PHP.)
function push_one($sub, $vapid, $urgent) {
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'TTL: 86400',
            'Urgency: ' . ($urgent ? 'high' : 'normal'),
            'Authorization: vapid t=' . vapid_jwt($sub['endpoint'], $vapid) . ', k=' . $vapid['public'],
            'Content-Length: 0',
        ],
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

function send_mail($to, $subject, $body, $imgBin = null, $imgName = null) {
    $from = cfg()['from'] ?? '';
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    if (!$imgBin) {
        $headers = "From: K1 Max <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
        return @mail($to, $subj, $body, $headers, $from ? "-f$from" : '');
    }
    // Courriel avec la capture en pièce jointe
    $b = 'k1-' . bin2hex(random_bytes(8));
    $mime = substr($imgName, -4) === '.png' ? 'image/png' : 'image/jpeg';
    $headers = "From: K1 Max <$from>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"";
    $msg = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$body\r\n"
         . "--$b\r\nContent-Type: $mime; name=\"$imgName\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$imgName\"\r\n\r\n"
         . chunk_split(base64_encode($imgBin)) . "--$b--\r\n";
    return @mail($to, $subj, $msg, $headers, $from ? "-f$from" : '');
}
