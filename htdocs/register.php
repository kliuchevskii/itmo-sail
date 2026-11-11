<?php
require __DIR__ . '/inc/bootstrap.php';
if (current_user()) redirect('index.php');

$f = ['login' => '', 'email' => '', 'last_name' => '', 'first_name' => '', 'middle_name' => '', 'gender' => '', 'program' => 'bachelor', 'course' => '1'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($f as $k => $_) $f[$k] = post($k);
    $f['login'] = mb_strtolower($f['login']);
    $f['email'] = mb_strtolower($f['email']);
    $pass = (string) ($_POST['password'] ?? '');
    $err = [];
    if (!preg_match('/^[a-z0-9_.\-]{3,30}$/', $f['login'])) $err[] = 'Логин: 3–30 символов, латинские буквы, цифры, точка, дефис, подчёркивание.';
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Укажите корректную почту.';
    if (mb_strlen($pass) < 8) $err[] = 'Пароль должен быть не короче 8 символов.';
    if ($pass !== (string) ($_POST['password2'] ?? '')) $err[] = 'Пароли не совпадают.';
    if ($f['last_name'] === '' || $f['first_name'] === '') $err[] = 'Укажите фамилию и имя.';
    if (!isset(GENDERS[$f['gender']])) $err[] = 'Укажите пол.';
    if (!isset(PROGRAMS[$f['program']])) $err[] = 'Выберите статус обучения.';
    $course = (int) $f['course'];
    if (isset(PROGRAM_YEARS[$f['program']]) && ($course < 1 || $course > PROGRAM_YEARS[$f['program']])) $err[] = 'Неверный курс.';
    if (empty($_POST['consent'])) $err[] = 'Нужно согласие на обработку персональных данных.';
    if (!$err && val('SELECT id FROM users WHERE LOWER(login)=? OR LOWER(email)=?', [$f['login'], $f['email']])) $err[] = 'Такой логин или почта уже зарегистрированы.';

    if ($err) {
        foreach ($err as $m) flash($m, 'err');
    } else {
        q('INSERT INTO users (login,email,pass_hash,last_name,first_name,middle_name,gender,program,enroll_year,consent_at,created_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
            $f['login'], $f['email'], password_hash($pass, PASSWORD_DEFAULT),
            mb_substr($f['last_name'], 0, 80), mb_substr($f['first_name'], 0, 80), mb_substr($f['middle_name'], 0, 80), $f['gender'],
            $f['program'], isset(PROGRAM_YEARS[$f['program']]) ? enroll_year_for_course($course) : null, now(), now(),
        ]);
        login_user(last_id(), true);
        flash('Добро пожаловать! Заполните профиль и загрузите справку-допуск.');
        redirect('profile.php');
    }
}

guest_start('Регистрация');
?>
<h2>Регистрация</h2>
<form method="post" class="stack">
  <?= csrf_field() ?>
  <div class="grid-2">
    <label class="field"><span>Логин для входа</span><input name="login" value="<?= e($f['login']) ?>" pattern="[A-Za-z0-9_.\-]{3,30}" autocomplete="username" required></label>
    <label class="field"><span>Почта</span><input type="email" name="email" value="<?= e($f['email']) ?>" autocomplete="email" required></label>
    <label class="field"><span>Пароль</span><input type="password" name="password" minlength="8" autocomplete="new-password" required></label>
    <label class="field"><span>Пароль ещё раз</span><input type="password" name="password2" minlength="8" autocomplete="new-password" required></label>
  </div>
  <div class="grid-3">
    <label class="field"><span>Фамилия</span><input name="last_name" value="<?= e($f['last_name']) ?>" required></label>
    <label class="field"><span>Имя</span><input name="first_name" value="<?= e($f['first_name']) ?>" required></label>
    <label class="field"><span>Отчество</span><input name="middle_name" value="<?= e($f['middle_name']) ?>"></label>
  </div>
  <?= gender_field($f['gender'], true) ?>
  <div class="grid-2">
    <label class="field"><span>Уровень обучения</span>
      <select name="program" data-program>
        <?php foreach (PROGRAMS as $k => $v): ?><option value="<?= $k ?>"<?= $f['program'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach ?>
      </select></label>
    <label class="field" data-course-field><span>Курс</span>
      <select name="course">
        <?php for ($i = 1; $i <= 6; $i++): ?><option value="<?= $i ?>"<?= (int) $f['course'] === $i ? ' selected' : '' ?>><?= $i ?></option><?php endfor ?>
      </select></label>
  </div>
  <p class="hint">Курс будет автоматически увеличиваться каждый год 1 сентября.</p>
  <label class="check"><input type="checkbox" name="consent" value="1" required><span>Даю согласие на обработку моих персональных данных парусной секцией для записи на тренировки и учёта посещаемости.</span></label>
  <button class="btn btn-primary btn-block" type="submit">Создать аккаунт</button>
</form>
<div class="guest-alt"><a class="btn btn-block" href="<?= url('login.php') ?>">У меня уже есть аккаунт</a></div>
<?php guest_end();
