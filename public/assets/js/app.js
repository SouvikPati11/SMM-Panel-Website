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
    function show(id, visible) { var el = $('#' + id); if (el) el.hidden = !visible; }
    function setIcon(el, key) { if (el) el.innerHTML = icons[key] || icons.other || ''; }

    // --- platform shortcuts: filter categories (and therefore services) by platform
    function applyPlatform(p) {
      platform = p;
      $$('.platform-chip').forEach(function (b) { var on = b.getAttribute('data-platform') === p; b.classList.toggle('active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      var first = null;
      $$('option', catSel).forEach(function (o) {
        var ok = !p || o.getAttribute('data-platform') === p;
        o.hidden = !ok; o.disabled = !ok;
        if (ok && first === null) first = o.value;
      });
      var cur = catSel.options[catSel.selectedIndex];
      if (!cur || cur.disabled) { catSel.value = first; }
      fillServices(catSel.value);
    }
    $$('.platform-chip').forEach(function (b) { b.addEventListener('click', function () { applyPlatform(b.getAttribute('data-platform')); }); });

    function fillServices(catId, selectId) {
      svcSel.innerHTML = '';
      var c = catById[catId];
      setIcon($('#category-icon'), c ? c.p : 'other');
      data.services.filter(function (s) { return String(s.c) === String(catId); }).forEach(function (s) {
        var o = document.createElement('option');
        o.value = s.id;
        o.textContent = s.id + ' — ' + s.n + ' — ' + rateFmt(toMicro(s.r)) + (s.pk ? '' : ' / 1000') + (s.sb ? ' · Subscription' : '');
        svcSel.appendChild(o);
      });
      if (selectId) svcSel.value = selectId;
      onService();
    }

    function onService() {
      var s = byId[svcSel.value];
      submitBtn.disabled = !s;
      if (!s) { summary.hidden = true; return; }
      summary.hidden = false;
      $('#svc-rate').textContent = rateFmt(toMicro(s.r)) + (s.pk ? '' : ' / 1K');
      $('#svc-min').textContent = s.pk ? '—' : Number(s.mi).toLocaleString();
      $('#svc-max').textContent = s.pk ? '—' : Number(s.ma).toLocaleString();
      $('#svc-time').textContent = s.t || '—';
      var flags = $('#svc-flags'); flags.innerHTML = '';
      [[s.rf, 'Refill'], [s.cn, 'Cancel'], [s.df, 'Drip-feed'], [s.sb, 'Subscription']].forEach(function (f) {
        if (!f[0]) return;
        var b = document.createElement('span'); b.className = 'badge badge-success'; b.textContent = f[1]; flags.appendChild(b);
      });
      linkLabel.textContent = s.l || 'Link';
      link.placeholder = s.lt === 'url' ? 'https://' : (s.l || '');
      var type = s.ty;
      show('field-order-type', !!s.sb);
      if (!s.sb) { var one = $('input[name="order_type"][value="single"]'); if (one) one.checked = true; }
      show('field-quantity', ['default', 'comment_likes', 'poll', 'keywords'].indexOf(type) >= 0);
      show('field-comments', type === 'custom_comments' || type === 'custom_comments_package');
      show('field-usernames', type === 'mentions_custom_list');
      show('field-username', type === 'comment_likes');
      show('field-answer', type === 'poll');
      show('field-keywords', type === 'keywords');
      if (qty) { qty.min = s.mi; qty.max = s.ma; }
      $('#qty-hint').textContent = 'Min ' + Number(s.mi).toLocaleString() + ' · Max ' + Number(s.ma).toLocaleString();
      onType();
      loadDescription(s.id);
      try { history.replaceState(null, '', '?service=' + s.id); } catch (e) {}
    }

    function onType() {
      var s = byId[svcSel.value], sub = isSub();
      show('field-subscription', sub);
      show('field-dripfeed', !!(s && s.df) && !sub);
      if ((!s || !s.df || sub) && drip) drip.checked = false;
      show('dripfeed-fields', !!(drip && drip.checked));
      $('#quantity-label').textContent = sub ? 'Quantity per delivery' : 'Quantity';
      $('#order-submit-label').textContent = sub ? 'Review subscription' : 'Review order';
      calc();
    }

    function loadDescription(id) {
      if (descCache[id] !== undefined) { renderDesc(descCache[id]); return; }
      descEl.innerHTML = '<span class="spinner spinner-sm" aria-hidden="true"></span> <span class="text-muted">Loading description…</span>';
      fetch(orderForm.getAttribute('data-info-url').replace('__ID__', encodeURIComponent(id)), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : { description: '' }; })
        .then(function (j) { descCache[id] = j.description || ''; if (String(svcSel.value) === String(id)) renderDesc(descCache[id]); })
        .catch(function () { if (String(svcSel.value) === String(id)) descEl.textContent = 'Description unavailable.'; });
    }
    function renderDesc(text) { descEl.textContent = text || 'No description provided for this service.'; }

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
      chargeEl.textContent = money(total);
      var sub = isSub(), cycles = Math.max(0, parseInt(($('#sub_cycles') || {}).value, 10) || 0);
      $('#charge-label').textContent = sub ? 'Charge per delivery' : 'Estimated charge';
      $('#charge-note').textContent = sub && cycles ? '× ' + cycles + ' deliveries ≈ ' + money(total * BigInt(cycles)) + ' in total' : '';
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
          if (!res.ok || !res.j.ok) { lastQuote = null; return { error: res.j.error || 'Could not calculate the price.' }; }
          lastQuote = res.j;
          chargeEl.textContent = res.j.subscription ? res.j.subscription.per_delivery : res.j.charge;
          if (res.j.subscription) $('#charge-note').textContent = '× ' + res.j.subscription.cycles + ' deliveries ≈ ' + res.j.subscription.estimated_total + ' in total';
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
      if (!s || !link.value.trim()) return; // the server needs the link to validate
      quoteTimer = setTimeout(function () {
        requestQuote().then(function (q) { if (q && q.error) { quoteErr.textContent = q.error; quoteErr.hidden = false; } });
      }, 450);
    }

    catSel.addEventListener('change', function () { fillServices(catSel.value); });
    svcSel.addEventListener('change', onService);
    ['input', 'change'].forEach(function (ev) {
      [qty, runs, interval, link, $('#comments'), $('#usernames'), $('#username'), $('#answer_number'), $('#keywords'), $('#sub_cycles'), $('#sub_interval')].forEach(function (el) { if (el) el.addEventListener(ev, calc); });
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
      row(dl, byId[svcSel.value] && byId[svcSel.value].l ? byId[svcSel.value].l : 'Link', q.link);
      row(dl, q.subscription ? 'Quantity per delivery' : 'Quantity', Number(q.quantity).toLocaleString() + (q.runs ? ' × ' + q.runs + ' runs' : ''));
      Object.keys(q.extra || {}).forEach(function (k) { if (k !== 'comments' && k !== 'usernames') row(dl, k.replace(/_/g, ' '), q.extra[k]); });
      row(dl, 'Rate', q.rate);
      if (q.subscription) {
        row(dl, 'Repeats', q.subscription.interval + ' · ' + q.subscription.cycles + ' deliveries');
        row(dl, 'Charged now', q.subscription.per_delivery);
        row(dl, 'Estimated total', q.subscription.estimated_total);
      } else {
        row(dl, 'Total charge', q.charge);
      }
      if (q.charge_base && q.charge_base !== q.charge) row(dl, 'Charged in account currency', q.charge_base);
      row(dl, 'Average time', q.service.average_time);
      row(dl, 'Refill / cancel', (q.service.refill ? 'Refill available' : 'No refill') + ' · ' + (q.service.cancel ? 'cancel available' : 'no cancel'));
      var notes = $('#confirm-notes'); notes.innerHTML = '';
      (q.notes || []).forEach(function (n) { var li = document.createElement('li'); li.textContent = n; notes.appendChild(li); });
      $('#confirm-insufficient').hidden = !q.insufficient;
      $('#confirm-error').hidden = true;
      confirmBtn.disabled = !!q.insufficient;
      $('#order-confirm-title').textContent = q.subscription ? 'Confirm your subscription' : 'Confirm your order';
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
          if (!res.ok) { $('#confirm-error').textContent = res.j.error || 'The order could not be placed.'; $('#confirm-error').hidden = false; confirmBtn.disabled = false; return; }
          var j = res.j;
          if (j.form_key) orderForm.querySelector('[name="form_key"]').value = j.form_key; // next order gets a fresh idempotency key
          $('#done-title').textContent = j.type === 'subscription' ? 'Subscription #' + j.subscription_id + ' created' : (j.ok ? 'Order #' + j.order_id + ' placed' : 'Order #' + j.order_id + ' was not accepted');
          $('#done-text').textContent = j.message || '';
          $('#done-view').href = j.url;
          $('.done-icon', dialog).classList.toggle('is-error', !j.ok);
          $('#confirm-review').hidden = true; $('#confirm-done').hidden = false;
          $('#order-confirm-title').textContent = j.ok ? 'Success' : 'Order not accepted';
          $('#done-view').focus();
        })
        .catch(function () {
          busy = false; confirmBtn.classList.remove('is-loading'); confirmBtn.disabled = false;
          $('#confirm-error').textContent = 'Network error. Check "My orders" before retrying — resubmitting the same form never creates a duplicate.';
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
        if (!found.length) { results.innerHTML = '<div class="text-muted text-sm" style="padding:12px 14px">No services found</div>'; return; }
        found.forEach(function (s) {
          var b = document.createElement('button');
          b.type = 'button';
          var cat = catById[s.c];
          b.innerHTML = '<span class="result-icon"></span><span class="result-text"><strong></strong><small></small></span>';
          setIcon(b.querySelector('.result-icon'), cat ? cat.p : 'other');
          b.querySelector('strong').textContent = s.id + ' — ' + s.n;
          b.querySelector('small').textContent = (cat ? cat.n + ' · ' : '') + rateFmt(toMicro(s.r)) + (s.pk ? '' : ' per 1000');
          b.addEventListener('click', function () {
            if (platform && cat && cat.p !== platform) applyPlatform('');
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
