<?php
/**
 * karty30/ti/dydaktyk/klienci.php — Podgląd wszystkich kursantów (ekran kierownika).
 *
 * Lista kursantów ze wszystkich grup (bez filtrowania po prowadzącym — panel
 * kierownika, dostęp tylko dyd_is_staff()). Kolumny: grupy, saldo (nadpłata/
 * zaległość z ti_client_balance()), kontakt. Z tego ekranu kierownik może też
 * DOŁADOWAĆ PORTFEL kursanta (prosty formularz — bez przechodzenia przez cały
 * widok rozliczeń w billing.php): op add_payment, ta sama logika co
 * student_billing.php (ti_payment_add), tyle że w sesji panelu dydaktyka.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_payments.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$dyd_name = (string)($me['name'] ?? '');
karty30_migrate();
ti_payments_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'wallet_topup') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $amount    = round((float)str_replace(',', '.', (string)($_POST['amount'] ?? '0')), 2);
        $method    = in_array($_POST['method'] ?? '', ['transfer', 'cash', 'other'], true) ? $_POST['method'] : 'transfer';
        $note      = trim($_POST['note'] ?? '');
        $pay_course = (int)($_POST['pay_course_id'] ?? 0);
        if ($pay_course > 0 && !db_one("SELECT 1 FROM k30_ti_enrollments WHERE client_id=? AND course_id=?", [$client_id, $pay_course])) {
            $pay_course = 0;
        }
        $client = $client_id ? db_one("SELECT id FROM k30_clients WHERE id=?", [$client_id]) : null;
        if ($client && $amount > 0) {
            $r     = ti_payment_add($client_id, $amount, '', $method, $note, 'manual', 0, $pay_course);
            $gname = $pay_course ? (db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$pay_course])['name'] ?? '') : '';
            $msg   = 'Portfel doładowany kwotą ' . number_format($amount, 2, ',', ' ') . ' zł'
                   . ($gname !== '' ? ' na grupę „' . $gname . '”' : ' (ogólnie)') . '.';
            if ($r['credit'] > 0) $msg .= ' Nadpłata: ' . number_format($r['credit'], 2, ',', ' ') . ' zł.';
            flash_set('success', $msg);
        } else {
            flash_set('danger', 'Podaj poprawną kwotę doładowania.');
        }
        header('Location: klienci.php' . (isset($_GET['q']) ? '?q=' . urlencode($_GET['q']) : '')); exit;
    }

    // Nowy klient — dotąd tylko w starym adminie (karty30/clients/add.php,
    // wymagał osobnego logowania SZO). Tu celowo lekka wersja: minimum danych
    // + od razu zapis do wybranej grupy (bez tego klient nie pojawiłby się
    // nawet na tej liście — filtrowana po aktywnym zapisie, patrz $rows niżej).
    if ($op === 'add_client') {
        $nc_name  = trim($_POST['name'] ?? '');
        $nc_email = trim($_POST['email'] ?? '');
        $nc_phone = trim($_POST['phone'] ?? '');
        $nc_pesel = preg_replace('/\D/', '', trim($_POST['pesel'] ?? ''));
        $nc_course = (int)($_POST['course_id'] ?? 0);
        $nc_err = [];
        if ($nc_name === '') $nc_err[] = 'Imię i nazwisko jest wymagane.';
        if ($nc_email !== '' && !filter_var($nc_email, FILTER_VALIDATE_EMAIL)) $nc_err[] = 'Nieprawidłowy adres e-mail.';
        if ($nc_pesel !== '' && !pesel_valid($nc_pesel)) $nc_err[] = 'Nieprawidłowy PESEL.';
        if (!$nc_course || !db_one("SELECT 1 FROM k30_ti_courses WHERE id=? AND is_active=1", [$nc_course])) $nc_err[] = 'Wybierz grupę, do której zapisać klienta.';
        if ($nc_err) {
            flash_set('danger', implode(' ', $nc_err));
        } else {
            $nc_id = db_insert('k30_clients', [
                'name' => $nc_name, 'email' => $nc_email ?: null, 'phone' => $nc_phone ?: null,
                'pesel' => $nc_pesel ?: '', 'status' => 'enrolled', 'created_by' => $me['user_id'] ?? null,
            ]);
            db()->prepare(
                "INSERT INTO k30_ti_enrollments (course_id,client_id,start_date,status) VALUES (?,?,?,'active')"
            )->execute([$nc_course, $nc_id, date('Y-m-d')]);
            try { k30_sync_to_crm(['name' => $nc_name, 'email' => $nc_email, 'phone' => $nc_phone], (int)($me['user_id'] ?? 0)); }
            catch (\Throwable $e) { /* best-effort, jak przy rk_book() */ }
            flash_set('success', 'Klient dodany i zapisany do grupy.');
        }
        header('Location: klienci.php'); exit;
    }

    if ($op === 'wallet_request_approve' || $op === 'wallet_request_reject') {
        $rid = (int)($_POST['request_id'] ?? 0);
        if ($op === 'wallet_request_approve') {
            $ok = ti_wallet_request_approve($rid, (int)$me['user_id']);
            flash_set($ok ? 'success' : 'danger', $ok ? 'Zgłoszenie zatwierdzone — wpłata zaksięgowana.' : 'Nie udało się zatwierdzić zgłoszenia.');
        } else {
            $ok = ti_wallet_request_reject($rid, (int)$me['user_id']);
            flash_set($ok ? 'success' : 'danger', $ok ? 'Zgłoszenie odrzucone.' : 'Nie udało się odrzucić zgłoszenia.');
        }
        header('Location: klienci.php'); exit;
    }

    // Wnioski wypisania (małoletni) — przeniesione z karty30/ti/unenroll_admin.php.
    // dyd_is_staff() zastępuje tam dawne is_admin()/can_write('karty30').
    if ($op === 'unenroll_approve' || $op === 'unenroll_reject') {
        $req_id = (int)($_POST['req_id'] ?? 0);
        $note   = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
        if ($op === 'unenroll_approve') {
            $ok = $req_id ? k30_ti_unenroll_admin_decide($req_id, (int)$me['user_id'], true, $note) : false;
            flash_set($ok ? 'success' : 'danger', $ok ? 'Wniosek zatwierdzony. Kursant wypisany z kursu.' : 'Nie udało się zatwierdzić — wniosek mógł już być rozpatrzony.');
        } else {
            $ok = $req_id ? k30_ti_unenroll_admin_decide($req_id, (int)$me['user_id'], false, $note) : false;
            flash_set($ok ? 'warning' : 'danger', $ok ? 'Wniosek odrzucony. Kursant pozostaje na kursie.' : 'Nie udało się odrzucić — wniosek mógł już być rozpatrzony.');
        }
        header('Location: klienci.php'); exit;
    }
}

