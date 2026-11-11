<?php
declare(strict_types=1);

/*
 * Типы тренировок (trainings.kind):
 *  instructor — тренировку ведёт участник с личным допуском (instructor_id = он).
 *               visibility=public: открытая, записываются все желающие;
 *               visibility=crew:   только для его родного экипажа (можно открыть для добора).
 *  crew       — тренировку создаёт родной экипаж. Все члены экипажа получают приглашение и
 *               должны подтвердить. Без инструктора можно выйти, только если у экипажа есть
 *               допуск и на борту только свои; иначе нужен инструктор (needs_instructor=1).
 */

const ACTIVE_STATUSES = ['confirmed', 'invited'];

/* ---------- уведомления ---------- */

function notify(int $uid, string $body, string $url = ''): void
{
    q('INSERT INTO notifications (user_id, body, url, created_at) VALUES (?,?,?,?)', [$uid, mb_substr($body, 0, 500), $url, now()]);
    push_queue($uid, $body, $url);
}
/** Тренировка появилась в открытом доступе — сообщаем всем, кто в ней не участвует */
function notify_public(array $t, string $body, array $exclude = []): void
{
    if ($t['start_at'] <= now()) return;
    $ex = array_map('intval', $exclude);
    foreach (all('SELECT id FROM users WHERE active=1') as $r) {
        if (!in_array((int) $r['id'], $ex, true)) notify((int) $r['id'], $body, 'training.php?id=' . $t['id']);
    }
}
function notify_many(array $uids, string $body, string $url = '', int $except = 0): void
{
    foreach (array_unique(array_map('intval', $uids)) as $id) if ($id && $id !== $except) notify($id, $body, $url);
}

/* ---------- экипажи ---------- */

function user_crew(int $uid): ?array
{
    return one('SELECT c.* FROM crews c JOIN crew_members m ON m.crew_id=c.id WHERE m.user_id=?', [$uid]);
}
function crew_member_ids(int $cid): array
{
    return array_map('intval', array_column(all('SELECT user_id FROM crew_members WHERE crew_id=?', [$cid]), 'user_id'));
}
function crew_members(int $cid): array
{
    return all('SELECT u.*, m.joined_at FROM crew_members m JOIN users u ON u.id=m.user_id WHERE m.crew_id=? ORDER BY m.joined_at, m.id', [$cid]);
}
/** Состав изменился — допуск экипажа нужно подтвердить заново */
function crew_composition_changed(int $cid): void
{
    $c = one('SELECT * FROM crews WHERE id=?', [$cid]);
    if ($c && (int) $c['clearance'] === 1) {
        q('UPDATE crews SET clearance=0 WHERE id=?', [$cid]);
        notify_many(crew_member_ids($cid), "Состав экипажа «{$c['name']}» изменился — допуск экипажа снят до повторного подтверждения администратором.", 'profile.php?tab=crew');
    }
}

/* ---------- тренировки ---------- */

function training_get(int $id): ?array
{
    return one('SELECT t.*, c.name AS crew_name, c.clearance AS crew_clearance FROM trainings t LEFT JOIN crews c ON c.id=t.crew_id WHERE t.id=?', [$id]);
}

