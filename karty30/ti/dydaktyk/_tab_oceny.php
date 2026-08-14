<?php /* ═══════════════════════ TAB: OCENY — e-dziennik (Dydaktyka 3.0) ═══════════════════════ */ ?>
<?php
  $course_row = k30_ti_course_get($cur_course);
  $grades_on  = !empty($course_row['grades_enabled']);
  $CATS       = k30_ti_grade_categories();

  $g_enrollees = db_all(
    "SELECT cl.id, cl.name, COALESCE(cl.ti_grades_enabled,1) AS grades_enabled
     FROM k30_ti_enrollments e JOIN k30_clients cl ON cl.id=e.client_id
     WHERE e.course_id=? AND e.status='active'
     ORDER BY cl.name COLLATE NOCASE",
    [$cur_course]
  );

  // Oceny chronologicznie (do macierzy)
  $g_rows = db_all(
    "SELECT g.*, cl.name AS client_name
     FROM k30_ti_grades g JOIN k30_clients cl ON cl.id=g.client_id
     WHERE g.course_id=?
     ORDER BY g.graded_at ASC, g.id ASC",
    [$cur_course]
  );
  $g_by_client = [];
  foreach ($g_rows as $g) $g_by_client[(int)$g['client_id']][] = $g;

  $g_edit_id = (int)($_GET['edit'] ?? 0);
  $g_edit    = $g_edit_id ? k30_ti_grade_get($g_edit_id) : null;
  if ($g_edit && (int)$g_edit['course_id'] !== $cur_course) $g_edit = null;

  // Midpoint date (for "śródroczna" split — first 50% of sorted grade dates)
  $all_dates = array_unique(array_map(fn($g) => substr($g['graded_at'], 0, 10), $g_rows));
  sort($all_dates);
  $mid_date  = count($all_dates) > 1 ? $all_dates[(int)(count($all_dates) / 2) - 1] : null;
?>

<style>
/* ── Dydaktyka 3.0 — Synergia-inspired grade matrix ─────────── */
.dyd30-bar { display:flex; align-items:center; flex-wrap:wrap; gap:.4rem; margin-bottom:.75rem; }

.dyd30-matrix-wrap { overflow-x:auto; }
.dyd30-matrix {
  border-collapse:collapse; font-size:.81rem; min-width:100%;
  table-layout:auto;
}
.dyd30-matrix th, .dyd30-matrix td {
  padding:3px 6px; border:1px solid var(--bs-border-color); vertical-align:middle;
}
.dyd30-matrix thead th {
  background:#415a77; color:#fff; text-align:center; white-space:nowrap;
  font-weight:600; letter-spacing:.02em;
}
.dyd30-matrix .col-name {
  font-weight:600; white-space:nowrap; min-width:130px;
  position:sticky; left:0; z-index:2;
  background:var(--bs-body-bg);
  border-right:2px solid var(--bs-border-color);
}
.dyd30-matrix .col-grades { padding:3px 4px; }
.dyd30-matrix .col-stat { text-align:center; white-space:nowrap; font-weight:600; min-width:48px; }
.dyd30-matrix tbody tr:hover .col-name,
.dyd30-matrix tbody tr:hover td { background:rgba(65,90,119,.06); }
.dyd30-matrix tbody tr:hover .col-name { background:rgba(65,90,119,.09); }

.gc {                       /* grade cell */
  display:inline-flex; align-items:center; justify-content:center;
  width:30px; height:30px; border-radius:5px;
  font-size:.75rem; font-weight:800; line-height:1;
  text-decoration:none; transition:filter .12s, box-shadow .12s;
  position:relative; border:none; cursor:pointer; padding:0; margin:1px;
  box-shadow:0 1px 2px rgba(0,0,0,.18);
  flex-shrink:0;
}
.gc:hover { filter:brightness(1.1); box-shadow:0 2px 6px rgba(0,0,0,.25); z-index:1; }
.gc .gc-cat {
  position:absolute; top:2px; left:3px;
  font-size:8px; font-weight:700; opacity:.72; line-height:1;
}
.gc-del {
  position:absolute; top:1px; right:1px;
  width:14px; height:14px; border-radius:50%;
  background:rgba(0,0,0,.45); color:#fff;
  font-size:9px; font-weight:700; line-height:14px; text-align:center;
  display:none; cursor:pointer; border:none; padding:0;
}
.gc:hover .gc-del { display:block; }

