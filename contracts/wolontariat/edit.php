<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/byli_check.php';
require_once dirname(dirname(__DIR__)) . '/includes/persons.php';
require_once dirname(dirname(__DIR__)) . '/includes/address.php';
require_once dirname(dirname(__DIR__)) . '/includes/dyspozycyjnosc.php';
require_once dirname(dirname(__DIR__)) . '/includes/cpc.php';
require_once dirname(dirname(__DIR__)) . '/includes/volunteer_hours.php';
require_once dirname(dirname(__DIR__)) . '/includes/rpts.php';
require_once dirname(dirname(__DIR__)) . '/includes/wolontariat_schema.php';
cpc_migrate();

require_role('admin', 'editor');
$TYPE  = 'wolontariat';
$TABLE = 'umowy_wolontariat';
$id    = intval($_GET['id'] ?? 0);
$row   = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); die('Nie znaleziono porozumienia.'); }
// Odśwież wyliczone godziny z zadań przed pokazaniem formularza
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    volunteer_recompute_hours($id, $row);
    $row = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]);
}
// Idempotentna migracja pola
try { db()->exec("ALTER TABLE umowy_wolontariat ADD COLUMN przetwarza_dane_osobowe INTEGER NOT NULL DEFAULT 0"); } catch(\Throwable $e) {}
if (contract_is_locked($row)) {
    flash_set('warning', 'Umowa jest zablokowana (zawarty aneks) — edycja niedostępna.');
    header('Location: view.php?id=' . $id); exit;
}
$PAGE_TITLE = 'Edycja: ' . $row['numer_umowy'];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET') ika_require(APP_URL . '/contracts/wolontariat/edit.php?id=' . $id);

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
        foreach (['niepelnoletni', 'bezterminowa', 'ubezpieczenie_nnw', 'ubezpieczenie_oc', 'szkolenie_bhp', 'zwrot_kosztow', 'm365_konto', 'm365_nie_wylaczaj', 'z_webngo', 'canva_access', 'email_consent', 'przetwarza_dane_osobowe', 'rpts_wymagana', 'rpts_zweryfikowano'] as $f) {
            $data[$f] = isset($_POST[$f]) ? 1 : 0;
        }
        if (empty($data['rpts_zweryfikowano'])) { $data['rpts_data_weryfikacji'] = null; $data['rpts_wynik'] = null; }
        // Wykryj nowe zaznaczenie „przetwarza dane osobowe"
        $_rodo_was = (int)($row['przetwarza_dane_osobowe'] ?? 0);
        $_rodo_now = $data['przetwarza_dane_osobowe'];
        // Dostępność — serialize jako JSON
        $data['dostepnosc_dni']  = json_encode(array_values(array_filter((array)($_POST['dostepnosc_dni']  ?? []))));
        $data['dostepnosc_pora'] = json_encode(array_values(array_filter((array)($_POST['dostepnosc_pora'] ?? []))));
        $data['obszar_dzialania'] = json_encode(array_values(array_filter((array)($_POST['obszar_dzialania'] ?? []))));
        // Zgoda RODO — zapisz datę udzielenia jeśli zaznaczono po raz pierwszy
        if ($data['email_consent'] && !$row['email_consent']) {
            $data['email_consent_at'] = date('Y-m-d H:i:s');
        } elseif (!$data['email_consent']) {
            $data['email_consent_at'] = null;
        }
        // Guardian initials — przelicz gdy zmieniono guardian_editor_id
        if (!empty($data['guardian_editor_id'])) {
            $ge = db_one("SELECT first_name, last_name, name FROM users WHERE id=?", [(int)$data['guardian_editor_id']]);
            if ($ge) {
                $fn = trim($ge['first_name'] ?: explode(' ', $ge['name']??'')[0]);
                $ln = trim($ge['last_name']  ?: (explode(' ', $ge['name']??'')[1] ?? ''));
                $data['guardian_initials'] = strtoupper(mb_substr($fn,0,1).mb_substr($ln,0,1));
                $data['opiekun']           = trim($fn . ' ' . $ln) ?: ($ge['name'] ?? '');
            }
        }
        // Czyszczenie pól webNGO jeśli checkbox nie jest zaznaczony
        if ($data['z_webngo'] === 0) {
            $data['webngo_id'] = null;
            $data['webngo_numer_umowy'] = null;
        }
        // Godziny przepracowane są wyliczane (godzin_z_zadan + godzin_korekta) —
        // z formularza przyjmujemy WYŁĄCZNIE ręczną korektę.
        $data['godzin_korekta'] = (($_POST['godzin_korekta'] ?? '') === '')
            ? 0 : (float)str_replace(',', '.', $_POST['godzin_korekta']);
        unset($data['godzin_przepracowanych']);

        // Nullifikacja pól, które nie mogą być pustym stringiem (FK, daty, liczby)
        $nullable_fields = [
            'godzin_tygodniowo', 'limit_zwrotu_kosztow',
            'data_urodzenia', 'data_zawarcia', 'data_rozpoczecia', 'data_zakonczenia',
            'data_szkolenia_bhp', 'epodpis_data_waznosci',
            'person_id', 'org_unit_id', 'org_position_id', 'action_id', 'grant_id',
            'guardian_editor_id', 'opiekun_wolontariusz_id',
            'webngo_id', 'numer_polisy_nnw', 'id_dokumentu_el',
            'pesel', 'seria_nr_dowodu',
            'm365_security_group_id',
            'rpts_data_weryfikacji', 'rpts_wynik',
        ];
        foreach ($nullable_fields as $f) {
            if (isset($data[$f]) && $data[$f] === '') $data[$f] = null;
        }

        $plik_umowy = handle_upload('plik_umowy', $TYPE);
        $plik_potw  = handle_upload('plik_potwierdzenia', $TYPE);
        $zgoda_op   = handle_upload('zgoda_opiekuna', $TYPE);
        $rpts_plik  = handle_upload('rpts_plik_potwierdzenia', $TYPE);
        $data['plik_umowy']         = $plik_umowy  ?: $row['plik_umowy'];
        $data['plik_potwierdzenia'] = $plik_potw   ?: $row['plik_potwierdzenia'];
        $data['zgoda_opiekuna']     = $zgoda_op    ?: $row['zgoda_opiekuna'];
        $data['rpts_plik_potwierdzenia'] = $rpts_plik ?: $row['rpts_plik_potwierdzenia'];

        $data['updated_at'] = date('Y-m-d H:i:s');

        $allowed = ['numer_umowy', 'status', 'imie_nazwisko', 'pesel', 'adres', 'telefon', 'email',
            'addr_street', 'addr_house', 'addr_flat', 'addr_postal', 'addr_city', 'addr_country',
            'data_urodzenia', 'niepelnoletni', 'zgoda_opiekuna', 'rodzic_imie_nazwisko', 'rodzic_email', 'rodzic_telefon',
            'opiekun_wolontariusz_id',
            'przedmiot_porozumienia',
            'miejsce_wolontariatu', 'data_zawarcia', 'data_rozpoczecia', 'data_zakonczenia',
            'bezterminowa', 'godzin_tygodniowo', 'godzin_korekta', 'ubezpieczenie_nnw',
            'numer_polisy_nnw', 'ubezpieczenie_oc', 'szkolenie_bhp', 'data_szkolenia_bhp',
            'zwrot_kosztow', 'zwrot_kosztow_opis', 'opiekun', 'projekt_program',
            'forma_podpisania', 'platforma_el', 'id_dokumentu_el', 'plik_potwierdzenia',
            'plik_umowy', 'uwagi', 'updated_at',
            'm365_konto', 'm365_login', 'm365_user_id', 'm365_konto_aktywne', 'm365_nie_wylaczaj', 'm365_data_utworzenia', 'm365_licencja_przypisana',
            'nr_roboczy', 'nr_system', 'nr_rejestru',
            'adres_odbiorca', 'adres_linia1', 'adres_linia2', 'adres_kod_pocztowy', 'adres_miasto', 'adres_kraj',
            'z_webngo', 'webngo_id', 'webngo_numer_umowy', 'person_id', 'org_unit_id',
            'action_id', 'grant_id',
            // Dostęp IT
            'portal_scope', 'canva_access',
            'm365_security_group_id', 'm365_security_group_name',
            // Opiekun
            'guardian_editor_id', 'guardian_initials',
            // Finanse
            'limit_zwrotu_kosztow',
            // ePodpis
            'epodpis_dostawca', 'epodpis_nr_certyfikatu', 'epodpis_data_waznosci',
            // Podpisujący
            'podpisujacy_fundacja', 'podpisujacy_stanowisko',
            // Terytorium
            'gmina', 'powiat', 'wojewodztwo', 'teryt_kod',
            // Profil wolontariusza
            'wolontariat_typ', 'obszar_dzialania', 'kompetencje', 'jezyki', 'wyksztalcenie',
            // Dostępność
            'dostepnosc_dni', 'dostepnosc_pora',
            // RODO zgody i upoważnienia
            'email_consent', 'email_consent_at',
            'przetwarza_dane_osobowe',
            // RPTS
            'rpts_wymagana', 'rpts_zweryfikowano', 'rpts_data_weryfikacji', 'rpts_wynik',
            'rpts_zweryfikowal', 'rpts_nr_potwierdzenia', 'rpts_plik_potwierdzenia', 'rpts_uwagi',
        ];
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
        // Przelicz godzin_przepracowanych = godzin_z_zadan + godzin_korekta
        volunteer_recompute_hours($id);
        require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
        log_contract_action($TYPE, $id, current_user()['id'], 'edit', $diff ?: 'Edytowano umowę');

        // Dedykowany wpis w historii dla zmiany flagi „nie wyłączaj dostępu po wygaśnięciu"
        if ((int)($row['m365_nie_wylaczaj'] ?? 0) !== (int)($save['m365_nie_wylaczaj'] ?? 0)) {
            log_contract_action($TYPE, $id, current_user()['id'], 'note',
                !empty($save['m365_nie_wylaczaj'])
                    ? 'Włączono utrzymanie dostępu M365 po wygaśnięciu/zakończeniu umowy'
                    : 'Wyłączono utrzymanie dostępu M365 — konto zostanie wyłączone po wygaśnięciu umowy');
        }

        // Sync grupy CRM "Wolontariusze" gdy zmienił się status lub e-mail
        $status_changed = isset($save['status']) && ($save['status'] !== ($row['status'] ?? ''));
        $email_changed  = isset($save['email'])  && ($save['email']  !== ($row['email']  ?? ''));
        if (($status_changed || $email_changed) && !empty($save['email'])) {
            try {
                require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
                CrmManager::syncVolunteerGroupMember(
                    $save['email'],
                    $save['status'] ?? ($row['status'] ?? '')
                );
            } catch (\Throwable $e) {}
        }

        // ── Nowe upoważnienie RODO wymagane po zaznaczeniu checkbox ──────────
        if ($_rodo_now && !$_rodo_was) {
            // Sprawdź czy nie ma już aktywnego upoważnienia
            require_once dirname(dirname(__DIR__)) . '/includes/rodo.php';
            rodo_migrate();
            $_has_rodo = db_one(
                "SELECT id FROM rodo_authorizations WHERE contract_type='wolontariat' AND contract_id=? AND status='aktywne' LIMIT 1",
                [$id]
            );
            if (!$_has_rodo) {
                // Zbuduj URL do tworzenia RODO
                $_rodo_url = APP_URL . '/rodo/new.php?contract_type=wolontariat&contract_id=' . $id . '&_from_edit=1';
                flash_set('success', 'Zmiany zapisane. Wymagane jest wygenerowanie upoważnienia RODO — potwierdź kodem IKA.');
                // IKA gate przekieruje na $_rodo_url
                auth_start();
                ika_require($_rodo_url);
                // Jeśli IKA jest już ważne — idź od razu do RODO
                header('Location: ' . $_rodo_url); exit;
            }
        }
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
// Edytorzy do selecta opiekuna
try { $__editors = db_all("SELECT id, CASE WHEN first_name!='' AND last_name!='' THEN first_name||' '||last_name ELSE name END AS display_name FROM users WHERE role IN ('admin','editor') AND is_active=1 ORDER BY display_name"); }
catch (\Throwable $e) { $__editors = []; }
// Dorośli wolontariusze (inne umowy) do selecta opiekuna towarzyszącego małoletniemu
try {
    $__opiekunowie_wol = db_all(
        "SELECT id, imie_nazwisko FROM umowy_wolontariat
         WHERE id != ? AND (niepelnoletni = 0 OR niepelnoletni IS NULL)
           AND status NOT IN ('zakończona', 'anulowana', 'rozwiązana')
         ORDER BY imie_nazwisko",
        [(int)$id]
    );
} catch (\Throwable $e) { $__opiekunowie_wol = []; }
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

