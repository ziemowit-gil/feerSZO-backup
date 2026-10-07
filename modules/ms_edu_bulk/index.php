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
require_once dirname(__DIR__, 2) . '/includes/ti_online.php';
require_once __DIR__ . '/logic/msEduBulk.php';
require_once __DIR__ . '/logic/msEduBulkImport.php';
require_once __DIR__ . '/logic/msEduBulkMail.php';

require_role('admin');
$ms_view = $ms_view ?? 'hub'; // hub = konta anonimowe + wyniki; kreator = import listy (kreator.php)
$PAGE_TITLE = $ms_view === 'kreator' ? 'Kreator kont M365 Edu' : 'Generowanie kont M365 Edu';
// Działa pod /modules/ms_edu_bulk/index.php i pod aliasem /ms — przekierowania i linki wracają pod aktualny adres.
$self = strtok((string)($_SERVER['REQUEST_URI'] ?? 'index.php'), '?');
$_alias = str_starts_with($self, '/ms');
$lnk = $_alias ? ['hub' => '/ms', 'kreator' => '/ms/kreator', 'konta' => '/ms/konta']
               : ['hub' => dirname($self) . '/index.php', 'kreator' => dirname($self) . '/kreator.php', 'konta' => dirname($self) . '/konta.php'];

$graph  = m365_edu(); // konta zawsze w tenancie EDU
$skus   = $graph->is_configured() ? $graph->get_subscribed_skus() : [];
$domain = m365_training_domain();

// ── Pobieranie wyników (hasła tylko w sesji) ────────────────────────────────
$result = $_SESSION['msEduBulkResult'] ?? [];
if (isset($_GET['clear']) || isset($_GET['porzuc'])) {
    $_had = !empty($_SESSION['msEduBulkResult']);
    unset($_SESSION['msEduBulkResult'], $_SESSION['msEduBulkMail']);
    flash_set('info', 'Porzucono bieżący kreator' . ($_had ? ' — lista haseł została usunięta z sesji (utworzone konta zostają w Entra ID).' : ' — nic nie zostało utworzone.'));
    header('Location: ' . $self); exit;
}
if (isset($_GET['csv']) && $result) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="konta_m365_edu_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBFimie;nazwisko;rola;grupa;email;login;haslo\n";
    foreach ($result as $r) echo implode(';', [$r['imie'], $r['nazwisko'], $r['rola'], $r['klasa'], $r['email'] ?? '', $r['upn'], $r['password']]) . "\n";
    exit;
}
if (isset($_GET['pdf']) && $result) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="konta_m365_edu_' . date('Ymd_His') . '.pdf"');
    $_pm = $_GET['pdf'] ?? ''; echo $_pm === 'cards' ? msEduBulkPdfCards($result) : ($_pm === 'strips' ? msEduBulkPdfStrips($result) : ($_pm === 'strips1' ? msEduBulkPdfStrips($result, 'Konta Microsoft 365', 1) : msEduBulkPdf($result))); exit;
}

