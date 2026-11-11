<?php
/* Действия администраторов. Подключается из action.php, переменные $a, $u, $uid уже есть. */

/** Админ управляет только обычными участниками; главный администратор — всеми */
function can_manage(array $admin, array $target): bool
{
    return is_super($admin) || $target['role'] === 'member';
}
function load_target(array $u): array
{
    $t = one('SELECT * FROM users WHERE id=?', [(int) post('uid')]);
    if (!$t) fail('Пользователь не найден.');
    if (!can_manage($u, $t)) fail('Изменять администраторов может только администратор платформы.');
    return $t;
}
function random_password(): string
{
    $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 10; $i++) $p .= $abc[random_int(0, strlen($abc) - 1)];
    return $p;
}

/** Отменить запланированные тренировки в дни регаты */
function cancel_trainings_for_regatta(string $from, string $to, string $title): int
{
    $n = 0;
    foreach (all("SELECT id FROM trainings WHERE status<>'cancelled' AND start_at>=? AND start_at<=? AND start_at>?", [$from . ' 00:00:00', $to . ' 23:59:59', now()]) as $r) {
        $t = training_get((int) $r['id']);
        $people = training_people((int) $t['id']);
        q("UPDATE trainings SET status='cancelled', cancel_reason=? WHERE id=?", ['В этот день регата «' . $title . '»', $t['id']]);
        notify_many(training_involved($t, $people), 'Тренировка ' . ru_date($t['start_at']) . ' в ' . hm($t['start_at']) . ' отменена: в этот день регата «' . $title . '», тренировки не проводятся.', 'training.php?id=' . $t['id']);
        $n++;
    }
    return $n;
}

