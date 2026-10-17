<?php
/**
 * modules/ti_plan_public/ui/portalLayout.php — Szablon stron portalu planu zajęć TI w stylu „Plan lekcji Optivum”:
 * z lewej lista Grupy / Prowadzący / Sale, z prawej niebieski pasek z nazwą i plan (bez ramek; na telefonie lista rozwijana).
 *
 * Wszystkie adresy budowane od $o['self'] (ścieżka skryptu po przepisaniu, np. /karty30/ti/dydaktyk/planPubliczny.php),
 * bo strona bywa serwowana pod /plan/<token> i ścieżki względne by się rozjechały.
 */

/** Adres strony portalu: tiPlanPortalUrl($self, ['g' => token, 't' => '2026-10-19']). */
function tiPlanPortalUrl(string $self, array $q = []): string {
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return $self . ($q ? '?' . http_build_query($q, '', '&amp;') : '');
}

/**
 * Otwiera stronę. $o: title (tytuł karty), heading (pasek), sub (linia pod paskiem, opcjonalnie), self, org,
 * side ['grupy' => [[nazwa, href], …], 'prowadzacy' => […], 'sale' => […] | null], current (href bieżącej pozycji listy),
 * crumbs [[label, href|null], …] (opcjonalnie).
 */
function tiPlanPortalOpen(array $o): void {
    $org  = $o['org'] ?? ['name' => ''];
    $self = (string)$o['self'];
    $side = $o['side'] ?? [];
    $cur  = (string)($o['current'] ?? '');
    $orgName = trim((string)($org['name'] ?? ''));
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<title><?= h($o['title']) ?><?= $orgName !== '' ? ' — ' . h($orgName) : '' ?></title>
<style><?php readfile(__DIR__ . '/portal.css'); ?></style>
</head>
<body>
<a class="skip" href="#tresc">Przejdź do planu</a>
<div class="layout">
  <nav class="side" aria-label="Lista grup, prowadzących i sal">
    <button type="button" class="side-toggle" aria-expanded="false" aria-controls="sideBody" data-side-toggle>☰ Grupy, prowadzący, sale</button>
    <div class="side-body" id="sideBody" data-collapsed="1">
      <p class="org"><a href="<?= tiPlanPortalUrl($self) ?>"><?= h($orgName !== '' ? $orgName : 'Plan zajęć') ?></a><br>Plan zajęć</p>
      <?php $all = array_sum(array_map(fn($x) => is_array($x) ? count($x) : 0, $side)); if ($all > 12): ?>
      <label class="sr" for="sideSearch">Szukaj na liście</label>
      <input type="search" class="search" id="sideSearch" placeholder="szukaj…" autocomplete="off" data-side-search>
      <?php endif; ?>
      <?php foreach (['grupy' => 'Grupy', 'prowadzacy' => 'Prowadzący', 'sale' => 'Sale', 'dk' => 'DK — dyżury'] as $k => $lbl): if (!isset($side[$k]) || $side[$k] === null) continue; ?>
      <h2><a href="<?= tiPlanPortalUrl($self, ['k' => $k]) ?>" style="color:inherit;text-decoration:none"><?= $lbl ?></a></h2>
      <?php if (!$side[$k]): ?><p class="none">brak</p><?php else: ?>
      <ul>
        <?php foreach ($side[$k] as $it): ?>
        <li data-side-item><a href="<?= $it[1] ?>"<?= $it[1] === $cur ? ' aria-current="page"' : '' ?>><?= h($it[0]) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </nav>
  <main class="page" id="tresc">
    <div class="titlebar">
      <h1 class="title"><?= h($o['heading'] ?? $o['title']) ?></h1>
      <?php if (!empty($o['full'])): ?>
      <a class="login-btn" href="<?= tiPlanPortalUrl($self, ['logout' => 1]) ?>">Wyloguj</a>
      <?php else: ?>
      <a class="login-btn" href="<?= tiPlanPortalUrl($self, ['login' => 1]) ?>" title="Pełny plan dla pracowników (hasło od kierownika)">Zaloguj</a>
      <?php endif; ?>
    </div>
    <?php if (trim((string)($o['sub'] ?? '')) !== ''): ?><p class="subtitle"><?= $o['sub'] /* HTML z wywołującego (już escapowany) */ ?></p><?php endif; ?>
    <?php if (!empty($o['crumbs'])): ?>
    <nav class="crumbs" aria-label="Okruszki">
      <?php foreach ($o['crumbs'] as $i => $c): ?><?= $i ? ' / ' : '' ?><?php if ($c[1] !== null): ?><a href="<?= $c[1] ?>"><?= h($c[0]) ?></a><?php else: ?><strong aria-current="page"><?= h($c[0]) ?></strong><?php endif; ?><?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <div class="content">
    <?php if (!empty($o['full'])): ?>
    <div class="box note" role="note"><strong>Widok pełny</strong> (zalogowano hasłem kierownika): widoczne wszystkie grupy, pełne nazwiska i linki do zajęć online.
      Nie udostępniaj tego ekranu osobom postronnym. <a href="<?= tiPlanPortalUrl($self, ['logout' => 1]) ?>">Wyloguj</a></div>
    <?php endif; ?>
    <?php
}

/** Zamyka stronę: stopka + skrypty (lista na telefonie, szukanie na liście, wydruk, wybór tygodnia). */
function tiPlanPortalClose(array $o = []): void {
    $org = $o['org'] ?? ['name' => ''];
    ?>
    </div>
    <footer class="site-foot">
      <span>Plan może ulec zmianie — aktualna wersja zawsze pod tym adresem. Wygenerowano <?= h(date('d.m.Y H:i')) ?>.<?php
        $pu = trim((string)org_setting('ti_plan_public_privacy_url'));
        if ($pu !== '' && preg_match('#^https?://#i', $pu)): ?> <a href="<?= h($pu) ?>">Informacja o przetwarzaniu danych osobowych</a>.<?php endif; ?></span>
      <span><?= h(implode(' · ', array_filter([(string)($org['name'] ?? ''), (string)($org['phone'] ?? ''), (string)($org['email'] ?? '')]))) ?></span>
    </footer>
  </main>
</div>
<script>
(function () {
  var tg = document.querySelector('[data-side-toggle]'), body = document.getElementById('sideBody');
  if (tg && body) tg.addEventListener('click', function () {
    var open = body.getAttribute('data-collapsed') === '1';
    body.setAttribute('data-collapsed', open ? '0' : '1'); tg.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  var fold = function (s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/g, 'l'); };
  var ss = document.querySelector('[data-side-search]');
  if (ss) ss.addEventListener('input', function () {
    var q = fold(ss.value.trim());
    document.querySelectorAll('[data-side-item]').forEach(function (li) { li.hidden = q !== '' && fold(li.textContent).indexOf(q) === -1; });
  });
  document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });
  document.querySelectorAll('[data-week-select]').forEach(function (s) { s.addEventListener('change', function () { if (s.value) location.href = s.value; }); });
})();
</script>
</body>
</html>
<?php
}
