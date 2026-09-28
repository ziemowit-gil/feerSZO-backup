<?php
/**
 * admin/redis.php — konfiguracja opcjonalnego cache Redis
 * (modules/redis_cache/logic/redisCache.php).
 *
 * Ustawienia organizacji redis_* (osobno per tenant). Hasło nie jest nigdy
 * wyświetlane — puste pole przy zapisie = bez zmian. „Testuj” sprawdza
 * wartości z formularza BEZ zapisywania; „Wyczyść cache” usuwa tylko klucze
 * z prefiksem tej instalacji (SCAN, nie FLUSHDB).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');

$test = null;
$form_cfg = function (): array {
    $cur = szo_redis_config();
    $pw  = (string)($_POST['redis_password'] ?? '');
    return [
        'enabled'  => !empty($_POST['redis_enabled']),
        'socket'   => trim((string)($_POST['redis_socket'] ?? '')),
        'host'     => trim((string)($_POST['redis_host'] ?? '')) ?: '127.0.0.1',
        'port'     => max(1, min(65535, (int)($_POST['redis_port'] ?? 6379) ?: 6379)),
        'password' => !empty($_POST['redis_password_clear']) ? '' : ($pw !== '' ? $pw : $cur['password']),
        'db'       => max(0, min(15, (int)($_POST['redis_db'] ?? 0))),
        'prefix'   => trim((string)($_POST['redis_prefix'] ?? '')) ?: $cur['prefix'],
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = (string)($_POST['_op'] ?? '');
    $cfg = $form_cfg();
    if ($op === 'save') {
        if ($cfg['socket'] !== '' && !preg_match('#^/[\w./-]+$#', $cfg['socket'])) {
            flash_set('danger', 'Ścieżka gniazda musi być bezwzględna (np. /usr/home/feer/redis/redis.sock).');
        } else {
            org_setting_set('redis_enabled',  $cfg['enabled'] ? '1' : '0');
            org_setting_set('redis_socket',   $cfg['socket']);
            org_setting_set('redis_host',     $cfg['host']);
            org_setting_set('redis_port',     (string)$cfg['port']);
            org_setting_set('redis_password', $cfg['password']);
            org_setting_set('redis_db',       (string)$cfg['db']);
            org_setting_set('redis_prefix',   $cfg['prefix']);
            $st = szo_redis_status($cfg);
            flash_set($st['ok'] || !$cfg['enabled'] ? 'success' : 'warning',
                'Zapisano.' . ($cfg['enabled'] ? ($st['ok'] ? ' Połączenie działa.' : ' Uwaga: brak połączenia — ' . ($st['error'] ?? '') . '. Do czasu naprawy system działa bez cache.') : ' Cache wyłączony.'));
        }
        header('Location: redis.php'); exit;
    }
    if ($op === 'test') {
        $test = szo_redis_status($cfg);
    }
    if ($op === 'flush') {
        $c = szo_redis(true, szo_redis_config(), $err);
        $n = $c ? szo_cache_flush($c) : 0;
        flash_set($c ? 'success' : 'danger', $c ? "Usunięto kluczy: {$n}." : 'Brak połączenia: ' . $err);
        header('Location: redis.php'); exit;
    }
}

$cfg    = szo_redis_config();
$status = $test ?? szo_redis_status();
$PAGE_TITLE = 'Redis (cache)';
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container py-3" style="max-width:900px">
  <div class="d-flex align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-lightning-charge me-2" aria-hidden="true"></i>Redis (cache)</h1>
    <a href="index.php" class="btn btn-sm btn-outline-secondary ms-auto">Panel admina</a>
  </div>
  <?= flash_html() ?>

  <p class="text-body-secondary small">
    Opcjonalna pamięć podręczna — przyspiesza najcięższe obliczenia (np. Pulpit kierownika w TI).
    Gdy Redis jest wyłączony albo nieosiągalny, system działa normalnie, tylko bez cache.
    Rozszerzenie PHP <code>redis</code>: <strong><?= extension_loaded('redis') ? 'jest' : 'brak — używany wbudowany klient' ?></strong>.
  </p>

  <div class="card mb-3">
    <div class="card-header d-flex align-items-center gap-2">
      Stan<?= $test ? ' (test wartości z formularza, niezapisanych)' : '' ?>
      <span class="badge ms-auto <?= $status['ok'] ? 'text-bg-success' : ($cfg['enabled'] || $test ? 'text-bg-danger' : 'text-bg-secondary') ?>">
        <?= $status['ok'] ? 'połączono' : ($cfg['enabled'] || $test ? 'brak połączenia' : 'wyłączony') ?>
      </span>
    </div>
    <div class="card-body small">
      <?php if ($status['ok']): ?>
      Redis <?= h($status['version']) ?> · klient: <?= h($status['client']) ?> · pamięć: <?= h($status['memory']) ?>
      · kluczy tej instalacji: <?= (int)$status['keys'] ?> (prefiks <code><?= h($status['prefix']) ?></code>) · odpowiedź w <?= (int)$status['ms'] ?> ms
      <?php if (!$cfg['enabled'] && !$test): ?><div class="text-warning-emphasis mt-1">Redis odpowiada, ale cache jest wyłączony — zaznacz „Włączony” i zapisz.</div><?php endif; ?>
      <?php else: ?>
      <?= h($status['error'] ?? 'Brak połączenia') ?> · klient: <?= h($status['client'] ?? '?') ?>
      <?php endif; ?>
    </div>
  </div>

  <form method="post" class="card mb-3">
    <div class="card-header">Konfiguracja</div>
    <div class="card-body row g-3">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <div class="col-12 form-check form-switch ms-2">
        <input class="form-check-input" type="checkbox" role="switch" id="rd-en" name="redis_enabled" value="1"<?= $cfg['enabled'] ? ' checked' : '' ?>>
        <label class="form-check-label" for="rd-en">Włączony</label>
      </div>
      <div class="col-12">
        <label class="form-label" for="rd-sock">Gniazdo unix (zalecane na hostingu współdzielonym)</label>
        <input class="form-control" id="rd-sock" name="redis_socket" value="<?= h($cfg['socket']) ?>" placeholder="/usr/home/feer/redis/redis.sock">
        <div class="form-text">Gdy ustawione — host i port są pomijane.</div>
      </div>
      <div class="col-md-6"><label class="form-label" for="rd-host">Host</label><input class="form-control" id="rd-host" name="redis_host" value="<?= h($cfg['host']) ?>"></div>
      <div class="col-md-3"><label class="form-label" for="rd-port">Port</label><input class="form-control" id="rd-port" name="redis_port" type="number" min="1" max="65535" value="<?= (int)$cfg['port'] ?>"></div>
      <div class="col-md-3"><label class="form-label" for="rd-db">Baza (0–15)</label><input class="form-control" id="rd-db" name="redis_db" type="number" min="0" max="15" value="<?= (int)$cfg['db'] ?>"></div>
      <div class="col-md-6">
        <label class="form-label" for="rd-pw">Hasło (requirepass)</label>
        <input class="form-control" id="rd-pw" name="redis_password" type="password" autocomplete="new-password" placeholder="<?= $cfg['password'] !== '' ? '•••••• ustawione — puste = bez zmian' : 'brak' ?>">
        <?php if ($cfg['password'] !== ''): ?>
        <div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="rd-pwc" name="redis_password_clear" value="1"><label class="form-check-label small" for="rd-pwc">usuń zapisane hasło</label></div>
        <?php endif; ?>
      </div>
      <div class="col-md-6"><label class="form-label" for="rd-pre">Prefiks kluczy</label><input class="form-control" id="rd-pre" name="redis_prefix" value="<?= h($cfg['prefix']) ?>">
        <div class="form-text">Oddziela dane tej instalacji (tenanta) od innych w tym samym Redisie.</div></div>
    </div>
    <div class="card-footer d-flex gap-2 flex-wrap">
      <button class="btn btn-primary" name="_op" value="save">Zapisz</button>
      <button class="btn btn-outline-secondary" name="_op" value="test">Testuj (bez zapisu)</button>
      <button class="btn btn-outline-danger ms-auto" name="_op" value="flush" onclick="return confirm('Usunąć wszystkie klucze tej instalacji z Redisa?')">Wyczyść cache</button>
    </div>
  </form>

  <details class="card">
    <summary class="card-header" style="cursor:pointer">Jak uruchomić Redis na MyDevil</summary>
    <div class="card-body small">
      <p class="mb-1"><strong>Najprościej:</strong> na serwerze uruchom <code>sh cli/mydevil_redis_setup.sh</code> — zapyta o login i domenę
      (domyślnie szo.feer.org.pl), ustawi gniazdo <code>/usr/home/LOGIN/domains/DOMENA/redis.sock</code>, wygeneruje hasło,
      uruchomi Redis w <code>screen</code> i doda autostart <code>@reboot</code>. Na końcu wypisze, co wpisać poniżej.</p>
      <p class="mb-1">Komunikat „Socket operation on non-socket” oznacza, że w polu „Gniazdo unix” wpisano katalog albo zwykły plik
      (np. <code>redis.conf</code>) zamiast ścieżki z linii <code>unixsocket</code>.</p>
      <p class="mb-1">Ręcznie — konfiguracja tylko na gnieździe unix w katalogu domowym (bez portu TCP, bez zapisu na dysk):</p>
<pre class="bg-body-tertiary p-2 border small mb-2">mkdir -p ~/redis && cat > ~/redis/redis.conf &lt;&lt;'EOF'
port 0
unixsocket /usr/home/feer/redis/redis.sock
unixsocketperm 700
daemonize yes
pidfile /usr/home/feer/redis/redis.pid
logfile /usr/home/feer/redis/redis.log
dir /usr/home/feer/redis
maxclients 200
maxmemory 64mb
maxmemory-policy allkeys-lru
save ""
appendonly no
EOF
redis-server ~/redis/redis.conf</pre>
      <p class="mb-1">Plik twórz jak wyżej (<code>cat &lt;&lt;'EOF'</code>), nie w edytorze z obsługą myszy — mcedit potrafi wkleić sekwencje sterujące, na których Redis odmawia startu.
      Ponowny start po restarcie serwera: wpis w crontab <code>@reboot redis-server ~/redis/redis.conf</code>.
      Tutaj w polu „Gniazdo unix” wpisz <code>/usr/home/feer/redis/redis.sock</code>, zaznacz „Włączony” i „Testuj”.</p>
    </div>
  </details>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
