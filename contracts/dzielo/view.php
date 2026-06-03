<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/termination.php';
require_once dirname(dirname(__DIR__)) . '/includes/amendments.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
require_once dirname(dirname(__DIR__)) . '/includes/letters.php';
require_once dirname(dirname(__DIR__)) . '/includes/certificates.php';
require_once dirname(dirname(__DIR__)) . '/includes/m365.php';
require_once dirname(dirname(__DIR__)) . '/includes/messages.php';
require_once dirname(dirname(__DIR__)) . '/includes/supervisors.php';
require_once dirname(dirname(__DIR__)) . '/includes/persons.php';
require_once dirname(dirname(__DIR__)) . '/includes/address.php';

require_login();
$TYPE  = 'dzielo';
$TABLE = 'umowy_dzielo';
$id    = intval($_GET['id'] ?? 0);
$row   = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); die('Nie znaleziono umowy.'); }
if (!viewer_owns_contract($TYPE, $row)) {
    flash_set('error', 'Nie masz dostępu do tej umowy.');
    header('Location: ' . APP_URL . '/panel/index.php'); exit;
}
$PAGE_TITLE = 'Umowa o dzieło ' . $row['numer_umowy'];

// ── Szybka zmiana statusu ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_set_status'])) {
    csrf_check();
    if (can_edit() && !contract_is_locked($row)) {
        $new_status = $_POST['status'] ?? '';
        if (isset(STATUS_LABELS[$new_status]) && $new_status !== 'aneks') {
            $old_status = $row['status'];
            db_update($TABLE, ['status' => $new_status], $id);
            log_contract_action($TYPE, $id, (int)current_user()['id'], 'status_change',
                'Zmiana statusu: ' . $old_status . ' → ' . $new_status);
            $row['status'] = $new_status;
        }
    }
    header('Location: view.php?id=' . $id); exit;
}

// ── Obsługa opiekuna umowy ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_set_supervisor'])) {
    csrf_check();
    supervisor_set($TYPE, $id, (int)($_POST['sup_user_id'] ?? 0));
    header('Location: view.php?id=' . $id); exit;
}

$_pending_term    = get_pending_termination_for_contract($TYPE, $id);
$amendments       = get_amendments($TYPE, $id);
$edit_requests    = get_edit_requests($TYPE, $id);
$approval         = get_current_approval($TYPE, $id);
$audit_log        = get_audit_log($TYPE, $id);
$_letters         = get_contract_letters($TYPE, $id);
$cert_requests    = get_certificate_requests($TYPE, $id);
$cert_has_pending = !empty(array_filter($cert_requests, fn($r) => $r['status'] === 'oczekuje'));
$has_pending_edit = !empty(array_filter($edit_requests, fn($r) => $r['status'] === 'oczekuje'));
$m365_enabled     = m365_setting('m365_enabled') === '1';

$person = !empty($row['person_id']) ? person_by_id((int)$row['person_id']) : null;
$unit_name = '';
if (!empty($row['org_unit_id'])) {
    try {
        $pos = db_one("SELECT name FROM org_units WHERE id=?", [(int)$row['org_unit_id']]);
        $unit_name = $pos['name'] ?? '';
    } catch(\Throwable $e) {}
}

$_badge_obieg = 0;
if ($approval && $approval['status'] === 'oczekuje') $_badge_obieg++;
$_badge_obieg += count(array_filter($amendments,    fn($a) => $a['status'] === 'oczekuje'));
$_badge_obieg += count(array_filter($edit_requests, fn($r) => $r['status'] === 'oczekuje'));
$_badge_docs = count(array_filter($cert_requests,   fn($r) => $r['status'] === 'oczekuje'));

include dirname(dirname(__DIR__)) . '/includes/header.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_preview_notice.php';
echo contract_preview_notice('dzielo');

auth_start();
$_m365_creds = null;
if (!empty($_SESSION['m365_new_login'])) {
    $_m365_creds = [
        'login' => $_SESSION['m365_new_login'],
        'pass'  => $_SESSION['m365_new_pass'],
        'sent'  => $_SESSION['m365_sent'] ?? false,
        'email' => $row['email'] ?? '',
    ];
    unset($_SESSION['m365_new_login'], $_SESSION['m365_new_pass'], $_SESSION['m365_sent']);
}
?>

<?php
$_cvh_type       = $TYPE;
$_cvh_id         = $id;
$_cvh_row        = $row;
$_cvh_icon       = 'bi-palette';
$_cvh_label      = 'Umowa o dzieło';
$_cvh_person     = $row['imie_nazwisko'] ?? '';
$_cvh_person_sub = $row['email'] ?? '';
$_cvh_amount     = $row['wartosc_brutto'] ?? ($row['wynagrodzenie_brutto'] ?? null);
$_cvh_amount_lbl = 'Wartość brutto';
$_cvh_end_date   = $row['termin_oddania'] ?? ($row['data_zakonczenia'] ?? null);
$_cvh_subject    = $row['opis_dziela'] ?? ($row['rodzaj_dziela'] ?? null);
$_cvh_list_url   = APP_URL . '/contracts/dzielo/list.php';
$_cvh_edit_url   = 'edit.php?id=' . $id;
include dirname(dirname(__DIR__)) . '/includes/contract_view_header.php';
?>

