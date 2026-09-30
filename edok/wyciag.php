<?php
/**
 * edok/wyciag.php — Transakcje z wyciągów bankowych (MT940 PKO BP): przypisywanie
 * do dokumentów EODoK. Wgrywanie: edok/mt940_import.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok_bank.php';

edok_require_access();
edok_migrate();
$can_edit = is_admin() || edok_has_role('upload') || edok_has_role('ksiegowy');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_edit) { http_response_code(403); exit('Brak uprawnień.'); }
    $action = $_POST['action'] ?? '';
    $tx_id  = (int)($_POST['tx_id'] ?? 0);
    $err = null;
    if ($action === 'assign') {
        $doc_id = (int)($_POST['doc_id'] ?? 0);
        if (!$doc_id && trim($_POST['doc_number'] ?? '') !== '') {
            $d = db_one("SELECT id FROM edok_documents WHERE UPPER(REPLACE(number,' ','')) = UPPER(REPLACE(?,' ',''))", [trim($_POST['doc_number'])]);
            $doc_id = (int)($d['id'] ?? 0);
            if (!$doc_id) $err = 'Nie znaleziono dokumentu o numerze „' . trim($_POST['doc_number']) . '”.';
        }
        if (!$err) $err = $doc_id ? edok_bank_assign($tx_id, $doc_id) : 'Wybierz dokument.';
        flash_set($err ? 'danger' : 'success', $err ?: 'Przypisano transakcję do dokumentu.');
    } elseif ($action === 'unassign') {
        $err = edok_bank_unassign($tx_id);
        flash_set($err ? 'danger' : 'success', $err ?: 'Odpięto transakcję.');
    } elseif ($action === 'ignore') {
        db_exec("UPDATE edok_bank_tx SET ignored=1, ignore_note=? WHERE id=? AND doc_id IS NULL", [trim($_POST['note'] ?? ''), $tx_id]);
        flash_set('success', 'Transakcja pominięta (nie wymaga dokumentu).');
    } elseif ($action === 'own') {
        // operacja, której nie da się rozpoznać automatycznie → ręcznie jako przelew własny (rejestr „Przelewy własne")
        $err = edok_bank_register_own_transfer($tx_id, trim((string)($_POST['counter_nrb'] ?? '')), trim((string)($_POST['reason'] ?? '')), (int)(current_user()['id'] ?? 0));
        flash_set($err ? 'danger' : 'success', $err ?: 'Zarejestrowano jako przelew własny.');
    } elseif ($action === 'unignore') {
        db_exec("UPDATE edok_bank_tx SET ignored=0, ignore_note='', transfer_id=NULL WHERE id=?", [$tx_id]);
    } elseif ($action === 'auto') {
        $pp = ['matched' => 0];
        try { require_once dirname(__DIR__) . '/modules/payment_portal/logic/paymentPortal.php'; $__u = current_user(); $pp = pp_bank_auto_match((string)($__u["name"] ?? "EODoK"), (int)($__u["id"] ?? 0) ?: null); }
        catch (\Throwable $e) { error_log('[platnosci] ' . $e->getMessage()); }
        flash_set('success', 'Dopasowano automatycznie: ' . edok_bank_auto_match() . '.' . ($pp['matched'] ? ' Płatności z portalu /platnosci: ' . $pp['matched'] . '.' : ''));
    }
    header('Location: ' . APP_URL . '/edok/wyciag.php?' . http_build_query(array_intersect_key($_GET, array_flip(['f', 'q', 'znak']))));
    exit;
}

$f    = $_GET['f'] ?? 'nieprzypisane';
$znak = in_array($_GET['znak'] ?? '', ['C', 'D'], true) ? $_GET['znak'] : '';
$q    = trim($_GET['q'] ?? '');
$where = []; $params = [];
if ($f === 'nieprzypisane')   $where[] = 'doc_id IS NULL AND ignored = 0';
elseif ($f === 'przypisane')  $where[] = 'doc_id IS NOT NULL';
elseif ($f === 'pominiete')   $where[] = 'ignored = 1';
if ($znak) { $where[] = 'znak = ?'; $params[] = $znak; }
if ($q !== '') { $where[] = '(tytul LIKE ? OR kontrahent_nazwa LIKE ? OR numer_operacji LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
$rows = db_all("SELECT b.*, d.number AS doc_number, d.kontrahent_nazwa AS doc_kontrahent FROM edok_bank_tx b LEFT JOIN edok_documents d ON d.id = b.doc_id"
    . ($where ? ' WHERE ' . implode(' AND ', array_map(fn($w) => preg_replace('/\b(doc_id|ignored|znak|tytul|kontrahent_nazwa|numer_operacji)\b/', 'b.$1', $w), $where)) : '')
    . " ORDER BY b.data_waluty DESC, b.id DESC LIMIT 300", $params);
$tab = fn($k, $l) => '<a class="nav-link' . ($f === $k ? ' active' : '') . '" href="?f=' . $k . '">' . $l . '</a>';

$PAGE_TITLE = 'Wyciągi bankowe — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-2">
    <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-bank2"></i> Wyciągi bankowe — transakcje</h4>
  </div>
  <div class="d-flex gap-2">
    <?php if ($can_edit): ?>
    <form method="post"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="auto">
      <button class="btn btn-outline-primary btn-sm"><i class="bi bi-magic"></i> Dopasuj automatycznie</button></form>
    <a href="<?= APP_URL ?>/edok/mt940_import.php" class="btn btn-primary btn-sm"><i class="bi bi-upload"></i> Wgraj wyciąg (MT940)</a>
    <?php endif; ?>
  </div>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><?= $tab('nieprzypisane', 'Do przypisania <span class="badge bg-warning text-dark">' . edok_bank_unassigned_count() . '</span>') ?></li>
  <li class="nav-item"><?= $tab('przypisane', 'Przypisane') ?></li>
  <li class="nav-item"><?= $tab('pominiete', 'Pominięte') ?></li>
  <li class="nav-item"><?= $tab('wszystkie', 'Wszystkie') ?></li>
</ul>
<form class="row g-2 mb-3" method="get">
  <input type="hidden" name="f" value="<?= h($f) ?>">
  <div class="col-auto"><select name="znak" class="form-select form-select-sm"><option value="">Wpływy i wypływy</option>
    <option value="C" <?= $znak === 'C' ? 'selected' : '' ?>>Wpływy</option><option value="D" <?= $znak === 'D' ? 'selected' : '' ?>>Wypływy</option></select></div>
  <div class="col-auto"><input type="search" name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="Tytuł / kontrahent / nr operacji"></div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filtruj</button></div>
</form>

<?php if (!$rows): ?><div class="alert alert-secondary">Brak transakcji w tym widoku.</div><?php else: ?>
<div class="table-responsive">
<table class="table table-sm align-middle">
  <thead class="table-light"><tr><th>Data</th><th class="text-end">Kwota</th><th>Kontrahent / tytuł</th><th style="min-width:340px">Dokument EODoK</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $t): $wplyw = $t['znak'] === 'C'; ?>
    <tr>
      <td class="text-nowrap small"><?= h(date_pl($t['data_waluty'])) ?></td>
      <td class="text-end font-monospace text-nowrap <?= $wplyw ? 'text-success' : '' ?>"><?= $wplyw ? '+' : '−' ?><?= h(number_format((float)$t['kwota'], 2, ',', ' ')) ?></td>
      <td class="small"><div class="fw-semibold"><?= h($t['kontrahent_nazwa'] ?: '—') ?></div><div class="text-muted"><?= h($t['tytul'] ?: '—') ?></div>
        <div class="text-muted font-monospace" style="font-size:.75rem">wyciąg <?= h($t['statement_no'] ?: '—') ?> · <?= h($t['numer_operacji']) ?></div></td>
      <td>
      <?php if ($t['doc_id']): ?>
        <a href="<?= APP_URL ?>/edok/view.php?id=<?= (int)$t['doc_id'] ?>" class="fw-semibold"><?= h($t['doc_number']) ?></a>
        <span class="small text-muted">— <?= h($t['doc_kontrahent']) ?> · <?= $t['matched_how'] === 'auto' ? 'automatycznie' : 'ręcznie (' . h($t['matched_by_name']) . ')' ?></span>
        <?php if ($can_edit): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('Odpiąć transakcję od dokumentu?');"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="unassign"><input type="hidden" name="tx_id" value="<?= (int)$t['id'] ?>">
          <button class="btn btn-sm btn-link text-danger p-0 ms-2">odepnij</button></form>
        <?php endif; ?>
      <?php elseif ($t['ignored']): ?>
        <span class="text-muted small">Pominięta<?= $t['ignore_note'] ? ': ' . h($t['ignore_note']) : '' ?></span>
        <?php if ($can_edit): ?><form method="post" class="d-inline"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="unignore"><input type="hidden" name="tx_id" value="<?= (int)$t['id'] ?>">
          <button class="btn btn-sm btn-link p-0 ms-2">przywróć</button></form><?php endif; ?>
      <?php elseif ($can_edit): $cands = edok_bank_candidates($t, 4); ?>
        <?php foreach ($cands as $c): ?>
        <form method="post" class="d-flex align-items-center gap-2 mb-1"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="assign"><input type="hidden" name="tx_id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="doc_id" value="<?= (int)$c['doc']['id'] ?>">
          <button class="btn btn-sm btn-outline-success py-0" title="Przypisz"><i class="bi bi-link-45deg"></i></button>
          <span class="small"><strong><?= h($c['doc']['number']) ?></strong> · <?= h($c['doc']['kontrahent_nazwa']) ?> · <?= h($c['doc']['kwota_brutto']) ?>
            <span class="text-muted">(<?= h(implode(', ', $c['reasons'])) ?>)</span></span>
        </form>
        <?php endforeach; ?>
        <form method="post" class="d-flex gap-1 mt-1"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="assign"><input type="hidden" name="tx_id" value="<?= (int)$t['id'] ?>">
          <input type="text" name="doc_number" class="form-control form-control-sm" placeholder="Nr EODoK, np. EODoK/0012/2026" style="max-width:220px">
          <button class="btn btn-sm btn-outline-primary">Przypisz</button>
        </form>
        <form method="post" class="d-flex gap-1 mt-1 flex-wrap"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="own"><input type="hidden" name="tx_id" value="<?= (int)$t['id'] ?>">
          <select name="counter_nrb" class="form-select form-select-sm" style="max-width:220px" required aria-label="Przelew własny — rachunek drugiej strony">
            <option value=""><?= $wplyw ? 'Przelew własny z rachunku…' : 'Przelew własny na rachunek…' ?></option>
            <?php foreach (edok_rachunki_list() as $r): if (_edok_nrb_digits((string)$r['nrb']) === _edok_nrb_digits((string)$t['account_nrb'])) continue; ?>
            <option value="<?= h($r['nrb']) ?>"><?= h(($r['nazwa'] ?: $r['bank']) . ' …' . substr(_edok_nrb_digits((string)$r['nrb']), -4)) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="reason" class="form-control form-control-sm" placeholder="Uzasadnienie (opcjonalnie)" style="max-width:200px">
          <button class="btn btn-sm btn-outline-info">Jako przelew własny</button>
        </form>
        <form method="post" class="d-flex gap-1 mt-1" onsubmit="return confirm('Pominąć tę transakcję (np. opłata bankowa)?');"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="ignore"><input type="hidden" name="tx_id" value="<?= (int)$t['id'] ?>">
          <input type="text" name="note" class="form-control form-control-sm" placeholder="Powód pominięcia" style="max-width:220px">
          <button class="btn btn-sm btn-outline-secondary">Pomiń</button>
        </form>
      <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
