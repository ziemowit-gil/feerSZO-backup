<?php
/**
 * modules/vcardy/view/index.php — /pomocne/vcardy: kontakty z Outlooka (Microsoft 365) do pliku VCF albo CSV i instrukcja importu.
 * Samodzielna strona bez menu SZO, wygląd METRO jak /pomocne/gosposia (style: modules/dhosting_migrator/view/_metro.php).
 * Zmienne: $user, $accounts (list<{upn, folders:?list<{id,name}>, error:''|'gone'|'down', copyDate, copyCount}>), $flash.
 *
 * WCAG 2.2 AA: grupy pól w <fieldset> z <legend>, etykiety <label>, komunikaty w role="status"/role="alert", fokus: żółta ramka 3 px,
 * cele dotyku ≥ 44 px, bez JS (zwykły formularz, plik pobiera przeglądarka). Teksty prostym językiem (humanizer).
 */
$org = org_setting('org_short_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'SZO');
$app = rtrim(APP_URL, '/');
$article = $app . '/uslugi-it/uslugi/microsoft-365/kontakty-z-outlooka';
$hd = $app . '/helpdesk/new.php';
$cloudDav = $app . '/kalendarz-kontakty';
$cloud = function_exists('org_setting') && org_setting('sso_cloud_url') ? rtrim((string)org_setting('sso_cloud_url'), '/') : 'https://nc.feer.org.pl';
$msOff = (function_exists('org_setting') && org_setting('sso_ms_off_date')) ? (string)org_setting('sso_ms_off_date') : '2026-11-20';
$msOffTxt = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $msOff, $m)
    ? (int)$m[3] . ' ' . (['', 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'][(int)$m[2]] ?? '') . ' ' . $m[1] . ' r.'
    : $msOff;
$n = 0;
?><!doctype html>
<html lang="pl" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Kontakty z Outlooka — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php include dirname(__DIR__, 2) . '/dhosting_migrator/view/_metro.php'; ?>
<style>
  .pvm fieldset{border:0;margin:0 0 1rem;padding:0;min-width:0}
  .pvm legend{font-weight:600;margin:0 0 .4rem;padding:0}
  .pvm .vc-opt{display:flex;gap:.6rem;align-items:flex-start;border:2px solid var(--pvm-line);padding:.6rem .8rem;margin:0 0 .5rem;cursor:pointer;min-height:44px}
  .pvm .vc-opt:has(input:checked){border-color:var(--m-blue);background:var(--pvm-light)}
  .pvm .vc-opt input{width:1.25rem;height:1.25rem;margin-top:.15rem;flex-shrink:0;accent-color:var(--m-blue)}
  .pvm .vc-opt input:focus-visible,.pvm select:focus-visible,.pvm .mt-btn:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
  .pvm .vc-opt strong{display:block}
  .pvm .vc-opt__t{display:block}
  .pvm .vc-opt__t>span{color:var(--pvm-muted);font-size:.95rem}
  .pvm select.dm-inp{min-height:44px}
  .pvm .vc-acc{border-left:8px solid var(--m-blue);padding:.2rem 0 .2rem 1rem;margin:0 0 1.5rem}
  .pvm .vc-howto h3{font-size:1.1rem;margin:1.2rem 0 .4rem}
  .pvm .vc-howto ol{margin:0 0 .8rem;padding-left:1.4rem}
  .pvm .vc-howto li{margin:0 0 .3rem}
</style>
</head>
<body>
<a class="skip" href="#tresc">Przejdź do treści</a>
<header class="dm-bar">
  <div class="dm-bar__in">
    <span class="dm-bar__org"><span class="sq" aria-hidden="true"><i class="bi bi-person-lines-fill"></i></span><?= h($org) ?></span>
    <span class="dm-bar__sp"></span>
    <span class="dm-bar__who"><?= h((string)($user['name'] ?? '')) ?></span>
    <a href="<?= h($article) ?>"><i class="bi bi-question-circle" aria-hidden="true"></i> Pomoc</a>
    <a href="<?= h($app . '/') ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do SZO</a>
  </div>
</header>

<main id="tresc" class="pvm dm">
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Pomocne narzędzia</div>
      <h1 class="mt-title">Kontakty z Outlooka</h1>
      <p class="mt-lead">Pobierz swoje kontakty z Outlooka do pliku. Potem wczytasz je do chmury Fundacji, do poczty dPoczta albo do telefonu.
        Microsoft 365 wyłączamy <?= h($msOffTxt) ?>, więc zrób to wcześniej.</p>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="dm-msg dm-msg--<?= h($flash['type']) ?>" role="<?= in_array($flash['type'], ['success', 'info'], true) ? 'status' : 'alert' ?>">
      <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle' : ($flash['type'] === 'info' ? 'bi-info-circle' : 'bi-exclamation-triangle') ?>" aria-hidden="true"></i><div><?= h($flash['msg']) ?></div>
    </div>
  <?php endif; ?>

  <section class="dm-sec" aria-labelledby="vcGetH">
    <h2 class="mt-h" id="vcGetH">Pobierz plik</h2>

  <?php if (!$accounts): ?>
    <div class="dm-msg dm-msg--warning" role="status"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      <div>Nie znaleźliśmy Twojego konta Microsoft 365. Jeśli miałeś skrzynkę w Outlooku, <a href="<?= h($hd) ?>">napisz do Helpdesku IT</a> i podaj jej adres.</div></div>
  <?php endif; ?>

  <?php foreach ($accounts as $a): $n++; $id = 'vc' . $n; ?>
    <div class="vc-acc">
      <p style="margin:0 0 .6rem"><i class="bi bi-microsoft" aria-hidden="true"></i> Konto Microsoft 365: <strong><?= h($a['upn']) ?></strong></p>

    <?php if ($a['error'] === ''): ?>
      <form method="post" action="<?= h(route('vcardy.download')) ?>">
        <?= Router::csrfField() ?>
        <input type="hidden" name="upn" value="<?= h($a['upn']) ?>">
        <input type="hidden" name="source" value="graph">
        <?php if (count((array)$a['folders']) > 1): ?>
          <label class="dm-fld" for="<?= $id ?>F">Które kontakty</label>
          <select class="dm-inp" id="<?= $id ?>F" name="folder" aria-describedby="<?= $id ?>FH">
            <option value="*">Wszystkie foldery</option>
            <?php foreach ($a['folders'] as $f): ?>
              <option value="<?= h($f['id']) ?>"><?= h($f['id'] === '' ? 'Kontakty (folder główny)' : $f['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="dm-help" id="<?= $id ?>FH">Nazwa folderu z Outlooka trafi do kategorii kontaktu.</p>
        <?php else: ?>
          <input type="hidden" name="folder" value="*">
        <?php endif; ?>
        <fieldset>
          <legend>Format pliku</legend>
          <label class="vc-opt"><input type="radio" name="format" value="vcf" checked>
            <span class="vc-opt__t"><strong>VCF (vCard)</strong><span>Do chmury Fundacji, telefonu i większości programów. Wybierz ten, jeśli nie wiesz, który.</span></span></label>
          <label class="vc-opt"><input type="radio" name="format" value="csv">
            <span class="vc-opt__t"><strong>CSV</strong><span>Do poczty dPoczta i do arkusza, na przykład Excela albo LibreOffice.</span></span></label>
        </fieldset>
        <button type="submit" class="mt-btn"><i class="bi bi-download" aria-hidden="true"></i> Pobierz kontakty</button>
        <p class="dm-help">Przy kilkuset kontaktach pobieranie trwa kilka sekund. Zdjęć kontaktów nie przenosimy.</p>
      </form>

    <?php elseif ($a['copyCount'] > 0): ?>
      <div class="dm-msg dm-msg--info" role="status"><i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>Microsoft 365 teraz nie odpowiada. Mamy kopię Twoich kontaktów z <?= h($a['copyDate']) ?> (kontaktów: <?= (int)$a['copyCount'] ?>). Możesz pobrać ją zamiast nich.</div></div>
      <form method="post" action="<?= h(route('vcardy.download')) ?>">
        <?= Router::csrfField() ?>
        <input type="hidden" name="upn" value="<?= h($a['upn']) ?>">
        <input type="hidden" name="source" value="copy">
        <fieldset>
          <legend>Format pliku</legend>
          <label class="vc-opt"><input type="radio" name="format" value="vcf" checked>
            <span class="vc-opt__t"><strong>VCF (vCard)</strong><span>Do chmury Fundacji, telefonu i większości programów.</span></span></label>
          <label class="vc-opt"><input type="radio" name="format" value="csv">
            <span class="vc-opt__t"><strong>CSV</strong><span>Do poczty dPoczta i do arkusza.</span></span></label>
        </fieldset>
        <button type="submit" class="mt-btn"><i class="bi bi-download" aria-hidden="true"></i> Pobierz kopię kontaktów</button>
      </form>

    <?php else: ?>
      <div class="dm-msg dm-msg--warning" role="status"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <div><?= $a['error'] === 'gone'
            ? 'Microsoft 365 nie ma już skrzynki dla tego konta i nie mamy kopii Twoich kontaktów.'
            : 'Microsoft 365 teraz nie odpowiada. Spróbuj za kilka minut.' ?>
          Jeśli to się powtarza, <a href="<?= h($hd) ?>">napisz do Helpdesku IT</a>.</div></div>
    <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </section>

  <section class="dm-sec vc-howto" aria-labelledby="vcHowH">
    <h2 class="mt-h" id="vcHowH">Co zrobić z plikiem</h2>
    <p>Wybierz jedno miejsce, w którym będziesz trzymać kontakty. Gdy wczytasz ten sam plik w dwa miejsca, będziesz mieć dwie osobne kopie,
      które same się nie zsynchronizują.</p>

    <h3>Chmura Fundacji (polecamy)</h3>
    <p>Kontakty w chmurze możesz potem mieć też w telefonie i w programie na komputerze. Zmiana w jednym miejscu pojawi się w pozostałych.</p>
    <ol>
      <li>Zaloguj się do <a href="<?= h($cloud) ?>">chmury Fundacji</a> i otwórz aplikację Kontakty z menu na górnym pasku.</li>
      <li>Na dole lewego panelu kliknij „Ustawienia kontaktów”.</li>
      <li>Kliknij „Importuj kontakty”, wybierz książkę adresową i wskaż pobrany plik VCF z komputera.</li>
    </ol>
    <p>Żeby mieć te kontakty w telefonie, połącz go z chmurą na stronie <a href="<?= h($cloudDav) ?>">Kalendarz i kontakty z chmury</a>.</p>

    <h3>Poczta dPoczta</h3>
    <ol>
      <li>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a> i przejdź do „Kontakty”.</li>
      <li>Kliknij ikonę chmurki do importu na górnym pasku. Podpowiedź pojawi się po najechaniu kursorem.</li>
      <li>Wskaż pobrany plik CSV albo VCF i kliknij „Importuj”.</li>
    </ol>
    <p>Kontakty w dPoczcie nie łączą się z chmurą ani z telefonem.</p>

    <h3>iPhone i iPad</h3>
    <ol>
      <li>Wyślij sobie plik VCF e-mailem albo zapisz go w aplikacji Pliki.</li>
      <li>Stuknij plik i wybierz „Dodaj wszystkie kontakty”.</li>
    </ol>

    <h3>Telefon z Androidem</h3>
    <ol>
      <li>Skopiuj plik VCF na telefon, na przykład wyślij go sobie e-mailem i zapisz.</li>
      <li>W aplikacji Kontakty otwórz menu, potem „Ustawienia” albo „Zarządzaj kontaktami”, i wybierz „Importuj”.</li>
      <li>Wskaż plik i konto, na którym mają się zapisać kontakty.</li>
    </ol>
    <p>Nazwy pozycji w menu różnią się trochę między telefonami.</p>

    <h3>Thunderbird i inne programy</h3>
    <p>W Thunderbirdzie otwórz Książkę adresową, wybierz „Narzędzia” i „Importuj”, a potem wskaż plik VCF albo CSV.
      Inne programy pocztowe zwykle mają podobną opcję importu kontaktów.</p>

    <div class="dm-msg dm-msg--warning" role="note"><i class="bi bi-shield-exclamation" aria-hidden="true"></i>
      <div>W pliku są dane osobowe innych osób. Nie wysyłaj go nikomu i nie zostawiaj na wspólnym komputerze. Gdy wczytasz kontakty, usuń plik z Pobranych i z Kosza.</div></div>
    <p>Coś nie działa? <a href="<?= h($hd) ?>">Napisz do Helpdesku IT</a>. Więcej w artykule <a href="<?= h($article) ?>">Kontakty z Outlooka do pliku</a>.</p>
  </section>
</main>
</body>
</html>
