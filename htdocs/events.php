<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$uid = (int) $u['id'];
regatta_cleanup();
$today = date('Y-m-d');
$events = all('SELECT e.*, (SELECT COUNT(*) FROM event_people p WHERE p.event_id=e.id) AS cnt,
                      (SELECT p2.status FROM event_people p2 WHERE p2.event_id=e.id AND p2.user_id=?) AS my_status
               FROM events e ORDER BY e.start_date, e.id', [$uid]);

page_start('Регаты', 'events');
?>
<?php if (is_admin($u)): ?><div class="row-btns end"><a class="btn btn-primary" href="<?= url('admin/events.php') ?>"><?= icon('plus') ?><span>Создать регату</span></a></div><?php endif ?>
<div class="grid-2">
<?php foreach ($events as $ev):
    $past = $ev['end_date'] < $today;
    $teams = event_teams((int) $ev['id']);
    $openTeams = count(array_filter($teams, fn($t) => $t['kind'] === 'temp' && $t['free'] > 0));
    $mine = user_team((int) $ev['id'], $uid); ?>
  <a class="card ev ev-link<?= $past ? ' is-past' : '' ?>" href="<?= url('event.php?id=' . $ev['id']) ?>">
    <span class="ev-date"><b><?= (int) date('j', strtotime($ev['start_date'])) ?></b><span><?= e(RU_MON_G[(int) date('n', strtotime($ev['start_date']))]) ?></span></span>
    <span class="ev-body stack-sm">
      <span class="badges">
        <?php if (!empty($ev['stage'])): ?><span class="badge b-info">Этап <?= (int) $ev['stage'] ?></span><?php endif ?>
        <?php if ($mine && $mine['my_status'] === 'invited'): ?><span class="badge b-warn">Вас зовут в экипаж</span>
        <?php elseif ($mine): ?><span class="badge b-me">Вы в экипаже</span>
        <?php elseif ($ev['my_status'] === 'participated'): ?><span class="badge b-ok">Вы участвовали</span>
        <?php elseif ($ev['my_status']): ?><span class="badge b-ok">Вы участвуете</span><?php endif ?>
        <?php if ($past): ?><span class="badge b-muted">Прошла</span><?php elseif (!(int) $ev['reg_open']): ?><span class="badge b-muted">Регистрация закрыта</span>
        <?php elseif ($openTeams): ?><span class="badge b-open">Набор в экипаж: <?= $openTeams ?></span><?php endif ?>
      </span>
      <b class="ev-title"><?= e($ev['title']) ?></b>
      <span class="meta"><?= icon('calendar') ?><?= e(event_dates($ev)) ?></span>
      <?php if ($ev['place']): ?><span class="meta"><?= icon('pin') ?><?= e($ev['place']) ?></span><?php endif ?>
    </span>
    <span class="ev-go"><?= icon('right') ?></span>
  </a>
<?php endforeach ?>
</div>
<?php if (!$events): ?><div class="card empty"><?= icon('trophy', 'empty-ic') ?><p>Регат пока нет.</p></div><?php endif ?>
<?php page_end();
