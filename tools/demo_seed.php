<?php
/*
 * Демо-данные для локальной проверки (SQLite): php tools/demo_seed.php
 * Все люди и экипажи вымышленные. Логин администратора: admin / demo12345, остальные — тот же пароль.
 */
declare(strict_types=1);
$_SERVER['REQUEST_METHOD'] = 'CLI';
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../htdocs/inc/bootstrap.php';
require_once __DIR__ . '/../htdocs/inc/schema.php';

foreach (schema_statements(DB_DRIVER) as $sql) {
    try { db()->exec($sql); } catch (Throwable $e) { if (!str_starts_with($sql, 'CREATE INDEX')) throw $e; }
}
if ((int) val('SELECT COUNT(*) FROM users') > 0) { echo "База уже заполнена\n"; exit; }

$pass = password_hash('demo12345', PASSWORD_DEFAULT);
$people = [
    // login, фамилия, имя, роль, уровень, допуск, год поступления
    ['admin',    'Смирнов',   'Алексей',  'superadmin', 3, 2, 2021],
    ['orlova',   'Орлова',    'Мария',    'member',     3, 2, 2022],
    ['volkov',   'Волков',    'Иван',     'member',     2, 1, 2022],
    ['lebedeva', 'Лебедева',  'Анна',     'member',     1, 0, 2023],
    ['kozlov',   'Козлов',    'Дмитрий',  'member',     1, 0, 2024],
    ['morozova', 'Морозова',  'Екатерина','member',     0, 0, 2024],
    ['sokolov',  'Соколов',   'Павел',    'admin',      2, 0, 2023],
    ['novikova', 'Новикова',  'Ольга',    'member',     0, 0, 2025],
];
$ids = [];
foreach ($people as [$login, $ln, $fn, $role, $lvl, $cl, $year]) {
    q('INSERT INTO users (login,email,pass_hash,last_name,first_name,program,enroll_year,role,level,clearance,consent_at,created_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$login, "$login@example.com", $pass, $ln, $fn, 'bachelor', $year, $role, $lvl, $cl, now(), now()]);
    $ids[$login] = last_id();
}

q('INSERT INTO crews (name,captain_id,clearance,created_at) VALUES (?,?,?,?)', ['Бриз', $ids['volkov'], 1, now()]);
$crew = last_id();
foreach (['volkov', 'lebedeva', 'kozlov'] as $l) q('INSERT INTO crew_members (crew_id,user_id,joined_at) VALUES (?,?,?)', [$crew, $ids[$l], now()]);

$slots = [[1, 10, 'instructor', 'orlova', ['lebedeva', 'kozlov', 'morozova']],
          [1, 16, 'open', 'orlova', ['novikova']],
          [2, 12, 'crew', null, ['volkov', 'lebedeva', 'kozlov']],
          [3, 18, 'instructor', 'volkov', ['morozova', 'novikova']],
          [5, 10, 'instructor', 'orlova', ['admin', 'sokolov']],
          [-2, 14, 'instructor', 'orlova', ['lebedeva', 'kozlov', 'morozova']]];
foreach ($slots as [$day, $hour, $kind, $instr, $who]) {
    $start = date('Y-m-d H:i:s', strtotime("today +$day day $hour:00"));
    $end   = date('Y-m-d H:i:s', strtotime("today +$day day " . ($hour + TRAINING_HOURS) . ':00'));
    $past  = $day < 0;
    q('INSERT INTO trainings (start_at,end_at,creator_id,kind,crew_id,instructor_id,visibility,needs_instructor,status,report_done,created_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)',
      [$start, $end, $ids[$instr ?? 'volkov'], $kind, $kind === 'crew' ? $crew : null, $instr ? $ids[$instr] : null,
       $kind === 'crew' ? 'crew' : 'public', $kind === 'crew' ? 1 : 0, $past ? 'done' : 'planned', $past ? 1 : 0, now()]);
    $t = last_id();
    foreach ($who as $l) q('INSERT INTO training_people (training_id,user_id,status,via_crew,attended,created_at) VALUES (?,?,?,?,?,?)',
        [$t, $ids[$l], 'confirmed', $kind === 'crew' ? 1 : 0, $past ? 1 : null, now()]);
}

q('INSERT INTO events (title,kind,series,start_date,end_date,place,description,reg_open,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
  ['Осенний кубок секции', 'itmo', '', date('Y-m-d', strtotime('+12 day')), date('Y-m-d', strtotime('+13 day')),
   'Яхт-клуб, Петровская коса', 'Внутренняя регата для всех экипажей секции.', 1, $ids['admin'], now()]);

echo "Готово: " . count($ids) . " пользователей, " . count($slots) . " тренировок. Вход: admin / demo12345\n";
