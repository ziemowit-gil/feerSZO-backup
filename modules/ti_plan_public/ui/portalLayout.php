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
    // Na hostach z aliasem /plan/ (ti*, szo*, kurs* — .htaccess) krótki adres zamiast …/planPubliczny.php?p=…
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (str_ends_with($self, '/planPubliczny.php') && preg_match('/^(ti|szo|kurs)[^.]*\./', $host)) {
        $rest  = array_diff_key($q, ['g' => 1, 'p' => 1, 's' => 1, 'k' => 1]);
        $pick  = array_intersect_key($q, ['g' => 1, 'p' => 1, 's' => 1, 'k' => 1]);
        $path  = null;
        $tok   = fn($v) => preg_match('/^[a-z0-9]{24}$/', (string)$v) === 1;
        if (!$pick) $path = '/plan/';
        elseif (count($pick) === 1 && isset($pick['g']) && $tok($pick['g'])) $path = '/plan/' . $pick['g'];
        elseif (count($pick) === 1 && isset($pick['p']) && $tok($pick['p'])) $path = '/plan/p/' . $pick['p'];
        elseif (count($pick) === 1 && isset($pick['s']) && preg_match('/^([0-9]+|online)$/', (string)$pick['s'])) $path = '/plan/s/' . $pick['s'];
        elseif (count($pick) === 1 && isset($pick['k']) && in_array($pick['k'], ['grupy', 'prowadzacy', 'sale', 'dk'], true)) $path = '/plan/' . $pick['k'];
        // Widok pełny (c=, u=), logowanie itp. zostają na adresie skryptu
        if ($path !== null && !array_intersect_key($rest, ['c' => 1, 'u' => 1, 'login' => 1, 'login_ti' => 1, 'logout' => 1])) {
            return $path . ($rest ? '?' . http_build_query($rest, '', '&amp;') : '');
        }
    }
    return $self . ($q ? '?' . http_build_query($q, '', '&amp;') : '');
}

/**
 * Wygląd portalu: 'metro' (domyślny, w interfejsie „Nowoczesny” — szata Metro UI jak SZO) albo 'klasyczny' (dotychczasowy „Plan lekcji Optivum”).
 * Wybór z ?wyglad= zapamiętany w ciasteczku na rok. Wygląd klasyczny jest tylko dla pracowników (prowadzący / kierownik z dostępem do
 * pełnego widoku, $allowClassic); kursanci i osoby bez logowania zawsze dostają Metro — niezależnie od ciasteczka i parametru.
 */
