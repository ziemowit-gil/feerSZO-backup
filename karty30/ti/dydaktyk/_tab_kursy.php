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

    // Tworzenie kursu — pełny zestaw pól jak w dawnym module admina (?new=1)
    if ($ku_op === 'create_course') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $gc = trim($_POST['group_code'] ?? '');
            $ku_oneoff      = isset($_POST['is_oneoff']) ? 1 : 0;
            $ku_oneoff_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_POST['oneoff_date'] ?? ''))
                ? trim($_POST['oneoff_date']) : null;
            $data = [
                'is_oneoff'           => $ku_oneoff,
                'oneoff_date'         => $ku_oneoff ? $ku_oneoff_date : null,
                'name'                => $name,
                'display_name'        => mb_substr(trim($_POST['display_name'] ?? ''), 0, 160),
                'description'         => trim($_POST['description'] ?? ''),
                'instructor_id'       => ((int)($_POST['instructor_id'] ?? 0)) ?: null,
                'location'            => trim($_POST['location'] ?? ''),
                'default_meeting_url' => trim($_POST['default_meeting_url'] ?? ''),
                'is_active'           => 1,
                'track_attendance'    => isset($_POST['track_attendance']) ? 1 : 0,
                'is_subgroup'         => isset($_POST['is_subgroup']) ? 1 : 0,
                'is_online'           => isset($_POST['is_online']) ? 1 : 0,
                'no_invoice'          => isset($_POST['no_invoice']) ? 1 : 0,
                'wup_exclude'         => isset($_POST['wup_exclude']) ? 1 : 0,
                'billing_model'       => in_array((int)($_POST['billing_model'] ?? 2), [1,2,3], true) ? (int)$_POST['billing_model'] : 2,
                'billing_amount'      => max(0, (float)str_replace(',', '.', (string)($_POST['billing_amount'] ?? '0'))),
                // Konto z listy rozwijanej rachunków organizacji — przyjmujemy tylko
                // numer z listy ('' = domyślne konto dla TI z ustawień)
                'pay_account'         => (function () {
                    $sel = preg_replace('/\s+/', '', trim((string)($_POST['pay_account'] ?? '')));
                    if ($sel === '') return '';
                    $raw = org_setting('org_rachunki_bankowe');
                    foreach (($raw ? (json_decode($raw, true) ?: []) : []) as $a) {
                        $n = preg_replace('/\D/', '', (string)($a['nrb'] ?? ''));
                        if ($n !== '' && ($sel === 'PL' . $n || $sel === $n)) {
                            return strlen($n) === 26 ? implode(' ', str_split('PL' . $n, 4)) : (string)$a['nrb'];
                        }
                    }
                    return '';
                })(),
                'pay_title'           => trim($_POST['pay_title'] ?? ''),
                'pay_due_days'        => ((int)($_POST['pay_due_days'] ?? 0)) ?: null,
                'lesson_payout_bb'    => max(0, (float)str_replace(',', '.', (string)($_POST['lesson_payout_bb'] ?? '0'))),
                'subject_type_id'     => ((int)($_POST['subject_type_id'] ?? 0)) ?: null,
                'class_type'          => in_array($_POST['class_type'] ?? '', ['individual','group'], true) ? $_POST['class_type'] : 'individual',
                // Sesja panelu dydaktyka: current_user() jest tu puste — tożsamość z dyd_require()
                'created_by'          => $uid ?? null,
                'created_at'          => date('Y-m-d H:i:s'),
                'group_code'          => preg_match('/^\d{5}$/', $gc) ? $gc : k30_ti_generate_group_code(),
            ];
            $new_id = db_insert('k30_ti_courses', $data);

            // Kurs jednorazowy = wpis w sekcji Działania (Strategia, tabela actions):
            // szkolenie z terminem realizacji, formą i miejscem przepisanymi z kursu.
            $ku_action_note = '';
            if ($ku_oneoff) {
                try {
                    require_once dirname(dirname(dirname(__DIR__))) . '/includes/grants.php';
                    $ku_action_id = db_insert('actions', [
                        'nazwa'            => $data['display_name'] !== '' ? $data['display_name'] : $name,
                        'typ'              => 'szkolenie',
                        'opis'             => trim('Kurs jednorazowy TI — grupa ' . $name
                                              . ($data['description'] !== '' ? "\n" . $data['description'] : '')),
                        'status'           => 'planowane',
                        'koordynator_id'   => $data['instructor_id'],
                        'data_od'          => $ku_oneoff_date,
                        'data_do'          => $ku_oneoff_date,
                        'cykliczne'        => 0,
                        'lokalizacja'      => $data['location'],
                        'forma'            => $data['is_online'] ? 'online' : 'stacjonarne',
                        'link_online'      => $data['default_meeting_url'],
                        'wlasne_dzialanie' => 1,
                        'created_by'       => $uid ?? null,
                    ]);
                    db()->prepare("UPDATE k30_ti_courses SET action_id=? WHERE id=?")->execute([$ku_action_id, $new_id]);
                    $ku_action_note = ' Utworzono też działanie w Strategii (sekcja Działania).';
                } catch (\Throwable $e) {
                    $ku_action_note = ' Uwaga: nie udało się utworzyć działania w Strategii — dodaj je ręcznie.';
                }
            }
            $_SESSION['dyd_flash'] = ['type'=>'success','msg'=>'Kurs utworzony. Zapisz uczestników (możesz przenieść ich z innej grupy) i uzupełnij szczegóły.' . $ku_action_note];
            header('Location: index.php?course=' . $new_id . '&tab=uczestnicy');
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
        header('Location: index.php?tab=kursy' . (($_GET['v'] ?? '') === 'tabela' ? '&v=tabela' : ''));
        exit;
    }

    // Miękkie usunięcie kursu — tylko admin
    if ($ku_op === 'delete_course' && $ku_can_del) {
        $cid = (int)($_POST['course_id'] ?? 0);
        if ($cid) {
            db()->prepare("UPDATE k30_ti_courses SET status='cancelled', is_active=0 WHERE id=?")->execute([$cid]);
        }
        header('Location: index.php?tab=kursy' . (($_GET['v'] ?? '') === 'tabela' ? '&v=tabela' : ''));
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
// Widok listy: karty (domyślny) / tabela — przełącznik w pasku narzędzi
$ku_view = ($_GET['v'] ?? 'karty') === 'tabela' ? 'tabela' : 'karty';

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
  <div class="btn-group btn-group-sm" role="group" aria-label="Widok listy kursów">
    <a href="index.php?tab=kursy&v=karty"
       class="btn btn-<?= $ku_view==='karty'?'primary':'outline-secondary' ?>" title="Widok kart">
      <i class="bi bi-grid-1x2 me-1" aria-hidden="true"></i>Karty
    </a>
    <a href="index.php?tab=kursy&v=tabela"
       class="btn btn-<?= $ku_view==='tabela'?'primary':'outline-secondary' ?>" title="Widok tabeli">
      <i class="bi bi-table me-1" aria-hidden="true"></i>Tabela
    </a>
  </div>
  <?php if ($ku_can_write): ?>
  <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#kuNewModal">
    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowy kurs
  </button>
  <?php endif; ?>
  <a href="../index.php" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener"
     title="Pełny panel zarządzania kursami TI">
    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
  </a>
</div>

<!-- Formularz nowego kursu — popup; ?new_course=1 (deep link z Przeglądu grup) otwiera go automatycznie -->
<?php if ($ku_can_write): ?>
<div class="modal fade" id="kuNewModal" tabindex="-1" aria-labelledby="kuNewModalLbl" aria-hidden="true">
 <div class="modal-dialog modal-lg modal-dialog-scrollable">
  <div class="modal-content">
   <div class="modal-header py-2">
     <h2 class="modal-title h6 mb-0" id="kuNewModalLbl"><i class="bi bi-plus-circle me-2 text-primary" aria-hidden="true"></i>Nowy kurs TI</h2>
     <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
   </div>
   <div class="modal-body">
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
          <?php /* Rodzaj zajęć: buduje nazwę (skrót) i zapisuje się na kursie (subject_type_id) */ ?>
          <select class="form-select form-select-sm" id="kug_subject" name="subject_type_id" aria-label="Rodzaj zajęć">
            <option value="">— rodzaj —</option>
            <?php foreach ($ku_gen_subjects as $st): ?>
            <option value="<?= (int)$st['id'] ?>" data-abbr="<?= h($st['abbreviation']) ?>" data-name="<?= h($st['name']) ?>"><?= h($st['abbreviation']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-2">
          <select class="form-select form-select-sm" id="kug_level" aria-label="Poziom do nazwy">
            <option value="P">P</option><option value="S">S</option><option value="Z">Z</option>
            <option value="NI" title="Nauczanie indywidualne">NI</option>
          </select>
        </div>
        <div class="col-1">
          <input type="number" class="form-control form-control-sm" id="kug_nr" value="1" min="1" max="99" aria-label="Nr grupy">
        </div>
        <div class="col-2 d-flex align-items-center">
          <button type="button" class="btn btn-outline-primary btn-sm w-100" id="kug_btn"
                  title="Zbuduj nazwę: OKRES-RODZAJ-POZIOMnr (+TEST / -PFRON); poziom NI = nauczanie indywidualne">
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
      <label class="form-label small fw-semibold mb-1 mt-2" for="ku_display_name">Nazwa dla kursanta</label>
      <input type="text" id="ku_display_name" name="display_name" class="form-control form-control-sm"
             placeholder="np. Informatyka — grupa 1" maxlength="160">
      <div class="form-text mt-0">To widzi kursant zamiast kodu technicznego; generator podpowiada
        „pełna nazwa rodzaju — grupa nr”.</div>
      <script>
      document.getElementById('kug_btn')?.addEventListener('click', function () {
        var per = document.getElementById('kug_period');
        if (!per.value) { per.focus(); alert('Wybierz okres — schemat nazwy zaczyna się od jego skrótu.'); return; }
        var parts = [per.value];
        var subj = document.getElementById('kug_subject').selectedOptions[0]?.dataset.abbr || '';
        if (subj) parts.push(subj);
        var lvl = document.getElementById('kug_level').value;
        var nr  = Math.max(1, parseInt(document.getElementById('kug_nr').value || '1', 10));
        parts.push(lvl + nr);
        var test = document.getElementById('kug_test').checked ? 'TEST ' : '';
        var out = document.getElementById('kug_name') || document.getElementById('ku_name');
        out.value = test + parts.join('-')
                  + (document.getElementById('kug_pfron').checked ? '-PFRON' : '');
        // Czytelna nazwa dla kursanta: pełna nazwa rodzaju + grupa / nauczanie indywidualne
        var so = document.getElementById('kug_subject');
        var full = so.selectedOptions[0]?.dataset.name || '';
        var dn = document.getElementById('ku_display_name');
        if (dn && full) dn.value = test + full
          + (lvl === 'NI' ? ' — nauczanie indywidualne' + (nr > 1 ? ' ' + nr : '') : ' — grupa ' + nr);
        out.focus();
      });
      // NI (nauczanie indywidualne) ↔ typ kursu — dwustronna synchronizacja
      document.getElementById('kug_level')?.addEventListener('change', function () {
        var ct = document.getElementById('ku_class_type');
        if (ct) ct.value = this.value === 'NI' ? 'individual' : 'group';
      });
      document.getElementById('ku_class_type')?.addEventListener('change', function () {
        var lv = document.getElementById('kug_level');
        if (!lv) return;
        if (this.value === 'individual') lv.value = 'NI';
        else if (lv.value === 'NI')      lv.value = 'P';
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

    <div class="col-sm-4">
      <label class="form-label small fw-semibold mb-1" for="ku_gc">Kod grupy</label>
      <input type="text" id="ku_gc" name="group_code" class="form-control form-control-sm font-monospace"
             maxlength="5" pattern="\d{5}" placeholder="np. 742<?= date('y') ?>"
             aria-describedby="ku_gc_help">
      <div class="form-text mt-0" id="ku_gc_help">3 cyfry + <?= date('y') ?>; puste = wygeneruje się sam.</div>
    </div>
    <div class="col-sm-4">
      <label class="form-label small fw-semibold mb-1" for="ku_bm">Model rozliczania</label>
      <select id="ku_bm" name="billing_model" class="form-select form-select-sm"
              onchange="document.getElementById('ku_bm_amount_wrap').style.display = this.value==='2' ? 'none' : ''">
        <?php foreach ([1,2,3] as $bm_code): ?>
        <option value="<?= $bm_code ?>" <?= $bm_code === 2 ? 'selected' : '' ?>><?= h(k30_ti_billing_model_label($bm_code)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-4" id="ku_bm_amount_wrap" style="display:none">
      <label class="form-label small fw-semibold mb-1" for="ku_bm_amount">Kwota (zł)</label>
      <input type="number" id="ku_bm_amount" name="billing_amount" step="0.01" min="0"
             class="form-control form-control-sm" placeholder="0.00">
      <div class="form-text mt-0">dla modelu miesięcznego/stałego</div>
    </div>

    <div class="col-sm-5">
      <label class="form-label small fw-semibold mb-1" for="ku_pay_acc">Nr konta do wpłat <span class="text-body-secondary fw-normal">(domyślny)</span></label>
      <?php
      $ku_org_acc  = k30_ti_org_account();
      $ku_acc_raw  = org_setting('org_rachunki_bankowe');
      $ku_accounts = [];
      foreach (($ku_acc_raw ? (json_decode($ku_acc_raw, true) ?: []) : []) as $a) {
          $n = preg_replace('/\D/', '', (string)($a['nrb'] ?? ''));
          if (strlen($n) !== 26) continue;
          $ku_accounts[] = ['iban' => implode(' ', str_split('PL' . $n, 4)),
                            'opis' => trim((string)($a['opis'] ?? '')),
                            'ti'   => !empty($a['dla_ti'])];
      }
      ?>
      <select id="ku_pay_acc" name="pay_account" class="form-select form-select-sm">
        <option value=""><?= $ku_org_acc['iban'] !== ''
            ? '— domyślne: konto dla TI (' . h($ku_org_acc['iban']) . ') —'
            : '— domyślne: konto dla TI z Ustawień organizacji —' ?></option>
        <?php foreach ($ku_accounts as $ka): ?>
        <option value="<?= h($ka['iban']) ?>"><?= h($ka['iban']) ?><?= $ka['opis'] !== '' ? ' · ' . h($ka['opis']) : '' ?><?= $ka['ti'] ? ' · TI' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text mt-0">Rachunki z Ustawień organizacji → Rachunki; domyślnie konto oznaczone „dla TI".</div>
    </div>
    <div class="col-sm-4">
      <label class="form-label small fw-semibold mb-1" for="ku_pay_title">Tytuł wpłaty <span class="text-body-secondary fw-normal">(domyślny)</span></label>
      <input type="text" id="ku_pay_title" name="pay_title" class="form-control form-control-sm"
             placeholder="np. Opłata za zajęcia TI">
      <div class="form-text mt-0">Puste = tytuł automatyczny: <code>TI/nr kursanta/kod grupy Imię Nazwisko</code>.</div>
    </div>
    <div class="col-sm-3">
      <label class="form-label small fw-semibold mb-1" for="ku_pay_due">Termin płatności <span class="text-body-secondary fw-normal">(dni)</span></label>
      <input type="number" id="ku_pay_due" name="pay_due_days" min="0" max="365"
             class="form-control form-control-sm" placeholder="<?= K30_TI_PAY_DUE_DAYS_DEFAULT ?>">
    </div>

    <div class="col-sm-6">
      <label class="form-label small fw-semibold mb-1" for="ku_loc">Lokalizacja / sala</label>
      <input type="text" id="ku_loc" name="location" class="form-control form-control-sm" placeholder="Sala A, piętro 2…">
    </div>
    <div class="col-sm-6">
      <label class="form-label small fw-semibold mb-1" for="ku_url">Stały link do zajęć online</label>
      <input type="url" id="ku_url" name="default_meeting_url" class="form-control form-control-sm"
             placeholder="https://… (Teams/Zoom/Meet)">
    </div>

    <div class="col-12">
      <label class="form-label small fw-semibold mb-1" for="ku_desc">Opis</label>
      <textarea id="ku_desc" name="description" class="form-control form-control-sm" rows="2"
                placeholder="Czego dotyczą zajęcia…"></textarea>
    </div>

    <div class="col-12 d-flex flex-wrap gap-3 mt-1">
      <div class="form-check form-switch m-0">
        <input class="form-check-input" type="checkbox" name="is_oneoff" id="ku_oneoff"
               onchange="document.getElementById('ku_oneoff_wrap').style.display = this.checked ? '' : 'none';
                         if (this.checked) { var bm = document.getElementById('ku_bm'); bm.value = '3'; bm.dispatchEvent(new Event('change')); }">
        <label class="form-check-label small" for="ku_oneoff">Kurs jednorazowy
          <span class="text-body-secondary">(szkolenie/warsztat — trafia do sekcji Działania)</span></label>
      </div>
      <div class="form-check form-switch m-0">
        <input class="form-check-input" type="checkbox" name="track_attendance" id="ku_att" checked>
        <label class="form-check-label small" for="ku_att">Licz frekwencję</label>
      </div>
      <?php /* Podgrupa to inna konstrukcja (wydzielenie z istniejącej grupy, lekcje 1I) —
               ustawia się ją w pełnej edycji kursu, nie przy szybkim tworzeniu. */ ?>
      <div class="form-check form-switch m-0">
        <input class="form-check-input" type="checkbox" name="is_online" id="ku_onl">
        <label class="form-check-label small" for="ku_onl">Zajęcia online</label>
      </div>
      <div class="form-check form-switch m-0">
        <input class="form-check-input" type="checkbox" name="no_invoice" id="ku_noinv">
        <label class="form-check-label small" for="ku_noinv">Bez fakturowania <span class="text-body-secondary">(np. dotacja)</span></label>
      </div>
      <div class="form-check form-switch m-0">
        <input class="form-check-input" type="checkbox" name="wup_exclude" id="ku_wup">
        <label class="form-check-label small" for="ku_wup">Poza raportem WUP</label>
      </div>
    </div>

    <div class="col-sm-5" id="ku_oneoff_wrap" style="display:none">
      <label class="form-label small fw-semibold mb-1" for="ku_oneoff_date">Termin realizacji (kurs jednorazowy)</label>
      <input type="date" id="ku_oneoff_date" name="oneoff_date" class="form-control form-control-sm">
      <div class="form-text mt-0">Z tym terminem powstanie działanie „szkolenie" w Strategii;
        model rozliczania przestawia się na Stały (jednorazowa kwota).</div>
    </div>

    <div class="col-12 d-flex gap-2 mt-1">
      <button class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Utwórz kurs
      </button>
      <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
      <span class="text-body-secondary small align-self-center ms-2">
        <i class="bi bi-info-circle me-1 opacity-50" aria-hidden="true"></i>
        Po utworzeniu uzupełnisz stawki, kursantów i harmonogram w ustawieniach kursu.
      </span>
    </div>
  </form>
   </div>
  </div>
 </div>
</div>
<?php if ($ku_show_new): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var m = document.getElementById('kuNewModal');
  if (m && window.bootstrap) bootstrap.Modal.getOrCreateInstance(m).show();
});
</script>
<?php endif; ?>
<?php endif; ?>

<?php if ($ku_view === 'tabela'): ?>

<?php /* ── Widok tabeli: wszystkie kursy w jednym, gęstym zestawieniu ── */ ?>
<div class="card">
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <caption class="visually-hidden">Kursy TI: rodzaj, prowadzący, kursanci, stawka BB i status</caption>
      <thead class="table-light">
        <tr>
          <th scope="col">Kurs</th>
          <th scope="col">Rodzaj</th>
          <th scope="col">Prowadzący</th>
          <th scope="col" class="text-end">Kursanci</th>
          <th scope="col" class="text-end">BB zł/lekcja</th>
          <th scope="col">Status</th>
          <th scope="col" class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $ku_rows = array_merge(
            array_map(fn($c) => $c + ['_active' => true],  array_values($ku_active)),
            array_map(fn($c) => $c + ['_active' => false], array_values($ku_inactive))
        );
        if (!$ku_rows): ?>
        <tr><td colspan="7" class="text-center text-body-secondary py-4">Brak kursów TI — utwórz pierwszy przyciskiem „Nowy kurs".</td></tr>
        <?php endif; ?>
        <?php foreach ($ku_rows as $c):
          $cid       = (int)$c['id'];
          $cancelled = ($c['status'] ?? '') === 'cancelled';
        ?>
        <tr<?= $c['_active'] ? '' : ' class="text-muted"' ?>>
          <th scope="row" class="fw-semibold">
            <?= h($c['name']) ?>
            <?php if ($c['group_code']): ?>
            <span class="text-body-secondary ms-1" style="font-family:var(--bs-font-monospace);font-size:.68rem">
              <i class="bi bi-hash opacity-50" aria-hidden="true"></i><?= h($c['group_code']) ?></span>
            <?php endif; ?>
            <?php if (($c['class_type'] ?? '') === 'individual'): ?>
            <span class="badge ms-1" style="font-size:.6rem;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa"
                  title="Nauczanie indywidualne — to też grupa">NI</span>
            <?php endif; ?>
            <?php if (!empty($c['is_online'])): ?>
            <span class="badge ms-1" style="font-size:.6rem;background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd">Online</span>
            <?php endif; ?>
            <?php if (!empty($c['is_oneoff'])): ?>
            <span class="badge text-bg-info ms-1" style="font-size:.6rem">jednorazowy<?=
              !empty($c['oneoff_date']) ? ' · ' . h(date('d.m.Y', strtotime((string)$c['oneoff_date']))) : '' ?></span>
            <?php if (!empty($c['action_id'])): ?>
            <a href="<?= APP_URL ?>/strategy/actions/view.php?id=<?= (int)$c['action_id'] ?>" target="_blank" rel="noopener"
               class="ms-1" style="font-size:.72rem" title="Powiązane działanie w Strategii (wymaga logowania do SZO)">
              działanie <i class="bi bi-box-arrow-up-right" style="font-size:.58rem" aria-hidden="true"></i></a>
            <?php endif; ?>
            <?php endif; ?>
          </th>
          <td class="small"><?= $c['subject_abbr'] ? h($c['subject_abbr']) : '<span class="text-body-secondary">—</span>' ?></td>
          <td class="small"><?= $c['instructor_name'] ? h($c['instructor_name']) : '<span class="text-body-secondary">—</span>' ?></td>
          <td class="text-end"><?= (int)$c['enrolled_count'] ?></td>
          <td class="text-end small">
            <?= !empty($c['lesson_payout_bb']) && $c['lesson_payout_bb'] > 0
                ? number_format((float)$c['lesson_payout_bb'], 2, ',', ' ')
                : '<span class="text-body-secondary">—</span>' ?>
          </td>
          <td>
            <?php if ($cancelled): ?>
            <span class="badge bg-danger" style="font-size:.62rem">Anulowany</span>
            <?php elseif ($c['_active']): ?>
            <span class="badge text-bg-success" style="font-size:.62rem">aktywny</span>
            <?php else: ?>
            <span class="badge bg-secondary" style="font-size:.62rem">nieaktywny</span>
            <?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <?php if (!$cancelled): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_token" value="<?= dyd_token() ?>">
              <input type="hidden" name="_op" value="toggle_active">
              <input type="hidden" name="course_id" value="<?= $cid ?>">
              <input type="hidden" name="active" value="<?= $c['_active'] ? 0 : 1 ?>">
              <button class="btn btn-sm btn-outline-<?= $c['_active'] ? 'secondary' : 'success' ?> py-0 px-2"
                      title="<?= $c['_active'] ? 'Dezaktywuj kurs' : 'Aktywuj kurs' ?>">
                <i class="bi bi-<?= $c['_active'] ? 'pause-circle' : 'play-circle' ?>" aria-hidden="true"></i>
                <span class="visually-hidden"><?= $c['_active'] ? 'Dezaktywuj' : 'Aktywuj' ?></span>
              </button>
            </form>
            <?php endif; ?>
            <a href="index.php?course=<?= $cid ?>&tab=uczestnicy" class="btn btn-sm btn-outline-primary py-0 px-2" title="Uczestnicy kursu">
              <i class="bi bi-people" aria-hidden="true"></i><span class="visually-hidden">Uczestnicy</span></a>
            <a href="../course.php?id=<?= $cid ?>" class="btn btn-sm btn-primary py-0 px-2" title="Zarządzaj kursem">
              <i class="bi bi-gear" aria-hidden="true"></i><span class="visually-hidden">Zarządzaj</span></a>
            <?php if ($ku_can_del && !$cancelled): ?>
            <form method="post" class="d-inline"
                  onsubmit="return confirm('Usunąć kurs „<?= h(addslashes($c['name'])) ?>&quot;? Kurs zniknie z listy i będzie nieaktywny.')">
              <input type="hidden" name="_token" value="<?= dyd_token() ?>">
              <input type="hidden" name="_op" value="delete_course">
              <input type="hidden" name="course_id" value="<?= $cid ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń kurs">
                <i class="bi bi-trash3" aria-hidden="true"></i><span class="visually-hidden">Usuń</span>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>

<!-- Aktywne kursy -->
<?php if ($ku_active): ?>
<div class="dyd-ku-section-head" aria-label="Sekcja Aktywne">
  Aktywne (<?= count($ku_active) ?>)
</div>
<?php foreach ($ku_active as $c):
  $cid = (int)$c['id'];
?>
<div class="dyd-ku-card">
  <div class="dyd-ku-icon" title="<?= ($c['class_type']??'') === 'group' ? 'Zajęcia grupowe' : 'Nauczanie indywidualne' ?>">
    <i class="bi bi-<?= ($c['class_type']??'') === 'group' ? 'people' : 'person-workspace' ?>" aria-hidden="true"></i>
    <span class="visually-hidden"><?= ($c['class_type']??'') === 'group' ? 'Zajęcia grupowe' : 'Nauczanie indywidualne' ?></span>
  </div>
  <div class="dyd-ku-body">
    <div class="dyd-ku-name"><?= h($c['name']) ?></div>
    <div class="dyd-ku-meta">
      <?php if (!empty($c['is_oneoff'])): ?>
      <span class="badge text-bg-info" style="font-size:.62rem">jednorazowy<?=
        !empty($c['oneoff_date']) ? ' · ' . h(date('d.m.Y', strtotime((string)$c['oneoff_date']))) : '' ?></span>
      <?php if (!empty($c['action_id'])): ?>
      <a href="<?= APP_URL ?>/strategy/actions/view.php?id=<?= (int)$c['action_id'] ?>" target="_blank" rel="noopener"
         title="Powiązane działanie w Strategii (wymaga logowania do SZO)">
        działanie <i class="bi bi-box-arrow-up-right" style="font-size:.6rem" aria-hidden="true"></i></a>
      <?php endif; ?>
      <?php endif; ?>
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
      <?php if (($c['class_type']??'') === 'individual'): ?>
      <span class="badge" style="font-size:.62rem;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa"
            title="Nauczanie indywidualne — to też grupa">NI</span>
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

<?php endif; /* widok karty / tabela */ ?>

</section>
