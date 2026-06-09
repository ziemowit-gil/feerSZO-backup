<?php
/**
 * admin/backups.php — Zarządzanie kopiami zapasowymi.
 * Wymaga roli admin. Obsługuje: lista, pobieranie, uruchomienie backupu, usuwanie.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';

require_role('admin');

$bak_root  = dirname(__DIR__) . '/backups';
$PAGE_TITLE = 'Kopie zapasowe';
$flash = $flash_type = '';

// ── Pobierz plik (download) ───────────────────────────────────────────────
if (isset($_GET['dl'])) {
    $rel  = trim($_GET['dl'], '/');
    // Zabezpieczenie: tylko pliki wewnątrz backups/, bez path traversal
    $full = realpath($bak_root . '/' . $rel);
    if (!$full || !str_starts_with($full, realpath($bak_root) . '/') || !is_file($full)) {
        http_response_code(404); exit('Plik nie istnieje.');
    }
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $mime = match($ext) {
        'gz'  => 'application/gzip',
        'db'  => 'application/octet-stream',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . basename($full) . '"');
    header('Content-Length: ' . filesize($full));
    header('X-Content-Type-Options: nosniff');
    readfile($full);
    exit;
}

// ── POST: uruchom backup / usuń ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'run') {
        $agent = dirname(__DIR__) . '/cron/agents/backup.php';
        if (!file_exists($agent)) {
            $flash = 'Skrypt backupu nie istnieje: cron/agents/backup.php';
            $flash_type = 'danger';
        } else {
            ob_start();
            define('APP_CLI', true);
            try {
                include $agent;
                $out = ob_get_clean();
                $flash = 'Backup uruchomiony. Wynik:<br><pre class="mb-0 small">' . h($out) . '</pre>';
                $flash_type = 'success';
            } catch (\Throwable $e) {
                ob_end_clean();
                $flash = 'Błąd backupu: ' . h($e->getMessage());
                $flash_type = 'danger';
            }
        }
    }

    if ($act === 'delete') {
        $rel  = trim($_POST['file'] ?? '', '/');
        $full = realpath($bak_root . '/' . $rel);
        if ($full && str_starts_with($full, realpath($bak_root) . '/') && is_file($full)) {
            unlink($full);
            // Usuń pusty katalog miesiąca
            $dir = dirname($full);
            if (is_dir($dir) && count(scandir($dir)) === 2) rmdir($dir);
            $flash = 'Plik usunięty: ' . h(basename($full));
            $flash_type = 'success';
        } else {
            $flash = 'Plik nie istnieje.'; $flash_type = 'danger';
        }
    }

    header('Location: ' . APP_URL . '/admin/backups.php'
         . ($flash ? '?msg=' . urlencode($flash) . '&type=' . $flash_type : ''));
    exit;
}

if (!empty($_GET['msg'])) { $flash = $_GET['msg']; $flash_type = $_GET['type'] ?? 'success'; }

// ── Skanuj katalog backupów ───────────────────────────────────────────────
$months = [];
$total_size = 0;

if (is_dir($bak_root)) {
    foreach (array_reverse(glob($bak_root . '/????-??', GLOB_ONLYDIR)) as $mdir) {
        $month = basename($mdir);
        $files = [];
        foreach (glob($mdir . '/*') as $fp) {
            if (!is_file($fp)) continue;
            $sz = filesize($fp);
            $total_size += $sz;
            $ext  = strtolower(pathinfo($fp, PATHINFO_EXTENSION));
            $type = match($ext) {
                'db'  => ['label' => 'Baza SQLite', 'icon' => 'bi-database',      'cls' => 'primary'],
                'gz'  => ['label' => 'Archiwum',    'icon' => 'bi-file-zip',       'cls' => 'success'],
                default => ['label' => strtoupper($ext), 'icon' => 'bi-file',       'cls' => 'secondary'],
            };
            $files[] = [
                'name'  => basename($fp),
                'rel'   => $month . '/' . basename($fp),
                'size'  => $sz,
                'size_h'=> $sz > 1048576 ? round($sz/1048576, 1).' MB' : round($sz/1024).' KB',
                'mtime' => filemtime($fp),
                'type'  => $type,
            ];
        }
        usort($files, fn($a,$b) => $b['mtime'] - $a['mtime']);
        if ($files) $months[$month] = $files;
    }
}

$total_h = $total_size > 1048576
    ? round($total_size / 1048576, 1) . ' MB'
    : round($total_size / 1024) . ' KB';

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Kopie zapasowe</li>
  </ol>
</nav>

<?php
$_sp_enabled = (new M365Graph())->is_configured() && m365_setting('sp_enabled') === '1' && m365_setting('sp_site_url');
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-archive me-2 text-primary"></i>Kopie zapasowe</h4>
  <div class="d-flex gap-2 flex-wrap">
    <span class="badge bg-secondary align-self-center">Łącznie: <?= h($total_h) ?></span>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="run">
      <button class="btn btn-sm btn-success"
              onclick="return confirm('Uruchomić backup teraz?')">
        <i class="bi bi-play-circle me-1"></i>Uruchom backup teraz
      </button>
    </form>
    <button class="btn btn-sm <?= $_sp_enabled ? 'btn-primary' : 'btn-outline-secondary' ?>"
            id="btn-sp-backup"
            <?= !$_sp_enabled ? 'disabled' : '' ?>
            title="<?= $_sp_enabled ? 'Backup bazy SQLite do SharePoint' : 'Skonfiguruj SharePoint, aby włączyć tę funkcję' ?>">
      <i class="bi bi-cloud-arrow-up me-1"></i>Backup do SharePoint
    </button>
    <?php if (!$_sp_enabled): ?>
    <a href="<?= APP_URL ?>/admin/sp_onboarding.php" class="btn btn-sm btn-outline-primary align-self-center"
       title="Skonfiguruj SharePoint backup">
      <i class="bi bi-gear me-1"></i>Skonfiguruj SP
    </a>
    <?php endif; ?>
  </div>
</div>
<div id="sp-backup-result" class="mb-3" style="display:none"></div>

<?php if ($flash): ?>
<div class="alert alert-<?= h($flash_type) ?> py-2 small"><?= $flash ?></div>
<?php endif; ?>

<?php if (!$months): ?>
<div class="alert alert-info">
  Brak kopii zapasowych. Uruchom backup ręcznie lub poczekaj na CRON (codziennie w nocy 1:00–3:00).
</div>
<?php endif; ?>

<?php foreach ($months as $month => $files): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 d-flex align-items-center justify-content-between">
    <span class="fw-semibold">
      <i class="bi bi-calendar3 me-1 text-muted"></i>
      <?php
      [$y, $m] = explode('-', $month);
      $pl_months = ['','Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec',
                    'Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
      echo h(($pl_months[(int)$m] ?? $m) . ' ' . $y);
      ?>
    </span>
    <span class="text-muted small"><?= count($files) ?> plik(ów)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Plik</th>
          <th>Typ</th>
          <th>Rozmiar</th>
          <th>Data</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($files as $f): ?>
        <tr>
          <td>
            <i class="bi <?= $f['type']['icon'] ?> me-1 text-<?= $f['type']['cls'] ?>"></i>
            <code style="font-size:.8rem"><?= h($f['name']) ?></code>
          </td>
          <td>
            <span class="badge bg-<?= $f['type']['cls'] ?> bg-opacity-10 text-<?= $f['type']['cls'] ?> border border-<?= $f['type']['cls'] ?> border-opacity-25">
              <?= h($f['type']['label']) ?>
            </span>
          </td>
          <td class="text-muted small"><?= h($f['size_h']) ?></td>
          <td class="text-muted small"><?= date('d.m.Y H:i', $f['mtime']) ?></td>
          <td class="text-end">
            <a href="?dl=<?= urlencode($f['rel']) ?>"
               class="btn btn-outline-primary btn-sm py-0 px-2"
               title="Pobierz">
              <i class="bi bi-download"></i>
            </a>
            <form method="post" class="d-inline"
                  onsubmit="return confirm('Usunąć <?= h(addslashes($f['name'])) ?>?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="file" value="<?= h($f['rel']) ?>">
              <button class="btn btn-outline-danger btn-sm py-0 px-2" title="Usuń">
                <i class="bi bi-trash3"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<div class="text-muted small mt-3">
  <i class="bi bi-info-circle me-1"></i>
  Backupy lokalne w <code>backups/YYYY-MM/</code>, niedostępne przez HTTP.
  Rotacja: pliki starsze niż 30 dni usuwane przez CRON.
  <?php if ($_sp_enabled): ?>
  &middot; <i class="bi bi-cloud-arrow-up text-primary"></i> SharePoint backup aktywny.
  <?php else: ?>
  &middot; <a href="<?= APP_URL ?>/admin/sp_onboarding.php"><i class="bi bi-cloud-arrow-up"></i> Włącz SharePoint backup</a>
  <?php endif; ?>
</div>

<?php if ($_sp_enabled): ?>
<script>
document.getElementById('btn-sp-backup').addEventListener('click', async function () {
    if (!confirm('Utworzyć backup bazy danych i wysłać na SharePoint?')) return;
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Tworzę backup…';

    const res = await fetch('<?= APP_URL ?>/admin/api/sp_backup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'backup' }),
    }).then(r => r.json()).catch(e => ({ ok: false, error: e.message }));

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-cloud-arrow-up me-1"></i>Backup do SharePoint';

    const box = document.getElementById('sp-backup-result');
    box.style.display = 'block';
    if (res.ok) {
        const link = res.web_url ? ' <a href="' + res.web_url + '" target="_blank" class="ms-1">Otwórz w SharePoint</a>' : '';
        box.innerHTML = '<div class="alert alert-success py-2 small mb-0">'
            + '<i class="bi bi-check-circle-fill me-1"></i>Backup wysłany: <code>'
            + (res.sp_path || '?') + '</code> (' + (res.size_h || '?') + ')' + link + '</div>';
    } else {
        box.innerHTML = '<div class="alert alert-danger py-2 small mb-0">'
            + '<i class="bi bi-x-circle-fill me-1"></i>' + (res.error || 'Nieznany błąd.') + '</div>';
    }
});
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
