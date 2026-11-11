<?php
require __DIR__ . '/inc/bootstrap.php';
if (current_user()) redirect('index.php');

$login = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $login = mb_strtolower(post('login'));
    $pass = (string) ($_POST['password'] ?? '');
    $u = one('SELECT * FROM users WHERE (LOWER(login)=? OR LOWER(email)=?) AND active=1', [$login, $login]);
    if ($u && password_verify($pass, $u['pass_hash'])) {
        if (password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT)) {
            q('UPDATE users SET pass_hash=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
        }
        login_user((int) $u['id'], !empty($_POST['remember']));
        redirect('index.php');
    }
    usleep(600000);
    flash('Неверный логин или пароль', 'err');
}

guest_start('Вход');
?>
<h2>Вход</h2>
<form method="post" class="stack">
  <?= csrf_field() ?>
  <label class="field"><span>Логин или почта</span><input name="login" value="<?= e($login) ?>" autocomplete="username" required autofocus></label>
  <label class="field"><span>Пароль</span><input type="password" name="password" autocomplete="current-password" required></label>
  <label class="check"><input type="checkbox" name="remember" value="1" checked><span>Запомнить меня на этом устройстве</span></label>
  <button class="btn btn-primary btn-block" type="submit">Войти</button>
</form>
<div class="guest-alt">
  <span>Ещё нет аккаунта?</span>
  <a class="btn btn-block" href="<?= url('register.php') ?>">Зарегистрироваться</a>
  <p class="hint">Забыли пароль — напишите администратору секции, он выдаст временный.</p>
</div>
<?php guest_end();
