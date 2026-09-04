<?php
/**
 * ksiegowosc/ksef_sync.php — Ręczna synchronizacja KSeF (EOD Dokumentów Księgowych).
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';
require_once __DIR__ . '/../includes/kdok_ksef.php';

require_login();

// Przeniesiony do EODoK — nowe faktury z KSeF importują się tam.
header('Location: ' . APP_URL . '/edok/ksef_sync.php');
exit;

if (!kdok_has_role('upload') && !is_admin()) {
    flash_set('error', 'Brak uprawnień do tej strony.');
    header('Location: ' . APP_URL . '/ksiegowosc/index.php');
    exit;
}

kdok_migrate();
kdok_ksef_migrate();

$PAGE_TITLE = 'Synchronizacja KSeF — EOD Dokumentów Księgowych';

// ── Odczyt ustawień ───────────────────────────────────────────────────────────

$ksef_enabled   = org_setting('kdok_ksef_enabled') === '1';
$ksef_env       = org_setting('kdok_ksef_env') ?: 'prod';
$ksef_env_cfg   = KSEF_ENVS[$ksef_env] ?? KSEF_ENVS['production'];
$ksef_nip       = org_setting('kdok_ksef_nip');
$ksef_last_sync = org_setting('kdok_ksef_last_sync');

// ── Obsługa POST ──────────────────────────────────────────────────────────────

$sync_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Pobieranie z KSeF nie wymaga IKA/IKAKS — wystarczy zalogowanie
    // i rola upload/admin (sprawdzone na wejściu do strony). Operacje są
    // wyłącznie odczytem z API KSeF + wprowadzeniem faktur do obiegu EOD.

    // Import ręczny po numerze referencyjnym KSeF
    if ($action === 'import_ref') {
        $ref = trim($_POST['ksef_reference'] ?? '');
        if (!$ref) {
            flash_set('error', 'Podaj numer referencyjny KSeF.');
            header('Location: ' . APP_URL . '/ksiegowosc/ksef_sync.php#import-ref');
            exit;
        }
        try {
            kdok_ksef_migrate();
            // Sprawdź czy już w kolejce
            $existing = kdok_one("SELECT * FROM kdok_ksef_queue WHERE ksef_reference=?", [$ref]);
            if ($existing && $existing['doc_id']) {
                flash_set('info', 'Faktura już w systemie — dokument EOD #' . $existing['doc_id'] . '.');
                header('Location: ' . APP_URL . '/ksiegowosc/view.php?id=' . $existing['doc_id']);
                exit;
            }
            if ($existing && !$existing['doc_id']) {
                // Jest w kolejce, ale bez dokumentu — utwórz dokument
                $data = (array)$existing;
                $doc_id = kdok_ksef_create_doc($data);
                kdok_exec("UPDATE kdok_ksef_queue SET doc_id=? WHERE ksef_reference=?", [$doc_id, $ref]);
                flash_set('success', 'Faktura była już w kolejce — wprowadzono do obiegu EOD.');
                header('Location: ' . APP_URL . '/ksiegowosc/view.php?id=' . $doc_id);
                exit;
            }
            // Pobierz z KSeF
            $client = kdok_ksef_client();
            $xml    = kdok_ksef_get_invoice_xml(null, $ref);
            $data   = kdok_ksef_parse_xml($xml);
            $data['ksef_reference'] = $ref;

            kdok_insert('kdok_ksef_queue', [
                'ksef_reference' => $ref,
                'invoice_number' => $data['invoice_number'] ?? '',
                'seller_name'    => $data['seller_name']    ?? '',
                'seller_nip'     => $data['seller_nip']     ?? '',
                'gross_value'    => $data['gross_value']     ?? '',
                'currency'       => $data['currency']        ?? 'PLN',
                'issue_date'     => $data['issue_date']      ?? '',
                'ksef_date'      => date('Y-m-d'),
            ]);
            $doc_id = kdok_ksef_create_doc($data);
            kdok_exec("UPDATE kdok_ksef_queue SET doc_id=? WHERE ksef_reference=?", [$doc_id, $ref]);
            flash_set('success', 'Faktura zaimportowana i wprowadzona do obiegu EOD.');
            header('Location: ' . APP_URL . '/ksiegowosc/view.php?id=' . $doc_id);
            exit;
        } catch (\Throwable $e) {
            flash_set('error', 'Błąd importu: ' . $e->getMessage());
            header('Location: ' . APP_URL . '/ksiegowosc/ksef_sync.php#import-ref');
            exit;
        }
    }

    // Wprowadź pozycję z kolejki do obiegu (utwórz dokument KDOK)
    if ($action === 'introduce') {
        $ref = trim($_POST['ksef_reference'] ?? '');
        if (!$ref) {
            flash_set('error', 'Brak numeru referencyjnego KSeF.');
        } else {
            try {
                kdok_ksef_migrate();
                $row = kdok_one("SELECT * FROM kdok_ksef_queue WHERE ksef_reference=?", [$ref]);
                if (!$row) throw new RuntimeException('Nie znaleziono pozycji w kolejce.');
                if ($row['doc_id']) {
                    flash_set('info', 'Dokument już istnieje w obiegu.');
                } else {
                    $data = [
                        'ksef_reference' => $row['ksef_reference'],
                        'invoice_number' => $row['invoice_number'],
                        'seller_name'    => $row['seller_name'],
                        'seller_nip'     => $row['seller_nip'],
                        'gross_value'    => $row['gross_value'],
                        'currency'       => $row['currency'],
                        'issue_date'     => $row['issue_date'],
                    ];
                    $doc_id = kdok_ksef_create_doc($data);
                    kdok_exec("UPDATE kdok_ksef_queue SET doc_id=? WHERE ksef_reference=?", [$doc_id, $ref]);
                    flash_set('success', 'Dokument wprowadzony do obiegu.');
                    header('Location: ' . APP_URL . '/ksiegowosc/view.php?id=' . $doc_id);
                    exit;
                }
            } catch (\Throwable $e) {
                flash_set('error', 'Błąd: ' . $e->getMessage());
            }
        }
        header('Location: ' . APP_URL . '/ksiegowosc/ksef_sync.php');
        exit;
    }

    if ($action === 'sync' || $action === 'sync_all') {
        if (!$ksef_enabled) {
            flash_set('error', 'Integracja KSeF jest wyłączona. Włącz ją w ustawieniach przed synchronizacją.');
            header('Location: ' . APP_URL . '/ksiegowosc/ksef_sync.php');
            exit;
        }

        try {
            kdok_ksef_migrate();

            if ($action === 'sync_all') {
                $from = trim($_POST['sync_from'] ?? '');
                $to   = trim($_POST['sync_to']   ?? date('Y-m-d'));
                if (!$from) $from = date('Y-m-d', strtotime('-12 months'));
                // Walidacja dat
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                    throw new \InvalidArgumentException('Nieprawidłowy format daty (wymagany: RRRR-MM-DD).');
                }
                if ($from > $to) throw new \InvalidArgumentException('Data od musi być wcześniejsza niż data do.');
                $sync_result = kdok_ksef_sync_range($from, $to);
                kdok_ksef_setting_save('kdok_ksef_last_sync', date('Y-m-d H:i:s'));
                $label = "Pobieranie wszystkich ({$from} – {$to})";
            } else {
                $sync_result = kdok_ksef_sync();
                $label = 'Synchronizacja przyrostowa';
            }

            $imported = (int)($sync_result['imported'] ?? 0);
            $skipped  = (int)($sync_result['skipped']  ?? 0);
            $errors   = $sync_result['errors'] ?? [];

            $msg = "{$label} zakończona. Zaimportowano: {$imported}, pominięto: {$skipped}.";
            if (empty($errors)) {
                flash_set('success', $msg);
            } else {
                flash_set('warning', $msg . ' Błędy: ' . count($errors) . ' — ' . h(implode('; ', array_slice($errors, 0, 3))));
            }
        } catch (\Throwable $e) {
            flash_set('error', 'Błąd podczas synchronizacji: ' . $e->getMessage());
        }

        header('Location: ' . APP_URL . '/ksiegowosc/ksef_sync.php');
        exit;
    }
}

// ── Kolejka KSeF — ostatnie 50 wpisów z paginacją ────────────────────────────

$per_page = 50;
$page     = max(1, (int)($_GET['page'] ?? 1));

$total_queue = 0;
$queue_rows  = [];

try {
    $total_queue = (int)(kdok_one("SELECT COUNT(*) AS c FROM kdok_ksef_queue")['c'] ?? 0);
    $pag         = paginate($total_queue, $per_page, $page, '?');
    $queue_rows  = kdok_all(
        "SELECT * FROM kdok_ksef_queue ORDER BY id DESC LIMIT ? OFFSET ?",
        [$per_page, $pag['offset']]
    );
} catch (\Throwable $e) {
    // Tabela może jeszcze nie istnieć — zignoruj
    $pag = paginate(0, $per_page, 1, '?');
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Nagłówek strony -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold d-flex align-items-center gap-2">
      <i class="bi bi-arrow-repeat text-primary"></i>Synchronizacja KSeF
      <?php if ($ksef_env === 'test'): ?>
        <span class="badge bg-warning text-dark fs-6"><i class="bi bi-flask"></i> TEST</span>
      <?php else: ?>
        <span class="badge bg-danger fs-6">PROD</span>
      <?php endif; ?>
    </h4>
    <p class="text-muted small mb-0">
      EOD Dokumentów Księgowych &nbsp;·&nbsp; <?= h($ksef_env_cfg['label']) ?>
    </p>
  </div>
  <a href="<?= APP_URL ?>/ksiegowosc/index.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Powrót do listy
  </a>
</div>

<?= flash_html() ?>

<!-- ── Sekcja status ──────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">

  <!-- Status integracji -->
  <div class="col-md-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header fw-semibold small">
        <i class="bi bi-toggle-on me-1 text-primary"></i>Status integracji
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <tbody>
            <tr>
              <td class="text-muted ps-3 py-2" style="width:55%">KSeF włączony</td>
              <td class="fw-semibold py-2">
                <?php if ($ksef_enabled): ?>
                  <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Tak</span>
                <?php else: ?>
                  <span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i>Nie</span>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <td class="text-muted ps-3 py-2">NIP organizacji</td>
              <td class="fw-semibold py-2 font-monospace">
                <?= $ksef_nip ? h($ksef_nip) : '<span class="text-muted">—</span>' ?>
              </td>
            </tr>
            <tr>
              <td class="text-muted ps-3 py-2">Ostatnia synchronizacja</td>
              <td class="fw-semibold py-2">
                <?php if ($ksef_last_sync): ?>
                  <?php
                    $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $ksef_last_sync)
                          ?: \DateTime::createFromFormat('U', $ksef_last_sync)
                          ?: null;
                    echo $dt ? h($dt->format('d.m.Y H:i')) : h($ksef_last_sync);
                  ?>
                <?php else: ?>
                  <span class="text-muted">Nigdy</span>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <td class="text-muted ps-3 py-2">Wpisów w kolejce</td>
              <td class="fw-semibold py-2">
                <?php if ($total_queue > 0): ?>
                  <span class="badge bg-warning text-dark"><?= $total_queue ?></span>
                <?php else: ?>
                  <span class="text-success"><i class="bi bi-check-circle me-1"></i>0</span>
                <?php endif; ?>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Akcja lub ostrzeżenie -->
  <div class="col-md-8">
    <?php if (!$ksef_enabled): ?>
    <!-- Integracja wyłączona -->
    <div class="alert alert-warning h-100 d-flex flex-column justify-content-center mb-0">
      <div class="d-flex align-items-start gap-3">
        <i class="bi bi-exclamation-triangle-fill fs-3 flex-shrink-0 mt-1"></i>
        <div>
          <h6 class="alert-heading fw-bold mb-1">Integracja KSeF jest wyłączona</h6>
          <p class="mb-2">
            Aby pobrać dokumenty z Krajowego Systemu e-Faktur, włącz integrację
            i skonfiguruj NIP oraz token autoryzujący.
          </p>
          <?php if (is_admin()): ?>
          <a href="<?= APP_URL ?>/admin/kdok_ksef_settings.php" class="btn btn-warning btn-sm">
            <i class="bi bi-gear me-1"></i>Przejdź do ustawień KSeF
          </a>
          <?php else: ?>
          <p class="mb-0 small text-muted">Skontaktuj się z administratorem systemu.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php else: ?>
    <!-- Integracja włączona — przycisk synchronizacji -->
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header fw-semibold small">
        <i class="bi bi-cloud-download me-1 text-primary"></i>Synchronizacja ręczna
      </div>
      <div class="card-body d-flex flex-column justify-content-between">
        <div>
          <p class="mb-2 small text-muted">
            Kliknij poniższy przycisk, aby pobrać nowe dokumenty z KSeF i zaimportować je
            do kolejki EOD. Dokumenty już obecne w systemie zostaną pominięte.
          </p>
          <div class="alert alert-info small py-2 mb-3">
            <i class="bi bi-hourglass-split me-1"></i>
            <strong>Uwaga:</strong> synchronizacja może chwilę zająć — nie zamykaj tej strony
            podczas jej trwania.
          </div>
        </div>
        <!-- Synchronizacja przyrostowa -->
        <form method="post" id="syncForm" class="mb-3">
          <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
          <input type="hidden" name="action"     value="sync">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <button type="submit" class="btn btn-success" id="syncBtn">
              <span id="syncBtnLabel"><i class="bi bi-arrow-repeat me-1"></i>Synchronizuj od ostatniego razu</span>
              <span id="syncBtnSpinner" class="d-none">
                <span class="spinner-border spinner-border-sm me-1"></span>Synchronizacja…
              </span>
            </button>
            <?php if ($ksef_last_sync): ?>
            <span class="text-muted small">od <?= h(date('d.m.Y', strtotime($ksef_last_sync))) ?></span>
            <?php endif; ?>
          </div>
        </form>

        <hr class="my-2">

        <!-- Pobierz wszystkie z KSeF -->
        <p class="small fw-semibold mb-2"><i class="bi bi-cloud-download me-1 text-primary"></i>Pobierz wszystkie z KSeF</p>
        <form method="post" id="syncAllForm">
          <input type="hidden" name="_csrf"       value="<?= csrf_token() ?>">
          <input type="hidden" name="action"      value="sync_all">
          <div class="row g-2 align-items-end mb-2">
            <div class="col-auto">
              <label class="form-label small mb-1">Od</label>
              <input type="date" name="sync_from" class="form-control form-control-sm"
                     value="<?= h(date('Y-m-d', strtotime('-12 months'))) ?>"
                     max="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-auto">
              <label class="form-label small mb-1">Do</label>
              <input type="date" name="sync_to" class="form-control form-control-sm"
                     value="<?= h(date('Y-m-d')) ?>"
                     max="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-auto">
              <button type="submit" class="btn btn-primary btn-sm" id="syncAllBtn"
                      onclick="return confirm('Pobrać wszystkie faktury z KSeF z wybranego okresu? Może to potrwać kilka minut.')">
                <span id="syncAllLabel"><i class="bi bi-cloud-arrow-down me-1"></i>Pobierz wszystkie</span>
                <span id="syncAllSpinner" class="d-none">
                  <span class="spinner-border spinner-border-sm me-1"></span>Pobieranie…
                </span>
              </button>
            </div>
          </div>
          <div class="form-text">
            <i class="bi bi-info-circle me-1"></i>
            Zapytania są dzielone na 3-miesięczne przedziały (limit API KSeF).
            Faktury już obecne w systemie zostaną pominięte.
          </div>
        </form>

        <?php if (is_admin()): ?>
        <div class="mt-3">
          <a href="<?= APP_URL ?>/admin/kdok_ksef_settings.php"
             class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-gear me-1"></i>Ustawienia KSeF
          </a>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /row status -->

<?php if ($ksef_enabled): ?>
<!-- ── Ręczny import po numerze referencyjnym ──────────────────────────────── -->
<div class="card shadow-sm mb-3" id="import-ref">
  <div class="card-header fw-semibold small">
    <i class="bi bi-upc-scan me-1 text-primary"></i>Ręczny import faktury z KSeF
  </div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Wpisz lub wklej numer referencyjny KSeF faktury — system pobierze ją bezpośrednio
      z API i wprowadzi do obiegu EOD. Jeśli faktura jest już w kolejce, zostanie tylko
      przypisana do obiegu bez ponownego pobierania.
    </p>
    <form method="post" id="importRefForm" class="d-flex gap-2 flex-wrap align-items-start">
      <input type="hidden" name="_csrf"       value="<?= csrf_token() ?>">
      <input type="hidden" name="action"      value="import_ref">
      <div style="flex:1;min-width:280px">
        <input type="text" name="ksef_reference" id="ksef_ref_input"
               class="form-control font-monospace"
               placeholder="np. 1234560000-20260604-ABCDEF123456-AA"
               autocomplete="off" required>
        <div class="form-text">Numer referencyjny z potwierdzenia UPO lub panelu KSeF.</div>
      </div>
      <div>
        <button type="submit" class="btn btn-primary" id="importRefBtn">
          <span id="importRefLabel"><i class="bi bi-cloud-download me-1"></i>Pobierz i importuj</span>
          <span id="importRefSpinner" class="d-none">
            <span class="spinner-border spinner-border-sm me-1"></span>Pobieranie…
          </span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── Tabela kolejki KSeF ─────────────────────────────────────────────────── -->
<div class="card shadow-sm">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span class="fw-semibold small">
      <i class="bi bi-list-ul me-1 text-primary"></i>
      Kolejka KSeF — ostatnie <?= $per_page ?> wpisów
      <?php if ($total_queue > 0): ?>
        <span class="badge bg-secondary ms-1"><?= $total_queue ?></span>
      <?php endif; ?>
    </span>
  </div>

  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle small">
      <thead class="table-light">
        <tr>
          <th style="width:18%">Nr ref. KSeF</th>
          <th style="width:12%">Nr faktury</th>
          <th>Sprzedawca</th>
          <th style="width:10%">NIP sprzedawcy</th>
          <th style="width:9%" class="text-end">Kwota brutto</th>
          <th style="width:5%" class="text-center">Waluta</th>
          <th style="width:9%">Data wystawienia</th>
          <th style="width:10%" class="text-center">Dokument EOD</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$queue_rows): ?>
          <tr>
            <td colspan="8" class="py-5">
              <div class="text-center text-muted">
                <i class="bi bi-cloud-slash fs-2 d-block mb-2"></i>
                <strong>Brak faktur w kolejce KSeF</strong>
                <p class="small mt-1 mb-2">
                  Kolejka jest pusta — nie pobrano jeszcze żadnych faktur
                  lub KSeF nie ma faktur dla NIP <code><?= h($ksef_nip) ?></code>
                  w wybranym okresie.
                </p>
                <ul class="list-unstyled small text-start d-inline-block">
                  <li><i class="bi bi-check2 text-success me-1"></i>Sprawdź czy NIP jest poprawny (oba środowiska mają osobne dane)</li>
                  <li><i class="bi bi-check2 text-success me-1"></i>Użyj przycisku <strong>Pobierz wszystkie</strong> z szerszym zakresem dat</li>
                  <li><i class="bi bi-check2 text-success me-1"></i>Faktury w KSeF muszą być wystawione od <strong>2024-07-01</strong> (duże firmy)</li>
                  <li><i class="bi bi-check2 text-success me-1"></i>Środowisko testowe: <a href="https://ksef-test.mf.gov.pl" target="_blank">ksef-test.mf.gov.pl</a> → generuj faktury testowe</li>
                </ul>
              </div>
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($queue_rows as $row): ?>
          <?php
            $ref     = $row['ksef_reference'] ?? '';
            $ref_short = strlen($ref) > 30 ? substr($ref, 0, 27) . '…' : $ref;
            $inv_no  = $row['invoice_number'] ?? '';
            $seller  = $row['seller_name']    ?? '';
            $nip     = $row['seller_nip']     ?? '';
            $gross   = $row['gross_value']    ?? null;
            $curr    = $row['currency']       ?? 'PLN';
            $idate   = $row['issue_date']     ?? '';
            $doc_id  = isset($row['doc_id']) ? (int)$row['doc_id'] : null;

            // Format daty wystawienia
            $idate_fmt = '';
            if ($idate) {
                $dt = \DateTime::createFromFormat('Y-m-d', substr($idate, 0, 10));
                $idate_fmt = $dt ? $dt->format('d.m.Y') : h($idate);
            }

            // Kwota
            $gross_fmt = ($gross !== null && $gross !== '')
                ? number_format((float)$gross, 2, ',', ' ')
                : '—';
          ?>
          <tr>
            <td class="font-monospace text-truncate" style="max-width:170px"
                title="<?= h($ref) ?>"><?= h($ref_short) ?></td>
            <td class="font-monospace text-truncate" style="max-width:120px"
                title="<?= h($inv_no) ?>"><?= h($inv_no) ?: '<span class="text-muted">—</span>' ?></td>
            <td class="text-truncate" style="max-width:180px"
                title="<?= h($seller) ?>"><?= h($seller) ?: '<span class="text-muted">—</span>' ?></td>
            <td class="font-monospace"><?= h($nip) ?: '<span class="text-muted">—</span>' ?></td>
            <td class="text-end font-monospace"><?= h($gross_fmt) ?></td>
            <td class="text-center"><?= h($curr) ?></td>
            <td><?= $idate_fmt ?: '<span class="text-muted">—</span>' ?></td>
            <td class="text-center">
              <?php if ($doc_id): ?>
                <a href="<?= APP_URL ?>/ksiegowosc/view.php?id=<?= $doc_id ?>"
                   class="btn btn-outline-primary btn-sm py-0 px-2" title="Otwórz dokument EOD #<?= $doc_id ?>">
                  <i class="bi bi-eye me-1"></i>#<?= $doc_id ?>
                </a>
              <?php else: ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf"           value="<?= csrf_token() ?>">
                  <input type="hidden" name="action"          value="introduce">
                  <input type="hidden" name="ksef_reference"  value="<?= h($ref) ?>">
                  <button type="submit" class="btn btn-success btn-sm py-0 px-2"
                          title="Wprowadź do obiegu EOD">
                    <i class="bi bi-play-circle me-1"></i>Wprowadź
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (($pag['pages'] ?? 1) > 1): ?>
  <div class="card-footer border-top-0 pt-2 pb-2">
    <?= pagination_html($pag) ?>
  </div>
  <?php endif; ?>
</div><!-- /card kolejka -->
<?php endif; // $ksef_enabled ?>

<!-- Spinner JS — blokuj formularz podczas wysyłania -->
<script>
(function () {
  function bindSpinner(formId, btnId, labelId, spinId) {
    var form = document.getElementById(formId);
    if (!form) return;
    form.addEventListener('submit', function () {
      var btn   = document.getElementById(btnId);
      var label = document.getElementById(labelId);
      var spin  = document.getElementById(spinId);
      if (btn)   btn.disabled = true;
      if (label) label.classList.add('d-none');
      if (spin)  spin.classList.remove('d-none');
    });
  }
  bindSpinner('syncForm',     'syncBtn',       'syncBtnLabel',    'syncBtnSpinner');
  bindSpinner('syncAllForm',  'syncAllBtn',    'syncAllLabel',    'syncAllSpinner');
  bindSpinner('importRefForm','importRefBtn',  'importRefLabel',  'importRefSpinner');

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
