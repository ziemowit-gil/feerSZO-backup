<?php
/**
 * karty30/ti/dydaktyk/kadraLimity.php — Limity godzin i kwalifikacje kadry (kierownik).
 *
 * Trzy sekcje: (1) limity godzin trenera (dzień / tydzień / miesiąc / ciąg zajęć,
 * opcjonalnie „twarde"), (2) kwalifikacje trenerów, (3) wymagania — które rodzaje
 * zajęć / ścieżki mogą prowadzić tylko trenerzy z kwalifikacją. Egzekwuje to
 * sc_validate() (planista, przesuwanie lekcji).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerValidator.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
ti_sched_migrate();
$uid = (int)$me['user_id'];

$subjects = db_all("SELECT id, name FROM k30_ti_subject_types ORDER BY name");
$paths    = db_all("SELECT id, name FROM k30_pl_tech_paths WHERE is_active=1 ORDER BY name");
$refs = ['subject' => $subjects, 'path' => $paths];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    $to_min = fn($h) => max(0, (int)round((float)str_replace(',', '.', (string)$h) * 60));
    if ($op === 'limits') {
        $iid = (int)($_POST['instructor_id'] ?? 0);
        if ($iid) {
            $vals = [$to_min($_POST['day_h'] ?? 0), $to_min($_POST['week_h'] ?? 0), $to_min($_POST['month_h'] ?? 0),
                     max(0, (int)($_POST['consecutive_min'] ?? 0)), empty($_POST['hard']) ? 0 : 1, substr(trim((string)($_POST['note'] ?? '')), 0, 200), $uid];
            db_exec("INSERT INTO k30_sc_instructor_limits (instructor_id,max_day_min,max_week_min,max_month_min,max_consecutive_min,hard,note,updated_by,updated_at)
                     VALUES (?,?,?,?,?,?,?,?,datetime('now'))
                     ON CONFLICT(instructor_id) DO UPDATE SET max_day_min=excluded.max_day_min, max_week_min=excluded.max_week_min,
                       max_month_min=excluded.max_month_min, max_consecutive_min=excluded.max_consecutive_min, hard=excluded.hard,
                       note=excluded.note, updated_by=excluded.updated_by, updated_at=datetime('now')", array_merge([$iid], $vals));
            pl_audit('sc_limits', $iid, 'save', [], $_POST, $uid);
            flash_set('success', 'Zapisano limity trenera.');
        }
    } elseif ($op === 'qual_add') {
        $iid = (int)($_POST['instructor_id'] ?? 0); $kind = (string)($_POST['ref_kind'] ?? ''); $ref = (int)($_POST['ref_id'] ?? 0);
        $vt = (string)($_POST['valid_to'] ?? '');
        if ($iid && isset(SC_REF_KINDS[$kind]) && $ref) {
            db_exec("INSERT INTO k30_sc_qualifications (instructor_id,ref_kind,ref_id,level,valid_to,note,created_by) VALUES (?,?,?,?,?,?,?)
                     ON CONFLICT(instructor_id,ref_kind,ref_id) DO UPDATE SET level=excluded.level, valid_to=excluded.valid_to, note=excluded.note",
                [$iid, $kind, $ref, substr(trim((string)($_POST['level'] ?? '')), 0, 40), preg_match('/^\d{4}-\d{2}-\d{2}$/', $vt) ? $vt : '', substr(trim((string)($_POST['note'] ?? '')), 0, 200), $uid]);
            flash_set('success', 'Zapisano kwalifikację.');
        } else flash_set('danger', 'Wybierz trenera i rodzaj / ścieżkę.');
    } elseif ($op === 'qual_del') {
        db_exec("DELETE FROM k30_sc_qualifications WHERE id=?", [(int)($_POST['id'] ?? 0)]);
        flash_set('success', 'Usunięto kwalifikację.');
    } elseif ($op === 'req_set') {
        $kind = (string)($_POST['ref_kind'] ?? ''); $ref = (int)($_POST['ref_id'] ?? 0);
        if (isset(SC_REF_KINDS[$kind]) && $ref) {
            if (!empty($_POST['on'])) db_exec("INSERT OR IGNORE INTO k30_sc_requirements (ref_kind,ref_id) VALUES (?,?)", [$kind, $ref]);
            else db_exec("DELETE FROM k30_sc_requirements WHERE ref_kind=? AND ref_id=?", [$kind, $ref]);
            flash_set('success', 'Zapisano wymaganie.');
        }
    }
    header('Location: kadraLimity.php'); exit;
}

$instructors = db_all(
    "SELECT u.id, u.name FROM users u
      WHERE u.id IN (SELECT instructor_id FROM k30_ti_courses WHERE instructor_id IS NOT NULL)
         OR u.id IN (SELECT user_id FROM k30_ti_panel_roles)
         OR u.id IN (SELECT instructor_id FROM k30_sc_instructor_limits)
         OR u.id IN (SELECT instructor_id FROM k30_sc_qualifications)
      ORDER BY u.name");
$limits = []; foreach (db_all("SELECT * FROM k30_sc_instructor_limits") as $l) $limits[(int)$l['instructor_id']] = $l;
$quals = db_all("SELECT q.*, u.name AS uname FROM k30_sc_qualifications q JOIN users u ON u.id=q.instructor_id ORDER BY u.name, q.ref_kind");
$req = []; foreach (db_all("SELECT ref_kind, ref_id FROM k30_sc_requirements") as $r) $req[$r['ref_kind'] . ':' . $r['ref_id']] = true;
$hf = fn(int $m) => $m ? rtrim(rtrim(number_format($m / 60, 2, '.', ''), '0'), '.') : '';

$KP_TITLE  = 'Limity i kwalifikacje kadry — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php $PL_CUR = 'kadraLimity.php'; $PL_LABEL = 'Limity i kwalifikacje'; include __DIR__ . '/_planista_bar.php'; ?>
<main id="main" class="dyd-wrap">
<?= flash_html() ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="h4 mb-0"><i class="bi bi-person-gear me-2 text-primary"></i>Limity i kwalifikacje kadry</h1>
  <a class="btn btn-outline-secondary btn-sm ms-auto" href="planista.php"><i class="bi bi-calendar3-range me-1"></i>Planista zajęć</a>
</div>
<p class="text-body-secondary small">Puste pole = brak limitu. Limit „twardy” blokuje zapis terminu (kierownik może go świadomie zatwierdzić), miękki tylko ostrzega.
  Ciąg zajęć to lekcje oddzielone przerwą do <?= (int)SC_CHAIN_GAP_MIN ?> min.</p>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-semibold bg-body-tertiary"><i class="bi bi-speedometer2 me-1"></i>Limity godzin trenerów</div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th scope="col">Trener</th><th scope="col">Dzień (h)</th><th scope="col">Tydzień (h)</th><th scope="col">Miesiąc (h)</th><th scope="col">Ciąg (min)</th><th scope="col">Twardy</th><th scope="col">Notatka</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($instructors as $u): $l = $limits[(int)$u['id']] ?? null; $fid = 'lf' . (int)$u['id']; ?>
      <tr>
        <td class="fw-semibold"><?= h($u['name']) ?></td>
        <td><input form="<?= $fid ?>" name="day_h" class="form-control form-control-sm" style="width:5rem" inputmode="decimal" value="<?= h($l ? $hf((int)$l['max_day_min']) : '') ?>" aria-label="Limit dzienny, godziny"></td>
        <td><input form="<?= $fid ?>" name="week_h" class="form-control form-control-sm" style="width:5rem" inputmode="decimal" value="<?= h($l ? $hf((int)$l['max_week_min']) : '') ?>" aria-label="Limit tygodniowy, godziny"></td>
        <td><input form="<?= $fid ?>" name="month_h" class="form-control form-control-sm" style="width:5rem" inputmode="decimal" value="<?= h($l ? $hf((int)$l['max_month_min']) : '') ?>" aria-label="Limit miesięczny, godziny"></td>
        <td><input form="<?= $fid ?>" name="consecutive_min" class="form-control form-control-sm" style="width:5rem" inputmode="numeric" value="<?= h($l && (int)$l['max_consecutive_min'] ? (string)(int)$l['max_consecutive_min'] : '') ?>" aria-label="Maksymalny ciąg zajęć, minuty"></td>
        <td><input form="<?= $fid ?>" type="checkbox" class="form-check-input" name="hard" value="1" <?= $l && !empty($l['hard']) ? 'checked' : '' ?> aria-label="Limit twardy"></td>
        <td><input form="<?= $fid ?>" name="note" class="form-control form-control-sm" maxlength="200" value="<?= h($l['note'] ?? '') ?>" aria-label="Notatka"></td>
        <td><form id="<?= $fid ?>" method="post"><?= csrf_field() ?><input type="hidden" name="_op" value="limits"><input type="hidden" name="instructor_id" value="<?= (int)$u['id'] ?>"><button class="btn btn-primary btn-sm">Zapisz</button></form></td>
      </tr>
    <?php endforeach; if (!$instructors): ?><tr><td colspan="8" class="text-body-secondary p-3">Brak trenerów.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold bg-body-tertiary"><i class="bi bi-patch-check me-1"></i>Kwalifikacje trenerów</div>
      <form method="post" class="card-body border-bottom row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="_op" value="qual_add">
        <div class="col-md-4"><label class="form-label small mb-0" for="qi">Trener</label><select class="form-select form-select-sm" id="qi" name="instructor_id" required><option value="">—</option><?php foreach ($instructors as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small mb-0" for="qk">Typ</label><select class="form-select form-select-sm" id="qk" name="ref_kind"><?php foreach (SC_REF_KINDS as $k => $lbl): ?><option value="<?= h($k) ?>"><?= h($lbl) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><label class="form-label small mb-0" for="qr">Rodzaj / ścieżka</label><select class="form-select form-select-sm" id="qr" name="ref_id" required></select></div>
        <div class="col-md-3"><label class="form-label small mb-0" for="ql">Poziom</label><input class="form-control form-control-sm" id="ql" name="level" maxlength="40"></div>
        <div class="col-md-3"><label class="form-label small mb-0" for="qv">Ważne do</label><input type="date" class="form-control form-control-sm" id="qv" name="valid_to"></div>
        <div class="col-md-4"><label class="form-label small mb-0" for="qn">Notatka</label><input class="form-control form-control-sm" id="qn" name="note" maxlength="200"></div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Dodaj</button></div>
      </form>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th scope="col">Trener</th><th scope="col">Kwalifikacja</th><th scope="col">Poziom</th><th scope="col">Ważne do</th><th></th></tr></thead><tbody>
        <?php foreach ($quals as $q): $exp = $q['valid_to'] !== '' && $q['valid_to'] < date('Y-m-d'); ?>
          <tr><td><?= h($q['uname']) ?></td><td><?= h(sc_ref_label($q['ref_kind'], (int)$q['ref_id'])) ?></td><td><?= h($q['level']) ?></td>
            <td><?= $q['valid_to'] === '' ? '—' : h($q['valid_to']) ?><?= $exp ? ' <span class="badge text-bg-danger">wygasła</span>' : '' ?></td>
            <td class="text-end"><form method="post" class="d-inline" onsubmit="return confirm('Usunąć kwalifikację?')"><?= csrf_field() ?><input type="hidden" name="_op" value="qual_del"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>"><button class="btn btn-outline-danger btn-sm py-0" aria-label="Usuń"><i class="bi bi-trash"></i></button></form></td></tr>
        <?php endforeach; if (!$quals): ?><tr><td colspan="5" class="text-body-secondary p-3">Brak zapisanych kwalifikacji.</td></tr><?php endif; ?>
      </tbody></table></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold bg-body-tertiary"><i class="bi bi-shield-lock me-1"></i>Wymagane kwalifikacje</div>
      <div class="card-body small text-body-secondary pb-0">Zaznaczone pozycje mogą prowadzić tylko trenerzy z ważną kwalifikacją — przypisanie innego trenera zostanie zablokowane.</div>
      <ul class="list-group list-group-flush">
      <?php foreach ($refs as $kind => $items): foreach ($items as $it): $on = isset($req[$kind . ':' . $it['id']]); ?>
        <li class="list-group-item d-flex align-items-center gap-2">
          <form method="post" class="d-flex align-items-center gap-2 w-100"><?= csrf_field() ?><input type="hidden" name="_op" value="req_set"><input type="hidden" name="ref_kind" value="<?= h($kind) ?>"><input type="hidden" name="ref_id" value="<?= (int)$it['id'] ?>">
            <input type="checkbox" class="form-check-input mt-0" name="on" value="1" id="rq<?= h($kind . $it['id']) ?>" <?= $on ? 'checked' : '' ?> onchange="this.form.submit()">
            <label class="form-check-label flex-grow-1" for="rq<?= h($kind . $it['id']) ?>"><?= h($it['name']) ?></label>
            <span class="badge text-bg-light border"><?= h(SC_REF_KINDS[$kind]) ?></span></form>
        </li>
      <?php endforeach; endforeach; if (!$subjects && !$paths): ?><li class="list-group-item text-body-secondary">Brak rodzajów zajęć i ścieżek.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>
</main>
<script>
(() => {
  const refs = <?= json_encode(array_map(fn($a) => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name']], $a), $refs), JSON_UNESCAPED_UNICODE) ?>;
  const k = document.getElementById('qk'), r = document.getElementById('qr');
  const fill = () => { r.innerHTML = (refs[k.value] || []).map(x => '<option value="' + x.id + '">' + x.name.replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])) + '</option>').join(''); };
  k.addEventListener('change', fill); fill();
})();
</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
