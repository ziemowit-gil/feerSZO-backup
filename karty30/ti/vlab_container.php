<?php
/**
 * karty30/ti/vlab_container.php — Podgląd (konsola) maszyny kursanta: status, docker inspect,
 * bieżące zużycie zasobów, logi kontenera, dostęp do terminala (ttyd) oraz szybkie akcje
 * start/stop/restart. Uzupełnienie listy „Maszyny kursantów" w vlab_admin.php.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/vlab.php';

k30_require_access();
karty30_migrate();
if (!(can_write('karty30') || is_admin())) {
    flash_set('danger', 'Brak uprawnień.'); header('Location: index.php'); exit;
}

$cid = (int)($_GET['id'] ?? $_POST['container_id'] ?? 0);
$container = $cid ? db_one(
    "SELECT c.*, cl.name AS client_name, a.login AS student_login, t.name AS tpl_name
     FROM k30_ti_vlab_containers c
     LEFT JOIN k30_clients cl ON cl.id=c.client_id
     LEFT JOIN k30_ti_student_accounts a ON a.id=c.student_id
     LEFT JOIN k30_ti_vlab_templates t ON t.id=c.template_id
     WHERE c.id=? AND c.status!='removed'", [$cid]) : null;
if (!$container) { flash_set('danger', 'Maszyna nie istnieje.'); header('Location: vlab_admin.php'); exit; }

$PAGE_TITLE = 'Podgląd maszyny — VLAB';
$user = current_user();
$uid  = (int)($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';
    if (in_array($op, ['start', 'stop', 'restart', 'remove'], true)) {
        $r = vlab_action($cid, (int)$container['student_id'], $op);
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
        header('Location: ' . ($op === 'remove' ? 'vlab_admin.php' : ('vlab_container.php?id=' . $cid))); exit;
    }
}

// Odśwież po ewentualnej akcji powyżej (status mógł się zmienić).
$container = db_one(
    "SELECT c.*, cl.name AS client_name, a.login AS student_login, t.name AS tpl_name
     FROM k30_ti_vlab_containers c
     LEFT JOIN k30_clients cl ON cl.id=c.client_id
     LEFT JOIN k30_ti_student_accounts a ON a.id=c.student_id
     LEFT JOIN k30_ti_vlab_templates t ON t.id=c.template_id
     WHERE c.id=?", [$cid]
);

$name      = $container['container_name'];
$inspect   = vlab_container_inspect($name);
$stats     = ($container['status'] === 'running') ? vlab_container_stats($name) : null;
$logs      = vlab_container_logs($name, 200);
$ttyd_url  = vlab_ttyd_url($container);
$cfg       = vlab_config();

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item"><a href="vlab_admin.php">VLAB / Docker</a></li>
  <li class="breadcrumb-item active">Podgląd</li>
</ol></nav>

<div class="d-flex align-items-center flex-wrap gap-2 mb-1">
  <h4 class="mb-0 fw-bold"><i class="bi bi-pc-display text-primary me-2"></i><?= h($container['label']) ?></h4>
  <span class="badge bg-<?= ['running'=>'success','stopped'=>'secondary','error'=>'danger','provisioning'=>'warning'][$container['status']] ?? 'secondary' ?>"><?= h($container['status']) ?></span>
</div>
<p class="text-muted small mb-3">
  <?= h($container['client_name'] ?? $container['student_login'] ?? '') ?> ·
  <code><?= h($name) ?></code> ·
  szablon: <?= h($container['tpl_name'] ?? '—') ?>
</p>

<?= flash_html() ?>

<?php if ($container['error_msg']): ?>
<div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= h($container['error_msg']) ?></div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mb-4">
  <?php if ($container['status'] === 'running'): ?>
  <form method="post" onsubmit="return confirm('Zatrzymać tę maszynę?')">
    <?= csrf_field() ?><input type="hidden" name="_op" value="stop"><input type="hidden" name="container_id" value="<?= $cid ?>">
    <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-stop-circle me-1"></i>Zatrzymaj</button>
  </form>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="_op" value="restart"><input type="hidden" name="container_id" value="<?= $cid ?>">
    <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-clockwise me-1"></i>Restart</button>
  </form>
  <?php elseif ($container['status'] === 'stopped'): ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="_op" value="start"><input type="hidden" name="container_id" value="<?= $cid ?>">
    <button class="btn btn-outline-success btn-sm"><i class="bi bi-play-circle me-1"></i>Uruchom</button>
  </form>
  <?php endif; ?>
  <a href="vlab_ports.php?container=<?= $cid ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-hdd-network me-1"></i>Porty</a>
  <a href="vlab_container.php?id=<?= $cid ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Odśwież podgląd</a>
  <form method="post" class="ms-auto" onsubmit="return confirm('Wymusić usunięcie tej maszyny? Konto SSH na hoście też zostanie skasowane.')">
    <?= csrf_field() ?><input type="hidden" name="_op" value="remove"><input type="hidden" name="container_id" value="<?= $cid ?>">
    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Usuń maszynę</button>
  </form>
</div>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header fw-semibold"><i class="bi bi-info-circle me-1"></i>Szczegóły (docker inspect)</div>
      <div class="card-body">
        <?php if (!$inspect): ?>
        <p class="text-muted small mb-0">Nie udało się pobrać szczegółów z hosta (kontener mógł zniknąć na hoście lub host jest nieosiągalny).</p>
        <?php else: ?>
        <table class="table table-sm mb-0">
          <tbody>
            <tr><th class="text-muted small fw-normal" style="width:40%">Obraz</th><td><code class="small"><?= h($inspect['image']) ?></code></td></tr>
            <tr><th class="text-muted small fw-normal">Utworzono</th><td class="small"><?= $inspect['created'] ? h(date('d.m.Y H:i', strtotime($inspect['created']))) : '—' ?></td></tr>
            <tr><th class="text-muted small fw-normal">Uruchomiono</th><td class="small"><?= $inspect['started_at'] && $inspect['started_at'] !== '0001-01-01T00:00:00Z' ? h(date('d.m.Y H:i', strtotime($inspect['started_at']))) : '—' ?></td></tr>
            <tr><th class="text-muted small fw-normal">Stan Dockera</th><td class="small">
              <?= h($inspect['status']) ?>
              <?= $inspect['restarting'] ? ' <span class="badge bg-warning text-dark">restartuje się</span>' : '' ?>
              <?= $inspect['oom_killed'] ? ' <span class="badge bg-danger">OOM killed</span>' : '' ?>
              <?= $inspect['exit_code'] !== null && (int)$inspect['exit_code'] !== 0 ? ' <span class="badge bg-danger">exit ' . (int)$inspect['exit_code'] . '</span>' : '' ?>
            </td></tr>
            <?php if ($stats): ?>
            <tr><th class="text-muted small fw-normal">CPU / RAM (bieżące)</th><td class="small"><?= h($stats['cpu']) ?> / <?= h($stats['mem']) ?></td></tr>
            <?php endif; ?>
            <?php if ($inspect['mem_limit'] > 0 || $inspect['nano_cpus'] > 0): ?>
            <tr><th class="text-muted small fw-normal">Limity</th><td class="small">
              <?= $inspect['nano_cpus'] > 0 ? number_format($inspect['nano_cpus'] / 1e9, 2) . ' CPU' : '' ?>
              <?= $inspect['mem_limit'] > 0 ? ' / ' . number_format($inspect['mem_limit'] / 1048576, 0) . ' MB' : '' ?>
            </td></tr>
            <?php endif; ?>
            <?php if ($inspect['mounts']): ?>
            <tr><th class="text-muted small fw-normal align-top">Woluminy</th><td class="small"><?php foreach ($inspect['mounts'] as $m): ?><div class="font-monospace"><?= h($m) ?></div><?php endforeach; ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header fw-semibold"><i class="bi bi-terminal me-1"></i>Dostęp</div>
      <div class="card-body">
        <?php if ($ttyd_url): ?>
        <p class="small mb-2">Terminal w przeglądarce:</p>
        <div class="input-group input-group-sm mb-2">
          <input type="text" class="form-control font-monospace" readonly value="<?= h($ttyd_url) ?>" onclick="this.select()">
          <a href="<?= h($ttyd_url) ?>" target="_blank" class="btn btn-outline-primary"><i class="bi bi-box-arrow-up-right"></i></a>
        </div>
        <?php if (!empty($container['ttyd_user'])): ?>
        <p class="small text-muted mb-3">Login terminala: <code><?= h($container['ttyd_user']) ?> / <?= h($container['ttyd_password']) ?></code></p>
        <?php endif; ?>
        <?php else: ?>
        <p class="text-muted small mb-3">Terminal ttyd niedostępny (wyłączony w konfiguracji, maszyna nie działa lub brak portu).</p>
        <?php endif; ?>
        <hr>
        <p class="small mb-1">Połączenie SSH:</p>
        <?php if (!empty($container['host_user'])): ?>
        <div class="input-group input-group-sm mb-2">
          <input type="text" class="form-control font-monospace" readonly
                 value="ssh <?= h($container['host_user']) ?>@<?= h($cfg['public_host'] ?? '') ?> -p <?= (int)($container['ssh_port'] ?: ($cfg['ssh_port'] ?: 22)) ?>"
                 onclick="this.select()">
        </div>
        <p class="small text-muted mb-0">Hasło konta nie jest przechowywane — użyj przycisku „reset hasła" na liście maszyn, jeśli potrzebujesz nowego.</p>
        <?php else: ?>
        <p class="text-muted small mb-0">Ta maszyna nie ma jeszcze konta SSH na hoście.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mt-4">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-file-text me-1"></i>Logi kontenera (ostatnie 200 linii)
    <a href="vlab_container.php?id=<?= $cid ?>" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-arrow-repeat me-1"></i>Odśwież</a>
  </div>
  <div class="card-body">
    <?php if ($logs === ''): ?>
    <p class="text-muted small mb-0">Brak logów (lub kontener nie istnieje na hoście).</p>
    <?php else: ?>
    <pre class="bg-body-tertiary p-3 rounded small mb-0" style="max-height:420px;overflow:auto;white-space:pre-wrap"><?= h($logs) ?></pre>
    <?php endif; ?>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
