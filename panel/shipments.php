<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/apaczka.php';

require_login();
panel_require_enabled('przesylki', 'Przesyłki');

$enabled = apaczka_setting('apaczka_enabled') !== '0';
if (!$enabled) {
    flash_set('info', 'Moduł przesyłek jest chwilowo niedostępny.');
    header('Location: ' . APP_URL . '/panel/index.php'); exit;
}

$PAGE_TITLE = 'Moje przesyłki';
$user       = current_user();
$uid        = (int)$user['id'];
$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);
$errors     = [];

// Pobierz umowy wolontariackie użytkownika
function panel_vol_contracts(int $uid): array {
    $u     = db_one("SELECT email, microsoft_id FROM users WHERE id=?", [$uid]);
    $email = $u['email'] ?? '';
    $ms_id = $u['microsoft_id'] ?? '';
    if (!$email && !$ms_id) return [];
    $conds = []; $params = [];
    if ($ms_id) { $conds[] = 'm365_user_id=?'; $params[] = $ms_id; }
    if ($email) { $conds[] = 'm365_login=?';   $params[] = $email;
                  $conds[] = 'email=?';         $params[] = $email; }
    return db_all(
        "SELECT id, numer_umowy, imie_nazwisko, adres, telefon, email, m365_login, status
         FROM umowy_wolontariat
         WHERE (" . implode(' OR ', $conds) . ")
           AND status NOT IN ('anulowana','rozwiązana')
         ORDER BY status DESC, id DESC",
        $params
    );
}

$contracts = panel_vol_contracts($uid);
$user_full = db_one("SELECT email, microsoft_id FROM users WHERE id=?", [$uid]);
$user_email = $user_full['email'] ?? '';

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    // Wyślij wniosek o przesyłkę
    if ($action === 'request') {
        $cid     = (int)($_POST['contract_id'] ?? 0);
        $purpose = $_POST['purpose']    ?? 'return_docs';
        $pt_type = $_POST['pickup_type'] ?? 'SELF';
        $weight  = (float)str_replace(',', '.', $_POST['weight'] ?? '0.3');
        $point_id   = trim($_POST['receiver_point_id']   ?? '');
        $point_type = trim($_POST['receiver_point_type'] ?? '');
        $comment = trim($_POST['comment'] ?? '');

        // Sprawdź własność umowy
        $contract = null;
        foreach ($contracts as $c) { if ((int)$c['id'] === $cid) { $contract = $c; break; } }
        if (!$contract) $errors[] = 'Nieprawidłowa umowa.';
        if ($weight <= 0 || $weight > 50) $errors[] = 'Podaj prawidłową wagę paczki.';

        if (!$errors) {
            $defs = apaczka_sender_defaults(); // FEER = odbiorca zwrotu

            db()->prepare(
                "INSERT INTO shipments
                 (contract_id, contract_type, user_id, direction, purpose, status,
                  sender_name, sender_line1, sender_postal_code, sender_city,
                  sender_email, sender_phone,
                  receiver_name, receiver_line1, receiver_postal_code, receiver_city,
                  receiver_email, receiver_phone,
                  receiver_point_id, receiver_point_type,
                  pickup_type, weight, dimension1, dimension2, dimension3,
                  content, comment, created_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'))"
            )->execute([
                $cid, 'wolontariat', $uid, 'return', $purpose, 'requested',
                // nadawca = wolontariusz
                $contract['imie_nazwisko'] ?? '',
                $contract['adres']         ?? '',
                '', // kod pocztowy — wolontariusz wpisuje w komentarzu lub admin uzupełni
                '',
                $user_email,
                $contract['telefon']       ?? '',
                // odbiorca = FEER
                $defs['name'],   $defs['line1'], $defs['postal_code'], $defs['city'],
                $defs['email'],  $defs['phone'],
                $point_id, $point_type,
                $pt_type, $weight, 25, 20, 5,
                SHIPMENT_PURPOSE[$purpose] ?? 'Przesyłka zwrotna',
                $comment,
                $uid,
            ]);

            flash_set('success', 'Wniosek o przesyłkę złożony. Administrator zatwierdzi i prześle etykietę e-mailem.');
            header('Location: ' . APP_URL . '/panel/shipments.php'); exit;
        }
    }

    // Cofnij wniosek (do czasu zatwierdzenia)
    if ($action === 'retract') {
        $sid = (int)($_POST['shipment_id'] ?? 0);
        $s   = db_one("SELECT user_id, status FROM shipments WHERE id=?", [$sid]);
        if ($s && (int)$s['user_id'] === $uid && $s['status'] === 'requested') {
            db()->prepare("UPDATE shipments SET status='draft', updated_at=datetime('now') WHERE id=?")
                ->execute([$sid]);
            flash_set('success', 'Wniosek wycofany.');
        }
        header('Location: ' . APP_URL . '/panel/shipments.php'); exit;
    }
}

