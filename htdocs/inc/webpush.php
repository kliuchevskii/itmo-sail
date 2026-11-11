<?php
declare(strict_types=1);

/*
 * Web Push без сторонних библиотек: шифрование RFC 8291 (aes128gcm) и подпись VAPID (RFC 8292).
 * Нужны только стандартные расширения PHP: openssl и curl.
 * Ключи VAPID создаются автоматически при первом использовании и хранятся в таблице settings.
 */

function b64u_enc(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

function setting_get(string $k): ?string { $v = val('SELECT v FROM settings WHERE k=?', [$k]); return $v === null ? null : (string) $v; }
function setting_set(string $k, string $v): void
{
    q('DELETE FROM settings WHERE k=?', [$k]);
    q('INSERT INTO settings (k, v) VALUES (?, ?)', [$k, $v]);
}

/** Несжатая точка P-256 (65 байт) из деталей ключа openssl */
function ec_public_raw($key): string
{
    $d = openssl_pkey_get_details($key);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}
/** PEM открытого ключа P-256 из несжатой точки */
function ec_public_pem(string $raw65): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw65;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
/** PEM закрытого ключа P-256 из d (32 байта) и открытой точки — для тестов с известными ключами */
function ec_private_pem(string $d32, string $pub65): string
{
    $der = hex2bin('30770201010420') . $d32 . hex2bin('a00a06082a8648ce3d030107a144034200') . $pub65;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}
function ec_new_key()
{
    return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
}

/** Ключи VAPID: ['public' => base64url (65 байт), 'pem' => закрытый ключ] */
function vapid_keys(): array
{
    $pub = setting_get('vapid_public');
    $pem = setting_get('vapid_private');
    if ($pub && $pem) return ['public' => $pub, 'pem' => $pem];
    $key = ec_new_key();
    if (!$key) throw new RuntimeException('openssl не смог создать ключ EC');
    openssl_pkey_export($key, $pem);
    $pub = b64u_enc(ec_public_raw($key));
    setting_set('vapid_public', $pub);
    setting_set('vapid_private', $pem);
    return ['public' => $pub, 'pem' => $pem];
}

/** Подпись ES256: openssl отдаёт DER, JWT нужен «сырой» r||s по 32 байта */
function der_to_raw_sig(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $int = ltrim($int, "\0");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}
function vapid_header(string $endpoint, array $keys, string $subject): string
{
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $data = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.'
          . b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $subject]));
    openssl_sign($data, $sig, $keys['pem'], OPENSSL_ALGO_SHA256);
    return 'vapid t=' . $data . '.' . b64u_enc(der_to_raw_sig($sig)) . ', k=' . $keys['public'];
}

function hmac256(string $key, string $data): string { return hash_hmac('sha256', $data, $key, true); }

/**
 * Шифрование содержимого уведомления (RFC 8291).
 * $uaPublic — ключ p256dh подписки (65 байт), $auth — секрет подписки (16 байт).
 * $asKey и $salt передаются только в тестах, в работе создаются случайно.
 */
function webpush_encrypt(string $payload, string $uaPublic, string $auth, $asKey = null, ?string $salt = null): string
{
    $asKey ??= ec_new_key();
    $salt ??= random_bytes(16);
    $asPublic = ec_public_raw($asKey);
    $ecdh = openssl_pkey_derive(openssl_pkey_get_public(ec_public_pem($uaPublic)), $asKey, 32);
    if ($ecdh === false) throw new RuntimeException('ECDH не удался');
    $prkKey = hmac256($auth, $ecdh);
    $ikm = hmac256($prkKey, "WebPush: info\0" . $uaPublic . $asPublic . "\x01");
    $prk = hmac256($salt, $ikm);
    $cek = substr(hmac256($prk, "Content-Encoding: aes128gcm\0\x01"), 0, 16);
    $nonce = substr(hmac256($prk, "Content-Encoding: nonce\0\x01"), 0, 12);
    $tag = '';
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/** Подготовить запрос curl для одной подписки */
function webpush_request(array $sub, array $message, array $keys, string $subject)
{
    $body = webpush_encrypt(json_encode($message, JSON_UNESCAPED_UNICODE), b64u_dec($sub['p256dh']), b64u_dec($sub['auth']));
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 172800',
            'Urgency: high',
            'Authorization: ' . vapid_header($sub['endpoint'], $keys, $subject),
        ],
    ]);
    return $ch;
}

/**
 * Отправить уведомления пачкой. $items: [[подписка, сообщение], ...].
 * Возвращает [id подписки => HTTP-код]. Подписки, которые сервис объявил недействительными (404/410), удаляются.
 */
