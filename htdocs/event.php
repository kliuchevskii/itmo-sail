<?php
/* Страница регаты: регистрация лично, родным экипажем или собранным на регату экипажем */
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$uid = (int) $u['id'];
regatta_cleanup();

$ev = event_get((int) get('id'));
if (!$ev) { flash('Регата не найдена', 'err'); redirect('events.php'); }
$eid = (int) $ev['id'];
$open = event_reg_open($ev);
$past = $ev['end_date'] < date('Y-m-d');

$teams = event_teams($eid);
$myTeam = user_team($eid, $uid);
$myTeamFull = null;
foreach ($teams as $t) if ($myTeam && (int) $t['id'] === (int) $myTeam['id']) $myTeamFull = $t;
$registered = one('SELECT * FROM event_people WHERE event_id=? AND user_id=?', [$eid, $uid]);
$crew = user_crew($uid);
$crewTeam = $crew ? one('SELECT * FROM event_teams WHERE event_id=? AND crew_id=?', [$eid, $crew['id']]) : null;
$people = all('SELECT p.*, u.last_name, u.first_name, u.middle_name, u.login, u.program, u.enroll_year FROM event_people p JOIN users u ON u.id=p.user_id
               WHERE p.event_id=? ORDER BY u.last_name, u.first_name', [$eid]);
$busy = array_map('intval', array_column(all("SELECT m.user_id FROM event_team_members m JOIN event_teams t ON t.id=m.team_id
               WHERE t.event_id=? AND m.status IN ('invited','confirmed')", [$eid]), 'user_id'));
$candidates = array_values(array_filter(all('SELECT id, login, last_name, first_name, middle_name, level FROM users WHERE active=1 ORDER BY last_name, first_name'),
               fn($x) => (int) $x['id'] !== $uid && !in_array((int) $x['id'], $busy, true)));
$hasIns = (bool) val("SELECT id FROM documents WHERE user_id=? AND doc_type='insurance'", [$uid]);
$teamOf = []; // user_id => название экипажа
foreach ($teams as $t) foreach ($t['members'] as $m) if ($m['status'] === 'confirmed') $teamOf[(int) $m['user_id']] = $t['name'];

function role_select(string $name = 'role', string $label = 'Ваша роль'): string
{
    $h = '<label class="field"><span>' . e($label) . '</span><select name="' . e($name) . '"><option value="">Не важно</option>';
    foreach (CREW_ROLES as $k => $v) $h .= '<option value="' . $k . '">' . e($v) . '</option>';
    return $h . '</select></label>';
}
function invite_list(array $cands, int $max): string
{
    if (!$cands) return '<p class="hint">Некого пригласить: все уже в экипажах на эту регату.</p>';
    $h = '<div class="field"><span>Пригласить (до ' . $max . ')</span><input type="search" placeholder="Поиск по фамилии" data-filter-list aria-label="Поиск">'
       . '<div class="pick" data-max="' . $max . '">';
    foreach ($cands as $c) {
        $h .= '<label class="pick-item" data-name="' . e(mb_strtolower(full_name($c) . ' ' . $c['login'])) . '"><input type="checkbox" name="invite[]" value="' . (int) $c['id'] . '">'
            . '<span><b>' . e(full_name($c)) . '</b><small>' . e(LEVELS[(int) $c['level']]) . '</small></span></label>';
    }
    return $h . '</div><small>Если пригласить меньше ' . $max . ', свободные места будут видны всем — в открытом наборе.</small></div>';
}
function team_card(array $t, array $u, bool $open, bool $canJoin, bool $names = true): string
{
    ob_start();
    $confirmed = array_filter($t['members'], fn($m) => $m['status'] === 'confirmed'); ?>
  <div class="team">
    <div class="team-head">
      <div><b><?= e($t['name']) ?></b><small><?= $t['kind'] === 'home' ? 'Экипаж секции' : 'Собран на регату' ?> · <?= count($confirmed) ?> из <?= REGATTA_CREW_MAX ?></small></div>
      <?php if ($t['kind'] === 'temp' && $t['free'] > 0 && $open): ?><span class="badge b-open">Свободно: <?= $t['free'] ?></span><?php endif ?>
    </div>
    <?php if ($t['note']): ?><p class="hint"><?= e($t['note']) ?></p><?php endif ?>
    <?php if (!$names):
        $taken = array_filter(array_map(fn($m) => role_label($m['role']), $confirmed)); ?>
      <p class="meta">Занятые роли: <?= $taken ? e(implode(', ', $taken)) : 'пока не указаны' ?></p>
      <?php if (count($confirmed) < MIN_CREW): ?><p class="hint">Для выхода нужно минимум <?= MIN_CREW ?> человека.</p><?php endif ?>
    <?php else: ?>
    <ul class="people compact">
      <?php foreach ($t['members'] as $m): ?>
        <li><span class="p-name"><b><?= e(short_name($m)) ?></b><small><?= e(implode(' · ', array_filter([role_label($m['role']), (int) $m['user_id'] === (int) $t['captain_id'] ? gw($m, 'собрал', 'собрала') . ' экипаж' : '']))) ?: '&nbsp;' ?></small></span>
          <?php if ($m['status'] === 'invited'): ?><span class="badge b-warn">Ждём ответа</span><?php endif ?></li>
      <?php endforeach ?>
    </ul>
    <?php if (count($confirmed) < MIN_CREW): ?><p class="hint">Нужно минимум <?= MIN_CREW ?> человека — пригласите ещё.</p><?php endif ?>
    <?php endif ?>
    <?php if ($canJoin): ?>
      <?= form_open('ev_team_join', 'class="row-form"') ?>
        <input type="hidden" name="team" value="<?= $t['id'] ?>">
        <div class="grow"><?= role_select('role', 'Ваша роль') ?></div>
        <button class="btn btn-primary" type="submit">Присоединиться</button>
      </form>
    <?php endif ?>
  </div>
    <?php return (string) ob_get_clean();
}

page_start('Регата', 'events', ['back' => 'events.php']);
?>
<div class="cols">
<section class="col-main stack">
  <article class="card ev-hero">
    <div class="ev">
      <div class="ev-date"><b><?= (int) date('j', strtotime($ev['start_date'])) ?></b><span><?= e(RU_MON_G[(int) date('n', strtotime($ev['start_date']))]) ?></span></div>
      <div class="ev-body stack-sm">
        <div class="badges">
          <?php if (!empty($ev['stage'])): ?><span class="badge b-info">Этап <?= (int) $ev['stage'] ?></span><?php endif ?>
          <?php if ($past): ?><span class="badge b-muted">Прошла</span><?php elseif ($open): ?><span class="badge b-ok">Регистрация открыта</span><?php else: ?><span class="badge b-muted">Регистрация закрыта</span><?php endif ?>
        </div>
        <h2 class="ev-title"><?= e($ev['title']) ?></h2>
        <p class="meta"><?= icon('calendar') ?><?= e(event_dates($ev)) ?></p>
        <?php if ($ev['place']): ?><p class="meta"><?= icon('pin') ?><?= e($ev['place']) ?></p><?php endif ?>
      </div>
    </div>
    <?php if ($ev['description']): ?><p class="ev-desc"><?= nl2br(e($ev['description'])) ?></p><?php endif ?>
  </article>

  <?php if ($myTeam && $myTeam['my_status'] === 'invited' && $open): ?>
    <div class="card card-accent stack">
      <h2 class="card-h"><?= icon('users') ?>Вас зовут в экипаж «<?= e($myTeam['name']) ?>»</h2>
      <?= form_open('ev_team_reply', 'class="stack"') ?>
        <input type="hidden" name="team" value="<?= $myTeam['id'] ?>">
        <?= role_select() ?>
        <div class="row-btns">
          <button class="btn btn-primary" type="submit" name="yes" value="1">Участвую</button>
          <button class="btn" type="submit" name="yes" value="0">Не смогу</button>
        </div>
      </form>
    </div>
  <?php endif ?>

  <?php if ($myTeamFull && $myTeam['my_status'] === 'confirmed'):
      $isCap = (int) $myTeamFull['captain_id'] === $uid; ?>
    <div class="card stack">
      <h2 class="card-h"><?= icon('sail') ?>Ваш экипаж на регату</h2>
      <?= team_card($myTeamFull, $u, $open, false) ?>
      <?php if ($open): ?>
      <div class="row-btns">
        <?php if ($isCap && $myTeamFull['free'] > 0): ?><button class="btn btn-primary" type="button" data-open-dialog="dlg-invite"><?= icon('plus') ?><span>Пригласить</span></button><?php endif ?>
        <?= action_button('ev_team_leave', ['team' => $myTeamFull['id']], 'Выйти из экипажа', 'btn', 'Выйти из экипажа на эту регату?') ?>
        <?php if ($isCap) echo action_button('ev_team_disband', ['team' => $myTeamFull['id']], 'Расформировать', 'btn btn-danger', 'Расформировать экипаж? Регистрация всех его участников будет отменена.'); ?>
      </div>
      <?php endif ?>
    </div>
  <?php endif ?>

  <?php
  $openTeams = array_filter($teams, fn($t) => $t['kind'] === 'temp' && $t['free'] > 0 && (!$myTeamFull || (int) $t['id'] !== (int) $myTeamFull['id']));
  if ($openTeams && $open): ?>
    <h2 class="sec-h">Набор в экипаж</h2>
    <?php foreach ($openTeams as $t): ?><div class="card"><?= team_card($t, $u, $open, !$myTeam, false) ?></div><?php endforeach ?>
  <?php endif ?>

</section>

<aside class="col-side stack">
  <?php if ($open && !($myTeam && $myTeam['my_status'] === 'confirmed')): ?>
  <div class="card stack">
    <h2 class="card-h"><?= icon('trophy') ?>Регистрация</h2>
    <?php if ($registered): ?>
      <div class="doc"><span class="doc-n"><b>Вы зарегистрированы лично</b><small><?= e(role_label($registered['crew_role']) ?: 'роль не указана') ?></small></span></div>
      <?= action_button('event_unregister', ['eid' => $eid], 'Отменить регистрацию', 'btn btn-block', 'Отменить регистрацию на регату?') ?>
    <?php else: ?>
      <button class="btn btn-block" type="button" data-open-dialog="dlg-solo"><?= icon('user') ?><span>Участвую лично</span></button>
    <?php endif ?>
    <?php if (!$myTeam): ?>
      <button class="btn btn-primary btn-block" type="button" data-open-dialog="dlg-temp"><?= icon('users') ?><span>Собрать экипаж</span></button>
    <?php endif ?>
  </div>
  <?php endif ?>
  <?php if (!$hasIns && !$past): ?>
    <div class="alert"><span class="alert-ic"><?= icon('file') ?></span><div class="alert-b"><b>Нужна спортивная страховка</b><span>Загрузите её в профиле.</span></div>
      <a class="btn btn-sm" href="<?= url('profile.php?tab=docs') ?>">Загрузить</a></div>
  <?php endif ?>
</aside>
</div>

<?php if ($open && !$registered && !($myTeam && $myTeam['my_status'] === 'confirmed')): ?>
<dialog class="sheet" id="dlg-solo">
  <div class="sheet-head"><div><small><?= e(event_title($ev)) ?></small><h2>Участвую лично</h2></div><button class="icon-btn ghost" type="button" data-close-dialog aria-label="Закрыть"><?= icon('x') ?></button></div>
  <div class="sheet-body">
    <?= form_open('event_register', 'class="stack"') ?>
      <input type="hidden" name="eid" value="<?= $eid ?>">
      <?= role_select('crew_role') ?>
      <button class="btn btn-primary btn-block" type="submit">Зарегистрироваться</button>
    </form>
  </div>
</dialog>
<?php endif ?>
<?php if ($open && !$myTeam):
    $canHome = $crew && !$crewTeam; ?>
<dialog class="sheet" id="dlg-temp">
  <div class="sheet-head"><div><small><?= e(event_title($ev)) ?></small><h2>Собрать экипаж</h2></div><button class="icon-btn ghost" type="button" data-close-dialog aria-label="Закрыть"><?= icon('x') ?></button></div>
  <div class="sheet-body">
    <?= form_open('ev_team_start', 'class="stack team-form"') ?>
      <input type="hidden" name="eid" value="<?= $eid ?>">
      <?php if ($canHome): ?>
      <div class="field"><span>Кем идём</span>
        <div class="types">
          <label class="type"><input type="radio" name="mode" value="home" checked><span class="type-b"><b>Экипаж «<?= e($crew['name']) ?>»</b></span></label>
          <label class="type"><input type="radio" name="mode" value="temp"><span class="type-b"><b>Новый экипаж</b></span></label>
        </div>
        <small class="type-hint type-hint-1">Подаём свой экипаж. Остальным придёт приглашение — каждый подтверждает участие.</small>
        <small class="type-hint type-hint-2">Экипаж только на эту регату. После регаты он удалится сам.</small>
      </div>
      <?php else: ?><input type="hidden" name="mode" value="temp"><?php endif ?>
      <?= role_select() ?>
      <div class="temp-only stack">
        <label class="field"><span>Название экипажа (необязательно)</span><input name="name" maxlength="80" placeholder="Экипаж <?= e(short_name($u)) ?>"></label>
      </div>
      <label class="field"><span>Комментарий (необязательно)</span><input name="note" maxlength="300" placeholder="Например: ищем бакового, класс лодки"></label>
      <div class="temp-only"><?= invite_list($candidates, REGATTA_CREW_MAX - 1) ?></div>
      <button class="btn btn-primary btn-block" type="submit">Собрать экипаж</button>
    </form>
  </div>
</dialog>
<?php endif ?>

<?php if ($open && $myTeamFull && (int) $myTeamFull['captain_id'] === $uid && $myTeamFull['free'] > 0): ?>
<dialog class="sheet" id="dlg-invite">
  <div class="sheet-head"><div><small><?= e($myTeamFull['name']) ?></small><h2>Пригласить в экипаж</h2></div><button class="icon-btn ghost" type="button" data-close-dialog aria-label="Закрыть"><?= icon('x') ?></button></div>
  <div class="sheet-body">
    <?= form_open('ev_team_invite', 'class="stack"') ?>
      <input type="hidden" name="eid" value="<?= $eid ?>"><input type="hidden" name="team" value="<?= $myTeamFull['id'] ?>">
      <?= invite_list($candidates, $myTeamFull['free']) ?>
      <button class="btn btn-primary btn-block" type="submit">Отправить приглашения</button>
    </form>
  </div>
</dialog>
<?php endif ?>
<?php page_end();