// ── Stan formularza ──────────────────────────────────────────────────────────
$op     = (string)($_POST['_op'] ?? '');
// Tworzenie kont, ponowienie grup i wysyłka e-maili z hasłami wymagają świeżej weryfikacji IKA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($op, ['utworz', 'anon_utworz', 'retry_groups', 'mail_test', 'mail_send'], true)) msEduBulkIkaGuard();
$grid   = [];
$errors = [];
$year   = preg_match('/^\d{4}$/', $_POST['rok'] ?? '') ? $_POST['rok'] : date('Y');
$domIn  = preg_replace('/[^a-z0-9.\-]/i', '', $_POST['domena'] ?? '') ?: $domain;
$mode   = in_array($_POST['tryb'] ?? '', ['rola', 'imie_nazwisko', 'szkolenie', 'is_skrot', 'in_skrot', 'skrot_is', 'skrot_in'], true) ? $_POST['tryb'] : 'rola';
$abbrIn = trim((string)($_POST['skrot'] ?? ''));
$fixedPass = trim((string)($_POST['haslo_startowe'] ?? ''));
if (($pe = msEduBulkPasswordError($fixedPass)) !== null) { $errors[] = $pe; $fixedPass = ''; $op = ''; }
$dupOver = ($_POST['duplikat'] ?? 'nadpisz') === 'nadpisz'; // duplikat loginu: nadpisz własne konto albo dodaj sufiks
$slug   = trim((string)($_POST['szkolenie'] ?? ''));
$skuSel = ['uczen' => msEduBulkSkuList($_POST['sku_uczen'] ?? []), 'nauczyciel' => msEduBulkSkuList($_POST['sku_nauczyciel'] ?? [])];
$defRole  = msEduBulkRole((string)($_POST['rola_domyslna'] ?? 'uczen')) ?: 'uczen';
$defKlasa = msEduBulkGroupFromPost($_POST);
$tiGroups = msEduBulkTiGroups();
$map    = array_map('strval', (array)($_POST['map'] ?? []));
$header = !empty($_POST['naglowek']);
$plan = null; $res = null; $rowErrors = []; $anPlan = null; $anSkipped = []; $an = ['szk' => '', 'ile' => 10, 'od' => 1, 'sep' => '.', 'pad' => 2];
$mail = $_SESSION['msEduBulkMail'] ?? ['subject' => MS_EDU_BULK_MAIL_SUBJECT, 'body' => MS_EDU_BULK_MAIL_BODY, 'szkolenie' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($op === 'retry_groups' && $result) {
        $rg = msEduBulkRetryGroups($graph, array_column($result, 'upn'));
        if ($rg['fixed']) msEduBulkClearGroupWarn($rg['fixed']);
        flash_set($rg['failed'] ? 'warning' : 'success', 'Grupy MS365 — dodano: ' . count($rg['fixed']) . ($rg['failed'] ? '. Nadal błąd: ' . implode(' | ', array_slice($rg['failed'], 0, 3)) : '.'));
        header('Location: ' . $self); exit;
    }

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
                $rg = msEduBulkRetryGroups($graph, array_column($s['rows'], 'upn')); // ponów grupy po wysyłce maili
                if ($rg['fixed']) { msEduBulkClearGroupWarn($rg['fixed']); flash_set('info', 'Ponowiono dodanie do grup MS365: ' . count($rg['fixed']) . '.'); }
                if ($rg['failed']) flash_set('warning', 'Grupa MS365 — nadal błąd: ' . implode(' | ', array_slice($rg['failed'], 0, 3)));
                flash_set($s['failed'] ? 'warning' : 'success', 'Zakolejkowano wiadomości: ' . $s['sent'] . ($s['failed'] ? '. Błędy: ' . implode(' | ', $s['failed']) : '.'));
            }
        }
        header('Location: ' . $self); exit;
    }

    // Konta anonimowe: {szkolenie}{sep}{nr}
    if (in_array($op, ['anon_podglad', 'anon_utworz'], true)) {
        $an = ['szk' => trim((string)($_POST['an_szkolenie'] ?? '')), 'ile' => (int)($_POST['an_ile'] ?? 0), 'od' => (int)($_POST['an_od'] ?? 1),
               'sep' => (string)($_POST['an_sep'] ?? '.'), 'pad' => (int)($_POST['an_pad'] ?? 2)];
        if (!$graph->is_configured()) $errors[] = 'Integracja Microsoft 365 nie jest skonfigurowana.';
        elseif ($an['ile'] < 1) $errors[] = 'Podaj liczbę kont.';
        else {
            $ap = msEduBulkAnonPlan($graph, $an['szk'], $an['ile'], $an['od'], $an['sep'], $an['pad'], $domIn, ($_POST['an_dup'] ?? 'nadpisz') === 'nadpisz');
            if ($ap['error']) $errors[] = $ap['error'];
            else {
                $anPlan = $ap['rows']; $anSkipped = $ap['skipped'];
                if ($op === 'anon_utworz') {
                    $res = msEduBulkCreate($graph, $anPlan, ['uczen' => $skuSel['uczen'], 'nauczyciel' => []], $year, false, $fixedPass);
                    if ($res['ok']) { $_SESSION['msEduBulkResult'] = $res['ok']; $result = $res['ok']; }
                    $anPlan = null;
                }
            }
        }
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
            $rowErrors = $p['errors']; // błędne wiersze są pomijane, nie blokują reszty
            if (!$p['rows']) $errors[] = 'Brak poprawnych wierszy do utworzenia.';
            else $plan = msEduBulkPlan($graph, $p['rows'], $domIn, $year, $mode, $slug, 1, $abbrIn, $dupOver);
            if ($op === 'utworz' && $plan) {
                $res = msEduBulkCreate($graph, $plan, $skuSel, $year, true, $fixedPass);
                if ($res['ok']) { $_SESSION['msEduBulkResult'] = $res['ok']; $result = $res['ok']; $mail['szkolenie'] = $slug; $_SESSION['msEduBulkMail'] = $mail; }
                $plan = null; $grid = [];
            }
        }
    }
}