$ok_msg = flash_get();

// Przesyłki tego użytkownika
$my_shipments = db_all(
    "SELECT s.*, u.numer_umowy
     FROM shipments s
     LEFT JOIN umowy_wolontariat u ON u.id=s.contract_id
     WHERE s.user_id=? OR s.contract_id IN (
       SELECT id FROM umowy_wolontariat WHERE " .
       (function() use ($user_email, $uid): string {
            $u = db_one("SELECT microsoft_id FROM users WHERE id=?", [$uid]);
            $ms = $u['microsoft_id'] ?? '';
            $parts = [];
            if ($ms)         $parts[] = "m365_user_id=" . db()->quote($ms);
            if ($user_email) { $parts[] = "m365_login=" . db()->quote($user_email);
                               $parts[] = "email=" . db()->quote($user_email); }
            return $parts ? implode(' OR ', $parts) : '0';
       })() . "
     )
     ORDER BY s.created_at DESC
     LIMIT 50",
    [$uid]
);

$show_form = isset($_GET['new']) || $errors;

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
if ($ok_msg) echo '<div class="alert alert-' . h($ok_msg['type']) . ' alert-dismissible fade show py-2">'
    . h($ok_msg['msg']) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
?>
<style>
.ship-panel-stat { background:#fff; border:1px solid #e2e8f0; border-radius:.5rem; padding:.6rem 1rem; }
.ship-panel-stat-lbl { font-size:.67rem; text-transform:uppercase; letter-spacing:.09em; color:#94a3b8; font-weight:700; }
.ship-panel-stat-val { font-size:1.2rem; font-weight:800; color:#1e293b; }
.ship-row { background:#fff; border:1px solid #e2e8f0; border-radius:.5rem; padding:.75rem 1rem; margin-bottom:.6rem; }
.ship-row-pending { border-color:#fde68a; background:#fefce8; }
.ship-row-ordered { border-color:#bfdbfe; background:#eff6ff; }
.ship-row-delivered { border-color:#bbf7d0; background:#f0fdf4; }
.dir-pill { font-size:.7rem; font-weight:700; padding:.1rem .45rem; border-radius:.25rem; }
</style>

<?php if ($_is_volunteer_only): ?>
<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-box-seam me-2" aria-hidden="true"></i>Moje przesyłki</h1>
  <p class="pv-page-sub">Śledzenie wysyłek i paczek</p>
</div>
<?php echo flash_html(); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-box-seam text-primary me-2"></i>Moje przesyłki</h4>
    <div class="text-muted small mt-1">Przesyłki wysyłane przez FEER oraz zwroty dokumentów</div>
  </div>
  <?php if ($contracts && !$show_form): ?>
  <a href="?new=1" class="btn btn-primary">
    <i class="bi bi-arrow-return-left me-1"></i>Odeślij dokumenty / zamów odbiór
  </a>
  <?php endif; ?>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger py-2 small"><ul class="mb-0 ps-3">
  <?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?>
</ul></div>
<?php endif; ?>

<?php if (!$contracts): ?>
<div class="alert alert-info small"><i class="bi bi-info-circle me-2"></i>
  Nie masz aktywnych umów wolontariackich. Moduł przesyłek dostępny po podpisaniu umowy.</div>
<?php else: ?>

<!-- Statystyki -->
<?php
$total = count($my_shipments);
$pending = count(array_filter($my_shipments, fn($s) => $s['status'] === 'requested'));
$ordered = count(array_filter($my_shipments, fn($s) => in_array($s['status'], ['ordered','in_transit'])));
$delivered = count(array_filter($my_shipments, fn($s) => $s['status'] === 'delivered'));
?>
<div class="d-flex gap-2 flex-wrap mb-3">
  <div class="ship-panel-stat"><div class="ship-panel-stat-lbl">Łącznie</div><div class="ship-panel-stat-val"><?= $total ?></div></div>
  <?php if ($pending): ?>
  <div class="ship-panel-stat" style="border-color:#fde68a;background:#fefce8">
    <div class="ship-panel-stat-lbl">Oczekujące</div>
    <div class="ship-panel-stat-val text-warning"><?= $pending ?></div></div>
  <?php endif; ?>
  <?php if ($ordered): ?>
  <div class="ship-panel-stat" style="border-color:#bfdbfe;background:#eff6ff">
    <div class="ship-panel-stat-lbl">W drodze</div>
    <div class="ship-panel-stat-val text-primary"><?= $ordered ?></div></div>
  <?php endif; ?>
  <div class="ship-panel-stat" style="border-color:#bbf7d0;background:#f0fdf4">
    <div class="ship-panel-stat-lbl">Dostarczone</div>
    <div class="ship-panel-stat-val text-success"><?= $delivered ?></div></div>
</div>

<!-- Formularz wniosku o zwrot -->
<?php if ($show_form): ?>
<div class="card border-primary border-opacity-50 mb-3" style="border-left:4px solid #2563eb">
  <div class="card-header fw-semibold">
    <i class="bi bi-arrow-return-left text-primary me-2"></i>Wniosek o przesyłkę zwrotną / odbiór
  </div>
  <div class="card-body">
  <div class="alert alert-info py-2 small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    Po złożeniu wniosku administrator zatwierdzi go i wyśle e-mailem etykietę do wydruku lub zamówi kuriera.
    Wysyłka odbywa się <strong>na koszt FEER</strong>.
  </div>
  <form method="post">
    <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
    <input type="hidden" name="_action"  value="request">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold small">Umowa <span class="text-danger">*</span></label>
        <select name="contract_id" class="form-select" required>
          <option value="">— wybierz umowę —</option>
          <?php foreach ($contracts as $c): ?>
          <option value="<?= $c['id'] ?>" <?= ($errors && ($_POST['contract_id'] ?? '') == $c['id']) ? 'selected' : '' ?>>
            <?= h($c['numer_umowy']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold small">Co odsyłam / co ma dotrzeć?</label>
        <select name="purpose" class="form-select">
          <option value="return_docs" selected>Zwrot dokumentów (podpisane umowy itp.)</option>
          <option value="documents">Dokumenty od FEER do mnie</option>
          <option value="equipment">Materiały / ekwipunek</option>
          <option value="other">Inne</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small">Typ nadania</label>
        <select name="pickup_type" class="form-select" id="panelPickupType">
          <option value="SELF">Sam zaniosę do punktu / paczkomatu</option>
          <option value="COURIER">Poproszę o kuriera pod drzwi</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold small">Szacowana waga (kg)</label>
        <div class="input-group">
          <input name="weight" type="number" step="0.1" min="0.1" max="30"
                 class="form-control" value="0.3" required>
          <span class="input-group-text text-muted">kg</span>
        </div>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold small">Punkt nadania / paczkomat <span class="text-muted fw-normal">(jeśli wybrałeś/aś "Sam zaniosę")</span></label>
        <div class="input-group mb-1">
          <input type="text" id="panelPointSearch" class="form-control form-control-sm"
                 placeholder="Wpisz kod pocztowy lub miasto…">
          <select id="panelPointType" class="form-select form-select-sm" style="max-width:120px">
            <option value="INPOST">InPost</option>
            <option value="POCZTA">Poczta</option>
            <option value="UPS">UPS</option>
          </select>
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="panelSearchPoints()">
            <i class="bi bi-search"></i>
          </button>
        </div>
        <div id="panelPointResults" class="border rounded p-1 mb-2 d-none" style="max-height:180px;overflow-y:auto;font-size:.82rem"></div>
        <div class="input-group input-group-sm" style="max-width:420px">
          <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
          <input name="receiver_point_id"   id="panelPointId"   class="form-control font-monospace"
                 placeholder="ID punktu (np. WAW01N)">
          <input name="receiver_point_type" id="panelPointType2" class="form-control"
                 placeholder="typ" style="max-width:90px">
        </div>
        <div class="form-text">
          Możesz też znaleźć punkt na
          <a href="https://inpost.pl/znajdz-paczkomat" target="_blank">mapie InPost</a>
          i wpisać ID ręcznie.
        </div>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold small">Uwagi dla administratora</label>
        <textarea name="comment" class="form-control" rows="2"
                  placeholder="Np. adres odbioru kuriera, zawartość przesyłki, szczególne życzenia…"></textarea>
      </div>
    </div>
    <div class="d-flex gap-2 mt-3">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-send-check me-1"></i>Złóż wniosek
      </button>
      <a href="<?= APP_URL ?>/panel/shipments.php" class="btn btn-outline-secondary">Anuluj</a>
    </div>
  </form>
  </div>
</div>
<?php endif; ?>

<!-- Lista moich przesyłek -->
<?php if ($my_shipments): ?>
<?php foreach ($my_shipments as $s):
  $row_class = match($s['status']) {
    'requested'  => 'ship-row-pending',
    'ordered','in_transit' => 'ship-row-ordered',
    'delivered'  => 'ship-row-delivered',
    default      => '',
  };
  $wurl = $s['waybill_path'] ? APP_URL . '/uploads/' . $s['waybill_path'] : '';
?>
<div class="ship-row <?= $row_class ?>">
  <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
    <div>
      <span class="dir-pill me-1 <?= $s['direction'] === 'out' ? 'bg-primary text-white' : 'bg-pink text-white' ?>"
            style="background:<?= $s['direction'] === 'out' ? '#2563eb' : '#db2777' ?>">
        <?= $s['direction'] === 'out' ? '→ Do mnie' : '← Do FEER' ?>
      </span>
      <span class="small fw-semibold"><?= h(SHIPMENT_PURPOSE[$s['purpose']] ?? $s['purpose']) ?></span>
      <?php if ($s['numer_umowy']): ?>
      <span class="text-muted small ms-1">(<?= h($s['numer_umowy']) ?>)</span>
      <?php endif; ?>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <?= shipment_badge($s['status']) ?>
      <?php if ($wurl): ?>
      <a href="<?= h($wurl) ?>" target="_blank" class="btn btn-sm btn-outline-dark py-0 px-2">
        <i class="bi bi-printer me-1"></i>Etykieta PDF
      </a>
      <?php endif; ?>
      <?php if ($s['tracking_url']): ?>
      <a href="<?= h($s['tracking_url']) ?>" target="_blank" class="btn btn-sm btn-outline-success py-0 px-2">
        <i class="bi bi-geo-alt me-1"></i>Śledź
      </a>
      <?php endif; ?>
      <?php if ($s['status'] === 'requested'): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="retract">
        <input type="hidden" name="shipment_id" value="<?= $s['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-2">
          <i class="bi bi-x me-1"></i>Wycofaj
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="mt-1 small text-muted">
    <?php if ($s['waybill_number']): ?>
    <i class="bi bi-upc-scan me-1"></i><span class="font-monospace"><?= h($s['waybill_number']) ?></span> ·
    <?php endif; ?>
    <?php if ($s['status'] === 'requested'): ?>
    <span class="text-warning"><i class="bi bi-hourglass-split me-1"></i>Oczekuje na zatwierdzenie przez administratora</span>
    <?php elseif ($s['status'] === 'ordered'): ?>
    <span class="text-primary"><i class="bi bi-box-arrow-right me-1"></i>Zamówione — oczekuje na nadanie</span>
    <?php elseif ($s['status'] === 'in_transit'): ?>
    <span class="text-info"><i class="bi bi-truck me-1"></i>W dostawie</span>
    <?php elseif ($s['status'] === 'delivered'): ?>
    <span class="text-success"><i class="bi bi-check-circle me-1"></i>Dostarczone</span>
    <?php endif; ?>
    <span class="ms-2"><?= date_pl($s['created_at']) ?></span>
  </div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="text-center py-5 text-muted">
  <i class="bi bi-box-seam" style="font-size:2.5rem;opacity:.25"></i>
  <div class="mt-2">Nie masz jeszcze żadnych przesyłek.</div>
  <a href="?new=1" class="btn btn-primary btn-sm mt-2">
    <i class="bi bi-arrow-return-left me-1"></i>Odeślij dokumenty
  </a>
</div>
<?php endif; ?>
<?php endif; /* $contracts */ ?>

<script>
function panelSearchPoints() {
  var q    = document.getElementById('panelPointSearch').value.trim();
  var type = document.getElementById('panelPointType').value;
  var box  = document.getElementById('panelPointResults');
  if (!q) return;
  box.innerHTML = '<div class="text-muted p-2"><span class="spinner-border spinner-border-sm me-1"></span>Szukam…</div>';
  box.classList.remove('d-none');
  fetch('<?= APP_URL ?>/admin/api/apaczka_points.php?type=' + type + '&q=' + encodeURIComponent(q))
    .then(r => r.json()).then(function(data) {
      if (data.error) { box.innerHTML = '<div class="text-danger p-2">' + data.error + '</div>'; return; }
      var pts = data.results || [];
      if (!pts.length) { box.innerHTML = '<div class="text-muted p-2">Brak punktów.</div>'; return; }
      box.innerHTML = pts.map(function(p) {
        return '<div style="padding:.4rem .6rem;cursor:pointer;border-radius:.3rem" '
          + 'onmouseover="this.style.background=\'#eff6ff\'" onmouseout="this.style.background=\'\'" '
          + 'onclick="panelSelectPoint(' + JSON.stringify(p) + ',\'' + type + '\')">'
          + '<strong>' + p.name + '</strong> <span class="text-muted">' + p.line1 + ', ' + p.postal_code + ' ' + p.city + '</span>'
          + '</div>';
      }).join('');
    }).catch(function(e) { box.innerHTML = '<div class="text-danger p-2">Błąd: ' + e.message + '</div>'; });
}
function panelSelectPoint(p, type) {
  document.getElementById('panelPointId').value    = p.id;
  document.getElementById('panelPointType2').value = type;
  document.getElementById('panelPointResults').classList.add('d-none');
  document.getElementById('panelPointSearch').value = p.name + ' — ' + p.city;
}
document.getElementById('panelPointSearch').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') { e.preventDefault(); panelSearchPoints(); }
});
</script>


<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
