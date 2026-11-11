<?php
require __DIR__ . '/common.php';

// по умолчанию — текущий семестр (с 1 сентября или с 1 февраля)
$m = (int) date('n');
$defFrom = $m >= 9 ? date('Y-09-01') : ($m >= 2 ? date('Y-02-01') : (date('Y') - 1) . '-09-01');
$from = valid_date(get('from')) ? get('from') : $defFrom;
$to = valid_date(get('to')) ? get('to') : date('Y-m-d');
$onlyPts = get('all') !== '1';
$view = get('view') === 'list' ? 'list' : 'journal';
$qs = fn(array $extra = []) => http_build_query(array_merge(['from' => $from, 'to' => $to, 'all' => $onlyPts ? '' : '1', 'view' => $view], $extra));

$trainings = all("SELECT t.*, c.name AS crew_name FROM trainings t LEFT JOIN crews c ON c.id=t.crew_id
                  WHERE t.report_done=1 AND t.status<>'cancelled' AND t.start_at>=? AND t.start_at<=?
                  ORDER BY t.start_at", [$from . ' 00:00:00', $to . ' 23:59:59']);

$rows = [];     // [тренировка, участники]
$people = [];   // uid => человек
$cells = [];    // uid => [tid => true]
foreach ($trainings as $t) {
    $list = all('SELECT u.* FROM training_people tp JOIN users u ON u.id=tp.user_id WHERE tp.training_id=? AND tp.attended=1 ORDER BY u.last_name, u.first_name', [$t['id']]);
    if ($t['instructor_id'] && ($ins = one('SELECT * FROM users WHERE id=?', [$t['instructor_id']]))) { $ins['_instr'] = 1; array_unshift($list, $ins); }
    $list = array_values(array_filter($list, fn($p) => !$onlyPts || needs_points($p)));
    $rows[] = [$t, $list];
    foreach ($list as $p) { $people[$p['id']] ??= $p; $cells[$p['id']][$t['id']] = !empty($p['_instr']) ? 'i' : 'p'; }
}
uasort($people, fn($a, $b) => strcmp(full_name($a), full_name($b)));

$posted = [];   // uid => [tid => true] — баллы уже перенесены (например, в ИСУ)
if ($trainings) {
    $tids = array_column($trainings, 'id');
    foreach (all('SELECT * FROM pe_points WHERE training_id IN (' . in_list($tids) . ')', $tids) as $r) $posted[$r['user_id']][$r['training_id']] = true;
}
$tpts = [];
foreach ($trainings as $t) $tpts[$t['id']] = training_points($t);
$sum = function (int $uid) use ($cells, $tpts) { $s = 0; foreach (array_keys($cells[$uid] ?? []) as $tid) $s += $tpts[$tid]; return $s; };

if (get('csv') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sail-journal-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $head = ['ФИО', 'Курс'];
    foreach ($trainings as $t) $head[] = date('d.m.Y H:i', strtotime($t['start_at']));
    $head[] = 'Тренировок'; $head[] = 'Баллов';
    fputcsv($out, $head, ';');
    foreach ($people as $uid => $p) {
        $line = [full_name($p), course_label($p)];
        foreach ($trainings as $t) $line[] = isset($cells[$uid][$t['id']]) ? (string) $tpts[$t['id']] : '';
        $line[] = count($cells[$uid] ?? []); $line[] = $sum((int) $uid);
        fputcsv($out, $line, ';');
    }
    exit;
}

page_start('Администрирование', 'admin', ['wide' => true]);
admin_tabs('reports');
?>
<form class="toolbar" method="get">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <div class="field period"><span>Период</span>
    <div class="date-range">
      <input type="date" name="from" value="<?= e($from) ?>" aria-label="Начало периода">
      <span class="range-dash">—</span>
      <input type="date" name="to" value="<?= e($to) ?>" aria-label="Конец периода">
      <button class="btn" type="submit">Показать</button>
    </div>
  </div>
  <div class="field"><span>Участники</span>
    <div class="seg seg-field">
      <label class="seg-btn<?= $onlyPts ? ' is-on' : '' ?>"><input type="radio" name="all" value=""<?= $onlyPts ? ' checked' : '' ?> data-autosubmit>1–3 курс</label>
      <label class="seg-btn<?= $onlyPts ? '' : ' is-on' ?>"><input type="radio" name="all" value="1"<?= $onlyPts ? '' : ' checked' ?> data-autosubmit>Все курсы</label>
    </div>
  </div>
  <span class="grow"></span>
  <div class="seg">
    <a class="seg-btn<?= $view === 'journal' ? ' is-on' : '' ?>" href="<?= url('admin/reports.php?' . $qs(['view' => 'journal'])) ?>">Журнал</a>
    <a class="seg-btn<?= $view === 'list' ? ' is-on' : '' ?>" href="<?= url('admin/reports.php?' . $qs(['view' => 'list'])) ?>">По тренировкам</a>
  </div>
  <a class="btn" href="<?= url('admin/reports.php?' . $qs(['csv' => 1])) ?>"><?= icon('download') ?><span>CSV</span></a>
</form>
<p class="lead"><?= POINTS_PER_HOUR ?> баллов за каждый час тренировки (<?= POINTS_PER_HOUR * TRAINING_HOURS ?> за тренировку). Только тренировки, проведение которых подтвердил инструктор, и отмеченные им участники<?= $onlyPts ? ' 1–3 курса' : '' ?>.</p>

<?php if (!$rows): ?>
  <div class="card empty"><?= icon('grid', 'empty-ic') ?><p>За период нет подтверждённых тренировок.</p></div>

<?php elseif ($view === 'journal'): ?>
  <!-- Компьютер: таблица «фамилии × даты» -->
  <?= form_open('admin_points_save', 'class="card journal-card desk-only" data-journal') ?>
    <div class="journal-tools">
      <span class="hint">Галочка — баллы уже выставлены.</span>
      <span class="grow"></span>
      <button class="btn btn-sm" type="button" data-fill>Отметить все</button>
      <button class="btn btn-primary btn-sm" type="submit">Сохранить отметки</button>
    </div>
    <div class="journal-wrap">
      <table class="journal">
        <thead><tr>
          <th class="j-name">Фамилия, имя</th>
          <?php foreach ($trainings as $t): ?>
            <th title="<?= e(training_title($t)) ?>"><b><?= date('d.m', strtotime($t['start_at'])) ?></b><small><?= hm($t['start_at']) ?></small></th>
          <?php endforeach ?>
          <th class="j-sum">Трен.</th><th class="j-sum">Баллы</th>
        </tr></thead>
        <tbody>
        <?php foreach ($people as $uid => $p): ?>
          <tr>
            <th class="j-name"><b><?= e(short_name($p)) ?></b><small><?= e(course_label($p)) ?></small></th>
            <?php foreach ($trainings as $t): $c = $cells[$uid][$t['id']] ?? null; ?>
              <td class="<?= $c ? 'is-on' : '' ?>">
                <?php if ($c): ?><label class="jcell<?= isset($posted[$uid][$t['id']]) ? ' is-done' : '' ?>" title="<?= $c === 'i' ? 'Инструктор' : gw($people[$uid], 'Был', 'Была') ?>"><input type="hidden" name="cell[<?= $uid ?>][<?= $t['id'] ?>]" value="1"><input type="checkbox" name="done[<?= $uid ?>][<?= $t['id'] ?>]" value="1"<?= isset($posted[$uid][$t['id']]) ? ' checked' : '' ?>><b><?= $tpts[$t['id']] ?></b></label><?php endif ?>
              </td>
            <?php endforeach ?>
            <td class="j-sum"><?= count($cells[$uid] ?? []) ?></td>
            <td class="j-sum"><b><?= $sum((int) $uid) ?></b></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </form>

  <!-- Телефон: по одной тренировке, без горизонтальной прокрутки -->
  <div class="stack mob-only">
    <?php foreach (array_reverse($rows) as [$t, $list]): if (!$list) continue; ?>
      <details class="card jday">
        <summary><span><b><?= e(ru_date($t['start_at'])) ?>, <?= hm($t['start_at']) ?></b><small><?= e(training_title($t)) ?> · <?= count($list) ?> чел.</small></span><?= icon('right') ?></summary>
        <?= form_open('admin_points_save', 'class="stack"') ?>
          <?php foreach ($list as $p): ?>
            <label class="jrow"><span><b><?= e(short_name($p)) ?></b><small><?= e(course_label($p)) ?><?= !empty($p['_instr']) ? ' · инструктор' : '' ?></small></span>
              <b class="jpts"><?= $tpts[$t['id']] ?> б.</b>
              <input type="hidden" name="cell[<?= $p['id'] ?>][<?= $t['id'] ?>]" value="1"><input type="checkbox" name="done[<?= $p['id'] ?>][<?= $t['id'] ?>]" value="1"<?= isset($posted[$p['id']][$t['id']]) ? ' checked' : '' ?> aria-label="Выставлено"></label>
          <?php endforeach ?>
          <p class="hint">Галочка — баллы уже выставлены.</p>
          <button class="btn btn-primary btn-block" type="submit">Сохранить отметки</button>
        </form>
      </details>
    <?php endforeach ?>
  </div>

<?php else: ?>
  <div class="cols">
  <section class="col-main stack">
    <?php foreach (array_reverse($rows) as [$t, $list]): ?>
    <div class="card">
      <div class="card-top">
        <div><h3><?= e(ru_dow($t['start_at'])) ?>, <?= e(ru_date($t['start_at'], true)) ?> · <?= hm($t['start_at']) ?>–<?= hm($t['end_at']) ?></h3>
          <p class="meta"><?= e(training_title($t)) ?></p></div>
        <a class="btn btn-sm" href="<?= url('training.php?id=' . $t['id']) ?>">Тренировка</a>
      </div>
      <ul class="people compact">
        <?php foreach ($list as $p): ?>
          <li><span class="p-name"><b><?= e(full_name($p)) ?></b><small><?= e(course_label($p)) ?></small></span>
            <?php if (!empty($p['_instr'])): ?><span class="badge b-me">Инструктор</span><?php endif ?>
            <span class="badge <?= isset($posted[$p['id']][$t['id']]) ? 'b-ok' : 'b-info' ?>"><?= $tpts[$t['id']] ?> б.<?= isset($posted[$p['id']][$t['id']]) ? ' · выставлено' : '' ?></span></li>
        <?php endforeach ?>
        <?php if (!$list): ?><li class="is-off"><span class="p-name">Нет участников<?= $onlyPts ? ', которым нужны баллы' : '' ?></span></li><?php endif ?>
      </ul>
    </div>
    <?php endforeach ?>
  </section>
  <aside class="col-side">
    <div class="card">
      <h2 class="card-h">Итого по людям</h2>
      <ul class="people compact">
        <?php foreach ($people as $uid => $p): ?>
          <li><span class="p-name"><b><?= e(full_name($p)) ?></b><small><?= count($cells[$uid]) ?> трен.</small></span><span class="badge b-info"><?= $sum((int) $uid) ?> б.</span></li>
        <?php endforeach ?>
      </ul>
    </div>
  </aside>
  </div>
<?php endif ?>
<?php page_end();
