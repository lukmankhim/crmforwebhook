// JS minimal: konfirmasi aksi berisiko, tombol salin, status loading form, dan field alasan lost.
(function () {
  'use strict';

  document.addEventListener('click', function (e) {
    var confirmBtn = e.target.closest('[data-confirm]');
    if (confirmBtn && !window.confirm(confirmBtn.getAttribute('data-confirm'))) {
      e.preventDefault();
      e.stopImmediatePropagation();
      return;
    }

    var copyBtn = e.target.closest('[data-copy]');
    if (copyBtn) {
      var text = copyBtn.getAttribute('data-copy');
      var original = copyBtn.textContent;
      var done = function (ok) {
        copyBtn.textContent = ok ? 'Tersalin' : 'Gagal, salin manual';
        setTimeout(function () { copyBtn.textContent = original; }, 1800);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
      } else {
        done(false);
      }
    }
  });

  // Cegah submit ganda: tombol dinonaktifkan dan diberi label proses.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.method.toLowerCase() !== 'post') return;
    var btn = e.submitter || form.querySelector('button[type="submit"]');
    if (!btn) {
      btn = document.querySelector('button[form="' + form.id + '"]');
    }
    if (btn) {
      setTimeout(function () {
        btn.disabled = true;
        btn.dataset.label = btn.textContent;
        btn.textContent = 'Memproses...';
      }, 0);
    }
  });

  // Kembalikan tombol saat halaman diambil dari bfcache (tombol back).
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    document.querySelectorAll('button[disabled][data-label]').forEach(function (b) {
      b.disabled = false;
      b.textContent = b.dataset.label;
    });
  });

  // Alasan lost hanya tampil dan wajib kalau stage tujuan berjenis lost.
  document.querySelectorAll('[data-stage-form]').forEach(function (form) {
    var select = form.querySelector('select[name="stage"]');
    var wrap = form.querySelector('[data-lost-reason]');
    var input = wrap.querySelector('input');
    var sync = function () {
      var isLost = select.options[select.selectedIndex].getAttribute('data-kind') === 'lost';
      wrap.hidden = !isLost;
      input.required = isLost;
    };
    select.addEventListener('change', sync);
    sync();
  });
})();
