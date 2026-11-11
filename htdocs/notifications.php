<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

function notif_cat(array $n): string { return notif_category((string) $n['url']); }
$cats = PUSH_CATS;

$all = all('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 300', [$u['id']]);
$by = array_fill_keys(array_keys($cats), []);
foreach ($all as $n) $by[notif_cat($n)][] = $n;
$unread = array_map(fn($l) => count(array_filter($l, fn($n) => !(int) $n['is_read'])), $by);

$cat = get('c');
if (!isset($cats[$cat])) {
    $cat = 'training';
    foreach (array_keys($cats) as $k) if ($unread[$k]) { $cat = $k; break; }
}
if ($cat === 'other' && !$by['other']) $cat = 'training';
$list = $by[$cat];

page_start('Уведомления', 'notifications');
$pushOff = array_filter(explode(',', (string) $u['push_off']));
$vapid = null;
try { $vapid = vapid_keys()['public']; } catch (Throwable $e) { $vapid = null; }
// Открыли раздел — его уведомления считаются прочитанными (выделение остаётся до следующего захода)
$ids = array_column(array_filter($list, fn($n) => !(int) $n['is_read']), 'id');
if ($ids) q('UPDATE notifications SET is_read=1 WHERE user_id=? AND id IN (' . in_list($ids) . ')', array_merge([$u['id']], $ids));
?>
<div class="card push-card" data-push data-key="<?= e((string) $vapid) ?>" data-api="<?= url('push_api.php') ?>" data-csrf="<?= csrf_token() ?>">
  <div class="push-head">
    <span class="alert-ic"><?= icon('bell') ?></span>
    <div class="grow"><b>Уведомления на телефон</b><small data-push-status>Проверяем…</small></div>
  </div>
  <div class="row-btns" data-push-actions hidden>
    <button class="btn btn-primary" type="button" data-push-on>Включить на этом устройстве</button>
    <button class="btn" type="button" data-push-test hidden>Проверить</button>
    <button class="btn" type="button" data-push-off hidden>Отключить на этом устройстве</button>
  </div>
  <p class="hint" data-push-hint hidden></p>
  <p class="hint" data-push-oem>Xiaomi, Huawei, Honor, Realme: если уведомления приходят с опозданием, в настройках телефона для Chrome (или приложения ITMO Sail) разрешите автозапуск, уведомления на экране блокировки и отключите экономию батареи.</p>
  <div class="push-prefs">
    <span class="push-prefs-t">Присылать на телефон:</span>
    <?= form_open('push_prefs', 'class="toggles"') ?>
      <input type="hidden" name="cats[]" value="__none">
      <?php foreach (PUSH_CATS as $k => $label): ?>
        <label class="toggle"><input type="checkbox" name="cats[]" value="<?= $k ?>"<?= in_array($k, $pushOff, true) ? '' : ' checked' ?> data-autosubmit><span><?= e($label) ?></span></label>
      <?php endforeach ?>
    </form>
  </div>
</div>
<div class="tabs">
  <?php foreach ($cats as $k => $label): if ($k === 'other' && !$by['other']) continue; ?>
    <a class="tab-btn<?= $k === $cat ? ' is-on' : '' ?>" href="<?= url('notifications.php?c=' . $k) ?>"><?= e($label) ?><?php if ($unread[$k] && $k !== $cat): ?> <em class="tab-count"><?= $unread[$k] ?></em><?php endif ?></a>
  <?php endforeach ?>
</div>
<div class="stack">
<?php foreach ($list as $n): ?>
  <div class="notif<?= (int) $n['is_read'] ? '' : ' is-new' ?>">
    <span class="notif-ic"><?= icon(['training' => 'calendar', 'crew' => 'users', 'regatta' => 'trophy', 'other' => 'user'][$cat]) ?></span>
    <div class="notif-b"><p><?= e($n['body']) ?></p><small><?= e(ru_date($n['created_at'])) ?>, <?= hm($n['created_at']) ?></small></div>
    <?php if ($n['url']): ?><a class="btn btn-sm" href="<?= url($n['url']) ?>">Открыть</a><?php endif ?>
  </div>
<?php endforeach ?>
<?php if (!$list): ?><div class="card empty"><?= icon('bell', 'empty-ic') ?><p>В этом разделе уведомлений нет.</p></div><?php endif ?>
</div>
<?php page_end();