include dirname(__DIR__, 2) . '/includes/header.php';
?>
<div class="container-xl py-4">
  <div class="d-flex align-items-center mb-3"><h1 class="h3 fw-bold mb-0"><i class="bi bi-<?= $ms_view === 'kreator' ? 'magic' : 'people-fill' ?> me-2"></i><?= $ms_view === 'kreator' ? 'Kreator kont z listy' : 'Generowanie kont M365 Edu' ?></h1></div>
  <?php include __DIR__ . '/views/tenantInfo.php'; ?>
  <?= msEduBulkTabs(['hub' => ['Generator', $lnk['hub'], 'bi-people-fill'], 'kreator' => ['Kreator z listy (XLS)', $lnk['kreator'], 'bi-magic'], 'konta' => ['Administracja kont', $lnk['konta'], 'bi-person-gear']], $ms_view) ?>
  <?= flash_html() ?>
  <?php if ($ms_view === 'kreator'):
    $_st = $result && !$grid ? 4 : ($plan ? 3 : ($grid ? 2 : 1));
    $_labels = [1 => 'Wczytaj listę', 2 => 'Kolumny i opcje', 3 => 'Podgląd loginów', 4 => 'Konta i e-maile']; ?>
  <ol class="d-flex gap-2 list-unstyled flex-wrap mb-3 small" aria-label="Kroki kreatora">
    <?php foreach ($_labels as $n => $l): ?><li class="badge rounded-pill <?= $n === $_st ? 'text-bg-primary' : ($n < $_st ? 'text-bg-success' : 'text-bg-light border text-muted') ?> px-3 py-2"><?= $n ?>. <?= h($l) ?></li><?php endforeach; ?>
  </ol>
  <?php endif; ?>
  <?php if (m365_edu_ready()): ?>
  <div class="alert alert-info py-2 small"><i class="bi bi-mortarboard me-1"></i>Konta powstają w <strong>tenancie EDU</strong> — domena <code><?= h($domain) ?></code>.</div>
  <?php else: ?>
  <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><strong>Generowanie zablokowane:</strong> tenant EDU nie jest skonfigurowany
    (Dydaktyka 3 → M365, „własny tenant”). Konta nigdy nie powstają w głównym tenancie organizacji.</div>
  <?php endif; ?>
  <?php foreach ($errors as $e): ?><div class="alert alert-danger py-2 small mb-2"><?= h($e) ?></div><?php endforeach; ?>

  <?php if ($res && $res['errors']): ?><div class="alert alert-warning small">Nie utworzono: <?= h(implode(' | ', $res['errors'])) ?></div><?php endif; ?>
  <?php if ($res && !empty($res['groups_created'])): ?><div class="alert alert-info py-2 small"><i class="bi bi-diagram-3 me-1"></i>Utworzono grupy w MS365: <strong><?= h(implode(', ', $res['groups_created'])) ?></strong></div><?php endif; ?>

  <?php if ($result): ?>
  <div class="card mb-4 border-success">
    <div class="card-header d-flex gap-2 align-items-center flex-wrap">
      <span>Wygenerowane konta: <strong><?= count($result) ?></strong> — hasła widoczne tylko tutaj</span>
      <a href="?pdf=1" target="_blank" class="btn btn-sm btn-primary ms-auto"><i class="bi bi-printer me-1"></i>Drukuj PDF</a>
      <a href="?pdf=cards" target="_blank" class="btn btn-sm btn-primary"><i class="bi bi-scissors me-1"></i>Kartki do rozcięcia</a>
      <a href="?pdf=strips" target="_blank" class="btn btn-sm btn-primary"><i class="bi bi-list-nested me-1"></i>Paski: dwa bloki obok siebie</a>
      <a href="?pdf=strips1" target="_blank" class="btn btn-sm btn-primary"><i class="bi bi-list-nested me-1"></i>Paski: jedna kolumna Login | Hasło</a>
      <a href="?csv=1" class="btn btn-sm btn-success"><i class="bi bi-download me-1"></i>CSV</a>
      <?php $_pg = msEduBulkPendingGroups(array_column($result, 'upn')); if ($_pg): ?>
      <form method="post" class="d-inline"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><button name="_op" value="retry_groups" class="btn btn-sm btn-warning"><i class="bi bi-arrow-repeat me-1"></i>Ponów dodanie do grup (<?= $_pg ?>)</button></form>
      <?php endif; ?>
      <a href="?porzuc=1" class="btn btn-sm btn-outline-danger" onclick="return confirm('Porzucić? Lista haseł zniknie z sesji (konta zostają utworzone). Upewnij się, że została wydrukowana lub pobrana.')"><i class="bi bi-x-octagon me-1"></i>Porzuć</a>
    </div>
    <div class="table-responsive"><table class="table table-sm mb-0 font-monospace">
      <thead class="table-light"><tr><th>Osoba</th><th>Grupa</th><th>Login</th><th>Hasło</th><th></th></tr></thead>
      <tbody><?php foreach ($result as $r): ?>
        <tr><td><?= h($r['imie'] . ' ' . $r['nazwisko']) ?></td><td><?= h($r['klasa']) ?></td><td><?= h($r['upn']) ?></td><td><?= h($r['password']) ?></td>
            <td class="text-warning small"><?= h($r['warn'] ?? '') ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
  </div>
  <?php if (array_filter($result, fn($r) => !empty($r['email']))):
    $mbRows = array_values(array_filter($result, fn($r) => !empty($r['email']))); $mbTokenName = '_csrf'; $mbTokenValue = csrf_token(); $mbAction = $self;
    $mbSubject = $mail['subject']; $mbBody = $mail['body']; $mbSzkolenie = $mail['szkolenie'] ?? '';
    include __DIR__ . '/views/mailComposer.php';
  endif; endif; ?>

  <?php if ($ms_view === 'hub'): ?>
  <a href="<?= h($lnk['kreator']) ?>" class="card card-body mb-4 text-decoration-none border-primary">
    <div class="d-flex align-items-center gap-3"><i class="bi bi-file-earmark-spreadsheet fs-2 text-primary"></i>
      <div><div class="h5 mb-0">Kreator: konta z listy (XLS / CSV / wklejka)</div><div class="small text-muted">Wczytaj plik lub wklej tabelę, wskaż kolumny, sprawdź loginy i utwórz konta; potem wyślij dane na e-mail alternatywny.</div></div>
      <i class="bi bi-arrow-right-circle fs-3 ms-auto text-primary"></i></div></a>
  <form method="post" class="card card-body mb-4">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <h2 class="h5">Konta anonimowe <span class="text-muted fw-normal small">— bez danych osobowych, login: nazwa szkolenia + numer</span></h2>
    <div class="row g-3 align-items-end">
      <div class="col-md-3"><label class="form-label small fw-semibold" for="anSzk">Nazwa szkolenia</label>
        <input id="anSzk" name="an_szkolenie" class="form-control" value="<?= h($an['szk']) ?>" placeholder="np. excel"></div>
      <div class="col-md-2"><label class="form-label small fw-semibold" for="anIle">Liczba kont</label>
        <input id="anIle" type="number" min="1" max="200" name="an_ile" class="form-control" value="<?= (int)$an['ile'] ?>"></div>
      <div class="col-md-2"><label class="form-label small fw-semibold" for="anOd">Numer początkowy</label>
        <input id="anOd" type="number" min="1" name="an_od" class="form-control" value="<?= (int)$an['od'] ?>"></div>
      <div class="col-md-2"><label class="form-label small fw-semibold" for="anSep">Separator</label>
        <select id="anSep" name="an_sep" class="form-select">
          <?php foreach (['.' => 'kropka (excel.01)', '' => 'brak (excel01)', '_' => 'podkreślnik (excel_01)', '-' => 'myślnik (excel-01)'] as $k => $l): ?>
          <option value="<?= h($k) ?>"<?= $an['sep'] === (string)$k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="anPad">Cyfry numeru</label>
        <select id="anPad" name="an_pad" class="form-select">
          <?php foreach ([0 => 'bez zer (excel.1)', 2 => '2 cyfry (01)', 3 => '3 cyfry (001)'] as $k => $l): ?>
          <option value="<?= $k ?>"<?= $an['pad'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><?= msEduBulkPasswordField('anHs') ?></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="anDup">Zajęty numer</label>
        <select id="anDup" name="an_dup" class="form-select"><option value="nadpisz"<?= ($_POST['an_dup'] ?? 'nadpisz') === 'nadpisz' ? ' selected' : '' ?>>nadpisz własne konto</option><option value="pomin"<?= ($_POST['an_dup'] ?? '') === 'pomin' ? ' selected' : '' ?>>pomiń numer</option></select></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="anDom">Domena kont</label>
        <input id="anDom" name="domena" class="form-control" value="<?= h($domIn) ?>"></div>
      <?php for ($n = 0; $n < 2; $n++): ?>
      <div class="col-md-4"><label class="form-label small fw-semibold" for="anSku<?= $n ?>">Licencja <?= $n + 1 ?><?= $n ? ' <span class="text-muted fw-normal">(opcjonalnie)</span>' : '' ?></label>
        <select id="anSku<?= $n ?>" name="sku_uczen[]" class="form-select"><option value="">— brak —</option>
          <?php foreach ($skus as $s): ?><option value="<?= h($s['skuId']) ?>"<?= ($skuSel['uczen'][$n] ?? '') === $s['skuId'] ? ' selected' : '' ?>><?= h($s['skuPartNumber']) ?></option><?php endforeach; ?></select></div>
      <?php endfor; ?>
    </div>
    <?php if ($anPlan): ?>
    <div class="mt-3"><div class="fw-semibold small mb-1">Podgląd (<?= count($anPlan) ?>) — nic jeszcze nie utworzono<?= $anSkipped ? '; pominięto zajęte numery: ' . h(implode(', ', $anSkipped)) : '' ?></div>
      <div class="font-monospace small" style="columns:3"><?php foreach ($anPlan as $r): ?><div><?= h($r['upn']) ?><?= !empty($r['nadpisz']) ? ' <span class="badge bg-warning text-dark">nadpisze</span>' : '' ?></div><?php endforeach; ?></div></div>
    <?php endif; ?>
    <div class="d-flex gap-2 mt-3">
      <button name="_op" value="anon_podglad" class="btn btn-outline-primary"><i class="bi bi-eye me-1"></i>Podgląd loginów</button>
      <?php if ($anPlan): ?><a href="?porzuc=1" class="btn btn-outline-danger" onclick="return confirm('Porzucić podgląd? Nic nie zostanie utworzone.')"><i class="bi bi-x-octagon me-1"></i>Porzuć</a><button name="_op" value="anon_utworz" class="btn btn-primary" onclick="return confirm('Utworzyć REALNE konta w tenancie EDU (<?= h($domain) ?>)?')"><i class="bi bi-person-plus me-1"></i>Utwórz <?= count($anPlan) ?> kont</button><?php endif; ?>
    </div>
    <p class="small text-muted mt-2 mb-0">Numeracja pomija numery już zajęte w tenancie. Hasła pokażą się raz — wydrukujesz je do PDF (jedno konto w linii) lub pobierzesz CSV.</p>
  </form>
  <?php endif; ?>

  <?php if ($ms_view === 'kreator' && !$grid): ?>
  <form method="post" enctype="multipart/form-data" class="card card-body">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_op" value="wczytaj">
    <h2 class="h5">1. Wczytaj listę osób</h2>
    <p class="small text-muted mb-2">Skopiuj tabelę z Excela (Ctrl+C) i wklej poniżej (Ctrl+V) <strong>albo</strong> wybierz plik XLSX / CSV. W następnym kroku wskażesz, która kolumna co zawiera.
      Wymagane: imię, nazwisko i <strong>e-mail alternatywny</strong> (na niego wyślemy dane logowania).</p>
    <textarea name="wklej" rows="9" class="form-control font-monospace mb-3" aria-label="Wklej listę osób"
              placeholder="Imię&#9;Nazwisko&#9;E-mail&#9;Rola&#9;Grupa&#10;Jan&#9;Kowalski&#9;jan@gmail.com&#9;uczeń&#9;1A"></textarea>
    <div class="mb-3"><label class="form-label small fw-semibold" for="plik">…lub plik (XLSX, CSV)</label>
      <input id="plik" type="file" name="plik" class="form-control" accept=".xlsx,.csv,.txt"></div>
    <div><button class="btn btn-primary"><i class="bi bi-arrow-right-circle me-1"></i>Dalej — dopasuj kolumny</button></div>
  </form>
  <?php elseif ($ms_view === 'kreator'): ?>
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
      <div class="col-md-3"><?= msEduBulkGroupField($defKlasa, $tiGroups, 'gw', msEduBulkEntraGroups($graph)) ?></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="rok">Rok rozpoczęcia nauki</label>
        <input id="rok" name="rok" class="form-control" value="<?= h($year) ?>" maxlength="4"></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="domena">Domena kont</label>
        <input id="domena" name="domena" class="form-control" value="<?= h($domIn) ?>"></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="tryb">Schemat loginu</label>
        <select id="tryb" name="tryb" class="form-select">
          <?= msEduBulkModeOptions($mode) ?>
        </select></div>
      <div class="col-md-3"><?= msEduBulkPasswordField('hs') ?></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="dup">Duplikat loginu</label>
        <select id="dup" name="duplikat" class="form-select"><option value="nadpisz"<?= $dupOver ? ' selected' : '' ?>>nadpisz istniejące konto (własne)</option><option value="sufiks"<?= !$dupOver ? ' selected' : '' ?>>dodaj sufiks .1, .2…</option></select></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="szkolenie">Nazwa szkolenia</label>
        <input id="szkolenie" name="szkolenie" class="form-control" value="<?= h($slug) ?>"></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="skrot">Skrót szkolenia <span class="text-muted fw-normal">(do loginu; puste = z nazwy: <code><?= h(msEduBulkAbbrev($slug) ?: '—') ?></code>)</span></label>
        <input id="skrot" name="skrot" class="form-control" value="<?= h($abbrIn) ?>" maxlength="12"></div>
      <?php foreach (['uczen' => 'uczniowie', 'nauczyciel' => 'nauczyciele'] as $k => $lbl): for ($n = 0; $n < 2; $n++): ?>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="sku_<?= $k . $n ?>">Licencja <?= $n + 1 ?> — <?= $lbl ?></label>
        <select id="sku_<?= $k . $n ?>" name="sku_<?= $k ?>[]" class="form-select"><option value="">— brak —</option>
          <?php foreach ($skus as $s): ?><option value="<?= h($s['skuId']) ?>"<?= ($skuSel[$k][$n] ?? '') === $s['skuId'] ? ' selected' : '' ?>><?= h($s['skuPartNumber']) ?> (wolne: <?= (int)$s['prepaidUnits']['enabled'] - (int)$s['consumedUnits'] ?>)</option><?php endforeach; ?>
        </select></div>
      <?php endfor; endforeach; ?>
    </div>

    <?php if ($plan): ?>
    <?php if ($rowErrors): ?><div class="alert alert-warning py-2 small"><strong>Pominięte wiersze (<?= count($rowErrors) ?>):</strong><br><?= h(implode(' • ', $rowErrors)) ?></div><?php endif; ?>
    <h2 class="h5">3. Podgląd — nic jeszcze nie utworzono (<?= count($plan) ?>)</h2>
    <div class="table-responsive mb-3"><table class="table table-sm mb-0">
      <thead class="table-light"><tr><th>Osoba</th><th>Rola</th><th>Grupa</th><th>E-mail alternatywny</th><th>Login</th></tr></thead>
      <tbody><?php foreach ($plan as $r): ?><tr><td><?= h($r['imie'] . ' ' . $r['nazwisko']) ?></td><td><?= h($r['rola']) ?></td><td><?= h($r['klasa']) ?></td><td><?= h($r['email']) ?></td><td class="font-monospace"><?= h($r['upn']) ?><?= !empty($r['nadpisz']) ? ' <span class="badge bg-warning text-dark">nadpisze konto</span>' : '' ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>

    <div class="d-flex gap-2 flex-wrap">
      <button name="_op" value="podglad" class="btn btn-outline-primary"><i class="bi bi-eye me-1"></i>Podgląd loginów</button>
      <?php if ($plan): ?>
      <button name="_op" value="utworz" class="btn btn-primary" onclick="return confirm('Utworzyć REALNE konta w tenancie EDU (<?= h($domain) ?>)?')"><i class="bi bi-person-plus me-1"></i>Utwórz <?= count($plan) ?> kont<?= $rowErrors ? ' (pominięto ' . count($rowErrors) . ' błędnych)' : '' ?></button>
      <?php endif; ?>
      <a href="<?= h($self) ?>" class="btn btn-outline-secondary ms-auto">Wczytaj inną listę</a>
      <a href="?porzuc=1" class="btn btn-outline-danger" onclick="return confirm('Porzucić kreator? Wczytane dane i podgląd zostaną odrzucone — nic nie zostanie utworzone.')"><i class="bi bi-x-octagon me-1"></i>Porzuć</a>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer.php';
