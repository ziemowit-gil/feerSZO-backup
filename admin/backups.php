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
require_once dirname(__DIR__) . '/includes/backup.php';
require_once dirname(__DIR__) . '/includes/admin_audit.php';
admin_audit_migrate();

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

    if ($act === 'restore') {
        $rel  = trim($_POST['file'] ?? '', '/');
        $full = realpath($bak_root . '/' . $rel);
        if (!$full || !str_starts_with($full, realpath($bak_root) . '/') || !is_file($full)) {
            $flash = 'Plik kopii nie istnieje.'; $flash_type = 'danger';
        } else {
            $kind = backup_kind(basename($full));
            if ($kind === 'db') {
                $res = backup_restore_db($full);
            } elseif ($kind === 'uploads' || $kind === 'certs') {
                $res = backup_restore_archive($full);
            } else {
                $res = ['ok' => false, 'msg' => 'Nieznany typ kopii — nie można przywrócić.'];
            }
            $flash = ($res['ok'] ? 'Przywrócono: ' : 'Błąd przywracania: ') . h($res['msg']);
            $flash_type = $res['ok'] ? 'success' : 'danger';
            admin_audit(
                $res['ok'] ? 'backup_restore' : 'backup_restore_failed',
                'backups', basename($full) . ' [' . ($kind ?: '?') . '] — ' . $res['msg'], 0, basename($full)
            );
        }
    }

    if ($act === 'enc_enable' && !defined('BACKUP_ENCRYPT_KEY')) {
        $key = trim($_POST['key'] ?? '');
        if ($key === '') $key = backup_generate_key();
        org_setting_set('backup_encrypt_key', $key);
        admin_audit('backup_encrypt_enable', 'backups', 'Włączono szyfrowanie kopii zapasowych.');
        $flash = 'Szyfrowanie włączone. ZAPISZ KLUCZ POZA SYSTEMEM — bez niego kopie są nie do odzyskania.';
        $flash_type = 'warning';
    }

    if ($act === 'enc_disable' && !defined('BACKUP_ENCRYPT_KEY')) {
        org_setting_set('backup_encrypt_key', '');
        admin_audit('backup_encrypt_disable', 'backups', 'Wyłączono szyfrowanie kopii zapasowych (klucz usunięty z ustawień).');
        $flash = 'Szyfrowanie wyłączone. Istniejące kopie .enc pozostają zaszyfrowane — potrzebują poprzedniego klucza.';
        $flash_type = 'warning';
    }

    if ($act === 'save_settings') {
        $h = max(1, min(168, (int)($_POST['max_hours'] ?? 8)));
        org_setting_set('backup_alert_max_hours', (string)$h);
        $flash = 'Zapisano próg alertu o przeterminowaniu: ' . $h . ' h.'; $flash_type = 'success';
    }

    $verify = null;
    if ($act === 'verify') {
        $verify = backup_verify(true);
        $flash = $verify['problems']
            ? 'Weryfikacja: wykryto ' . $verify['problems'] . ' problem(ów) — patrz niżej.'
            : 'Weryfikacja: wszystkie ' . $verify['checked'] . ' kopii poprawne.';
        $flash_type = $verify['problems'] ? 'danger' : 'success';
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

    // Weryfikacja renderuje wyniki na miejscu (bez przekierowania).
    if ($act !== 'verify') {
        header('Location: ' . APP_URL . '/admin/backups.php'
             . ($flash ? '?msg=' . urlencode($flash) . '&type=' . $flash_type : ''));
        exit;
    }
}
$verify = $verify ?? null;

if (!empty($_GET['msg'])) { $flash = $_GET['msg']; $flash_type = $_GET['type'] ?? 'success'; }

// ── Skanuj katalog backupów ───────────────────────────────────────────────
$months = [];
$total_size = 0;
$manifest = backup_manifest_load();

