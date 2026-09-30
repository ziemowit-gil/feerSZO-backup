<?php /* ═══════════════════════ TAB: UCZESTNICY — kartoteka grupy (widok USOS) ═══════════════════════ */ ?>
<?php
/**
 * Lista uczestników kursu w układzie rejestru: kontakt, frekwencja, oceny.
 * Odpowiednik listy studentów grupy w USOSweb — jedna gęsta tabela zamiast kart.
 *
 * Zmienne z index.php: $cur_course, $course, $uid.
 */
$u_staff = dyd_is_staff();

// ── Przeniesienie kursanta do innej grupy (kierownik) ────────────────────────
// Zapis w tej grupie zostaje zamknięty (historia frekwencji i rozliczeń bez zmian),
// w grupie docelowej powstaje/odżywa zapis z przepisanym modelem rozliczania.
// Puste obecności przyszłych lekcji starej grupy są usuwane, żeby kursant nie
// wisiał na listach i w podstawie frekwencji po dacie przeniesienia.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $u_staff && ($_POST['_op'] ?? '') === 'move_student') {
    dyd_token_check();
    $mv_cid = (int)($_POST['client_id'] ?? 0);
    $mv_to  = (int)($_POST['to_course'] ?? 0);
    $mv_ok  = false; $mv_msg = '';
    $mv_src = $mv_cid ? db_one("SELECT * FROM k30_ti_enrollments WHERE course_id=? AND client_id=? AND status='active'",
                               [$cur_course, $mv_cid]) : null;
    $mv_tc  = ($mv_to && $mv_to !== (int)$cur_course) ? k30_ti_course_get($mv_to) : null;
    if (!$mv_src) {
        $mv_msg = 'Ten kursant nie ma aktywnego zapisu w tej grupie.';
    } elseif (!$mv_tc || empty($mv_tc['is_active'])) {
        $mv_msg = 'Wybierz aktywną grupę docelową (inną niż bieżąca).';
    } else {
        db()->prepare("UPDATE k30_ti_enrollments SET status='inactive' WHERE course_id=? AND client_id=?")
           ->execute([$cur_course, $mv_cid]);
        $mv_ex = db_one("SELECT id FROM k30_ti_enrollments WHERE course_id=? AND client_id=?", [$mv_to, $mv_cid]);
        if ($mv_ex) {
            db()->prepare("UPDATE k30_ti_enrollments SET status='active', start_date=? WHERE id=?")
               ->execute([date('Y-m-d'), (int)$mv_ex['id']]);
        } else {
            try {
                db()->prepare(
                    "INSERT INTO k30_ti_enrollments
                       (course_id, client_id, hourly_rate, hourly_rate_online, start_date, status,
                        billing_model, billing_amount, pay_account, pay_title, pay_due_days)
                     VALUES (?,?,?,?,?,'active',?,?,?,?,?)"
                )->execute([$mv_to, $mv_cid, $mv_src['hourly_rate'] ?? 0, $mv_src['hourly_rate_online'] ?? 0, date('Y-m-d'),
                            $mv_src['billing_model'] ?? null, $mv_src['billing_amount'] ?? 0,
                            $mv_src['pay_account'] ?? '', $mv_src['pay_title'] ?? '', $mv_src['pay_due_days'] ?? null]);
            } catch (\Throwable $e) {   // starszy schemat bez pól rozliczeniowych
                db()->prepare("INSERT INTO k30_ti_enrollments (course_id, client_id, hourly_rate, start_date, status)
                               VALUES (?,?,?,?,'active')")
                   ->execute([$mv_to, $mv_cid, $mv_src['hourly_rate'] ?? 0, date('Y-m-d')]);
            }
        }
        db()->prepare(
            "DELETE FROM k30_ti_attendance
             WHERE client_id=? AND attended=0 AND COALESCE(cancelled,0)=0 AND COALESCE(no_show,0)=0
               AND session_id IN (SELECT id FROM k30_ti_sessions WHERE course_id=? AND lesson_date > date('now'))"
        )->execute([$mv_cid, $cur_course]);
        $mv_ok = true;
    }
    $mv_who = $mv_cid ? (db_one("SELECT name FROM k30_clients WHERE id=?", [$mv_cid])['name'] ?? ('#'.$mv_cid)) : '';
    $_SESSION['dyd_flash'] = $mv_ok
        ? ['type'=>'success', 'msg'=>'Przeniesiono: ' . $mv_who . ' → ' . $mv_tc['name']
            . '. Zapis w tej grupie zamknięty (historia zostaje), rozliczenia kolejnych zajęć pójdą już w nowej grupie.']
        : ['type'=>'danger', 'msg'=>'Nie przeniesiono. ' . $mv_msg];
    header('Location: index.php?course=' . (int)$cur_course . '&tab=uczestnicy'); exit;
}