switch ($a) {

case 'admin_user_create': {
    $login = mb_strtolower((string) post('login'));
    $email = mb_strtolower((string) post('email'));
    if (!preg_match('/^[a-z0-9_.\-]{3,30}$/', $login)) fail('Неверный логин.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Неверная почта.');
    if (val('SELECT id FROM users WHERE LOWER(login)=? OR LOWER(email)=?', [$login, $email])) fail('Логин или почта уже заняты.');
    $prog = isset(PROGRAMS[post('program')]) ? (string) post('program') : 'bachelor';
    $course = max(1, (int) post('course'));
    $pass = random_password();
    q('INSERT INTO users (login,email,pass_hash,last_name,first_name,middle_name,gender,program,enroll_year,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)', [
        $login, $email, password_hash($pass, PASSWORD_DEFAULT), mb_substr(post('last_name'), 0, 80), mb_substr(post('first_name'), 0, 80),
        mb_substr(post('middle_name'), 0, 80), isset(GENDERS[post('gender')]) ? (string) post('gender') : '', $prog, isset(PROGRAM_YEARS[$prog]) ? enroll_year_for_course(min($course, PROGRAM_YEARS[$prog])) : null, now(),
    ]);
    flash("Участник добавлен. Логин: $login, временный пароль: $pass — передайте его человеку, он сменит пароль в профиле.");
    redirect('admin/index.php?u=' . last_id() . '&ut=access');
}

case 'admin_user_save': {
    $t = load_target($u);
    $tid = (int) $t['id'];
    switch ((string) post('part')) {
    case 'main':
        $prog = isset(PROGRAMS[post('program')]) ? (string) post('program') : $t['program'];
        $course = (int) post('course');
        $enroll = isset(PROGRAM_YEARS[$prog]) ? enroll_year_for_course(max(1, min($course, PROGRAM_YEARS[$prog]))) : null;
        $email = mb_strtolower((string) post('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Неверная почта.');
        if (val('SELECT id FROM users WHERE LOWER(email)=? AND id<>?', [$email, $tid])) fail('Эта почта уже занята.');
        if (post('last_name') === '' || post('first_name') === '') fail('Фамилия и имя обязательны.');
        $gender = isset(GENDERS[post('gender')]) ? (string) post('gender') : (string) ($t['gender'] ?? '');
        q('UPDATE users SET last_name=?, first_name=?, middle_name=?, gender=?, email=?, program=?, enroll_year=? WHERE id=?', [
            mb_substr(post('last_name'), 0, 80), mb_substr(post('first_name'), 0, 80), mb_substr(post('middle_name'), 0, 80), $gender, $email, $prog, $enroll, $tid]);
        break;
    case 'sport':
        $level = (int) post('level');
        $clr = (int) post('clearance');
        if (!isset(LEVELS[$level]) || !isset(CLEARANCES[$clr])) fail('Неверный уровень или допуск.');
        q('UPDATE users SET level=?, clearance=?, extra_trainings=?, extra_regattas=? WHERE id=?',
            [$level, $clr, max(0, (int) post('extra_trainings')), max(0, (int) post('extra_regattas')), $tid]);
        if ((int) $t['clearance'] !== $clr) notify($tid, 'Ваш допуск изменён: ' . mb_strtolower(CLEARANCES[$clr]) . '.', 'profile.php');
        if ((int) $t['level'] !== $level) notify($tid, 'Ваш уровень подготовки: ' . LEVELS[$level] . '.', 'profile.php');
        break;
    case 'role':
        if (!is_super($u)) fail('Назначать роли может только администратор платформы.');
        $role = (string) post('role');
        if (!isset(ROLES[$role])) fail('Неверная роль.');
        if ($t['role'] === 'superadmin' && $role !== 'superadmin' && (int) val("SELECT COUNT(*) FROM users WHERE role='superadmin'") <= 1) {
            fail('Нельзя убрать последнего администратора платформы.');
        }
        q('UPDATE users SET role=? WHERE id=?', [$role, $tid]);
        if ($role !== $t['role']) notify($tid, 'Ваша роль на платформе: ' . ROLES[$role] . '.', 'profile.php');
        break;
    case 'active':
        if ($tid === $uid) fail('Нельзя заблокировать самого себя.');
        $active = post('active') === '1' ? 1 : 0;
        q('UPDATE users SET active=? WHERE id=?', [$active, $tid]);
        if (!$active) q('DELETE FROM auth_tokens WHERE user_id=?', [$tid]);
        ok($active ? 'Вход разблокирован.' : 'Вход заблокирован.');
    default:
        fail('Неизвестный раздел.');
    }
    ok('Сохранено.');
}

case 'admin_user_password': {
    $t = load_target($u);
    $pass = random_password();
    q('UPDATE users SET pass_hash=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $t['id']]);
    q('DELETE FROM auth_tokens WHERE user_id=?', [$t['id']]);
    ok("Новый временный пароль для {$t['login']}: $pass");
}

case 'admin_user_delete': {
    $t = load_target($u);
    if ((int) $t['id'] === $uid) fail('Нельзя удалить самого себя.');
    $tid = (int) $t['id'];
    // будущие тренировки, которые он ведёт или создал, — отменяем
    foreach (all("SELECT * FROM trainings WHERE status<>'cancelled' AND start_at>? AND (instructor_id=? OR (creator_id=? AND kind='instructor'))", [now(), $tid, $tid]) as $tr) {
        $tr = training_get((int) $tr['id']);
        $people = training_people((int) $tr['id']);
        if ($tr['kind'] === 'crew') { q('UPDATE trainings SET instructor_id=NULL WHERE id=?', [$tr['id']]); training_recompute((int) $tr['id']); }
        else { q("UPDATE trainings SET status='cancelled', cancel_reason='Инструктор удалён из секции' WHERE id=?", [$tr['id']]);
               notify_many(training_involved($tr, $people), 'Тренировка ' . ru_date($tr['start_at']) . ' ' . hm($tr['start_at']) . ' отменена: инструктор больше не в секции.', 'training.php?id=' . $tr['id'], $tid); }
    }
    q("DELETE FROM training_people WHERE user_id=? AND training_id IN (SELECT id FROM trainings WHERE start_at>?)", [$tid, now()]);
    if ($crew = user_crew($tid)) {
        q('DELETE FROM crew_members WHERE user_id=?', [$tid]);
        $rest = crew_members((int) $crew['id']);
        if (!$rest) q('DELETE FROM crews WHERE id=?', [$crew['id']]);
        else { if ((int) $crew['captain_id'] === $tid) q('UPDATE crews SET captain_id=? WHERE id=?', [$rest[0]['id'], $crew['id']]); crew_composition_changed((int) $crew['id']); }
    }
    foreach (all('SELECT stored_name FROM documents WHERE user_id=?', [$tid]) as $d) @unlink(__DIR__ . '/../storage/docs/' . $d['stored_name']);
    foreach (['documents', 'notifications', 'auth_tokens', 'event_people', 'pe_points', 'push_subscriptions'] as $tbl) q("DELETE FROM $tbl WHERE user_id=?", [$tid]);
    q("DELETE FROM crew_invites WHERE to_user=? OR from_user=?", [$tid, $tid]);
    q("DELETE FROM instructor_invites WHERE instructor_id=?", [$tid]);
    q("DELETE FROM event_team_members WHERE user_id=?", [$tid]);
    q('DELETE FROM users WHERE id=?', [$tid]);
    flash('Участник ' . full_name($t) . ' удалён. Прошедшие тренировки сохранены в отчётах.');
    redirect('admin/index.php');
}

case 'admin_points_save': {
    // Баллы считаются автоматически; здесь только отметка «баллы выставлены»
    $cells = (array) ($_POST['cell'] ?? []);
    $done = (array) ($_POST['done'] ?? []);
    $n = 0;
    foreach ($cells as $pid => $byT) {
        foreach (array_keys((array) $byT) as $tid) {
            $pid = (int) $pid; $tid = (int) $tid;
            $t = one("SELECT * FROM trainings t WHERE t.id=? AND t.report_done=1 AND t.status<>'cancelled'
                      AND (t.instructor_id=? OR EXISTS (SELECT 1 FROM training_people tp WHERE tp.training_id=t.id AND tp.user_id=? AND tp.attended=1))", [$tid, $pid, $pid]);
            if (!$t) continue;
            q('DELETE FROM pe_points WHERE user_id=? AND training_id=?', [$pid, $tid]);
            if (!empty($done[$pid][$tid])) {
                q('INSERT INTO pe_points (user_id,training_id,points,set_by,set_at) VALUES (?,?,?,?,?)', [$pid, $tid, training_points($t), $uid, now()]);
                $n++;
            }
        }
    }
    ok("Отметки сохранены. Выставлено: $n.");
}

case 'admin_crew_clearance': {
    $c = one('SELECT * FROM crews WHERE id=?', [(int) post('cid')]);
    if (!$c) fail('Экипаж не найден.');
    $val = post('value') === '1' ? 1 : 0;
    q('UPDATE crews SET clearance=? WHERE id=?', [$val, $c['id']]);
    notify_many(crew_member_ids((int) $c['id']), $val ? "Экипажу «{$c['name']}» выдан допуск: можно выходить на воду без инструктора, если присутствуют все члены экипажа." : "У экипажа «{$c['name']}» снят допуск экипажа.", 'profile.php?tab=crew');
    // пересчитать будущие тренировки экипажа
    foreach (all("SELECT id FROM trainings WHERE crew_id=? AND kind='crew' AND status<>'cancelled' AND start_at>?", [$c['id'], now()]) as $r) training_recompute((int) $r['id']);
    ok('Допуск экипажа обновлён.');
}

case 'admin_event_save': {
    $id = (int) post('eid');
    $title = mb_substr((string) post('title'), 0, 160);
    $kind = 'regatta';
    $s = (string) post('start_date'); $e = (string) post('end_date') ?: $s;
    if ($title === '' || !valid_date($s) || !valid_date($e) || $e < $s) fail('Проверьте название и даты.');
    $stage = (int) post('stage');
    $stage = $stage >= 1 && $stage <= STAGES_MAX ? $stage : null;
    $noTr = post('no_trainings') ? 1 : 0;
    $f = [$title, $kind, $stage, $s, $e, mb_substr((string) post('place'), 0, 200), (string) post('description'), post('reg_open') ? 1 : 0, $noTr];
    $cancelled = $noTr ? cancel_trainings_for_regatta($s, $e, $title) : 0;
    $note = $cancelled ? " Отменено тренировок в эти дни: $cancelled, участники уведомлены." : '';
    if ($id) {
        q('UPDATE events SET title=?, kind=?, stage=?, start_date=?, end_date=?, place=?, description=?, reg_open=?, no_trainings=? WHERE id=?', [...$f, $id]);
        go('admin/events.php', 'Регата обновлена.' . $note);
    }
    q('INSERT INTO events (title,kind,stage,start_date,end_date,place,description,reg_open,no_trainings,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [...$f, $uid, now()]);
    $eid = last_id();
    if (post('announce')) {
        foreach (all('SELECT id FROM users WHERE active=1') as $r) notify((int) $r['id'], 'Новая регата: «' . $title . ($stage ? ', этап ' . $stage : '') . '», ' . ru_date($s) . '. Открыта регистрация.', 'event.php?id=' . $eid);
    }
    go('admin/events.php', 'Регата создана.' . $note);
}

case 'admin_settings_save': {
    $on = post('season_on') ? '1' : '0';
    $latest = (string) post('season_latest');
    $ok = array_filter(range(DAY_START_HOUR * 60, (DAY_END_HOUR - TRAINING_HOURS) * 60, 30), fn($m) => sprintf('%02d:%02d', intdiv($m, 60), $m % 60) === $latest);
    if (!$ok) $latest = SEASON_DEFAULT_LATEST;
    setting_set('season_on', $on);
    setting_set('season_latest', $latest);
    $later = $on === '1' ? (int) val("SELECT COUNT(*) FROM trainings WHERE status<>'cancelled' AND start_at>? AND " . (DB_DRIVER === 'mysql' ? "TIME(start_at)>?" : "time(start_at)>?"), [now(), $latest . ':00']) : 0;
    ok($on === '1'
        ? "Студенческий сезон включён: тренировки начинаются не позже $latest." . ($later ? " Уже созданных тренировок позже этого времени: $later — они остаются, но новых записей на них не будет." : '')
        : 'Ограничение по времени выключено.');
}

case 'admin_event_delete': {
    $id = (int) post('eid');
    q('DELETE FROM event_people WHERE event_id=?', [$id]);
    q('DELETE FROM event_team_members WHERE team_id IN (SELECT id FROM event_teams WHERE event_id=?)', [$id]);
    q('DELETE FROM event_teams WHERE event_id=?', [$id]);
    q('DELETE FROM events WHERE id=?', [$id]);
    go('admin/events.php', 'Регата удалена.');
}

case 'admin_event_mark': {
    $id = (int) post('eid');
    $ids = array_map('intval', (array) ($_POST['participated'] ?? []));
    foreach (all('SELECT * FROM event_people WHERE event_id=?', [$id]) as $p) {
        q('UPDATE event_people SET status=? WHERE id=?', [in_array((int) $p['user_id'], $ids, true) ? 'participated' : 'registered', $p['id']]);
    }
    ok('Участие в регате отмечено.');
}
}
