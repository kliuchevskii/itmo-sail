<?php
require __DIR__ . '/common.php';

$qstr = get('q');
$filter = get('f');
$fl = ['course' => get('course'), 'level' => get('level'), 'clr' => get('clr'), 'crew' => get('crew'), 'med' => get('med')];
$sort = get('sort', 'az');
$SORTS = ['az' => 'Фамилия: А → Я', 'za' => 'Фамилия: Я → А', 'course' => 'По курсу', 'level' => 'По уровню', 'clr' => 'По допуску'];
if (!isset($SORTS[$sort])) $sort = 'az';
$where = '1=1'; $p = [];
if ($qstr !== '') {
    $where .= ' AND (LOWER(last_name) LIKE ? OR LOWER(first_name) LIKE ? OR LOWER(login) LIKE ? OR LOWER(email) LIKE ?)';
    $like = '%' . mb_strtolower($qstr) . '%'; $p = [$like, $like, $like, $like];
}
if ($filter === 'admins') $where .= " AND role<>'member'";
if ($filter === 'inactive') $where .= ' AND active=0';
if ($filter === 'active') $where .= ' AND active=1';
if ($fl['level'] !== '' && isset(LEVELS[(int) $fl['level']])) { $where .= ' AND level=?'; $p[] = (int) $fl['level']; }
if ($fl['clr'] !== '' && isset(CLEARANCES[(int) $fl['clr']])) { $where .= ' AND clearance=?'; $p[] = (int) $fl['clr']; }
$users = all("SELECT u.*, (SELECT c.id FROM crew_members m JOIN crews c ON c.id=m.crew_id WHERE m.user_id=u.id) AS crew_id,
                     (SELECT c.name FROM crew_members m JOIN crews c ON c.id=m.crew_id WHERE m.user_id=u.id) AS crew_name,
                     (SELECT COUNT(*) FROM documents d WHERE d.user_id=u.id AND d.doc_type='medical') AS has_med
              FROM users u WHERE $where", $p);

/** Ключ курса для фильтра и сортировки: 1–6, «grad» — выпускник, «other» — без курса */
$courseKey = function (array $x): string {
    $c = user_course($x);
    if ($c === null) return 'other';
    return $c > PROGRAM_YEARS[$x['program']] ? 'grad' : (string) max(1, $c);
};
$users = array_values(array_filter($users, function ($x) use ($fl, $courseKey) {
    if ($fl['course'] !== '' && $courseKey($x) !== $fl['course']) return false;
    if ($fl['crew'] === 'none' && $x['crew_id']) return false;
    if ($fl['crew'] === 'any' && !$x['crew_id']) return false;
    if (ctype_digit((string) $fl['crew']) && (int) $x['crew_id'] !== (int) $fl['crew']) return false;
    if ($fl['med'] === 'yes' && !(int) $x['has_med']) return false;
    if ($fl['med'] === 'no' && (int) $x['has_med']) return false;
    return true;
}));
$byName = fn($a, $b) => strcoll(mb_strtolower(full_name($a)), mb_strtolower(full_name($b)));
setlocale(LC_COLLATE, 'ru_RU.UTF-8', 'ru_RU.utf8', 'C.UTF-8');
$courseOrd = fn($x) => ['grad' => 50, 'other' => 60][$courseKey($x)] ?? (int) $courseKey($x);
usort($users, match ($sort) {
    'za' => fn($a, $b) => -$byName($a, $b),
    'course' => fn($a, $b) => [$courseOrd($a), $a['program']] <=> [$courseOrd($b), $b['program']] ?: $byName($a, $b),
    'level' => fn($a, $b) => (int) $b['level'] <=> (int) $a['level'] ?: $byName($a, $b),
    'clr' => fn($a, $b) => (int) $b['clearance'] <=> (int) $a['clearance'] ?: $byName($a, $b),
    default => $byName,
});
$crews = all('SELECT id, name FROM crews ORDER BY name');
$active = count(array_filter($fl, fn($v) => $v !== '')) + ($filter !== '' ? 1 : 0);
$total = (int) val('SELECT COUNT(*) FROM users');
$openId = (int) get('u');

page_start('Администрирование', 'admin', ['wide' => true]);
admin_tabs('index');
?>
<div class="cols">
<section class="col-main stack">
  <form class="ufilters" method="get">
    <div class="usearch">
      <input name="q" value="<?= e($qstr) ?>" placeholder="Поиск: фамилия, логин или почта" aria-label="Поиск">
      <button class="btn btn-primary" type="submit">Найти</button>
    </div>
    <div class="ubar">
      <details class="dd" data-dd>
        <summary class="btn<?= $active ? ' is-active' : '' ?>"><?= icon('filter') ?><span>Фильтр</span><?php if ($active): ?><b class="dd-count"><?= $active ?></b><?php endif ?></summary>
        <div class="dd-panel">
          <div class="fgrid">
            <label class="field"><span>Курс</span><select name="course" data-autosubmit>
              <option value="">Все</option>
              <?php for ($i = 1; $i <= 6; $i++): ?><option value="<?= $i ?>"<?= $fl['course'] === (string) $i ? ' selected' : '' ?>><?= $i ?> курс</option><?php endfor ?>
              <option value="grad"<?= $fl['course'] === 'grad' ? ' selected' : '' ?>>Выпускники</option>
              <option value="other"<?= $fl['course'] === 'other' ? ' selected' : '' ?>>Сотрудники и другие</option>
            </select></label>
            <label class="field"><span>Уровень</span><select name="level" data-autosubmit>
              <option value="">Все</option>
              <?php foreach (LEVELS as $k => $v): ?><option value="<?= $k ?>"<?= $fl['level'] === (string) $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach ?>
            </select></label>
            <label class="field"><span>Допуск</span><select name="clr" data-autosubmit>
              <option value="">Все</option>
              <?php foreach (CLEARANCES as $k => $v): ?><option value="<?= $k ?>"<?= $fl['clr'] === (string) $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach ?>
            </select></label>
            <label class="field"><span>Экипаж</span><select name="crew" data-autosubmit>
              <option value="">Все</option>
              <option value="any"<?= $fl['crew'] === 'any' ? ' selected' : '' ?>>В экипаже</option>
              <option value="none"<?= $fl['crew'] === 'none' ? ' selected' : '' ?>>Без экипажа</option>
              <?php foreach ($crews as $c): ?><option value="<?= $c['id'] ?>"<?= $fl['crew'] === (string) $c['id'] ? ' selected' : '' ?>>«<?= e($c['name']) ?>»</option><?php endforeach ?>
            </select></label>
            <label class="field"><span>Справка</span><select name="med" data-autosubmit>
              <option value="">Все</option>
              <option value="yes"<?= $fl['med'] === 'yes' ? ' selected' : '' ?>>Есть</option>
              <option value="no"<?= $fl['med'] === 'no' ? ' selected' : '' ?>>Нет</option>
            </select></label>
            <label class="field"><span>Статус</span><select name="f" data-autosubmit>
              <option value="">Все</option>
              <option value="active"<?= $filter === 'active' ? ' selected' : '' ?>>Активные</option>
              <option value="admins"<?= $filter === 'admins' ? ' selected' : '' ?>>Администраторы</option>
              <option value="inactive"<?= $filter === 'inactive' ? ' selected' : '' ?>>Заблокированные</option>
            </select></label>
          </div>
          <?php if ($active): ?><a class="btn btn-sm dd-reset" href="<?= url('admin/index.php?' . http_build_query(['q' => $qstr, 'sort' => $sort])) ?>"><?= icon('x') ?><span>Сбросить фильтры</span></a><?php endif ?>
        </div>
      </details>
      <details class="dd" data-dd>
        <summary class="btn<?= $sort !== 'az' ? ' is-active' : '' ?>"><?= icon('sort') ?><span>Сортировка</span></summary>
        <div class="dd-panel dd-list">
          <?php foreach ($SORTS as $k => $v): ?>
            <label class="dd-opt"><input type="radio" name="sort" value="<?= $k ?>"<?= $sort === $k ? ' checked' : '' ?> data-autosubmit><span><?= e($v) ?></span><?= icon('check') ?></label>
          <?php endforeach ?>
        </div>
      </details>
      <span class="ubar-n muted">Найдено: <b><?= count($users) ?></b> из <?= $total ?></span>
    </div>
  </form>

  <div class="card ulist">
    <div class="ulist-head"><span>Участник</span><span>Курс</span><span>Уровень</span><span>Допуск</span><span>Экипаж</span><span>Справка</span></div>
    <?php foreach ($users as $x): ?>
      <button class="urow<?= (int) $x['active'] ? '' : ' is-off' ?>" type="button" data-user="<?= $x['id'] ?>">
        <span class="u-main"><span class="avatar sm"><?= e(initials($x)) ?></span><span class="u-name"><b><?= e(full_name($x)) ?></b><small>@<?= e($x['login']) ?><?= $x['role'] !== 'member' ? ' · ' . e(ROLES[$x['role']]) : '' ?></small></span></span>
        <span class="u-c"><?= e(course_label($x)) ?></span>
        <span class="u-c"><?= e(LEVELS[(int) $x['level']]) ?></span>
        <span class="u-c"><?= (int) $x['clearance'] ? '<span class="badge b-me">' . e(CLEARANCES[(int) $x['clearance']]) . '</span>' : '<span class="muted">—</span>' ?></span>
        <span class="u-c"><?= $x['crew_name'] ? e($x['crew_name']) : '<span class="muted">—</span>' ?></span>
        <span class="u-c"><?= (int) $x['has_med'] ? '<span class="badge b-ok">Есть</span>' : '<span class="badge b-muted">Нет</span>' ?></span>
      </button>
    <?php endforeach ?>
    <?php if (!$users): ?><p class="hint pad">Никого не найдено.</p><?php endif ?>
  </div>
</section>
<aside class="col-side">
  <details class="card add-user" <?= $users ? '' : 'open' ?>>
    <summary class="card-h"><?= icon('plus') ?>Добавить участника</summary>
    <?= form_open('admin_user_create', 'class="stack"') ?>
      <label class="field"><span>Фамилия</span><input name="last_name" required></label>
      <div class="grid-2">
        <label class="field"><span>Имя</span><input name="first_name" required></label>
        <label class="field"><span>Отчество</span><input name="middle_name"></label>
      </div>
      <?= gender_field('') ?>
      <div class="grid-2">
        <label class="field"><span>Уровень обучения</span><select name="program" data-program><?php foreach (PROGRAMS as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach ?></select></label>
        <label class="field" data-course-field><span>Курс</span><select name="course"><?php for ($i = 1; $i <= 6; $i++): ?><option><?= $i ?></option><?php endfor ?></select></label>
      </div>
      <label class="field"><span>Логин</span><input name="login" pattern="[A-Za-z0-9_.\-]{3,30}" required></label>
      <label class="field"><span>Почта</span><input type="email" name="email" required></label>
      <p class="hint">Временный пароль появится после сохранения.</p>
      <button class="btn btn-primary btn-block" type="submit">Добавить</button>
    </form>
  </details>
</aside>
</div>

<dialog class="modal" id="user-modal" data-open-user="<?= $openId ?: '' ?>" data-open-tab="<?= e(get('ut')) ?>" data-src="<?= url('admin/user.php') ?>">
  <div class="modal-body" data-modal-body><p class="hint pad">Загрузка…</p></div>
</dialog>
<?php page_end();
