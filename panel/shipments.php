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
?>
<div class="pv-wrap">

<?php if ($ok_msg): ?>
<div class="pv-alert pv-alert-<?= h($ok_msg['type']) ?>" role="alert">
  <?= h($ok_msg['msg']) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
</div>
<?php endif; ?>

<div class="pv-page-header">
  <div class="pv-page-head-main">
    <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>
    <h1 class="pv-page-title"><i class="bi bi-box-seam" aria-hidden="true"></i> Przesyłki</h1>
    <p class="pv-page-sub">Zamów dostawę materiałów do wolontariatu</p>
  </div>
  <?php if ($contracts && !$show_form): ?>
  <div class="pv-page-head-actions">
    <a href="?new=1" class="tz-btn">
      <i class="bi bi-arrow-return-left" aria-hidden="true"></i> Odeślij dokumenty / zamów odbiór
    </a>
  </div>
  <?php endif; ?>
</div>

<?php if ($errors): ?>
<div class="tz-note tz-note--danger" role="alert">
  <ul class="mb-0 ps-3">
    <?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (!$contracts): ?>
<div class="tz-note tz-note--info">
  <i class="bi bi-info-circle" aria-hidden="true"></i>
  Nie masz aktywnych umów wolontariackich. Moduł przesyłek dostępny po podpisaniu umowy.
</div>
<?php else: ?>

<?php
$total     = count($my_shipments);
$pending   = count(array_filter($my_shipments, fn($s) => $s['status'] === 'requested'));
$ordered   = count(array_filter($my_shipments, fn($s) => in_array($s['status'], ['ordered', 'in_transit'])));
$delivered = count(array_filter($my_shipments, fn($s) => $s['status'] === 'delivered'));
?>
<div class="d-flex gap-2 flex-wrap mb-3 align-items-center">
  <span class="tz-badge">Łącznie: <?= $total ?></span>
  <?php if ($pending): ?>
  <span class="tz-badge tz-badge--warning">Oczekujące: <?= $pending ?></span>
  <?php endif; ?>
  <?php if ($ordered): ?>
  <span class="tz-badge tz-badge--info">W drodze: <?= $ordered ?></span>
  <?php endif; ?>
  <span class="tz-badge tz-badge--success">Dostarczone: <?= $delivered ?></span>
</div>