function training_people(int $tid): array
{
    return all('SELECT tp.*, u.login, u.last_name, u.first_name, u.middle_name, u.gender, u.program, u.enroll_year,
                       u.clearance, u.crew_roles, u.level
                FROM training_people tp JOIN users u ON u.id=tp.user_id
                WHERE tp.training_id=? ORDER BY tp.created_at, tp.id', [$tid]);
}

function active_people(array $people): array
{
    return array_values(array_filter($people, fn($p) => in_array($p['status'], ACTIVE_STATUSES, true)));
}

function seats_used(array $t, array $people): int
{
    return count(active_people($people)) + (($t['instructor_id'] && INSTRUCTOR_TAKES_SEAT) ? 1 : 0);
}
function seats_free(array $t, array $people): int { return max(0, BOAT_CAPACITY - seats_used($t, $people)); }

function training_end(string $start): string { return date('Y-m-d H:i:s', strtotime($start) + TRAINING_HOURS * 3600); }

/** Пересекается ли с другой тренировкой пользователя */
function user_busy(int $uid, string $start, string $end, int $exceptTid = 0): ?array
{
    return one("SELECT t.* FROM trainings t
                WHERE t.status<>'cancelled' AND t.id<>? AND t.start_at<? AND t.end_at>?
                  AND (t.instructor_id=? OR EXISTS (SELECT 1 FROM training_people tp
                       WHERE tp.training_id=t.id AND tp.user_id=? AND tp.status IN ('confirmed','invited')))
                LIMIT 1", [$exceptTid, $end, $start, $uid, $uid]);
}

/** Нужен ли инструктор тренировке экипажа */
function training_recompute(int $tid): void
{
    $t = training_get($tid);
    if (!$t || !in_array($t['kind'], ['crew', 'open'], true) || $t['status'] === 'cancelled') return;
    $people  = training_people($tid);
    $active  = active_people($people);
    $members = $t['crew_id'] ? crew_member_ids((int) $t['crew_id']) : [];

    $need = 1;
    if ($t['instructor_id']) {
        $need = 0;
    } else {
        foreach ($active as $p) if ($p['status'] === 'confirmed' && (int) $p['clearance'] >= 1) $need = 0;
        if ($need && (int) $t['crew_clearance'] === 1 && $members) {
            $activeIds = array_map(fn($p) => (int) $p['user_id'], $active);
            $allMembersIn = !array_diff($members, $activeIds);
            $onlyMembers  = !array_diff($activeIds, $members);
            if ($allMembersIn && $onlyMembers) $need = 0;
        }
    }
    if ((int) $t['needs_instructor'] !== $need) {
        q('UPDATE trainings SET needs_instructor=? WHERE id=?', [$need, $tid]);
    }
    // Все ответили, но в лодке меньше минимума — открываем добор
    $pending = count(array_filter($people, fn($p) => $p['status'] === 'invited'));
    if (!$pending && $t['visibility'] === 'crew' && seats_used($t, $people) < MIN_CREW) {
        q("UPDATE trainings SET visibility='public' WHERE id=?", [$tid]);
    }
}

function training_title(array $t): string
{
    $crew = $t['crew_name'] ?? '';
    if ($t['kind'] === 'crew') return 'Экипаж «' . $crew . '»';
    return $t['visibility'] === 'crew' ? 'Экипаж «' . $crew . '» с инструктором' : 'Открытая тренировка';
}

function training_responsible(array $t): int { return (int) ($t['instructor_id'] ?: $t['creator_id']); }

/** Все, кого нужно уведомлять о тренировке */
function training_involved(array $t, array $people): array
{
    $ids = array_map(fn($p) => (int) $p['user_id'], active_people($people));
    if ($t['instructor_id']) $ids[] = (int) $t['instructor_id'];
    $ids[] = (int) $t['creator_id'];
    return array_values(array_unique($ids));
}

/** Права и состояние тренировки для конкретного пользователя */
function training_view(array $t, array $people, array $u): array
{
    $uid = (int) $u['id'];
    $now = now();
    $started = $t['start_at'] <= $now;
    $ended   = $t['end_at'] <= $now;
    $cancel  = $t['status'] === 'cancelled';
    $open    = !$cancel && !$started;

    $mine = null;
    foreach ($people as $p) if ((int) $p['user_id'] === $uid) $mine = $p;
    $my = $mine['status'] ?? null;
    $myActive = in_array($my, ACTIVE_STATUSES, true);

    $crewIds  = $t['crew_id'] ? crew_member_ids((int) $t['crew_id']) : [];
    $inCrew   = in_array($uid, $crewIds, true);
    $isInstr  = (int) $t['instructor_id'] === $uid;
    $isCreator = (int) $t['creator_id'] === $uid;
    $admin    = is_admin($u);
    $free     = seats_free($t, $people);
    $public   = $t['visibility'] === 'public';

    $userCrew = user_crew($uid);
    $crewJoinable = 0;
    if ($userCrew && $public && (int) $userCrew['id'] !== (int) $t['crew_id']) {
        $activeIds = array_map(fn($p) => (int) $p['user_id'], active_people($people));
        $crewJoinable = count(array_diff(crew_member_ids((int) $userCrew['id']), $activeIds));
    }

    $invitedCnt = count(array_filter($people, fn($p) => $p['status'] === 'invited'));
    $confirmedCnt = count(array_filter($people, fn($p) => $p['status'] === 'confirmed'));

    $v = [
        'my_status' => $my, 'is_instructor' => $isInstr, 'in_crew' => $inCrew, 'free' => $free,
        'started' => $started, 'ended' => $ended, 'cancelled' => $cancel,
        'can_join'      => $open && !$isInstr && !$myActive && $free >= 1 && ($public || $inCrew) && season_allows($t['start_at']),
        'can_join_crew' => $open && !$isInstr && !$myActive && $crewJoinable >= 1 && $crewJoinable <= $free && season_allows($t['start_at']),
        'season_block'  => $open && !$isInstr && !$myActive && !season_allows($t['start_at']),
        'user_crew'     => $userCrew,
        'can_confirm'   => $open && $my === 'invited',
        'can_leave'     => $open && $my === 'confirmed',
        'can_cancel'    => $open && ($admin || $isCreator || ($t['kind'] === 'instructor' && $isInstr) || ($t['kind'] === 'crew' && $inCrew)),
        'is_self_led'   => in_array($t['kind'], ['crew', 'open'], true),
        'can_open'      => $open && !$public && ($isCreator || $inCrew || $isInstr),
        'can_take'      => $open && in_array($t['kind'], ['crew', 'open'], true) && !$t['instructor_id'] && (int) $t['needs_instructor'] === 1
                           && is_instructor($u) && !$myActive && $free >= (INSTRUCTOR_TAKES_SEAT ? 1 : 0),
        'can_drop'      => $open && $isInstr && in_array($t['kind'], ['crew', 'open'], true),
        'can_invite_instr' => $open && in_array($t['kind'], ['crew', 'open'], true) && !$t['instructor_id'] && (int) $t['needs_instructor'] === 1 && ($inCrew || $isCreator || $my === 'confirmed'),
        'can_report'    => $ended && !$cancel && !(int) $t['report_done'] && ($uid === training_responsible($t) || $admin),
        'badges'        => [],
    ];

    $b = &$v['badges'];
    if ($cancel) $b[] = ['muted', 'Отменена'];
    elseif ((int) $t['report_done']) $b[] = ['ok', 'Проведена'];
    elseif ($ended) $b[] = ['warn', 'Ждёт подтверждения проведения'];
    elseif ($started) $b[] = ['info', 'Идёт сейчас'];
    if (!$cancel && !$started) {
        if ($t['kind'] === 'crew' && $invitedCnt) $b[] = ['info', "Подтвердили $confirmedCnt из " . ($confirmedCnt + $invitedCnt)];
        if ((int) $t['needs_instructor']) $b[] = ['warn', 'Нужен инструктор'];
        if (seats_used($t, $people) < MIN_CREW) $b[] = ['warn', 'Нужно ещё ' . (MIN_CREW - seats_used($t, $people)) . ' — минимум ' . MIN_CREW . ' в лодке'];
        if (!$public) $b[] = ['dark', 'Только экипаж'];
        elseif ($free > 0) $b[] = ['open', 'Свободно мест: ' . $free];
        else $b[] = ['muted', 'Мест нет'];
    }
    if ($isInstr) $b[] = ['me', 'Вы инструктор'];
    elseif ($my === 'confirmed') $b[] = ['me', 'Вы записаны'];
    elseif ($my === 'invited' && !$cancel) $b[] = ['warn', 'Ждёт вашего ответа'];
    return $v;
}

/** Тренировки в диапазоне — компактно, для расписания */
/**
 * Видимость тренировки.
 * Открытые (visibility=public) видят все. Тренировки «только для экипажа», пока не открыт добор, видят:
 *  - члены этого экипажа и все, кто в ней участвует, ведёт её или создал;
 *  - если экипажу нужен инструктор (нет допуска экипажа) — ещё и все инструкторы.
 */
function can_see_training(array $t, array $u, ?int $userCrewId, bool $involved): bool
{
    if ($t['visibility'] === 'public' || $involved) return true;
    if ($t['crew_id'] && $userCrewId === (int) $t['crew_id']) return true;
    return $t['kind'] === 'crew' && (int) $t['needs_instructor'] === 1 && !$t['instructor_id'] && is_instructor($u);
}
function user_involved(array $t, int $uid): bool
{
    if ((int) $t['instructor_id'] === $uid || (int) $t['creator_id'] === $uid) return true;
    return (bool) val("SELECT 1 FROM training_people WHERE training_id=? AND user_id=? AND status IN ('confirmed','invited','declined','left')", [$t['id'], $uid]);
}
function can_see(array $t, array $u): bool
{
    $crew = user_crew((int) $u['id']);
    return can_see_training($t, $u, $crew ? (int) $crew['id'] : null, user_involved($t, (int) $u['id']));
}

function trainings_range(string $from, string $to, array $u): array
{
    $uid = (int) $u['id'];
    $myCrew = user_crew($uid);
    $myCrewId = $myCrew ? (int) $myCrew['id'] : null;
    $rows = all("SELECT t.*, c.name AS crew_name, c.clearance AS crew_clearance,
            (SELECT COUNT(*) FROM training_people tp WHERE tp.training_id=t.id AND tp.status IN ('confirmed','invited')) AS cnt,
            (SELECT tp2.status FROM training_people tp2 WHERE tp2.training_id=t.id AND tp2.user_id=?) AS my_status,
            iu.last_name AS i_last, iu.first_name AS i_first, iu.middle_name AS i_middle, iu.login AS i_login
        FROM trainings t
        LEFT JOIN crews c ON c.id=t.crew_id
        LEFT JOIN users iu ON iu.id=t.instructor_id
        WHERE t.start_at>=? AND t.start_at<? ORDER BY t.start_at, t.id", [$uid, $from, $to]);
    $out = [];
    foreach ($rows as $r) {
        $involved = $r['my_status'] || (int) $r['instructor_id'] === $uid || (int) $r['creator_id'] === $uid;
        if ($r['status'] === 'cancelled') continue; // отменённые исчезают из расписания у всех
        if (!can_see_training($r, $u, $myCrewId, $involved)) continue;
        $r['seats_used'] = (int) $r['cnt'] + (($r['instructor_id'] && INSTRUCTOR_TAKES_SEAT) ? 1 : 0);
        $r['state'] = chip_state($r, $uid);
        $out[substr($r['start_at'], 0, 10)][] = $r;
    }
    return $out;
}

/** Цветовое состояние «плашки» тренировки */
function chip_state(array $r, int $uid): string
{
    if ($r['status'] === 'cancelled') return 'cancel';
    if ($r['end_at'] <= now()) return 'past';
    if (in_array($r['my_status'], ACTIVE_STATUSES, true) || (int) $r['instructor_id'] === $uid) return 'mine';
    if ((int) $r['needs_instructor']) return 'need';
    if ($r['visibility'] === 'crew') return 'crew';
    if ($r['seats_used'] >= BOAT_CAPACITY) return 'full';
    return 'open';
}
function chip_label(array $r): string
{
    return match ($r['state']) {
        'cancel' => 'Отменена', 'past' => 'Прошла', 'mine' => 'Вы участвуете', 'need' => 'Нужен инструктор',
        'crew' => 'Только экипаж', 'full' => 'Мест нет',
        default => 'Мест: ' . max(0, BOAT_CAPACITY - $r['seats_used']),
    };
}
function chip_instructor(array $r): string
{
    if (!$r['instructor_id']) return $r['kind'] === 'crew' ? 'без инструктора' : '';
    return short_name(['last_name' => $r['i_last'], 'first_name' => $r['i_first'], 'middle_name' => $r['i_middle'], 'login' => $r['i_login']]);
}

/** Тренировки, которые пользователь должен подтвердить после окончания */
function pending_reports(int $uid): array
{
    return all("SELECT t.*, c.name AS crew_name FROM trainings t LEFT JOIN crews c ON c.id=t.crew_id
                WHERE t.status<>'cancelled' AND t.report_done=0 AND t.end_at<=?
                  AND (t.instructor_id=? OR (t.instructor_id IS NULL AND t.creator_id=?))
                ORDER BY t.start_at", [now(), $uid, $uid]);
}

/** Какие типы тренировок пользователь может создать */
function create_options(array $u): array
{
    $crew = user_crew((int) $u['id']);
    $o = [];
    if ($crew) {
        $o['crew'] = ['Для моего экипажа', is_instructor($u)
            ? 'Для вашего экипажа, вы ведёте. Видна только экипажу, каждый подтверждает участие.'
            : ((int) $crew['clearance'] === 1
                ? 'Видна только экипажу. Если подтвердят все — выход без инструктора.'
                : 'Видна экипажу и инструкторам: у экипажа нет допуска, нужен инструктор.')];
    }
    $o['open'] = ['Для всех', is_instructor($u)
        ? 'Вы инструктор. Видна всем, записаться может любой — до ' . BOAT_CAPACITY . ' человек в лодке.'
        : 'Видна всем, записаться может любой. Нужен инструктор — тренировка появится в запросах инструкторам.'];
    return $o;
}

/** Сетка допустимого времени начала */
/* ---------- Студенческий сезон: ограничение времени начала ---------- */

const SEASON_DEFAULT_LATEST = '14:00';

/** Самое позднее начало тренировки, если включён студенческий сезон; иначе null */
function season_latest(): ?string
{
    static $v = false;
    if ($v === false) {
        try {
            $on = setting_get('season_on') === '1';
            $v = $on ? (setting_get('season_latest') ?: SEASON_DEFAULT_LATEST) : null;
        } catch (Throwable $e) { $v = null; }
    }
    return $v;
}
/** Можно ли начинать тренировку в это время (учёт сезона) */
function season_allows(string $startAt): bool
{
    $lim = season_latest();
    return $lim === null || hm($startAt) <= $lim;
}

function start_times(): array
{
    $out = [];
    $lim = season_latest();
    $last = $lim ? min((DAY_END_HOUR - TRAINING_HOURS) * 60, (int) substr($lim, 0, 2) * 60 + (int) substr($lim, 3, 2)) : (DAY_END_HOUR - TRAINING_HOURS) * 60;
    for ($m = DAY_START_HOUR * 60; $m <= $last; $m += 30) {
        $out[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
    return $out;
}

/** Первый понедельник расписания и последний день */
function schedule_bounds(): array
{
    $start = new DateTimeImmutable('monday this week');
    $end = $start->modify('+' . (SCHEDULE_WEEKS * 7) . ' days');
    return [$start, $end];
}


/* ---------- Удобные дни и часы: на неделю вперёд ---------- */

const AVAIL_DAYS = 7;

/** Дни окна выбора: сегодня и ещё 6 */
function avail_window(): array
{
    $out = [];
    for ($i = 0; $i < AVAIL_DAYS; $i++) $out[] = date('Y-m-d', strtotime("+$i days", strtotime('today')));
    return $out;
}
/** Часовые слоты, которые можно отметить: слот h = с h:00 до h+1:00 */
function avail_slots(): array
{
    $last = DAY_END_HOUR - 1;
    if ($lim = season_latest()) $last = min($last, (int) substr($lim, 0, 2) + TRAINING_HOURS - 1);
    return range(DAY_START_HOUR, $last);
}
function hours_parse(string $s): array
{
    $h = array_values(array_intersect(avail_slots(), array_map('intval', array_filter(explode(',', $s), 'strlen'))));
    return $h ?: avail_slots();   // пусто = любое время
}
/** Отметки человека в окне для экипажа ('crew') или для всех ('all'): day => [часы] */
function avail_user_days(int $uid, string $scope = 'all'): array
{
    $w = avail_window(); $out = [];
    foreach (all('SELECT day, hours FROM avail_marks WHERE user_id=? AND scope=? AND day>=? AND day<=?', [$uid, $scope, $w[0], end($w)]) as $r)
        $out[$r['day']] = $r['hours'] === '' ? [] : array_map('intval', explode(',', $r['hours']));
    return $out;
}
/** Непрерывные отрезки из часов, не короче тренировки: [[с, до), ...] */
function hour_runs(array $hours): array
{
    sort($hours); $runs = []; $a = null; $prev = null;
    foreach ($hours as $h) {
        if ($a === null) { $a = $prev = $h; continue; }
        if ($h === $prev + 1) { $prev = $h; continue; }
        $runs[] = [$a, $prev + 1]; $a = $prev = $h;
    }
    if ($a !== null) $runs[] = [$a, $prev + 1];
    return array_values(array_filter($runs, fn($r) => $r[1] - $r[0] >= TRAINING_HOURS));
}
function runs_label(array $runs): string
{
    return implode(', ', array_map(fn($r) => sprintf('%d:00–%d:00', $r[0], $r[1]), $runs));
}
/**
 * Удобные дни экипажа.
 * ['members' => [...], 'days' => [day => [uid => [часы]]], 'common' => [day => [[с, до), ...]]]
 * Общий день — тот, который отметили все, и у всех есть общее окно не короче тренировки.
 */
function crew_availability(int $cid): array
{
    $w = avail_window();
    $members = crew_members($cid);
    $ids = array_map(fn($m) => (int) $m['id'], $members);
    $days = array_fill_keys($w, []);
    if ($ids) {
        $rows = all("SELECT user_id, day, hours FROM avail_marks WHERE scope='crew' AND user_id IN (" . in_list($ids) . ') AND day>=? AND day<=?', array_merge($ids, [$w[0], end($w)]));
        foreach ($rows as $r) $days[$r['day']][(int) $r['user_id']] = hours_parse($r['hours']);
    }
    $common = [];
    if (count($ids) >= 2) foreach ($days as $d => $who) {
        if (count($who) !== count($ids)) continue;
        $inter = array_values(array_intersect(...array_values($who)));
        $runs = hour_runs($inter);
        if ($runs) $common[$d] = $runs;
    }
    return ['members' => $members, 'days' => $days, 'common' => $common];
}


/** Последний час, который показывать в расписании (с учётом студенческого сезона: начало + длительность тренировки) */
function schedule_end_hour(): int
{
    $lim = season_latest();
    if (!$lim) return DAY_END_HOUR;
    $m = (int) substr($lim, 0, 2) * 60 + (int) substr($lim, 3, 2) + TRAINING_HOURS * 60;
    return min(DAY_END_HOUR, (int) ceil($m / 60));
}
/**
 * Общий пул: отметки «для всех участников» (отметки для экипажа сюда не попадают).
 * ['hours' => [day => [h => сколько могут в этот час]],
 *  'best'  => [day => [сколько, начало]] — лучшее окно на длину тренировки,
 *  'days'  => [day => сколько людей отметили день]]
 */
function public_availability(): array
{
    $w = avail_window();
    $rows = all("SELECT a.user_id, a.day, a.hours FROM avail_marks a JOIN users u ON u.id=a.user_id
                 WHERE u.active=1 AND a.scope='all' AND a.day>=? AND a.day<=?", [$w[0], end($w)]);
    $byDay = array_fill_keys($w, []);
    foreach ($rows as $r) $byDay[$r['day']][(int) $r['user_id']] = hours_parse($r['hours']);
    $lim = season_latest(); $limH = $lim ? (int) substr($lim, 0, 2) : 99;
    $out = ['hours' => [], 'best' => [], 'days' => [], 'starts' => []];
    foreach ($byDay as $d => $people) {
        if (!$people) continue;
        $out['days'][$d] = count($people);
        $hc = [];
        foreach ($people as $hs) foreach ($hs as $h) $hc[$h] = ($hc[$h] ?? 0) + 1;
        ksort($hc);
        $out['hours'][$d] = $hc;
        $best = [0, null];
        foreach (avail_slots() as $h) {
            if ($h > $limH || $h + TRAINING_HOURS > DAY_END_HOUR) continue;
            $n = 0;
            foreach ($people as $hs) {
                $okAll = true;
                for ($k = 0; $k < TRAINING_HOURS; $k++) if (!in_array($h + $k, $hs, true)) { $okAll = false; break; }
                if ($okAll) $n++;
            }
            if ($n > 0) $out['starts'][$d][$h] = $n;
            if ($n > $best[0]) $best = [$n, $h];
        }
        if ($best[0] > 0) $out['best'][$d] = $best;
    }
    return $out;
}
function best_label(array $b): string
{
    return $b[0] . ' ' . plural($b[0], 'человек', 'человека', 'человек') . ' · ' . sprintf('%d:00–%d:00', $b[1], $b[1] + TRAINING_HOURS);
}

/**
 * Когда могут инструкторы (личный допуск ≥ ограниченного) — по их отметкам «для всех».
 * ['runs' => [day => [[с, до), ...]] — окна, где хотя бы один инструктор может всю тренировку,
 *  'hours' => [day => [h => сколько инструкторов]]]
 */
function instructor_availability(int $excludeUid = 0): array
{
    $w = avail_window();
    $rows = all("SELECT a.user_id, a.day, a.hours FROM avail_marks a JOIN users u ON u.id=a.user_id
                 WHERE u.active=1 AND u.clearance>=1 AND a.scope='all' AND a.user_id<>? AND a.day>=? AND a.day<=?", [$excludeUid, $w[0], end($w)]);
    $out = ['runs' => [], 'hours' => []];
    $cover = [];
    foreach ($rows as $r) {
        $hs = hours_parse($r['hours']);
        foreach ($hs as $h) $out['hours'][$r['day']][$h] = ($out['hours'][$r['day']][$h] ?? 0) + 1;
        foreach (hour_runs($hs) as [$a, $b]) for ($h = $a; $h < $b; $h++) $cover[$r['day']][$h] = true;
    }
    foreach ($cover as $d => $hs) if ($runs = hour_runs(array_keys($hs))) $out['runs'][$d] = $runs;
    return $out;
}


/** Часовые слоты, которые занимает тренировка: 10:30–12:30 → 10, 11, 12 */
function hours_of(string $startAt, string $endAt): array
{
    $a = (int) date('G', strtotime($startAt));
    $e = strtotime($endAt);
    $b = (int) date('G', $e) + ((int) date('i', $e) ? 1 : 0);
    return range($a, max($a, $b - 1));
}
/** Кто отметил «для всех», что может всю тренировку (по желанию — только инструкторы) */
function avail_users_at(string $startAt, string $endAt, bool $instructorsOnly = false): array
{
    $day = substr($startAt, 0, 10);
    $need = hours_of($startAt, $endAt);
    $rows = all("SELECT a.user_id, a.hours FROM avail_marks a JOIN users u ON u.id=a.user_id
                 WHERE u.active=1 AND a.scope='all' AND a.day=?" . ($instructorsOnly ? ' AND u.clearance>=1' : ''), [$day]);
    $ids = [];
    foreach ($rows as $r) if (!array_diff($need, hours_parse($r['hours']))) $ids[] = (int) $r['user_id'];
    return $ids;
}
/** Для мастера создания: инструкторы и их удобные часы по дням окна */
function instructor_marks(int $excludeUid): array
{
    $w = avail_window(); $out = [];
    $rows = all("SELECT a.day, a.hours, u.id, u.last_name, u.first_name, u.middle_name, u.login, u.clearance FROM avail_marks a JOIN users u ON u.id=a.user_id
                 WHERE u.active=1 AND u.clearance>=1 AND a.scope='all' AND u.id<>? AND a.day>=? AND a.day<=? ORDER BY u.last_name", [$excludeUid, $w[0], end($w)]);
    foreach ($rows as $r) $out[$r['day']][] = ['id' => (int) $r['id'], 'name' => short_name($r), 'clr' => CLEARANCES[(int) $r['clearance']], 'hours' => hours_parse($r['hours'])];
    return $out;
}
/** После создания: пригласить выбранных инструкторов и сообщить всем, кому удобно это время */
function after_create_invites(array $t, array $u, array $invite, bool $openForAll): string
{
    $uid = (int) $u['id'];
    $sent = [];
    if ((int) $t['needs_instructor']) {
        $free = avail_users_at($t['start_at'], $t['end_at'], true);
        foreach (array_unique(array_map('intval', $invite)) as $iid) {
            if ($iid === $uid || !in_array($iid, $free, true)) continue;
            if (user_busy($iid, $t['start_at'], $t['end_at'], (int) $t['id'])) continue;
            q('INSERT INTO instructor_invites (training_id,instructor_id,from_user,created_at) VALUES (?,?,?,?)', [$t['id'], $iid, $uid, now()]);
            notify($iid, short_name($u) . ' приглашает вас инструктором на тренировку ' . t_desc($t) . ' — вы отметили, что это время вам удобно.', t_link($t));
            $sent[] = $iid;
        }
    }
    // остальным, кто отметил это время: всем — если тренировка открыта для всех; иначе только инструкторам (нужен инструктор)
    $others = $openForAll ? avail_users_at($t['start_at'], $t['end_at']) : ((int) $t['needs_instructor'] ? avail_users_at($t['start_at'], $t['end_at'], true) : []);
    $crewIds = $t['crew_id'] ? crew_member_ids((int) $t['crew_id']) : [];
    $others = array_values(array_diff($others, $sent, [$uid], $crewIds, [(int) $t['instructor_id']]));
    $GLOBALS['NOTIFIED_AVAIL'] = array_merge($sent, $others);
    if ($others) notify_many($others, 'Создана тренировка в удобное для вас время: ' . t_desc($t) . ($openForAll ? '. Можно записаться.' : '. Ей нужен инструктор.'), t_link($t), $uid);
    return ($sent ? ' Приглашено инструкторов: ' . count($sent) . '.' : '') . ($others ? ' Уведомлено тех, кому удобно это время: ' . count($others) . '.' : '');
}
