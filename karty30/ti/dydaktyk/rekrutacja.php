<?php
/**
 * karty30/ti/dydaktyk/rekrutacja.php — Zapisy na zajęcia (strona panelu dydaktyka).
 *
 * Prowadzący: wystawia i otwiera własne terminy (sloty) w ramach tury,
 * widzi rezerwacje kursantów, odwołuje termin (żetony wracają w całości).
 * Kierownik (dyd_is_staff): dodatkowo zarządza turami — zasady, otwarcie,
 * zamknięcie, ręczna wysyłka zapowiedzi e-mail (idempotentna).
 *
 * Zakładki: ?tab=terminy (domyślna) | tury (kierownik) | zapisy (kierownik).
 * Sesja panelu dydaktyka (dyd_require) — current_user()/is_admin() są tu puste.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_rekrutacja.php';

karty30_migrate();
ti_planner_ext_migrate();
ti_rk_migrate();

$me       = dyd_require();
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '');
$is_staff = dyd_is_staff();

$tab = $_GET['tab'] ?? 'terminy';
if (!in_array($tab, ['terminy','tury','zapisy'], true)) $tab = 'terminy';
if (!$is_staff && $tab !== 'terminy') { header('Location: rekrutacja.php'); exit; }

/* ══════════════════════════════════════════════════════════════════════════
   POST
   ══════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    /* ── Terminy prowadzącego ────────────────────────────────────────── */
    if ($op === 'slot_add') {
        $date = trim($_POST['date'] ?? '');
        $from = trim($_POST['time_from'] ?? '');
        $to   = trim($_POST['time_to'] ?? '');
        try {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                || !preg_match('/^\d{2}:\d{2}$/', $from) || !preg_match('/^\d{2}:\d{2}$/', $to)) {
                throw new RkException('SLOT_INVALID_TIME');
            }
            $sid = rk_slot_save([
                'round_id'      => (int)($_POST['round_id'] ?? 0),
                'instructor_id' => $uid,
                'course_id'     => (int)($_POST['course_id'] ?? 0) ?: null,
                'subject_label' => $_POST['subject_label'] ?? '',
                'mode'          => $_POST['mode'] ?? 'online',
                'starts_at'     => "$date $from:00",
                'ends_at'       => "$date $to:00",
                'capacity'      => (int)($_POST['capacity'] ?? 1),
                'token_cost'    => (int)($_POST['token_cost'] ?? 1),
            ]);
            if (!empty($_POST['open_now'])) {
                db_exec("UPDATE k30_rk_slots SET status='open' WHERE id=? AND instructor_id=?", [$sid, $uid]);
            }
            flash_set('success', 'Termin dodany' . (!empty($_POST['open_now']) ? ' i otwarty na zapisy.' : ' (roboczy).'));
        } catch (RkException $e) {
            flash_set('danger', rk_error_message($e->getMessage()));
        } catch (\PDOException $e) {
            flash_set('danger', 'Masz już termin o tym starcie — dwa sloty o tej samej godzinie nie mogą istnieć.');
        }
        header('Location: rekrutacja.php'); exit;
    }

    if ($op === 'slot_toggle') {
        $sid = (int)($_POST['slot_id'] ?? 0);
        $s   = db_one("SELECT * FROM k30_rk_slots WHERE id=? AND instructor_id=?", [$sid, $uid]);
        if ($s && in_array($s['status'], ['draft','open'], true)) {
            $new = $s['status'] === 'open' ? 'draft' : 'open';
            // Cofnięcie do roboczego tylko bez rezerwacji — kursanci już siedzą na słocie
            if ($new === 'draft' && (int)$s['seats_taken'] > 0) {
                flash_set('warning', 'Termin ma rezerwacje — możesz go tylko odwołać, nie schować.');
            } else {
                db_exec("UPDATE k30_rk_slots SET status=? WHERE id=?", [$new, $sid]);
                flash_set('success', $new === 'open' ? 'Termin otwarty na zapisy.' : 'Termin cofnięty do roboczych.');
            }
        }
        header('Location: rekrutacja.php'); exit;
    }

    if ($op === 'slot_cancel') {
        try {
            $n = rk_slot_cancel((int)($_POST['slot_id'] ?? 0), $uid, trim($_POST['reason'] ?? ''));
            flash_set('success', "Termin odwołany. Zwrócono żetony $n kursantom.");
        } catch (RkException $e) {
            flash_set('danger', rk_error_message($e->getMessage()));
        }
        header('Location: rekrutacja.php'); exit;
    }

    /* ── Tury (kierownik) ────────────────────────────────────────────── */
    if ($is_staff && $op === 'round_save') {
        if (trim($_POST['name'] ?? '') === '') {
            flash_set('danger', 'Podaj nazwę tury.');
            header('Location: rekrutacja.php?tab=tury'); exit;
        }
        $rid = (int)($_POST['round_id'] ?? 0) ?: null;
        $audience = json_encode([
            'courses'     => array_values(array_filter(array_map('intval', (array)($_POST['aud_courses'] ?? [])))),
            'instructors' => array_values(array_filter(array_map('intval', (array)($_POST['aud_instructors'] ?? [])))),
        ], JSON_UNESCAPED_UNICODE);
        rk_round_save([
            'name'            => $_POST['name'] ?? '',
            'pool_id'         => (int)($_POST['pool_id'] ?? 0),
            'opens_at'        => str_replace('T', ' ', trim($_POST['opens_at'] ?? '')),
            'closes_at'       => str_replace('T', ' ', trim($_POST['closes_at'] ?? '')),
            'announce_at'     => str_replace('T', ' ', trim($_POST['announce_at'] ?? '')),
            'max_per_client'  => (int)($_POST['max_per_client'] ?? 0),
            'refund_hours'    => (int)($_POST['refund_hours'] ?? 24),
            'late_refund_pct' => (int)($_POST['late_refund_pct'] ?? 0),
            'audience_json'   => $audience,
            'rules_html'      => trim($_POST['rules_html'] ?? ''),
            'created_by'      => $uid,
        ], $rid);
        flash_set('success', $rid ? 'Tura zaktualizowana.' : 'Tura utworzona (robocza).');
        header('Location: rekrutacja.php?tab=tury'); exit;
    }

    if ($is_staff && $op === 'round_status') {
        $rid = (int)($_POST['round_id'] ?? 0);
        $to  = $_POST['to'] ?? '';
        if ($rid && in_array($to, ['scheduled','open','closed','draft'], true)) {
            db_exec("UPDATE k30_rk_rounds SET status=? WHERE id=?", [$to, $rid]);
            flash_set('success', 'Status tury zmieniony na: ' . $to . '.');
        }
        header('Location: rekrutacja.php?tab=tury'); exit;
    }

    if ($is_staff && $op === 'round_announce') {
        $rid = (int)($_POST['round_id'] ?? 0);
        $n   = $rid ? rk_round_announce($rid) : 0;
        flash_set($n ? 'success' : 'warning',
            $n ? "Zapowiedź wysłana do $n odbiorców (pozostali dostali ją wcześniej)."
               : 'Nikt nowy nie dostał zapowiedzi — wszyscy odbiorcy mają ją już wysłaną albo lista jest pusta.');
        header('Location: rekrutacja.php?tab=tury'); exit;
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   DANE
   ══════════════════════════════════════════════════════════════════════════ */
$rounds_all  = rk_rounds_list();
$rounds_live = array_values(array_filter($rounds_all, fn($r) => in_array($r['status'], ['scheduled','open'], true)));
$my_slots    = rk_slots_of_instructor($uid);
$my_courses  = db_all("SELECT id, name FROM k30_ti_courses WHERE instructor_id=? AND is_active=1 ORDER BY name", [$uid]);
$my_avail    = ti_instructor_availability($uid, 'approved');

// Rezerwacje na moich slotach (podgląd uczestników)
$slot_bookings = [];
foreach (db_all(
    "SELECT b.slot_id, b.status, cl.name AS client_name
       FROM k30_rk_bookings b
       JOIN k30_rk_slots s ON s.id = b.slot_id
       JOIN k30_clients cl ON cl.id = b.client_id
      WHERE s.instructor_id = ? AND b.status IN ('confirmed','attended','no_show')
      ORDER BY cl.name COLLATE NOCASE", [$uid]) as $bb) {
    $slot_bookings[(int)$bb['slot_id']][] = $bb;
}

$pools = $is_staff ? pl_pools_list(true) : [];
$all_instructors = $is_staff ? db_all(
    "SELECT DISTINCT u.id, u.name FROM users u
      JOIN k30_ti_courses c ON c.instructor_id=u.id
     WHERE u.is_active=1 ORDER BY u.name COLLATE NOCASE", []) : [];
$all_courses = $is_staff ? db_all("SELECT id, name FROM k30_ti_courses WHERE is_active=1 ORDER BY name", []) : [];

$edit_round_id = (int)($_GET['edit_round'] ?? 0);
$edit_round    = $edit_round_id ? rk_round_get($edit_round_id) : null;
$rf = $edit_round ?: ['id'=>0,'name'=>'','pool_id'=>0,'opens_at'=>'','closes_at'=>'','announce_at'=>'',
                     'max_per_client'=>0,'refund_hours'=>24,'late_refund_pct'=>0,'audience_json'=>'{}','rules_html'=>''];
$rf_aud = json_decode((string)($rf['audience_json'] ?? '{}'), true) ?: [];

// Zapisy per tura (kierownik)
$view_round_id = (int)($_GET['round'] ?? 0);
$round_bookings = ($is_staff && $tab === 'zapisy' && $view_round_id) ? db_all(
    "SELECT b.*, s.starts_at, s.subject_label, u.name AS instructor_name, cl.name AS client_name
       FROM k30_rk_bookings b
       JOIN k30_rk_slots s ON s.id = b.slot_id
       JOIN users u ON u.id = s.instructor_id
       JOIN k30_clients cl ON cl.id = b.client_id
      WHERE s.round_id = ?
      ORDER BY s.starts_at, cl.name COLLATE NOCASE", [$view_round_id]) : [];

$dt_local = function (?string $v): string {
    if (!$v) return '';
    $t = strtotime($v);
    return $t ? date('Y-m-d\TH:i', $t) : '';
};
$dow_names = [1=>'pon',2=>'wt',3=>'śr',4=>'czw',5=>'pt',6=>'sob',7=>'niedz'];

/* ══════════════════════════════════════════════════════════════════════════
   HTML
   ══════════════════════════════════════════════════════════════════════════ */
$KP_TITLE  = 'Zapisy na zajęcia — Panel dydaktyka';
$KP_TOPBAR = [
    'brand'  => 'Panel dydaktyka',
    'icon'   => 'easel2',
    'user'   => $dyd_name,
    'logout' => 'logout.php',
];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php if ($is_staff) {
    $KIER_CUR = 'rekrutacja.php'; $KIER_LABEL = 'Zapisy na zajęcia';
    include __DIR__ . '/_kierownik_bar.php';
} ?>

<main id="main" class="dyd-wrap">

<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <div>
    <h1 class="h4 fw-bold mb-0"><i class="bi bi-ticket-perforated me-2 text-primary"></i>Zapisy na zajęcia</h1>
    <p class="text-body-secondary small mb-0">Terminy wystawiane przez prowadzących, rezerwowane przez kursantów za żetony</p>
  </div>
  <div class="ms-auto d-flex gap-2">
    <a href="index.php?tab=pulpit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Panel dydaktyka</a>
  </div>
</div>

<?= flash_html() ?>

<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $tab==='terminy'?'active':'' ?>" href="rekrutacja.php">
      <i class="bi bi-calendar-week me-1" aria-hidden="true"></i>Moje terminy
      <span class="badge bg-secondary ms-1"><?= count($my_slots) ?></span>
    </a>
  </li>
  <?php if ($is_staff): ?>
  <li class="nav-item">
    <a class="nav-link <?= $tab==='tury'?'active':'' ?>" href="rekrutacja.php?tab=tury">
      <i class="bi bi-flag me-1" aria-hidden="true"></i>Tury zapisów
      <span class="badge bg-secondary ms-1"><?= count($rounds_all) ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab==='zapisy'?'active':'' ?>" href="rekrutacja.php?tab=zapisy">
      <i class="bi bi-people me-1" aria-hidden="true"></i>Zapisy kursantów
    </a>
  </li>
  <?php endif; ?>
</ul>

<?php if ($tab === 'terminy'): ?>

<div class="row g-4">
  <div class="col-12 col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <h2 class="h6 fw-bold mb-3"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Nowy termin</h2>
        <?php if (!$rounds_live): ?>
        <div class="text-body-secondary small">Brak trwającej ani zaplanowanej tury zapisów.
          <?= $is_staff ? '<a href="rekrutacja.php?tab=tury">Utwórz turę</a>.' : 'Tury otwiera kierownik.' ?></div>
        <?php else: ?>
        <form method="post" class="d-flex flex-column gap-2">
          <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="slot_add">
          <div>
            <label class="form-label small mb-1" for="rk-round">Tura</label>
            <select class="form-select form-select-sm" id="rk-round" name="round_id" required>
              <?php foreach ($rounds_live as $r): ?>
              <option value="<?= (int)$r['id'] ?>"><?= h($r['name']) ?><?= $r['status']==='scheduled' ? ' (zaplanowana)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label small mb-1" for="rk-date">Data</label>
              <input type="date" class="form-control form-control-sm" id="rk-date" name="date" required min="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-3">
              <label class="form-label small mb-1" for="rk-from">Od</label>
              <input type="time" class="form-control form-control-sm" id="rk-from" name="time_from" required>
            </div>
            <div class="col-3">
              <label class="form-label small mb-1" for="rk-to">Do</label>
              <input type="time" class="form-control form-control-sm" id="rk-to" name="time_to" required>
            </div>
          </div>
          <div class="row g-2">
            <div class="col-4">
              <label class="form-label small mb-1" for="rk-cap">Miejsca</label>
              <input type="number" class="form-control form-control-sm" id="rk-cap" name="capacity" value="1" min="1" max="30">
            </div>
            <div class="col-4">
              <label class="form-label small mb-1" for="rk-cost">Koszt (żet.)</label>
              <input type="number" class="form-control form-control-sm" id="rk-cost" name="token_cost" value="1" min="0" max="99">
            </div>
            <div class="col-4">
              <label class="form-label small mb-1" for="rk-mode">Forma</label>
              <select class="form-select form-select-sm" id="rk-mode" name="mode">
                <option value="online">online</option>
                <option value="onsite">stacjonarnie</option>
                <option value="hybrid">hybrydowo</option>
              </select>
            </div>
          </div>
          <div>
            <label class="form-label small mb-1" for="rk-subj">Temat (opcjonalnie)</label>
            <input type="text" class="form-control form-control-sm" id="rk-subj" name="subject_label" maxlength="160" placeholder="np. konsultacja projektowa">
          </div>
          <?php if ($my_courses): ?>
          <div>
            <label class="form-label small mb-1" for="rk-course">Kurs (opcjonalnie)</label>
            <select class="form-select form-select-sm" id="rk-course" name="course_id">
              <option value="">— konsultacja poza kursem —</option>
              <?php foreach ($my_courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="rk-open" name="open_now" value="1" checked>
            <label class="form-check-label small" for="rk-open">Od razu otwórz na zapisy</label>
          </div>
          <button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Dodaj termin</button>
        </form>
        <?php endif; ?>

        <?php if ($my_avail): ?>
        <hr>
        <div class="small text-body-secondary">
          <strong>Twoje okna dostępności:</strong><br>
          <?php foreach ($my_avail as $a): ?>
          <?= h($dow_names[(int)$a['day_of_week']] ?? (string)$a['day_of_week']) ?> <?= h($a['time_from']) ?>–<?= h($a['time_to']) ?><br>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <?php if (!$my_slots): ?>
    <div class="text-body-secondary text-center py-5">
      <i class="bi bi-calendar-x fs-2 d-block mb-2 opacity-50" aria-hidden="true"></i>
      Nie wystawiłeś(-aś) jeszcze żadnego terminu.
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <caption class="visually-hidden">Moje terminy</caption>
        <thead>
          <tr>
            <th scope="col">Termin</th>
            <th scope="col">Tura</th>
            <th scope="col" class="text-end">Miejsca</th>
            <th scope="col" class="text-end">Koszt</th>
            <th scope="col">Status</th>
            <th scope="col">Zapisani</th>
            <th scope="col" class="text-end"><span class="visually-hidden">Akcje</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($my_slots as $s):
            $names = array_map(fn($b) => $b['client_name'], $slot_bookings[(int)$s['id']] ?? []);
            [$st_label, $st_class] = match ((string)$s['status']) {
                'open'      => ['otwarty', 'success'],
                'draft'     => ['roboczy', 'secondary'],
                'locked'    => ['zablokowany', 'warning'],
                'cancelled' => ['odwołany', 'danger'],
                'done'      => ['odbyty', 'primary'],
                default     => [(string)$s['status'], 'secondary'],
            };
          ?>
          <tr class="<?= $s['status']==='cancelled' ? 'opacity-50' : '' ?>">
            <td>
              <strong><?= h(rk_fmt_dt((string)$s['starts_at'])) ?></strong>
              <span class="text-body-secondary">– <?= h(substr((string)$s['ends_at'], 11, 5)) ?></span>
              <?php if ($s['subject_label']): ?>
              <div class="text-body-secondary" style="font-size:.78rem"><?= h($s['subject_label']) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-body-secondary" style="font-size:.85rem"><?= h($s['round_name']) ?></td>
            <td class="text-end"><?= (int)$s['seats_taken'] ?>/<?= (int)$s['capacity'] ?></td>
            <td class="text-end"><?= (int)$s['token_cost'] ?> żet.</td>
            <td><span class="badge text-bg-<?= $st_class ?>"><?= h($st_label) ?></span></td>
            <td style="font-size:.82rem"><?= $names ? h(implode(', ', $names)) : '<span class="text-body-secondary">—</span>' ?></td>
            <td class="text-end text-nowrap">
              <?php if (in_array($s['status'], ['draft','open'], true)): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="slot_toggle">
                <input type="hidden" name="slot_id" value="<?= (int)$s['id'] ?>">
                <button class="btn btn-sm btn-outline-<?= $s['status']==='open' ? 'secondary' : 'success' ?>"
                        title="<?= $s['status']==='open' ? 'Cofnij do roboczych' : 'Otwórz na zapisy' ?>">
                  <i class="bi bi-<?= $s['status']==='open' ? 'eye-slash' : 'unlock' ?>"></i>
                </button>
              </form>
              <form method="post" class="d-inline"
                    onsubmit="return confirm('Odwołać termin? Kursanci dostaną zwrot żetonów w całości.')">
                <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="slot_cancel">
                <input type="hidden" name="slot_id" value="<?= (int)$s['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="Odwołaj termin"><i class="bi bi-x-lg"></i></button>
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

<?php elseif ($tab === 'tury'): ?>

<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <h2 class="h6 fw-bold mb-3">
          <i class="bi bi-<?= $edit_round ? 'pencil' : 'plus-circle' ?> me-1" aria-hidden="true"></i>
          <?= $edit_round ? 'Edycja tury: ' . h($rf['name']) : 'Nowa tura zapisów' ?>
        </h2>
        <form method="post" class="d-flex flex-column gap-2">
          <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="round_save">
          <input type="hidden" name="round_id" value="<?= (int)$rf['id'] ?>">
          <div>
            <label class="form-label small mb-1" for="rr-name">Nazwa</label>
            <input type="text" class="form-control form-control-sm" id="rr-name" name="name"
                   value="<?= h($rf['name']) ?>" required maxlength="160" placeholder="np. Konsultacje 2026/Q4">
          </div>
          <div>
            <label class="form-label small mb-1" for="rr-pool">Pula żetonów (z której schodzą opłaty)</label>
            <select class="form-select form-select-sm" id="rr-pool" name="pool_id">
              <option value="">— dowolna ważna pula kursanta —</option>
              <?php foreach ($pools as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= (int)$rf['pool_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label small mb-1" for="rr-open">Start zapisów</label>
              <input type="datetime-local" class="form-control form-control-sm" id="rr-open" name="opens_at"
                     value="<?= h($dt_local($rf['opens_at'])) ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label small mb-1" for="rr-close">Koniec zapisów</label>
              <input type="datetime-local" class="form-control form-control-sm" id="rr-close" name="closes_at"
                     value="<?= h($dt_local($rf['closes_at'])) ?>">
            </div>
          </div>
          <div>
            <label class="form-label small mb-1" for="rr-ann">Zapowiedź e-mail (kiedy wysłać automatycznie)</label>
            <input type="datetime-local" class="form-control form-control-sm" id="rr-ann" name="announce_at"
                   value="<?= h($dt_local($rf['announce_at'])) ?>">
            <div class="form-text">Puste = tylko wysyłka ręczna przyciskiem przy turze.</div>
          </div>
          <div class="row g-2">
            <div class="col-4">
              <label class="form-label small mb-1" for="rr-max">Limit/kursant</label>
              <input type="number" class="form-control form-control-sm" id="rr-max" name="max_per_client"
                     value="<?= (int)$rf['max_per_client'] ?>" min="0" max="99">
              <div class="form-text">0 = bez limitu</div>
            </div>
            <div class="col-4">
              <label class="form-label small mb-1" for="rr-ref">Zwrot do (h)</label>
              <input type="number" class="form-control form-control-sm" id="rr-ref" name="refund_hours"
                     value="<?= (int)$rf['refund_hours'] ?>" min="0" max="336">
            </div>
            <div class="col-4">
              <label class="form-label small mb-1" for="rr-late">Zwrot po (%)</label>
              <input type="number" class="form-control form-control-sm" id="rr-late" name="late_refund_pct"
                     value="<?= (int)$rf['late_refund_pct'] ?>" min="0" max="100">
            </div>
          </div>
          <div>
            <label class="form-label small mb-1" for="rr-aud-c">Odbiorcy zapowiedzi: grupy</label>
            <select class="form-select form-select-sm" id="rr-aud-c" name="aud_courses[]" multiple size="4">
              <?php foreach ($all_courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], (array)($rf_aud['courses'] ?? []), true) ? 'selected' : '' ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Posiadacze żetonów wskazanej puli dostają zapowiedź zawsze.</div>
          </div>
          <div>
            <label class="form-label small mb-1" for="rr-aud-i">Odbiorcy zapowiedzi: kursanci prowadzących</label>
            <select class="form-select form-select-sm" id="rr-aud-i" name="aud_instructors[]" multiple size="4">
              <?php foreach ($all_instructors as $i): ?>
              <option value="<?= (int)$i['id'] ?>" <?= in_array((int)$i['id'], (array)($rf_aud['instructors'] ?? []), true) ? 'selected' : '' ?>><?= h($i['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label small mb-1" for="rr-rules">Dodatkowe zasady (HTML, trafia do maila)</label>
            <textarea class="form-control form-control-sm" id="rr-rules" name="rules_html" rows="3"><?= h($rf['rules_html']) ?></textarea>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i><?= $edit_round ? 'Zapisz zmiany' : 'Utwórz turę' ?></button>
            <?php if ($edit_round): ?>
            <a class="btn btn-sm btn-outline-secondary" href="rekrutacja.php?tab=tury">Anuluj edycję</a>
            <?php endif; ?>
          </div>
        </form>
        <div class="form-text mt-2">
          Treść maila z zapowiedzią edytujesz w
          <a href="../email_templates.php" target="_blank" rel="noopener">szablonach e-mail</a>
          (szablon „Rekrutacja TI — start zapisów”). Każdy kursant dostaje w nim osobisty link
          z tokenem na stronę zapisów — działa bez logowania.
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <?php if (!$rounds_all): ?>
    <div class="text-body-secondary text-center py-5">
      <i class="bi bi-flag fs-2 d-block mb-2 opacity-50" aria-hidden="true"></i>Brak tur zapisów.
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <caption class="visually-hidden">Tury zapisów</caption>
        <thead>
          <tr>
            <th scope="col">Tura</th>
            <th scope="col">Zapisy</th>
            <th scope="col" class="text-end">Terminy</th>
            <th scope="col" class="text-end">Rezerwacje</th>
            <th scope="col">Status</th>
            <th scope="col" class="text-end"><span class="visually-hidden">Akcje</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rounds_all as $r):
            [$st_label, $st_class] = match ((string)$r['status']) {
                'open'      => ['otwarta', 'success'],
                'scheduled' => ['zaplanowana', 'info'],
                'closed'    => ['zamknięta', 'secondary'],
                default     => ['robocza', 'secondary'],
            };
          ?>
          <tr>
            <td>
              <strong><?= h($r['name']) ?></strong>
              <?php if ($r['pool_name']): ?>
              <div class="text-body-secondary" style="font-size:.78rem">pula: <?= h($r['pool_name']) ?></div>
              <?php endif; ?>
            </td>
            <td style="font-size:.82rem">
              od <?= h(rk_fmt_dt((string)$r['opens_at'])) ?>
              <?= $r['closes_at'] ? '<br>do ' . h(rk_fmt_dt((string)$r['closes_at'])) : '' ?>
              <?php if ($r['announced_at']): ?>
              <br><span class="text-success">zapowiedź wysłana</span>
              <?php elseif ($r['announce_at']): ?>
              <br><span class="text-body-secondary">zapowiedź: <?= h(rk_fmt_dt((string)$r['announce_at'])) ?></span>
              <?php endif; ?>
            </td>
            <td class="text-end"><?= (int)$r['n_slots'] ?></td>
            <td class="text-end">
              <?php if ((int)$r['n_bookings'] > 0): ?>
              <a href="rekrutacja.php?tab=zapisy&round=<?= (int)$r['id'] ?>"><?= (int)$r['n_bookings'] ?></a>
              <?php else: ?>0<?php endif; ?>
            </td>
            <td><span class="badge text-bg-<?= $st_class ?>"><?= h($st_label) ?></span></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-secondary" href="rekrutacja.php?tab=tury&edit_round=<?= (int)$r['id'] ?>" title="Edytuj"><i class="bi bi-pencil"></i></a>
              <?php if (in_array($r['status'], ['draft','closed'], true)): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="round_status">
                <input type="hidden" name="round_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="to" value="open">
                <button class="btn btn-sm btn-outline-success" title="Otwórz zapisy"><i class="bi bi-unlock"></i></button>
              </form>
              <?php elseif ($r['status'] === 'open'): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Zamknąć zapisy w tej turze?')">
                <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="round_status">
                <input type="hidden" name="round_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="to" value="closed">
                <button class="btn btn-sm btn-outline-warning" title="Zamknij zapisy"><i class="bi bi-lock"></i></button>
              </form>
              <?php endif; ?>
              <?php if (in_array($r['status'], ['scheduled','open'], true)): ?>
              <form method="post" class="d-inline"
                    onsubmit="return confirm('Wysłać zapowiedź e-mail do odbiorców tury? Każdy dostanie osobisty link z tokenem. Wysyłka jest jednokrotna na odbiorcę.')">
                <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="round_announce">
                <input type="hidden" name="round_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline-primary" title="Wyślij zapowiedź e-mail"><i class="bi bi-envelope"></i></button>
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

<?php elseif ($tab === 'zapisy'): ?>

<form method="get" class="d-flex gap-2 align-items-end mb-3 flex-wrap">
  <input type="hidden" name="tab" value="zapisy">
  <div>
    <label class="form-label small mb-1" for="rz-round">Tura</label>
    <select class="form-select form-select-sm" id="rz-round" name="round" onchange="this.form.submit()">
      <option value="">— wybierz turę —</option>
      <?php foreach ($rounds_all as $r): ?>
      <option value="<?= (int)$r['id'] ?>" <?= $view_round_id === (int)$r['id'] ? 'selected' : '' ?>>
        <?= h($r['name']) ?> (<?= (int)$r['n_bookings'] ?> rez.)
      </option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<?php if (!$view_round_id): ?>
<div class="text-body-secondary small">Wybierz turę, aby zobaczyć rezerwacje kursantów.</div>
<?php elseif (!$round_bookings): ?>
<div class="text-body-secondary small">Brak rezerwacji w tej turze.</div>
<?php else: ?>
<div class="table-responsive">
  <table class="table table-sm align-middle">
    <caption class="visually-hidden">Rezerwacje w turze</caption>
    <thead>
      <tr>
        <th scope="col">Termin</th>
        <th scope="col">Prowadzący</th>
        <th scope="col">Kursant</th>
        <th scope="col" class="text-end">Żetony</th>
        <th scope="col">Status</th>
        <th scope="col">Źródło</th>
        <th scope="col">Zapisano</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($round_bookings as $b): ?>
      <tr>
        <td><strong><?= h(rk_fmt_dt((string)$b['starts_at'])) ?></strong>
          <?= $b['subject_label'] ? '<div class="text-body-secondary" style="font-size:.78rem">' . h($b['subject_label']) . '</div>' : '' ?></td>
        <td><?= h($b['instructor_name']) ?></td>
        <td><?= h($b['client_name']) ?></td>
        <td class="text-end"><?= (int)$b['tokens_spent'] ?><?= (int)$b['tokens_refunded'] > 0 ? ' <span class="text-success" style="font-size:.78rem">(−' . (int)$b['tokens_refunded'] . ')</span>' : '' ?></td>
        <td><span class="badge text-bg-<?= match ((string)$b['status']) {
            'confirmed' => 'primary', 'attended' => 'success', 'no_show' => 'danger',
            'cancelled_staff' => 'warning', default => 'secondary' } ?>"><?= h($b['status']) ?></span></td>
        <td class="text-body-secondary" style="font-size:.82rem"><?= h($b['source']) ?></td>
        <td class="text-body-secondary" style="font-size:.82rem"><?= h(rk_fmt_dt((string)$b['booked_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php endif; ?>

</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
