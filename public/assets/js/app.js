/* SMM Panel front-end. Vanilla JS, no dependencies, CSP-safe (no inline handlers).
   Prices shown here are previews only — the server always recalculates. */
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var csrf = ($('meta[name="csrf-token"]') || {}).content || '';

  // ------------------------------------------------------------ theme
  function currentTheme() {
    var set = document.documentElement.getAttribute('data-theme');
    if (set) return set;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  $$('[data-theme-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var next = currentTheme() === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('theme', next); } catch (e) {}
    });
  });

  // ------------------------------------------------------------ navigation
  $$('[data-nav-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var open = document.body.classList.toggle('nav-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  $$('.backdrop').forEach(function (b) {
    b.addEventListener('click', function () { document.body.classList.remove('nav-open'); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.body.classList.remove('nav-open');
  });
  // close dropdowns when clicking elsewhere
  document.addEventListener('click', function (e) {
    $$('details.dropdown[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });

  // ------------------------------------------------------------ flash messages
  $$('.flash-stack .alert').forEach(function (el, i) {
    setTimeout(function () { el.style.transition = 'opacity .4s'; el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 400); }, 6000 + i * 800);
    el.addEventListener('click', function () { el.remove(); });
  });

  // ------------------------------------------------------------ forms: confirm + double-submit guard
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (e.defaultPrevented) return; // e.g. the reCAPTCHA step below held the submit back
    var msg = form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    if (form.hasAttribute('data-no-lock')) return;
    // A second submit of the same form (double click, Enter) is dropped until the page changes.
    if (form.getAttribute('data-submitting') === '1') { e.preventDefault(); return; }
    form.setAttribute('data-submitting', '1');
    var btns = $$('button[type="submit"], button:not([type])', form);
    btns.forEach(function (b) { b.classList.add('is-loading'); b.setAttribute('aria-busy', 'true'); });
    setTimeout(function () {
      form.removeAttribute('data-submitting');
      btns.forEach(function (b) { b.classList.remove('is-loading'); b.removeAttribute('aria-busy'); });
    }, 8000);
  });
  // Back/forward cache restores the page as it was: unlock forms.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    $$('form[data-submitting]').forEach(function (f) { f.removeAttribute('data-submitting'); });
    $$('.is-loading').forEach(function (b) { b.classList.remove('is-loading'); b.removeAttribute('aria-busy'); });
  });
  $$('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form && el.form.submit(); });
  });
  $$('[data-check-all]').forEach(function (master) {
    master.addEventListener('change', function () {
      $$(master.getAttribute('data-check-all')).forEach(function (c) { c.checked = master.checked; });
    });
  });

  // ------------------------------------------------------------ simple tab switcher (API page examples)
  $$('[data-api-tab]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      var group = tab.parentNode;
      $$('[data-api-tab]', group).forEach(function (t) {
        var on = t === tab; t.classList.toggle('active', on); t.setAttribute('aria-selected', on ? 'true' : 'false');
        var panel = document.getElementById(t.getAttribute('data-api-tab')); if (panel) panel.hidden = !on;
      });
    });
  });

  // ------------------------------------------------------------ settings: sub-options follow their parent toggle
  $$('[data-show-if]').forEach(function (el) {
    var box = $('input[type="checkbox"][name="' + el.getAttribute('data-show-if') + '"]');
    if (!box) return;
    var sync = function () { el.hidden = !box.checked; };
    box.addEventListener('change', sync); sync();
  });

  // ------------------------------------------------------------ auth pages: password reveal, rules, loading label
  $$('.auth-card input[type="password"]').forEach(function (input) {
    var host = input.parentNode;
    if (!host.classList.contains('input-icon')) {
      host = document.createElement('div'); host.className = 'input-icon';
      input.parentNode.insertBefore(host, input); host.appendChild(input);
    }
    host.classList.add('pw-field');
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'pw-toggle'; btn.setAttribute('aria-label', 'Show password'); btn.setAttribute('aria-pressed', 'false');
    btn.setAttribute('aria-controls', input.id || '');
    var eye = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
    var off = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.6 10.6 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.2M6.6 6.6C3.9 8.4 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
    btn.innerHTML = eye;
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.innerHTML = show ? off : eye;
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      input.focus();
    });
    host.appendChild(btn);
  });
  $$('[data-strength]').forEach(function (pw) {
    var list = $(pw.getAttribute('data-strength')); if (!list) return;
    var confirm = $('[data-match="#' + pw.id + '"]');
    function upd() {
      var v = pw.value, c = confirm ? confirm.value : v;
      var ok = { len: v.length >= 8, letter: /[A-Za-z]/.test(v), digit: /\d/.test(v), match: v !== '' && v === c };
      $$('li[data-rule]', list).forEach(function (li) { li.classList.toggle('ok', !!ok[li.getAttribute('data-rule')]); });
      if (confirm) confirm.setCustomValidity(c !== '' && v !== c ? 'Passwords do not match.' : '');
    }
    pw.addEventListener('input', upd); if (confirm) confirm.addEventListener('input', upd); upd();
  });
  // reCAPTCHA (only rendered while enabled in Admin → Settings → Users). The server verifies the
  // token; this only avoids a round trip for an unticked box and fetches the invisible v3 token.
  $$('form[data-auth-form]').forEach(function (form) {
    var v2 = $('.g-recaptcha', form), v3 = $('[data-recaptcha-v3]', form), err = $('[data-recaptcha-error]', form);
    if (!v2 && !v3) return;
    form.addEventListener('submit', function (e) {
      if (v2) {
        var ok = window.grecaptcha && window.grecaptcha.getResponse && window.grecaptcha.getResponse() !== '';
        if (err) err.hidden = !!ok;
        if (!ok) { e.preventDefault(); }
        return;
      }
      if (v3.value) return; // token fetched: let the form go
      e.preventDefault();
      if (!window.grecaptcha || !window.grecaptcha.ready) { form.submit(); return; } // server answers with a clear error
      window.grecaptcha.ready(function () {
        window.grecaptcha.execute(v3.getAttribute('data-recaptcha-v3'), { action: v3.getAttribute('data-recaptcha-action') }).then(function (token) {
          v3.value = token;
          if (form.requestSubmit) form.requestSubmit(); else form.submit();
        }, function () { form.submit(); });
      });
    });
  });
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    $$('button[data-loading-text]', e.target).forEach(function (b) {
      var label = b.querySelector('span') || b;
      if (!b.hasAttribute('data-label')) b.setAttribute('data-label', label.textContent);
      label.textContent = b.getAttribute('data-loading-text');
    });
  });
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    $$('button[data-label]').forEach(function (b) { (b.querySelector('span') || b).textContent = b.getAttribute('data-label'); });
  });

  // ------------------------------------------------------------ admin service form: subscription settings
  (function () {
    var typeSel = $('[data-service-type]'), card = $('#sub-settings'), wrap = $('[data-sub-toggle]');
    if (!typeSel || !card) return;
    var toggle = wrap && $('input[type="checkbox"]', wrap);
    var modeSel = $('[data-sub-mode]');
    function sync() {
      var isType = typeSel.value === 'subscription';
      if (wrap) wrap.hidden = isType;
      card.hidden = !(isType || (toggle && toggle.checked));
      // Post-based subscriptions exist only for the "Subscriptions" type; "also allow" = scheduled repeats.
      var mode = isType && modeSel ? modeSel.value : 'scheduled';
      $$('[data-sub-type-only]', card).forEach(function (el) { el.hidden = !isType; });
      $$('[data-sub-panel]', card).forEach(function (el) { el.hidden = el.getAttribute('data-sub-panel') !== mode; });
    }
    typeSel.addEventListener('change', sync);
    if (modeSel) modeSel.addEventListener('change', sync);
    if (toggle) toggle.addEventListener('change', sync);
    sync();
  })();

  // ------------------------------------------------------------ copy to clipboard
  $$('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var target = btn.getAttribute('data-copy-target');
      if (target && $(target)) text = $(target).value || $(target).textContent;
      var done = function () {
        var old = btn.getAttribute('aria-label') || '';
        btn.classList.add('copied');
        var label = btn.querySelector('.copy-label');
        if (label) { var prev = label.textContent; label.textContent = 'Copied'; setTimeout(function () { label.textContent = prev; }, 1500); }
        setTimeout(function () { btn.classList.remove('copied'); btn.setAttribute('aria-label', old); }, 1500);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done);
      } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (err) {}
        ta.remove();
      }
    });
  });

  // ------------------------------------------------------------ dialogs
  $$('[data-open-dialog]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var d = document.getElementById(btn.getAttribute('data-open-dialog'));
      if (!d) return;
      // optional: fill fields from data-fill='{"name":"value"}'
      var fill = btn.getAttribute('data-fill');
      if (fill) {
        try {
          var data = JSON.parse(fill);
          $$('input[type="checkbox"]', d).forEach(function (c) { if (c.name.slice(-2) === '[]') c.checked = false; });
          Object.keys(data).forEach(function (k) {
            if (Array.isArray(data[k])) {
              $$('[name="' + k + '[]"]', d).forEach(function (c) { c.checked = data[k].map(String).indexOf(c.value) >= 0; });
              return;
            }
            var f = d.querySelector('[name="' + k + '"]');
            if (!f) return;
            if (f.type === 'radio') { $$('[name="' + k + '"]', d).forEach(function (r) { r.checked = r.value === String(data[k]); }); return; }
            if (f.type === 'checkbox') f.checked = !!Number(data[k]) || data[k] === true;
            else f.value = data[k] == null ? '' : data[k];
          });
        } catch (e) {}
      } else if (btn.hasAttribute('data-reset')) {
        var form = d.querySelector('form'); if (form) form.reset();
        var idf = d.querySelector('[name="id"]'); if (idf) idf.value = '';
      }
      if (d.showModal) d.showModal(); else d.setAttribute('open', '');
    });
  });
  $$('[data-close-dialog]').forEach(function (btn) {
    btn.addEventListener('click', function () { var d = btn.closest('dialog'); if (d) d.close(); });
  });
  // Deep links such as ?balance=add open the matching dialog once the page is ready.
  $$('[data-click-on-load]').forEach(function (b) { b.click(); });

  // ------------------------------------------------------------ exact decimal helpers (BigInt micro-units)
  var SCALE = 1000000n;
  function toMicro(str) {
    str = String(str || '0').trim();
    var neg = str.charAt(0) === '-'; if (neg) str = str.slice(1);
    var parts = str.split('.');
    var i = parts[0] || '0', f = (parts[1] || '').slice(0, 6);
    while (f.length < 6) f += '0';
    var v = BigInt(i) * SCALE + BigInt(f);
    return neg ? -v : v;
  }
  function fmtMicro(v, decimals) {
    decimals = decimals == null ? 2 : decimals;
    var neg = v < 0n; if (neg) v = -v;
    var div = 10n ** BigInt(6 - decimals);
    var r = (v + div / 2n) / div; // half-up
    var s = r.toString();
    while (s.length <= decimals) s = '0' + s;
    var ip = s.slice(0, s.length - decimals), fp = s.slice(s.length - decimals);
    ip = ip.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return (neg ? '-' : '') + ip + (decimals ? '.' + fp : '');
  }
  // Amounts arrive in the base currency (micro-units); display them in the user's
  // currency with the same exact rounding as the server (rate has 8 decimals).
  function toRate(str) {
    var p = String(str || '1').split('.'), f = (p[1] || '').slice(0, 8);
    while (f.length < 8) f += '0';
    return BigInt(p[0] || '0') * 100000000n + BigInt(f);
  }
  function convert(micro) {
    var cfg = window.__currency || {};
    if (!cfg.rate || String(cfg.rate) === '1') return micro;
    var r = toRate(cfg.rate), v = micro * r, neg = v < 0n; if (neg) v = -v;
    var out = (v + 50000000n) / 100000000n; // half-up back to micro-units
    return neg ? -out : out;
  }
  function money(micro) {
    var cfg = window.__currency || { symbol: '$', position: 'before', decimals: 2 };
    var f = fmtMicro(convert(micro), cfg.decimals);
    return cfg.position === 'after' ? f + ' ' + cfg.symbol : cfg.symbol + f;
  }
  // Per-1000 rates keep up to 6 decimals (trailing zeros trimmed, min 2) — mirrors Money::formatRate().
  function rateFmt(micro) {
    var cfg = window.__currency || { symbol: '$', position: 'before' };
    var f = fmtMicro(convert(micro), 6).replace(/0+$/, '');
    var dot = f.indexOf('.');
    while (f.length - dot - 1 < 2) f += '0';
    return cfg.position === 'after' ? f + ' ' + cfg.symbol : cfg.symbol + f;
  }
  var currencyEl = $('#currency-config');
  if (currencyEl) { try { window.__currency = JSON.parse(currencyEl.textContent); } catch (e) {} }

  // ------------------------------------------------------------ rich select (progressive enhancement)
  // Wraps a native <select>: the select stays in the form (and is what gets submitted);
  // a button + listbox shows icons, a second line, a price and badges from data-* attributes.
  // opt.icon(option) → HTML for the leading icon. Options with hidden/disabled are skipped.
  function richSelect(select, opt) {
    opt = opt || {};
    var uid = 'rs' + Math.random().toString(36).slice(2, 8);
    var wrap = document.createElement('div'); wrap.className = 'rs';
    var trigger = document.createElement('button');
    trigger.type = 'button'; trigger.className = 'rs-trigger';
    trigger.setAttribute('aria-haspopup', 'listbox'); trigger.setAttribute('aria-expanded', 'false');
    var lbl = select.id && document.querySelector('label[for="' + select.id + '"]');
    if (lbl) { lbl.id = lbl.id || uid + '-label'; trigger.setAttribute('aria-labelledby', lbl.id + ' ' + uid + '-value'); lbl.addEventListener('click', function (e) { e.preventDefault(); trigger.focus(); }); }
    var panel = document.createElement('div'); panel.className = 'rs-panel'; panel.hidden = true;
    var searchBox = null;
    if (opt.search) {
      searchBox = document.createElement('input');
      searchBox.type = 'search'; searchBox.className = 'input rs-search'; searchBox.placeholder = opt.search; searchBox.setAttribute('aria-label', opt.search);
      searchBox.setAttribute('aria-controls', uid + '-list'); searchBox.autocomplete = 'off';
      panel.appendChild(searchBox);
    }
    var list = document.createElement('ul'); list.className = 'rs-list'; list.id = uid + '-list'; list.setAttribute('role', 'listbox'); list.tabIndex = -1;
    if (lbl) list.setAttribute('aria-labelledby', lbl.id);
    panel.appendChild(list);
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select); wrap.appendChild(trigger); wrap.appendChild(panel);
    select.classList.add('rs-native'); select.tabIndex = -1; select.setAttribute('aria-hidden', 'true');
    var items = [], active = -1;

    function content(o, forTrigger) {
      var d = o.dataset, html = '';
      if (opt.icon) html += '<span class="rs-icon">' + opt.icon(o) + '</span>';
      html += '<span class="rs-text"><span class="rs-title"' + (forTrigger ? ' id="' + uid + '-value"' : '') + '></span>';
      if (d.sub) html += '<span class="rs-sub"></span>';
      html += '</span>';
      if (d.badge || d.meta) html += '<span class="rs-side">' + (d.meta ? '<span class="rs-meta"></span>' : '') + (d.badge ? '<span class="rs-badge"></span>' : '') + '</span>';
      return html;
    }
    function fill(el, o) {
      el.innerHTML = content(o, el === trigger);
      $('.rs-title', el).textContent = o.getAttribute('data-title') || o.textContent;
      if (o.dataset.sub) $('.rs-sub', el).textContent = o.dataset.sub;
      if (o.dataset.meta) $('.rs-meta', el).textContent = o.dataset.meta;
      if (o.dataset.badge) $('.rs-badge', el).textContent = o.dataset.badge;
    }
    function renderTrigger() {
      var o = select.options[select.selectedIndex];
      if (o && !o.disabled) { fill(trigger, o); trigger.classList.remove('is-empty'); }
      else { trigger.innerHTML = '<span class="rs-text"><span class="rs-title rs-placeholder" id="' + uid + '-value"></span></span>'; $('.rs-title', trigger).textContent = opt.empty || 'Nothing to choose'; trigger.classList.add('is-empty'); }
      trigger.disabled = !items.length && !select.options.length;
    }
    function build() {
      list.innerHTML = ''; items = [];
      var q = searchBox ? searchBox.value.trim().toLowerCase() : '';
      Array.prototype.forEach.call(select.options, function (o) {
        if (o.hidden || o.disabled) return;
        if (q && (o.textContent + ' ' + (o.dataset.sub || '') + ' ' + o.value).toLowerCase().indexOf(q) < 0) return;
        var li = document.createElement('li');
        li.className = 'rs-option'; li.setAttribute('role', 'option'); li.id = uid + '-o' + o.value;
        li.setAttribute('aria-selected', o.selected ? 'true' : 'false');
        fill(li, o);
        li.addEventListener('mousedown', function (e) { e.preventDefault(); });
        li.addEventListener('click', function () { choose(o.value); });
        list.appendChild(li); items.push({ li: li, o: o });
      });
      if (!items.length) {
        var empty = document.createElement('li'); empty.className = 'rs-empty'; empty.setAttribute('role', 'presentation');
        empty.textContent = q ? 'No matches for “' + q + '”' : (opt.empty || 'Nothing to choose');
        list.appendChild(empty);
      }
      setActive(Math.max(0, items.findIndex(function (it) { return it.o.selected; })));
    }
    function setActive(i) {
      if (!items.length) { active = -1; list.removeAttribute('aria-activedescendant'); return; }
      active = Math.max(0, Math.min(items.length - 1, i));
      items.forEach(function (it, k) { it.li.classList.toggle('is-active', k === active); });
      list.setAttribute('aria-activedescendant', items[active].li.id);
      if (searchBox) searchBox.setAttribute('aria-activedescendant', items[active].li.id);
      var li = items[active].li, top = li.offsetTop, bottom = top + li.offsetHeight;
      if (top < list.scrollTop) list.scrollTop = top; else if (bottom > list.scrollTop + list.clientHeight) list.scrollTop = bottom - list.clientHeight;
    }
    function open() {
      if (!panel.hidden || trigger.disabled) return;
      if (searchBox) searchBox.value = '';
      build(); panel.hidden = false; wrap.classList.add('is-open'); trigger.setAttribute('aria-expanded', 'true');
      // Focus the list, never the search box: on phones focusing an input opens the on-screen
      // keyboard. The keyboard appears only when the user taps the search box; on desktop,
      // typing while the list has focus moves the keystrokes into the search box (typeahead).
      list.focus({ preventScroll: true });
      setActive(active);
    }
    function close(focusTrigger) {
      if (panel.hidden) return;
      panel.hidden = true; wrap.classList.remove('is-open'); trigger.setAttribute('aria-expanded', 'false');
      if (focusTrigger) trigger.focus();
    }
    function choose(value) {
      var changed = select.value !== String(value);
      select.value = value; renderTrigger(); close(true);
      if (changed) select.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function keys(e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
      else if (e.key === 'Home' && !searchBox) { e.preventDefault(); setActive(0); }
      else if (e.key === 'End' && !searchBox) { e.preventDefault(); setActive(items.length - 1); }
      else if (e.key === 'Enter') { e.preventDefault(); if (items[active]) choose(items[active].o.value); }
      else if (e.key === 'Escape') { e.preventDefault(); close(true); }
      else if (e.key === 'Tab') { close(false); }
    }
    trigger.addEventListener('click', function () { panel.hidden ? open() : close(true); });
    trigger.addEventListener('keydown', function (e) { if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    list.addEventListener('keydown', function (e) {
      if (searchBox && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey && e.key !== ' ') {
        e.preventDefault();
        searchBox.value += e.key; searchBox.focus(); build();
        return;
      }
      if (searchBox && e.key === 'Backspace' && searchBox.value) { e.preventDefault(); searchBox.value = searchBox.value.slice(0, -1); searchBox.focus(); build(); return; }
      keys(e);
    });
    if (searchBox) { searchBox.addEventListener('keydown', keys); searchBox.addEventListener('input', function () { build(); }); }
    document.addEventListener('mousedown', function (e) { if (!wrap.contains(e.target)) close(false); });
    select.addEventListener('change', renderTrigger);
    var api = { refresh: function () { if (!panel.hidden) build(); renderTrigger(); }, open: open, close: close };
    new MutationObserver(function () { api.refresh(); }).observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'disabled'] });
    select._rich = api;
    renderTrigger();
    return api;
  }

  // ------------------------------------------------------------ new order form
  var orderForm = $('#order-form');
  if (orderForm) {
    var data = JSON.parse(($('#services-data') || {}).textContent || '{"categories":[],"services":[]}');
    var icons = {}; try { icons = JSON.parse(($('#platform-icons') || {}).textContent || '{}'); } catch (e) {}
    var byId = {}, catById = {};
    data.services.forEach(function (s) { byId[s.id] = s; });
    data.categories.forEach(function (c) { catById[c.id] = c; });
    var catSel = $('#category'), svcSel = $('#service'), search = $('#service-search'), results = $('#search-results');
    var qty = $('#quantity'), link = $('#link'), chargeEl = $('#charge-amount'), spinner = $('#charge-spinner');
    var summary = $('#svc-summary'), descEl = $('#svc-desc'), linkLabel = $('#link-label');
    var drip = $('#dripfeed'), runs = $('#runs'), interval = $('#interval');
    var submitBtn = $('#order-submit'), quoteErr = $('#quote-error');
    var dialog = $('#order-confirm'), confirmBtn = $('#confirm-submit');
    var descCache = {}, platform = '';
    var quoteSeq = 0, quoteTimer = null, quoteCtl = null, busy = false, lastQuote = null;

    function isSub() { var r = $('input[name="order_type"]:checked'); return !!r && r.value === 'subscription'; }
    // Post-based subscription (username / new & old posts / min-max / delay / expiry) for the selected service?
    function isPosts() { var s = byId[svcSel.value]; return !!s && s.sb && s.sm === 'posts' && isSub(); }
    function num(id) { var el = $('#' + id); return el ? Math.max(0, parseInt(el.value, 10) || 0) : 0; }
    function isoDate(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    function setupPosts(s) {
      var posts = $('#sub_posts'), old = $('#sub_old_posts'), mn = $('#sub_min'), mx = $('#sub_max'), dl = $('#sub_delay'), ex = $('#sub_expiry');
      if (!posts) return;
      posts.min = s.smi; posts.max = s.sma;
      if (num('sub_posts') < s.smi || num('sub_posts') > s.sma) posts.value = s.smi;
      $('#sub-posts-hint').textContent = s.smi + '–' + s.sma + ' posts';
      show('field-old-posts', s.om > 0);
      old.max = s.om; if (num('sub_old_posts') > s.om) old.value = 0;
      $('#sub-old-hint').textContent = s.om > 0 ? 'Up to ' + s.om + ' existing posts' : '';
      [mn, mx].forEach(function (el) { el.min = s.mi; el.max = s.ma; });
      if (!mn.value || num('sub_min') < s.mi || num('sub_min') > s.ma) mn.value = s.mi;
      if (!mx.value || num('sub_max') < s.mi || num('sub_max') > s.ma) mx.value = s.mi;
      $('#sub-qty-hint').textContent = 'Between ' + Number(s.mi).toLocaleString() + ' and ' + Number(s.ma).toLocaleString() + ' per post';
      var first = null;
      $$('option', dl).forEach(function (o) { var ok = (s.dl || []).indexOf(o.value) >= 0; o.hidden = !ok; o.disabled = !ok; if (ok && first === null) first = o.value; });
      if (dl.selectedOptions[0] && dl.selectedOptions[0].disabled) dl.value = first;
      var t = new Date(); t.setDate(t.getDate() + 1); ex.min = isoDate(t);
      if (s.ed) { var m = new Date(); m.setDate(m.getDate() + s.ed); ex.max = isoDate(m); $('#sub-expiry-hint').textContent = 'Up to ' + s.ed + ' days ahead (default: ' + s.ed + ' days).'; }
      else { ex.removeAttribute('max'); $('#sub-expiry-hint').textContent = 'Optional. The subscription stops on this date.'; }
      if (ex.value && ((ex.min && ex.value < ex.min) || (ex.max && ex.value > ex.max))) ex.value = '';
    }
    function show(id, visible) { var el = $('#' + id); if (el) el.hidden = !visible; }
    function setIcon(el, key) { if (el) el.innerHTML = icons[key] || icons.other || ''; }

    // --- platform shortcuts: filter categories (and therefore services) by platform
    // Cards filter by platform group (a card per main platform, everything else under "Other").
    function applyPlatform(p) {
      platform = p;
      $$('.pf-card').forEach(function (b) { var on = b.getAttribute('data-platform') === p; b.classList.toggle('active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      var first = null;
      $$('option', catSel).forEach(function (o) {
        var ok = !p || o.getAttribute('data-group') === p;
        o.hidden = !ok; o.disabled = !ok;
        if (ok && first === null) first = o.value;
      });
      var cur = catSel.options[catSel.selectedIndex];
      if (!cur || cur.disabled) { catSel.value = first; }
      fillServices(catSel.value);
    }
    $$('.pf-card').forEach(function (b) { b.addEventListener('click', function () { applyPlatform(b.getAttribute('data-platform')); }); });
    var platformSearch = $('#platform-search');
    if (platformSearch) {
      platformSearch.addEventListener('input', function () {
        var q = platformSearch.value.trim().toLowerCase(), shown = 0;
        $$('.pf-card').forEach(function (b) { var ok = !q || (b.getAttribute('data-name') || '').indexOf(q) >= 0; b.hidden = !ok; if (ok) shown++; });
        show('pf-empty', shown === 0);
      });
    }

    function fillServices(catId, selectId) {
      svcSel.innerHTML = '';
      var c = catById[catId];
      setIcon($('#category-icon'), c ? c.p : 'other');
      data.services.filter(function (s) { return String(s.c) === String(catId); }).forEach(function (s) {
        var o = document.createElement('option'), price = rateFmt(toMicro(s.r)) + (s.pk ? '' : ' / 1K');
        o.value = s.id;
        o.textContent = s.id + ' — ' + s.n + ' — ' + price + (s.sb ? ' · Subscription' : ''); // native fallback
        o.setAttribute('data-title', s.n);
        o.setAttribute('data-sub', '#' + s.id + (s.pk ? ' · Package' : ' · Min ' + Number(s.mi).toLocaleString() + ' · Max ' + Number(s.ma).toLocaleString()) + (s.rf ? ' · Refill' : ''));
        o.setAttribute('data-meta', price);
        if (s.sb) o.setAttribute('data-badge', s.so ? 'Subscription' : 'Auto-repeat');
        svcSel.appendChild(o);
      });
      if (selectId) svcSel.value = selectId;
      if (catSel._rich) catSel._rich.refresh();
      if (svcSel._rich) svcSel._rich.refresh();
      onService();
    }

    function onService() {
      var s = byId[svcSel.value];
      submitBtn.disabled = !s;
      if (!s) { summary.hidden = true; return; }
      summary.hidden = false;
      $('#svc-rate').textContent = rateFmt(toMicro(s.r)) + (s.pk ? ' per package' : '');
      $('#svc-min').textContent = s.pk ? '—' : Number(s.mi).toLocaleString();
      $('#svc-max').textContent = s.pk ? '—' : Number(s.ma).toLocaleString();
      $('#svc-time').textContent = s.t || '—';
      var flags = $('#svc-flags'); flags.innerHTML = '';
      [[s.rf, 'Refill'], [s.cn, 'Can be cancelled'], [s.df, 'Gradual delivery'], [s.sb, s.so ? 'Subscription only' : 'Can repeat']].forEach(function (f) {
        if (!f[0]) return;
        var b = document.createElement('span'); b.className = 'badge badge-success'; b.textContent = f[1]; flags.appendChild(b);
      });
      linkLabel.textContent = s.l || 'Link';
      link.placeholder = s.lt === 'url' ? 'https://' : (s.l || '');
      var type = s.ty;
      // Order type: one-time / subscription toggle; "Subscriptions" services are subscription-only.
      show('field-order-type', !!s.sb && !s.so);
      var forceType = !s.sb ? 'single' : (s.so ? 'subscription' : null);
      if (forceType) { var r = $('input[name="order_type"][value="' + forceType + '"]'); if (r) r.checked = true; }
      var subInt = $('#sub_interval'), subCyc = $('#sub_cycles');
      if (s.sb && subInt) {
        var allowed = s.si || [], firstOk = null;
        $$('option', subInt).forEach(function (o) { var ok = allowed.indexOf(o.value) >= 0; o.hidden = !ok; o.disabled = !ok; if (ok && firstOk === null) firstOk = o.value; });
        if (subInt.selectedOptions[0] && subInt.selectedOptions[0].disabled) subInt.value = allowed.indexOf('24') >= 0 ? '24' : firstOk;
      }
      if (s.sb && subCyc) {
        subCyc.min = s.smi; subCyc.max = s.sma;
        var v = parseInt(subCyc.value, 10) || 0;
        if (v < s.smi || v > s.sma) subCyc.value = Math.min(Math.max(v, s.smi), s.sma);
        $('#sub-cycles-hint').textContent = 'Between ' + s.smi + ' and ' + s.sma + ' times';
      }
      show('field-quantity', ['default', 'comment_likes', 'poll', 'keywords', 'subscription'].indexOf(type) >= 0);
      if (s.sm === 'posts') setupPosts(s);
      show('field-comments', type === 'custom_comments' || type === 'custom_comments_package');
      show('field-usernames', type === 'mentions_custom_list');
      show('field-username', type === 'comment_likes');
      show('field-answer', type === 'poll');
      show('field-keywords', type === 'keywords');
      if (qty) { qty.min = s.mi; qty.max = s.ma; }
      $('#qty-hint').textContent = 'Minimum ' + Number(s.mi).toLocaleString() + ' · Maximum ' + Number(s.ma).toLocaleString();
      onType();
      loadDescription(s.id);
      try { history.replaceState(null, '', '?service=' + s.id); } catch (e) {}
    }

    function onType() {
      var s = byId[svcSel.value], sub = isSub(), posts = isPosts();
      show('field-subscription', sub && !posts);
      // Post subscriptions replace link + quantity with their own fields (a disabled fieldset is not validated or sent).
      var fs = $('#field-subscription-posts'); if (fs) { fs.hidden = !posts; fs.disabled = !posts; }
      show('field-link', !posts); link.disabled = posts;
      if (s && ['default', 'comment_likes', 'poll', 'keywords', 'subscription'].indexOf(s.ty) >= 0) { show('field-quantity', !posts); if (qty) qty.disabled = posts; }
      show('field-dripfeed', !!(s && s.df) && !sub);
      if ((!s || !s.df || sub) && drip) drip.checked = false;
      show('dripfeed-fields', !!(drip && drip.checked));
      $('#quantity-label').textContent = sub && !posts ? 'Quantity each time' : 'Quantity';
      $('#order-submit-label').textContent = sub ? 'Review subscription' : 'Review order';
      calc();
    }

    function loadDescription(id) {
      if (descCache[id] !== undefined) { renderDesc(descCache[id]); return; }
      descEl.innerHTML = '<span class="spinner spinner-sm" aria-hidden="true"></span> <span class="text-muted">Loading details…</span>';
      fetch(orderForm.getAttribute('data-info-url').replace('__ID__', encodeURIComponent(id)), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : { description: '' }; })
        .then(function (j) { descCache[id] = j.description || ''; if (String(svcSel.value) === String(id)) renderDesc(descCache[id]); })
        .catch(function () { if (String(svcSel.value) === String(id)) descEl.textContent = 'Details could not be loaded. You can still place the order.'; });
    }
    function renderDesc(text) { descEl.textContent = text || 'No extra details for this service.'; }

    function lines(id) {
      var el = $('#' + id); if (!el) return 0;
      return el.value.split(/\r?\n/).filter(function (l) { return l.trim() !== ''; }).length;
    }

    // Instant local preview; the server quote below replaces it.
    function calc() {
      var s = byId[svcSel.value];
      if (!s) { chargeEl.textContent = money(0n); return; }
      var rate = toMicro(s.r), total, q = 0;
      if (s.ty === 'custom_comments' || s.ty === 'custom_comments_package') q = lines('comments');
      else if (s.ty === 'mentions_custom_list') q = lines('usernames');
      else if (s.ty === 'package') q = 1;
      else q = parseInt(qty.value, 10) || 0;
      if (s.ty === 'custom_comments' || s.ty === 'mentions_custom_list') $('#qty-count').textContent = q + ' line' + (q === 1 ? '' : 's');
      var r = drip && drip.checked ? Math.max(1, parseInt(runs.value, 10) || 1) : 1;
      total = s.pk ? rate : rate * BigInt(q) * BigInt(r) / 1000n;
      var sub = isSub(), cycles = Math.max(0, parseInt(($('#sub_cycles') || {}).value, 10) || 0);
      if (isPosts()) {
        // Reserve = max × (new + old posts) × rate; the server recalculates it and refunds what is unused.
        total = rate * BigInt(num('sub_max')) * BigInt(num('sub_posts') + num('sub_old_posts')) / 1000n;
        $('#charge-label').textContent = 'Held from your balance now (maximum)';
        $('#charge-note').textContent = 'You only pay for what is delivered; the rest comes back automatically.';
      } else {
        $('#charge-label').textContent = sub ? 'Price each time' : 'Price';
        $('#charge-note').textContent = sub && cycles ? '× ' + cycles + ' times ≈ ' + money(total * BigInt(cycles)) + ' in total' : '';
      }
      chargeEl.textContent = money(total);
      $('#charge-warning').hidden = total <= toMicro(orderForm.getAttribute('data-balance'));
      scheduleQuote();
    }

    // --- server quote (debounced; stale responses are ignored, in-flight requests aborted)
    function formBody() { return new URLSearchParams(new FormData(orderForm)); }
    function requestQuote() {
      var seq = ++quoteSeq;
      if (quoteCtl && quoteCtl.abort) quoteCtl.abort();
      quoteCtl = window.AbortController ? new AbortController() : null;
      spinner.hidden = false; chargeEl.classList.add('is-updating');
      return fetch(orderForm.getAttribute('data-quote-url'), { method: 'POST', body: formBody(), credentials: 'same-origin', signal: quoteCtl ? quoteCtl.signal : undefined, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf } })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (seq !== quoteSeq) return null; // a newer request superseded this one
          spinner.hidden = true; chargeEl.classList.remove('is-updating');
          if (!res.ok || !res.j.ok) { lastQuote = null; return { error: res.j.error || 'We could not work out the price. Check the fields above.' }; }
          lastQuote = res.j;
          chargeEl.textContent = res.j.subscription ? res.j.subscription.per_delivery : (res.j.posts ? res.j.posts.reserve : res.j.charge);
          if (res.j.subscription) $('#charge-note').textContent = '× ' + res.j.subscription.cycles + ' times ≈ ' + res.j.subscription.estimated_total + ' in total';
          $('#charge-warning').hidden = !res.j.insufficient;
          quoteErr.hidden = true;
          return res.j;
        })
        .catch(function (e) {
          if (e && e.name === 'AbortError') return null;
          if (seq === quoteSeq) { spinner.hidden = true; chargeEl.classList.remove('is-updating'); }
          return { error: 'Network error — please try again.' };
        });
    }
    function scheduleQuote() {
      clearTimeout(quoteTimer);
      var s = byId[svcSel.value];
      var target = isPosts() ? ($('#sub_username') || {}).value || '' : link.value;
      if (!s || !target.trim()) return; // the server needs the link / username to validate
      quoteTimer = setTimeout(function () {
        requestQuote().then(function (q) { if (q && q.error) { quoteErr.textContent = q.error; quoteErr.hidden = false; } });
      }, 450);
    }

    catSel.addEventListener('change', function () { fillServices(catSel.value); });
    svcSel.addEventListener('change', onService);
    ['input', 'change'].forEach(function (ev) {
      [qty, runs, interval, link, $('#comments'), $('#usernames'), $('#username'), $('#answer_number'), $('#keywords'), $('#sub_cycles'), $('#sub_interval'),
        $('#sub_username'), $('#sub_posts'), $('#sub_old_posts'), $('#sub_min'), $('#sub_max'), $('#sub_delay'), $('#sub_expiry')].forEach(function (el) { if (el) el.addEventListener(ev, calc); });
    });
    $$('input[name="order_type"]').forEach(function (r) { r.addEventListener('change', onType); });
    if (drip) drip.addEventListener('change', function () { show('dripfeed-fields', drip.checked); calc(); });

    // --- review → confirm → success
    function row(dl, label, value) {
      if (value === null || value === undefined || value === '') return;
      var dt = document.createElement('dt'), dd = document.createElement('dd');
      dt.textContent = label; dd.textContent = value; dl.appendChild(dt); dl.appendChild(dd);
    }
    function openConfirm(q) {
      var dl = $('#confirm-list'); dl.innerHTML = '';
      row(dl, 'Service', '#' + q.service.id + ' — ' + q.service.name);
      if (q.posts) {
        row(dl, 'Username', q.posts.username);
        row(dl, 'New posts', String(q.posts.posts));
        if (q.posts.old_posts) row(dl, 'Recent posts included', String(q.posts.old_posts));
        row(dl, 'Amount per post', q.posts.quantity);
        row(dl, 'Start after', q.posts.delay);
        row(dl, 'Ends on', q.posts.expiry);
        row(dl, 'Price', q.rate);
        row(dl, 'Price per post', q.posts.per_post);
        row(dl, 'Held now (maximum)', q.posts.reserve);
        if (q.charge_base && q.charge_base !== q.charge) row(dl, 'Taken from your balance', q.charge_base);
      } else {
      row(dl, byId[svcSel.value] && byId[svcSel.value].l ? byId[svcSel.value].l : 'Link', q.link);
      row(dl, q.subscription ? 'Quantity each time' : 'Quantity', Number(q.quantity).toLocaleString() + (q.runs ? ' × ' + q.runs + ' rounds' : ''));
      Object.keys(q.extra || {}).forEach(function (k) { if (k !== 'comments' && k !== 'usernames') row(dl, k.replace(/_/g, ' '), q.extra[k]); });
      row(dl, 'Price', q.rate);
      if (q.subscription) {
        row(dl, 'Repeats', q.subscription.interval + ' · ' + q.subscription.cycles + ' times');
        row(dl, 'Paid now', q.subscription.per_delivery);
        row(dl, 'Total if every repeat runs', q.subscription.estimated_total);
      } else {
        row(dl, 'Total to pay', q.charge);
      }
      if (q.charge_base && q.charge_base !== q.charge) row(dl, 'Taken from your balance', q.charge_base);
      }
      row(dl, 'Average time', q.service.average_time);
      row(dl, 'Refill & cancel', (q.service.refill ? 'Refill available' : 'No refill') + ' · ' + (q.service.cancel ? 'can be cancelled' : 'cannot be cancelled'));
      var notes = $('#confirm-notes'); notes.innerHTML = '';
      (q.notes || []).forEach(function (n) { var li = document.createElement('li'); li.textContent = n; notes.appendChild(li); });
      $('#confirm-insufficient').hidden = !q.insufficient;
      $('#confirm-error').hidden = true;
      confirmBtn.disabled = !!q.insufficient;
      $('#order-confirm-title').textContent = q.subscription || q.posts ? 'Check your subscription' : 'Check your order';
      var csl = $('#confirm-submit-label'); if (csl) csl.textContent = q.subscription || q.posts ? 'Start subscription' : 'Place order';
      $('#confirm-review').hidden = false; $('#confirm-done').hidden = true;
      if (dialog.showModal && !dialog.open) dialog.showModal(); else dialog.setAttribute('open', '');
      confirmBtn.focus();
    }

    orderForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (busy || submitBtn.disabled) return;
      if (!orderForm.reportValidity()) return;
      busy = true; submitBtn.classList.add('is-loading'); submitBtn.setAttribute('aria-busy', 'true');
      clearTimeout(quoteTimer);
      requestQuote().then(function (q) {
        busy = false; submitBtn.classList.remove('is-loading'); submitBtn.removeAttribute('aria-busy');
        if (!q) return;
        if (q.error) { quoteErr.textContent = q.error; quoteErr.hidden = false; quoteErr.scrollIntoView({ block: 'nearest' }); return; }
        openConfirm(q);
      });
    });

    confirmBtn.addEventListener('click', function () {
      if (busy) return; // never send twice (the server also dedupes on form_key)
      busy = true; confirmBtn.disabled = true; confirmBtn.classList.add('is-loading');
      fetch(orderForm.getAttribute('action'), { method: 'POST', body: formBody(), credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf } })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          busy = false; confirmBtn.classList.remove('is-loading');
          if (!res.ok) { $('#confirm-error').textContent = res.j.error || 'Your order could not be placed. Please try again.'; $('#confirm-error').hidden = false; confirmBtn.disabled = false; return; }
          var j = res.j;
          if (j.form_key) orderForm.querySelector('[name="form_key"]').value = j.form_key; // next order gets a fresh idempotency key
          $('#done-title').textContent = j.type === 'subscription' ? 'Subscription #' + j.subscription_id + ' started' : (j.ok ? 'Order #' + j.order_id + ' placed' : 'Order #' + j.order_id + ' could not start');
          $('#done-text').textContent = j.message || '';
          $('#done-view').href = j.url;
          $('#done-view').textContent = j.type === 'subscription' ? 'View subscription' : 'View order';
          $('.done-icon', dialog).classList.toggle('is-error', !j.ok);
          $('#confirm-review').hidden = true; $('#confirm-done').hidden = false;
          $('#order-confirm-title').textContent = j.ok ? 'All done' : 'Order not started';
          $('#done-view').focus();
        })
        .catch(function () {
          busy = false; confirmBtn.classList.remove('is-loading'); confirmBtn.disabled = false;
          $('#confirm-error').textContent = 'We could not reach the server. Check "My orders" before trying again: the same order is never placed twice.';
          $('#confirm-error').hidden = false;
        });
    });
    // After success the balance changed: start the next order from a fresh page (same service).
    $('#done-new').addEventListener('click', function () {
      window.location.assign(window.location.pathname + '?service=' + encodeURIComponent(svcSel.value));
    });
    dialog.addEventListener('close', function () { if (!$('#confirm-done').hidden) { window.location.reload(); } });

    if (search) {
      search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        results.innerHTML = '';
        if (q.length < 2) { results.hidden = true; return; }
        var found = data.services.filter(function (s) { return String(s.id) === q || s.n.toLowerCase().indexOf(q) >= 0; }).slice(0, 40);
        results.hidden = false;
        if (!found.length) { results.innerHTML = '<div class="text-muted text-sm" style="padding:12px 14px">No services match your search. Try a shorter word or a service ID.</div>'; return; }
        found.forEach(function (s) {
          var b = document.createElement('button');
          b.type = 'button';
          var cat = catById[s.c];
          b.innerHTML = '<span class="result-icon"></span><span class="result-text"><strong></strong><small></small></span>';
          setIcon(b.querySelector('.result-icon'), cat ? cat.p : 'other');
          b.querySelector('strong').textContent = s.id + ' — ' + s.n;
          b.querySelector('small').textContent = (cat ? cat.n + ' · ' : '') + rateFmt(toMicro(s.r)) + (s.pk ? '' : ' per 1000');
          b.addEventListener('click', function () {
            if (platform && cat && cat.g !== platform) applyPlatform('');
            catSel.value = s.c; fillServices(s.c, s.id);
            results.hidden = true; search.value = '';
            // Touch screens: hide the keyboard instead of jumping into the next input.
            if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) search.blur(); else link.focus();
          });
          results.appendChild(b);
        });
      });
    }

    // Rich pickers: platform icons, prices, min/max and badges in the lists (native selects stay in the form).
    richSelect(catSel, { icon: function (o) { return icons[o.getAttribute('data-platform')] || icons.other || ''; }, search: 'Find a category', empty: 'No categories for this platform yet' });
    richSelect(svcSel, { search: 'Find a service in this category', empty: 'No services in this category yet' });

    // initial state
    var pre = orderForm.getAttribute('data-preselect');
    if (pre && byId[pre]) { catSel.value = byId[pre].c; fillServices(byId[pre].c, pre); }
    else if (catSel.value) { fillServices(catSel.value); }
  }

  // ------------------------------------------------------------ admin platforms: live On/Off badge
  $$('.platform-table input[name^="enabled["]').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var tr = cb.closest('tr'), st = $('.platform-state', tr);
      if (tr) tr.classList.toggle('is-off', !cb.checked);
      if (st) st.innerHTML = cb.checked ? '<span class="badge badge-success">On</span>' : '<span class="badge badge-muted">Off</span>';
    });
  });

  // ------------------------------------------------------------ mass order line counter
  var mass = $('#mass-orders');
  if (mass) {
    var counter = $('#mass-count');
    var upd = function () { counter.textContent = mass.value.split(/\r?\n/).filter(function (l) { return l.trim(); }).length; };
    mass.addEventListener('input', upd); upd();
  }

  // ------------------------------------------------------------ add funds
  var fundsForm = $('#funds-page');
  if (fundsForm) {
    var radios = $$('input[name="method_select"]');
    var sync = function () {
      var sel = radios.filter(function (r) { return r.checked; })[0];
      var id = sel ? sel.value : '';
      var gateway = sel ? sel.getAttribute('data-gateway') : '';
      $$('.method-panel').forEach(function (p) { p.hidden = p.getAttribute('data-method') !== id; });
      var gwForm = $('#gateway-form'); // absent when no payment method is active yet
      if (gwForm) gwForm.hidden = !sel || gateway === 'manual';
      $$('[data-for-gateway]').forEach(function (f) {
        var on = f.getAttribute('data-for-gateway') === gateway;
        f.hidden = !on;
        $$('input', f).forEach(function (i) { i.required = on; i.disabled = !on; });
      });
      $$('.method-id').forEach(function (i) { i.value = id; });
      $$('.method-card').forEach(function (c) { c.classList.toggle('active', c.getAttribute('data-method') === id); });
      // Deposit limits belong to the selected gateway (the server enforces the same values).
      var amt = gwForm && $('input[name="amount"]', gwForm);
      if (amt && sel) {
        amt.min = sel.getAttribute('data-min'); amt.max = sel.getAttribute('data-max');
        amt.placeholder = sel.getAttribute('data-min');
        var hint = $('#gw-limits'); if (hint) hint.textContent = sel.getAttribute('data-limits') || '';
      }
    };
    // Expected gateway bonus (preview only: the server calculates and credits it on confirmation).
    var cents = function (v) { var n = parseFloat(v); return isFinite(n) ? Math.round(n * 100) : 0; };
    var bonusPreview = function () {
      var sel = radios.filter(function (r) { return r.checked; })[0];
      $$('.bonus-preview').forEach(function (out) {
        var form = out.closest('form');
        var amt = form && $('input[name="amount"]', form);
        var pct = sel && sel.getAttribute('data-bonus-pct');
        if (!amt || !pct) { out.hidden = true; return; }
        var a = cents(amt.value), fixed = cents(sel.getAttribute('data-bonus-fixed')), min = sel.getAttribute('data-bonus-min');
        var sym = (($('.input-prefix', form) || {}).textContent || '');
        var fmt = function (c) { return sym + (c / 100).toFixed(2); };
        if (a <= 0) { out.hidden = true; return; }
        out.hidden = false;
        if (min && a < cents(min)) {
          out.className = 'bonus-preview bonus-preview-muted';
          out.textContent = 'Deposit ' + fmt(cents(min)) + ' or more to get the deposit bonus.';
          return;
        }
        var bonus = Math.floor(a * parseFloat(pct) / 100) + fixed;
        out.className = 'bonus-preview';
        out.textContent = 'Deposit bonus: +' + fmt(bonus) + ' — you receive ' + fmt(a + bonus) + ' in total.';
      });
    };
    $$('input[name="amount"]', fundsForm).forEach(function (i) { i.addEventListener('input', bonusPreview); });
    // Promo codes can be limited to certain gateways: after switching gateway, re-check an entered
    // code (the server re-checks it again on submit) so an invalid code is never shown as applied.
    var recheckCoupon = function () {
      var gw = $('#gateway-form'), code = gw && $('[name="coupon"]', gw), out = gw && $('.coupon-result', gw), btn = gw && $('[data-coupon-check]', gw);
      if (!code || !out) return;
      out.textContent = ''; out.className = 'coupon-result hint';
      if (code.value.trim() && !gw.hidden && btn) btn.click();
    };
    radios.forEach(function (r) { r.addEventListener('change', sync); r.addEventListener('change', bonusPreview); r.addEventListener('change', recheckCoupon); });
    sync();
    bonusPreview();
    $$('[data-coupon-check]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var form = btn.closest('form');
        var out = form.querySelector('.coupon-result');
        var mid = form.querySelector('.method-id');
        var body = new URLSearchParams({ code: form.querySelector('[name="coupon"]').value, amount: form.querySelector('[name="amount"]').value, method_id: mid ? mid.value : '', _token: csrf });
        out.textContent = 'Checking…'; out.className = 'coupon-result hint';
        fetch(btn.getAttribute('data-coupon-check'), { method: 'POST', body: body, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf }, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (j) { out.textContent = j.message || j.error || ''; out.className = 'coupon-result hint ' + (j.ok ? 'text-success' : 'text-danger'); })
          .catch(function () { out.textContent = 'Could not check the code.'; });
      });
    });
  }

  // ------------------------------------------------------------ slug autofill (admin)
  $$('[data-slug-from]').forEach(function (slug) {
    var src = $(slug.getAttribute('data-slug-from'));
    if (!src) return;
    var touched = slug.value !== '';
    slug.addEventListener('input', function () { touched = true; });
    src.addEventListener('input', function () {
      if (touched) return;
      slug.value = src.value.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120);
    });
  });
})();
