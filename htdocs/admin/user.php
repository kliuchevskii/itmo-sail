<?php
/* Карточка участника. Открывается во всплывающем окне (?partial=1) из списка участников. */
require __DIR__ . '/common.php';
$x = one('SELECT * FROM users WHERE id=?', [(int) get('id')]);
$partial = get('partial') === '1';
if (!$x) {
    if ($partial) { http_response_code(404); exit('<p class="hint pad">Участник не найден.</p>'); }
    flash('Участник не найден', 'err'); redirect('admin/index.php');
}
if (!$partial) redirect('admin/index.php?u=' . $x['id']);

$id = (int) $x['id'];
$can = is_super($u) || $x['role'] === 'member';
$exp = user_experience($id);
$crew = user_crew($id);
$docs = all('SELECT * FROM documents WHERE user_id=?', [$id]);
$course = user_course($x);
$dis = $can ? '' : ' disabled';
$tab = in_array(get('ut'), ['main', 'sport', 'role', 'docs', 'access'], true) ? get('ut') : 'main';
$tabs = ['main' => 'Основное', 'sport' => 'Подготовка', 'role' => 'Роль', 'docs' => 'Документы', 'access' => 'Доступ'];
function back_to(int $id, string $t): void { $GLOBALS['BACK'] = "admin/index.php?u=$id&ut=$t"; }
?>
<div class="modal-head">
  <span class="avatar"><?= e(initials($x)) ?></span>
  <div class="modal-title"><h2><?= e(full_name($x)) ?></h2><small>@<?= e($x['login']) ?> · <?= e(ROLES[$x['role']]) ?><?= (int) $x['active'] ? '' : ' · заблокирован' ?></small></div>
  <button class="icon-btn ghost" type="button" data-close-dialog aria-label="Закрыть"><?= icon('x') ?></button>
</div>
<div class="mtabs" role="tablist">
  <?php foreach ($tabs as $k => $label): ?><button class="mtab<?= $k === $tab ? ' is-on' : '' ?>" type="button" data-mtab="<?= $k ?>"><?= e($label) ?></button><?php endforeach ?>
</div>
<?php if (!$can): ?><div class="flash flash-warn">Это администратор. Изменять его может только администратор платформы.</div><?php endif ?>

