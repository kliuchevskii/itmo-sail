<?php
require __DIR__ . '/common.php';
regatta_cleanup();
$edit = get('edit') ? event_get((int) get('edit')) : null;
$e = $edit ?: ['id' => 0, 'title' => '', 'stage' => null, 'no_trainings' => 0, 'start_date' => date('Y-m-d', strtotime('+14 days')), 'end_date' => '', 'place' => '', 'description' => '', 'reg_open' => 1];
$events = all('SELECT * FROM events ORDER BY start_date, id');

page_start('Администрирование', 'admin', ['wide' => true]);
admin_tabs('events');
?>
<div class="row-btns end mob-only"><button class="btn btn-primary btn-block" type="button" data-open-dialog="dlg-event"><?= icon('plus') ?><span>Новая регата</span></button></div>
<div class="cols">
<section class="col-main stack">
  <?php foreach ($events as $ev):
      $eid = (int) $ev['id'];
      $teamsN = (int) val('SELECT COUNT(*) FROM event_teams WHERE event_id=?', [$eid]);
      $peopleN = (int) val('SELECT COUNT(*) FROM event_people WHERE event_id=?', [$eid]);
      $past = $ev['end_date'] < date('Y-m-d'); ?>
    <a class="card ev ev-link<?= $past ? ' is-past' : '' ?>" href="<?= url('admin/event.php?id=' . $eid) ?>">
      <span class="ev-date"><b><?= (int) date('j', strtotime($ev['start_date'])) ?></b><span><?= e(RU_MON_G[(int) date('n', strtotime($ev['start_date']))]) ?></span></span>
      <span class="ev-body stack-sm">
        <span class="badges">
          <?php if (!empty($ev['stage'])): ?><span class="badge b-info">Этап <?= (int) $ev['stage'] ?></span><?php endif ?>
          <?php if ($past): ?><span class="badge b-muted">Прошла</span><?php elseif ((int) $ev['reg_open']): ?><span class="badge b-ok">Регистрация открыта</span><?php else: ?><span class="badge b-muted">Регистрация закрыта</span><?php endif ?>
        </span>
        <b class="ev-title"><?= e($ev['title']) ?></b>
        <span class="meta"><?= icon('calendar') ?><?= e(event_dates($ev)) ?><?= $ev['place'] ? ' · ' . e($ev['place']) : '' ?></span>
        <span class="meta"><?= icon('users') ?>Экипажей: <?= $teamsN ?> · участников: <?= $peopleN ?></span>
      </span>
      <span class="ev-go"><?= icon('right') ?></span>
    </a>
  <?php endforeach ?>
  <?php if (!$events): ?><div class="card empty"><?= icon('trophy', 'empty-ic') ?><p>Регат пока нет.</p></div><?php endif ?>
</section>
<aside class="col-side desk-only">
  <div class="card stack">
    <h2 class="card-h"><?= icon($edit ? 'trophy' : 'plus') ?><?= $edit ? 'Изменить регату' : 'Новая регата' ?></h2>
    <?= form_open('admin_event_save', 'class="stack"') ?>
      <input type="hidden" name="eid" value="<?= (int) $e['id'] ?>">
      <label class="field"><span>Название</span><input name="title" value="<?= e($e['title']) ?>" required></label>
      <label class="field"><span>Этап (необязательно)</span>
        <select name="stage"><option value="">Без этапов</option>
          <?php for ($i = 1; $i <= STAGES_MAX; $i++): ?><option value="<?= $i ?>"<?= (int) $e['stage'] === $i ? ' selected' : '' ?>><?= $i ?>-й этап</option><?php endfor ?>
        </select></label>
      <div class="grid-2">
        <label class="field"><span>Начало</span><input type="date" name="start_date" value="<?= e($e['start_date']) ?>" required></label>
        <label class="field"><span>Окончание</span><input type="date" name="end_date" value="<?= e($e['end_date']) ?>"></label>
      </div>
      <label class="field"><span>Место</span><input name="place" value="<?= e($e['place']) ?>"></label>
      <label class="field"><span>Описание</span><textarea name="description" rows="3"><?= e($e['description']) ?></textarea></label>
      <label class="check"><input type="checkbox" name="reg_open" value="1"<?= (int) $e['reg_open'] ? ' checked' : '' ?>><span>Регистрация открыта</span></label>
      <label class="check"><input type="checkbox" name="no_trainings" value="1"<?= (int) ($e['no_trainings'] ?? 0) ? ' checked' : '' ?>><span>В дни регаты тренировки не проводятся</span></label>
      <?php if (!$edit): ?><label class="check"><input type="checkbox" name="announce" value="1" checked><span>Уведомить всех участников</span></label><?php endif ?>
      <button class="btn btn-primary btn-block" type="submit"><?= $edit ? 'Сохранить' : 'Создать' ?></button>
      <?php if ($edit): ?><a class="btn btn-block" href="<?= url('admin/events.php') ?>">Отмена</a><?php endif ?>
    </form>
  </div>
</aside>
</div>
<dialog class="sheet mob-dialog" id="dlg-event"<?= $edit ? ' data-autoopen-mobile' : '' ?>>
  <div class="sheet-head"><div><small>Регаты</small><h2><?= $edit ? 'Изменить регату' : 'Новая регата' ?></h2></div><button class="icon-btn ghost" type="button" data-close-dialog aria-label="Закрыть"><?= icon('x') ?></button></div>
  <div class="sheet-body">
    <?= form_open('admin_event_save', 'class="stack"') ?>
      <input type="hidden" name="eid" value="<?= (int) $e['id'] ?>">
      <label class="field"><span>Название</span><input name="title" value="<?= e($e['title']) ?>" required></label>
      <label class="field"><span>Этап (необязательно)</span>
        <select name="stage"><option value="">Без этапов</option>
          <?php for ($i = 1; $i <= STAGES_MAX; $i++): ?><option value="<?= $i ?>"<?= (int) $e['stage'] === $i ? ' selected' : '' ?>><?= $i ?>-й этап</option><?php endfor ?>
        </select></label>
      <div class="grid-2">
        <label class="field"><span>Начало</span><input type="date" name="start_date" value="<?= e($e['start_date']) ?>" required></label>
        <label class="field"><span>Окончание</span><input type="date" name="end_date" value="<?= e($e['end_date']) ?>"></label>
      </div>
      <label class="field"><span>Место</span><input name="place" value="<?= e($e['place']) ?>"></label>
      <label class="field"><span>Описание</span><textarea name="description" rows="3"><?= e($e['description']) ?></textarea></label>
      <label class="check"><input type="checkbox" name="reg_open" value="1"<?= (int) $e['reg_open'] ? ' checked' : '' ?>><span>Регистрация открыта</span></label>
      <label class="check"><input type="checkbox" name="no_trainings" value="1"<?= (int) ($e['no_trainings'] ?? 0) ? ' checked' : '' ?>><span>В дни регаты тренировки не проводятся</span></label>
      <?php if (!$edit): ?><label class="check"><input type="checkbox" name="announce" value="1" checked><span>Уведомить всех участников</span></label><?php endif ?>
      <button class="btn btn-primary btn-block" type="submit"><?= $edit ? 'Сохранить' : 'Создать' ?></button>
      <?php if ($edit): ?><a class="btn btn-block" href="<?= url('admin/events.php') ?>">Отмена</a><?php endif ?>
    </form>
  </div>
</dialog>
<?php page_end();
