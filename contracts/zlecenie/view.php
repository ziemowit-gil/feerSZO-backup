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
require_once dirname(dirname(__DIR__)) . '/includes/docusign.php';
require_once dirname(dirname(__DIR__)) . '/includes/autenti.php';
require_once dirname(dirname(__DIR__)) . '/includes/messages.php';
require_once dirname(dirname(__DIR__)) . '/includes/supervisors.php';
require_once dirname(dirname(__DIR__)) . '/includes/persons.php';
require_once dirname(dirname(__DIR__)) . '/includes/address.php';
require_once dirname(dirname(__DIR__)) . '/includes/ksiegowy_email.php';
require_once dirname(dirname(__DIR__)) . '/includes/rozliczenia.php';
require_once dirname(dirname(__DIR__)) . '/includes/ksiegowosc.php';
require_once dirname(dirname(__DIR__)) . '/includes/impersonation.php';

require_login();
$TYPE  = 'zlecenie';
$TABLE = 'umowy_zlecenie';
$id    = intval($_GET['id'] ?? 0);
$row   = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); die('Nie znaleziono umowy.'); }
if (!viewer_owns_contract($TYPE, $row)) {
    flash_set('error', 'Nie masz dostępu do tej umowy.');
    header('Location: ' . APP_URL . '/panel/index.php'); exit;
}
$PAGE_TITLE = 'Umowa zlecenie ' . $row['numer_umowy'];

// ── Wejście na konto tej osoby (impersonacja z potwierdzeniem SMS/e-mail) ────
$_imp_target  = (is_admin() && !ctx_is_impersonating()) ? impersonation_linked_user($TYPE, $row) : null;
$_imp_pending = $_SESSION['imp_pending'] ?? null;
$_imp_show_verify = !empty($_GET['imp']) && is_array($_imp_pending)
    && ($_imp_pending['type'] ?? '') === $TYPE && (int)($_imp_pending['id'] ?? 0) === $id;

// ── Przepisz użytkownika (nowy e-mail, oświadczenie do podpisu + skan) ───────
require_once dirname(dirname(__DIR__)) . '/includes/user_reassignment.php';
$_reassign_pending = $_SESSION['reassign_pending'] ?? null;
$_reassign_show_upload = !empty($_GET['reassign']) && is_array($_reassign_pending)
    && ($_reassign_pending['type'] ?? '') === $TYPE && (int)($_reassign_pending['id'] ?? 0) === $id;
$_reassign_req = $_reassign_show_upload
    ? db_one("SELECT * FROM user_reassignments WHERE id=?", [(int)$_reassign_pending['request_id']])
    : null;

