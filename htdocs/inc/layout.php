<?php
declare(strict_types=1);

const ASSET_V = '29'; // версия статики — увеличьте после изменения css/js (и в sw.js)

function head_html(string $title): void
{
    ?><!doctype html>
<html lang="ru" data-theme="auto">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<meta name="theme-color" content="#f6f8fb" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0a0e15" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">
<script>
/* Манифест приложения создаётся прямо в браузере: файл manifest.webmanifest на InfinityFree
   перехватывает защитная проверка хостинга, и телефон предлагает только «ярлык» вместо установки. */
(function () {
  try {
    var b = location.origin + <?= json_encode(BASE_URL) ?> + '/';
    var m = {
      id: b, name: 'ITMO Sail — парусная секция', short_name: 'ITMO Sail', description: 'Запись на тренировки парусной секции ИТМО',
      lang: 'ru', start_url: b + 'index.php', scope: b, display: 'standalone',
      background_color: '#0a0e15', theme_color: '#12284d',
      icons: [
        { src: b + 'assets/icons/icon-192.png?v=17', sizes: '192x192', type: 'image/png', purpose: 'any' },
        { src: b + 'assets/icons/icon-512.png?v=17', sizes: '512x512', type: 'image/png', purpose: 'any' },
        { src: b + 'assets/icons/maskable-512.png?v=17', sizes: '512x512', type: 'image/png', purpose: 'maskable' }
      ]
    };
    var l = document.createElement('link');
    l.rel = 'manifest';
    l.href = URL.createObjectURL(new Blob([JSON.stringify(m)], { type: 'application/manifest+json' }));
    document.head.appendChild(l);
  } catch (e) {}
})();
</script>
<link rel="icon" type="image/png" sizes="32x32" href="<?= url('assets/icons/favicon-32.png?v=' . ASSET_V) ?>">
<link rel="icon" type="image/png" sizes="64x64" href="<?= url('assets/icons/favicon-64.png?v=' . ASSET_V) ?>">
<link rel="icon" type="image/svg+xml" href="<?= url('assets/icons/logo.svg?v=' . ASSET_V) ?>">
<link rel="apple-touch-icon" href="<?= url('assets/icons/apple-touch-icon.png?v=' . ASSET_V) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= url('assets/app.css?v=' . ASSET_V) ?>">
<script>
(function(){try{var t=localStorage.getItem('theme')||'auto';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();
</script>
<script src="<?= url('assets/app.js?v=' . ASSET_V) ?>" defer></script>
</head>
<?php
}

function icon_sprite(): void
{
    ?>
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
 <defs>
  <symbol id="i-sail" viewBox="0 0 24 24"><path d="M11 2 4 17h7z" fill="currentColor" stroke="none"/><path d="M13 5c3.5 3 5.5 7 6 12h-6z" fill="currentColor" stroke="none" opacity=".55"/><path d="M2 19.5c2 1.3 4 1.3 6 0s4-1.3 6 0 4 1.3 6 0" fill="none"/></symbol>
  <symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/></symbol>
  <symbol id="i-list" viewBox="0 0 24 24"><path d="M9 6.5h11M9 12h11M9 17.5h11"/><circle cx="4.5" cy="6.5" r="1" fill="currentColor"/><circle cx="4.5" cy="12" r="1" fill="currentColor"/><circle cx="4.5" cy="17.5" r="1" fill="currentColor"/></symbol>
  <symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><circle cx="17" cy="9" r="2.6"/><path d="M17 14.5c2.4.2 4 1.8 4.5 4.5"/></symbol>
  <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c.8-4.2 4-6.5 8-6.5s7.2 2.3 8 6.5"/></symbol>
  <symbol id="i-bell" viewBox="0 0 24 24"><path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15z"/><path d="M10 20.5a2.2 2.2 0 0 0 4 0"/></symbol>
  <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3 4.5 6v5.5c0 4.6 3.1 8.2 7.5 9.5 4.4-1.3 7.5-4.9 7.5-9.5V6z"/><path d="m9 12 2 2 4-4"/></symbol>
  <symbol id="i-anchor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="2.2"/><path d="M12 7.2V21M8 11h8M4.5 14.5c.6 3.8 3.7 6.5 7.5 6.5s6.9-2.7 7.5-6.5"/></symbol>
  <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/></symbol>
  <symbol id="i-moon" viewBox="0 0 24 24"><path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/></symbol>
  <symbol id="i-auto" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 3.5v17a8.5 8.5 0 0 0 0-17z" fill="currentColor"/></symbol>
  <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
  <symbol id="i-left" viewBox="0 0 24 24"><path d="m15 5-7 7 7 7"/></symbol>
  <symbol id="i-right" viewBox="0 0 24 24"><path d="m9 5 7 7-7 7"/></symbol>
  <symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></symbol>
  <symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5"/></symbol>
  <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></symbol>
  <symbol id="i-logout" viewBox="0 0 24 24"><path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4M10 16l-4-4 4-4M6 12h10"/></symbol>
  <symbol id="i-file" viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></symbol>
  <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 4v11M7 10.5l5 5 5-5M5 20h14"/></symbol>
  <symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/></symbol>
  <symbol id="i-trophy" viewBox="0 0 24 24"><path d="M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M7 6H4v1.5A3.5 3.5 0 0 0 7.5 11M17 6h3v1.5a3.5 3.5 0 0 1-3.5 3.5M12 14v4M8 20.5h8"/></symbol>
  <symbol id="i-grid" viewBox="0 0 24 24"><rect x="3.5" y="3.5" width="17" height="17" rx="3"/><path d="M3.5 9.5h17M3.5 15h17M9.5 3.5v17"/></symbol>
  <symbol id="i-edit" viewBox="0 0 24 24"><path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/></symbol>
  <symbol id="i-sort" viewBox="0 0 24 24"><path d="M7 4v16M3.5 16.5L7 20l3.5-3.5M17 20V4M13.5 7.5L17 4l3.5 3.5"/></symbol>
  <symbol id="i-filter" viewBox="0 0 24 24"><path d="M4 5h16l-6 7.5V19l-4-2v-4.5z"/></symbol>
 </defs>
</svg>
<?php
}

function nav_items(array $u): array
{
    $items = [
        ['index.php', 'calendar', 'Расписание', 'schedule'],
        ['my.php', 'list', 'Мои тренировки', 'my'],
    ];
    if (is_instructor($u)) $items[] = ['instructors.php', 'anchor', 'Запросы инструкторам', 'instructors'];
    $items[] = ['events.php', 'trophy', 'Регаты', 'events'];
    $items[] = ['profile.php?tab=crew', 'users', 'Экипаж', 'crew'];
    $items[] = ['profile.php', 'user', 'Профиль', 'profile'];
    $items[] = ['notifications.php', 'bell', 'Уведомления', 'notifications'];
    if (is_admin($u)) $items[] = ['admin/index.php', 'shield', 'Администрирование', 'admin'];
    return $items;
}

function theme_button(string $cls = 'icon-btn'): string
{
    return '<button class="' . $cls . '" type="button" data-theme-toggle aria-label="Тема" title="Тема: как в системе / светлая / тёмная">'
        . '<span class="th th-auto">' . icon('auto') . '</span><span class="th th-light">' . icon('sun') . '</span><span class="th th-dark">' . icon('moon') . '</span></button>';
}

function page_start(string $title, string $active, array $opt = []): void
{
    $u = current_user();
    head_html($title);
    $unread = $u ? (int) val('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0', [$u['id']]) : 0;
    $pendingCrewInv = $u ? (int) val("SELECT COUNT(*) FROM crew_invites WHERE to_user=? AND status='pending'", [$u['id']]) : 0;
    echo '<body class="app">';
    icon_sprite();
    ?>
<aside class="sidebar">
  <div class="brand"><?= icon('sail', 'brand-ic') ?><span>ITMO<b>.</b>SAIL</span></div>
  <nav class="side-nav">
  <?php foreach (nav_items($u) as [$href, $ic, $label, $key]):
      $badge = $key === 'notifications' ? $unread : ($key === 'crew' ? $pendingCrewInv : 0); ?>
    <a class="nav-btn<?= $active === $key ? ' is-active' : '' ?>" href="<?= url($href) ?>"><?= icon($ic) ?><span><?= e($label) ?></span><?php if ($badge): ?><em class="count"><?= $badge ?></em><?php endif ?></a>
  <?php endforeach ?>
  </nav>
  <form method="post" action="<?= url('logout.php') ?>" class="side-foot"><?= csrf_field() ?>
    <button class="nav-btn" type="submit"><?= icon('logout') ?><span>Выйти</span></button>
  </form>
</aside>
<div class="main">
  <header class="topbar">
    <div class="topbar-title">
      <?php if (!empty($opt['back'])): ?><a class="icon-btn ghost" href="<?= url($opt['back']) ?>" aria-label="Назад"><?= icon('left') ?></a><?php endif ?>
      <h1><?= e($title) ?></h1>
    </div>
    <div class="topbar-actions">
      <?= theme_button('icon-btn ghost') ?>
      <a class="icon-btn ghost" href="<?= url('notifications.php') ?>" aria-label="Уведомления"><?= icon('bell') ?><?php if ($unread): ?><em class="dot"><?= $unread > 9 ? '9+' : $unread ?></em><?php endif ?></a>
      <a class="user-btn" href="<?= url('profile.php') ?>"><span class="avatar"><?= e(initials($u)) ?></span><span class="user-meta"><b><?= e(short_name($u)) ?></b><small><?= e(ROLES[$u['role']]) ?></small></span></a>
    </div>
  </header>
  <main class="content<?= !empty($opt['wide']) ? ' is-wide' : '' ?>">
  <?php foreach (take_flash() as [$type, $msg]): ?>
    <div class="flash flash-<?= e($type) ?>"><?= e($msg) ?></div>
  <?php endforeach;
}

function page_end(): void
{
    $script = basename($_SERVER['SCRIPT_NAME']);
    $dir = basename(dirname($_SERVER['SCRIPT_NAME']));
    $tab = $_GET['tab'] ?? '';
    $tabs = [
        ['index.php', 'calendar', 'Расписание', $dir !== 'admin' && in_array($script, ['index.php', 'day.php', 'training.php'], true)],
        ['my.php', 'list', 'Мои', $script === 'my.php'],
        ['events.php', 'trophy', 'Регаты', $script === 'events.php'],
        ['profile.php?tab=crew', 'users', 'Экипаж', $script === 'profile.php' && $tab === 'crew'],
        ['profile.php', 'user', 'Профиль', ($script === 'profile.php' && $tab !== 'crew') || $dir === 'admin' || in_array($script, ['instructors.php', 'notifications.php'], true)],
    ];
    ?>
  </main>
</div>
<nav class="tabbar">
<?php foreach ($tabs as [$href, $ic, $label, $on]): ?>
  <a class="tab<?= $on ? ' is-active' : '' ?>" href="<?= url($href) ?>"><?= icon($ic) ?><span><?= e($label) ?></span></a>
<?php endforeach ?>
</nav>
<script>window.SAIL_BASE=<?= json_encode(BASE_URL) ?>;</script>
</body></html>
<?php
}

function guest_start(string $title): void
{
    head_html($title);
    echo '<body class="guest">';
    icon_sprite();
    echo theme_button('icon-btn ghost guest-theme');
    ?>
<div class="guest-wrap">
  <div class="guest-brand"><?= icon('sail', 'brand-ic big') ?><div><b>ITMO<span>.</span>SAIL</b><small>Парусная секция ИТМО</small></div></div>
  <div class="card guest-card">
  <?php foreach (take_flash() as [$type, $msg]): ?>
    <div class="flash flash-<?= e($type) ?>"><?= e($msg) ?></div>
  <?php endforeach;
}
function guest_end(): void
{
    echo '</div></div><script>window.SAIL_BASE=' . json_encode(BASE_URL) . ';</script></body></html>';
}

/** Плашки-бейджи */
function badges(array $b): string
{
    $h = '';
    foreach ($b as [$cls, $txt]) $h .= '<span class="badge b-' . e($cls) . '">' . e($txt) . '</span>';
    return $h;
}

/** Кнопка-форма POST к action.php */
function action_button(string $action, array $fields, string $label, string $cls = 'btn', string $confirm = '', string $ic = ''): string
{
    $h = '<form method="post" action="' . url('action.php') . '" class="inline-form"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>';
    $h .= csrf_field() . '<input type="hidden" name="a" value="' . e($action) . '">';
    $h .= '<input type="hidden" name="back" value="' . e(current_back()) . '">';
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    $h .= '<button class="' . e($cls) . '" type="submit">' . ($ic ? icon($ic) : '') . '<span>' . e($label) . '</span></button></form>';
    return $h;
}
/** Куда вернуться после действия. Можно переопределить через $GLOBALS['BACK'] (например, во всплывающем окне). */
function current_back(): string
{
    if (!empty($GLOBALS['BACK'])) return $GLOBALS['BACK'];
    $uri = $_SERVER['REQUEST_URI'] ?? '/index.php';
    if (BASE_URL !== '' && str_starts_with($uri, BASE_URL)) $uri = substr($uri, strlen(BASE_URL));
    return ltrim($uri, '/') ?: 'index.php';
}
function form_open(string $action, string $extra = ''): string
{
    return '<form method="post" action="' . url('action.php') . '" ' . $extra . '>' . csrf_field()
        . '<input type="hidden" name="a" value="' . e($action) . '"><input type="hidden" name="back" value="' . e(current_back()) . '">';
}

/**
 * Всплывающее окно создания тренировки. Открывается кнопками с атрибутом data-sheet-date
 * (дата задана заранее) или data-create-open (дату выбирают в окне).
 */
function create_dialog(array $u, string $back): string
{
    $opts = create_options($u);
    [, $sEnd] = schedule_bounds();
    // каким типам нужен инструктор: тогда в мастере показываем инструкторов, которым удобно это время
    $isInstr = is_instructor($u);
    $myCrew = user_crew((int) $u['id']);
    $needsInstr = ['open' => !$isInstr, 'crew' => !$isInstr && $myCrew && !(int) $myCrew['clearance']];
    $instr = array_filter($needsInstr) ? instructor_marks((int) $u['id']) : [];
    $today = date('Y-m-d');
    $old = $GLOBALS['BACK'] ?? null;
    $GLOBALS['BACK'] = $back;
    ob_start(); ?>
<dialog class="sheet" id="create-sheet">
  <div class="sheet-head">
    <div><small data-sheet-sub>Новая тренировка</small><h2 data-sheet-title>Создать тренировку</h2></div>
    <button class="icon-btn ghost" type="button" data-close-dialog aria-label="Закрыть"><?= icon('x') ?></button>
  </div>
  <div class="sheet-body">
  <?php if (!$opts): ?>
    <p class="hint">У вас нет личного допуска и экипажа, поэтому создать тренировку нельзя. Запишитесь к инструктору — лично или вместе с экипажем.</p>
    <a class="btn btn-block" href="<?= url('profile.php?tab=crew') ?>">Собрать экипаж</a>
  <?php else: ?>
    <?= form_open('create_training', 'class="stack" data-create-form') ?>
      <label class="field"><span>Чем будем заниматься</span><textarea name="note" rows="2" maxlength="500" placeholder="Например: повороты и постановка спинакера"></textarea></label>
      <div class="field">
        <span>Тип</span>
        <div class="types">
          <?php $first = true; foreach ($opts as $k => [$title, $desc]): ?>
            <label class="type"><input type="radio" name="type" value="<?= $k ?>"<?= $first ? ' checked' : '' ?><?= !empty($needsInstr[$k]) ? ' data-needs-instr' : '' ?>><span class="type-b"><b><?= e($title) ?></b></span></label>
          <?php $first = false; endforeach ?>
        </div>
        <?php $i = 0; foreach ($opts as [$title, $desc]): $i++; ?><small class="type-hint type-hint-<?= $i ?>"><?= e($desc) ?></small><?php endforeach ?>
      </div>
      <label class="field" data-date-field><span>День</span>
        <select name="date" data-date required>
          <?php $blocked = no_training_days($today, $sEnd->format('Y-m-d'));
          for ($d = new DateTimeImmutable('today'); $d->format('Y-m-d') < $sEnd->format('Y-m-d'); $d = $d->modify('+1 day')): $ds = $d->format('Y-m-d'); ?>
            <option value="<?= $ds ?>"<?= isset($blocked[$ds]) ? ' disabled' : '' ?>><?= e(RU_DOW_S[(int) $d->format('N')] . ', ' . ru_date($ds)) ?><?= isset($blocked[$ds]) ? ' — регата' : '' ?></option>
          <?php endfor ?>
        </select></label>
      <div class="field"><span>Время начала · тренировка длится <?= TRAINING_HOURS ?> часа</span>
        <div class="times">
          <?php foreach (start_times() as $tm): ?>
            <label class="time-chip"><input type="radio" name="time" value="<?= $tm ?>" required<?= $tm === '10:00' ? ' checked' : '' ?>><span><?= $tm ?></span></label>
          <?php endforeach ?>
        </div>
      </div>
      <div class="field instr-pick" data-instr-box hidden>
        <span><?= icon('anchor') ?>Инструкторы, которым удобно это время</span>
        <div class="instr-list" data-instr-list></div>
        <small class="hint" data-instr-hint>Отмеченным придёт приглашение провести тренировку.</small>
      </div>
      <button class="btn btn-primary btn-block" type="submit">Создать тренировку</button>
    </form>
  <?php endif ?>
  </div>
</dialog>
<script>window.SAIL_TODAY=<?= json_encode($today) ?>;window.SAIL_INSTR=<?= json_encode($instr, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;window.SAIL_TRAIN_MIN=<?= TRAINING_HOURS * 60 ?>;</script>
    <?php
    $GLOBALS['BACK'] = $old;
    return (string) ob_get_clean();
}

/** Кнопка «Создать тренировку» (дата — необязательно) */
function create_button(string $date = '', string $cls = 'btn btn-primary'): string
{
    $attr = $date !== ''
        ? ' data-sheet-date="' . e($date) . '" data-sheet-title="' . e(ru_dow($date) . ', ' . ru_date($date)) . '"'
        : ' data-create-open';
    return '<button class="' . e($cls) . '" type="button"' . $attr . '>' . icon('plus') . '<span>Создать тренировку</span></button>';
}

/** Выбор пола: две кнопки-переключателя */
function gender_field(string $val, bool $required = false, string $extra = ''): string
{
    $h = '<fieldset class="choice gender-f"><legend>Пол</legend><div class="toggles">';
    foreach (GENDERS as $k => $v) {
        $h .= '<label class="toggle"><input type="radio" name="gender" value="' . $k . '"' . ($val === $k ? ' checked' : '') . ($required ? ' required' : '') . $extra . '><span>' . e($v) . '</span></label>';
    }
    return $h . '</div></fieldset>';
}
