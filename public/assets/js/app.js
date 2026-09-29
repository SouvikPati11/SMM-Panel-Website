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
    var msg = form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    if (form.hasAttribute('data-no-lock')) return;
    $$('button[type="submit"], button:not([type])', form).forEach(function (b) {
      b.classList.add('is-loading');
      setTimeout(function () { b.classList.remove('is-loading'); }, 8000);
    });
  });
  $$('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form && el.form.submit(); });
  });
  $$('[data-check-all]').forEach(function (master) {
    master.addEventListener('change', function () {
      $$(master.getAttribute('data-check-all')).forEach(function (c) { c.checked = master.checked; });
    });
  });

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
  function money(micro) {
    var cfg = window.__currency || { symbol: '$', position: 'before', decimals: 2 };
    var f = fmtMicro(micro, cfg.decimals);
    return cfg.position === 'after' ? f + ' ' + cfg.symbol : cfg.symbol + f;
  }
  // Per-1000 rates keep up to 6 decimals (trailing zeros trimmed, min 2) — mirrors Money::formatRate().
  function rateFmt(micro) {
    var cfg = window.__currency || { symbol: '$', position: 'before' };
    var f = fmtMicro(micro, 6).replace(/0+$/, '');
    var dot = f.indexOf('.');
    while (f.length - dot - 1 < 2) f += '0';
    return cfg.position === 'after' ? f + ' ' + cfg.symbol : cfg.symbol + f;
  }
  var currencyEl = $('#currency-config');
  if (currencyEl) { try { window.__currency = JSON.parse(currencyEl.textContent); } catch (e) {} }

  // ------------------------------------------------------------ new order form
  var orderForm = $('#order-form');
  if (orderForm) {
    var data = JSON.parse(($('#services-data') || {}).textContent || '{"categories":[],"services":[]}');
    var byId = {};
    data.services.forEach(function (s) { byId[s.id] = s; });
    var catSel = $('#category'), svcSel = $('#service'), search = $('#service-search'), results = $('#search-results');
    var qty = $('#quantity'), link = $('#link'), chargeEl = $('#charge-amount');
    var summary = $('#svc-summary'), descEl = $('#svc-desc'), linkLabel = $('#link-label');
    var drip = $('#dripfeed'), runs = $('#runs'), interval = $('#interval');
    var descCache = {};

    function fillServices(catId, selectId) {
      svcSel.innerHTML = '';
      data.services.filter(function (s) { return String(s.c) === String(catId); }).forEach(function (s) {
        var o = document.createElement('option');
        o.value = s.id;
        o.textContent = s.id + ' — ' + s.n + ' — ' + rateFmt(toMicro(s.r)) + (s.pk ? '' : ' / 1000');
        svcSel.appendChild(o);
      });
      if (selectId) svcSel.value = selectId;
      onService();
    }

    function show(id, visible) { var el = $('#' + id); if (el) el.hidden = !visible; }

    function onService() {
      var s = byId[svcSel.value];
      $('#order-submit').disabled = !s;
      if (!s) { summary.hidden = true; return; }
      summary.hidden = false;
      $('#svc-rate').textContent = rateFmt(toMicro(s.r)) + (s.pk ? '' : ' / 1K');
      $('#svc-min').textContent = s.pk ? '—' : Number(s.mi).toLocaleString();
      $('#svc-max').textContent = s.pk ? '—' : Number(s.ma).toLocaleString();
      $('#svc-time').textContent = s.t || '—';
      var flags = $('#svc-flags'); flags.innerHTML = '';
      [[s.rf, 'Refill'], [s.cn, 'Cancel'], [s.df, 'Drip-feed']].forEach(function (f) {
        if (!f[0]) return;
        var b = document.createElement('span'); b.className = 'badge badge-success'; b.textContent = f[1]; flags.appendChild(b);
      });
      linkLabel.textContent = s.l || 'Link';
      link.placeholder = s.lt === 'url' ? 'https://' : (s.l || '');
      var type = s.ty;
      show('field-quantity', ['default', 'comment_likes', 'poll', 'keywords'].indexOf(type) >= 0);
      show('field-comments', type === 'custom_comments' || type === 'custom_comments_package');
      show('field-usernames', type === 'mentions_custom_list');
      show('field-username', type === 'comment_likes');
      show('field-answer', type === 'poll');
      show('field-keywords', type === 'keywords');
      show('field-dripfeed', !!s.df);
      if (!s.df && drip) { drip.checked = false; }
      show('dripfeed-fields', !!(drip && drip.checked));
      if (qty) { qty.min = s.mi; qty.max = s.ma; }
      $('#qty-hint').textContent = 'Min ' + Number(s.mi).toLocaleString() + ' · Max ' + Number(s.ma).toLocaleString();
      loadDescription(s.id);
      calc();
      try { history.replaceState(null, '', '?service=' + s.id); } catch (e) {}
    }

    function loadDescription(id) {
      if (descCache[id] !== undefined) { renderDesc(descCache[id]); return; }
      descEl.textContent = 'Loading…';
      fetch(orderForm.getAttribute('data-info-url').replace('__ID__', encodeURIComponent(id)), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : { description: '' }; })
        .then(function (j) { descCache[id] = j.description || ''; if (String(svcSel.value) === String(id)) renderDesc(descCache[id]); })
        .catch(function () { descEl.textContent = ''; });
    }
    function renderDesc(text) {
      descEl.textContent = text || 'No description provided for this service.';
    }

    function lines(id) {
      var el = $('#' + id); if (!el) return 0;
      return el.value.split(/\r?\n/).filter(function (l) { return l.trim() !== ''; }).length;
    }

    function calc() {
      var s = byId[svcSel.value];
      if (!s) { chargeEl.textContent = money(0n); return; }
      var rate = toMicro(s.r), total;
      var q = 0;
      if (s.ty === 'custom_comments' || s.ty === 'custom_comments_package') q = lines('comments');
      else if (s.ty === 'mentions_custom_list') q = lines('usernames');
      else if (s.ty === 'package') q = 1;
      else q = parseInt(qty.value, 10) || 0;
      if (s.ty === 'custom_comments' || s.ty === 'mentions_custom_list') $('#qty-count').textContent = q + ' line' + (q === 1 ? '' : 's');
      var r = drip && drip.checked ? Math.max(1, parseInt(runs.value, 10) || 1) : 1;
      if (s.pk) total = rate;
      else total = rate * BigInt(q) * BigInt(r) / 1000n;
      chargeEl.textContent = money(total);
      var bal = toMicro(orderForm.getAttribute('data-balance'));
      $('#charge-warning').hidden = total <= bal;
    }

    catSel.addEventListener('change', function () { fillServices(catSel.value); });
    svcSel.addEventListener('change', onService);
    ['input', 'change'].forEach(function (ev) {
      [qty, runs, $('#comments'), $('#usernames')].forEach(function (el) { if (el) el.addEventListener(ev, calc); });
    });
    if (drip) drip.addEventListener('change', function () { show('dripfeed-fields', drip.checked); calc(); });

    if (search) {
      search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        results.innerHTML = '';
        if (q.length < 2) { results.hidden = true; return; }
        var found = data.services.filter(function (s) { return String(s.id) === q || s.n.toLowerCase().indexOf(q) >= 0; }).slice(0, 40);
        results.hidden = false;
        if (!found.length) { results.innerHTML = '<div class="text-muted text-sm" style="padding:12px 14px">No services found</div>'; return; }
        found.forEach(function (s) {
          var b = document.createElement('button');
          b.type = 'button';
          var cat = data.categories.filter(function (c) { return String(c.id) === String(s.c); })[0];
          b.innerHTML = '<strong></strong><br><small></small>';
          b.querySelector('strong').textContent = s.id + ' — ' + s.n;
          b.querySelector('small').textContent = (cat ? cat.n + ' · ' : '') + rateFmt(toMicro(s.r)) + (s.pk ? '' : ' per 1000');
          b.addEventListener('click', function () {
            catSel.value = s.c; fillServices(s.c, s.id);
            results.hidden = true; search.value = '';
            link.focus();
          });
          results.appendChild(b);
        });
      });
    }

    // initial state
    var pre = orderForm.getAttribute('data-preselect');
    if (pre && byId[pre]) { catSel.value = byId[pre].c; fillServices(byId[pre].c, pre); }
    else if (catSel.value) { fillServices(catSel.value); }
  }

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
    };
    radios.forEach(function (r) { r.addEventListener('change', sync); });
    sync();
    $$('[data-coupon-check]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var form = btn.closest('form');
        var out = form.querySelector('.coupon-result');
        var body = new URLSearchParams({ code: form.querySelector('[name="coupon"]').value, amount: form.querySelector('[name="amount"]').value, _token: csrf });
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
