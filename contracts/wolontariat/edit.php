<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/persons.php';
require_once dirname(dirname(__DIR__)) . '/includes/address.php';

require_role('admin', 'editor');
$TYPE  = 'wolontariat';
$TABLE = 'umowy_wolontariat';
$id    = intval($_GET['id'] ?? 0);
$row   = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); die('Nie znaleziono porozumienia.'); }
$PAGE_TITLE = 'Edycja: ' . $row['numer_umowy'];
$errors = [];

if (!empty($row['person_id'])) {
    $person_row = person_by_id((int)$row['person_id']);
    $row['_person_name'] = $person_row['imie_nazwisko'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data = $_POST;
    unset($data['_csrf']);

    if (empty($data['numer_umowy'])) $errors[] = 'Numer umowy jest wymagany.';
    if (empty($data['status']))      $errors[] = 'Status jest wymagany.';

    if (!$errors) {
        foreach (['niepelnoletni', 'bezterminowa', 'ubezpieczenie_nnw', 'ubezpieczenie_oc', 'szkolenie_bhp', 'zwrot_kosztow', 'm365_konto', 'z_webngo'] as $f) {
            $data[$f] = isset($_POST[$f]) ? 1 : 0;
        }
        // Czyszczenie pól webNGO jeśli checkbox nie jest zaznaczony
        if ($data['z_webngo'] === 0) {
            $data['webngo_id'] = null;
            $data['webngo_numer_umowy'] = null;
        }
        // Nullifikacja pól, które nie mogą być pustym stringiem (FK, daty, liczby)
        $nullable_fields = [
            'godzin_tygodniowo', 'godzin_przepracowanych',
            'data_urodzenia', 'data_zawarcia', 'data_rozpoczecia', 'data_zakonczenia',
            'data_szkolenia_bhp',
            'person_id', 'org_unit_id', 'org_position_id', 'action_id', 'grant_id',
            'webngo_id', 'numer_polisy_nnw', 'id_dokumentu_el',
            'pesel', 'seria_nr_dowodu',
        ];
        foreach ($nullable_fields as $f) {
            if (isset($data[$f]) && $data[$f] === '') $data[$f] = null;
        }

        $plik_umowy = handle_upload('plik_umowy', $TYPE);
        $plik_potw  = handle_upload('plik_potwierdzenia', $TYPE);
        $zgoda_op   = handle_upload('zgoda_opiekuna', $TYPE);
        $data['plik_umowy']         = $plik_umowy  ?: $row['plik_umowy'];
        $data['plik_potwierdzenia'] = $plik_potw   ?: $row['plik_potwierdzenia'];
        $data['zgoda_opiekuna']     = $zgoda_op    ?: $row['zgoda_opiekuna'];

        $data['updated_at'] = date('Y-m-d H:i:s');

        $allowed = ['numer_umowy', 'status', 'imie_nazwisko', 'pesel', 'adres', 'telefon', 'email',
            'addr_street', 'addr_house', 'addr_flat', 'addr_postal', 'addr_city', 'addr_country',
            'data_urodzenia', 'niepelnoletni', 'zgoda_opiekuna', 'rodzic_imie_nazwisko', 'rodzic_email', 'rodzic_telefon',
            'przedmiot_porozumienia',
            'miejsce_wolontariatu', 'data_zawarcia', 'data_rozpoczecia', 'data_zakonczenia',
            'bezterminowa', 'godzin_tygodniowo', 'godzin_przepracowanych', 'ubezpieczenie_nnw',
            'numer_polisy_nnw', 'ubezpieczenie_oc', 'szkolenie_bhp', 'data_szkolenia_bhp',
            'zwrot_kosztow', 'zwrot_kosztow_opis', 'opiekun', 'projekt_program',
            'forma_podpisania', 'platforma_el', 'id_dokumentu_el', 'plik_potwierdzenia',
            'plik_umowy', 'uwagi', 'updated_at',
            'm365_konto', 'm365_login', 'm365_user_id', 'm365_konto_aktywne', 'm365_data_utworzenia', 'm365_licencja_przypisana',
            'nr_roboczy', 'nr_system', 'nr_rejestru',
            'adres_odbiorca', 'adres_linia1', 'adres_linia2', 'adres_kod_pocztowy', 'adres_miasto', 'adres_kraj',
            'z_webngo', 'webngo_id', 'webngo_numer_umowy', 'person_id', 'org_unit_id',
            'action_id', 'grant_id'];
        $save = array_intersect_key($data, array_flip($allowed));

        // Normalizacja telefonu: zawsze 48XXXXXXXXX
        if (!empty($save['telefon'])) {
            $t = preg_replace('/\D/', '', $save['telefon']);
            if (strlen($t) === 9) $t = '48' . $t;
            $save['telefon'] = $t;
        }

        require_once dirname(dirname(__DIR__)) . '/includes/amendments.php';
        $diff = format_field_diff($row, $save);
        db_update($TABLE, $save, $id);
        require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
        log_contract_action($TYPE, $id, current_user()['id'], 'edit', $diff ?: 'Edytowano umowę');
        flash_set('success', 'Zmiany zapisane.');
        header('Location: view.php?id=' . $id);
        exit;
    }
    $row = array_merge($row, $_POST);
}

include dirname(dirname(__DIR__)) . '/includes/header.php';

// Przygotuj dane pomocnicze dla widoku
try { $__actions = db_all("SELECT id, nazwa FROM actions WHERE status NOT IN ('anulowane','zakończone') ORDER BY nazwa"); }
catch (\Throwable $e) { $__actions = []; }
try { $__grants = db_all("SELECT id, nazwa, donator FROM grants WHERE status IN ('przyznany','w realizacji','rozliczany') ORDER BY nazwa"); }
catch (\Throwable $e) { $__grants = []; }
try { $__units = db_all("SELECT id, name FROM org_units WHERE status='active' ORDER BY sort_order, name"); }
catch (\Throwable $e) { $__units = []; }
$__st = STATUS_LABELS[$row['status']] ?? ['label' => $row['status'], 'class' => 'secondary'];
?>
<style>
/* ── Sticky topbar edycji ──────────────────────────────── */
#edit-topbar {
  position: sticky; top: 0; z-index: 40;
  background: #fff; border-bottom: 1px solid #e2e8f0;
  padding: .55rem 0; margin: -1.5rem -1.5rem .1rem;
  padding-left: 1.5rem; padding-right: 1.5rem;
  display: flex; align-items: center; gap: .75rem;
  box-shadow: 0 2px 8px rgba(0,0,0,.07);
}
#edit-topbar .etb-title {
  font-weight: 700; font-size: 1rem; color: #1e293b; flex:1; min-width:0;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
