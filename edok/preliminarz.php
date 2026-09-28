<?php
/**
 * edok/preliminarz.php — Preliminarz Płatności (przeniesiony z KDOK).
 * Ujednolicony: pokazuje zaakceptowane dokumenty z EODoK ORAZ archiwalnego
 * KDOK (edok_preliminarz_query() w includes/edok.php) — jedna lista „co trzeba
 * zapłacić" niezależnie od tego, w którym module dokument powstał.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

if (!is_admin() && !edok_has_role('zatwierdza') && !(function_exists('kdok_has_role') && kdok_has_role('zatwierdza'))) {
    flash_set('danger', 'Brak dostępu do Preliminarza Płatności.');
    header('Location: ' . APP_URL . '/edok/index.php');
    exit;
}

$f_termin_od = trim($_GET['termin_od'] ?? '');
$f_termin_do = trim($_GET['termin_do'] ?? date('Y-m-d', strtotime('+30 days')));
$f_status    = trim($_GET['status'] ?? '');
$f_waluta    = trim($_GET['waluta'] ?? '');
$f_mpp       = !empty($_GET['mpp']);
$f_q         = trim($_GET['q'] ?? '');

$filters = array_filter([
    'termin_od'        => $f_termin_od,
    'termin_do'        => $f_termin_do ?: null,
    'status_platnosci' => $f_status,
    'waluta'           => $f_waluta,
    'mpp'              => $f_mpp ?: null,
    'q'                => $f_q,
]);

// Tytuł zbiorczy dla kilku dokumentów płaconych jednym przelewem (Uchwała 5/2026
// §2 pkt 10-11) — tylko dokumenty z EODoK (numeracja/format specyficzne dla EODoK,
// archiwalny KDOK ma własną numerację i nie pasuje do formatu AKC/FAK).
$pakiet_tytul = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pakiet_tytul') {
    csrf_check();
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $_POST['pakiet_ids'] ?? '')))));
    if (count($ids) < 2) {
        flash_set('warning', 'Zaznacz co najmniej dwa dokumenty EODoK, żeby wygenerować tytuł zbiorczy.');
    } else {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $docs = db_all("SELECT * FROM edok_documents WHERE id IN ($placeholders)", $ids);
        $pakiet_tytul = edok_generate_tytul_pakiet($docs);
    }
}

// Eksport przelewów zbiorczych do iPKO biznes (format ELIXIR-O) — tylko EODoK,
// tylko dokumenty wydatkowe zaakceptowane z prawidłowym 26-cyfrowym rachunkiem
// kontrahenta (edok_ipko_biznes_export() pomija resztę). Patrz includes/edok.php.
// Każdy dokument może iść z innego rachunku organizacji (rachunek_map: id => NRB,
// brak wpisu = rachunek domyślny z paska) — jeden plik ELIXIR-O na rachunek
// nadawcy, przy kilku rachunkach pakowane razem w ZIP.
$ipko_error   = null;
$ipko_confirm = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_ipko') {
    csrf_check();
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $_POST['pakiet_ids'] ?? '')))));
    $rachunek_zlecen = preg_replace('/\D/', '', $_POST['rachunek_zlecen'] ?? '');
    $rachunki_ok = [];
    foreach (edok_rachunki_list() as $r) $rachunki_ok[preg_replace('/\D/', '', $r['nrb'])] = $r;
    $rachunek_map = [];
    foreach ((array) json_decode($_POST['rachunek_map'] ?? '{}', true) as $doc_id => $nrb) {
        $nrb = preg_replace('/\D/', '', (string) $nrb);
        if ($nrb !== '' && isset($rachunki_ok[$nrb])) $rachunek_map[(int) $doc_id] = $nrb;
    }
    $bez_rachunku = array_filter($ids, fn($id) => !isset($rachunek_map[$id]));
    if (!$ids) {
        flash_set('warning', 'Zaznacz co najmniej jeden dokument do eksportu.');
    } elseif ($bez_rachunku && !isset($rachunki_ok[$rachunek_zlecen])) {
        flash_set('warning', 'Wybierz rachunek, z którego mają pójść przelewy (domyślny albo przy każdym dokumencie).');
    } else {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $docs = db_all(
            "SELECT * FROM edok_documents WHERE id IN ($placeholders) AND status='zaakceptowany' AND COALESCE(kierunek,'wydatek')='wydatek'",
            $ids
        );
        // Pierwszy przelew na daną parę NIP + rachunek wymaga potwierdzenia, że
        // obie wartości zgadzają się z fakturą — zanim plik trafi do banku.
        $unverified = edok_ipko_unverified_pairs($docs);
        $confirmed  = array_flip((array)($_POST['confirm_pair'] ?? []));
        $missing    = array_diff_key($unverified, $confirmed);
        if ($missing) {
            $ipko_confirm = ['pairs' => $unverified, 'ids' => $ids, 'rachunek' => $rachunek_zlecen, 'map' => $rachunek_map];
            if (!empty($_POST['confirm_step'])) flash_set('warning', 'Potwierdź poprawność NIP i numeru rachunku dla każdego kontrahenta.');
        } else {
            foreach ($unverified as $p) edok_kontrahent_verify($p['nip'], $p['nrb'], $p['nazwa']);
        }
        if (!$missing) try {
            $grupy = [];
            foreach ($docs as $doc) $grupy[$rachunek_map[(int) $doc['id']] ?? $rachunek_zlecen][] = $doc;
            $stamp = date('Y-m-d_His');
            $pliki = [];
            foreach ($grupy as $nrb => $grupa) {
                $content = edok_ipko_biznes_export($grupa, (string) $nrb);
                if ($content === '') continue;
                $nazwa = $rachunki_ok[$nrb]['nazwa'] ?? '' ?: ($rachunki_ok[$nrb]['bank'] ?? '');
                $slug  = trim(preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nazwa) ?: ''), '_');
                $pliki['iPKO_biznes_' . ($slug !== '' ? $slug . '_' : '') . substr((string) $nrb, -4) . '_' . $stamp . '.txt'] = $content;
            }
            if (!$pliki) {
                flash_set('warning', 'Żaden z zaznaczonych dokumentów nie nadaje się do eksportu (brak prawidłowego 26-cyfrowego rachunku kontrahenta).');
            } elseif (count($pliki) === 1) {
                header('Content-Type: text/plain; charset=ISO-8859-2');
                header('Content-Disposition: attachment; filename="' . array_key_first($pliki) . '"');
                header('Content-Length: ' . strlen(reset($pliki)));
                echo reset($pliki);
                exit;
            } else {
                $tmp = tempnam(sys_get_temp_dir(), 'ipko');
                $zip = new ZipArchive();
                if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Nie udało się utworzyć archiwum ZIP.');
                foreach ($pliki as $fn => $c) $zip->addFromString($fn, $c);
                $zip->close();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="iPKO_biznes_' . $stamp . '.zip"');
                header('Content-Length: ' . filesize($tmp));
                readfile($tmp);
                @unlink($tmp);
                exit;
            }
        } catch (\Throwable $e) {
            flash_set('danger', 'Błąd eksportu: ' . $e->getMessage());
        }
    }
}

$rows = edok_preliminarz_query($filters);
$rachunki_org = edok_rachunki_list();

if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="EODoK_Preliminarz_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Źródło','Numer','Tytuł','Tytuł przelewu','Kontrahent','NIP','Nr rachunku','Netto','VAT','Brutto','Waluta','Termin płatności','Klasyfikacja','Status płatności','MPP'], ';', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, [
            strtoupper($r['source']), $r['number'], $r['title'], $r['tytul_przelewu'] ?? '', $r['kontrahent'], $r['nip'], $r['rachunek_bankowy'],
            $r['kwota_netto'], $r['kwota_vat'], $r['kwota_brutto'], $r['waluta'],
            $r['termin_platnosci'] ? substr($r['termin_platnosci'], 0, 10) : '',
            $r['klasyfikacja'], $r['status_platnosci'], !empty($r['wymaga_mpp']) ? 'MPP' : '',
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

$sumy = [];
foreach ($rows as $r) {
    $w = $r['waluta'] ?: 'PLN';
    $b = (float) str_replace([' ', ','], ['', '.'], $r['kwota_brutto'] ?: '0');
    $v = (float) str_replace([' ', ','], ['', '.'], $r['kwota_vat'] ?: '0');
    $sumy[$w]['brutto'] = ($sumy[$w]['brutto'] ?? 0) + $b;
    $sumy[$w]['vat']    = ($sumy[$w]['vat']    ?? 0) + $v;
}

$PAGE_TITLE = 'Preliminarz Płatności — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-calendar-check"></i> Preliminarz Płatności</h4>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/edok/preliminarz.php?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="btn btn-sm btn-outline-success">
      <i class="bi bi-filetype-csv"></i> CSV
    </a>
    <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> EODoK</a>
  </div>
</div>
<p class="text-muted small">Zaakceptowane dokumenty z EODoK oraz archiwalnego KDOK — jedna lista płatności niezależnie od modułu pochodzenia.</p>

<?= flash_html() ?>

<?php if ($pakiet_tytul !== null): ?>
<div class="alert alert-info d-flex align-items-center gap-2">
  <i class="bi bi-magic"></i>
  <span class="font-monospace flex-grow-1" id="pakiet_tytul_text"><?= h($pakiet_tytul) ?></span>
  <button type="button" class="btn btn-sm btn-outline-secondary" title="Kopiuj"
    onclick="navigator.clipboard.writeText(document.getElementById('pakiet_tytul_text').textContent)">
    <i class="bi bi-clipboard"></i> Kopiuj
  </button>
</div>
<p class="text-muted small">Pełna lista dokumentów objętych tą płatnością pozostaje w danych poszczególnych dokumentów EODoK — wklej powyższy tytuł w banku i odnotuj wykonany przelew na każdym z nich.</p>
<?php endif; ?>

<?php if ($ipko_confirm): ?>
<form method="post" class="card border-warning shadow-sm mb-3">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="export_ipko">
  <input type="hidden" name="confirm_step" value="1">
  <input type="hidden" name="pakiet_ids" value="<?= h(implode(',', $ipko_confirm['ids'])) ?>">
  <input type="hidden" name="rachunek_zlecen" value="<?= h($ipko_confirm['rachunek']) ?>">
  <input type="hidden" name="rachunek_map" value="<?= h(json_encode((object) $ipko_confirm['map'])) ?>">
  <div class="card-header bg-warning-subtle fw-semibold">
    <i class="bi bi-shield-exclamation"></i> Pierwszy przelew — potwierdź dane kontrahenta
  </div>
  <div class="card-body">
    <p class="small mb-2">Do poniższych kontrahentów (lub na poniższe rachunki) nie wygenerowano jeszcze żadnego przelewu. Porównaj NIP i numer rachunku z fakturą, a najlepiej także z białą listą VAT, zanim wygenerujesz plik dla iPKO biznes. Potwierdzenie zostaje zapisane, więc przy następnych eksportach tej pary nie trzeba go powtarzać.</p>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-2" style="font-size:.85rem">
        <thead><tr><th style="width:2rem"></th><th>Kontrahent</th><th>NIP</th><th>Nr rachunku</th><th>Dokumenty</th></tr></thead>
        <tbody>
        <?php foreach ($ipko_confirm['pairs'] as $key => $p): $cid = 'cp_' . md5($key); ?>
          <tr>
            <td><input type="checkbox" class="form-check-input" name="confirm_pair[]" value="<?= h($key) ?>" id="<?= $cid ?>" required></td>
            <td><label for="<?= $cid ?>"><?= h($p['nazwa'] ?: '—') ?></label>
              <?php if ($p['known_nip']): ?><div><span class="badge bg-danger">Nowy rachunek znanego kontrahenta</span></div><?php endif; ?>
            </td>
            <td class="font-monospace"><?= $p['nip'] !== '' ? h($p['nip']) : '<span class="badge bg-danger">brak NIP</span>' ?></td>
            <td class="font-monospace fw-semibold"><?= h(edok_nrb_format($p['nrb'])) ?></td>
            <td class="small"><?= h(implode(', ', $p['docs'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check2-square"></i> Potwierdzam poprawność i eksportuję</button>
      <a href="<?= APP_URL ?>/edok/preliminarz.php" class="btn btn-sm btn-outline-secondary">Anuluj</a>
    </div>
  </div>
</form>
<?php endif; ?>

<form method="post" id="pakietForm" class="d-none">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="pakiet_tytul">
  <input type="hidden" name="pakiet_ids" id="pakiet_ids">
</form>

<form method="post" id="ipkoForm" class="d-none">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="export_ipko">
  <input type="hidden" name="pakiet_ids" id="ipko_pakiet_ids">
  <input type="hidden" name="rachunek_zlecen" id="ipko_rachunek_zlecen">
  <input type="hidden" name="rachunek_map" id="ipko_rachunek_map">
</form>

<form method="get" class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-sm-auto">
        <label class="form-label mb-1 small fw-semibold">Termin płatności</label>
        <div class="d-flex gap-1 align-items-center">
          <input type="date" name="termin_od" class="form-control form-control-sm" value="<?= h($f_termin_od) ?>">
          <span class="text-muted">–</span>
          <input type="date" name="termin_do" class="form-control form-control-sm" value="<?= h($f_termin_do) ?>">
        </div>
      </div>
      <div class="col-sm-2">
        <label class="form-label mb-1 small fw-semibold">Status płatności</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">Wszystkie</option>
          <?php foreach (EDOK_STATUS_PLATNOSCI as $k => $s): ?>
          <option value="<?= h($k) ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= h($s['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-1">
        <label class="form-label mb-1 small fw-semibold">Waluta</label>
        <select name="waluta" class="form-select form-select-sm">
          <option value="">Wszystkie</option>
          <?php foreach (['PLN','EUR','USD','CHF','GBP'] as $w): ?>
          <option value="<?= $w ?>" <?= $f_waluta === $w ? 'selected' : '' ?>><?= $w ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label mb-1 small fw-semibold">Szukaj</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="NIP / nr faktury / tytuł" value="<?= h($f_q) ?>">
      </div>
      <div class="col-auto">
        <div class="form-check form-check-inline mt-3">
          <input class="form-check-input" type="checkbox" name="mpp" id="f-mpp" value="1" <?= $f_mpp ? 'checked' : '' ?>>
          <label class="form-check-label small" for="f-mpp">Tylko MPP</label>
        </div>
      </div>
      <div class="col-auto d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
        <a href="<?= APP_URL ?>/edok/preliminarz.php" class="btn btn-outline-secondary btn-sm">Wyczyść</a>
      </div>
    </div>
  </div>
</form>

<div class="d-none align-items-center gap-2 mb-3 flex-wrap" id="pakietBar">
  <span class="small text-muted"><span id="pakietCount">0</span> zaznaczonych dokumentów EODoK</span>
  <button type="button" class="btn btn-sm btn-outline-primary" onclick="edokPakietSubmit()">
    <i class="bi bi-magic"></i> Wygeneruj tytuł zbiorczy
  </button>
  <?php if ($rachunki_org): ?>
  <span class="text-muted">·</span>
  <select id="ipko_rachunek_select" class="form-select form-select-sm" style="width:auto" title="Rachunek, z którego mają pójść przelewy">
    <option value="">— domyślny rachunek nadawcy —</option>
    <?php foreach ($rachunki_org as $r): ?>
    <option value="<?= h($r['nrb']) ?>"><?= h($r['nazwa'] ?: $r['bank']) ?> (…<?= h(substr($r['nrb'], -4)) ?>)</option>
    <?php endforeach; ?>
  </select>
  <button type="button" class="btn btn-sm btn-outline-success" onclick="edokIpkoSubmit()">
    <i class="bi bi-bank"></i> Eksportuj do iPKO biznes
  </button>
  <?php endif; ?>
</div>
<?php if ($rachunki_org): ?>
<p class="text-muted small">Eksport do iPKO biznes: plik przelewów zbiorczych (ELIXIR-O) — zaimportuj go w iPKO biznes i zweryfikuj przed skierowaniem do realizacji. Obejmuje tylko zaznaczone dokumenty wydatkowe z prawidłowym 26-cyfrowym rachunkiem kontrahenta. Rachunek nadawcy można zmienić przy każdym dokumencie (kolumna „Z rachunku”). Każdy rachunek dostaje osobny plik, a przy kilku rachunkach pliki są spakowane w ZIP.</p>
<?php endif; ?>

<?php if ($sumy): ?>
<div class="d-flex flex-wrap gap-3 mb-3">
  <?php foreach ($sumy as $w => $s): ?>
  <div class="card border-0 shadow-sm px-3 py-2 text-center" style="min-width:160px">
    <div class="small text-muted">Łącznie brutto <strong><?= h($w) ?></strong></div>
    <div class="fw-bold fs-6 font-monospace"><?= number_format($s['brutto'], 2, ',', ' ') ?></div>
  </div>
  <?php endforeach; ?>
  <div class="card border-0 shadow-sm px-3 py-2 text-center" style="min-width:120px">
    <div class="small text-muted">Liczba pozycji</div>
    <div class="fw-bold fs-6"><?= count($rows) ?></div>
  </div>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
<div class="text-center text-muted py-5">
  <i class="bi bi-check-circle display-4 d-block mb-2"></i>
  Brak dokumentów spełniających kryteria.
</div>
<?php else: ?>
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle" style="font-size:.83rem">
      <thead class="table-dark">
        <tr>
          <th style="width:2rem"></th>
          <th>Źródło</th>
          <th>Numer</th>
          <th>Kontrahent / NIP</th>
          <th>Tytuł przelewu</th>
          <th class="text-end">Brutto</th>
          <th>Termin</th>
          <th class="text-center">Dni</th>
          <th class="text-center">MPP</th>
          <th>Klasyfikacja</th>
          <th>Status płatności</th>
          <?php if ($rachunki_org): ?><th>Z rachunku</th><?php endif; ?>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
        $p      = (int)($r['priorytet'] ?? 5);
        $termin = $r['termin_platnosci'] ?? '';
        $diff   = $termin ? (int)round((strtotime($termin) - strtotime(date('Y-m-d'))) / 86400) : null;
        $rowClass = match(true) { $p === 1 => 'table-danger', $p === 2 => 'table-warning', default => '' };
        ?>
        <tr class="<?= $rowClass ?>">
          <td>
            <?php if ($r['source'] === 'edok'): ?>
            <input type="checkbox" class="form-check-input pakiet-check" value="<?= (int)$r['id'] ?>">
            <?php endif; ?>
          </td>
          <td><span class="badge bg-<?= $r['source'] === 'edok' ? 'primary' : 'secondary' ?>"><?= strtoupper($r['source']) ?></span></td>
          <td><a href="<?= h($r['view_url']) ?>"><code><?= h($r['number']) ?></code></a></td>
          <td>
            <div><?= h($r['kontrahent'] ?: '—') ?></div>
            <?php if ($r['nip']): ?><div class="text-muted small font-monospace"><?= h($r['nip']) ?></div><?php endif; ?>
          </td>
          <td>
            <?php if (!empty($r['tytul_przelewu'])): ?>
            <span class="font-monospace" style="font-size:.78rem"><?= h($r['tytul_przelewu']) ?></span>
            <button type="button" class="btn btn-sm btn-link p-0 ms-1" title="Kopiuj tytuł przelewu"
              onclick="navigator.clipboard.writeText(<?= json_encode($r['tytul_przelewu'], JSON_UNESCAPED_UNICODE) ?>)">
              <i class="bi bi-clipboard"></i>
            </button>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="text-end font-monospace fw-semibold"><?= $r['kwota_brutto'] ? h($r['kwota_brutto']) : '—' ?> <?= h($r['waluta']) ?></td>
          <td><?php if ($termin): ?><span class="<?= $p <= 2 ? 'fw-semibold' : '' ?>"><?= h(substr($termin, 0, 10)) ?></span><?php else: ?>—<?php endif; ?></td>
          <td class="text-center">
            <?php if ($diff !== null): ?>
            <span class="badge <?= $diff < 0 ? 'bg-danger' : ($diff <= 3 ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= $diff < 0 ? abs($diff) . ' po' : $diff ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="text-center"><?= !empty($r['wymaga_mpp']) ? '<i class="bi bi-exclamation-triangle-fill text-warning" title="MPP"></i>' : '—' ?></td>
          <td class="small"><?= h($r['klasyfikacja'] ?: '—') ?></td>
          <td>
            <form method="post" action="<?= APP_URL ?>/edok/preliminarz_action.php" class="d-flex gap-1 align-items-center">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="source" value="<?= h($r['source']) ?>">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <select name="status" class="form-select form-select-sm" style="width:auto" onchange="this.form.requestSubmit()">
                <?php foreach (EDOK_STATUS_PLATNOSCI as $k => $s): ?>
                <option value="<?= h($k) ?>" <?= $r['status_platnosci'] === $k ? 'selected' : '' ?>><?= h($s['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <?php if ($rachunki_org): ?>
          <td>
            <?php if ($r['source'] === 'edok'): ?>
            <select class="form-select form-select-sm ipko-row-rachunek" data-id="<?= (int)$r['id'] ?>" style="width:auto" title="Rachunek nadawcy dla tego dokumentu">
              <option value="">domyślny</option>
              <?php foreach ($rachunki_org as $ro): ?>
              <option value="<?= h($ro['nrb']) ?>"><?= h($ro['nazwa'] ?: $ro['bank']) ?> (…<?= h(substr($ro['nrb'], -4)) ?>)</option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  var boxes  = Array.from(document.querySelectorAll('.pakiet-check'));
  var bar    = document.getElementById('pakietBar');
  var count  = document.getElementById('pakietCount');
  var tytulBtn = bar ? bar.querySelector('button[onclick="edokPakietSubmit()"]') : null;
  function sync() {
    var n = boxes.filter(function (b) { return b.checked; }).length;
    count.textContent = n;
    bar.classList.toggle('d-none', n < 1);
    bar.classList.toggle('d-flex', n >= 1);
    if (tytulBtn) tytulBtn.disabled = n < 2;
  }
  boxes.forEach(function (b) { b.addEventListener('change', sync); });
  sync();
})();
function edokSelectedIds() {
  return Array.from(document.querySelectorAll('.pakiet-check')).filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
}
function edokPakietSubmit() {
  document.getElementById('pakiet_ids').value = edokSelectedIds().join(',');
  document.getElementById('pakietForm').submit();
}
function edokIpkoSubmit() {
  var rachunek = document.getElementById('ipko_rachunek_select');
  var ids = edokSelectedIds(), map = {}, bezRachunku = false;
  ids.forEach(function (id) {
    var sel = document.querySelector('.ipko-row-rachunek[data-id="' + id + '"]');
    if (sel && sel.value) map[id] = sel.value; else bezRachunku = true;
  });
  if (bezRachunku && (!rachunek || !rachunek.value)) { alert('Wybierz domyślny rachunek nadawcy albo rachunek przy każdym zaznaczonym dokumencie.'); return; }
  document.getElementById('ipko_pakiet_ids').value = ids.join(',');
  document.getElementById('ipko_rachunek_zlecen').value = rachunek ? rachunek.value : '';
  document.getElementById('ipko_rachunek_map').value = JSON.stringify(map);
  document.getElementById('ipkoForm').submit();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
