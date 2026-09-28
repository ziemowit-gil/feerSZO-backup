<?php require_once __DIR__ . '/../includes/shell_safe.php';
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

// ── Obsługa tokenu ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'generate_token') {
    csrf_check();
    $token = bin2hex(random_bytes(24)); // 48 znaków hex
    $exists = db_one("SELECT 1 FROM settings WHERE key_='cron_token'");
    if ($exists) db()->prepare("UPDATE settings SET value=? WHERE key_='cron_token'")->execute([$token]);
    else         db()->prepare("INSERT INTO settings (key_,value) VALUES ('cron_token',?)")->execute([$token]);
    flash_set('success', 'Wygenerowano nowy token CRON.');
    header('Location: cron_setup.php'); exit;
}

$cron_token = trim(db_one("SELECT value FROM settings WHERE key_='cron_token'")['value'] ?? '');
$cron_url   = preg_replace('/\s+/', '', rtrim(APP_URL, '/')) . '/cron.php' . ($cron_token ? '?token=' . $cron_token : '');

// Wykryj ścieżkę do PHP i do katalogu aplikacji
$php_bin  = preg_replace('/\s+/', '', szo_shell('which php8.2 || which php8.1 || which php 2>/dev/null') ?: 'php');
$app_path = rtrim(preg_replace('/\s+/', '', str_replace('\\', '/', (string)realpath(dirname(__DIR__)))), '/');
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
    ['script' => 'cron/outlook_calendar_sync.php',  'freq' => 'co godzinę',   'tag' => 'CRM / Outlook',  'desc' => 'Synchronizacja kalendarzy Outlook per-user → crm_events (tylko użytkownicy z kontem M365)'],
    ['script' => 'cron/kdok_cleanup.php',           'freq' => 'raz w tygodniu','tag'=> 'Dokumenty',       'desc' => 'Czyszczenie dokumentów EOD Dokumentów Księgowych'],
    ['script' => 'cron/contract_auto_complete.php', 'freq' => 'codziennie 4:00-6:00','tag'=> 'Umowy',    'desc' => 'Automatyczne kończenie umów po upływie terminu'],
    ['script' => 'cron/sync_users_to_test.php',     'freq' => 'co godzinę',   'tag' => 'Testy',          'desc' => 'Synchronizacja kont użytkowników prod → środowisko testowe (tylko gdy TEST_SYNC_URL skonfigurowane)'],
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

<?php
$full_cmd      = sprintf('* * * * * %s %s/%s >> %s/%s 2>&1',
    $php_bin, $app_path, $dispatcher['script'], $app_path, $dispatcher['log']);
$mkdir_cmd     = 'mkdir -p ' . $app_path . '/logs';
$crontab_block = $full_cmd;
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>Konfiguracja CRON</h4>
</div>

<?= flash_html() ?>

