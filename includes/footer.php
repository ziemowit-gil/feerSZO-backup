  </div><!-- /content -->

  <footer class="border-top py-2 px-4 text-muted small bg-white d-flex justify-content-between align-items-center">
    <span>Platforma NGO</span>
    <span class="d-flex align-items-center gap-3">
      <?php
        $_w5 = preg_split('/\s+/', trim(ORG_NAME));
        $_acr = count($_w5) >= 2 ? implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8'), $_w5)) : mb_substr(ORG_NAME,0,12,'UTF-8');
        echo '<span class="text-muted" style="opacity:.55;font-size:.78rem">' . h($_acr) . '</span>';
        try {
            require_once __DIR__ . '/version.php';
            $__fv = app_version();
            echo '<a href="' . APP_URL . '/admin/version.php" class="text-muted text-decoration-none" style="font-size:.72rem" title="commit: ' . htmlspecialchars($__fv['hash']) . ' · ' . htmlspecialchars($__fv['date']) . '">v' . htmlspecialchars($__fv['main']) . '</a>';
        } catch (\Throwable $e) {}
      ?>
      <span>&copy; <?= date('Y') ?> Ziemowit Gil | dev@ziemowit.me</span>
    </span>
  </footer>

</div><!-- /main -->

<?php
// Modal „Podpis elektroniczny" + walidacja — globalnie dla zalogowanych.
// Guard w partialu pomija render, jeśli widok dołączył go już samodzielnie.
if (!defined('EZD_SIG_MODAL_RENDERED') && function_exists('current_user') && current_user()) {
    require __DIR__ . '/ezd_sig_modal.php';
}
// Modal „Podgląd PDF" (wyskakujące okienko z iframe) — globalnie dla zalogowanych.
if (!defined('EZD_PDF_MODAL_RENDERED') && function_exists('current_user') && current_user()) {
    require __DIR__ . '/ezd_pdf_modal.php';
}
// Modal „Zapisz zmiany z Office Online" (nowa wersja / zastąp) — globalnie dla zalogowanych.
if (!defined('EZD_OFFICE_PULL_MODAL_RENDERED') && function_exists('current_user') && current_user()) {
    require __DIR__ . '/ezd_office_pull_modal.php';
}
?>

<?php require_once __DIR__ . '/chat_widget.php'; ?>
<script>
// Mobile sidebar toggle
const _tog = document.getElementById('sidebarToggle');
const _sb  = document.getElementById('sidebar');
if (_tog && _sb) _tog.addEventListener('click', () => _sb.classList.toggle('show'));

// ── Sidebar collapse — toggle .type-open on parent button ──────────────────
document.querySelectorAll('.sb-type-btn[data-bs-toggle="collapse"]').forEach(function(btn) {
  var target = document.querySelector(btn.dataset.bsTarget);
  if (!target) return;
  target.addEventListener('show.bs.collapse',  function() { btn.classList.add('type-open'); });
  target.addEventListener('hide.bs.collapse',  function() { btn.classList.remove('type-open'); });
});

// ── Automatyczny prefiks +48 dla pól .phone-48 ─────────────────────────────
(function() {
  function strip48(v) {
    var d = v.replace(/\D/g, '');
    if (d.length === 11 && d.startsWith('48')) return d.slice(2);
    return d.length === 9 ? d : v;
  }
  document.querySelectorAll('.phone-48').forEach(function(input) {
    // Usuń prefiks 48 z wartości zapisanej w bazie (wyświetlaj tylko 9 cyfr)
    input.value = strip48(input.value);
    // Przy wysyłaniu formularza doklejaj prefiks 48
    var frm = input.form || input.closest('form');
    if (frm) {
      frm.addEventListener('submit', function() {
        var d = input.value.replace(/\D/g, '');
        if (d.length === 9) input.value = '48' + d;
      });
    }
  });
})();
</script>
<script>
// ── Wykrywanie przeglądarki (SZO) ────────────────────────────────────────────
(function() {
  var KEY = 'szo_browser_ok_v1';
  if (sessionStorage.getItem(KEY)) return;
  var ua = navigator.userAgent;
  var msg = null;
  var sev = 'warn'; // 'warn' | 'block'

  if (/Vivaldi/i.test(ua)) {
    msg = '⚠️ <strong>Vivaldi</strong> nie jest oficjalnie wspierany — mogą wystąpić błędy wyświetlania i problemy z formularzami. Zalecamy <strong>Firefox</strong> lub <strong>Safari</strong>.';
    sev = 'block';
  } else if (/Trident\/|MSIE /i.test(ua)) {
    msg = '🚫 <strong>Internet Explorer</strong> nie jest obsługiwany. System może nie działać prawidłowo. Użyj <strong>Firefox</strong> lub <strong>Safari</strong>.';
    sev = 'block';
  } else if (/OPR\//i.test(ua)) {
    msg = '⚠️ <strong>Opera</strong> może powodować drobne problemy. Dla pewności użyj <strong>Firefox</strong> lub <strong>Safari</strong>.';
  } else if (navigator.brave !== undefined) {
    msg = '⚠️ <strong>Brave</strong> — agresywne blokowanie zasobów może utrudniać pracę z systemem. Polecamy <strong>Firefox</strong> lub <strong>Safari</strong>.';
  } else if (/SamsungBrowser/i.test(ua)) {
    msg = '⚠️ Używasz przeglądarki <strong>Samsung</strong> — dla lepszego doświadczenia polecamy <strong>Firefox</strong>.';
  }

  if (!msg) return;

  var bar = document.createElement('div');
  bar.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:9998;padding:.6rem 1.25rem;'
    + (sev === 'block'
        ? 'background:#7f1d1d;color:#fecaca;'
        : 'background:#1c1917;color:#d4d4d4;')
    + 'font-size:.8rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;'
    + 'box-shadow:0 -2px 10px rgba(0,0,0,.25);';
  bar.innerHTML = '<span style="flex:1;line-height:1.5">' + msg + '</span>'
    + '<div style="display:flex;gap:.4rem;flex-shrink:0">'
    + '<a href="https://www.mozilla.org/firefox/" target="_blank" rel="noopener" '
    + 'style="background:#ff9500;color:#fff;border-radius:5px;padding:.22rem .6rem;text-decoration:none;font-weight:600;font-size:.75rem">🦊 Firefox</a>'
    + '<a href="https://www.apple.com/safari/" target="_blank" rel="noopener" '
    + 'style="background:#0071e3;color:#fff;border-radius:5px;padding:.22rem .6rem;text-decoration:none;font-weight:600;font-size:.75rem">🧭 Safari</a>'
    + '<button onclick="this.closest(\'div[style]\').remove();sessionStorage.setItem(\'' + KEY + '\',\'1\')" '
    + 'style="background:none;border:1px solid rgba(255,255,255,.25);color:inherit;border-radius:5px;'
    + 'padding:.2rem .55rem;cursor:pointer;font-size:.75rem">OK, rozumiem</button>'
    + '</div>';
  document.body.appendChild(bar);
})();
</script>
</body>
</html>
