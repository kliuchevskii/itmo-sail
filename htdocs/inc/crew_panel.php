<?php
declare(strict_types=1);

/** Содержимое раздела «Экипаж»: страница и всплывающее окно в профиле */
function crew_panel(array $u, bool $compact = false): string
{
    $uid = (int) $u['id'];
    $crew = user_crew($uid);
    ob_start();
    $invites = all("SELECT ci.*, c.name, u.last_name, u.first_name, u.middle_name, u.login FROM crew_invites ci
                  JOIN crews c ON c.id=ci.crew_id JOIN users u ON u.id=ci.from_user
                  WHERE ci.to_user=? AND ci.status='pending' ORDER BY ci.created_at DESC", [$uid]); ?>
  <?php foreach ($invites as $inv): ?>
    <div class="alert">
      <?= icon('users') ?><div><b>Приглашение в экипаж «<?= e($inv['name']) ?>»</b><span>от <?= e(full_name($inv)) ?><?= $crew ? ' · чтобы принять, сначала выйдите из текущего экипажа' : '' ?></span></div>
      <div class="row-btns">
        <?= action_button('crew_invite_reply', ['inv' => $inv['id'], 'yes' => 1], 'Принять', 'btn btn-primary btn-sm') ?>
        <?= action_button('crew_invite_reply', ['inv' => $inv['id'], 'yes' => 0], 'Отклонить', 'btn btn-sm') ?>
      </div>
    </div>
  <?php endforeach ?>

  <?php if (!$crew): ?>
    <div class="card stack">
      <h2 class="card-h"><?= icon('users') ?>Вы пока не в экипаже</h2>
      <p class="hint">Создайте экипаж и пригласите в него людей или примите чужое приглашение. Состоять можно только в одном экипаже, в нём до <?= BOAT_CAPACITY ?> человек.</p>
      <?= form_open('crew_create', 'class="row-form"') ?>
        <label class="field grow"><span>Название экипажа</span><input name="name" maxlength="60" required placeholder="Например: Альбатрос"></label>
        <button class="btn btn-primary" type="submit">Создать экипаж</button>
      </form>
    </div>
  <?php else:
    $members = crew_members((int) $crew['id']);
    $isCap = (int) $crew['captain_id'] === $uid;
    $sent = all("SELECT ci.*, u.last_name, u.first_name, u.middle_name, u.login FROM crew_invites ci JOIN users u ON u.id=ci.to_user WHERE ci.crew_id=? AND ci.status='pending'", [$crew['id']]); ?>
    <div class="<?= $compact ? 'stack' : 'cols' ?>">
      <section class="col-main stack">
        <div class="card">
          <div class="card-top">
            <h2 class="card-h"><?= icon('sail') ?>«<?= e($crew['name']) ?>»</h2>
            <span class="badge <?= (int) $crew['clearance'] ? 'b-ok' : 'b-muted' ?>"><?= (int) $crew['clearance'] ? 'Допуск экипажа есть' : 'Допуска экипажа нет' ?></span>
          </div>
          <ul class="people">
            <?php foreach ($members as $m): ?>
              <li>
                <span class="avatar sm"><?= e(initials($m)) ?></span>
                <span class="p-name"><b><?= e(full_name($m)) ?></b><small><?= e(implode(' · ', array_filter([$m['crew_roles'] ? crew_roles_label($m['crew_roles']) : '', LEVELS[(int) $m['level']], is_instructor($m) ? 'личный допуск' : '']))) ?></small></span>
              </li>
            <?php endforeach ?>
          </ul>
        </div>
      </section>
      <aside class="col-side stack">
        <div class="card stack">
          <h2 class="card-h"><?= icon('plus') ?>Пригласить</h2>
          <?php if (count($members) >= BOAT_CAPACITY): ?>
            <p class="hint">В экипаже максимум людей.</p>
          <?php else: ?>
            <?php
              $pendingIds = array_map('intval', array_column($sent, 'to_user'));
              $memberIds = array_map(fn($m) => (int) $m['id'], $members);
              $free = BOAT_CAPACITY - count($members) - count($sent);
              $cands = all("SELECT u.id, u.login, u.last_name, u.first_name, u.middle_name, u.level,
                                   (SELECT c.name FROM crew_members cm JOIN crews c ON c.id=cm.crew_id WHERE cm.user_id=u.id) AS crew_name
                            FROM users u WHERE u.active=1 ORDER BY u.last_name, u.first_name");
              $cands = array_values(array_filter($cands, fn($c) => !in_array((int) $c['id'], $memberIds, true) && !in_array((int) $c['id'], $pendingIds, true))); ?>
            <?php if ($free <= 0): ?>
              <p class="hint">Все свободные места уже заняты приглашениями.</p>
            <?php else: ?>
            <?= form_open('crew_invite', 'class="stack"') ?>
              <div class="field"><span>Спортсмены (можно выбрать до <?= $free ?>)</span>
                <input type="search" placeholder="Поиск по фамилии или логину" data-filter-list aria-label="Поиск">
                <div class="pick" data-max="<?= $free ?>">
                  <?php foreach ($cands as $c): ?>
                    <label class="pick-item" data-name="<?= e(mb_strtolower(full_name($c) . ' ' . $c['login'])) ?>"><input type="checkbox" name="invite[]" value="<?= (int) $c['id'] ?>">
                      <span><b><?= e(full_name($c)) ?></b><small><?= e(LEVELS[(int) $c['level']]) ?><?= $c['crew_name'] ? ' · в экипаже «' . e($c['crew_name']) . '»' : '' ?></small></span></label>
                  <?php endforeach ?>
                  <?php if (!$cands): ?><p class="hint pad">Некого пригласить.</p><?php endif ?>
                </div>
              </div>
              <button class="btn btn-primary btn-block" type="submit">Отправить приглашения</button>
            </form>
            <?php endif ?>
          <?php endif ?>
          <?php foreach ($sent as $s): ?>
            <div class="doc"><span class="doc-n"><b><?= e(full_name($s)) ?></b><small>ждём ответа</small></span><?= action_button('crew_invite_cancel', ['inv' => $s['id']], 'Отозвать', 'btn btn-sm') ?></div>
          <?php endforeach ?>
        </div>
        <button class="btn btn-block" type="button" data-open-dialog="dlg-crew-rename"><?= icon('edit') ?><span>Переименовать экипаж</span></button>
        <dialog class="sheet" id="dlg-crew-rename">
          <div class="sheet-head"><div><small>Экипаж</small><h2>Новое название</h2></div><button class="icon-btn ghost" type="button" data-close-dialog aria-label="Закрыть"><?= icon('x') ?></button></div>
          <div class="sheet-body">
            <?= form_open('crew_rename', 'class="stack"') ?>
              <label class="field"><span>Название</span><input name="name" value="<?= e($crew['name']) ?>" maxlength="60" required></label>
              <button class="btn btn-primary btn-block" type="submit">Сохранить</button>
            </form>
          </div>
        </dialog>
        <?= action_button('crew_leave', [], 'Выйти из экипажа', 'btn btn-danger btn-block', 'Выйти из экипажа?' . ((int) $crew['clearance'] ? ' Допуск экипажа будет снят.' : '')) ?>
      </aside>
    </div>
  <?php endif ?>
<?php
    return (string) ob_get_clean();
}
