<?php
/**
 * edok/ksef_sync.php — Synchronizacja KSeF dla EODoK.
 * Reużywa silnika z includes/kdok_ksef.php (autoryzacja, eksport, XML) —
 * to samo połączenie organizacyjne co KDOK — ale tworzy dokumenty EODoK
 * (edok_ksef_create_doc(), tabela edok_ksef_queue), nie kdok_documents.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';
require_once __DIR__ . '/../includes/kdok_ksef.php';

edok_require_role('upload');
edok_migrate();

$ksef_enabled = org_setting('kdok_ksef_enabled') === '1';
$errors = [];
$sync_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'import_ref') {
        $ref = trim($_POST['ksef_reference'] ?? '');
        if (!$ref) {
            flash_set('error', 'Podaj numer referencyjny KSeF.');
        } else {
            $jwt = null;
            try {
                $nip = org_setting('kdok_ksef_nip');
                if (!$nip) throw new RuntimeException('Brak NIP w konfiguracji KSeF.');
                $auth = kdok_ksef_authenticate_auto($nip);
                if (!$auth['ok']) throw new RuntimeException($auth['error'] ?? 'nieznany błąd autoryzacji');
                $jwt = $auth['token'];

                $existing = db_one("SELECT * FROM edok_ksef_queue WHERE ksef_reference=?", [$ref]);
                if ($existing && $existing['doc_id']) {
                    kdok_ksef_session_terminate($jwt);
                    flash_set('info', 'Faktura już w systemie — dokument EODoK #' . $existing['doc_id'] . '.');
                    header('Location: ' . APP_URL . '/edok/view.php?id=' . $existing['doc_id']);
                    exit;
                }

                $xml = kdok_ksef_get_invoice_xml($jwt, $ref);
                kdok_ksef_session_terminate($jwt);
                $jwt = null;
                $data = kdok_ksef_parse_xml($xml);
                $data['ksef_reference'] = $ref;

                db_insert('edok_ksef_queue', [
                    'ksef_reference' => $ref,
                    'invoice_number' => $data['invoice_number'] ?? '',
                    'seller_name'    => $data['seller_name']    ?? '',
                    'seller_nip'     => $data['seller_nip']     ?? '',
                    'gross_value'    => $data['gross_value']    ?? '',
                    'currency'       => $data['currency']       ?? 'PLN',
                    'issue_date'     => $data['issue_date']     ?? '',
                    'ksef_date'      => date('Y-m-d'),
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);
                $doc_id = edok_ksef_create_doc($data);
                db_exec("UPDATE edok_ksef_queue SET doc_id=? WHERE ksef_reference=?", [$doc_id, $ref]);

                flash_set('success', 'Dokument wprowadzony do obiegu EODoK.');
                header('Location: ' . APP_URL . '/edok/view.php?id=' . $doc_id);
                exit;
            } catch (\Throwable $e) {
                if ($jwt) { try { kdok_ksef_session_terminate($jwt); } catch (\Throwable $_) {} }
                flash_set('error', 'Błąd KSeF: ' . $e->getMessage());
            }
        }
        header('Location: ' . APP_URL . '/edok/ksef_sync.php');
        exit;
    }

    if ($action === 'sync' || $action === 'sync_all') {
        if (!$ksef_enabled) {
            flash_set('error', 'Integracja KSeF jest wyłączona. Włącz ją w ustawieniach KDOK przed synchronizacją (połączenie jest wspólne).');
            header('Location: ' . APP_URL . '/edok/ksef_sync.php');
            exit;
        }
        try {
            if ($action === 'sync_all') {
                $from = trim($_POST['sync_from'] ?? '') ?: date('Y-m-d', strtotime('-12 months'));
                $to   = trim($_POST['sync_to']   ?? '') ?: date('Y-m-d');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                    throw new \InvalidArgumentException('Nieprawidłowy format daty (wymagany: RRRR-MM-DD).');
                }
                if ($from > $to) throw new \InvalidArgumentException('Data od musi być wcześniejsza niż data do.');
                $sync_result = kdok_ksef_sync_range($from, $to, 'buyer', 'edok_ksef_queue', 'edok_ksef_create_doc');
                $label = "Pobieranie wszystkich ({$from} – {$to})";
            } else {
                $from = date('Y-m-d', strtotime('-30 days'));
                $to   = date('Y-m-d');
                $sync_result = kdok_ksef_sync_export($from, $to, 'edok_ksef_queue', 'edok_ksef_create_doc');
                $label = 'Synchronizacja przyrostowa (30 dni)';
            }
            $imported = (int)($sync_result['imported'] ?? 0);
            $skipped  = (int)($sync_result['skipped']  ?? 0);
            $sync_errors  = $sync_result['errors']  ?? [];
            $sync_notices = $sync_result['notices'] ?? [];
            $msg = "{$label} zakończona. Zaimportowano: {$imported}, pominięto: {$skipped}.";
            if ($sync_errors) {
                flash_set('warning', $msg . ' Błędy: ' . count($sync_errors) . ' — ' . implode('; ', array_slice($sync_errors, 0, 3)));
            } elseif ($imported === 0 && $sync_notices) {
                // Zero zaimportowanych bez błędów wygląda jak sukces, ale najczęściej znaczy
                // "KSeF nie miał czego zwrócić" — pokazujemy dlaczego, zamiast milczącego zera.
                flash_set('info', $msg . ' ' . implode(' ', array_slice($sync_notices, 0, 2)));
            } else {
                flash_set('success', $msg);
            }
        } catch (\Throwable $e) {
            flash_set('error', 'Błąd podczas synchronizacji: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/edok/ksef_sync.php');
        exit;
    }
}

$queue = db_all("SELECT * FROM edok_ksef_queue ORDER BY id DESC LIMIT 50");

$PAGE_TITLE = 'Synchronizacja KSeF — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-cloud-download"></i> Synchronizacja KSeF — EODoK</h4>
</div>

<?= flash_html() ?>

<?php if (!$ksef_enabled): ?>
<div class="alert alert-warning">
  Integracja KSeF jest wyłączona. Skonfiguruj ją w <a href="<?= APP_URL ?>/admin/kdok_ksef_settings.php">ustawieniach KSeF</a> (połączenie wspólne z KDOK).
</div>
<?php else: ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card shadow-sm mb-3">
      <div class="card-header py-2"><strong>Import po numerze referencyjnym</strong></div>
      <div class="card-body">
        <form method="post" class="d-flex gap-2">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="import_ref">
          <input type="text" name="ksef_reference" class="form-control" placeholder="Numer referencyjny KSeF" required>
          <button type="submit" class="btn btn-primary text-nowrap"><i class="bi bi-download"></i> Pobierz</button>
        </form>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header py-2"><strong>Synchronizacja przyrostowa</strong></div>
      <div class="card-body">
        <p class="text-muted small">Pobiera nowe faktury z ostatnich 30 dni, pomijając te już zaimportowane.</p>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="sync">
          <button type="submit" class="btn btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Synchronizuj (30 dni)</button>
        </form>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header py-2"><strong>Pobierz z zakresu dat</strong></div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="sync_all">
          <div class="col-sm-5">
            <label class="form-label small">Od</label>
            <input type="date" name="sync_from" class="form-control form-control-sm" value="<?= h(date('Y-m-d', strtotime('-3 months'))) ?>">
          </div>
          <div class="col-sm-5">
            <label class="form-label small">Do</label>
            <input type="date" name="sync_to" class="form-control form-control-sm" value="<?= h(date('Y-m-d')) ?>">
          </div>
          <div class="col-sm-2">
            <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-download"></i></button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-header py-2"><strong>Ostatnio zaimportowane</strong></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 small">
          <thead class="table-light"><tr><th>Nr referencyjny</th><th>Sprzedawca</th><th class="text-end">Brutto</th><th></th></tr></thead>
          <tbody>
            <?php if (!$queue): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">Brak.</td></tr>
            <?php endif; ?>
            <?php foreach ($queue as $q): ?>
            <tr>
              <td class="font-monospace text-truncate" style="max-width:160px"><?= h($q['ksef_reference']) ?></td>
              <td><?= h($q['seller_name']) ?></td>
              <td class="text-end font-monospace"><?= h($q['gross_value']) ?> <?= h($q['currency']) ?></td>
              <td><?php if ($q['doc_id']): ?><a href="<?= APP_URL ?>/edok/view.php?id=<?= $q['doc_id'] ?>" class="btn btn-sm btn-outline-primary py-0"><i class="bi bi-eye"></i></a><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
