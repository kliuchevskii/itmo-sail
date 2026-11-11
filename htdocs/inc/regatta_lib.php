<?php
declare(strict_types=1);

/*
 * Регистрация на регаты.
 *  - лично: запись в event_people;
 *  - экипажем: event_teams + event_team_members.
 *    kind=home — родной экипаж, каждый член экипажа подтверждает участие;
 *    kind=temp — временный экипаж только на эту регату. Свободные места видны всем (открытый набор).
 *    Временные экипажи удаляются после окончания регаты.
 * Подтвердивший участие член экипажа автоматически попадает в общий список участников (event_people).
 */

if (!defined('REGATTA_CREW_MAX')) define('REGATTA_CREW_MAX', BOAT_CAPACITY);
const TEAM_ACTIVE = ['invited', 'confirmed'];
const STAGES_MAX = 10;

function event_get(int $id): ?array { return one('SELECT * FROM events WHERE id=?', [$id]); }
function event_title(array $ev): string { return $ev['title'] . (!empty($ev['stage']) ? ' · этап ' . (int) $ev['stage'] : ''); }
function event_dates(array $ev): string
{
    return ru_date($ev['start_date'], true) . ($ev['end_date'] !== $ev['start_date'] ? ' — ' . ru_date($ev['end_date'], true) : '');
}
function event_reg_open(array $ev): bool { return (int) $ev['reg_open'] === 1 && $ev['end_date'] >= date('Y-m-d'); }

/** Удаляем временные экипажи прошедших регат и опустевшие экипажи */
function regatta_cleanup(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $ids = array_merge(
        array_column(all("SELECT t.id FROM event_teams t JOIN events e ON e.id=t.event_id WHERE t.kind='temp' AND e.end_date<?", [date('Y-m-d')]), 'id'),
        array_column(all("SELECT t.id FROM event_teams t WHERE NOT EXISTS (SELECT 1 FROM event_team_members m WHERE m.team_id=t.id AND m.status IN ('invited','confirmed'))"), 'id'),
        array_column(all('SELECT t.id FROM event_teams t WHERE NOT EXISTS (SELECT 1 FROM events e WHERE e.id=t.event_id)'), 'id')
    );
    if (!$ids) return;
    q('DELETE FROM event_team_members WHERE team_id IN (' . in_list($ids) . ')', $ids);
    q('DELETE FROM event_teams WHERE id IN (' . in_list($ids) . ')', $ids);
}

function team_get(int $id): ?array { return one('SELECT * FROM event_teams WHERE id=?', [$id]); }
function team_members(int $teamId): array
{
    return all("SELECT m.*, u.login, u.last_name, u.first_name, u.middle_name, u.gender, u.level, u.program, u.enroll_year
                FROM event_team_members m JOIN users u ON u.id=m.user_id
                WHERE m.team_id=? AND m.status IN ('invited','confirmed') ORDER BY m.status='invited', m.id", [$teamId]);
}
function team_free(array $members): int { return max(0, REGATTA_CREW_MAX - count($members)); }
function event_teams(int $eid): array
{
    $out = [];
    foreach (all('SELECT * FROM event_teams WHERE event_id=? ORDER BY id', [$eid]) as $t) {
        $t['members'] = team_members((int) $t['id']);
        $t['free'] = team_free($t['members']);
        $out[] = $t;
    }
    return $out;
}
/** Экипаж, в котором пользователь состоит (или приглашён) на этой регате */
function user_team(int $eid, int $uid): ?array
{
    return one("SELECT t.*, m.status AS my_status, m.role AS my_role FROM event_teams t JOIN event_team_members m ON m.team_id=t.id
                WHERE t.event_id=? AND m.user_id=? AND m.status IN ('invited','confirmed') ORDER BY m.status='confirmed' DESC LIMIT 1", [$eid, $uid]);
}
function user_team_confirmed(int $eid, int $uid): ?array
{
    return one("SELECT t.* FROM event_teams t JOIN event_team_members m ON m.team_id=t.id
                WHERE t.event_id=? AND m.user_id=? AND m.status='confirmed' LIMIT 1", [$eid, $uid]);
}

function ensure_event_person(int $eid, int $uid, string $role): void
{
    $row = one('SELECT * FROM event_people WHERE event_id=? AND user_id=?', [$eid, $uid]);
    if ($row) { if ($role !== '') q('UPDATE event_people SET crew_role=? WHERE id=?', [$role, $row['id']]); return; }
    q('INSERT INTO event_people (event_id,user_id,crew_role,created_at) VALUES (?,?,?,?)', [$eid, $uid, $role, now()]);
}
function remove_event_person(int $eid, int $uid): void
{
    q("DELETE FROM event_people WHERE event_id=? AND user_id=? AND status='registered'", [$eid, $uid]);
}

/** Дни регат, в которые тренировки не проводятся: ['Y-m-d' => название регаты] */
function no_training_days(string $fromDate, string $toDate): array
{
    static $cache = [];
    $key = "$fromDate|$toDate";
    if (isset($cache[$key])) return $cache[$key];
    $out = [];
    try {
        foreach (all('SELECT * FROM events WHERE no_trainings=1 AND start_date<=? AND end_date>=?', [$toDate, $fromDate]) as $ev) {
            for ($d = new DateTimeImmutable(max($ev['start_date'], $fromDate)); $d->format('Y-m-d') <= min($ev['end_date'], $toDate); $d = $d->modify('+1 day')) {
                $out[$d->format('Y-m-d')] = event_title($ev);
            }
        }
    } catch (Throwable $e) { /* колонка ещё не создана */ }
    return $cache[$key] = $out;
}

function role_label(string $k): string { return CREW_ROLES[$k] ?? ''; }

/** Регаты в диапазоне дат — по дням, для расписания */
function events_range(string $fromDate, string $toDate, int $uid): array
{
    regatta_cleanup();
    $out = [];
    foreach (all('SELECT * FROM events WHERE start_date<? AND end_date>=? ORDER BY start_date', [$toDate, $fromDate]) as $ev) {
        $teams = event_teams((int) $ev['id']);
        $ev['open_teams'] = count(array_filter($teams, fn($t) => $t['kind'] === 'temp' && $t['free'] > 0));
        $ev['teams'] = count($teams);
        $mine = user_team((int) $ev['id'], $uid);
        $ev['my'] = $mine ? ($mine['my_status'] === 'invited' ? 'invited' : 'team')
            : (val('SELECT 1 FROM event_people WHERE event_id=? AND user_id=?', [$ev['id'], $uid]) ? 'registered' : null);
        $d = new DateTimeImmutable(max($ev['start_date'], $fromDate));
        $last = min($ev['end_date'], (new DateTimeImmutable($toDate))->modify('-1 day')->format('Y-m-d'));
        for (; $d->format('Y-m-d') <= $last; $d = $d->modify('+1 day')) $out[$d->format('Y-m-d')][] = $ev;
    }
    return $out;
}
function event_badge(array $ev): string
{
    return match ($ev['my']) {
        'team' => 'Вы в экипаже',
        'invited' => 'Вас зовут в экипаж',
        'registered' => 'Вы участвуете',
        default => $ev['open_teams'] ? 'Набор в экипаж: ' . $ev['open_teams'] : 'Регата',
    };
}
