<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
if (!is_instructor($u)) { flash('Раздел доступен участникам с личным допуском.', 'err'); redirect('index.php'); }
$uid = (int) $u['id'];

$list = all("SELECT t.*, c.name AS crew_name, c.clearance AS crew_clearance FROM trainings t LEFT JOIN crews c ON c.id=t.crew_id
             WHERE t.kind IN ('crew','open') AND t.status<>'cancelled' AND t.needs_instructor=1 AND t.instructor_id IS NULL AND t.start_at>?
             ORDER BY t.start_at", [now()]);
$invited = array_map('intval', array_column(all("SELECT training_id FROM instructor_invites WHERE instructor_id=? AND status='pending'", [$uid]), 'training_id'));

page_start('Запросы инструкторам', 'instructors');
?>
<p class="lead">Экипажи и сборные составы, которым нужен инструктор. Возьмите тренировку — и она станет вашей.</p>
<div class="stack">
<?php foreach ($list as $t):
    $people = training_people((int) $t['id']);
    $v = training_view($t, $people, $u); ?>
  <article class="card tcard">
    <div class="tcard-time"><b><?= hm($t['start_at']) ?>–<?= hm($t['end_at']) ?></b></div>
    <div class="tcard-body">
      <div class="badges"><?= badges($v['badges']) ?><?php if (in_array((int) $t['id'], $invited, true)): ?><span class="badge b-me">Вас пригласили</span><?php endif ?></div>
      <h3><?= e(ru_dow($t['start_at'])) ?>, <?= e(ru_date($t['start_at'])) ?> · <?= e(training_title($t)) ?></h3>
      <p class="meta"><?= icon('users') ?><?php foreach (active_people($people) as $p): ?><span class="pill"><?= e(short_name($p)) ?> · <?= e(LEVELS[(int) $p['level']]) ?></span><?php endforeach ?></p>
      <div class="row-btns">
        <?php if ($v['can_take']) echo action_button('take', ['tid' => $t['id']], 'Взять тренировку', 'btn btn-primary', 'Вы будете инструктором на этой тренировке.', 'anchor'); ?>
        <a class="btn" href="<?= url('training.php?id=' . $t['id']) ?>">Подробнее</a>
      </div>
    </div>
  </article>
<?php endforeach ?>
<?php if (!$list): ?><div class="card empty"><?= icon('anchor', 'empty-ic') ?><p>Сейчас запросов нет.</p></div><?php endif ?>
</div>
<?php page_end();
