<?php
/*
 * Первичная установка: создаёт таблицы и главного администратора.
 * Работает, только пока в базе нет ни одного пользователя. После установки файл можно удалить.
 */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/schema.php';

$installed = false;
try { $installed = (int) val('SELECT COUNT(*) FROM users') > 0; } catch (Throwable $e) { $installed = false; }

if ($installed) {
    guest_start('Установка');
    echo '<h2>Уже установлено</h2><p class="hint">В базе есть пользователи. Удалите install.php с хостинга.</p><a class="btn btn-primary btn-block" href="' . url('login.php') . '">Ко входу</a>';
    guest_end();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $login = mb_strtolower(post('login'));
    $email = mb_strtolower(post('email'));
    $pass = (string) ($_POST['password'] ?? '');
    if (!preg_match('/^[a-z0-9_.\-]{3,30}$/', $login) || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($pass) < 8) {
        flash('Проверьте логин, почту и пароль (не короче 8 символов).', 'err');
    } else {
        foreach (schema_statements(DB_DRIVER) as $sql) {
            try { db()->exec($sql); } catch (Throwable $e) {
                if (!str_starts_with($sql, 'CREATE INDEX')) throw $e; // индекс уже есть — не страшно
            }
        }
        q('INSERT INTO users (login,email,pass_hash,last_name,first_name,middle_name,program,role,clearance,consent_at,created_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$login, $email, password_hash($pass, PASSWORD_DEFAULT),
            post('last_name'), post('first_name'), post('middle_name'), 'staff', 'superadmin', 0, now(), now()]);
        login_user(last_id(), true);
        flash('Установка завершена. Удалите файл install.php с хостинга.');
        redirect('profile.php');
    }
}

guest_start('Установка');
?>
<h2>Установка платформы</h2>
<p class="hint">Будут созданы таблицы в базе и аккаунт главного администратора.</p>
<form method="post" class="stack">
  <?= csrf_field() ?>
  <div class="grid-2">
    <label class="field"><span>Логин</span><input name="login" required></label>
    <label class="field"><span>Почта</span><input type="email" name="email" required></label>
  </div>
  <div class="grid-3">
    <label class="field"><span>Фамилия</span><input name="last_name" required></label>
    <label class="field"><span>Имя</span><input name="first_name" required></label>
    <label class="field"><span>Отчество</span><input name="middle_name"></label>
  </div>
  <label class="field"><span>Пароль</span><input type="password" name="password" minlength="8" required></label>
  <button class="btn btn-primary btn-block" type="submit">Установить</button>
</form>
<?php guest_end();
