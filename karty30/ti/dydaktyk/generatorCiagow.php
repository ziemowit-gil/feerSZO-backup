<?php
/**
 * karty30/ti/dydaktyk/generatorCiagow.php — Generator ciągów zajęć (kierownik).
 * Profile struktury czasu (ciągły / weekendowy / wieczorowy), układanie lekcji z pominięciem
 * świąt i dni wolnych, przerwami i limitem godzin pod rząd; podgląd przed zapisem.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerSeries.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
ti_sched_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'profile_save') {
        $id = (int)($_POST['id'] ?? 0);
        $days = implode(',', array_values(array_unique(array_filter(array_map('intval', (array)($_POST['days'] ?? [])), fn($x) => $x >= 1 && $x <= 7))));
        $tm = fn($k, $d) => preg_match('/^\d{2}:\d{2}$/', (string)($_POST[$k] ?? '')) ? (string)$_POST[$k] : $d;
        $f = [trim((string)($_POST['name'] ?? '')) ?: 'Profil', $days ?: '1,2,3,4,5', $tm('start_time', '08:00'), $tm('end_time', '16:00'), max(15, (int)($_POST['unit_min'] ?? 45)),
              max(0, (int)($_POST['break_min'] ?? 10)), preg_match('/^\d{2}:\d{2}$/', (string)($_POST['lunch_from'] ?? '')) ? $_POST['lunch_from'] : '', max(0, (int)($_POST['lunch_min'] ?? 0)),
              max(15, (int)($_POST['max_consecutive_min'] ?? 180)), max(1, (int)($_POST['every_weeks'] ?? 1)), empty($_POST['is_active']) ? 0 : 1];
        if ($id) db_exec("UPDATE k30_sc_day_profiles SET name=?,days=?,start_time=?,end_time=?,unit_min=?,break_min=?,lunch_from=?,lunch_min=?,max_consecutive_min=?,every_weeks=?,is_active=? WHERE id=?", array_merge($f, [$id]));
        else db_exec("INSERT INTO k30_sc_day_profiles (name,days,start_time,end_time,unit_min,break_min,lunch_from,lunch_min,max_consecutive_min,every_weeks,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)", $f);
        flash_set('success', 'Zapisano profil.');
    }
    header('Location: generatorCiagow.php'); exit;
}

$profiles = sc_profiles(false);
$courses = db_all("SELECT id, name, group_code FROM k30_ti_courses WHERE COALESCE(closed_at,'')='' AND COALESCE(status,'active')!='cancelled' AND is_active=1 ORDER BY name");
$instr = k30_get_consultants();
$rooms = db_all("SELECT id, name FROM k30_pl_rooms WHERE is_active=1 ORDER BY name");
$year = (int)date('Y');
$DN = [1 => 'Pn', 2 => 'Wt', 3 => 'Śr', 4 => 'Czw', 5 => 'Pt', 6 => 'Sb', 7 => 'Nd'];

$KP_TITLE  = 'Generator ciągów zajęć — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php $KIER_CUR = 'generatorCiagow.php'; $KIER_LABEL = 'Generator ciągów zajęć'; include __DIR__ . '/_kierownik_bar.php'; ?>
<main id="main" class="dyd-wrap">
<?= flash_html() ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="h4 mb-0"><i class="bi bi-magic me-2 text-primary"></i>Generator ciągów zajęć</h1>
  <a class="btn btn-outline-secondary btn-sm ms-auto" href="planista.php"><i class="bi bi-calendar3-range me-1"></i>Planista zajęć</a>
</div>
<p class="text-body-secondary small">Układa lekcje grupy według profilu (ciągły, weekendowy, wieczorowy) do wskazanego wymiaru godzin. Pomija święta ustawowe, dni wolne z kalendarza TI, urlopy trenera i okresy zamknięte, wstawia przerwy i pilnuje maksymalnej liczby godzin pod rząd oraz dziennego limitu trenera. Dni z twardym konfliktem są pomijane, a wymiar uzupełniany dalej.</p>

<div class="card border-0 shadow-sm mb-4"><div class="card-header fw-semibold bg-body-tertiary">Nowy ciąg zajęć</div>
<div class="card-body row g-2 align-items-end">
  <div class="col-md-4"><label class="form-label small mb-0" for="gC">Grupa</label><select class="form-select form-select-sm" id="gC"><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h(($c['group_code'] ? $c['group_code'] . ' · ' : '') . $c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label small mb-0" for="gP">Profil</label><select class="form-select form-select-sm" id="gP"><?php foreach ($profiles as $p) if ($p['is_active']): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endif; ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-0" for="gH">Wymiar (godz.)</label><input type="number" class="form-control form-control-sm" id="gH" value="40" min="1" step="0.5"></div>
  <div class="col-md-2"><label class="form-label small mb-0" for="gU">Godzina to</label><select class="form-select form-select-sm" id="gU"><option value="45">45 min</option><option value="60">60 min</option></select></div>
  <div class="col-md-3"><label class="form-label small mb-0" for="gS">Od dnia</label><input type="date" class="form-control form-control-sm" id="gS" value="<?= date('Y-m-d') ?>"></div>
  <div class="col-md-4"><label class="form-label small mb-0" for="gI">Trener</label><select class="form-select form-select-sm" id="gI"><option value="0">— prowadzący grupy —</option><?php foreach ($instr as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['display_name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label small mb-0" for="gR">Sala</label><select class="form-select form-select-sm" id="gR"><option value="0">— bez sali —</option><?php foreach ($rooms as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h($r['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><button type="button" class="btn btn-primary btn-sm w-100" id="gGo">Podgląd</button></div>
</div>
<div class="card-body border-top" id="gOut" hidden></div></div>

<div class="row g-3">
  <div class="col-lg-8"><div class="card border-0 shadow-sm">
    <div class="card-header fw-semibold bg-body-tertiary">Profile struktury czasu</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th scope="col">Nazwa</th><th scope="col">Dni</th><th scope="col">Godziny</th><th scope="col">Lekcja / przerwa</th><th scope="col">Obiad</th><th scope="col">Max pod rząd</th><th scope="col">Co ile tyg.</th><th></th></tr></thead><tbody>
      <?php foreach ($profiles as $p): $fid = 'pf' . (int)$p['id']; $pd = array_map('intval', explode(',', $p['days'])); ?>
        <tr<?= $p['is_active'] ? '' : ' class="text-body-secondary"' ?>>
          <td><input form="<?= $fid ?>" name="name" class="form-control form-control-sm" value="<?= h($p['name']) ?>" aria-label="Nazwa"></td>
          <td class="text-nowrap"><?php foreach ($DN as $k => $l): ?><label class="me-1 small"><input form="<?= $fid ?>" type="checkbox" class="form-check-input" name="days[]" value="<?= $k ?>" <?= in_array($k, $pd, true) ? 'checked' : '' ?>><?= $l ?></label><?php endforeach; ?></td>
          <td class="text-nowrap"><input form="<?= $fid ?>" type="time" name="start_time" class="form-control form-control-sm d-inline-block" style="width:6.5rem" value="<?= h($p['start_time']) ?>" aria-label="Początek"> – <input form="<?= $fid ?>" type="time" name="end_time" class="form-control form-control-sm d-inline-block" style="width:6.5rem" value="<?= h($p['end_time']) ?>" aria-label="Koniec"></td>
          <td class="text-nowrap"><input form="<?= $fid ?>" type="number" name="unit_min" class="form-control form-control-sm d-inline-block" style="width:4.2rem" value="<?= (int)$p['unit_min'] ?>" aria-label="Minut w godzinie lekcyjnej"> / <input form="<?= $fid ?>" type="number" name="break_min" class="form-control form-control-sm d-inline-block" style="width:4.2rem" value="<?= (int)$p['break_min'] ?>" aria-label="Przerwa, minuty"></td>
          <td class="text-nowrap"><input form="<?= $fid ?>" type="time" name="lunch_from" class="form-control form-control-sm d-inline-block" style="width:6.5rem" value="<?= h($p['lunch_from']) ?>" aria-label="Obiad od"> <input form="<?= $fid ?>" type="number" name="lunch_min" class="form-control form-control-sm d-inline-block" style="width:4.2rem" value="<?= (int)$p['lunch_min'] ?>" aria-label="Obiad, minuty"></td>
          <td><input form="<?= $fid ?>" type="number" name="max_consecutive_min" class="form-control form-control-sm" style="width:5rem" value="<?= (int)$p['max_consecutive_min'] ?>" aria-label="Maks. minut pod rząd"></td>
          <td><input form="<?= $fid ?>" type="number" name="every_weeks" class="form-control form-control-sm" style="width:4.2rem" min="1" value="<?= (int)$p['every_weeks'] ?>" aria-label="Co ile tygodni"></td>
          <td class="text-nowrap"><label class="small me-2"><input form="<?= $fid ?>" type="checkbox" class="form-check-input" name="is_active" value="1" <?= $p['is_active'] ? 'checked' : '' ?>> aktywny</label>
            <form id="<?= $fid ?>" method="post" class="d-inline"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="profile_save"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-primary btn-sm py-0">Zapisz</button></form></td>
        </tr>
      <?php endforeach; ?>
      <tr><td colspan="8" class="p-2"><form method="post" class="d-flex gap-2 align-items-center"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="profile_save"><input type="hidden" name="id" value="0">
        <input name="name" class="form-control form-control-sm" style="max-width:18rem" placeholder="Nowy profil (domyślnie Pn–Pt 8–16)" aria-label="Nazwa nowego profilu"><button class="btn btn-outline-primary btn-sm">Dodaj profil</button></form></td></tr>
      </tbody></table></div></div></div>
  <div class="col-lg-4"><div class="card border-0 shadow-sm"><div class="card-header fw-semibold bg-body-tertiary">Święta ustawowe <?= $year ?>–<?= $year + 1 ?></div>
    <ul class="list-group list-group-flush small">
      <?php foreach ([$year, $year + 1] as $y) foreach (sc_pl_holidays($y) as $d => $n): if ($d < date('Y-m-d')) continue; ?><li class="list-group-item d-flex justify-content-between"><span><?= h(date('d.m.Y', strtotime($d))) ?> <small class="text-body-secondary"><?= h($DN[(int)date('N', strtotime($d))]) ?></small></span><span><?= h($n) ?></span></li><?php endforeach; ?>
    </ul><div class="card-body small text-body-secondary">Dodatkowe dni wolne i przerwy: <a href="dni_wolne.php">Dni wolne</a>.</div></div></div>
</div>
</main>
<script>
(() => {
'use strict';
const TOKEN = <?= json_encode(dyd_token()) ?>, $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const DN = ['Nd','Pn','Wt','Śr','Czw','Pt','Sb'];
const fmt = m => Math.floor(m/60) + ' h ' + String(m%60).padStart(2,'0') + ' min';
async function call(action) {
  const fd = new FormData(); fd.append('_token', TOKEN); fd.append('action', action);
  fd.append('course_id', $('gC').value); fd.append('profile_id', $('gP').value); fd.append('total_hours', $('gH').value); fd.append('hour_min', $('gU').value);
  fd.append('start_date', $('gS').value); fd.append('instructor_id', $('gI').value); fd.append('room_id', $('gR').value);
  return (await fetch('planistaAjax.php', { method: 'POST', body: fd, credentials: 'same-origin' })).json();
}
function render(j) {
  const o = $('gOut'); o.hidden = false;
  if (!j.ok) { o.innerHTML = '<div class="text-danger">' + esc(j.msg || 'Błąd') + '</div>'; return; }
  const by = {}; j.sessions.forEach(s => (by[s.date] = by[s.date] || []).push(s));
  const rows = Object.entries(by).map(([d, ss]) => '<tr><td>' + esc(DN[new Date(d + 'T12:00:00').getDay()] + ' ' + d) + '</td><td>' + ss.map(s => esc(s.from + '–' + s.to)).join(', ') + '</td><td>' + fmt(ss.reduce((a, s) => a + s.min, 0)) + '</td><td class="small text-warning">' + esc([...new Set(ss.flatMap(s => s.warn))].join(' | ')) + '</td></tr>').join('');
  const short = j.placed_min < j.total_min;
  o.innerHTML = '<div class="mb-2"><strong>' + j.sessions.length + '</strong> lekcji w <strong>' + Object.keys(by).length + '</strong> dniach, razem ' + fmt(j.placed_min) + ' z ' + fmt(j.total_min) + (j.end_date ? ', do <strong>' + esc(j.end_date) + '</strong>' : '') + '.'
    + (short ? ' <span class="text-danger">Nie udało się ułożyć całego wymiaru.</span>' : '') + '</div>'
    + '<div class="table-responsive" style="max-height:320px;overflow:auto"><table class="table table-sm mb-2"><thead><tr><th>Dzień</th><th>Bloki</th><th>Czas</th><th>Ostrzeżenia</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
    + (j.skipped.length ? '<details class="small mb-2"><summary>Pominięte dni (' + j.skipped.length + ')</summary><ul class="mb-0">' + j.skipped.map(s => '<li>' + esc(s.date + ' — ' + s.reason) + '</li>').join('') + '</ul></details>' : '')
    + (j.sessions.length ? '<button type="button" class="btn btn-success btn-sm" id="gApply">Zapisz ' + j.sessions.length + ' lekcji w planie</button>' : '');
  const b = $('gApply'); if (b) b.onclick = async () => {
    if (!confirm('Dodać ' + j.sessions.length + ' lekcji do planu oficjalnego grupy?')) return;
    const r = await call('series_apply'); o.innerHTML = '<div class="' + (r.ok ? 'text-success' : 'text-danger') + '">' + esc(r.msg || '') + '</div>';
  };
}
$('gGo').onclick = async () => { $('gOut').hidden = false; $('gOut').textContent = 'Układam…'; render(await call('series_preview')); };
})();
</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
