<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$uid = (int) $u['id'];

$d = get('d', date('Y-m-d'));
if (!valid_date($d)) redirect('index.php');
[$sStart, $sEnd] = schedule_bounds();
$past = $d < date('Y-m-d');
$inRange = $d < $sEnd->format('Y-m-d');

$list = all("SELECT t.*, c.name AS crew_name, c.clearance AS crew_clearance FROM trainings t LEFT JOIN crews c ON c.id=t.crew_id
             WHERE t.start_at>=? AND t.start_at<? ORDER BY t.start_at", [$d . ' 00:00:00', $d . ' 23:59:59']);
$opts = create_options($u);
$myCrew = user_crew($uid);
$myCrewId = $myCrew ? (int) $myCrew['id'] : null;

page_start(ru_date($d) . ', ' . mb_strtolower(RU_DOW_S[(int) date('N', strtotime($d))]), 'schedule', ['back' => 'index.php']);
?>
<?php $blockedBy = no_training_days($d, $d)[$d] ?? null; ?>
<?php if ($blockedBy): ?><div class="flash flash-warn">В этот день регата «<?= e($blockedBy) ?>» — тренировки не проводятся.</div>
<?php elseif (!$past && $inRange && $opts): ?><div class="row-btns end"><?= create_button($d) ?></div><?php endif ?>
<section class="stack">
  <?php foreach (events_range($d, (new DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d'), $uid)[$d] ?? [] as $ev): ?>
    <a class="card ev ev-link" href="<?= url('event.php?id=' . $ev['id']) ?>">
      <span class="ev-date"><?= icon('trophy') ?></span>
      <span class="ev-body stack-sm">
        <span class="badges"><span class="badge b-ev"><?= e(event_badge($ev)) ?></span></span>
        <b class="ev-title"><?= e(event_title($ev)) ?></b>
        <span class="meta"><?= e(event_dates($ev)) ?><?= $ev['place'] ? ' · ' . e($ev['place']) : '' ?></span>
      </span>
      <span class="ev-go"><?= icon('right') ?></span>
    </a>
  <?php endforeach ?>
  <?php
  $shown = 0;
  foreach ($list as $t):
      $people = training_people((int) $t['id']);
      $v = training_view($t, $people, $u);
      $involved = $v['my_status'] || $v['is_instructor'] || (int) $t['creator_id'] === $uid;
      if ($t['status'] === 'cancelled') continue;
      if (!can_see_training($t, $u, $myCrewId, (bool) $involved)) continue;
      $shown++;
      $instr = $t['instructor_id'] ? one('SELECT * FROM users WHERE id=?', [$t['instructor_id']]) : null; ?>
    <article class="card tcard">
      <div class="tcard-time"><b><?= hm($t['start_at']) ?>–<?= hm($t['end_at']) ?></b></div>
      <div class="tcard-body">
        <div class="badges"><?= badges($v['badges']) ?></div>
        <h3><?= e(training_title($t)) ?></h3>
        <p class="meta"><?= icon('user') ?><?= $instr ? 'Инструктор: ' . e(full_name($instr)) : 'Без инструктора' ?></p>
        <p class="meta"><?= icon('users') ?>В лодке <?= seats_used($t, $people) ?> из <?= BOAT_CAPACITY ?><?php foreach (active_people($people) as $p): ?><span class="pill"><?= e(short_name($p)) ?></span><?php endforeach ?></p>
        <div class="row-btns">
          <?php if ($v['can_join']) echo action_button('join', ['tid' => $t['id']], 'Записаться', 'btn btn-primary', '', 'check'); ?>
          <?php if ($v['can_confirm']) echo action_button('confirm', ['tid' => $t['id']], 'Подтвердить участие', 'btn btn-primary', '', 'check'); ?>
          <a class="btn" href="<?= url('training.php?id=' . $t['id']) ?>">Подробнее</a>
        </div>
      </div>
    </article>
  <?php endforeach ?>
  <?php if (!$shown): ?>
    <div class="card empty"><?= icon('sail', 'empty-ic') ?><p>На этот день тренировок пока нет.</p></div>
  <?php endif ?>
</section>

<?php if (!$past && $inRange) echo create_dialog($u, 'day.php?d=' . $d); ?>
<?php page_end();