$pending_requests   = ti_wallet_requests_pending();
$unenroll_pending   = k30_ti_unenroll_pending_admin();
$unenroll_history   = k30_ti_unenroll_requests_all();
$unenroll_status_labels = [
    'pending_parent' => ['label' => 'Czeka na opiekuna', 'cls' => 'warning'],
    'pending_admin'  => ['label' => 'Czeka na kierownika', 'cls' => 'primary'],
    'approved'       => ['label' => 'Zatwierdzone',       'cls' => 'success'],
    'rejected'       => ['label' => 'Odrzucone',          'cls' => 'secondary'],
];
$nc_courses = k30_ti_courses(true);

$q = trim($_GET['q'] ?? '');

$rows = db_all(
    "SELECT DISTINCT cl.id, cl.name, cl.email, cl.phone, a.student_no
     FROM k30_clients cl
     JOIN k30_ti_enrollments e ON e.client_id=cl.id AND e.status='active'
     LEFT JOIN k30_ti_student_accounts a ON a.client_id=cl.id
     WHERE (? = '' OR cl.name LIKE ?)
     ORDER BY cl.name",
    [$q, '%' . $q . '%']
);

$clients = [];
foreach ($rows as $r) {
    $cid = (int)$r['id'];
    $courses = k30_ti_client_courses($cid);
    $bal     = ti_client_balance($cid);
    $clients[] = [
        'id' => $cid, 'name' => (string)$r['name'], 'email' => (string)$r['email'], 'phone' => (string)$r['phone'],
        'student_no' => (string)($r['student_no'] ?? ''),
        'courses' => array_values(array_filter($courses, fn($c) => $c['status'] === 'active')),
        'balance' => $bal,
    ];
}

