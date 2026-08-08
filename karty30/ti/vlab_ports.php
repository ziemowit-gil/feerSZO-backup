<?php
/**
 * karty30/ti/vlab_ports.php — Zarządzanie portami maszyny VLAB.
 * Otwiera/zamyka porty w zaporze hosta (UFW przez SSH) oraz w Azure NSG.
 * Każda zmiana przechodzi przez wniosek zatwierdzany przez admina (is_admin()).
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
$user = current_user();
$uid  = (int)($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    // Złóż wniosek o otwarcie portu
    if ($op === 'request_open') {
        $port  = (int)($_POST['host_port'] ?? 0);
        $proto = $_POST['proto'] ?? 'tcp';
        $note  = trim($_POST['note'] ?? '');
        $r = vlab_port_request_open($cid, $port, $proto, $note, $uid, null);
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
        header('Location: vlab_ports.php?container=' . $cid); exit;
    }

    // Złóż wniosek o zamknięcie portu
    if ($op === 'request_close') {
        $port_id = (int)($_POST['port_id'] ?? 0);
        $r = vlab_port_request_close($port_id, 'k30 staff', $uid, null);
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
        header('Location: vlab_ports.php?container=' . $cid); exit;
    }

    // Zatwierdź wniosek (tylko admin)
    if ($op === 'approve' && is_admin()) {
        $rid = (int)($_POST['request_id'] ?? 0);
        $r = vlab_port_request_approve($rid, $uid);
        flash_set($r['ok'] ? 'success' : 'danger', 'Wniosek #' . $rid . ': ' . $r['msg']);
        header('Location: vlab_ports.php?container=' . $cid); exit;
    }

    // Odrzuć wniosek (tylko admin)
    if ($op === 'reject' && is_admin()) {
        $rid    = (int)($_POST['request_id'] ?? 0);
        $reason = trim($_POST['reject_reason'] ?? '');
        vlab_port_request_reject($rid, $uid, $reason);
        flash_set('warning', 'Wniosek #' . $rid . ' odrzucony.');
        header('Location: vlab_ports.php?container=' . $cid); exit;
    }

    // Bezpośrednie zamknięcie przez admina (bez wniosku — np. awaryjne)
    if ($op === 'force_close' && is_admin()) {
        $r = vlab_port_close((int)($_POST['port_id'] ?? 0));
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
        header('Location: vlab_ports.php?container=' . $cid); exit;
    }

    // Przydzielenie dedykowanego IP i aktywacja zamówienia (tylko admin)
    if ($op === 'dedip_activate' && is_admin()) {
        $oid = (int)($_POST['order_id'] ?? 0);
        $ip  = (string)($_POST['ip_address'] ?? '');
        $r = vlab_dedicated_ip_activate($oid, $uid, $ip);
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
        header('Location: vlab_ports.php?container=' . $cid); exit;
    }

    // Anulowanie zamówienia/usługi dedykowanego IP (tylko admin)
    if ($op === 'dedip_cancel' && is_admin()) {
        $oid    = (int)($_POST['order_id'] ?? 0);
        $reason = trim($_POST['dedip_reason'] ?? '');
        $r = vlab_dedicated_ip_cancel($oid, $uid, $reason);
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
        header('Location: vlab_ports.php?container=' . $cid); exit;
    }

    // Trwałe usunięcie rekordu zamówienia dedykowanego IP z historii (tylko admin)
    if ($op === 'dedip_delete' && is_admin()) {
        $oid = (int)($_POST['order_id'] ?? 0);
        $r = vlab_dedicated_ip_delete($oid, $uid);
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
        header('Location: vlab_ports.php?container=' . $cid); exit;
    }
}

$ports    = vlab_ports_list($cid);
$mappings = $container['status'] === 'running' ? vlab_docker_ports_all($container['container_name']) : [];
$az_on    = vlab_azure_enabled();
$ufw_on   = vlab_ufw_enabled();
$requests = vlab_port_requests_for($cid);
$pending  = array_values(array_filter($requests, fn($r) => $r['status'] === 'pending'));
$history  = array_values(array_filter($requests, fn($r) => $r['status'] !== 'pending'));

$dedip_history = vlab_dedicated_ip_history_for($cid);
$dedip_current = $dedip_history ? $dedip_history[0] : null; // najnowsze zamówienie (requested/active/cancelled)

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item"><a href="vlab_admin.php">VLAB / Docker</a></li>
  <li class="breadcrumb-item active">Porty</li>
</ol></nav>

<div class="d-flex align-items-center flex-wrap gap-2 mb-1">
  <h4 class="mb-0 fw-bold"><i class="bi bi-hdd-network text-primary me-2"></i>Porty: <?= h($container['label']) ?></h4>
  <?php if ($pending): ?>
  <span class="badge text-bg-warning"><?= count($pending) ?> oczekuje</span>
  <?php endif; ?>
</div>
<p class="text-muted small mb-3">
  <?= h($container['client_name'] ?? $container['student_login'] ?? '') ?> ·
  <code><?= h($container['container_name']) ?></code> ·
  <span class="badge bg-secondary"><?= h($container['status']) ?></span>
</p>

<?= flash_html() ?>

<?php if (!is_admin()): ?>
<div class="alert alert-info small py-2 mb-3">
  <i class="bi bi-info-circle me-1"></i>Zmiany portów wymagają zatwierdzenia przez administratora. Złóż wniosek — admin zostanie powiadomiony.
</div>
<?php endif; ?>

<div class="alert alert-light border small d-flex flex-wrap gap-3 mb-3">
  <span><i class="bi bi-shield<?= $ufw_on ? '-check text-success' : ' text-muted' ?> me-1"></i>UFW: <strong><?= $ufw_on ? 'włączone' : 'wyłączone' ?></strong></span>
  <span><i class="bi bi-cloud<?= $az_on ? '-check text-success' : ' text-muted' ?> me-1"></i>Azure NSG: <strong><?= $az_on ? 'skonfigurowane' : 'nie' ?></strong></span>
</div>

<!-- ── Dedykowane IP (usługa płatna) ──────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4<?= ($dedip_current && $dedip_current['status'] === 'requested') ? ' border-warning' : '' ?>">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-globe text-primary"></i>Dedykowane IP
    <?php if ($dedip_current && $dedip_current['status'] === 'requested'): ?>
    <span class="badge text-bg-warning ms-auto">oczekuje na aktywację</span>
    <?php elseif ($dedip_current && $dedip_current['status'] === 'active'): ?>
    <span class="badge text-bg-success ms-auto">aktywne</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (!$dedip_current): ?>
      <p class="text-muted small mb-0">Kursant nie zamówił dedykowanego adresu IP dla tej maszyny.</p>
    <?php elseif ($dedip_current['status'] === 'cancelled'): ?>
      <p class="small mb-3">
        Ostatnie zamówienie #<?= (int)$dedip_current['id'] ?> zostało anulowane
        <?= $dedip_current['cancelled_at'] ? h(date('d.m.Y H:i', strtotime($dedip_current['cancelled_at']))) : '' ?>.
        <?= $dedip_current['note'] !== '' ? '<br><span class="text-muted">Powód: ' . h($dedip_current['note']) . '</span>' : '' ?>
      </p>
      <form method="post" onsubmit="return confirm('Trwale usunąć ten rekord zamówienia z historii? Tej operacji nie można cofnąć.')">
        <?= csrf_field() ?>
        <input type="hidden" name="_op" value="dedip_delete">
        <input type="hidden" name="container_id" value="<?= $cid ?>">
        <input type="hidden" name="order_id" value="<?= (int)$dedip_current['id'] ?>">
        <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Usuń trwale z historii</button>
      </form>
    <?php elseif ($dedip_current['status'] === 'requested'): ?>
      <p class="small mb-3">
        Zamówienie #<?= (int)$dedip_current['id'] ?> z dnia <?= h(date('d.m.Y H:i', strtotime($dedip_current['requested_at']))) ?> ·
        aktywacja <?= number_format((float)$dedip_current['activation_fee'], 2, ',', ' ') ?> zł ·
        abonament <?= number_format((float)$dedip_current['monthly_fee'], 2, ',', ' ') ?> zł/mc
        (dodano do rozliczenia kursanta).
      </p>
      <div class="d-flex flex-wrap gap-3 align-items-end">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="_op" value="dedip_activate">
          <input type="hidden" name="container_id" value="<?= $cid ?>">
          <input type="hidden" name="order_id" value="<?= (int)$dedip_current['id'] ?>">
          <div class="col-auto">
            <label class="form-label small" for="dedip-ip">Adres IP do przydzielenia</label>
            <input class="form-control form-control-sm" id="dedip-ip" name="ip_address" required placeholder="np. 10.20.0.50" style="width:200px">
          </div>
          <div class="col-auto">
            <button class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Aktywuj</button>
          </div>
        </form>
        <form method="post" onsubmit="return confirm('Odrzucić/anulować to zamówienie dedykowanego IP?')">
          <?= csrf_field() ?>
          <input type="hidden" name="_op" value="dedip_cancel">
          <input type="hidden" name="container_id" value="<?= $cid ?>">
          <input type="hidden" name="order_id" value="<?= (int)$dedip_current['id'] ?>">
          <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-lg me-1"></i>Odrzuć zamówienie</button>
        </form>
      </div>
    <?php else: /* active */ ?>
      <p class="small mb-3">
        Adres: <code class="fw-semibold"><?= h($dedip_current['ip_address']) ?></code> ·
        aktywne od <?= h(date('d.m.Y', strtotime($dedip_current['activated_at']))) ?> ·
        abonament <?= number_format((float)$dedip_current['monthly_fee'], 2, ',', ' ') ?> zł/mc
        (ostatnio rozliczono: <?= $dedip_current['last_billed_period'] !== '' ? h($dedip_current['last_billed_period']) : '—' ?>)
      </p>
      <form method="post" onsubmit="return confirm('Zakończyć usługę dedykowanego IP dla tej maszyny? Abonament przestanie być naliczany.')">
        <?= csrf_field() ?>
        <input type="hidden" name="_op" value="dedip_cancel">
        <input type="hidden" name="container_id" value="<?= $cid ?>">
        <input type="hidden" name="order_id" value="<?= (int)$dedip_current['id'] ?>">
        <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-lg me-1"></i>Zakończ usługę</button>
      </form>
    <?php endif; ?>

    <?php if (count($dedip_history) > 1): ?>
    <hr>
    <p class="small fw-semibold mb-1">Historia zamówień</p>
    <ul class="small text-muted mb-0 ps-3">
      <?php foreach (array_slice($dedip_history, 1) as $dh): ?>
      <li class="d-flex align-items-center gap-2">
        <span>#<?= (int)$dh['id'] ?> — <?= h($dh['status']) ?><?= $dh['ip_address'] !== '' ? ' (' . h($dh['ip_address']) . ')' : '' ?>, <?= h(date('d.m.Y', strtotime($dh['requested_at']))) ?></span>
        <?php if ($dh['status'] === 'cancelled'): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('Trwale usunąć ten rekord zamówienia z historii? Tej operacji nie można cofnąć.')">
          <?= csrf_field() ?>
          <input type="hidden" name="_op" value="dedip_delete">
          <input type="hidden" name="container_id" value="<?= $cid ?>">
          <input type="hidden" name="order_id" value="<?= (int)$dh['id'] ?>">
          <button class="btn btn-sm btn-outline-danger py-0" title="Usuń trwale z historii"><i class="bi bi-trash"></i></button>
        </form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>

