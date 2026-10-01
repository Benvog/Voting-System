// VoteMS: small progressive enhancements. Every page works without this file.
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  /* ---------- Theme ---------- */
  $$('[data-theme-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('theme', next); } catch (e) {}
    });
  });

  /* ---------- Mobile sidebar ---------- */
  var sidebar = $('#sidebar');
  var scrim = $('.scrim');
  function setSidebar(open) {
    if (!sidebar) return;
    sidebar.classList.toggle('is-open', open);
    scrim.classList.toggle('is-open', open);
  }
  $$('[data-open-sidebar]').forEach(function (b) { b.addEventListener('click', function () { setSidebar(true); }); });
  $$('[data-close-sidebar]').forEach(function (b) { b.addEventListener('click', function () { setSidebar(false); }); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setSidebar(false); });

  /* ---------- Confirm dialog for destructive forms ----------
     <form data-confirm="Message" data-confirm-title="Delete?" data-confirm-ok="Delete"> */
  var dialog = $('#confirm-dialog');
  $$('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (form.dataset.confirmed === '1') return;
      e.preventDefault();
      if (!dialog || typeof dialog.showModal !== 'function') {
        if (window.confirm(form.dataset.confirm)) { form.dataset.confirmed = '1'; form.submit(); }
        return;
      }
      $('#confirm-title').textContent = form.dataset.confirmTitle || 'Are you sure?';
      $('#confirm-message').textContent = form.dataset.confirm;
      var ok = $('#confirm-ok');
      ok.textContent = form.dataset.confirmOk || 'Confirm';
      ok.className = 'btn ' + (form.dataset.confirmTone === 'primary' ? 'btn-primary' : 'btn-danger');
      dialog.returnValue = '';
      dialog.showModal();
      dialog.addEventListener('close', function onClose() {
        dialog.removeEventListener('close', onClose);
        if (dialog.returnValue === 'ok') { form.dataset.confirmed = '1'; form.submit(); }
      });
    });
  });

  /* ---------- Countdown: <div class="countdown" data-countdown="2026-10-01T18:00:00"> ---------- */
  $$('[data-countdown]').forEach(function (el) {
    var end = new Date(el.dataset.countdown).getTime();
    var parts = { d: $('[data-unit="d"]', el), h: $('[data-unit="h"]', el), m: $('[data-unit="m"]', el), s: $('[data-unit="s"]', el) };
    function pad(n) { return n < 10 ? '0' + n : String(n); }
    function tick() {
      var diff = end - Date.now();
      if (diff <= 0) {
        el.outerHTML = '<p class="muted">Voting time is up. Refresh the page for the latest status.</p>';
        clearInterval(timer);
        return;
      }
      parts.d.textContent = pad(Math.floor(diff / 86400000));
      parts.h.textContent = pad(Math.floor(diff % 86400000 / 3600000));
      parts.m.textContent = pad(Math.floor(diff % 3600000 / 60000));
      parts.s.textContent = pad(Math.floor(diff % 60000 / 1000));
      el.classList.toggle('is-urgent', diff < 3600000);
    }
    var timer = setInterval(tick, 1000);
    tick();
  });

  /* ---------- Copy to clipboard: <button data-copy="text"> ---------- */
  $$('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!navigator.clipboard) return;
      navigator.clipboard.writeText(btn.dataset.copy).then(function () {
        var label = btn.getAttribute('aria-label');
        btn.setAttribute('aria-label', 'Copied');
        btn.classList.add('is-copied');
        setTimeout(function () { btn.setAttribute('aria-label', label); btn.classList.remove('is-copied'); }, 1500);
      });
    });
  });

  /* ---------- Select that submits its form on change: <select data-autosubmit> ---------- */
  $$('select[data-autosubmit]').forEach(function (sel) {
    sel.addEventListener('change', function () { sel.form.submit(); });
  });

  /* ---------- Download a table as CSV: <button data-download-csv="#table" data-filename="x.csv"> ---------- */
  $$('[data-download-csv]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var table = $(btn.dataset.downloadCsv);
      var csv = $$('tr', table).map(function (tr) {
        return $$('th, td', tr).map(function (cell) {
          return '"' + cell.textContent.trim().replace(/"/g, '""') + '"';
        }).join(',');
      }).join('\r\n');
      var url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
      var a = document.createElement('a');
      a.href = url;
      a.download = btn.dataset.filename || 'export.csv';
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    });
  });

  /* ---------- Auto refresh: <span data-autorefresh="30"> shows seconds left ---------- */
  $$('[data-autorefresh]').forEach(function (el) {
    var left = parseInt(el.dataset.autorefresh, 10);
    setInterval(function () {
      left -= 1;
      el.textContent = left;
      if (left <= 0) window.location.reload();
    }, 1000);
  });

  /* ---------- Line chart hover: crosshair snaps to the nearest point ----------
     <div class="chart" data-points='[[x, y, "label", "value"], ...]'> around an SVG
     whose viewBox uses the same x/y units. */
  $$('.chart[data-points]').forEach(function (chart) {
    var points = JSON.parse(chart.dataset.points);
    var svg = $('svg', chart);
    var cross = $('.cross', chart);
    var dot = $('.cross-dot', chart);
    var tip = $('.tooltip', chart);
    var vb = svg.viewBox.baseVal;
    if (!points.length) return;

    // The SVG scales with its card; keep axis text at 11px and dots at 4.5px on screen.
    function rescale() {
      var k = vb.width / svg.getBoundingClientRect().width;
      $$('.tick', svg).forEach(function (t) { t.style.fontSize = (11 * k) + 'px'; });
      $$('.end-dot, .cross-dot', svg).forEach(function (c) { c.setAttribute('r', 4.5 * k); c.style.strokeWidth = 2 * k; });
    }
    rescale();
    if (window.ResizeObserver) new ResizeObserver(rescale).observe(chart);

    function show(i) {
      var p = points[i];
      cross.setAttribute('x1', p[0]); cross.setAttribute('x2', p[0]);
      dot.setAttribute('cx', p[0]); dot.setAttribute('cy', p[1]);
      tip.textContent = '';
      var b = document.createElement('b'); b.textContent = p[3];
      var s = document.createElement('span'); s.textContent = p[2];
      tip.appendChild(b); tip.appendChild(s);
      var scale = svg.getBoundingClientRect().width / vb.width;
      var left = p[0] * scale + 12;
      if (left + tip.offsetWidth > chart.clientWidth) left = p[0] * scale - tip.offsetWidth - 12;
      tip.style.left = left + 'px';
      tip.style.top = Math.max(0, p[1] * scale - 24) + 'px';
      chart.classList.add('is-hover');
      tip.classList.add('is-on');
    }
    function hide() { chart.classList.remove('is-hover'); tip.classList.remove('is-on'); }
    function nearest(clientX) {
      var r = svg.getBoundingClientRect();
      var x = (clientX - r.left) / r.width * vb.width;
      var best = 0;
      points.forEach(function (p, i) { if (Math.abs(p[0] - x) < Math.abs(points[best][0] - x)) best = i; });
      return best;
    }
    svg.addEventListener('pointermove', function (e) { show(nearest(e.clientX)); });
    svg.addEventListener('pointerleave', hide);
    // Keyboard: focus the chart, then arrow through points.
    var idx = points.length - 1;
    chart.addEventListener('focus', function () { show(idx); });
    chart.addEventListener('blur', hide);
    chart.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') idx = Math.min(points.length - 1, idx + 1);
      else if (e.key === 'ArrowLeft') idx = Math.max(0, idx - 1);
      else return;
      e.preventDefault();
      show(idx);
    });
  });

  /* ---------- Ballot stepper ----------
     One <fieldset class="ballot-step"> per position, then a review step.
     Without JS every step shows at once and the form still submits. */
  var ballot = $('#ballot');
  if (ballot) {
    var steps = $$('.ballot-step', ballot);
    var bars = $$('.progress-steps span');
    var count = $('.step-count');
    var current = 0;

    function go(i) {
      current = Math.max(0, Math.min(steps.length - 1, i));
      steps.forEach(function (s, n) { s.classList.toggle('is-current', n === current); });
      bars.forEach(function (b, n) {
        b.classList.toggle('is-done', n < current);
        b.classList.toggle('is-current', n === current);
      });
      var isReview = steps[current].hasAttribute('data-review');
      if (count) count.textContent = isReview ? 'Last step' : 'Position ' + (current + 1) + ' of ' + (steps.length - 1);
      if (isReview) fillReview();
      var first = $('input:checked', steps[current]) || $('input, button', steps[current]);
      window.scrollTo({ top: 0, behavior: 'smooth' });
      if (first) first.focus({ preventScroll: true });
    }

    function fillReview() {
      steps.forEach(function (s) {
        if (s.hasAttribute('data-review')) return;
        var row = $('[data-review-for="' + s.dataset.position + '"]', ballot);
        if (!row) return;
        var picked = $('input:checked', s);
        var pick = $('.pick', row);
        var none = !picked || picked.value === '0';
        pick.textContent = none ? 'Skipped for now' : picked.dataset.label;
        pick.classList.toggle('is-none', none);
      });
    }

    ballot.addEventListener('click', function (e) {
      var next = e.target.closest('[data-next]');
      var prev = e.target.closest('[data-prev]');
      var jump = e.target.closest('[data-jump]');
      if (next) {
        if (!$('input:checked', steps[current])) {
          var err = $('.step-error', steps[current]);
          if (err) err.hidden = false;
          return;
        }
        go(current + 1);
      }
      if (prev) go(current - 1);
      if (jump) go(parseInt(jump.dataset.jump, 10));
    });
    ballot.addEventListener('change', function (e) {
      var err = $('.step-error', e.target.closest('.ballot-step'));
      if (err) err.hidden = true;
    });
    go(0);
  }
})();
