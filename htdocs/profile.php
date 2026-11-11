<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$uid = (int) $u['id'];
$tab = get('tab', 'main');
if (!in_array($tab, ['main', 'docs', 'crew', 'pcrew', 'security'], true)) $tab = 'main';

$exp = user_experience($uid);
$crew = user_crew($uid);
$course = user_course($u);

page_start($tab === 'crew' ? 'Экипаж' : 'Профиль', $tab === 'crew' ? 'crew' : 'profile');
?>
<?php if ($tab !== 'crew'): ?>
<div class="profile-head card">
  <span class="avatar avatar-lg"><?= e(initials($u)) ?></span>
  <div class="ph-main">
    <h2><?= e(full_name($u)) ?></h2>
    <p class="meta">@<?= e($u['login']) ?> · <?= e(course_label($u)) ?></p>
    <div class="badges">
      <span class="badge b-info">Уровень: <?= e(LEVELS[(int) $u['level']]) ?></span>
      <span class="badge <?= is_instructor($u) ? 'b-me' : 'b-muted' ?>"><?= (int) $u['clearance'] ? 'Допуск: ' . e(mb_strtolower(CLEARANCES[(int) $u['clearance']])) : 'Без допуска' ?></span>
      <?php if ($crew): ?><span class="badge b-dark">Экипаж «<?= e($crew['name']) ?>»</span><?php endif ?>
    </div>
  </div>
  <div class="stats">
    <div class="stat"><b><?= $exp['trainings'] ?></b><span><?= plural($exp['trainings'], 'тренировка', 'тренировки', 'тренировок') ?></span></div>
    <div class="stat"><b><?= $exp['regattas'] ?></b><span><?= plural($exp['regattas'], 'регата', 'регаты', 'регат') ?></span></div>
  </div>
</div>
<?php endif ?>

<?php if ($tab !== 'crew' && (is_admin($u) || is_instructor($u))): ?>
<div class="quick mob-only">
  <?php if (is_instructor($u)): ?><a class="btn" href="<?= url('instructors.php') ?>"><?= icon('anchor') ?><span>Запросы инструкторам</span></a><?php endif ?>
  <?php if (is_admin($u)): ?><a class="btn" href="<?= url('admin/index.php') ?>"><?= icon('shield') ?><span>Администрирование</span></a><?php endif ?>
</div>
<?php endif ?>
<?php if ($tab !== 'crew'): ?>
<div class="tabs">
  <a class="tab-btn<?= $tab === 'main' ? ' is-on' : '' ?>" href="<?= url('profile.php') ?>">Данные</a>
  <a class="tab-btn<?= $tab === 'docs' ? ' is-on' : '' ?>" href="<?= url('profile.php?tab=docs') ?>">Документы</a>
  <a class="tab-btn<?= $tab === 'pcrew' ? ' is-on' : '' ?>" href="<?= url('profile.php?tab=pcrew') ?>">Экипаж</a>
  <a class="tab-btn<?= $tab === 'security' ? ' is-on' : '' ?>" href="<?= url('profile.php?tab=security') ?>">Вход и пароль</a>
</div>
<?php endif ?>

