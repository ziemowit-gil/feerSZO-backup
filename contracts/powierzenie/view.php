<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/messages.php';
require_once dirname(dirname(__DIR__)) . '/includes/supervisors.php';

require_login();
$TYPE  = 'powierzenie';
$TABLE = 'umowy_powierzenie';
$id    = intval($_GET['id'] ?? 0);
$row   = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); die('Nie znaleziono umowy.'); }
if (!viewer_owns_contract($TYPE, $row)) { flash_set('error', 'Nie masz dostępu do tej umowy.'); header('Location: ' . APP_URL . '/panel/index.php'); exit; }
$PAGE_TITLE = 'Umowa ' . $row['numer_umowy'];

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

$formy_zlecenia = ['powierzenie' => 'Powierzenie (100% dotacji)', 'wsparcie' => 'Wsparcie (z wkładem własnym)'];
$tryby = ['konkurs' => 'Otwarty konkurs ofert', 'art19a' => 'Tryb pozakonkursowy (art. 19a — mały grant)', 'inny' => 'Inny tryb'];

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<?php
$_cvh_type       = $TYPE;
$_cvh_id         = $id;
$_cvh_row        = $row;
$_cvh_icon       = 'bi-bank';
$_cvh_label      = 'Umowa powierzenia zadania publicznego';
$_cvh_person     = $row['organ_zlecajacy'] ?? '';
$_cvh_person_sub = $row['email'] ?? '';
$_cvh_amount     = $row['kwota_dotacji'] ?? null;
$_cvh_amount_lbl = 'Kwota dotacji';
$_cvh_end_date   = $row['data_zakonczenia'] ?? null;
$_cvh_subject    = $row['nazwa_zadania'] ?? null;
$_cvh_list_url   = APP_URL . '/contracts/powierzenie/list.php';
$_cvh_edit_url   = 'edit.php?id=' . $id;
include dirname(dirname(__DIR__)) . '/includes/contract_view_header.php';
?>

<div class="row g-3">

<div class="col-lg-8">

<!-- DANE PODSTAWOWE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold">Dane podstawowe</div>
<div class="card-body">
<div class="row g-3">
  <div class="col-md-4"><div class="detail-label">Forma zlecenia</div><div class="detail-value"><?= h($formy_zlecenia[$row['forma_zlecenia']] ?? $row['forma_zlecenia']) ?: '—' ?></div></div>
  <div class="col-md-4"><div class="detail-label">Opiekun</div><div class="detail-value"><?= h($row['opiekun']) ?: '—' ?></div></div>
  <div class="col-md-4"><div class="detail-label">Numer projektu</div><div class="detail-value"><?= h($row['numer_projektu']) ?: '—' ?></div></div>
</div>
</div>
</div>

<!-- ZADANIE PUBLICZNE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold">Zadanie publiczne</div>
<div class="card-body">
<div class="row g-3">
  <div class="col-12"><div class="detail-label">Nazwa zadania</div><div class="detail-value fw-semibold"><?= h($row['nazwa_zadania']) ?: '—' ?></div></div>
  <div class="col-md-4"><div class="detail-label">Sfera pożytku publicznego</div><div class="detail-value"><?= h($row['sfera_zadania']) ?: '—' ?></div></div>
  <div class="col-md-4"><div class="detail-label">Tryb zlecenia</div><div class="detail-value"><?= h($tryby[$row['tryb_zlecenia']] ?? $row['tryb_zlecenia']) ?: '—' ?></div></div>
  <div class="col-md-4"><div class="detail-label">Nr / nazwa konkursu</div><div class="detail-value"><?= h($row['nazwa_konkursu']) ?: '—' ?></div></div>
  <?php if ($row['zakres_rzeczowy']): ?>
  <div class="col-12"><div class="detail-label">Zakres rzeczowy</div><div class="detail-value"><?= nl2br(h($row['zakres_rzeczowy'])) ?></div></div>
  <?php endif; ?>
  <?php if ($row['rezultaty']): ?>
  <div class="col-12"><div class="detail-label">Zakładane rezultaty</div><div class="detail-value"><?= nl2br(h($row['rezultaty'])) ?></div></div>
  <?php endif; ?>
</div>
</div>
</div>

<!-- ORGAN ZLECAJĄCY -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold">Zleceniodawca (organ administracji)</div>
<div class="card-body">
<div class="row g-3">
  <div class="col-md-6"><div class="detail-label">Organ zlecający</div><div class="detail-value"><?= h($row['organ_zlecajacy']) ?: '—' ?></div></div>
  <div class="col-md-6"><div class="detail-label">Reprezentowany przez</div><div class="detail-value"><?= h($row['organ_reprezentacja']) ?: '—' ?></div></div>
  <div class="col-12"><div class="detail-label">Adres organu</div><div class="detail-value"><?= h($row['organ_adres']) ?: '—' ?></div></div>
  <div class="col-md-6"><div class="detail-label">Adres e-mail</div><div class="detail-value"><?= $row['email'] ? '<a href="mailto:' . h($row['email']) . '">' . h($row['email']) . '</a>' : '—' ?></div></div>
