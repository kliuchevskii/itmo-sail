<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$uid = (int) $u['id'];

$t = training_get((int) get('id'));
if (!$t || (!can_see($t, $u) && !is_admin($u))) { flash('Тренировка не найдена', 'err'); redirect('index.php'); }
$people = training_people((int) $t['id']);
$v = training_view($t, $people, $u);
$instr = $t['instructor_id'] ? one('SELECT * FROM users WHERE id=?', [$t['instructor_id']]) : null;
$creator = one('SELECT * FROM users WHERE id=?', [$t['creator_id']]);
$statusTxt = ['confirmed' => 'Идёт', 'invited' => 'Ждём ответа', 'declined' => 'Не сможет', 'left' => 'Выписался'];

$instrCandidates = [];
if ($v['can_invite_instr']) {
    $busyIds = array_map(fn($p) => (int) $p['user_id'], active_people($people));
    foreach (all('SELECT * FROM users WHERE clearance>=1 AND active=1 ORDER BY last_name, first_name') as $c) {
        if (in_array((int) $c['id'], $busyIds, true)) continue;
        $c['busy'] = (bool) user_busy((int) $c['id'], $t['start_at'], $t['end_at'], (int) $t['id']);
        $instrCandidates[] = $c;
    }
}
$invInstr = all("SELECT ii.*, u.last_name, u.first_name, u.middle_name, u.login FROM instructor_invites ii JOIN users u ON u.id=ii.instructor_id
                 WHERE ii.training_id=? AND ii.status='pending'", [$t['id']]);

page_start(training_title($t), 'schedule', ['back' => 'day.php?d=' . substr($t['start_at'], 0, 10)]);
?>
<div class="cols">
<section class="col-main stack">
  <div class="card">
    <div class="badges"><?= badges($v['badges']) ?></div>
    <div class="t-when">
      <div class="t-date"><b><?= e(ru_dow($t['start_at'])) ?></b><span><?= e(ru_date($t['start_at'], true)) ?></span></div>
      <div class="t-time"><?= hm($t['start_at']) ?> – <?= hm($t['end_at']) ?></div>
    </div>
    <dl class="dl">
      <dt>Инструктор</dt><dd><?= $instr ? e(full_name($instr)) : ((int) $t['needs_instructor'] ? 'Пока нет — ищем инструктора' : ($t['kind'] === 'crew' ? 'Не требуется, пока выходят только свои и у экипажа есть допуск' : '—')) ?></dd>
      <?php if ($t['crew_name']): ?><dt>Экипаж</dt><dd><?= e($t['crew_name']) ?><?= (int) $t['crew_clearance'] ? ' · допуск экипажа есть' : ' · без допуска экипажа' ?></dd><?php endif ?>
      <dt>Запись</dt><dd><?= $t['visibility'] === 'public' ? 'Открыта для всех' : 'Только для экипажа' ?></dd>
      <dt>Мест в лодке</dt><dd><?= seats_used($t, $people) ?> из <?= BOAT_CAPACITY ?><?= INSTRUCTOR_TAKES_SEAT ? ' (с инструктором)' : '' ?></dd>
      <dt><?= $creator ? e(gw($creator, 'Создал', 'Создала')) : 'Создатель' ?></dt><dd><?= $creator ? e(full_name($creator)) : 'Удалённый участник' ?></dd>
      <?php if ($t['note']): ?><dt>Комментарий</dt><dd><?= nl2br(e($t['note'])) ?></dd><?php endif ?>
      <?php if ($t['status'] === 'cancelled' && $t['cancel_reason']): ?><dt>Причина отмены</dt><dd><?= e($t['cancel_reason']) ?></dd><?php endif ?>
    </dl>

    <div class="row-btns">
      <?php
      if (!empty($v['season_block'])) echo '<p class="hint season-note">' . icon('clock') . 'Студенческий сезон: запись открыта только на тренировки, которые начинаются не позже ' . e(season_latest()) . '.</p>';
      if ($v['can_join']) echo action_button('join', ['tid' => $t['id']], 'Записаться', 'btn btn-primary', '', 'check');
      if ($v['can_join_crew']) echo action_button('join_crew', ['tid' => $t['id']], 'Записаться экипажем «' . $v['user_crew']['name'] . '»', 'btn', 'Всем членам экипажа придёт приглашение, каждый должен подтвердить участие.', 'users');
      if ($v['can_confirm']) {
          echo action_button('confirm', ['tid' => $t['id']], 'Подтвердить участие', 'btn btn-primary', '', 'check');
          echo action_button('decline', ['tid' => $t['id']], 'Не смогу', 'btn', $t['kind'] === 'crew' ? 'Тренировка автоматически откроется для добора участников.' : '');
      }
      if ($v['can_leave']) echo action_button('leave', ['tid' => $t['id']], 'Выписаться', 'btn', $t['kind'] === 'crew' && $v['in_crew'] ? 'Вы член экипажа: тренировка автоматически откроется для добора. Продолжить?' : 'Выписаться из тренировки?');
      if ($v['can_take']) echo action_button('take', ['tid' => $t['id']], 'Стать инструктором', 'btn btn-primary', 'Вы будете инструктором на этой тренировке.', 'anchor');
      if ($v['can_drop']) echo action_button('drop', ['tid' => $t['id']], 'Отказаться от роли инструктора', 'btn', 'Экипажу понадобится другой инструктор. Продолжить?');
      if ($v['can_open']) echo action_button('open_public', ['tid' => $t['id']], 'Открыть добор для всех', 'btn', 'Тренировка появится в открытом доступе для записи.');
      ?>
    </div>
  </div>

  <div class="card">
    <h2 class="card-h"><?= icon('users') ?>Участники</h2>
    <ul class="people">
      <?php if ($instr): ?>
        <li><span class="avatar sm"><?= e(initials($instr)) ?></span><span class="p-name"><b><?= e(full_name($instr)) ?></b><small>Инструктор · <?= e(CLEARANCES[(int) $instr['clearance']]) ?></small></span><span class="badge b-me">Инструктор</span></li>
      <?php endif ?>
      <?php $going = array_filter($people, fn($p) => $p['status'] === 'confirmed');
      foreach ($going as $p): ?>
        <li class="<?= !$t['report_done'] || (int) $p['attended'] ? '' : 'is-off' ?>">
          <span class="avatar sm"><?= e(initials($p)) ?></span>
          <span class="p-name"><b><?= e(full_name($p)) ?></b><small><?= e(implode(' · ', array_filter([course_label($p), $p['crew_roles'] ? crew_roles_label($p['crew_roles']) : '', $p['via_crew'] ? 'с экипажем' : '']))) ?></small></span>
          <?php if ($t['report_done']): ?><span class="badge att b-<?= (int) $p['attended'] ? 'ok' : 'muted' ?>"><?= e((int) $p['attended'] ? gw($p, 'Был', 'Была') : gw($p, 'Не был', 'Не была')) ?></span>
<?php endif ?>
        </li>
      <?php endforeach ?>
      <?php if (!$going && !$instr): ?><li class="is-off"><span class="p-name">Пока никто не записан</span></li><?php endif ?>
    </ul>
  </div>

  <?php if ($v['can_report']): $confirmed = array_filter($people, fn($p) => $p['status'] === 'confirmed'); ?>
  <div class="card card-accent">
    <h2 class="card-h"><?= icon('check') ?>Подтвердить проведение</h2>
    <p class="hint">Отметьте, кто был на тренировке. По этим отметкам администраторы выставляют баллы за физкультуру.</p>
    <?= form_open('report', 'class="stack"') ?>
      <input type="hidden" name="tid" value="<?= $t['id'] ?>">
      <?php foreach ($confirmed as $p): ?>
        <label class="check"><input type="checkbox" name="attended[]" value="<?= $p['user_id'] ?>" checked><span><?= e(full_name($p)) ?> <small class="muted"><?= e(course_label($p)) ?><?= needs_points($p) ? ' · нужны баллы' : '' ?></small></span></label>
      <?php endforeach ?>
      <?php if (!$confirmed): ?><p class="hint">Подтверждённых участников нет.</p><?php endif ?>
      <div class="row-btns">
        <button class="btn btn-primary" type="submit" name="held" value="1">Тренировка состоялась</button>
        <button class="btn" type="submit" name="held" value="0" data-confirm-click="Отметить, что тренировка не состоялась?">Не состоялась</button>
      </div>
    </form>
  </div>
  <?php endif ?>
</section>

<aside class="col-side stack">
  <?php if ($v['can_invite_instr']): ?>
  <div class="card">
    <h2 class="card-h"><?= icon('anchor') ?>Нужен инструктор</h2>
    <p class="hint">Тренировка уже видна всем инструкторам в разделе «Запросы инструкторам». Можно позвать конкретного человека:</p>
    <?php if ($invInstr): ?><p class="meta">Ждём ответа: <?php foreach ($invInstr as $ii): ?><span class="pill"><?= e(short_name($ii)) ?></span><?php endforeach ?></p><?php endif ?>
    <?= form_open('invite_instr', 'class="stack"') ?>
      <input type="hidden" name="tid" value="<?= $t['id'] ?>">
      <label class="field"><span>Инструктор</span>
        <select name="instructor_id" required>
          <option value="">— выберите —</option>
          <?php foreach ($instrCandidates as $c): ?><option value="<?= $c['id'] ?>"<?= $c['busy'] ? ' disabled' : '' ?>><?= e(full_name($c)) ?><?= $c['busy'] ? ' (занят)' : '' ?></option><?php endforeach ?>
        </select></label>
      <button class="btn btn-primary btn-block" type="submit">Отправить приглашение</button>
    </form>
  </div>
  <?php endif ?>

  <?php if ($v['can_cancel']): ?>
  <div class="card">
    <h2 class="card-h"><?= icon('x') ?>Отмена</h2>
    <?= form_open('cancel', 'class="stack" data-confirm="Отменить тренировку полностью? Все участники получат уведомление."') ?>
      <input type="hidden" name="tid" value="<?= $t['id'] ?>">
      <label class="field"><span>Причина (увидят все записанные)</span><input name="reason" maxlength="300" placeholder="Например: сильный ветер"></label>
      <button class="btn btn-danger btn-block" type="submit">Отменить тренировку</button>
    </form>
  </div>
  <?php endif ?>
</aside>
</div>
<?php page_end();
