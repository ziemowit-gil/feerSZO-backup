<?php
/**
 * _tab_kursy.php — Zarządzanie kursami TI (Kierownik).
 * CRUD kursów: lista, szybkie tworzenie, toggle aktywności, link do pełnej edycji.
 * Tylko dyd_is_staff(). Niebezpieczne operacje (usunięcie) — tylko is_admin().
 */
$ku_can_write = dyd_is_staff();
$ku_can_del   = is_admin();

// ── Obsługa POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ku_can_write) {
    dyd_token_check();
    $ku_op = $_POST['_op'] ?? '';

    // Szybkie tworzenie kursu (minimalne pola)
    if ($ku_op === 'create_course') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $data = [
                'name'             => $name,
                'instructor_id'    => ((int)($_POST['instructor_id'] ?? 0)) ?: null,
                'is_active'        => 1,
                'track_attendance' => 1,
                'billing_model'    => 2,
                'billing_amount'   => 0,
                'lesson_payout_bb' => max(0, (float)str_replace(',', '.', (string)($_POST['lesson_payout_bb'] ?? '0'))),
                'class_type'       => in_array($_POST['class_type'] ?? '', ['individual','group'], true) ? $_POST['class_type'] : 'individual',
                'created_by'       => current_user()['id'] ?? null,
                'created_at'       => date('Y-m-d H:i:s'),
                'group_code'       => k30_ti_generate_group_code(),
            ];
            $new_id = db_insert('k30_ti_courses', $data);
            $_SESSION['dyd_flash'] = ['type'=>'success','msg'=>'Kurs utworzony. Uzupełnij szczegóły w ustawieniach kursu.'];
            header('Location: ../course.php?id=' . $new_id);
            exit;
        }
    }

    // Toggle aktywność kursu
    if ($ku_op === 'toggle_active') {
        $cid = (int)($_POST['course_id'] ?? 0);
        $act = (int)($_POST['active'] ?? 0);
        if ($cid) {
            db()->prepare("UPDATE k30_ti_courses SET is_active=?, status=? WHERE id=?")
                 ->execute([$act, $act ? 'active' : 'inactive', $cid]);
        }
        header('Location: index.php?tab=kursy');
        exit;
    }

    // Miękkie usunięcie kursu — tylko admin
    if ($ku_op === 'delete_course' && $ku_can_del) {
        $cid = (int)($_POST['course_id'] ?? 0);
        if ($cid) {
            db()->prepare("UPDATE k30_ti_courses SET status='cancelled', is_active=0 WHERE id=?")->execute([$cid]);
        }
        header('Location: index.php?tab=kursy');
        exit;
    }
}

// ── Dane ─────────────────────────────────────────────────────────────────────
$ku_all      = k30_ti_courses(false);  // wszystkie (w tym nieaktywne)
$ku_active   = array_filter($ku_all, fn($c) => !empty($c['is_active']) && ($c['status']??'')!=='cancelled');
$ku_inactive = array_filter($ku_all, fn($c) =>  empty($c['is_active']) || ($c['status']??'')==='cancelled');
$ku_instrs   = k30_ti_instructors();
$ku_subjects = k30_ti_subject_types(true);

$ku_show_new = isset($_GET['new_course']);

$ku_flash = $_SESSION['dyd_flash'] ?? null;
unset($_SESSION['dyd_flash']);
?>

<section aria-label="Zarządzanie kursami TI" class="dyd-ku-wrap">
<style>
.dyd-ku-wrap { padding: 1.1rem 0 2.5rem; max-width: 960px; }

