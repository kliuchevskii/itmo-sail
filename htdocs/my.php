<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$uid = (int) $u['id'];

$sql = "SELECT t.*, c.name AS crew_name, tp.status AS my_status, tp.attended
        FROM trainings t LEFT JOIN crews c ON c.id=t.crew_id
        LEFT JOIN training_people tp ON tp.training_id=t.id AND tp.user_id=?
        WHERE (t.instructor_id=? OR tp.status IN ('confirmed','invited') OR (t.creator_id=? AND t.status<>'cancelled'))";
$upcoming = all($sql . " AND t.end_at>? AND t.status<>'cancelled' ORDER BY t.start_at", [$uid, $uid, $uid, now()]);
$past = all($sql . " AND t.end_at<=? ORDER BY t.start_at DESC LIMIT 30", [$uid, $uid, $uid, now()]);

function my_row(array $t, int $uid, bool $past): void
{
    $role = (int) $t['instructor_id'] === $uid ? ['me', 'Инструктор'] : ($t['my_status'] === 'invited' ? ['warn', 'Ждёт вашего ответа'] : ['ok', 'Участник']);
    if ($past) {
        if ($t['status'] === 'cancelled') $role = ['muted', 'Не состоялась'];
        elseif (!(int) $t['report_done']) $role = ['warn', 'Ждёт подтверждения'];
        elseif ((int) $t['instructor_id'] !== $uid) $role = (int) $t['attended'] ? ['ok', 'Засчитана'] : ['muted', 'Не засчитана'];
    } ?>
  <a class="fcard st-<?= $past ? 'past' : 'mine' ?>" href="<?= url('training.php?id=' . $t['id']) ?>">
    <span class="fcard-body">
      <span class="fcard-top"><b class="fcard-when"><?= hm($t['start_at']) ?>–<?= hm($t['end_at']) ?></b><span class="fcard-d"><?= e(ru_dow($t['start_at'])) ?>, <?= e(ru_date($t['start_at'])) ?></span></span>
      <b class="fcard-t"><?= e(training_title($t)) ?></b>
      <span><?= badges([$role]) ?></span>
    </span>
  </a>
<?php }

page_start('Мои тренировки', 'my');
?>
<div class="cols">
  <section class="col-main stack">
    <h2 class="sec-h">Предстоящие</h2>
    <?php foreach ($upcoming as $t) my_row($t, $uid, false); ?>
    <?php if (!$upcoming): ?><div class="card empty"><?= icon('sail', 'empty-ic') ?><p>Вы пока никуда не записаны.</p><a class="btn btn-primary" href="<?= url('index.php') ?>">Открыть расписание</a></div><?php endif ?>
  </section>
  <aside class="col-side stack">
    <h2 class="sec-h">Прошедшие</h2>
    <?php foreach ($past as $t) my_row($t, $uid, true); ?>
    <?php if (!$past): ?><p class="hint">Здесь появятся прошедшие тренировки.</p><?php endif ?>
  </aside>
</div>
<?php page_end();
