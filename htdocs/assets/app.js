(function () {
  'use strict';
  var root = document.documentElement;
  var BASE = function () { return window.SAIL_BASE || ''; };

  function setTheme(t) {
    root.setAttribute('data-theme', t);
    try { localStorage.setItem('theme', t); } catch (e) {}
  }

  /* ---------- всплывающие окна ---------- */
  /* Окна открываем как обычный слой поверх страницы (не showModal):
     на Android выпадающие списки внутри «модального» окна иногда рисуются под ним. */
  var scrim = null;
  function getScrim() {
    if (!scrim) {
      scrim = document.createElement('div');
      scrim.className = 'dlg-scrim';
      scrim.addEventListener('click', function () { closeDialog(document.querySelector('dialog.is-layer[open]')); });
      document.body.appendChild(scrim);
    }
    return scrim;
  }
  function openDialog(d) {
    if (!d) return;
    d.classList.add('is-layer');
    if (!d.open) { if (typeof d.show === 'function') d.show(); else d.setAttribute('open', ''); }
    getScrim().classList.add('is-on');
    document.body.classList.add('has-dialog');
  }
  function closeDialog(d) {
    if (!d) return;
    if (typeof d.close === 'function') d.close(); else d.removeAttribute('open');
    if (!document.querySelector('dialog.is-layer[open]')) {
      if (scrim) scrim.classList.remove('is-on');
      document.body.classList.remove('has-dialog');
    }
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { var d = document.querySelector('dialog.is-layer[open]'); if (d) closeDialog(d); }
  });

  // создание тренировки: время начала до текущего момента сегодня недоступно
  function syncTimes(form) {
    var date = form.querySelector('[data-date]').value;
    var isToday = date === window.SAIL_TODAY;
    var now = new Date();
    var nowStr = ('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2);
    var firstOk = null, curOk = false;
    form.querySelectorAll('input[name="time"]').forEach(function (o) {
      var past = isToday && o.value <= nowStr;
      o.disabled = past;
      if (!past && !firstOk) firstOk = o;
      if (!past && o.checked) curOk = true;
    });
    if (!curOk && firstOk) firstOk.checked = true;
    var submit = form.querySelector('[type=submit]');
    if (submit) submit.disabled = !firstOk;
  }
  // инструкторы, которые отметили, что могут в выбранные день и время
  function syncInstr(form) {
    var box = form.querySelector('[data-instr-box]');
    if (!box) return;
    var type = form.querySelector('input[name="type"]:checked');
    var time = form.querySelector('input[name="time"]:checked');
    var date = form.querySelector('[data-date]').value;
    if (!type || !type.hasAttribute('data-needs-instr') || !time) { box.hidden = true; return; }
    var p = time.value.split(':'), s = +p[0] * 60 + +p[1], e = s + (window.SAIL_TRAIN_MIN || 120);
    var need = []; for (var h = Math.floor(s / 60); h * 60 < e; h++) need.push(h);
    var list = ((window.SAIL_INSTR || {})[date] || []).filter(function (i) { return need.every(function (h) { return i.hours.indexOf(h) >= 0; }); });
    var wrap = box.querySelector('[data-instr-list]');
    wrap.innerHTML = '';
    list.forEach(function (i) {
      var l = document.createElement('label'); l.className = 'instr-item';
      var c = document.createElement('input'); c.type = 'checkbox'; c.name = 'instr_invite[]'; c.value = i.id; c.checked = true;
      var t = document.createElement('span'); t.innerHTML = '<b></b><small></small>';
      t.querySelector('b').textContent = i.name; t.querySelector('small').textContent = 'Допуск: ' + i.clr.toLowerCase() + ' · пригласить';
      l.appendChild(c); l.appendChild(t); wrap.appendChild(l);
    });
    box.hidden = false;
    box.querySelector('[data-instr-hint]').textContent = list.length
      ? 'Отмеченным придёт приглашение провести тренировку. Остальным, кому удобно это время, придёт уведомление.'
      : 'На это время инструкторы пока не отметились. Тренировка появится у всех инструкторов в «Запросах инструкторам».';
  }
  // открыть окно создания: из дня (дата задана) или общей кнопкой (дату выбирают в окне)
  function openCreateSheet(btn) {
    var sheet = document.getElementById('create-sheet');
    if (!sheet) return;
    var date = btn.getAttribute('data-sheet-date');
    var form = sheet.querySelector('[data-create-form]');
    var title = sheet.querySelector('[data-sheet-title]');
    if (form) {
      var field = form.querySelector('[data-date-field]');
      var dsel = form.querySelector('[data-date]');
      if (date) {
        dsel.value = date;
        field.hidden = true;
        title.textContent = btn.getAttribute('data-sheet-title');
      } else {
        field.hidden = false;
        title.textContent = 'Создать тренировку';
      }
      syncTimes(form);
      // предложение из расписания: сразу нужное время и тип «для экипажа»
      var tm = btn.getAttribute('data-sheet-time'), tp = btn.getAttribute('data-sheet-type');
      if (tm) { var r = form.querySelector('input[name="time"][value="' + tm + '"]'); if (r && !r.disabled) r.checked = true; }
      if (tp) { var t = form.querySelector('input[name="type"][value="' + tp + '"]'); if (t) { t.checked = true; t.dispatchEvent(new Event('change', { bubbles: true })); } }
      syncInstr(form);
      // сегодня уже поздно — сразу предлагаем ближайший доступный день
      if (!date && !form.querySelector('input[name="time"]:not(:disabled)')) {
        var next = Array.prototype.find.call(dsel.options, function (o) { return !o.disabled && o.value > window.SAIL_TODAY; });
        if (next) { dsel.value = next.value; syncTimes(form); syncInstr(form); }
      }
    }
    openDialog(sheet);
  }

  // админка: карточка участника во всплывающем окне
  function openUser(id, tab) {
    var modal = document.getElementById('user-modal');
    if (!modal) return;
    var body = modal.querySelector('[data-modal-body]');
    body.innerHTML = '<p class="hint pad">Загрузка…</p>';
    openDialog(modal);
    var url = modal.getAttribute('data-src') + '?partial=1&id=' + encodeURIComponent(id) + (tab ? '&ut=' + encodeURIComponent(tab) : '');
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        body.innerHTML = html;
        // сообщения после сохранения показываем внутри окна
        var flashes = document.querySelectorAll('.content > .flash');
        if (tab && flashes.length) {
          var head = body.querySelector('.mtabs');
          Array.prototype.forEach.call(flashes, function (f) { head.parentNode.insertBefore(f, head.nextSibling); });
        }
        initPrograms(body);
      })
      .catch(function () { body.innerHTML = '<p class="hint pad">Не удалось загрузить. Проверьте соединение.</p>'; });
  }

  document.addEventListener('click', function (e) {
    var el;
    if ((el = e.target.closest('[data-theme-toggle]'))) {
      var order = ['auto', 'light', 'dark'];
      var cur = root.getAttribute('data-theme') || 'auto';
      setTheme(order[(order.indexOf(cur) + 1) % order.length]);
      return;
    }
    if ((el = e.target.closest('[data-sheet-date], [data-create-open]'))) { e.preventDefault(); openCreateSheet(el); return; }
    if ((el = e.target.closest('[data-user]'))) { openUser(el.getAttribute('data-user')); return; }
    if ((el = e.target.closest('[data-close-dialog]'))) { closeDialog(el.closest('dialog')); return; }
    if ((el = e.target.closest('[data-open-dialog]'))) { openDialog(document.getElementById(el.getAttribute('data-open-dialog'))); return; }
    if ((el = e.target.closest('[data-href]'))) { e.preventDefault(); location.href = el.getAttribute('data-href'); return; }
    if ((el = e.target.closest('[data-mtab]'))) {
      var box = el.closest('[data-tabs-scope]') || el.closest('dialog') || document;
      var name = el.getAttribute('data-mtab');
      box.querySelectorAll('[data-mtab]').forEach(function (b) { b.classList.toggle('is-on', b === el); });
      box.querySelectorAll('[data-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
      return;
    }
    if ((el = e.target.closest('[data-fill]'))) {
      el.closest('[data-journal]').querySelectorAll('.jcell input[type=checkbox]').forEach(function (i) { i.checked = true; i.closest('.jcell').classList.add('is-done'); });
      return;
    }
    // клик по затемнению вокруг окна закрывает его
    if ((el = e.target.closest('[data-confirm-click]')) && !window.confirm(el.getAttribute('data-confirm-click'))) e.preventDefault();
  });

  document.addEventListener('close', function (e) {
    if (e.target.tagName === 'DIALOG' && !document.querySelector('dialog.is-layer[open]')) {
      if (scrim) scrim.classList.remove('is-on');
      document.body.classList.remove('has-dialog');
    }
  }, true);

  document.addEventListener('submit', function (e) {
    var f = e.target;
    var sub = e.submitter;
    if (f.hasAttribute('data-confirm') && !(sub && sub.hasAttribute('data-confirm-click'))) {
      if (!window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); return; }
    }
    if (sub && !e.defaultPrevented) setTimeout(function () { sub.disabled = true; }, 0);
  });

  // уровень обучения: курс нужен только студентам
  function syncCourse(sel) {
    var form = sel.closest('form');
    var field = form && form.querySelector('[data-course-field]');
    if (!field) return;
    var max = { bachelor: 4, master: 2, specialist: 6 }[sel.value];
    field.style.display = max ? '' : 'none';
    var s = field.querySelector('select');
    if (s && max) Array.prototype.forEach.call(s.options, function (o) { o.hidden = +o.value > max; if (+o.value > max && o.selected) s.value = '1'; });
  }
  function initPrograms(scope) { scope.querySelectorAll('[data-program]').forEach(syncCourse); }
  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-program]')) syncCourse(e.target);
    if (e.target.matches('[data-autosubmit]')) { var f = e.target.form; setTimeout(function () { f.submit(); }, 150); }
    if (e.target.matches('[data-create-form] [data-date]')) syncTimes(e.target.closest('form'));
    if (e.target.closest && e.target.closest('[data-create-form]')) syncInstr(e.target.closest('[data-create-form]'));
    if (e.target.matches('.pick input')) {
      var pick = e.target.closest('.pick'), max = +pick.getAttribute('data-max');
      var checked = pick.querySelectorAll('input:checked').length;
      pick.querySelectorAll('input').forEach(function (i) { if (!i.checked) i.disabled = checked >= max; });
    }
    if (e.target.matches('.jcell input')) e.target.closest('.jcell').classList.toggle('is-done', e.target.checked);
  });

  document.addEventListener('input', function (e) {
    if (!e.target.matches('[data-filter-list]')) return;
    var q = e.target.value.trim().toLowerCase();
    var list = e.target.parentNode.querySelector('.pick');
    list.querySelectorAll('.pick-item').forEach(function (it) { it.hidden = q && it.getAttribute('data-name').indexOf(q) === -1; });
  });

  document.addEventListener('DOMContentLoaded', function () {
    var autoM = document.querySelector('dialog[data-autoopen-mobile]');
    if (autoM && window.matchMedia('(max-width: 860px)').matches) openDialog(autoM);
    var auto = document.querySelector('dialog[data-autoopen]');
    if (auto) {
      openDialog(auto);
      var fl = document.querySelectorAll('.content > .flash');
      var body = auto.querySelector('.sheet-body');
      Array.prototype.forEach.call(fl, function (f) { body.insertBefore(f, body.firstChild); });
      try { history.replaceState(null, '', location.pathname + location.search.replace(/[?&]crew=1/, '').replace(/^&/, '?')); } catch (e) {}
    }
    initPrograms(document);
    document.querySelectorAll('.file input[type=file]').forEach(function (inp) {
      inp.addEventListener('change', function () {
        var span = inp.nextElementSibling;
        if (inp.files[0] && span) span.lastChild.textContent = inp.files[0].name;
      });
    });
    var modal = document.getElementById('user-modal');
    if (modal && modal.getAttribute('data-open-user')) {
      openUser(modal.getAttribute('data-open-user'), modal.getAttribute('data-open-tab') || 'main');
      // убрать ?u= из адреса, чтобы окно не открывалось при обновлении
      try { history.replaceState(null, '', location.pathname); } catch (e) {}
    }
  });

  /* ---------- пуш-уведомления ---------- */
  function b64ToBytes(s) {
    var pad = '='.repeat((4 - s.length % 4) % 4);
    var raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }
  var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var isStandalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  var pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

  function pushInit() {
    var box = document.querySelector('[data-push]');
    if (!box) return;
    var q = function (s) { return box.querySelector(s); };
    var status = q('[data-push-status]'), acts = q('[data-push-actions]'), hint = q('[data-push-hint]');
    var bOn = q('[data-push-on]'), bOff = q('[data-push-off]'), bTest = q('[data-push-test]');
    var say = function (t) { status.textContent = t; };
    var tip = function (t) { hint.textContent = t || ''; hint.hidden = !t; };
    var api = function (a, sub) {
      return fetch(box.getAttribute('data-api'), {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ a: a, sub: sub ? sub.toJSON() : null, csrf: box.getAttribute('data-csrf') })
      }).then(function (r) {
        return r.text().then(function (t) {
          try { return JSON.parse(t); }
          catch (e) { return { ok: false, error: 'сайт ответил не так, как ожидалось (HTTP ' + r.status + '): ' + t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160) }; }
        });
      }).catch(function (e) { return { ok: false, error: 'нет связи с сайтом (' + e.message + ')' }; });
    };
    var showDiag = function (d) {
      if (!d) return;
      var lines = Object.keys(d).map(function (k) { return k + ': ' + d[k]; });
      tip('Диагностика (пришлите разработчику): ' + lines.join(' · '));
    };
    if (!pushSupported) {
      if (isIOS && !isStandalone) {
        say('На iPhone уведомления работают только в установленном приложении.');
        tip('Откройте сайт в Safari → «Поделиться» → «На экран „Домой“». Запустите ITMO Sail с главного экрана и включите уведомления здесь. Нужна iOS 16.4 или новее.');
      } else say('Этот браузер не поддерживает уведомления. Откройте сайт в Chrome.');
      return;
    }
    if (!box.getAttribute('data-key')) { say('Сервер не смог подготовить уведомления.'); return; }
    var key = b64ToBytes(box.getAttribute('data-key'));

    function render(sub) {
      acts.hidden = false;
      if (Notification.permission === 'denied') {
        say('Уведомления запрещены для этого сайта.');
        tip('Разрешите их: значок слева от адреса сайта → «Уведомления» → «Разрешить». Для установленного приложения: настройки телефона → Приложения → ITMO Sail → Уведомления.');
        bOn.hidden = true; bOff.hidden = true; bTest.hidden = true;
        return;
      }
      tip('');
      bOn.hidden = !!sub; bOff.hidden = !sub; bTest.hidden = !sub;
      say(sub ? 'Включены на этом устройстве — нажмите «Проверить», чтобы убедиться, что уведомление приходит.' : 'Выключены на этом устройстве.');
      var done = false;
      try { done = !!sub && localStorage.getItem('pushTested') === sub.endpoint; } catch (e) {}
      box.classList.toggle('is-done', done);
    }
    navigator.serviceWorker.ready.then(function (reg) {
      return reg.pushManager.getSubscription().then(function (sub) {
        if (sub) api('sub', sub); // напоминаем серверу о подписке (безопасно повторять)
        render(sub);
        bOn.addEventListener('click', function () {
          bOn.disabled = true; say('Запрашиваем разрешение…');
          Notification.requestPermission().then(function (p) {
            if (p !== 'granted') { bOn.disabled = false; render(null); return; }
            return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }).then(function (s) {
              return api('sub', s).then(function (r) {
                bOn.disabled = false;
                if (!r.ok) { say('Не удалось сохранить подписку: ' + (r.error || '')); return; }
                render(s);
              });
            });
          }).catch(function (err) { bOn.disabled = false; say('Не удалось включить: ' + err.message); });
        });
        bOff.addEventListener('click', function () {
          reg.pushManager.getSubscription().then(function (s) {
            if (!s) return render(null);
            return api('unsub', s).then(function () { return s.unsubscribe(); }).then(function () { render(null); });
          });
        });
        bTest.addEventListener('click', function () {
          bTest.disabled = true; say('Отправляем тестовое уведомление…');
          reg.pushManager.getSubscription().then(function (s) { return api('test', s); }).then(function (r) {
            bTest.disabled = false;
            say(r.ok ? 'Отправлено — проверьте шторку уведомлений.' : 'Не отправилось: ' + (r.error || 'ошибка'));
            if (r.ok) reg.pushManager.getSubscription().then(function (s) {
              try { localStorage.setItem('pushTested', s.endpoint); } catch (e) {}
              setTimeout(function () { box.classList.add('is-done'); }, 2500);
            });
            if (!r.ok) { if (r.diag) showDiag(r.diag); else api('diag', null).then(function (x) { showDiag(x && x.diag); }); }
          });
        });
      });
    }).catch(function () { say('Не удалось запустить фоновый обработчик. Обновите страницу.'); });
  }

  /* Подсказка на главной: уведомления ещё не включены */
  function pushNudge() {
    var n = document.querySelector('[data-push-nudge]');
    if (!n || !pushSupported || Notification.permission !== 'default') return;
    try { if (localStorage.getItem('pushNudgeHidden')) return; } catch (e) {}
    navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
      if (!sub) n.hidden = false;
    });
    var close = n.querySelector('[data-nudge-close]');
    if (close) close.addEventListener('click', function () { n.hidden = true; try { localStorage.setItem('pushNudgeHidden', '1'); } catch (e) {} });
  }
  document.addEventListener('DOMContentLoaded', function () { pushInit(); pushNudge(); });

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(BASE() + '/sw.js').catch(function () {});
    });
  }
})();

