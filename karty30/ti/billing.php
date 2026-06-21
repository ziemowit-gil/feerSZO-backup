<?php
/**
 * karty30/ti/billing.php — Miesięczne rozliczenia zajęć TI.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/stripe.php';

k30_require_access();
karty30_migrate();
stripe_migrate();

$PAGE_TITLE = 'Rozliczenia TI';
$can_write  = can_write('karty30') || is_admin();
$can_delete = is_admin(); // usuwanie rozliczeń — tylko administrator (globalnie)
$course_id  = (int)($_GET['course_id'] ?? 0);
$course     = $course_id ? k30_ti_course_get($course_id) : null;

// Filtr okresu
$month = (int)($_GET['month'] ?? date('m'));
$year  = (int)($_GET['year']  ?? date('Y'));
$month = max(1, min(12, $month));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'issue') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $notes     = trim($_POST['notes'] ?? '');
        if ($client_id) {
            $bid = k30_ti_issue_billing($client_id, $month, $year, $notes);
            $n   = k30_ti_billing_notify($bid);
            $extra = '';
            if (!empty($n['ok'])) {
                $parts = [];
                if (!empty($n['sms']))   $parts[] = 'SMS';
                if (!empty($n['email'])) $parts[] = 'e-mail';
                $extra = $parts ? ' Wysłano: ' . implode(' i ', $parts) . '.' : ' (brak danych kontaktowych do powiadomienia).';
            }
            flash_set('success', 'Rozliczenie wystawione.' . $extra);
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    if ($op === 'issue_all') {
        $clients_with_sessions = db_all(
            "SELECT DISTINCT e.client_id FROM k30_ti_attendance a
             JOIN k30_ti_sessions s ON s.id=a.session_id AND s.status='held'
             JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=a.client_id
             WHERE a.attended=1 AND strftime('%m',s.lesson_date)=? AND strftime('%Y',s.lesson_date)=?",
            [sprintf('%02d',$month), (string)$year]
        );
        $sms = 0; $eml = 0;
        foreach ($clients_with_sessions as $c) {
            $bid = k30_ti_issue_billing((int)$c['client_id'], $month, $year);
            $n   = k30_ti_billing_notify($bid);
            if (!empty($n['sms']))   $sms++;
            if (!empty($n['email'])) $eml++;
        }
        flash_set('success', 'Wystawiono ' . count($clients_with_sessions) . ' rozliczeń. Powiadomienia: SMS ' . $sms . ', e-mail ' . $eml . '.');
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Ponowne wysłanie powiadomienia o rozliczeniu (SMS + e-mail)
    if ($op === 'notify') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        if ($bid) {
            $n = k30_ti_billing_notify($bid, true);
            if (!empty($n['ok'])) {
                $parts = [];
                if (!empty($n['sms']))   $parts[] = 'SMS';
                if (!empty($n['email'])) $parts[] = 'e-mail';
                flash_set('success', $parts ? 'Wysłano powiadomienie: ' . implode(' i ', $parts) . '.' : 'Brak danych kontaktowych (telefon/e-mail).');
            } else {
                flash_set('danger', $n['msg'] ?? 'Nie udało się wysłać powiadomienia.');
            }
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    if ($op === 'set_paid') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        if ($bid) db()->prepare("UPDATE k30_ti_billing SET status='paid' WHERE id=?")->execute([$bid]);
        flash_set('success','Oznaczono jako opłacone.');
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Wygeneruj link do zapłaty Stripe dla rozliczenia
    if ($op === 'stripe_link') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $b   = $bid ? db_one("SELECT b.*, cl.name AS client_name, cl.email AS client_email FROM k30_ti_billing b JOIN k30_clients cl ON cl.id=b.client_id WHERE b.id=?", [$bid]) : null;
        if (!$b) { flash_set('danger','Nie znaleziono rozliczenia.'); }
        elseif (!stripe_enabled()) { flash_set('danger','Płatności Stripe nie są skonfigurowane (Administracja → Płatności / Stripe).'); }
        else {
            $amount = (float)$b['amount'] + (float)($b['adjustment'] ?? 0);
            $back   = rtrim(APP_URL,'/') . '/karty30/ti/billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'');
            try {
                $r = stripe_create_checkout(
                    'k30_ti_billing', $bid, $amount,
                    'Zajęcia TI — ' . ($b['client_name'] ?? '') . ' (' . $month . '/' . $year . ')',
                    $back . '&paid=1', $back,
                    (string)($b['client_email'] ?? '')
                );
                flash_set('success', 'Link do zapłaty utworzony: ' . $r['url']);
            } catch (\Throwable $e) {
                flash_set('danger', 'Stripe: ' . $e->getMessage());
            }
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Korekta rozliczenia — opłata dodatkowa (+) lub rabat (−)
    if ($op === 'set_adjustment') {
        $bid  = (int)($_POST['billing_id'] ?? 0);
        $kind = ($_POST['adj_kind'] ?? 'fee') === 'discount' ? 'discount' : 'fee';
        $val  = (float)str_replace(',', '.', (string)($_POST['adj_value'] ?? '0'));
        $val  = abs($val);
        $adj  = $kind === 'discount' ? -$val : $val;
        $note = trim($_POST['adj_note'] ?? '');
        if ($bid) {
            db()->prepare("UPDATE k30_ti_billing SET adjustment=?, adjustment_note=? WHERE id=?")
               ->execute([$adj, $note, $bid]);
            flash_set('success', $adj == 0 ? 'Korekta usunięta.' : 'Korekta zapisana.');
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Miękkie usuwanie rozliczenia (status='cancelled') — tylko admin
    if ($op === 'delete') {
        if (!$can_delete) { http_response_code(403); die('Brak uprawnień.'); }
        $bid = (int)($_POST['billing_id'] ?? 0);
        if ($bid) db()->prepare("UPDATE k30_ti_billing SET status='cancelled' WHERE id=?")->execute([$bid]);
        flash_set('success','Rozliczenie usunięte.');
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }
}

// Pobierz rozliczenia za wybrany miesiąc
$where = ["b.month=? AND b.year=?", "b.status!='cancelled'"]; $params = [$month, $year];
if ($course_id) {
    // Filtruj per kurs — klienci w tym kursie
    $where[] = "b.client_id IN (SELECT client_id FROM k30_ti_enrollments WHERE course_id=?)";
    $params[] = $course_id;
}
$billings = db_all(
    "SELECT b.*, cl.name AS client_name, cl.email AS client_email
     FROM k30_ti_billing b
     JOIN k30_clients cl ON cl.id=b.client_id
     WHERE " . implode(' AND ', $where) . " ORDER BY cl.name",
    $params
);

// Podgląd nieopłaconych (kalkulacja bez zapisu)
$preview_where = []; $prev_params = [];
if ($course_id) {
    $preview_where[] = "e.course_id=?";
    $prev_params[] = $course_id;
}
$pq = $preview_where ? 'AND ' . implode(' AND ', $preview_where) : '';
$enrolled_active = db_all(
    "SELECT DISTINCT e.client_id, cl.name AS client_name
     FROM k30_ti_enrollments e
     JOIN k30_clients cl ON cl.id=e.client_id
     WHERE e.status='active' $pq ORDER BY cl.name",
    $prev_params
);
$billed_ids = array_column($billings, 'client_id');

// Mapa płatności Stripe dla wyświetlanych rozliczeń (source_type=k30_ti_billing)
$stripe_pay = [];
$bids = array_column($billings, 'id');
if ($bids) {
    $in = implode(',', array_fill(0, count($bids), '?'));
    foreach (db_all("SELECT * FROM stripe_payments WHERE source_type='k30_ti_billing' AND source_id IN ($in) ORDER BY id", $bids) as $sp) {
        $stripe_pay[(int)$sp['source_id']] = $sp; // ostatni wygrywa
    }
}
$stripe_on = stripe_enabled();

// Podgląd kwot dla nieopłaconych
$preview = [];
foreach ($enrolled_active as $e) {
    $cid = (int)$e['client_id'];
    if (in_array($cid, $billed_ids)) continue;
    $calc = k30_ti_calculate_billing($cid, $month, $year);
    if ($calc['hours_billed'] > 0 || $calc['amount'] > 0) {
        $preview[$cid] = array_merge($calc, ['client_name' => $e['client_name']]);
    }
}

// Miesiące do nawigacji
$months_pl = [1=>'Styczeń',2=>'Luty',3=>'Marzec',4=>'Kwiecień',5=>'Maj',6=>'Czerwiec',
              7=>'Lipiec',8=>'Sierpień',9=>'Wrzesień',10=>'Październik',11=>'Listopad',12=>'Grudzień'];

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <?php if ($course): ?>
  <li class="breadcrumb-item"><a href="course.php?id=<?= $course_id ?>"><?= h($course['name']) ?></a></li>
  <?php endif; ?>
  <li class="breadcrumb-item active">Rozliczenia miesięczne</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-receipt text-primary me-2"></i>
    Rozliczenia TI<?= $course ? ' — '.h($course['name']) : '' ?>
  </h4>
</div>

<?= flash_html() ?>

<?php if (isset($_GET['paid'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="bi bi-check-circle me-1"></i>Dziękujemy — płatność została zainicjowana. Status zaktualizuje się po potwierdzeniu przez Stripe (webhook).
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Nawigacja miesięczna -->
<div class="d-flex align-items-center gap-2 mb-4">
  <?php
    $prev_m = $month === 1 ? 12 : $month - 1;
    $prev_y = $month === 1 ? $year - 1 : $year;
    $next_m = $month === 12 ? 1 : $month + 1;
    $next_y = $month === 12 ? $year + 1 : $year;
    $base   = '?'.($course_id?'course_id='.$course_id.'&':'');
  ?>
  <a href="<?= $base ?>month=<?= $prev_m ?>&year=<?= $prev_y ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-chevron-left"></i>
  </a>
  <h5 class="mb-0 fw-bold"><?= $months_pl[$month] ?> <?= $year ?></h5>
  <a href="<?= $base ?>month=<?= $next_m ?>&year=<?= $next_y ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-chevron-right"></i>
  </a>
  <?php if ($preview && $can_write): ?>
  <form method="post" class="ms-auto" onsubmit="return confirm('Wystawić rozliczenia dla wszystkich klientów z lekcjami w tym miesiącu?')">
    <input type="hidden" name="_csrf"  value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_op"    value="issue_all">
    <button type="submit" class="btn btn-primary btn-sm">
      <i class="bi bi-receipt-cutoff me-1"></i>Wystaw wszystkie (<?= count($preview) ?>)
    </button>
  </form>
  <?php endif; ?>
</div>

<!-- Wystawione rozliczenia -->
<?php if ($billings): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex align-items-center">
    <i class="bi bi-check-circle text-success me-2"></i>Wystawione rozliczenia
    <span class="badge bg-secondary ms-2"><?= count($billings) ?></span>
    <span class="ms-auto text-muted small fw-normal">
      Razem: <?= number_format(array_sum(array_map(fn($b)=>(float)$b['amount']+(float)($b['adjustment']??0),$billings)),2,',','') ?> zł
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Klient</th><th>Godz.</th><th>Korekta</th><th>Do zapłaty</th><th>Status</th><th class="text-end">Akcje</th></tr>
      </thead>
      <tbody>
        <?php foreach ($billings as $b):
          $bs   = K30_TI_BILLING_STATUSES[$b['status']] ?? ['label'=>$b['status'],'color'=>'#666','bg'=>'#eee'];
          $adj  = (float)($b['adjustment'] ?? 0);
          $base = (float)$b['amount'];
          $tot  = $base + $adj;
        ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= h($b['client_name']) ?></div>
            <?php $bpay = k30_ti_client_payment((int)$b['client_id']); ?>
            <?php if ($bpay['codes']): ?>
            <div class="small">
              <?php foreach ($bpay['codes'] as $code): ?>
              <span class="badge <?= $code===9999 ? 'bg-warning text-dark' : 'bg-light text-secondary border' ?>" title="Kod modelu rozliczania"><?= $code===9999 ? '9999 · indyw.' : 'kod '.(int)$code ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($bpay['account']) || !empty($bpay['title'])): ?>
            <div class="text-muted" style="font-size:.72rem"><i class="bi bi-bank me-1"></i><?= h($bpay['account'] ?: '—') ?><?php if (!empty($bpay['title'])): ?> · „<?= h($bpay['title']) ?>"<?php endif; ?></div>
            <?php endif; ?>
            <?php if ($b['notes']): ?><div class="text-muted small"><?= h($b['notes']) ?></div><?php endif; ?>
          </td>
          <td><?= number_format((float)$b['hours_billed'],2,',','') ?> h<br>
            <span class="text-muted small"><?= number_format($base,2,',','') ?> zł</span>
          </td>
          <td>
            <?php if ($adj != 0): ?>
              <span class="fw-semibold <?= $adj > 0 ? 'text-danger' : 'text-success' ?>">
                <?= ($adj > 0 ? '+' : '−') . number_format(abs($adj),2,',','') ?> zł
              </span>
              <div class="text-muted small"><?= $adj > 0 ? 'opłata dod.' : 'rabat' ?><?= $b['adjustment_note'] ? ': '.h($b['adjustment_note']) : '' ?></div>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="fw-bold"><?= number_format($tot,2,',','') ?> zł</td>
          <td>
            <span class="badge" style="background:<?= h($bs['bg']) ?>;color:<?= h($bs['color']) ?>;border:1px solid <?= h($bs['color']) ?>44;font-size:.74rem">
              <?= h($bs['label']) ?>
            </span>
          </td>
          <td class="text-end text-nowrap">
            <?php if ($can_write): ?>
            <button type="button" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2" title="Opłata dodatkowa / rabat"
                    data-bs-toggle="collapse" data-bs-target="#adj<?= (int)$b['id'] ?>">
              <i class="bi bi-percent"></i>
            </button>
            <?php endif; ?>
            <?php if ($b['status'] === 'issued' && $can_write):
              $sp = $stripe_pay[(int)$b['id']] ?? null; ?>
            <?php if ($stripe_on && $sp && $sp['status'] === 'pending' && !empty($sp['checkout_url'])): ?>
            <a href="<?= h($sp['checkout_url']) ?>" target="_blank" rel="noopener"
               class="btn btn-xs btn-sm btn-outline-primary py-0 px-2" title="Otwórz link do zapłaty Stripe">
              <i class="bi bi-link-45deg me-1"></i>Link
            </a>
            <?php elseif ($stripe_on): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"         value="stripe_link">
              <input type="hidden" name="billing_id"  value="<?= (int)$b['id'] ?>">
              <button type="submit" class="btn btn-xs btn-sm btn-outline-primary py-0 px-2" title="Wygeneruj link do zapłaty Stripe">
                <i class="bi bi-credit-card me-1"></i>Stripe
              </button>
            </form>
            <?php endif; ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"         value="set_paid">
              <input type="hidden" name="billing_id"  value="<?= (int)$b['id'] ?>">
              <button type="submit" class="btn btn-xs btn-sm btn-outline-success py-0 px-2">
                <i class="bi bi-check-lg me-1"></i>Opłacone
              </button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('Wysłać powiadomienie (SMS + e-mail) o tym rozliczeniu?')">
              <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"         value="notify">
              <input type="hidden" name="billing_id"  value="<?= (int)$b['id'] ?>">
              <button type="submit" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2"
                      title="<?= !empty($b['notified_at']) ? 'Powiadomiono '.h(substr($b['notified_at'],0,16)).' — wyślij ponownie' : 'Wyślij powiadomienie SMS + e-mail' ?>">
                <i class="bi bi-send<?= !empty($b['notified_at']) ? '-check' : '' ?>"></i>
              </button>
            </form>
            <?php endif; ?>
            <?php if ($can_delete): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć rozliczenie dla „<?= h(addslashes($b['client_name'])) ?>”?')">
              <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"         value="delete">
              <input type="hidden" name="billing_id"  value="<?= (int)$b['id'] ?>">
              <button type="submit" class="btn btn-xs btn-sm btn-outline-danger py-0 px-2" title="Usuń rozliczenie">
                <i class="bi bi-trash"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($can_write): ?>
        <tr class="collapse" id="adj<?= (int)$b['id'] ?>">
          <td colspan="6" class="bg-light">
            <form method="post" class="row g-2 align-items-end">
              <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"        value="set_adjustment">
              <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
              <div class="col-auto">
                <label class="form-label small mb-0">Rodzaj</label>
                <select name="adj_kind" class="form-select form-select-sm">
                  <option value="fee"      <?= $adj > 0 ? 'selected' : '' ?>>Opłata dodatkowa (+)</option>
                  <option value="discount" <?= $adj < 0 ? 'selected' : '' ?>>Rabat (−)</option>
                </select>
              </div>
              <div class="col-auto">
                <label class="form-label small mb-0">Kwota (zł)</label>
                <input type="text" name="adj_value" class="form-control form-control-sm" style="max-width:120px"
                       value="<?= $adj != 0 ? number_format(abs($adj),2,',','') : '' ?>" placeholder="0,00">
              </div>
              <div class="col">
                <label class="form-label small mb-0">Opis (opcjonalnie)</label>
                <input type="text" name="adj_note" class="form-control form-control-sm"
                       value="<?= h($b['adjustment_note'] ?? '') ?>" placeholder="np. materiały, rabat lojalnościowy">
              </div>
              <div class="col-auto">
                <button class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Zapisz</button>
              </div>
              <div class="form-text">Wpisz 0, aby usunąć korektę.</div>
            </form>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Podgląd — klienci z lekcjami bez rozliczenia -->
<?php if ($preview): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header fw-semibold d-flex align-items-center">
    <i class="bi bi-hourglass-split text-warning me-2"></i>Do wystawienia
    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-2"><?= count($preview) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Klient</th><th>Godz. obecności</th><th>Szacowana kwota</th><th class="text-end">Akcja</th></tr>
      </thead>
      <tbody>
        <?php foreach ($preview as $cid => $p): ?>
        <tr>
          <td class="fw-semibold"><?= h($p['client_name']) ?></td>
          <td><?= number_format($p['hours_billed'],2,',','') ?> h</td>
          <td class="fw-semibold"><?= number_format($p['amount'],2,',','') ?> zł</td>
          <td class="text-end">
            <?php if ($can_write): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"        value="issue">
              <input type="hidden" name="client_id"  value="<?= $cid ?>">
              <button type="submit" class="btn btn-xs btn-sm btn-outline-primary py-0 px-2">
                <i class="bi bi-receipt me-1"></i>Wystaw
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php elseif (!$billings): ?>
<div class="alert alert-info">Brak lekcji odbyłych w <?= $months_pl[$month] ?> <?= $year ?>.</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