$KP_TITLE  = 'Podgląd klientów — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'klienci.php'; $KIER_LABEL = 'Podgląd klientów';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
  <h1 class="h4 fw-bold mb-3"><i class="bi bi-people text-primary me-2" aria-hidden="true"></i>Podgląd klientów</h1>
  <?= flash_html() ?>

  <?php if ($pending_requests): ?>
  <div class="card border-warning-subtle mb-3">
    <div class="card-header bg-warning bg-opacity-10 fw-semibold">
      <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Oczekujące zgłoszenia przelewów
      <span class="badge bg-warning text-dark ms-1"><?= count($pending_requests) ?></span>
    </div>
    <ul class="list-group list-group-flush">
      <?php foreach ($pending_requests as $wr): ?>
      <li class="list-group-item d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span>
          <strong><?= h($wr['client_name']) ?></strong> — <?= number_format((float)$wr['amount'], 2, ',', ' ') ?> zł
          <?php if (!empty($wr['is_year_end'])): ?>
          <span class="badge bg-info text-dark" title="Nadpłata do końca roku — po zatwierdzeniu dostaniesz osobny e-mail z prośbą o FV">Nadpłata do końca roku</span>
          <?php endif; ?>
          <span class="text-body-secondary small">· zgłoszono <?= h(substr($wr['created_at'], 0, 16)) ?> (<?= $wr['declared_by'] === 'opiekun' ? 'opiekun' : 'kursant' ?>)</span>
          <?php if ($wr['note'] !== ''): ?><div class="text-body-secondary small"><?= h($wr['note']) ?></div><?php endif; ?>
        </span>
        <span class="d-flex gap-2">
          <form method="post" class="d-inline">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="wallet_request_approve">
            <input type="hidden" name="request_id" value="<?= (int)$wr['id'] ?>">
            <button class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zatwierdź</button>
          </form>
          <form method="post" class="d-inline">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="wallet_request_reject">
            <input type="hidden" name="request_id" value="<?= (int)$wr['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
          </form>
        </span>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($unenroll_pending): ?>
  <div class="card border-warning-subtle mb-3">
    <div class="card-header bg-warning bg-opacity-10 fw-semibold">
      <i class="bi bi-person-dash me-1" aria-hidden="true"></i>Oczekujące wnioski wypisania (małoletni)
      <span class="badge bg-warning text-dark ms-1"><?= count($unenroll_pending) ?></span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <caption class="visually-hidden">Wnioski wypisania oczekujące na decyzję</caption>
        <thead class="table-light">
          <tr><th>Kursant</th><th>Kurs</th><th>Powód</th><th>Status</th><th>Zgłoszono</th><th>Opiekun ok</th><th class="text-end">Decyzja</th></tr>
        </thead>
        <tbody>
          <?php foreach ($unenroll_pending as $r):
            $sl = $unenroll_status_labels[$r['status']] ?? ['label' => $r['status'], 'cls' => 'secondary'];
          ?>
          <tr>
            <td class="fw-semibold small"><?= h($r['client_name']) ?></td>
            <td class="small"><?= h($r['course_name']) ?></td>
            <td class="small text-body-secondary" style="max-width:200px">
              <?= $r['reason'] !== '' ? nl2br(h(mb_substr($r['reason'], 0, 120))) : '<em>—</em>' ?>
            </td>
            <td><span class="badge text-bg-<?= $sl['cls'] ?>"><?= h($sl['label']) ?></span></td>
            <td class="small text-nowrap"><?= h(substr($r['created_at'] ?? '', 0, 16)) ?></td>
            <td class="small text-nowrap"><?= $r['parent_ok_at'] ? h(substr($r['parent_ok_at'], 0, 16)) : '<span class="text-body-secondary">—</span>' ?></td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-success btn-sm"
                      data-bs-toggle="modal" data-bs-target="#unenrollDecideModal"
                      data-req-id="<?= (int)$r['id'] ?>" data-action="unenroll_approve"
                      data-client="<?= h($r['client_name']) ?>" data-course="<?= h($r['course_name']) ?>">
                <i class="bi bi-check-lg" aria-hidden="true"></i> Zatwierdź
              </button>
              <button type="button" class="btn btn-outline-danger btn-sm ms-1"
                      data-bs-toggle="modal" data-bs-target="#unenrollDecideModal"
                      data-req-id="<?= (int)$r['id'] ?>" data-action="unenroll_reject"
                      data-client="<?= h($r['client_name']) ?>" data-course="<?= h($r['course_name']) ?>">
                <i class="bi bi-x-lg" aria-hidden="true"></i> Odrzuć
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($unenroll_history): ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
      <i class="bi bi-clock-history" aria-hidden="true"></i> Historia wniosków wypisania
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 small">
        <caption class="visually-hidden">Historia wniosków wypisania</caption>
        <thead class="table-light">
          <tr><th>Kursant</th><th>Kurs</th><th>Status</th><th>Zgłoszono</th><th>Decyzja</th><th>Kierownik</th><th>Uwaga</th></tr>
        </thead>
        <tbody>
          <?php foreach ($unenroll_history as $r):
            $sl = $unenroll_status_labels[$r['status']] ?? ['label' => $r['status'], 'cls' => 'secondary'];
          ?>
          <tr>
            <td class="fw-semibold"><?= h($r['client_name']) ?></td>
            <td><?= h($r['course_name']) ?></td>
            <td><span class="badge text-bg-<?= $sl['cls'] ?>"><?= h($sl['label']) ?></span></td>
            <td class="text-nowrap"><?= h(substr($r['created_at'] ?? '', 0, 16)) ?></td>
            <td class="text-nowrap"><?= $r['admin_ok_at'] ? h(substr($r['admin_ok_at'], 0, 16)) : '<span class="text-body-secondary">—</span>' ?></td>
            <td><?= $r['admin_name'] ? h($r['admin_name']) : '<span class="text-body-secondary">—</span>' ?></td>
            <td class="text-body-secondary"><?= $r['admin_note'] !== '' ? h(mb_substr($r['admin_note'], 0, 80)) : '' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="d-flex flex-wrap gap-2 align-items-start mb-3">
    <form method="get" style="max-width:360px" class="flex-grow-1">
      <div class="input-group">
        <input type="text" name="q" class="form-control" placeholder="Szukaj po nazwisku…" value="<?= h($q) ?>">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
      </div>
    </form>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newClientModal">
      <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Nowy klient
    </button>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Kursanci ze wszystkich grup, ich saldo i kontakt</caption>
        <thead class="table-light">
          <tr>
            <th scope="col">Kursant</th>
            <th scope="col">Nr kursanta</th>
            <th scope="col">Grupy</th>
            <th scope="col">Kontakt</th>
            <th scope="col" class="text-end">Saldo</th>
            <th scope="col" class="text-end">Akcje</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$clients): ?>
          <tr><td colspan="6" class="text-center text-body-secondary py-4">Brak kursantów.</td></tr>
          <?php endif; ?>
          <?php foreach ($clients as $c): $b = $c['balance']; ?>
          <tr>
            <th scope="row" class="fw-normal"><?= h($c['name']) ?></th>
            <td class="font-monospace small"><?= $c['student_no'] !== '' ? h($c['student_no']) : '<span class="text-body-secondary">—</span>' ?></td>
            <td>
              <?php foreach ($c['courses'] as $co): ?>
              <span class="badge bg-light text-secondary border me-1 mb-1"><?= h($co['course_name']) ?></span>
              <?php endforeach; ?>
              <?php if (!$c['courses']): ?><span class="text-body-secondary small">—</span><?php endif; ?>
            </td>
            <td class="small text-body-secondary">
              <?= h($c['email']) ?><?php if ($c['email'] !== '' && $c['phone'] !== ''): ?><br><?php endif; ?><?= h($c['phone']) ?>
            </td>
            <td class="text-end">
              <?php if ($b['credit'] > 0.005): ?>
                <span class="text-success fw-semibold">+<?= number_format($b['credit'], 2, ',', ' ') ?> zł</span>
              <?php elseif ($b['debt'] > 0.005): ?>
                <span class="text-danger fw-semibold">−<?= number_format($b['debt'], 2, ',', ' ') ?> zł</span>
              <?php else: ?>
                <span class="text-body-secondary">0,00 zł</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#topup<?= $c['id'] ?>">
                <i class="bi bi-wallet2 me-1" aria-hidden="true"></i>Doładuj
              </button>
              <a class="btn btn-sm btn-outline-secondary" href="client_billings_view.php?client_id=<?= (int)$c['id'] ?>" target="_blank" rel="noopener"
                 title="Podgląd rozliczeń kursanta — wszystkie okresy i grupy, saldo, wpłaty">
                <i class="bi bi-eye me-1" aria-hidden="true"></i>Rozliczenia
              </a>
              <a class="btn btn-sm btn-outline-secondary" href="client_ledger.php?client_id=<?= (int)$c['id'] ?>" target="_blank" rel="noopener"
                 title="Historia pobrań za lekcje z salda — PDF i wysyłka do kursanta">
                <i class="bi bi-journal-text" aria-hidden="true"></i><span class="visually-hidden">Historia pobrań</span>
              </a>
              <a class="btn btn-sm btn-outline-primary" title="Rozliczenia grupy (lista kierownika)" aria-label="Rozliczenia grupy" href="billing.php<?= $c['courses'] ? '?course_id=' . (int)$c['courses'][0]['course_id'] : '' ?>">
                <i class="bi bi-receipt" aria-hidden="true"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: nowy klient -->