// ── Zapisanie nowego uczestnika do grupy (kierownik) — przeniesione z
// admina (karty30/ti/course.php), które wymagało osobnego logowania SZO.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $u_staff && ($_POST['_op'] ?? '') === 'enroll') {
    dyd_token_check();
    $en_cid  = (int)($_POST['client_id'] ?? 0);
    $en_rate = max(0, (float)str_replace(',', '.', $_POST['hourly_rate'] ?? '0'));
    $en_rate_on = max(0, (float)str_replace(',', '.', $_POST['hourly_rate_online'] ?? '0'));   // 0 = jak stacjonarna
    if ($en_cid) {
        try {
            db()->prepare(
                "INSERT INTO k30_ti_enrollments (course_id,client_id,hourly_rate,hourly_rate_online,start_date,status)
                 VALUES (?,?,?,?,?,'active')
                 ON CONFLICT(course_id,client_id) DO UPDATE SET hourly_rate=excluded.hourly_rate, hourly_rate_online=excluded.hourly_rate_online,
                     status='active', start_date=excluded.start_date"
            )->execute([$cur_course, $en_cid, $en_rate, $en_rate_on, date('Y-m-d')]);
            $_SESSION['dyd_flash'] = ['type' => 'success', 'msg' => 'Uczestnik zapisany.'];
        } catch (\Throwable $e) {
            $_SESSION['dyd_flash'] = ['type' => 'danger', 'msg' => 'Nie udało się zapisać uczestnika.'];
        }
    }
    header('Location: index.php?course=' . (int)$cur_course . '&tab=uczestnicy'); exit;
}

// ── Wypisanie uczestnika z grupy (kierownik) — status='inactive', historia zostaje.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $u_staff && ($_POST['_op'] ?? '') === 'unenroll') {
    dyd_token_check();
    $un_cid = (int)($_POST['client_id'] ?? 0);
    if ($un_cid) {
        db()->prepare("UPDATE k30_ti_enrollments SET status='inactive' WHERE course_id=? AND client_id=?")
           ->execute([$cur_course, $un_cid]);
        $_SESSION['dyd_flash'] = ['type' => 'success', 'msg' => 'Uczestnik wypisany.'];
    }
    header('Location: index.php?course=' . (int)$cur_course . '&tab=uczestnicy'); exit;
}

// Grupy docelowe do przenoszenia (aktywne, bez bieżącej)
$u_targets = $u_staff
    ? array_values(array_filter(k30_ti_courses(true), fn($c) => (int)$c['id'] !== (int)$cur_course))
    : [];
$u_flash = $_SESSION['dyd_flash'] ?? null;
unset($_SESSION['dyd_flash']);

$u_parts = db_all(
    "SELECT e.client_id, e.status AS enroll_status, e.start_date, e.hourly_rate,
            cl.name, cl.email, cl.phone
       FROM k30_ti_enrollments e
       JOIN k30_clients cl ON cl.id = e.client_id
      WHERE e.course_id = ?
      ORDER BY (e.status != 'active'), cl.name COLLATE NOCASE",
    [$cur_course]
);

// Frekwencja: obecności / zajęcia, na których uczestnik był oczekiwany
// (odwołany udział i lekcje odwołane nie liczą się do podstawy).
$u_att = [];
foreach (db_all(
    "SELECT a.client_id,
            SUM(CASE WHEN a.attended = 1 THEN 1 ELSE 0 END)                    AS present,
            COUNT(*)                                                            AS expected,
            SUM(CASE WHEN COALESCE(a.no_show,0) = 1 THEN 1 ELSE 0 END)          AS no_show
       FROM k30_ti_attendance a
       JOIN k30_ti_sessions   s ON s.id = a.session_id
      WHERE s.course_id = ?
        AND s.status NOT IN ('cancelled','draft')
        AND s.lesson_date <= date('now')
        AND COALESCE(a.cancelled,0) = 0
      GROUP BY a.client_id",
    [$cur_course]
) as $r) {
    $u_att[(int)$r['client_id']] = $r;
}

// Oceny z e-dziennika — liczba i średnia ważona
$u_grades = [];
foreach (k30_ti_course_grades($cur_course) as $g) {
    $u_grades[(int)$g['client_id']][] = $g;
}