if (is_dir($bak_root)) {
    foreach (array_reverse(glob($bak_root . '/????-??', GLOB_ONLYDIR)) as $mdir) {
        $month = basename($mdir);
        $files = [];
        foreach (glob($mdir . '/*') as $fp) {
            if (!is_file($fp)) continue;
            $sz = filesize($fp);
            $total_size += $sz;
            $kind = backup_kind(basename($fp));
            $enc  = backup_is_encrypted(basename($fp));
            $type = match($kind) {
                'db'      => ['label' => 'Baza SQLite', 'icon' => 'bi-database', 'cls' => 'primary', 'restore' => true],
                'uploads' => ['label' => 'Uploads',     'icon' => 'bi-file-zip', 'cls' => 'success', 'restore' => true],
                'certs'   => ['label' => 'Certyfikaty',  'icon' => 'bi-shield-lock', 'cls' => 'warning', 'restore' => true],
                default   => ['label' => strtoupper(pathinfo($fp, PATHINFO_EXTENSION)), 'icon' => 'bi-file', 'cls' => 'secondary', 'restore' => false],
            };
            $files[] = [
                'name'  => basename($fp),
                'rel'   => $month . '/' . basename($fp),
                'size'  => $sz,
                'size_h'=> $sz > 1048576 ? round($sz/1048576, 1).' MB' : round($sz/1024).' KB',
                'mtime' => filemtime($fp),
                'type'  => $type,
                'enc'   => $enc,
                'kind'  => $kind,
                'sha'   => $manifest[$month . '/' . basename($fp)]['sha256'] ?? '',
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

<?php
// ── Kondycja / RPO ─────────────────────────────────────────────────────────
$_last_ok   = backup_last_ok_ts();
$_stale     = backup_is_stale();
$_max_h     = backup_max_age_hours();
$_age_h     = $_last_ok ? round((time() - $_last_ok) / 3600, 1) : null;
$_counts    = ['db' => 0, 'uploads' => 0, 'certs' => 0];
foreach (glob($bak_root . '/????-??/*') ?: [] as $fp) {
    if (!is_file($fp)) continue;
    $k = backup_kind(basename($fp));
    if ($k && isset($_counts[$k])) $_counts[$k]++;
}
$_health_cls = $_last_ok === 0 ? 'secondary' : ($_stale ? 'danger' : 'success');
$_health_txt = $_last_ok === 0 ? 'Brak danych' : ($_stale ? 'Przeterminowany' : 'Aktualny');
?>
<div class="card shadow-sm mb-3 border-<?= $_health_cls ?>">
  <div class="card-header py-2 d-flex align-items-center justify-content-between">
    <span class="fw-semibold"><i class="bi bi-heart-pulse me-1 text-<?= $_health_cls ?>"></i>Kondycja kopii zapasowych</span>
    <span class="badge bg-<?= $_health_cls ?>"><?= h($_health_txt) ?></span>
  </div>
  <div class="card-body">
    <div class="row g-3 small">
      <div class="col-6 col-md-3">
        <div class="text-muted">Ostatni udany backup</div>
        <div class="fw-semibold"><?= $_last_ok ? date('d.m.Y H:i', $_last_ok) . ' (' . $_age_h . 'h temu)' : '—' ?></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="text-muted">Próg alertu (RPO)</div>
        <div class="fw-semibold"><?= (int)$_max_h ?> h</div>
      </div>
      <div class="col-6 col-md-3">
        <div class="text-muted">Kopie (baza / uploads / certs)</div>
        <div class="fw-semibold"><?= $_counts['db'] ?> / <?= $_counts['uploads'] ?> / <?= $_counts['certs'] ?></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="text-muted">Szyfrowanie</div>
        <div class="fw-semibold"><?= backup_encryption_enabled() ? '<span class="text-success"><i class="bi bi-lock-fill"></i> AES-256</span>' : '<span class="text-muted">wyłączone</span>' ?></div>
      </div>
      <?php
        $_sp_last   = function_exists('sp_backup_last_ok_ts') ? sp_backup_last_ok_ts() : 0;
        $_sp_folder = m365_setting('sp_backup_folder') ?: 'Backup';
        $_sp_cfg    = (new M365Graph())->is_configured() && m365_setting('sp_enabled') === '1';
      ?>
      <div class="col-6 col-md-3">
        <div class="text-muted"><i class="bi bi-cloud-arrow-up me-1"></i>SharePoint — ostatnia wysyłka</div>
        <div class="fw-semibold">
          <?php if (!$_sp_cfg): ?><span class="text-muted">wyłączony</span>
          <?php elseif ($_sp_last): ?><?= date('d.m.Y H:i', $_sp_last) ?> <span class="text-muted">(<?= round((time()-$_sp_last)/3600, 1) ?>h)</span>
          <?php else: ?><span class="text-muted">brak danych</span><?php endif; ?>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="text-muted">SharePoint — folder / retencja</div>
        <div class="fw-semibold"><code style="font-size:.78rem"><?= h($_sp_folder) ?></code> · <?= (int)(org_setting('sp_backup_retention_days') ?: 90) ?> dni</div>
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3 align-items-center">
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="verify">
        <button class="btn btn-outline-primary btn-sm"><i class="bi bi-patch-check me-1"></i>Zweryfikuj integralność</button>
      </form>
      <form method="post" class="d-inline d-flex align-items-center gap-1">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save_settings">
        <label class="text-muted" style="font-size:.8rem">Alert gdy brak kopii przez</label>
        <input type="number" name="max_hours" value="<?= (int)$_max_h ?>" min="1" max="168" class="form-control form-control-sm" style="width:80px">
        <span class="text-muted" style="font-size:.8rem">h</span>
        <button class="btn btn-outline-secondary btn-sm">Zapisz</button>
      </form>
    </div>
  </div>
</div>

<?php if ($verify): ?>
<div class="card shadow-sm mb-3 border-<?= $verify['problems'] ? 'danger' : 'success' ?>">
  <div class="card-header py-2 fw-semibold">
    <i class="bi bi-patch-check me-1"></i>Wynik weryfikacji — <?= $verify['checked'] ?> kopii, problemów: <?= $verify['problems'] ?>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle small">
      <thead class="table-light"><tr><th>Plik</th><th>Typ</th><th>Status</th><th>Szczegóły</th></tr></thead>
      <tbody>
      <?php foreach ($verify['items'] as $v):
        [$vc, $vl] = match($v['status']) {
          'ok'            => ['success', 'OK'],
          'unmanifested'  => ['secondary', 'Bez manifestu'],
          'changed'       => ['danger', 'Suma się nie zgadza'],
          'integrity_fail'=> ['danger', 'Błąd integralności'],
          'missing'       => ['warning', 'Brak pliku'],
          'unreadable'    => ['warning', 'Nieodczytany'],
          default         => ['secondary', $v['status']],
        }; ?>
        <tr>
          <td><code style="font-size:.78rem"><?= h($v['rel']) ?></code></td>
          <td><?= h($v['kind'] ?? '—') ?></td>
          <td><span class="badge bg-<?= $vc ?> bg-opacity-10 text-<?= $vc ?> border border-<?= $vc ?> border-opacity-25"><?= h($vl) ?></span></td>
          <td class="text-muted"><?= h($v['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
$_enc_on     = backup_encryption_enabled();
$_enc_const  = defined('BACKUP_ENCRYPT_KEY');
$_enc_key    = backup_encrypt_key();
$_openssl    = backup_openssl_available();
?>
<div class="card shadow-sm mb-3 border-<?= $_enc_on ? 'success' : 'secondary' ?>">
  <div class="card-header py-2 d-flex align-items-center justify-content-between">
    <span class="fw-semibold"><i class="bi bi-shield-lock me-1 text-<?= $_enc_on ? 'success' : 'muted' ?>"></i>Szyfrowanie kopii (AES-256)</span>
    <span class="badge bg-<?= $_enc_on ? 'success' : 'secondary' ?>"><?= $_enc_on ? 'Włączone' : 'Wyłączone' ?></span>
  </div>
  <div class="card-body small">
    <?php if (!$_openssl): ?>
    <div class="alert alert-warning py-2 mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Brak narzędzia <code>openssl</code> na serwerze — szyfrowanie niedostępne.</div>
    <?php endif; ?>
    <?php if ($_enc_const): ?>
    <p class="mb-1">Klucz skonfigurowany na sztywno w <code>config.php</code> (<code>BACKUP_ENCRYPT_KEY</code>). Zmieniaj go tam.</p>
    <?php elseif ($_enc_on): ?>
    <p class="mb-2">Nowe kopie (baza, uploads, certs) są szyfrowane AES-256. <strong class="text-danger">Zapisz klucz poza systemem</strong> — bez niego kopie są nie do odzyskania.</p>
    <div class="input-group input-group-sm mb-2" style="max-width:640px">
      <span class="input-group-text">Klucz</span>
      <input type="text" class="form-control font-monospace" id="enc-key" value="<?= h($_enc_key) ?>" readonly>
      <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('enc-key').value)"><i class="bi bi-clipboard"></i> Kopiuj</button>
    </div>
    <form method="post" class="d-inline" onsubmit="return confirm('Wyłączyć szyfrowanie? Istniejące kopie .enc pozostaną zaszyfrowane i będą wymagać TEGO klucza. Zapisałeś go?')">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="enc_disable">
      <button class="btn btn-outline-danger btn-sm"><i class="bi bi-shield-slash me-1"></i>Wyłącz szyfrowanie</button>
    </form>
    <?php else: ?>
    <p class="mb-2">Kopie są zapisywane bez szyfrowania. Włącz, aby chronić bazę, uploads i klucze prywatne (certs/) w spoczynku.</p>
    <form method="post" onsubmit="return confirm('Włączyć szyfrowanie i wygenerować klucz? Po włączeniu ZAPISZ klucz poza systemem.')">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="enc_enable">
      <button class="btn btn-success btn-sm" <?= $_openssl ? '' : 'disabled' ?>><i class="bi bi-shield-lock me-1"></i>Włącz szyfrowanie (wygeneruj klucz)</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!$months): ?>
<div class="alert alert-info">
  Brak kopii zapasowych. Uruchom backup ręcznie lub poczekaj na CRON (przyrostowo, co 4 godziny).
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
            <?php if ($f['sha']): ?>
            <span class="badge bg-light text-muted border ms-1" style="font-size:.66rem;cursor:pointer" title="SHA-256: <?= h($f['sha']) ?> (kliknij, aby skopiować)" onclick="navigator.clipboard.writeText('<?= h($f['sha']) ?>')">
              <i class="bi bi-patch-check"></i> <?= h(substr($f['sha'], 0, 10)) ?>…
            </span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge bg-<?= $f['type']['cls'] ?> bg-opacity-10 text-<?= $f['type']['cls'] ?> border border-<?= $f['type']['cls'] ?> border-opacity-25">
              <?= h($f['type']['label']) ?>
            </span>
            <?php if ($f['enc']): ?>
            <span class="badge bg-dark bg-opacity-10 text-dark border border-dark border-opacity-25" title="Zaszyfrowany AES-256"><i class="bi bi-lock-fill"></i></span>
            <?php endif; ?>
          </td>
          <td class="text-muted small"><?= h($f['size_h']) ?></td>
          <td class="text-muted small"><?= date('d.m.Y H:i', $f['mtime']) ?></td>
          <td class="text-end">
            <?php if (!empty($f['type']['restore'])): ?>
            <?php
              $_rmsg = $f['kind'] === 'db'
                ? 'PRZYWRÓCIĆ BAZĘ z tej kopii? Bieżąca baza zostanie NADPISANA (utworzymy kopię pre-restore). Kontynuować?'
                : 'PRZYWRÓCIĆ pliki z tej kopii? Bieżące pliki zostaną NADPISANE plikami z archiwum. Kontynuować?';
            ?>
            <form method="post" class="d-inline" onsubmit="return confirm('<?= h($_rmsg) ?>')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="restore">
              <input type="hidden" name="file" value="<?= h($f['rel']) ?>">
              <button class="btn btn-outline-warning btn-sm py-0 px-2" title="Przywróć z tej kopii"<?= ($f['enc'] && !backup_encryption_enabled()) ? ' disabled title="Brak klucza — nie można odszyfrować"' : '' ?>>
                <i class="bi bi-arrow-counterclockwise"></i>
              </button>
            </form>
            <?php endif; ?>
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
  Backupy lokalne w <code>backups/YYYY-MM/</code>, niedostępne przez HTTP. Backup przyrostowy co 4 godziny — baza, uploads oraz katalog <code>certs/</code> (klucze prywatne).
  Rotacja: zawsze zachowywane min. 3 ostatnie kopie każdego typu, pozostałe starsze niż 30 dni usuwane przez CRON.
  <?php if ($_enc_on): ?>&middot; <i class="bi bi-lock-fill"></i> Kopie szyfrowane AES-256.<?php endif; ?>
  &middot; <i class="bi bi-arrow-counterclockwise"></i> Przywracanie: baza jest walidowana (integrity_check) i poprzedzana kopią pre-restore; archiwa nadpisują pliki.
  <?php if ($_sp_enabled): ?>
  &middot; <i class="bi bi-cloud-arrow-up text-primary"></i> SharePoint: przyrostowo co 6h, pełny backup systemu w nocy.
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
