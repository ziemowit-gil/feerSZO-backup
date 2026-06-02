<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/apaczka.php';
require_once dirname(__DIR__) . '/includes/furgonetka.php';

require_role('admin', 'editor');
$PAGE_TITLE = 'Przesyłki';
$errors = [];

// ── Pobierz dostępne usługi z cache ──────────────────────────────────────────
$services_cache = json_decode(apaczka_setting('apaczka_services_cache') ?: '[]', true) ?: [];
$furg_services_cache = json_decode(furgonetka_setting('furgonetka_services_cache') ?: '[]', true) ?: [];

// ── Obsługa POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    // Utwórz / edytuj przesyłkę (szkic)
    if (in_array($action, ['create', 'update'], true)) {
        $sid = (int)($_POST['shipment_id'] ?? 0);

        $d = [
            'contract_id'           => (int)($_POST['contract_id'] ?? 0) ?: null,
            'contract_type'         => preg_replace('/[^a-z]/', '', $_POST['contract_type'] ?? 'wolontariat'),
            'direction'             => $_POST['direction']   ?? 'out',
            'purpose'               => $_POST['purpose']     ?? 'documents',
            'service_id'            => (int)($_POST['service_id'] ?? 0) ?: null,
            'provider'              => $_POST['provider'] ?? 'apaczka',
            'furgonetka_service'    => trim($_POST['furgonetka_service'] ?? ''),
            'sender_name'           => trim($_POST['sender_name']          ?? ''),
            'sender_line1'          => trim($_POST['sender_line1']         ?? ''),
            'sender_line2'          => trim($_POST['sender_line2']         ?? ''),
            'sender_postal_code'    => trim($_POST['sender_postal_code']   ?? ''),
            'sender_city'           => trim($_POST['sender_city']          ?? ''),
            'sender_email'          => trim($_POST['sender_email']         ?? ''),
            'sender_phone'          => trim($_POST['sender_phone']         ?? ''),
            'receiver_name'         => trim($_POST['receiver_name']        ?? ''),
            'receiver_line1'        => trim($_POST['receiver_line1']       ?? ''),
            'receiver_line2'        => trim($_POST['receiver_line2']       ?? ''),
            'receiver_postal_code'  => trim($_POST['receiver_postal_code'] ?? ''),
            'receiver_city'         => trim($_POST['receiver_city']        ?? ''),
            'receiver_email'        => trim($_POST['receiver_email']       ?? ''),
            'receiver_phone'        => trim($_POST['receiver_phone']       ?? ''),
            'receiver_point_id'     => trim($_POST['receiver_point_id']    ?? ''),
            'receiver_point_type'   => trim($_POST['receiver_point_type']  ?? ''),
            'pickup_type'           => $_POST['pickup_type']    ?? 'SELF',
            'pickup_date'           => $_POST['pickup_date']    ?? '',
            'pickup_hours_from'     => $_POST['pickup_hours_from'] ?? '',
            'pickup_hours_to'       => $_POST['pickup_hours_to']   ?? '',
            'weight'                => (float)str_replace(',', '.', $_POST['weight'] ?? '0.5'),
            'dimension1'            => (int)($_POST['dimension1'] ?? 25),
            'dimension2'            => (int)($_POST['dimension2'] ?? 20),
            'dimension3'            => (int)($_POST['dimension3'] ?? 5),
            'content'               => trim($_POST['content'] ?? ''),
            'comment'               => trim($_POST['comment'] ?? ''),
            'notes'                 => trim($_POST['notes']   ?? ''),
            'updated_at'            => date('Y-m-d H:i:s'),
        ];

        if (!$d['sender_name'] || !$d['receiver_name']) $errors[] = 'Wymagane: nazwa nadawcy i odbiorcy.';
        if ($d['provider'] === 'apaczka' && !$d['service_id'])            $errors[] = 'Wybierz usługę kurierską Apaczka.';
        if ($d['provider'] === 'furgonetka' && !$d['furgonetka_service']) $errors[] = 'Wybierz usługę kurierską Furgonetka.';

        if (!$errors) {
            if ($sid) {
                $existing = db_one("SELECT status FROM shipments WHERE id=?", [$sid]);
                if ($existing && in_array($existing['status'], ['draft','requested','approved'], true)) {
                    db_update('shipments', $d, $sid);
                    flash_set('success', 'Przesyłka zaktualizowana.');
                }
            } else {
                $d['status']     = 'draft';
                $d['created_by'] = (int)current_user()['id'];
                $d['created_at'] = date('Y-m-d H:i:s');
                $sid = db_insert('shipments', $d);
                flash_set('success', 'Przesyłka utworzona.');
            }
            header('Location: ' . APP_URL . '/admin/shipments.php?id=' . $sid); exit;
        }
    }

    // Złóż zamówienie przez Apaczka API
    if ($action === 'order') {
        $sid = (int)($_POST['shipment_id'] ?? 0);
        try {
            $g   = new Apaczka();
            $res = $g->place_order($sid);

            // Wyślij email z etykietą
            if (!empty($res['waybill_path'])) {
                $ship = db_one("SELECT * FROM shipments WHERE id=?", [$sid]);
                if ($ship) apaczka_send_waybill_email($ship);
            }

            flash_set('success', 'Zamówiono! Numer przesyłki: ' . ($res['waybill_number'] ?: '—'));
        } catch (\Throwable $e) {
            flash_set('danger', 'Błąd API: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/admin/shipments.php?id=' . $sid); exit;
    }

    // Złóż zamówienie przez Furgonetka API
    if ($action === 'order_furgonetka') {
        $sid = (int)($_POST['shipment_id'] ?? 0);
        try {
            $f   = new Furgonetka();
            $res = $f->place_order($sid);
            // Send waybill email (reuse apaczka_send_waybill_email)
            if (!empty($res['waybill_path'])) {
                $ship = db_one("SELECT * FROM shipments WHERE id=?", [$sid]);
                if ($ship) apaczka_send_waybill_email($ship);
            }
            flash_set('success', 'Zamówiono przez Furgonetka! Nr: ' . ($res['waybill_number'] ?: '—'));
        } catch (\Throwable $e) {
            flash_set('danger', 'Błąd Furgonetka API: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/admin/shipments.php?id=' . $sid); exit;
    }

    // Zatwierdź wniosek wolontariusza
    if ($action === 'approve') {
        $sid = (int)($_POST['shipment_id'] ?? 0);
        db()->prepare("UPDATE shipments SET status='approved', approved_by=?, updated_at=datetime('now') WHERE id=? AND status='requested'")
            ->execute([(int)current_user()['id'], $sid]);
        flash_set('success', 'Wniosek zatwierdzony — możesz teraz złożyć zamówienie.');
        header('Location: ' . APP_URL . '/admin/shipments.php?id=' . $sid); exit;
    }

    // Anuluj
    if ($action === 'cancel') {
        $sid  = (int)($_POST['shipment_id'] ?? 0);
        $ship = db_one("SELECT apaczka_order_id, status, provider FROM shipments WHERE id=?", [$sid]);
        if ($ship && $ship['apaczka_order_id'] && $ship['status'] === 'ordered') {
            try {
                if (($ship['provider'] ?? 'apaczka') === 'furgonetka') {
                    (new Furgonetka())->cancel_order($ship['apaczka_order_id']);
                } else {
                    (new Apaczka())->cancel((int)$ship['apaczka_order_id']);
                }
            } catch (\Throwable $e) {}
        }
        db()->prepare("UPDATE shipments SET status='cancelled', updated_at=datetime('now') WHERE id=?")
            ->execute([$sid]);
        flash_set('warning', 'Przesyłka anulowana.');
        header('Location: ' . APP_URL . '/admin/shipments.php'); exit;
    }

    // Odśwież status z API
    if ($action === 'refresh') {
        $sid  = (int)($_POST['shipment_id'] ?? 0);
        $ship = db_one("SELECT provider FROM shipments WHERE id=?", [$sid]);
        try {
            if (($ship['provider'] ?? 'apaczka') === 'furgonetka') {
                $new = (new Furgonetka())->refresh_status($sid);
            } else {
                $new = (new Apaczka())->refresh_status($sid);
            }
            flash_set('success', 'Status odświeżony' . ($new ? ': ' . $new : '.'));
        } catch (\Throwable $e) {
            flash_set('danger', 'Błąd: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/admin/shipments.php?id=' . $sid); exit;
    }
}

// ── Widok szczegółów przesyłki ────────────────────────────────────────────────
$detail_id = (int)($_GET['id'] ?? 0);
$detail    = $detail_id ? db_one("SELECT * FROM shipments WHERE id=?", [$detail_id]) : null;

// ── Lista przesyłek ───────────────────────────────────────────────────────────
$f_status = $_GET['status'] ?? '';
$f_dir    = $_GET['direction'] ?? '';
$f_q      = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

$where = ['1=1']; $params = [];
if ($f_status) { $where[] = 's.status=?';    $params[] = $f_status; }
if ($f_dir)    { $where[] = 's.direction=?'; $params[] = $f_dir; }
if ($f_q)      { $where[] = '(s.receiver_name LIKE ? OR s.sender_name LIKE ? OR s.waybill_number LIKE ?)';
                 $params[] = "%{$f_q}%"; $params[] = "%{$f_q}%"; $params[] = "%{$f_q}%"; }
$where_sql = implode(' AND ', $where);

$total = (int)(db_one("SELECT COUNT(*) AS c FROM shipments s WHERE {$where_sql}", $params)['c'] ?? 0);
$pag   = paginate($total, $per_page, $page, APP_URL . '/admin/shipments.php?' . http_build_query(array_filter([
    'status'    => $f_status,
    'direction' => $f_dir,
    'q'         => $f_q,
])));

$list = db_all(
    "SELECT s.* FROM shipments s WHERE {$where_sql}
     ORDER BY CASE s.status WHEN 'requested' THEN 0 ELSE 1 END, s.created_at DESC
     LIMIT {$per_page} OFFSET {$pag['offset']}",
    $params
);

// Pre-fill nowej przesyłki z parametrów URL (z widoku umowy)
$prefill = [];
if (isset($_GET['contract_id'])) {
    $cid   = (int)$_GET['contract_id'];
    $ctype = preg_replace('/[^a-z]/', '', $_GET['contract_type'] ?? 'wolontariat');
    $defs  = apaczka_sender_defaults();
    $dir   = $_GET['direction'] ?? 'out';

    // Pobierz dane odbiorcy z dowolnego typu umowy
    $recip = [];
    try {
        require_once dirname(__DIR__) . '/includes/address.php';
        switch ($ctype) {
            case 'wolontariat':
                $r = db_one("SELECT * FROM umowy_wolontariat WHERE id=?", [$cid]);
                if ($r) {
                    $a = address_from_row($r);
                    // Prefer new structured fields; fall back to legacy adres_linia1 then adres
                    $line1   = trim($a['street'] . ($a['house'] ? ' ' . $a['house'] : '') . ($a['flat'] ? '/' . $a['flat'] : ''))
                             ?: ($r['adres_linia1'] ?: $r['adres'] ?? '');
                    $postal  = $a['postal'] ?: ($r['adres_kod_pocztowy'] ?? '');
                    $city    = $a['city']   ?: ($r['adres_miasto']       ?? '');
                    $recip = [
                        'name'        => $r['imie_nazwisko'] ?? '',
                        'line1'       => $line1,
                        'postal_code' => $postal,
                        'city'        => $city,
                        'email'       => $r['email'] ?? $r['m365_login'] ?? '',
                        'phone'       => $r['telefon'] ?? '',
                    ];
                }
                break;
            case 'zlecenie':
            case 'dzielo':
                $r = db_one("SELECT * FROM umowy_{$ctype} WHERE id=?", [$cid]);
                if ($r) {
                    $a = address_from_row($r);
                    $recip = [
                        'name'        => $r['imie_nazwisko'] ?? '',
                        'line1'       => trim($a['street'] . ($a['house'] ? ' ' . $a['house'] : '') . ($a['flat'] ? '/' . $a['flat'] : ''))
                                       ?: ($r['adres'] ?? ''),
                        'postal_code' => $a['postal'],
                        'city'        => $a['city'],
                        'email'       => $r['m365_login'] ?? $r['email'] ?? '',
                        'phone'       => $r['telefon'] ?? '',
                    ];
                }
                break;
            case 'praca':
                $r = db_one("SELECT * FROM umowy_praca WHERE id=?", [$cid]);
                if ($r) {
                    $a = address_from_row($r);
                    $recip = [
                        'name'        => $r['imie_nazwisko'] ?? '',
                        'line1'       => trim($a['street'] . ($a['house'] ? ' ' . $a['house'] : '') . ($a['flat'] ? '/' . $a['flat'] : ''))
                                       ?: ($r['adres'] ?? ''),
                        'postal_code' => $a['postal'],
                        'city'        => $a['city'],
                        'email'       => $r['email_login'] ?? $r['email'] ?? '',
                        'phone'       => $r['telefon'] ?? '',
                    ];
                }
                break;
        }
    } catch (\Throwable $e) {}

    if ($recip) {
        if ($dir === 'out') {
            $prefill = array_merge($defs, [
                'contract_id'          => $cid,
                'contract_type'        => $ctype,
                'direction'            => 'out',
                'receiver_name'        => $recip['name'],
                'receiver_line1'       => $recip['line1'],
                'receiver_postal_code' => $recip['postal_code'],
                'receiver_city'        => $recip['city'],
                'receiver_email'       => $recip['email'],
                'receiver_phone'       => $recip['phone'],
            ]);
        } else {
            $prefill = [
                'contract_id'          => $cid,
                'contract_type'        => $ctype,
                'direction'            => 'return',
                'sender_name'          => $recip['name'],
                'sender_line1'         => $recip['line1'],
                'sender_postal_code'   => $recip['postal_code'],
                'sender_city'          => $recip['city'],
                'sender_email'         => $recip['email'],
                'sender_phone'         => $recip['phone'],
                'receiver_name'        => $defs['name'],
                'receiver_line1'       => $defs['line1'],
                'receiver_postal_code' => $defs['postal_code'],
                'receiver_city'        => $defs['city'],
                'receiver_email'       => $defs['email'],
                'receiver_phone'       => $defs['phone'],
            ];
        }
    }
}

$show_form = isset($_GET['new']) || $prefill || $errors;
$edit_ship = ($detail && in_array($detail['status'], ['draft','requested','approved'], true)) ? $detail : null;

include dirname(__DIR__) . '/includes/header.php';
echo flash_html();
?>
<style>
.ship-card { background:#fff; border:1px solid #e2e8f0; border-radius:.6rem; margin-bottom:1rem; overflow:hidden; }
.ship-card-head { padding:.6rem 1rem; background:#f8fafc; border-bottom:1px solid #e2e8f0;
  font-size:.8rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em;
  color:#475569; display:flex; align-items:center; gap:.5rem; }
.ship-card-body { padding:1rem; }
.dir-badge-out    { background:#dbeafe; color:#1d4ed8; border-radius:.3rem; padding:.1rem .5rem; font-size:.75rem; font-weight:700; }
.dir-badge-return { background:#fce7f3; color:#9d174d; border-radius:.3rem; padding:.1rem .5rem; font-size:.75rem; font-weight:700; }
.addr-block { background:#f8fafc; border:1px solid #e2e8f0; border-radius:.4rem; padding:.65rem .85rem; font-size:.82rem; }
.addr-lbl   { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; margin-bottom:.2rem; }
.point-result { padding:.5rem .75rem; border-radius:.35rem; cursor:pointer; border:1px solid transparent; font-size:.82rem; }
.point-result:hover { background:#eff6ff; border-color:#bfdbfe; }
.point-selected { background:#eff6ff; border-color:#2563eb!important; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-box-seam text-primary me-2"></i>Przesyłki</h4>
  <div class="d-flex gap-2">
    <?php if (is_admin()): ?>
    <a href="<?= APP_URL ?>/admin/apaczka_settings.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-gear me-1"></i>Apaczka API
    </a>
    <a href="<?= APP_URL ?>/admin/furgonetka_settings.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-truck me-1"></i>Furgonetka API
    </a>
    <?php endif; ?>
    <a href="?new=1" class="btn btn-sm btn-primary">
      <i class="bi bi-plus-lg me-1"></i>Nowa przesyłka
    </a>
  </div>
</div>

<!-- ── Widok szczegółów ──────────────────────────────────────────────────── -->
<?php if ($detail): ?>
<?php
$wbn   = $detail['waybill_number'] ?? '';
$wpath = $detail['waybill_path']   ?? '';
$wurl  = $wpath ? APP_URL . '/uploads/' . $wpath : '';
$prov  = $detail['provider'] ?? 'apaczka';
?>
<div class="ship-card mb-3">
  <div class="ship-card-head">
    <i class="bi bi-box-seam"></i>
    Przesyłka #<?= $detail['id'] ?>
    <span class="<?= $detail['direction'] === 'out' ? 'dir-badge-out' : 'dir-badge-return' ?> ms-1">
      <?= SHIPMENT_DIRECTION[$detail['direction']] ?? $detail['direction'] ?>
    </span>
    <?= shipment_badge($detail['status']) ?>
    <span class="badge bg-<?= $prov === 'furgonetka' ? 'success' : 'primary' ?> ms-1">
      <i class="bi bi-<?= $prov === 'furgonetka' ? 'truck' : 'box-seam' ?> me-1"></i><?= $prov === 'furgonetka' ? 'Furgonetka' : 'Apaczka' ?>
    </span>
    <div class="ms-auto d-flex gap-1 flex-wrap">
      <?php if (in_array($detail['status'], ['draft','requested','approved'])): ?>
      <a href="?edit=<?= $detail['id'] ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-pencil"></i> Edytuj
      </a>
      <?php endif; ?>
      <?php if ($detail['status'] === 'requested'): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="approve">
        <input type="hidden" name="shipment_id" value="<?= $detail['id'] ?>">
        <button class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Zatwierdź wniosek</button>
      </form>
      <?php endif; ?>
      <?php if (in_array($detail['status'], ['approved','draft'])): ?>
      <?php
        // Build CPC metadata for shipment order confirmation
        $_ship_addr = trim(
            ($detail['receiver_line1'] ?? '') . ', ' .
            ($detail['receiver_postal_code'] ?? '') . ' ' .
            ($detail['receiver_city'] ?? '')
        );
        $_ship_meta_furg = htmlspecialchars(json_encode(['rows' => [
            ['Typ operacji',  'Zlecenie wysyłki kurierskiej (Furgonetka)'],
            ['Odbiorca',      $detail['receiver_name'] ?? ''],
            ['Adres docelowy',$_ship_addr],
            ['Nr przesyłki',  '#' . $detail['id']],
        ]]), ENT_QUOTES, 'UTF-8');
        $_ship_meta_apaczka = htmlspecialchars(json_encode(['rows' => [
            ['Typ operacji',  'Zlecenie wysyłki kurierskiej (Apaczka)'],
            ['Odbiorca',      $detail['receiver_name'] ?? ''],
            ['Adres docelowy',$_ship_addr],
            ['Nr przesyłki',  '#' . $detail['id']],
        ]]), ENT_QUOTES, 'UTF-8');
      ?>
      <?php if ($prov === 'furgonetka' && furgonetka_enabled()): ?>
      <form method="post" class="d-inline"
            data-cpc="1" data-cpc-meta="<?= $_ship_meta_furg ?>">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="order_furgonetka">
        <input type="hidden" name="shipment_id" value="<?= $detail['id'] ?>">
        <button class="btn btn-sm btn-success">
          <i class="bi bi-truck me-1"></i>Zamów przez Furgonetka
        </button>
      </form>
      <?php else: ?>
      <form method="post" class="d-inline"
            data-cpc="1" data-cpc-meta="<?= $_ship_meta_apaczka ?>">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="order">
        <input type="hidden" name="shipment_id" value="<?= $detail['id'] ?>">
        <button class="btn btn-sm btn-primary">
          <i class="bi bi-send-check me-1"></i>Zamów przez Apaczka
        </button>
      </form>
      <?php endif; ?>
      <?php endif; ?>
      <?php if ($detail['status'] === 'ordered'): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="refresh">
        <input type="hidden" name="shipment_id" value="<?= $detail['id'] ?>">
        <button class="btn btn-sm btn-outline-info"><i class="bi bi-arrow-clockwise me-1"></i>Odśwież status</button>
      </form>
      <?php endif; ?>
      <?php if ($wurl): ?>
      <a href="<?= h($wurl) ?>" target="_blank" class="btn btn-sm btn-outline-dark">
        <i class="bi bi-printer me-1"></i>Etykieta PDF
      </a>
      <?php endif; ?>
      <?php if (!in_array($detail['status'], ['cancelled','delivered'])): ?>
      <form method="post" class="d-inline" onsubmit="return confirm('Anulować przesyłkę?')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="cancel">
        <input type="hidden" name="shipment_id" value="<?= $detail['id'] ?>">
        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1"></i>Anuluj</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="ship-card-body">
    <div class="row g-3">
      <!-- Dane Apaczka/Furgonetka -->
      <?php if ($wbn || $detail['tracking_url']): ?>
      <div class="col-12">
        <div class="alert alert-success py-2 mb-0 small d-flex gap-3 flex-wrap align-items-center">
          <?php if ($wbn): ?>
          <span><i class="bi bi-upc-scan me-1"></i><strong>Nr przesyłki:</strong>
            <span class="font-monospace"><?= h($wbn) ?></span></span>
          <?php endif; ?>
          <?php if ($detail['tracking_url']): ?>
          <a href="<?= h($detail['tracking_url']) ?>" target="_blank" class="btn btn-sm btn-outline-success py-0">
            <i class="bi bi-geo-alt me-1"></i>Śledź przesyłkę
          </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Adresy -->
      <div class="col-md-6">
        <div class="addr-lbl">Nadawca</div>
        <div class="addr-block">
          <div class="fw-semibold"><?= h($detail['sender_name']) ?></div>
          <div><?= h($detail['sender_line1']) ?> <?= h($detail['sender_line2']) ?></div>
          <div><?= h($detail['sender_postal_code']) ?> <?= h($detail['sender_city']) ?></div>
          <div class="text-muted mt-1"><?= h($detail['sender_email']) ?> · <?= h($detail['sender_phone']) ?></div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="addr-lbl">Odbiorca</div>
        <div class="addr-block">
          <div class="fw-semibold"><?= h($detail['receiver_name']) ?></div>
          <div><?= h($detail['receiver_line1']) ?> <?= h($detail['receiver_line2']) ?></div>
          <div><?= h($detail['receiver_postal_code']) ?> <?= h($detail['receiver_city']) ?></div>
          <?php if ($detail['receiver_point_id']): ?>
          <div class="badge bg-info mt-1">
            <i class="bi bi-geo-alt me-1"></i>Punkt: <?= h($detail['receiver_point_id']) ?> (<?= h($detail['receiver_point_type']) ?>)
          </div>
          <?php endif; ?>
          <div class="text-muted mt-1"><?= h($detail['receiver_email']) ?> · <?= h($detail['receiver_phone']) ?></div>
        </div>
      </div>

      <!-- Parametry -->
      <div class="col-md-4">
        <div class="addr-lbl">Usługa</div>
        <div>
          <?php if ($prov === 'furgonetka'): ?>
          <?= h($detail['furgonetka_service'] ?: '—') ?>
          <?php else: ?>
          <?= h($detail['service_id'] ?: '—') ?>
          <?php
          $svc = array_filter($services_cache, fn($s) => (string)$s['service_id'] === (string)$detail['service_id']);
          $svc = reset($svc);
          if ($svc) echo ' — ' . h($svc['name'] ?? '');
          ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-md-4">
        <div class="addr-lbl">Odbiór / nadanie</div>
        <div><?= h($detail['pickup_type']) ?>
          <?php if ($detail['pickup_date']): ?>
          · <?= h($detail['pickup_date']) ?> <?= h($detail['pickup_hours_from']) ?>–<?= h($detail['pickup_hours_to']) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-md-4">
        <div class="addr-lbl">Wymiary / waga</div>
        <div><?= h($detail['dimension1']) ?> × <?= h($detail['dimension2']) ?> × <?= h($detail['dimension3']) ?> cm
          · <?= h($detail['weight']) ?> kg</div>
      </div>
      <?php if ($detail['content'] || $detail['comment']): ?>
      <div class="col-12">
        <div class="addr-lbl">Zawartość / uwagi</div>
        <div><?= h($detail['content']) ?> <?= $detail['comment'] ? '| ' . h($detail['comment']) : '' ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<a href="<?= APP_URL ?>/admin/shipments.php" class="btn btn-outline-secondary btn-sm mb-3">
  <i class="bi bi-arrow-left me-1"></i>Lista przesyłek
</a>
<?php endif; ?>

<!-- ── Formularz nowej / edycji przesyłki ───────────────────────────────── -->
<?php
$fe = $edit_ship ?? ($errors ? array_merge($prefill, $_POST) : $prefill);
$show_form = $show_form || isset($_GET['edit']);
if (isset($_GET['edit']) && !$edit_ship) {
    $edit_ship = db_one("SELECT * FROM shipments WHERE id=?", [(int)$_GET['edit']]);
    $fe = $edit_ship ?: $fe;
    $show_form = (bool)$edit_ship;
}
?>
<?php if ($show_form): ?>
<div class="ship-card">
  <div class="ship-card-head">
    <i class="bi bi-pencil-square"></i>
    <?= ($fe['id'] ?? 0) ? 'Edytuj przesyłkę #' . $fe['id'] : 'Nowa przesyłka' ?>
  </div>
  <div class="ship-card-body">
  <?php if ($errors): ?>
  <div class="alert alert-danger py-2 small"><ul class="mb-0 ps-3">
    <?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?>
  </ul></div>
  <?php endif; ?>
  <form method="post" id="shipForm">
    <input type="hidden" name="_csrf"        value="<?= csrf_token() ?>">
    <input type="hidden" name="_action"      value="<?= ($fe['id'] ?? 0) ? 'update' : 'create' ?>">
    <input type="hidden" name="shipment_id"  value="<?= (int)($fe['id'] ?? 0) ?>">
    <input type="hidden" name="contract_id"  value="<?= (int)($fe['contract_id'] ?? 0) ?>">
    <input type="hidden" name="contract_type" value="<?= h($fe['contract_type'] ?? 'wolontariat') ?>">

    <!-- Provider tabs -->
    <?php $cur_provider = $fe['provider'] ?? 'apaczka'; ?>
    <ul class="nav nav-tabs mb-3" id="providerTabs">
      <li class="nav-item">
        <button type="button" class="nav-link <?= $cur_provider === 'apaczka' ? 'active' : '' ?>"
                onclick="setProvider('apaczka')">
          <i class="bi bi-box-seam me-1"></i>Apaczka
        </button>
      </li>
      <li class="nav-item">
        <button type="button" class="nav-link <?= $cur_provider === 'furgonetka' ? 'active' : '' ?>"
                onclick="setProvider('furgonetka')">
          <i class="bi bi-truck me-1"></i>Furgonetka
        </button>
      </li>
    </ul>
    <input type="hidden" name="provider" id="providerInput" value="<?= h($cur_provider) ?>">

    <div class="row g-3">
      <!-- Kierunek i cel -->
      <div class="col-md-4">
        <label class="form-label fw-semibold small">Kierunek</label>
        <select name="direction" class="form-select" id="directionSel">
          <?php foreach (SHIPMENT_DIRECTION as $dv => $dl): ?>
          <option value="<?= $dv ?>" <?= ($fe['direction'] ?? 'out') === $dv ? 'selected' : '' ?>><?= h($dl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small">Cel przesyłki</label>
        <select name="purpose" class="form-select">
          <?php foreach (SHIPMENT_PURPOSE as $pv => $pl): ?>
          <option value="<?= $pv ?>" <?= ($fe['purpose'] ?? 'documents') === $pv ? 'selected' : '' ?>><?= h($pl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Usługa APACZKA -->
      <div class="col-md-4" id="svcApaczka" <?= $cur_provider !== 'apaczka' ? 'style="display:none"' : '' ?>>
        <label class="form-label fw-semibold small">Usługa Apaczka <span class="text-danger">*</span></label>
        <select name="service_id" class="form-select" id="serviceApaczkaSelect">
          <option value="">— wybierz —</option>
          <?php foreach ($services_cache as $svc):
            $sel = (string)($fe['service_id'] ?? '') === (string)($svc['service_id'] ?? '') ? 'selected' : '';
          ?>
          <option value="<?= h($svc['service_id']) ?>" <?= $sel ?>><?= h($svc['name']) ?> — <?= h($svc['supplier']) ?></option>
          <?php endforeach; ?>
          <?php if (!$services_cache): ?>
          <option value="<?= h($fe['service_id'] ?? '') ?>" selected><?= h($fe['service_id'] ?? 'Wpisz ID ręcznie') ?></option>
          <?php endif; ?>
        </select>
        <?php if (!$services_cache): ?>
        <div class="form-text">Brak cache — <a href="<?= APP_URL ?>/admin/apaczka_settings.php">pobierz z API</a>.</div>
        <?php endif; ?>
      </div>

      <!-- Usługa FURGONETKA -->
      <div class="col-md-4" id="svcFurgonetka" <?= $cur_provider !== 'furgonetka' ? 'style="display:none"' : '' ?>>
        <label class="form-label fw-semibold small">Usługa Furgonetka <span class="text-danger">*</span></label>
        <?php if ($furg_services_cache): ?>
        <select name="furgonetka_service" class="form-select">
          <option value="">— wybierz —</option>
          <?php foreach ($furg_services_cache as $fs):
            $fcode = $fs['code'] ?? $fs['service_code'] ?? $fs['id'] ?? '';
            $fname = $fs['name'] ?? $fcode;
            $fcar  = $fs['carrier'] ?? $fs['courier'] ?? $fs['supplier'] ?? '';
            $fsel  = ($fe['furgonetka_service'] ?? '') === $fcode ? 'selected' : '';
          ?>
          <option value="<?= h($fcode) ?>" <?= $fsel ?>><?= h($fname) ?><?= $fcar ? ' — ' . h($fcar) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input name="furgonetka_service" class="form-control" placeholder="np. inpost_courier"
               value="<?= h($fe['furgonetka_service'] ?? '') ?>">
        <div class="form-text">Brak cache — <a href="<?= APP_URL ?>/admin/furgonetka_settings.php">pobierz z API</a>.</div>
        <?php endif; ?>
      </div>

      <!-- Nadawca -->
      <div class="col-12"><h6 class="mb-0 fw-semibold text-muted small text-uppercase letter-spacing-1">
        <i class="bi bi-arrow-up-circle me-1"></i>Nadawca</h6></div>
      <div class="col-md-4"><label class="form-label fw-semibold small">Nazwa <span class="text-danger">*</span></label>
        <input name="sender_name" class="form-control" required value="<?= h($fe['sender_name'] ?? '') ?>"></div>
      <div class="col-md-5"><label class="form-label fw-semibold small">Ulica i numer</label>
        <input name="sender_line1" class="form-control" value="<?= h($fe['sender_line1'] ?? '') ?>"></div>
      <div class="col-md-3"><label class="form-label fw-semibold small">Kod pocztowy</label>
        <input name="sender_postal_code" class="form-control" value="<?= h($fe['sender_postal_code'] ?? '') ?>" placeholder="00-001"></div>
      <div class="col-md-4"><label class="form-label fw-semibold small">Miasto</label>
        <input name="sender_city" class="form-control" value="<?= h($fe['sender_city'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label fw-semibold small">E-mail</label>
        <input name="sender_email" type="email" class="form-control" value="<?= h($fe['sender_email'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label fw-semibold small">Telefon</label>
        <input name="sender_phone" class="form-control" value="<?= h($fe['sender_phone'] ?? '') ?>" placeholder="48123456789"></div>

      <!-- Odbiorca -->
      <div class="col-12"><h6 class="mb-0 fw-semibold text-muted small text-uppercase">
        <i class="bi bi-arrow-down-circle me-1"></i>Odbiorca</h6></div>
      <div class="col-md-4"><label class="form-label fw-semibold small">Nazwa <span class="text-danger">*</span></label>
        <input name="receiver_name" class="form-control" required value="<?= h($fe['receiver_name'] ?? '') ?>"></div>
      <div class="col-md-5"><label class="form-label fw-semibold small">Ulica i numer</label>
        <input name="receiver_line1" class="form-control" value="<?= h($fe['receiver_line1'] ?? '') ?>"></div>
      <div class="col-md-3"><label class="form-label fw-semibold small">Kod pocztowy</label>
        <input name="receiver_postal_code" class="form-control" value="<?= h($fe['receiver_postal_code'] ?? '') ?>" placeholder="00-001"></div>
      <div class="col-md-4"><label class="form-label fw-semibold small">Miasto</label>
        <input name="receiver_city" class="form-control" value="<?= h($fe['receiver_city'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label fw-semibold small">E-mail (etykieta)</label>
        <input name="receiver_email" type="email" class="form-control" value="<?= h($fe['receiver_email'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label fw-semibold small">Telefon</label>
        <input name="receiver_phone" class="form-control" value="<?= h($fe['receiver_phone'] ?? '') ?>" placeholder="48123456789"></div>

      <!-- Punkt nadania — Apaczka -->
      <div class="col-12" id="pointBoxApaczka" <?= $cur_provider !== 'apaczka' ? 'style="display:none"' : '' ?>>
        <label class="form-label fw-semibold small">Punkt nadania / paczkomat Apaczka (opcjonalnie)</label>
        <div class="input-group mb-1">
          <input type="text" id="pointSearch" class="form-control" placeholder="Wpisz kod pocztowy lub miasto…">
          <select id="pointType" class="form-select" style="max-width:140px">
            <option value="INPOST">InPost</option>
            <option value="UPS">UPS</option>
            <option value="POCZTA">Poczta</option>
          </select>
          <button type="button" class="btn btn-outline-secondary" onclick="searchPoints()">
            <i class="bi bi-search"></i>
          </button>
        </div>
        <div id="pointResults" class="border rounded p-1 mb-2 d-none" style="max-height:200px;overflow-y:auto"></div>
        <div class="input-group input-group-sm">
          <span class="input-group-text text-muted">Wybrany punkt</span>
          <input name="receiver_point_id"   id="pointId"    class="form-control font-monospace"
                 value="<?= h($fe['receiver_point_id'] ?? '') ?>" placeholder="ID punktu (np. WAW01N)">
          <input name="receiver_point_type" id="pointType2" class="form-control"
                 value="<?= h($fe['receiver_point_type'] ?? '') ?>" placeholder="typ (np. INPOST)">
        </div>
      </div>

      <!-- Punkt nadania — Furgonetka -->
      <div class="col-12" id="pointBoxFurgonetka" <?= $cur_provider !== 'furgonetka' ? 'style="display:none"' : '' ?>>
        <label class="form-label fw-semibold small">Punkt nadania Furgonetka (opcjonalnie)</label>
        <div class="input-group mb-1">
          <input type="text" id="furgPointSearch" class="form-control" placeholder="Wpisz kod pocztowy lub miasto…">
          <select id="furgPointService" class="form-select" style="max-width:160px">
            <option value="inpost_locker">InPost</option>
            <option value="ups">UPS</option>
            <option value="poczta">Poczta</option>
            <option value="dhl">DHL</option>
          </select>
          <button type="button" class="btn btn-outline-secondary" onclick="searchPointsFurg()">
            <i class="bi bi-search"></i>
          </button>
        </div>
        <div id="furgPointResults" class="border rounded p-1 mb-2 d-none" style="max-height:200px;overflow-y:auto"></div>
        <div class="form-text">Wybrany punkt zostanie zapisany w polu "Punkt nadania" powyżej.</div>
      </div>

      <!-- Typ odbioru -->
      <div class="col-md-4">
        <label class="form-label fw-semibold small">Typ odbioru</label>
        <select name="pickup_type" class="form-select" id="pickupType">
          <option value="SELF"    <?= ($fe['pickup_type'] ?? 'SELF') === 'SELF'    ? 'selected' : '' ?>>SELF — sam zaniosę / nadanie w punkcie</option>
          <option value="COURIER" <?= ($fe['pickup_type'] ?? '') === 'COURIER' ? 'selected' : '' ?>>COURIER — kurier przyjedzie</option>
        </select>
      </div>
      <div id="courierDateFields" class="col-md-8 row g-2" style="display:none">
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Data odbioru</label>
          <input name="pickup_date" type="date" class="form-control" value="<?= h($fe['pickup_date'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Godz. od</label>
          <input name="pickup_hours_from" class="form-control" placeholder="08:00" value="<?= h($fe['pickup_hours_from'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Godz. do</label>
          <input name="pickup_hours_to" class="form-control" placeholder="17:00" value="<?= h($fe['pickup_hours_to'] ?? '') ?>">
        </div>
      </div>

      <!-- Paczka -->
      <div class="col-12"><h6 class="mb-0 fw-semibold text-muted small text-uppercase">
        <i class="bi bi-box me-1"></i>Parametry paczki</h6></div>
      <div class="col-md-2"><label class="form-label fw-semibold small">Waga (kg)</label>
        <input name="weight" type="number" step="0.1" min="0.1" class="form-control" value="<?= h($fe['weight'] ?? '0.5') ?>"></div>
      <div class="col-md-2"><label class="form-label fw-semibold small">Dł. (cm)</label>
        <input name="dimension1" type="number" min="1" class="form-control" value="<?= h($fe['dimension1'] ?? '25') ?>"></div>
      <div class="col-md-2"><label class="form-label fw-semibold small">Szer. (cm)</label>
        <input name="dimension2" type="number" min="1" class="form-control" value="<?= h($fe['dimension2'] ?? '20') ?>"></div>
      <div class="col-md-2"><label class="form-label fw-semibold small">Wys. (cm)</label>
        <input name="dimension3" type="number" min="1" class="form-control" value="<?= h($fe['dimension3'] ?? '5') ?>"></div>
      <div class="col-md-4"><label class="form-label fw-semibold small">Zawartość (opis na etykiecie)</label>
        <input name="content" class="form-control" value="<?= h($fe['content'] ?? '') ?>" placeholder="Dokumenty"></div>

      <div class="col-md-8"><label class="form-label fw-semibold small">Komentarz</label>
        <input name="comment" class="form-control" value="<?= h($fe['comment'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label fw-semibold small">Notatki wewnętrzne</label>
        <input name="notes" class="form-control" value="<?= h($fe['notes'] ?? '') ?>"></div>
    </div>

    <div class="d-flex gap-2 mt-3 flex-wrap">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-floppy me-1"></i>Zapisz szkic
      </button>
      <a href="<?= APP_URL ?>/admin/shipments.php" class="btn btn-outline-secondary">Anuluj</a>
    </div>
  </form>
  </div>
</div>

<script>
// Kurier — pokaż datę tylko gdy COURIER
document.getElementById('pickupType').addEventListener('change', function() {
  document.getElementById('courierDateFields').style.display = this.value === 'COURIER' ? '' : 'none';
});
document.getElementById('courierDateFields').style.display =
  document.getElementById('pickupType').value === 'COURIER' ? '' : 'none';

// Przełącznik dostawcy
function setProvider(p) {
  document.getElementById('providerInput').value = p;
  document.querySelectorAll('#providerTabs .nav-link').forEach(function(b) {
    b.classList.toggle('active', b.getAttribute('onclick').includes("'" + p + "'"));
  });
  document.getElementById('svcApaczka').style.display        = p === 'apaczka'    ? '' : 'none';
  document.getElementById('svcFurgonetka').style.display      = p === 'furgonetka' ? '' : 'none';
  document.getElementById('pointBoxApaczka').style.display    = p === 'apaczka'    ? '' : 'none';
  document.getElementById('pointBoxFurgonetka').style.display = p === 'furgonetka' ? '' : 'none';
}

// Wyszukiwarka punktów — Apaczka
function searchPoints() {
  var q    = document.getElementById('pointSearch').value.trim();
  var type = document.getElementById('pointType').value;
  var box  = document.getElementById('pointResults');
  if (!q) return;
  box.innerHTML = '<div class="text-muted small p-2"><span class="spinner-border spinner-border-sm me-1"></span>Szukam…</div>';
  box.classList.remove('d-none');
  fetch('<?= APP_URL ?>/admin/api/apaczka_points.php?type=' + type + '&q=' + encodeURIComponent(q))
    .then(r => r.json()).then(function(data) {
      if (data.error) { box.innerHTML = '<div class="text-danger small p-2">' + data.error + '</div>'; return; }
      var pts = data.results || [];
      if (!pts.length) { box.innerHTML = '<div class="text-muted small p-2">Brak punktów dla tego zapytania.</div>'; return; }
      box.innerHTML = pts.map(function(p) {
        return '<div class="point-result" onclick="selectPoint(' + JSON.stringify(p) + ', \'' + type + '\')">'
          + '<strong>' + p.name + '</strong> <span class="text-muted">' + p.line1 + ', ' + p.postal_code + ' ' + p.city + '</span>'
          + (p.open_hours ? '<br><small class="text-muted">' + p.open_hours + '</small>' : '')
          + '</div>';
      }).join('');
    }).catch(function(e) { box.innerHTML = '<div class="text-danger small p-2">Błąd: ' + e.message + '</div>'; });
}

function selectPoint(p, type) {
  document.getElementById('pointId').value    = p.id;
  document.getElementById('pointType2').value = type;
  document.getElementById('pointResults').querySelectorAll('.point-result')
    .forEach(el => el.classList.remove('point-selected'));
  event.currentTarget.classList.add('point-selected');
}

document.getElementById('pointSearch').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') { e.preventDefault(); searchPoints(); }
});

// Wyszukiwarka punktów — Furgonetka
function searchPointsFurg() {
  var q    = document.getElementById('furgPointSearch').value.trim();
  var svc  = document.getElementById('furgPointService').value;
  var box  = document.getElementById('furgPointResults');
  if (!q) return;
  box.innerHTML = '<div class="text-muted small p-2"><span class="spinner-border spinner-border-sm me-1"></span>Szukam…</div>';
  box.classList.remove('d-none');
  fetch('<?= APP_URL ?>/admin/api/furgonetka_points.php?service=' + encodeURIComponent(svc) + '&q=' + encodeURIComponent(q))
    .then(r => r.json()).then(function(data) {
      if (data.error) { box.innerHTML = '<div class="text-danger small p-2">' + data.error + '</div>'; return; }
      var pts = data.results || [];
      if (!pts.length) { box.innerHTML = '<div class="text-muted small p-2">Brak wyników.</div>'; return; }
      box.innerHTML = pts.map(function(p) {
        return '<div class="point-result" onclick="selectPointFurg(' + JSON.stringify(p) + ')">'
          + '<strong>' + p.name + '</strong> <span class="text-muted">' + p.line1 + ', ' + p.postal_code + ' ' + p.city + '</span>'
          + (p.open_hours ? '<br><small class="text-muted">' + p.open_hours + '</small>' : '')
          + '</div>';
      }).join('');
    }).catch(function(e) { box.innerHTML = '<div class="text-danger small p-2">Błąd: ' + e.message + '</div>'; });
}

function selectPointFurg(p) {
  document.getElementById('pointId').value    = p.id;
  document.getElementById('pointType2').value = 'FURG_' + document.getElementById('furgPointService').value.toUpperCase();
  document.getElementById('furgPointResults').querySelectorAll('.point-result')
    .forEach(function(el) { el.classList.remove('point-selected'); });
  event.currentTarget.classList.add('point-selected');
}

document.getElementById('furgPointSearch').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') { e.preventDefault(); searchPointsFurg(); }
});
</script>
<?php endif; /* show_form */ ?>

<!-- ── Lista przesyłek ────────────────────────────────────────────────── -->
<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-sm-4 col-md-3">
    <input name="q" class="form-control form-control-sm" placeholder="Szukaj po nazwie, nr WB…" value="<?= h($f_q) ?>">
  </div>
  <div class="col-auto">
    <select name="status" class="form-select form-select-sm">
      <option value="">— wszystkie statusy —</option>
      <?php foreach (SHIPMENT_STATUS as $sv => $sl): ?>
      <option value="<?= $sv ?>" <?= $f_status === $sv ? 'selected' : '' ?>><?= h($sl['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <select name="direction" class="form-select form-select-sm">
      <option value="">— kierunek —</option>
      <?php foreach (SHIPMENT_DIRECTION as $dv => $dl): ?>
      <option value="<?= $dv ?>" <?= $f_dir === $dv ? 'selected' : '' ?>><?= h($dl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <button type="submit" class="btn btn-sm btn-primary">Filtruj</button>
    <a href="<?= APP_URL ?>/admin/shipments.php" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
  </div>
</form>

<?php if ($list): ?>
<div class="card border-0 shadow-sm">
<table class="table table-hover table-sm align-middle mb-0">
<thead class="table-light">
  <tr>
    <th>#</th><th>Kierunek</th><th>Odbiorca / Nadawca</th>
    <th>Nr WB</th><th>Dostawca</th><th>Status</th><th>Cel</th><th>Data</th><th></th>
  </tr>
</thead>
<tbody>
<?php foreach ($list as $s):
  $is_req  = $s['status'] === 'requested';
  $s_prov  = $s['provider'] ?? 'apaczka';
?>
<tr class="<?= $is_req ? 'table-warning' : '' ?>">
  <td class="font-monospace text-muted"><?= $s['id'] ?></td>
  <td>
    <span class="<?= $s['direction'] === 'out' ? 'dir-badge-out' : 'dir-badge-return' ?>">
      <?= $s['direction'] === 'out' ? '→ Wol.' : '← FEER' ?>
    </span>
  </td>
  <td>
    <div class="fw-semibold small"><?= h($s['direction'] === 'out' ? $s['receiver_name'] : $s['sender_name']) ?></div>
    <div class="text-muted" style="font-size:.72rem"><?= h($s['direction'] === 'out' ? ($s['receiver_city'] ?? '') : ($s['sender_city'] ?? '')) ?></div>
  </td>
  <td class="font-monospace small"><?= $s['waybill_number'] ? h($s['waybill_number']) : '—' ?></td>
  <td>
    <span class="badge bg-<?= $s_prov === 'furgonetka' ? 'success' : 'primary' ?>" style="font-size:.65rem">
      <i class="bi bi-<?= $s_prov === 'furgonetka' ? 'truck' : 'box-seam' ?> me-1"></i><?= $s_prov === 'furgonetka' ? 'Furg.' : 'Apaczka' ?>
    </span>
  </td>
  <td><?= shipment_badge($s['status']) ?></td>
  <td class="small text-muted"><?= h(SHIPMENT_PURPOSE[$s['purpose']] ?? $s['purpose']) ?></td>
  <td class="small text-muted"><?= date_pl($s['created_at']) ?></td>
  <td>
    <a href="?id=<?= $s['id'] ?>" class="btn btn-xs btn-outline-secondary" style="font-size:.72rem;padding:.2rem .5rem">
      <i class="bi bi-eye"></i>
    </a>
    <?php if ($s['waybill_path']): ?>
    <a href="<?= APP_URL ?>/uploads/<?= h($s['waybill_path']) ?>" target="_blank"
       class="btn btn-xs btn-outline-dark ms-1" style="font-size:.72rem;padding:.2rem .5rem" title="Etykieta PDF">
      <i class="bi bi-printer"></i>
    </a>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php if ($pag['pages'] > 1): ?>
<div class="d-flex justify-content-center mt-3"><?= pagination_html($pag) ?></div>
<?php endif; ?>
<div class="text-muted small mt-2">Wyświetlono <?= count($list) ?> z <?= $total ?> przesyłek</div>
<?php else: ?>
<div class="text-center py-5 text-muted">
  <i class="bi bi-box-seam" style="font-size:2.5rem;opacity:.25"></i>
  <div class="mt-2">Brak przesyłek. <a href="?new=1">Utwórz pierwszą</a>.</div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