$u_active = count(array_filter($u_parts, fn($p) => $p['enroll_status'] === 'active'));

// Klienci możliwi do dopisania — wszyscy poza już AKTYWNYMI w tej grupie
// (kogoś wypisanego można zapisać ponownie — ON CONFLICT wyżej to obsłuży).
$u_active_ids   = array_map('intval', array_column(array_filter($u_parts, fn($p) => $p['enroll_status'] === 'active'), 'client_id'));
$u_not_enrolled = $u_staff
    ? array_values(array_filter(
        db_all("SELECT id, name FROM k30_clients ORDER BY name COLLATE NOCASE"),
        fn($c) => !in_array((int)$c['id'], $u_active_ids, true)
    ))
    : [];
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-people me-2" aria-hidden="true"></i>Uczestnicy — <?= h($course['name']) ?></h1>
  <span class="badge bg-secondary"><?= (int)$u_active ?> aktywnych z <?= count($u_parts) ?></span>
  <div class="ms-auto d-flex gap-2 usos-noprint">
    <a href="index.php?course=<?= (int)$cur_course ?>&tab=protokol" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-card-checklist me-1" aria-hidden="true"></i>Protokoły
    </a>
    <a href="index.php?course=<?= (int)$cur_course ?>&tab=oceny" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-journal-bookmark me-1" aria-hidden="true"></i>E-dziennik
    </a>
  </div>
</div>

<?php if ($u_flash): ?>
<div class="alert alert-<?= $u_flash['type'] === 'success' ? 'success' : 'danger' ?> d-flex align-items-center gap-2" role="<?= $u_flash['type'] === 'success' ? 'status' : 'alert' ?>">
  <i class="bi bi-<?= $u_flash['type'] === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>" aria-hidden="true"></i>
  <span><?= h($u_flash['msg']) ?></span>
</div>
<?php endif; ?>