<!-- ══ OPCJA A: DirectAdmin / PHP CLI ════════════════════════════════════════ -->
<div class="card border-primary shadow mb-4">
  <div class="card-header bg-primary text-white fw-bold py-2">
    <i class="bi bi-globe2 me-2"></i>Opcja A — DirectAdmin / panel hostingowy (PHP CLI)
  </div>
  <div class="card-body">

    <?php
    $curl_cmd = 'curl -L -s ' . $cron_url . ' > /dev/null 2>&1';
    ?>
    <?php if ($cron_token): ?>

    <!-- DirectAdmin — krok po kroku -->
    <div class="alert alert-primary py-2 mb-3 small">
      <strong><i class="bi bi-info-circle me-1"></i>DirectAdmin:</strong>
      Zaloguj się → <strong>Zaawansowane funkcje</strong> → <strong>Menadżer zadań Cron</strong>
      → kliknij <strong>Dodaj zadanie Cron</strong>.
    </div>

    <?php $da_cmd = preg_replace('/[\r\n\t]+/', '', $php_bin . ' ' . $app_path . '/' . $dispatcher['script']); ?>

    <div class="row g-3 mb-3">
      <!-- Pola czasowe -->
      <div class="col-12">
        <p class="small fw-semibold mb-2">Pola czasowe — ustaw wszystkie na <code>*</code> (co minutę):</p>
        <div class="d-flex gap-2 flex-wrap">
          <?php foreach (['Minuta' => '*', 'Godzina' => '*', 'Dzień' => '*', 'Miesiąc' => '*', 'Dzień tyg.' => '*'] as $lbl => $val): ?>
          <div class="text-center">
            <div class="small text-muted mb-1" style="font-size:.7rem"><?= $lbl ?></div>
            <div class="border rounded px-3 py-1 fw-bold font-monospace"
                 style="background:#f8fafc;min-width:50px"><?= $val ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Pole Polecenie -->
      <div class="col-12">
        <p class="small fw-semibold mb-1">Pole <strong>„Polecenie"</strong> — wklej dokładnie to (bez spacji na końcu):</p>
        <div class="input-group">
          <input type="text" class="form-control form-control-sm font-monospace"
                 id="da-cmd-input"
                 value="<?= h($da_cmd) ?>"
                 readonly
                 onclick="this.select()"
                 style="background:#1e293b;color:#6ee7b7;border-color:#334155;font-size:.82rem">
          <button class="btn btn-primary btn-sm"
                  onclick="var v=document.getElementById('da-cmd-input').value.trim();navigator.clipboard.writeText(v);this.textContent='✓ Skopiowano';setTimeout(()=>this.textContent='Kopiuj',2000)">
            Kopiuj
          </button>
        </div>
        <div class="form-text mt-1">
          Kliknij w pole → zaznacza całość → <strong>Kopiuj</strong> lub Ctrl+C. Następnie <strong>Dodaj</strong> w DirectAdmin.
        </div>
      </div>
    </div>

    <div class="mt-3 pt-3 border-top d-flex align-items-center gap-2">
      <span class="small text-muted">Token: <code><?= h(substr($cron_token, 0, 8)) ?>…</code></span>
      <form method="post" class="d-inline ms-auto">
        <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="generate_token">
        <button class="btn btn-sm btn-outline-danger"
                onclick="return confirm('Stary token przestanie działać. Zaktualizuj URL w panelu hostingu. Kontynuować?')">
          <i class="bi bi-arrow-clockwise me-1"></i>Wygeneruj nowy token
        </button>
      </form>
    </div>

    <?php else: ?>
    <p class="text-muted small mb-3">Aby korzystać z URL CRON, wygeneruj token bezpieczeństwa.</p>
    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="generate_token">
      <button class="btn btn-primary">
        <i class="bi bi-key me-1"></i>Wygeneruj token i URL
      </button>
    </form>
    <?php endif; ?>

  </div>
</div>