<!-- ── Wnioski oczekujące (tylko dla admina) ─────────────────────────────── -->
<?php if ($pending && is_admin()): ?>
<div class="card border-warning shadow-sm mb-4">
  <div class="card-header bg-warning bg-opacity-10 d-flex align-items-center gap-2">
    <i class="bi bi-hourglass-split text-warning"></i>
    <span class="fw-semibold">Wnioski oczekujące na zatwierdzenie</span>
    <span class="badge text-bg-warning ms-auto"><?= count($pending) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Akcja</th><th>Port</th><th>Zgłoszone przez</th><th>Data</th><th>Opis</th><th class="text-end">Decyzja</th></tr>
      </thead>
      <tbody>
        <?php foreach ($pending as $req): ?>
        <tr>
          <td>
            <span class="badge <?= $req['action'] === 'open' ? 'text-bg-success' : 'text-bg-danger' ?>">
              <?= $req['action'] === 'open' ? 'Otwórz' : 'Zamknij' ?>
            </span>
          </td>
          <td class="fw-semibold font-monospace"><?= (int)$req['host_port'] ?>/<?= h($req['proto']) ?></td>
          <td><?= h($req['req_name'] ?? '—') ?></td>
          <td class="text-nowrap small"><?= h(date('d.m.Y H:i', strtotime($req['created_at']))) ?></td>
          <td class="small text-muted"><?= h($req['note']) ?></td>
          <td class="text-end">
            <form method="post" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="_op" value="approve">
              <input type="hidden" name="container_id" value="<?= $cid ?>">
              <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
              <button class="btn btn-sm btn-success py-0 me-1" title="Zatwierdź i wykonaj">
                <i class="bi bi-check-lg me-1"></i>Zatwierdź
              </button>
            </form>
            <button class="btn btn-sm btn-outline-danger py-0"
                    data-rid="<?= (int)$req['id'] ?>"
                    onclick="rejectModal(this.dataset.rid)">
              <i class="bi bi-x-lg me-1"></i>Odrzuć
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php elseif ($pending && !is_admin()): ?>
<div class="alert alert-warning py-2 small mb-3">
  <i class="bi bi-hourglass-split me-1"></i><?= count($pending) ?> wniosek(-ów) oczekuje na zatwierdzenie przez admina.