/* ── Section nav — boczny układ (jak w podglądzie) ─────── */
.edit-layout { display: flex; gap: 1rem; align-items: flex-start; }
.edit-main   { flex: 1 1 auto; min-width: 0; }
#sec-nav {
  flex: 0 0 208px; max-width: 208px;
  display: flex; flex-direction: column; gap: .18rem;
  margin-bottom: 0; padding: .5rem;
  background: #f8fafc; border-radius: .6rem; border: 1px solid #e2e8f0;
  position: sticky; top: 64px;
}
#sec-nav a {
  font-size: .8rem; font-weight: 600; padding: .45rem .7rem;
  border-radius: 8px; text-decoration: none;
  color: #475569; background: transparent; border: none;
  white-space: normal; transition: all .12s; display: block;
}
#sec-nav a:hover  { background: #eef2f7; color: #1e293b; }
#sec-nav a.active { background: #2563eb; color: #fff; }

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
  .edit-layout  { flex-direction: column; }
  #sec-nav {
    flex-basis: auto; max-width: none; width: 100%;
    flex-direction: row; flex-wrap: wrap; position: static; margin-bottom: 1rem;
  }
  #sec-nav a { width: auto; }
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

<!-- ── Boczny układ: nawigacja sekcji + formularz ─────────── -->
<div class="edit-layout">

