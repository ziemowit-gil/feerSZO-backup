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
require_once dirname(dirname(__DIR__)) . '/modules/ti_training_contract/logic/trainingContractFees.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_training_contract/logic/trainingContractBilling.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_training_contract/logic/trainingContractConsents.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_training_contract/logic/trainingContractReports.php';

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
                TiSzkBilling::onStatusChange($id, $new);   // naliczenie należności (podpisana) / wycofanie (anulowana)
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
    <?php if ((int)$row['client_id'] > 0): ?><a class="small ms-1" href="<?= APP_URL ?>/karty30/ti/dydaktyk/office_enter.php?next=<?= urlencode('kartaKursanta.php?client=' . (int)$row['client_id']) ?>">karta uczestnika</a><?php endif; ?></div></div>
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
$_op = (array)($d['oplaty'] ?? []);
$_set = TiSzkFees::settings();
$_opModel = (string)($_op['model'] ?? $_set['model']);
$_canFees = can_edit() && !TiTrainingContractStore::isSigned($row['status']);
$_promos = TiSzkFees::promotions();
$_pid = (int)($_op['promocja']['id'] ?? $row['promocja_id'] ?? 0);
$_money = fn($gr) => number_format(((int)$gr) / 100, 2, ',', ' ') . ' zł';
?>
<div class="card shadow-sm mb-3" id="szk-fees"><div class="card-header fw-semibold d-flex justify-content-between align-items-center">
  <span><i class="bi bi-percent"></i> Promocje i opłaty</span>
  <a class="small" href="<?= APP_URL ?>/contracts/szk/promocje.php">Promocje</a></div>
