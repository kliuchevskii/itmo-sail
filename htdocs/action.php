<?php
/* Единый обработчик всех действий (POST). После действия возвращает на страницу, откуда пришёл запрос. */
require __DIR__ . '/inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
csrf_check();
$u = require_login();
$uid = (int) $u['id'];
$a = (string) post('a');
if ($a === 'ev_team_start') $a = post('mode') === 'home' ? 'ev_team_home' : 'ev_team_create';

function back(): never { redirect(safe_back((string) post('back'))); }
function fail(string $m): never { flash($m, 'err'); back(); }
function ok(string $m): never { flash($m); back(); }
function go(string $path, string $m = ''): never { if ($m) flash($m); redirect($path); }
/** После создания из расписания — обратно в расписание с теми же настройками, иначе на страницу тренировки */
function created(int $tid, string $m): never
{
    $b = safe_back((string) post('back'), '');
    go(str_starts_with($b, 'index.php') ? $b : "training.php?id=$tid", $m);
}

function tr_load(): array
{
    $t = training_get((int) post('tid'));
    if (!$t) fail('Тренировка не найдена');
    return [$t, training_people((int) $t['id'])];
}
function t_link(array $t): string { return 'training.php?id=' . $t['id']; }
function t_desc(array $t): string { return ru_dow($t['start_at']) . ', ' . ru_date($t['start_at']) . ' в ' . hm($t['start_at']) . ' («' . training_title($t) . '»)'; }
function insurance_hint(array $ev, int $uid): void
{
    $has = val("SELECT id FROM documents WHERE user_id=? AND doc_type='insurance' AND (valid_until IS NULL OR valid_until>=?)", [$uid, $ev['end_date']]);
    if (!$has) flash('Не забудьте загрузить в профиль спортивную страховку, действующую на даты регаты.', 'warn');
}
function busy_fail(int $uid, array $t, string $who = 'У вас'): void
{
    if ($c = user_busy($uid, $t['start_at'], $t['end_at'], (int) $t['id'])) {
        fail("$who уже есть тренировка в это время: " . ru_date($c['start_at']) . ' ' . hm($c['start_at']) . '–' . hm($c['end_at']) . '.');
    }
}
function upsert_person(int $tid, int $uid, string $status, int $viaCrew = 0): void
{
    $row = one('SELECT id FROM training_people WHERE training_id=? AND user_id=?', [$tid, $uid]);
    if ($row) q('UPDATE training_people SET status=?, via_crew=?, attended=NULL WHERE id=?', [$status, $viaCrew, $row['id']]);
    else q('INSERT INTO training_people (training_id,user_id,status,via_crew,created_at) VALUES (?,?,?,?,?)', [$tid, $uid, $status, $viaCrew, now()]);
}
/** Член экипажа не сможет — тренировка экипажа уходит на добор */
function member_out(array $t, array $u, string $verb): void
{
    $tid = (int) $t['id'];
    $name = short_name($u);
    $onto = str_starts_with($verb, 'выпис') ? 'из тренировки' : 'на тренировку';
    $members = $t['crew_id'] ? crew_member_ids((int) $t['crew_id']) : [];
    if ($t['kind'] === 'crew' && in_array((int) $u['id'], $members, true)) {
        $wasCrewOnly = $t['visibility'] === 'crew';
        q("UPDATE trainings SET visibility='public' WHERE id=?", [$tid]);
        training_recompute($tid);
        $t = training_get($tid);
        $people = training_people($tid);
        $msg = "$name $verb $onto экипажа " . t_desc($t) . '. Тренировка открыта для добора участников'
            . ((int) $t['needs_instructor'] ? ' и ищет инструктора' : '') . '. Если нужно — её можно полностью отменить.';
        notify_many(array_merge(training_involved($t, $people), $members), $msg, t_link($t), (int) $u['id']);
        if ($wasCrewOnly) notify_public($t, 'Открыт добор на тренировку ' . t_desc($t) . '. Есть свободные места.', array_merge(training_involved($t, $people), $members, [(int) $u['id']]));
    } else {
        training_recompute($tid);
        notify_many([training_responsible($t)], "$name $verb $onto " . t_desc($t) . '.', t_link($t), (int) $u['id']);
    }
}

