<?php
declare(strict_types=1);

const REMEMBER_COOKIE = 'sailrem';

function current_user(bool $reload = false): ?array
{
    static $u = false;
    if ($u !== false && !$reload) return $u;
    $id = $_SESSION['uid'] ?? null;
    if (!$id && !empty($_COOKIE[REMEMBER_COOKIE])) $id = remember_login();
    $u = $id ? one('SELECT * FROM users WHERE id=? AND active=1', [$id]) : null;
    if (!$u) unset($_SESSION['uid']);
    return $u;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) redirect('login.php');
    return $u;
}

function is_admin(array $u): bool { return in_array($u['role'], ['admin', 'superadmin'], true); }
function is_super(array $u): bool { return $u['role'] === 'superadmin'; }
function is_instructor(array $u): bool { return (int) $u['clearance'] >= 1; }

function require_admin(): array
{
    $u = require_login();
    if (!is_admin($u)) { http_response_code(403); exit('Доступ только для администраторов'); }
    return $u;
}

function login_user(int $id, bool $remember): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
    if ($remember) issue_remember($id);
}

function set_remember_cookie(string $value, int $expires): void
{
    setcookie(REMEMBER_COOKIE, $value, [
        'expires' => $expires, 'path' => '/', 'secure' => is_https(),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function issue_remember(int $uid): void
{
    $sel = bin2hex(random_bytes(9));
    $val = bin2hex(random_bytes(32));
    $exp = time() + REMEMBER_DAYS * 86400;
    q('INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at) VALUES (?,?,?,?)',
        [$uid, $sel, hash('sha256', $val), date('Y-m-d H:i:s', $exp)]);
    set_remember_cookie("$sel:$val", $exp);
}

function remember_login(): ?int
{
    $parts = explode(':', (string) $_COOKIE[REMEMBER_COOKIE], 2);
    if (count($parts) !== 2) { set_remember_cookie('', time() - 3600); return null; }
    [$sel, $val] = $parts;
    $row = one('SELECT * FROM auth_tokens WHERE selector=?', [$sel]);
    if (!$row || $row['expires_at'] < now() || !hash_equals($row['validator_hash'], hash('sha256', $val))) {
        if ($row) q('DELETE FROM auth_tokens WHERE id=?', [$row['id']]);
        set_remember_cookie('', time() - 3600);
        return null;
    }
    // Ротация токена при каждом автоматическом входе
    q('DELETE FROM auth_tokens WHERE id=?', [$row['id']]);
    $uid = (int) $row['user_id'];
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    issue_remember($uid);
    return $uid;
}

function logout_user(): void
{
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        $sel = explode(':', (string) $_COOKIE[REMEMBER_COOKIE])[0];
        q('DELETE FROM auth_tokens WHERE selector=?', [$sel]);
        set_remember_cookie('', time() - 3600);
    }
    $_SESSION = [];
    session_destroy();
}
