<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$uid = (int) $u['id'];

[$start, $end] = schedule_bounds();
$byDay = trainings_range($start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 00:00:00'), $u);
$evByDay = events_range($start->format('Y-m-d'), $end->format('Y-m-d'), $uid);
$noTr = no_training_days($start->format('Y-m-d'), $end->format('Y-m-d'));
$today = date('Y-m-d');
$canCreate = (bool) create_options($u);
$view = get('view') === 'week' ? 'week' : 'month';
$w = max(0, min(SCHEDULE_WEEKS - 1, (int) get('w', '0')));

$myCrew = user_crew($uid);
// Что показывать в расписании: удобное время экипажа или сколько человек могут (общий пул)
$avView = $myCrew && get('av') !== 'all' ? 'crew' : 'all';
$av = $myCrew ? crew_availability((int) $myCrew['id']) : null;
$pubData = public_availability();
$okDays = $avView === 'crew' ? ($av['common'] ?? []) : [];      // день => общие окна экипажа [[с, до)]
$pub = $avView === 'all' ? $pubData : null;
$pubBest = array_filter($pub['best'] ?? [], fn($b) => $b[0] >= 2);   // подсвечиваем, когда могут хотя бы двое
// Окна инструкторов: во «Всех участниках» и в экипаже без допуска (ему нужен инструктор)
$showInstr = $avView === 'all' || ($myCrew && !(int) $myCrew['clearance']);
$instrAv = instructor_availability($uid);
$instrDays = $showInstr ? $instrAv['runs'] : [];
$marks = ['all' => avail_user_days($uid, 'all'), 'crew' => $myCrew ? avail_user_days($uid, 'crew') : []];
$dl = fn(string $d): string => RU_DOW_S[(int) date('N', strtotime($d))] . ' ' . (int) date('j', strtotime($d));
/** Ссылка на расписание с сохранением вида и режима */
$link = function (array $p = []) use ($view, $w, $myCrew, $avView): string {
    $q = http_build_query(array_filter(array_merge(['view' => $view === 'week' ? 'week' : null, 'w' => $view === 'week' ? $w : null, 'av' => $myCrew && $avView === 'all' ? 'all' : null], $p), fn($v) => $v !== null));
    return url('index.php' . ($q !== '' ? '?' . $q : ''));
};

$reports = pending_reports($uid);
$invites = all("SELECT t.*, c.name AS crew_name FROM training_people tp JOIN trainings t ON t.id=tp.training_id
                LEFT JOIN crews c ON c.id=t.crew_id
                WHERE tp.user_id=? AND tp.status='invited' AND t.status<>'cancelled' AND t.start_at>? ORDER BY t.start_at", [$uid, now()]);
$instrInv = all("SELECT ii.id AS inv_id, t.*, c.name AS crew_name FROM instructor_invites ii JOIN trainings t ON t.id=ii.training_id
                 LEFT JOIN crews c ON c.id=t.crew_id
                 WHERE ii.instructor_id=? AND ii.status='pending' AND t.status<>'cancelled' AND t.start_at>? ORDER BY t.start_at", [$uid, now()]);

/** Регата в ячейке месяца: ячейка — ссылка на день, поэтому переход на регату делаем через data-href */
function ev_chip(array $ev): string
{
    return '<span class="chip st-ev" role="link" data-href="' . e(url('event.php?id=' . $ev['id'])) . '" title="' . e(event_title($ev)) . '">'
        . '<b>' . icon('trophy', 'ic chip-ic') . '</b><span class="chip-t">' . e(event_title($ev)) . '</span></span>';
}

function chip(array $t): string
{
    return '<span class="chip st-' . $t['state'] . '"><b>' . hm($t['start_at']) . '–' . hm($t['end_at']) . '</b><span class="chip-t">' . e(training_title($t)) . '</span></span>';
}

page_start('Расписание', 'schedule', ['wide' => true]);
?>

<div class="alert" data-push-nudge hidden>
  <span class="alert-ic"><?= icon('bell') ?></span><div class="alert-b"><b>Включите уведомления на телефон</b><span>Узнаете о тренировках и приглашениях, даже когда сайт закрыт.</span></div>
  <div class="row-btns"><a class="btn btn-primary btn-sm" href="<?= url('notifications.php') ?>">Включить</a><button class="btn btn-sm" type="button" data-nudge-close>Не сейчас</button></div>
</div>
<?php if ($reports || $invites || $instrInv): ?>
<section class="alerts">
  <?php foreach ($reports as $t): ?>
    <div class="alert">
      <span class="alert-ic"><?= icon('check') ?></span><div class="alert-b"><b>Подтвердите проведение</b><span><?= e(ru_date($t['start_at'])) ?>, <?= hm($t['start_at']) ?> · <?= e(training_title($t)) ?></span></div>
      <a class="btn btn-primary btn-sm" href="<?= url('training.php?id=' . $t['id']) ?>">Отметить</a>
    </div>
  <?php endforeach ?>
  <?php foreach ($invites as $t): ?>
    <div class="alert">
      <span class="alert-ic"><?= icon('users') ?></span><div class="alert-b"><b>Подтвердите участие</b><span><?= e(ru_dow($t['start_at'])) ?>, <?= e(ru_date($t['start_at'])) ?>, <?= hm($t['start_at']) ?> · <?= e(training_title($t)) ?></span></div>
      <div class="row-btns">
        <?= action_button('confirm', ['tid' => $t['id']], 'Иду', 'btn btn-primary btn-sm') ?>
        <?= action_button('decline', ['tid' => $t['id']], 'Не смогу', 'btn btn-sm') ?>
      </div>
    </div>
  <?php endforeach ?>
  <?php foreach ($instrInv as $t): ?>
    <div class="alert">
      <span class="alert-ic"><?= icon('anchor') ?></span><div class="alert-b"><b>Вас зовут инструктором</b><span><?= e(ru_date($t['start_at'])) ?>, <?= hm($t['start_at']) ?> · <?= e(training_title($t)) ?></span></div>
      <div class="row-btns">
        <?= action_button('instr_reply', ['inv' => $t['inv_id'], 'yes' => 1], 'Согласиться', 'btn btn-primary btn-sm') ?>
        <?= action_button('instr_reply', ['inv' => $t['inv_id'], 'yes' => 0], 'Отказаться', 'btn btn-sm') ?>
      </div>
    </div>
  <?php endforeach ?>
</section>
<?php endif ?>

<?php
$nMem = $av ? count($av['members']) : 0;
$editTab = $myCrew ? $avView : 'all';
$anyMarks = $marks['all'] || $marks['crew'];
$pubBestAll = array_filter($pubData['best'], fn($b) => $b[0] >= 2);
?>
<details class="card avail" id="avail"<?= $anyMarks ? '' : ' open' ?>>
  <summary class="avail-head">
    <span class="avail-title"><h2 class="card-h"><?= icon('calendar') ?>Когда вы можете в ближайшую неделю</h2><span class="avail-chev"><?= icon('right') ?></span></span>
    <span class="avail-sum">
      <?php if ($myCrew): ?><span class="badge <?= $marks['crew'] ? 'b-info' : 'b-muted' ?>">Экипажу: <?= $marks['crew'] ? e(implode(', ', array_map($dl, array_keys($marks['crew'])))) : 'не отмечено' ?></span><?php endif ?>
      <span class="badge <?= $marks['all'] ? 'b-info' : 'b-muted' ?>"><?= $myCrew ? 'Всем' : 'Вы' ?>: <?= $marks['all'] ? e(implode(', ', array_map($dl, array_keys($marks['all'])))) : 'не отмечено' ?></span>
    </span>
    <span class="hint">Отметьте дни, затем удобные часы.<?= $myCrew ? ' Для экипажа и для всех участников отметки заполняются отдельно и могут отличаться.' : ' В расписании будет видно, сколько человек могут пойти на тренировку в каждый день и час.' ?></span>
  </summary>
  <?php if ($myCrew): ?>
  <div class="seg seg-wide avail-tabs" role="tablist">
    <button class="seg-btn<?= $editTab === 'crew' ? ' is-on' : '' ?>" type="button" data-avail-tab="crew">Для экипажа «<?= e($myCrew['name']) ?>»</button>
    <button class="seg-btn<?= $editTab === 'all' ? ' is-on' : '' ?>" type="button" data-avail-tab="all">Для всех участников</button>
  </div>
  <?php endif ?>
  <?php foreach ($myCrew ? ['crew', 'all'] : ['all'] as $scope): $mine = $marks[$scope]; ?>
  <div class="avail-panel" data-avail-panel="<?= $scope ?>"<?= $scope === $editTab ? '' : ' hidden' ?>>
  <?= form_open('avail_save', 'class="stack"') ?>
    <input type="hidden" name="scope" value="<?= $scope ?>">
    <input type="hidden" name="back" value="<?= e(substr($link(), strlen(url('')))) ?>">
    <div class="avail-days">
      <?php foreach (avail_window() as $d):
          $others = 0;
          if ($scope === 'crew') { $cnt = count($av['days'][$d]); $others = count(array_diff_key($av['days'][$d], [$uid => 1])); $lbl = $cnt . '/' . $nMem; $tip = "Могут $cnt из $nMem"; $isOk = isset($av['common'][$d]); $okTip = $isOk ? 'Экипажу удобно: ' . runs_label($av['common'][$d]) : ($others ? 'Этот день уже выбрали из экипажа: ' . $others : ''); }
          else { $cnt = $pubData['days'][$d] ?? 0; $lbl = $cnt ? $cnt . ' чел.' : '—'; $tip = 'Отметили этот день: ' . $cnt; $isOk = isset($pubBestAll[$d]); $okTip = $isOk ? 'Лучшее время: ' . best_label($pubBestAll[$d]) : ''; } ?>
        <label class="aday<?= $isOk ? ' is-ok' : '' ?><?= $others && !$isOk ? ' has-others' : '' ?><?= isset($noTr[$d]) ? ' is-blocked' : '' ?>"<?= $okTip ? ' title="' . e($okTip) . '"' : '' ?>>
          <input type="checkbox" name="days[]" value="<?= $d ?>"<?= isset($mine[$d]) ? ' checked' : '' ?><?= isset($noTr[$d]) ? ' disabled' : '' ?> data-avail-day>
          <span class="aday-b"><small><?= RU_DOW_S[(int) date('N', strtotime($d))] ?></small><b><?= (int) date('j', strtotime($d)) ?></b></span>
          <span class="aday-n" title="<?= e($tip) ?>"><?= e($lbl) ?></span>
        </label>
      <?php endforeach ?>
    </div>
    <div class="avail-hours" data-avail-hours>
      <div class="avail-sub"><b>Удобное время</b><span class="hint">Каждая кнопка — час. Ничего не отмечено — подходит любое время<?= season_latest() ? '. Сейчас студенческий сезон: начало не позже ' . e(season_latest()) : '' ?>.</span></div>
      <?php foreach (avail_window() as $d): $hs = $mine[$d] ?? []; $hc = [];
          if ($scope === 'all') $hc = $pubData['hours'][$d] ?? [];
          else foreach ($av['days'][$d] as $mid => $mh) if ($mid !== $uid) foreach ($mh as $hh) $hc[$hh] = ($hc[$hh] ?? 0) + 1; ?>
        <div class="ahrow" data-day="<?= $d ?>"<?= isset($mine[$d]) ? '' : ' hidden' ?>>
          <span class="ahrow-d"><b><?= RU_DOW_S[(int) date('N', strtotime($d))] ?></b> <?= (int) date('j', strtotime($d)) ?></span>
          <div class="hchips">
            <?php foreach (avail_slots() as $h): ?>
              <label class="hchip"><input type="checkbox" name="hours[<?= $d ?>][]" value="<?= $h ?>"<?= in_array($h, $hs, true) ? ' checked' : '' ?>><?php $hi = !empty($instrAv['hours'][$d][$h]) && ($scope === 'all' || !(int) $myCrew['clearance']); $hcr = $scope === 'crew' && !empty($hc[$h]); ?><span class="<?= $hi ? 'has-instr' : '' ?><?= $hcr ? ' has-crew' : '' ?>" title="<?= e(implode('. ', array_filter([$hcr ? 'Уже выбрали из экипажа: ' . $hc[$h] : '', $hi ? 'В этот час может инструктор' : '']))) ?>"><?= $h ?>:00<?php if (!empty($hc[$h])): ?><em><?= $hc[$h] ?></em><?php endif ?></span></label>
            <?php endforeach ?>
          </div>
        </div>
      <?php endforeach ?>
      <p class="hint avail-empty"<?= $mine ? ' hidden' : '' ?>>Сначала выберите дни выше.</p>
    </div>
    <?php if ($scope === 'crew' && $av['common']): ?>
      <div class="avail-common"><?= icon('users') ?><span><b>Экипажу удобно:</b> <?= e(implode('; ', array_map(fn($d) => $dl($d) . ' — ' . runs_label($av['common'][$d]), array_keys($av['common'])))) ?></span></div>
    <?php elseif ($scope === 'all' && $pubBestAll): ?>
      <div class="avail-common"><?= icon('users') ?><span><b>Больше всего людей могут:</b> <?= e(implode('; ', array_map(fn($d) => $dl($d) . ' — ' . best_label($pubBestAll[$d]), array_keys($pubBestAll)))) ?></span></div>
    <?php endif ?>
    <div class="avail-foot">
      <span class="avail-who">
        <?php if ($scope === 'crew') foreach ($av['members'] as $m): $n = 0; foreach ($av['days'] as $who) if (isset($who[(int) $m['id']])) $n++; ?>
          <span class="who<?= $n ? ' is-set' : '' ?>" title="<?= e(full_name($m)) ?>"><span class="avatar xs"><?= e(initials($m)) ?></span><?= e(short_name($m)) ?><small><?= $n ? $n . ' ' . plural($n, 'день', 'дня', 'дней') : 'не ' . gw($m, 'отметил', 'отметила') ?></small></span>
        <?php endforeach ?>
        <?php if ($scope === 'all'): ?><span class="hint">Эти дни видны всем участникам — числом, без имени.</span><?php endif ?>
      </span>
      <button class="btn btn-primary" type="submit">Сохранить<?= $myCrew ? ($scope === 'crew' ? ' для экипажа' : ' для всех') : '' ?></button>
    </div>
  </form>
  </div>
  <?php endforeach ?>
</details>

<section class="sched desk-only">
  <div class="sched-head">
    <?php if ($view === 'week'):
        $ws = $start->modify('+' . ($w * 7) . ' days'); $we = $ws->modify('+6 days'); ?>
      <div class="row-btns">
        <a class="btn btn-sm" href="<?= $link(['w' => 0]) ?>">Сегодня</a>
        <a class="icon-btn<?= $w <= 0 ? ' is-disabled' : '' ?>" href="<?= $link(['w' => max(0, $w - 1)]) ?>" aria-label="Назад"><?= icon('left') ?></a>
        <a class="icon-btn<?= $w >= SCHEDULE_WEEKS - 1 ? ' is-disabled' : '' ?>" href="<?= $link(['w' => min(SCHEDULE_WEEKS - 1, $w + 1)]) ?>" aria-label="Вперёд"><?= icon('right') ?></a>
        <span class="range"><?= e(ru_date($ws->format('Y-m-d'))) ?> — <?= e(ru_date($we->format('Y-m-d'))) ?></span>
      </div>
    <?php else: ?>
      <span class="range"><?= e(ru_date($start->format('Y-m-d'))) ?> — <?= e(ru_date($end->modify('-1 day')->format('Y-m-d'))) ?></span>
    <?php endif ?>
    <div class="row-btns">
    <?php if ($canCreate) echo create_button(); ?>
    <div class="seg">
      <a class="seg-btn<?= $view === 'month' ? ' is-on' : '' ?>" href="<?= $link(['view' => null, 'w' => null]) ?>">Месяц</a>
      <a class="seg-btn<?= $view === 'week' ? ' is-on' : '' ?>" href="<?= $link(['view' => 'week', 'w' => $w]) ?>">Неделя</a>
    </div>
    <?php if ($myCrew): ?>
    <div class="seg av-seg" title="Что подсвечивать в расписании">
      <a class="seg-btn<?= $avView === 'crew' ? ' is-on' : '' ?>" href="<?= $link(['av' => null]) ?>"><?= icon('users') ?>Экипаж</a>
      <a class="seg-btn<?= $avView === 'all' ? ' is-on' : '' ?>" href="<?= $link(['av' => 'all']) ?>"><?= icon('users') ?>Все участники</a>
    </div>
    <?php endif ?>
    </div>
  </div>

  <?php if ($view === 'month'): ?>
  <div class="month">
    <?php foreach (RU_DOW_S as $d): ?><div class="month-dow"><?= $d ?></div><?php endforeach ?>
    <?php for ($i = 0; $i < SCHEDULE_WEEKS * 7; $i++):
        $day = $start->modify("+$i days"); $ds = $day->format('Y-m-d');
        $past = $ds < $today; $list = $byDay[$ds] ?? [];
        $cls = 'mday' . ($past ? ' is-past' : '') . ($ds === $today ? ' is-today' : '') . (isset($okDays[$ds]) ? ' is-crew-ok' : '') . (isset($pubBest[$ds]) ? ' is-pub' : ''); ?>
      <a class="<?= $cls ?>" href="<?= url('day.php?d=' . $ds) ?>">
        <span class="mday-top"><span class="mday-n"><?= (int) $day->format('j') ?></span><?php if ($i === 0 || $day->format('j') === '1'): ?><small><?= e(RU_MON_G[(int) $day->format('n')]) ?></small><?php endif ?>
          <?php if (isset($okDays[$ds])): ?><span class="crew-ok" title="Весь экипаж может: <?= e(runs_label($okDays[$ds])) ?>"><?= icon('users') ?></span><?php endif ?>
          <?php if (!$past && $canCreate && !isset($noTr[$ds])): ?><span class="mday-add" role="button" title="Создать тренировку" data-sheet-date="<?= $ds ?>"<?= isset($okDays[$ds]) ? ' data-sheet-time="' . sprintf('%02d:00', $okDays[$ds][0][0]) . '" data-sheet-type="crew"' : '' ?> data-sheet-title="<?= e(ru_dow($ds) . ', ' . ru_date($ds)) ?>"><?= icon('plus') ?></span><?php endif ?></span>
        <span class="mday-list">
          <?php
          // быстрые варианты: двухчасовые окна, удобные экипажу или большинству — клик открывает создание
          $mslots = [];
          if (!$past && $canCreate && !isset($noTr[$ds])) {
              if ($avView === 'crew') { foreach ($okDays[$ds] ?? [] as [$ra, $rb]) for ($sh = $ra; $sh + TRAINING_HOURS <= $rb; $sh++) $mslots[$sh] = 0; }
              else { $st = array_filter($pub['starts'][$ds] ?? [], fn($n) => $n >= 2); arsort($st); $mslots = $st; }
              if ($ds === $today) $mslots = array_filter($mslots, fn($n, $sh) => $sh > (int) date('G'), ARRAY_FILTER_USE_BOTH);
          }
          $mshow = array_slice($mslots, 0, 3, true); ?>
          <?php if ($mshow): ?><span class="mslots">
            <?php foreach ($mshow as $sh => $n): ?><span class="mslot<?= $avView === 'crew' ? ' is-crew' : '' ?>" role="button" tabindex="0" data-sheet-date="<?= $ds ?>" data-sheet-time="<?= sprintf('%02d:00', $sh) ?>" data-sheet-type="<?= $avView === 'crew' ? 'crew' : 'open' ?>" data-sheet-title="<?= e(ru_dow($ds) . ', ' . ru_date($ds)) ?>" title="Создать тренировку <?= $sh ?>:00–<?= $sh + TRAINING_HOURS ?>:00<?= $n ? ' — могут ' . $n . ' ' . plural($n, 'человек', 'человека', 'человек') : ' — удобно всему экипажу' ?>"><?= icon('plus') ?><?= $sh ?>–<?= $sh + TRAINING_HOURS ?><?php if ($n): ?><em><?= $n ?></em><?php endif ?></span><?php endforeach ?>
            <?php if (count($mslots) > 3): ?><span class="more">ещё <?= count($mslots) - 3 ?></span><?php endif ?>
          </span><?php elseif (isset($okDays[$ds])): ?><span class="crew-ok-time"><?= e(runs_label($okDays[$ds])) ?></span><?php endif ?>
          <?php if (isset($instrDays[$ds])): ?><span class="instr-ok" title="Может инструктор: <?= e(runs_label($instrDays[$ds])) ?>"><?= icon('anchor') ?><?= e(implode(', ', array_map(fn($r) => $r[0] . '–' . $r[1], $instrDays[$ds]))) ?></span><?php endif ?>
          <?php if (isset($pubBest[$ds]) && !$mshow): ?><span class="pub-ok" title="<?= e(best_label($pubBest[$ds])) ?>"><?= icon('users') ?><?= $pubBest[$ds][0] ?> чел. · <?= $pubBest[$ds][1] ?>–<?= $pubBest[$ds][1] + TRAINING_HOURS ?></span><?php endif ?>
          <?php foreach ($evByDay[$ds] ?? [] as $ev) echo ev_chip($ev); ?>
          <?php foreach (array_slice($list, 0, 3) as $t) echo chip($t); ?>
          <?php if (count($list) > 3): ?><span class="more">ещё <?= count($list) - 3 ?></span><?php endif ?>
        </span>
      </a>
    <?php endfor ?>
  </div>
  <?php else:
    $hourH = 48; $h0 = DAY_START_HOUR; $h1 = schedule_end_hour();
    // уже созданные тренировки позже сезонного времени не прячем
    for ($i = 0; $i < 7; $i++) foreach ($byDay[$ws->modify("+$i days")->format('Y-m-d')] ?? [] as $t) {
        $eh = (int) date('G', strtotime($t['end_at'])) + ((int) date('i', strtotime($t['end_at'])) ? 1 : 0);
        $h1 = min(24, max($h1, $eh));
    } ?>
  <div class="week" style="--hour-h:<?= $hourH ?>px;--hours:<?= $h1 - $h0 ?>">
    <div class="week-corner"></div>
    <?php for ($i = 0; $i < 7; $i++): $day = $ws->modify("+$i days"); $ds = $day->format('Y-m-d'); ?>
      <a class="week-dh<?= $ds === $today ? ' is-today' : '' ?><?= $ds < $today ? ' is-past' : '' ?><?= isset($okDays[$ds]) ? ' is-crew-ok' : '' ?>" href="<?= url('day.php?d=' . $ds) ?>"<?= isset($okDays[$ds]) ? ' title="Весь экипаж может"' : '' ?>><small><?= RU_DOW_S[$i + 1] ?><?= isset($okDays[$ds]) ? ' · экипаж' : '' ?></small><b><?= (int) $day->format('j') ?></b></a>
    <?php endfor ?>
    <?php $weekEv = false; for ($i = 0; $i < 7; $i++) if (!empty($evByDay[$ws->modify("+$i days")->format('Y-m-d')])) $weekEv = true;
    if ($weekEv): ?>
      <div class="week-allday-l">Регаты</div>
      <?php for ($i = 0; $i < 7; $i++): $ds = $ws->modify("+$i days")->format('Y-m-d'); ?>
        <div class="week-allday"><?php foreach ($evByDay[$ds] ?? [] as $ev): ?><a class="chip st-ev" href="<?= url('event.php?id=' . $ev['id']) ?>" title="<?= e(event_title($ev)) ?>"><b><?= icon('trophy', 'ic chip-ic') ?></b><span class="chip-t"><?= e(event_title($ev)) ?></span></a><?php endforeach ?></div>
      <?php endfor ?>
    <?php endif ?>
    <div class="week-times"><?php for ($h = $h0; $h < $h1; $h++): ?><span><?= sprintf('%02d:00', $h) ?></span><?php endfor ?></div>
    <?php for ($i = 0; $i < 7; $i++): $day = $ws->modify("+$i days"); $ds = $day->format('Y-m-d'); ?>
      <div class="week-col<?= $ds === $today ? ' is-today' : '' ?><?= isset($okDays[$ds]) ? ' is-crew-ok' : '' ?>">
        <?php if ($ds === $today && (int) date('G') >= $h0 && (int) date('G') < $h1):
            $nowTop = ((int) date('G') - $h0 + (int) date('i') / 60) * $hourH; ?>
          <span class="now-line" style="top:<?= round($nowTop) ?>px"></span>
        <?php endif ?>
        <?php $hc = $pub['hours'][$ds] ?? []; $mx = $hc ? max($hc) : 0;
        foreach ($hc as $hh => $cnt): if ($hh < $h0 || $hh >= $h1) continue; ?>
          <span class="pub-cell" style="top:<?= ($hh - $h0) * $hourH ?>px;height:<?= $hourH ?>px;--a:<?= round(.25 + .75 * $cnt / $mx, 2) ?>" title="Могут <?= $cnt ?> <?= plural($cnt, 'человек', 'человека', 'человек') ?>"><span><?= icon('users') ?><?= $cnt ?></span></span>
        <?php endforeach ?>
        <?php foreach ($instrDays[$ds] ?? [] as [$ra, $rb]): $ra = max($ra, $h0); $rb = min($rb, $h1); if ($rb <= $ra) continue; ?>
          <span class="instr-band" style="top:<?= ($ra - $h0) * $hourH ?>px;height:<?= ($rb - $ra) * $hourH ?>px" title="Может инструктор: <?= $ra ?>:00–<?= $rb ?>:00"><span><?= icon('anchor') ?></span></span>
        <?php endforeach ?>
        <?php if ($canCreate && !isset($noTr[$ds])) foreach ($okDays[$ds] ?? [] as [$ra, $rb]):
            for ($sh = max($ra, $h0); $sh + TRAINING_HOURS <= min($rb, $h1); $sh++):
                if ($ds === $today && $sh <= (int) date('G')) continue;
                $tm = sprintf('%02d:00', $sh); ?>
          <button class="crew-slot" type="button" style="top:<?= ($sh - $h0) * $hourH ?>px;height:<?= $hourH ?>px" data-sheet-date="<?= $ds ?>" data-sheet-time="<?= $tm ?>" data-sheet-type="crew" data-sheet-title="<?= e(ru_dow($ds) . ', ' . ru_date($ds)) ?>" aria-label="Создать тренировку экипажа <?= $sh ?>:00–<?= $sh + TRAINING_HOURS ?>:00">
            <span class="crew-slot-hl" style="height:<?= TRAINING_HOURS * $hourH ?>px"><span class="crew-slot-t"><?= icon('plus') ?>Создать тренировку<br><b><?= $sh ?>:00–<?= $sh + TRAINING_HOURS ?>:00</b></span></span>
          </button>
        <?php endfor; endforeach ?>
        <?php foreach ($okDays[$ds] ?? [] as [$ra, $rb]): $ra = max($ra, $h0); $rb = min($rb, $h1); if ($rb <= $ra) continue; ?>
          <span class="crew-band" style="top:<?= ($ra - $h0) * $hourH ?>px;height:<?= ($rb - $ra) * $hourH ?>px" title="Весь экипаж может"><span><?= icon('users') ?><?= $ra ?>:00–<?= $rb ?>:00</span></span>
        <?php endforeach ?>
        <?php foreach ($byDay[$ds] ?? [] as $t):
            $st = strtotime($t['start_at']);
            $top = (((int) date('G', $st) - $h0) + (int) date('i', $st) / 60) * $hourH; ?>
          <a class="wev st-<?= $t['state'] ?>" style="top:<?= round($top) + 2 ?>px;height:<?= TRAINING_HOURS * $hourH - 4 ?>px" href="<?= url('training.php?id=' . $t['id']) ?>">
            <b><?= hm($t['start_at']) ?>–<?= hm($t['end_at']) ?></b>
            <span class="wev-t"><?= e(training_title($t)) ?></span>
            <?php if ($ci = chip_instructor($t)): ?><span class="wev-m"><?= e($ci) ?></span><?php endif ?>
            <span class="wev-m"><?= $t['seats_used'] ?>/<?= BOAT_CAPACITY ?> · <?= e(chip_label($t)) ?></span>
          </a>
        <?php endforeach ?>
      </div>
    <?php endfor ?>
  </div>
  <?php endif ?>

  <div class="legend">
    <span class="lg st-open">Открыта запись</span><span class="lg st-crew">Только экипаж</span>
    <span class="lg st-need">Нужен инструктор</span><span class="lg st-mine">Вы участвуете</span>
    <span class="lg st-full">Мест нет</span><span class="lg st-ev">Регата</span>
    <?php if ($avView === 'crew'): ?><span class="lg lg-crew-ok">Весь экипаж может</span><?php else: ?><span class="lg lg-pub">Сколько человек могут</span><?php endif ?>
    <?php if ($showInstr): ?><span class="lg lg-instr">Может инструктор</span><?php endif ?>
  </div>
</section>

<?php if ($myCrew): ?>
<div class="seg seg-wide av-seg mob-only">
  <a class="seg-btn<?= $avView === 'crew' ? ' is-on' : '' ?>" href="<?= $link(['av' => null]) ?>">Экипаж «<?= e($myCrew['name']) ?>»</a>
  <a class="seg-btn<?= $avView === 'all' ? ' is-on' : '' ?>" href="<?= $link(['av' => 'all']) ?>">Все участники</a>
</div>
<?php endif ?>
<section class="feed mob-only">
  <?php
  $d = new DateTimeImmutable('today');
  while ($d < $end):
      $ds = $d->format('Y-m-d'); $list = $byDay[$ds] ?? []; $n = count($list); $evs = $evByDay[$ds] ?? []; ?>
    <div class="fday<?= $n || $evs ? '' : ' is-empty' ?><?= isset($okDays[$ds]) ? ' is-crew-ok' : '' ?><?= isset($pubBest[$ds]) ? ' is-pub' : '' ?>">
      <?php if (isset($noTr[$ds])): ?>
      <div class="fday-h is-blocked">
        <span class="fday-t"><b><?= e(ru_dow($ds)) ?></b><small><?= e(ru_date($ds)) ?> · регата, тренировок нет</small></span>
      </div>
      <?php else: ?>
      <button class="fday-h" type="button" data-sheet-date="<?= $ds ?>"<?= isset($okDays[$ds]) ? ' data-sheet-time="' . sprintf('%02d:00', $okDays[$ds][0][0]) . '" data-sheet-type="crew"' : (isset($pubBest[$ds]) ? ' data-sheet-time="' . sprintf('%02d:00', $pubBest[$ds][1]) . '" data-sheet-type="open"' : '') ?> data-sheet-title="<?= e(ru_dow($ds) . ', ' . ru_date($ds)) ?>">
        <span class="fday-t"><b><?= e(ru_dow($ds)) ?></b><small><?= e(ru_date($ds)) ?> · <?= $n ? $n . ' ' . plural($n, 'тренировка', 'тренировки', 'тренировок') : 'тренировок нет' ?></small><?php if (isset($okDays[$ds])): ?><span class="crew-ok-tag"><?= icon('users') ?>Весь экипаж может · <?= e(runs_label($okDays[$ds])) ?></span><?php endif ?><?php if (isset($instrDays[$ds])): ?><span class="crew-ok-tag instr-tag"><?= icon('anchor') ?>Может инструктор · <?= e(runs_label($instrDays[$ds])) ?></span><?php endif ?><?php if (isset($pubBest[$ds])): ?><span class="crew-ok-tag pub-tag"><?= icon('users') ?><?= $pubBest[$ds][0] === 1 ? 'Может' : 'Могут' ?> <?= e(best_label($pubBest[$ds])) ?></span><?php endif ?></span>
        <span class="fday-add"><?= icon('plus') ?></span>
      </button>
      <?php endif ?>
      <?php foreach ($evs as $ev): ?>
        <a class="fcard st-ev" href="<?= url('event.php?id=' . $ev['id']) ?>">
          <span class="fcard-body">
            <span class="fcard-top"><b class="fcard-when"><?= icon('trophy', 'ic fcard-ic') ?>Регата</b><span class="badge b-ev"><?= e(event_badge($ev)) ?></span></span>
            <b class="fcard-t"><?= e(event_title($ev)) ?></b>
            <span class="fcard-m"><?= e(event_dates($ev)) ?><?= $ev['place'] ? ' · ' . e($ev['place']) : '' ?></span>
          </span>
        </a>
      <?php endforeach ?>
      <?php foreach ($list as $t): ?>
        <a class="fcard st-<?= $t['state'] ?>" href="<?= url('training.php?id=' . $t['id']) ?>">
          <span class="fcard-body">
            <span class="fcard-top"><b class="fcard-when"><?= hm($t['start_at']) ?>–<?= hm($t['end_at']) ?></b><span class="badge b-st-<?= $t['state'] ?>"><?= e(chip_label($t)) ?></span></span>
            <b class="fcard-t"><?= e(training_title($t)) ?></b>
            <span class="fcard-m"><?= ($ci = chip_instructor($t)) ? e($ci) . ' · ' : '' ?>в лодке <?= $t['seats_used'] ?> из <?= BOAT_CAPACITY ?><?= $t['seats_used'] < MIN_CREW && $t['state'] !== 'past' && $t['state'] !== 'cancel' ? ' · нужно ещё ' . (MIN_CREW - $t['seats_used']) : '' ?></span>
          </span>
        </a>
      <?php endforeach ?>
    </div>
  <?php $d = $d->modify('+1 day'); endwhile ?>
</section>

<?= create_dialog($u, substr($link(), strlen(url('')))) ?>
<?php page_end();