.dyd-p-banner {
  display: flex; align-items: flex-start; gap: .75rem;
  border-radius: 12px; padding: .75rem 1rem;
  margin-bottom: 1rem; font-size: .875rem; line-height: 1.45;
}
.dyd-p-banner.success { background: #eff6ff; color: #1e3a5f; border: 1px solid #bfdbfe; }
[data-bs-theme="dark"] .dyd-p-banner.success { background: #0c1f3a; color: #93c5fd; border-color: #1d4ed8; }

/* Pasek narzędzi */
.dyd-ku-toolbar {
  display: flex; align-items: center; gap: .6rem; flex-wrap: wrap;
  margin-bottom: 1rem;
}
.dyd-ku-toolbar-title { font-size: 1rem; font-weight: 700; flex: 1; }

/* Sekcja */
.dyd-ku-section-head {
  font-size: .72rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: var(--bs-secondary-color);
  margin: 1rem 0 .5rem; padding-bottom: .25rem;
  border-bottom: 1px solid var(--bs-border-color);
}

/* Karta kursu */
.dyd-ku-card {
  display: flex; align-items: flex-start; gap: .85rem;
  padding: .85rem 1rem; background: var(--bs-body-bg);
  border: 1px solid var(--bs-border-color); border-radius: 12px;
  margin-bottom: .5rem; transition: border-color .12s, box-shadow .12s;
}
.dyd-ku-card:hover { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.dyd-ku-card.cancelled { opacity: .5; }

.dyd-ku-icon {
  width: 38px; height: 38px; border-radius: 9px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; background: rgba(37,99,235,.08); color: #2563eb;
}
[data-bs-theme="dark"] .dyd-ku-icon { background: rgba(96,165,250,.1); color: #60a5fa; }
.dyd-ku-card.inactive .dyd-ku-icon { background: rgba(100,116,139,.1); color: var(--bs-secondary-color); }

.dyd-ku-body { flex: 1; min-width: 0; }
.dyd-ku-name {
  font-weight: 700; font-size: .95rem;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.dyd-ku-meta {
  display: flex; flex-wrap: wrap; gap: .25rem .7rem;
  margin-top: .25rem; font-size: .78rem; color: var(--bs-secondary-color);
}

.dyd-ku-actions { display: flex; gap: .35rem; flex-shrink: 0; align-items: flex-start; flex-wrap: wrap; }

/* Formularz nowego kursu */
.dyd-ku-new-card {
  border: 2px dashed var(--bs-primary); border-radius: 12px;
  padding: 1.1rem; margin-bottom: 1rem; background: var(--bs-body-bg);
}
.dyd-ku-new-card h6 { font-size: .88rem; font-weight: 700; margin-bottom: .9rem; color: var(--bs-primary); }

.dyd-ku-empty {
  text-align: center; padding: 2.5rem 1rem;
  color: var(--bs-secondary-color); font-size: .875rem;
}
</style>

<?php if ($ku_flash): ?>
<div class="dyd-p-banner success" role="status">
  <i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i>
  <?= h($ku_flash['msg']) ?>
</div>
<?php endif; ?>

<!-- Pasek narzędzi -->
<div class="dyd-ku-toolbar">
  <div class="dyd-ku-toolbar-title">
    <i class="bi bi-mortarboard text-primary me-2" aria-hidden="true"></i>Kursy TI
    <span class="badge bg-secondary ms-1" style="font-size:.72rem"><?= count($ku_all) ?></span>
  </div>
  <a href="index.php?tab=kursy<?= $ku_show_new ? '' : '&new_course=1' ?>"
     class="btn btn-<?= $ku_show_new ? 'outline-secondary' : 'primary' ?> btn-sm">
    <?php if ($ku_show_new): ?>
    <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Anuluj
    <?php else: ?>
    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowy kurs
    <?php endif; ?>
  </a>
  <a href="../index.php" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener"
     title="Pełny panel zarządzania kursami TI">
    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
  </a>
</div>

<!-- Formularz nowego kursu -->
<?php if ($ku_show_new && $ku_can_write): ?>
<div class="dyd-ku-new-card">
  <h6><i class="bi bi-plus-circle me-2" aria-hidden="true"></i>Nowy kurs TI</h6>
  <form method="post" class="row g-2">
    <input type="hidden" name="_token" value="<?= dyd_token() ?>">
    <input type="hidden" name="_op" value="create_course">

    <div class="col-sm-8">
      <label class="form-label small fw-semibold mb-1" for="ku_name">Nazwa grupy <span class="text-danger">*</span></label>
      <?php
      // Generator nazwy wg schematu OKRES-RODZAJ-POZIOMnr (+TEST/-PFRON)
      if (!function_exists('ti_periods_all')) require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_periods.php';
      ti_periods_migrate();
      $ku_gen_periods = array_map(function ($p) {
          $ini = implode('', array_map(
              fn($w) => mb_strtoupper(mb_substr($w, 0, 1)),
              array_slice(preg_split('/\s+/', preg_replace('/[^\p{L}\s]/u', '', preg_replace('/^TEST\s+/', '', preg_replace('/\s\d-[A-Z]{3}$/', '', (string)$p['name'])))) ?: [], 0, 3)
          ));
          $p['short'] = date('y', strtotime((string)$p['date_from'])) . $ini;
          return $p;
      }, ti_periods_all());
      $ku_gen_subjects = function_exists('k30_ti_subject_types') ? k30_ti_subject_types(true) : [];
      ?>
      <div class="row g-1 mb-1">
        <div class="col-4">
          <select class="form-select form-select-sm" id="kug_period" aria-label="Okres do nazwy">
            <option value="">— okres —</option>
            <?php foreach ($ku_gen_periods as $gp): ?>
            <option value="<?= h($gp['short']) ?>"><?= h($gp['short']) ?> · <?= h($gp['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-3">
          <select class="form-select form-select-sm" id="kug_subject" aria-label="Rodzaj zajęć do nazwy">
            <option value="">— rodzaj —</option>
            <?php foreach ($ku_gen_subjects as $st): ?>
            <option value="<?= h($st['abbreviation']) ?>"><?= h($st['abbreviation']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-2">
          <select class="form-select form-select-sm" id="kug_level" aria-label="Poziom do nazwy">
            <option value="P">P</option><option value="S">S</option><option value="Z">Z</option>
          </select>
        </div>
        <div class="col-1">
          <input type="number" class="form-control form-control-sm" id="kug_nr" value="1" min="1" max="99" aria-label="Nr grupy">
        </div>
        <div class="col-2 d-flex align-items-center">
          <button type="button" class="btn btn-outline-primary btn-sm w-100" id="kug_btn"
                  title="Zbuduj nazwę: OKRES-RODZAJ-POZIOMnr (+TEST / -PFRON)">
            <i class="bi bi-magic" aria-hidden="true"></i>
          </button>
        </div>
      </div>
      <div class="d-flex gap-3 mb-1">
        <div class="form-check m-0">
          <input class="form-check-input" type="checkbox" id="kug_test">
          <label class="form-check-label small" for="kug_test">TEST</label>
        </div>
        <div class="form-check m-0">
          <input class="form-check-input" type="checkbox" id="kug_pfron">
          <label class="form-check-label small" for="kug_pfron">PFRON</label>
        </div>
      </div>
      <input type="text" id="ku_name" name="name" class="form-control form-control-sm"
             placeholder="np. 26SZ-INF-P1" required maxlength="200">
      <script>
      document.getElementById('kug_btn')?.addEventListener('click', function () {
        var per = document.getElementById('kug_period');
        if (!per.value) { per.focus(); alert('Wybierz okres — schemat nazwy zaczyna się od jego skrótu.'); return; }
        var parts = [per.value];
        var subj = document.getElementById('kug_subject').value;
        if (subj) parts.push(subj);
        parts.push(document.getElementById('kug_level').value
                 + Math.max(1, parseInt(document.getElementById('kug_nr').value || '1', 10)));
        var out = document.getElementById('ku_name');
        out.value = (document.getElementById('kug_test').checked ? 'TEST ' : '')
                  + parts.join('-')
                  + (document.getElementById('kug_pfron').checked ? '-PFRON' : '');
        out.focus();
      });
      </script>
    </div>
    <div class="col-sm-4">
      <label class="form-label small fw-semibold mb-1" for="ku_class_type">Typ</label>
      <select id="ku_class_type" name="class_type" class="form-select form-select-sm">
        <option value="individual">Indywidualny</option>
        <option value="group">Grupowy</option>
      </select>
    </div>

    <div class="col-sm-6">
      <label class="form-label small fw-semibold mb-1" for="ku_instr">Prowadzący</label>
      <select id="ku_instr" name="instructor_id" class="form-select form-select-sm">
        <option value="0">— brak przypisania —</option>
        <?php foreach ($ku_instrs as $ins): ?>
        <option value="<?= (int)$ins['id'] ?>"><?= h($ins['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-6">
      <label class="form-label small fw-semibold mb-1" for="ku_bb">Stawka wynagrodzenia BB <span class="text-body-secondary fw-normal">(zł/lekcja)</span></label>
      <input type="number" id="ku_bb" name="lesson_payout_bb" step="0.01" min="0"
             class="form-control form-control-sm" placeholder="0.00">
    </div>

    <div class="col-12 d-flex gap-2 mt-1">
      <button class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Utwórz kurs
      </button>
      <a href="index.php?tab=kursy" class="btn btn-outline-secondary btn-sm">Anuluj</a>
      <span class="text-body-secondary small align-self-center ms-2">
        <i class="bi bi-info-circle me-1 opacity-50" aria-hidden="true"></i>
        Po utworzeniu uzupełnisz stawki, kursantów i harmonogram w ustawieniach kursu.
      </span>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Aktywne kursy -->
<?php if ($ku_active): ?>
<div class="dyd-ku-section-head" aria-label="Sekcja Aktywne">
  Aktywne (<?= count($ku_active) ?>)
</div>
<?php foreach ($ku_active as $c):
  $cid = (int)$c['id'];
?>
<div class="dyd-ku-card">
  <div class="dyd-ku-icon" aria-hidden="true">
    <i class="bi bi-<?= ($c['class_type']??'') === 'group' ? 'people' : 'person-workspace' ?>"></i>
  </div>
  <div class="dyd-ku-body">
    <div class="dyd-ku-name"><?= h($c['name']) ?></div>
    <div class="dyd-ku-meta">
      <?php if ($c['subject_abbr']): ?><span><?= h($c['subject_abbr']) ?></span><?php endif; ?>
      <?php if ($c['instructor_name']): ?>
      <span><i class="bi bi-person me-1 opacity-60"></i><?= h($c['instructor_name']) ?></span>
      <?php endif; ?>
      <span><i class="bi bi-people me-1 opacity-60"></i><?= (int)$c['enrolled_count'] ?> kursantów</span>
      <?php if ($c['group_code']): ?>
      <span style="font-family:var(--bs-font-monospace);font-size:.68rem">
        <i class="bi bi-hash opacity-50" aria-hidden="true"></i><?= h($c['group_code']) ?>
      </span>
      <?php endif; ?>
      <?php if (!empty($c['is_online'])): ?>
      <span class="badge" style="font-size:.62rem;background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd">Online</span>
      <?php endif; ?>
      <?php if (!empty($c['lesson_payout_bb']) && $c['lesson_payout_bb'] > 0): ?>
      <span><i class="bi bi-wallet2 me-1 opacity-50" aria-hidden="true"></i><?= number_format((float)$c['lesson_payout_bb'], 2, ',', ' ') ?> zł BB</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="dyd-ku-actions">
    <!-- Dezaktywuj -->
    <form method="post" class="flex-shrink-0">
      <input type="hidden" name="_token" value="<?= dyd_token() ?>">
      <input type="hidden" name="_op" value="toggle_active">
      <input type="hidden" name="course_id" value="<?= $cid ?>">
      <input type="hidden" name="active" value="0">
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Dezaktywuj kurs">
        <i class="bi bi-pause-circle" aria-hidden="true"></i>
      </button>
    </form>
    <!-- Edytuj (pełna strona) -->
    <a href="../course.php?id=<?= $cid ?>"
       class="btn btn-sm btn-primary py-0 px-2" title="Zarządzaj kursem">
      <i class="bi bi-gear" aria-hidden="true"></i>
    </a>
    <!-- Usuń (admin) -->
    <?php if ($ku_can_del): ?>
    <form method="post" class="flex-shrink-0"
          onsubmit="return confirm('Usunąć kurs „<?= h(addslashes($c['name'])) ?>"? Kurs zniknie z listy i będzie nieaktywny.')">
      <input type="hidden" name="_token" value="<?= dyd_token() ?>">
      <input type="hidden" name="_op" value="delete_course">
      <input type="hidden" name="course_id" value="<?= $cid ?>">
      <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń kurs">
        <i class="bi bi-trash3" aria-hidden="true"></i>
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php elseif (!$ku_show_new): ?>
<div class="card border-0 shadow-sm mb-3">
  <div class="dyd-ku-empty">
    <i class="bi bi-mortarboard d-block mb-2 fs-3" style="opacity:.3" aria-hidden="true"></i>
    Brak aktywnych kursów TI.
    <div class="mt-2">
      <a href="index.php?tab=kursy&new_course=1" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Utwórz pierwszy kurs
      </a>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Nieaktywne / zarchiwizowane -->
<?php if ($ku_inactive): ?>
<div class="dyd-ku-section-head" aria-label="Sekcja Nieaktywne">
  Nieaktywne / archiwum (<?= count($ku_inactive) ?>)
</div>
<?php foreach ($ku_inactive as $c):
  $cid = (int)$c['id'];
  $cancelled = ($c['status'] ?? '') === 'cancelled';
?>
<div class="dyd-ku-card inactive <?= $cancelled ? 'cancelled' : '' ?>">
  <div class="dyd-ku-icon" aria-hidden="true">
    <i class="bi bi-archive"></i>
  </div>
  <div class="dyd-ku-body">
    <div class="dyd-ku-name"><?= h($c['name']) ?></div>
    <div class="dyd-ku-meta">
      <?php if ($c['instructor_name']): ?>
      <span><i class="bi bi-person me-1 opacity-60"></i><?= h($c['instructor_name']) ?></span>
      <?php endif; ?>
      <span><i class="bi bi-people me-1 opacity-60"></i><?= (int)$c['enrolled_count'] ?> kursantów</span>
      <?php if ($cancelled): ?>
      <span class="badge bg-danger" style="font-size:.62rem">Anulowany</span>
      <?php else: ?>
      <span class="badge bg-secondary" style="font-size:.62rem">Nieaktywny</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="dyd-ku-actions">
    <?php if (!$cancelled): ?>
    <!-- Aktywuj -->
    <form method="post" class="flex-shrink-0">
      <input type="hidden" name="_token" value="<?= dyd_token() ?>">
      <input type="hidden" name="_op" value="toggle_active">
      <input type="hidden" name="course_id" value="<?= $cid ?>">
      <input type="hidden" name="active" value="1">
      <button class="btn btn-sm btn-outline-success py-0 px-2" title="Aktywuj kurs">
        <i class="bi bi-play-circle" aria-hidden="true"></i>
      </button>
    </form>
    <?php endif; ?>
    <a href="../course.php?id=<?= $cid ?>"
       class="btn btn-sm btn-outline-secondary py-0 px-2" title="Ustawienia kursu">
      <i class="bi bi-gear" aria-hidden="true"></i>
    </a>
    <?php if ($ku_can_del && !$cancelled): ?>
    <form method="post" class="flex-shrink-0"
          onsubmit="return confirm('Usunąć kurs „<?= h(addslashes($c['name'])) ?>"?')">
      <input type="hidden" name="_token" value="<?= dyd_token() ?>">
      <input type="hidden" name="_op" value="delete_course">
      <input type="hidden" name="course_id" value="<?= $cid ?>">
      <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń kurs">
        <i class="bi bi-trash3" aria-hidden="true"></i>
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

</section>