<div class="modal fade" id="newClientModal" tabindex="-1" aria-labelledby="newClientLbl" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="add_client">
        <div class="modal-header">
          <h2 class="modal-title h5" id="newClientLbl"><i class="bi bi-person-plus text-primary me-2" aria-hidden="true"></i>Nowy klient</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label" for="nc_name">Imię i nazwisko <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="nc_name" name="name" required>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label" for="nc_email">E-mail</label>
              <input type="email" class="form-control" id="nc_email" name="email">
            </div>
            <div class="col-6">
              <label class="form-label" for="nc_phone">Telefon</label>
              <input type="tel" class="form-control" id="nc_phone" name="phone">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="nc_pesel">PESEL <span class="text-body-secondary fw-normal">(opcjonalnie)</span></label>
            <input type="text" class="form-control" id="nc_pesel" name="pesel" maxlength="11" inputmode="numeric">
          </div>
          <div class="mb-0">
            <label class="form-label" for="nc_course">Zapisz od razu do grupy <span class="text-danger">*</span></label>
            <select class="form-select" id="nc_course" name="course_id" required>
              <option value="">— wybierz grupę —</option>
              <?php foreach ($nc_courses as $co): ?>
              <option value="<?= (int)$co['id'] ?>"><?= h($co['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Pełne dane beneficjenta (RODO, sprzęt, status uczestnictwa…) uzupełnisz później w module Beneficjenci.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Dodaj i zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modale doładowania portfela -->
<?php foreach ($clients as $c): ?>
<div class="modal fade" id="topup<?= $c['id'] ?>" tabindex="-1" aria-labelledby="topupLbl<?= $c['id'] ?>" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="wallet_topup">
        <input type="hidden" name="client_id" value="<?= $c['id'] ?>">
        <div class="modal-header">
          <h2 class="modal-title h5" id="topupLbl<?= $c['id'] ?>"><i class="bi bi-wallet2 text-success me-2" aria-hidden="true"></i>Doładuj portfel — <?= h($c['name']) ?></h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label" for="amt<?= $c['id'] ?>">Kwota (zł)</label>
            <input type="text" name="amount" id="amt<?= $c['id'] ?>" class="form-control" placeholder="0,00" inputmode="decimal" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="meth<?= $c['id'] ?>">Metoda</label>
            <select name="method" id="meth<?= $c['id'] ?>" class="form-select">
              <option value="transfer">Przelew</option>
              <option value="cash">Gotówka</option>
              <option value="other">Inna</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label" for="grp<?= $c['id'] ?>">Zaksięguj na grupę</label>
            <select name="pay_course_id" id="grp<?= $c['id'] ?>" class="form-select">
              <option value="0">— wpłata ogólna (FIFO) —</option>
              <?php foreach ($c['courses'] as $co): ?>
              <option value="<?= (int)$co['course_id'] ?>"><?= h($co['course_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-0">
            <label class="form-label" for="note<?= $c['id'] ?>">Notatka (opcjonalnie)</label>
            <input type="text" name="note" id="note<?= $c['id'] ?>" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Doładuj</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- Modal: decyzja ws. wniosku wypisania -->
<div class="modal fade" id="unenrollDecideModal" tabindex="-1" aria-labelledby="unenrollDecideLbl" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h2 class="modal-title h5 fw-bold" id="unenrollDecideLbl">Decyzja ws. wniosku</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <form method="post">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op"    id="unenrollDecideOp" value="">
        <input type="hidden" name="req_id" id="unenrollDecideReqId" value="">
        <div class="modal-body">
          <p id="unenrollDecideDesc" class="mb-3"></p>
          <div class="mb-0">
            <label class="form-label" for="unenrollDecideNote">Uwaga dla kursanta <span class="text-body-secondary fw-normal">(opcjonalnie)</span></label>
            <textarea class="form-control" id="unenrollDecideNote" name="note" rows="2" maxlength="500"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" id="unenrollDecideSubmitBtn" class="btn btn-primary fw-semibold">Potwierdź</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('unenrollDecideModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function (e) {
    var btn    = e.relatedTarget;
    var action = btn.getAttribute('data-action');
    var client = btn.getAttribute('data-client') || '';
    var course = btn.getAttribute('data-course') || '';
    document.getElementById('unenrollDecideOp').value    = action;
    document.getElementById('unenrollDecideReqId').value = btn.getAttribute('data-req-id') || '';
    document.getElementById('unenrollDecideNote').value  = '';
    var submitBtn = document.getElementById('unenrollDecideSubmitBtn');
    if (action === 'unenroll_approve') {
      document.getElementById('unenrollDecideDesc').textContent =
        'Zatwierdź wypisanie kursanta ' + client + ' z kursu ' + course + '. Operacja jest nieodwracalna.';
      submitBtn.textContent = 'Zatwierdź wypisanie';
      submitBtn.className = 'btn btn-success fw-semibold';
    } else {
      document.getElementById('unenrollDecideDesc').textContent =
        'Odrzuć wniosek wypisania kursanta ' + client + ' z kursu ' + course + '. Kursant pozostanie na kursie.';
      submitBtn.textContent = 'Odrzuć wniosek';
      submitBtn.className = 'btn btn-danger fw-semibold';
    }
  });
})();
</script>

</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
