<?php
declare(strict_types=1);

const CREW_ROLES = ['helm' => 'Рулевой', 'main' => 'Гротовый', 'sheet' => 'Шкотовый', 'bow' => 'Баковый', 'tactic' => 'Тактик'];
const LEVELS     = [0 => 'Новичок', 1 => 'Базовый', 2 => 'Опытный', 3 => 'Продвинутый'];
const CLEARANCES = [0 => 'Нет допуска', 1 => 'Ограниченный', 2 => 'Полный'];
const PROGRAMS   = ['bachelor' => 'Бакалавриат', 'master' => 'Магистратура', 'specialist' => 'Специалитет', 'staff' => 'Сотрудник', 'other' => 'Выпускник / другое'];
const PROGRAM_YEARS = ['bachelor' => 4, 'master' => 2, 'specialist' => 6];
const ROLES      = ['member' => 'Спортсмен', 'admin' => 'Администратор секции', 'superadmin' => 'Администратор платформы'];
const DOC_TYPES  = ['medical' => 'Справка-допуск', 'insurance' => 'Спортивная страховка'];

const RU_DOW   = [1 => 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];
const RU_DOW_S = [1 => 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
const RU_MON_G = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
const RU_MON   = [1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

if (!defined('POINTS_PER_HOUR')) define('POINTS_PER_HOUR', 5);
if (!defined('MIN_CREW')) define('MIN_CREW', 3);

/** Баллы за физкультуру за тренировку: 5 за каждый час */
function training_points(array $t): int
{
    $hours = (strtotime($t['end_at']) - strtotime($t['start_at'])) / 3600;
    return (int) round(POINTS_PER_HOUR * $hours);
}

function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function url(string $p = ''): string { return BASE_URL . '/' . ltrim($p, '/'); }

function redirect(string $p): never
{
    header('Location: ' . url($p));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Сессия устарела. Вернитесь назад и обновите страницу.');
    }
}

function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }
function take_flash(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function post(string $k, $d = '') { $v = $_POST[$k] ?? $d; return is_string($v) ? trim($v) : $v; }
function get(string $k, $d = '') { $v = $_GET[$k] ?? $d; return is_string($v) ? trim($v) : $v; }

/** Разрешаем возвращаться только на свои страницы */
function safe_back(string $b, string $def = 'index.php'): string
{
    $b = ltrim($b, '/');
    if (BASE_URL !== '' && str_starts_with('/' . $b, BASE_URL . '/')) $b = substr('/' . $b, strlen(BASE_URL) + 1);
    return preg_match('~^[a-z0-9_/\-]+\.php(\?[A-Za-z0-9_=&%\-\.]*)?$~', $b) ? $b : $def;
}

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function ru_date(string $dt, bool $withYear = false): string
{
    $t = strtotime($dt);
    return (int) date('j', $t) . ' ' . RU_MON_G[(int) date('n', $t)] . ($withYear ? ' ' . date('Y', $t) : '');
}
function ru_dow(string $dt): string { return RU_DOW[(int) date('N', strtotime($dt))]; }
function hm(string $dt): string { return date('H:i', strtotime($dt)); }

function plural(int $n, string $one, string $few, string $many): string
{
    $n = abs($n) % 100; $n1 = $n % 10;
    if ($n > 10 && $n < 20) return $many;
    if ($n1 > 1 && $n1 < 5) return $few;
    if ($n1 == 1) return $one;
    return $many;
}

/* ---------- Курс и баллы за физкультуру ---------- */

/** Учебный год начинается 1 сентября: в ноябре 2026 это 2026, в марте 2027 — тоже 2026 */
function academic_year(?int $ts = null): int
{
    $ts ??= time();
    $y = (int) date('Y', $ts);
    return (int) date('n', $ts) >= 9 ? $y : $y - 1;
}
function enroll_year_for_course(int $course): int { return academic_year() - $course + 1; }

function user_course(array $u): ?int
{
    if (!isset(PROGRAM_YEARS[$u['program']]) || !$u['enroll_year']) return null;
    return academic_year() - (int) $u['enroll_year'] + 1;
}
function course_label(array $u): string
{
    $c = user_course($u);
    if ($c === null) return PROGRAMS[$u['program']] ?? '—';
    $max = PROGRAM_YEARS[$u['program']];
    if ($c > $max) return 'Выпускник (' . mb_strtolower(PROGRAMS[$u['program']]) . ')';
    $short = ['bachelor' => 'бак.', 'master' => 'маг.', 'specialist' => 'спец.'][$u['program']];
    return max(1, $c) . ' курс, ' . $short;
}
/** Баллы нужны бакалаврам и специалистам 1–3 курса */
function needs_points(array $u): bool
{
    $c = user_course($u);
    return in_array($u['program'], ['bachelor', 'specialist'], true) && $c !== null && $c >= 1 && $c <= 3;
}

/* ---------- Имена ---------- */

const GENDERS = ['m' => 'Мужской', 'f' => 'Женский'];
/** Слово в нужном роде: gw($u, 'создал', 'создала'). Пол не указан — «создал(а)» */
function gw(array $u, string $m, string $f, ?string $any = null): string
{
    $g = $u['gender'] ?? '';
    if ($g === 'm') return $m;
    if ($g === 'f') return $f;
    if ($any !== null) return $any;
    return str_starts_with($f, $m) ? $m . '(' . mb_substr($f, mb_strlen($m)) . ')' : $m . '/' . $f;
}
/** Короткое имя и глагол: who($u, 'создал', 'создала') → «Иванов И. И. создал» */
function who(array $u, string $m, string $f, ?string $any = null): string { return short_name($u) . ' ' . gw($u, $m, $f, $any); }

function full_name(array $u): string
{
    return trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? '') . ' ' . ($u['middle_name'] ?? '')) ?: ($u['login'] ?? '');
}
function short_name(array $u): string
{
    $s = $u['last_name'] ?? '';
    if (!empty($u['first_name'])) $s .= ' ' . mb_substr($u['first_name'], 0, 1) . '.';
    if (!empty($u['middle_name'])) $s .= ' ' . mb_substr($u['middle_name'], 0, 1) . '.';
    return trim($s) ?: ($u['login'] ?? '');
}
function initials(array $u): string
{
    return mb_strtoupper(mb_substr($u['first_name'] ?: $u['login'], 0, 1) . mb_substr($u['last_name'] ?? '', 0, 1));
}
function crew_roles_label(string $csv): string
{
    $out = [];
    foreach (array_filter(explode(',', $csv)) as $k) if (isset(CREW_ROLES[$k])) $out[] = CREW_ROLES[$k];
    return $out ? implode(', ', $out) : '—';
}

/* ---------- Опыт ---------- */

function user_experience(int $uid): array
{
    $u = one('SELECT extra_trainings, extra_regattas FROM users WHERE id=?', [$uid]);
    $asPart = (int) val("SELECT COUNT(*) FROM training_people tp JOIN trainings t ON t.id=tp.training_id
                         WHERE tp.user_id=? AND tp.attended=1 AND t.report_done=1", [$uid]);
    $asInstr = (int) val("SELECT COUNT(*) FROM trainings WHERE instructor_id=? AND report_done=1 AND status<>'cancelled'", [$uid]);
    $reg = (int) val("SELECT COUNT(*) FROM event_people WHERE user_id=? AND status='participated'", [$uid]);
    return [
        'trainings' => $asPart + $asInstr + (int) ($u['extra_trainings'] ?? 0),
        'as_instructor' => $asInstr,
        'regattas' => $reg + (int) ($u['extra_regattas'] ?? 0),
    ];
}

function icon(string $name, string $cls = 'ic'): string
{
    return '<svg class="' . $cls . '" aria-hidden="true"><use href="#i-' . $name . '"/></svg>';
}
