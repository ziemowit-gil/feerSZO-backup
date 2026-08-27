<?php
/**
 * karty30/ti/rekrutacja_admin.php — Rekrutacja godzin (moduł administracyjny).
 *
 * Pełny przegląd godzin (slotów) wystawionych w turach zapisów: filtry
 * (tura, prowadzący, grupa, status, zakres dat, szukaj), statystyki,
 * PODGLĄD godziny (master-detail, bez modali) z listą rezerwacji,
 * USUWANIE: godzina bez żadnych rezerwacji znika twardo (razem z pustą
 * zmaterializowaną lekcją), godzina z rezerwacjami może być tylko odwołana
 * (pełny zwrot żetonów). Do tego anulowanie pojedynczej rezerwacji
 * i operacje masowe na zaznaczonych.
 *
 * Zarządzanie turami/przypisaniami zostaje w panelu kierownika
 * (karty30/ti/dydaktyk/rekrutacja.php) — ten ekran to nadzór i porządki.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_rekrutacja.php';

k30_require_access();
karty30_migrate();
ti_planner_ext_migrate();
ti_rk_migrate();

$can_write = can_write('karty30') || is_admin();
$uid       = (int)(current_user()['id'] ?? 0);
$PAGE_TITLE = 'Rekrutacja godzin — TI';

/* ── Filtry (GET) — zapamiętywane w adresie, wracają po każdej akcji ───────── */
$F = [
    'round_id'      => (int)($_GET['round'] ?? 0),
    'instructor_id' => (int)($_GET['instructor'] ?? 0),
    'course_id'     => (int)($_GET['course'] ?? 0),
    'status'        => in_array($_GET['status'] ?? '', ['draft','open','locked','cancelled','done'], true) ? $_GET['status'] : '',
    'date_from'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '',
    'date_to'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : '',
    'only_free'     => !empty($_GET['free']),
    'q'             => trim($_GET['q'] ?? ''),
];
$qs = http_build_query(array_filter([
    'round' => $F['round_id'], 'instructor' => $F['instructor_id'], 'course' => $F['course_id'],
    'status' => $F['status'], 'from' => $F['date_from'], 'to' => $F['date_to'],
    'free' => $F['only_free'] ? 1 : null, 'q' => $F['q'],
]));
$back     = 'rekrutacja_admin.php' . ($qs ? "?$qs" : '');
$backslot = $back . ($qs ? '&' : '?') . 'slot=';

