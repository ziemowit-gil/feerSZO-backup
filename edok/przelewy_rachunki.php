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
$ym = preg_match('/^\d{4}-\d{2}$/', $_okres_in) ? $_okres_in : date('Y-m', strtotime('first day of last month'));
if ($_okres_in === 'wszystkie') $ym = '';

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

$rows   = prz_rach_lista($ym);
$konta  = edok_rachunki_list();
$hist   = prz_rach_historia();
$money  = fn($v) => number_format((float)$v, 2, ',', ' ');
$domyslne_konto = '';
foreach ($konta as $k) if (substr(preg_replace('/\D/', '', (string)$k['nrb']), 2, 4) === '1020') { $domyslne_konto = preg_replace('/\D/', '', (string)$k['nrb']); break; }
if ($domyslne_konto === '' && $konta) $domyslne_konto = preg_replace('/\D/', '', (string)$konta[0]['nrb']);
$do_wyboru = count(array_filter($rows, fn($r) => $r['problem'] === null));

$PAGE_TITLE = 'Przelewy za rachunki do umów';
require_once __DIR__ . '/../includes/header.php';
?>
<style type="text/tailwindcss">
  .prz-card  { @apply tw-rounded-xl tw-border tw-border-solid tw-border-slate-200 tw-bg-white tw-p-4 tw-shadow-sm sm:tw-p-5; }
  .prz-input { @apply tw-rounded-lg tw-border tw-border-solid tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm tw-outline-none focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100; }
  .prz-label { @apply tw-mb-1 tw-block tw-text-sm tw-font-medium tw-text-slate-700; }
  .prz-table th { @apply tw-bg-slate-50 tw-px-3 tw-py-2 tw-text-left tw-text-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-slate-500; }
  .prz-table td { @apply tw-border-0 tw-border-t tw-border-solid tw-border-slate-100 tw-px-3 tw-py-2 tw-align-top tw-text-sm; }
</style>