<?php if ($u_staff && $u_not_enrolled): ?>
<div class="card mb-3 usos-noprint">
  <div class="card-body">
    <form method="post" class="d-flex gap-2 align-items-end flex-wrap">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="enroll">
      <div>
        <label class="form-label small fw-semibold mb-1" for="u_en_cid">Zapisz uczestnika</label>
        <select name="client_id" id="u_en_cid" class="form-select form-select-sm" required>
          <option value="">— wybierz —</option>
          <?php foreach ($u_not_enrolled as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label small fw-semibold mb-1" for="u_en_rate">Stawka stacjonarna (zł/h)</label>
        <input type="number" class="form-control form-control-sm" id="u_en_rate" name="hourly_rate" step="0.01" min="0" value="0" style="width:90px">
      </div>
      <div>
        <label class="form-label small fw-semibold mb-1" for="u_en_rate_on">Stawka online (zł/h)</label>
        <input type="number" class="form-control form-control-sm" id="u_en_rate_on" name="hourly_rate_online" step="0.01" min="0" value="0" style="width:90px"
               title="0 = taka sama jak stacjonarna">
      </div>
      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Zapisz</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">Lista uczestników grupy</div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <caption class="visually-hidden">Uczestnicy kursu <?= h($course['name']) ?> z kontaktem, frekwencją i ocenami</caption>
      <thead><tr>
        <th scope="col" style="width:2.5rem">#</th>
        <th scope="col">Uczestnik</th>
        <th scope="col">Kontakt</th>
        <th scope="col" class="text-nowrap">Zapisany od</th>
        <th scope="col" class="text-nowrap">Frekwencja</th>
        <th scope="col" class="text-nowrap">Oceny</th>
        <th scope="col">Status</th>
        <?php if ($u_staff): ?>
        <th scope="col" class="usos-noprint">Przenieś do grupy</th>
        <th scope="col" class="usos-noprint">Wypisz</th>
        <?php endif; ?>
      </tr></thead>
      <tbody>
        <?php if (!$u_parts): ?>
        <tr><td colspan="<?= $u_staff ? 9 : 7 ?>" class="text-center text-muted py-3">Do tej grupy nikt nie jest zapisany.</td></tr>
        <?php endif; ?>
        <?php foreach ($u_parts as $i => $p):
          $cid  = (int)$p['client_id'];
          $att  = $u_att[$cid] ?? null;
          $exp  = (int)($att['expected'] ?? 0);
          $pres = (int)($att['present'] ?? 0);
          $pct  = $exp > 0 ? (int)round($pres * 100 / $exp) : null;
          $gr   = $u_grades[$cid] ?? [];
          $avg  = $gr ? k30_ti_grades_average($gr) : null;
          $inactive = $p['enroll_status'] !== 'active';
        ?>
        <tr<?= $inactive ? ' class="text-muted"' : '' ?>>
          <td class="small"><?= $i + 1 ?></td>
          <td class="fw-semibold"><?= h($p['name']) ?></td>
          <td class="small">
            <?php if (!empty($p['email'])): ?>
              <a href="mailto:<?= h($p['email']) ?>"><?= h($p['email']) ?></a>
            <?php endif; ?>
            <?php if (!empty($p['phone'])): ?>
              <?= !empty($p['email']) ? '<br>' : '' ?><a href="tel:<?= h(preg_replace('/\s+/', '', (string)$p['phone'])) ?>"><?= h($p['phone']) ?></a>
            <?php endif; ?>
            <?php if (empty($p['email']) && empty($p['phone'])): ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="text-nowrap small"><?= !empty($p['start_date']) ? h(date('d.m.Y', strtotime((string)$p['start_date']))) : '—' ?></td>
          <td class="text-nowrap small">
            <?php if ($pct === null): ?><span class="text-muted">brak zajęć</span>
            <?php else: ?>
              <span class="badge <?= $pct >= 75 ? 'text-bg-success' : ($pct >= 50 ? 'text-bg-warning' : 'text-bg-danger') ?>"><?= $pct ?>%</span>
              <span class="text-body-secondary"><?= $pres ?>/<?= $exp ?></span>
              <?php if ((int)($att['no_show'] ?? 0) > 0): ?>
                <span class="text-danger" title="Nieusprawiedliwione nieobecności">· nb <?= (int)$att['no_show'] ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="text-nowrap small">
            <?php if (!$gr): ?><span class="text-muted">—</span>
            <?php else: ?>
              <?= count($gr) ?> szt.<?= $avg !== null ? ' · śr. <strong>' . number_format($avg, 2, ',', '') . '</strong>' : '' ?>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if ($inactive): ?><span class="badge text-bg-secondary">wypisany</span>
            <?php else: ?><span class="badge text-bg-success">aktywny</span><?php endif; ?>
          </td>
          <?php if ($u_staff): ?>
          <td class="text-nowrap usos-noprint">
            <?php if (!$inactive && $u_targets): ?>
            <form method="post" class="d-flex gap-1 align-items-center"
                  onsubmit="return this.to_course.value !== '' && confirm('Przenieść uczestnika do wybranej grupy?\n\nZapis w tej grupie zostanie zamknięty (historia frekwencji i rozliczeń zostaje), a kolejne zajęcia i rozliczenia pójdą w nowej grupie.')">
              <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op" value="move_student">
              <input type="hidden" name="client_id" value="<?= $cid ?>">
              <label class="visually-hidden" for="mvTo<?= $cid ?>">Grupa docelowa dla <?= h($p['name']) ?></label>
              <select name="to_course" id="mvTo<?= $cid ?>" class="form-select form-select-sm" style="max-width:190px" required>
                <option value="">— wybierz grupę —</option>
                <?php foreach ($u_targets as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-sm btn-outline-primary" title="Przenieś do wybranej grupy">
                <i class="bi bi-arrow-left-right" aria-hidden="true"></i><span class="visually-hidden">Przenieś</span>
              </button>
            </form>
            <?php elseif (!$inactive): ?><span class="text-muted small">brak innych grup</span>
            <?php endif; ?>
          </td>
          <td class="text-nowrap usos-noprint">
            <?php if (!$inactive): ?>
            <form method="post" onsubmit="return confirm('Wypisać uczestnika z tej grupy?\n\nHistoria frekwencji i rozliczeń zostaje — to tylko zamknięcie zapisu.')">
              <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op" value="unenroll">
              <input type="hidden" name="client_id" value="<?= $cid ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger" title="Wypisz z grupy">
                <i class="bi bi-person-dash" aria-hidden="true"></i><span class="visually-hidden">Wypisz</span>
              </button>
            </form>
            <?php else: ?><span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<p class="small text-body-secondary mt-2">
  Frekwencja liczona z lekcji, które już się odbyły (bez odwołanych i szkiców);
  odwołany udział uczestnika nie wchodzi do podstawy. Średnia to średnia ważona
  z e-dziennika — ocena końcowa jest osobno, w protokole.
</p>
