<?php
/**
 * karty30/ti/dydaktyk/umowaSzkolenia.php — „Umowy o świadczenie usług szkoleniowych” (TI/UMSZ/NNN/RRRR):
 * lista, generowanie z formularza (dane z karty uczestnika), DOCX, rejestracja z numerem w Rejestrze Umów (RU),
 * statusy, umowy przykładowe (testowe) i ich czyszczenie. Dostęp: kierownik (dyd_is_staff).
 * Logika i treść wzoru: modules/ti_training_contract (logic/, view/).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_training_contract/logic/trainingContractStore.php';
$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
TiTrainingContractStore::migrate();

$uid = (int)($me['user_id'] ?? $me['id'] ?? 0);
$myName = (string)($me['name'] ?? '');
$back = function (string $qs): void {
    header('Location: umowaSzkolenia.php' . ($qs !== '' ? '?' . $qs : ''));
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

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$err = [];

if (isset($_GET['docx']) && $id) {
    $c = TiTrainingContractStore::get($id);
    if (!$c) $back('err=brak');
    $sendDocx($c);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'seed') {
        TiTrainingContractStore::seed($myName, $uid);
        $back('ok=seed');
    }
    if ($act === 'purge') {
        $n = TiTrainingContractStore::purgeTest();
        $back('ok=purge&n=' . $n);
    }
    if ($act === 'status' && $id) {
        TiTrainingContractStore::setStatus($id, (string)($_POST['status'] ?? ''));
        $back('id=' . $id . '&ok=status');
    }
    if ($act === 'delete' && $id) {
        $back(TiTrainingContractStore::delete($id) ? 'ok=deleted' : 'id=' . $id . '&err=nodelete');
    }
    if (in_array($act, ['save', 'register', 'preview'], true)) {
        $cur = $id ? TiTrainingContractStore::get($id) : null;
        if ($id && !$cur) $back('err=brak');
        [$d, $err] = TiTrainingContract::normalize($_POST);
        $d['nr'] = $cur['nr'] ?? '';
        if ($act === 'preview' && !$err) $sendDocx($d);
        if ($cur && TiTrainingContractStore::isSigned($cur['_row']['status'])) $err[] = 'Umowa jest już podpisana — treści nie można zmieniać.';
        if ($act === 'register' && trim($d['imie_nazwisko']) === '') $err[] = 'Do rejestracji potrzebne jest imię i nazwisko.';
        if ($act === 'register' && trim($d['data']) === '') $err[] = 'Do rejestracji potrzebna jest data zawarcia.';
        if (!$err) {
            $id = TiTrainingContractStore::save($id, $d, $myName, $uid);
            if ($act === 'register') {
                try { TiTrainingContractStore::register($id); $back('id=' . $id . '&ok=registered'); }
                catch (\Throwable $e) { $err[] = 'Nie udało się nadać numeru: ' . $e->getMessage(); }
            } else $back('id=' . $id . '&ok=saved');
        }
    }
}

$MSG = [
    'saved' => 'Zapisano umowę.', 'registered' => 'Umowa zarejestrowana — nadano numer umowy i numer w Rejestrze Umów (RU).',
    'status' => 'Zmieniono status.', 'deleted' => 'Usunięto umowę.', 'seed' => 'Dodano przykładowe umowy testowe.',
    'purge' => 'Usunięto umowy testowe: ' . (int)($_GET['n'] ?? 0) . '.',
];
$ERR = ['brak' => 'Nie ma takiej umowy.', 'nodelete' => 'Zarejestrowanej umowy nie można usunąć (jest w Rejestrze Umów) — zmień status na „anulowana”.'];
$flash = $MSG[$_GET['ok'] ?? ''] ?? '';
if (isset($ERR[$_GET['err'] ?? ''])) $err[] = $ERR[$_GET['err']];

$mode = ($id || isset($_GET['nowa']) || ($err && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') !== 'delete')) ? 'form' : 'list';
if ($mode === 'form') {
    $c = $id ? TiTrainingContractStore::get($id) : null;
    if ($id && !$c) { $mode = 'list'; $err[] = $ERR['brak']; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($d)) $data = $d + ['client_id' => 0];     // po błędzie — to, co wpisano
    elseif ($c) $data = $c;
    else {
        $data = in_array($_GET['przyklad'] ?? '', ['K', 'M'], true) ? TiTrainingContract::sample($_GET['przyklad']) : TiTrainingContract::defaults();
        if ((int)($_GET['client'] ?? 0) > 0) $data = array_merge($data, TiTrainingContractStore::fromClient((int)$_GET['client']));
        $data['nr'] = '';
    }
    $row = $c['_row'] ?? null;
    $locked = $row && TiTrainingContractStore::isSigned($row['status']);
}

$KP_TITLE = 'Umowy szkoleniowe — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $myName, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php $KIER_CUR = 'umowaSzkolenia.php'; $KIER_LABEL = 'Umowy szkoleniowe'; include __DIR__ . '/_kierownik_bar.php'; ?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1000px">
<?php if ($flash): ?><div class="alert alert-success" role="status"><?= h($flash) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger" role="alert"><?php foreach ($err as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php include dirname(__DIR__, 3) . '/modules/ti_training_contract/view/' . ($mode === 'form' ? 'form.php' : 'list.php'); ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