</div>
<?php endif; ?>

<div class="row g-4">
  <!-- Złóż wniosek o otwarcie portu -->
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-plus-circle me-1"></i>Zgłoś otwarcie portu</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="_op" value="request_open">
          <input type="hidden" name="container_id" value="<?= $cid ?>">
          <div class="row g-2 mb-2">
            <div class="col-7">
              <label class="form-label small">Port hosta</label>
              <input class="form-control form-control-sm" type="number" name="host_port" min="1" max="65535" required placeholder="np. 8080">
            </div>
            <div class="col-5">
              <label class="form-label small">Protokół</label>
              <select class="form-select form-select-sm" name="proto">
                <option value="tcp">TCP</option>
                <option value="udp">UDP</option>
              </select>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label small">Opis (opcjonalnie)</label>
            <input class="form-control form-control-sm" name="note" maxlength="200" placeholder="np. serwer WWW kursanta">
          </div>
          <button class="btn btn-primary btn-sm">
            <i class="bi bi-send me-1"></i><?= is_admin() ? 'Złóż wniosek' : 'Złóż wniosek' ?>
          </button>
          <p class="form-text small mb-0 mt-1"><?= is_admin() ? 'Wniosek wymaga zatwierdzenia przez administratora (możesz zatwierdzić od razu).' : 'Wniosek zostanie wykonany po zatwierdzeniu przez administratora.' ?></p>
        </form>

        <?php if ($mappings): ?>
        <hr>
        <p class="small fw-semibold mb-2">Mapowania portów kontenera</p>
        <div class="table-responsive">
          <table class="table table-sm small mb-0">
            <thead><tr><th>Kont.</th><th>Host</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($mappings as $mp): ?>
            <tr>
              <td><code><?= h($mp['cport']) ?></code></td>
              <td><code><?= (int)$mp['host'] ?></code></td>
              <td class="text-end">
                <form method="post" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_op" value="request_open">
                  <input type="hidden" name="container_id" value="<?= $cid ?>">
                  <input type="hidden" name="host_port" value="<?= (int)$mp['host'] ?>">
                  <input type="hidden" name="proto" value="<?= strpos($mp['cport'],'udp')!==false ? 'udp' : 'tcp' ?>">
                  <input type="hidden" name="note" value="<?= h($mp['cport']) ?>">
                  <button class="btn btn-xs btn-sm btn-outline-primary py-0 px-2" title="Zgłoś otwarcie">
                    <i class="bi bi-send"></i>
                  </button>
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
              <td class="text-end d-flex gap-1 justify-content-end">
                <!-- Wniosek o zamknięcie -->
                <form method="post" class="d-inline" onsubmit="return confirm('Złożyć wniosek o zamknięcie portu <?= (int)$p['host_port'] ?>?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_op" value="request_close">
                  <input type="hidden" name="container_id" value="<?= $cid ?>">
                  <input type="hidden" name="port_id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-sm btn-outline-warning py-0" title="Zgłoś zamknięcie (wniosek)">
                    <i class="bi bi-lock me-1"></i>Wniosek
                  </button>
                </form>
                <?php if (is_admin()): ?>
                <!-- Awaryjne zamknięcie bez wniosku -->
                <form method="post" class="d-inline" onsubmit="return confirm('AWARYJNIE zamknąć port <?= (int)$p['host_port'] ?> bez wniosku?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_op" value="force_close">
                  <input type="hidden" name="container_id" value="<?= $cid ?>">
                  <input type="hidden" name="port_id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger py-0" title="Zamknij bez wniosku (awaryjnie)">
                    <i class="bi bi-lightning"></i>
                  </button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        <p class="text-muted small mb-0 mt-2"><i class="bi bi-info-circle me-1"></i>Port musi być wystawiony przez kontener (mapowanie docker). Zapora otwiera ruch do <strong>portu hosta</strong>.</p>
      </div>
    </div>
  </div>
