<?php
/* Админка: одна регата — экипажи и участники */
require __DIR__ . '/common.php';
regatta_cleanup();
$ev = event_get((int) get('id'));
if (!$ev) { flash('Регата не найдена', 'err'); redirect('admin/events.php'); }
$eid = (int) $ev['id'];
$view = get('v') === 'people' ? 'people' : 'teams';
$teams = event_teams($eid);
$people = all("SELECT p.*, u.last_name, u.first_name, u.middle_name, u.login,
                      (SELECT COUNT(*) FROM documents d WHERE d.user_id=u.id AND d.doc_type='insurance' AND (d.valid_until IS NULL OR d.valid_until>=?)) AS ins
               FROM event_people p JOIN users u ON u.id=p.user_id WHERE p.event_id=? ORDER BY u.last_name, u.first_name", [$ev['end_date'], $eid]);
$teamOf = [];
foreach ($teams as $t) foreach ($t['members'] as $m) if ($m['status'] === 'confirmed') $teamOf[(int) $m['user_id']] = $t['name'];

page_start('Администрирование', 'admin', ['wide' => true, 'back' => 'admin/events.php']);
admin_tabs('events');
?>
<div class="card ev-head">
  <div class="card-top">
    <div class="stack-sm">
      <div class="badges">
        <?php if (!empty($ev['stage'])): ?><span class="badge b-info">Этап <?= (int) $ev['stage'] ?></span><?php endif ?>
        <?php if ($ev['end_date'] < date('Y-m-d')): ?><span class="badge b-muted">Прошла</span><?php elseif ((int) $ev['reg_open']): ?><span class="badge b-ok">Регистрация открыта</span><?php else: ?><span class="badge b-muted">Регистрация закрыта</span><?php endif ?>
      </div>
      <h2 class="ev-title"><?= e($ev['title']) ?></h2>
      <p class="meta"><?= icon('calendar') ?><?= e(event_dates($ev)) ?><?= $ev['place'] ? ' · ' . e($ev['place']) : '' ?></p>
    </div>
    <div class="row-btns">
      <a class="btn btn-sm" href="<?= url('event.php?id=' . $eid) ?>">Страница для спортсменов</a>
      <a class="btn btn-sm" href="<?= url('admin/events.php?edit=' . $eid) ?>">Изменить</a>
      <?= action_button('admin_event_delete', ['eid' => $eid], 'Удалить', 'btn btn-sm btn-danger', 'Удалить регату со всеми регистрациями и экипажами?') ?>
    </div>
  </div>
</div>

<div class="seg seg-wide">
  <a class="seg-btn<?= $view === 'teams' ? ' is-on' : '' ?>" href="<?= url('admin/event.php?id=' . $eid) ?>">Экипажи · <?= count($teams) ?></a>
  <a class="seg-btn<?= $view === 'people' ? ' is-on' : '' ?>" href="<?= url('admin/event.php?id=' . $eid . '&v=people') ?>">Участники · <?= count($people) ?></a>
</div>

<?php if ($view === 'teams'): ?>
  <div class="team-grid">
  <?php foreach ($teams as $t):
      $confirmed = count(array_filter($t['members'], fn($m) => $m['status'] === 'confirmed')); ?>
    <div class="card team-card">
      <div class="team-card-head">
        <div><h3 class="team-name"><?= e($t['name']) ?></h3><small><?= $t['kind'] === 'home' ? 'Экипаж секции' : 'Собран на регату' ?> · <?= $confirmed ?> из <?= REGATTA_CREW_MAX ?></small></div>
        <span class="badges">
          <?php if ($confirmed < MIN_CREW): ?><span class="badge b-warn">Меньше <?= MIN_CREW ?></span><?php endif ?>
          <?php if ($t['kind'] === 'temp' && $t['free'] > 0): ?><span class="badge b-open">Свободно: <?= $t['free'] ?></span><?php endif ?>
        </span>
      </div>
      <?php if ($t['note']): ?><p class="hint"><?= e($t['note']) ?></p><?php endif ?>
      <ul class="members">
        <?php foreach ($t['members'] as $m): ?>
          <li><span class="m-name"><?= e(full_name($m)) ?></span><span class="m-role"><?= e(role_label($m['role']) ?: 'роль не указана') ?></span>
            <?php if ($m['status'] === 'invited'): ?><span class="badge b-warn">Ждём ответа</span><?php endif ?></li>
        <?php endforeach ?>
      </ul>
    </div>
  <?php endforeach ?>
  </div>
  <?php if (!$teams): ?><div class="card empty"><?= icon('users', 'empty-ic') ?><p>Экипажей пока нет.</p></div><?php endif ?>

<?php else: ?>
  <div class="card">
    <?php if ($people): ?>
    <?= form_open('admin_event_mark', 'class="stack"') ?>
      <input type="hidden" name="eid" value="<?= $eid ?>">
      <ul class="members">
      <?php foreach ($people as $p): ?>
        <li><label class="check grow"><input type="checkbox" name="participated[]" value="<?= $p['user_id'] ?>"<?= $p['status'] === 'participated' ? ' checked' : '' ?>>
          <span class="m-col"><span class="m-name"><?= e(full_name($p)) ?></span>
            <span class="m-role"><?= e(implode(' · ', array_filter([role_label($p['crew_role']), isset($teamOf[(int) $p['user_id']]) ? 'экипаж «' . $teamOf[(int) $p['user_id']] . '»' : 'лично']))) ?></span></span></label>
          <span class="badge <?= (int) $p['ins'] ? 'b-ok' : 'b-warn' ?>"><?= (int) $p['ins'] ? 'Страховка есть' : 'Нет страховки' ?></span></li>
      <?php endforeach ?>
      </ul>
      <p class="hint">Отметьте, кто участвовал, — это попадёт в опыт спортсменов.</p>
      <button class="btn btn-primary" type="submit">Сохранить участие</button>
    </form>
    <?php else: ?><p class="hint">Регистраций пока нет.</p><?php endif ?>
  </div>
<?php endif ?>
<?php page_end();
