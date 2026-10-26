<?php
/**
 * contracts/szk/edit.php — formularz umowy szkoleniowej SZK w Menu Umowy (nowa i edycja), DOCX, rejestracja z numerem RU,
 * usuwanie projektu/umowy testowej. Formularz i treść wzoru: modules/ti_training_contract (view/form.php, logic/).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_training_contract/logic/trainingContractStore.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_training_contract/logic/trainingContractBilling.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('contract_szk', 'Ten typ umowy');
TiTrainingContractStore::migrate();
$TYPE = 'szk';

$cu = current_user();
$uid = (int)($cu['id'] ?? 0);
$myName = (string)($cu['name'] ?? $cu['full_name'] ?? $cu['email'] ?? '');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$err = [];
$back = function (string $qs) use (&$id): void {
    header('Location: ' . APP_URL . '/contracts/szk/' . ($qs === 'list' ? 'list.php' : 'edit.php' . ($qs !== '' ? '?' . $qs : '')));
    exit;
};
$sendDocx = function (array $d): void {
    $bytes = TiTrainingContract::docx($d);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . TiTrainingContract::filename($d) . '"');
    header('Cache-Control: no-store, private');
    echo $bytes;
    exit;
};

$cur = $id ? TiTrainingContractStore::get($id) : null;
if ($id && (!$cur || !contract_can_access($TYPE, $cur['_row']))) { flash_set('error', 'Nie ma takiej umowy albo brak dostępu.'); $back('list'); }

if (isset($_GET['docx']) && $cur) $sendDocx($cur);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_edit()) { flash_set('error', 'Brak uprawnień do edycji umów.'); $back('list'); }
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'status' && $cur) {
        $new = (string)($_POST['status'] ?? '');
        if (TiTrainingContractStore::setStatus($id, $new)) {
            TiSzkBilling::onStatusChange($id, $new);
            log_contract_action($TYPE, $id, $uid, 'status_change', 'Zmiana statusu: ' . $cur['_row']['status'] . ' → ' . $new);
            flash_set('success', 'Zmieniono status.');
        }
        $back('id=' . $id);
    }
    if ($act === 'delete' && $cur) {
        if (TiTrainingContractStore::delete($id)) { flash_set('success', 'Usunięto umowę.'); $back('list'); }
        flash_set('error', 'Zarejestrowanej umowy nie można usunąć (jest w Rejestrze Umów) — zmień status na „anulowana”.');
        $back('id=' . $id);
    }
    if (in_array($act, ['save', 'register', 'preview'], true)) {
        [$d, $err] = TiTrainingContract::normalize($_POST);
        $d['nr'] = $cur['nr'] ?? '';
        if ($act === 'preview' && !$err) $sendDocx($d);
        if ($cur && TiTrainingContractStore::isSigned($cur['_row']['status'])) $err[] = 'Umowa jest już podpisana — treści nie można zmieniać.';
        if ($act === 'register' && trim($d['imie_nazwisko']) === '') $err[] = 'Do rejestracji potrzebne jest imię i nazwisko.';
        if ($act === 'register' && trim($d['data']) === '') $err[] = 'Do rejestracji potrzebna jest data zawarcia.';
        if (!$err) {
            $isNew = $id === 0;
            $id = TiTrainingContractStore::save($id, $d, $myName, $uid);
            if ($isNew) log_contract_action($TYPE, $id, $uid, 'create', 'Utworzono umowę szkoleniową (projekt).');
            if ($act === 'register') {
                try {
                    [$nr, $ru] = TiTrainingContractStore::register($id);
                    log_contract_action($TYPE, $id, $uid, 'register', "Zarejestrowano: {$nr} / {$ru}");
                    flash_set('success', 'Umowa zarejestrowana — numer ' . $nr . ', Rejestr Umów ' . $ru . '.');
                    $back('id=' . $id);
                } catch (\Throwable $e) { $err[] = 'Nie udało się nadać numeru: ' . $e->getMessage(); }
            } else { flash_set('success', 'Zapisano umowę.'); $back('id=' . $id); }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($d)) $data = $d + ['client_id' => 0];
elseif ($cur) $data = $cur;
else {
    $data = in_array($_GET['przyklad'] ?? '', ['K', 'M'], true) ? TiTrainingContract::sample($_GET['przyklad']) : TiTrainingContract::defaults();
    if ((int)($_GET['client'] ?? 0) > 0) $data = array_merge($data, TiTrainingContractStore::fromClient((int)$_GET['client']));
    $data['nr'] = '';
}
$row = $cur['_row'] ?? null;
$locked = $row && TiTrainingContractStore::isSigned($row['status']);
$clients = $id ? [] : TiTrainingContractStore::clients();

$SZK_URL   = APP_URL . '/contracts/szk/edit.php';
$SZK_LIST  = APP_URL . '/contracts/szk/list.php';
$SZK_TOKEN = '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
$PAGE_TITLE = $id ? 'Umowa SZK ' . ($row['numer_umowy'] ?: '(projekt)') : 'Nowa umowa szkoleniowa';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<?php if ($err): ?><div class="alert alert-danger" role="alert"><?php foreach ($err as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if (!$id && $clients): ?>
<form method="get" class="d-flex flex-wrap align-items-end gap-2 mb-3">
  <div><label class="form-label small mb-1" for="u-client">Wypełnij danymi uczestnika TI</label>
    <select class="form-select form-select-sm" id="u-client" name="client" style="min-width:16rem">
      <?php foreach ($clients as $_c): ?><option value="<?= (int)$_c['id'] ?>" <?= (int)($_GET['client'] ?? 0) === (int)$_c['id'] ? 'selected' : '' ?>><?= h($_c['name']) ?></option><?php endforeach; ?>
    </select></div>
  <button class="btn btn-outline-primary btn-sm">Wczytaj dane</button>
  <a class="btn btn-link btn-sm" href="?przyklad=K">Dane przykładowe (Pani)</a><a class="btn btn-link btn-sm" href="?przyklad=M">(Pan)</a>
</form>
<?php endif; ?>
<?php include dirname(dirname(__DIR__)) . '/modules/ti_training_contract/view/form.php'; ?>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
