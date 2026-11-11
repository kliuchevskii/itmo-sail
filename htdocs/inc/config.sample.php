<?php
// Скопируйте этот файл в config.php и заполните данные из панели InfinityFree
// (раздел MySQL Databases: MySQL Hostname, MySQL Username, MySQL Password, имя базы).

define('DB_DRIVER', 'mysql');                       // 'mysql' на хостинге, 'sqlite' — только для локальной проверки
define('DB_HOST',   'sql000.infinityfree.com');     // MySQL Hostname
define('DB_NAME',   'if0_00000000_sail');           // имя базы
define('DB_USER',   'if0_00000000');                // MySQL Username
define('DB_PASS',   'пароль_от_аккаунта');           // MySQL Password
define('DB_SQLITE_PATH', __DIR__ . '/../storage/local.sqlite');

define('APP_NAME', 'ITMO Sail');
define('APP_TZ',   'Europe/Moscow');
define('BASE_URL', '');            // '' если сайт в корне домена, иначе например '/sail'

define('BOAT_CAPACITY', 5);        // максимум людей в лодке (включая инструктора)
define('MIN_CREW', 3);             // меньше трёх человек на воду не выходят
define('INSTRUCTOR_TAKES_SEAT', true);
define('TRAINING_HOURS', 2);       // длительность любой тренировки
define('POINTS_PER_HOUR', 5);      // баллы за физкультуру за каждый час тренировки
define('SCHEDULE_WEEKS', 5);       // сколько недель показывать
define('DAY_START_HOUR', 8);       // самое раннее начало тренировки
define('DAY_END_HOUR', 22);        // тренировка должна закончиться не позже
define('REMEMBER_DAYS', 60);       // «запомнить меня»
define('MAX_UPLOAD_MB', 5);
