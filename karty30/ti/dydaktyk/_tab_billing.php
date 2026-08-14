<?php
/**
 * _tab_billing.php — Miesięczne rozliczenia TI (Kierownik).
 * Widok zarządczy billing.php w nowym UI panelu dydaktyka. Tylko dyd_is_staff().
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_payments.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/stripe.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/payu.php';
ti_payments_migrate();
stripe_migrate();
payu_migrate();

// Obsługa szybkich akcji POST (wystawianie + masowe)
$bi_can_write = dyd_is_staff();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $bi_can_write) {
    dyd_token_check();
    $bi_op = $_POST['_op'] ?? '';

    if ($bi_op === 'issue') {
        $bi_cid = (int)($_POST['client_id'] ?? 0);
        $bi_m   = (int)($_POST['month'] ?? 0);
        $bi_y   = (int)($_POST['year']  ?? 0);
        if ($bi_cid && $bi_m && $bi_y) {
            $bids = k30_ti_issue_billing_split($bi_cid, $bi_m, $bi_y);
            ti_billing_recompute($bi_cid);
            foreach ($bids as $bid) k30_ti_billing_notify($bid);
            $_SESSION['dyd_flash'] = ['type'=>'success','msg'=>'Rozliczenie wystawione (' . count($bids) . ').'];
        }
        $bi_redir_ym = sprintf('%04d-%02d', $_POST['year'] ?? date('Y'), $_POST['month'] ?? date('m'));
        header('Location: index.php?tab=billing&m=' . $bi_redir_ym);
        exit;
    }

    if ($bi_op === 'issue_all') {
        $bi_m = (int)($_POST['month'] ?? 0);
        $bi_y = (int)($_POST['year']  ?? 0);
        if ($bi_m && $bi_y) {
            $clients_with_sessions = db_all(
                "SELECT DISTINCT e.client_id FROM k30_ti_attendance a
                 JOIN k30_ti_sessions s ON s.id=a.session_id AND s.status IN ('held','individual_change','remote_material')
                 JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=a.client_id
                 WHERE a.attended=1 AND strftime('%m',s.lesson_date)=? AND strftime('%Y',s.lesson_date)=?",
                [sprintf('%02d',$bi_m), (string)$bi_y]
            );
            $cnt = 0;
            foreach ($clients_with_sessions as $c) {
                $bids = k30_ti_issue_billing_split((int)$c['client_id'], $bi_m, $bi_y);
                ti_billing_recompute((int)$c['client_id']);
                foreach ($bids as $bid) k30_ti_billing_notify($bid);
                $cnt += count($bids);
            }
            $_SESSION['dyd_flash'] = ['type'=>'success','msg'=>"Wystawiono $cnt rozliczeń dla " . count($clients_with_sessions) . " kursantów."];
        }
        $bi_redir_ym = sprintf('%04d-%02d', $_POST['year'] ?? date('Y'), $_POST['month'] ?? date('m'));
        header('Location: index.php?tab=billing&m=' . $bi_redir_ym);
        exit;
    }
}

// ── Parametry widoku ──────────────────────────────────────────────────────────
$bi_ym     = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
[$bi_year, $bi_mon] = array_map('intval', explode('-', $bi_ym));
$bi_mon    = max(1, min(12, $bi_mon));
$bi_prev   = date('Y-m', strtotime($bi_ym . '-01 -1 month'));
$bi_next   = date('Y-m', strtotime($bi_ym . '-01 +1 month'));
$_bi_msc   = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
              7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$bi_label  = ($_bi_msc[$bi_mon] ?? '') . ' ' . $bi_year;

$bi_course_id = (int)($_GET['course_id'] ?? 0);
$bi_courses   = k30_ti_courses(true);   // aktywne kursy do filtra

// ── Rozliczenia za miesiąc ────────────────────────────────────────────────────
$bi_where  = ["b.month=? AND b.year=?", "b.status!='cancelled'"]; $bi_params = [$bi_mon, $bi_year];
if ($bi_course_id) {
    $bi_where[] = "b.client_id IN (SELECT client_id FROM k30_ti_enrollments WHERE course_id=?)";
    $bi_params[] = $bi_course_id;
}
$bi_records = db_all(
    "SELECT b.*, cl.name AS client_name
     FROM k30_ti_billing b
     JOIN k30_clients cl ON cl.id=b.client_id
     WHERE " . implode(' AND ', $bi_where) . " ORDER BY cl.name COLLATE NOCASE",
    $bi_params
);

// Saldo per kursant
$bi_balances = [];
foreach (array_unique(array_column($bi_records, 'client_id')) as $cid) {
    $bi_balances[(int)$cid] = ti_client_balance((int)$cid);
}

// Statystyki
$bi_total_amount = array_sum(array_column($bi_records, 'amount'));
$bi_total_paid   = array_sum(array_column($bi_records, 'paid_amount'));
$bi_paid_count   = count(array_filter($bi_records, fn($b) => (float)$b['paid_amount'] >= (float)$b['amount'] - 0.01));
$bi_debt_count   = count($bi_records) - $bi_paid_count;

// Podgląd nieopłaconych (do wystawienia)
$bi_billed_ids  = array_column($bi_records, 'client_id');
$bi_preview_q   = $bi_course_id ? "AND e.course_id=?" : '';
$bi_preview_par = $bi_course_id ? [$bi_course_id] : [];
$bi_unbilled    = db_all(
    "SELECT DISTINCT e.client_id, cl.name AS client_name
     FROM k30_ti_enrollments e
     JOIN k30_clients cl ON cl.id=e.client_id
     WHERE e.status='active' $bi_preview_q ORDER BY cl.name COLLATE NOCASE",
    $bi_preview_par
);
$bi_unbilled = array_filter($bi_unbilled, fn($e) => !in_array((int)$e['client_id'], array_map('intval', $bi_billed_ids)));
$bi_to_issue = [];
foreach ($bi_unbilled as $e) {
    $cid  = (int)$e['client_id'];
    $calc = k30_ti_calculate_billing($cid, $bi_mon, $bi_year, $bi_course_id);
    if ($calc['hours_billed'] > 0 || $calc['amount'] > 0) {
        $bi_to_issue[$cid] = array_merge($calc, ['client_name' => $e['client_name']]);
    }
}

$stripe_on = stripe_enabled();
$payu_on   = payu_enabled();

// Mapa płatności Stripe/PayU
$bi_ids = array_column($bi_records, 'id');
$bi_stripe = $bi_payu = [];
if ($bi_ids) {
    $in = implode(',', array_fill(0, count($bi_ids), '?'));
    foreach (db_all("SELECT * FROM stripe_payments WHERE source_type='k30_ti_billing' AND source_id IN ($in)", $bi_ids) as $sp) {
        $bi_stripe[(int)$sp['source_id']] = $sp;
    }
    foreach (db_all("SELECT * FROM payu_payments WHERE source_type='k30_ti_billing' AND source_id IN ($in)", $bi_ids) as $pp) {
        $bi_payu[(int)$pp['source_id']] = $pp;
    }
}

// Flash z POST redirect
$bi_flash = $_SESSION['dyd_flash'] ?? null;
unset($_SESSION['dyd_flash']);

$bi_f = fn($x) => number_format((float)$x, 2, ',', ' ');
?>

<section aria-label="Rozliczenia TI" class="dyd-bi-wrap">
<style>
.dyd-bi-wrap { padding: 1.1rem 0 2.5rem; max-width: 960px; }

.dyd-p-banner {
  display: flex; align-items: flex-start; gap: .75rem;
  border-radius: 12px; padding: .75rem 1rem;
  margin-bottom: 1rem; font-size: .875rem; line-height: 1.45;
}
.dyd-p-banner.warn    { background: #fff7ed; color: #7c2d12; border: 1px solid #fed7aa; }
.dyd-p-banner.ok      { background: #f0fdf4; color: #14532d; border: 1px solid #bbf7d0; }
.dyd-p-banner.success { background: #eff6ff; color: #1e3a5f; border: 1px solid #bfdbfe; }
[data-bs-theme="dark"] .dyd-p-banner.warn    { background: #3c1a00; color: #fdba74; border-color: #92400e; }
[data-bs-theme="dark"] .dyd-p-banner.ok      { background: #052e16; color: #86efac; border-color: #166534; }
[data-bs-theme="dark"] .dyd-p-banner.success { background: #0c1f3a; color: #93c5fd; border-color: #1d4ed8; }

/* Nawigacja miesiąca */
.dyd-bi-nav {
  display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
  margin-bottom: 1rem;
}
.dyd-bi-nav-title { font-size: 1rem; font-weight: 700; flex: 1; }
.dyd-bi-nav-sub {
  font-size: .75rem; color: var(--bs-secondary-color);
  text-transform: uppercase; letter-spacing: .05em; margin-top: .1rem;
}

