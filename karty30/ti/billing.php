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
require_once dirname(dirname(__DIR__)) . '/includes/payu.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_payments.php';

k30_require_access();
karty30_migrate();
stripe_migrate();
payu_migrate();
ti_payments_migrate();

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
            $bids  = k30_ti_issue_billing_split($client_id, $month, $year, $notes);
            ti_billing_recompute($client_id);
            $sms_c = 0; $eml_c = 0;
            foreach ($bids as $bid) {
                $n = k30_ti_billing_notify($bid);
                if (!empty($n['sms']))   $sms_c++;
                if (!empty($n['email'])) $eml_c++;
            }
            $cnt   = count($bids);
            $extra = ($sms_c || $eml_c)
                ? ' Wysłano: ' . ($sms_c ? "SMS ({$sms_c})" : '') . ($sms_c && $eml_c ? ' i ' : '') . ($eml_c ? "e-mail ({$eml_c})" : '') . '.'
                : ' (brak danych kontaktowych do powiadomień).';
            flash_set('success', ($cnt > 1 ? "Wystawiono {$cnt} rozliczeń (osobno per kurs)." : 'Rozliczenie wystawione.') . $extra);
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    if ($op === 'issue_all') {
        $clients_with_sessions = db_all(
            "SELECT DISTINCT e.client_id FROM k30_ti_attendance a
             JOIN k30_ti_sessions s ON s.id=a.session_id AND s.status IN ('held','individual_change','remote_material')
             JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=a.client_id
             WHERE a.attended=1 AND strftime('%m',s.lesson_date)=? AND strftime('%Y',s.lesson_date)=?",
            [sprintf('%02d',$month), (string)$year]
        );
        $sms = 0; $eml = 0; $bill_cnt = 0;
        foreach ($clients_with_sessions as $c) {
            $bids = k30_ti_issue_billing_split((int)$c['client_id'], $month, $year);
            ti_billing_recompute((int)$c['client_id']);
            $bill_cnt += count($bids);
            foreach ($bids as $bid) {
                $n = k30_ti_billing_notify($bid);
                if (!empty($n['sms']))   $sms++;
                if (!empty($n['email'])) $eml++;
            }
        }
        flash_set('success', "Wystawiono {$bill_cnt} rozliczeń (dla " . count($clients_with_sessions) . " kursantów). Powiadomienia: SMS {$sms}, e-mail {$eml}.");
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

    // „Opłacone" = zarejestruj wpłatę na pozostałą do zapłaty kwotę (księga + saldo)
    if ($op === 'set_paid') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $b   = $bid ? db_one("SELECT * FROM k30_ti_billing WHERE id=?", [$bid]) : null;
        if ($b) {
            $due       = (float)$b['amount'] + (float)($b['adjustment'] ?? 0);
            $remaining = round($due - (float)($b['paid_amount'] ?? 0), 2);
            if ($remaining > 0) {
                $r = ti_payment_add((int)$b['client_id'], $remaining, date('Y-m-d'), 'manual', 'Oznaczono jako opłacone (rozl. '.$b['month'].'/'.$b['year'].')');
                flash_set('success', 'Zarejestrowano wpłatę ' . number_format($remaining,2,',',' ') . ' zł.' . (!empty($r['emailed']) ? ' Wysłano e-mail.' : ''));
            } else {
                flash_set('info', 'Rozliczenie jest już w pełni pokryte.');
            }
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Ręczna wpłata (dowolna kwota) — może utworzyć nadpłatę
    if ($op === 'add_payment') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $amount    = round((float)str_replace(',', '.', (string)($_POST['amount'] ?? '0')), 2);
        $paid_at   = trim($_POST['paid_at'] ?? '');
        $method    = in_array($_POST['method'] ?? '', ['transfer','cash','stripe','payu','other'], true) ? $_POST['method'] : 'transfer';
        $note      = trim($_POST['note'] ?? '');
        if ($client_id && $amount > 0) {
            $r = ti_payment_add($client_id, $amount, $paid_at, $method, $note);
            $msg = 'Wpłata ' . number_format($amount,2,',',' ') . ' zł zapisana.';
            if ($r['credit'] > 0) $msg .= ' Nadpłata: ' . number_format($r['credit'],2,',',' ') . ' zł' . (!empty($r['emailed']) ? ' (wysłano e-mail).' : '.');
            flash_set('success', $msg);
        } else {
            flash_set('danger', 'Podaj kursanta i kwotę wpłaty.');
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Usunięcie wpłaty (korekta)
    if ($op === 'del_payment') {
        ti_payment_delete((int)($_POST['payment_id'] ?? 0));
        flash_set('success', 'Wpłata usunięta, saldo przeliczone.');
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

    // Wygeneruj link do zapłaty PayU dla rozliczenia
    if ($op === 'payu_link') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $b   = $bid ? db_one("SELECT b.*, cl.name AS client_name, cl.email AS client_email FROM k30_ti_billing b JOIN k30_clients cl ON cl.id=b.client_id WHERE b.id=?", [$bid]) : null;
        if (!$b) { flash_set('danger','Nie znaleziono rozliczenia.'); }
        elseif (!payu_enabled()) { flash_set('danger','Płatności PayU nie są skonfigurowane (Integracje → Płatności / PayU).'); }
        else {
            $amount = (float)$b['amount'] + (float)($b['adjustment'] ?? 0);
            $back   = rtrim(APP_URL,'/') . '/karty30/ti/billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'');
            $notify = rtrim(APP_URL,'/') . '/api/payu_webhook.php';
            try {
                $r = payu_create_order(
                    'k30_ti_billing', $bid, $amount,
                    'Zajęcia TI — ' . ($b['client_name'] ?? '') . ' (' . $month . '/' . $year . ')',
                    $back . '&paid=1', $notify,
                    (string)($b['client_email'] ?? '')
                );
                flash_set('success', 'Link do zapłaty PayU utworzony: ' . $r['url']);
            } catch (\Throwable $e) {
                flash_set('danger', 'PayU: ' . $e->getMessage());
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

    // Indywidualny termin płatności dla pojedynczego rozliczenia
    if ($op === 'set_due') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $due = trim($_POST['due_date'] ?? '');
        // Walidacja formatu YYYY-MM-DD (puste = wyczyść termin)
        $val = ($due !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) ? $due : null;
        if ($bid) {
            db()->prepare("UPDATE k30_ti_billing SET due_date=? WHERE id=?")->execute([$val, $bid]);
            flash_set('success', $val ? 'Termin płatności zapisany.' : 'Termin płatności wyczyszczony.');
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Płatnik rozliczenia (beneficjent|rodzic|pfron|feer) + opcjonalna nazwa
    if ($op === 'set_payer') {
        $bid  = (int)($_POST['billing_id'] ?? 0);
        $type = (string)($_POST['payer_type'] ?? '');
        if (!isset(K30_TI_PAYERS[$type])) $type = '';
        $name = mb_substr(trim((string)($_POST['payer_name'] ?? '')), 0, 200);
        if ($bid) {
            db()->prepare("UPDATE k30_ti_billing SET payer_type=?, payer_name=? WHERE id=?")
               ->execute([$type, $name, $bid]);
            flash_set('success', 'Płatnik zapisany.');
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Dodanie / wymiana faktury (FVAT) — plik PDF dołączany do rozliczenia
    if ($op === 'upload_invoice') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $b   = $bid ? db_one("SELECT * FROM k30_ti_billing WHERE id=?", [$bid]) : null;
        if (!$b) { flash_set('danger','Nie znaleziono rozliczenia.'); }
        else {
            try {
                $up = k30_ti_invoice_upload('invoice', 'fv' . $bid);
                if ($up) {
                    if (!empty($b['invoice_path'])) k30_ti_invoice_delete_file($b['invoice_path']);
                    db()->prepare("UPDATE k30_ti_billing SET invoice_path=?, invoice_name=?, invoice_at=datetime('now') WHERE id=?")
                       ->execute([$up['stored'], $up['name'], $bid]);
                    flash_set('success', 'Faktura dodana.');
                } else {
                    flash_set('danger', 'Nie wybrano pliku faktury (PDF).');
                }
            } catch (\Throwable $e) { flash_set('danger', $e->getMessage()); }
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Usunięcie faktury z rozliczenia
    if ($op === 'delete_invoice') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $b   = $bid ? db_one("SELECT invoice_path FROM k30_ti_billing WHERE id=?", [$bid]) : null;
        if ($b) {
            if (!empty($b['invoice_path'])) k30_ti_invoice_delete_file($b['invoice_path']);
            db()->prepare("UPDATE k30_ti_billing SET invoice_path='', invoice_name='', invoice_at=NULL WHERE id=?")->execute([$bid]);
            flash_set('success', 'Faktura usunięta.');
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

// Saldo (nadpłata/niedopłata) per kursant — dla wyświetlanych rozliczeń
$balances = [];
foreach (array_unique($billed_ids) as $bcid) { $balances[(int)$bcid] = ti_client_balance((int)$bcid); }

// Rozbicie kosztów per kurs dla wystawionych rozliczeń łącznych (course_id=0).
// Dla rozliczeń per kurs (course_id>0) wystarczy nazwa kursu z bazy.
$billing_courses = [];
foreach ($billings as $b) {
    if ((int)$b['course_id'] === 0) {
        $calc = k30_ti_calculate_billing((int)$b['client_id'], (int)$b['month'], (int)$b['year']);
        if (!empty($calc['courses'])) $billing_courses[(int)$b['id']] = $calc['courses'];
    }
}

// Mapa nazw kursów dla rozliczeń per-kurs
$billing_course_names = [];
foreach ($billings as $b) {
    if ((int)$b['course_id'] > 0) {
        $cn = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [(int)$b['course_id']]);
        $billing_course_names[(int)$b['id']] = $cn['name'] ?? '?';
    }
}

// Kursanci z niedopłatą (globalnie) — flaga dla panelu admina
$debtors = ti_clients_with_debt();

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

// Mapa płatności PayU dla wyświetlanych rozliczeń
$payu_pay = [];
if ($bids) {
    $in = implode(',', array_fill(0, count($bids), '?'));
    foreach (db_all("SELECT * FROM payu_payments WHERE source_type='k30_ti_billing' AND source_id IN ($in) ORDER BY id", $bids) as $pp) {
        $payu_pay[(int)$pp['source_id']] = $pp; // ostatni wygrywa
    }
}
$payu_on = payu_enabled();

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
  <i class="bi bi-check-circle me-1"></i>Dziękujemy — płatność została zainicjowana. Status zaktualizuje się po potwierdzeniu przez operatora płatności (webhook).
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

<!-- Niedopłaty (flaga dla admina) -->
<?php if ($debtors): ?>
<div class="card border-0 shadow-sm mb-4 border-start border-danger border-4">
  <div class="card-header fw-semibold d-flex align-items-center bg-danger-subtle text-danger-emphasis">
    <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>Niedopłaty
    <span class="badge bg-danger ms-2"><?= count($debtors) ?></span>
    <span class="ms-auto small fw-normal">Łącznie brakuje: <?= number_format(array_sum(array_map(fn($d)=>(float)$d['debt'],$debtors)),2,',',' ') ?> zł</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
      <caption class="visually-hidden">Kursanci z niedopłatą</caption>
      <thead class="table-light"><tr><th>Kursant</th><th>Należności</th><th>Wpłacono</th><th>Brakuje</th></tr></thead>
      <tbody>
        <?php foreach ($debtors as $d): ?>
        <tr>
          <td class="fw-semibold"><?= h($d['client_name']) ?></td>
          <td><?= number_format((float)$d['charges'],2,',',' ') ?> zł</td>
          <td><?= number_format((float)$d['paid'],2,',',' ') ?> zł</td>
          <td class="fw-bold text-danger"><?= number_format((float)$d['debt'],2,',',' ') ?> zł</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

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
        <tr><th>Klient</th><th>Godz.</th><th>Korekta</th><th>Do zapłaty</th><th>Termin</th><th>Status</th><th class="text-end">Akcje</th></tr>
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
            <div class="fw-semibold">
              <a href="student_billing.php?client_id=<?= (int)$b['client_id'] ?>" class="text-decoration-none link-body-emphasis"
                 title="Zestawienie płatności kursanta"><?= h($b['client_name']) ?></a>
            </div>
            <?php if ((int)$b['course_id'] > 0): ?>
            <div class="mt-1">
              <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle" style="font-size:.75rem">
                <i class="bi bi-mortarboard me-1"></i><?= h($billing_course_names[(int)$b['id']] ?? '?') ?>
              </span>
            </div>
            <?php else: $bc = $billing_courses[(int)$b['id']] ?? []; ?>
            <?php if (count($bc) > 1): ?>
            <div class="mt-1">
              <?php foreach ($bc as $bcc): if ($bcc['amount'] <= 0 && $bcc['hours_billed'] <= 0) continue; ?>
              <span class="badge bg-light text-secondary border me-1" style="font-size:.72rem;font-weight:500">
                <?= h($bcc['course_name']) ?>:
                <?php if ($bcc['model'] !== 2): ?>
                  <?= number_format($bcc['amount'],2,',','') ?> zł
                <?php else: ?>
                  <?= number_format($bcc['hours_billed'],2,',','') ?> h · <?= number_format($bcc['amount'],2,',','') ?> zł
                <?php endif; ?>
              </span>
              <?php endforeach; ?>
            </div>
            <?php elseif (!empty($bc)): ?>
            <div class="text-muted" style="font-size:.78rem"><?= h($bc[0]['course_name'] ?? '') ?></div>
            <?php endif; ?>
            <?php endif; ?>
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
            <?php $bbal = $balances[(int)$b['client_id']] ?? null; if ($bbal && $bbal['credit'] > 0.005): ?>
            <div class="small mt-1"><span class="badge bg-success-subtle text-success-emphasis border border-success-subtle" title="Nadpłata zostanie użyta na kolejne zajęcia"><i class="bi bi-piggy-bank me-1"></i>nadpłata <?= number_format($bbal['credit'],2,',',' ') ?> zł</span></div>
            <?php elseif ($bbal && $bbal['debt'] > 0.005): ?>
            <div class="small mt-1"><span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle" title="Saldo ujemne kursanta"><i class="bi bi-exclamation-triangle me-1"></i>niedopłata <?= number_format($bbal['debt'],2,',',' ') ?> zł</span></div>
            <?php endif; ?>
            <div class="text-muted" style="font-size:.72rem">
              <i class="bi bi-person-badge me-1"></i>Płatnik: <?= h(k30_ti_billing_payer_label($b)) ?>
            </div>
            <?php if (!empty($b['invoice_path'])): ?>
            <div style="font-size:.72rem">
              <i class="bi bi-file-earmark-pdf text-danger me-1"></i>
              <a href="billing_invoice.php?id=<?= (int)$b['id'] ?>" target="_blank" rel="noopener">Faktura<?= !empty($b['invoice_at']) ? ' ('.h(substr($b['invoice_at'],0,10)).')' : '' ?></a>
            </div>
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
          <?php $paid = (float)($b['paid_amount'] ?? 0); $rem = round($tot - $paid, 2); ?>
          <td class="fw-bold">
            <?= number_format($tot,2,',','') ?> zł
            <?php if ($paid > 0.005 && $rem > 0.005): ?>
            <div class="small text-danger fw-normal">wpłacono <?= number_format($paid,2,',',' ') ?> · brakuje <?= number_format($rem,2,',',' ') ?> zł</div>
            <?php elseif ($paid > 0.005): ?>
            <div class="small text-success fw-normal">pokryte</div>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($b['due_date'])):
              $overdue = $b['status'] !== 'paid' && $b['due_date'] < date('Y-m-d'); ?>
              <span class="<?= $overdue ? 'text-danger fw-semibold' : 'text-body-secondary' ?>" <?= $overdue ? 'title="Po terminie"' : '' ?>>
                <?= date('d.m.Y', strtotime($b['due_date'])) ?><?php if ($overdue): ?> <i class="bi bi-exclamation-triangle-fill"></i><?php endif; ?>
              </span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge" style="background:<?= h($bs['bg']) ?>;color:<?= h($bs['color']) ?>;border:1px solid <?= h($bs['color']) ?>44;font-size:.74rem">
              <?= h($bs['label']) ?>
            </span>
            <?php if ($b['status'] !== 'paid' && $rem > 0.005 && $paid > 0.005): ?>
            <span class="badge bg-danger ms-1" title="Częściowo opłacone">niedopłata</span>
            <?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <?php if ($can_write): ?>
            <button type="button" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2"
                    title="Edytuj rozliczenie (korekta, termin, płatnik, faktura)"
                    data-bs-toggle="modal" data-bs-target="#editBill<?= (int)$b['id'] ?>">
              <i class="bi bi-pencil-square me-1"></i>Edytuj
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
            <?php // ── PayU ──
              $pu = $payu_pay[(int)$b['id']] ?? null; ?>
            <?php if ($payu_on && $pu && $pu['status'] === 'pending' && !empty($pu['redirect_uri'])): ?>
            <a href="<?= h($pu['redirect_uri']) ?>" target="_blank" rel="noopener"
               class="btn btn-xs btn-sm btn-outline-success py-0 px-2" title="Otwórz link do zapłaty PayU">
              <i class="bi bi-link-45deg me-1"></i>PayU
            </a>
            <?php elseif ($payu_on): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"         value="payu_link">
              <input type="hidden" name="billing_id"  value="<?= (int)$b['id'] ?>">
              <button type="submit" class="btn btn-xs btn-sm btn-outline-success py-0 px-2" title="Wygeneruj link do zapłaty PayU">
                <i class="bi bi-wallet2 me-1"></i>PayU
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
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modale edycji rozliczeń (poza tabelą — czytelne, bez obcinania) -->
<?php if ($can_write): foreach ($billings as $b):
  $adj      = (float)($b['adjustment'] ?? 0);
  $tot      = (float)$b['amount'] + $adj;
  $payer_def = k30_ti_default_payer((int)$b['client_id']);
  $period   = ($months_pl[(int)$b['month']] ?? $b['month']) . ' ' . (int)$b['year'];
?>
<div class="modal fade" id="editBill<?= (int)$b['id'] ?>" tabindex="-1" aria-labelledby="editBillLbl<?= (int)$b['id'] ?>" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="editBillLbl<?= (int)$b['id'] ?>">
          <i class="bi bi-receipt text-primary me-2" aria-hidden="true"></i>Rozliczenie — <?= h($b['client_name']) ?>
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <?php $cbal = $balances[(int)$b['client_id']] ?? ti_client_balance((int)$b['client_id']); $cpayments = ti_payments_for_client((int)$b['client_id']); ?>
        <p class="text-body-secondary small mb-3">
          Okres: <strong><?= h($period) ?></strong> · Do zapłaty: <strong><?= number_format($tot,2,',',' ') ?> zł</strong>
          <?php if ($cbal['credit'] > 0.005): ?> · <span class="text-success fw-semibold">nadpłata: <?= number_format($cbal['credit'],2,',',' ') ?> zł</span>
          <?php elseif ($cbal['debt'] > 0.005): ?> · <span class="text-danger fw-semibold">niedopłata: <?= number_format($cbal['debt'],2,',',' ') ?> zł</span>
          <?php else: ?> · <span class="text-success">saldo rozliczone</span><?php endif; ?>
        </p>

        <!-- Wpłaty i saldo -->
        <section class="border rounded p-3 mb-3">
          <h3 class="h6 fw-semibold mb-2"><i class="bi bi-cash-stack text-success me-2" aria-hidden="true"></i>Wpłaty i saldo</h3>
          <form method="post" class="row g-2 align-items-end mb-2">
            <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"        value="add_payment">
            <input type="hidden" name="client_id"  value="<?= (int)$b['client_id'] ?>">
            <div class="col-sm-3">
              <label class="form-label small mb-0" for="payamt<?= (int)$b['id'] ?>">Kwota (zł)</label>
              <input type="text" name="amount" id="payamt<?= (int)$b['id'] ?>" class="form-control form-control-sm" placeholder="0,00" inputmode="decimal">
            </div>
            <div class="col-sm-3">
              <label class="form-label small mb-0" for="paydt<?= (int)$b['id'] ?>">Data</label>
              <input type="date" name="paid_at" id="paydt<?= (int)$b['id'] ?>" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-sm-3">
              <label class="form-label small mb-0" for="paymeth<?= (int)$b['id'] ?>">Metoda</label>
              <select name="method" id="paymeth<?= (int)$b['id'] ?>" class="form-select form-select-sm">
                <option value="transfer">Przelew</option>
                <option value="cash">Gotówka</option>
                <option value="other">Inna</option>
              </select>
            </div>
            <div class="col-sm-3 d-flex align-items-end">
              <button class="btn btn-sm btn-success w-100"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj wpłatę</button>
            </div>
            <div class="col-12"><input type="text" name="note" class="form-control form-control-sm" placeholder="Notatka (opcjonalnie)"></div>
            <div class="col-12"><span class="form-text">Wpłata wyższa niż należność utworzy nadpłatę (rodzic/opiekun dostanie e-mail). Nadpłata jest automatycznie używana na kolejne zajęcia.</span></div>
          </form>
          <?php if ($cpayments): ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
              <caption class="visually-hidden">Historia wpłat kursanta</caption>
              <thead class="table-light"><tr><th>Data</th><th>Kwota</th><th>Metoda</th><th>Notatka</th><th class="text-end">Akcje</th></tr></thead>
              <tbody>
                <?php foreach ($cpayments as $pm):
                  $mlabel = ['transfer'=>'Przelew','cash'=>'Gotówka','stripe'=>'Stripe','payu'=>'PayU','other'=>'Inna'][$pm['method']] ?? $pm['method']; ?>
                <tr>
                  <td class="text-nowrap"><?= h(substr($pm['paid_at'] ?: $pm['created_at'], 0, 10)) ?></td>
                  <td class="fw-semibold text-success">+<?= number_format((float)$pm['amount'],2,',',' ') ?> zł</td>
                  <td><?= h($mlabel) ?></td>
                  <td class="text-body-secondary"><?= h(mb_substr($pm['note'] ?? '', 0, 60)) ?></td>
                  <td class="text-end">
                    <form method="post" class="d-inline" onsubmit="return confirm('Usunąć tę wpłatę? Saldo zostanie przeliczone.')">
                      <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="_op"          value="del_payment">
                      <input type="hidden" name="payment_id"   value="<?= (int)$pm['id'] ?>">
                      <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-1" title="Usuń wpłatę"><i class="bi bi-trash"></i></button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <p class="text-body-secondary small mb-0">Brak zarejestrowanych wpłat.</p>
          <?php endif; ?>
        </section>

        <!-- Korekta -->
        <section class="border rounded p-3 mb-3">
          <h3 class="h6 fw-semibold mb-2"><i class="bi bi-percent text-secondary me-2" aria-hidden="true"></i>Korekta (opłata dodatkowa / rabat)</h3>
          <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"        value="set_adjustment">
            <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
            <div class="col-sm-4">
              <label class="form-label small mb-0" for="adjkind<?= (int)$b['id'] ?>">Rodzaj</label>
              <select name="adj_kind" id="adjkind<?= (int)$b['id'] ?>" class="form-select form-select-sm">
                <option value="fee"      <?= $adj > 0 ? 'selected' : '' ?>>Opłata dodatkowa (+)</option>
                <option value="discount" <?= $adj < 0 ? 'selected' : '' ?>>Rabat (−)</option>
              </select>
            </div>
            <div class="col-sm-3">
              <label class="form-label small mb-0" for="adjval<?= (int)$b['id'] ?>">Kwota (zł)</label>
              <input type="text" name="adj_value" id="adjval<?= (int)$b['id'] ?>" class="form-control form-control-sm"
                     value="<?= $adj != 0 ? number_format(abs($adj),2,',','') : '' ?>" placeholder="0,00">
            </div>
            <div class="col-sm-5">
              <label class="form-label small mb-0" for="adjnote<?= (int)$b['id'] ?>">Opis (opcjonalnie)</label>
              <input type="text" name="adj_note" id="adjnote<?= (int)$b['id'] ?>" class="form-control form-control-sm"
                     value="<?= h($b['adjustment_note'] ?? '') ?>" placeholder="np. materiały, rabat">
            </div>
            <div class="col-12 d-flex align-items-center gap-2">
              <button class="btn btn-sm btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz korektę</button>
              <span class="form-text mb-0">Wpisz 0, aby usunąć korektę.</span>
            </div>
          </form>
        </section>

        <!-- Termin płatności -->
        <section class="border rounded p-3 mb-3">
          <h3 class="h6 fw-semibold mb-2"><i class="bi bi-calendar-event text-secondary me-2" aria-hidden="true"></i>Termin płatności</h3>
          <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"        value="set_due">
            <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
            <div class="col-sm-6">
              <label class="form-label small mb-0" for="due<?= (int)$b['id'] ?>">Termin (indywidualny)</label>
              <input type="date" name="due_date" id="due<?= (int)$b['id'] ?>" class="form-control form-control-sm"
                     value="<?= h($b['due_date'] ?? '') ?>">
            </div>
            <div class="col-12 d-flex align-items-center gap-2">
              <button class="btn btn-sm btn-outline-primary"><i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Zapisz termin</button>
              <span class="form-text mb-0">Puste = bez terminu.</span>
            </div>
          </form>
        </section>

        <!-- Płatnik -->
        <section class="border rounded p-3 mb-3">
          <h3 class="h6 fw-semibold mb-2"><i class="bi bi-person-badge text-secondary me-2" aria-hidden="true"></i>Płatnik</h3>
          <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"        value="set_payer">
            <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
            <div class="col-sm-5">
              <label class="form-label small mb-0" for="payer<?= (int)$b['id'] ?>">Płatnik</label>
              <select name="payer_type" id="payer<?= (int)$b['id'] ?>" class="form-select form-select-sm">
                <option value="" <?= empty($b['payer_type']) ? 'selected' : '' ?>>— domyślnie (<?= h(K30_TI_PAYERS[$payer_def]) ?>) —</option>
                <?php foreach (K30_TI_PAYERS as $pk => $pl): ?>
                <option value="<?= h($pk) ?>" <?= ($b['payer_type'] ?? '') === $pk ? 'selected' : '' ?>><?= h($pl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-7">
              <label class="form-label small mb-0" for="payername<?= (int)$b['id'] ?>">Nazwa płatnika (opcjonalnie)</label>
              <input type="text" name="payer_name" id="payername<?= (int)$b['id'] ?>" class="form-control form-control-sm"
                     value="<?= h($b['payer_name'] ?? '') ?>" placeholder="np. dane firmy / opiekuna do faktury">
            </div>
            <div class="col-12">
              <button class="btn btn-sm btn-outline-primary"><i class="bi bi-person-badge me-1" aria-hidden="true"></i>Zapisz płatnika</button>
            </div>
          </form>
        </section>

        <!-- Faktura (FVAT) -->
        <section class="border rounded p-3">
          <h3 class="h6 fw-semibold mb-2"><i class="bi bi-file-earmark-pdf text-secondary me-2" aria-hidden="true"></i>Faktura (FVAT)</h3>
          <?php if (!empty($b['invoice_path'])): ?>
          <p class="small mb-2">
            <i class="bi bi-file-earmark-pdf text-danger me-1" aria-hidden="true"></i>Załączono:
            <a href="billing_invoice.php?id=<?= (int)$b['id'] ?>" target="_blank" rel="noopener"><?= h($b['invoice_name'] ?: 'faktura.pdf') ?></a>
            <?= !empty($b['invoice_at']) ? '<span class="text-body-secondary">('.h(substr($b['invoice_at'],0,10)).')</span>' : '' ?>
          </p>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
            <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"        value="upload_invoice">
            <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
            <div class="col-sm-8">
              <label class="form-label small mb-0" for="inv<?= (int)$b['id'] ?>">Plik PDF</label>
              <input type="file" name="invoice" id="inv<?= (int)$b['id'] ?>" accept="application/pdf" class="form-control form-control-sm">
            </div>
            <div class="col-12 d-flex align-items-center gap-2">
              <button class="btn btn-sm btn-outline-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i><?= !empty($b['invoice_path']) ? 'Wymień fakturę' : 'Dodaj fakturę' ?></button>
            </div>
          </form>
          <?php if (!empty($b['invoice_path'])): ?>
          <form method="post" class="mt-2" onsubmit="return confirm('Usunąć fakturę z tego rozliczenia?')">
            <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"        value="delete_invoice">
            <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
            <button class="btn btn-link btn-sm text-danger p-0"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń fakturę</button>
          </form>
          <?php endif; ?>
        </section>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zamknij</button>
      </div>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>
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
          <td>
            <div class="fw-semibold"><?= h($p['client_name']) ?></div>
            <?php if (!empty($p['courses']) && count($p['courses']) > 1): ?>
            <div class="mt-1">
              <?php foreach ($p['courses'] as $pc): if ($pc['amount'] <= 0 && $pc['hours_billed'] <= 0) continue; ?>
              <span class="badge bg-light text-secondary border me-1" style="font-size:.72rem;font-weight:500">
                <?= h($pc['course_name']) ?>:
                <?php if ($pc['model'] !== 2): ?>
                  <?= number_format($pc['amount'],2,',','') ?> zł
                <?php else: ?>
                  <?= number_format($pc['hours_billed'],2,',','') ?> h · <?= number_format($pc['amount'],2,',','') ?> zł
                <?php endif; ?>
              </span>
              <?php endforeach; ?>
            </div>
            <?php elseif (!empty($p['courses'])): ?>
            <div class="text-muted" style="font-size:.78rem"><?= h($p['courses'][0]['course_name'] ?? '') ?></div>
            <?php endif; ?>
          </td>
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
