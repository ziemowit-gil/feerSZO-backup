<?php
/**
 * Hurtowe generowanie kont M365 Edu z listy osób: wklejka (Ctrl+C/V) lub plik CSV/XLSX
 * z wyborem kolumn → podgląd loginów → utworzenie → wysyłka danych na e-mail alternatywny.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/m365.php';
require_once __DIR__ . '/logic/msEduBulk.php';
require_once __DIR__ . '/logic/msEduBulkImport.php';
require_once __DIR__ . '/logic/msEduBulkMail.php';

require_role('admin');
$PAGE_TITLE = 'Generowanie kont M365 Edu';

$graph  = new M365Graph();
$skus   = $graph->is_configured() ? $graph->get_subscribed_skus() : [];
$domain = m365_setting('m365_domain') ?: 'feer.org.pl';

// ── Pobieranie wyników (hasła tylko w sesji) ────────────────────────────────
$result = $_SESSION['msEduBulkResult'] ?? [];
if (isset($_GET['clear'])) { unset($_SESSION['msEduBulkResult'], $_SESSION['msEduBulkMail']); header('Location: index.php'); exit; }
if (isset($_GET['csv']) && $result) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="konta_m365_edu_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBFimie;nazwisko;rola;klasa;email;login;haslo\n";
    foreach ($result as $r) echo implode(';', [$r['imie'], $r['nazwisko'], $r['rola'], $r['klasa'], $r['email'] ?? '', $r['upn'], $r['password']]) . "\n";
    exit;
}
if (isset($_GET['pdf']) && $result) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="konta_m365_edu_' . date('Ymd_His') . '.pdf"');
    echo msEduBulkPdf($result); exit;
}

// ── Stan formularza ──────────────────────────────────────────────────────────
$op     = (string)($_POST['_op'] ?? '');
$grid   = [];
$errors = [];
$year   = preg_match('/^\d{4}$/', $_POST['rok'] ?? '') ? $_POST['rok'] : date('Y');
$domIn  = preg_replace('/[^a-z0-9.\-]/i', '', $_POST['domena'] ?? '') ?: $domain;
$mode   = in_array($_POST['tryb'] ?? '', ['rola', 'imie_nazwisko', 'szkolenie'], true) ? $_POST['tryb'] : 'rola';
$slug   = trim((string)($_POST['szkolenie'] ?? ''));
$skuSel = ['uczen' => (string)($_POST['sku_uczen'] ?? ''), 'nauczyciel' => (string)($_POST['sku_nauczyciel'] ?? '')];
$defRole  = msEduBulkRole((string)($_POST['rola_domyslna'] ?? 'uczen')) ?: 'uczen';
$defKlasa = trim((string)($_POST['klasa_domyslna'] ?? ''));
$map    = array_map('strval', (array)($_POST['map'] ?? []));
$header = !empty($_POST['naglowek']);
$plan = null; $res = null;
$mail = $_SESSION['msEduBulkMail'] ?? ['subject' => MS_EDU_BULK_MAIL_SUBJECT, 'body' => MS_EDU_BULK_MAIL_BODY, 'szkolenie' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (in_array($op, ['mail_preview', 'mail_test', 'mail_send'], true) && $result) {
        $mail = ['subject' => trim((string)($_POST['mb_subject'] ?? '')) ?: MS_EDU_BULK_MAIL_SUBJECT,
                 'body' => (string)($_POST['mb_body'] ?? '') ?: MS_EDU_BULK_MAIL_BODY,
                 'szkolenie' => trim((string)($_POST['mb_szkolenie'] ?? ''))];
        $_SESSION['msEduBulkMail'] = $mail;
        if ($op === 'mail_test') {
            $me = current_user(); $to = trim((string)($me['email'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) flash_set('danger', 'Brak adresu e-mail na Twoim koncie.');
            else {
                $t = msEduBulkMailSend([['imie' => $result[0]['imie'], 'nazwisko' => $result[0]['nazwisko'], 'upn' => $result[0]['upn'],
                    'password' => $result[0]['password'], 'klasa' => $result[0]['klasa'], 'email' => $to]], '[TEST] ' . $mail['subject'], $mail['body'], $mail['szkolenie']);
                flash_set($t['sent'] ? 'success' : 'danger', $t['sent'] ? "Wysłano test na $to (dane pierwszej osoby z listy)." : implode(' ', $t['failed']));
            }
        } elseif ($op === 'mail_send') {
            $only = array_map('intval', (array)($_POST['mb_sel'] ?? []));
            if (!$only) flash_set('danger', 'Zaznacz odbiorców.');
            else {
                $s = msEduBulkMailSend($result, $mail['subject'], $mail['body'], $mail['szkolenie'], $only);
                $_SESSION['msEduBulkResult'] = $s['rows'];
                flash_set($s['failed'] ? 'warning' : 'success', 'Zakolejkowano wiadomości: ' . $s['sent'] . ($s['failed'] ? '. Błędy: ' . implode(' | ', $s['failed']) : '.'));
            }
        }
        header('Location: index.php'); exit;
    }

    // Wczytanie danych
    if ($op === 'wczytaj') {
        try {
            $f = $_FILES['plik'] ?? null;
            if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if ($ext === 'xls') throw new RuntimeException('Format XLS nie jest obsługiwany — zapisz plik jako XLSX lub CSV.');
                if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) throw new RuntimeException('Dozwolone formaty: XLSX, CSV, TXT.');
                $grid = $ext === 'xlsx' ? msEduBulkGridFromXlsx($f['tmp_name']) : msEduBulkGridFromCsv($f['tmp_name']);
            } else {
                $grid = msEduBulkGridFromText((string)($_POST['wklej'] ?? ''));
            }
            if (!$grid) throw new RuntimeException('Brak danych — wklej listę lub wybierz plik.');
            if (count($grid) > 301) throw new RuntimeException('Maksymalnie 300 osób na raz.');
            $header = msEduBulkLooksLikeHeader($grid[0]);
            $map = [];
            if ($header) foreach ($grid[0] as $i => $h) $map[$i] = msEduBulkAutoMap((string)$h);
            else $map = msEduBulkGuessMapByContent($grid[0]);
        } catch (\Throwable $e) { $errors[] = $e->getMessage(); $grid = []; }
    } else {
        $grid = json_decode((string)($_POST['grid'] ?? ''), true) ?: [];
    }

    if ($grid && in_array($op, ['podglad', 'utworz'], true)) {
        if (!in_array('email', $map, true)) $errors[] = 'Wskaż kolumnę z e-mailem alternatywnym — jest wymagana.';
        if (!in_array('imie', $map, true) && !in_array('imie_nazwisko', $map, true)) $errors[] = 'Wskaż kolumnę z imieniem (lub imieniem i nazwiskiem).';
        if (!$graph->is_configured()) $errors[] = 'Integracja Microsoft 365 nie jest skonfigurowana.';
        if (!$errors) {
            $p = msEduBulkRowsFromGrid($grid, $map, $header, $defRole, $defKlasa, $domIn);
            $errors = $p['errors'];
            if (!$p['rows']) $errors[] = 'Brak poprawnych wierszy do utworzenia.';
            // Błędy w wierszach blokują utworzenie — popraw dane i wczytaj ponownie / pomiń wiersze.
            elseif (!$errors || $op === 'podglad') $plan = msEduBulkPlan($graph, $p['rows'], $domIn, $year, $mode, $slug);
            if ($op === 'utworz' && $plan && !$errors) {
                $res = msEduBulkCreate($graph, $plan, $skuSel, $year);
                if ($res['ok']) { $_SESSION['msEduBulkResult'] = $res['ok']; $result = $res['ok']; $mail['szkolenie'] = $slug; $_SESSION['msEduBulkMail'] = $mail; }
                $plan = null; $grid = [];
            }
        }
    }
}

include dirname(__DIR__, 2) . '/includes/header.php';
?>
<div class="container-xl py-4">
  <h1 class="h3 fw-bold mb-3"><i class="bi bi-people-fill me-2"></i>Generowanie kont M365 Edu</h1>
  <?= flash_html() ?>
  <?php foreach ($errors as $e): ?><div class="alert alert-danger py-2 small mb-2"><?= h($e) ?></div><?php endforeach; ?>

  <?php if ($res && $res['errors']): ?><div class="alert alert-warning small">Nie utworzono: <?= h(implode(' | ', $res['errors'])) ?></div><?php endif; ?>

  <?php if ($result): ?>
  <div class="card mb-4 border-success">
    <div class="card-header d-flex gap-2 align-items-center flex-wrap">
      <span>Wygenerowane konta: <strong><?= count($result) ?></strong> — hasła widoczne tylko tutaj</span>
      <a href="?pdf=1" target="_blank" class="btn btn-sm btn-primary ms-auto"><i class="bi bi-printer me-1"></i>Drukuj PDF</a>
      <a href="?csv=1" class="btn btn-sm btn-success"><i class="bi bi-download me-1"></i>CSV</a>
      <a href="?clear=1" class="btn btn-sm btn-outline-secondary" onclick="return confirm('Zamknąć listę? Hasła znikną z sesji.')">Zamknij listę</a>
    </div>
    <div class="table-responsive"><table class="table table-sm mb-0 font-monospace">
      <thead class="table-light"><tr><th>Osoba</th><th>Klasa</th><th>Login</th><th>Hasło</th><th></th></tr></thead>
      <tbody><?php foreach ($result as $r): ?>
        <tr><td><?= h($r['imie'] . ' ' . $r['nazwisko']) ?></td><td><?= h($r['klasa']) ?></td><td><?= h($r['upn']) ?></td><td><?= h($r['password']) ?></td>
            <td class="text-warning small"><?= h($r['warn'] ?? '') ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
  </div>
  <?php
    $mbRows = $result; $mbTokenName = '_csrf'; $mbTokenValue = csrf_token(); $mbAction = 'index.php';
    $mbSubject = $mail['subject']; $mbBody = $mail['body']; $mbSzkolenie = $mail['szkolenie'] ?? '';
    include __DIR__ . '/views/mailComposer.php';
  endif; ?>

  <?php if (!$grid): ?>
  <form method="post" enctype="multipart/form-data" class="card card-body">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_op" value="wczytaj">
    <h2 class="h5">1. Wczytaj listę osób</h2>
    <p class="small text-muted mb-2">Skopiuj tabelę z Excela (Ctrl+C) i wklej poniżej (Ctrl+V) <strong>albo</strong> wybierz plik XLSX / CSV. W następnym kroku wskażesz, która kolumna co zawiera.
      Wymagane: imię, nazwisko i <strong>e-mail alternatywny</strong> (na niego wyślemy dane logowania).</p>
    <textarea name="wklej" rows="9" class="form-control font-monospace mb-3" aria-label="Wklej listę osób"
              placeholder="Imię&#9;Nazwisko&#9;E-mail&#9;Rola&#9;Klasa&#10;Jan&#9;Kowalski&#9;jan@gmail.com&#9;uczeń&#9;1A"></textarea>
    <div class="mb-3"><label class="form-label small fw-semibold" for="plik">…lub plik (XLSX, CSV)</label>
      <input id="plik" type="file" name="plik" class="form-control" accept=".xlsx,.csv,.txt"></div>
    <div><button class="btn btn-primary"><i class="bi bi-arrow-right-circle me-1"></i>Dalej — dopasuj kolumny</button></div>
  </form>
  <?php else: ?>
  <form method="post" class="card card-body">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="grid" value="<?= h(json_encode($grid, JSON_UNESCAPED_UNICODE)) ?>">
    <h2 class="h5">2. Dopasuj kolumny i ustawienia</h2>
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="naglowek" name="naglowek" value="1"<?= $header ? ' checked' : '' ?>>
      <label class="form-check-label" for="naglowek">Pierwszy wiersz to nagłówki (pomiń go)</label></div>
    <div class="table-responsive mb-3"><table class="table table-sm table-bordered mb-0" style="font-size:.85rem">
      <thead class="table-light"><tr><?php foreach ($grid[0] as $i => $_): ?>
        <th style="min-width:11rem"><select name="map[<?= $i ?>]" class="form-select form-select-sm" aria-label="Kolumna <?= $i + 1 ?>">
          <?php foreach (MS_EDU_BULK_FIELDS as $k => $lbl): ?><option value="<?= h($k) ?>"<?= ($map[$i] ?? '') === $k ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
        </select></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach (array_slice($grid, 0, 6) as $row): ?><tr><?php foreach ($row as $v): ?><td><?= h($v) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
    </table></div>
    <div class="small text-muted mb-3">Wczytano wierszy: <?= count($grid) ?> (podgląd: 6).</div>

    <div class="row g-3 mb-3">
      <div class="col-md-3"><label class="form-label small fw-semibold" for="rd">Rola domyślna <span class="text-muted fw-normal">(gdy brak kolumny)</span></label>
        <select id="rd" name="rola_domyslna" class="form-select"><option value="uczen"<?= $defRole === 'uczen' ? ' selected' : '' ?>>uczeń / kursant</option><option value="nauczyciel"<?= $defRole === 'nauczyciel' ? ' selected' : '' ?>>nauczyciel</option></select></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="kd">Klasa / grupa domyślna</label>
        <input id="kd" name="klasa_domyslna" class="form-control" value="<?= h($defKlasa) ?>"></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="rok">Rok rozpoczęcia nauki</label>
        <input id="rok" name="rok" class="form-control" value="<?= h($year) ?>" maxlength="4"></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="domena">Domena kont</label>
        <input id="domena" name="domena" class="form-control" value="<?= h($domIn) ?>"></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="tryb">Schemat loginu</label>
        <select id="tryb" name="tryb" class="form-select">
          <option value="rola"<?= $mode === 'rola' ? ' selected' : '' ?>>wg roli (uczeń i.nazwisko+rok / nauczyciel imie.nazwisko)</option>
          <option value="szkolenie"<?= $mode === 'szkolenie' ? ' selected' : '' ?>>nazwa szkolenia + numer (excel01)</option>
          <option value="imie_nazwisko"<?= $mode === 'imie_nazwisko' ? ' selected' : '' ?>>imie.nazwisko</option>
        </select></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="szkolenie">Nazwa szkolenia</label>
        <input id="szkolenie" name="szkolenie" class="form-control" value="<?= h($slug) ?>"></div>
      <?php foreach (['uczen' => 'Licencja — uczniowie', 'nauczyciel' => 'Licencja — nauczyciele'] as $k => $lbl): ?>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="sku_<?= $k ?>"><?= $lbl ?></label>
        <select id="sku_<?= $k ?>" name="sku_<?= $k ?>" class="form-select"><option value="">— bez licencji —</option>
          <?php foreach ($skus as $s): ?><option value="<?= h($s['skuId']) ?>"<?= ($skuSel[$k] ?? '') === $s['skuId'] ? ' selected' : '' ?>><?= h($s['skuPartNumber']) ?> (wolne: <?= (int)$s['prepaidUnits']['enabled'] - (int)$s['consumedUnits'] ?>)</option><?php endforeach; ?>
        </select></div>
      <?php endforeach; ?>
    </div>

    <?php if ($plan): ?>
    <h2 class="h5">3. Podgląd — nic jeszcze nie utworzono (<?= count($plan) ?>)</h2>
    <div class="table-responsive mb-3"><table class="table table-sm mb-0">
      <thead class="table-light"><tr><th>Osoba</th><th>Rola</th><th>Klasa / dział</th><th>E-mail alternatywny</th><th>Login</th></tr></thead>
      <tbody><?php foreach ($plan as $r): ?><tr><td><?= h($r['imie'] . ' ' . $r['nazwisko']) ?></td><td><?= h($r['rola']) ?></td><td><?= h($r['klasa']) ?></td><td><?= h($r['email']) ?></td><td class="font-monospace"><?= h($r['upn']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>

    <div class="d-flex gap-2 flex-wrap">
      <button name="_op" value="podglad" class="btn btn-outline-primary"><i class="bi bi-eye me-1"></i>Podgląd loginów</button>
      <?php if ($plan && !$errors): ?>
      <button name="_op" value="utworz" class="btn btn-primary" onclick="return confirm('Utworzyć REALNE konta w Entra ID?')"><i class="bi bi-person-plus me-1"></i>Utwórz <?= count($plan) ?> kont</button>
      <?php endif; ?>
      <a href="index.php" class="btn btn-outline-secondary ms-auto">Wczytaj inną listę</a>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer.php';
