<?php
/**
 * edok/mt940_import.php — Import wyciągu bankowego (MT940, iPKO biznes) jako
 * dokumentów przychodowych EODoK. Tylko operacje uznaniowe (wpływy) — dokumenty
 * powstają jako "w obiegu", bez skanu źródłowego (trzeba dołączyć ręcznie przed
 * kontrolą merytoryczną, patrz edok_mt940_create_doc()).
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok_bank.php';

edok_require_role('upload');
edok_migrate();

$user = current_user();
$errors = [];
$parsed = null;
$raw_b64 = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'parse') {
    csrf_check();
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        $errors[] = 'Wybierz plik wyciągu (MT940 lub Elixir, .txt/.sta) do wgrania.';
    } elseif ($_FILES['file']['size'] > 5 * 1024 * 1024) {
        $errors[] = 'Plik jest za duży (max 5 MB).';
    } else {
        $raw = file_get_contents($_FILES['file']['tmp_name']);
        $parsed = edok_bank_parse_any($raw);
        if (!$parsed['transactions']) {
            $errors[] = 'Nie znaleziono żadnych operacji (:61:) w pliku — sprawdź, czy to prawidłowy plik MT940 albo Elixir-0.';
            $parsed = null;
        } else {
            $raw_b64 = base64_encode($raw);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    csrf_check();
    $raw = base64_decode((string)($_POST['raw_b64'] ?? ''), true);
    $selected = array_map('intval', $_POST['idx'] ?? []);
    if ($raw !== false && ($_POST['do'] ?? '') === 'save') {
        // Zapis WSZYSTKICH operacji (wpływy i wypływy) do przypisywania do dokumentów — bez tworzenia dokumentów.
        $saved = edok_bank_import(edok_bank_parse_any($raw), (int)$user['id']);
        // Powiązania z dokumentami wybrane w podglądzie (idx → id dokumentu albo numer EODoK)
        $lnk_n = 0; $lnk_err = [];
        foreach ((array)($_POST['link'] ?? []) as $i => $docref) {
            $docref = trim((string)$docref); $extra = trim((string)($_POST['link_num'][$i] ?? '')); $tid = $saved['ids'][(int)$i] ?? null;
            if ($extra !== '') { $d = db_one("SELECT id FROM edok_documents WHERE number = ?", [$extra]); $docref = $d ? (string)$d['id'] : ''; if (!$d) $lnk_err[] = "brak dokumentu {$extra}"; }
            if ($docref === '' || !$tid) continue;
            $e = edok_bank_assign((int)$tid, (int)$docref);
            if ($e === null) $lnk_n++; else $lnk_err[] = $e;
        }
        // Przelewy własne zaznaczone w podglądzie (idx → rachunek drugiej strony)
        $own_n = 0; $own_err = [];
        foreach ((array)($_POST['own'] ?? []) as $i => $nrb) {
            $nrb = trim((string)$nrb); $tid = $saved['ids'][(int)$i] ?? null;
            if ($nrb === '' || !$tid) continue;
            $e = edok_bank_register_own_transfer((int)$tid, $nrb, trim((string)($_POST['own_reason'] ?? '')), (int)$user['id']);
            if ($e === null) $own_n++; else $own_err[] = $e;
        }
        $auto  = edok_bank_auto_match();
        // Wpływy za płatności z portalu /platnosci (kod w tytule / rachunek wirtualny) — rozliczane od razu
        $pp = ['matched' => 0];
        try { require_once dirname(__DIR__) . '/modules/payment_portal/logic/paymentPortal.php'; $pp = pp_bank_auto_match((string)($user['name'] ?? 'EODoK'), (int)$user['id']); }
        catch (\Throwable $e) { error_log('[platnosci] ' . $e->getMessage()); }
        flash_set('success', "Zapisano operacji: {$saved['added']}" . ($lnk_n ? ", powiązano z dokumentami: {$lnk_n}" : '') . ($lnk_err ? ' [' . implode('; ', array_unique($lnk_err)) . ']' : '') . ($own_n ? ", zarejestrowano przelewów własnych: {$own_n}" : '') . ($own_err ? ' (błędy: ' . implode('; ', array_unique($own_err)) . ')' : '') . ($saved['duplicates'] ? ", pominięto już zaimportowane: {$saved['duplicates']}" : '') . ". Dopasowano automatycznie: {$auto}."
            . ($pp['matched'] ? " Rozliczono płatności z portalu /platnosci: {$pp['matched']}." : ''));
        header('Location: ' . APP_URL . '/edok/wyciag.php');
        exit;
    }
    if ($raw === false || !$selected) {
        flash_set('warning', 'Nie zaznaczono żadnych operacji do importu.');
    } else {
        $parsed_import = edok_bank_parse_any($raw);
        $saved_ids = edok_bank_import($parsed_import, (int)$user['id'])['ids']; // transakcje trafiają też na ekran przypisywania
        $n = 0;
        foreach ($selected as $idx) {
            $tx = $parsed_import['transactions'][$idx] ?? null;
            if (!$tx || $tx['znak'] !== 'C') continue;
            $new_id = edok_mt940_create_doc($tx, (int)$user['id']);
            if (!empty($saved_ids[$idx])) edok_bank_assign((int)$saved_ids[$idx], $new_id, 'auto');
            $n++;
        }
        $auto_rest = edok_bank_auto_match();   // pozostałe zapisane operacje (np. wypływy) — też wiązane automatycznie
        flash_set('success', "Utworzono dokumentów przychodowych: {$n}" . ($auto_rest ? ", dopasowano automatycznie pozostałych operacji: {$auto_rest}" : '') . ". Uzupełnij każdy o skan wyciągu i dekretację przed kontrolą merytoryczną.");
        header('Location: ' . APP_URL . '/edok/index.php?kierunek=przychod');
        exit;
    }
}

$own_accounts = edok_rachunki_list();
$PAGE_TITLE = 'Import wyciągu bankowego (MT940) — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-bank2"></i> Import wyciągu bankowego (MT940)</h4>
</div>
<p class="text-muted small">Wgraj plik MT940 lub Elixir-0 wyeksportowany z iPKO biznes (PKO BP); format jest rozpoznawany automatycznie, a ta sama operacja z obu formatów nie zostanie zapisana dwa razy. Możesz zapisać cały wyciąg i przypisać każdą transakcję do dokumentu EODoK (<a href="<?= APP_URL ?>/edok/wyciag.php">ekran przypisywania</a>) albo — z operacji uznaniowych (wpływów) można od razu utworzyć dokumenty przychodowe EODoK. Każdy z nich trzeba potem uzupełnić o skan wyciągu i dekretację, zanim przejdzie kontrolę merytoryczną.</p>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if (!$parsed): ?>
<div class="card shadow-sm" style="max-width:520px">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="parse">
      <div class="mb-3">
        <label class="form-label">Plik wyciągu (MT940 lub Elixir-0)</label>
        <input type="file" name="file" class="form-control" accept=".txt,.sta" required>
      </div>
      <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Wczytaj i pokaż operacje</button>
    </form>
  </div>
</div>
<?php else: ?>

<div class="card shadow-sm mb-3">
  <div class="card-body py-2 small text-muted">
    Rachunek: <span class="font-monospace"><?= h($parsed['account_nrb'] ?: '—') ?></span>
    · Wyciąg nr <?= h($parsed['statement_no'] ?: '—') ?>
    <?php if ($parsed['opening']): ?> · Saldo początkowe: <?= h($parsed['opening']['kwota']) ?> <?= h($parsed['opening']['waluta']) ?><?php endif; ?>
    <?php if ($parsed['closing']): ?> · Saldo końcowe: <?= h($parsed['closing']['kwota']) ?> <?= h($parsed['closing']['waluta']) ?><?php endif; ?>
  </div>
</div>

<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="import">
  <input type="hidden" name="raw_b64" value="<?= h($raw_b64) ?>">

  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:2rem"><input type="checkbox" id="mt940-select-all"></th>
          <th>Data waluty</th>
          <th>Typ</th>
          <th class="text-end">Kwota</th>
          <th>Kontrahent</th>
          <th>Tytuł</th>
          <th>Referencja / nr operacji</th>
          <th>Powiąż z dokumentem</th>
          <th>Przelew własny</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($parsed['transactions'] as $i => $t): $jest_wplyw = $t['znak'] === 'C'; ?>
        <tr class="<?= $jest_wplyw ? '' : 'text-muted' ?>">
          <td><?php if ($jest_wplyw): ?><input type="checkbox" name="idx[]" value="<?= $i ?>" class="mt940-check" checked><?php endif; ?></td>
          <td><?= h(date_pl($t['data_waluty'])) ?></td>
          <td><?= $jest_wplyw ? '<span class="badge bg-success">Wpływ</span>' : '<span class="badge bg-secondary">Wypływ</span>' ?></td>
          <td class="text-end font-monospace"><?= h(number_format((float)$t['kwota'], 2, ',', ' ')) ?></td>
          <td><?= h($t['kontrahent_nazwa'] ?: '—') ?></td>
          <td class="small"><?= h($t['tytul'] ?: '—') ?></td>
          <td class="font-monospace small"><?= h($t['referencja'] ?: $t['numer_operacji']) ?></td>
          <td style="min-width:260px">
            <?php $cands = edok_bank_candidates($t + ['id' => 0, 'waluta' => 'PLN'], 4); ?>
            <select name="link[<?= $i ?>]" class="form-select form-select-sm" aria-label="Dokument EODoK do powiązania z operacją">
              <option value="">— nie —</option>
              <?php foreach ($cands as $k => $c): $d = $c['doc']; ?>
              <option value="<?= (int)$d['id'] ?>"<?= $k === 0 && $c['amount_ok'] && $c['score'] >= 90 ? ' selected' : '' ?>><?= h($d['number'] . ' · ' . mb_substr((string)$d['kontrahent_nazwa'], 0, 24) . ' · ' . $d['kwota_brutto']) ?> (<?= h(implode(', ', $c['reasons'])) ?>)</option>
              <?php endforeach; ?>
            </select>
            <input name="link_num[<?= $i ?>]" class="form-control form-control-sm mt-1" placeholder="lub nr EODoK" aria-label="Numer dokumentu EODoK">
          </td>
          <td>
            <?php $det = edok_bank_detect_own_counter($t + ['account_nrb' => $parsed['account_nrb']]); ?>
            <select name="own[<?= $i ?>]" class="form-select form-select-sm" style="min-width:210px" aria-label="Przelew własny — rachunek drugiej strony">
              <option value="">— nie —</option>
              <?php foreach ($own_accounts as $r): if (_edok_nrb_digits((string)$r['nrb']) === _edok_nrb_digits($parsed['account_nrb'])) continue; ?>
              <option value="<?= h($r['nrb']) ?>"<?= $det === $r['nrb'] ? ' selected' : '' ?>><?= $jest_wplyw ? 'z: ' : 'na: ' ?><?= h(($r['nazwa'] ?: $r['bank']) . ' …' . substr(_edok_nrb_digits((string)$r['nrb']), -4)) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="mb-3" style="max-width:640px">
    <label class="form-label small mb-1" for="own_reason">Uzasadnienie przelewów własnych (dla operacji oznaczonych w ostatniej kolumnie)</label>
    <input id="own_reason" name="own_reason" class="form-control form-control-sm" value="Przelew własny między rachunkami organizacji (import wyciągu)">
    <div class="form-text">Oznaczona operacja trafia do rejestru „Przelewy własne" (jedna para wypływ+wpływ = jeden wpis) i nie wymaga przypisania do dokumentu. Rozpoznane automatycznie po rachunku kontrahenta są już zaznaczone.</div>
  </div>
  <p class="text-muted small">Tylko operacje uznaniowe (wpływy) można zaimportować jako dokumenty przychodowe — wypływy nie są zaznaczalne (EODoK dla wydatków zaczyna się od faktury/rachunku, nie od wyciągu).</p>
  <button type="submit" name="do" value="save" class="btn btn-primary"><i class="bi bi-link-45deg"></i> Zapisz wyciąg i przypisz transakcje do dokumentów</button>
  <button type="submit" class="btn btn-success"><i class="bi bi-check2-all"></i> Importuj zaznaczone jako dokumenty przychodowe (projekty)</button>
  <a href="<?= APP_URL ?>/edok/mt940_import.php" class="btn btn-outline-secondary">Wgraj inny plik</a>
</form>

<script>
document.getElementById('mt940-select-all').addEventListener('change', function () {
  var checked = this.checked;
  document.querySelectorAll('.mt940-check').forEach(function (c) { c.checked = checked; });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