// ── Szybka zmiana statusu ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_set_status'])) {
    csrf_check();
    if (can_edit() && !contract_is_locked($row)) {
        $new_status = $_POST['status'] ?? '';
        if (isset(STATUS_LABELS[$new_status]) && $new_status !== 'aneks') {
            $old_status = $row['status'];
            require_once dirname(dirname(__DIR__)) . '/includes/guardian_consent.php';
            require_once dirname(dirname(__DIR__)) . '/includes/contract_transitions.php';
            try {
                ContractStatusTransitionValidator::assertAllowed($old_status, $new_status, $row);
            } catch (ContractTransitionException $e) {
                flash_set('error', $e->getMessage());
                header('Location: view.php?id=' . $id); exit;
            }
            db_update($TABLE, ['status' => $new_status], $id);
            if (ContractStatusTransitionValidator::isBlockedGroupExit($old_status, $new_status)) {
                db_update($TABLE, [
                    'is_blocked'   => 0,
                    'unblocked_by' => (int)current_user()['id'],
                    'unblocked_at' => date('Y-m-d H:i:s'),
                ], $id);
                log_contract_action($TYPE, $id, (int)current_user()['id'], 'unblock',
                    'Odblokowano po potwierdzeniu zgody przedstawiciela ustawowego.');
            }
            log_contract_action($TYPE, $id, (int)current_user()['id'], 'status_change',
                'Zmiana statusu: ' . $old_status . ' → ' . $new_status);
            $row['status'] = $new_status; // odśwież dla nagłówka
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
$rozliczenia      = get_rozliczenia($TYPE, $id);
$_rozl_open       = count(array_filter($rozliczenia, fn($r) => in_array($r['status'], ['oczekuje','wyslane'], true)));
try { $kdok_docs = kdok_documents_for_contract($TYPE, $id); } catch (\Throwable $e) { $kdok_docs = []; }
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
if (contract_is_preview('zlecenie')) echo contract_preview_notice('zlecenie');

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
$_cvh_icon       = 'bi-person-lines-fill';
$_cvh_label      = 'Umowa zlecenie';
$_cvh_person     = $row['imie_nazwisko'] ?? '';
$_cvh_person_sub = $row['email'] ?? '';
$_cvh_amount     = $row['wynagrodzenie_brutto'] ?? null;
$_cvh_amount_lbl = 'Wynagrodzenie brutto';
$_cvh_end_date   = $row['data_zakonczenia'] ?? null;
$_cvh_subject    = $row['przedmiot_zlecenia'] ?? null;
$_cvh_list_url   = APP_URL . '/contracts/zlecenie/list.php';
$_cvh_edit_url   = 'edit.php?id=' . $id;
include dirname(dirname(__DIR__)) . '/includes/contract_view_header.php';
require_once dirname(__DIR__) . '/includes/cv_layout.php';
?>

<div class="cv-tabs-layout">
<ul class="nav nav-pills cv-side-tabs mb-0 no-print" id="zlecenieTabs" role="tablist">

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-umowa-btn" data-bs-toggle="tab"
            data-bs-target="#tab-umowa" type="button" role="tab">
      <i class="bi bi-file-text"></i> Umowa
    </button>
  </li>

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-zleceniobiorca-btn" data-bs-toggle="tab"
            data-bs-target="#tab-zleceniobiorca" type="button" role="tab">
      <i class="bi bi-person"></i> Zleceniobiorca
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
    <button class="nav-link" id="tab-rozliczenia-btn" data-bs-toggle="tab"
            data-bs-target="#tab-rozliczenia" type="button" role="tab">
      <i class="bi bi-cash-coin"></i> Rozliczenia
      <?php if ($_rozl_open): ?>
      <span class="badge bg-info text-dark ms-1"><?= $_rozl_open ?></span>
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

  <?php if (($row['forma_podpisania'] ?? '') === 'elektroniczna' && (docusign_is_enabled() || current_user()['role'] === 'admin')): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-docusign-btn" data-bs-toggle="tab"
            data-bs-target="#tab-docusign" type="button" role="tab">
      <i class="bi bi-pen-fill"></i> DocuSign
      <?php $__ds = $row['docusign_status'] ?? ''; ?>
      <?php if (in_array($__ds, ['sent','delivered'])): ?>
      <span class="badge bg-warning text-dark ms-1">●</span>
      <?php elseif ($__ds === 'completed'): ?>
      <span class="badge bg-success ms-1">✓</span>
      <?php elseif (in_array($__ds, ['declined','voided'])): ?>
      <span class="badge bg-danger ms-1">✗</span>
      <?php endif; ?>
    </button>
  </li>
  <?php endif; ?>

  <?php if (($row['forma_podpisania'] ?? '') === 'elektroniczna' && (autenti_is_enabled() || current_user()['role'] === 'admin')): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-autenti-btn" data-bs-toggle="tab"
            data-bs-target="#tab-autenti" type="button" role="tab">
      <i class="bi bi-pen-fill"></i> Autenti
      <?php $__at = $row['autenti_status'] ?? ''; ?>
      <?php if ($__at === 'IN_PROGRESS'): ?>
      <span class="badge bg-warning text-dark ms-1">●</span>
      <?php elseif ($__at === 'COMPLETED'): ?>
      <span class="badge bg-success ms-1">✓</span>
      <?php elseif (in_array($__at, ['DECLINED','CANCELLED','EXPIRED'])): ?>
      <span class="badge bg-danger ms-1">✗</span>
      <?php endif; ?>
    </button>
  </li>
  <?php endif; ?>

</ul>

<div class="tab-content cv-side-tabs-content" id="zlecenieTabsContent">

<!-- ═══════════════════ TAB 1 — UMOWA ═══════════════════ -->
<div class="tab-pane fade" id="tab-umowa" role="tabpanel">

  <div class="cv-section">
    <div class="cv-section-head">
      <div class="cv-section-icon" style="background:#EEF4FF;color:#2563EB"><i class="bi bi-file-text-fill"></i></div>
      <span class="cv-section-title">Dane podstawowe</span>
    </div>
    <div class="cv-fields">
      <div class="cv-field"><div class="cv-label">Opiekun</div><div class="cv-value"><?= h($row['opiekun']) ?: '—' ?></div></div>
      <div class="cv-field"><div class="cv-label">Data zawarcia</div><div class="cv-value"><?= date_pl($row['data_zawarcia']) ?></div></div>
      <div class="cv-field"><div class="cv-label">Data rozpoczęcia</div><div class="cv-value"><?= date_pl($row['data_rozpoczecia']) ?></div></div>
      <div class="cv-field"><div class="cv-label">Data zakończenia</div><div class="cv-value"><?= date_pl($row['data_zakonczenia']) ?></div></div>
      <div class="cv-field-full"><div class="cv-label">Przedmiot zlecenia</div><div class="cv-value"><?= nl2br(h($row['przedmiot_zlecenia'])) ?: '—' ?></div></div>
      <div class="cv-field-wide"><div class="cv-label">Numer projektu / źródło finansowania</div><div class="cv-value"><?= h($row['numer_projektu']) ?: '—' ?></div></div>
    </div>
  </div>

  <div class="cv-section">
    <div class="cv-section-head">
      <div class="cv-section-icon" style="background:#F0FDF4;color:#16A34A"><i class="bi bi-cash-coin"></i></div>
      <span class="cv-section-title">Wynagrodzenie i podatek</span>
    </div>
    <div class="cv-fields">
      <div class="cv-field"><div class="cv-label">Wynagrodzenie brutto</div><div class="cv-value fw-bold text-success"><?= money($row['wynagrodzenie_brutto']) ?></div></div>
      <div class="cv-field"><div class="cv-label">Stawka</div><div class="cv-value"><?= money($row['stawka_kwota']) ?> (<?= h($row['typ_stawki']) ?: '—' ?>)</div></div>
      <div class="cv-field"><div class="cv-label">Liczba godzin planowana</div><div class="cv-value"><?= h($row['liczba_godzin_planowana']) ?: '—' ?></div></div>
      <div class="cv-field"><div class="cv-label">Termin płatności</div><div class="cv-value"><?= h($row['termin_platnosci']) ?: '—' ?></div></div>
      <div class="cv-field"><div class="cv-label">KUP</div><div class="cv-value"><?= $row['kup'] && $row['kup'] !== 'brak' ? h($row['kup']) . '%' : '—' ?></div></div>
      <div class="cv-field"><div class="cv-label">Zaliczka podatek</div><div class="cv-value"><?= money($row['zaliczka_podatek']) ?></div></div>
      <div class="cv-field"><div class="cv-label">Składki ZUS</div><div class="cv-value"><?= yn($row['zus_skladki']) ?></div></div>
      <div class="cv-field"><div class="cv-label">Zwolnienie &lt;26 lat</div><div class="cv-value"><?= yn($row['zwolnienie_wiek']) ?></div></div>
      <?php if ($row['tytul_ubezpieczenia']): ?>
      <div class="cv-field-wide"><div class="cv-label">Tytuł ubezpieczenia</div><div class="cv-value"><?= h($row['tytul_ubezpieczenia']) ?></div></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($row['zus_skladki'])):
    // ── Rejestracja / wyrejestrowanie ZUS (termin: 7 dni od zdarzenia) ──────────
    $_zus_box = static function (string $label, string $action, ?string $done, ?string $anchor): string {
        if (!empty($done)) {
            return '<div class="cv-label">' . $label . '</div>'
                 . '<div class="cv-value"><span class="badge bg-success">' . $action . ': ' . date_pl($done) . '</span></div>';
        }
        if (empty($anchor)) {
            return '<div class="cv-label">' . $label . '</div>'
                 . '<div class="cv-value text-muted">brak daty odniesienia</div>';
        }
        $deadline = date('Y-m-d', strtotime($anchor . ' +7 days'));
        $dleft    = (int) round((strtotime($deadline) - strtotime(date('Y-m-d'))) / 86400);
        if     ($dleft < 0)  { $cls = 'danger';    $txt = 'po terminie (' . abs($dleft) . ' dni)'; }
        elseif ($dleft <= 3) { $cls = 'warning';   $txt = 'pozostało ' . $dleft . ' dni'; }
        else                 { $cls = 'secondary'; $txt = 'pozostało ' . $dleft . ' dni'; }
        return '<div class="cv-label">' . $label . '</div>'
             . '<div class="cv-value">Termin: <strong>' . date_pl($deadline) . '</strong> '
             . '<span class="badge bg-' . $cls . '">' . $txt . '</span></div>';
    };
    $_zus_anchor_reg = $row['data_rozpoczecia'] ?: $row['data_zawarcia'];
  ?>
  <div class="cv-section">
    <div class="cv-section-head">
      <div class="cv-section-icon" style="background:#FFF7ED;color:#EA580C"><i class="bi bi-shield-check"></i></div>
      <span class="cv-section-title">ZUS — rejestracja i wyrejestrowanie</span>
    </div>
    <div class="cv-fields">
      <div class="cv-field-wide"><?= $_zus_box('Zgłoszenie do ZUS (ZUA/ZZA)', 'Zgłoszono', $row['zus_data_rejestracji'] ?? null, $_zus_anchor_reg) ?></div>
      <?php if (empty($row['bezterminowa']) && !empty($row['data_zakonczenia'])): ?>
      <div class="cv-field-wide"><?= $_zus_box('Wyrejestrowanie z ZUS (ZWUA)', 'Wyrejestrowano', $row['zus_data_wyrejestrowania'] ?? null, $row['data_zakonczenia']) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="cv-section">
    <div class="cv-section-head">
      <div class="cv-section-icon" style="background:#F5F3FF;color:#7C3AED"><i class="bi bi-pen-fill"></i></div>
      <span class="cv-section-title">Rachunek i podpisanie</span>
    </div>
    <div class="cv-fields">
      <div class="cv-field"><div class="cv-label">Wymagany rachunek</div><div class="cv-value"><?= yn($row['wymagany_rachunek']) ?></div></div>
      <div class="cv-field"><div class="cv-label">Data złożenia rachunku</div><div class="cv-value"><?= date_pl($row['data_zl_rachunku']) ?></div></div>
      <div class="cv-field"><div class="cv-label">Data rachunku</div><div class="cv-value"><?= date_pl($row['data_rachunku'] ?? '') ?></div></div>
      <div class="cv-field"><div class="cv-label">Za jaki okres jest rachunek</div><div class="cv-value"><?= h($row['okres_rachunku'] ?? '') ?: '—' ?></div></div>
      <div class="cv-field"><div class="cv-label">Forma podpisania</div><div class="cv-value"><?= h(ucfirst($row['forma_podpisania'] ?? '')) ?: '—' ?></div></div>
      <?php if ($row['forma_podpisania'] === 'elektroniczna'): ?>
      <div class="cv-field"><div class="cv-label">Platforma</div><div class="cv-value"><?= h($row['platforma_el']) ?: '—' ?></div></div>
      <div class="cv-field"><div class="cv-label">ID dokumentu</div><div class="cv-value"><?= h($row['id_dokumentu_el']) ?: '—' ?></div></div>
      <div class="cv-field"><div class="cv-label">Plik potwierdzenia</div><div class="cv-value"><?= upload_link_signed($row['plik_potwierdzenia']) ?></div></div>
      <?php elseif ($row['forma_podpisania'] === 'epodpis_kwalifikowany'): ?>
      <div class="cv-field"><div class="cv-label">Dostawca podpisu</div><div class="cv-value"><?= h($row['epodpis_dostawca'] ?? '') ?: '—' ?></div></div>
      <div class="cv-field"><div class="cv-label">Nr certyfikatu</div><div class="cv-value font-monospace small"><?= h($row['epodpis_nr_certyfikatu'] ?? '') ?: '—' ?></div></div>
      <div class="cv-field"><div class="cv-label">Ważność certyfikatu</div><div class="cv-value"><?= date_pl($row['epodpis_data_waznosci'] ?? '') ?></div></div>
      <?php endif; ?>
      <?php if (!empty($row['podpisujacy_fundacja'])): ?>
      <div class="cv-field">
        <div class="cv-label">Podpisuje ze strony fundacji</div>
        <div class="cv-value fw-semibold"><?= h($row['podpisujacy_fundacja']) ?></div>
        <?php if (!empty($row['podpisujacy_stanowisko'])): ?>
        <div class="text-muted" style="font-size:.8rem"><?= h($row['podpisujacy_stanowisko']) ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($row['uwagi']): ?>
  <div class="cv-section">
    <div class="cv-section-head">
      <div class="cv-section-icon" style="background:#F8FAFC;color:#64748B"><i class="bi bi-chat-left-text"></i></div>
      <span class="cv-section-title">Uwagi</span>
    </div>
    <div class="cv-value"><?= nl2br(h($row['uwagi'])) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($row['nr_roboczy'] || $row['nr_system'] || $row['nr_rejestru']): ?>
  <div class="cv-section">
    <div class="cv-section-head">
      <div class="cv-section-icon" style="background:#F8FAFC;color:#94A3B8"><i class="bi bi-hash"></i></div>
      <span class="cv-section-title">Numery referencyjne</span>
    </div>
    <div class="cv-fields">
      <?php if ($row['nr_roboczy']): ?>
      <div class="cv-field"><div class="cv-label">Nr roboczy</div><div class="cv-value"><?= h($row['nr_roboczy']) ?></div></div>
      <?php endif; ?>
      <?php if ($row['nr_system']): ?>
      <div class="cv-field"><div class="cv-label">Nr ogólny (webNGO)</div><div class="cv-value"><?= h($row['nr_system']) ?></div></div>
      <?php endif; ?>
      <?php if ($row['nr_rejestru']): ?>
      <div class="cv-field"><div class="cv-label">Nr rejestru</div><div class="cv-value fw-bold font-monospace"><?= h($row['nr_rejestru']) ?></div></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /tab-umowa -->

<!-- ═══════════════════ TAB 2 — ZLECENIOBIORCA ═══════════════════ -->
<div class="tab-pane fade" id="tab-zleceniobiorca" role="tabpanel">

  <div class="row g-3">
  <div class="col-lg-8">

    <div class="cv-section">
      <div class="cv-section-head">
        <div class="cv-section-icon" style="background:#EEF4FF;color:#2563EB"><i class="bi bi-person-vcard"></i></div>
        <span class="cv-section-title">Dane osobowe</span>
      </div>
      <div class="cv-fields">
        <div class="cv-field-wide"><div class="cv-label">Imię i nazwisko</div><div class="cv-value fw-semibold"><?= h($row['imie_nazwisko']) ?: '—' ?></div></div>
        <div class="cv-field"><div class="cv-label">PESEL</div><div class="cv-value font-monospace"><?= h($row['pesel']) ?: '—' ?></div></div>
        <div class="cv-field"><div class="cv-label">Seria/nr dowodu</div><div class="cv-value"><?= h($row['seria_nr_dowodu']) ?: '—' ?></div></div>
        <div class="cv-field-wide"><div class="cv-label">Adres</div><div class="cv-value"><?= address_format($row, true) ?: '—' ?></div></div>
        <div class="cv-field"><div class="cv-label">Adres e-mail</div>
          <div class="cv-value">
            <?= $row['email'] ? '<a href="mailto:' . h($row['email']) . '">' . h($row['email']) . '</a>' : '—' ?>
          </div>
        </div>
        <div class="cv-field"><div class="cv-label">Urząd skarbowy</div><div class="cv-value"><?= h($row['urzad_skarbowy']) ?: '—' ?></div></div>
        <div class="cv-field-full"><div class="cv-label">Rachunek bankowy</div><div class="cv-value font-monospace"><?= h($row['rachunek_bankowy']) ?: '—' ?></div></div>
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
      <div class="mb-2"><div class="detail-label">Plik umowy</div><?= upload_link_signed($row['plik_umowy']) ?></div>
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

</div><!-- /tab-zleceniobiorca -->

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
        <?php if (can_edit()): ?>
        <a href="<?= APP_URL ?>/contracts/letters/edit.php?id=<?= $_l['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edytuj"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
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
        <a href="<?= APP_URL ?>/certificates/print.php?id=<?= $cr['id'] ?>" target="_blank" class="btn btn-sm btn-outline-success" title="Podgląd PDF">
          <i class="bi bi-printer"></i> Drukuj
        </a>
          <a href="<?= APP_URL ?>/certificates/download_docx.php?id=<?= $cr['id'] ?>"
             class="btn btn-sm btn-outline-secondary" title="Pobierz DOCX">
            <i class="bi bi-file-earmark-word"></i> DOCX
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

<!-- ═══════════════════ TAB — ROZLICZENIA ═══════════════════ -->
<div class="tab-pane fade" id="tab-rozliczenia" role="tabpanel">

  <?php $_ksieg_addr = org_setting('ksiegowy_email'); ?>
  <div class="card shadow-sm">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-cash-coin"></i> Rozliczenia umowy</span>
    <?php if (can_edit()): ?>
    <button type="button" class="btn btn-sm btn-primary" onclick="cvhOpenRozliczenie(null)">
      <i class="bi bi-receipt-cutoff"></i> Zlecenie wystawienia rachunku
    </button>
    <?php endif; ?>
  </div>

  <?php if (!$_ksieg_addr): ?>
  <div class="card-body pb-0">
    <div class="alert alert-warning small mb-0 py-2">
      <i class="bi bi-exclamation-triangle"></i> Adres księgowego nie jest ustawiony — wysyłka e-mail będzie niedostępna.
      Uzupełnij go w <a href="<?= APP_URL ?>/admin/org_settings.php?tab=mail">Ustawienia → Poczta</a>.
    </div>
  </div>
  <?php endif; ?>

  <?php if ($rozliczenia): ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover mb-0 align-middle">
    <thead class="table-light">
      <tr><th>#</th><th>Status</th><th>Okres</th><th>Data rachunku</th><th>Kwota</th><th>Utworzył</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($rozliczenia as $rz): ?>
    <tr>
      <td class="text-muted">#<?= (int)$rz['id'] ?></td>
      <td><?= rozliczenie_status_badge($rz['status']) ?></td>
      <td><?= h($rz['okres']) ?: '—' ?></td>
      <td class="text-nowrap"><?= date_pl($rz['data_rachunku']) ?></td>
      <td class="text-nowrap"><?= $rz['kwota_brutto'] !== null ? money((float)$rz['kwota_brutto']) : '—' ?></td>
      <td class="small text-muted">
        <?= h($rz['created_by_name'] ?? '—') ?><br>
        <span style="font-size:.8em"><?= date_pl($rz['created_at']) ?></span>
      </td>
      <td class="text-end text-nowrap">
        <a href="<?= APP_URL ?>/contracts/zlecenie/ksiegowy_print.php?id=<?= $id ?>&rozliczenie_id=<?= (int)$rz['id'] ?>"
           target="_blank" class="btn btn-sm btn-outline-secondary" title="PDF"><i class="bi bi-file-earmark-pdf"></i></a>
        <?php if (can_edit() && $rz['status'] !== 'rozliczone' && $rz['status'] !== 'anulowane'): ?>
          <?php if ($_ksieg_addr && empty($rz['nie_wysylac'])): ?>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="rozlSend(<?= (int)$rz['id'] ?>, this)" title="Wyślij do księgowego">
            <i class="bi bi-envelope"></i>
          </button>
          <?php endif; ?>
          <button type="button" class="btn btn-sm btn-outline-success" onclick="rozlSettle(<?= (int)$rz['id'] ?>, this)" title="Oznacz jako rozliczone">
            <i class="bi bi-check2-circle"></i>
          </button>
        <?php endif; ?>
        <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
          <button type="button" class="btn btn-sm btn-outline-danger ms-1"
                  onclick="rozlDelete(<?= (int)$rz['id'] ?>)" title="Usuń rozliczenie (wymaga kodu IKA i powodu)">
            <i class="bi bi-trash"></i>
          </button>
        <?php endif; ?>
        <?php if (!empty($rz['nie_wysylac'])): ?>
        <span class="badge bg-light text-secondary border ms-1" title="Oznaczone: nie wysyłać do księgowego"><i class="bi bi-envelope-slash"></i> Bez wysyłki</span>
        <?php elseif ($rz['status'] === 'wyslane' && $rz['sent_to_email']): ?>
        <i class="bi bi-envelope-check text-info ms-1" title="Wysłano: <?= h($rz['sent_to_email']) ?> (<?= date_pl($rz['sent_at']) ?>)"></i>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="card-body text-muted small">
    Brak rozliczeń. Ustaw status umowy na <strong>„Do rozliczenia”</strong> lub kliknij <strong>„Zlecenie wystawienia rachunku”</strong>,
    aby przygotować dane do rachunku dla księgowego.
  </div>
  <?php endif; ?>
  </div>

  <?php if ($kdok_docs): ?>
  <div class="card shadow-sm mt-3">
    <div class="card-header fw-semibold"><i class="bi bi-file-earmark-check"></i> Rachunki w EOD Dokumentów Księgowych</div>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr><th>Numer</th><th>Tytuł</th><th>Kwota</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($kdok_docs as $kd): ?>
        <tr>
          <td><code><?= h($kd['number']) ?></code></td>
          <td><?= h($kd['title']) ?></td>
          <td class="text-nowrap"><?= $kd['kwota'] !== '' ? h($kd['kwota']) . ' PLN' : '—' ?></td>
          <td><?= kdok_status_badge($kd['status']) ?></td>
          <td class="text-end">
            <a href="<?= APP_URL ?>/ksiegowosc/view.php?id=<?= (int)$kd['id'] ?>" target="_blank"
               class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /tab-rozliczenia -->

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
      <input type="hidden" name="type" value="zlecenie">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="disable">
      <button class="btn btn-sm btn-warning" data-confirm="Wyłączyć konto M365?">
        <i class="bi bi-pause-circle"></i> Wyłącz konto
      </button>
    </form>
    <?php else: ?>
    <form method="post" action="<?= APP_URL ?>/contracts/m365_action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="zlecenie">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="enable">
      <button class="btn btn-sm btn-success"><i class="bi bi-play-circle"></i> Włącz konto</button>
    </form>
    <?php endif; ?>
    <?php if ($row['email']): ?>
    <form method="post" action="<?= APP_URL ?>/contracts/m365_action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="zlecenie">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="send_email">
      <button class="btn btn-sm btn-outline-primary"><i class="bi bi-envelope"></i> Wyślij mail z hasłem</button>
    </form>
    <?php endif; ?>
    <form method="post" action="<?= APP_URL ?>/contracts/m365_action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="zlecenie">
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
      <input type="hidden" name="type"    value="zlecenie">
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
      <input type="hidden" name="type"   value="zlecenie">
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
      <input type="hidden" name="type" value="zlecenie">
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

<?php if (($row['forma_podpisania'] ?? '') === 'elektroniczna' && (docusign_is_enabled() || current_user()['role'] === 'admin')): ?>
<?php include dirname(dirname(__DIR__)) . '/includes/docusign_tab.php'; ?>
<?php endif; ?>

<?php if (($row['forma_podpisania'] ?? '') === 'elektroniczna' && (autenti_is_enabled() || current_user()['role'] === 'admin')): ?>
<?php include dirname(dirname(__DIR__)) . '/includes/autenti_tab.php'; ?>
<?php endif; ?>

</div><!-- /tab-content -->
</div><!-- /cv-tabs-layout -->

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

<?php if (can_edit()): ?>
<!-- ═══════════════════ MODAL — ROZLICZENIE (Umowa do rozliczenia) ═══════════════════ -->
<div class="modal fade" id="rozliczenieModal" tabindex="-1" aria-labelledby="rozliczenieModalLabel">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:#6366f1;color:#fff">
        <h5 class="modal-title" id="rozliczenieModalLabel"><i class="bi bi-receipt-cutoff me-2"></i>Zlecenie wystawienia rachunku</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">Dane zaciągnięto z umowy. Uzupełnij brakujące pola i powód, zapisz, a następnie przekaż dane księgowemu (kopiuj / PDF / wyślij).</p>
        <div id="rozlMissing" class="alert alert-warning py-2 small d-none">
          <i class="bi bi-exclamation-triangle me-1"></i><span id="rozlMissingList"></span>
        </div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label small fw-semibold">Dla kogo rachunek</label>
            <input id="rozlName" class="form-control" readonly></div>
          <div class="col-md-6"><label class="form-label small fw-semibold">Data umowy</label>
            <input id="rozlDataUmowy" class="form-control" readonly></div>
          <div class="col-md-4"><label class="form-label small fw-semibold">Data rachunku</label>
            <input id="rozlDataRachunku" type="date" class="form-control" oninput="rozlBuildPreview()"></div>
          <div class="col-md-4"><label class="form-label small fw-semibold">Za jaki okres</label>
            <input id="rozlOkres" class="form-control" placeholder="np. czerwiec 2026" oninput="rozlBuildPreview()"></div>
          <div class="col-md-4"><label class="form-label small fw-semibold">Liczba godzin</label>
            <input id="rozlGodziny" class="form-control" oninput="rozlBuildPreview()"></div>
          <div class="col-md-4"><label class="form-label small fw-semibold">Kwota brutto (PLN)</label>
            <input id="rozlKwota" type="number" step="0.01" class="form-control" oninput="rozlBuildPreview()"></div>
          <div class="col-md-8"><label class="form-label small fw-semibold">Powód wystawienia rachunku</label>
            <input id="rozlPowod" class="form-control" placeholder="np. wynagrodzenie za realizację zlecenia w czerwcu" oninput="rozlBuildPreview()"></div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="rozlNieWysylac" onchange="rozlToggleSend()">
              <label class="form-check-label" for="rozlNieWysylac">
                Nie wysyłaj do księgowego <span class="text-muted">(rozliczę bez maila — kopiuj / PDF / poza systemem)</span>
              </label>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold">Podgląd e-mail do księgowego</label>
            <textarea id="rozlPreview" class="form-control font-monospace" rows="9" readonly style="font-size:.85rem;background:#f8f9fa"></textarea>
            <div id="rozlInfo" class="small mt-1" style="min-height:1.1em"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer flex-wrap gap-2">
        <input type="hidden" id="rozlId" value="">
        <button type="button" class="btn btn-primary" id="rozlSaveBtn" onclick="rozlSave(this)">
          <i class="bi bi-save me-1"></i>Zapisz rozliczenie
        </button>
        <button type="button" class="btn btn-outline-secondary" onclick="rozlCopy()">
          <i class="bi bi-clipboard me-1"></i>Kopiuj e-mail
        </button>
        <a id="rozlPdfBtn" class="btn btn-outline-secondary disabled" target="_blank" href="#" aria-disabled="true">
          <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </a>
        <button type="button" class="btn btn-success disabled" id="rozlSendBtn" onclick="rozlSendFromModal(this)"
                <?= org_setting('ksiegowy_email') ? '' : 'title="Ustaw adres księgowego w Ustawieniach → Poczta"' ?>>
          <i class="bi bi-envelope me-1"></i>Wyślij do księgowego
        </button>
      </div>
    </div>
  </div>
</div>

<script>
window.ROZL_DEFAULTS = {
  imie_nazwisko: <?= json_encode($row['imie_nazwisko'] ?? '') ?>,
  adres:         <?= json_encode(trim(address_format($row))) ?>,
  data_umowy:    <?= json_encode(!empty($row['data_zawarcia']) ? date_pl($row['data_zawarcia']) : '') ?>,
  kwota_brutto:  <?= json_encode($row['wynagrodzenie_brutto'] ?? '') ?>,
  liczba_godzin: <?= json_encode($row['liczba_godzin_planowana'] ?? '') ?>,
  data_rachunku: <?= json_encode($row['data_rachunku'] ?? '') ?>,
  okres:         <?= json_encode($row['okres_rachunku'] ?? '') ?>
};
window.ROZL_CTX = {
  id:        <?= (int)$id ?>,
  type:      'zlecenie',
  ksiegEmail: <?= json_encode((bool)org_setting('ksiegowy_email')) ?>,
  appUrl:    <?= json_encode(rtrim(APP_URL, '/')) ?>
};
</script>
<?php endif; ?>

<script>
window.CVTabsConfig = {
  tabsId:     'zlecenieTabs',
  storageKey: 'zlecenie_tab_<?= $id ?>',
  defaultTab: 'tab-umowa',
  appUrl:     <?= json_encode(rtrim(APP_URL, '/')) ?>,
  csrf:       <?= json_encode(csrf_token()) ?>
};
</script>
<script src="<?= APP_URL ?>/assets/js/contract-view-tabs.js" defer></script>

<?php if ((current_user()['role'] ?? '') === 'admin'): ?>
<!-- ═══════════════════ MODAL — usunięcie rozliczenia (admin + IKA + powód) ═══════════════════ -->
<div class="modal fade" id="rozlDeleteModal" tabindex="-1" aria-labelledby="rozlDeleteLabel">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:#dc3545;color:#fff">
        <h5 class="modal-title" id="rozlDeleteLabel"><i class="bi bi-trash me-2"></i>Usuń rozliczenie</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted">Usunięcie jest trwałe i wymaga potwierdzenia <strong>kodem IKA</strong>. Operacja zostanie zapisana w dzienniku z podanym powodem.</p>
        <input type="hidden" id="rozlDelId" value="">
        <div class="mb-3">
          <label class="form-label fw-semibold">Powód usunięcia <span class="text-danger">*</span></label>
          <textarea id="rozlDelReason" class="form-control" rows="2" placeholder="np. błędnie wystawione, duplikat"></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold">Kod IKA <span class="text-danger">*</span></label>
          <input type="password" id="rozlDelIka" class="form-control" autocomplete="off" placeholder="Twój kod IKA">
        </div>
        <div id="rozlDelErr" class="text-danger small d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-danger" id="rozlDelConfirm"><i class="bi bi-trash me-1"></i>Usuń trwale</button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var APP = <?= json_encode(rtrim(APP_URL, '/')) ?>;
  window.rozlDelete = function (rid) {
    document.getElementById('rozlDelId').value = rid;
    document.getElementById('rozlDelReason').value = '';
    document.getElementById('rozlDelIka').value = '';
    var err = document.getElementById('rozlDelErr'); err.classList.add('d-none'); err.textContent = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('rozlDeleteModal')).show();
  };
  var btn = document.getElementById('rozlDelConfirm');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var rid    = parseInt(document.getElementById('rozlDelId').value, 10);
    var reason = document.getElementById('rozlDelReason').value.trim();
    var ika    = document.getElementById('rozlDelIka').value.trim();
    var err    = document.getElementById('rozlDelErr');
    function showErr(m){ err.textContent = m; err.classList.remove('d-none'); }
    if (!reason) { showErr('Podaj powód usunięcia.'); return; }
    if (!ika)    { showErr('Podaj kod IKA.'); return; }
    btn.disabled = true;
    csrfFetch(APP + '/api/ajax.php', { action: 'rozliczenie_delete', rozliczenie_id: rid, reason: reason, ika: ika })
      .then(function (res) {
        btn.disabled = false;
        if (res && res.ok) {
          bootstrap.Modal.getInstance(document.getElementById('rozlDeleteModal')).hide();
          if (typeof ajaxToast === 'function') ajaxToast(res.msg || 'Rozliczenie usunięte');
          setTimeout(function () { location.reload(); }, 500);
        } else {
          showErr((res && res.msg) || 'Nie udało się usunąć.');
        }
      })
      .catch(function () { btn.disabled = false; showErr('Błąd połączenia.'); });
  });
})();
</script>
<?php endif; ?>

