<?php
/**
 * edok/przelewy_rachunki.php — przelewy za rachunki do umów zlecenie (bez obiegu EODoK).
 * Zatwierdzanie rachunków jest papierowe; tu tylko wybór rachunków i plik dla banku (kwota NETTO).
 * Logika: modules/edok_przelewy_rachunki/logic/przelewyRachunki.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';
require_once __DIR__ . '/../includes/approval.php';
require_once __DIR__ . '/../modules/edok_przelewy_rachunki/logic/przelewyRachunki.php';

edok_require_access();
edok_migrate();
if (!is_admin() && !edok_has_role('zatwierdza')) {
    flash_set('danger', 'Przelewy za rachunki: dostęp ma administrator i osoba zatwierdzająca do zapłaty.');
    header('Location: ' . APP_URL . '/edok/index.php');
    exit;
}
prz_rach_migrate();

// Pobranie pliku z historii.
if (isset($_GET['plik'])) {
    $h = db_one("SELECT * FROM zlecenie_rachunki_przelewy WHERE id = ?", [(int)$_GET['plik']]);
    $abs = $h && $h['plik_path'] ? rtrim(UPLOAD_DIR, '/') . '/' . $h['plik_path'] : '';
    if (!$abs || !is_file($abs)) { flash_set('warning', 'Nie ma zapisanego pliku tego eksportu.'); header('Location: przelewy_rachunki.php'); exit; }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename((string)$h['plik_nazwa']) . '"');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}

$_okres_in = (string)($_POST['okres'] ?? $_GET['okres'] ?? '');
// Domyślnie wszystkie niezapłacone — filtr miesiąca ukrywał rachunki wystawione za inny okres.
$ym = preg_match('/^\d{4}-\d{2}$/', $_okres_in) ? $_okres_in : '';
$zapl = !empty($_POST['zaplacone']) || !empty($_GET['zaplacone']); // pokaż też zapłacone (do przekazania do EODoK z opisem)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'do_edok') {
    csrf_check();
    $rids = (array)($_POST['rid'] ?? []);
    if (!$rids) {
        flash_set('warning', 'Zaznacz co najmniej jeden rachunek do przekazania.');
    } else {
        $w = prz_rach_do_edok($rids, (int)(current_user()['id'] ?? 0) ?: null, (string)($_POST['powod_zaplacony'] ?? ''));
        if ($w['ok']) flash_set('success', 'Przekazano do EODoK: ' . $w['ok'] . ' ' . ($w['ok'] === 1 ? 'rachunek' : 'rachunków') . ' (obieg papierowy — dokumenty są do zapłaty).');
        if ($w['bledy']) flash_set('warning', 'Nie przekazano: ' . implode(' · ', $w['bledy']));
    }
    header('Location: przelewy_rachunki.php?okres=' . urlencode($ym === '' ? 'wszystkie' : $ym) . ($zapl ? '&zaplacone=1' : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_edok') {
    csrf_check();
    $w = prz_rach_import_edok((int)(current_user()['id'] ?? 0) ?: null);
    $msg = $w['ok'] ? 'Zaimportowano z systemu do EODoK: ' . $w['ok'] . ' ' . ($w['ok'] === 1 ? 'rachunek' : 'rachunków') . ' (typ „Rachunek do umowy zlecenie”, okres od 10/2026, zatwierdzone automatycznie — obieg papierowy).' : '';
    $nie = array_merge($w['bledy'], $w['pominiete']);
    if ($nie) flash_set('warning', ($msg ? $msg . ' ' : '') . 'Nie zaimportowano: ' . implode(' · ', $nie));
    else flash_set($w['ok'] ? 'success' : 'info', $msg ?: 'Brak nowych rachunków do zaimportowania (okres od 10/2026).');
    header('Location: przelewy_rachunki.php?okres=' . urlencode($ym === '' ? 'wszystkie' : $ym) . ($zapl ? '&zaplacone=1' : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generuj') {
    csrf_check();
    $res = prz_rach_generuj(
        (array)($_POST['rid'] ?? []),
        (string)($_POST['rachunek_zlecen'] ?? ''),
        (string)($_POST['format'] ?? 'auto'),
        !empty($_POST['oznacz_zaplacone']),
        !empty($_POST['potwierdz_ponowne']),
        $ym,
        current_user()
    );
    if (!$res['ok']) {
        flash_set('warning', $res['msg']);
        header('Location: przelewy_rachunki.php?okres=' . urlencode($ym === '' ? 'wszystkie' : $ym));
        exit;
    }
    flash_set('success', $res['msg'] . ' Plik pobrał się automatycznie — znajdziesz go też w historii poniżej.');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $res['plik']['nazwa'] . '"');
    echo $res['plik']['content'];
    exit;
}

$rows   = prz_rach_lista($ym, $zapl);
$konta  = edok_rachunki_list();
$hist   = prz_rach_historia();
$money  = fn($v) => number_format((float)$v, 2, ',', ' ');
$domyslne_konto = '';
foreach ($konta as $k) if (substr(preg_replace('/\D/', '', (string)$k['nrb']), 2, 4) === '1020') { $domyslne_konto = preg_replace('/\D/', '', (string)$k['nrb']); break; }
if ($domyslne_konto === '' && $konta) $domyslne_konto = preg_replace('/\D/', '', (string)$konta[0]['nrb']);
$do_wyboru = count(array_filter($rows, fn($r) => $r['problem'] === null));

$PAGE_TITLE = 'Generuj paczki przelewów — rachunki do umów';
require_once __DIR__ . '/../includes/header.php';
?>
<style type="text/tailwindcss">
  .prz-card  { @apply tw-rounded-xl tw-border tw-border-solid tw-border-slate-200 tw-bg-white tw-p-4 tw-shadow-sm sm:tw-p-5; }
  .prz-input { @apply tw-rounded-lg tw-border tw-border-solid tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm tw-outline-none focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100; }
  .prz-label { @apply tw-mb-1 tw-block tw-text-sm tw-font-medium tw-text-slate-700; }
  .prz-step  { @apply tw-mr-1 tw-inline-flex tw-h-6 tw-w-6 tw-items-center tw-justify-center tw-rounded-full tw-bg-blue-600 tw-text-xs tw-font-semibold tw-text-white; }
  .prz-bar   { @apply tw-sticky tw-bottom-0 tw-z-10 tw--mx-4 tw-mt-5 tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-rounded-b-xl tw-border-0 tw-border-t tw-border-solid tw-border-slate-200 tw-bg-white tw-px-4 tw-py-3 sm:tw--mx-5 sm:tw-px-5; }
  .prz-table th { @apply tw-bg-slate-50 tw-px-3 tw-py-2 tw-text-left tw-text-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-slate-500; }
  .prz-table td { @apply tw-border-0 tw-border-t tw-border-solid tw-border-slate-100 tw-px-3 tw-py-2 tw-align-top tw-text-sm; }
</style>

<div class="tw-mx-auto tw-max-w-6xl">
  <div class="tw-mb-4">
    <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-slate-500">EODoK · Płatności</p>
    <h1 class="tw-m-0 tw-mt-1 tw-text-2xl tw-font-semibold tw-text-slate-900"><i class="bi bi-file-earmark-arrow-down tw-mr-1 tw-text-blue-600" aria-hidden="true"></i> Generuj paczki przelewów — wynagrodzenia</h1>
    <p class="tw-m-0 tw-mt-1 tw-max-w-3xl tw-text-sm tw-text-slate-500">Rachunki do umów zlecenie zatwierdzone na papierze. Przelew idzie z kwoty <strong>netto</strong>, tytuł: „WYNAGRODZENIE MM/RRRR - umowa nr …”. Wszystkie zaznaczone rachunki trafiają do jednego pliku.</p>
  </div>

  <!-- Krok 1: rachunki z systemu -->
  <section class="prz-card tw-mb-4" aria-labelledby="przKrok1">
    <div class="tw-flex tw-flex-wrap tw-items-end tw-justify-between tw-gap-3">
      <h2 id="przKrok1" class="tw-m-0 tw-text-base tw-font-semibold tw-text-slate-900"><span class="prz-step">1</span> Rachunki do zapłaty</h2>
      <div class="tw-flex tw-flex-wrap tw-items-end tw-gap-3">
        <form method="get" class="tw-flex tw-flex-wrap tw-items-end tw-gap-2">
          <div>
            <label for="okres" class="prz-label">Okres</label>
            <input id="okres" name="okres" type="month" class="prz-input" value="<?= h($ym) ?>">
          </div>
          <label class="tw-flex tw-items-center tw-gap-1 tw-pb-2 tw-text-sm"><input type="checkbox" class="form-check-input" name="zaplacone" value="1" <?= $zapl ? 'checked' : '' ?>> zapłacone</label>
          <button class="btn btn-outline-secondary btn-sm" type="submit">Pokaż</button>
          <a class="btn btn-link btn-sm" href="?okres=wszystkie">Wszystkie niezapłacone</a>
        </form>
        <form method="post" class="tw-m-0">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="okres" value="<?= h($ym === '' ? 'wszystkie' : $ym) ?>">
          <?php if ($zapl): ?><input type="hidden" name="zaplacone" value="1"><?php endif; ?>
          <button type="submit" name="action" value="import_edok" class="btn btn-outline-primary btn-sm" title="Importuje z systemu rachunki do umów zlecenie z okresem od 10/2026, których nie ma jeszcze w EODoK (typ „Rachunek do umowy zlecenie”); zatwierdzane automatycznie — obieg papierowy"><i class="bi bi-cloud-download me-1" aria-hidden="true"></i> Importuj rachunki od 10/2026</button>
        </form>
      </div>
    </div>
  </section>

  <form method="post" id="przForm" class="prz-card">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="okres" value="<?= h($ym === '' ? 'wszystkie' : $ym) ?>">
    <?php if ($zapl): ?><input type="hidden" name="zaplacone" value="1"><?php endif; ?>

    <?php if (!$rows): ?>
      <p class="tw-m-0 tw-text-sm tw-text-slate-600">Brak rachunków <?= $ym ? 'za ' . h(edok_okres_label($ym)) : 'do zapłaty' ?>. Użyj „Importuj rachunki od 10/2026”, jeśli w systemie są nowe.</p>
    <?php else: ?>
    <div class="tw-mb-3 tw-grid tw-gap-3 md:tw-grid-cols-3" role="search" aria-label="Filtrowanie listy rachunków">
      <div class="md:tw-col-span-2">
        <label for="przQ" class="prz-label">Szukaj (osoba, umowa RU, numer rachunku)</label>
        <input id="przQ" type="search" class="prz-input tw-w-full" autocomplete="off">
      </div>
      <div>
        <label for="przStan" class="prz-label">Pokaż</label>
        <select id="przStan" class="prz-input tw-w-full">
          <option value="">Wszystkie</option>
          <option value="gotowe">Gotowe do przelewu</option>
          <option value="plik">Już w pliku przelewów</option>
          <option value="problem">Z problemem</option>
          <option value="brak">Nie ma w EODoK</option>
          <option value="zaplacony">Zapłacone</option>
        </select>
      </div>
    </div>
    <p class="tw-m-0 tw-mb-2 tw-text-xs tw-text-slate-500" aria-live="polite">Widoczne: <strong id="przWidoczne"><?= count($rows) ?></strong> z <?= count($rows) ?>. „Zaznacz wszystkie” i suma dotyczą tylko widocznych wierszy.</p>
    <div class="tw-overflow-x-auto">
      <table class="prz-table tw-w-full">
        <caption class="tw-sr-only">Rachunki do umów zlecenie do zapłaty</caption>
        <thead><tr>
          <th scope="col"><input type="checkbox" class="form-check-input" id="przAll" aria-label="Zaznacz wszystkie do wyboru" <?= $do_wyboru ? '' : 'disabled' ?>></th>
          <th scope="col">Zleceniobiorca / umowa</th>
          <th scope="col">Rachunek / okres</th>
          <th scope="col" class="tw-text-right">Do wypłaty (netto)</th>
          <th scope="col">Konto odbiorcy</th>
          <th scope="col">Stan</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $ok = $r['problem'] === null; ?>
          <?php $_stan = [$r['status'] === 'zaplacony' ? 'zaplacony' : '', !empty($r['edok']) ? 'edok' : 'brak', !empty($r['przelew_id']) ? 'plik' : '', $ok ? 'gotowe' : 'problem'];
                $_hay = mb_strtolower($r['imie_nazwisko'] . ' ' . $r['umowa_ref'] . ' ' . $r['numer'] . ' ' . ($r['edok']['number'] ?? '')); ?>
          <tr class="prz-row <?= $ok ? '' : 'tw-text-slate-400' ?>" data-hay="<?= h($_hay) ?>" data-stan="<?= h(implode(' ', array_filter($_stan))) ?>" data-ru="<?= h(mb_strtolower($r['umowa_ref'])) ?>">
            <td><input type="checkbox" class="form-check-input prz-cb" name="rid[]" value="<?= (int)$r['id'] ?>"
                   data-kwota="<?= h((string)(float)($r['kwota_netto'] ?? 0)) ?>" <?php $_zp = $r['status'] === 'zaplacony' && $r['do_edok_ok']; ?><?= $_zp ? 'data-zapl="1"' : '' ?> <?= $ok ? (empty($r['ponowny']) ? 'checked' : '') : ($_zp ? '' : 'disabled') ?>
                   aria-label="<?= h($r['imie_nazwisko'] . ' ' . $r['numer']) ?>"></td>
            <td><a href="<?= APP_URL ?>/contracts/zlecenie/view.php?id=<?= (int)$r['umowa_id'] ?>&amp;tab=rachunki" class="tw-font-medium"><?= h($r['imie_nazwisko']) ?></a>
                <div class="tw-text-xs tw-text-slate-500"><?= $r['umowa_ref'] !== '' ? 'umowa ' . h($r['umowa_ref']) : 'umowa techniczna (bez numeru)' ?></div></td>
            <td><div class="tw-font-mono tw-text-xs"><?= h($r['numer'] ?: '#' . $r['id']) ?></div>
                <div class="tw-text-xs tw-text-slate-500"><?= h((string)($r['okres'] ?: '—')) ?></div></td>
            <td class="tw-whitespace-nowrap tw-text-right"><div class="tw-font-semibold"><?= ($r['kwota_netto'] ?? null) !== null ? $money($r['kwota_netto']) : '—' ?></div>
                <div class="tw-text-xs tw-text-slate-500">brutto <?= $r['kwota_brutto'] !== null ? $money($r['kwota_brutto']) : '—' ?></div></td>
            <td class="tw-whitespace-nowrap tw-font-mono tw-text-xs"><?= strlen($r['nrb']) === 26 ? h(edok_nrb_format($r['nrb'])) : '<span class="tw-text-red-700">brak</span>' ?></td>
            <td class="tw-text-xs">
              <?= rachunek_status_badge((string)$r['status']) ?>
              <?php if ($r['problem']): ?><div class="tw-mt-1 tw-font-semibold tw-text-red-700"><?= h($r['problem']) ?></div><?php endif; ?>
              <?php if ($r['uwaga']): ?><div class="tw-mt-1 tw-text-amber-700"><?= h($r['uwaga']) ?></div><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Krok 2: paczka -->
    <div class="tw-mt-5 tw-border-0 tw-border-t tw-border-solid tw-border-slate-100 tw-pt-4">
      <h2 class="tw-m-0 tw-mb-3 tw-text-base tw-font-semibold tw-text-slate-900"><span class="prz-step">2</span> Paczka przelewów</h2>
      <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
        <div>
          <label for="rachunek_zlecen" class="prz-label">Z rachunku organizacji</label>
          <select id="rachunek_zlecen" name="rachunek_zlecen" class="prz-input tw-w-full" required>
            <?php if (!$konta): ?><option value="">— brak rachunków w Ustawieniach organizacji —</option><?php endif; ?>
            <?php foreach ($konta as $k): $n = preg_replace('/\D/', '', (string)$k['nrb']); ?>
            <option value="<?= h($n) ?>" <?= $n === $domyslne_konto ? 'selected' : '' ?>><?= h(edok_rachunek_etykieta($k)) ?> · …<?= h(substr($n, -4)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="format" class="prz-label">Format pliku</label>
          <select id="format" name="format" class="prz-input tw-w-full">
            <?php foreach (EDOK_PRZELEWY_FORMATY as $fk => $fl): ?>
            <option value="<?= h($fk) ?>" <?= $fk === 'auto' ? 'selected' : '' ?>><?= h($fl) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="tw-m-0 tw-mt-1 tw-text-xs tw-text-slate-500">Automatycznie wg banku konta: PKO BP → iPKO biznes, Millennium → Millenet, inne → ELIXIR-O.</p>
        </div>
      </div>

      <details class="tw-mt-4">
        <summary class="tw-cursor-pointer tw-text-sm tw-text-slate-600">Opcje dodatkowe</summary>
        <div class="tw-mt-3 tw-space-y-3">
          <label class="tw-flex tw-items-start tw-gap-2 tw-text-sm"><input type="checkbox" class="form-check-input tw-mt-0.5" name="oznacz_zaplacone" value="1"> Oznacz rachunki jako zapłacone po wygenerowaniu</label>
          <label class="tw-flex tw-items-start tw-gap-2 tw-text-sm"><input type="checkbox" class="form-check-input tw-mt-0.5" name="potwierdz_ponowne" value="1"> Potwierdzam ponowne ujęcie rachunków, które były już w pliku</label>
          <div class="tw-rounded-lg tw-bg-slate-50 tw-p-3">
            <p class="tw-m-0 tw-mb-2 tw-text-xs tw-text-slate-600">Rachunki spoza EODoK (np. już zapłacone) możesz przekazać do EODoK pojedynczo — zapłacone wymagają opisu powodu i nie trafią do przelewów.</p>
            <label for="powod_zaplacony" class="prz-label">Powód przekazania zapłaconego rachunku</label>
            <textarea id="powod_zaplacony" name="powod_zaplacony" rows="2" class="prz-input tw-w-full" placeholder="np. rachunek opłacony z konta projektowego przed wprowadzeniem do EODoK"></textarea>
            <button type="submit" name="action" value="do_edok" class="btn btn-outline-secondary btn-sm tw-mt-2" title="Przekazuje zaznaczone rachunki (bez dokumentu EODoK) do EODoK jako dokumenty do zapłaty — obieg papierowy"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i> Przekaż zaznaczone do EODoK</button>
          </div>
        </div>
      </details>
    </div>

    <div class="prz-bar">
      <p class="tw-m-0 tw-text-sm" aria-live="polite">Zaznaczone: <strong id="przIle">0</strong> · suma netto <strong id="przSuma">0,00</strong> zł</p>
      <button type="submit" name="action" value="generuj" class="btn btn-primary" <?= $do_wyboru && $konta ? '' : 'disabled' ?>><i class="bi bi-download me-1" aria-hidden="true"></i> Generuj paczkę przelewów</button>
    </div>
    <?php endif; ?>
  </form>

  <?php if ($hist): ?>
  <section class="prz-card tw-mt-5" aria-labelledby="przHistH">
    <h2 id="przHistH" class="tw-m-0 tw-mb-3 tw-text-base tw-font-semibold">Historia plików</h2>
    <div class="tw-overflow-x-auto">
      <table class="prz-table tw-w-full">
        <thead><tr><th scope="col">Kiedy</th><th scope="col">Kto</th><th scope="col">Okres</th><th scope="col">Format / konto</th><th scope="col" class="tw-text-right">Przelewy</th><th scope="col" class="tw-text-right">Suma netto</th><th scope="col">Plik</th></tr></thead>
        <tbody>
        <?php foreach ($hist as $hh): ?>
          <tr>
            <td class="tw-whitespace-nowrap"><?= h(date('d.m.Y H:i', strtotime((string)$hh['created_at']))) ?></td>
            <td><?= h((string)$hh['created_name']) ?></td>
            <td><?= $hh['okres'] ? h(edok_okres_label((string)$hh['okres'])) : 'różne' ?></td>
            <td class="tw-text-xs"><?= h(str_replace('_', ' ', (string)$hh['format'])) ?> · …<?= h(substr((string)$hh['nrb_z'], -4)) ?><?= $hh['oznaczono_zaplacone'] ? ' · oznaczone jako zapłacone' : '' ?></td>
            <td class="tw-text-right"><?= (int)$hh['liczba'] ?></td>
            <td class="tw-whitespace-nowrap tw-text-right"><?= $money($hh['suma']) ?></td>
            <td><?php if ($hh['plik_path']): ?><a href="?plik=<?= (int)$hh['id'] ?>"><i class="bi bi-download" aria-hidden="true"></i> pobierz</a><?php else: ?>—<?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>
</div>

<script>
(function () {
  var rows = Array.prototype.slice.call(document.querySelectorAll('.prz-row'));
  var cbs = Array.prototype.slice.call(document.querySelectorAll('.prz-cb'));
  var all = document.getElementById('przAll');
  var q = document.getElementById('przQ'), stan = document.getElementById('przStan'), ru = document.getElementById('przRu');
  function upd() {
    var n = 0, s = 0;
    cbs.forEach(function (c) { if (c.checked) { n++; s += parseFloat(c.dataset.kwota || '0'); } });
    document.getElementById('przIle').textContent = n;
    document.getElementById('przSuma').textContent = s.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function filtruj() {
    var t = q ? q.value.trim().toLowerCase() : '', st = stan ? stan.value : '', r = ru ? ru.value : '', vis = 0;
    rows.forEach(function (tr) {
      var ok = (!t || tr.dataset.hay.indexOf(t) !== -1) && (!st || (' ' + tr.dataset.stan + ' ').indexOf(' ' + st + ' ') !== -1) && (!r || tr.dataset.ru === r);
      tr.hidden = !ok;
      if (ok) vis++; else { var c = tr.querySelector('.prz-cb'); if (c) c.checked = false; } // ukryty wiersz nie wchodzi do wysyłki
    });
    var w = document.getElementById('przWidoczne'); if (w) w.textContent = vis;
    upd();
  }
  cbs.forEach(function (c) { c.addEventListener('change', upd); });
  if (all) all.addEventListener('change', function () { cbs.forEach(function (c) { if (!c.disabled && !c.dataset.zapl && !c.closest('tr').hidden) c.checked = all.checked; }); upd(); });
  [q, stan, ru].forEach(function (el) { if (el) el.addEventListener('input', filtruj); });
  if (cbs.length) upd();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