<!-- ── Section jump nav (boczna, przyklejona) ─────────────── -->
<nav id="sec-nav" aria-label="Sekcje formularza">
  <a href="#sec-podstawowe">📋 Porozumienie</a>
  <a href="#sec-wolontariusz">👤 Wolontariusz</a>
  <a href="#sec-profil">🗂 Profil</a>
  <a href="#sec-szczegoly">📍 Szczegóły</a>
  <a href="#sec-bhp">🛡 BHP &amp; Ubezpieczenia</a>
  <a href="#sec-podpisanie">✍ Podpisanie</a>
  <a href="#sec-powiazania">🔗 Powiązania</a>
  <a href="#sec-it">🔐 Dostęp IT</a>
  <a href="#sec-zaawansowane">⚙ Zaawansowane</a>
</nav>

<div class="edit-main">
<form method="post" enctype="multipart/form-data" id="editForm"
      data-cpc="<?= in_array(current_user()['role'] ?? '', ['admin','editor']) ? '1' : '0' ?>"
      data-cpc-meta=''
      data-dirty-check
      data-autosave="wolontariat_edit_<?= $id ?>">
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
      <label for="guardian_editor_id">Opiekun</label>
      <select name="guardian_editor_id" id="guardian_editor_id" class="form-select">
        <option value="">— nie przypisano —</option>
        <?php foreach ($__editors as $ed): ?>
        <option value="<?= (int)$ed['id'] ?>" <?= (int)($row['guardian_editor_id']??0) === (int)$ed['id'] ? 'selected' : '' ?>><?= h($ed['display_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Inicjały opiekuna pojawiają się na numerze umowy.</div>
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
    <div class="col-md-6 d-flex align-items-end pb-1">
      <div class="toggle-row w-100 mb-0">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch"
                 name="m365_nie_wylaczaj" id="m365_nie_wylaczaj" value="1"
                 <?= !empty($row['m365_nie_wylaczaj']) ? 'checked' : '' ?>>
        </div>
        <label for="m365_nie_wylaczaj" class="mb-0">Nie wyłączaj dostępu po wygaśnięciu umowy</label>
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
      <?= byli_check_field('imie_nazwisko') ?>
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
      <?= address_widget($row, ['label' => 'Adres zamieszkania', 'autocomplete' => true]) ?>
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
      <div class="mt-2">
        <a href="assign_guardian.php?id=<?= (int)$id ?>" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Wyznacz / powiąż konto opiekuna
        </a>
        <div class="form-text">Utworzy (jeśli brak) i powiąże konto rodzica z kontem dziecka — opiekun będzie mógł wejść na konto dziecka ze swojego panelu.</div>
      </div>
    </div>

    <div class="p-3 mt-2 rounded" style="background:#eff6ff;border:1px solid #bfdbfe">
      <div class="fw-semibold small mb-2">
        <i class="bi bi-person-check text-primary me-1"></i>Opiekun towarzyszący (wolontariusz)
      </div>
      <div class="row g-3">
        <div class="col-md-6 fgroup">
          <label>Dorosły wolontariusz nadzorujący</label>
          <select name="opiekun_wolontariusz_id" class="form-select form-select-sm">
            <option value="">— brak —</option>
            <?php foreach ($__opiekunowie_wol as $ow): ?>
            <option value="<?= (int)$ow['id'] ?>" <?= (int)($row['opiekun_wolontariusz_id'] ?? 0) === (int)$ow['id'] ? 'selected' : '' ?>>
              <?= h($ow['imie_nazwisko']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Dorosły wolontariusz FEER towarzyszący małoletniemu przy wykonywaniu świadczeń — pole informacyjne/organizacyjne, bez wpływu na zgodę przedstawiciela ustawowego.</div>
        </div>
      </div>
    </div>
  </div>

</section>

<!-- ══════════════════════════════════════════════════════════
     SEKCJA 2b — PROFIL WOLONTARIUSZA
     ══════════════════════════════════════════════════════════ -->
<section id="sec-profil" class="esec">
  <div class="esec-head">
    <i class="bi bi-person-lines-fill"></i>
    <h6>Profil wolontariusza <span class="esec-sub">segmentacja, terytorium, dostępność</span></h6>
  </div>

  <?php
  $woj_list = ['dolnośląskie','kujawsko-pomorskie','lubelskie','lubuskie','łódzkie','małopolskie','mazowieckie','opolskie','podkarpackie','podlaskie','pomorskie','śląskie','świętokrzyskie','warmińsko-mazurskie','wielkopolskie','zachodniopomorskie'];
  $typ_list  = ['stały' => 'Stały', 'jednorazowy' => 'Jednorazowy', 'projektowy' => 'Projektowy', 'akcyjny' => 'Akcyjny / eventowy', 'wakacyjny' => 'Wakacyjny'];
  $obszar_all = ['społeczny' => 'Społeczny', 'edukacyjny' => 'Edukacyjny', 'zdrowotny' => 'Zdrowotny', 'ekologiczny' => 'Ekologiczny', 'kulturalny' => 'Kulturalny', 'sportowy' => 'Sportowy', 'pomocowy' => 'Pomocowy / humanitarny', 'zwierzeta' => 'Ochrona zwierząt', 'cyfrowy' => 'Cyfrowy / IT', 'inny' => 'Inny'];
  $wyksztalcenie_list = ['podstawowe' => 'Podstawowe', 'zawodowe' => 'Zawodowe', 'srednie' => 'Średnie', 'wyzsze_lic' => 'Wyższe — licencjat', 'wyzsze_mgr' => 'Wyższe — magister', 'doktorat' => 'Doktorat / dr', 'student' => 'Student'];
  $current_obszar = json_decode($row['obszar_dzialania'] ?? '[]', true) ?: [];
  $current_dni    = json_decode($row['dostepnosc_dni']   ?? '[]', true) ?: [];
  $current_pora   = json_decode($row['dostepnosc_pora']  ?? '[]', true) ?: [];
  ?>

  <div class="row g-3 mb-3">
    <!-- Typ wolontariatu -->
    <div class="col-md-4 fgroup">
      <label>Typ wolontariatu</label>
      <select name="wolontariat_typ" class="form-select form-select-sm">
        <option value="">— nie określono —</option>
        <?php foreach ($typ_list as $v => $l): ?>
        <option value="<?= h($v) ?>" <?= ($row['wolontariat_typ'] ?? '') === $v ? 'selected' : '' ?>><?= h($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <!-- Wykształcenie -->
    <div class="col-md-4 fgroup">
      <label>Wykształcenie</label>
      <select name="wyksztalcenie" class="form-select form-select-sm">
        <option value="">— nie określono —</option>
        <?php foreach ($wyksztalcenie_list as $v => $l): ?>
        <option value="<?= h($v) ?>" <?= ($row['wyksztalcenie'] ?? '') === $v ? 'selected' : '' ?>><?= h($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <!-- Języki -->
    <div class="col-md-4 fgroup">
      <label>Języki <span class="text-muted fw-normal">(oddziel przecinkiem)</span></label>
      <input name="jezyki" class="form-control form-control-sm"
             placeholder="polski, angielski, ukraiński…"
             value="<?= h($row['jezyki'] ?? '') ?>">
    </div>
    <!-- Kompetencje -->
    <div class="col-12 fgroup">
      <label>Kompetencje / specjalizacje <span class="text-muted fw-normal">(swobodny opis)</span></label>
      <textarea name="kompetencje" class="form-control form-control-sm" rows="2"
                placeholder="np. grafika komputerowa, pierwsza pomoc, obsługa mediów społecznościowych…"><?= h($row['kompetencje'] ?? '') ?></textarea>
    </div>
  </div>

  <!-- Obszar działania (multi-select checkboxes) -->
  <div class="mb-3">
    <label class="form-label small fw-semibold mb-1">Obszar działania</label>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($obszar_all as $v => $l): ?>
      <label class="badge fw-normal border text-dark d-flex align-items-center gap-1"
             style="cursor:pointer;padding:.35em .7em;background:<?= in_array($v, $current_obszar) ? '#fff3e0;border-color:#fd7e14!important' : '#f8fafc' ?>">
        <input type="checkbox" name="obszar_dzialania[]" value="<?= h($v) ?>"
               class="form-check-input mt-0" style="width:13px;height:13px"
               <?= in_array($v, $current_obszar) ? 'checked' : '' ?>>
        <?= h($l) ?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Terytorium -->
  <div class="p-3 rounded mb-3" style="background:#f8fafc;border:1px solid #e2e8f0">
    <div class="fw-semibold small mb-2"><i class="bi bi-map me-1 text-secondary"></i>Terytorium działania / zamieszkania</div>
    <div class="row g-2">
      <div class="col-md-4 fgroup">
        <label>Województwo</label>
        <select name="wojewodztwo" class="form-select form-select-sm">
          <option value="">— wybierz —</option>
          <?php foreach ($woj_list as $w): ?>
          <option value="<?= h($w) ?>" <?= ($row['wojewodztwo'] ?? '') === $w ? 'selected' : '' ?>><?= h(ucfirst($w)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 fgroup">
        <label>Powiat</label>
        <input name="powiat" class="form-control form-control-sm"
               placeholder="np. powiat warszawski zachodni"
               value="<?= h($row['powiat'] ?? '') ?>">
      </div>
      <div class="col-md-3 fgroup">
        <label>Gmina / miejscowość</label>
        <input name="gmina" class="form-control form-control-sm"
               placeholder="np. Ożarów Mazowiecki"
               value="<?= h($row['gmina'] ?? '') ?>">
      </div>
      <div class="col-md-1 fgroup">
        <label>Kod TERYT</label>
        <input name="teryt_kod" class="form-control form-control-sm font-monospace"
               maxlength="10" placeholder="1461011"
               value="<?= h($row['teryt_kod'] ?? '') ?>">
      </div>
    </div>
  </div>

  <!-- Dostępność -->
  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <label class="form-label small fw-semibold mb-1">Dostępne dni tygodnia</label>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach (['pon' => 'Pon', 'wt' => 'Wt', 'sr' => 'Śr', 'czw' => 'Czw', 'pt' => 'Pt', 'sob' => 'Sob', 'ndz' => 'Ndz'] as $v => $l): ?>
        <label class="badge fw-normal border text-dark d-flex align-items-center gap-1"
               style="cursor:pointer;padding:.4em .75em;background:<?= in_array($v, $current_dni) ? '#e0f2fe;border-color:#0ea5e9!important' : '#f8fafc' ?>">
          <input type="checkbox" name="dostepnosc_dni[]" value="<?= $v ?>"
                 class="form-check-input mt-0" style="width:13px;height:13px"
                 <?= in_array($v, $current_dni) ? 'checked' : '' ?>>
          <?= $l ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-md-6">
      <label class="form-label small fw-semibold mb-1">Pora dnia</label>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach (['rano' => 'Rano', 'popoludnie' => 'Południe', 'wieczor' => 'Wieczór', 'weekend' => 'Weekend'] as $v => $l): ?>
        <label class="badge fw-normal border text-dark d-flex align-items-center gap-1"
               style="cursor:pointer;padding:.4em .75em;background:<?= in_array($v, $current_pora) ? '#e0f2fe;border-color:#0ea5e9!important' : '#f8fafc' ?>">
          <input type="checkbox" name="dostepnosc_pora[]" value="<?= $v ?>"
                 class="form-check-input mt-0" style="width:13px;height:13px"
                 <?= in_array($v, $current_pora) ? 'checked' : '' ?>>
          <?= $l ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ── Dyspozycyjność szczegółowa (sloty) + urlopy ─────────────── -->
  <?php if (module_enabled('dyspozycyjnosc_enabled')):
  dyspo_migrate();
  $dyspo_slots_init  = dyspo_slots($id);
  $dyspo_urlopy_init = urlop_list($id);
  ?>
  <div id="dyspoAdmin" data-cid="<?= (int)$id ?>" class="mb-3">
    <div class="row g-3">
      <!-- Sloty -->
      <div class="col-md-6">
        <div class="p-3 rounded h-100" style="background:#f8fafc;border:1px solid #e2e8f0">
          <div class="fw-semibold small mb-2"><i class="bi bi-clock me-1 text-primary"></i>Konkretne terminy dostępności</div>
          <div id="dyspoSlotList" class="d-flex flex-column gap-1 mb-2"></div>
          <div class="d-flex flex-wrap gap-1 align-items-end">
            <div><label class="form-label small mb-0">Data</label><input type="date" id="dyspoSlotData" class="form-control form-control-sm"></div>
            <div><label class="form-label small mb-0">Od</label><input type="time" id="dyspoSlotOd" class="form-control form-control-sm"></div>
            <div><label class="form-label small mb-0">Do</label><input type="time" id="dyspoSlotDo" class="form-control form-control-sm"></div>
            <button type="button" id="dyspoSlotAdd" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i></button>
          </div>
          <input type="text" id="dyspoSlotNota" class="form-control form-control-sm mt-1" placeholder="Notatka (opcjonalnie)">
        </div>
      </div>
      <!-- Urlopy -->
      <div class="col-md-6">
        <div class="p-3 rounded h-100" style="background:#fff7ed;border:1px solid #fed7aa">
          <div class="fw-semibold small mb-2"><i class="bi bi-airplane me-1 text-warning"></i>Urlopy / niedostępność <span class="text-muted fw-normal">(akceptacja formalna)</span></div>
          <div id="dyspoUrlopList" class="d-flex flex-column gap-1 mb-2"></div>
          <div class="d-flex flex-wrap gap-1 align-items-end">
            <div><label class="form-label small mb-0">Od</label><input type="date" id="dyspoUrlOd" class="form-control form-control-sm"></div>
            <div><label class="form-label small mb-0">Do</label><input type="date" id="dyspoUrlDo" class="form-control form-control-sm"></div>
            <button type="button" id="dyspoUrlAdd" class="btn btn-sm btn-warning text-dark"><i class="bi bi-plus-lg"></i></button>
          </div>
          <input type="text" id="dyspoUrlPowod" class="form-control form-control-sm mt-1" placeholder="Powód (opcjonalnie)">
        </div>
      </div>
    </div>
  </div>
  <script>
  (function(){
    const CID  = <?= (int)$id ?>;
    const CSRF = <?= json_encode(csrf_token()) ?>;
    const EP   = <?= json_encode(APP_URL . '/contracts/wolontariat/dyspo_action.php') ?>;
    const SBADGE = {oczekuje:['warning','Oczekuje'],zaakceptowany:['success','Zaakceptowany'],odrzucony:['danger','Odrzucony']};
    let state = <?= json_encode(['slots'=>$dyspo_slots_init,'urlopy'=>$dyspo_urlopy_init], JSON_UNESCAPED_UNICODE) ?>;

    const el = id => document.getElementById(id);
    const slotList = el('dyspoSlotList'), urlList = el('dyspoUrlopList');

    function plDate(d){ if(!d) return ''; const p=d.split('-'); return p.length===3?`${p[2]}.${p[1]}.${p[0]}`:d; }
    function hhmm(t){ return (t||'').slice(0,5); }

    async function call(payload){
      const fd = new FormData();
      fd.append('_ajax','1'); fd.append('_csrf',CSRF); fd.append('contract_id',CID);
      for (const k in payload) fd.append(k, payload[k]);
      const r = await fetch(EP, {method:'POST', body:fd, headers:{'Accept':'application/json'}});
      const j = await r.json();
      if (j.ok) { state = {slots:j.slots, urlopy:j.urlopy}; render(); }
      else alert(j.error || 'Błąd zapisu.');
    }

    function mkBtn(cls, icon, title){
      const b=document.createElement('button'); b.type='button';
      b.className='btn btn-sm '+cls; b.title=title||''; b.innerHTML='<i class="bi '+icon+'"></i>';
      return b;
    }

    function render(){
      // Sloty
      slotList.innerHTML='';
      if(!state.slots.length){ slotList.innerHTML='<div class="text-muted small fst-italic">Brak terminów.</div>'; }
      state.slots.forEach(s=>{
        const row=document.createElement('div');
        row.className='d-flex align-items-center gap-2 bg-white border rounded px-2 py-1';
        const txt=document.createElement('div'); txt.className='small flex-grow-1';
        txt.innerHTML='<strong></strong> <span class="text-muted"></span>';
        txt.querySelector('strong').textContent = plDate(s.data)+'  '+hhmm(s.czas_od)+'–'+hhmm(s.czas_do);
        if(s.notatka){ txt.querySelector('span').textContent = '· '+s.notatka; }
        const del=mkBtn('btn-outline-danger border-0 p-1','bi-trash','Usuń');
        del.onclick=()=>{ if(confirm('Usunąć termin?')) call({action:'slot_del', id:s.id}); };
        row.append(txt, del); slotList.append(row);
      });
      // Urlopy
      urlList.innerHTML='';
      if(!state.urlopy.length){ urlList.innerHTML='<div class="text-muted small fst-italic">Brak urlopów.</div>'; }
      state.urlopy.forEach(u=>{
        const wrap=document.createElement('div');
        wrap.className='bg-white border rounded px-2 py-1';
        const top=document.createElement('div'); top.className='d-flex align-items-center gap-2';
        const txt=document.createElement('div'); txt.className='small flex-grow-1';
        const rng = plDate(u.data_od)+(u.data_od===u.data_do?'':' – '+plDate(u.data_do));
        txt.innerHTML='<strong></strong> <span class="text-muted"></span>';
        txt.querySelector('strong').textContent=rng;
        if(u.powod){ txt.querySelector('span').textContent='· '+u.powod; }
        const bi=SBADGE[u.status]||['secondary',u.status];
        const badge=document.createElement('span'); badge.className='badge bg-'+bi[0]; badge.textContent=bi[1];
        top.append(txt, badge);
        if(u.status==='oczekuje'){
          const ok=mkBtn('btn-success p-1','bi-check-lg','Zatwierdź');
          ok.onclick=()=>call({action:'urlop_decide', id:u.id, decision:'zaakceptowany', decision_note:''});
          const no=mkBtn('btn-outline-danger border-0 p-1','bi-x-lg','Odrzuć');
          no.onclick=()=>{ const n=prompt('Powód odrzucenia (opcjonalnie):')??''; call({action:'urlop_decide', id:u.id, decision:'odrzucony', decision_note:n}); };
          top.append(ok, no);
        } else {
          const del=mkBtn('btn-outline-secondary border-0 p-1','bi-trash','Usuń');
          del.onclick=()=>{ if(confirm('Usunąć wpis urlopu?')) call({action:'urlop_del', id:u.id}); };
          top.append(del);
        }
        wrap.append(top);
        if(u.decision_note){ const n=document.createElement('div'); n.className='text-muted small mt-1'; n.textContent='Uwaga: '+u.decision_note; wrap.append(n); }
        urlList.append(wrap);
      });
    }

    el('dyspoSlotAdd').onclick=()=>{
      const data=el('dyspoSlotData').value, od=el('dyspoSlotOd').value, doKon=el('dyspoSlotDo').value;
      if(!data||!od||!doKon){ alert('Podaj datę i godziny.'); return; }
      call({action:'slot_add', data, czas_od:od, czas_do:doKon, notatka:el('dyspoSlotNota').value})
        .then(()=>{ el('dyspoSlotData').value=el('dyspoSlotOd').value=el('dyspoSlotDo').value=el('dyspoSlotNota').value=''; });
    };
    el('dyspoUrlAdd').onclick=()=>{
      const od=el('dyspoUrlOd').value, doKon=el('dyspoUrlDo').value;
      if(!od||!doKon){ alert('Podaj zakres dat.'); return; }
      call({action:'urlop_add', data_od:od, data_do:doKon, powod:el('dyspoUrlPowod').value})
        .then(()=>{ el('dyspoUrlOd').value=el('dyspoUrlDo').value=el('dyspoUrlPowod').value=''; });
    };

    render();
  })();
  </script>
  <?php endif; ?>

  <!-- Zgoda RODO email -->
  <div class="p-3 rounded mb-2" style="background:#f0fdf4;border:1px solid #bbf7d0">
    <div class="toggle-row">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch"
               name="email_consent" id="emailConsent" value="1"
               <?= !empty($row['email_consent']) ? 'checked' : '' ?>>
      </div>
      <div>
        <label for="emailConsent" class="mb-0 fw-semibold">
          <i class="bi bi-shield-check text-success me-1"></i>Zgoda na komunikację e-mail (RODO)
        </label>
        <div class="ts-sub">Wolontariusz wyraził zgodę na otrzymywanie wiadomości e-mail z organizacji</div>
        <?php if (!empty($row['email_consent_at'])): ?>
        <div class="text-success small mt-1">
          <i class="bi bi-check-circle me-1"></i>Zgoda udzielona: <?= date_pl(substr($row['email_consent_at'],0,10)) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Upoważnienie do przetwarzania danych osobowych -->
  <?php
  try { require_once dirname(dirname(__DIR__)) . '/includes/rodo.php'; rodo_migrate(); } catch(\Throwable $e) {}
  $_has_active_rodo = false;
  try {
      $_has_active_rodo = (bool)db_one(
          "SELECT id FROM rodo_authorizations WHERE contract_type='wolontariat' AND contract_id=? AND status='aktywne'",
          [$id]
      );
  } catch(\Throwable $e) {}
  ?>
  <div class="p-3 rounded" style="background:#fff3e0;border:1px solid #fed7aa">
    <div class="toggle-row">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch"
               name="przetwarza_dane_osobowe" id="przetwarzaDane" value="1"
               <?= !empty($row['przetwarza_dane_osobowe']) ? 'checked' : '' ?>>
      </div>
      <div class="flex-grow-1">
        <label for="przetwarzaDane" class="mb-0 fw-semibold">
          <i class="bi bi-shield-lock text-warning me-1"></i>
          <?= h($row['imie_nazwisko'] ?: 'Wolontariusz') ?> przetwarza dane osobowe
        </label>
        <div class="ts-sub">
          Zaznacz jeśli wolontariusz ma dostęp do danych osobowych — wymagane upoważnienie RODO (§ 29 RODO).
          Po zapisaniu zostaniesz poproszony o kod IKA i wygenerowanie upoważnienia.
        </div>
        <?php if (!empty($row['przetwarza_dane_osobowe'])): ?>
          <?php if ($_has_active_rodo): ?>
          <div class="text-success small mt-1">
            <i class="bi bi-check-circle-fill me-1"></i>
            Upoważnienie RODO aktywne ·
            <a href="<?= APP_URL ?>/rodo/index.php?type=wolontariat&q=<?= urlencode($row['numer_umowy']) ?>">podgląd</a>
          </div>
          <?php else: ?>
          <div class="text-warning small mt-1">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            Brak aktywnego upoważnienia RODO — wygeneruj po zapisaniu.
          </div>
          <?php endif; ?>
        <?php endif; ?>
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
      <label>Godzin z zadań</label>
      <input type="text" class="form-control" readonly
             value="<?= h(number_format((float)($row['godzin_z_zadan'] ?? 0), 2, ',', ' ')) ?> h"
             title="Suma zarejestrowanego czasu zadań przypisanych do wolontariusza (wyliczane automatycznie)">
      <div class="form-text">Z zarejestrowanego czasu zadań — wyliczane automatycznie.</div>
    </div>
    <div class="col-md-3 fgroup">
      <label>Korekta ręczna (h)</label>
      <input name="godzin_korekta" type="number" step="0.5" class="form-control"
             value="<?= h($row['godzin_korekta'] ?? 0) ?>"
             title="Ręczna korekta (+/-), np. godziny przepracowane poza systemem zadań">
      <div class="form-text">
        Razem: <strong><?= h(number_format((float)($row['godzin_przepracowanych'] ?? 0), 2, ',', ' ')) ?> h</strong>
        (zadania + korekta).
      </div>
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
  <div id="zwrot_kosztow_opis_field" style="display:<?= $row['zwrot_kosztow'] ? '' : 'none' ?>" class="mt-2 row g-2">
    <div class="col-md-8 fgroup">
      <label>Opis zwrotu kosztów</label>
      <input name="zwrot_kosztow_opis" class="form-control" value="<?= h($row['zwrot_kosztow_opis']) ?>">
    </div>
    <div class="col-md-4 fgroup">
      <label>Limit zwrotu (zł)</label>
      <input name="limit_zwrotu_kosztow" type="number" step="0.01" min="0" class="form-control"
             value="<?= h($row['limit_zwrotu_kosztow'] ?? '') ?>" placeholder="np. 200.00">
    </div>
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
     SEKCJA 4B — WERYFIKACJA RPTS
     ══════════════════════════════════════════════════════════ -->
<section id="sec-rpts" class="esec">
  <div class="esec-head">
    <i class="bi bi-shield-exclamation"></i>
    <h6>Weryfikacja RPTS <span class="esec-sub">Rejestr Sprawców Przestępstw na Tle Seksualnym</span></h6>
  </div>

  <div class="alert alert-secondary small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    Wymagana, gdy wolontariusz ma kontakt z małoletnimi (ustawa z 13.05.2016 r.).
    Sprawdzenia dokonuje się ręcznie na <strong>rps.ms.gov.pl</strong> — tu odnotuj wynik i dowód weryfikacji.
  </div>

  <div class="toggle-row">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" role="switch"
             name="rpts_wymagana" id="rpts_wymagana" value="1"
             <?= $row['rpts_wymagana'] ? 'checked' : '' ?>>
    </div>
    <label for="rpts_wymagana" class="mb-0">Wolontariusz ma kontakt z małoletnimi — wymagana weryfikacja RPTS</label>
  </div>

  <div id="rpts_fields" style="<?= $row['rpts_wymagana'] ? '' : 'display:none' ?>">
    <div class="toggle-row mt-2">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch"
               name="rpts_zweryfikowano" id="rpts_zweryfikowano" value="1"
               <?= $row['rpts_zweryfikowano'] ? 'checked' : '' ?>>
      </div>
      <label for="rpts_zweryfikowano" class="mb-0">Zweryfikowano w RPTS</label>
    </div>

    <div class="row g-3 mt-1" id="rpts_detail_fields" style="<?= $row['rpts_zweryfikowano'] ? '' : 'display:none' ?>">
      <div class="col-md-3 fgroup">
        <label>Data weryfikacji</label>
        <input name="rpts_data_weryfikacji" type="date" class="form-control"
               value="<?= h($row['rpts_data_weryfikacji'] ?? '') ?>">
      </div>
      <div class="col-md-3 fgroup">
        <label>Wynik weryfikacji</label>
        <select name="rpts_wynik" class="form-select">
          <option value="">— wybierz —</option>
          <?php foreach (rpts_wynik_options() as $_rk => $_rl): ?>
          <option value="<?= h($_rk) ?>" <?= ($row['rpts_wynik'] ?? '') === $_rk ? 'selected' : '' ?>><?= h($_rl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3 fgroup">
        <label>Kto zweryfikował</label>
        <input name="rpts_zweryfikowal" class="form-control"
               value="<?= h($row['rpts_zweryfikowal'] ?? '') ?>" placeholder="Imię i nazwisko">
      </div>
      <div class="col-md-3 fgroup">
        <label>Nr / identyfikator potwierdzenia</label>
        <input name="rpts_nr_potwierdzenia" class="form-control"
               value="<?= h($row['rpts_nr_potwierdzenia'] ?? '') ?>">
      </div>
      <div class="col-md-8 fgroup">
        <label>Skan/wydruk potwierdzenia weryfikacji</label>
        <input name="rpts_plik_potwierdzenia" type="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
        <?php if ($row['rpts_plik_potwierdzenia']): ?>
        <div class="mt-1"><?= upload_link($row['rpts_plik_potwierdzenia']) ?></div>
        <?php endif; ?>
      </div>
      <div class="col-12 fgroup">
        <label>Uwagi</label>
        <textarea name="rpts_uwagi" class="form-control" rows="2"><?= h($row['rpts_uwagi'] ?? '') ?></textarea>
      </div>
    </div>
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
      <label>Podpisuje ze strony fundacji</label>
      <?= org_representative_select_by_name('podpisujacy_fundacja', $row['podpisujacy_fundacja'] ?? '', 'form-select') ?>
    </div>
    <div class="col-md-4 fgroup">
      <label>Stanowisko / funkcja <span class="text-muted small">(uzupełnia się automatycznie)</span></label>
      <input name="podpisujacy_stanowisko" class="form-control"
             id="podpisujacy_stanowisko"
             value="<?= h($row['podpisujacy_stanowisko'] ?? '') ?>"
             placeholder="np. Prezes Zarządu">
    </div>
  </div>
  <div class="row g-3 mt-1">
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

  <div id="epodpis_fields" class="mt-2" style="display:<?= $row['forma_podpisania'] === 'epodpis_kwalifikowany' ? '' : 'none' ?>">
    <div class="card border-0 shadow-sm" style="border-left:4px solid #16a34a!important;background:#f0fdf4">
      <div class="card-body py-3 px-4">
        <div class="d-flex align-items-center gap-2 mb-3">
          <i class="bi bi-shield-lock-fill text-success fs-5"></i>
          <span class="fw-bold" style="color:#16a34a">Podpis kwalifikowany (X.509 / eIDAS)</span>
        </div>
        <div class="row g-3">
          <div class="col-sm-4">
            <label class="form-label small fw-semibold">Dostawca podpisu (TSP)</label>
            <input name="epodpis_dostawca" class="form-control form-control-sm"
                   placeholder="Certum, SimplySign, mSzafir…"
                   value="<?= h($row['epodpis_dostawca'] ?? '') ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label small fw-semibold">Numer certyfikatu</label>
            <input name="epodpis_nr_certyfikatu" class="form-control form-control-sm font-monospace"
                   value="<?= h($row['epodpis_nr_certyfikatu'] ?? '') ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label small fw-semibold">Ważność certyfikatu</label>
            <input name="epodpis_data_waznosci" type="date" class="form-control form-control-sm"
                   value="<?= h($row['epodpis_data_waznosci'] ?? '') ?>">
          </div>
        </div>
        <div class="row g-3 mt-1">
          <div class="col-12">
            <label class="form-label small fw-semibold">
              <i class="bi bi-cloud-upload me-1"></i>Podpisany dokument
              <?php if ($row['plik_potwierdzenia']): ?>
              <span class="text-muted fw-normal">(aktualny: <?= upload_link($row['plik_potwierdzenia']) ?>)</span>
              <?php endif; ?>
            </label>
            <input name="plik_potwierdzenia" type="file" class="form-control form-control-sm"
                   accept=".pdf,.docx,.xades,.p7m">
            <div class="form-text">Wgraj plik podpisany kwalifikowanym podpisem (PAdES / XAdES / CAdES).</div>
          </div>
        </div>
        <?php
        require_once dirname(dirname(dirname(__FILE__))) . '/includes/contract_template_engine.php';
        $_epodpis_edit_tpls = cte_list('wolontariat');
        if ($_epodpis_edit_tpls):
        ?>
        <div class="mt-3 pt-2 border-top border-success border-opacity-25">
          <div class="small fw-semibold text-success mb-2">
            <i class="bi bi-file-earmark-word me-1"></i>Pobierz wzór do podpisu:
          </div>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($_epodpis_edit_tpls as $_etpl): ?>
            <a href="<?= h(APP_URL . '/contracts/download_template_docx.php?template_id=' . $_etpl['id'] . '&contract_id=' . $id . '&type=wolontariat') ?>"
               class="btn btn-sm btn-outline-secondary"
               title="Pobierz DOCX do podpisu kwalifikowanego">
              <i class="bi bi-file-earmark-word me-1"></i><?= h($_etpl['name']) ?>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
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
     SEKCJA IT — DOSTĘP I UPRAWNIENIA
     ══════════════════════════════════════════════════════════ -->
<section id="sec-it" class="esec">
  <div class="esec-head">
    <i class="bi bi-shield-lock" style="color:#7c3aed"></i>
    <h6>Dostęp IT <span class="esec-sub">portal, Canva, security group</span></h6>
  </div>

  <!-- Ważna uwaga o uprawnieniach -->
  <div class="alert alert-primary d-flex gap-2 py-2 mb-3" style="font-size:.83rem;background:#eff6ff;border-color:#bfdbfe;color:#1e40af">
    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
    <div>
      <strong>Uprawnienia przez Security Group:</strong>
      Wszelkie ścieżki uprawnień (dostęp do plików, SharePoint, Teams, aplikacji M365)
      są przypisywane na poziomie <strong>Security Group</strong> w Azure AD — nie per użytkownik.
      Zmiana grupy poniżej automatycznie aktualizuje zakres dostępu w całej platformie M365.
    </div>
  </div>

  <!-- Zakres portalu -->
  <div class="mb-3">
    <div class="fw-semibold small mb-2"><i class="bi bi-display me-1"></i>Zakres dostępu do portalu</div>
    <div class="d-flex flex-column gap-2">
      <?php foreach ([
        ''           => ['label'=>'Pełny dostęp','sub'=>'Wolontariusz widzi wszystkie dostępne moduły','icon'=>'bi-grid-3x3-gap-fill','color'=>'#1d6ef9'],
        'tasks_only' => ['label'=>'Tylko zadania','sub'=>'Przekierowanie bezpośrednio do tablicy zadań','icon'=>'bi-kanban','color'=>'#0ea5e9'],
        'crm_only'   => ['label'=>'Tylko CRM','sub'=>'Przekierowanie bezpośrednio do systemu CRM','icon'=>'bi-diagram-2-fill','color'=>'#16a34a'],
      ] as $val => $opt): $checked = ($row['portal_scope']??'') === $val; ?>
      <label style="display:flex;align-items:flex-start;gap:.75rem;padding:.6rem .85rem;border-radius:9px;border:1.5px solid <?= $checked?$opt['color']:'#E2E8F0' ?>;background:<?= $checked?'#F8FBFF':'#fff' ?>;cursor:pointer;transition:border-color .12s">
        <input type="radio" name="portal_scope" value="<?= h($val) ?>" <?= $checked?'checked':'' ?> style="margin-top:.2rem;flex-shrink:0">
        <div>
          <div style="font-weight:600;font-size:.85rem;color:<?= $opt['color'] ?>">
            <i class="bi <?= $opt['icon'] ?> me-1"></i><?= $opt['label'] ?>
          </div>
          <div style="font-size:.75rem;color:#64748B"><?= $opt['sub'] ?></div>
        </div>
      </label>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Canva -->
  <div class="toggle-row mb-3">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" role="switch"
             name="canva_access" id="canva_access_edit" value="1"
             <?= !empty($row['canva_access'])?'checked':'' ?>>
    </div>
    <div>
      <label for="canva_access_edit" class="mb-0 fw-semibold small">Dostęp do Canva Pro</label>
      <div style="font-size:.72rem;color:#94A3B8">
        <?php if (!empty($row['canva_invited_at'])): ?>
          <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Zaproszony <?= date_pl($row['canva_invited_at']) ?></span>
        <?php else: ?>
          Zaproszenie do Canva zostanie wysłane po zaznaczeniu
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Security Group M365 -->
  <div class="fgroup">
    <label class="fw-semibold small"><i class="bi bi-people-fill text-primary me-1"></i>Security Group M365</label>
    <div class="input-group input-group-sm">
      <span class="input-group-text bg-white border-end-0"><i class="bi bi-microsoft text-primary"></i></span>
      <input name="m365_security_group_name" id="sg_name_edit" class="form-control border-start-0"
             value="<?= h($row['m365_security_group_name']??'') ?>"
             placeholder="np. Wolontariusze-Aktywni" style="font-family:monospace;font-size:.85rem">
    </div>
    <input type="hidden" name="m365_security_group_id" id="sg_id_edit" value="<?= h($row['m365_security_group_id']??'') ?>">
    <div class="form-text">
      Przypisanie do grupy nadaje <strong>wszystkie uprawnienia M365</strong> (SharePoint, Teams, aplikacje).
      Zmiana grupy wymaga ręcznej aktualizacji w Azure AD — system zapisuje tylko metadane.
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
</div><!-- /edit-main -->
</div><!-- /edit-layout -->

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
document.getElementById('rpts_wymagana').addEventListener('change', function () {
  document.getElementById('rpts_fields').style.display = this.checked ? '' : 'none';
});
document.getElementById('rpts_zweryfikowano').addEventListener('change', function () {
  document.getElementById('rpts_detail_fields').style.display = this.checked ? '' : 'none';
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
