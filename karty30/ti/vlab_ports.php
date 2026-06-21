<?php
/**
 * karty30/ti/vlab_ports.php — Zarządzanie portami maszyny VLAB.
 * Otwiera/zamyka porty w zaporze hosta (UFW przez SSH) oraz w grupie zabezpieczeń
 * sieci Microsoft Azure (NSG, ARM REST). Tylko dla administracji K30.
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

$cid = (int)($_GET['container'] ?? $_POST['container_id'] ?? 0);
$container = $cid ? db_one(
    "SELECT c.*, cl.name AS client_name, a.login AS student_login
     FROM k30_ti_vlab_containers c
     LEFT JOIN k30_clients cl ON cl.id=c.client_id
     LEFT JOIN k30_ti_student_accounts a ON a.id=c.student_id
     WHERE c.id=? AND c.status!='removed'", [$cid]) : null;
if (!$container) { flash_set('danger', 'Maszyna nie istnieje.'); header('Location: vlab_admin.php'); exit; }

$PAGE_TITLE = 'Porty maszyny — VLAB';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = $_POST['_op'] ?? '';
    $uid = (int)(current_user()['id'] ?? 0);

    if ($op === 'open') {
        $port  = (int)($_POST['host_port'] ?? 0);
        $proto = $_POST['proto'] ?? 'tcp';
        $note  = trim($_POST['note'] ?? '');
        $r = vlab_port_open($cid, $port, $proto, $uid, $note);
        flash_set($r['ok'] ? 'success' : 'danger', 'Port ' . $port . ': ' . $r['msg']);
        header('Location: vlab_ports.php?container=' . $cid); exit;
    }

    if ($op === 'close') {
        $r = vlab_port_close((int)($_POST['port_id'] ?? 0));
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
        header('Location: vlab_ports.php?container=' . $cid); exit;
    }
}

$ports    = vlab_ports_list($cid);
$mappings  = $container['status'] === 'running' ? vlab_docker_ports_all($container['container_name']) : [];
$az_on    = vlab_azure_enabled();
$ufw_on   = vlab_ufw_enabled();

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item"><a href="vlab_admin.php">VLAB / Docker</a></li>
  <li class="breadcrumb-item active">Porty</li>
</ol></nav>

<h4 class="mb-1 fw-bold"><i class="bi bi-hdd-network text-primary me-2"></i>Porty maszyny: <?= h($container['label']) ?></h4>
<p class="text-muted small mb-3">
  <?= h($container['client_name'] ?? $container['student_login'] ?? '') ?> ·
  kontener <code><?= h($container['container_name']) ?></code> ·
  status <span class="badge bg-secondary"><?= h($container['status']) ?></span>
</p>

<?= flash_html() ?>

<div class="alert alert-light border small d-flex flex-wrap gap-3 mb-3">
  <span><i class="bi bi-shield<?= $ufw_on ? '-check text-success' : ' text-muted' ?> me-1"></i>UFW (host): <strong><?= $ufw_on ? 'sterowanie włączone' : 'wyłączone' ?></strong></span>
  <span><i class="bi bi-cloud<?= $az_on ? '-check text-success' : ' text-muted' ?> me-1"></i>Azure NSG: <strong><?= $az_on ? 'skonfigurowane' : 'nieskonfigurowane' ?></strong></span>
</div>

<div class="row g-4">
  <!-- Otwórz port -->
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-plus-circle me-1"></i>Otwórz port</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="open">
          <input type="hidden" name="container_id" value="<?= $cid ?>">
          <div class="row g-2 mb-2">
            <div class="col-7"><label class="form-label small">Port hosta</label>
              <input class="form-control form-control-sm" type="number" name="host_port" min="1" max="65535" required placeholder="np. 8080"></div>
            <div class="col-5"><label class="form-label small">Protokół</label>
              <select class="form-select form-select-sm" name="proto">
                <option value="tcp">TCP</option>
                <option value="udp">UDP</option>
              </select></div>
          </div>
          <div class="mb-2"><label class="form-label small">Opis (opcjonalnie)</label>
            <input class="form-control form-control-sm" name="note" maxlength="200" placeholder="np. serwer WWW kursanta"></div>
          <button class="btn btn-primary btn-sm"><i class="bi bi-unlock me-1"></i>Otwórz port</button>
          <p class="form-text small mb-0 mt-2">Otworzymy port w <?= $ufw_on ? 'UFW' : '' ?><?= $ufw_on && $az_on ? ' i ' : '' ?><?= $az_on ? 'Azure NSG' : '' ?><?= (!$ufw_on && !$az_on) ? '(tylko rejestr — brak aktywnego kanału)' : '' ?>.</p>
        </form>

        <?php if ($mappings): ?>
        <hr>
        <p class="small fw-semibold mb-2">Mapowania portów kontenera</p>
        <div class="table-responsive">
          <table class="table table-sm small mb-0">
            <thead><tr><th>Port kontenera</th><th>Port hosta</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($mappings as $mp): ?>
              <tr>
                <td><code><?= h($mp['cport']) ?></code></td>
                <td><code><?= (int)$mp['host'] ?></code></td>
                <td class="text-end">
                  <form method="post" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="_op" value="open">
                    <input type="hidden" name="container_id" value="<?= $cid ?>">
                    <input type="hidden" name="host_port" value="<?= (int)$mp['host'] ?>">
                    <input type="hidden" name="proto" value="<?= strpos($mp['cport'],'udp')!==false ? 'udp' : 'tcp' ?>">
                    <input type="hidden" name="note" value="<?= h($mp['cport']) ?>">
                    <button class="btn btn-xs btn-sm btn-outline-primary py-0 px-2" title="Otwórz ten port hosta"><i class="bi bi-unlock"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Otwarte porty -->
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-list-check me-1"></i>Otwarte porty (<?= count($ports) ?>)</div>
      <div class="card-body">
        <?php if (!$ports): ?>
        <p class="text-muted small mb-0">Brak otwartych portów dla tej maszyny.</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Port</th><th>Proto</th><th class="text-center">UFW</th><th class="text-center">Azure</th><th>Opis</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($ports as $p): ?>
              <tr>
                <td class="fw-semibold"><?= (int)$p['host_port'] ?></td>
                <td class="text-uppercase small"><?= h($p['proto']) ?></td>
                <td class="text-center"><?= $p['ufw_ok'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-muted"></i>' ?></td>
                <td class="text-center"><?= $p['az_ok'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-muted"></i>' ?></td>
                <td class="small text-muted"><?= h($p['note']) ?></td>
                <td class="text-end">
                  <form method="post" class="d-inline" onsubmit="return confirm('Zamknąć port <?= (int)$p['host_port'] ?>?')">
                    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="_op" value="close">
                    <input type="hidden" name="container_id" value="<?= $cid ?>">
                    <input type="hidden" name="port_id" value="<?= (int)$p['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-lock me-1"></i>Zamknij</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        <p class="text-muted small mb-0 mt-3"><i class="bi bi-info-circle me-1"></i>Port musi być też wystawiony przez kontener (mapowanie <code>docker</code>). Zapora otwiera ruch do <strong>portu hosta</strong>.</p>
      </div>
    </div>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
