<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(500);
    exit('Не найден inc/config.php. Скопируйте inc/config.sample.php в inc/config.php и заполните данные базы.');
}
require __DIR__ . '/config.php';

date_default_timezone_set(APP_TZ);
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

session_name('sailsid');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => is_https(),
    'httponly' => true, 'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/training_lib.php';
require __DIR__ . '/layout.php';

require __DIR__ . '/regatta_lib.php';
require __DIR__ . '/crew_panel.php';
require __DIR__ . '/webpush.php';

// Обновление структуры базы для уже установленных копий (один раз за сессию)
const SCHEMA_VERSION = 10;
if (($_SESSION['schema_v'] ?? 0) < SCHEMA_VERSION) {
    require_once __DIR__ . '/schema.php';
    try { if (run_migrations(DB_DRIVER)) $_SESSION['schema_v'] = SCHEMA_VERSION; } catch (Throwable $e) { /* попробуем при следующем запросе */ }
}