.dyd30-summary { border:2px solid var(--bs-border-color); border-radius:8px; overflow:hidden; margin-bottom:.25rem; }
.dyd30-summary-hdr { background:#415a77; color:#fff; padding:5px 12px; font-size:.83rem; font-weight:600; }
.dyd30-summary-row {
  display:flex; align-items:center; gap:8px; padding:5px 12px;
  border-top:1px solid var(--bs-border-color); font-size:.81rem; flex-wrap:wrap;
}

.dyd30-legend { display:flex; flex-wrap:wrap; gap:.3rem .9rem; font-size:.78rem; }
.dyd30-legend-item { display:inline-flex; align-items:center; gap:5px; }
.dyd30-legend-dot { width:16px; height:16px; border-radius:3px; flex-shrink:0; box-shadow:0 1px 2px rgba(0,0,0,.2); }
</style>

<!-- ── Pasek akcji ──────────────────────────────────────────── -->
<div class="dyd30-bar">
  <?php if (!$grades_on): ?>
  <div class="alert alert-warning py-1 px-3 mb-0 small d-flex align-items-center gap-2 flex-grow-1">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <span>Oceny w tym kursie są <strong>wyłączone</strong>.</span>
    <form method="post" class="ms-auto">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="grade_toggle_course">
      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
      <button class="btn btn-sm btn-warning py-0 px-2">Włącz oceny</button>
    </form>
  </div>
  <?php else: ?>
  <div class="d-flex align-items-center gap-1 me-auto">
    <i class="bi bi-journal-bookmark text-primary"></i>
    <span class="fw-semibold">E-dziennik ocen bieżących</span>
    <span class="badge bg-secondary ms-1"><?= count($g_rows) ?> ocen</span>
  </div>
  <form method="post" class="d-inline">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op" value="grade_toggle_course">
    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-toggle-on text-success me-1"></i>Oceny: wł.</button>
  </form>
  <?php if ($g_enrollees): ?>
  <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#gradeModal30">
    <i class="bi bi-plus-lg me-1"></i>Wystaw ocenę
  </button>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ── Macierz ocen ─────────────────────────────────────────── -->
<?php if (!$g_enrollees): ?>
<div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>Brak aktywnych kursantów w kursie.</div>
<?php else: ?>

<div class="card border-0 shadow-sm mb-3">
  <div class="dyd30-matrix-wrap">
    <table class="dyd30-matrix" aria-label="Macierz ocen kursantów">
      <thead>
        <tr>
          <th scope="col" class="col-name text-start">Kursant</th>
          <th scope="col" class="text-start ps-2" style="min-width:200px">Oceny bieżące</th>
          <th scope="col" class="col-stat">Ilość</th>
          <th scope="col" class="col-stat">Śr. ważona</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($g_enrollees as $en):
          $cid    = (int)$en['id'];
          $cgr    = $g_by_client[$cid] ?? [];
          $avg    = k30_ti_grades_average($cgr);
          [$abg, $afg] = k30_ti_grade_color($avg);
          $person_on   = (int)($en['grades_enabled'] ?? 1) === 1;
          $counted     = count(array_filter($cgr, fn($g) => isset($g['value_num']) && $g['value_num'] !== null));
        ?>
        <tr>
          <td class="col-name<?= $person_on ? '' : ' text-muted' ?>">
            <?= h($en['name']) ?>
            <?php if (!$person_on): ?>
            <i class="bi bi-slash-circle text-warning ms-1" title="Oceny wyłączone globalnie dla tej osoby"></i>
            <?php endif; ?>
          </td>
          <td class="col-grades">
            <?php if (!$cgr): ?>
            <span class="text-body-secondary small fst-italic">— brak ocen —</span>
            <?php else: ?>
            <div class="d-flex flex-wrap">
              <?php foreach ($cgr as $g):
                [$gbg, $gfg] = k30_ti_grade_color(isset($g['value_num']) ? (float)$g['value_num'] : null);
                $cat_short   = $CATS[$g['category'] ?? 'inne']['short'] ?? 'In';
                $cat_label   = k30_ti_grade_category_label((string)($g['category'] ?? 'inne'));
                $w     = rtrim(rtrim(number_format((float)($g['weight'] ?? 1), 2, '.', ''), '0'), '.');
                $title = $cat_label . ' · waga ' . ($w ?: '1')
                       . (($g['description'] ?? '') !== '' ? ' · ' . $g['description'] : '')
                       . ' · ' . substr((string)$g['graded_at'], 0, 10);
              ?>
              <div style="position:relative;display:inline-flex">
                <a href="index.php?course=<?= $cur_course ?>&tab=oceny&edit=<?= (int)$g['id'] ?>"
                   class="gc text-decoration-none"
                   style="background:<?= $gbg ?>;color:<?= $gfg ?>"
                   title="<?= h($title) ?>"
                   aria-label="Ocena <?= h($g['value_text']) ?> — <?= h($title) ?>">
                  <span class="gc-cat"><?= h($cat_short) ?></span>
                  <?= h($g['value_text']) ?>
                </a>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ocenę <?= h($g['value_text']) ?>?')">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="grade_delete">
                  <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                  <input type="hidden" name="grade_id" value="<?= (int)$g['id'] ?>">
                  <button type="submit" class="gc-del" title="Usuń">×</button>
                </form>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="col-stat"><?= count($cgr) ?></td>
          <td class="col-stat">
            <?php if ($avg !== null): ?>
            <span class="badge" style="background:<?= $abg ?>;color:<?= $afg ?>;font-size:.85rem;min-width:2.8rem">
              <?= number_format($avg, 2, ',', '') ?>
            </span>
            <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Podsumowanie (Synergia-style: Okres 1 / Ocena roczna) ── -->
<div class="row g-3 mb-3">
  <?php
  $periods = [
    ['key'=>'sr',  'label'=>'Okres 1 — ocena śródroczna', 'icon'=>'bi-calendar2-half',  'filter'=>fn($g)=>(!$mid_date || substr($g['graded_at'],0,10) <= $mid_date)],
    ['key'=>'rok', 'label'=>'Ocena roczna (wszystkie)',    'icon'=>'bi-calendar2-check', 'filter'=>fn($g)=>true],
  ];
  foreach ($periods as $period): ?>
  <div class="col-md-6">
    <div class="dyd30-summary">
      <div class="dyd30-summary-hdr"><i class="bi <?= $period['icon'] ?> me-2"></i><?= $period['label'] ?></div>
      <?php foreach ($g_enrollees as $en):
        $cid   = (int)$en['id'];
        $cgr   = array_values(array_filter($g_by_client[$cid] ?? [], $period['filter']));
        $avg   = k30_ti_grades_average($cgr);
        [$pbg, $pfg] = k30_ti_grade_color($avg);
        $pred  = $avg !== null ? number_format($avg, 2, ',', '') : null;
      ?>
      <div class="dyd30-summary-row">
        <span class="fw-semibold" style="min-width:120px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($en['name']) ?>"><?= h($en['name']) ?></span>
        <span class="text-body-secondary small flex-grow-1">Ocena przewidywana przez system</span>
        <?php if ($pred !== null): ?>
        <span class="badge ms-1" style="background:<?= $pbg ?>;color:<?= $pfg ?>;font-size:.82rem;min-width:2.6rem"><?= $pred ?></span>
        <?php else: ?>
        <span class="text-body-secondary fst-italic small ms-1">Brak ocen</span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Legenda ─────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm">
  <div class="card-body py-2 px-3">
    <div class="fw-semibold small mb-2 text-body-secondary text-uppercase" style="letter-spacing:.04em">Legenda</div>
    <div class="dyd30-legend mb-2">
      <?php foreach ([
        ['#dc3545','#fff','1 — niedostateczny'],
        ['#fd7e14','#fff','2 — dopuszczający'],
        ['#ffc107','#212529','3 — dostateczny'],
        ['#0dcaf0','#212529','4 — dobry'],
        ['#198754','#fff','5 — bardzo dobry'],
        ['#6f42c1','#fff','6 — celujący'],
        ['#6c757d','#fff','np / bz / nb (nie do średniej)'],
      ] as [$bg, $fg, $lbl]): ?>
      <span class="dyd30-legend-item">
        <span class="dyd30-legend-dot" style="background:<?= $bg ?>"></span>
        <span><?= $lbl ?></span>
      </span>
      <?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap gap-3" style="font-size:.77rem">
      <?php foreach ($CATS as $ck => $cv): ?>
      <span class="text-body-secondary"><strong><?= h($cv['short']) ?></strong> = <?= h($cv['label']) ?> (waga <?= $cv['weight'] ?>)</span>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php endif; /* $g_enrollees */ ?>

<!-- ══ Modal: wystaw / edytuj ocenę ════════════════════════════ -->
<div class="modal fade" id="gradeModal30" tabindex="-1" aria-labelledby="gm30Title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" id="gradeForm30">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="grade_save">
        <input type="hidden" name="course_id" value="<?= $cur_course ?>">
        <input type="hidden" name="grade_id" id="gm30_grade_id" value="<?= $g_edit ? (int)$g_edit['id'] : 0 ?>">
        <div class="modal-header py-2">
          <h2 class="modal-title h6 mb-0" id="gm30Title">
            <i class="bi bi-<?= $g_edit ? 'pencil' : 'plus-lg' ?> me-2" aria-hidden="true"></i><?= $g_edit ? 'Edytuj ocenę' : 'Wystaw ocenę' ?>
          </h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">

          <!-- Kursant -->
          <div class="mb-2">
            <label class="form-label small fw-semibold" for="gm30_client">Kursant <span class="text-danger">*</span></label>
            <select class="form-select form-select-sm" id="gm30_client" name="client_id" required>
              <option value="">— wybierz kursanta —</option>
              <?php foreach ($g_enrollees as $en): ?>
              <option value="<?= (int)$en['id'] ?>" <?= ($g_edit && (int)$g_edit['client_id']===(int)$en['id']) ? 'selected' : '' ?>><?= h($en['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Kategoria + waga -->
          <div class="row g-2 mb-2">
            <div class="col-7">
              <label class="form-label small fw-semibold" for="gm30_cat">Kategoria</label>
              <select class="form-select form-select-sm" id="gm30_cat" name="category">
                <?php foreach ($CATS as $ck => $cv): ?>
                <option value="<?= h($ck) ?>" data-weight="<?= $cv['weight'] ?>"
                  <?= (($g_edit['category'] ?? 'sprawdzian') === $ck) ? 'selected' : '' ?>>
                  <?= h($cv['label']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-5">
              <label class="form-label small fw-semibold" for="gm30_weight">Waga</label>
              <input type="number" class="form-control form-control-sm" id="gm30_weight" name="weight" min="0.5" max="10" step="0.5"
                     value="<?= h(rtrim(rtrim(number_format((float)($g_edit['weight'] ?? 3), 2, '.', ''), '0'), '.') ?: '3') ?>">
            </div>
          </div>

          <!-- Szybkie przyciski ocen (Synergia-style) -->
          <div class="mb-2">
            <label class="form-label small fw-semibold">Ocena <span class="text-danger">*</span></label>
            <div class="d-flex flex-wrap gap-1 mb-2">
              <?php foreach (['6','5+','5','5-','4+','4','4-','3+','3','3-','2+','2','2-','1'] as $q):
                $qn = k30_ti_grade_parse_num($q);
                [$qbg,$qfg] = k30_ti_grade_color($qn);
              ?>
              <button type="button" class="gc gc-q" style="background:<?= $qbg ?>;color:<?= $qfg ?>;width:32px;height:32px;box-shadow:0 1px 3px rgba(0,0,0,.2)" data-val="<?= h($q) ?>"><?= h($q) ?></button>
              <?php endforeach; ?>
              <?php foreach (['np','bz','nb'] as $q): ?>
              <button type="button" class="gc gc-q" style="background:#6c757d;color:#fff;width:32px;height:32px;font-size:.7rem" data-val="<?= h($q) ?>" title="Nie liczy się do średniej"><?= h($q) ?></button>
              <?php endforeach; ?>
            </div>
            <input type="text" class="form-control form-control-sm" id="gm30_value" name="value_text" maxlength="8"
                   value="<?= h($g_edit['value_text'] ?? '') ?>" required placeholder="np. 5, 4+, 2-, np">
          </div>

          <!-- Opis -->
          <div class="mb-2">
            <label class="form-label small fw-semibold" for="gm30_desc">Za co / opis <span class="text-body-secondary">(opc.)</span></label>
            <input type="text" class="form-control form-control-sm" id="gm30_desc" name="description" maxlength="300"
                   value="<?= h($g_edit['description'] ?? '') ?>" placeholder="np. Sprawdzian — rozdział 3">
          </div>

          <!-- Lekcja -->
          <div class="mb-2">
            <label class="form-label small fw-semibold" for="gm30_sess">Lekcja <span class="text-body-secondary">(opc.)</span></label>
            <select class="form-select form-select-sm" id="gm30_sess" name="session_id">
              <option value="">— bez powiązania —</option>
              <?php foreach ($all_sessions as $ss): ?>
              <option value="<?= (int)$ss['id'] ?>" <?= ($g_edit && (int)($g_edit['session_id'] ?? 0) === (int)$ss['id']) ? 'selected' : '' ?>>
                <?= h(date('d.m.Y', strtotime($ss['lesson_date']))) ?><?= $ss['topic'] ? ' · ' . h(mb_substr($ss['topic'], 0, 32)) : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Powiadomienie -->
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="gm30_notify" name="notify" value="1" <?= $g_edit ? '' : 'checked' ?>>
            <label class="form-check-label small" for="gm30_notify">Powiadom kursanta e-mailem o ocenie</label>
          </div>
        </div>
        <div class="modal-footer py-2">
          <?php if ($g_edit): ?>
          <a href="index.php?course=<?= $cur_course ?>&tab=oceny" class="btn btn-sm btn-outline-secondary me-auto">
            <i class="bi bi-x me-1"></i>Anuluj edycję
          </a>
          <?php endif; ?>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Zamknij</button>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i><?= $g_edit ? 'Zapisz zmiany' : 'Wystaw ocenę' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  // Auto-waga z kategorii
  var cat = document.getElementById('gm30_cat');
  var wgt = document.getElementById('gm30_weight');
  var val = document.getElementById('gm30_value');
  if (cat && wgt) cat.addEventListener('change', function(){
    var dw = cat.selectedOptions[0] && cat.selectedOptions[0].getAttribute('data-weight');
    if (dw) wgt.value = dw;
  });
  // Szybkie przyciski
  if (val) document.querySelectorAll('.gc-q').forEach(function(b){
    b.addEventListener('click', function(){ val.value = b.dataset.val; val.focus(); });
  });
  <?php if ($g_edit): ?>
  // Auto-otwórz modal przy edycji
  var el = document.getElementById('gradeModal30');
  if (el && window.bootstrap) { new bootstrap.Modal(el).show(); }
  <?php endif; ?>
})();
</script>