<ul class="nav nav-tabs mb-0 no-print" id="dzieloTabs" role="tablist">

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-umowa-btn" data-bs-toggle="tab"
            data-bs-target="#tab-umowa" type="button" role="tab">
      <i class="bi bi-file-text"></i> Umowa
    </button>
  </li>

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-wykonawca-btn" data-bs-toggle="tab"
            data-bs-target="#tab-wykonawca" type="button" role="tab">
      <i class="bi bi-person"></i> Wykonawca
    </button>
  </li>

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-docs-btn" data-bs-toggle="tab"
            data-bs-target="#tab-docs" type="button" role="tab">
      <i class="bi bi-folder2-open"></i> Dokumenty
      <?php if ($_badge_docs): ?>
      <span class="badge bg-warning text-dark ms-1"><?= $_badge_docs ?></span>
      <?php endif; ?>
    </button>
  </li>

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-obieg-btn" data-bs-toggle="tab"
            data-bs-target="#tab-obieg" type="button" role="tab">
      <i class="bi bi-arrow-repeat"></i> Obieg
      <?php if ($_badge_obieg): ?>
      <span class="badge bg-danger ms-1"><?= $_badge_obieg ?></span>
      <?php endif; ?>
    </button>
  </li>

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-m365-btn" data-bs-toggle="tab"
            data-bs-target="#tab-m365" type="button" role="tab">
      <i class="bi bi-microsoft"></i> M365
      <?php if ($row['m365_konto']): ?>
      <span class="badge <?= $row['m365_konto_aktywne'] ? 'bg-success' : 'bg-secondary' ?> ms-1">
        <?= $row['m365_konto_aktywne'] ? '●' : '○' ?>
      </span>
      <?php endif; ?>
    </button>
  </li>

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-historia-btn" data-bs-toggle="tab"
            data-bs-target="#tab-historia" type="button" role="tab">
      <i class="bi bi-journal-text"></i> Historia
      <?php if ($audit_log): ?>
      <span class="badge bg-secondary ms-1"><?= count($audit_log) ?></span>
      <?php endif; ?>
    </button>
  </li>

</ul>

<div class="tab-content border border-top-0 rounded-bottom bg-white shadow-sm mb-3"
     id="dzieloTabsContent" style="padding:1.25rem">

