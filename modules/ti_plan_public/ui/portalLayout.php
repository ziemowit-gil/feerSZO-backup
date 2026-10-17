<?php
/**
 * modules/ti_plan_public/ui/portalLayout.php — Szablon stron portalu planu zajęć TI (nagłówek, nawigacja, stopka).
 *
 * Wszystkie adresy budowane od $o['self'] (ścieżka skryptu po przepisaniu, np. /karty30/ti/dydaktyk/planPubliczny.php),
 * bo strona bywa serwowana pod /plan/<token> i ścieżki względne by się rozjechały.
 */

/** Adres strony portalu: tiPlanPortalUrl($self, ['g' => token, 't' => '2026-10-19']). */
function tiPlanPortalUrl(string $self, array $q = []): string {
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return $self . ($q ? '?' . http_build_query($q, '', '&amp;') : '');
}

/** Ikony SVG (stroke = currentColor). */
function tiPlanPortalIcon(string $name): string {
    $p = [
        'groups' => '<rect x="4" y="4.5" width="16" height="10" rx="1.7"/><path d="M7.5 19.5h9M10 14.5v5M14 14.5v5M8 8h8M9.5 10.5h5"/>',
        'person' => '<path d="M6 19c0-2.6 2.7-4.5 6-4.5s6 1.9 6 4.5"/><circle cx="12" cy="8" r="3.2"/>',
        'room'   => '<path d="M5 20V6.5C5 5.7 5.7 5 6.5 5H17a2 2 0 0 1 2 2v13M5 20h14M9 9h6M9 13h6"/><circle cx="16" cy="17" r=".7" fill="currentColor" stroke="none"/>',
        'cal'    => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/>',
        'sun'    => '<path d="M12 3.8v2.1M12 18.1v2.1M4.8 12h2.1M17.1 12h2.1M6.9 6.9l1.5 1.5M15.6 15.6l1.5 1.5M17.1 6.9l-1.5 1.5M8.4 15.6l-1.5 1.5"/><circle cx="12" cy="12" r="4.1"/>',
        'print'  => '<path d="M7 8V4h10v4M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2M7 14h10v6H7z"/>',
        'prev'   => '<path d="m15 6-6 6 6 6"/>',
        'next'   => '<path d="m9 6 6 6-6 6"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($p[$name] ?? '') . '</svg>';
}

/**
 * Otwiera stronę. $o: title, self, active ('' | grupy | prowadzacy | sale), crumbs [[label, url|null], …],
 * nav ['grupy' => bool, 'prowadzacy' => bool, 'sale' => bool], org (ti_org_contact_info()).
 */
function tiPlanPortalOpen(array $o): void {
    $org  = $o['org'] ?? ['name' => ''];
    $self = (string)$o['self'];
    $nav  = $o['nav'] ?? [];
    $orgName = trim((string)($org['name'] ?? ''));
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="pl" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<title><?= h($o['title']) ?><?= $orgName !== '' ? ' — ' . h($orgName) : '' ?></title>
<script>
(function () {   /* motyw przed renderem: zapisany wybór albo ustawienie systemu */
  var t = null; try { t = localStorage.getItem('tiPlan:theme'); } catch (e) {}
  if (t !== 'light' && t !== 'dark') t = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  document.documentElement.setAttribute('data-theme', t);
})();
</script>
<style><?php readfile(__DIR__ . '/portal.css'); ?></style>
</head>
<body>
<a class="skip" href="#tresc">Przejdź do treści</a>
<div class="wrap">
  <header class="masthead">
    <a class="brand" href="<?= tiPlanPortalUrl($self) ?>">
      <span class="brand-mark"><?= tiPlanPortalIcon('cal') ?></span>
      <span class="brand-copy"><small>Plan zajęć online</small><strong><?= h($orgName !== '' ? $orgName : 'Plan zajęć') ?></strong></span>
    </a>
    <button class="theme-toggle" type="button" data-theme-toggle aria-pressed="false">
      <?= tiPlanPortalIcon('sun') ?><span>Motyw: <strong data-theme-label>jasny</strong></span>
    </button>
  </header>
  <?php if (array_filter($nav)): ?>
  <nav class="modnav" aria-label="Widoki planu">
    <?php foreach (['grupy' => 'Grupy', 'prowadzacy' => 'Prowadzący', 'sale' => 'Sale'] as $k => $lbl): if (empty($nav[$k])) continue; ?>
      <a href="<?= tiPlanPortalUrl($self, ['k' => $k]) ?>"<?= ($o['active'] ?? '') === $k ? ' aria-current="page"' : '' ?>><?= $lbl ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <main class="page" id="tresc">
    <?php if (!empty($o['crumbs'])): ?>
    <nav class="crumbs" aria-label="Okruszki">
      <?php foreach ($o['crumbs'] as $i => $c): ?>
        <?= $i ? '<span aria-hidden="true">/</span>' : '' ?>
        <?php if ($c[1] !== null): ?><a href="<?= $c[1] ?>"><?= h($c[0]) ?></a><?php else: ?><strong aria-current="page"><?= h($c[0]) ?></strong><?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <?php endif;
}

/** Zamyka stronę: stopka + skrypty (motyw, wyszukiwarka katalogu, wydruk, skok do dnia). */
function tiPlanPortalClose(array $o = []): void {
    $org = $o['org'] ?? ['name' => ''];
    ?>
  </main>
  <footer class="site-foot">
    <span>Plan może ulec zmianie — aktualna wersja zawsze pod tym adresem. Stan na <?= h(date('d.m.Y H:i')) ?>.</span>
    <span><?= h(implode(' · ', array_filter([(string)($org['name'] ?? ''), (string)($org['phone'] ?? ''), (string)($org['email'] ?? '')]))) ?></span>
  </footer>
</div>
<script>
(function () {
  var root = document.documentElement;
  var btn = document.querySelector('[data-theme-toggle]');
  var lbl = document.querySelector('[data-theme-label]');
  function sync() { var d = root.getAttribute('data-theme') === 'dark'; if (lbl) lbl.textContent = d ? 'ciemny' : 'jasny'; if (btn) btn.setAttribute('aria-pressed', d ? 'true' : 'false'); }
  sync();
  if (btn) btn.addEventListener('click', function () {
    var t = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', t); try { localStorage.setItem('tiPlan:theme', t); } catch (e) {} sync();
  });
  /* Wyszukiwarka katalogu: bez polskich znaków i wielkości liter */
  var fold = function (s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/g, 'l'); };
  document.querySelectorAll('[data-search-root]').forEach(function (box) {
    var inp = box.querySelector('[data-search-input]'), items = box.querySelectorAll('[data-search-item]'), st = box.querySelector('[data-search-status]');
    if (!inp) return;
    inp.addEventListener('input', function () {
      var q = fold(inp.value.trim()), n = 0;
      items.forEach(function (it) { var ok = !q || fold(it.getAttribute('data-search-text')).indexOf(q) !== -1; it.hidden = !ok; if (ok) n++; });
      if (st) st.textContent = 'Wyników: ' + n;
    });
  });
  document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });
  /* Wybór tygodnia z listy */
  document.querySelectorAll('[data-week-select]').forEach(function (s) { s.addEventListener('change', function () { if (s.value) location.href = s.value; }); });
  /* Mobilnie: przewiń do dzisiejszego dnia */
  var today = document.querySelector('.day-list [data-today]');
  if (today && window.matchMedia('(max-width: 899px)').matches && !location.hash) today.scrollIntoView({ block: 'start' });
})();
</script>
</body>
</html>
<?php
}
