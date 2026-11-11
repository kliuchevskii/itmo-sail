<?php
require __DIR__ . '/common.php';
$crews = all('SELECT c.* FROM crews c ORDER BY c.name');

page_start('Администрирование', 'admin', ['wide' => true]);
admin_tabs('crews');
?>
<p class="lead">При изменении состава экипажа допуск снимается автоматически.</p>
<div class="grid-2">
<?php foreach ($crews as $c): $members = crew_members((int) $c['id']); ?>
  <div class="card stack">
    <div class="card-top">
      <h2 class="card-h"><?= icon('sail') ?>«<?= e($c['name']) ?>»</h2>
      <span class="badge <?= (int) $c['clearance'] ? 'b-ok' : 'b-muted' ?>"><?= (int) $c['clearance'] ? 'Допуск экипажа есть' : 'Допуска нет' ?></span>
    </div>
    <ul class="people">
      <?php foreach ($members as $m): ?>
        <li><span class="avatar sm"><?= e(initials($m)) ?></span><span class="p-name"><b><?= e(full_name($m)) ?></b><small><?= e(implode(' · ', array_filter([LEVELS[(int) $m['level']], $m['crew_roles'] ? crew_roles_label($m['crew_roles']) : '']))) ?></small></span></li>
      <?php endforeach ?>
    </ul>
    <?= (int) $c['clearance']
        ? action_button('admin_crew_clearance', ['cid' => $c['id'], 'value' => 0], 'Снять допуск экипажа', 'btn', 'Снять допуск экипажа?')
        : action_button('admin_crew_clearance', ['cid' => $c['id'], 'value' => 1], 'Выдать допуск экипажа', 'btn btn-primary', 'Выдать допуск экипажу в текущем составе?') ?>
  </div>
<?php endforeach ?>
</div>
<?php if (!$crews): ?><div class="card empty"><?= icon('users', 'empty-ic') ?><p>Экипажей пока нет.</p></div><?php endif ?>
<?php page_end();