<?php if ($show_form): ?>
<div class="tz-card mb-3">
  <div class="tz-card__hd">
    <i class="bi bi-arrow-return-left" aria-hidden="true"></i> Wniosek o przesyłkę zwrotną / odbiór
  </div>
  <div class="tz-card__bd">
    <div class="tz-note tz-note--info mb-3">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      Po złożeniu wniosku administrator zatwierdzi go i wyśle e-mailem etykietę do wydruku lub zamówi kuriera.
      Wysyłka odbywa się <strong>na koszt FEER</strong>.
    </div>
    <form method="post" novalidate>
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="request">
      <div class="row g-3">
        <div class="col-md-6">
          <label for="ship_contract_id" class="form-label fw-semibold small">
            Umowa <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden">(wymagane)</span>
          </label>
          <select id="ship_contract_id" name="contract_id" class="form-select"
                  required aria-required="true">
            <option value="">— wybierz umowę —</option>
            <?php foreach ($contracts as $c): ?>
            <option value="<?= $c['id'] ?>" <?= ($errors && ($_POST['contract_id'] ?? '') == $c['id']) ? 'selected' : '' ?>>
              <?= h($c['numer_umowy']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label for="ship_purpose" class="form-label fw-semibold small">Co odsyłam / co ma dotrzeć?</label>
          <select id="ship_purpose" name="purpose" class="form-select">
            <option value="return_docs" selected>Zwrot dokumentów (podpisane umowy itp.)</option>
            <option value="documents">Dokumenty od FEER do mnie</option>
            <option value="equipment">Materiały / ekwipunek</option>
            <option value="other">Inne</option>
          </select>
        </div>
        <div class="col-md-4">
          <label for="ship_pickup_type" class="form-label fw-semibold small">Typ nadania</label>
          <select id="ship_pickup_type" name="pickup_type" class="form-select">
            <option value="SELF">Sam zaniosę do punktu / paczkomatu</option>
            <option value="COURIER">Poproszę o kuriera pod drzwi</option>
          </select>
        </div>
        <div class="col-md-3">
          <label for="ship_weight" class="form-label fw-semibold small">Szacowana waga (kg)</label>
          <div class="input-group">
            <input id="ship_weight" name="weight" type="number" step="0.1" min="0.1" max="30"
                   class="form-control" value="0.3" required aria-required="true">
            <span class="input-group-text text-muted">kg</span>
          </div>
        </div>
        <div class="col-12">
          <label for="panelPointSearch" class="form-label fw-semibold small">
            Punkt nadania / paczkomat
            <span class="text-muted fw-normal">(jeśli wybrałeś/aś "Sam zaniosę")</span>
          </label>
          <div class="input-group mb-1">
            <input type="text" id="panelPointSearch" class="form-control form-control-sm"
                   placeholder="Wpisz kod pocztowy lub miasto…">
            <select id="panelPointType" class="form-select form-select-sm" style="max-width:120px"
                    aria-label="Typ punktu nadania">
              <option value="INPOST">InPost</option>
              <option value="POCZTA">Poczta</option>
              <option value="UPS">UPS</option>
            </select>
            <button type="button" class="tz-btn tz-btn--ghost btn-sm" onclick="panelSearchPoints()"
                    aria-label="Szukaj punktów nadania">
              <i class="bi bi-search" aria-hidden="true"></i>
            </button>
          </div>
          <div id="panelPointResults" class="border rounded p-1 mb-2 d-none"
               style="max-height:180px;overflow-y:auto;font-size:.82rem"
               role="listbox" aria-label="Wyniki wyszukiwania punktów"></div>
          <div class="input-group input-group-sm" style="max-width:420px">
            <span class="input-group-text"><i class="bi bi-geo-alt" aria-hidden="true"></i></span>
            <input id="panelPointId" name="receiver_point_id" class="form-control font-monospace"
                   placeholder="ID punktu (np. WAW01N)" aria-label="ID punktu nadania">
            <input id="panelPointType2" name="receiver_point_type" class="form-control"
                   placeholder="typ" style="max-width:90px" aria-label="Typ punktu (kod)">
          </div>
          <div class="form-text">
            Możesz też znaleźć punkt na
            <a href="https://inpost.pl/znajdz-paczkomat" target="_blank" rel="noopener noreferrer">mapie InPost</a>
            i wpisać ID ręcznie.
          </div>
        </div>
        <div class="col-12">
          <label for="ship_comment" class="form-label fw-semibold small">Uwagi dla administratora</label>
          <textarea id="ship_comment" name="comment" class="form-control" rows="2"
                    placeholder="Np. adres odbioru kuriera, zawartość przesyłki, szczególne życzenia…"></textarea>
        </div>
      </div>
      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="tz-btn">
          <i class="bi bi-send-check" aria-hidden="true"></i> Złóż wniosek
        </button>
        <a href="<?= APP_URL ?>/panel/shipments.php" class="tz-btn tz-btn--ghost">Anuluj</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($my_shipments): ?>
<div class="pv-table-wrap">
  <table class="pv-table">
    <thead>
      <tr>
        <th scope="col">Kierunek / cel</th>
        <th scope="col">Umowa</th>
        <th scope="col">Status</th>
        <th scope="col">Data</th>
        <th scope="col" class="text-end">Akcje</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($my_shipments as $s):
      $wurl     = $s['waybill_path'] ? APP_URL . '/uploads/' . $s['waybill_path'] : '';
      $dirLabel = $s['direction'] === 'out' ? '→ Do mnie' : '← Do FEER';
      $dirClass = $s['direction'] === 'out' ? 'pv-sp-info' : 'pv-sp-warning';
    ?>
    <tr>
      <td>
        <span class="pv-status-pill <?= $dirClass ?>"><?= $dirLabel ?></span>
        <span class="small fw-semibold ms-1"><?= h(SHIPMENT_PURPOSE[$s['purpose']] ?? $s['purpose']) ?></span>
        <?php if ($s['waybill_number']): ?>
        <div class="text-muted small font-monospace mt-1">
          <i class="bi bi-upc-scan" aria-hidden="true"></i> <?= h($s['waybill_number']) ?>
        </div>
        <?php endif; ?>
      </td>
      <td class="text-muted small"><?= $s['numer_umowy'] ? h($s['numer_umowy']) : '—' ?></td>
      <td>
        <?= shipment_badge($s['status']) ?>
        <?php if ($s['status'] === 'requested'): ?>
        <div class="small text-warning mt-1"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Oczekuje na zatwierdzenie</div>
        <?php elseif ($s['status'] === 'ordered'): ?>
        <div class="small text-primary mt-1"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Oczekuje na nadanie</div>
        <?php elseif ($s['status'] === 'in_transit'): ?>
        <div class="small text-info mt-1"><i class="bi bi-truck" aria-hidden="true"></i> W dostawie</div>
        <?php elseif ($s['status'] === 'delivered'): ?>
        <div class="small text-success mt-1"><i class="bi bi-check-circle" aria-hidden="true"></i> Dostarczone</div>
        <?php endif; ?>
      </td>
      <td class="small text-muted text-nowrap"><?= date_pl($s['created_at']) ?></td>
      <td class="text-end">
        <div class="d-flex gap-1 justify-content-end flex-wrap">
        <?php if ($wurl): ?>
        <a href="<?= h($wurl) ?>" target="_blank" rel="noopener noreferrer"
           class="tz-btn tz-btn--ghost btn-sm"
           aria-label="Pobierz etykietę PDF dla przesyłki <?= h($s['numer_umowy'] ?? $s['id']) ?>">
          <i class="bi bi-printer" aria-hidden="true"></i> Etykieta PDF
        </a>
        <?php endif; ?>
        <?php if ($s['tracking_url']): ?>
        <a href="<?= h($s['tracking_url']) ?>" target="_blank" rel="noopener noreferrer"
           class="tz-btn tz-btn--ghost btn-sm"
           aria-label="Śledź przesyłkę <?= h($s['numer_umowy'] ?? $s['id']) ?>">
          <i class="bi bi-geo-alt" aria-hidden="true"></i> Śledź
        </a>
        <?php endif; ?>
        <?php if ($s['status'] === 'requested'): ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="retract">
          <input type="hidden" name="shipment_id" value="<?= $s['id'] ?>">
          <button type="submit" class="tz-btn tz-btn--ghost btn-sm">
            <i class="bi bi-x" aria-hidden="true"></i> Wycofaj
          </button>
        </form>
        <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="tz-empty">
  <i class="bi bi-box-seam" aria-hidden="true"></i>
  <p>Nie masz jeszcze żadnych przesyłek.</p>
  <a href="?new=1" class="tz-btn">
    <i class="bi bi-arrow-return-left" aria-hidden="true"></i> Odeślij dokumenty
  </a>
</div>
<?php endif; ?>
<?php endif; /* $contracts */ ?>

</div><!-- /.pv-wrap -->

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