<div class="card-body">
<?php if ($_op): ?>
  <div class="row g-3 mb-3">
    <div class="col-md-4"><div class="detail-label">Model</div><div class="detail-value"><?= h(TiSzkFees::MODELS[$_opModel] ?? $_opModel) ?></div></div>
    <div class="col-md-4"><div class="detail-label">Kwota bazowa</div><div class="detail-value"><?= $_money($_op['kwota_bazowa'] ?? 0) ?></div></div>
    <div class="col-md-4"><div class="detail-label">Do zapłaty po promocji</div><div class="detail-value fw-bold"><?= $_money($_op['kwota_po'] ?? 0) ?></div></div>
    <?php if (!empty($_op['promocja'])): ?><div class="col-12"><div class="detail-label">Promocja</div><div class="detail-value"><?= h($_op['promocja']['nazwa']) ?> — rabat <?= $_money($_op['promocja']['rabat']) ?></div></div><?php endif; ?>
  </div>
  <?php if (!empty($_op['harmonogram'])): ?>
  <div class="table-responsive mb-3"><table class="table table-sm mb-0"><caption class="visually-hidden">Harmonogram płatności</caption>
    <thead><tr><th scope="col">Lp.</th><th scope="col">Termin</th><th scope="col" class="text-end">Kwota</th></tr></thead><tbody>
    <?php foreach ($_op['harmonogram'] as $_h): ?><tr><td><?= (int)$_h['lp'] ?></td><td><?= h(date('d.m.Y', strtotime($_h['termin']))) ?></td><td class="text-end"><?= $_money($_h['kwota']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
<?php else: ?><p class="text-muted small">Nie ustawiono jeszcze modelu opłat — kwota umowy to wynagrodzenie z formularza.</p><?php endif; ?>
<?php if ($_canFees): ?>
<form method="post" action="<?= APP_URL ?>/contracts/szk/fees.php" class="row g-2 no-print border-top pt-3">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="col-md-6"><label class="form-label small mb-1" for="f-model">Model naliczania opłat</label>
    <select class="form-select form-select-sm" id="f-model" name="model"><?php foreach (TiSzkFees::MODELS as $k => $l): ?><option value="<?= h($k) ?>" <?= $_opModel === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label small mb-1" for="f-promo">Promocja</label>
    <select class="form-select form-select-sm" id="f-promo" name="promocja_id"><option value="">— bez promocji —</option><?php foreach ($_promos as $_p): ?><option value="<?= (int)$_p['id'] ?>" <?= $_pid === (int)$_p['id'] ? 'selected' : '' ?>><?= h(TiSzkFees::promotionLabel($_p)) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label small mb-1" for="f-kw">Kwota bazowa (zł)</label><input class="form-control form-control-sm" id="f-kw" name="kwota_bazowa" inputmode="decimal" value="<?= isset($_op['kwota_bazowa']) ? h(TiSzkFees::fmt((int)$_op['kwota_bazowa'])) : h((string)($d['wynagrodzenie'] ?? '')) ?>"></div>
  <div class="col-md-3"><label class="form-label small mb-1" for="f-h">Godziny (model godzinowy)</label><input class="form-control form-control-sm" id="f-h" name="godziny" inputmode="decimal" value="<?= h((string)($_op['godziny'] ?? '')) ?>"></div>
  <div class="col-md-3"><label class="form-label small mb-1" for="f-s">Stawka za godzinę (zł)</label><input class="form-control form-control-sm" id="f-s" name="stawka" inputmode="decimal" value="<?= isset($_op['stawka']) && $_op['stawka'] !== null ? h(TiSzkFees::fmt((int)$_op['stawka'])) : '' ?>"></div>
  <div class="col-md-3"><label class="form-label small mb-1" for="f-r">Liczba rat / miesięcy</label><input class="form-control form-control-sm" id="f-r" type="number" min="1" max="60" name="raty" value="<?= (int)($_op['raty'] ?? $_set['raty']) ?>"></div>
  <div class="col-md-4"><label class="form-label small mb-1" for="f-t">Pierwsza płatność</label><input class="form-control form-control-sm" id="f-t" type="date" name="termin" value="<?= h((string)($_op['termin'] ?? ($row['data_zawarcia'] !== '' ? date('Y-m-d', strtotime($row['data_zawarcia'] . ' +' . (int)$_set['dni'] . ' days')) : ''))) ?>"></div>
  <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-calculator me-1"></i>Przelicz i zapisz</button>
    <span class="small text-muted ms-2">Kwota po promocji trafia do wynagrodzenia w umowie (DOCX).</span></div>
</form>
<?php elseif (can_edit()): ?><p class="small text-muted mb-0">Umowa jest podpisana — opłat nie zmieniamy.</p><?php endif; ?>
</div></div>

<?php
$_bp = TiSzkBilling::posted($id);
$_bplan = TiSzkBilling::plan($d);
$_bbal = TiSzkBilling::balance((int)$row['client_id']);
$_bm = fn($v) => number_format((float)$v, 2, ',', ' ') . ' zł';
?>
<div class="card shadow-sm mb-3" id="szk-billing"><div class="card-header fw-semibold d-flex justify-content-between align-items-center">
  <span><i class="bi bi-receipt-cutoff"></i> Rozliczenia</span>
  <?php if ((int)$row['client_id'] > 0): ?><a class="small" href="<?= APP_URL ?>/karty30/ti/dydaktyk/office_enter.php?next=<?= urlencode('kartaKursanta.php?client=' . (int)$row['client_id']) ?>">Karta uczestnika</a><?php endif; ?></div>
<div class="card-body">
<?php if ((int)$row['client_id'] <= 0): ?><p class="text-muted small mb-0">Umowa nie jest powiązana z uczestnikiem z karty TI — wybierz uczestnika w formularzu umowy, aby księgować należności.</p>
<?php else: ?>
  <?php if ($_bbal): ?><div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="detail-label">Należności (całość)</div><div class="detail-value"><?= $_bm($_bbal['charges']) ?></div></div>
    <div class="col-6 col-md-3"><div class="detail-label">Wpłaty</div><div class="detail-value"><?= $_bm($_bbal['payments']) ?></div></div>
    <div class="col-6 col-md-3"><div class="detail-label">Nadpłata</div><div class="detail-value <?= $_bbal['credit'] > 0 ? 'text-success fw-bold' : '' ?>"><?= $_bm($_bbal['credit']) ?></div></div>
    <div class="col-6 col-md-3"><div class="detail-label">Niedopłata</div><div class="detail-value <?= $_bbal['debt'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= $_bm($_bbal['debt']) ?></div></div>
  </div><p class="small text-muted">Saldo dotyczy całego konta rozliczeń uczestnika (nie tylko tej umowy).</p><?php endif; ?>
  <?php if ($_bplan): ?>
  <div class="table-responsive mb-2"><table class="table table-sm mb-0"><caption class="visually-hidden">Należności z umowy</caption>
    <thead><tr><th scope="col">Lp.</th><th scope="col">Termin</th><th scope="col" class="text-end">Kwota uczestnika</th><th scope="col">Księga rozliczeń</th></tr></thead><tbody>
    <?php foreach ($_bplan as [$_lp, $_dt, $_gr]): $_ph = $_bp[$_lp] ?? null; $_same = $_ph && abs((float)$_ph['amount'] - $_gr / 100) < 0.004 && (int)$_ph['month'] === (int)date('n', strtotime($_dt)) && (int)$_ph['year'] === (int)date('Y', strtotime($_dt)); ?>
    <tr><td><?= (int)$_lp ?></td><td><?= h(date('d.m.Y', strtotime($_dt))) ?></td><td class="text-end"><?= $_bm($_gr / 100) ?></td>
      <td><?= $_same ? '<span class="badge text-bg-success">zaksięgowano</span>' : ($_ph ? '<span class="badge text-bg-warning">do korekty</span>' : '<span class="badge text-bg-secondary">nie zaksięgowano</span>') ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php else: ?><p class="text-muted small">Brak kwoty uczestnika w umowie — uzupełnij wynagrodzenie / kwotę uczestnika.</p><?php endif; ?>
  <?php if (can_edit() && $_bplan && (int)$row['is_test'] === 0): ?>
  <form method="post" action="<?= APP_URL ?>/contracts/szk/billing.php" class="no-print d-flex flex-wrap gap-2 align-items-center">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn btn-outline-primary btn-sm" name="act" value="sync"><i class="bi bi-arrow-repeat me-1"></i>Zaksięguj / zsynchronizuj należności</button>
    <?php if ($_bp): ?><button class="btn btn-outline-danger btn-sm" name="act" value="clear" onclick="return confirm('Wycofać zaksięgowane należności tej umowy z rozliczeń?')">Wycofaj należności</button><?php endif; ?>
    <span class="small text-muted"><?= TiSzkBilling::autoEnabled() ? 'Automatycznie po zmianie statusu na „podpisana”.' : 'Automatyczne naliczanie wyłączone w ustawieniach.' ?></span>
  </form><?php endif; ?>
<?php endif; ?>
</div></div>

<?php
$_zg = TiSzkConsents::list($id);
[$_zgReq, $_zgOk] = TiSzkConsents::summary($id);
$_zgBadge = ['pending' => 'text-bg-secondary', 'granted' => 'text-bg-success', 'declined' => 'text-bg-danger'];
?>
<div class="card shadow-sm mb-3" id="szk-consents"><div class="card-header fw-semibold d-flex justify-content-between align-items-center">
  <span><i class="bi bi-patch-check"></i> Zgody i dokumenty</span>
  <?php if ($_zg): ?><span class="badge <?= $_zgOk === $_zgReq ? 'text-bg-success' : 'text-bg-warning' ?>"><?= (int)$_zgOk ?> / <?= (int)$_zgReq ?> wymaganych</span><?php endif; ?></div>
<?php if (!$_zg): ?>
  <div class="card-body"><p class="text-muted small mb-2">Brak listy zgód i dokumentów dla tej umowy.</p>
  <?php if (can_edit()): ?><form method="post" action="<?= APP_URL ?>/contracts/szk/consents.php"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="init">
    <button class="btn btn-outline-primary btn-sm"><i class="bi bi-list-check me-1"></i>Utwórz listę wymaganych zgód</button></form><?php endif; ?></div>
<?php else: ?>
  <ul class="list-group list-group-flush">
  <?php foreach ($_zg as $_z): ?>
    <li class="list-group-item"><div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
      <div><strong><?= h($_z['title']) ?></strong><?= (int)$_z['required'] ? '' : ' <span class="badge text-bg-light border">dobrowolna</span>' ?>
        <div class="small text-muted"><?= h(TiSzkConsents::KINDS[$_z['kind']][2] ?? '') ?></div>
        <?php if ($_z['decided_at']): ?><div class="small">Decyzja: <?= h(date('d.m.Y H:i', strtotime($_z['decided_at']))) ?> · <?= h($_z['decided_by']) ?><?= $_z['note'] !== '' ? ' · ' . h($_z['note']) : '' ?></div><?php endif; ?>
        <?php if ($_z['file_path'] !== ''): ?><div class="small"><i class="bi bi-paperclip"></i> <a href="<?= APP_URL ?>/contracts/szk/consents.php?dl=<?= (int)$_z['id'] ?>" target="_blank" rel="noopener"><?= h($_z['file_name']) ?></a></div><?php endif; ?></div>
      <span class="badge <?= $_zgBadge[$_z['state']] ?? 'text-bg-secondary' ?>"><?= h(TiSzkConsents::STATES[$_z['state']] ?? $_z['state']) ?></span>
    </div>
    <?php if (can_edit()): ?>
    <form method="post" action="<?= APP_URL ?>/contracts/szk/consents.php" enctype="multipart/form-data" class="row g-2 mt-1 no-print align-items-end">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="item" value="<?= (int)$_z['id'] ?>">
      <div class="col-auto"><label class="visually-hidden" for="zs-<?= (int)$_z['id'] ?>">Stan: <?= h($_z['title']) ?></label>
        <select class="form-select form-select-sm" id="zs-<?= (int)$_z['id'] ?>" name="state"><?php foreach (TiSzkConsents::STATES as $k => $l): ?><option value="<?= h($k) ?>" <?= $_z['state'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col"><label class="visually-hidden" for="zn-<?= (int)$_z['id'] ?>">Notatka</label><input class="form-control form-control-sm" id="zn-<?= (int)$_z['id'] ?>" name="note" maxlength="500" placeholder="Notatka (widoczna dla uczestnika)" value="<?= h($_z['note']) ?>"></div>
      <div class="col-auto"><button class="btn btn-outline-primary btn-sm" name="act" value="decide">Zapisz stan</button></div>
      <div class="col-12 d-flex flex-wrap gap-2 align-items-center"><label class="visually-hidden" for="zf-<?= (int)$_z['id'] ?>">Skan: <?= h($_z['title']) ?></label>
        <input class="form-control form-control-sm w-auto" id="zf-<?= (int)$_z['id'] ?>" type="file" name="skan" accept=".pdf,.jpg,.jpeg,.png">
        <button class="btn btn-outline-secondary btn-sm" name="act" value="attach"><i class="bi bi-upload me-1"></i>Wgraj skan</button></div>
    </form>
    <?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>
</div>

<?php $_notes = TiSzkReports::notesFor($id); $_meName = (string)(current_user()['name'] ?? ''); ?>
<div class="card shadow-sm mb-3 no-print" id="szk-notes"><div class="card-header fw-semibold d-flex justify-content-between align-items-center">
  <span><i class="bi bi-sticky"></i> Notatki do umowy</span><a class="small" href="<?= APP_URL ?>/contracts/szk/zestawienia.php?v=notatki">Wszystkie notatki</a></div>
<?php if ($_notes): ?><ul class="list-group list-group-flush">
  <?php foreach ($_notes as $_n): ?><li class="list-group-item small d-flex justify-content-between gap-2"><div>
    <?= (int)$_n['pinned'] ? '<i class="bi bi-pin-angle-fill text-warning" title="przypięta"></i> ' : '' ?><?= nl2br(h($_n['body'])) ?>
    <div class="text-muted"><?= h($_n['author']) ?> · <?= h(date('d.m.Y H:i', strtotime($_n['created_at']))) ?></div></div>
    <?php if (can_edit() && ((string)$_n['author'] === $_meName || is_admin())): ?>
    <form method="post" action="<?= APP_URL ?>/contracts/szk/zestawienia.php" onsubmit="return confirm('Usunąć notatkę?')"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="act" value="note_del"><input type="hidden" name="note" value="<?= (int)$_n['id'] ?>"><input type="hidden" name="contract_id" value="<?= $id ?>"><input type="hidden" name="back" value="view">
      <button class="btn btn-sm btn-outline-danger" aria-label="Usuń notatkę"><i class="bi bi-trash3" aria-hidden="true"></i></button></form><?php endif; ?></li><?php endforeach; ?>
</ul><?php endif; ?>
<?php if (can_edit()): ?><form method="post" action="<?= APP_URL ?>/contracts/szk/zestawienia.php" class="card-body d-flex flex-wrap gap-2 align-items-end">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="act" value="note_add"><input type="hidden" name="contract_id" value="<?= $id ?>"><input type="hidden" name="back" value="view">
  <div class="flex-grow-1"><label class="form-label small mb-1" for="nv-b">Nowa notatka</label><textarea class="form-control form-control-sm" id="nv-b" name="body" rows="2" maxlength="4000" required></textarea></div>
  <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="nv-p" name="pinned" value="1"><label class="form-check-label small" for="nv-p">Przypnij</label></div>
  <button class="btn btn-outline-primary btn-sm mb-1">Dodaj</button></form>
<?php elseif (!$_notes): ?><div class="card-body text-muted small">Brak notatek.</div><?php endif; ?>
</div>

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