/* Stats */
.dyd-bi-stats { display: grid; grid-template-columns: repeat(2,1fr); gap: .65rem; margin-bottom: 1.1rem; }
@media (min-width: 640px) { .dyd-bi-stats { grid-template-columns: repeat(4,1fr); } }
.dyd-bi-stat {
  background: var(--bs-body-bg); border: 1px solid var(--bs-border-color);
  border-radius: 12px; padding: .9rem 1rem;
}
.dyd-bi-stat-icon { font-size: 1.3rem; margin-bottom: .4rem; opacity: .7; }
.dyd-bi-stat-value { font-size: 1.2rem; font-weight: 700; line-height: 1.2; margin-bottom: .15rem; }
.dyd-bi-stat-label {
  font-size: .72rem; font-weight: 600; text-transform: uppercase;
  letter-spacing: .06em; color: var(--bs-secondary-color);
}

/* Wiersz rozliczenia */
.dyd-bi-row {
  display: flex; align-items: flex-start; gap: .75rem; flex-wrap: wrap;
  padding: .75rem 1rem; border-bottom: 1px solid var(--bs-border-color);
}
.dyd-bi-row:last-child { border-bottom: none; }
.dyd-bi-row:hover { background: rgba(0,0,0,.02); }
[data-bs-theme="dark"] .dyd-bi-row:hover { background: rgba(255,255,255,.04); }