function tiPlanPortalSkin(bool $allowClassic = false): string {
    static $skin = null;
    if ($skin !== null) return $skin;
    if (!$allowClassic) return $skin = 'metro';
    $q = (string)($_GET['wyglad'] ?? '');
    if (in_array($q, ['metro', 'klasyczny'], true)) {
        $skin = $q;
        if (!headers_sent()) setcookie('tiplan_skin', $q, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
        return $skin;
    }
    $c = (string)($_COOKIE['tiplan_skin'] ?? '');
    return $skin = in_array($c, ['metro', 'klasyczny'], true) ? $c : 'metro';
}

/** Adres bieżącej strony z przełączonym wyglądem (zachowuje ścieżkę i pozostałe parametry). */
function tiPlanPortalSkinUrl(string $to): string {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string)parse_url($uri, PHP_URL_PATH);
    parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
    $q['wyglad'] = $to;
    return h($path . '?' . http_build_query($q));
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
<?php $allowClassic = !empty($o['canFull']) || !empty($o['full']); $skin = tiPlanPortalSkin($allowClassic); ?>
<style><?php readfile(__DIR__ . '/portal.css'); ?></style>
<?php if ($skin === 'metro'): ?><link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<?php /* Ikony (Font Awesome) ładowane leniwie: arkusz nie blokuje pierwszego renderu strony (media=print → all po załadowaniu); bez JS ładuje się normalnie (noscript) */ ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer"></noscript>
<style><?php readfile(__DIR__ . '/portal_metro.css'); ?></style><?php endif; ?>
</head>
<body class="skin-<?= $skin ?>">
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
      <?php // Sale: podział na stacjonarne i online (3. element pozycji = „zajęcia online”)
      $parts = $k === 'sale'
          ? ['Stacjonarnie' => array_filter($side[$k], fn($i) => empty($i[2])), 'Online' => array_filter($side[$k], fn($i) => !empty($i[2]))]
          : ['' => $side[$k]];
      foreach ($parts as $pn => $pitems): if (!$pitems) continue; ?>
      <?php if ($pn !== ''): ?><h3 class="side-sub" id="side-sale-<?= mb_strtolower($pn) ?>" style="font-size:.85em;margin:.5em 0 .1em;font-weight:600"><?= h($pn) ?></h3><?php endif; ?>
      <ul<?= $pn !== '' ? ' aria-labelledby="side-sale-' . mb_strtolower($pn) . '"' : '' ?>>
        <?php foreach ($pitems as $it): ?>
        <li data-side-item><a href="<?= $it[1] ?>"<?= $it[1] === $cur ? ' aria-current="page"' : '' ?>><?= h($it[0]) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php endforeach; ?>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </nav>
  <main class="page" id="tresc">
    <div class="titlebar">
      <h1 class="title"><?= h($o['heading'] ?? $o['title']) ?></h1>
      <?php if ($allowClassic): ?><a class="skin-btn" href="<?= tiPlanPortalSkinUrl($skin === 'metro' ? 'klasyczny' : 'metro') ?>" title="Przełącz wygląd planu (zapamiętany w tej przeglądarce)"><?= $skin === 'metro' ? 'Widok klasyczny' : 'Widok nowoczesny' ?></a><?php endif; ?>
      <?php $isFull = !empty($o['full']); $canFull = !empty($o['canFull']) || $isFull;
        $vu = fn(string $v) => htmlspecialchars(preg_replace('/([?&])widok=[^&]*&?/', '$1', (string)($_SERVER['REQUEST_URI'] ?? '/')), ENT_QUOTES) . ((str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ? '&amp;' : '?')) . 'widok=' . $v; // zostajemy na bieżącej stronie planu ?>
      <span class="view-switch" role="group" aria-label="Rodzaj widoku">
        <span class="view-switch-l">Widok:</span>
        <?php if ($isFull): ?><span class="view-opt is-on" aria-current="true">Pełny</span><?php else: ?>
        <a class="view-opt" href="<?= $canFull ? $vu('pelny') : tiPlanPortalUrl($self, ['login' => 1]) ?>" title="<?= $canFull ? 'Wróć do widoku pełnego' : 'Pełny plan dla pracowników (logowanie)' ?>">Pełny</a><?php endif; ?>
        <?php if (!$isFull): ?><span class="view-opt is-on" aria-current="true">Uproszczony</span><?php else: ?>
        <a class="view-opt" href="<?= $vu('uproszczony') ?>" title="Pokaż plan tak, jak widzą go osoby bez logowania">Uproszczony</a><?php endif; ?>
      </span>
      <?php // Stan zalogowania — osobno od rodzaju widoku: zalogowany może oglądać plan także w widoku uproszczonym ?>
      <span class="login-state <?= $canFull ? 'is-in' : 'is-out' ?>" role="status"><i class="<?= $canFull ? 'fa-solid fa-user-check' : 'fa-regular fa-user' ?>" aria-hidden="true"></i><?= $canFull ? 'Zalogowano' : 'Niezalogowany' ?></span>
      <?php if ($canFull): ?><a class="login-btn" href="<?= tiPlanPortalUrl($self, ['logout' => 1]) ?>">Wyloguj</a>
      <?php else: ?><a class="login-btn" href="<?= tiPlanPortalUrl($self, ['login' => 1]) ?>" title="Logowanie pracowników (pełny plan)">Zaloguj</a><?php endif; ?>
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
