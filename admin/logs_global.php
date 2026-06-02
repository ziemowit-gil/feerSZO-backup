<?php
/**
 * admin/logs_global.php — Globalna przeglądarka logów systemu
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth_security.php';

require_role('admin');
$PAGE_TITLE = 'Przeglądarka logów';

const PER_PAGE = 50;

$tab    = $_GET['tab'] ?? 'auth';
$flash  = '';
$flash_type = 'success';

// ── Obsługa akcji POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'clear_auth') {
        try { db()->exec("DELETE FROM login_log"); $flash = 'Logi logowania wyczyszczone.'; }
        catch (\Throwable $e) { $flash = 'Błąd: ' . h($e->getMessage()); $flash_type = 'danger'; }
        $tab = 'auth';
    }
    elseif ($action === 'clear_system') {
        try { db()->exec("DELETE FROM system_log"); $flash = 'Logi systemowe wyczyszczone.'; }
        catch (\Throwable $e) { $flash = 'Błąd: ' . h($e->getMessage()); $flash_type = 'danger'; }
        $tab = 'system';
    }
    elseif ($action === 'export_auth') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="login_log_' . date('Ymd') . '.csv"');
        $fh = fopen('php://output', 'w');
        fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($fh, ['ID','Użytkownik','E-mail','IP','User Agent','Akcja','Szczegóły','Data'], ';');
        try {
            $rows = db()->query("SELECT l.*, u.name FROM login_log l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.created_at DESC")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                fputcsv($fh, [$r['id'], $r['name'] ?? '', $r['email'] ?? '', $r['ip'] ?? '', $r['user_agent'] ?? '', $r['action'] ?? '', $r['detail'] ?? '', $r['created_at'] ?? ''], ';');
            }
        } catch (\Throwable $e) {}
        fclose($fh);
        exit;
    }
    elseif ($action === 'export_audit') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="contract_audit_log_' . date('Ymd') . '.csv"');
        $fh = fopen('php://output', 'w');
        fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($fh, ['ID','Typ umowy','ID umowy','Użytkownik','Akcja','Notatka','Data'], ';');
        try {
            $rows = db()->query("SELECT l.*, u.name FROM contract_audit_log l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.created_at DESC")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                fputcsv($fh, [$r['id'], $r['contract_type'] ?? '', $r['contract_id'] ?? '', $r['name'] ?? '', $r['action'] ?? '', $r['note'] ?? '', $r['created_at'] ?? ''], ';');
            }
        } catch (\Throwable $e) {}
        fclose($fh);
        exit;
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=' . urlencode($tab));
    exit;
}

// ── Filtry ────────────────────────────────────────────────────────────────────
$search   = trim($_GET['q'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to'] ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$offset    = ($page - 1) * PER_PAGE;

function build_date_where(string $col, string $from, string $to, array &$params): string {
    $parts = [];
    if ($from) { $parts[] = "DATE($col) >= ?"; $params[] = $from; }
    if ($to)   { $parts[] = "DATE($col) <= ?"; $params[] = $to;   }
    return $parts ? ' AND ' . implode(' AND ', $parts) : '';
}

// ── Count badges ──────────────────────────────────────────────────────────────
$cnt_auth   = 0; try { $cnt_auth   = (int)db()->query("SELECT COUNT(*) FROM login_log")->fetchColumn(); } catch (\Throwable $e) {}
$cnt_audit  = 0; try { $cnt_audit  = (int)db()->query("SELECT COUNT(*) FROM contract_audit_log")->fetchColumn(); } catch (\Throwable $e) {}
$cnt_system = 0;
$has_system = false;
try { $cnt_system = (int)db()->query("SELECT COUNT(*) FROM system_log")->fetchColumn(); $has_system = true; } catch (\Throwable $e) {}

$php_log_file  = ini_get('error_log');
$php_log_lines = [];
if ($php_log_file && is_readable($php_log_file)) {
    $all_lines     = file($php_log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $php_log_lines = array_slice($all_lines, -100);
    $php_log_lines = array_reverse($php_log_lines);
}
$cnt_php = count($php_log_lines);

// ── Dane aktywnej zakładki ────────────────────────────────────────────────────
$rows  = [];
$total = 0;

if ($tab === 'auth') {
    $params = [];
    $where  = '1=1';
    if ($search) {
        $where   .= " AND (l.email LIKE ? OR l.action LIKE ? OR l.ip LIKE ? OR l.detail LIKE ? OR u.name LIKE ?)";
        $s = "%$search%";
        $params = array_merge($params, [$s,$s,$s,$s,$s]);
    }
    $where .= build_date_where('l.created_at', $date_from, $date_to, $params);
    try {
        $total = (int)db()->prepare("SELECT COUNT(*) FROM login_log l LEFT JOIN users u ON u.id=l.user_id WHERE $where")->execute($params) ? db()->prepare("SELECT COUNT(*) FROM login_log l LEFT JOIN users u ON u.id=l.user_id WHERE $where")->execute($params) : 0;
        $st = db()->prepare("SELECT COUNT(*) FROM login_log l LEFT JOIN users u ON u.id=l.user_id WHERE $where");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        $st = db()->prepare("SELECT l.*, u.name FROM login_log l LEFT JOIN users u ON u.id=l.user_id WHERE $where ORDER BY l.created_at DESC LIMIT " . PER_PAGE . " OFFSET $offset");
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}
elseif ($tab === 'audit') {
    $params = [];
    $where  = '1=1';
    if ($search) {
        $where   .= " AND (l.contract_type LIKE ? OR l.action LIKE ? OR l.note LIKE ? OR u.name LIKE ?)";
        $s = "%$search%";
        $params = array_merge($params, [$s,$s,$s,$s]);
    }
    $where .= build_date_where('l.created_at', $date_from, $date_to, $params);
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM contract_audit_log l LEFT JOIN users u ON u.id=l.user_id WHERE $where");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        $st = db()->prepare("SELECT l.*, u.name FROM contract_audit_log l LEFT JOIN users u ON u.id=l.user_id WHERE $where ORDER BY l.created_at DESC LIMIT " . PER_PAGE . " OFFSET $offset");
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}
elseif ($tab === 'system' && $has_system) {
    $params = [];
    $where  = '1=1';
    if ($search) {
        $where   .= " AND (l.action LIKE ? OR l.detail LIKE ? OR u.name LIKE ?)";
        $s = "%$search%";
        $params = array_merge($params, [$s,$s,$s]);
    }
    $where .= build_date_where('l.created_at', $date_from, $date_to, $params);
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM system_log l LEFT JOIN users u ON u.id=l.user_id WHERE $where");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        $st = db()->prepare("SELECT l.*, u.name FROM system_log l LEFT JOIN users u ON u.id=l.user_id WHERE $where ORDER BY l.created_at DESC LIMIT " . PER_PAGE . " OFFSET $offset");
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}

$pages = $total > 0 ? (int)ceil($total / PER_PAGE) : 1;

function paginate_url(int $p, string $tab, string $q, string $df, string $dt): string {
    return '?' . http_build_query(array_filter(['tab'=>$tab,'q'=>$q,'date_from'=>$df,'date_to'=>$dt,'page'=>$p]));
}

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="container-fluid py-4" style="max-width:1200px">

  <h4 class="mb-3"><i class="bi bi-journal-text me-2"></i>Przeglądarka logów</h4>

  <?php if ($flash): ?>
  <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show">
    <?= $flash ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- Zakładki -->
  <ul class="nav nav-tabs mb-3" id="logTabs">
    <li class="nav-item">
      <a class="nav-link<?= $tab==='auth'  ?' active':'' ?>" href="?tab=auth">
        Logowania <span class="badge bg-secondary ms-1"><?= $cnt_auth ?></span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link<?= $tab==='audit' ?' active':'' ?>" href="?tab=audit">
        Umowy <span class="badge bg-secondary ms-1"><?= $cnt_audit ?></span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link<?= $tab==='system'?' active':'' ?>" href="?tab=system">
        System <span class="badge bg-secondary ms-1"><?= $cnt_system ?></span>
        <?php if (!$has_system): ?><small class="text-muted ms-1">(brak tabeli)</small><?php endif; ?>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link<?= $tab==='php'  ?' active':'' ?>" href="?tab=php">
        Błędy PHP <span class="badge bg-<?= $cnt_php ? 'danger' : 'secondary' ?> ms-1"><?= $cnt_php ?></span>
      </a>
    </li>
  </ul>

  <?php if ($tab !== 'php'): ?>
  <!-- Filtry -->
  <form method="get" class="row g-2 mb-3">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <div class="col-auto flex-grow-1">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Szukaj..." value="<?= h($search) ?>">
    </div>
    <div class="col-auto">
      <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($date_from) ?>" title="Data od">
    </div>
    <div class="col-auto">
      <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($date_to) ?>" title="Data do">
    </div>
    <div class="col-auto">
      <button type="submit" class="btn btn-sm btn-primary">Filtruj</button>
      <a href="?tab=<?= h($tab) ?>" class="btn btn-sm btn-outline-secondary">Wyczyść</a>
    </div>
  </form>
  <?php endif; ?>

  <!-- Akcje -->
  <div class="d-flex gap-2 mb-3">
    <?php if ($tab === 'auth'): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="export_auth">
        <button class="btn btn-sm btn-outline-success"><i class="bi bi-download me-1"></i>Eksport CSV</button>
      </form>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="clear_auth">
        <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Na pewno wyczyścić logi logowania?')">
          <i class="bi bi-trash3 me-1"></i>Wyczyść logi
        </button>
      </form>
    <?php elseif ($tab === 'audit'): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="export_audit">
        <button class="btn btn-sm btn-outline-success"><i class="bi bi-download me-1"></i>Eksport CSV</button>
      </form>
    <?php elseif ($tab === 'system' && $has_system): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="clear_system">
        <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Na pewno wyczyścić logi systemowe?')">
          <i class="bi bi-trash3 me-1"></i>Wyczyść logi
        </button>
      </form>
    <?php endif; ?>
  </div>

  <!-- Tabela / treść -->
  <?php if ($tab === 'auth'): ?>
  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th><th>Data</th><th>Użytkownik</th><th>E-mail</th><th>IP</th><th>Akcja</th><th>Szczegóły</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-muted small"><?= (int)$r['id'] ?></td>
          <td class="text-nowrap small"><?= h($r['created_at']) ?></td>
          <td><?= h($r['name'] ?? '—') ?></td>
          <td><?= h($r['email'] ?? '—') ?></td>
          <td class="small text-muted"><?= h($r['ip'] ?? '—') ?></td>
          <td><span class="badge bg-secondary"><?= h($r['action'] ?? '') ?></span></td>
          <td class="text-muted small"><?= h($r['detail'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Brak wpisów.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php elseif ($tab === 'audit'): ?>
  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th><th>Data</th><th>Użytkownik</th><th>Typ umowy</th><th>ID umowy</th><th>Akcja</th><th>Notatka</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-muted small"><?= (int)$r['id'] ?></td>
          <td class="text-nowrap small"><?= h($r['created_at']) ?></td>
          <td><?= h($r['name'] ?? '—') ?></td>
          <td><?= h($r['contract_type'] ?? '—') ?></td>
          <td><?= h($r['contract_id'] ?? '—') ?></td>
          <td><span class="badge bg-secondary"><?= h($r['action'] ?? '') ?></span></td>
          <td class="text-muted small"><?= h($r['note'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Brak wpisów.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php elseif ($tab === 'system'): ?>
  <?php if (!$has_system): ?>
  <div class="alert alert-info">Tabela <code>system_log</code> nie istnieje w bazie danych.</div>
  <?php else: ?>
  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th><th>Data</th><th>Użytkownik</th><th>Akcja</th><th>Szczegóły</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-muted small"><?= (int)$r['id'] ?></td>
          <td class="text-nowrap small"><?= h($r['created_at']) ?></td>
          <td><?= h($r['name'] ?? '—') ?></td>
          <td><span class="badge bg-secondary"><?= h($r['action'] ?? '') ?></span></td>
          <td class="text-muted small"><?= h($r['detail'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">Brak wpisów.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php elseif ($tab === 'php'): ?>
  <?php if (!$php_log_file): ?>
  <div class="alert alert-warning">Nie skonfigurowano <code>error_log</code> w PHP.</div>
  <?php elseif (!is_readable($php_log_file)): ?>
  <div class="alert alert-warning">Plik logów PHP (<code><?= h($php_log_file) ?></code>) nie jest czytelny.</div>
  <?php elseif (!$php_log_lines): ?>
  <div class="alert alert-info">Plik logów PHP jest pusty.</div>
  <?php else: ?>
  <div class="alert alert-secondary small mb-2">
    Plik: <code><?= h($php_log_file) ?></code> — ostatnie <?= $cnt_php ?> linii (najnowsze pierwsze)
  </div>
  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-light"><tr><th>#</th><th>Wpis</th></tr></thead>
        <tbody>
        <?php foreach ($php_log_lines as $i => $line): ?>
        <tr>
          <td class="text-muted small text-nowrap"><?= $cnt_php - $i ?></td>
          <td><pre class="mb-0 small" style="white-space:pre-wrap;word-break:break-all"><?= h($line) ?></pre></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- Paginacja -->
  <?php if ($tab !== 'php' && $pages > 1): ?>
  <nav class="mt-3">
    <ul class="pagination pagination-sm">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
      <li class="page-item<?= $p===$page?' active':'' ?>">
        <a class="page-link" href="<?= paginate_url($p, $tab, $search, $date_from, $date_to) ?>"><?= $p ?></a>
      </li>
      <?php endfor; ?>
    </ul>
  </nav>
  <?php endif; ?>

</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