<!-- ══ OPCJA B: curl (URL CRON) ══════════════════════════════════════════════ -->
<div class="card border-info shadow mb-4">
  <div class="card-header bg-info text-white fw-bold py-2">
    <i class="bi bi-link-45deg me-2"></i>Opcja B — URL CRON przez curl (cPanel, Plesk, inne panele bez PHP CLI)
  </div>
  <div class="card-body">

    <?php if ($cron_token): ?>
    <div class="alert alert-info py-2 mb-3 small">
      Gdy panel hostingowy wymaga <strong>adresu URL</strong> zamiast komendy — wpisz URL bezpośrednio lub użyj curl w polu polecenia. Ustaw częstotliwość: <strong>co minutę</strong>.
    </div>

    <p class="small fw-semibold mb-1">Sam URL (wklej w pole „Adres URL" w panelu):</p>
    <?php $url_only = $cron_url; ?>
    <div class="d-flex gap-2 align-items-stretch mb-3">
      <code class="flex-grow-1 p-2 rounded"
            style="background:#1e293b;color:#7dd3fc;font-size:.82rem;word-break:break-all;display:block">
        <?= h($url_only) ?>
      </code>
      <button class="btn btn-info btn-sm text-white flex-shrink-0"
              onclick="navigator.clipboard.writeText(<?= json_encode($url_only) ?>);this.innerHTML='<i class=\'bi bi-check-lg\'></i><br>OK';setTimeout(()=>this.innerHTML='<i class=\'bi bi-clipboard\'></i><br>Kopiuj',2000)"
              style="min-width:65px"><i class="bi bi-clipboard"></i><br>Kopiuj</button>
    </div>

    <p class="small fw-semibold mb-1">Lub jako komenda curl (gdy panel wymaga komendy zamiast URL):</p>
    <?php $curl_cmd2 = 'curl -L -s ' . $cron_url . ' > /dev/null 2>&1'; ?>
    <div class="d-flex gap-2 align-items-stretch mb-0">
      <code class="flex-grow-1 p-2 rounded"
            style="background:#1e293b;color:#7dd3fc;font-size:.82rem;word-break:break-all;display:block">
        <?= h($curl_cmd2) ?>
      </code>
      <button class="btn btn-info btn-sm text-white flex-shrink-0"
              onclick="navigator.clipboard.writeText(<?= json_encode($curl_cmd2) ?>);this.innerHTML='<i class=\'bi bi-check-lg\'></i><br>OK';setTimeout(()=>this.innerHTML='<i class=\'bi bi-clipboard\'></i><br>Kopiuj',2000)"
              style="min-width:65px"><i class="bi bi-clipboard"></i><br>Kopiuj</button>
    </div>

    <div class="mt-3 pt-2 border-top d-flex align-items-center gap-2">
      <span class="small text-muted">Token: <code><?= h(substr($cron_token,0,8)) ?>…</code></span>
      <form method="post" class="d-inline ms-auto">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="generate_token">
        <button class="btn btn-sm btn-outline-danger"
                onclick="return confirm('Stary token przestanie działać. Zaktualizuj URL. Kontynuować?')">
          <i class="bi bi-arrow-clockwise me-1"></i>Nowy token
        </button>
      </form>
    </div>

    <?php else: ?>
    <p class="text-muted small mb-3">Wygeneruj token w Opcji A — zostanie użyty też tutaj.</p>
    <?php endif; ?>

  </div>
</div>

<!-- ══ OPCJA C: CLI crontab ══════════════════════════════════════════════════ -->
<!-- GŁÓWNE polecenie — duże, nie można przegapić -->
<div class="card border-success shadow mb-4">
  <div class="card-header bg-success text-white fw-bold py-2 d-flex align-items-center justify-content-between">
    <span><i class="bi bi-terminal me-2"></i>Opcja C — CLI crontab (SSH, serwer VPS/dedykowany)</span>
    <button class="btn btn-sm btn-light" onclick="copyBlock()">
      <i class="bi bi-clipboard me-1"></i>Kopiuj
    </button>
  </div>
  <div class="card-body p-0">
    <pre id="crontab-block" class="mb-0 p-4" style="background:#022c22;color:#6ee7b7;font-size:.95rem;border-radius:0 0 .5rem .5rem;overflow-x:auto;line-height:1.8;white-space:pre-wrap;word-break:break-all"><?= h($full_cmd) ?></pre>
  </div>
  <div class="card-footer bg-success bg-opacity-10 py-2 small">
    <strong>Jak dodać:</strong>
    w terminalu wpisz <code>crontab -e</code>, wklej powyższą linię, zapisz (<kbd>Ctrl+O</kbd> → <kbd>Enter</kbd> → <kbd>Ctrl+X</kbd> w nano, lub <kbd>:wq</kbd> w vim).
  </div>
</div>

<!-- Krok 0: katalog logów -->
<?php if (!is_dir($log_dir)): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
  <div>
    <strong>Najpierw utwórz katalog logów:</strong>
    <div class="mt-1">
      <pre class="mb-0 d-inline-block px-3 py-1 rounded" style="background:#1e293b;color:#fde68a;font-size:.85rem"><?= h($mkdir_cmd) ?></pre>
      <button class="btn btn-sm btn-outline-warning ms-2" onclick="navigator.clipboard.writeText(<?= json_encode($mkdir_cmd) ?>)">
        <i class="bi bi-clipboard"></i> Kopiuj
      </button>
    </div>
  </div>
</div>
<?php else: ?>
<div class="alert alert-success py-2 small mb-3">
  <i class="bi bi-check-circle me-1"></i>Katalog logów istnieje: <code><?= h($log_dir) ?></code>
</div>
<?php endif; ?>

<!-- Szczegóły techniczne (zwinięte) -->
<div class="mb-3">
  <button class="btn btn-outline-secondary btn-sm" type="button"
          data-bs-toggle="collapse" data-bs-target="#techDetails">
    <i class="bi bi-chevron-down me-1"></i>Szczegóły techniczne (ścieżki)
  </button>
  <div class="collapse mt-2" id="techDetails">
    <div class="card card-body py-2 small">
      <div class="row g-2">
        <div class="col-sm-3 text-muted">PHP binary</div>
        <div class="col-sm-9"><code><?= h($php_bin) ?></code></div>
        <div class="col-sm-3 text-muted">Aplikacja</div>
        <div class="col-sm-9"><code><?= h($app_path) ?></code></div>
        <div class="col-sm-3 text-muted">Skrypt</div>
        <div class="col-sm-9"><code><?= h($app_path . '/' . $dispatcher['script']) ?></code></div>
        <div class="col-sm-3 text-muted">Log</div>
        <div class="col-sm-9"><code><?= h($app_path . '/' . $dispatcher['log']) ?></code></div>
      </div>
    </div>
  </div>
</div>

<!-- Agenty uruchamiane przez dispatcher -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold py-2 d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-check me-1"></i>Zadania uruchamiane przez dispatcher</span>
    <a href="<?= APP_URL ?>/admin/cron_dispatcher.php" class="btn btn-sm btn-primary"><i class="bi bi-calendar2-range me-1"></i>Edytor harmonogramu (wszyscy agenci)</a>
  </div>
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
