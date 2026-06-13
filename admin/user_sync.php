<?php
/**
 * admin/user_sync.php — Panel synchronizacji kont prod → test.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/user_sync.php';

require_role('admin');
$PAGE_TITLE = 'Synchronizacja kont → testy';

$configured = defined('TEST_SYNC_URL') && defined('TEST_SYNC_KEY');

// ── Akcje POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'sync_now' && $configured) {
        $users  = db_all("SELECT name, first_name, last_name, email, password, role, is_active FROM users ORDER BY id");
        $result = user_sync_batch($users);
        if ($result) {
            flash_set('success', sprintf(
                'Synchronizacja zakończona: <strong>%d</strong> utworzonych, <strong>%d</strong> zaktualizowanych, <strong>%d</strong> pominiętych.',
                $result['created'], $result['updated'], $result['skipped']
            ));
        } else {
            flash_set('danger', 'Synchronizacja nie powiodła się lub środowisko testowe jest niedostępne. Sprawdź error_log.');
        }
        header('Location: user_sync.php'); exit;
    }

    if ($act === 'test_connection' && $configured) {
        $result = user_sync_batch([]);
        if ($result !== null || true) {
            // Wyślij pusty payload — poczekaj na odpowiedź
            $url   = rtrim((string)TEST_SYNC_URL, '/') . '/api/internal/user_sync.php';
            $body  = json_encode(['users' => []]);
            $ch    = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS     => 10000,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($body),
                    'X-Sync-Key: ' . TEST_SYNC_KEY,
                ],
            ]);
            $res  = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ms   = (int)curl_getinfo($ch, CURLINFO_TOTAL_TIME_T) / 1000;
            curl_close($ch);

            if ($err) {
                flash_set('danger', 'Błąd połączenia: ' . h($err));
            } elseif ($code !== 200) {
                flash_set('danger', "Środowisko testowe odpowiedziało HTTP {$code}.");
            } else {
                flash_set('success', "Połączenie OK — HTTP 200 w {$ms} ms. Endpoint odbiorczy działa poprawnie.");
            }
        }
        header('Location: user_sync.php'); exit;
    }
}

// ── Dane ──────────────────────────────────────────────────────────────────────
$user_count   = (int)(db_one("SELECT COUNT(*) AS c FROM users")['c'] ?? 0);
$active_count = (int)(db_one("SELECT COUNT(*) AS c FROM users WHERE is_active=1")['c'] ?? 0);

// Odczytaj log ostatniej synchronizacji crona
$log_path = (defined('LOG_PATH') ? rtrim(LOG_PATH, '/') : dirname(__DIR__) . '/logs')
          . '/cron_sync_users_to_test.log';
$log_lines = [];
if (is_readable($log_path)) {
    $all = file($log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $log_lines = array_slice(array_reverse($all), 0, 50);
}

// Czas ostatniej synchronizacji crona (z pliku blokady)
$lock_file  = sys_get_temp_dir() . '/umowy_cron_sync_users_to_test.last';
$last_cron  = file_exists($lock_file) ? (int)file_get_contents($lock_file) : 0;

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item"><a href="users.php">Użytkownicy</a></li>
    <li class="breadcrumb-item active">Sync → testy</li>
  </ol>
</nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0">
    <i class="bi bi-arrow-repeat me-2 text-primary"></i>Synchronizacja kont → środowisko testowe
  </h4>
  <?php if ($configured): ?>
  <div class="d-flex gap-2">
    <form method="post" class="d-inline">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="test_connection">
      <button class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-wifi me-1"></i>Sprawdź połączenie
      </button>
    </form>
    <form method="post" class="d-inline"
          onsubmit="return confirm('Zsynchronizować wszystkie <?= $user_count ?> kont z testem teraz?')">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="sync_now">
      <button class="btn btn-sm btn-primary">
        <i class="bi bi-play-circle me-1"></i>Synchronizuj teraz
      </button>
    </form>
  </div>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if (!$configured): ?>
<!-- ── Stan: brak konfiguracji ── -->
<div class="alert alert-warning d-flex gap-3 align-items-start">
  <i class="bi bi-exclamation-triangle-fill fs-4 mt-1 flex-shrink-0"></i>
  <div>
    <strong>Synchronizacja nie jest skonfigurowana.</strong><br>
    Dodaj poniższe stałe do pliku <code>config.local.php</code> na środowisku <strong>produkcyjnym</strong>
    i do <code>config.local.php</code> na środowisku <strong>testowym</strong> (tylko <code>TEST_SYNC_KEY</code>).<br>
    Wygeneruj klucz poleceniem:
    <code>php -r "echo bin2hex(random_bytes(24));"</code>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-header py-2 fw-semibold small">
    <i class="bi bi-file-code me-1"></i>config.local.php — środowisko PRODUKCYJNE
  </div>
  <div class="card-body p-0">
    <pre class="m-0 p-3 small" style="background:#1e293b;color:#e2e8f0;border-radius:0 0 .375rem .375rem">define('TEST_SYNC_URL', 'https://testy-szo.feer.org.pl');
define('TEST_SYNC_KEY', '<span style="color:#fbbf24">WKLEJ_TUTAJ_WSPOLNY_KLUCZ_MIN_32_ZNAKOW</span>');</pre>
  </div>
</div>
<div class="card shadow-sm">
  <div class="card-header py-2 fw-semibold small">
    <i class="bi bi-file-code me-1"></i>config.local.php — środowisko TESTOWE
  </div>
  <div class="card-body p-0">
    <pre class="m-0 p-3 small" style="background:#1e293b;color:#e2e8f0;border-radius:0 0 .375rem .375rem">define('TEST_SYNC_KEY', '<span style="color:#fbbf24">TEN_SAM_KLUCZ_CO_NA_PRODUKCJI</span>');</pre>
  </div>
</div>

<?php else: ?>
<!-- ── Stan: skonfigurowane ── -->

<!-- Karty statystyk -->
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-md-3">
    <div class="card shadow-sm h-100">
      <div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-people me-1"></i>Kont łącznie</div>
        <div class="fs-3 fw-bold"><?= $user_count ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="card shadow-sm h-100">
      <div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-person-check me-1"></i>Aktywnych</div>
        <div class="fs-3 fw-bold text-success"><?= $active_count ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="card shadow-sm h-100">
      <div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-link-45deg me-1"></i>URL testowy</div>
        <div class="small fw-semibold text-truncate" title="<?= h(TEST_SYNC_URL) ?>"><?= h(TEST_SYNC_URL) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="card shadow-sm h-100">
      <div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-clock me-1"></i>Ostatni CRON</div>
        <div class="small fw-semibold">
          <?php if ($last_cron): ?>
            <?= date('d.m.Y H:i', $last_cron) ?>
            <span class="text-muted">(<?= _relative_time($last_cron) ?>)</span>
          <?php else: ?>
            <span class="text-muted">Brak danych</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Klucz sync -->
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 small fw-semibold d-flex align-items-center justify-content-between">
    <span><i class="bi bi-key me-1"></i>Klucz synchronizacji (TEST_SYNC_KEY)</span>
    <span class="badge bg-success">Skonfigurowany</span>
  </div>
  <div class="card-body py-2 small text-muted">
    Klucz jest ustawiony. Upewnij się, że <strong>ten sam klucz</strong> jest wpisany w
    <code>config.local.php</code> na środowisku testowym.
    Długość: <?= strlen((string)TEST_SYNC_KEY) ?> znaków.
  </div>
</div>

<!-- Log CRON -->
<div class="card shadow-sm">
  <div class="card-header py-2 small fw-semibold d-flex align-items-center justify-content-between">
    <span><i class="bi bi-terminal me-1"></i>Log synchronizacji (ostatnie 50 wpisów)</span>
    <span class="text-muted" style="font-weight:normal">
      <?= $log_lines ? h($log_path) : 'Brak logu' ?>
    </span>
  </div>
  <div class="card-body p-0">
    <?php if ($log_lines): ?>
    <div style="max-height:400px;overflow-y:auto;background:#0f172a;border-radius:0 0 .375rem .375rem">
      <table class="table table-sm mb-0" style="font-family:monospace;font-size:.75rem;border-collapse:collapse">
        <tbody>
        <?php foreach ($log_lines as $line): ?>
          <?php
          $cls   = '';
          $lline = strtolower($line);
          if (str_contains($lline, 'ok'))     $cls = 'color:#4ade80';
          if (str_contains($lline, 'failed')) $cls = 'color:#f87171';
          if (str_contains($lline, 'error'))  $cls = 'color:#f87171';
          ?>
          <tr>
            <td style="padding:.15rem .75rem;color:#cbd5e1;<?= $cls ?>;white-space:pre-wrap;word-break:break-all">
              <?= h($line) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="p-3 text-muted small">
      Brak wpisów w logu. Log pojawi się po pierwszym uruchomieniu przez CRON lub ręcznej synchronizacji.<br>
      <span class="text-muted">Oczekiwana ścieżka: <code><?= h($log_path) ?></code></span>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>

<!-- Lista użytkowników do podglądu -->
<?php if ($configured): ?>
<div class="card shadow-sm mt-3">
  <div class="card-header py-2 small fw-semibold">
    <i class="bi bi-list-ul me-1"></i>Konta objęte synchronizacją
  </div>
  <div class="table-responsive" style="max-height:400px;overflow-y:auto">
    <table class="table table-sm table-hover align-middle mb-0" style="font-size:.8rem">
      <thead class="table-light sticky-top">
        <tr>
          <th>Imię i nazwisko</th>
          <th>E-mail</th>
          <th>Rola</th>
          <th class="text-center">Aktywny</th>
          <th class="text-center">Hasło</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $users = db_all("SELECT name, first_name, last_name, email, role, is_active, password FROM users ORDER BY name");
        foreach ($users as $u):
            $full = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: $u['name'];
        ?>
        <tr>
          <td><?= h($full) ?></td>
          <td class="text-muted"><?= h($u['email']) ?></td>
          <td><span class="badge bg-secondary"><?= h($u['role']) ?></span></td>
          <td class="text-center">
            <?php if ($u['is_active']): ?>
              <i class="bi bi-check-circle-fill text-success"></i>
            <?php else: ?>
              <i class="bi bi-x-circle text-danger"></i>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($u['password']): ?>
              <i class="bi bi-shield-lock-fill text-success" title="Hash ustawiony"></i>
            <?php else: ?>
              <i class="bi bi-shield-x text-warning" title="Brak hasła"></i>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
function _relative_time(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 60)   return 'przed chwilą';
    if ($diff < 3600) return 'ok. ' . round($diff/60) . ' min temu';
    if ($diff < 86400)return 'ok. ' . round($diff/3600) . ' godz. temu';
    return 'ok. ' . round($diff/86400) . ' dni temu';
}
?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
