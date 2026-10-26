<?php
/**
 * contracts/szk/rozliczenia.php — jeden prosty ekran rozliczeń umowy SZK: raty ze statusami, konto główne i subkonta (grupy),
 * wpłata uczestnika, rozliczenie z płatnikiem (PFRON/MOPS) i wypowiedzenie umowy (przez moduł rozwiązań).
 * Księga TI (includes/ti_payments.php) działa jak dotąd — ten ekran tylko ją czyta i do niej księguje.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
require_once dirname(dirname(__DIR__)) . '/includes/termination.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_training_contract/logic/trainingContractBilling.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('contract_szk', 'Ten typ umowy');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$c = $id ? TiTrainingContractStore::get($id) : null;
if (!$c || !contract_can_access('szk', $c['_row'])) { flash_set('error', 'Nie ma takiej umowy albo brak dostępu.'); header('Location: ' . APP_URL . '/contracts/szk/list.php'); exit; }
$row = $c['_row'];
$cu = current_user();
$uid = (int)($cu['id'] ?? 0);
$by = (string)($cu['name'] ?? $cu['email'] ?? '');
$self = APP_URL . '/contracts/szk/rozliczenia.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_edit()) { flash_set('error', 'Brak uprawnień do edycji umów.'); header('Location: ' . $self); exit; }
    $act = (string)($_POST['act'] ?? '');
    $err = '';
    $ok = 'Zapisano.';
    $num = fn(string $k) => (float)str_replace(',', '.', preg_replace('/[^\d,.]/', '', (string)($_POST[$k] ?? '')));
    if ($act === 'sync' || $act === 'clear') {
        $r = TiSzkBilling::sync($id, $act === 'clear');
        $err = $r['error'];
        $ok = ($act === 'clear' ? 'Wycofano należności' : 'Zsynchronizowano należności') . ' — operacji w księdze: ' . (int)$r['posted'] . '.';
        if ($err === '') log_contract_action('szk', $id, $uid, 'update', $ok);
    } elseif ($act === 'pay') {
        $err = TiSzkBilling::pay($id, $num('kwota'), (string)($_POST['data'] ?? ''), (string)($_POST['metoda'] ?? 'transfer'), (int)($_POST['konto'] ?? 0), (string)($_POST['notatka'] ?? ''));
        $ok = 'Zaksięgowano wpłatę uczestnika.';
        if ($err === '') log_contract_action('szk', $id, $uid, 'update', 'Wpłata uczestnika: ' . number_format($num('kwota'), 2, ',', ' ') . ' zł');
    } elseif ($act === 'transfer') {
        $err = TiSzkBilling::transferCredit($id, (int)($_POST['lp'] ?? 0), (int)($_POST['z_subkonta'] ?? 0), $num('kwota'), $by);
        $ok = 'Przeniesiono nadpłatę z subkonta na ratę.';
        if ($err === '') log_contract_action('szk', $id, $uid, 'update', 'Przeniesienie nadpłaty z subkonta na ratę ' . (int)($_POST['lp'] ?? 0) . ': ' . number_format($num('kwota'), 2, ',', ' ') . ' zł');
    } elseif ($act === 'payer_add') {
        $err = TiSzkBilling::payerAdd($id, $num('kwota'), (string)($_POST['data'] ?? ''), (string)($_POST['notatka'] ?? ''), $by);
        $ok = 'Zapisano wpłatę płatnika.';
        if ($err === '') log_contract_action('szk', $id, $uid, 'update', 'Wpłata płatnika: ' . number_format($num('kwota'), 2, ',', ' ') . ' zł');
    } elseif ($act === 'payer_del') {
        TiSzkBilling::payerDelete((int)($_POST['entry'] ?? 0), $id);
        $ok = 'Usunięto wpis płatnika.';
    } elseif ($act === 'terminate') {
        $status = (string)$row['status'];
        if (!in_array($status, contractStatusesInGroup('active'), true) && !in_array($status, TERMINABLE_STATUSES, true)) $err = 'Wypowiedzieć można umowę podpisaną i obowiązującą (status w grupie „aktywna”).';
        elseif (get_pending_termination_for_contract('szk', $id)) $err = 'Wniosek o rozwiązanie tej umowy już czeka na decyzję.';
        elseif (!isset(TERMINATION_VARIANTS[$_POST['wariant'] ?? ''])) $err = 'Wybierz tryb rozwiązania.';
        elseif (trim((string)($_POST['powod'] ?? '')) === '') $err = 'Podaj powód wypowiedzenia.';
        else {
            $base = (string)($_POST['data'] ?? '');
            if ($base !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $base)) $err = 'Nieprawidłowa data.';
            else {
                $eff = termination_compute_effective_date((string)$_POST['wariant'], $base !== '' ? $base : null);
                create_termination_request('szk', $id, $uid ?: null, $by, trim((string)$_POST['powod']), $eff, (string)($_POST['inicjator'] ?? ''), (string)$_POST['wariant']);
                db_update(TiTrainingContractStore::TABLE, ['data_wypowiedzenia' => $base !== '' ? $base : date('Y-m-d'), 'powod_wypowiedzenia' => trim((string)$_POST['powod'])], $id);
                log_contract_action('szk', $id, $uid, 'termination_requested', 'Złożono wniosek o rozwiązanie umowy (skutek od ' . $eff . ').');
                $ok = 'Złożono wniosek o rozwiązanie — decyzja w Rozwiązaniach umów; po akceptacji raty po dacie skutku znikną z rozliczeń.';
            }
        }
    }
    $err !== '' ? flash_set('error', $err) : flash_set('success', $ok);
    header('Location: ' . $self); exit;
}

$inst = TiSzkBilling::installments($c);
$accounts = TiSzkBilling::accounts((int)$row['client_id']);
$payer = TiSzkBilling::payerBalance($c);
$payerRows = TiSzkBilling::payerEntries($id);
$terms = get_termination_requests('szk', $id);
$pending = get_pending_termination_for_contract('szk', $id);
$sumDue = array_sum(array_column($inst, 'kwota'));
$sumPaid = array_sum(array_column($inst, 'wplacono'));
$zl = fn($v) => number_format((float)$v, 2, ',', ' ') . ' zł';
$groupId = (int)($row['grupa_id'] ?? 0);
$platnikName = (string)($c['platnik'] ?: 'płatnik');   // header.php nadpisuje krótkie zmienne ($m, $c…) — wartości liczymy przed jego dołączeniem
$PAGE_TITLE = 'Rozliczenia umowy ' . ($row['numer_umowy'] ?: 'SZK');
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<p class="mb-2"><a href="view.php?id=<?= $id ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Karta umowy</a></p>
<h4 class="mb-1"><i class="bi bi-receipt-cutoff text-primary"></i> Rozliczenia umowy <span class="font-monospace"><?= h($row['numer_umowy'] ?: 'projekt') ?></span></h4>
<p class="text-muted mb-3"><?= h($row['imie_nazwisko']) ?> · <?= h($row['temat']) ?> · <?= contractStatusBadge($row['status']) ?></p>
<?php if ((int)$row['client_id'] <= 0): ?><div class="alert alert-warning">Umowa nie jest powiązana z uczestnikiem z karty TI — wybierz uczestnika w formularzu umowy, aby księgować należności i wpłaty.</div><?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="detail-label">Do zapłaty przez uczestnika</div><div class="fs-5 fw-bold"><?= $zl($sumDue) ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="detail-label">Wpłacono na raty</div><div class="fs-5 fw-bold text-success"><?= $zl($sumPaid) ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="detail-label">Pozostało</div><div class="fs-5 fw-bold <?= $sumDue - $sumPaid > 0.004 ? 'text-danger' : '' ?>"><?= $zl(max(0, $sumDue - $sumPaid)) ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="detail-label">Płatnik: pozostało</div><div class="fs-5 fw-bold"><?= $payer['due'] > 0 ? $zl($payer['left']) : '—' ?></div></div></div></div>
</div>

<div class="card shadow-sm mb-3"><div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2"><span>Raty uczestnika</span>
  <?php if (can_edit() && $inst && (int)$row['is_test'] === 0): ?>
  <form method="post" class="d-flex gap-2 no-print"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn btn-outline-primary btn-sm" name="act" value="sync"><i class="bi bi-arrow-repeat me-1"></i>Zaksięguj / zsynchronizuj</button>
    <?php if (TiSzkBilling::posted($id)): ?><button class="btn btn-outline-danger btn-sm" name="act" value="clear" onclick="return confirm('Wycofać zaksięgowane należności tej umowy?')">Wycofaj</button><?php endif; ?></form><?php endif; ?></div>
<?php if (!$inst): ?><div class="card-body text-muted small">Brak rat — uzupełnij wynagrodzenie / kwotę uczestnika w umowie albo rata wypadła po dacie rozwiązania.</div>
<?php else: ?><div class="table-responsive"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Raty umowy</caption>
  <thead><tr><th scope="col">Lp.</th><th scope="col">Termin</th><th scope="col" class="text-end">Kwota</th><th scope="col" class="text-end">Wpłacono</th><th scope="col">Status</th></tr></thead><tbody>
  <?php foreach ($inst as $r): [$lab, $cls] = TiSzkBilling::STATUS_LABELS[$r['status']]; ?>
  <tr><td><?= (int)$r['lp'] ?></td><td><?= h(date('d.m.Y', strtotime($r['termin']))) ?></td><td class="text-end"><?= $zl($r['kwota']) ?></td><td class="text-end"><?= $zl($r['wplacono']) ?></td><td><span class="badge <?= h($cls) ?>"><?= h($lab) ?></span></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?></div>

<?php if ($accounts): ?>
<div class="card shadow-sm mb-3"><div class="card-header fw-semibold">Konta uczestnika (konto główne i subkonta)</div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Konto główne i subkonta grup</caption>
  <thead><tr><th scope="col">Konto</th><th scope="col" class="text-end">Należności</th><th scope="col" class="text-end">Wpłaty</th><th scope="col" class="text-end">Nadpłata</th><th scope="col" class="text-end">Niedopłata</th></tr></thead><tbody>
  <tr class="table-light"><td><strong>Konto główne</strong> <span class="text-muted small">(nadpłata ogólna do wykorzystania w dowolnej grupie)</span></td><td class="text-end">—</td><td class="text-end">—</td><td class="text-end"><?= $zl($accounts['general_credit']) ?></td><td class="text-end">—</td></tr>
  <?php foreach ($accounts['groups'] as $g): ?>
  <tr class="<?= (int)$g['course_id'] === $groupId && $groupId > 0 ? 'table-primary' : '' ?>"><td><?= h($g['course_name']) ?><?= (int)$g['course_id'] === $groupId && $groupId > 0 ? ' <span class="badge text-bg-primary">grupa umowy</span>' : '' ?></td>
    <td class="text-end"><?= $zl($g['charges']) ?></td><td class="text-end"><?= $zl($g['payments']) ?></td><td class="text-end <?= $g['credit'] > 0 ? 'text-success' : '' ?>"><?= $zl($g['credit']) ?></td><td class="text-end <?= $g['debt'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= $zl($g['debt']) ?></td></tr>
  <?php endforeach; ?>
  <tr class="fw-bold"><td>Razem</td><td class="text-end"><?= $zl($accounts['total']['charges']) ?></td><td class="text-end"><?= $zl($accounts['total']['payments']) ?></td><td class="text-end"><?= $zl($accounts['total']['credit']) ?></td><td class="text-end"><?= $zl($accounts['total']['debt']) ?></td></tr>
  </tbody></table></div></div>
<?php endif; ?>

<?php if (can_edit() && (int)$row['client_id'] > 0): ?>
<div class="card shadow-sm mb-3 no-print"><div class="card-header fw-semibold">Zaksięguj wpłatę uczestnika</div><div class="card-body">
<form method="post" class="row g-2 align-items-end"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="pay">
  <div class="col-6 col-md-2"><label class="form-label small mb-1" for="p-k">Kwota (zł)</label><input class="form-control form-control-sm" id="p-k" name="kwota" inputmode="decimal" required></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-1" for="p-d">Data wpłaty</label><input class="form-control form-control-sm" id="p-d" type="date" name="data" value="<?= h(date('Y-m-d')) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-1" for="p-m">Sposób</label><select class="form-select form-select-sm" id="p-m" name="metoda"><option value="transfer">Przelew</option><option value="cash">Gotówka</option><option value="other">Inny</option></select></div>
  <div class="col-6 col-md-3"><label class="form-label small mb-1" for="p-c">Konto</label><select class="form-select form-select-sm" id="p-c" name="konto"><option value="0">Konto główne (ogólne)</option>
    <?php foreach (($accounts['groups'] ?? []) as $g): if ((int)$g['course_id'] <= 0) continue; ?><option value="<?= (int)$g['course_id'] ?>" <?= (int)$g['course_id'] === $groupId ? 'selected' : '' ?>>Subkonto: <?= h($g['course_name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-12 col-md-3"><label class="form-label small mb-1" for="p-n">Notatka</label><input class="form-control form-control-sm" id="p-n" name="notatka" maxlength="200"></div>
  <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-cash-coin me-1"></i>Zaksięguj wpłatę</button></div>
</form></div></div>
<?php endif; ?>

<?php
$creditAccts = array_filter($accounts['groups'] ?? [], fn($g) => (int)$g['course_id'] > 0 && (float)$g['credit'] > 0.005);
$openInst = array_filter($inst, fn($r) => $r['zaksiegowano'] && $r['status'] !== 'zaplacona');
if (can_edit() && $creditAccts && $openInst): ?>
<div class="card shadow-sm mb-3 no-print"><div class="card-header fw-semibold">Przenieś nadpłatę z subkonta na ratę</div><div class="card-body">
<form method="post" class="row g-2 align-items-end"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="transfer">
  <div class="col-md-4"><label class="form-label small mb-1" for="t-s">Z subkonta</label><select class="form-select form-select-sm" id="t-s" name="z_subkonta"><?php foreach ($creditAccts as $g): ?><option value="<?= (int)$g['course_id'] ?>"><?= h($g['course_name']) ?> — nadpłata <?= $zl($g['credit']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label small mb-1" for="t-l">Na ratę</label><select class="form-select form-select-sm" id="t-l" name="lp"><?php foreach ($openInst as $r): ?><option value="<?= (int)$r['lp'] ?>">Rata <?= (int)$r['lp'] ?> · <?= h(date('d.m.Y', strtotime($r['termin']))) ?> · <?= $zl($r['kwota'] - $r['wplacono']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-1" for="t-k">Kwota (zł)</label><input class="form-control form-control-sm" id="t-k" name="kwota" inputmode="decimal" required></div>
  <div class="col-md-3"><button class="btn btn-outline-primary btn-sm w-100">Przenieś nadpłatę</button></div>
</form><p class="small text-muted mt-2 mb-0">Wpisy przeniesienia trafiają do księgi rozliczeń TI (ten sam mechanizm co przy grupach).</p></div></div>
<?php endif; ?>

<?php if ($payer['due'] > 0): ?>
<div class="card shadow-sm mb-3"><div class="card-header fw-semibold">Rozliczenie z płatnikiem — <?= h($platnikName) ?></div><div class="card-body">
  <div class="row g-3 mb-2"><div class="col-4"><div class="detail-label">Należność płatnika</div><div class="fw-bold"><?= $zl($payer['due']) ?></div></div>
    <div class="col-4"><div class="detail-label">Wpłacono</div><div class="fw-bold text-success"><?= $zl($payer['paid']) ?></div></div>
    <div class="col-4"><div class="detail-label">Pozostało</div><div class="fw-bold <?= $payer['left'] > 0.004 ? 'text-danger' : '' ?>"><?= $zl($payer['left']) ?></div></div></div>
  <?php if ($payerRows): ?><ul class="list-group list-group-flush mb-2"><?php foreach ($payerRows as $e): ?>
    <li class="list-group-item d-flex justify-content-between align-items-center small"><span><?= h(date('d.m.Y', strtotime($e['paid_at']))) ?> · <strong><?= $zl($e['amount']) ?></strong><?= $e['note'] !== '' ? ' · ' . h($e['note']) : '' ?> <span class="text-muted">(<?= h($e['created_by']) ?>)</span></span>
      <?php if (can_edit()): ?><form method="post" onsubmit="return confirm('Usunąć wpis?')"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="payer_del"><input type="hidden" name="entry" value="<?= (int)$e['id'] ?>"><button class="btn btn-sm btn-outline-danger" aria-label="Usuń wpis"><i class="bi bi-trash3" aria-hidden="true"></i></button></form><?php endif; ?></li>
  <?php endforeach; ?></ul><?php endif; ?>
  <?php if (can_edit()): ?><form method="post" class="row g-2 align-items-end no-print"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="payer_add">
    <div class="col-6 col-md-3"><label class="form-label small mb-1" for="y-k">Kwota (zł)</label><input class="form-control form-control-sm" id="y-k" name="kwota" inputmode="decimal" required></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1" for="y-d">Data wpłaty</label><input class="form-control form-control-sm" id="y-d" type="date" name="data" value="<?= h(date('Y-m-d')) ?>" required></div>
    <div class="col-12 col-md-4"><label class="form-label small mb-1" for="y-n">Notatka (nr przelewu)</label><input class="form-control form-control-sm" id="y-n" name="notatka" maxlength="200"></div>
    <div class="col-12 col-md-2"><button class="btn btn-outline-primary btn-sm w-100">Dodaj wpłatę płatnika</button></div></form><?php endif; ?>
</div></div>
<?php endif; ?>

<div class="card shadow-sm mb-3"><div class="card-header fw-semibold">Wypowiedzenie umowy</div><div class="card-body">
<?php foreach ($terms as $t): ?><div class="small mb-2"><?= termination_status_badge($t['status']) ?> złożono <?= h(date('d.m.Y', strtotime($t['created_at']))) ?> · skutek od <?= h($t['effective_date'] ?: $t['proposed_date'] ?: '—') ?> · <?= h($t['powod']) ?></div><?php endforeach; ?>
<?php if ($pending): ?><p class="small text-muted mb-0">Wniosek czeka na decyzję w <a href="<?= APP_URL ?>/admin/terminations.php">Rozwiązaniach umów</a>. Po akceptacji raty z terminem po dacie skutku znikną z rozliczeń, a nadpłata zostanie na koncie.</p>
<?php elseif (can_edit() && (in_array($row['status'], contractStatusesInGroup('active'), true) || in_array($row['status'], TERMINABLE_STATUSES, true))): ?>
<form method="post" class="row g-2 align-items-end no-print"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="terminate">
  <div class="col-md-3"><label class="form-label small mb-1" for="w-i">Strona wypowiadająca</label><select class="form-select form-select-sm" id="w-i" name="inicjator"><?php foreach (TERMINATION_INITIATORS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label small mb-1" for="w-w">Tryb</label><select class="form-select form-select-sm" id="w-w" name="wariant"><?php foreach (TERMINATION_VARIANTS as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v['label']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-1" for="w-d">Data złożenia</label><input class="form-control form-control-sm" id="w-d" type="date" name="data" value="<?= h(date('Y-m-d')) ?>"></div>
  <div class="col-12"><label class="form-label small mb-1" for="w-p">Powód</label><textarea class="form-control form-control-sm" id="w-p" name="powod" rows="2" required></textarea></div>
  <div class="col-12"><button class="btn btn-outline-danger btn-sm" onclick="return confirm('Złożyć wniosek o rozwiązanie umowy?')"><i class="bi bi-file-earmark-x me-1"></i>Złóż wniosek o rozwiązanie</button></div></form>
<?php elseif (!$terms): ?><p class="small text-muted mb-0">Wypowiedzieć można umowę podpisaną i obowiązującą.</p><?php endif; ?>
</div></div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
