<?php
declare(strict_types=1);

function schema_statements(string $driver): array
{
    $my = $driver === 'mysql';
    $ID = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $E  = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $t = [];
    $t[] = "CREATE TABLE IF NOT EXISTS users (
        id $ID,
        login VARCHAR(40) NOT NULL UNIQUE,
        email VARCHAR(190) NOT NULL UNIQUE,
        pass_hash VARCHAR(255) NOT NULL,
        last_name VARCHAR(80) NOT NULL DEFAULT '',
        first_name VARCHAR(80) NOT NULL DEFAULT '',
        middle_name VARCHAR(80) NOT NULL DEFAULT '',
        gender VARCHAR(1) NOT NULL DEFAULT '',
        program VARCHAR(20) NOT NULL DEFAULT 'bachelor',
        enroll_year INT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'member',
        level INT NOT NULL DEFAULT 0,
        clearance INT NOT NULL DEFAULT 0,
        crew_roles VARCHAR(100) NOT NULL DEFAULT '',
        phone VARCHAR(30) NOT NULL DEFAULT '',
        telegram VARCHAR(64) NOT NULL DEFAULT '',
        extra_trainings INT NOT NULL DEFAULT 0,
        extra_regattas INT NOT NULL DEFAULT 0,
        active INT NOT NULL DEFAULT 1,
        push_off VARCHAR(60) NOT NULL DEFAULT '',
        consent_at DATETIME NULL,
        created_at DATETIME NOT NULL
    )$E";
    $t[] = "CREATE TABLE IF NOT EXISTS auth_tokens (
        id $ID,
        user_id INT NOT NULL,
        selector VARCHAR(24) NOT NULL UNIQUE,
        validator_hash VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL
    )$E";
    $t[] = "CREATE TABLE IF NOT EXISTS documents (
        id $ID,
        user_id INT NOT NULL,
        doc_type VARCHAR(20) NOT NULL,
        orig_name VARCHAR(200) NOT NULL,
        stored_name VARCHAR(80) NOT NULL,
        mime VARCHAR(80) NOT NULL,
        size INT NOT NULL,
        valid_until DATE NULL,
        uploaded_at DATETIME NOT NULL
    )$E";
    $t[] = "CREATE TABLE IF NOT EXISTS crews (
        id $ID,
        name VARCHAR(80) NOT NULL,
        captain_id INT NOT NULL,
        clearance INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL
    )$E";
    $t[] = "CREATE TABLE IF NOT EXISTS crew_members (
        id $ID,
        crew_id INT NOT NULL,
        user_id INT NOT NULL UNIQUE,
        joined_at DATETIME NOT NULL
    )$E";
    $t[] = "CREATE TABLE IF NOT EXISTS crew_invites (
        id $ID,
        crew_id INT NOT NULL,
        from_user INT NOT NULL,
        to_user INT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL
    )$E";
    $t[] = "CREATE TABLE IF NOT EXISTS trainings (
        id $ID,
        start_at DATETIME NOT NULL,
        end_at DATETIME NOT NULL,
        creator_id INT NOT NULL,
        kind VARCHAR(16) NOT NULL,
        crew_id INT NULL,
        instructor_id INT NULL,
        visibility VARCHAR(10) NOT NULL DEFAULT 'public',
        needs_instructor INT NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'planned',
        note VARCHAR(500) NOT NULL DEFAULT '',
        cancel_reason VARCHAR(300) NOT NULL DEFAULT '',
        report_done INT NOT NULL DEFAULT 0,
        report_by INT NULL,
        report_at DATETIME NULL,
        created_at DATETIME NOT NULL
    )$E";
    $t[] = "CREATE TABLE IF NOT EXISTS training_people (
        id $ID,
        training_id INT NOT NULL,
        user_id INT NOT NULL,
        status VARCHAR(12) NOT NULL,
        via_crew INT NOT NULL DEFAULT 0,
        attended INT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE (training_id, user_id)
    )$E";
    $t[] = "CREATE TABLE IF NOT EXISTS instructor_invites (
        id $ID,
        training_id INT NOT NULL,
        instructor_id INT NOT NULL,
        from_user INT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL
    )$E";
    $t[] = "CREATE TABLE IF NOT EXISTS notifications (
        id $ID,
        user_id INT NOT NULL,
        body VARCHAR(500) NOT NULL,
        url VARCHAR(200) NOT NULL DEFAULT '',
        is_read INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL
    )$E";
    $t[] = "CREATE TABLE IF NOT EXISTS events (
        id $ID,
        title VARCHAR(160) NOT NULL,
        kind VARCHAR(10) NOT NULL DEFAULT 'itmo',
        series VARCHAR(160) NOT NULL DEFAULT '',
        stage INT NULL,
        no_trainings INT NOT NULL DEFAULT 0,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        place VARCHAR(200) NOT NULL DEFAULT '',
        description TEXT NULL,
        reg_open INT NOT NULL DEFAULT 1,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL
    )$E";
    $t[] = "CREATE TABLE IF NOT EXISTS event_people (
        id $ID,
        event_id INT NOT NULL,
        user_id INT NOT NULL,
        status VARCHAR(14) NOT NULL DEFAULT 'registered',
        crew_role VARCHAR(40) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        UNIQUE (event_id, user_id)
    )$E";
    $t[] = pe_points_sql($driver);
    foreach (regatta_team_sql($driver) as $q) $t[] = $q;
    foreach (push_sql($driver) as $q) $t[] = $q;
    $t[] = "CREATE INDEX idx_tr_start ON trainings (start_at)";
    $t[] = "CREATE INDEX idx_tp_user ON training_people (user_id)";
    $t[] = "CREATE INDEX idx_nt_user ON notifications (user_id, is_read)";
    return $t;
}