<!-- ═══════════════════ TAB 1 — UMOWA ═══════════════════ -->
<div class="tab-pane fade" id="tab-umowa" role="tabpanel">

  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold">Dane podstawowe</div>
  <div class="card-body">
  <div class="row g-3">
    <div class="col-md-4"><div class="detail-label">Data zawarcia</div><div class="detail-value"><?= date_pl($row['data_zawarcia']) ?></div></div>
    <div class="col-md-4"><div class="detail-label">Opiekun</div><div class="detail-value"><?= h($row['opiekun']) ?: '—' ?></div></div>
    <div class="col-md-4"><div class="detail-label">Numer projektu</div><div class="detail-value"><?= h($row['numer_projektu']) ?: '—' ?></div></div>
  </div>
  </div>
  </div>

  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold">Dzieło i wynagrodzenie</div>
  <div class="card-body">
  <div class="row g-3">
    <div class="col-12"><div class="detail-label">Opis dzieła</div><div class="detail-value"><?= nl2br(h($row['opis_dziela'])) ?: '—' ?></div></div>
    <div class="col-md-4"><div class="detail-label">Termin oddania</div><div class="detail-value"><?= date_pl($row['termin_oddania']) ?></div></div>
    <div class="col-md-4"><div class="detail-label">Wynagrodzenie brutto</div><div class="detail-value fw-bold text-success"><?= money($row['wynagrodzenie_brutto']) ?></div></div>
    <div class="col-md-4"><div class="detail-label">Zaliczka na podatek</div><div class="detail-value"><?= money($row['zaliczka_podatek']) ?></div></div>
    <div class="col-md-4"><div class="detail-label">50% KUP</div><div class="detail-value"><?= yn($row['kup50']) ?></div></div>
  </div>
  </div>
  </div>

  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold">Prawa autorskie</div>
  <div class="card-body">
  <div class="row g-3">
    <div class="col-md-4"><div class="detail-label">Przeniesienie praw autorskich</div><div class="detail-value"><?= yn($row['prawa_autorskie']) ?></div></div>
    <?php if ($row['prawa_autorskie'] && $row['zakres_praw']): ?>
    <div class="col-12"><div class="detail-label">Zakres praw autorskich</div><div class="detail-value"><?= nl2br(h($row['zakres_praw'])) ?></div></div>
    <?php endif; ?>
  </div>
  </div>
  </div>

  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold">Odbiór dzieła</div>
  <div class="card-body">
  <div class="row g-3">
    <div class="col-md-4"><div class="detail-label">Wymagany protokół odbioru</div><div class="detail-value"><?= yn($row['wymagany_protokol']) ?></div></div>
    <div class="col-md-4"><div class="detail-label">Data odbioru</div><div class="detail-value"><?= date_pl($row['data_odbioru']) ?></div></div>
    <div class="col-md-4"><div class="detail-label">Dzieło przyjęte</div><div class="detail-value"><?= yn($row['dzielo_przyjete']) ?></div></div>
    <div class="col-md-4"><div class="detail-label">Data złożenia rachunku</div><div class="detail-value"><?= date_pl($row['data_zl_rachunku']) ?></div></div>
  </div>
  </div>
  </div>

  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold">Forma podpisania</div>
  <div class="card-body">
  <div class="row g-3">
    <div class="col-md-4"><div class="detail-label">Forma podpisania</div><div class="detail-value"><?= h(ucfirst($row['forma_podpisania'] ?? '')) ?: '—' ?></div></div>
    <?php if ($row['forma_podpisania'] === 'elektroniczna'): ?>
    <div class="col-md-4"><div class="detail-label">Platforma</div><div class="detail-value"><?= h($row['platforma_el']) ?: '—' ?></div></div>
    <div class="col-md-4"><div class="detail-label">ID dokumentu</div><div class="detail-value"><?= h($row['id_dokumentu_el']) ?: '—' ?></div></div>
    <div class="col-md-4"><div class="detail-label">Plik potwierdzenia</div><div class="detail-value"><?= upload_link($row['plik_potwierdzenia']) ?></div></div>
    <?php elseif ($row['forma_podpisania'] === 'epodpis_kwalifikowany'): ?>
    <div class="col-md-4"><div class="detail-label">Dostawca podpisu</div><div class="detail-value"><?= h($row['epodpis_dostawca'] ?? '') ?: '—' ?></div></div>
    <div class="col-md-4"><div class="detail-label">Nr certyfikatu</div><div class="detail-value font-monospace small"><?= h($row['epodpis_nr_certyfikatu'] ?? '') ?: '—' ?></div></div>
    <div class="col-md-4"><div class="detail-label">Ważność certyfikatu</div><div class="detail-value"><?= date_pl($row['epodpis_data_waznosci'] ?? '') ?></div></div>
    <?php endif; ?>
    <?php if (!empty($row['podpisujacy_fundacja'])): ?>
    <div class="col-md-4">
      <div class="detail-label">Podpisuje ze strony fundacji</div>
      <div class="detail-value fw-semibold"><?= h($row['podpisujacy_fundacja']) ?></div>
      <?php if (!empty($row['podpisujacy_stanowisko'])): ?>
      <div class="text-muted" style="font-size:.8rem"><?= h($row['podpisujacy_stanowisko']) ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  </div>
  </div>

  <?php if ($row['uwagi']): ?>
  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold">Uwagi</div>
  <div class="card-body"><?= nl2br(h($row['uwagi'])) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($row['nr_roboczy'] || $row['nr_system'] || $row['nr_rejestru']): ?>
  <div class="card shadow-sm">
  <div class="card-header fw-semibold"><i class="bi bi-hash"></i> Numery referencyjne</div>
  <div class="card-body"><div class="row g-3">
    <?php if ($row['nr_roboczy']): ?>
    <div class="col-md-4"><div class="detail-label">Nr roboczy</div><div class="detail-value"><?= h($row['nr_roboczy']) ?></div></div>
    <?php endif; ?>
    <?php if ($row['nr_system']): ?>
    <div class="col-md-4"><div class="detail-label">Nr ogólny (webNGO)</div><div class="detail-value"><?= h($row['nr_system']) ?></div></div>
    <?php endif; ?>
    <?php if ($row['nr_rejestru']): ?>
    <div class="col-md-4"><div class="detail-label">Nr rejestru</div><div class="detail-value fw-bold font-monospace"><?= h($row['nr_rejestru']) ?></div></div>
    <?php endif; ?>
  </div></div>
  </div>
  <?php endif; ?>

</div><!-- /tab-umowa -->

