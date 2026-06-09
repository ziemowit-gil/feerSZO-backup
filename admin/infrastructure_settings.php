<?php
/**
 * admin/infrastructure_settings.php — Konfiguracja infrastruktury: Redis + RabbitMQ
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'Infrastruktura — Redis & RabbitMQ';

// ── Helpers ───────────────────────────────────────────────────────────────────
function infra_get(string $key): string {
    return org_setting($key);
}

function infra_save(string $key, string $value): void {
    db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")->execute([$key, $value]);
}

function infra_defaults(): array {
    return [
        'infra_redis_host'    => getenv('REDIS_HOST')    ?: 'redis',
        'infra_redis_port'    => getenv('REDIS_PORT')    ?: '6379',
        'infra_redis_pass'    => '',
        'infra_redis_prefix'  => 'feer:',
        'infra_redis_ttl'     => '86400',
        'infra_rabbit_host'   => getenv('RABBITMQ_HOST')  ?: 'rabbitmq',
        'infra_rabbit_port'   => getenv('RABBITMQ_PORT')  ?: '5672',
        'infra_rabbit_user'   => getenv('RABBITMQ_USER')  ?: 'feer',
        'infra_rabbit_pass'   => getenv('RABBITMQ_PASS')  ?: 'feer',
        'infra_rabbit_vhost'  => getenv('RABBITMQ_VHOST') ?: '/',
    ];
}

// ── Wczytaj bieżące ustawienia (DB → env → domyślne) ─────────────────────────
$defaults = infra_defaults();
$cfg = [];
foreach (array_keys($defaults) as $k) {
    $cfg[$k] = infra_get($k) ?: $defaults[$k];
}

// ── POST: zapis ───────────────────────────────────────────────────────────────
$test_redis  = null;
$test_rabbit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    // ── Zbierz wartości z formularza ─────────────────────────────────────────
    $new = [
        'infra_redis_host'   => trim($_POST['infra_redis_host']   ?? ''),
        'infra_redis_port'   => trim($_POST['infra_redis_port']   ?? ''),
        'infra_redis_pass'   => $_POST['infra_redis_pass_change'] ?? '' // puste = nie zmieniaj
                                    ? trim($_POST['infra_redis_pass'] ?? '')
                                    : $cfg['infra_redis_pass'],
        'infra_redis_prefix' => trim($_POST['infra_redis_prefix'] ?? ''),
        'infra_redis_ttl'    => trim($_POST['infra_redis_ttl']    ?? ''),
        'infra_rabbit_host'  => trim($_POST['infra_rabbit_host']  ?? ''),
        'infra_rabbit_port'  => trim($_POST['infra_rabbit_port']  ?? ''),
        'infra_rabbit_user'  => trim($_POST['infra_rabbit_user']  ?? ''),
        'infra_rabbit_pass'  => $_POST['infra_rabbit_pass_change'] ?? ''
                                    ? trim($_POST['infra_rabbit_pass'] ?? '')
                                    : $cfg['infra_rabbit_pass'],
        'infra_rabbit_vhost' => trim($_POST['infra_rabbit_vhost'] ?? ''),
    ];
    // Uzupełnij braki wartościami domyślnymi
    foreach ($new as $k => $v) {
        if ($v === '') $new[$k] = $defaults[$k];
    }

    // ── Test Redis ────────────────────────────────────────────────────────────
    if (in_array($action, ['test_redis', 'save'], true)) {
        $test_redis = _infra_test_redis($new['infra_redis_host'], (int)$new['infra_redis_port'], $new['infra_redis_pass']);
    }

    // ── Test RabbitMQ ─────────────────────────────────────────────────────────
    if (in_array($action, ['test_rabbit', 'save'], true)) {
        $test_rabbit = _infra_test_rabbit($new['infra_rabbit_host'], (int)$new['infra_rabbit_port'],
                                          $new['infra_rabbit_user'],  $new['infra_rabbit_pass'],
                                          $new['infra_rabbit_vhost']);
    }

    // ── Zapis ─────────────────────────────────────────────────────────────────
    if ($action === 'save') {
        foreach ($new as $k => $v) {
            infra_save($k, $v);
        }
        // Odśwież $cfg
        foreach ($new as $k => $v) $cfg[$k] = $v;
        flash_set('success', 'Konfiguracja infrastruktury zapisana.');
        header('Location: infrastructure_settings.php'); exit;
    }

    // Zaktualizuj podgląd w formularzu bez redirect (przy akcji test_*)
    foreach ($new as $k => $v) $cfg[$k] = $v;
}

// ── Testy połączeń przy ładowaniu strony (GET, jeśli cokolwiek skonfigurowane) ─
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $test_redis  = _infra_test_redis($cfg['infra_redis_host'],  (int)$cfg['infra_redis_port'],  $cfg['infra_redis_pass']);
    $test_rabbit = _infra_test_rabbit($cfg['infra_rabbit_host'], (int)$cfg['infra_rabbit_port'],
                                      $cfg['infra_rabbit_user'], $cfg['infra_rabbit_pass'],
                                      $cfg['infra_rabbit_vhost']);
}

// ── Funkcje testujące ─────────────────────────────────────────────────────────
function _infra_test_redis(string $host, int $port, string $pass): array {
    if (!extension_loaded('redis')) {
        return ['ok' => false, 'msg' => 'Rozszerzenie ext-redis nie jest zainstalowane.'];
    }
    try {
        $r = new Redis();
        if (!@$r->connect($host, $port, 2.0)) {
            return ['ok' => false, 'msg' => "Nie można połączyć z {$host}:{$port}"];
        }
        if ($pass !== '' && !$r->auth($pass)) {
            return ['ok' => false, 'msg' => 'Błąd autoryzacji (nieprawidłowe hasło)'];
        }
        $pong    = $r->ping('pong');
        $version = $r->info('server')['redis_version'] ?? '?';
        $mem     = $r->info('memory')['used_memory_human'] ?? '?';
        $r->close();
        return ['ok' => true, 'msg' => "Połączono. Redis {$version} · pamięć: {$mem}"];
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

function _infra_test_rabbit(string $host, int $port, string $user, string $pass, string $vhost): array {
    if (!class_exists('\PhpAmqpLib\Connection\AMQPStreamConnection')) {
        return ['ok' => false, 'msg' => 'Biblioteka php-amqplib nie jest zainstalowana (composer install).'];
    }
    try {
        $conn = new \PhpAmqpLib\Connection\AMQPStreamConnection($host, $port, $user, $pass, $vhost,
            false, 'AMQPLAIN', null, 'en_US', 3.0, 3.0);
        $props   = $conn->getServerProperties();
        $version = $props['version']->getValue()  ?? '?';
        $product = $props['product']->getValue()  ?? 'RabbitMQ';
        $conn->close();
        return ['ok' => true, 'msg' => "Połączono. {$product} {$version}"];
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.status-dot { width:10px;height:10px;border-radius:50%;display:inline-block;flex-shrink:0 }
.dot-ok     { background:#22c55e }
.dot-fail   { background:#ef4444 }
.dot-unknown{ background:#94a3b8 }
.infra-card .card-header { font-weight:600;letter-spacing:.01em }
</style>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-hdd-network text-primary"></i> Infrastruktura — Redis &amp; RabbitMQ</h4>
</div>

<?= flash_html() ?>

<form method="post" autocomplete="off" novalidate>
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="_action" id="infra_action" value="save">

<div class="row g-3">

<!-- ══ REDIS ══════════════════════════════════════════════════════════════════ -->
<div class="col-xl-6">
<div class="card shadow-sm infra-card h-100">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-lightning-charge-fill text-danger"></i>
    Redis — sesje logowania
    <?php $rd_ok = $test_redis['ok'] ?? null; ?>
    <span class="ms-auto d-flex align-items-center gap-1 small fw-normal
          <?= $rd_ok === true ? 'text-success' : ($rd_ok === false ? 'text-danger' : 'text-muted') ?>">
      <span class="status-dot <?= $rd_ok === true ? 'dot-ok' : ($rd_ok === false ? 'dot-fail' : 'dot-unknown') ?>"></span>
      <?= $rd_ok === true ? 'Połączono' : ($rd_ok === false ? 'Brak połączenia' : 'Nie sprawdzono') ?>
    </span>
  </div>
  <div class="card-body">

    <?php if ($test_redis !== null): ?>
    <div class="alert alert-<?= $test_redis['ok'] ? 'success' : 'danger' ?> alert-sm py-2 small d-flex align-items-center gap-2 mb-3">
      <i class="bi bi-<?= $test_redis['ok'] ? 'check-circle' : 'x-circle' ?>-fill"></i>
      <?= h($test_redis['msg']) ?>
    </div>
    <?php endif; ?>

    <div class="alert alert-light border small p-2 mb-3">
      <i class="bi bi-info-circle text-primary"></i>
      Redis przechowuje sesje logowania. Ustawienia są stosowane przez
      <code>ini_set()</code> przed <code>session_start()</code> — aktywne natychmiast po zapisie.
    </div>

    <div class="row g-3">
      <div class="col-md-8">
        <label class="form-label fw-semibold small">Host <span class="text-danger">*</span></label>
        <input type="text" name="infra_redis_host" class="form-control form-control-sm font-monospace"
               value="<?= h($cfg['infra_redis_host']) ?>" placeholder="redis" required>
        <div class="form-text">Nazwa kontenera lub adres IP</div>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small">Port</label>
        <input type="number" name="infra_redis_port" class="form-control form-control-sm"
               value="<?= h($cfg['infra_redis_port']) ?>" min="1" max="65535" placeholder="6379">
      </div>
      <div class="col-md-8">
        <label class="form-label fw-semibold small">Prefiks kluczy sesji</label>
        <input type="text" name="infra_redis_prefix" class="form-control form-control-sm font-monospace"
               value="<?= h($cfg['infra_redis_prefix']) ?>" placeholder="feer:">
        <div class="form-text">Izoluje klucze aplikacji w Redis od innych usług</div>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small">TTL sesji (s)</label>
        <input type="number" name="infra_redis_ttl" class="form-control form-control-sm"
               value="<?= h($cfg['infra_redis_ttl']) ?>" min="60" placeholder="86400">
        <div class="form-text">86400 = 24h</div>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold small">Hasło</label>
        <?php if ($cfg['infra_redis_pass'] !== ''): ?>
        <div class="form-check mb-1">
          <input class="form-check-input" type="checkbox" name="infra_redis_pass_change" id="redis_pass_change" value="1">
          <label class="form-check-label small" for="redis_pass_change">Zmień hasło</label>
        </div>
        <input type="password" name="infra_redis_pass" class="form-control form-control-sm"
               placeholder="(skonfigurowane — zaznacz powyżej, żeby zmienić)"
               id="redis_pass_field" disabled>
        <script>document.getElementById('redis_pass_change').addEventListener('change',function(){
          document.getElementById('redis_pass_field').disabled=!this.checked;
        });</script>
        <?php else: ?>
        <input type="hidden" name="infra_redis_pass_change" value="1">
        <input type="password" name="infra_redis_pass" class="form-control form-control-sm"
               placeholder="Pozostaw puste jeśli bez hasła">
        <?php endif; ?>
        <div class="form-text">Opcjonalne — wymagane tylko gdy Redis jest zabezpieczony hasłem</div>
      </div>
    </div><!-- /row -->

  </div><!-- /card-body -->
  <div class="card-footer bg-transparent d-flex gap-2">
    <button type="submit" class="btn btn-sm btn-outline-secondary"
            onclick="document.getElementById('infra_action').value='test_redis'">
      <i class="bi bi-plug"></i> Testuj połączenie
    </button>
  </div>
</div><!-- /card -->
</div><!-- /col -->

<!-- ══ RABBITMQ ════════════════════════════════════════════════════════════════ -->
<div class="col-xl-6">
<div class="card shadow-sm infra-card h-100">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-send-fill text-warning"></i>
    RabbitMQ — kolejka wiadomości e-mail
    <?php $rb_ok = $test_rabbit['ok'] ?? null; ?>
    <span class="ms-auto d-flex align-items-center gap-1 small fw-normal
          <?= $rb_ok === true ? 'text-success' : ($rb_ok === false ? 'text-danger' : 'text-muted') ?>">
      <span class="status-dot <?= $rb_ok === true ? 'dot-ok' : ($rb_ok === false ? 'dot-fail' : 'dot-unknown') ?>"></span>
      <?= $rb_ok === true ? 'Połączono' : ($rb_ok === false ? 'Brak połączenia' : 'Nie sprawdzono') ?>
    </span>
  </div>
  <div class="card-body">

    <?php if ($test_rabbit !== null): ?>
    <div class="alert alert-<?= $test_rabbit['ok'] ? 'success' : 'danger' ?> alert-sm py-2 small d-flex align-items-center gap-2 mb-3">
      <i class="bi bi-<?= $test_rabbit['ok'] ? 'check-circle' : 'x-circle' ?>-fill"></i>
      <?= h($test_rabbit['msg']) ?>
    </div>
    <?php endif; ?>

    <div class="alert alert-light border small p-2 mb-3">
      <i class="bi bi-info-circle text-primary"></i>
      Wiadomości e-mail są publikowane na kolejkę <code>mail.send</code>.
      Cron konsumuje i wysyła co minutę. Panel zarządzania: <code>:15672</code>.
    </div>

    <div class="row g-3">
      <div class="col-md-8">
        <label class="form-label fw-semibold small">Host <span class="text-danger">*</span></label>
        <input type="text" name="infra_rabbit_host" class="form-control form-control-sm font-monospace"
               value="<?= h($cfg['infra_rabbit_host']) ?>" placeholder="rabbitmq" required>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small">Port</label>
        <input type="number" name="infra_rabbit_port" class="form-control form-control-sm"
               value="<?= h($cfg['infra_rabbit_port']) ?>" min="1" max="65535" placeholder="5672">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold small">Użytkownik <span class="text-danger">*</span></label>
        <input type="text" name="infra_rabbit_user" class="form-control form-control-sm"
               value="<?= h($cfg['infra_rabbit_user']) ?>" placeholder="feer" required>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold small">Virtual host</label>
        <input type="text" name="infra_rabbit_vhost" class="form-control form-control-sm font-monospace"
               value="<?= h($cfg['infra_rabbit_vhost']) ?>" placeholder="/">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold small">Hasło <span class="text-danger">*</span></label>
        <?php if ($cfg['infra_rabbit_pass'] !== ''): ?>
        <div class="form-check mb-1">
          <input class="form-check-input" type="checkbox" name="infra_rabbit_pass_change" id="rabbit_pass_change" value="1">
          <label class="form-check-label small" for="rabbit_pass_change">Zmień hasło</label>
        </div>
        <input type="password" name="infra_rabbit_pass" class="form-control form-control-sm"
               placeholder="(skonfigurowane — zaznacz powyżej, żeby zmienić)"
               id="rabbit_pass_field" disabled>
        <script>document.getElementById('rabbit_pass_change').addEventListener('change',function(){
          document.getElementById('rabbit_pass_field').disabled=!this.checked;
        });</script>
        <?php else: ?>
        <input type="hidden" name="infra_rabbit_pass_change" value="1">
        <input type="password" name="infra_rabbit_pass" class="form-control form-control-sm"
               placeholder="Hasło użytkownika RabbitMQ" required>
        <?php endif; ?>
      </div>
    </div><!-- /row -->

  </div><!-- /card-body -->
  <div class="card-footer bg-transparent d-flex gap-2">
    <button type="submit" class="btn btn-sm btn-outline-secondary"
            onclick="document.getElementById('infra_action').value='test_rabbit'">
      <i class="bi bi-plug"></i> Testuj połączenie
    </button>
  </div>
</div><!-- /card -->
</div><!-- /col -->

</div><!-- /row -->

<!-- ── Zapisz ─────────────────────────────────────────────────────────────────── -->
<div class="mt-3 d-flex justify-content-end">
  <button type="submit" class="btn btn-primary"
          onclick="document.getElementById('infra_action').value='save'">
    <i class="bi bi-floppy"></i> Zapisz i przetestuj
  </button>
</div>

</form>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