/** Баллы за физкультуру: одна запись = человек × тренировка */
function pe_points_sql(string $driver): string
{
    $my = $driver === 'mysql';
    $ID = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $E  = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    return "CREATE TABLE IF NOT EXISTS pe_points (
        id $ID,
        user_id INT NOT NULL,
        training_id INT NOT NULL,
        points INT NOT NULL,
        set_by INT NOT NULL,
        set_at DATETIME NOT NULL,
        UNIQUE (user_id, training_id)
    )$E";
}

/** Экипажи на регату: родной экипаж (home) или временный (temp) */
function regatta_team_sql(string $driver): array
{
    $my = $driver === 'mysql';
    $ID = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $E  = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    return [
        "CREATE TABLE IF NOT EXISTS event_teams (
            id $ID,
            event_id INT NOT NULL,
            kind VARCHAR(8) NOT NULL,
            crew_id INT NULL,
            name VARCHAR(80) NOT NULL,
            captain_id INT NOT NULL,
            note VARCHAR(300) NOT NULL DEFAULT '',
            is_open INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        )$E",
        "CREATE TABLE IF NOT EXISTS event_team_members (
            id $ID,
            team_id INT NOT NULL,
            user_id INT NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE (team_id, user_id)
        )$E",
    ];
}

/** Обновления структуры для уже установленной базы. Повторный запуск безопасен. */
function run_migrations(string $driver): bool
{
    try { db()->query('SELECT 1 FROM users LIMIT 1'); } catch (Throwable $e) { return false; } // ещё не установлено
    $steps = [pe_points_sql($driver)];
    foreach (regatta_team_sql($driver) as $q) $steps[] = $q;
    foreach (push_sql($driver) as $q) $steps[] = $q;
    foreach ($steps as $q) db()->exec($q);
    try { db()->query('SELECT push_off FROM users LIMIT 1'); }
    catch (Throwable $e) { db()->exec("ALTER TABLE users ADD COLUMN push_off VARCHAR(60) NOT NULL DEFAULT ''"); }
    try { db()->query('SELECT no_trainings FROM events LIMIT 1'); }
    catch (Throwable $e) { try { db()->exec('ALTER TABLE events ADD COLUMN no_trainings INT NOT NULL DEFAULT 0'); } catch (Throwable $e2) {} }
    try { db()->query('SELECT stage FROM events LIMIT 1'); }
    catch (Throwable $e) { db()->exec('ALTER TABLE events ADD COLUMN stage INT NULL'); }
    try { db()->query('SELECT gender FROM users LIMIT 1'); }
    catch (Throwable $e) { db()->exec("ALTER TABLE users ADD COLUMN gender VARCHAR(1) NOT NULL DEFAULT ''"); }
    return true;
}

/** Подписки на пуш-уведомления и настройки (ключи VAPID) */
function push_sql(string $driver): array
{
    $my = $driver === 'mysql';
    $ID = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $E  = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    return [
        "CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(40) NOT NULL PRIMARY KEY,
            v TEXT NOT NULL
        )$E",
        "CREATE TABLE IF NOT EXISTS push_subscriptions (
            id $ID,
            user_id INT NOT NULL,
            endpoint_hash CHAR(64) NOT NULL UNIQUE,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(200) NOT NULL,
            auth VARCHAR(100) NOT NULL,
            ua VARCHAR(200) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            last_ok DATETIME NULL
        )$E",
        // удобные дни и часы: scope 'crew' — для своего экипажа, 'all' — для всех участников
        "CREATE TABLE IF NOT EXISTS avail_marks (
            user_id INT NOT NULL,
            day VARCHAR(10) NOT NULL,
            scope VARCHAR(5) NOT NULL DEFAULT 'all',
            hours VARCHAR(80) NOT NULL DEFAULT '',
            PRIMARY KEY (user_id, day, scope)
        )$E",
    ];
}
