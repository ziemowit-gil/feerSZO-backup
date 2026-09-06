<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/pfron.php';

k30_require_access();
karty30_migrate();
require_once dirname(dirname(__DIR__)) . '/modules/srs/logic/srs.php';
resources_migrate();

if (!can_write('karty30') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do zapisu.');
    header('Location: index.php');
    exit;
}

$PAGE_TITLE = 'Nowy termin — Dydaktyka 3';
$errors = [];

$prefill_client_id = (int)($_GET['client_id'] ?? 0);

$clients = db_all("SELECT id, name FROM k30_clients ORDER BY name");
$users   = k30_get_consultants();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $client_id       = (int)($_POST['client_id'] ?? 0);
    $assigned_to     = (int)($_POST['assigned_to'] ?? 0) ?: null;
    $date            = trim($_POST['date'] ?? '');
    $time            = trim($_POST['time'] ?? '');
    $duration        = (int)($_POST['duration_minutes'] ?? 60);
    $status          = $_POST['status'] ?? 'preliminary';
    $description     = trim($_POST['description'] ?? '');
    $time_from_val   = trim($_POST['time_from_val'] ?? '');
    $time_to_val     = trim($_POST['time_to_val']   ?? '');
    // Przelicz czas trwania z godzin od-do (nadpisuje duration_minutes jeśli podano)
    if ($time_from_val && $time_to_val) {
        $mins = (strtotime('1970-01-01 '.$time_to_val) - strtotime('1970-01-01 '.$time_from_val)) / 60;
        if ($mins > 0) { $duration = (int)$mins; $time = $time_from_val; }
    }
    $location_type   = $_POST['location_type'] ?? ''; // 'resource' | 'remote'
    $resource_id     = ($location_type === 'resource') ? ((int)($_POST['resource_id'] ?? 0)) ?: null : null;
    $is_remote       = ($location_type === 'remote') ? 1 : 0;

    // Rozliczenie
    $billing_type      = in_array($_POST['billing_type'] ?? '', ['free','paid','pfron'], true)
                         ? $_POST['billing_type'] : 'free';
    $pfron_contract_id = $billing_type === 'pfron' ? ((int)($_POST['pfron_contract_id'] ?? 0)) ?: null : null;
    $pfron_status      = $billing_type === 'pfron' ? 'pending' : '';
    if ($billing_type === 'pfron' && !$pfron_contract_id)
        $errors[] = 'Wybierz umowę PFRON dla trybu PFRON.';

    // Cykliczność
    $is_recurring  = !empty($_POST['is_recurring']);
    $recur_freq    = in_array($_POST['recur_freq'] ?? '', ['daily','weekly','biweekly','monthly'], true)
                     ? $_POST['recur_freq'] : 'weekly';
    $recur_days    = array_map('intval', (array)($_POST['recur_days'] ?? []));
    $recur_count   = max(1, min(52, (int)($_POST['recur_count'] ?? 4)));
    $recur_until   = trim($_POST['recur_until'] ?? '');
    $recur_end_by  = $_POST['recur_end_by'] ?? 'count'; // 'count' | 'date'
    $recur_rule    = $is_recurring ? json_encode(['freq'=>$recur_freq,'days'=>$recur_days,'count'=>$recur_count,'until'=>$recur_until,'end_by'=>$recur_end_by], JSON_UNESCAPED_UNICODE) : '';

    // Faktura
    $needs_invoice   = !empty($_POST['needs_invoice']) ? 1 : 0;
    $invoice_type    = in_array($_POST['invoice_type'] ?? '', ['personal','company'], true)
                       ? $_POST['invoice_type'] : 'company';
    $invoice_name    = trim($_POST['invoice_name']    ?? '');
    $invoice_nip     = $invoice_type === 'company' ? preg_replace('/\D/', '', trim($_POST['invoice_nip'] ?? '')) : '';
    $invoice_address = trim($_POST['invoice_address'] ?? '');
    $invoice_email   = trim($_POST['invoice_email']   ?? '');

    if (!$client_id)  $errors[] = 'Wybierz beneficjenta.';
    if (!$date || !$time) $errors[] = 'Data i godzina są wymagane.';
    if ($needs_invoice && !$invoice_name)    $errors[] = 'Podaj imię i nazwisko / nazwę firmy do FV.';
    if ($needs_invoice && $invoice_type === 'company' && !$invoice_nip) $errors[] = 'Podaj NIP do FV.';
    if ($needs_invoice && !$invoice_address) $errors[] = 'Podaj adres do FV.';
    if ($duration <= 0) $duration = 60;
    if (!array_key_exists($status, K30_SCHEDULE_STATUSES)) $status = 'preliminary';

    // Lokalizacja — wymagana: zasób lub zdalnie
    if (!$errors) {
        if (!$location_type) {
            $errors[] = 'Wybierz lokalizację terminu: zasób fizyczny lub zdalnie.';
        } elseif ($location_type === 'resource' && !$resource_id) {
            $errors[] = 'Wybierz konkretny zasób (salę / stanowisko) lub zmień tryb na „Zdalnie".';
        }
    }

    // Walidacja dostępności i kolizji zasobu
    if (!$errors && $resource_id) {
        $time_end = date('H:i', strtotime($time) + $duration * 60);

        if (!res_is_available_at($resource_id, $date, $time, $time_end)) {
            $res_row = res_get($resource_id);
            $avail   = res_availability($resource_id);
            $dow     = (int)date('N', strtotime($date));
            if ($dow === 7) $dow = 0;
            if (isset($avail[$dow]) && $avail[$dow]['is_closed']) {
                $errors[] = 'Zasób „' . ($res_row['name'] ?? '') . '" jest w tym dniu zamknięty.';
            } else {
                $hours = isset($avail[$dow])
                    ? $avail[$dow]['time_open'] . '–' . $avail[$dow]['time_close']
                    : '?';
                $errors[] = 'Termin (' . $time . '–' . $time_end . ') wykracza poza godziny dostępności zasobu „'
                    . ($res_row['name'] ?? '') . '" — dostępny: ' . $hours . '.';
            }
        } else {
            // Kolizja z innym terminem D3 na tym samym zasobie
            $time_end_check = date('H:i', strtotime($time) + $duration * 60);
            $collision = db_one(
                "SELECT s.id, c.name AS client_name, TIME(s.start_time) AS t_start,
                        TIME(s.start_time, '+' || s.duration_minutes || ' minutes') AS t_end
                 FROM k30_schedules s
                 LEFT JOIN k30_clients c ON c.id=s.client_id
                 WHERE s.resource_id=?
                   AND s.status NOT IN ('cancelled','rejected')
                   AND DATE(s.start_time)=?
                   AND TIME(s.start_time) < ?
                   AND TIME(s.start_time, '+' || s.duration_minutes || ' minutes') > ?",
                [$resource_id, $date, $time_end_check, $time]
            );
            if ($collision) {
                $errors[] = 'Zasób jest już zajęty w tym czasie (termin #' . $collision['id']
                    . ', ' . ($collision['client_name'] ?? '?')
                    . ', ' . $collision['t_start'] . '–' . $collision['t_end'] . ').';
            } else {
                // Kolizja z rezerwacją w Systemie Rezerwacji Sal (SRS) — zasób
                // współdzielony (k30_enabled=1) ma dwa niezależne rejestry
                // rezerwacji (k30_schedules i resource_reservations), więc
                // sprawdzamy oba, żeby uniknąć podwójnej rezerwacji tej samej sali.
                $srs_collision = db_one(
                    "SELECT rr.id, rr.time_from, rr.time_to, rr.purpose, u.name AS user_name
                     FROM resource_reservations rr
                     JOIN users u ON u.id = rr.user_id
                     WHERE rr.resource_id=?
                       AND rr.status NOT IN ('odmowa','anulowana')
                       AND rr.date_from<=? AND rr.date_to>=?
                       AND (rr.time_from='' OR rr.time_to='' OR (rr.time_from < ? AND rr.time_to > ?))",
                    [$resource_id, $date, $date, $time_end_check, $time]
                );
                if ($srs_collision) {
                    $errors[] = 'Zasób jest już zarezerwowany w Systemie Rezerwacji Sal (SRS) w tym terminie'
                        . ($srs_collision['purpose'] !== '' ? ' — ' . $srs_collision['purpose'] : '')
                        . ' (' . ($srs_collision['user_name'] ?? '?') . ').';
                }
            }
        }
    }

    if (!$errors) {
        $start_time = $date . ' ' . $time . ':00';

        // Wspólne dane terminu (bez pól cennikowych — te wylicza k30_create_series)
        $base_row = [
            'client_id'        => $client_id,
            'assigned_to'      => $assigned_to,
            'start_time'       => $start_time,
            'duration_minutes' => $duration,
            'time_from'        => $time_from_val ?: $time,
            'time_to'          => $time_to_val,
            'status'           => $status,
            'description'      => $description ?: null,
            'resource_id'      => $resource_id,
            'is_remote'        => $is_remote,
            'billing_type'     => $billing_type,
            'pfron_contract_id'=> $pfron_contract_id,
            'pfron_status'     => $pfron_status,
            'recurrence_rule'  => $recur_rule,
            'needs_invoice'    => $needs_invoice,
            'invoice_type'     => $invoice_type,
            'invoice_name'     => $invoice_name,
            'invoice_nip'      => $invoice_nip,
            'invoice_address'  => $invoice_address,
            'invoice_email'    => $invoice_email,
            'created_by'       => (int)(current_user()['id'] ?? 0),
        ];

        if ($is_recurring) {
            // Generuj daty i utwórz serię
            $dates = k30_generate_series_dates(
                $date, $recur_freq, $recur_days,
                $recur_end_by === 'count' ? $recur_count : 0,
                $recur_end_by === 'date'  ? $recur_until : ''
            );
            $ids = k30_create_series($base_row, $dates);
            $id  = $ids[0] ?? 0;
            flash_set('success', 'Seria ' . count($ids) . ' terminów została utworzona. Pierwszy termin wyświetlony poniżej.');
        } else {
            // Pojedynczy termin
            $billed_hours = round($duration / 60, 4);
            $pricing      = k30_calculate_amount_v2($client_id, $billed_hours, $billing_type, $pfron_contract_id);
            $id = db_insert('k30_schedules', array_merge($base_row, [
                'billed_hours'  => $pricing['billed_hours'],
                'free_hours'    => $pricing['free_hours'],
                'charged_hours' => $pricing['charged_hours'],
                'amount_due'    => $pricing['amount_due'],
                'pricing_note'  => $pricing['pricing_note'],
                'series_id'     => '',
                'series_index'  => 0,
            ]));
            db()->prepare(
                "UPDATE k30_clients SET used=used+?, used_paid=used_paid+?, updated_at=datetime('now') WHERE id=?"
            )->execute([$pricing['billed_hours'], $pricing['charged_hours'], $client_id]);
            if ($billing_type === 'pfron' && $pfron_contract_id && $pricing['free_hours'] > 0) {
                db()->prepare("UPDATE k30_pfron_contracts SET hours_used=hours_used+?, updated_at=datetime('now') WHERE id=?")
                   ->execute([$pricing['free_hours'], $pfron_contract_id]);
            }
        }

        // ── Integracja CRM: notatka o fakturze na karcie kontaktu ─────────
        if ($needs_invoice) {
            try {
                $client = db_one("SELECT * FROM k30_clients WHERE id=?", [$client_id]);
                if ($client) {
                    $contact_id = k30_sync_to_crm($client, (int)(current_user()['id'] ?? 0));
                    if ($contact_id) {
                        $type_label = $invoice_type === 'personal' ? 'Imienna' : 'Firmowa';
                        $note_body = "Prośba o FV ({$type_label}) do terminu D3 (" . $date . "):\n"
                            . "Nazwa: " . $invoice_name . "\n"
                            . ($invoice_nip ? "NIP: " . $invoice_nip . "\n" : '')
                            . "Adres: " . $invoice_address
                            . ($invoice_email ? "\nE-mail FV: " . $invoice_email : '');
                        // Notatka na kontakcie CRM
                        require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
                        crm_migrate();
                        CrmManager::addNote($contact_id, $note_body, (int)(current_user()['id'] ?? 0), true);
                        // Aktywność CRM
                        k30_log_crm_activity(
                            $contact_id, 'task',
                            'Faktura — termin D3 ' . $date,
                            $note_body,
                            $start_time, 'planned', '',
                            (int)(current_user()['id'] ?? 0)
                        );
                    }
                }
            } catch (\Throwable $e) {
                error_log('[k30 invoice crm] ' . $e->getMessage());
            }

            // ── Powiadomienie admina (system + email) ─────────────────────
            try {
                require_once dirname(dirname(__DIR__)) . '/includes/notifications.php';
                require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
                notif_migrate();

                $client_name = $client['name'] ?? 'beneficjent';
                $notif_title = 'Prośba o FV — D3 — ' . $client_name . ' (' . $date . ')';
                $notif_body  = "Beneficjent <strong>{$client_name}</strong> zażądał faktury do terminu D3 ({$date})."
                    . "\n\nDane:\n• Firma: {$invoice_name}\n• NIP: {$invoice_nip}\n• Adres: {$invoice_address}"
                    . ($invoice_email ? "\n• E-mail faktury: {$invoice_email}" : '');
                $notif_url   = APP_URL . '/karty30/schedules/view.php?id=' . $id;

                $admins = db_all("SELECT id, name, email FROM users WHERE role='admin' AND is_active=1");
                foreach ($admins as $admin) {
                    notif_create((int)$admin['id'], 'system', $notif_title, $notif_body, $notif_url);

                    // Email
                    $org  = defined('ORG_NAME') ? htmlspecialchars(ORG_NAME, ENT_QUOTES) : 'System';
                    $rn   = htmlspecialchars($admin['name'], ENT_QUOTES);
                    $html = <<<HTML
<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"></head>
<body style="font-family:'Segoe UI',Arial,sans-serif;background:#f0f4f8;padding:32px 16px;margin:0">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)">
  <div style="background:#1e293b;padding:22px 30px;color:#fff;font-size:17px;font-weight:700">{$org} — Prośba o FV — K30</div>
  <div style="padding:26px 30px;font-size:14px;color:#374151;line-height:1.6">
    <p>Cześć <strong>{$rn}</strong>,</p>
    <p>Beneficjent <strong>{$client_name}</strong> zażądał wystawienia faktury do terminu D3 w dniu <strong>{$date}</strong>.</p>
    <table style="border-collapse:collapse;width:100%;font-size:13px;margin:16px 0">
      <tr style="background:#f8fafc"><td style="padding:8px 12px;border:1px solid #e2e8f0;font-weight:600;width:140px">Firma / Imię</td><td style="padding:8px 12px;border:1px solid #e2e8f0">{$invoice_name}</td></tr>
      <tr><td style="padding:8px 12px;border:1px solid #e2e8f0;font-weight:600">NIP</td><td style="padding:8px 12px;border:1px solid #e2e8f0">{$invoice_nip}</td></tr>
      <tr style="background:#f8fafc"><td style="padding:8px 12px;border:1px solid #e2e8f0;font-weight:600">Adres</td><td style="padding:8px 12px;border:1px solid #e2e8f0">{$invoice_address}</td></tr>
      <tr><td style="padding:8px 12px;border:1px solid #e2e8f0;font-weight:600">E-mail faktury</td><td style="padding:8px 12px;border:1px solid #e2e8f0">{$invoice_email}</td></tr>
    </table>
    <p><a href="{$notif_url}" style="display:inline-block;background:#2563eb;color:#fff;padding:11px 22px;border-radius:7px;text-decoration:none;font-weight:700">Przejdź do terminu →</a></p>
  </div>
  <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 30px;font-size:11px;color:#94a3b8">
    Wiadomość automatyczna — {$org}
  </div>
</div></body></html>
HTML;
                    mail_queue_add(
                        $admin['email'], $admin['name'],
                        $notif_title, $html, strip_tags($notif_body),
                        'k30_invoice', $id
                    );
                }
                mail_queue_process(count($admins));
            } catch (\Throwable $e) {
                error_log('[k30 invoice notify] ' . $e->getMessage());
            }
        }

        $flash_msg = 'Termin został dodany.' . ($needs_invoice ? ' Powiadomienie o FV wysłane do administratora.' : '');
        flash_set('success', $flash_msg);
        header('Location: view.php?id=' . $id . ($is_recurring ? '' : '&print_suggest=1'));
        exit;
    }
}

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
    <li class="breadcrumb-item"><a href="index.php">Harmonogram</a></li>
    <li class="breadcrumb-item active">Nowy termin</li>
  </ol>
