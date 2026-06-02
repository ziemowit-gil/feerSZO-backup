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

// Polecenia cron — definicja
$crons = [
    [
        'schedule' => '* * * * *',
        'script'   => 'cron/dispatcher.php',
        'desc'     => 'Główny dyspozytor — uruchamia wszystkie agenty. <strong>Zastępuje wszystkie pozostałe wpisy cron.</strong>',
        'log'      => 'logs/dispatcher.log',
        'required' => true,
        'tag'      => 'Wymagany',
    ],
    [
        'schedule' => '* * * * *',
        'script'   => 'cron/mail_queue.php',
        'desc'     => 'Kolejka e-mail — przetwarza oczekujące wiadomości do wysyłki.',
        'log'      => 'logs/mail_queue.log',
        'required' => false,
        'tag'      => 'E-mail',
    ],
    [
        'schedule' => '* * * * *',
        'script'   => 'cron/bulk_email_process.php',
        'desc'     => 'Masowa wysyłka e-mail — przetwarza kampanie batchowe.',
        'log'      => 'logs/bulk_email.log',
        'required' => false,
        'tag'      => 'E-mail',
    ],
    [
        'schedule' => '0 8 * * *',
        'script'   => 'cron/contract_expiry_reminder.php',
        'desc'     => 'Przypomnienia o wygasających umowach — codziennie o 8:00.',
        'log'      => 'logs/contract_expiry.log',
        'required' => false,
        'tag'      => 'Umowy',
    ],
    [
        'schedule' => '0 8 * * *',
        'script'   => 'cron/tasks_due_reminder.php',
        'desc'     => 'Przypomnienia o terminach zadań — codziennie o 8:00.',
        'log'      => 'logs/tasks_due.log',
        'required' => false,
        'tag'      => 'Zadania',
    ],
    [
        'schedule' => '0 6 * * *',
        'script'   => 'cron/tasks_recurring.php',
        'desc'     => 'Tworzenie kolejnych instancji zadań cyklicznych — codziennie o 6:00.',
        'log'      => 'logs/tasks_recurring.log',
        'required' => false,
        'tag'      => 'Zadania',
    ],
    [
        'schedule' => '*/5 * * * *',
        'script'   => 'cron/process_m365_queue.php',
        'desc'     => 'Procesor kolejki synchronizacji Microsoft 365 — co 5 minut.',
        'log'      => 'logs/m365_queue.log',
        'required' => false,
        'tag'      => 'Microsoft 365',
    ],
    [
        'schedule' => '0 * * * *',
        'script'   => 'cron/sync_m365_reverse.php',
        'desc'     => 'Synchronizacja odwrotna Azure AD → DB (statusy kont) — co godzinę.',
        'log'      => 'logs/m365_reverse.log',
        'required' => false,
        'tag'      => 'Microsoft 365',
    ],
    [
        'schedule' => '0 0 * * 0',
        'script'   => 'cron/kdok_cleanup.php',
        'desc'     => 'Czyszczenie starych dokumentów eObieg DK — raz w tygodniu (niedziela 0:00).',
        'log'      => 'logs/kdok_cleanup.log',
        'required' => false,
        'tag'      => 'Dokumenty',
    ],
];

$tag_colors = [
    'Wymagany'     => 'bg-danger text-white',
    'E-mail'       => 'bg-primary bg-opacity-25 text-primary',
    'Umowy'        => 'bg-success bg-opacity-25 text-success',
    'Zadania'      => 'bg-info bg-opacity-25 text-info',
    'Microsoft 365'=> 'bg-warning bg-opacity-25 text-warning',
    'Dokumenty'    => 'bg-secondary bg-opacity-25 text-secondary',
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

<!-- Info -->
<div class="alert alert-info py-2 mb-4 d-flex align-items-start gap-2">
  <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
  <div class="small">
    <strong>Zalecane:</strong> używaj tylko <code>dispatcher.php</code> — uruchamia wszystkie zadania wewnętrznie i loguje wyniki.
    Pozostałe skrypty możesz dodać tylko jeśli chcesz niezależnie kontrolować każde zadanie.<br>
    Edytuj crontab poleceniem: <code>crontab -e</code>
  </div>
</div>

<!-- Wykryta konfiguracja -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold py-2"><i class="bi bi-gear me-1"></i>Wykryta konfiguracja serwera</div>
  <div class="card-body py-2">
    <div class="row g-2 small">
      <div class="col-sm-3 text-muted">PHP binary</div>
      <div class="col-sm-9"><code><?= h($php_bin) ?></code></div>
      <div class="col-sm-3 text-muted">Ścieżka aplikacji</div>
      <div class="col-sm-9"><code><?= h($app_path) ?></code></div>
      <div class="col-sm-3 text-muted">Katalog logów</div>
      <div class="col-sm-9">
        <code><?= h($log_dir) ?></code>
        <?php if (!is_dir($log_dir)): ?>
        <span class="badge bg-warning text-dark ms-2">nie istnieje — utwórz: <code>mkdir <?= h($log_dir) ?></code></span>
        <?php else: ?>
        <span class="badge bg-success ms-2">istnieje</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Lista zadań -->
<?php foreach ($crons as $c):
    $full_cmd = sprintf(
        '%s %s %s/%s >> %s/%s 2>&1',
        $c['schedule'],
        $php_bin,
        $app_path,
        $c['script'],
        $app_path,
        $c['log']
    );
    $tag_cls = $tag_colors[$c['tag']] ?? 'bg-secondary';
?>
<div class="card shadow-sm mb-3">
  <div class="card-body py-3">
    <div class="d-flex align-items-start gap-2 mb-2">
      <span class="badge <?= $tag_cls ?>" style="font-size:.7rem"><?= h($c['tag']) ?></span>
      <div class="small"><?= $c['desc'] ?></div>
    </div>
    <div class="cron-cmd" id="cmd-<?= md5($c['script']) ?>">
      <span class="cron-schedule"><?= h($c['schedule']) ?></span>
      <span class="cron-php"><?= h($php_bin) ?></span>
      <span class="cron-path"><?= h($app_path . '/' . $c['script']) ?></span>
      <span class="cron-redir">&gt;&gt; <?= h($app_path . '/' . $c['log']) ?> 2&gt;&amp;1</span>
      <button class="copy-btn" onclick="copyCron(this, <?= json_encode($full_cmd) ?>)" title="Kopiuj">
        <i class="bi bi-clipboard"></i> Kopiuj
      </button>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- Cały blok crontab -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold py-2 d-flex align-items-center justify-content-between">
    <span><i class="bi bi-terminal me-1"></i>Gotowy blok crontab (zalecany — tylko dispatcher)</span>
    <button class="btn btn-sm btn-outline-secondary" onclick="copyBlock()">
      <i class="bi bi-clipboard me-1"></i>Kopiuj blok
    </button>
  </div>
  <div class="card-body p-0">
    <pre id="crontab-block" class="mb-0 p-3" style="background:#0f172a;color:#e2e8f0;font-size:.78rem;border-radius:0 0 .375rem .375rem;overflow-x:auto"><?php
// Tylko dispatcher
$disp = $crons[0];
printf(
    "# feerSZO — CRON\n# Dispatcher (uruchamia wszystkie zadania)\n%s %s %s/%s >> %s/%s 2>&1\n",
    $disp['schedule'], $php_bin, $app_path, $disp['script'], $app_path, $disp['log']
);
?></pre>
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