<div class="tw-mx-auto tw-max-w-6xl">
  <div class="tw-mb-4 tw-flex tw-flex-wrap tw-items-end tw-justify-between tw-gap-3">
    <div>
      <p class="tw-m-0 tw-text-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-slate-500">EODoK · Płatności</p>
      <h1 class="tw-m-0 tw-mt-1 tw-text-2xl tw-font-semibold tw-text-slate-900"><i class="bi bi-bank tw-mr-1 tw-text-blue-600" aria-hidden="true"></i> Przelewy za rachunki do umów</h1>
      <p class="tw-m-0 tw-mt-1 tw-max-w-3xl tw-text-sm tw-text-slate-500">Rachunki zatwierdzane na papierze — tu tylko plik przelewów dla banku. Kwota przelewu = <strong>netto</strong> (do wypłaty). Tytuł: „WYNAGRODZENIE MM/RRRR - umowa nr …”.</p>
    </div>
    <form method="get" class="tw-flex tw-items-end tw-gap-2">
      <div>
        <label for="okres" class="prz-label">Okres rachunków</label>
        <input id="okres" name="okres" type="month" class="prz-input" value="<?= h($ym) ?>">
      </div>
      <button class="btn btn-outline-secondary btn-sm" type="submit">Pokaż</button>
      <a class="btn btn-link btn-sm" href="?okres=wszystkie">Wszystkie niezapłacone</a>
    </form>
  </div>

  <form method="post" id="przForm" class="prz-card">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="generuj">
    <input type="hidden" name="okres" value="<?= h($ym === '' ? 'wszystkie' : $ym) ?>">

    <?php if (!$rows): ?>
      <p class="tw-m-0 tw-text-sm tw-text-slate-600">Brak rachunków <?= $ym ? 'za ' . h(edok_okres_label($ym)) : 'do zapłaty' ?>.</p>
    <?php else: ?>
    <div class="tw-overflow-x-auto">
      <table class="prz-table tw-w-full">
        <thead><tr>
          <th scope="col"><input type="checkbox" class="form-check-input" id="przAll" aria-label="Zaznacz wszystkie do wyboru" <?= $do_wyboru ? '' : 'disabled' ?>></th>
          <th scope="col">Zleceniobiorca / umowa</th>
          <th scope="col">Rachunek</th>
          <th scope="col">Okres</th>
          <th scope="col" class="tw-text-right">Brutto</th>
          <th scope="col" class="tw-text-right">Netto (przelew)</th>
          <th scope="col">Konto</th>
          <th scope="col">Stan</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $ok = $r['problem'] === null; ?>
          <tr class="<?= $ok ? '' : 'tw-text-slate-400' ?>">
            <td><input type="checkbox" class="form-check-input prz-cb" name="rid[]" value="<?= (int)$r['id'] ?>"
                   data-kwota="<?= h((string)(float)($r['kwota_netto'] ?? 0)) ?>" <?= $ok ? (empty($r['przelew_id']) ? 'checked' : '') : 'disabled' ?>
                   aria-label="<?= h($r['imie_nazwisko'] . ' ' . $r['numer']) ?>"></td>
            <td><a href="<?= APP_URL ?>/contracts/zlecenie/view.php?id=<?= (int)$r['umowa_id'] ?>&amp;tab=rachunki" class="tw-font-medium"><?= h($r['imie_nazwisko']) ?></a>
                <div class="tw-text-xs tw-text-slate-500">umowa <?= h($r['umowa_ref'] ?: ($r['numer_umowy'] ?: '—')) ?></div></td>
            <td class="tw-font-mono tw-text-xs"><?= h($r['numer'] ?: '#' . $r['id']) ?></td>
            <td class="tw-whitespace-nowrap"><?= h((string)($r['okres'] ?: '—')) ?></td>
            <td class="tw-whitespace-nowrap tw-text-right"><?= $r['kwota_brutto'] !== null ? $money($r['kwota_brutto']) : '—' ?></td>
            <td class="tw-whitespace-nowrap tw-text-right tw-font-semibold"><?= ($r['kwota_netto'] ?? null) !== null ? $money($r['kwota_netto']) : '—' ?></td>
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

    <div class="tw-mt-4 tw-grid tw-gap-4 tw-border-0 tw-border-t tw-border-solid tw-border-slate-100 tw-pt-4 md:tw-grid-cols-3">
      <div>
        <label for="rachunek_zlecen" class="prz-label">Z rachunku organizacji</label>
        <select id="rachunek_zlecen" name="rachunek_zlecen" class="prz-input tw-w-full" required>
          <?php if (!$konta): ?><option value="">— brak rachunków w Ustawieniach organizacji —</option><?php endif; ?>
          <?php foreach ($konta as $k): $n = preg_replace('/\D/', '', (string)$k['nrb']); ?>
          <option value="<?= h($n) ?>" <?= $n === $domyslne_konto ? 'selected' : '' ?>><?= h(trim(($k['nazwa'] ?? '') . ' ' . ($k['bank'] ?? ''))) ?> · …<?= h(substr($n, -4)) ?></option>
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
        <p class="tw-m-0 tw-mt-1 tw-text-xs tw-text-slate-500">Automatycznie: konto w PKO BP → iPKO biznes (ELIXIR-O, .txt).</p>
      </div>
      <div class="tw-space-y-2 tw-pt-1">
        <label class="tw-flex tw-items-start tw-gap-2 tw-text-sm"><input type="checkbox" class="form-check-input tw-mt-0.5" name="oznacz_zaplacone" value="1"> Oznacz rachunki jako zapłacone</label>
        <label class="tw-flex tw-items-start tw-gap-2 tw-text-sm"><input type="checkbox" class="form-check-input tw-mt-0.5" name="potwierdz_ponowne" value="1"> Potwierdzam ponowne ujęcie rachunków, które były już w pliku</label>
      </div>
    </div>

    <div class="tw-mt-4 tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
      <p class="tw-m-0 tw-text-sm" aria-live="polite">Zaznaczone: <strong id="przIle">0</strong> · suma netto <strong id="przSuma">0,00</strong> zł</p>
      <button type="submit" class="btn btn-primary" <?= $do_wyboru && $konta ? '' : 'disabled' ?>><i class="bi bi-download me-1" aria-hidden="true"></i> Generuj plik przelewów</button>
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
  var cbs = Array.prototype.slice.call(document.querySelectorAll('.prz-cb'));
  var all = document.getElementById('przAll');
  function upd() {
    var n = 0, s = 0;
    cbs.forEach(function (c) { if (c.checked) { n++; s += parseFloat(c.dataset.kwota || '0'); } });
    document.getElementById('przIle').textContent = n;
    document.getElementById('przSuma').textContent = s.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  cbs.forEach(function (c) { c.addEventListener('change', upd); });
  if (all) all.addEventListener('change', function () { cbs.forEach(function (c) { if (!c.disabled) c.checked = all.checked; }); upd(); });
  if (cbs.length) upd();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