</div>

<!-- Historia wniosków -->
<?php if ($history): ?>
<div class="card border-0 shadow-sm mt-4">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-clock-history text-primary"></i>Historia wniosków
    <span class="badge text-bg-secondary ms-auto"><?= count($history) ?></span>
  </div>
  <div class="table-responsive" style="max-height:300px;overflow-y:auto">
    <table class="table table-sm align-middle mb-0 small">
      <thead class="table-light sticky-top">
        <tr><th>Akcja</th><th>Port</th><th>Wnioskował</th><th>Data</th><th>Status</th><th>Zatwierdził</th><th>Uwagi</th></tr>
      </thead>
      <tbody>
        <?php foreach ($history as $req): ?>
        <tr>
          <td><span class="badge <?= $req['action']==='open' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $req['action']==='open' ? 'Otwórz' : 'Zamknij' ?></span></td>
          <td class="font-monospace"><?= (int)$req['host_port'] ?>/<?= h($req['proto']) ?></td>
          <td><?= h($req['req_name'] ?? '—') ?></td>
          <td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime($req['created_at']))) ?></td>
          <td>
            <?php if ($req['status']==='approved'): ?>
            <span class="badge text-bg-success">Zatwierdzone</span>
            <?php else: ?>
            <span class="badge text-bg-danger">Odrzucone</span>
            <?php endif; ?>
          </td>
          <td><?= h($req['approver_name'] ?? '—') ?></td>
          <td class="text-muted"><?= h($req['reject_reason']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Modal odrzucenia -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-modal="true">
  <div class="modal-dialog">
    <form method="post" id="reject-form">
      <?= csrf_field() ?>
      <input type="hidden" name="_op" value="reject">
      <input type="hidden" name="container_id" value="<?= $cid ?>">
      <input type="hidden" name="request_id" id="reject-rid" value="">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Odrzuć wniosek</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="form-label" for="reject-reason">Powód odrzucenia (opcjonalnie)</label>
          <input type="text" class="form-control" id="reject-reason" name="reject_reason" maxlength="300">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-danger btn-sm">Odrzuć wniosek</button>
        </div>
      </div>
    </form>
  </div>
</div>
<script>
function rejectModal(rid) {
  document.getElementById('reject-rid').value = rid;
  new bootstrap.Modal(document.getElementById('rejectModal')).show();
}
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