<!-- ═══════════════════ TAB 2 — WYKONAWCA ═══════════════════ -->
<div class="tab-pane fade" id="tab-wykonawca" role="tabpanel">

  <div class="row g-3">
  <div class="col-lg-8">

    <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-person-vcard"></i> Dane osobowe</div>
    <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6"><div class="detail-label">Imię i nazwisko</div><div class="detail-value fw-semibold"><?= h($row['imie_nazwisko']) ?: '—' ?></div></div>
      <div class="col-md-6"><div class="detail-label">PESEL</div><div class="detail-value font-monospace"><?= h($row['pesel']) ?: '—' ?></div></div>
      <div class="col-md-8"><div class="detail-label">Adres</div><div class="detail-value"><?= address_format($row, true) ?: '—' ?></div></div>
      <div class="col-md-4"><div class="detail-label">Adres e-mail</div>
        <div class="detail-value">
          <?= $row['email'] ? '<a href="mailto:' . h($row['email']) . '">' . h($row['email']) . '</a>' : '—' ?>
        </div>
      </div>
      <div class="col-md-4"><div class="detail-label">Urząd skarbowy</div><div class="detail-value"><?= h($row['urzad_skarbowy']) ?: '—' ?></div></div>
      <div class="col-12"><div class="detail-label">Rachunek bankowy</div><div class="detail-value font-monospace"><?= h($row['rachunek_bankowy']) ?: '—' ?></div></div>
    </div>
    </div>
    </div>

  </div>
  <div class="col-lg-4">

    <?php if ($person): ?>
    <div class="card shadow-sm mb-3 border-primary border-opacity-25">
    <div class="card-header fw-semibold text-primary"><i class="bi bi-person-vcard"></i> Karta osoby</div>
    <div class="card-body small">
      <div class="fw-semibold mb-1">
        <a href="<?= APP_URL ?>/persons/view.php?id=<?= $person['id'] ?>" class="text-decoration-none">
          <?= h($person['imie_nazwisko']) ?>
        </a>
      </div>
      <?php if ($person['pesel']): ?><div class="text-muted">PESEL: <span class="font-monospace"><?= h($person['pesel']) ?></span></div><?php endif; ?>
      <?php if ($person['email']): ?><div><a href="mailto:<?= h($person['email']) ?>"><?= h($person['email']) ?></a></div><?php endif; ?>
      <?php if ($person['telefon']): ?><div class="text-muted"><?= h($person['telefon']) ?></div><?php endif; ?>
      <?php if ($unit_name): ?><div class="mt-1"><span class="badge bg-secondary"><?= h($unit_name) ?></span></div><?php endif; ?>
      <div class="mt-2">
        <a href="<?= APP_URL ?>/persons/view.php?id=<?= $person['id'] ?>" class="btn btn-sm btn-outline-primary py-0">
          <i class="bi bi-arrow-right"></i> Profil osoby
        </a>
      </div>
    </div>
    </div>
    <?php elseif ($unit_name): ?>
    <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-diagram-3"></i> Komórka organizacyjna</div>
    <div class="card-body small"><span class="badge bg-secondary"><?= h($unit_name) ?></span></div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-paperclip"></i> Pliki</div>
    <div class="card-body">
      <div class="mb-2"><div class="detail-label">Plik umowy</div><?= upload_link($row['plik_umowy']) ?></div>
    </div>
    </div>

    <!-- Opiekun umowy -->
    <?php $sup = supervisor_get($TYPE, $id); ?>
    <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-person-check"></i> Opiekun umowy</div>
    <div class="card-body">
      <?php if ($sup): ?>
        <div class="fw-semibold small"><?= h($sup['user_name']) ?></div>
        <div class="text-muted" style="font-size:.8rem"><?= h($sup['user_email']) ?></div>
      <?php else: ?>
        <div class="text-muted small">Nieprzypisany</div>
      <?php endif; ?>
      <?php if (can_edit()): ?>
      <form method="post" class="mt-2">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_set_supervisor" value="1">
        <select name="sup_user_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— brak —</option>
          <?php foreach (supervisors_all_editors() as $_se): ?>
          <option value="<?= (int)$_se['id'] ?>" <?= ($sup && (int)$sup['user_id']===(int)$_se['id']) ? 'selected' : '' ?>>
            <?= h($_se['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>
    </div>
    </div>

    <div class="card shadow-sm">
    <div class="card-header fw-semibold">Metadata</div>
    <div class="card-body small text-muted">
      <div class="mb-1"><i class="bi bi-calendar-plus"></i> Dodano: <?= date_pl($row['created_at']) ?></div>
      <div><i class="bi bi-calendar-check"></i> Zmodyfikowano: <?= date_pl($row['updated_at']) ?></div>
    </div>
    </div>

  </div>
  </div>

</div><!-- /tab-wykonawca -->

<!-- ═══════════════════ TAB 3 — DOKUMENTY ═══════════════════ -->
<div class="tab-pane fade" id="tab-docs" role="tabpanel">

  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-envelope-paper"></i> Pisma</span>
    <?php if (can_edit()): ?>
    <a href="<?= APP_URL ?>/contracts/letters/add.php?type=<?= $TYPE ?>&id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-plus-lg"></i> Dodaj pismo
    </a>
    <?php endif; ?>
  </div>
  <?php if ($_letters): ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover mb-0 align-middle">
    <thead class="table-light">
      <tr><th>Kierunek</th><th>Typ</th><th>Tytuł</th><th>Data</th><th>Strona</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($_letters as $_l): ?>
    <tr>
      <td><?= letter_direction_badge($_l['kierunek']) ?></td>
      <td><?= letter_type_badge($_l['typ_pisma']) ?></td>
      <td>
        <a href="<?= APP_URL ?>/contracts/letters/view.php?id=<?= $_l['id'] ?>" class="text-decoration-none">
          <?= h($_l['tytul']) ?>
        </a>
        <?php if ($_l['email_sent']): ?><i class="bi bi-envelope-check text-success ms-1" title="E-mail wysłany"></i><?php endif; ?>
        <?php if ($_l['plik']): ?><i class="bi bi-paperclip text-muted ms-1" title="Z plikiem"></i><?php endif; ?>
      </td>
      <td class="small text-nowrap"><?= date_pl($_l['data_pisma']) ?></td>
      <td class="small"><?= h($_l['kierunek'] === 'wychodzące' ? ($_l['odbiorca'] ?: '—') : ($_l['nadawca'] ?: '—')) ?></td>
      <td class="text-end text-nowrap">
        <a href="<?= APP_URL ?>/contracts/letters/view.php?id=<?= $_l['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
        <?php if ($_l['plik']): ?>
        <a href="<?= h(letter_file_url($_l['plik'])) ?>" download class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i></a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="card-body text-muted small">Brak pism dla tej umowy.</div>
  <?php endif; ?>
  </div>

  <div class="card shadow-sm">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-award"></i> Zaświadczenia</span>
    <?php if (!$cert_has_pending): ?>
    <a href="<?= APP_URL ?>/certificates/request.php?type=<?= $TYPE ?>&id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-plus-lg"></i> Złóż wniosek
    </a>
    <?php else: ?>
    <span class="badge bg-warning text-dark"><i class="bi bi-clock"></i> Wniosek w toku</span>
    <?php endif; ?>
  </div>
  <?php if ($cert_requests): ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover mb-0">
    <thead class="table-light"><tr><th>Wnioskodawca</th><th>Cel</th><th>Data</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($cert_requests as $cr): ?>
    <tr>
      <td><?= h($cr['requester_name']) ?></td>
      <td class="small text-truncate" style="max-width:200px"><?= h($cr['cel']) ?></td>
      <td class="small text-nowrap"><?= date_pl($cr['created_at']) ?></td>
      <td><?= certificate_status_badge($cr['status']) ?></td>
      <td class="text-end text-nowrap">
        <?php if ($cr['status'] === 'oczekuje' && is_admin()): ?>
        <a href="<?= APP_URL ?>/certificates/issue.php?id=<?= $cr['id'] ?>" class="btn btn-sm btn-success">
          <i class="bi bi-award"></i> Wydaj
        </a>
        <?php elseif ($cr['status'] === 'wydane'): ?>
        <a href="<?= APP_URL ?>/certificates/print.php?id=<?= $cr['id'] ?>" target="_blank" class="btn btn-sm btn-outline-success">
          <i class="bi bi-printer"></i> Drukuj
        </a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="card-body text-muted small">Brak wniosków o zaświadczenia.</div>
  <?php endif; ?>
  </div>

</div><!-- /tab-docs -->

<!-- ═══════════════════ TAB 4 — OBIEG ═══════════════════ -->
<div class="tab-pane fade" id="tab-obieg" role="tabpanel">

  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-check2-circle"></i> Akceptacja</span>
    <?php if ($approval): echo approval_badge($approval['status']); else: ?>
    <span class="badge bg-secondary">Nie złożono</span>
    <?php endif; ?>
  </div>
  <div class="card-body">

  <?php if ($approval): ?>
  <div class="row g-2 mb-3">
    <div class="col-md-4"><div class="detail-label">Wnioskujący</div><div class="detail-value"><?= h($approval['requested_by_name'] ?? '—') ?></div></div>
    <div class="col-md-4"><div class="detail-label">Data wniosku</div><div class="detail-value"><?= date_pl($approval['requested_at']) ?></div></div>
    <?php if ($approval['decided_at']): ?>
    <div class="col-md-4"><div class="detail-label">Data decyzji</div><div class="detail-value"><?= date_pl($approval['decided_at']) ?></div></div>
    <?php if ($approval['decision_note']): ?>
    <div class="col-12"><div class="detail-label">Uwaga</div><div class="detail-value"><?= h($approval['decision_note']) ?></div></div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($approval && $approval['status'] === 'oczekuje' && is_admin()): ?>
  <form method="post" action="<?= APP_URL ?>/contracts/approvals/approve.php" class="mb-3">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="approval_id" value="<?= $approval['id'] ?>">
    <div class="row g-2 align-items-end">
      <div class="col-md-8">
        <label class="form-label small">Uwaga (opcjonalne)</label>
        <input type="text" name="decision_note" class="form-control form-control-sm">
      </div>
      <div class="col-auto">
        <button name="decision" value="zaakceptowana" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Zaakceptuj</button>
        <button name="decision" value="odrzucona" class="btn btn-sm btn-danger"><i class="bi bi-x-lg"></i> Odrzuć</button>
      </div>
    </div>
  </form>
  <?php endif; ?>

  <div class="d-flex gap-2 flex-wrap">
    <?php if (can_edit() && (!$approval || $approval['status'] !== 'oczekuje')): ?>
    <form method="post" action="<?= APP_URL ?>/contracts/approvals/submit.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="<?= $TYPE ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-sm btn-outline-warning"><i class="bi bi-send"></i> Złóż do akceptacji</button>
    </form>
    <?php endif; ?>
    <?php if (is_admin()): ?>
    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
      <i class="bi bi-trash3"></i> Usuń umowę
    </button>
    <?php endif; ?>
  </div>

  </div>
  </div>

  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-file-earmark-diff"></i> Aneksy</span>
    <?php if (can_edit()): ?>
    <a href="<?= APP_URL ?>/contracts/approvals/amendments_submit.php?type=<?= $TYPE ?>&id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-plus-lg"></i> Nowy aneks
    </a>
    <?php endif; ?>
  </div>
  <?php if ($amendments): ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover mb-0">
    <thead class="table-light"><tr><th>Nr</th><th>Opis zmian</th><th>Złożono</th><th>Status</th><th>Plik</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($amendments as $am): ?>
    <tr>
      <td><span class="badge bg-secondary">#<?= $am['numer_aneksu'] ?></span></td>
      <td style="max-width:250px">
        <?= h($am['opis_zmian']) ?>
        <?php if (!empty($am['proposed_changes'])): ?>
        <br><button class="btn btn-link btn-sm p-0 mt-1" type="button"
          data-bs-toggle="collapse" data-bs-target="#am-changes-<?= $am['id'] ?>">
          <i class="bi bi-table"></i> Pokaż zmiany pól
        </button>
        <div class="collapse mt-1" id="am-changes-<?= $am['id'] ?>">
          <?= render_amendment_changes($am['proposed_changes']) ?>
        </div>
        <?php endif; ?>
      </td>
      <td><?= date_pl($am['requested_at']) ?></td>
      <td><?= amendment_badge($am['status']) ?></td>
      <td><?= upload_link($am['plik_aneksu'] ?? '') ?></td>
      <td class="text-end">
        <?php if ($am['status'] === 'oczekuje' && is_admin()): ?>
        <form method="post" action="<?= APP_URL ?>/contracts/approvals/amendments_approve.php" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="amendment_id" value="<?= $am['id'] ?>">
          <input type="hidden" name="decision" value="zaakceptowany">
          <button class="btn btn-sm btn-success" title="Zatwierdź"><i class="bi bi-check-lg"></i></button>
        </form>
        <form method="post" action="<?= APP_URL ?>/contracts/approvals/amendments_approve.php" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="amendment_id" value="<?= $am['id'] ?>">
          <input type="hidden" name="decision" value="odrzucony">
          <button class="btn btn-sm btn-danger" title="Odrzuć"><i class="bi bi-x-lg"></i></button>
        </form>
        <?php endif; ?>
        <?php if ($am['decision_note']): ?>
        <span class="text-muted small ms-1" title="<?= h($am['decision_note']) ?>"><i class="bi bi-chat-text"></i></span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="card-body text-muted small">Brak aneksów.</div>
  <?php endif; ?>
  </div>

  <div class="card shadow-sm">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-pencil-square"></i> Wnioski o edycję</span>
    <?php if (can_edit() && !$has_pending_edit): ?>
    <a href="<?= APP_URL ?>/contracts/approvals/changes_request.php?type=<?= $TYPE ?>&id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-pencil"></i> Złóż wniosek
    </a>
    <?php endif; ?>
  </div>
  <?php if ($edit_requests): ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover mb-0">
    <thead class="table-light"><tr><th>Opis żądanej zmiany</th><th>Złożono przez</th><th>Data</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($edit_requests as $er): ?>
    <tr>
      <td class="text-truncate" style="max-width:280px"><?= h($er['opis_zmian']) ?></td>
      <td><?= h($er['requested_by_name'] ?? '—') ?></td>
      <td><?= date_pl($er['requested_at']) ?></td>
      <td><?= edit_request_badge($er['status']) ?></td>
      <td class="text-end">
        <?php if ($er['status'] === 'oczekuje' && is_admin()): ?>
        <form method="post" action="<?= APP_URL ?>/contracts/approvals/changes_approve.php" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="request_id" value="<?= $er['id'] ?>">
          <input type="hidden" name="decision" value="zaakceptowany">
          <button class="btn btn-sm btn-success" title="Zatwierdź"><i class="bi bi-check-lg"></i></button>
        </form>
        <form method="post" action="<?= APP_URL ?>/contracts/approvals/changes_approve.php" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="request_id" value="<?= $er['id'] ?>">
          <input type="hidden" name="decision" value="odrzucony">
          <button class="btn btn-sm btn-danger" title="Odrzuć"><i class="bi bi-x-lg"></i></button>
        </form>
        <?php endif; ?>
        <?php if ($er['decision_note']): ?>
        <span class="text-muted small" title="<?= h($er['decision_note']) ?>"><i class="bi bi-chat-text"></i></span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="card-body text-muted small">Brak wniosków o edycję.</div>
  <?php endif; ?>
  </div>

</div><!-- /tab-obieg -->

<!-- ═══════════════════ TAB 5 — M365 ═══════════════════ -->
<div class="tab-pane fade" id="tab-m365" role="tabpanel">

  <div class="card shadow-sm">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-microsoft"></i> Microsoft 365</span>
    <?php if ($row['m365_konto']): ?>
    <span class="badge bg-<?= $row['m365_konto_aktywne'] ? 'success' : 'secondary' ?>">
      <?= $row['m365_konto_aktywne'] ? 'Konto aktywne' : 'Konto nieaktywne' ?>
    </span>
    <?php else: ?>
    <span class="badge bg-light text-dark border">Brak konta</span>
    <?php endif; ?>
  </div>
  <div class="card-body">

  <?php if ($row['m365_konto']): ?>
  <div class="row g-3 mb-3">
    <div class="col-md-4"><div class="detail-label">Login M365</div>
      <div class="detail-value font-monospace"><?= h($row['m365_login']) ?></div></div>
    <div class="col-md-4"><div class="detail-label">Utworzono</div>
      <div class="detail-value"><?= date_pl($row['m365_data_utworzenia']) ?></div></div>
    <div class="col-md-4"><div class="detail-label">Licencja przypisana</div>
      <div class="detail-value"><?= yn($row['m365_licencja_przypisana']) ?></div></div>
  </div>

  <?php if (can_edit()): ?>
  <div class="d-flex gap-2 flex-wrap mb-3">
    <?php if ($row['m365_konto_aktywne']): ?>
    <form method="post" action="<?= APP_URL ?>/contracts/m365_action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="dzielo">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="disable">
      <button class="btn btn-sm btn-warning" data-confirm="Wyłączyć konto M365?">
        <i class="bi bi-pause-circle"></i> Wyłącz konto
      </button>
    </form>
    <?php else: ?>
    <form method="post" action="<?= APP_URL ?>/contracts/m365_action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="dzielo">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="enable">
      <button class="btn btn-sm btn-success"><i class="bi bi-play-circle"></i> Włącz konto</button>
    </form>
    <?php endif; ?>
    <?php if ($row['email']): ?>
    <form method="post" action="<?= APP_URL ?>/contracts/m365_action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="dzielo">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="send_email">
      <button class="btn btn-sm btn-outline-primary"><i class="bi bi-envelope"></i> Wyślij mail z hasłem</button>
    </form>
    <?php endif; ?>
    <form method="post" action="<?= APP_URL ?>/contracts/m365_action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="dzielo">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="unlink">
      <button class="btn btn-sm btn-outline-danger" data-confirm="Odpiąć konto? Konto w Azure AD NIE zostanie usunięte.">
        <i class="bi bi-unlink"></i> Odepnij
      </button>
    </form>
    <?php if (is_admin()):
      $_del_login = addslashes($row['m365_login'] ?? ''); ?>
    <button type="button" class="btn btn-sm btn-danger"
      onclick="if(confirm('Trwale usunąć konto M365 <?= h($_del_login) ?> z Azure AD?\n\nTej operacji nie można cofnąć.\nPowiązane konto lokalne zostanie dezaktywowane.')) { document.getElementById('form_delete_m365_<?= $id ?>').submit(); }">
      <i class="bi bi-trash3"></i> Usuń konto M365
    </button>
    <form id="form_delete_m365_<?= $id ?>" method="post" action="<?= APP_URL ?>/contracts/m365_action.php" class="d-none">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="type"    value="dzielo">
      <input type="hidden" name="id"      value="<?= $id ?>">
      <input type="hidden" name="action"  value="delete_m365">
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php
  $_local_users = db_all("SELECT id, name, email, microsoft_id FROM users WHERE is_active = 1 ORDER BY name");
  ?>
  <?php if (can_edit() && $row['m365_user_id']): ?>
  <div class="border-top pt-3">
    <div class="d-flex align-items-center gap-2 mb-2">
      <span class="small fw-semibold text-muted"><i class="bi bi-link-45deg"></i> Powiązanie z kontem lokalnym</span>
      <?php
      $linked_local = null;
      foreach ($_local_users as $_lu) {
          if ($_lu['microsoft_id'] === $row['m365_user_id']) { $linked_local = $_lu; break; }
      }
      ?>
      <?php if ($linked_local): ?>
      <span class="badge bg-success"><i class="bi bi-check"></i> <?= h($linked_local['name']) ?></span>
      <?php else: ?>
      <span class="badge bg-secondary">Brak powiązania</span>
      <?php endif; ?>
    </div>
    <form method="post" action="<?= APP_URL ?>/contracts/m365_action.php" class="d-flex gap-2 align-items-center flex-wrap">
      <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
      <input type="hidden" name="type"   value="dzielo">
      <input type="hidden" name="id"     value="<?= $id ?>">
      <input type="hidden" name="action" value="link_local_user">
      <select name="local_user_id" class="form-select form-select-sm" style="width:auto;max-width:260px" required>
        <option value="">— wybierz konto lokalne —</option>
        <?php foreach ($_local_users as $_lu): ?>
        <option value="<?= intval($_lu['id']) ?>" <?= ($linked_local && $linked_local['id'] === $_lu['id']) ? 'selected' : '' ?>>
          <?= h($_lu['name']) ?> &lt;<?= h($_lu['email']) ?>&gt;
          <?= $_lu['microsoft_id'] ? '✓' : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-link-45deg"></i> Powiąż
      </button>
    </form>
  </div>
  <?php endif; ?>

  <?php elseif ($m365_enabled && can_edit()): ?>
  <p class="text-muted mb-2">Brak powiązanego konta Microsoft 365.</p>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <form method="post" action="<?= APP_URL ?>/contracts/m365_action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="dzielo">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="create">
      <button class="btn btn-primary"><i class="bi bi-microsoft"></i> Utwórz konto M365</button>
    </form>
    <?php if ($row['email']): ?>
    <small class="text-success"><i class="bi bi-check-circle"></i> Mail zostanie wysłany na: <?= h($row['email']) ?></small>
    <?php else: ?>
    <small class="text-warning"><i class="bi bi-exclamation-triangle"></i> Brak adresu e-mail — mail powitalny nie zostanie wysłany</small>
    <?php endif; ?>
  </div>

  <?php elseif (!$m365_enabled): ?>
  <p class="text-muted small">
    Integracja M365 wyłączona.
    <a href="<?= APP_URL ?>/admin/m365.php">Włącz w ustawieniach →</a>
  </p>
  <?php endif; ?>

  </div>
  </div>

</div><!-- /tab-m365 -->

<!-- ═══════════════════ TAB 6 — HISTORIA ═══════════════════ -->
<div class="tab-pane fade" id="tab-historia" role="tabpanel">

  <?php if ($audit_log): ?>
  <ul class="list-group list-group-flush rounded">
  <?php foreach ($audit_log as $log): ?>
  <li class="list-group-item d-flex justify-content-between align-items-start py-2">
    <div>
      <?= action_badge($log['action']) ?>
      <span class="ms-2 small"><?= h($log['user_snapshot'] ?? 'System') ?></span>
      <?php if ($log['note']): ?>
      <br><small class="text-muted ms-1"><?= h($log['note']) ?></small>
      <?php endif; ?>
    </div>
    <small class="text-muted text-nowrap"><?= date_pl($log['created_at']) ?></small>
  </li>
  <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <p class="text-muted small mb-0">Brak wpisów w historii.</p>
  <?php endif; ?>

</div><!-- /tab-historia -->

</div><!-- /tab-content -->

<?php if (is_admin()): ?>
<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-trash3"></i> Usuń umowę</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="<?= APP_URL ?>/contracts/approvals/delete.php">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="type" value="<?= $TYPE ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="modal-body">
          <p class="text-danger fw-bold">Tej operacji nie można cofnąć.</p>
          <label class="form-label">Powód usunięcia <span class="text-danger">*</span></label>
          <textarea name="reason" class="form-control" rows="3" required placeholder="Wpisz powód usunięcia..."></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-trash3"></i> Usuń</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var STORAGE_KEY = 'dzielo_tab_<?= $id ?>';
  var tabs = document.getElementById('dzieloTabs');
  if (!tabs) return;
  function showTab(btn) { if (btn) new bootstrap.Tab(btn).show(); }
  var hash = location.hash;
  if (hash && hash.startsWith('#tab-')) {
    var hashBtn = document.querySelector('[data-bs-target="' + hash + '"]');
    if (hashBtn) { showTab(hashBtn); return; }
  }
  var saved = localStorage.getItem(STORAGE_KEY) || 'tab-umowa';
  var target = document.querySelector('[data-bs-target="#' + saved + '"]');
  showTab(target || tabs.querySelector('[data-bs-toggle="tab"]'));
  tabs.addEventListener('shown.bs.tab', function (e) {
    localStorage.setItem(STORAGE_KEY, e.target.dataset.bsTarget.replace('#', ''));
  });
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