/* ── POST: usuwanie / odwoływanie / anulowanie rezerwacji ──────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_write) { http_response_code(403); die('Brak uprawnień do zapisu.'); }
    $op = $_POST['_op'] ?? '';

    if ($op === 'slot_delete') {
        try {
            rk_slot_delete((int)($_POST['slot_id'] ?? 0));
            flash_set('success', 'Godzina usunięta.');
        } catch (RkException $e) {
            flash_set('danger', rk_error_message($e->getMessage()));
        }
        header('Location: ' . $back); exit;
    }

    if ($op === 'slot_cancel') {
        try {
            $n = rk_slot_cancel((int)($_POST['slot_id'] ?? 0), $uid,
                                trim($_POST['reason'] ?? 'odwołanie administracyjne'), true);
            flash_set('success', "Godzina odwołana. Zwrócono żetony $n kursantom.");
        } catch (RkException $e) {
            flash_set('danger', rk_error_message($e->getMessage()));
        }
        header('Location: ' . $back); exit;
    }

    if ($op === 'booking_cancel') {
        try {
            $r = rk_cancel((int)($_POST['booking_id'] ?? 0), 0, 'staff',
                           trim($_POST['reason'] ?? 'anulowanie administracyjne'));
            flash_set('success', 'Rezerwacja anulowana. Zwrócone żetony: ' . (int)$r['refunded'] . '.');
        } catch (RkException $e) {
            flash_set('danger', rk_error_message($e->getMessage()));
        }
        header('Location: ' . $backslot . (int)($_POST['slot_id'] ?? 0)); exit;
    }

    if ($op === 'bulk') {
        $ids    = array_values(array_filter(array_map('intval', (array)($_POST['slot_ids'] ?? []))));
        $action = $_POST['bulk_action'] ?? '';
        $done = 0; $skipped = 0;
        foreach ($ids as $sid) {
            try {
                if ($action === 'delete')      { rk_slot_delete($sid); $done++; }
                elseif ($action === 'cancel')  { rk_slot_cancel($sid, $uid, 'odwołanie administracyjne', true); $done++; }
                else break;
            } catch (RkException) { $skipped++; }
        }
        flash_set($done ? 'success' : 'warning',
            ($action === 'delete' ? "Usunięto $done godzin" : "Odwołano $done godzin")
            . ($skipped ? " (pominięto $skipped: rezerwacje albo stan uniemożliwia operację)" : '') . '.');
        header('Location: ' . $back); exit;
    }
}

/* ── Dane ──────────────────────────────────────────────────────────────────── */
$slots  = rk_slots_admin_list($F);
$rounds = rk_rounds_list();
$instructors = db_all(
    "SELECT DISTINCT u.id, u.name FROM users u
      JOIN k30_rk_slots s ON s.instructor_id = u.id ORDER BY u.name COLLATE NOCASE");
$courses = db_all("SELECT id, name FROM k30_ti_courses WHERE is_active=1 ORDER BY name COLLATE NOCASE");

// Statystyki bieżącego filtra
$st = ['n' => count($slots), 'free' => 0, 'taken' => 0, 'cap' => 0, 'tokens' => 0];
foreach ($slots as $s) {
    if ($s['status'] !== 'cancelled') {
        $st['cap']   += (int)$s['capacity'];
        $st['taken'] += (int)$s['seats_taken'];
        $st['free']  += max(0, (int)$s['seats_free']);
        $st['tokens'] += (int)$s['seats_taken'] * (int)$s['token_cost'];
    }
}

// Podgląd godziny (master-detail)
$view_slot_id = (int)($_GET['slot'] ?? 0);
$view_slot    = $view_slot_id ? rk_slot_get($view_slot_id) : null;
$view_bookings = $view_slot ? rk_slot_bookings($view_slot_id) : [];

$mode_label = fn(string $m) => match ($m) {
    'onsite' => 'stacjonarnie', 'hybrid' => 'hybrydowo', default => 'online',
};
$slot_badge = fn(string $s) => match ($s) {
    'open'      => ['otwarta', 'success'],
    'draft'     => ['robocza', 'secondary'],
    'locked'    => ['zablokowana', 'warning'],
    'cancelled' => ['odwołana', 'danger'],
    'done'      => ['odbyta', 'primary'],
    default     => [$s, 'secondary'],
};
$booking_badge = fn(string $s) => match ($s) {
    'confirmed'         => ['potwierdzona', 'primary'],
    'pending_parent'    => ['czeka na rodzica', 'warning'],
    'attended'          => ['obecność', 'success'],
    'no_show'           => ['nieobecność', 'danger'],
    'cancelled_student' => ['rezygnacja kursanta', 'secondary'],
    'cancelled_staff'   => ['anulowana przez ośrodek', 'secondary'],
    'cancelled_parent'  => ['odrzucona/wygasła u rodzica', 'secondary'],
    default             => [$s, 'secondary'],
};

include dirname(__DIR__) . '/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Rekrutacja godzin</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-ticket-perforated text-primary me-2"></i>Rekrutacja godzin</h4>
  <div class="ms-auto d-flex gap-2">
    <a href="availability.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history me-1"></i>Dostępności</a>
    <a href="dydaktyk/rekrutacja.php?tab=tury" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-flag me-1"></i>Tury i przypisania <span class="badge text-bg-light border text-dark ms-1">panel</span></a>
  </div>
</div>

<?= flash_html() ?>

<!-- ── Filtry ──────────────────────────────────────────────────────────────── -->
<form method="get" class="card border-0 shadow-sm mb-3">
  <div class="card-body row g-2 align-items-end">
    <div class="col-6 col-md-2">
      <label class="form-label small fw-semibold mb-1" for="f-round">Tura</label>
      <select class="form-select form-select-sm" id="f-round" name="round">
        <option value="">— wszystkie —</option>
        <?php foreach ($rounds as $r): ?>
        <option value="<?= (int)$r['id'] ?>" <?= $F['round_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= h($r['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small fw-semibold mb-1" for="f-instr">Prowadzący</label>
      <select class="form-select form-select-sm" id="f-instr" name="instructor">
        <option value="">— wszyscy —</option>
        <?php foreach ($instructors as $i): ?>
        <option value="<?= (int)$i['id'] ?>" <?= $F['instructor_id'] === (int)$i['id'] ? 'selected' : '' ?>><?= h($i['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small fw-semibold mb-1" for="f-course">Grupa</label>
      <select class="form-select form-select-sm" id="f-course" name="course">
        <option value="">— wszystkie —</option>
        <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $F['course_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-1">
      <label class="form-label small fw-semibold mb-1" for="f-status">Status</label>
      <select class="form-select form-select-sm" id="f-status" name="status">
        <option value="">—</option>
        <?php foreach (['open'=>'otwarta','draft'=>'robocza','locked'=>'zablokowana','cancelled'=>'odwołana','done'=>'odbyta'] as $k => $v): ?>
        <option value="<?= $k ?>" <?= $F['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small fw-semibold mb-1" for="f-from">Od dnia</label>
      <input type="date" class="form-control form-control-sm" id="f-from" name="from" value="<?= h($F['date_from']) ?>">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small fw-semibold mb-1" for="f-to">Do dnia</label>
      <input type="date" class="form-control form-control-sm" id="f-to" name="to" value="<?= h($F['date_to']) ?>">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small fw-semibold mb-1" for="f-q">Szukaj</label>
      <input type="search" class="form-control form-control-sm" id="f-q" name="q" value="<?= h($F['q']) ?>"
             placeholder="prowadzący albo temat">
    </div>
    <div class="col-6 col-md-2 form-check ms-2 mb-1">
      <input class="form-check-input" type="checkbox" id="f-free" name="free" value="1" <?= $F['only_free'] ? 'checked' : '' ?>>
      <label class="form-check-label small" for="f-free">tylko wolne, przyszłe</label>
    </div>
    <div class="col-auto">
      <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filtruj</button>
      <a class="btn btn-sm btn-outline-secondary" href="rekrutacja_admin.php">Wyczyść</a>
    </div>
  </div>
</form>

<!-- ── Statystyki filtra ───────────────────────────────────────────────────── -->
<div class="d-flex gap-2 flex-wrap mb-3 small">
  <span class="badge text-bg-light border fs-6">godzin: <strong><?= $st['n'] ?></strong></span>
  <span class="badge text-bg-light border fs-6">miejsca: <strong><?= $st['taken'] ?>/<?= $st['cap'] ?></strong> zajęte</span>
  <span class="badge text-bg-light border fs-6">wolne: <strong><?= $st['free'] ?></strong></span>
  <span class="badge text-bg-light border fs-6">żetony w rezerwacjach: <strong><?= $st['tokens'] ?></strong><?php
    $pln = rk_token_pln(); if ($pln > 0): ?> (≈ <?= number_format($st['tokens'] * $pln, 2, ',', ' ') ?> zł)<?php endif; ?></span>
</div>

<!-- ── Podgląd godziny ─────────────────────────────────────────────────────── -->
<?php if ($view_slot): [$vs_label, $vs_class] = $slot_badge((string)$view_slot['status']); ?>
<div class="card border-0 shadow-sm mb-3 border-start border-primary border-3" id="slot-view">
  <div class="card-body">
    <div class="d-flex align-items-start gap-2 flex-wrap">
      <div>
        <h5 class="fw-bold mb-1">
          <i class="bi bi-eye me-1 text-primary" aria-hidden="true"></i>
          <?= h(rk_fmt_dt((string)$view_slot['starts_at'])) ?>–<?= h(substr((string)$view_slot['ends_at'], 11, 5)) ?>
          — <?= h($view_slot['instructor_name']) ?>
          <span class="badge text-bg-<?= $vs_class ?> ms-1"><?= h($vs_label) ?></span>
        </h5>
        <div class="text-body-secondary small">
          tura: <?= h($view_slot['round_name']) ?>
          · <?= h($mode_label((string)$view_slot['mode'])) ?>
          · <?= h($view_slot['subject_label'] ?: 'konsultacja') ?>
          · miejsca <?= (int)$view_slot['seats_taken'] ?>/<?= (int)$view_slot['capacity'] ?>
          · koszt <?= (int)$view_slot['token_cost'] ?> żet.
          <?= $view_slot['session_id'] ? '· <a href="lesson.php?id=' . (int)$view_slot['session_id'] . '">lekcja w dzienniku</a>' : '' ?>
        </div>
      </div>
      <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?= h($back) ?>" aria-label="Zamknij podgląd">
        <i class="bi bi-x-lg" aria-hidden="true"></i> Zamknij podgląd</a>
    </div>

    <?php if (!$view_bookings): ?>
    <div class="text-body-secondary small mt-3"><i class="bi bi-inbox me-1" aria-hidden="true"></i>Brak rezerwacji na tej godzinie.</div>
    <?php else: ?>
    <div class="table-responsive mt-3">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Rezerwacje na godzinie</caption>
        <thead><tr>
          <th scope="col">Kursant</th><th scope="col">Status</th>
          <th scope="col" class="text-end">Żetony</th><th scope="col">Pula</th>
          <th scope="col">Źródło</th><th scope="col">Zapisano</th>
          <th scope="col" class="text-end"><span class="visually-hidden">Akcje</span></th>
        </tr></thead>
        <tbody>
          <?php foreach ($view_bookings as $b): [$bl, $bc] = $booking_badge((string)$b['status']); ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= h($b['client_name']) ?></div>
              <?php if ($b['client_email']): ?><div class="text-body-secondary" style="font-size:.75rem"><?= h($b['client_email']) ?></div><?php endif; ?>
            </td>
            <td><span class="badge text-bg-<?= $bc ?>"><?= h($bl) ?></span>
              <?php if ($b['cancel_reason']): ?><div class="text-body-secondary" style="font-size:.72rem"><?= h($b['cancel_reason']) ?></div><?php endif; ?>
            </td>
            <td class="text-end"><?= (int)$b['tokens_spent'] ?><?= (int)$b['tokens_refunded'] > 0
                ? ' <span class="text-success" style="font-size:.75rem">(zwrot ' . (int)$b['tokens_refunded'] . ')</span>' : '' ?></td>
            <td class="small"><?= h($b['pool_name'] ?: '—') ?></td>
            <td class="small"><?= h($b['source']) ?></td>
            <td class="small text-body-secondary"><?= h(rk_fmt_dt((string)$b['booked_at'])) ?></td>
            <td class="text-end">
              <?php if ($can_write && in_array($b['status'], ['confirmed','pending_parent'], true)): ?>
              <form method="post" class="d-inline"
                    onsubmit="return confirm('Anulować rezerwację kursanta <?= h($b['client_name']) ?>? Żetony wrócą w całości.')">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="booking_cancel">
                <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                <input type="hidden" name="slot_id" value="<?= (int)$view_slot['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Anuluj rezerwację">
                  <i class="bi bi-x-lg" aria-hidden="true"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Lista godzin ────────────────────────────────────────────────────────── -->
<?php if (!$slots): ?>
<div class="text-muted text-center py-5">
  <i class="bi bi-calendar-x fs-2 d-block mb-2 opacity-50" aria-hidden="true"></i>
  Brak godzin dla wybranych filtrów.
</div>
<?php else: ?>
<form method="post" id="bulk-form">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="_op" value="bulk">
  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 small">
        <caption class="visually-hidden">Godziny rekrutacji</caption>
        <thead class="table-light">
          <tr>
            <?php if ($can_write): ?>
            <th scope="col" style="width:32px">
              <input class="form-check-input" type="checkbox" aria-label="Zaznacz wszystkie"
                     onclick="document.querySelectorAll('.rk-chk').forEach(c => c.checked = this.checked)">
            </th>
            <?php endif; ?>
            <th scope="col">Termin</th>
            <th scope="col">Prowadzący</th>
            <th scope="col">Tura</th>
            <th scope="col">Grupa</th>
            <th scope="col" class="text-end">Miejsca</th>
            <th scope="col" class="text-end">Koszt</th>
            <th scope="col">Status</th>
            <th scope="col" class="text-end">Rezerwacje</th>
            <th scope="col" class="text-end"><span class="visually-hidden">Akcje</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($slots as $s): [$sl, $sc] = $slot_badge((string)$s['status']); ?>
          <tr class="<?= $s['status'] === 'cancelled' ? 'opacity-50' : '' ?> <?= $view_slot_id === (int)$s['id'] ? 'table-primary' : '' ?>">
            <?php if ($can_write): ?>
            <td><input class="form-check-input rk-chk" type="checkbox" name="slot_ids[]" value="<?= (int)$s['id'] ?>"
                       aria-label="Zaznacz godzinę <?= h(rk_fmt_dt((string)$s['starts_at'])) ?>"></td>
            <?php endif; ?>
            <td>
              <strong><?= h(rk_fmt_dt((string)$s['starts_at'])) ?></strong>
              <span class="text-body-secondary">– <?= h(substr((string)$s['ends_at'], 11, 5)) ?></span>
              <?php if ($s['subject_label']): ?>
              <div class="text-body-secondary" style="font-size:.75rem"><?= h($s['subject_label']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= h($s['instructor_name']) ?></td>
            <td class="text-body-secondary"><?= h($s['round_name']) ?></td>
            <td class="text-body-secondary"><?= $s['course_name'] ? h($s['course_name']) : '—' ?></td>
            <td class="text-end"><?= (int)$s['seats_taken'] ?>/<?= (int)$s['capacity'] ?></td>
            <td class="text-end"><?= (int)$s['token_cost'] ?> żet.</td>
            <td><span class="badge text-bg-<?= $sc ?>"><?= h($sl) ?></span></td>
            <td class="text-end">
              <?= (int)$s['n_bookings_live'] ?><?= (int)$s['n_bookings_all'] > (int)$s['n_bookings_live']
                  ? ' <span class="text-body-secondary">(+' . ((int)$s['n_bookings_all'] - (int)$s['n_bookings_live']) . ' hist.)</span>' : '' ?>
            </td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-secondary py-0 px-2"
                 href="<?= h($backslot) ?><?= (int)$s['id'] ?>#slot-view" title="Podgląd rezerwacji">
                <i class="bi bi-eye" aria-hidden="true"></i></a>
              <?php if ($can_write): ?>
                <?php if ((int)$s['n_bookings_all'] === 0): ?>
                <button type="submit" form="row-del-<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-danger py-0 px-2"
                        title="Usuń godzinę (bez rezerwacji)"><i class="bi bi-trash" aria-hidden="true"></i></button>
                <?php elseif (in_array($s['status'], ['draft','open','locked'], true)): ?>
                <button type="submit" form="row-can-<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-warning py-0 px-2"
                        title="Odwołaj godzinę (zwrot żetonów zapisanym)"><i class="bi bi-x-octagon" aria-hidden="true"></i></button>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($can_write): ?>
    <div class="card-body border-top d-flex gap-2 align-items-center flex-wrap">
      <label class="small fw-semibold mb-0" for="bulk-action">Z zaznaczonymi:</label>
      <select class="form-select form-select-sm w-auto" id="bulk-action" name="bulk_action">
        <option value="delete">usuń (tylko bez rezerwacji)</option>
        <option value="cancel">odwołaj (zwrot żetonów zapisanym)</option>
      </select>
      <button class="btn btn-sm btn-danger"
              onclick="return document.querySelectorAll('.rk-chk:checked').length
                       ? confirm('Wykonać operację na zaznaczonych godzinach?')
                       : (alert('Zaznacz przynajmniej jedną godzinę.'), false)">
        <i class="bi bi-lightning me-1" aria-hidden="true"></i>Wykonaj
      </button>
      <span class="text-body-secondary small ms-auto">Usunąć można tylko godzinę bez żadnych rezerwacji —
        z rezerwacjami (także historycznymi) godzinę się odwołuje, ślad zostaje.</span>
    </div>
    <?php endif; ?>
  </div>
</form>

<?php if ($can_write): ?>
<?php foreach ($slots as $s): ?>
  <?php if ((int)$s['n_bookings_all'] === 0): ?>
  <form method="post" id="row-del-<?= (int)$s['id'] ?>"
        onsubmit="return confirm('Usunąć godzinę <?= h(rk_fmt_dt((string)$s['starts_at'])) ?> (<?= h($s['instructor_name']) ?>)? Operacja nieodwracalna.')">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_op" value="slot_delete">
    <input type="hidden" name="slot_id" value="<?= (int)$s['id'] ?>">
  </form>
  <?php elseif (in_array($s['status'], ['draft','open','locked'], true)): ?>
  <form method="post" id="row-can-<?= (int)$s['id'] ?>"
        onsubmit="return confirm('Odwołać godzinę <?= h(rk_fmt_dt((string)$s['starts_at'])) ?>? Zapisani kursanci dostaną pełny zwrot żetonów.')">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_op" value="slot_cancel">
    <input type="hidden" name="slot_id" value="<?= (int)$s['id'] ?>">
  </form>
  <?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer_k30.php'; ?>
