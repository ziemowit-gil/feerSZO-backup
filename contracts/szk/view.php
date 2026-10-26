<?php
/**
 * contracts/szk/view.php — karta umowy szkoleniowej SZK (Menu Umowy): dane, status, pisma, sprawa EZD, historia.
 * Sekcje „Zgody i dokumenty”, „Promocje i opłaty” dochodzą w kolejnych etapach (modules/ti_training_contract).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/messages.php';
require_once dirname(dirname(__DIR__)) . '/includes/supervisors.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_training_contract/logic/trainingContractStore.php';

require_login();
$TYPE  = 'szk';
$TABLE = TiTrainingContractStore::TABLE;
TiTrainingContractStore::migrate();
require_once dirname(dirname(__DIR__)) . '/includes/contract_block_schema.php';
contract_block_schema_ensure($TABLE);
$id  = (int)($_GET['id'] ?? 0);
$row = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); die('Nie znaleziono umowy.'); }
if (!viewer_owns_contract($TYPE, $row) || !contract_can_access($TYPE, $row)) { flash_set('error', 'Nie masz dostępu do tej umowy.'); header('Location: ' . APP_URL . '/panel/index.php'); exit; }
$PAGE_TITLE = 'Umowa ' . ($row['numer_umowy'] !== '' ? $row['numer_umowy'] : 'szkoleniowa (projekt)');
$d = array_merge(TiTrainingContract::defaults(), json_decode((string)$row['dane'], true) ?: []);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_set_status'])) {
    csrf_check();
    if (can_edit() && !contract_is_locked($row)) {
        $new = (string)($_POST['status'] ?? '');
        if (contractStatusExists($new, $TYPE) && $new !== 'aneks') {
            require_once dirname(dirname(__DIR__)) . '/includes/guardian_consent.php';
            require_once dirname(dirname(__DIR__)) . '/includes/contract_transitions.php';
            try {
                ContractStatusTransitionValidator::assertAllowed($row['status'], $new, $row, $TYPE);
                db_update($TABLE, ['status' => $new], $id);
                log_contract_action($TYPE, $id, (int)current_user()['id'], 'status_change', 'Zmiana statusu: ' . $row['status'] . ' → ' . $new);
            } catch (ContractTransitionException $e) { flash_set('error', $e->getMessage()); }
        }
    }
    header('Location: view.php?id=' . $id); exit;
}

include dirname(dirname(__DIR__)) . '/includes/header.php';

$_cvh_type       = $TYPE;
$_cvh_id         = $id;
$_cvh_row        = $row;
$_cvh_icon       = 'bi-mortarboard';
$_cvh_label      = 'Umowa szkoleniowa (SZK)';
$_cvh_person     = $row['imie_nazwisko'];
$_cvh_person_sub = $row['email'] ?? '';
$_cvh_amount     = ($row['kwota_umowy'] ?? '') !== '' ? (float)str_replace(',', '.', preg_replace('/[^\d,.]/', '', (string)$row['kwota_umowy'])) : null;
$_cvh_amount_lbl = 'Wynagrodzenie';
$_cvh_end_date   = $row['data_zakonczenia'] ?? null;
$_cvh_subject    = $row['temat'] ?? null;
$_cvh_list_url   = APP_URL . '/contracts/szk/list.php';
$_cvh_edit_url   = 'edit.php?id=' . $id;
include dirname(dirname(__DIR__)) . '/includes/contract_view_header.php';
$_fund = TiTrainingContract::FUNDING[$d['finansowanie'] ?? ''] ?? '';
$_fd = fn(string $k) => trim((string)($d[$k] ?? '')) !== '' ? h((string)$d[$k]) : '—';
?>
<div class="row g-3"><div class="col-lg-8">

<div class="card shadow-sm mb-3"><div class="card-header fw-semibold">Uczestnik</div><div class="card-body"><div class="row g-3">
  <div class="col-md-6"><div class="detail-label">Imię i nazwisko</div><div class="detail-value"><?= h($row['imie_nazwisko']) ?: '—' ?>
    <?php if ((int)$row['client_id'] > 0): ?><a class="small ms-1" href="<?= APP_URL ?>/karty30/ti/dydaktyk/office_enter.php?next=<?= urlencode('kursant.php?id=' . (int)$row['client_id']) ?>">karta uczestnika</a><?php endif; ?></div></div>
  <div class="col-md-3"><div class="detail-label">PESEL</div><div class="detail-value"><?= $_fd('pesel') ?></div></div>
  <div class="col-md-3"><div class="detail-label">Telefon</div><div class="detail-value"><?= $_fd('telefon') ?></div></div>
  <div class="col-md-6"><div class="detail-label">Adres</div><div class="detail-value"><?= $_fd('adres') ?></div></div>
  <div class="col-md-6"><div class="detail-label">E-mail</div><div class="detail-value"><?= $row['email'] ? '<a href="mailto:' . h($row['email']) . '">' . h($row['email']) . '</a>' : '—' ?></div></div>
</div></div></div>

<div class="card shadow-sm mb-3"><div class="card-header fw-semibold">Szkolenie i rozliczenie</div><div class="card-body"><div class="row g-3">
  <div class="col-12"><div class="detail-label">Temat</div><div class="detail-value"><?= h($row['temat']) ?: '—' ?></div></div>
  <div class="col-md-4"><div class="detail-label">Wymiar</div><div class="detail-value"><?= $_fd('wymiar') ?></div></div>
  <div class="col-md-4"><div class="detail-label">Prowadzący</div><div class="detail-value"><?= $_fd('prowadzacy') ?></div></div>
  <div class="col-md-4"><div class="detail-label">Data zawarcia</div><div class="detail-value"><?= date_pl($row['data_zawarcia']) ?></div></div>
  <div class="col-md-4"><div class="detail-label">Finansowanie</div><div class="detail-value"><?= h($_fund) ?: '—' ?></div></div>
  <div class="col-md-4"><div class="detail-label">Wynagrodzenie</div><div class="detail-value"><?= $_fd('wynagrodzenie') ?></div></div>
  <div class="col-md-4"><div class="detail-label">Płatnik</div><div class="detail-value"><?= $_fd('platnik') ?><?= trim((string)($d['kwota_platnik'] ?? '')) !== '' ? ' · ' . h($d['kwota_platnik']) : '' ?></div></div>
  <div class="col-md-4"><div class="detail-label">Kwota uczestnika</div><div class="detail-value"><?= $_fd('kwota_kursant') ?></div></div>
  <div class="col-md-4"><div class="detail-label">Opiekun</div><div class="detail-value"><?= h($row['opiekun']) ?: '—' ?></div></div>
</div></div></div>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/letters.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_ezd.php';
$_letters = get_contract_letters($TYPE, $id);
?>
<div class="card shadow-sm mb-3 no-print">
<div class="card-header fw-semibold d-flex justify-content-between align-items-center">
  <span><i class="bi bi-envelope-paper"></i> Pisma</span>
  <?php if (can_edit()): ?><a href="<?= APP_URL ?>/contracts/letters/add.php?type=<?= $TYPE ?>&id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> Dodaj pismo</a><?php endif; ?>
</div>
<?php if ($_letters): ?><ul class="list-group list-group-flush">
  <?php foreach ($_letters as $_l): ?><li class="list-group-item small"><?= letter_direction_badge($_l['kierunek']) ?> <?= letter_type_badge($_l['typ_pisma']) ?>
    <a href="<?= APP_URL ?>/contracts/letters/view.php?id=<?= (int)$_l['id'] ?>"><?= h($_l['tytul']) ?></a> <span class="text-muted ms-1"><?= date_pl($_l['data_pisma']) ?></span></li><?php endforeach; ?>
</ul><?php else: ?><div class="card-body text-muted small">Brak pism dla tej umowy.</div><?php endif; ?>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/_contract_ezd_panel.php'; ?>

<?php $audit_log = get_audit_log($TYPE, $id); if ($audit_log): ?>
<div class="card shadow-sm mb-3 no-print"><div class="card-header fw-semibold"><i class="bi bi-journal-text"></i> Historia zdarzeń</div>
<ul class="list-group list-group-flush">
<?php foreach ($audit_log as $log): ?><li class="list-group-item d-flex justify-content-between align-items-start py-2"><div>
  <?= action_badge($log['action']) ?><span class="ms-2 small"><?= h($log['user_snapshot'] ?? 'System') ?></span>
  <?php if ($log['note']): ?><br><small class="text-muted ms-1"><?= h($log['note']) ?></small><?php endif; ?></div>
  <small class="text-muted text-nowrap"><?= date_pl($log['created_at']) ?></small></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

</div><div class="col-lg-4">

<div class="card shadow-sm mb-3 no-print"><div class="card-header fw-semibold">Akcje</div><div class="card-body d-grid gap-2">
  <a class="btn btn-outline-primary btn-sm" href="edit.php?id=<?= $id ?>&amp;docx=1"><i class="bi bi-file-earmark-word me-1"></i>Pobierz DOCX</a>
  <?php if (can_edit()): ?><a class="btn btn-outline-secondary btn-sm" href="edit.php?id=<?= $id ?>"><i class="bi bi-pencil me-1"></i>Edytuj umowę</a><?php endif; ?>
  <?php if (can_edit() && !contract_is_locked($row)): ?>
  <form method="post" class="mt-1">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_set_status" value="1">
    <label class="form-label small mb-1" for="szk-status">Zmień status</label>
    <div class="input-group input-group-sm">
      <select id="szk-status" name="status" class="form-select"><?php foreach (contractStatusOptions($TYPE, $row['status']) as $k => $v): ?><option value="<?= h($k) ?>" <?= $row['status'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
      <button class="btn btn-outline-secondary">Zapisz</button></div>
  </form>
  <?php endif; ?>
</div></div>

<div class="card shadow-sm mb-3"><div class="card-header fw-semibold">Rejestr</div><div class="card-body small">
  <div class="detail-label">Numer umowy</div><div class="detail-value font-monospace mb-2"><?= h($row['numer_umowy']) ?: '—' ?></div>
  <div class="detail-label">Rejestr Umów (RU)</div><div class="detail-value font-monospace"><?= h($row['nr_rejestru']) ?: '—' ?></div>
  <?php if ((int)$row['is_test'] === 1): ?><span class="badge text-bg-warning mt-2">umowa testowa</span><?php endif; ?>
</div></div>

</div></div>
<?php
require_once dirname(dirname(__DIR__)) . '/tasks/includes/linked_tasks_box.php';
$_tlb = tasksLinksBox('contract', 'szk:' . (int)$id, (int)(current_user()['id'] ?? 0));
if ($_tlb !== '') echo '<div class="container-xl my-4">' . $_tlb . '</div>';
include dirname(dirname(__DIR__)) . '/includes/footer.php';
