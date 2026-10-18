<?php
/**
 * _tab_grupy.php — Przegląd wszystkich grup TI (Kierownik).
 * Widok zarządczy: wszystkie kursy ze stanem rozliczeń, prowadzącym, frekwencją.
 * Tylko dla dyd_is_staff().
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_payments.php';
ti_payments_migrate();

// Filtr: aktywne / wszystkie
$gr_filter = in_array($_GET['f'] ?? 'active', ['active','all'], true) ? ($_GET['f'] ?? 'active') : 'active';
// Widok: karty (domyślny) / tabela — przełącznik w pasku narzędzi
$gr_view = ($_GET['v'] ?? 'karty') === 'tabela' ? 'tabela' : 'karty';

$gr_all_courses = k30_ti_courses($gr_filter !== 'all');  // false = wszystkie; true = tylko aktywne

// Pobierz liczbę kursantów z niedopłatą per kurs (jedno zapytanie)
ti_course_close_migrate();   // kolumna closed_at (status „Wygaszona”)
$gr_debt_map = [];
if ($gr_all_courses) {
    $ids = array_column($gr_all_courses, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $debt_rows = db_all(
        "SELECT e.course_id, COUNT(DISTINCT e.client_id) AS n
         FROM k30_ti_enrollments e
         JOIN k30_ti_billing b ON b.client_id=e.client_id AND b.status='issued'
              AND COALESCE(b.course_id,0) IN (0, e.course_id) AND COALESCE(b.hours_only,0)=0
              AND (b.amount + COALESCE(b.adjustment,0)) - COALESCE(b.paid_amount,0) > 0.005   -- niedopłata W TEJ grupie (lub w rozliczeniu łącznym)
         WHERE e.course_id IN ($ph) AND e.status='active'
         GROUP BY e.course_id",
        $ids
    );
    foreach ($debt_rows as $dr) $gr_debt_map[(int)$dr['course_id']] = (int)$dr['n'];
}

// Najbliższa lekcja per kurs
$gr_next_map = [];
if ($gr_all_courses) {
    $ids = array_column($gr_all_courses, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $nl  = db_all(
        "SELECT course_id, lesson_date, time_from
         FROM k30_ti_sessions
         WHERE course_id IN ($ph) AND lesson_date >= date('now') AND status='planned'
         GROUP BY course_id HAVING lesson_date=MIN(lesson_date)",
        $ids
    );
    foreach ($nl as $r) $gr_next_map[(int)$r['course_id']] = $r;
}

$gr_active   = array_filter($gr_all_courses, fn($c) => !empty($c['is_active']) && ($c['status']??'')!=='cancelled');
$gr_inactive = array_filter($gr_all_courses, fn($c) =>  empty($c['is_active']) || ($c['status']??'')==='cancelled');

$_dow = ['Mon'=>'Pn','Tue'=>'Wt','Wed'=>'Śr','Thu'=>'Cz','Fri'=>'Pt','Sat'=>'Sb','Sun'=>'Nd'];
?>

<section aria-label="Przegląd grup" class="dyd-gr-wrap<?= $gr_view === 'tabela' ? ' dyd-gr-wrap--wide' : '' ?>">
<style>
.dyd-gr-wrap { padding: 1.1rem 0 2.5rem; max-width: 960px; }
.dyd-gr-wrap--wide { max-width: none; }

/* Pasek nad listą */
.dyd-gr-toolbar {
  display: flex; align-items: center; gap: .65rem; flex-wrap: wrap;
  margin-bottom: 1rem;
}
.dyd-gr-toolbar-title { font-size: 1rem; font-weight: 700; flex: 1; }

/* Sekcja (aktywne / nieaktywne) */
.dyd-gr-section-head {
  font-size: .72rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: var(--bs-secondary-color);
  margin: 1rem 0 .5rem; padding-bottom: .25rem;
  border-bottom: 1px solid var(--bs-border-color);
}