.dyd-bi-name { font-weight: 600; font-size: .9rem; flex: 1; min-width: 140px; }
.dyd-bi-course { font-size: .74rem; color: var(--bs-secondary-color); }

.dyd-bi-amounts {
  display: flex; gap: .8rem; font-variant-numeric: tabular-nums; font-size: .82rem;
  flex-wrap: wrap; align-items: center;
}
.dyd-bi-amounts .lbl { font-size: .65rem; text-transform: uppercase; letter-spacing: .05em; color: var(--bs-secondary-color); }
.dyd-bi-amounts .val { font-weight: 600; }

/* Badges */
.dyd-bi-badge {
  display: inline-flex; align-items: center; gap: .25rem;
  padding: .22em .6em; border-radius: 20px; font-size: .7rem; font-weight: 700;
}
.dyd-bi-badge.paid { background: rgba(34,197,94,.12); color: #16a34a; }
.dyd-bi-badge.part { background: rgba(251,191,36,.12); color: #b45309; }
.dyd-bi-badge.unpaid { background: rgba(239,68,68,.12); color: #dc2626; }
[data-bs-theme="dark"] .dyd-bi-badge.paid   { background: rgba(34,197,94,.18); color: #4ade80; }
[data-bs-theme="dark"] .dyd-bi-badge.part   { background: rgba(251,191,36,.18); color: #fbbf24; }
[data-bs-theme="dark"] .dyd-bi-badge.unpaid { background: rgba(239,68,68,.18); color: #f87171; }

/* Podgląd do wystawienia */
.dyd-bi-preview-row {
  display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
  padding: .65rem 1rem; border-bottom: 1px solid var(--bs-border-color);
  opacity: .8;
}
.dyd-bi-preview-row:last-child { border-bottom: none; }

.dyd-bi-empty {
  text-align: center; padding: 2.5rem 1rem;
  color: var(--bs-secondary-color); font-size: .875rem;
}
</style>

<?php if ($bi_flash): ?>
<div class="dyd-p-banner success" role="status">
  <i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i>
  <?= h($bi_flash['msg']) ?>
</div>
<?php endif; ?>

<?php if ($bi_debt_count > 0): ?>
<div class="dyd-p-banner warn" role="alert">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
  <div>
    <strong><?= $bi_debt_count === 1 ? '1 rozliczenie nie' : "$bi_debt_count rozliczeń nie" ?> jest opłacone</strong>
    w <?= h($bi_label) ?>.
    Łączna kwota wymagana: <strong><?= $bi_f($bi_total_amount - $bi_total_paid) ?> zł</strong>.
    <a href="../billing.php?month=<?= $bi_mon ?>&year=<?= $bi_year ?><?= $bi_course_id ? '&course_id='.$bi_course_id : '' ?>"
       class="alert-link" target="_blank" rel="noopener">Pełny panel rozliczeń →</a>
  </div>
</div>
<?php elseif ($bi_records && $bi_debt_count === 0): ?>
<div class="dyd-p-banner ok" role="status">
  <i class="bi bi-check-circle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
  <div>Wszystkie rozliczenia <?= h($bi_label) ?> są opłacone.</div>
</div>
<?php endif; ?>

<!-- Nawigacja -->
<div class="dyd-bi-nav">
  <div>
    <div class="dyd-bi-nav-title">
      <i class="bi bi-receipt text-primary me-2" aria-hidden="true"></i><?= h(ucfirst($bi_label)) ?>
    </div>
    <div class="dyd-bi-nav-sub">Rozliczenia miesięczne kursantów TI</div>
  </div>
  <div class="d-flex align-items-center gap-1 ms-auto flex-wrap">
    <?php if ($bi_courses): ?>
    <select class="form-select form-select-sm" style="width:auto"
            onchange="location='index.php?tab=billing&m=<?= h($bi_ym) ?>&course_id='+this.value"
            aria-label="Filtruj po kursie">
      <option value="0" <?= !$bi_course_id?'selected':'' ?>>Wszystkie kursy</option>
      <?php foreach ($bi_courses as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $bi_course_id===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <a href="index.php?tab=billing&m=<?= h($bi_prev) ?><?= $bi_course_id ? '&course_id='.$bi_course_id : '' ?>"
       class="btn btn-outline-secondary btn-sm" aria-label="Poprzedni miesiąc">
      <i class="bi bi-chevron-left" aria-hidden="true"></i>
    </a>
    <form method="get" class="d-flex">
      <input type="hidden" name="tab" value="billing">
      <?php if ($bi_course_id): ?><input type="hidden" name="course_id" value="<?= $bi_course_id ?>"><?php endif; ?>
      <input type="month" name="m" value="<?= h($bi_ym) ?>"
             class="form-control form-control-sm" style="width:auto"
             aria-label="Wybierz miesiąc" onchange="this.form.submit()">
    </form>
    <a href="index.php?tab=billing&m=<?= h($bi_next) ?><?= $bi_course_id ? '&course_id='.$bi_course_id : '' ?>"
       class="btn btn-outline-secondary btn-sm" aria-label="Następny miesiąc">
      <i class="bi bi-chevron-right" aria-hidden="true"></i>
    </a>
    <a href="../billing.php?month=<?= $bi_mon ?>&year=<?= $bi_year ?><?= $bi_course_id ? '&course_id='.$bi_course_id : '' ?>"
       class="btn btn-outline-secondary btn-sm ms-1" target="_blank" rel="noopener"
       title="Otwórz pełny panel rozliczeń (edycja, ręczne płatności, faktury)">
      <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
    </a>
  </div>
</div>

<!-- Stats -->
<div class="dyd-bi-stats" role="list">
  <div class="dyd-bi-stat" role="listitem">
    <div class="dyd-bi-stat-icon text-primary"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></div>
    <div class="dyd-bi-stat-value"><?= count($bi_records) ?></div>
    <div class="dyd-bi-stat-label">Rozliczeń</div>
  </div>
  <div class="dyd-bi-stat" role="listitem">
    <div class="dyd-bi-stat-icon text-warning"><i class="bi bi-cash-stack" aria-hidden="true"></i></div>
    <div class="dyd-bi-stat-value"><?= $bi_f($bi_total_amount) ?> zł</div>
    <div class="dyd-bi-stat-label">Należności</div>
  </div>
  <div class="dyd-bi-stat" role="listitem">
    <div class="dyd-bi-stat-icon text-success"><i class="bi bi-cash-coin" aria-hidden="true"></i></div>
    <div class="dyd-bi-stat-value"><?= $bi_f($bi_total_paid) ?> zł</div>
    <div class="dyd-bi-stat-label">Wpłacono</div>
  </div>
  <div class="dyd-bi-stat" role="listitem">
    <?php if ($bi_debt_count): ?>
    <div class="dyd-bi-stat-icon" style="color:#dc2626"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>
    <div class="dyd-bi-stat-value" style="color:#dc2626"><?= $bi_debt_count ?></div>
    <div class="dyd-bi-stat-label">Nieopłaconych</div>
    <?php else: ?>
    <div class="dyd-bi-stat-icon text-success"><i class="bi bi-check-circle" aria-hidden="true"></i></div>
    <div class="dyd-bi-stat-value text-success"><?= $bi_paid_count ?></div>
    <div class="dyd-bi-stat-label">Opłaconych</div>
    <?php endif; ?>
  </div>
</div>

<!-- Lista rozliczeń -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-transparent d-flex align-items-center gap-2 flex-wrap">
    <i class="bi bi-receipt text-primary" aria-hidden="true"></i>
    <span class="fw-semibold">Wystawione rozliczenia</span>
    <?php if ($bi_records): ?>
    <span class="badge bg-secondary ms-1 fw-normal" style="font-size:.72rem"><?= count($bi_records) ?></span>
    <?php endif; ?>
    <?php if ($bi_to_issue && $bi_can_write): ?>
    <form method="post" class="ms-auto"
          onsubmit="return confirm('Wystawić rozliczenia dla wszystkich kursantów z lekcjami w <?= h($bi_label) ?>?')">
      <input type="hidden" name="dyd_token" value="<?= dyd_token() ?>">
      <input type="hidden" name="_op" value="issue_all">
      <input type="hidden" name="month" value="<?= $bi_mon ?>">
      <input type="hidden" name="year"  value="<?= $bi_year ?>">
      <button class="btn btn-sm btn-warning py-1">
        <i class="bi bi-send me-1" aria-hidden="true"></i>
        Wystaw wszystkim (<?= count($bi_to_issue) ?>)
      </button>
    </form>
    <?php endif; ?>
  </div>

  <?php if (!$bi_records): ?>
  <div class="dyd-bi-empty">
    <i class="bi bi-receipt d-block mb-2 fs-3" style="opacity:.3" aria-hidden="true"></i>
    Brak wystawionych rozliczeń w <?= h($bi_label) ?>.
  </div>
  <?php else: ?>
  <div role="list" aria-label="Lista rozliczeń">
    <?php foreach ($bi_records as $b):
      $paid  = (float)$b['paid_amount'];
      $amt   = (float)$b['amount'] + (float)$b['adjustment'];
      $rest  = max(0, $amt - $paid);
      $ovr   = max(0, $paid - $amt);
      if ($paid >= $amt - 0.01)     $bi_status = 'paid';
      elseif ($paid > 0.01)         $bi_status = 'part';
      else                           $bi_status = 'unpaid';
      $bal   = $bi_balances[(int)$b['client_id']] ?? null;
      $has_stripe = !empty($bi_stripe[(int)$b['id']]);
      $has_payu   = !empty($bi_payu[(int)$b['id']]);
    ?>
    <div class="dyd-bi-row" role="listitem">
      <div style="min-width:0;flex:1">
        <div class="dyd-bi-name"><?= h($b['client_name']) ?></div>
        <?php if ((int)$b['course_id'] > 0): ?>
        <div class="dyd-bi-course">
          <i class="bi bi-mortarboard me-1 opacity-50" aria-hidden="true"></i>
          <?= h(db_one("SELECT name FROM k30_ti_courses WHERE id=?", [(int)$b['course_id']])['name'] ?? '—') ?>
        </div>
        <?php endif; ?>
        <?php if ($b['notes']): ?>
        <div class="dyd-bi-course"><i class="bi bi-chat-left-text me-1 opacity-50"></i><?= h($b['notes']) ?></div>
        <?php endif; ?>
      </div>

      <div class="dyd-bi-amounts">
        <div>
          <div class="lbl">Kwota</div>
          <div class="val"><?= $bi_f($amt) ?> zł</div>
        </div>
        <?php if ($paid > 0.01): ?>
        <div>
          <div class="lbl">Wpłacono</div>
          <div class="val" style="color:#16a34a"><?= $bi_f($paid) ?> zł</div>
        </div>
        <?php endif; ?>
        <?php if ($rest > 0.01): ?>
        <div>
          <div class="lbl">Brakuje</div>
          <div class="val" style="color:#dc2626"><?= $bi_f($rest) ?> zł</div>
        </div>
        <?php endif; ?>
        <?php if ($has_stripe || $has_payu): ?>
        <div style="font-size:.68rem;color:var(--bs-secondary-color)">
          <?php if ($has_stripe): ?><i class="bi bi-stripe" aria-hidden="true" title="Stripe"></i><?php endif; ?>
          <?php if ($has_payu):   ?><span title="PayU" style="font-weight:600">P</span><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($bi_status==='paid'): ?>
      <span class="dyd-bi-badge paid"><i class="bi bi-check2" aria-hidden="true"></i>Opłacone</span>
      <?php elseif ($bi_status==='part'): ?>
      <span class="dyd-bi-badge part"><i class="bi bi-hourglass-split" aria-hidden="true"></i>Częściowo</span>
      <?php else: ?>
      <span class="dyd-bi-badge unpaid"><i class="bi bi-dash-circle" aria-hidden="true"></i>Nieopłacone</span>
      <?php endif; ?>

      <a href="../student_billing.php?client_id=<?= (int)$b['client_id'] ?>"
         class="btn btn-sm btn-outline-secondary py-0 px-2 flex-shrink-0"
         target="_blank" rel="noopener" title="Szczegóły kursanta">
        <i class="bi bi-person-lines-fill" aria-hidden="true"></i>
      </a>
      <a href="../billing.php?month=<?= $bi_mon ?>&year=<?= $bi_year ?>&course_id=<?= (int)$b['course_id'] ?>"
         class="btn btn-sm btn-outline-primary py-0 px-2 flex-shrink-0"
         target="_blank" rel="noopener" title="Edytuj w pełnym panelu">
        <i class="bi bi-pencil" aria-hidden="true"></i>
      </a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($bi_to_issue): ?>
<!-- Podgląd kursantów do wystawienia -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent d-flex align-items-center gap-2">
    <i class="bi bi-clock-history text-warning" aria-hidden="true"></i>
    <span class="fw-semibold">Do wystawienia</span>
    <span class="badge bg-warning text-dark ms-1 fw-normal" style="font-size:.72rem"><?= count($bi_to_issue) ?></span>
    <span class="text-body-secondary small ms-2">Kursanci z lekcjami bez rozliczenia</span>
  </div>
  <div role="list" aria-label="Kursanci do wystawienia rozliczenia">
    <?php foreach ($bi_to_issue as $cid => $calc): ?>
    <div class="dyd-bi-preview-row" role="listitem">
      <span class="dyd-bi-name flex-grow-1"><?= h($calc['client_name']) ?></span>
      <div class="dyd-bi-amounts">
        <div><div class="lbl">Godz.</div><div class="val"><?= number_format($calc['hours_billed'],1,',','') ?></div></div>
        <div><div class="lbl">Kwota</div><div class="val"><?= $bi_f($calc['amount']) ?> zł</div></div>
      </div>
      <?php if ($bi_can_write): ?>
      <form method="post" class="flex-shrink-0">
        <input type="hidden" name="dyd_token" value="<?= dyd_token() ?>">
        <input type="hidden" name="_op" value="issue">
        <input type="hidden" name="client_id" value="<?= $cid ?>">
        <input type="hidden" name="month" value="<?= $bi_mon ?>">
        <input type="hidden" name="year"  value="<?= $bi_year ?>">
        <button class="btn btn-sm btn-outline-primary py-0 px-2" title="Wystaw rozliczenie">
          <i class="bi bi-send" aria-hidden="true"></i>
        </button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

</section>
