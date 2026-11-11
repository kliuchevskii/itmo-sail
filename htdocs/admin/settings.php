<?php
require __DIR__ . '/common.php';
$on = setting_get('season_on') === '1';
$latest = setting_get('season_latest') ?: SEASON_DEFAULT_LATEST;
$times = [];
for ($m = DAY_START_HOUR * 60; $m <= (DAY_END_HOUR - TRAINING_HOURS) * 60; $m += 30) $times[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);

page_start('Администрирование', 'admin', ['wide' => true]);
admin_tabs('settings');
?>
<div class="cols">
<section class="col-main stack">
  <div class="card stack">
    <h2 class="card-h"><?= icon('clock') ?>Время тренировок</h2>
    <?= form_open('admin_settings_save', 'class="stack"') ?>
      <label class="switch-row">
        <span class="switch"><input type="checkbox" name="season_on" value="1"<?= $on ? ' checked' : '' ?> data-season-toggle><span></span></span>
        <span class="switch-t"><b>Студенческий сезон</b><small>Действует студенческая скидка: выходы на воду разрешены только с началом не позже указанного времени. Новые тренировки можно создавать только в это время, записаться на более поздние нельзя.</small></span>
      </label>
      <label class="field season-latest"><span>Самое позднее начало тренировки</span>
        <select name="season_latest"><?php foreach ($times as $tm): ?><option<?= $tm === $latest ? ' selected' : '' ?>><?= $tm ?></option><?php endforeach ?></select></label>
      <p class="hint">Сейчас: <?= $on ? '<b>сезон включён</b>, тренировки начинаются с ' . sprintf('%02d:00', DAY_START_HOUR) . ' до ' . e($latest) . ' включительно.' : 'ограничения нет, начало с ' . sprintf('%02d:00', DAY_START_HOUR) . ' до ' . sprintf('%02d:00', DAY_END_HOUR - TRAINING_HOURS) . '.' ?></p>
      <button class="btn btn-primary" type="submit">Сохранить</button>
    </form>
  </div>
</section>
</div>
<?php page_end();