<div class="mpanel" data-panel="main"<?= $tab === 'main' ? '' : ' hidden' ?>>
  <?php back_to($id, 'main'); ?>
  <?= form_open('admin_user_save', 'class="stack"') ?>
    <input type="hidden" name="uid" value="<?= $id ?>"><input type="hidden" name="part" value="main">
    <label class="field"><span>Фамилия</span><input name="last_name" value="<?= e($x['last_name']) ?>" required<?= $dis ?>></label>
    <div class="grid-2">
      <label class="field"><span>Имя</span><input name="first_name" value="<?= e($x['first_name']) ?>" required<?= $dis ?>></label>
      <label class="field"><span>Отчество</span><input name="middle_name" value="<?= e($x['middle_name']) ?>"<?= $dis ?>></label>
    </div>
    <?= gender_field((string) ($x['gender'] ?? ''), false, $dis) ?>
    <label class="field"><span>Почта</span><input type="email" name="email" value="<?= e($x['email']) ?>" required<?= $dis ?>></label>
    <div class="grid-2">
      <label class="field"><span>Уровень обучения</span><select name="program" data-program<?= $dis ?>><?php foreach (PROGRAMS as $k => $v): ?><option value="<?= $k ?>"<?= $x['program'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach ?></select></label>
      <label class="field" data-course-field><span>Курс</span><select name="course"<?= $dis ?>><?php for ($i = 1; $i <= 6; $i++): ?><option<?= $course === $i ? ' selected' : '' ?>><?= $i ?></option><?php endfor ?></select></label>
    </div>
    <?php if ($can): ?><button class="btn btn-primary" type="submit">Сохранить</button><?php endif ?>
  </form>
</div>

<div class="mpanel" data-panel="sport"<?= $tab === 'sport' ? '' : ' hidden' ?>>
  <?php back_to($id, 'sport'); ?>
  <div class="kpis">
    <div class="kpi"><b><?= $exp['trainings'] ?></b><span>тренировок</span></div>
    <div class="kpi"><b><?= $exp['as_instructor'] ?></b><span>инструктором</span></div>
    <div class="kpi"><b><?= $exp['regattas'] ?></b><span>регат</span></div>
  </div>
  <dl class="dl">
    <dt>Экипаж</dt><dd><?= $crew ? e($crew['name']) : '—' ?></dd>
    <dt>Роли в экипаже</dt><dd><?= e(crew_roles_label($x['crew_roles'])) ?></dd>
  </dl>
  <?= form_open('admin_user_save', 'class="stack"') ?>
    <input type="hidden" name="uid" value="<?= $id ?>"><input type="hidden" name="part" value="sport">
    <div class="grid-2">
      <label class="field"><span>Уровень подготовки</span><select name="level"<?= $dis ?>><?php foreach (LEVELS as $k => $v): ?><option value="<?= $k ?>"<?= (int) $x['level'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach ?></select></label>
      <label class="field"><span>Допуск</span><select name="clearance"<?= $dis ?>><?php foreach (CLEARANCES as $k => $v): ?><option value="<?= $k ?>"<?= (int) $x['clearance'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach ?></select></label>
      <label class="field"><span>Тренировок до платформы</span><input type="number" min="0" name="extra_trainings" value="<?= (int) $x['extra_trainings'] ?>"<?= $dis ?>></label>
      <label class="field"><span>Регат до платформы</span><input type="number" min="0" name="extra_regattas" value="<?= (int) $x['extra_regattas'] ?>"<?= $dis ?>></label>
    </div>
    <?php if ($can): ?><button class="btn btn-primary" type="submit">Сохранить</button><?php endif ?>
  </form>
</div>

<div class="mpanel" data-panel="role"<?= $tab === 'role' ? '' : ' hidden' ?>>
  <?php back_to($id, 'role'); ?>
  <?php if (is_super($u)): ?>
    <?= form_open('admin_user_save', 'class="stack"') ?>
      <input type="hidden" name="uid" value="<?= $id ?>"><input type="hidden" name="part" value="role">
      <fieldset class="choice">
        <legend>Роль на платформе</legend>
        <?php foreach (ROLES as $k => $v): ?>
          <label class="choice-item"><input type="radio" name="role" value="<?= $k ?>"<?= $x['role'] === $k ? ' checked' : '' ?>><span><b><?= e($v) ?></b><small><?= e(['member' => 'Записывается на тренировки и регаты.', 'admin' => 'Управляет спортсменами, экипажами, регатами и баллами.', 'superadmin' => 'Всё то же, плюс назначает роли и управляет администраторами.'][$k]) ?></small></span></label>
        <?php endforeach ?>
      </fieldset>
      <button class="btn btn-primary" type="submit">Сохранить</button>
    </form>
  <?php else: ?>
    <dl class="dl"><dt>Роль на платформе</dt><dd><?= e(ROLES[$x['role']]) ?></dd></dl>
    <p class="hint">Назначать роли может только администратор платформы.</p>
  <?php endif ?>
</div>

<div class="mpanel" data-panel="docs"<?= $tab === 'docs' ? '' : ' hidden' ?>>
  <div class="stack">
    <?php foreach (DOC_TYPES as $type => $label):
        $d = null; foreach ($docs as $dd) if ($dd['doc_type'] === $type) $d = $dd;
        $expired = $d && $d['valid_until'] && $d['valid_until'] < date('Y-m-d'); ?>
      <div class="doc<?= $d ? '' : ' is-empty' ?>">
        <span class="doc-n"><b><?= e($label) ?></b><small><?= $d ? ($d['valid_until'] ? 'до ' . e(ru_date($d['valid_until'], true)) : 'без срока') : 'не загружен' ?></small></span>
        <?php if ($d): ?><span class="badge <?= $expired ? 'b-warn' : 'b-ok' ?>"><?= $expired ? 'Истёк' : 'Есть' ?></span><a class="btn btn-sm" href="<?= url('doc.php?id=' . $d['id']) ?>" target="_blank" rel="noopener">Открыть</a><?php endif ?>
      </div>
    <?php endforeach ?>
  </div>
</div>

<div class="mpanel" data-panel="access"<?= $tab === 'access' ? '' : ' hidden' ?>>
  <?php back_to($id, 'access'); ?>
  <dl class="dl">
    <dt>Логин</dt><dd>@<?= e($x['login']) ?></dd>
    <dt>Контакты</dt><dd><?= e($x['phone'] ?: '—') ?><?= $x['telegram'] ? ' · @' . e($x['telegram']) : '' ?></dd>
    <dt>Регистрация</dt><dd><?= e(ru_date($x['created_at'], true)) ?></dd>
  </dl>
  <?php if ($can && $id !== (int) $u['id']): ?>
    <div class="stack">
      <?= action_button('admin_user_save', ['uid' => $id, 'part' => 'active', 'active' => (int) $x['active'] ? 0 : 1], (int) $x['active'] ? 'Заблокировать вход' : 'Разблокировать', 'btn btn-block', (int) $x['active'] ? 'Участник не сможет войти, пока вы его не разблокируете.' : '') ?>
      <?= action_button('admin_user_password', ['uid' => $id], 'Выдать временный пароль', 'btn btn-block', 'Старый пароль перестанет работать. Продолжить?') ?>
      <?= action_button('admin_user_delete', ['uid' => $id], 'Удалить участника', 'btn btn-danger btn-block', 'Удалить участника и все его данные? Это необратимо.') ?>
    </div>
  <?php endif ?>
</div>
<?php $GLOBALS['BACK'] = null;