switch ($a) {

/* ================= ТРЕНИРОВКИ ================= */

case 'create_training': {
    $opts = create_options($u);
    $type = (string) post('type');
    if (!isset($opts[$type])) fail('Этот тип тренировки вам недоступен.');
    $date = (string) post('date');
    $time = (string) post('time');
    [, $sEnd] = schedule_bounds();
    if (!valid_date($date) || $date >= $sEnd->format('Y-m-d')) fail('Неверная дата.');
    if (($lim = season_latest()) && $time > $lim) fail("Сейчас студенческий сезон: тренировки начинаются не позже $lim.");
    if (!in_array($time, start_times(), true)) fail('Неверное время начала.');
    $start = "$date $time:00";
    if ($start <= now()) fail('Нельзя создать тренировку в прошлом.');
    if ($reg = (no_training_days($date, $date)[$date] ?? null)) fail("В этот день регата «{$reg}» — тренировки не проводятся.");
    $end = training_end($start);
    if ($c = user_busy($uid, $start, $end)) fail('У вас уже есть тренировка в это время (' . hm($c['start_at']) . '–' . hm($c['end_at']) . ').');
    $crew = user_crew($uid);
    $note = mb_substr((string) post('note'), 0, 500);
    $vis = post('open_now') ? 'public' : 'crew';

    if ($type === 'open' && !is_instructor($u)) {
        // Тренировка для всех от спортсмена без допуска: ищет инструктора
        q('INSERT INTO trainings (start_at,end_at,creator_id,kind,visibility,needs_instructor,note,created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$start, $end, $uid, 'open', 'public', 1, $note, now()]);
        $tid = last_id();
        upsert_person($tid, $uid, 'confirmed');
        training_recompute($tid);
        $t = training_get($tid);
        $extra = after_create_invites($t, $u, (array) ($_POST['instr_invite'] ?? []), true);
        notify_public($t, 'Новая тренировка для всех: ' . t_desc($t) . '. Есть свободные места, ищем инструктора.', array_merge([$uid], $GLOBALS['NOTIFIED_AVAIL'] ?? []));
        created($tid, 'Тренировка для всех создана. Она уже видна инструкторам в «Запросах инструкторам».' . $extra);
    }
    if ($type === 'open') {
        q('INSERT INTO trainings (start_at,end_at,creator_id,kind,instructor_id,visibility,note,created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$start, $end, $uid, 'instructor', $uid, 'public', $note, now()]);
        $tid = last_id();
        $t = training_get($tid);
        $extra = after_create_invites($t, $u, [], true);
        notify_public($t, 'Новая тренировка для всех: ' . t_desc($t) . ', инструктор ' . rtrim(short_name($u), '.') . '. Запись открыта.', array_merge([$uid], $GLOBALS['NOTIFIED_AVAIL'] ?? []));
        created($tid, 'Тренировка для всех создана. Вы — инструктор.' . $extra);
    }
    if ($type === 'mycrew') {
        q('INSERT INTO trainings (start_at,end_at,creator_id,kind,crew_id,instructor_id,visibility,note,created_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$start, $end, $uid, 'instructor', $crew['id'], $uid, $vis, $note, now()]);
        $tid = last_id();
        $t = training_get($tid);
        notify_many(crew_member_ids((int) $crew['id']), short_name($u) . ' проводит тренировку для экипажа ' . t_desc($t) . '. Запишитесь, если идёте.', t_link($t), $uid);
        created($tid, 'Тренировка для экипажа создана, экипаж уведомлён.');
    }
    // type === 'crew'
    q('INSERT INTO trainings (start_at,end_at,creator_id,kind,crew_id,visibility,note,created_at) VALUES (?,?,?,?,?,?,?,?)',
        [$start, $end, $uid, 'crew', $crew['id'], $vis, $note, now()]);
    $tid = last_id();
    foreach (crew_member_ids((int) $crew['id']) as $mid) upsert_person($tid, $mid, $mid === $uid ? 'confirmed' : 'invited', 1);
    training_recompute($tid);
    $t = training_get($tid);
    notify_many(crew_member_ids((int) $crew['id']), who($u, 'создал', 'создала') . ' тренировку экипажа ' . t_desc($t) . '. Подтвердите участие.', t_link($t), $uid);
    $extra = after_create_invites($t, $u, (array) ($_POST['instr_invite'] ?? []), false);
    created($tid, 'Тренировка экипажа создана. Ждём подтверждения от всех членов экипажа.'
        . ((int) $t['needs_instructor'] ? ' Она уже видна инструкторам в «Запросах инструкторам».' : '') . $extra);
}

case 'join': {
    [$t, $people] = tr_load();
    $v = training_view($t, $people, $u);
    if (!empty($v['season_block'])) fail('Сейчас студенческий сезон: записаться можно только на тренировки, которые начинаются не позже ' . season_latest() . '.');
    if (!$v['can_join']) fail('Записаться на эту тренировку нельзя.');
    busy_fail($uid, $t);
    upsert_person((int) $t['id'], $uid, 'confirmed');
    training_recompute((int) $t['id']);
    notify_many([training_responsible($t)], who($u, 'записался', 'записалась') . ' на тренировку ' . t_desc($t) . '.', t_link($t), $uid);
    ok('Вы записаны на тренировку.');
}

case 'join_crew': {
    [$t, $people] = tr_load();
    $v = training_view($t, $people, $u);
    if (!empty($v['season_block'])) fail('Сейчас студенческий сезон: записаться можно только на тренировки, которые начинаются не позже ' . season_latest() . '.');
    if (!$v['can_join_crew']) fail('Экипажем записаться нельзя: не хватает мест или запись закрыта.');
    busy_fail($uid, $t);
    $crew = $v['user_crew'];
    $activeIds = array_map(fn($p) => (int) $p['user_id'], active_people($people));
    $new = array_diff(crew_member_ids((int) $crew['id']), $activeIds);
    foreach ($new as $mid) upsert_person((int) $t['id'], $mid, $mid === $uid ? 'confirmed' : 'invited', 1);
    training_recompute((int) $t['id']);
    notify_many($new, who($u, 'записал', 'записала') . ' экипаж «' . $crew['name'] . '» на тренировку ' . t_desc($t) . '. Подтвердите участие.', t_link($t), $uid);
    notify_many([training_responsible($t)], 'Экипаж «' . $crew['name'] . '» записался на тренировку ' . t_desc($t) . '.', t_link($t), $uid);
    ok('Экипаж записан. Каждому участнику отправлено приглашение — ждём подтверждений.');
}

case 'confirm': {
    [$t, $people] = tr_load();
    $v = training_view($t, $people, $u);
    if (!$v['can_confirm']) fail('Нечего подтверждать.');
    busy_fail($uid, $t);
    upsert_person((int) $t['id'], $uid, 'confirmed', (int) (one('SELECT via_crew FROM training_people WHERE training_id=? AND user_id=?', [$t['id'], $uid])['via_crew'] ?? 0));
    training_recompute((int) $t['id']);
    $people = training_people((int) $t['id']);
    $waiting = count(array_filter($people, fn($p) => $p['status'] === 'invited'));
    $msg = who($u, 'подтвердил', 'подтвердила') . ' участие в тренировке ' . t_desc($t) . '.' . ($waiting ? " Ждём ещё: $waiting." : ' Все подтвердили!');
    notify_many(training_involved($t, $people), $msg, t_link($t), $uid);
    ok('Участие подтверждено.');
}

case 'decline':
case 'leave': {
    [$t, $people] = tr_load();
    $v = training_view($t, $people, $u);
    if ($a === 'decline' && !$v['can_confirm']) fail('Нечего отклонять.');
    if ($a === 'leave' && !$v['can_leave']) fail('Выписаться нельзя.');
    q('UPDATE training_people SET status=? WHERE training_id=? AND user_id=?', [$a === 'decline' ? 'declined' : 'left', $t['id'], $uid]);
    member_out($t, $u, $a === 'decline' ? 'не сможет прийти' : gw($u, 'выписался', 'выписалась', 'выписался(ась)'));
    ok($a === 'decline' ? 'Вы отказались от участия.' : 'Вы выписались из тренировки.');
}

case 'cancel': {
    [$t, $people] = tr_load();
    $v = training_view($t, $people, $u);
    if (!$v['can_cancel']) fail('Отменить эту тренировку вы не можете.');
    $reason = mb_substr((string) post('reason'), 0, 300);
    q("UPDATE trainings SET status='cancelled', cancel_reason=? WHERE id=?", [$reason, $t['id']]);
    q("UPDATE instructor_invites SET status='closed' WHERE training_id=? AND status='pending'", [$t['id']]);
    notify_many(training_involved($t, $people), who($u, 'отменил', 'отменила') . ' тренировку ' . t_desc($t) . ($reason ? ". Причина: $reason" : '.'), t_link($t), $uid);
    ok('Тренировка отменена, все записанные уведомлены.');
}

case 'open_public': {
    [$t, $people] = tr_load();
    $v = training_view($t, $people, $u);
    if (!$v['can_open']) fail('Действие недоступно.');
    q("UPDATE trainings SET visibility='public' WHERE id=?", [$t['id']]);
    training_recompute((int) $t['id']);
    notify_many(training_involved($t, $people), 'Тренировка ' . t_desc($t) . ' открыта для добора участников.', t_link($t), $uid);
    notify_public($t, 'Открыт добор на тренировку ' . t_desc($t) . '. Есть свободные места.', training_involved($t, $people));
    ok('Добор открыт: тренировка видна всем для записи.');
}

case 'take': {
    [$t, $people] = tr_load();
    $v = training_view($t, $people, $u);
    if (!$v['can_take']) fail('Стать инструктором на этой тренировке нельзя.');
    busy_fail($uid, $t);
    q('UPDATE trainings SET instructor_id=? WHERE id=? AND instructor_id IS NULL', [$uid, $t['id']]);
    q("UPDATE instructor_invites SET status=CASE WHEN instructor_id=? THEN 'accepted' ELSE 'closed' END WHERE training_id=? AND status='pending'", [$uid, $t['id']]);
    training_recompute((int) $t['id']);
    notify_many(training_involved($t, $people), short_name($u) . ' будет инструктором на тренировке ' . t_desc($t) . '.', t_link($t), $uid);
    ok('Вы инструктор на этой тренировке.');
}

case 'drop': {
    [$t, $people] = tr_load();
    $v = training_view($t, $people, $u);
    if (!$v['can_drop']) fail('Действие недоступно.');
    q('UPDATE trainings SET instructor_id=NULL WHERE id=?', [$t['id']]);
    training_recompute((int) $t['id']);
    notify_many(training_involved($t, $people), short_name($u) . ' больше не может быть инструктором на тренировке ' . t_desc($t) . '. Ищем замену.', t_link($t), $uid);
    ok('Вы отказались от роли инструктора.');
}

case 'invite_instr': {
    [$t, $people] = tr_load();
    $v = training_view($t, $people, $u);
    if (!$v['can_invite_instr']) fail('Сейчас пригласить инструктора нельзя.');
    $iid = (int) post('instructor_id');
    $ins = one('SELECT * FROM users WHERE id=? AND clearance>=1 AND active=1', [$iid]);
    if (!$ins) fail('Выберите инструктора из списка.');
    if (user_busy($iid, $t['start_at'], $t['end_at'], (int) $t['id'])) fail('Этот инструктор занят в это время.');
    if (val("SELECT id FROM instructor_invites WHERE training_id=? AND instructor_id=? AND status='pending'", [$t['id'], $iid])) fail('Приглашение уже отправлено.');
    q('INSERT INTO instructor_invites (training_id,instructor_id,from_user,created_at) VALUES (?,?,?,?)', [$t['id'], $iid, $uid, now()]);
    notify($iid, short_name($u) . ' приглашает вас инструктором на тренировку ' . t_desc($t) . '.', t_link($t));
    ok('Приглашение отправлено: ' . short_name($ins) . '.');
}

case 'instr_reply': {
    $inv = one("SELECT * FROM instructor_invites WHERE id=? AND instructor_id=? AND status='pending'", [(int) post('inv'), $uid]);
    if (!$inv) fail('Приглашение не найдено или уже неактуально.');
    $t = training_get((int) $inv['training_id']);
    $people = training_people((int) $t['id']);
    if ($t['status'] === 'cancelled' || $t['start_at'] <= now()) {
        q("UPDATE instructor_invites SET status='closed' WHERE id=?", [$inv['id']]);
        fail('Тренировка уже неактуальна.');
    }
    if (post('yes') === '1') {
        if ($t['instructor_id']) { q("UPDATE instructor_invites SET status='closed' WHERE id=?", [$inv['id']]); fail('У тренировки уже есть инструктор.'); }
        if (INSTRUCTOR_TAKES_SEAT && seats_free($t, $people) < 1) fail('В лодке нет свободного места.');
        busy_fail($uid, $t);
        q('UPDATE trainings SET instructor_id=? WHERE id=?', [$uid, $t['id']]);
        q("UPDATE instructor_invites SET status=CASE WHEN id=? THEN 'accepted' ELSE 'closed' END WHERE training_id=? AND status='pending'", [$inv['id'], $t['id']]);
        training_recompute((int) $t['id']);
        notify_many(training_involved($t, $people), who($u, 'согласился', 'согласилась', 'согласился(ась)') . ' быть инструктором на тренировке ' . t_desc($t) . '.', t_link($t), $uid);
        ok('Вы инструктор на этой тренировке.');
    }
    q("UPDATE instructor_invites SET status='declined' WHERE id=?", [$inv['id']]);
    notify((int) $inv['from_user'], short_name($u) . ' не сможет быть инструктором на тренировке ' . t_desc($t) . '.', t_link($t));
    ok('Вы отказались.');
}

case 'report': {
    [$t, $people] = tr_load();
    $v = training_view($t, $people, $u);
    if (!$v['can_report']) fail('Подтвердить проведение может только инструктор (или администратор) после окончания тренировки.');
    if (post('held') === '0') {
        q("UPDATE trainings SET status='cancelled', cancel_reason='Не состоялась' WHERE id=?", [$t['id']]);
        ok('Отмечено: тренировка не состоялась.');
    }
    $att = array_map('intval', (array) ($_POST['attended'] ?? []));
    $onBoard = count(array_filter($people, fn($p) => $p['status'] === 'confirmed' && in_array((int) $p['user_id'], $att, true))) + ($t['instructor_id'] ? 1 : 0);
    if ($onBoard < MIN_CREW) fail('Меньше ' . MIN_CREW . ' человек на воду не выходят. Проверьте отметки или нажмите «Не состоялась».');
    foreach ($people as $p) {
        if ($p['status'] !== 'confirmed') continue;
        q('UPDATE training_people SET attended=? WHERE id=?', [in_array((int) $p['user_id'], $att, true) ? 1 : 0, $p['id']]);
    }
    q('UPDATE trainings SET report_done=1, report_by=?, report_at=? WHERE id=?', [$uid, now(), $t['id']]);
    ok('Проведение подтверждено, посещаемость записана.');
}

/* ================= ЭКИПАЖ ================= */

case 'crew_create': {
    if (user_crew($uid)) fail('Вы уже состоите в экипаже.');
    $name = mb_substr((string) post('name'), 0, 60);
    if (mb_strlen($name) < 2) fail('Введите название экипажа.');
    q('INSERT INTO crews (name,captain_id,created_at) VALUES (?,?,?)', [$name, $uid, now()]);
    $cid = last_id();
    q('INSERT INTO crew_members (crew_id,user_id,joined_at) VALUES (?,?,?)', [$cid, $uid, now()]);
    q("UPDATE crew_invites SET status='closed' WHERE to_user=? AND status='pending'", [$uid]);
    ok('Экипаж «' . $name . '» создан. Пригласите в него людей.');
}

case 'crew_invite': {
    $crew = user_crew($uid);
    if (!$crew) fail('Сначала создайте экипаж.');
    $ids = array_unique(array_map('intval', (array) ($_POST['invite'] ?? [])));
    if (!$ids) fail('Выберите, кого пригласить.');
    $members = crew_member_ids((int) $crew['id']);
    $pending = (int) val("SELECT COUNT(*) FROM crew_invites WHERE crew_id=? AND status='pending'", [$crew['id']]);
    $sent = [];
    foreach ($ids as $iid) {
        if (count($members) + $pending + count($sent) >= BOAT_CAPACITY) break;
        $to = one('SELECT * FROM users WHERE id=? AND active=1', [$iid]);
        if (!$to || $iid === $uid || in_array($iid, $members, true)) continue;
        if (val("SELECT id FROM crew_invites WHERE crew_id=? AND to_user=? AND status='pending'", [$crew['id'], $iid])) continue;
        q('INSERT INTO crew_invites (crew_id,from_user,to_user,created_at) VALUES (?,?,?,?)', [$crew['id'], $uid, $iid, now()]);
        notify($iid, short_name($u) . ' приглашает вас в экипаж «' . $crew['name'] . '».', 'profile.php?tab=crew');
        $sent[] = short_name($to);
    }
    if (!$sent) fail('Приглашения не отправлены: в экипаже нет мест или эти люди уже приглашены.');
    ok('Приглашения отправлены: ' . implode(', ', $sent) . '.');
}

case 'crew_invite_reply': {
    $inv = one("SELECT ci.*, c.name FROM crew_invites ci JOIN crews c ON c.id=ci.crew_id WHERE ci.id=? AND ci.to_user=? AND ci.status='pending'", [(int) post('inv'), $uid]);
    if (!$inv) fail('Приглашение не найдено.');
    if (post('yes') === '1') {
        if (user_crew($uid)) fail('Состоять можно только в одном экипаже. Сначала выйдите из текущего.');
        if (count(crew_member_ids((int) $inv['crew_id'])) >= BOAT_CAPACITY) fail('В экипаже уже нет мест.');
        q('INSERT INTO crew_members (crew_id,user_id,joined_at) VALUES (?,?,?)', [$inv['crew_id'], $uid, now()]);
        q("UPDATE crew_invites SET status=CASE WHEN id=? THEN 'accepted' ELSE 'closed' END WHERE to_user=? AND status='pending'", [$inv['id'], $uid]);
        notify_many(crew_member_ids((int) $inv['crew_id']), who($u, 'вступил', 'вступила') . ' в экипаж «' . $inv['name'] . '».', 'profile.php?tab=crew', $uid);
        crew_composition_changed((int) $inv['crew_id']);
        ok('Вы в экипаже «' . $inv['name'] . '».');
    }
    q("UPDATE crew_invites SET status='declined' WHERE id=?", [$inv['id']]);
    notify((int) $inv['from_user'], who($u, 'отклонил', 'отклонила') . ' приглашение в экипаж «' . $inv['name'] . '».', 'profile.php?tab=crew');
    ok('Приглашение отклонено.');
}

case 'crew_invite_cancel': {
    $crew = user_crew($uid);
    if ($crew) q("UPDATE crew_invites SET status='closed' WHERE id=? AND crew_id=? AND status='pending'", [(int) post('inv'), $crew['id']]);
    ok('Приглашение отозвано.');
}

case 'crew_leave':
case 'crew_remove': {
    $crew = user_crew($uid);
    if (!$crew) fail('Вы не состоите в экипаже.');
    $target = $a === 'crew_leave' ? $uid : (int) post('uid');
    if ($a === 'crew_remove' && (int) $crew['captain_id'] !== $uid) fail('Исключать может только капитан экипажа.');
    if (!in_array($target, crew_member_ids((int) $crew['id']), true)) fail('Человек не в экипаже.');
    q('DELETE FROM crew_members WHERE crew_id=? AND user_id=?', [$crew['id'], $target]);
    $rest = crew_members((int) $crew['id']);
    if (!$rest) {
        q('DELETE FROM crews WHERE id=?', [$crew['id']]);
        q("UPDATE crew_invites SET status='closed' WHERE crew_id=? AND status='pending'", [$crew['id']]);
        ok('Экипаж распущен.');
    }
    if ((int) $crew['captain_id'] === $target) q('UPDATE crews SET captain_id=? WHERE id=?', [$rest[0]['id'], $crew['id']]);
    $tu = one('SELECT * FROM users WHERE id=?', [$target]);
    notify_many(array_column($rest, 'id'), short_name($tu) . ' ' . ($a === 'crew_leave' ? gw($tu, 'вышел', 'вышла', 'вышел(шла)') . ' из экипажа' : gw($tu, 'исключён', 'исключена') . ' из экипажа') . ' «' . $crew['name'] . '».', 'profile.php?tab=crew', $uid);
    if ($a === 'crew_remove') notify($target, 'Вас исключили из экипажа «' . $crew['name'] . '».', 'profile.php?tab=crew');
    crew_composition_changed((int) $crew['id']);
    ok($a === 'crew_leave' ? 'Вы вышли из экипажа.' : 'Участник исключён.');
}

case 'avail_save': {
    $crew = user_crew($uid);
    $scope = $crew && post('scope') === 'crew' ? 'crew' : 'all';
    $win = avail_window();
    $picked = array_values(array_intersect($win, (array) ($_POST['days'] ?? [])));
    $hoursIn = (array) ($_POST['hours'] ?? []);
    $before = $scope === 'crew' ? crew_availability((int) $crew['id'])['common'] : [];
    q('DELETE FROM avail_marks WHERE user_id=? AND (day<? OR (scope=? AND day IN (' . in_list($win) . ')))', array_merge([$uid, $win[0], $scope], $win));
    foreach ($picked as $d) {
        $h = array_values(array_intersect(avail_slots(), array_map('intval', (array) ($hoursIn[$d] ?? []))));
        if (count($h) === count(avail_slots())) $h = [];   // всё отмечено = любое время
        q('INSERT INTO avail_marks (user_id, day, scope, hours) VALUES (?,?,?,?)', [$uid, $d, $scope, implode(',', $h)]);
    }
    $after = $scope === 'crew' ? crew_availability((int) $crew['id'])['common'] : [];
    $new = array_keys(array_filter($after, fn($runs, $d) => ($before[$d] ?? null) !== $runs, ARRAY_FILTER_USE_BOTH));
    if ($new) {
        $list = implode(', ', array_map(fn($d) => mb_strtolower(ru_dow($d)) . ' ' . ru_date($d) . ' (' . runs_label($after[$d]) . ')', $new));
        notify_many(crew_member_ids((int) $crew['id']), 'Весь экипаж «' . $crew['name'] . '» может тренироваться: ' . $list . '. Можно создавать тренировку.', 'index.php', $uid);
    }
    $where = $scope === 'crew' ? 'для экипажа' : 'для всех участников';
    ok($picked ? "Удобные дни и время $where сохранены." : "Удобные дни $where очищены.");
}

case 'crew_rename': {
    $crew = user_crew($uid);
    if (!$crew) fail('Вы не состоите в экипаже.');
    $name = mb_substr((string) post('name'), 0, 60);
    if (mb_strlen($name) < 2) fail('Слишком короткое название.');
    q('UPDATE crews SET name=? WHERE id=?', [$name, $crew['id']]);
    ok('Название обновлено.');
}

/* ================= ПРОФИЛЬ ================= */

case 'profile_save': {
    $prog = (string) post('program');
    if (!isset(PROGRAMS[$prog])) fail('Неверный статус.');
    $course = (int) post('course');
    $enroll = null;
    if (isset(PROGRAM_YEARS[$prog])) {
        if ($course < 1 || $course > PROGRAM_YEARS[$prog]) fail('Неверный курс.');
        $enroll = enroll_year_for_course($course);
    }
    $roles = array_values(array_intersect(array_keys(CREW_ROLES), (array) ($_POST['crew_roles'] ?? [])));
    if (post('last_name') === '' || post('first_name') === '') fail('Фамилия и имя обязательны.');
    $gender = isset(GENDERS[post('gender')]) ? (string) post('gender') : (string) ($u['gender'] ?? '');
    q('UPDATE users SET last_name=?, first_name=?, middle_name=?, gender=?, program=?, enroll_year=?, phone=?, telegram=?, crew_roles=? WHERE id=?', [
        mb_substr(post('last_name'), 0, 80), mb_substr(post('first_name'), 0, 80), mb_substr(post('middle_name'), 0, 80), $gender,
        $prog, $enroll, mb_substr(post('phone'), 0, 30), mb_substr(ltrim(post('telegram'), '@'), 0, 64), implode(',', $roles), $uid,
    ]);
    ok('Профиль сохранён.');
}

case 'account_save': {
    if (!password_verify((string) ($_POST['current'] ?? ''), $u['pass_hash'])) fail('Текущий пароль неверный.');
    $login = mb_strtolower(trim((string) post('login')));
    $email = mb_strtolower(trim((string) post('email')));
    if (!preg_match('/^[a-z0-9_.\-]{3,30}$/', $login)) fail('Логин: 3–30 символов, латинские буквы, цифры, точка, дефис, подчёркивание.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Укажите корректную почту.');
    if (val('SELECT id FROM users WHERE LOWER(login)=? AND id<>?', [$login, $uid])) fail('Этот логин уже занят.');
    if (val('SELECT id FROM users WHERE LOWER(email)=? AND id<>?', [$email, $uid])) fail('Эта почта уже используется.');
    q('UPDATE users SET login=?, email=? WHERE id=?', [$login, $email, $uid]);
    ok('Логин и почта сохранены.');
}

case 'password_change': {
    if (!password_verify((string) ($_POST['current'] ?? ''), $u['pass_hash'])) fail('Текущий пароль неверный.');
    $new = (string) ($_POST['new'] ?? '');
    if (mb_strlen($new) < 8) fail('Новый пароль должен быть не короче 8 символов.');
    q('UPDATE users SET pass_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $uid]);
    q('DELETE FROM auth_tokens WHERE user_id=?', [$uid]);
    ok('Пароль изменён. На других устройствах нужно будет войти заново.');
}

case 'doc_upload': {
    $type = (string) post('doc_type');
    if (!isset(DOC_TYPES[$type])) fail('Выберите тип документа.');
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Файл не загрузился. Максимум ' . MAX_UPLOAD_MB . ' МБ.');
    if ($f['size'] > MAX_UPLOAD_MB * 1024 * 1024) fail('Файл больше ' . MAX_UPLOAD_MB . ' МБ.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) fail('Поддерживаются PDF, JPG, PNG, WEBP.');
    $valid = (string) post('valid_until');
    if ($valid !== '' && !valid_date($valid)) fail('Неверная дата окончания.');
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/storage/docs/' . $stored)) fail('Не удалось сохранить файл на сервере.');
    foreach (all('SELECT * FROM documents WHERE user_id=? AND doc_type=?', [$uid, $type]) as $old) {
        @unlink(__DIR__ . '/storage/docs/' . $old['stored_name']);
        q('DELETE FROM documents WHERE id=?', [$old['id']]);
    }
    q('INSERT INTO documents (user_id,doc_type,orig_name,stored_name,mime,size,valid_until,uploaded_at) VALUES (?,?,?,?,?,?,?,?)',
        [$uid, $type, mb_substr(basename((string) $f['name']), 0, 200), $stored, $mime, (int) $f['size'], $valid ?: null, now()]);
    ok(DOC_TYPES[$type] . ' загружена.');
}

case 'doc_delete': {
    $d = one('SELECT * FROM documents WHERE id=?', [(int) post('id')]);
    if (!$d || ((int) $d['user_id'] !== $uid && !is_admin($u))) fail('Документ не найден.');
    @unlink(__DIR__ . '/storage/docs/' . $d['stored_name']);
    q('DELETE FROM documents WHERE id=?', [$d['id']]);
    ok('Документ удалён.');
}

case 'push_prefs': {
    $on = array_intersect(array_keys(PUSH_CATS), (array) ($_POST['cats'] ?? []));
    $quiet = true;
    $off = array_diff(array_keys(PUSH_CATS), $on);
    q('UPDATE users SET push_off=? WHERE id=?', [implode(',', $off), $uid]);
    if (!$on) flash('Пуш-уведомления выключены для всех разделов.');
    back();
}

case 'notif_read_all': {
    q('UPDATE notifications SET is_read=1 WHERE user_id=?', [$uid]);
    ok('Все уведомления прочитаны.');
}

/* ================= РЕГАТЫ ================= */

case 'event_register': {
    $ev = event_get((int) post('eid'));
    if (!$ev || !event_reg_open($ev)) fail('Регистрация закрыта.');
    $role = (string) post('crew_role');
    if ($role !== '' && !isset(CREW_ROLES[$role])) $role = '';
    if (val('SELECT id FROM event_people WHERE event_id=? AND user_id=?', [$ev['id'], $uid])) fail('Вы уже зарегистрированы.');
    ensure_event_person((int) $ev['id'], $uid, $role);
    insurance_hint($ev, $uid);
    ok('Вы зарегистрированы на «' . event_title($ev) . '».');
}

case 'event_unregister': {
    $eid = (int) post('eid');
    if (user_team_confirmed($eid, $uid)) fail('Вы в экипаже на эту регату — сначала выйдите из экипажа.');
    remove_event_person($eid, $uid);
    ok('Регистрация отменена.');
}

/* --- экипажи на регату --- */

case 'ev_team_home': {
    $ev = event_get((int) post('eid'));
    if (!$ev || !event_reg_open($ev)) fail('Регистрация закрыта.');
    $crew = user_crew($uid);
    if (!$crew) fail('Вы не состоите в экипаже.');
    if (user_team((int) $ev['id'], $uid)) fail('Вы уже в экипаже на эту регату.');
    if (val("SELECT id FROM event_teams WHERE event_id=? AND crew_id=?", [$ev['id'], $crew['id']])) fail('Ваш экипаж уже подан на эту регату.');
    $role = isset(CREW_ROLES[post('role')]) ? (string) post('role') : '';
    q("INSERT INTO event_teams (event_id,kind,crew_id,name,captain_id,note,is_open,created_at) VALUES (?,?,?,?,?,?,0,?)",
        [$ev['id'], 'home', $crew['id'], $crew['name'], $uid, mb_substr((string) post('note'), 0, 300), now()]);
    $tid = last_id();
    q("INSERT INTO event_team_members (team_id,user_id,role,status,created_at) VALUES (?,?,?,?,?)", [$tid, $uid, $role, 'confirmed', now()]);
    ensure_event_person((int) $ev['id'], $uid, $role);
    $skipped = 0;
    foreach (crew_member_ids((int) $crew['id']) as $mid) {
        if ($mid === $uid) continue;
        if (user_team((int) $ev['id'], $mid)) { $skipped++; continue; }
        q("INSERT INTO event_team_members (team_id,user_id,role,status,created_at) VALUES (?,?,?,?,?)", [$tid, $mid, '', 'invited', now()]);
        notify($mid, short_name($u) . ' подаёт экипаж «' . $crew['name'] . '» на регату «' . event_title($ev) . '». Подтвердите участие.', 'event.php?id=' . $ev['id']);
    }
    insurance_hint($ev, $uid);
    ok('Экипаж подан на регату. Остальные члены экипажа должны подтвердить участие.' . ($skipped ? " ($skipped уже в другом экипаже на эту регату.)" : ''));
}

case 'ev_team_create':
case 'ev_team_invite': {
    $ev = event_get((int) post('eid'));
    if (!$ev || !event_reg_open($ev)) fail('Регистрация закрыта.');
    $eid = (int) $ev['id'];
    if ($a === 'ev_team_create') {
        if (user_team($eid, $uid)) fail('Вы уже в экипаже на эту регату.');
        $role = isset(CREW_ROLES[post('role')]) ? (string) post('role') : '';
        $name = mb_substr(trim((string) post('name')), 0, 80) ?: 'Экипаж ' . short_name($u);
        q("INSERT INTO event_teams (event_id,kind,name,captain_id,note,is_open,created_at) VALUES (?,?,?,?,?,1,?)",
            [$eid, 'temp', $name, $uid, mb_substr((string) post('note'), 0, 300), now()]);
        $team = team_get(last_id());
        q("INSERT INTO event_team_members (team_id,user_id,role,status,created_at) VALUES (?,?,?,?,?)", [$team['id'], $uid, $role, 'confirmed', now()]);
        ensure_event_person($eid, $uid, $role);
    } else {
        $team = team_get((int) post('team'));
        if (!$team || (int) $team['event_id'] !== $eid || (int) $team['captain_id'] !== $uid) fail('Приглашать может только тот, кто собрал экипаж.');
    }
    $free = team_free(team_members((int) $team['id']));
    $sent = 0;
    foreach (array_unique(array_map('intval', (array) ($_POST['invite'] ?? []))) as $iid) {
        if ($free <= 0) break;
        if ($iid === $uid || !one('SELECT id FROM users WHERE id=? AND active=1', [$iid]) || user_team($eid, $iid)) continue;
        q('DELETE FROM event_team_members WHERE team_id=? AND user_id=?', [$team['id'], $iid]);
        q("INSERT INTO event_team_members (team_id,user_id,role,status,created_at) VALUES (?,?,?,?,?)", [$team['id'], $iid, '', 'invited', now()]);
        notify($iid, short_name($u) . ' зовёт вас в экипаж «' . $team['name'] . '» на регату «' . event_title($ev) . '».', 'event.php?id=' . $eid);
        $free--; $sent++;
    }
    if ($a === 'ev_team_create') insurance_hint($ev, $uid);
    ok(($a === 'ev_team_create' ? 'Экипаж на регату собран. ' : '') . ($sent ? "Приглашений отправлено: $sent. " : '')
        . ($free > 0 ? "Свободных мест: $free — экипаж виден всем в открытом наборе." : 'Все места заняты.'));
}

case 'ev_team_join': {
    $team = team_get((int) post('team'));
    $ev = $team ? event_get((int) $team['event_id']) : null;
    if (!$team || !$ev || !event_reg_open($ev) || $team['kind'] !== 'temp') fail('Набор в этот экипаж закрыт.');
    if (user_team((int) $ev['id'], $uid)) fail('Вы уже в экипаже на эту регату.');
    if (team_free(team_members((int) $team['id'])) <= 0) fail('В экипаже нет свободных мест.');
    $role = isset(CREW_ROLES[post('role')]) ? (string) post('role') : '';
    q('DELETE FROM event_team_members WHERE team_id=? AND user_id=?', [$team['id'], $uid]);
    q("INSERT INTO event_team_members (team_id,user_id,role,status,created_at) VALUES (?,?,?,?,?)", [$team['id'], $uid, $role, 'confirmed', now()]);
    ensure_event_person((int) $ev['id'], $uid, $role);
    notify((int) $team['captain_id'], who($u, 'присоединился', 'присоединилась', 'присоединился(ась)') . ' к экипажу «' . $team['name'] . '»' . ($role ? ' (' . role_label($role) . ')' : '') . '.', 'event.php?id=' . $ev['id']);
    insurance_hint($ev, $uid);
    ok('Вы в экипаже «' . $team['name'] . '».');
}

case 'ev_team_reply': {
    $team = team_get((int) post('team'));
    $ev = $team ? event_get((int) $team['event_id']) : null;
    $m = $team ? one("SELECT * FROM event_team_members WHERE team_id=? AND user_id=? AND status='invited'", [$team['id'], $uid]) : null;
    if (!$m || !$ev) fail('Приглашение не найдено.');
    if (post('yes') === '1') {
        if (!event_reg_open($ev)) fail('Регистрация закрыта.');
        $other = user_team_confirmed((int) $ev['id'], $uid);
        if ($other && (int) $other['id'] !== (int) $team['id']) fail('Вы уже в другом экипаже на эту регату.');
        $role = isset(CREW_ROLES[post('role')]) ? (string) post('role') : '';
        q("UPDATE event_team_members SET status='confirmed', role=? WHERE id=?", [$role, $m['id']]);
        ensure_event_person((int) $ev['id'], $uid, $role);
        notify((int) $team['captain_id'], who($u, 'подтвердил', 'подтвердила') . ' участие в экипаже «' . $team['name'] . '».', 'event.php?id=' . $ev['id']);
        insurance_hint($ev, $uid);
        ok('Участие подтверждено.');
    }
    q("UPDATE event_team_members SET status='declined' WHERE id=?", [$m['id']]);
    notify((int) $team['captain_id'], short_name($u) . ' не сможет участвовать в экипаже «' . $team['name'] . '».'
        . ($team['kind'] === 'temp' ? ' Место открыто для набора.' : ''), 'event.php?id=' . $ev['id']);
    ok('Вы отказались.');
}

case 'ev_team_leave':
case 'ev_team_disband': {
    $team = team_get((int) post('team'));
    if (!$team) fail('Экипаж не найден.');
    $eid = (int) $team['event_id'];
    $ev = event_get($eid);
    $members = team_members((int) $team['id']);
    if ($a === 'ev_team_disband' || (count($members) <= 1)) {
        if ($a === 'ev_team_disband' && (int) $team['captain_id'] !== $uid) fail('Расформировать может только тот, кто собрал экипаж.');
        foreach ($members as $m) {
            if ($m['status'] === 'confirmed') remove_event_person($eid, (int) $m['user_id']);
            if ((int) $m['user_id'] !== $uid) notify((int) $m['user_id'], 'Экипаж «' . $team['name'] . '» на регату «' . event_title($ev) . '» расформирован.', 'event.php?id=' . $eid);
        }
        q('DELETE FROM event_team_members WHERE team_id=?', [$team['id']]);
        q('DELETE FROM event_teams WHERE id=?', [$team['id']]);
        ok('Экипаж расформирован.');
    }
    $me = one("SELECT * FROM event_team_members WHERE team_id=? AND user_id=? AND status='confirmed'", [$team['id'], $uid]);
    if (!$me) fail('Вы не в этом экипаже.');
    q('DELETE FROM event_team_members WHERE id=?', [$me['id']]);
    remove_event_person($eid, $uid);
    if ((int) $team['captain_id'] === $uid) {
        $next = one("SELECT user_id FROM event_team_members WHERE team_id=? AND status='confirmed' ORDER BY id LIMIT 1", [$team['id']]);
        if ($next) q('UPDATE event_teams SET captain_id=? WHERE id=?', [$next['user_id'], $team['id']]);
    }
    if (!val("SELECT 1 FROM event_team_members WHERE team_id=? AND status='confirmed'", [$team['id']])) {
        notify_many(array_column(team_members((int) $team['id']), 'user_id'), 'Экипаж «' . $team['name'] . '» на регату «' . event_title($ev) . '» расформирован.', 'event.php?id=' . $eid, $uid);
        q('DELETE FROM event_team_members WHERE team_id=?', [$team['id']]);
        q('DELETE FROM event_teams WHERE id=?', [$team['id']]);
        ok('Вы вышли, экипаж расформирован.');
    }
    notify_many(array_column(team_members((int) $team['id']), 'user_id'), who($u, 'вышел', 'вышла', 'вышел(шла)') . ' из экипажа «' . $team['name'] . '».'
        . ($team['kind'] === 'temp' ? ' Место открыто для набора.' : ''), 'event.php?id=' . $eid, $uid);
    regatta_cleanup();
    ok('Вы вышли из экипажа.');
}

/* ================= АДМИНИСТРИРОВАНИЕ ================= */

default:
    if (!str_starts_with($a, 'admin_')) fail('Неизвестное действие.');
    if (!is_admin($u)) fail('Нет прав.');
    require __DIR__ . '/admin/actions.php';
    fail('Неизвестное действие.');
}