</nav>

<h4 class="fw-bold mb-4"><i class="bi bi-calendar-plus text-success me-2"></i>Nowy termin</h4>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:600px">
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold">Beneficjent <span class="text-danger">*</span></label>
        <select name="client_id" class="form-select" required>
          <option value="">— Wybierz beneficjenta —</option>
          <?php foreach ($clients as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)($_POST['client_id'] ?? $prefill_client_id) === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Konsultant</label>
        <select name="assigned_to" class="form-select">
          <option value="">— Nie przypisano —</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= (int)($_POST['assigned_to'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['display_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php
        // Dane do kalkulatora (przekazane przez JS lub z poprzedniego POSTa)
        $_k30_client_id_pre = (int)($_POST['client_id'] ?? $prefill_client_id ?? 0);
      ?>
      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <label class="form-label fw-semibold">Data <span class="text-danger">*</span></label>
          <input name="date" id="k30_date" type="date" class="form-control" required
                 value="<?= h($_POST['date'] ?? date('Y-m-d')) ?>"
                 onchange="k30CalcAmount()">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Godzina od <span class="text-danger">*</span></label>
          <input name="time" id="k30_time" type="time" class="form-control" required
                 value="<?= h($_POST['time'] ?? '09:00') ?>"
                 onchange="k30SyncTimes()">
          <!-- ukryty time_from_val = to samo co time, dla czytelności -->
          <input type="hidden" name="time_from_val" id="k30_time_from" value="<?= h($_POST['time_from_val'] ?? $_POST['time'] ?? '09:00') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Godzina do <span class="text-danger">*</span></label>
          <input name="time_to_val" id="k30_time_to" type="time" class="form-control" required
                 value="<?= h($_POST['time_to_val'] ?? '10:00') ?>"
                 onchange="k30CalcAmount()">
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <!-- duration_minutes aktualizowany przez JS z od-do -->
          <input type="hidden" name="duration_minutes" id="k30_duration_min" value="<?= h($_POST['duration_minutes'] ?? '60') ?>">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach (K30_SCHEDULE_STATUSES as $sk => $sv): ?>
            <option value="<?= $sk ?>" <?= ($_POST['status'] ?? 'preliminary') === $sk ? 'selected' : '' ?>><?= $sv['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <!-- Kalkulator kwoty -->
        <div class="col-md-6">
          <div id="k30_pricing_box" class="card border-0 bg-light" style="font-size:.85rem">
            <div class="card-body py-2 px-3">
              <div class="fw-semibold mb-1"><i class="bi bi-calculator me-1 text-primary"></i>Szacowana kwota</div>
              <div id="k30_pricing_result" class="text-muted">Wybierz beneficjenta i godziny.</div>
            </div>
          </div>
        </div>
      </div>

      <?php
        // Przekaż cennik i limit do JS jako JSON
        $_tiers_json = json_encode(array_map(fn($t) => [
            'hours_from' => (float)$t['hours_from'],
            'hours_to'   => $t['hours_to'] !== null ? (float)$t['hours_to'] : null,
            'rate'       => (float)$t['rate'],
            'label'      => $t['label'],
        ], k30_price_tiers()), JSON_UNESCAPED_UNICODE);
        $_free_limit_js = (float)k30_free_hours_limit();
        // Godziny już zużyte per klient — pobierzemy przez mini-API lub wbudujemy
        $_clients_used = [];
        foreach (db_all("SELECT id, used FROM k30_clients") as $c) {
            $_clients_used[(int)$c['id']] = (float)$c['used'];
        }
        $_used_json = json_encode($_clients_used);
      ?>
      <script>
      var K30_TIERS      = <?= $_tiers_json ?>;
      var K30_FREE_LIMIT = <?= $_free_limit_js ?>;
      var K30_USED       = <?= $_used_json ?>;

      function k30SyncTimes() {
        var tf = document.getElementById('k30_time').value;
        document.getElementById('k30_time_from').value = tf;
        // Przesuń "do" o 60 min domyślnie jeśli nie ustawiono
        var tt = document.getElementById('k30_time_to').value;
        if (!tt || tt <= tf) {
          var d = new Date('1970-01-01T' + tf);
          d.setMinutes(d.getMinutes() + 60);
          document.getElementById('k30_time_to').value =
            ('0'+d.getHours()).slice(-2) + ':' + ('0'+d.getMinutes()).slice(-2);
        }
        k30CalcAmount();
      }

      function applyTiers(hours, paidAlready) {
        var amount = 0, notes = [], pos = paidAlready, rem = hours;
        for (var i=0; i<K30_TIERS.length && rem > 0; i++) {
          var t=K30_TIERS[i], tTo=t.hours_to!==null?t.hours_to:1e9;
          if (pos>=tTo) continue;
          var start=Math.max(pos,t.hours_from), avail=tTo-start, inT=Math.min(rem,avail);
          if (inT<=0) continue;
          var cost=Math.round(inT*t.rate*100)/100;
          amount+=cost;
          notes.push(fmth(inT)+' h × '+fmt(t.rate)+' zł/h = '+fmt(cost)+' zł'+(t.label?' ('+t.label+')':''));
          rem-=inT; pos+=inT;
        }
        return {amount:amount, notes:notes};
      }

      function k30CalcAmount() {
        var tf   = document.getElementById('k30_time_from').value || document.getElementById('k30_time').value;
        var tt   = document.getElementById('k30_time_to').value;
        var csel = document.querySelector('[name="client_id"]');
        var box  = document.getElementById('k30_pricing_result');
        var btEl = document.querySelector('[name="billing_type"]:checked');
        var bt   = btEl ? btEl.value : 'free';

        if (!tf || !tt || tt <= tf) { box.textContent = 'Podaj poprawne godziny.'; return; }

        var fromMs = new Date('1970-01-01T' + tf).getTime();
        var toMs   = new Date('1970-01-01T' + tt).getTime();
        var mins   = Math.round((toMs - fromMs) / 60000);
        document.getElementById('k30_duration_min').value = mins;
        var billed = mins / 60;
        var notes = [], amount = 0, freeH = 0, chargedH = 0;

        var html = '<div style="line-height:1.7">';
        html += '<div><small class="text-muted">Czas: ' + fmth(billed) + ' h (' + mins + ' min)</small></div>';

        if (bt === 'paid') {
          // Całość odpłatnie
          var r = applyTiers(billed, 0);
          amount = r.amount; notes = r.notes;
          chargedH = billed;

        } else if (bt === 'pfron') {
          // Limit z wybranej umowy PFRON
          var pfSel = document.getElementById('pfron_contract_sel');
          var opt   = pfSel ? pfSel.options[pfSel.selectedIndex] : null;
          var rem   = opt && opt.value ? parseFloat(opt.dataset.remaining) || 0 : 0;
          freeH     = Math.min(billed, rem);
          chargedH  = Math.max(0, billed - freeH);
          if (freeH > 0) notes.push(fmth(freeH) + ' h bezpłatnie (PFRON)');
          if (chargedH > 0) {
            var r = applyTiers(chargedH, 0); amount = r.amount;
            notes = notes.concat(r.notes);
          }

        } else {
          // Bezpłatne w ramach limitu
          var cid  = csel ? parseInt(csel.value) || 0 : 0;
          var used = cid ? (K30_USED[cid] || 0) : 0;
          var fl   = K30_FREE_LIMIT;
          freeH    = Math.min(billed, Math.max(0, fl - used));
          chargedH = Math.max(0, billed - freeH);
          if (freeH > 0) notes.push(fmth(freeH) + ' h bezpłatnie (limit: ' + fmth(fl) + ' h)');
          if (chargedH > 0) {
            var paidAlready = Math.max(0, used - fl);
            var r = applyTiers(chargedH, paidAlready); amount = r.amount;
            notes = notes.concat(r.notes);
          }
        }

        if (notes.length) html += '<div style="font-size:.84rem">' + notes.join('<br>') + '</div>';
        html += '<div class="fw-bold mt-1" style="font-size:1.1rem">';
        if (amount === 0) {
          html += '<span class="text-success"><i class="bi bi-gift me-1"></i>Bezpłatne</span>';
        } else {
          html += '<span class="text-primary">' + fmt(amount) + ' zł</span>';
        }
        html += '</div></div>';
        box.innerHTML = html;
      }

      function fmth(h) { return h.toFixed(2).replace('.',','); }
      function fmt(v)  { return parseFloat(v).toFixed(2).replace('.',','); }

      // Inicjalizacja
      document.addEventListener('DOMContentLoaded', function() {
        var csel = document.querySelector('[name="client_id"]');
        if (csel) csel.addEventListener('change', k30CalcAmount);
        k30CalcAmount();
      });
      </script>

      <!-- ── Tryb rozliczenia ─────────────────────────────────────────────── -->
      <?php
        $bt_val  = $_POST['billing_type'] ?? 'free';
        $cid_pre = (int)($_POST['client_id'] ?? $prefill_client_id ?? 0);
        $pfron_contracts = k30_pfron_enabled() && $cid_pre ? k30_pfron_contracts_for_client($cid_pre) : [];
        $pfron_active    = array_filter($pfron_contracts, fn($c) => $c['status'] === 'active');
        $_billing_types  = K30_BILLING_TYPES;
        if (!k30_pfron_enabled()) unset($_billing_types['pfron']);
      ?>
      <div class="mb-3">
        <label class="form-label fw-semibold">
          <i class="bi bi-credit-card me-1 text-primary"></i>Tryb rozliczenia <span class="text-danger">*</span>
        </label>
        <div class="d-flex gap-2 flex-wrap mb-2">
          <?php foreach ($_billing_types as $bt_key => $bt): ?>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="billing_type"
                   id="bt_<?= $bt_key ?>" value="<?= $bt_key ?>"
                   <?= $bt_val === $bt_key ? 'checked' : '' ?>
                   onchange="btSwitch(this.value)">
            <label class="form-check-label fw-semibold" for="bt_<?= $bt_key ?>"
                   style="color:<?= h($bt['color']) ?>">
              <i class="bi <?= h($bt['icon']) ?> me-1"></i><?= h($bt['label']) ?>
            </label>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Opis trybu -->
        <div id="bt_desc_free"  class="form-text" style="display:<?= $bt_val==='free'  ?'':'none' ?>">
          Bezpłatne w ramach globalnego limitu lub limitu indywidualnego klienta.
        </div>
        <div id="bt_desc_paid"  class="form-text" style="display:<?= $bt_val==='paid'  ?'':'none' ?>">
          Pełna odpłatność według cennika.
        </div>
        <?php if (k30_pfron_enabled()): ?>
        <div id="bt_desc_pfron" class="form-text" style="display:<?= $bt_val==='pfron' ?'':'none' ?>">
          Finansowanie z PFRON. Godziny bezpłatne w ramach limitu umowy, nadwyżka wg cennika.
        </div>

        <!-- Wybór umowy PFRON -->
        <div id="bt_pfron_contract" class="mt-2" style="display:<?= $bt_val==='pfron'?'':'none' ?>">
          <?php if ($pfron_active): ?>
          <label class="form-label fw-semibold">Umowa PFRON <span class="text-danger">*</span></label>
          <select name="pfron_contract_id" class="form-select" id="pfron_contract_sel">
            <option value="">— wybierz umowę —</option>
            <?php foreach ($pfron_active as $pc): ?>
            <option value="<?= (int)$pc['id'] ?>"
                    data-remaining="<?= (float)$pc['hours_limit'] - (float)$pc['hours_used'] ?>"
                    <?= (int)($_POST['pfron_contract_id'] ?? 0) === (int)$pc['id'] ? 'selected' : '' ?>>
              <?= h($pc['contract_number']) ?>
              — limit: <?= number_format((float)$pc['hours_limit'],2,',','') ?> h,
              pozostało: <?= number_format(max(0,(float)$pc['hours_limit']-(float)$pc['hours_used']),2,',','') ?> h
              <?= $pc['valid_to'] ? '(do '.h($pc['valid_to']).')' : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div id="pfron_remaining_hint" class="form-text mt-1"></div>
          <?php else: ?>
          <div class="alert alert-warning py-2 small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Brak aktywnych umów PFRON dla tego klienta.
            <?php if ($cid_pre): ?>
            <a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= $cid_pre ?>#pfron">Dodaj umowę PFRON</a>.
            <?php else: ?>
            Najpierw wybierz beneficjenta.
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; // k30_pfron_enabled ?>
      </div>

      <script>
      function btSwitch(type) {
        ['free','paid','pfron'].forEach(function(t) {
          document.getElementById('bt_desc_' + t).style.display = t === type ? '' : 'none';
        });
        var pfronDiv = document.getElementById('bt_pfron_contract');
        if (pfronDiv) pfronDiv.style.display = type === 'pfron' ? '' : 'none';
        k30CalcAmount();
      }
      // PFRON remaining hint
      var pfronSel = document.getElementById('pfron_contract_sel');
      if (pfronSel) {
        pfronSel.addEventListener('change', function() {
          var opt = this.options[this.selectedIndex];
          var hint = document.getElementById('pfron_remaining_hint');
          if (opt && opt.value) {
            var rem = parseFloat(opt.dataset.remaining) || 0;
            hint.innerHTML = rem > 0
              ? '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Pozostało: ' + rem.toFixed(2).replace('.',',') + ' h w tej umowie.</span>'
              : '<span class="text-danger fw-bold"><i class="bi bi-exclamation-circle me-1"></i>Limit wyczerpany!</span>';
          } else { hint.innerHTML = ''; }
          k30CalcAmount();
        });
      }
      </script>

      <?php $_k30_resources = res_k30_available(); ?>
      <!-- Lokalizacja terminu — WYMAGANA -->
      <div class="mb-3">
        <label class="form-label fw-semibold">
          <i class="bi bi-geo-alt-fill text-primary me-1"></i>Lokalizacja terminu <span class="text-danger">*</span>
        </label>
        <?php $loc_type = $_POST['location_type'] ?? ''; ?>
        <div class="d-flex gap-2 mb-2">
          <div class="form-check">
            <input class="form-check-input" type="radio" name="location_type" id="lt_resource"
                   value="resource" <?= $loc_type === 'resource' ? 'checked' : '' ?>
                   onchange="k30LocSwitch(this.value)" required>
            <label class="form-check-label fw-semibold" for="lt_resource">
              <i class="bi bi-door-open me-1"></i>Zasób fizyczny
            </label>
          </div>
          <div class="form-check ms-3">
            <input class="form-check-input" type="radio" name="location_type" id="lt_remote"
                   value="remote" <?= $loc_type === 'remote' ? 'checked' : '' ?>
                   onchange="k30LocSwitch(this.value)">
            <label class="form-check-label fw-semibold" for="lt_remote">
              <i class="bi bi-camera-video me-1"></i>Zdalnie
            </label>
          </div>
        </div>
        <div id="k30_resource_panel" style="display:<?= $loc_type === 'resource' ? '' : 'none' ?>">
          <?php if ($_k30_resources): ?>
          <select name="resource_id" class="form-select" id="k30_resource_sel">
            <option value="">— wybierz zasób —</option>
            <?php foreach ($_k30_resources as $kr): ?>
            <option value="<?= (int)$kr['id'] ?>"
                    data-avail="<?= h(json_encode(res_availability((int)$kr['id']))) ?>"
                    <?= (int)($_POST['resource_id'] ?? 0) === (int)$kr['id'] ? 'selected' : '' ?>>
              <?= h($kr['cat_name'] ? '[' . $kr['cat_name'] . '] ' : '') . h($kr['name']) ?>
              <?= $kr['location'] ? ' — ' . h($kr['location']) : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div id="k30_avail_hint" class="mt-1" style="font-size:.83rem"></div>
          <?php else: ?>
          <div class="alert alert-warning py-2 small mt-2">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Brak zasobów dla Dydaktyka 3. <a href="<?= APP_URL ?>/modules/srs/admin/resources.php">Skonfiguruj zasoby</a>.
          </div>
          <?php endif; ?>
        </div>
        <div id="k30_remote_panel" class="alert alert-info py-2 mb-0 small"
             style="display:<?= $loc_type === 'remote' ? '' : 'none' ?>">
          <i class="bi bi-camera-video-fill me-1"></i>
          Termin odbywa się zdalnie — bez rezerwacji fizycznego zasobu.
        </div>
      </div>
      <script>
      function k30LocSwitch(type) {
        document.getElementById('k30_resource_panel').style.display = type === 'resource' ? '' : 'none';
        document.getElementById('k30_remote_panel').style.display  = type === 'remote'   ? '' : 'none';
        var sel = document.getElementById('k30_resource_sel');
        if (sel) sel.required = (type === 'resource');
      }
      (function(){
        var lt = document.querySelector('[name="location_type"]:checked');
        if (lt) k30LocSwitch(lt.value);
        var sel = document.getElementById('k30_resource_sel');
        if (!sel) return;
        var dateEl = document.querySelector('[name="date"]');
        var timeEl = document.querySelector('[name="time"]');
        var durEl  = document.querySelector('[name="duration_minutes"]');
        var hint   = document.getElementById('k30_avail_hint');
        var DAYS   = {0:'Niedziela',1:'Poniedziałek',2:'Wtorek',3:'Środa',4:'Czwartek',5:'Piątek',6:'Sobota'};
        function addMins(t, m) {
          var p=t.split(':'), h=+p[0], mi=+p[1]+m;
          h += Math.floor(mi/60); mi %= 60;
          return (h<10?'0':'')+h+':'+(mi<10?'0':'')+mi;
        }
        function updateHint() {
          if (!hint) return;
          var opt = sel.options[sel.selectedIndex];
          if (!opt || !opt.value) { hint.innerHTML = ''; return; }
          var avail = JSON.parse(opt.dataset.avail || '{}');
          var dateVal = dateEl ? dateEl.value : '';
          if (!dateVal) { hint.innerHTML = ''; return; }
          var dow = new Date(dateVal + 'T00:00:00').getDay();
          var timeVal = timeEl ? timeEl.value : '';
          var dur = durEl ? (+durEl.value || 60) : 60;
          var timeEnd = timeVal ? addMins(timeVal, dur) : '';
          if (!avail[dow]) {
            hint.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Brak ograniczeń — dostępny cały dzień.</span>';
            return;
          }
          var a = avail[dow];
          var dn = DAYS[dow] || '';
          if (a.is_closed) {
            hint.innerHTML = '<span class="text-danger fw-semibold"><i class="bi bi-x-circle-fill me-1"></i>' + dn + ': ZAMKNIĘTY.</span>';
            return;
          }
          var ok = !timeVal || (timeVal >= a.time_open && (!timeEnd || timeEnd <= a.time_close));
          hint.innerHTML = ok
            ? '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + dn + ': ' + a.time_open + '–' + a.time_close + ' ✓</span>'
            : '<span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle-fill me-1"></i>Poza godzinami! ' + dn + ': ' + a.time_open + '–' + a.time_close + '. Tw\xf3j termin: ' + timeVal + '–' + timeEnd + '</span>';
        }
        sel.addEventListener('change', updateHint);
        if (dateEl) dateEl.addEventListener('change', updateHint);
        if (timeEl) timeEl.addEventListener('change', updateHint);
        if (durEl)  durEl.addEventListener('change', updateHint);
        updateHint();
      })();
      </script>

      <div class="mb-3">
        <label class="form-label">Opis / uwagi</label>
        <textarea name="description" class="form-control" rows="3"><?= h($_POST['description'] ?? '') ?></textarea>
      </div>

      <!-- ── Wizyty cykliczne ───────────────────────────────────────────────── -->
      <hr class="my-3">
      <?php
        $rcv  = !empty($_POST['is_recurring']);
        $rfreq = $_POST['recur_freq'] ?? 'weekly';
        $rdays = array_map('intval', (array)($_POST['recur_days'] ?? []));
        $rend  = $_POST['recur_end_by'] ?? 'count';
      ?>
      <div class="mb-3">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch"
                 id="is_recurring" name="is_recurring" value="1"
                 <?= $rcv ? 'checked' : '' ?>
                 onchange="document.getElementById('recur_fields').style.display=this.checked?'':'none'">
          <label class="form-check-label fw-semibold" for="is_recurring">
            <i class="bi bi-arrow-repeat text-primary me-1"></i>Wizyta cykliczna
          </label>
        </div>
        <div class="form-text">Utwórz serię powtarzających się terminów naraz.</div>
      </div>

      <div id="recur_fields" style="display:<?= $rcv?'':'none' ?>">
        <div class="card border-primary-subtle mb-3">
          <div class="card-header bg-primary-subtle fw-semibold py-2" style="font-size:.85rem">
            <i class="bi bi-arrow-repeat me-1 text-primary"></i>Reguła powtarzania
          </div>
          <div class="card-body">
            <!-- Częstotliwość -->
            <div class="mb-3">
              <label class="form-label fw-semibold">Częstotliwość</label>
              <div class="d-flex gap-3 flex-wrap">
                <?php foreach ([
                  'daily'    => 'Codziennie',
                  'weekly'   => 'Co tydzień',
                  'biweekly' => 'Co 2 tygodnie',
                  'monthly'  => 'Co miesiąc',
                ] as $fv => $fl): ?>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="recur_freq"
                         id="rf_<?= $fv ?>" value="<?= $fv ?>"
                         <?= $rfreq===$fv?'checked':'' ?>
                         onchange="recurFreqChange(this.value)">
                  <label class="form-check-label" for="rf_<?= $fv ?>"><?= $fl ?></label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Dni tygodnia (tylko dla weekly/biweekly) -->
            <div id="recur_days_row" class="mb-3"
                 style="display:<?= in_array($rfreq,['weekly','biweekly'])?'':'none' ?>">
              <label class="form-label fw-semibold">Dni tygodnia</label>
              <div class="d-flex gap-2 flex-wrap">
                <?php foreach ([1=>'Pn',2=>'Wt',3=>'Śr',4=>'Czw',5=>'Pt',6=>'Sb',0=>'Nd'] as $dw=>$dn): ?>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="checkbox" name="recur_days[]"
                         id="rd_<?= $dw ?>" value="<?= $dw ?>"
                         <?= in_array($dw,$rdays)?'checked':'' ?>>
                  <label class="form-check-label" for="rd_<?= $dw ?>"><?= $dn ?></label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Koniec serii -->
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label fw-semibold">Koniec serii</label>
                <div class="d-flex gap-3 align-items-center flex-wrap">
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="recur_end_by"
                           id="re_count" value="count"
                           <?= $rend!=='date'?'checked':'' ?>
                           onchange="recurEndSwitch('count')">
                    <label class="form-check-label" for="re_count">
                      Po
                      <input type="number" name="recur_count" id="recur_count_in"
                             class="form-control form-control-sm d-inline-block"
                             style="width:70px" min="1" max="52"
                             value="<?= h($_POST['recur_count']??'4') ?>">
                      powtórzeniach
                    </label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="recur_end_by"
                           id="re_date" value="date"
                           <?= $rend==='date'?'checked':'' ?>
                           onchange="recurEndSwitch('date')">
                    <label class="form-check-label" for="re_date">
                      Do daty
                      <input type="date" name="recur_until" id="recur_until_in"
                             class="form-control form-control-sm d-inline-block"
                             style="width:145px"
                             value="<?= h($_POST['recur_until']??'') ?>"
                             <?= $rend==='date'?'':'disabled' ?>>
                    </label>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <script>
      function recurFreqChange(v) {
        document.getElementById('recur_days_row').style.display =
          (v==='weekly'||v==='biweekly') ? '' : 'none';
      }
      function recurEndSwitch(mode) {
        document.getElementById('recur_count_in').disabled = (mode==='date');
        document.getElementById('recur_until_in').disabled = (mode==='count');
      }
      </script>

      <!-- ── Prośba o FV ──────────────────────────────────────────────────── -->
      <hr class="my-3">
      <?php
        $inv_checked  = !empty($_POST['needs_invoice']);
        $inv_type_val = $_POST['invoice_type'] ?? 'company';
      ?>
      <div class="mb-3">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch"
                 id="needs_invoice" name="needs_invoice" value="1"
                 <?= $inv_checked ? 'checked' : '' ?>
                 onchange="fvToggle(this.checked)">
          <label class="form-check-label fw-semibold" for="needs_invoice">
            <i class="bi bi-receipt-cutoff text-warning me-1"></i>Prośba o FV
          </label>
        </div>
        <div class="form-text">Zaznacz jeśli chcesz otrzymać fakturę za konsultację.</div>
      </div>

      <div id="invoice_fields" style="display:<?= $inv_checked ? '' : 'none' ?>">
        <div class="card border-warning mb-3">
          <div class="card-header bg-warning-subtle fw-semibold py-2" style="font-size:.85rem">
            <i class="bi bi-receipt me-1"></i>Dane do FV
          </div>
          <div class="card-body">

            <!-- Typ faktury -->
            <div class="mb-3">
              <label class="form-label fw-semibold">Rodzaj FV</label>
              <div class="d-flex gap-3">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="invoice_type"
                         id="fv_personal" value="personal"
                         <?= $inv_type_val === 'personal' ? 'checked' : '' ?>
                         onchange="fvTypeSwitch('personal')">
                  <label class="form-check-label" for="fv_personal">
                    <i class="bi bi-person me-1"></i>Imienna
                  </label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="invoice_type"
                         id="fv_company" value="company"
                         <?= $inv_type_val !== 'personal' ? 'checked' : '' ?>
                         onchange="fvTypeSwitch('company')">
                  <label class="form-check-label" for="fv_company">
                    <i class="bi bi-building me-1"></i>Firmowa (NIP)
                  </label>
                </div>
              </div>
            </div>

            <!-- NIP + lookup (tylko firmowa) -->
            <div id="fv_nip_row" class="mb-3" style="display:<?= $inv_type_val !== 'personal' ? '' : 'none' ?>">
              <label class="form-label fw-semibold" for="invoice_nip">
                NIP <span class="text-danger">*</span>
              </label>
              <div class="input-group">
                <input type="text" class="form-control font-monospace" id="invoice_nip" name="invoice_nip"
                       value="<?= h($_POST['invoice_nip'] ?? '') ?>"
                       placeholder="0000000000" maxlength="13" inputmode="numeric"
                       oninput="fvNipChanged()">
                <button type="button" class="btn btn-outline-secondary" id="fv_lookup_btn"
                        onclick="fvLookup()" title="Pobierz dane z CEIDG / KRS" disabled>
                  <i class="bi bi-search me-1"></i>Pobierz dane
                </button>
              </div>
              <div id="fv_lookup_status" class="form-text mt-1"></div>
            </div>

            <!-- Nazwa + adres -->
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label fw-semibold" for="invoice_name">
                  <span id="fv_name_label">Nazwa firmy</span> <span class="text-danger">*</span>
                </label>
                <input type="text" class="form-control" id="invoice_name" name="invoice_name"
                       value="<?= h($_POST['invoice_name'] ?? '') ?>"
                       placeholder="np. Jan Kowalski lub Fundacja XYZ">
              </div>
              <div class="col-12">
                <label class="form-label fw-semibold" for="invoice_address">
                  Adres <span class="text-danger">*</span>
                </label>
                <input type="text" class="form-control" id="invoice_address" name="invoice_address"
                       value="<?= h($_POST['invoice_address'] ?? '') ?>"
                       placeholder="ul. Przykładowa 1, 00-001 Warszawa">
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="invoice_email">E-mail do wysyłki FV</label>
                <input type="email" class="form-control" id="invoice_email" name="invoice_email"
                       value="<?= h($_POST['invoice_email'] ?? '') ?>"
                       placeholder="faktura@firma.pl">
              </div>
            </div>

            <div class="alert alert-info small mt-3 mb-0 py-2">
              <i class="bi bi-info-circle me-1"></i>
              Dane trafią do systemu CRM i administrator zostanie powiadomiony.
            </div>
          </div>
        </div>
      </div>

      <script>
      function fvToggle(on) {
        document.getElementById('invoice_fields').style.display = on ? '' : 'none';
      }
      function fvTypeSwitch(type) {
        var nipRow   = document.getElementById('fv_nip_row');
        var nameLabel= document.getElementById('fv_name_label');
        nipRow.style.display = type === 'company' ? '' : 'none';
        nameLabel.textContent = type === 'company' ? 'Nazwa firmy' : 'Imię i nazwisko';
        if (type === 'personal') {
          document.getElementById('invoice_nip').value = '';
          document.getElementById('fv_lookup_status').innerHTML = '';
        }
        fvNipChanged();
      }
      function fvNipChanged() {
        var nip = document.getElementById('invoice_nip').value.replace(/\D/g,'');
        document.getElementById('fv_lookup_btn').disabled = nip.length < 9;
      }
      function fvLookup() {
        var nip = document.getElementById('invoice_nip').value.replace(/\D/g,'');
        if (nip.length < 9) return;
        var status = document.getElementById('fv_lookup_status');
        var btn    = document.getElementById('fv_lookup_btn');
        status.innerHTML = '<span class="text-muted"><i class="bi bi-arrow-repeat spin me-1"></i>Wyszukuję…</span>';
        btn.disabled = true;
        // Próbuj najpierw CEIDG (po NIP), potem KRS
        fetch('<?= APP_URL ?>/api/ceidg.php?nip=' + encodeURIComponent(nip))
          .then(r => r.json())
          .then(data => {
            if (data.error) {
              // CEIDG nie znalazło — spróbuj KRS (po NIP trzeba go mieć w KRS)
              return fetch('<?= APP_URL ?>/api/krs.php?krs=' + encodeURIComponent(nip))
                .then(r2 => r2.json())
                .then(d2 => {
                  if (d2.error) throw new Error(data.error + ' | KRS: ' + d2.error);
                  return d2;
                });
            }
            return data;
          })
          .then(d => {
            document.getElementById('invoice_name').value    = d.nazwa || d.name || '';
            document.getElementById('invoice_address').value = d.adres || d.address || '';
            if (d.nip) document.getElementById('invoice_nip').value = d.nip;
            status.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Dane pobrane pomyślnie'
              + (d.forma_prawna ? ' (' + d.forma_prawna + ')' : '')
              + (d.aktywna === false ? ' <span class="text-danger fw-bold">— NIEAKTYWNA!</span>' : '')
              + '</span>';
          })
          .catch(e => {
            status.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>' + e.message + '</span>';
          })
          .finally(() => { btn.disabled = false; });
      }
      // Inicjalizacja
      (function(){
        var t = document.querySelector('[name="invoice_type"]:checked');
        if (t) fvTypeSwitch(t.value);
        fvNipChanged();
      })();
      </script>
      <style>.spin{animation:spin 1s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}</style>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
        <a href="index.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