/* Выпадающие кнопки «Фильтр» и «Сортировка»: открыта только одна, закрываются по клику мимо и Esc */
(function () {
  var dds = document.querySelectorAll('[data-dd]');
  if (!dds.length) return;
  dds.forEach(function (d) {
    d.addEventListener('toggle', function () {
      if (d.open) dds.forEach(function (o) { if (o !== d) o.open = false; });
    });
  });
  document.addEventListener('click', function (e) {
    dds.forEach(function (d) { if (d.open && !d.contains(e.target)) d.open = false; });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') dds.forEach(function (d) { d.open = false; });
  });
})();

/* Удобные дни: строки часов показываются только для отмеченных дней; вкладки «для экипажа / для всех» */
(function () {
  document.querySelectorAll('[data-avail-hours]').forEach(function (box) {
    var form = box.closest('form');
    function sync() {
      var any = false;
      form.querySelectorAll('[data-avail-day]').forEach(function (cb) {
        var row = box.querySelector('.ahrow[data-day="' + cb.value + '"]');
        if (row) row.hidden = !cb.checked;
        if (cb.checked) any = true;
      });
      var empty = box.querySelector('.avail-empty');
      if (empty) empty.hidden = any;
    }
    form.querySelectorAll('[data-avail-day]').forEach(function (cb) { cb.addEventListener('change', sync); });
    sync();
  });
  document.querySelectorAll('[data-avail-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var card = btn.closest('.avail');
      card.querySelectorAll('[data-avail-tab]').forEach(function (b) { b.classList.toggle('is-on', b === btn); });
      card.querySelectorAll('[data-avail-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-avail-panel') !== btn.getAttribute('data-avail-tab'); });
    });
  });
})();

/* Переключатели-сегменты на радиокнопках: подсветка выбранного */
document.addEventListener('change', function (e) {
  var r = e.target;
  if (!r.matches || !r.matches('.seg-field input[type="radio"]')) return;
  r.closest('.seg-field').querySelectorAll('.seg-btn').forEach(function (b) { b.classList.toggle('is-on', b.contains(r)); });
});