</div>
</div>
</div>

<!-- FINANSOWANIE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold">Finansowanie / dotacja</div>
<div class="card-body">
<div class="row g-3">
  <div class="col-md-4"><div class="detail-label">Kwota dotacji</div><div class="detail-value fw-bold text-success"><?= money($row['kwota_dotacji'], $row['waluta'] ?: 'PLN') ?></div></div>
  <?php if ($row['forma_zlecenia'] === 'wsparcie'): ?>
  <div class="col-md-4"><div class="detail-label">Wkład własny finansowy</div><div class="detail-value"><?= money($row['wklad_wlasny'], $row['waluta'] ?: 'PLN') ?></div></div>
  <div class="col-md-4"><div class="detail-label">Wkład osobowy / rzeczowy</div><div class="detail-value"><?= money($row['wklad_osobowy'], $row['waluta'] ?: 'PLN') ?></div></div>
  <?php endif; ?>
  <?php if ($row['calkowity_koszt'] !== null && $row['calkowity_koszt'] !== ''): ?>
  <div class="col-md-4"><div class="detail-label">Całkowity koszt zadania</div><div class="detail-value"><?= money($row['calkowity_koszt'], $row['waluta'] ?: 'PLN') ?></div></div>
  <?php endif; ?>
  <?php if ($row['rachunek_dotacji']): ?>
  <div class="col-md-8"><div class="detail-label">Wyodrębniony rachunek dotacji</div><div class="detail-value font-monospace"><?= h($row['rachunek_dotacji']) ?></div></div>
  <?php endif; ?>
  <?php if ($row['transze']): ?>
  <div class="col-12"><div class="detail-label">Harmonogram / transze płatności</div><div class="detail-value"><?= nl2br(h($row['transze'])) ?></div></div>
  <?php endif; ?>
  <?php if ($row['koszty_kwalifikowane']): ?>
  <div class="col-12"><div class="detail-label">Koszty kwalifikowane / kosztorys</div><div class="detail-value"><?= nl2br(h($row['koszty_kwalifikowane'])) ?></div></div>
  <?php endif; ?>
</div>
</div>
</div>

<!-- TERMINY -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold">Terminy</div>
<div class="card-body">
<div class="row g-3">
  <div class="col-md-4"><div class="detail-label">Data zawarcia</div><div class="detail-value"><?= date_pl($row['data_zawarcia']) ?></div></div>
  <div class="col-md-4"><div class="detail-label">Realizacja od</div><div class="detail-value"><?= date_pl($row['data_rozpoczecia']) ?></div></div>
  <div class="col-md-4"><div class="detail-label">Realizacja do</div><div class="detail-value"><?= date_pl($row['data_zakonczenia']) ?></div></div>
  <div class="col-md-6"><div class="detail-label">Termin wykorzystania dotacji</div><div class="detail-value"><?= date_pl($row['termin_wykorzystania']) ?></div></div>
  <div class="col-md-6"><div class="detail-label">Termin sprawozdania końcowego</div><div class="detail-value"><?= date_pl($row['termin_sprawozdania']) ?></div></div>
</div>
</div>
</div>

<!-- PODPISANIE -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold">Forma podpisania</div>
<div class="card-body">
<div class="row g-3">
  <div class="col-md-4"><div class="detail-label">Forma podpisania</div><div class="detail-value"><?= h(ucfirst($row['forma_podpisania'] ?? '')) ?: '—' ?></div></div>
  <?php if ($row['forma_podpisania'] === 'elektroniczna'): ?>
  <div class="col-md-4"><div class="detail-label">Platforma</div><div class="detail-value"><?= h($row['platforma_el']) ?: '—' ?></div></div>
  <div class="col-md-4"><div class="detail-label">ID dokumentu</div><div class="detail-value"><?= h($row['id_dokumentu_el']) ?: '—' ?></div></div>
  <div class="col-md-4"><div class="detail-label">Plik potwierdzenia</div><div class="detail-value"><?= upload_link_signed($row['plik_potwierdzenia']) ?></div></div>
  <?php elseif ($row['forma_podpisania'] === 'epodpis_kwalifikowany'): ?>
  <div class="col-md-4"><div class="detail-label">Dostawca podpisu</div><div class="detail-value"><?= h($row['epodpis_dostawca'] ?? '') ?: '—' ?></div></div>
  <div class="col-md-4"><div class="detail-label">Nr certyfikatu</div><div class="detail-value"><?= h($row['epodpis_nr_certyfikatu'] ?? '') ?: '—' ?></div></div>
  <div class="col-md-4"><div class="detail-label">Ważność certyfikatu</div><div class="detail-value"><?= date_pl($row['epodpis_data_waznosci'] ?? '') ?></div></div>
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