#edit-topbar .etb-nav a {
  font-size: .8rem; color: #64748b; text-decoration: none; padding: .25rem .5rem;
  border-radius: .3rem; white-space: nowrap;
}
#edit-topbar .etb-nav a:hover { background: #f1f5f9; color: #1e293b; }

/* ── Section nav pills ─────────────────────────────────── */
#sec-nav {
  display: flex; gap: .3rem; flex-wrap: wrap;
  margin-bottom: 1.5rem; padding: .6rem .75rem;
  background: #f8fafc; border-radius: .6rem; border: 1px solid #e2e8f0;
}
#sec-nav a {
  font-size: .75rem; font-weight: 600; padding: .28rem .65rem;
  border-radius: 20px; text-decoration: none;
  color: #475569; background: #fff; border: 1px solid #e2e8f0;
  white-space: nowrap; transition: all .12s;
}
#sec-nav a:hover, #sec-nav a.active {
  background: #2563eb; color: #fff; border-color: #2563eb;
}

/* ── Section headers ───────────────────────────────────── */
.esec {
  scroll-margin-top: 70px;
  margin-bottom: 1.75rem;
}
.esec-head {
  display: flex; align-items: center; gap: .55rem;
  margin-bottom: 1rem; padding-bottom: .55rem;
  border-bottom: 2px solid #e2e8f0;
}
.esec-head i { font-size: 1.1rem; color: #2563eb; }
.esec-head h6 {
  margin: 0; font-size: .9rem; font-weight: 700;
  color: #1e293b; text-transform: uppercase; letter-spacing: .04em;
}
.esec-head .esec-sub {
  font-size: .72rem; color: #94a3b8; font-weight: 400;
  text-transform: none; letter-spacing: 0; margin-left: .3rem;
}

/* ── Field groups ──────────────────────────────────────── */
.fgroup { display: flex; flex-direction: column; gap: .2rem; }
.fgroup label { font-size: .78rem; font-weight: 600; color: #475569; margin-bottom: 0; }
.fgroup .form-text { font-size: .72rem; margin-top: .15rem; }
.fgroup .form-control, .fgroup .form-select {
  font-size: .87rem;
}

/* ── Toggle switch row ─────────────────────────────────── */
.toggle-row {
  display: flex; align-items: center; gap: .6rem;
  padding: .55rem .75rem; background: #f8fafc;
  border-radius: .45rem; border: 1px solid #e9ecef;
  margin-bottom: .5rem;
}
.toggle-row .form-check { margin: 0; padding: 0; }
.toggle-row .form-check-input { margin: 0; flex-shrink: 0; }
.toggle-row label { font-size: .84rem; cursor: pointer; }
.toggle-row .ts-sub { font-size: .72rem; color: #94a3b8; margin-left: auto; }

/* ── Sidebar ───────────────────────────────────────────── */
.edit-sidebar {
  position: sticky; top: 60px; height: fit-content;
}
.sidebar-card {
  background: #fff; border: 1px solid #e2e8f0; border-radius: .6rem;
  overflow: hidden; margin-bottom: .75rem;
}
.sidebar-card-head {
  padding: .55rem .85rem; background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
  font-size: .73rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: #64748b;
  display: flex; align-items: center; gap: .4rem;
}
.sidebar-card-body { padding: .85rem; }

/* ── Status alert banner ───────────────────────────────── */
.status-banner {
  border-radius: .5rem; padding: .75rem 1rem; margin-bottom: 1.25rem;
  display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
  border: 1px solid transparent;
}
.status-banner.sb-warning { background: #fefce8; border-color: #fde68a; }
.status-banner.sb-info    { background: #eff6ff; border-color: #bfdbfe; }
.status-banner .sb-text   { flex: 1; min-width: 200px; }
.sb-title { font-weight: 700; font-size: .9rem; margin-bottom: .15rem; }
.sb-desc  { font-size: .8rem; color: #64748b; }

/* ── Responsive ────────────────────────────────────────── */
@media (max-width: 991px) {
  .edit-sidebar { position: static; }
  #edit-topbar  { margin: -.75rem -.75rem .5rem; padding: .5rem .75rem; }
}
</style>

<!-- ── Sticky topbar ──────────────────────────────────── -->
<div id="edit-topbar">
  <div class="etb-nav d-flex gap-1">
    <a href="list.php"><i class="bi bi-arrow-left"></i></a>
    <a href="view.php?id=<?= $id ?>"><i class="bi bi-eye me-1"></i><span class="d-none d-sm-inline">Podgląd</span></a>
  </div>
  <div class="etb-title">
    <i class="bi bi-pencil text-primary me-1"></i>
    <?= h($row['numer_umowy']) ?>
  </div>
  <span class="badge bg-<?= h($__st['class']) ?> d-none d-sm-inline"><?= h($__st['label']) ?></span>
  <button type="submit" form="editForm" class="btn btn-primary btn-sm px-3">
    <i class="bi bi-check-lg me-1"></i>Zapisz
  </button>
  <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm d-none d-md-inline">Anuluj</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mt-2 py-2"><ul class="mb-0 ps-3">
  <?php foreach ($errors as $e) echo '<li class="small">' . h($e) . '</li>'; ?>
</ul></div>
<?php endif; ?>

<?php if ($row['status'] === 'projekt'): ?>
<div class="status-banner sb-warning mt-2">
  <i class="bi bi-file-earmark-diff-fill text-warning fs-4 flex-shrink-0"></i>
  <div class="sb-text">
    <div class="sb-title">Umowa w fazie projektu</div>
    <div class="sb-desc">Dopełnij formalności i przekaż dokument do akceptacji.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <form method="post" action="<?= APP_URL ?>/contracts/approvals/submit.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="<?= $TYPE ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-warning btn-sm fw-semibold"><i class="bi bi-send-check me-1"></i>Przekaż do akceptacji</button>
    </form>
    <?php if (is_admin()): ?>
    <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal">
      <i class="bi bi-trash3"></i>
    </button>
    <?php endif; ?>
  </div>
</div>
<?php elseif ($row['status'] === 'w akceptacji'): ?>
<div class="status-banner sb-info mt-2">
  <i class="bi bi-hourglass-split text-primary fs-4 flex-shrink-0"></i>
  <div class="sb-text">
    <div class="sb-title">Oczekiwanie na akceptację</div>
    <div class="sb-desc">Pola są zablokowane do czasu decyzji administratora.</div>
  </div>
  <?php if (is_admin()): ?>
  <a href="view.php?id=<?= $id ?>#tab-obieg" class="btn btn-primary btn-sm">Podejmij decyzję →</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Section jump nav ───────────────────────────────── -->
<nav id="sec-nav" aria-label="Sekcje formularza">
  <a href="#sec-podstawowe">📋 Porozumienie</a>
  <a href="#sec-wolontariusz">👤 Wolontariusz</a>
  <a href="#sec-szczegoly">📍 Szczegóły</a>
  <a href="#sec-bhp">🛡 BHP &amp; Ubezpieczenia</a>
  <a href="#sec-podpisanie">✍ Podpisanie</a>
  <a href="#sec-powiazania">🔗 Powiązania</a>
  <a href="#sec-zaawansowane">⚙ Zaawansowane</a>
</nav>

<form method="post" enctype="multipart/form-data" id="editForm"
      data-cpc="<?= in_array(current_user()['role'] ?? '', ['admin','editor']) ? '1' : '0' ?>"
      data-cpc-meta=''>
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<div class="row g-4 align-items-start">
<div class="col-lg-8">

<!-- ══════════════════════════════════════════════════════════
     SEKCJA 1 — POROZUMIENIE
     ══════════════════════════════════════════════════════════ -->
<section id="sec-podstawowe" class="esec">
  <div class="esec-head">
    <i class="bi bi-file-earmark-text"></i>
    <h6>Porozumienie <span class="esec-sub">numer, status, daty</span></h6>
  </div>

  <div class="row g-3">
    <div class="col-md-5 fgroup">
      <label for="numer_umowy">Numer umowy <span class="text-danger">*</span></label>
      <input name="numer_umowy" id="numer_umowy" class="form-control fw-bold font-monospace"
             value="<?= h($row['numer_umowy']) ?>" required>
    </div>
    <div class="col-md-4 fgroup">
      <label for="status_sel">Status <span class="text-danger">*</span></label>
      <select name="status" id="status_sel" class="form-select" required>
        <?php foreach (['projekt','podpisana','w realizacji','zakończona','rozwiązana','anulowana','obowiązująca'] as $s):
          $sel = $row['status'] === $s ? 'selected' : ''; ?>
        <option value="<?= h($s) ?>" <?= $sel ?>><?= h(ucfirst($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3 fgroup">
      <label for="opiekun_inp">Opiekun</label>
      <input name="opiekun" id="opiekun_inp" class="form-control" value="<?= h($row['opiekun']) ?>">
    </div>

    <div class="col-md-4 fgroup">
      <label>Data zawarcia</label>
      <input name="data_zawarcia" type="date" class="form-control" value="<?= h($row['data_zawarcia']) ?>">
    </div>
    <div class="col-md-4 fgroup">
      <label>Data rozpoczęcia</label>
      <input name="data_rozpoczecia" type="date" class="form-control" value="<?= h($row['data_rozpoczecia']) ?>">
    </div>
    <div class="col-md-4 fgroup">
      <label>Data zakończenia</label>
      <input name="data_zakonczenia" type="date" class="form-control" id="data_zakonczenia"
             value="<?= h($row['data_zakonczenia']) ?>" <?= $row['bezterminowa'] ? 'disabled' : '' ?>>
    </div>

    <div class="col-md-6 fgroup">
      <label>Projekt / program</label>
      <input name="projekt_program" class="form-control" value="<?= h($row['projekt_program']) ?>">
    </div>
    <div class="col-md-6 d-flex align-items-end pb-1">
      <div class="toggle-row w-100 mb-0">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch"
                 name="bezterminowa" id="bezterminowa" value="1"
                 <?= $row['bezterminowa'] ? 'checked' : '' ?>>
        </div>
        <label for="bezterminowa" class="mb-0">Porozumienie bezterminowe</label>
      </div>
    </div>
  </div>

  <!-- Nr referencyjne — zwijane -->
  <div class="mt-3">
    <button type="button" class="btn btn-link btn-sm p-0 text-muted text-decoration-none"
            data-bs-toggle="collapse" data-bs-target="#refNums">
      <i class="bi bi-hash"></i> Numery referencyjne
      <?php if ($row['nr_roboczy'] || $row['nr_system'] || $row['nr_rejestru']): ?>
      <span class="badge bg-secondary ms-1">wypełnione</span>
      <?php endif; ?>
      <i class="bi bi-chevron-down" style="font-size:.65rem"></i>
    </button>
    <div class="collapse <?= ($row['nr_roboczy'] || $row['nr_system'] || $row['nr_rejestru']) ? 'show' : '' ?>"
         id="refNums">
      <div class="row g-3 mt-1">
        <div class="col-md-4 fgroup">
          <label>Nr roboczy</label>
          <input name="nr_roboczy" class="form-control form-control-sm" value="<?= h($row['nr_roboczy']??'') ?>" placeholder="PR-2026-001">
        </div>
        <div class="col-md-4 fgroup">
          <label>Nr ogólny <span class="text-muted fw-normal">(webNGO)</span></label>
          <input name="nr_system" class="form-control form-control-sm" value="<?= h($row['nr_system']??'') ?>">
        </div>
        <div class="col-md-4 fgroup">
          <label>Nr rejestru</label>
          <input name="nr_rejestru" class="form-control form-control-sm font-monospace"
                 value="<?= h($row['nr_rejestru']??'') ?>"
                 placeholder="<?= h(suggest_nr_rejestru($row['opiekun']??'')) ?>">
          <div class="form-text">Puste = auto</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     SEKCJA 2 — WOLONTARIUSZ
     ══════════════════════════════════════════════════════════ -->
<section id="sec-wolontariusz" class="esec">
  <div class="esec-head">
    <i class="bi bi-person-circle"></i>
    <h6>Wolontariusz <span class="esec-sub">dane osobowe</span></h6>
  </div>

  <!-- Person picker -->
  <div class="mb-3 p-3 rounded" style="background:#f0f7ff;border:1px solid #bfdbfe">
    <label class="form-label fw-semibold small mb-1">
      <i class="bi bi-person-vcard me-1 text-primary"></i>Osoba w rejestrze
    </label>
    <div class="input-group">
      <input type="text" id="person_search" class="form-control form-control-sm"
             placeholder="Szukaj po imieniu, PESEL lub email…"
             value="<?= h($row['_person_name'] ?? '') ?>" autocomplete="off">
      <a href="<?= APP_URL ?>/persons/add.php" class="btn btn-outline-secondary btn-sm" target="_blank">
        <i class="bi bi-person-plus"></i>
      </a>
    </div>
    <input type="hidden" name="person_id" id="person_id" value="<?= h($row['person_id'] ?? '') ?>">
    <div id="person_results" class="list-group mt-1" style="display:none;position:absolute;z-index:2000;max-width:460px"></div>
    <div class="form-text">Opcjonalne — powiąż z kartą osoby lub zostaw puste.</div>
  </div>

  <div class="row g-3">
    <div class="col-md-6 fgroup">
      <label>Imię i nazwisko</label>
      <input name="imie_nazwisko" class="form-control" value="<?= h($row['imie_nazwisko']) ?>">
    </div>
    <div class="col-md-3 fgroup">
      <label>PESEL</label>
      <input name="pesel" id="peselInput" class="form-control font-monospace" maxlength="11"
             value="<?= h($row['pesel']) ?>" autocomplete="off">
    </div>
    <div class="col-md-3 fgroup">
      <label>
        Data urodzenia
        <span class="text-primary fw-normal" id="peselDateHint" style="display:none;font-size:.7rem">
          <i class="bi bi-magic"></i> z PESEL
        </span>
      </label>
      <input name="data_urodzenia" id="dataUrInput" type="date" class="form-control"
             value="<?= h($row['data_urodzenia']) ?>">
    </div>

    <div class="col-12 fgroup">
      <label class="form-label fw-semibold mb-1"><i class="bi bi-house me-1 text-secondary"></i>Adres zamieszkania</label>
      <?= address_widget($row, ['label' => 'Adres zamieszkania']) ?>
    </div>
    <div class="col-md-4 fgroup">
      <label>Telefon</label>
      <div class="input-group">
        <span class="input-group-text text-muted" style="font-size:.8rem">+48</span>
        <input name="telefon" class="form-control phone-48" type="tel"
               placeholder="123 456 789" value="<?= h($row['telefon']) ?>">
      </div>
    </div>

    <div class="col-md-7 fgroup">
      <label>E-mail <span class="text-muted fw-normal">(panel użytkownika)</span></label>
      <input name="email" class="form-control" type="email" value="<?= h($row['email']) ?>"
             placeholder="prywatny@email.com lub imie@org.pl">
    </div>
    <div class="col-md-5 fgroup">
      <label>Komórka organizacyjna</label>
      <select name="org_unit_id" class="form-select">
        <option value="">— wybierz —</option>
        <?php foreach ($__units as $pos):
          $sel = ($row['org_unit_id'] ?? '') == $pos['id'] ? 'selected' : ''; ?>
        <option value="<?= h($pos['id']) ?>" <?= $sel ?>><?= h($pos['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <!-- Niepełnoletni toggle -->
  <div class="toggle-row mt-3">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" role="switch"
             name="niepelnoletni" id="niepelnoletni" value="1"
             <?= $row['niepelnoletni'] ? 'checked' : '' ?>>
    </div>
    <label for="niepelnoletni" class="mb-0 fw-semibold">Osoba niepełnoletnia</label>
    <span class="ts-sub">wymaga zgody opiekuna prawnego</span>
  </div>

  <div id="sekcja_niepelnoletni" style="display:<?= $row['niepelnoletni'] ? '' : 'none' ?>">
    <div class="row g-3 mt-1">
      <div class="col-md-4 fgroup">
        <label>Zgoda opiekuna <span class="text-muted fw-normal">(plik)</span></label>
        <input name="zgoda_opiekuna" type="file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
        <?php if ($row['zgoda_opiekuna']): ?>
        <div class="mt-1"><?= upload_link($row['zgoda_opiekuna']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="p-3 mt-2 rounded" style="background:#fffbeb;border:1px solid #fde68a">
      <div class="fw-semibold small mb-2">
        <i class="bi bi-person-hearts text-warning me-1"></i>Dane rodzica / opiekuna prawnego
      </div>
      <div class="row g-3">
        <div class="col-md-4 fgroup">
          <label>Imię i nazwisko</label>
          <input name="rodzic_imie_nazwisko" class="form-control form-control-sm"
                 value="<?= h($row['rodzic_imie_nazwisko'] ?? '') ?>" placeholder="Jan Kowalski">
        </div>
        <div class="col-md-4 fgroup">
          <label>E-mail <span class="text-muted fw-normal">(panel)</span></label>
          <input name="rodzic_email" class="form-control form-control-sm" type="email"
                 value="<?= h($row['rodzic_email'] ?? '') ?>">
        </div>
        <div class="col-md-4 fgroup">
          <label>Telefon</label>
          <div class="input-group input-group-sm">
            <span class="input-group-text text-muted">+48</span>
            <input name="rodzic_telefon" class="form-control phone-48" type="tel"
                   value="<?= h($row['rodzic_telefon'] ?? '') ?>">
          </div>
        </div>
      </div>
    </div>
  </div>

</section>

<!-- ══════════════════════════════════════════════════════════
     SEKCJA 3 — SZCZEGÓŁY
     ══════════════════════════════════════════════════════════ -->
<section id="sec-szczegoly" class="esec">
  <div class="esec-head">
    <i class="bi bi-geo-alt"></i>
    <h6>Szczegóły <span class="esec-sub">zakres, miejsce, godziny</span></h6>
  </div>

  <div class="row g-3">
    <div class="col-12 fgroup">
      <label>Przedmiot porozumienia</label>
      <textarea name="przedmiot_porozumienia" class="form-control" rows="3"><?= h($row['przedmiot_porozumienia']) ?></textarea>
    </div>
    <div class="col-md-6 fgroup">
      <label>Miejsce wolontariatu</label>
      <input name="miejsce_wolontariatu" class="form-control" value="<?= h($row['miejsce_wolontariatu']) ?>">
    </div>
    <div class="col-md-3 fgroup">
      <label>Godzin / tydzień</label>
      <input name="godzin_tygodniowo" type="number" step="0.5" min="0" class="form-control"
             value="<?= h($row['godzin_tygodniowo']) ?>">
    </div>
    <div class="col-md-3 fgroup">
      <label>Godzin przepracowanych</label>
      <input name="godzin_przepracowanych" type="number" step="0.5" min="0" class="form-control"
             value="<?= h($row['godzin_przepracowanych']) ?>">
    </div>
  </div>

  <!-- Zwrot kosztów -->
  <div class="toggle-row mt-3">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" role="switch"
             name="zwrot_kosztow" id="zwrot_kosztow" value="1"
             <?= $row['zwrot_kosztow'] ? 'checked' : '' ?>>
    </div>
    <label for="zwrot_kosztow" class="mb-0">Zwrot kosztów</label>
  </div>
  <div id="zwrot_kosztow_opis_field" style="display:<?= $row['zwrot_kosztow'] ? '' : 'none' ?>" class="mt-2 fgroup">
    <label>Opis zwrotu kosztów</label>
    <input name="zwrot_kosztow_opis" class="form-control" value="<?= h($row['zwrot_kosztow_opis']) ?>">
  </div>

  <div class="mt-3 fgroup">
    <label>Uwagi</label>
    <textarea name="uwagi" class="form-control" rows="2"><?= h($row['uwagi']) ?></textarea>
  </div>

</section>

<!-- ══════════════════════════════════════════════════════════
     SEKCJA 4 — BHP & UBEZPIECZENIA
     ══════════════════════════════════════════════════════════ -->
<section id="sec-bhp" class="esec">
  <div class="esec-head">
    <i class="bi bi-shield-check"></i>
    <h6>BHP &amp; Ubezpieczenia</h6>
  </div>

  <!-- BHP -->
  <div class="toggle-row">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" role="switch"
             name="szkolenie_bhp" id="szkolenie_bhp" value="1"
             <?= $row['szkolenie_bhp'] ? 'checked' : '' ?>>
    </div>
    <label for="szkolenie_bhp" class="mb-0">Szkolenie BHP przeprowadzone</label>
    <div class="ms-auto" style="min-width:160px">
      <input name="data_szkolenia_bhp" type="date" class="form-control form-control-sm" id="data_szkolenia_bhp"
             value="<?= h($row['data_szkolenia_bhp']) ?>"
             <?= $row['szkolenie_bhp'] ? '' : 'disabled' ?> placeholder="data szkolenia">
    </div>
  </div>

  <!-- NNW -->
  <div class="toggle-row mt-2">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" role="switch"
             name="ubezpieczenie_nnw" id="ubezpieczenie_nnw" value="1"
             <?= $row['ubezpieczenie_nnw'] ? 'checked' : '' ?>>
    </div>
    <label for="ubezpieczenie_nnw" class="mb-0">Ubezpieczenie NNW</label>
    <div class="ms-auto" style="min-width:220px">
      <input name="numer_polisy_nnw" class="form-control form-control-sm" id="numer_polisy_nnw"
             value="<?= h($row['numer_polisy_nnw']) ?>"
             <?= $row['ubezpieczenie_nnw'] ? '' : 'disabled' ?> placeholder="numer polisy">
    </div>
  </div>

  <!-- OC -->
  <div class="toggle-row mt-2">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" role="switch"
             name="ubezpieczenie_oc" id="ubezpieczenie_oc" value="1"
             <?= $row['ubezpieczenie_oc'] ? 'checked' : '' ?>>
    </div>
    <label for="ubezpieczenie_oc" class="mb-0">Ubezpieczenie OC</label>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     SEKCJA 5 — PODPISANIE
     ══════════════════════════════════════════════════════════ -->
<section id="sec-podpisanie" class="esec">
  <div class="esec-head">
    <i class="bi bi-pen"></i>
    <h6>Podpisanie <span class="esec-sub">forma i pliki</span></h6>
  </div>

  <div class="row g-3">
    <div class="col-md-4 fgroup">
      <label>Forma podpisania</label>
      <select name="forma_podpisania" class="form-select" id="forma_podpisania">
        <option value="">— nie określono —</option>
        <option value="papierowa" <?= $row['forma_podpisania'] === 'papierowa' ? 'selected' : '' ?>>Papierowa</option>
        <option value="elektroniczna" <?= $row['forma_podpisania'] === 'elektroniczna' ? 'selected' : '' ?>>Elektroniczna</option>
        <option value="epodpis_kwalifikowany" <?= ($row['forma_podpisania'] === 'epodpis_kwalifikowany') ? 'selected' : '' ?>>ePodpis kwalifikowany</option>
      </select>
    </div>
  </div>

  <div id="el_fields" class="row g-3 mt-1" style="display:<?= $row['forma_podpisania'] === 'elektroniczna' ? '' : 'none' ?>">
    <div class="col-md-4 fgroup">
      <label>Platforma</label>
      <input name="platforma_el" class="form-control" value="<?= h($row['platforma_el']) ?>">
    </div>
    <div class="col-md-4 fgroup">
      <label>ID dokumentu</label>
      <input name="id_dokumentu_el" class="form-control" value="<?= h($row['id_dokumentu_el']) ?>">
    </div>
    <div class="col-md-4 fgroup">
      <label>Plik potwierdzenia</label>
      <input name="plik_potwierdzenia" type="file" class="form-control" accept=".pdf">
      <?php if ($row['plik_potwierdzenia']): ?>
      <div class="mt-1"><?= upload_link($row['plik_potwierdzenia']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div id="epodpis_fields" class="row g-3 mt-1" style="display:<?= $row['forma_podpisania'] === 'epodpis_kwalifikowany' ? '' : 'none' ?>">
    <div class="col-md-4 fgroup">
      <label>Dostawca podpisu (TSP)</label>
      <input name="epodpis_dostawca" class="form-control" placeholder="Certum, SimplySign…" value="<?= h($row['epodpis_dostawca'] ?? '') ?>">
    </div>
    <div class="col-md-4 fgroup">
      <label>Numer certyfikatu</label>
      <input name="epodpis_nr_certyfikatu" class="form-control font-monospace" value="<?= h($row['epodpis_nr_certyfikatu'] ?? '') ?>">
    </div>
    <div class="col-md-4 fgroup">
      <label>Ważność certyfikatu</label>
      <input name="epodpis_data_waznosci" type="date" class="form-control" value="<?= h($row['epodpis_data_waznosci'] ?? '') ?>">
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     SEKCJA 6 — POWIĄZANIA
     ══════════════════════════════════════════════════════════ -->
<section id="sec-powiazania" class="esec">
  <div class="esec-head">
    <i class="bi bi-diagram-3"></i>
    <h6>Powiązania <span class="esec-sub">działanie, grant, adres pocztowy</span></h6>
  </div>

  <div class="row g-3">
    <div class="col-md-6 fgroup">
      <label>Działanie</label>
      <select name="action_id" id="action_id_select" class="form-select ts-action">
        <option value="">— brak —</option>
        <?php foreach ($__actions as $__a):
          $sel = ($row['action_id'] ?? '') == $__a['id'] ? 'selected' : ''; ?>
        <option value="<?= h($__a['id']) ?>" <?= $sel ?>><?= h($__a['nazwa']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-6 fgroup">
      <label>Grant / dotacja</label>
      <select name="grant_id" id="grant_id_select" class="form-select ts-grant">
        <option value="">— brak —</option>
        <?php foreach ($__grants as $__g):
          $sel = ($row['grant_id'] ?? '') == $__g['id'] ? 'selected' : ''; ?>
        <option value="<?= h($__g['id']) ?>" <?= $sel ?>><?= h($__g['nazwa']) ?> (<?= h($__g['donator']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <!-- Adres korespondencyjny — zwijane -->
  <div class="mt-3">
    <button type="button" class="btn btn-link btn-sm p-0 text-muted text-decoration-none"
            data-bs-toggle="collapse" data-bs-target="#adresKor">
      <i class="bi bi-mailbox"></i> Adres do korespondencji
      <?php if ($row['adres_odbiorca'] || $row['adres_linia1']): ?>
      <span class="badge bg-secondary ms-1">wypełniony</span>
      <?php endif; ?>
      <i class="bi bi-chevron-down" style="font-size:.65rem"></i>
    </button>
    <div class="collapse <?= ($row['adres_odbiorca'] || $row['adres_linia1']) ? 'show' : '' ?>" id="adresKor">
      <div class="p-3 mt-2 rounded" style="background:#f8fafc;border:1px solid #e2e8f0">
        <div class="row g-3">
          <div class="col-md-8 fgroup">
            <label>Odbiorca</label>
            <input name="adres_odbiorca" class="form-control form-control-sm" value="<?= h($row['adres_odbiorca'] ?? '') ?>" placeholder="Imię Nazwisko">
          </div>
          <div class="col-md-4 fgroup">
            <label>Kraj</label>
            <select name="adres_kraj" class="form-select form-select-sm">
              <?php foreach (['PL'=>'PL — Polska','DE'=>'DE — Niemcy','GB'=>'GB — Wielka Brytania','UA'=>'UA — Ukraina','FR'=>'FR — Francja'] as $kc => $kl): ?>
              <option value="<?= $kc ?>" <?= ($row['adres_kraj'] ?? 'PL') === $kc ? 'selected' : '' ?>><?= $kl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-8 fgroup">
            <label>Adres linia 1</label>
            <input name="adres_linia1" class="form-control form-control-sm" value="<?= h($row['adres_linia1'] ?? '') ?>" placeholder="ul. Przykładowa 1">
          </div>
          <div class="col-md-4 fgroup">
            <label>Linia 2</label>
            <input name="adres_linia2" class="form-control form-control-sm" value="<?= h($row['adres_linia2'] ?? '') ?>">
          </div>
          <div class="col-md-4 fgroup">
            <label>Kod pocztowy</label>
            <input name="adres_kod_pocztowy" class="form-control form-control-sm" value="<?= h($row['adres_kod_pocztowy'] ?? '') ?>" placeholder="00-001" maxlength="10">
          </div>
          <div class="col-md-8 fgroup">
            <label>Miasto</label>
            <input name="adres_miasto" class="form-control form-control-sm" value="<?= h($row['adres_miasto'] ?? '') ?>">
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     SEKCJA 7 — ZAAWANSOWANE
     ══════════════════════════════════════════════════════════ -->
<section id="sec-zaawansowane" class="esec">
  <div class="esec-head">
    <i class="bi bi-gear"></i>
    <h6>Zaawansowane <span class="esec-sub">webNGO</span></h6>
  </div>

  <div class="toggle-row">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" role="switch"
             name="z_webngo" id="z_webngo" value="1"
             <?= !empty($row['z_webngo']) ? 'checked' : '' ?>>
    </div>
    <label for="z_webngo" class="mb-0 fw-semibold">Przeniesione z webNGO</label>
  </div>
  <div id="webngo_fields" style="display:<?= !empty($row['z_webngo']) ? '' : 'none' ?>" class="row g-3 mt-1">
    <div class="col-md-4 fgroup">
      <label>ID w webNGO</label>
      <input name="webngo_id" id="webngo_id" class="form-control font-monospace" value="<?= h($row['webngo_id'] ?? '') ?>" placeholder="4829">
    </div>
    <div class="col-md-8 fgroup">
      <label>Numer umowy w webNGO</label>
      <input name="webngo_numer_umowy" id="webngo_numer_umowy" class="form-control" value="<?= h($row['webngo_numer_umowy'] ?? '') ?>" placeholder="JST/WOL/2025/08">
    </div>
  </div>
</section>

</div><!-- /col-lg-8 -->

<!-- ══════════════════════════════════════════════════════════
     SIDEBAR
     ══════════════════════════════════════════════════════════ -->
<div class="col-lg-4">
<div class="edit-sidebar">

  <!-- Save buttons -->
  <div class="sidebar-card">
    <div class="sidebar-card-head"><i class="bi bi-floppy"></i> Zapis</div>
    <div class="sidebar-card-body d-grid gap-2">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i>Zapisz zmiany
      </button>
      <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm text-center">
        <i class="bi bi-x me-1"></i>Anuluj
      </a>
    </div>
  </div>

  <!-- Plik umowy -->
  <div class="sidebar-card">
    <div class="sidebar-card-head"><i class="bi bi-file-pdf"></i> Plik porozumienia</div>
    <div class="sidebar-card-body">
      <?php if ($row['plik_umowy']): ?>
      <div class="mb-2"><?= upload_link($row['plik_umowy']) ?></div>
      <div class="fgroup">
        <label class="text-muted">Zastąp nowym plikiem:</label>
        <input name="plik_umowy" type="file" class="form-control form-control-sm" accept=".pdf,.docx">
      </div>
      <?php else: ?>
      <div class="fgroup">
        <label>Plik porozumienia (PDF/DOCX)</label>
        <input name="plik_umowy" type="file" class="form-control form-control-sm" accept=".pdf,.docx">
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Microsoft 365 -->
  <div class="sidebar-card">
    <div class="sidebar-card-head" style="color:#0078d4">
      <i class="bi bi-microsoft"></i> Microsoft 365
    </div>
    <div class="sidebar-card-body">

      <div class="toggle-row mb-2">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch"
                 name="m365_konto" id="m365_konto" value="1"
                 <?= !empty($row['m365_konto']) ? 'checked' : '' ?>>
        </div>
        <label for="m365_konto" class="mb-0 small">Konto M365 przypisane</label>
      </div>

      <!-- Stan: sprawdzanie -->
      <div id="m365_checking" style="display:none" class="text-center py-3 text-muted">
        <div class="spinner-border spinner-border-sm text-primary me-1"></div>
        <span class="small">Sprawdzam w Microsoft 365…</span>
      </div>

      <!-- Stan: znaleziono dopasowanie -->
      <div id="m365_match_card" style="display:none"
           class="rounded p-2 mb-2 border" style="background:#eff6ff;border-color:#bfdbfe!important">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bi bi-person-check-fill text-primary"></i>
          <strong class="small">Znaleziono istniejące konto</strong>
        </div>
        <div id="m365_match_dname" class="fw-bold small text-dark mb-0"></div>
        <div id="m365_match_upn" class="small text-muted font-monospace"></div>
        <div id="m365_match_src" class="small text-muted mb-1"></div>
        <div id="m365_match_linked_warn" class="small text-warning mb-2 d-none">
          <i class="bi bi-exclamation-triangle-fill"></i> <span></span>
        </div>
        <div class="d-grid gap-1 mt-2">
          <button type="button" id="m365_btn_link" class="btn btn-primary btn-sm">
            <i class="bi bi-link-45deg me-1"></i>Połącz z tym kontem
          </button>
          <button type="button" id="m365_btn_skip" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-pencil me-1"></i>Wpisz ręcznie / utwórz nowe
          </button>
        </div>
      </div>

      <!-- Stan: pola ręczne -->
      <div id="m365_manual_fields" style="display:<?= !empty($row['m365_konto']) ? '' : 'none' ?>">
        <div id="m365_linked_info" class="alert alert-success alert-sm py-1 px-2 small mb-2 d-none">
          <i class="bi bi-check-circle-fill me-1"></i>Powiązano z istniejącym kontem
        </div>
        <div class="fgroup mb-2">
          <label>Login M365</label>
          <input name="m365_login" id="m365_login_inp"
                 class="form-control form-control-sm font-monospace"
                 value="<?= h($row['m365_login']??'') ?>" placeholder="imie.nazwisko@org.pl">
        </div>
        <div class="fgroup">
          <label>User ID (Azure AD)</label>
          <input name="m365_user_id" id="m365_uid_inp"
                 class="form-control form-control-sm font-monospace"
                 value="<?= h($row['m365_user_id']??'') ?>">
        </div>
      </div>

      <div class="form-text mt-1">Konto można też założyć z widoku szczegółów.</div>
    </div>
  </div>

  <!-- Metadata -->
  <div class="sidebar-card">
    <div class="sidebar-card-head"><i class="bi bi-clock-history"></i> Historia</div>
    <div class="sidebar-card-body small text-muted">
      <div><i class="bi bi-calendar-plus me-1"></i>Dodano: <?= date_pl($row['created_at'] ?? null) ?></div>
      <div class="mt-1"><i class="bi bi-calendar-check me-1"></i>Zmieniono: <?= date_pl($row['updated_at'] ?? null) ?></div>
    </div>
  </div>

</div><!-- /edit-sidebar -->
</div><!-- /col-lg-4 -->
</div><!-- /row -->
</form>

<script>
// ── Forma podpisania ─────────────────────────────────────
document.getElementById('forma_podpisania').addEventListener('change', function () {
  var v = this.value;
  document.getElementById('el_fields').style.display      = v === 'elektroniczna' ? '' : 'none';
  document.getElementById('epodpis_fields').style.display = v === 'epodpis_kwalifikowany' ? '' : 'none';
});
// ── Niepełnoletni ────────────────────────────────────────
document.getElementById('niepelnoletni').addEventListener('change', function () {
  document.getElementById('sekcja_niepelnoletni').style.display = this.checked ? '' : 'none';
});
// ── PESEL → data urodzenia ───────────────────────────────
(function () {
  function peselToBirthdate(p) {
    if (p.length!==11) return '';
    var y=parseInt(p.substr(0,2),10),m=parseInt(p.substr(2,2),10),d=parseInt(p.substr(4,2),10);
    if(m>=81){y+=1800;m-=80;}else if(m>=61){y+=2200;m-=60;}else if(m>=41){y+=2100;m-=40;}else if(m>=21){y+=2000;m-=20;}else{y+=1900;}
    if(m<1||m>12||d<1||d>31)return '';
    return y+'-'+String(m).padStart(2,'0')+'-'+String(d).padStart(2,'0');
  }
  var pi=document.getElementById('peselInput'),di=document.getElementById('dataUrInput'),hi=document.getElementById('peselDateHint');
  if(pi&&di) pi.addEventListener('input',function(){
    var bd=peselToBirthdate(this.value.replace(/\D/g,''));
    if(bd&&!di.value){di.value=bd;if(hi)hi.style.display='';}
  });
})();
// ── Toggles ──────────────────────────────────────────────
document.getElementById('zwrot_kosztow').addEventListener('change', function () {
  document.getElementById('zwrot_kosztow_opis_field').style.display = this.checked ? '' : 'none';
});
document.getElementById('szkolenie_bhp').addEventListener('change', function () {
  document.getElementById('data_szkolenia_bhp').disabled = !this.checked;
});
document.getElementById('ubezpieczenie_nnw').addEventListener('change', function () {
  document.getElementById('numer_polisy_nnw').disabled = !this.checked;
});
document.getElementById('bezterminowa').addEventListener('change', function () {
  var d = document.getElementById('data_zakonczenia');
  d.disabled = this.checked;
  if (this.checked) d.value = '';
});
document.getElementById('z_webngo').addEventListener('change', function () {
  document.getElementById('webngo_fields').style.display = this.checked ? '' : 'none';
  if (!this.checked) {
    document.getElementById('webngo_id').value = '';
    document.getElementById('webngo_numer_umowy').value = '';
  }
});
// ── M365: auto-check przy włączeniu ────────────────────────
(function () {
  var toggle   = document.getElementById('m365_konto');
  var checking = document.getElementById('m365_checking');
  var matchCard= document.getElementById('m365_match_card');
  var manual   = document.getElementById('m365_manual_fields');
  var linkedInf= document.getElementById('m365_linked_info');
  var mDname   = document.getElementById('m365_match_dname');
  var mUpn     = document.getElementById('m365_match_upn');
  var mSrc     = document.getElementById('m365_match_src');
  var mWarn    = document.getElementById('m365_match_linked_warn');
  var btnLink  = document.getElementById('m365_btn_link');
  var btnSkip  = document.getElementById('m365_btn_skip');
  var loginInp = document.getElementById('m365_login_inp');
  var uidInp   = document.getElementById('m365_uid_inp');
  var _found   = null;

  function hideAll()   { checking.style.display='none'; matchCard.style.display='none'; manual.style.display='none'; }
  function showManual(){ checking.style.display='none'; matchCard.style.display='none'; manual.style.display=''; }

  function doCheck() {
    var name  = (document.querySelector('[name=imie_nazwisko]')||{value:''}).value.trim();
    var email = (document.querySelector('[name=email]')||{value:''}).value.trim();
    if (!name && !email) { showManual(); return; }

    hideAll();
    checking.style.display = '';

    fetch('<?= APP_URL ?>/admin/api/m365_find_user.php'
      + '?name='  + encodeURIComponent(name)
      + '&email=' + encodeURIComponent(email))
      .then(function(r){ return r.json(); })
      .then(function(data){
        checking.style.display = 'none';
        if (!data.configured || !data.results || !data.results.length) { showManual(); return; }

        _found = data.results[0].ms_user;
        var r  = data.results[0];

        mDname.textContent = _found.displayName || _found.userPrincipalName;
        mUpn.textContent   = _found.userPrincipalName;
        mSrc.textContent   = r.source === 'email'
          ? 'Dopasowanie po adresie e-mail'
          : 'Dopasowanie po loginie: ' + (r.expected_login || '');

        if (r.already_linked) {
          mWarn.classList.remove('d-none');
          mWarn.querySelector('span').textContent =
            'Powiązane już z użytkownikiem: ' + r.already_linked.name;
        } else {
          mWarn.classList.add('d-none');
        }

        matchCard.style.display = '';
      })
      .catch(function(){ checking.style.display='none'; showManual(); });
  }

  toggle.addEventListener('change', function () {
    if (!this.checked) { hideAll(); return; }
    // Jeśli już ma user_id — nie sprawdzaj ponownie, tylko pokaż pola
    if (uidInp && uidInp.value.trim()) { showManual(); return; }
    doCheck();
  });

  btnLink.addEventListener('click', function () {
    if (!_found) return;
    loginInp.value = _found.userPrincipalName || '';
    uidInp.value   = _found.id || '';
    linkedInf.classList.remove('d-none');
    matchCard.style.display = 'none';
    manual.style.display    = '';
  });

  btnSkip.addEventListener('click', function () {
    matchCard.style.display = 'none';
    manual.style.display    = '';
  });
})();

// ── Person search ────────────────────────────────────────
(function() {
  var si=document.getElementById('person_search'), hi=document.getElementById('person_id'),
      rs=document.getElementById('person_results'), timer;
  if(!si) return;
  si.addEventListener('input',function(){
    clearTimeout(timer);
    var q=this.value.trim();
    if(q.length<2){rs.style.display='none';return;}
    timer=setTimeout(function(){
      fetch('<?= APP_URL ?>/persons/search.php?q='+encodeURIComponent(q))
        .then(r=>r.json()).then(function(data){
          rs.innerHTML='';
          if(!data.length){
            rs.innerHTML='<div class="list-group-item text-muted small">Brak wyników. <a href="<?= APP_URL ?>/persons/add.php" target="_blank">Dodaj nową osobę</a>.</div>';
          } else {
            data.forEach(function(p){
              var b=document.createElement('button');
              b.type='button'; b.className='list-group-item list-group-item-action small py-2';
              b.innerHTML='<strong>'+p.imie_nazwisko+'</strong>'
                +(p.pesel?' <span class="text-muted font-monospace">'+p.pesel.substring(0,6)+'…</span>':'')
                +(p.email?' <span class="text-muted">'+p.email+'</span>':'');
              b.addEventListener('click',function(){hi.value=p.id;si.value=p.imie_nazwisko;rs.style.display='none';});
              rs.appendChild(b);
            });
          }
          rs.style.display='';
        }).catch(function(){});
    },250);
  });
  document.addEventListener('click',function(e){
    if(!rs.contains(e.target)&&e.target!==si) rs.style.display='none';
  });
})();

// ── Section nav — highlight active ───────────────────────
(function(){
  var links = document.querySelectorAll('#sec-nav a');
  var secs  = Array.from(links).map(function(l){
    return document.querySelector(l.getAttribute('href'));
  });
  function onScroll(){
    var scrollY = window.scrollY + 90;
    var active = null;
    secs.forEach(function(s,i){ if(s && s.offsetTop <= scrollY) active=i; });
    links.forEach(function(l,i){ l.classList.toggle('active', i===active); });
  }
  window.addEventListener('scroll', onScroll, {passive:true});
  onScroll();
})();
</script>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2/dist/js/tom-select.complete.min.js"></script>
<script>
['ts-action','ts-grant'].forEach(function(cls){
  var el=document.querySelector('.'+cls);
  if(el) new TomSelect(el,{allowEmptyOption:true,placeholder:'— brak —'});
});
</script>

<script>
// ── CPC metadata builder ─────────────────────────────────────────────────────
// Buduje meta dla modalu CPC (tabela potwierdzenia operacji).
// Używa click capture na przyciskach submit — odpala się przed submitem,
// a przed document.submit (capture) czytanym przez CPC interceptor w header.php.
// DOMContentLoaded gwarantuje dostęp do DOM i załadowanego Bootstrap.
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('editForm');
  if (!form || form.dataset.cpc !== '1') return;

  function buildMeta() {
    var nrEl    = document.querySelector('[name="numer_umowy"]');
    var nameEl  = document.querySelector('[name="imie_nazwisko"]');
    var opEl    = document.querySelector('[name="opiekun"]');
    var statEl  = document.querySelector('[name="status"]');
    var rows = [
      ['Typ operacji',    'Edycja umowy wolontariatu'],
      ['Numer umowy',     nrEl   ? (nrEl.value || '—')   : '—'],
      ['Wolontariusz',    nameEl ? (nameEl.value || '—')  : '—'],
      ['Status',          statEl ? (statEl.options[statEl.selectedIndex] || {}).text || '—' : '—'],
      ['Opiekun',         opEl   ? (opEl.value || '—')    : '—'],
    ];
    form.dataset.cpcMeta = JSON.stringify({ rows: rows });
  }

  // Przyciski submit: zarówno w sidebar jak i w topbarze
  document.querySelectorAll('[type="submit"][form="editForm"], #editForm [type="submit"]')
    .forEach(function (btn) {
      btn.addEventListener('click', buildMeta, true);
    });
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