<?php if (can_edit()): ?>
<script>
(function () {
  var _dirty = false;
  var _rozlAdres = '';

  function fmtDate(iso) {
    if (!iso) return '';
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    return m ? (m[3] + '.' + m[2] + '.' + m[1]) : iso;
  }
  function fmtKwota(v) {
    if (v === '' || v === null || v === undefined || isNaN(parseFloat(v))) return '';
    var n = parseFloat(v).toFixed(2).replace('.', ',');
    n = n.replace(/\B(?=(\d{3})+(?!\d))/g, ' '); // separator tysięcy
    return n + ' PLN (brutto)';
  }
  function val(id) { var e = document.getElementById(id); return e ? e.value.trim() : ''; }

  // Wymagane pola + ich etykiety (do prośby o uzupełnienie braków)
  var REQUIRED = [
    {id: 'rozlDataRachunku', label: 'data rachunku'},
    {id: 'rozlOkres',        label: 'za jaki okres'},
    {id: 'rozlKwota',        label: 'kwota brutto'},
    {id: 'rozlGodziny',      label: 'liczba godzin'},
    {id: 'rozlPowod',        label: 'powód wystawienia'}
  ];

  function rozlCheckMissing() {
    var missing = [];
    REQUIRED.forEach(function (f) {
      var e = document.getElementById(f.id);
      if (!e) return;
      if (val(f.id) === '') { e.classList.add('is-invalid'); missing.push(f.label); }
      else { e.classList.remove('is-invalid'); }
    });
    var box = document.getElementById('rozlMissing');
    var list = document.getElementById('rozlMissingList');
    if (missing.length) {
      list.textContent = 'Uzupełnij brakujące dane: ' + missing.join(', ') + '.';
      box.classList.remove('d-none');
    } else {
      box.classList.add('d-none');
    }
  }

  window.rozlBuildPreview = function () {
    var powod = val('rozlPowod');
    var t = 'Dzień dobry,\n'
      + 'poniżej przesyłam dane do wystawienia rachunku:\n'
      + '- dla kogo rachunek: ' + val('rozlName') + '\n'
      + '- adres: ' + _rozlAdres + '\n'
      + '- data umowy: ' + val('rozlDataUmowy') + '\n'
      + '- data rachunku: ' + fmtDate(val('rozlDataRachunku')) + '\n'
      + '- za jaki okres jest rachunek: ' + val('rozlOkres') + '\n'
      + '- kwota brutto lub netto: ' + fmtKwota(val('rozlKwota')) + '\n'
      + '- ilość przepracowanych godzin: ' + val('rozlGodziny') + '\n'
      + (powod ? '- powód wystawienia rachunku: ' + powod + '\n' : '')
      + 'Pozdrawiam';
    var p = document.getElementById('rozlPreview');
    if (p) p.value = t;
    rozlCheckMissing();
  };

  function setVal(id, v) { var e = document.getElementById(id); if (e) e.value = (v === null || v === undefined) ? '' : v; }

  // Wywoływane przez hook nagłówka po zmianie statusu na „do rozliczenia”, lub ręcznie przyciskiem (res=null)
  window.cvhOpenRozliczenie = function (res) {
    var d = (res && res.prefill) ? res.prefill : window.ROZL_DEFAULTS;
    document.getElementById('rozlId').value = '';
    _rozlAdres = d.adres || '';
    setVal('rozlName',         d.imie_nazwisko || '');
    setVal('rozlDataUmowy',    d.data_umowy || '');
    setVal('rozlDataRachunku', d.data_rachunku || '');
    setVal('rozlOkres',        d.okres || '');
    setVal('rozlGodziny',      d.liczba_godzin || '');
    setVal('rozlKwota',        d.kwota_brutto || '');
    setVal('rozlPowod',        '');
    var nw = document.getElementById('rozlNieWysylac'); if (nw) nw.checked = false;
    var info = document.getElementById('rozlInfo'); if (info) { info.textContent = ''; info.className = 'small mt-1'; }
    document.getElementById('rozlSendBtn').classList.add('disabled');
    var pdf = document.getElementById('rozlPdfBtn'); pdf.classList.add('disabled'); pdf.setAttribute('href', '#');
    rozlBuildPreview();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('rozliczenieModal')).show();
  };

  // Włącza/wyłącza przycisk „Wyślij do księgowego” wg checkboxa, ustawień i zapisu
  window.rozlToggleSend = function () {
    var nw = document.getElementById('rozlNieWysylac');
    var sendBtn = document.getElementById('rozlSendBtn');
    var saved = document.getElementById('rozlId').value !== '';
    if ((nw && nw.checked) || !window.ROZL_CTX.ksiegEmail || !saved) sendBtn.classList.add('disabled');
    else sendBtn.classList.remove('disabled');
  };

  window.rozlCopy = function () {
    var ta = document.getElementById('rozlPreview');
    var info = document.getElementById('rozlInfo');
    var done = function () { info.className = 'small mt-1 text-success'; info.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Skopiowano do schowka'; };
    if (navigator.clipboard) navigator.clipboard.writeText(ta.value).then(done, function () { ta.select(); document.execCommand('copy'); done(); });
    else { ta.select(); document.execCommand('copy'); done(); }
  };

  window.rozlSave = function (btn) {
    btn.disabled = true;
    csrfFetch(window.ROZL_CTX.appUrl + '/api/ajax.php', {
      action: 'rozliczenie_create',
      id:     window.ROZL_CTX.id,
      type:   window.ROZL_CTX.type,
      data_rachunku: val('rozlDataRachunku'),
      okres:         val('rozlOkres'),
      kwota_brutto:  val('rozlKwota'),
      liczba_godzin: val('rozlGodziny'),
      powod:         val('rozlPowod'),
      nie_wysylac:   (document.getElementById('rozlNieWysylac') || {}).checked ? 1 : 0
    }).then(function (res) {
      btn.disabled = false;
      if (res.ok) {
        _dirty = true;
        document.getElementById('rozlId').value = res.rozliczenie_id;
        if (res.email_text) document.getElementById('rozlPreview').value = res.email_text;
        var pdf = document.getElementById('rozlPdfBtn');
        pdf.setAttribute('href', window.ROZL_CTX.appUrl + '/contracts/zlecenie/ksiegowy_print.php?id=' + window.ROZL_CTX.id + '&rozliczenie_id=' + res.rozliczenie_id);
        pdf.classList.remove('disabled');
        rozlToggleSend();
        var info = document.getElementById('rozlInfo'); info.className = 'small mt-1 text-success';
        info.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Rozliczenie zapisane (#' + res.rozliczenie_id + ')';
        ajaxToast('Rozliczenie zapisane');
      } else { ajaxToast(res.msg || 'Błąd zapisu', 'error'); }
    }).catch(function () { btn.disabled = false; ajaxToast('Błąd połączenia', 'error'); });
  };

  window.rozlSendFromModal = function (btn) {
    var rid = document.getElementById('rozlId').value;
    if (!rid) { ajaxToast('Najpierw zapisz rozliczenie', 'error'); return; }
    btn.classList.add('disabled');
    csrfFetch(window.ROZL_CTX.appUrl + '/api/ajax.php', { action: 'rozliczenie_send', rozliczenie_id: rid })
      .then(function (res) {
        if (res.ok) { _dirty = true; ajaxToast(res.msg || 'Wysłano'); var info = document.getElementById('rozlInfo'); info.className = 'small mt-1 text-success'; info.innerHTML = '<i class="bi bi-envelope-check me-1"></i>' + (res.msg || 'Wysłano do księgowego'); }
        else { btn.classList.remove('disabled'); ajaxToast(res.msg || 'Błąd wysyłki', 'error'); }
      }).catch(function () { btn.classList.remove('disabled'); ajaxToast('Błąd połączenia', 'error'); });
  };

  // Akcje z listy w zakładce „Rozliczenia”
  window.rozlSend = function (rid, btn) {
    btn.disabled = true;
    csrfFetch(window.ROZL_CTX.appUrl + '/api/ajax.php', { action: 'rozliczenie_send', rozliczenie_id: rid })
      .then(function (res) { if (res.ok) { ajaxToast(res.msg || 'Wysłano'); setTimeout(function () { location.reload(); }, 600); } else { btn.disabled = false; ajaxToast(res.msg || 'Błąd wysyłki', 'error'); } })
      .catch(function () { btn.disabled = false; ajaxToast('Błąd połączenia', 'error'); });
  };
  window.rozlSettle = function (rid, btn) {
    btn.disabled = true;
    csrfFetch(window.ROZL_CTX.appUrl + '/api/ajax.php', { action: 'rozliczenie_settle', rozliczenie_id: rid })
      .then(function (res) { if (res.ok) { ajaxToast(res.msg || 'Oznaczono'); setTimeout(function () { location.reload(); }, 600); } else { btn.disabled = false; ajaxToast(res.msg || 'Błąd', 'error'); } })
      .catch(function () { btn.disabled = false; ajaxToast('Błąd połączenia', 'error'); });
  };

  // Po zamknięciu okienka — odśwież listę, jeśli były zmiany
  document.addEventListener('DOMContentLoaded', function () {
    var m = document.getElementById('rozliczenieModal');
    if (m) m.addEventListener('hidden.bs.modal', function () { if (_dirty) location.reload(); });
  });
})();
</script>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
