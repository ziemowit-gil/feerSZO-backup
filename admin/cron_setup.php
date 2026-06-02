<?php
/**
 * admin/cron_setup.php — Konfiguracja zadań CRON.
 * Wyświetla gotowe polecenia do wklejenia w crontab.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'Konfiguracja CRON';

// Wykryj ścieżkę do PHP i do katalogu aplikacji
$php_bin  = trim(@shell_exec('which php8.2 || which php8.1 || which php 2>/dev/null') ?: 'php');
$app_path = rtrim(str_replace('\\', '/', realpath(dirname(__DIR__))), '/');
$log_dir  = $app_path . '/logs';

// Jeden wpis — dispatcher obsługuje wszystko
$dispatcher = [
    'schedule' => '* * * * *',
    'script'   => 'cron/dispatcher.php',
    'log'      => 'logs/dispatcher.log',
];

// Lista agentów uruchamianych przez dispatcher (informacyjnie)
$agents = [
    ['script' => 'cron/mail_queue.php',             'freq' => 'co minutę',    'tag' => 'E-mail',         'desc' => 'Kolejka e-mail'],
    ['script' => 'cron/bulk_email_process.php',     'freq' => 'co minutę',    'tag' => 'E-mail',         'desc' => 'Masowa wysyłka e-mail'],
    ['script' => 'cron/contract_expiry_reminder.php','freq' => 'codziennie 8:00','tag'=> 'Umowy',         'desc' => 'Przypomnienia o wygasających umowach'],
    ['script' => 'cron/tasks_due_reminder.php',     'freq' => 'codziennie 8:00','tag'=> 'Zadania',        'desc' => 'Przypomnienia o terminach zadań'],
    ['script' => 'cron/tasks_recurring.php',        'freq' => 'codziennie 6:00','tag'=> 'Zadania',        'desc' => 'Zadania cykliczne'],
    ['script' => 'cron/process_m365_queue.php',     'freq' => 'co 5 minut',   'tag' => 'Microsoft 365',  'desc' => 'Kolejka synchronizacji M365'],
    ['script' => 'cron/sync_m365_reverse.php',      'freq' => 'co godzinę',   'tag' => 'Microsoft 365',  'desc' => 'Synchronizacja odwrotna Azure AD → DB'],
    ['script' => 'cron/kdok_cleanup.php',           'freq' => 'raz w tygodniu','tag'=> 'Dokumenty',       'desc' => 'Czyszczenie dokumentów eObieg DK'],
];


include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.cron-cmd {
    font-family: 'SFMono-Regular', Consolas, monospace;
    font-size: .78rem;
    background: #0f172a;
    color: #e2e8f0;
    border-radius: 6px;
    padding: .55rem .85rem;
    display: flex;
    align-items: center;
    gap: .5rem;
    word-break: break-all;
}
.cron-schedule { color: #34d399; flex-shrink: 0; }
.cron-php      { color: #93c5fd; flex-shrink: 0; }
.cron-path     { color: #fde68a; }
.cron-redir    { color: #94a3b8; }
.copy-btn {
    margin-left: auto; flex-shrink: 0;
    background: rgba(255,255,255,.1); border: none; color: #94a3b8;
    border-radius: 4px; padding: .2rem .5rem; cursor: pointer; font-size: .7rem;
    transition: background .12s;
}
.copy-btn:hover { background: rgba(255,255,255,.2); color: #fff; }
.copy-btn.copied { color: #34d399; }
</style>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Konfiguracja CRON</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>Konfiguracja zadań CRON</h4>
</div>

<?php
$full_cmd = sprintf('* * * * * %s %s/%s >> %s/%s 2>&1',
    $php_bin, $app_path, $dispatcher['script'], $app_path, $dispatcher['log']);
$crontab_block = "# feerSZO — CRON\n# Dispatcher uruchamia wszystkie zadania wewnętrznie\n" . $full_cmd . "\n";
?>

<!-- Konfiguracja serwera -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold py-2"><i class="bi bi-server me-1"></i>Ścieżki serwera</div>
  <div class="card-body py-2">
    <div class="row g-2 small">
      <div class="col-sm-3 text-muted">PHP</div>
      <div class="col-sm-9"><code><?= h($php_bin) ?></code></div>
      <div class="col-sm-3 text-muted">Aplikacja</div>
      <div class="col-sm-9"><code><?= h($app_path) ?></code></div>
      <div class="col-sm-3 text-muted">Logi</div>
      <div class="col-sm-9">
        <code><?= h($log_dir) ?></code>
        <?php if (!is_dir($log_dir)): ?>
        <span class="badge bg-warning text-dark ms-2">utwórz: <code>mkdir -p <?= h($log_dir) ?></code></span>
        <?php else: ?>
        <span class="badge bg-success ms-2">OK</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Polecenie crontab -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold py-2 d-flex align-items-center justify-content-between">
    <span><i class="bi bi-terminal me-1"></i>Wpis do crontab <code style="font-size:.78rem">crontab -e</code></span>
    <button class="btn btn-sm btn-outline-secondary" onclick="copyBlock()">
      <i class="bi bi-clipboard me-1"></i>Kopiuj
    </button>
  </div>
  <div class="card-body p-0">
    <pre id="crontab-block" class="mb-0 p-3" style="background:#0f172a;color:#e2e8f0;font-size:.82rem;border-radius:0 0 .5rem .5rem;overflow-x:auto;line-height:1.7"><?= h($crontab_block) ?></pre>
  </div>
</div>

<!-- Agenty uruchamiane przez dispatcher -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold py-2"><i class="bi bi-list-check me-1"></i>Zadania uruchamiane przez dispatcher</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.84rem">
      <thead class="table-light">
        <tr><th class="ps-3">Skrypt</th><th>Opis</th><th>Częstotliwość</th><th>Moduł</th></tr>
      </thead>
      <tbody>
        <?php foreach ($agents as $a): ?>
        <tr>
          <td class="ps-3 font-monospace text-muted" style="font-size:.72rem"><?= h($a['script']) ?></td>
          <td><?= h($a['desc']) ?></td>
          <td class="text-muted small"><?= h($a['freq']) ?></td>
          <td><span class="badge bg-secondary bg-opacity-25 text-secondary" style="font-size:.68rem"><?= h($a['tag']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function copyCron(btn, text) {
    navigator.clipboard.writeText(text).then(function() {
        btn.innerHTML = '<i class="bi bi-check-lg"></i> Skopiowano';
        btn.classList.add('copied');
        setTimeout(function() {
            btn.innerHTML = '<i class="bi bi-clipboard"></i> Kopiuj';
            btn.classList.remove('copied');
        }, 2000);
    });
}

function copyBlock() {
    var text = document.getElementById('crontab-block').textContent;
    navigator.clipboard.writeText(text).then(function() {
        var btn = event.currentTarget;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Skopiowano';
        setTimeout(function() { btn.innerHTML = '<i class="bi bi-clipboard me-1"></i>Kopiuj blok'; }, 2000);
    });
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