<!-- Numery referencyjne -->
<?php if ($row['nr_roboczy'] || $row['nr_system'] || $row['nr_rejestru']): ?>
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-hash"></i> Numery referencyjne</div>
<div class="card-body"><div class="row g-3">
  <?php if ($row['nr_roboczy']): ?>
  <div class="col-md-4"><div class="detail-label">Nr roboczy</div>
    <div class="detail-value"><?= h($row['nr_roboczy']) ?></div></div>
  <?php endif; ?>
  <?php if ($row['nr_system']): ?>
  <div class="col-md-4"><div class="detail-label">Nr ogólny (webNGO)</div>
    <div class="detail-value"><?= h($row['nr_system']) ?></div></div>
  <?php endif; ?>
  <?php if ($row['nr_rejestru']): ?>
  <div class="col-md-4"><div class="detail-label">Nr rejestru</div>
    <div class="detail-value fw-bold font-monospace"><?= h($row['nr_rejestru']) ?></div></div>
  <?php endif; ?>
</div></div>
</div>
<?php endif; ?>

</div><!-- /col-lg-8 -->

<div class="col-lg-4">
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-paperclip"></i> Pliki</div>
<div class="card-body">
  <div class="mb-2">
    <div class="detail-label">Plik umowy</div>
    <?= upload_link_signed($row['plik_umowy']) ?>
  </div>
  <?php if ($row['zalaczniki']): ?>
  <div class="mb-2">
    <div class="detail-label">Załączniki</div>
    <?= upload_link($row['zalaczniki']) ?>
  </div>
  <?php endif; ?>
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
<div class="card-header fw-semibold">Historia</div>
<div class="card-body small text-muted">
  Dodano: <?= date_pl($row['created_at']) ?><br>
  Zmodyfikowano: <?= date_pl($row['updated_at']) ?>
</div>
</div>
</div>

</div><!-- /row -->

<?php
require_once dirname(dirname(__DIR__)) . '/includes/amendments.php';
$amendments   = get_amendments($TYPE, $id);
$edit_requests = get_edit_requests($TYPE, $id);
?>

<!-- Aneksy -->
<div class="card shadow-sm mb-3 no-print">
<div class="card-header fw-semibold d-flex justify-content-between align-items-center">
  <span><i class="bi bi-file-earmark-diff"></i> Aneksy do umowy</span>
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

<!-- Wnioski o edycję -->
<div class="card shadow-sm mb-3 no-print">
<div class="card-header fw-semibold d-flex justify-content-between align-items-center">
  <span><i class="bi bi-pencil-square"></i> Wnioski o edycję</span>
  <?php
  $has_pending_edit = !empty(array_filter($edit_requests, fn($r) => $r['status'] === 'oczekuje'));
  if (can_edit() && !$has_pending_edit): ?>
  <a href="<?= APP_URL ?>/contracts/approvals/changes_request.php?type=<?= $TYPE ?>&id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-pencil"></i> Złóż wniosek o edycję
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

<?php
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
$approval  = get_current_approval($TYPE, $id);
$audit_log = get_audit_log($TYPE, $id);
?>

<div class="card shadow-sm mb-3 no-print">
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

<?php if (can_edit() && (!$approval || $approval['status'] !== 'oczekuje')): ?>
<form method="post" action="<?= APP_URL ?>/contracts/approvals/submit.php">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="type" value="<?= $TYPE ?>">
  <input type="hidden" name="id" value="<?= $id ?>">
  <button class="btn btn-sm btn-outline-warning"><i class="bi bi-send"></i> Złóż do akceptacji</button>
</form>
<?php endif; ?>

<?php if (is_admin()): ?>
<div class="mt-3">
  <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
    <i class="bi bi-trash3"></i> Usuń umowę
  </button>
</div>
<?php endif; ?>

</div>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/letters.php';
$_letters = get_contract_letters($TYPE, $id);
?>
<div class="card shadow-sm mb-3 no-print">
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
  <thead class="table-light"><tr><th>Kierunek</th><th>Typ</th><th>Tytuł</th><th>Data</th><th>Strona</th><th></th></tr></thead>
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

<?php if ($audit_log): ?>
<div class="card shadow-sm mb-3 no-print">
<div class="card-header fw-semibold"><i class="bi bi-journal-text"></i> Historia zdarzeń</div>
<div class="card-body p-0">
<ul class="list-group list-group-flush">
<?php foreach ($audit_log as $log): ?>
<li class="list-group-item d-flex justify-content-between align-items-start py-2">
  <div>
    <?= action_badge($log['action']) ?>
    <span class="ms-2 small"><?= h($log['user_snapshot'] ?? 'System') ?></span>
    <?php if ($log['note']): ?><br><small class="text-muted ms-1"><?= h($log['note']) ?></small><?php endif; ?>
  </div>
  <small class="text-muted text-nowrap"><?= date_pl($log['created_at']) ?></small>
</li>
<?php endforeach; ?>
</ul>
</div>
</div>
<?php endif; ?>

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

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
