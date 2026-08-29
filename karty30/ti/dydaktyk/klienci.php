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
}

$q = trim($_GET['q'] ?? '');

$rows = db_all(
    "SELECT DISTINCT cl.id, cl.name, cl.email, cl.phone
     FROM k30_clients cl
     JOIN k30_ti_enrollments e ON e.client_id=cl.id AND e.status='active'
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

  <form method="get" class="mb-3" style="max-width:360px">
    <div class="input-group">
      <input type="text" name="q" class="form-control" placeholder="Szukaj po nazwisku…" value="<?= h($q) ?>">
      <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
    </div>
  </form>

  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Kursanci ze wszystkich grup, ich saldo i kontakt</caption>
        <thead class="table-light">
          <tr>
            <th scope="col">Kursant</th>
            <th scope="col">Grupy</th>
            <th scope="col">Kontakt</th>
            <th scope="col" class="text-end">Saldo</th>
            <th scope="col" class="text-end">Akcje</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$clients): ?>
          <tr><td colspan="5" class="text-center text-body-secondary py-4">Brak kursantów.</td></tr>
          <?php endif; ?>
          <?php foreach ($clients as $c): $b = $c['balance']; ?>
          <tr>
            <th scope="row" class="fw-normal"><?= h($c['name']) ?></th>
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
              <a class="btn btn-sm btn-outline-primary" href="billing.php<?= $c['courses'] ? '?course_id=' . (int)$c['courses'][0]['course_id'] : '' ?>">
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

</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