function webpush_send_many(array $items): array
{
    if (!$items) return [];
    $keys = vapid_keys();
    $subject = 'mailto:noreply@' . preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['HTTP_HOST'] ?? 'itmosail.local');
    $result = [];
    $multi = function_exists('curl_multi_init') && function_exists('curl_multi_exec') && function_exists('curl_multi_select')
          && function_exists('curl_multi_add_handle') && function_exists('curl_multi_remove_handle');
    if (!$multi) {
        // Хостинг отключил «пакетные» функции curl — отправляем по одному
        foreach ($items as [$sub, $msg]) {
            $GLOBALS['PUSH_LAST_ERROR'] = '';
            try {
                $ch = webpush_request($sub, $msg, $keys, $subject);
                curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if (!$code) $GLOBALS['PUSH_LAST_ERROR'] = curl_error($ch);
                curl_close($ch);
            } catch (Throwable $e) { $code = 0; $GLOBALS['PUSH_LAST_ERROR'] = $e->getMessage(); }
            $result[$sub['id']] = $code;
            webpush_after((int) $sub['id'], $code);
        }
        return $result;
    }
    foreach (array_chunk($items, 10) as $chunk) {
        $mh = curl_multi_init();
        $map = [];
        foreach ($chunk as [$sub, $msg]) {
            try { $ch = webpush_request($sub, $msg, $keys, $subject); } catch (Throwable $e) { $result[$sub['id']] = 0; $GLOBALS['PUSH_LAST_ERROR'] = $e->getMessage(); continue; }
            curl_multi_add_handle($mh, $ch);
            $map[(int) $sub['id']] = $ch;
        }
        do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
        foreach ($map as $sid => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (!$code) $GLOBALS['PUSH_LAST_ERROR'] = curl_error($ch) ?: 'нет ответа';
            $result[$sid] = $code;
            webpush_after($sid, $code);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $result;
}

function webpush_after(int $sid, int $code): void
{
    if ($code === 404 || $code === 410) q('DELETE FROM push_subscriptions WHERE id=?', [$sid]);
    elseif ($code >= 200 && $code < 300) q('UPDATE push_subscriptions SET last_ok=? WHERE id=?', [now(), $sid]);
}

/** Диагностика возможностей хостинга для пушей */
function webpush_diag(): array
{
    $r = [];
    $r['PHP'] = PHP_VERSION;
    $r['openssl'] = extension_loaded('openssl') ? 'есть' : 'НЕТ';
    $r['curl'] = function_exists('curl_init') ? 'есть' : 'НЕТ';
    $r['curl_multi'] = (function_exists('curl_multi_init') && function_exists('curl_multi_select')) ? 'есть' : 'нет (отправка по одному)';
    foreach (['openssl_pkey_new', 'openssl_pkey_derive', 'openssl_sign', 'openssl_encrypt', 'hash_hmac'] as $f) $r[$f] = function_exists($f) ? 'есть' : 'НЕТ';
    try { $k = ec_new_key(); $r['ключ EC'] = $k ? 'создаётся' : 'НЕ создаётся: ' . openssl_error_string(); } catch (Throwable $e) { $r['ключ EC'] = 'ошибка: ' . $e->getMessage(); }
    try {
        $ua = ec_new_key();
        webpush_encrypt('test', ec_public_raw($ua), random_bytes(16));
        $r['шифрование'] = 'работает';
    } catch (Throwable $e) { $r['шифрование'] = 'ошибка: ' . $e->getMessage(); }
    if (function_exists('curl_init')) {
        $ch = curl_init('https://fcm.googleapis.com/');
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $r['связь с Google'] = $code ? "есть (ответ $code)" : 'НЕТ: ' . curl_error($ch);
        curl_close($ch);
    }
    return $r;
}

/* ---------- очередь: уведомления копятся за запрос и уходят после ответа браузеру ---------- */

const PUSH_CATS = ['training' => 'Тренировки', 'crew' => 'Экипаж', 'regatta' => 'Регаты', 'other' => 'Профиль'];

/** Раздел уведомления — по ссылке, на которую оно ведёт */
function notif_category(string $url): string
{
    if (str_starts_with($url, 'training.php') || str_starts_with($url, 'index.php') || str_starts_with($url, 'day.php')) return 'training';
    if (str_starts_with($url, 'event.php') || str_starts_with($url, 'events.php')) return 'regatta';
    if (str_contains($url, 'tab=crew')) return 'crew';
    return 'other';
}

function push_queue(int $uid, string $body, string $url): void
{
    static $registered = false;
    $GLOBALS['PUSH_QUEUE'][] = [$uid, $body, $url];
    if (!$registered) { register_shutdown_function('push_flush'); $registered = true; }
}

function push_flush(): void
{
    $queue = $GLOBALS['PUSH_QUEUE'] ?? [];
    $GLOBALS['PUSH_QUEUE'] = [];
    if (!$queue) return;
    // Сначала отдаём ответ браузеру, рассылка идёт «в фоне»
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    @set_time_limit(40);
    ignore_user_abort(true);
    try {
        $uids = array_values(array_unique(array_map(fn($x) => (int) $x[0], $queue)));
        $subs = [];
        foreach (all('SELECT s.*, u.push_off FROM push_subscriptions s JOIN users u ON u.id=s.user_id WHERE u.active=1 AND s.user_id IN (' . in_list($uids) . ')', $uids) as $s) {
            $subs[(int) $s['user_id']][] = $s;
        }
        $items = [];
        $base = BASE_URL . '/';
        foreach ($queue as [$uid, $body, $url]) {
            $cat = notif_category($url);
            foreach ($subs[$uid] ?? [] as $s) {
                if (in_array($cat, explode(',', (string) $s['push_off']), true)) continue;
                $items[] = [$s, [
                    'title' => 'ITMO Sail · ' . PUSH_CATS[$cat],
                    'body' => mb_substr($body, 0, 400),
                    'url' => $base . ($url ?: 'notifications.php'),
                    'tag' => $cat . '-' . md5($url . $body),
                ]];
            }
        }
        webpush_send_many($items);
    } catch (Throwable $e) {
        error_log('push: ' . $e->getMessage());
    }
}
