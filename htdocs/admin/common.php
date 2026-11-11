<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_admin();

function admin_tabs(string $on): void
{
    $tabs = ['index' => ['admin/index.php', 'Участники'], 'crews' => ['admin/crews.php', 'Экипажи'],
             'reports' => ['admin/reports.php', 'Баллы за физкультуру'], 'events' => ['admin/events.php', 'Регаты'], 'settings' => ['admin/settings.php', 'Настройки']];
    echo '<div class="tabs">';
    foreach ($tabs as $k => [$href, $label]) echo '<a class="tab-btn' . ($k === $on ? ' is-on' : '') . '" href="' . url($href) . '">' . e($label) . '</a>';
    echo '</div>';
}