/* Karta grupy */
.dyd-gr-card {
  display: flex; align-items: flex-start; gap: .85rem;
  padding: .85rem 1rem; background: var(--bs-body-bg);
  border: 1px solid var(--bs-border-color); border-radius: 12px;
  margin-bottom: .5rem; text-decoration: none; color: inherit;
  transition: border-color .12s, box-shadow .12s;
}
.dyd-gr-card:hover { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.dyd-gr-card.inactive { opacity: .65; }

.dyd-gr-icon {
  width: 38px; height: 38px; border-radius: 9px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.15rem; background: rgba(37,99,235,.08); color: #2563eb;
}
[data-bs-theme="dark"] .dyd-gr-icon { background: rgba(96,165,250,.1); color: #60a5fa; }
.dyd-gr-card.inactive .dyd-gr-icon { background: rgba(100,116,139,.1); color: var(--bs-secondary-color); }

.dyd-gr-body { flex: 1; min-width: 0; }
.dyd-gr-name { font-weight: 700; font-size: .95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dyd-gr-meta { display: flex; flex-wrap: wrap; gap: .3rem .8rem; margin-top: .3rem; font-size: .78rem; color: var(--bs-secondary-color); }
.dyd-gr-code { font-size: .68rem; font-family: var(--bs-font-monospace); }

.dyd-gr-side {
  display: flex; flex-direction: column; align-items: flex-end; gap: .35rem;
  flex-shrink: 0; min-width: 90px;
}

/* Badge niedopłata */
.dyd-bal-debt {
  display: inline-flex; align-items: center; gap: .25rem;
  background: rgba(239,68,68,.1); color: #dc2626;
  border-radius: 20px; font-size: .68rem; font-weight: 700; padding: .2em .55em;
}
[data-bs-theme="dark"] .dyd-bal-debt { background: rgba(239,68,68,.18); color: #f87171; }

/* Następna lekcja */
.dyd-gr-next { font-size: .72rem; color: var(--bs-secondary-color); text-align: right; }
.dyd-gr-next strong { color: var(--bs-body-color); }

.dyd-gr-actions { display: flex; gap: .35rem; flex-shrink: 0; align-items: flex-start; }

.dyd-gr-empty {
  text-align: center; padding: 2.5rem 1rem;
  color: var(--bs-secondary-color); font-size: .875rem;
}
</style>

<!-- Pasek narzędzi -->
<div class="dyd-gr-toolbar">
  <div class="dyd-gr-toolbar-title">
    <i class="bi bi-grid text-primary me-2" aria-hidden="true"></i>Przegląd grup
    <span class="badge bg-secondary ms-1" style="font-size:.72rem"><?= count($gr_all_courses) ?></span>
  </div>
  <div class="btn-group btn-group-sm" role="group" aria-label="Filtr grup">
    <a href="index.php?tab=grupy&f=active&v=<?= $gr_view ?>"
       class="btn btn-<?= $gr_filter==='active'?'primary':'outline-secondary' ?>">
      Aktywne
    </a>
    <a href="index.php?tab=grupy&f=all&v=<?= $gr_view ?>"
       class="btn btn-<?= $gr_filter==='all'?'primary':'outline-secondary' ?>">
      Wszystkie
    </a>
  </div>
  <div class="btn-group btn-group-sm" role="group" aria-label="Widok listy grup">
    <a href="index.php?tab=grupy&f=<?= $gr_filter ?>&v=karty"
       class="btn btn-<?= $gr_view==='karty'?'primary':'outline-secondary' ?>" title="Widok kart">
      <i class="bi bi-grid-1x2 me-1" aria-hidden="true"></i>Karty
    </a>
    <a href="index.php?tab=grupy&f=<?= $gr_filter ?>&v=tabela"
       class="btn btn-<?= $gr_view==='tabela'?'primary':'outline-secondary' ?>" title="Widok tabeli">
      <i class="bi bi-table me-1" aria-hidden="true"></i>Tabela
    </a>
  </div>
  <a href="../index.php" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener"
     title="Pełny panel zarządzania kursami">
    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
  </a>
  <?php $gr_po = dyd_is_staff() ? ti_course_phase_out_count() : 0; if ($gr_po > 0): ?>
  <form method="post" action="index.php?tab=kursy" class="d-inline-flex align-items-center gap-2"
        onsubmit="return confirm('Zamknąć wszystkie grupy ze statusem „Planowana do wygaszenia” (<?= $gr_po ?>)?\n\nGrupy zostaną zarchiwizowane, a protokoły i wszelkie zmiany zablokowane. Odblokowuje tylko „Przywróć z archiwum”.' + (this.include_future.checked ? '\n\nUWAGA: zamkniesz także grupy z przyszłymi lekcjami.' : ''))">
    <input type="hidden" name="_token" value="<?= dyd_token() ?>">
    <input type="hidden" name="_op" value="close_phase_out">
    <button class="btn btn-outline-warning btn-sm" title="Zamknij i zarchiwizuj grupy ze statusem „Planowana do wygaszenia”">
      <i class="bi bi-lock me-1" aria-hidden="true"></i>Zamknij „Planowana do wygaszenia” <span class="badge text-bg-warning"><?= $gr_po ?></span>
    </button>
    <label class="form-check small mb-0"><input type="checkbox" name="include_future" value="1" class="form-check-input"> <span class="form-check-label">także z przyszłymi lekcjami</span></label>
  </form>
  <?php endif; ?>
  <a href="index.php?tab=kursy&amp;new_course=1" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowy kurs
  </a>
</div>

<?php if (dyd_is_staff() && $gr_all_courses): /* ── Masowe akcje na zaznaczonych grupach ── */
  $gr_bulk_opts = [
      'close'       => ['Zamknij i zarchiwizuj', 'Archiwizuje grupy i blokuje protokoły oraz wszelkie zmiany. Grupy z przyszłymi lekcjami są pomijane, chyba że zaznaczysz „także z przyszłymi lekcjami”. Odblokowuje tylko „Przywróć z archiwum”.'],
      'plan_out'    => ['Oznacz: Planowana do wygaszenia', 'Informacja dla kierownika — grupa działa dalej normalnie. Wymaga uzasadnienia. Potem można ją zamknąć przyciskiem „Zamknij „Planowana do wygaszenia”".'],
      'plan_launch' => ['Oznacz: Planowana do uruchomienia', 'Informacja dla kierownika o planowanym starcie grupy. Wymaga uzasadnienia.'],
      'plan_clear'  => ['Usuń oznaczenie planu', 'Zdejmuje status „Planowana do wygaszenia / uruchomienia” wraz z uzasadnieniem.'],
      'deactivate'  => ['Dezaktywuj', 'Grupa znika z list aktywnych (bez archiwizacji i blokady); historia zostaje. Można ją potem aktywować.'],
      'activate'    => ['Aktywuj', 'Przywraca nieaktywną (niezarchiwizowaną) grupę do list aktywnych.'],
      'restore'     => ['Przywróć z archiwum', 'Odblokowuje zamkniętą lub zarchiwizowaną grupę i ustawia ją jako aktywną.'],
  ]; ?>
<form method="post" action="index.php?tab=kursy" id="gr-bulk" class="card card-body py-2 px-3 mb-3 d-flex flex-row flex-wrap align-items-end gap-2"
      onsubmit="var n=document.querySelectorAll('input[name=&quot;ids[]&quot;][form=gr-bulk]:checked').length; if(!n){alert('Zaznacz co najmniej jedną grupę.');return false;} var o=this.bulk_action.options[this.bulk_action.selectedIndex]; if(!this.bulk_action.value){alert('Wybierz akcję.');return false;} return confirm(o.text+' — zaznaczonych grup: '+n+'?\n\n'+o.getAttribute('data-desc'));">
  <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="bulk_groups"><input type="hidden" name="v" value="<?= h($gr_view) ?>">
  <label class="form-check small mb-1 me-1"><input type="checkbox" class="form-check-input" id="gr-bulk-all"
    onclick="document.querySelectorAll('input[name=&quot;ids[]&quot;][form=gr-bulk]').forEach(function(x){x.checked=this.checked}.bind(this)); grBulkCount()"> <span class="form-check-label">zaznacz wszystkie</span></label>
  <span class="small fw-semibold mb-1" id="gr-bulk-n" role="status" aria-live="polite">Zaznaczone: 0</span>
  <div>
    <label class="form-label small mb-0" for="gr-bulk-act">Akcja dla zaznaczonych</label>
    <select class="form-select form-select-sm" id="gr-bulk-act" name="bulk_action" aria-describedby="gr-bulk-desc" onchange="grBulkDesc()">
      <option value="" data-desc="">— wybierz —</option>
      <?php foreach ($gr_bulk_opts as $k => $o): ?><option value="<?= $k ?>" data-desc="<?= h($o[1]) ?>"><?= h($o[0]) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div id="gr-bulk-note-wrap" hidden>
    <label class="form-label small mb-0" for="gr-bulk-note">Uzasadnienie (wymagane)</label>
    <input type="text" class="form-control form-control-sm" id="gr-bulk-note" name="bulk_note" maxlength="500" style="min-width:16rem">
  </div>
  <label class="form-check small mb-1" id="gr-bulk-fut-wrap" hidden><input type="checkbox" class="form-check-input" name="include_future" value="1"> <span class="form-check-label">także z przyszłymi lekcjami</span></label>
  <button class="btn btn-sm btn-primary mb-0"><i class="bi bi-check2-square me-1" aria-hidden="true"></i>Wykonaj</button>
  <p class="small text-body-secondary mb-0 w-100" id="gr-bulk-desc">Zaznacz grupy na liście (pole wyboru przy nazwie), wybierz akcję — tu pojawi się jej opis.</p>
</form>
<script>
function grBulkCount(){ var n=document.querySelectorAll('input[name="ids[]"][form=gr-bulk]:checked').length; document.getElementById('gr-bulk-n').textContent='Zaznaczone: '+n; }
function grBulkDesc(){ var s=document.getElementById('gr-bulk-act'), o=s.options[s.selectedIndex];
  document.getElementById('gr-bulk-desc').textContent = o.getAttribute('data-desc') || 'Zaznacz grupy na liście (pole wyboru przy nazwie), wybierz akcję — tu pojawi się jej opis.';
  var needNote = s.value==='plan_out' || s.value==='plan_launch'; document.getElementById('gr-bulk-note-wrap').hidden=!needNote; document.getElementById('gr-bulk-note').required=needNote;
  document.getElementById('gr-bulk-fut-wrap').hidden = s.value!=='close'; }
document.addEventListener('change', function(e){ if (e.target.matches('input[name="ids[]"][form=gr-bulk]')) grBulkCount(); });
</script>
<?php endif; ?>

<?php if (!$gr_all_courses): ?>
<div class="card border-0 shadow-sm">
  <div class="dyd-gr-empty">
    <i class="bi bi-inbox d-block mb-2 fs-3" style="opacity:.3" aria-hidden="true"></i>
    Brak grup TI.
  </div>
</div>
<?php elseif ($gr_view === 'tabela'): ?>

<?php /* ── Widok tabeli: wszystkie grupy w jednym, gęstym zestawieniu ── */ ?>
<div class="card">
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <caption class="visually-hidden">Przegląd grup TI: prowadzący, kursanci, niedopłaty i najbliższa lekcja</caption>
      <thead class="table-light">
        <tr>
          <?php if (dyd_is_staff()): ?><th scope="col" style="width:2rem"><span class="visually-hidden">Zaznacz</span></th><?php endif; ?>
          <th scope="col">Grupa</th>
          <th scope="col">Rodzaj</th>
          <th scope="col">Prowadzący</th>
          <th scope="col" class="text-end">Kursanci</th>
          <th scope="col" class="text-end">Niedopłaty</th>
          <th scope="col">Najbliższa lekcja</th>
          <th scope="col">Status</th>
          <th scope="col" class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $gr_rows = array_merge(
            array_map(fn($c) => $c + ['_active' => true],  array_values($gr_active)),
            $gr_filter === 'all' ? array_map(fn($c) => $c + ['_active' => false], array_values($gr_inactive)) : []
        );
        foreach ($gr_rows as $c):
          $cid  = (int)$c['id'];
          $debt = $gr_debt_map[$cid] ?? 0;
          $nl   = $gr_next_map[$cid] ?? null;
        ?>
        <tr<?= $c['_active'] ? '' : ' class="text-muted"' ?>>
          <?php if (dyd_is_staff()): ?><td><input type="checkbox" name="ids[]" value="<?= $cid ?>" form="gr-bulk" class="form-check-input" aria-label="Zaznacz grupę <?= h($c['name']) ?>"></td><?php endif; ?>
          <th scope="row" class="fw-semibold">
            <?= h($c['name']) ?>
            <?php if (!empty($c['group_code'])): ?>
            <span class="dyd-gr-code text-body-secondary ms-1"><i class="bi bi-hash" aria-hidden="true"></i><?= h($c['group_code']) ?></span>
            <?php endif; ?>
            <?php if (($c['class_type'] ?? '') === 'individual'): ?>
            <span class="badge ms-1" style="font-size:.6rem;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa"
                  title="Nauczanie indywidualne — to też grupa">NI</span>
            <?php endif; ?>
            <?php if (!empty($c['hours_only_from'])): ?>
            <span class="badge ms-1" style="font-size:.6rem;background:#FEF3C7;color:#92400E;border:1px solid #FDE68A"
                  title="Grupa liczy tylko godziny (od <?= h((string)$c['hours_only_from']) ?>) — bez kwot, salda, faktur i powiadomień o rozliczeniach">Tylko godziny</span>
            <?php endif; ?>
            <?php if (!empty($c['simple_mode'])): ?>
            <span class="badge ms-1" style="font-size:.6rem;background:#ecfeff;color:#0e7490;border:1px solid #a5f3fc"
                  title="Grupa uproszczona (2–4 zajęcia) — uczestnicy widzą uproszczony panel"><?= ($c['class_type'] ?? '') === 'hybrid' ? 'Hybryda krótka' : 'Uproszczona' ?></span>
            <?php if (trim((string)($c['internal_desc'] ?? '')) !== ''): ?><span class="small fst-italic text-body-secondary ms-1" title="Opis grupy — widzi tylko kadra"><?= h($c['internal_desc']) ?></span><?php endif; ?>
            <?php elseif (($c['class_type'] ?? '') === 'hybrid'): ?>
            <span class="badge ms-1" style="font-size:.6rem;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe"
                  title="Kurs hybrydowy — lekcje online i stacjonarnie, inna stawka za tryb">Hybryda</span>
            <?php endif; ?>

            <?php if (!empty($c['is_subgroup'])): ?>
            <span class="badge ms-1" style="font-size:.6rem;background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe">1I</span>
            <?php endif; ?>
            <?php if (!empty($c['is_oneoff'])): ?>
            <span class="badge text-bg-info ms-1" style="font-size:.6rem">jednorazowy<?=
              !empty($c['oneoff_date']) ? ' · ' . h(date('d.m.Y', strtotime((string)$c['oneoff_date']))) : '' ?></span>
            <?php endif; ?>
            <?php if (!empty($c['plan_status']) && isset(K30_TI_COURSE_PLAN_STATUSES[$c['plan_status']])): ?>
            <span class="badge text-bg-<?= h(K30_TI_COURSE_PLAN_STATUSES[$c['plan_status']]['badge']) ?> ms-1" style="font-size:.6rem"
                  title="Uzasadnienie: <?= h((string)($c['plan_note'] ?? '')) ?>">
              <i class="bi bi-signpost-split" aria-hidden="true"></i> <?= h(K30_TI_COURSE_PLAN_STATUSES[$c['plan_status']]['label']) ?></span>
            <?php endif; ?>
          </th>
          <td class="small"><?= $c['subject_abbr'] ? h($c['subject_abbr']) : '<span class="text-body-secondary">—</span>' ?></td>
          <td class="small"><?= $c['instructor_name'] ? h($c['instructor_name']) : '<span class="text-body-secondary">—</span>' ?></td>
          <td class="text-end"><?= (int)$c['enrolled_count'] ?></td>
          <td class="text-end">
            <?php if ($debt > 0): ?>
            <span class="dyd-bal-debt"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><?= $debt ?></span>
            <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
          </td>
          <td class="small text-nowrap">
            <?php if ($nl): ?>
            <strong><?= date('d.m', strtotime($nl['lesson_date'])) ?></strong>
            <?= $nl['time_from'] ? h(substr((string)$nl['time_from'], 0, 5)) : '' ?>
            <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
          </td>
          <td>
            <span class="badge text-bg-<?= $c['_active'] ? 'success' : 'secondary' ?>" style="font-size:.62rem">
              <?= $c['_active'] ? 'aktywny' : 'nieaktywny' ?></span>
          </td>
          <td class="text-end text-nowrap">
            <?php if ($debt > 0): ?>
            <a href="index.php?course=<?= $cid ?>&tab=rozliczenia" class="btn btn-sm btn-danger py-0 px-2" title="Rozliczenia grupy — są niedopłaty">
              <i class="bi bi-receipt me-1" aria-hidden="true"></i>Rozliczenia<span class="visually-hidden"> grupy <?= h($c['name']) ?></span></a>
            <?php endif; ?>
            <a href="index.php?course=<?= $cid ?>&tab=uczestnicy" class="btn btn-sm btn-outline-primary py-0 px-2" title="Lista uczestników grupy">
              <i class="bi bi-people me-1" aria-hidden="true"></i>Uczestnicy<span class="visually-hidden"> grupy <?= h($c['name']) ?></span></a>
            <a href="kurs.php?id=<?= $cid ?>" class="btn btn-sm btn-primary py-0 px-2" title="Ustawienia, lekcje i zapisy grupy">
              <i class="bi bi-arrow-right me-1" aria-hidden="true"></i>Zarządzaj<span class="visually-hidden"> grupą <?= h($c['name']) ?></span></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>

<?php /* ── Aktywne grupy ── */ ?>
<?php if ($gr_active): ?>
<div class="dyd-gr-section-head" aria-label="Sekcja Aktywne">
  Aktywne (<?= count($gr_active) ?>)
</div>
<?php foreach ($gr_active as $c):
  $cid   = (int)$c['id'];
  $debt  = $gr_debt_map[$cid] ?? 0;
  $nl    = $gr_next_map[$cid] ?? null;
  $subj  = $c['subject_abbr'] ? h($c['subject_abbr']) : null;
?>
<div class="dyd-gr-card">
  <?php if (dyd_is_staff()): ?><input type="checkbox" name="ids[]" value="<?= $cid ?>" form="gr-bulk" class="form-check-input flex-shrink-0 mt-0" aria-label="Zaznacz grupę <?= h($c['name']) ?>"><?php endif; ?>
  <div class="dyd-gr-icon" aria-hidden="true"><i class="bi bi-pc-display-horizontal"></i></div>
  <div class="dyd-gr-body">
    <div class="dyd-gr-name"><?= h($c['name']) ?></div>
    <div class="dyd-gr-meta">
      <?php if ($subj): ?><span><?= $subj ?></span><?php endif; ?>
      <?php if ($c['instructor_name']): ?>
      <span><i class="bi bi-person me-1"></i><?= h($c['instructor_name']) ?></span>
      <?php endif; ?>
      <span><i class="bi bi-people me-1"></i><?= (int)$c['enrolled_count'] ?> kursantów</span>
      <?php if (!empty($c['group_code'])): ?>
      <span class="dyd-gr-code"><i class="bi bi-hash me-1"></i><?= h($c['group_code']) ?></span>
      <?php endif; ?>
      <?php if (($c['class_type']??'') === 'individual'): ?>
      <span class="badge" style="font-size:.62rem;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa"
            title="Nauczanie indywidualne — to też grupa">NI</span>
      <?php endif; ?>
      <?php if (($c['class_type']??'') === 'hybrid'): ?>
      <span class="badge" style="font-size:.62rem;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe"
            title="Kurs hybrydowy — lekcje online i stacjonarnie, inna stawka za tryb">Hybryda</span>
      <?php endif; ?>
      <?php if (!empty($c['hours_only_from'])): ?>
      <span class="badge" style="font-size:.62rem;background:#FEF3C7;color:#92400E;border:1px solid #FDE68A"
            title="Grupa liczy tylko godziny (od <?= h((string)$c['hours_only_from']) ?>) — bez kwot, salda, faktur i powiadomień">Tylko godziny</span>
      <?php endif; ?>
      <?php if (!empty($c['is_subgroup'])): ?>
      <span class="badge" style="font-size:.62rem;background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe">1I</span>
      <?php endif; ?>
      <?php if (!empty($c['plan_status']) && isset(K30_TI_COURSE_PLAN_STATUSES[$c['plan_status']])): ?>
      <span class="badge text-bg-<?= h(K30_TI_COURSE_PLAN_STATUSES[$c['plan_status']]['badge']) ?>" style="font-size:.62rem"
            title="Uzasadnienie: <?= h((string)($c['plan_note'] ?? '')) ?>">
        <i class="bi bi-signpost-split" aria-hidden="true"></i> <?= h(K30_TI_COURSE_PLAN_STATUSES[$c['plan_status']]['label']) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="dyd-gr-side">
    <?php if ($debt > 0): ?>
    <span class="dyd-bal-debt">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      <?= $debt ?> niedopł.
    </span>
    <?php endif; ?>
    <?php if ($nl): ?>
    <div class="dyd-gr-next">
      <i class="bi bi-calendar-event me-1 opacity-50" aria-hidden="true"></i>
      <strong><?= date('d.m', strtotime($nl['lesson_date'])) ?></strong>
      <?php if ($nl['time_from']): ?>
      <?= h(substr((string)$nl['time_from'],0,5)) ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="dyd-gr-actions">
    <?php if ($debt > 0): ?>
    <a href="index.php?course=<?= $cid ?>&tab=rozliczenia"
       class="btn btn-sm btn-danger py-0 px-2"
       title="Rozliczenia grupy — są niedopłaty">
      <i class="bi bi-receipt me-1" aria-hidden="true"></i>Rozliczenia
    </a>
    <?php endif; ?>
    <a href="index.php?course=<?= $cid ?>&tab=uczestnicy" class="btn btn-sm btn-outline-primary py-0 px-2" title="Lista uczestników grupy">
      <i class="bi bi-people me-1" aria-hidden="true"></i>Uczestnicy
    </a>
    <a href="kurs.php?id=<?= $cid ?>"
       class="btn btn-sm btn-primary py-0 px-2"
       title="Ustawienia, lekcje i zapisy grupy">
      <i class="bi bi-arrow-right me-1" aria-hidden="true"></i>Zarządzaj
    </a>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php /* ── Nieaktywne grupy ── */ ?>
<?php if ($gr_inactive && $gr_filter === 'all'): ?>
<div class="dyd-gr-section-head d-flex flex-wrap align-items-center gap-2" aria-label="Sekcja Nieaktywne">
  <span>Nieaktywne / archiwum (<?= count($gr_inactive) ?>)</span>
  <?php if (dyd_is_staff()): ?><span class="small fw-normal text-body-secondary ms-auto">kilka naraz: zaznacz i wybierz „Przywróć z archiwum” w pasku akcji powyżej</span><?php endif; ?>
</div>
<?php foreach ($gr_inactive as $c):
  $cid  = (int)$c['id'];
  $debt = $gr_debt_map[$cid] ?? 0;
?>
<div class="dyd-gr-card inactive">
  <?php if (dyd_is_staff()): ?><input type="checkbox" name="ids[]" value="<?= $cid ?>" form="gr-bulk" class="form-check-input flex-shrink-0 mt-0" aria-label="Zaznacz grupę <?= h($c['name']) ?>"><?php endif; ?>
  <div class="dyd-gr-icon" aria-hidden="true"><i class="bi bi-archive"></i></div>
  <div class="dyd-gr-body">
    <div class="dyd-gr-name"><?= h($c['name']) ?></div>
    <div class="dyd-gr-meta">
      <?php if ($c['instructor_name']): ?>
      <span><i class="bi bi-person me-1"></i><?= h($c['instructor_name']) ?></span>
      <?php endif; ?>
      <span><i class="bi bi-people me-1"></i><?= (int)$c['enrolled_count'] ?> kursantów</span>
      <?php $gr_st = !empty($c['closed_at']) ? 'Wygaszona (zamknięta ' . date('d.m.Y', strtotime((string)$c['closed_at'])) . ')'
                  : (($c['status'] ?? '') === 'cancelled' ? 'Wyłączona (usunięta)' : ((($c['status'] ?? '') === 'archived') ? 'Zarchiwizowana' : 'Nieaktywny')); ?>
      <span class="badge bg-secondary" style="font-size:.62rem"><?= h($gr_st) ?></span>
    </div>
  </div>
  <div class="dyd-gr-side">
    <?php if ($debt > 0): ?>
    <span class="dyd-bal-debt">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i><?= $debt ?> niedopł.
    </span>
    <?php endif; ?>
  </div>
  <div class="dyd-gr-actions">
    <?php if (dyd_is_staff()): ?>
    <form method="post" action="index.php?tab=kursy" class="d-inline" onsubmit="return confirm('Przywrócić grupę „<?= h(addslashes($c['name'])) ?>” (aktywna i odblokowana)?')">
      <input type="hidden" name="_token" value="<?= dyd_token() ?>">
      <input type="hidden" name="_op" value="restore_groups">
      <input type="hidden" name="ids[]" value="<?= $cid ?>">
      <button class="btn btn-sm btn-outline-success py-0 px-2" title="Przywróć grupę"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Przywróć</button>
    </form>
    <?php endif; ?>
    <a href="kurs.php?id=<?= $cid ?>"
       class="btn btn-sm btn-outline-secondary py-0 px-2"
       title="Ustawienia i historia grupy">
      <i class="bi bi-arrow-right me-1" aria-hidden="true"></i>Zarządzaj
    </a>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>
</section>