<?php if ($tab === 'main'): ?>
<div class="card">
  <?= form_open('profile_save', 'class="stack"') ?>
    <div class="grid-3">
      <label class="field"><span>Фамилия</span><input name="last_name" value="<?= e($u['last_name']) ?>" required></label>
      <label class="field"><span>Имя</span><input name="first_name" value="<?= e($u['first_name']) ?>" required></label>
      <label class="field"><span>Отчество</span><input name="middle_name" value="<?= e($u['middle_name']) ?>"></label>
    </div>
    <?= gender_field((string) ($u['gender'] ?? ''), true) ?>
    <div class="grid-3">
      <label class="field"><span>Уровень обучения</span>
        <select name="program" data-program><?php foreach (PROGRAMS as $k => $v): ?><option value="<?= $k ?>"<?= $u['program'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach ?></select></label>
      <label class="field" data-course-field><span>Курс</span>
        <select name="course"><?php for ($i = 1; $i <= 6; $i++): ?><option value="<?= $i ?>"<?= $course === $i ? ' selected' : '' ?>><?= $i ?></option><?php endfor ?></select></label>
      <label class="field"><span>Телефон</span><input name="phone" value="<?= e($u['phone']) ?>" inputmode="tel" placeholder="+7…"></label>
    </div>
    <div class="grid-2">
      <label class="field"><span>Telegram</span><input name="telegram" value="<?= e($u['telegram']) ?>" placeholder="@username"></label>
      <div class="field"><span>Почта</span><input value="<?= e($u['email']) ?>" disabled></div>
    </div>
    <fieldset class="choice">
      <legend>Роль в экипаже — можно выбрать несколько</legend>
      <div class="toggles">
        <?php $my = explode(',', $u['crew_roles']); foreach (CREW_ROLES as $k => $v): ?>
          <label class="toggle"><input type="checkbox" name="crew_roles[]" value="<?= $k ?>"<?= in_array($k, $my, true) ? ' checked' : '' ?>><span><?= e($v) ?></span></label>
        <?php endforeach ?>
      </div>
    </fieldset>
    <div class="grid-2 readonly">
      <div class="field"><span>Уровень подготовки</span><b><?= e(LEVELS[(int) $u['level']]) ?></b><small>устанавливает администратор</small></div>
      <div class="field"><span>Допуск</span><b><?= e(CLEARANCES[(int) $u['clearance']]) ?></b><small>устанавливает администратор</small></div>
    </div>
    <button class="btn btn-primary" type="submit">Сохранить</button>
  </form>
</div>

<?php elseif ($tab === 'docs'):
  $docs = [];
  foreach (all('SELECT * FROM documents WHERE user_id=?', [$uid]) as $d) $docs[$d['doc_type']] = $d; ?>
<div class="grid-2">
  <?php foreach (DOC_TYPES as $type => $label): $d = $docs[$type] ?? null;
      $expired = $d && $d['valid_until'] && $d['valid_until'] < date('Y-m-d'); ?>
  <div class="card stack">
    <h2 class="card-h"><?= icon('file') ?><?= e($label) ?></h2>
    <p class="hint"><?= $type === 'medical' ? 'Медицинская справка о допуске к занятиям парусным спортом.' : 'Нужна для участия в соревнованиях.' ?></p>
    <?php if ($d): ?>
      <div class="doc">
        <span class="doc-n"><b><?= e($d['orig_name']) ?></b><small>загружен <?= e(ru_date($d['uploaded_at'], true)) ?><?= $d['valid_until'] ? ' · до ' . e(ru_date($d['valid_until'], true)) : '' ?></small></span>
        <span class="badge <?= $expired ? 'b-warn' : 'b-ok' ?>"><?= $expired ? 'Истёк' : 'Загружен' ?></span>
      </div>
      <div class="row-btns">
        <a class="btn btn-sm" href="<?= url('doc.php?id=' . $d['id']) ?>"><?= icon('download') ?><span>Открыть</span></a>
        <?= action_button('doc_delete', ['id' => $d['id']], 'Удалить', 'btn btn-sm', 'Удалить документ?') ?>
      </div>
    <?php else: ?>
      <div class="doc is-empty"><span class="doc-n"><b>Не загружен</b></span><span class="badge b-muted">Нет</span></div>
    <?php endif ?>
    <?= form_open('doc_upload', 'class="stack" enctype="multipart/form-data"') ?>
      <input type="hidden" name="doc_type" value="<?= $type ?>">
      <label class="file"><input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*" required><span><?= icon('file') ?>Выбрать файл (PDF или фото, до <?= MAX_UPLOAD_MB ?> МБ)</span></label>
      <label class="field"><span>Действует до (если указано)</span><input type="date" name="valid_until"></label>
      <button class="btn btn-primary" type="submit"><?= $d ? 'Заменить' : 'Загрузить' ?></button>
    </form>
  </div>
  <?php endforeach ?>
</div>

<?php elseif ($tab === 'crew' || $tab === 'pcrew'): ?>
  <?= crew_panel($u) ?>

<?php else: ?>
<div class="grid-2">
  <div class="card stack">
    <h2 class="card-h">Смена пароля</h2>
    <?= form_open('password_change', 'class="stack"') ?>
      <label class="field"><span>Текущий пароль</span><input type="password" name="current" autocomplete="current-password" required></label>
      <label class="field"><span>Новый пароль</span><input type="password" name="new" minlength="8" autocomplete="new-password" required></label>
      <button class="btn btn-primary" type="submit">Сменить пароль</button>
    </form>
  </div>
  <div class="card stack">
    <h2 class="card-h">Логин и почта</h2>
    <?= form_open('account_save', 'class="stack"') ?>
      <label class="field"><span>Логин для входа</span><input name="login" value="<?= e($u['login']) ?>" pattern="[A-Za-z0-9_.\-]{3,30}" autocomplete="username" required></label>
      <label class="field"><span>Почта</span><input type="email" name="email" value="<?= e($u['email']) ?>" autocomplete="email" required></label>
      <label class="field"><span>Текущий пароль — для подтверждения</span><input type="password" name="current" autocomplete="current-password" required></label>
      <button class="btn btn-primary" type="submit">Сохранить</button>
    </form>
    <p class="hint">Роль на платформе: <?= e(ROLES[$u['role']]) ?>.</p>
  </div>
</div>
<?php endif ?>
<?php if ($tab !== 'crew'): ?><form method="post" action="<?= url('logout.php') ?>" class="logout-row"><?= csrf_field() ?><button class="btn btn-danger btn-block" type="submit"><?= icon('logout') ?><span>Выйти из аккаунта</span></button></form><?php endif ?>
<?php page_end();
