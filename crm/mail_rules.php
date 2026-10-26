<?php
/**
 * crm/mail_rules.php — reguły automatyczne Skrzynki CRM (administrator): warunki → akcje (kolejka, tag, opiekun, obserwujący).
 * Logika: includes/crm_mail_rules.php. Reguły obejmują NOWE wiadomości (cron co 5 min); historię obejmuje przycisk „Zastosuj do historii”.
 * Panel „Sprawdź regułę na wiadomości” pokazuje, co by się stało, bez zmieniania czegokolwiek.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/crm_mailbox.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!is_admin()) { http_response_code(403); die('Reguły automatyczne ustawia administrator.'); }
crm_migrate();
$ready = crm_mailbox_ready();
$PAGE_TITLE = 'Reguły automatyczne Skrzynki';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    try {
        if ($op === 'save') {
            $conds = [];
            foreach ((array)($_POST['cond'] ?? []) as $c) {
                [$f, $o] = array_pad(explode(':', (string)($c['fo'] ?? ''), 2), 2, '');
                $v = $f === 'mailbox' ? ($c['mailbox'] ?? '') : ($f === 'attachment' ? ($c['att'] ?? 'yes') : ($c['value'] ?? ''));
                $conds[] = ['field' => $f, 'op' => $o, 'value' => $v];
            }
            $acts = [];
            foreach ((array)($_POST['act'] ?? []) as $a) $acts[] = ['type' => $a['type'] ?? '', 'tag' => $a['tag'] ?? '', 'queue_id' => $a['queue_id'] ?? 0, 'user_id' => $a['user_id'] ?? 0];
            $id = (int)($_POST['id'] ?? 0) ?: null;
            crm_mr_save(['name' => $_POST['name'] ?? '', 'match_mode' => $_POST['match_mode'] ?? 'all', 'stop_on_match' => $_POST['stop_on_match'] ?? 0, 'sort_order' => $_POST['sort_order'] ?? 50,
                         'is_active' => $id ? ($_POST['is_active'] ?? 0) : 1, 'conditions' => $conds, 'actions' => $acts], $id);
            flash_set('success', $id ? 'Reguła zapisana.' : 'Reguła dodana — obejmie nowe wiadomości (do 5 minut).');
        } elseif ($op === 'toggle') {
            db()->prepare("UPDATE crm_mail_rules SET is_active = 1 - is_active WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
            flash_set('success', 'Stan reguły zmieniony.');
        } elseif ($op === 'delete') {
            crm_mr_delete((int)($_POST['id'] ?? 0));
            flash_set('success', 'Reguła usunięta (zmiany już wykonane na wiadomościach zostają).');
        } elseif ($op === 'move') {
            $rules = crm_mr_list(false); $ids = array_map(fn($r) => (int)$r['id'], $rules); $id = (int)($_POST['id'] ?? 0); $i = array_search($id, $ids, true);
            $j = $i === false ? false : $i + (($_POST['dir'] ?? '') === 'up' ? -1 : 1);
            if ($i !== false && $j >= 0 && $j < count($ids)) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; foreach ($ids as $k => $rid) db()->prepare("UPDATE crm_mail_rules SET sort_order = ? WHERE id = ?")->execute([($k + 1) * 10, $rid]); }
            flash_set('success', 'Kolejność zmieniona.');
        } elseif ($op === 'apply') {
            $r = crm_mr_apply_existing((int)($_POST['id'] ?? 0));
            flash_set('success', 'Zastosowano do historii: sprawdzono ' . $r['checked'] . ' ostatnich wiadomości, dopasowano ' . $r['matched'] . '.');
        } elseif ($op === 'run') {
            $r = crm_mr_run();
            flash_set($r['errors'] ? 'warning' : 'success', 'Przebieg reguł: sprawdzono ' . $r['checked'] . ', dopasowano ' . $r['matched'] . ', akcji ' . $r['actions'] . ($r['errors'] ? '. Błędy: ' . implode(' | ', array_slice($r['errors'], 0, 3)) : '.'));
        }
    } catch (\Throwable $e) {
        flash_set('danger', $e->getMessage());
    }
    header('Location: ' . APP_URL . '/crm/mail_rules.php' . (!empty($_POST['id']) && $op === 'save' ? '' : '')); exit;
}

$rules = $ready ? crm_mr_list(false) : [];
$boxes = $ready ? db_all("SELECT id, mailbox FROM poczta_mailboxes ORDER BY mailbox") : [];
$queues = $ready ? crm_mq_list() : [];
$users = $ready ? db_all("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name") : [];
$boxN = array_column($boxes, 'mailbox', 'id'); $queN = array_column($queues, 'name', 'id'); $usrN = array_column($users, 'name', 'id');
$matchCount = [];
if ($ready) foreach (db_all("SELECT rule_id, COUNT(*) AS n FROM crm_mail_rule_log GROUP BY rule_id") as $r) $matchCount[(int)$r['rule_id']] = (int)$r['n'];
$edit = null;
if (isset($_GET['edit'])) foreach ($rules as $r) if ((int)$r['id'] === (int)$_GET['edit']) $edit = $r;
$since = org_setting('crm_rules_since_id');

// Test na wiadomości (bez zmian): ?test=ID
$test = null; $testMsg = null;
if ($ready && isset($_GET['test']) && (int)$_GET['test'] > 0) {
    $testMsg = db_one("SELECT * FROM crm_communications WHERE id = ? AND direction = 'in'", [(int)$_GET['test']]);
    if ($testMsg) $test = crm_mr_dry_run($testMsg);
}
$log = $ready ? db_all("SELECT l.*, r.name AS rname, c.subject FROM crm_mail_rule_log l LEFT JOIN crm_mail_rules r ON r.id = l.rule_id LEFT JOIN crm_communications c ON c.id = l.comm_id ORDER BY l.id DESC LIMIT 20") : [];

$condLabel = function (array $c) use ($boxN) {
    $f = CRM_MR_FIELDS[$c['field']] ?? $c['field']; $o = CRM_MR_OPS[$c['field']][$c['op']] ?? $c['op'];
    $v = $c['field'] === 'mailbox' ? ($boxN[(int)$c['value']] ?? ('#' . $c['value'])) : ($c['field'] === 'attachment' ? ($c['value'] === 'yes' ? 'tak' : 'nie') : '„' . $c['value'] . '”');
    return $f . ' ' . $o . ' ' . $v;
};
$actLabel = function (array $a) use ($queN, $usrN) {
    return match ($a['type']) {
        'queue' => 'kolejka: ' . ($queN[(int)$a['value']] ?? ('#' . $a['value'])), 'tag' => 'tag: ' . $a['value'],
        'assign' => 'opiekun: ' . ($usrN[(int)$a['value']] ?? ('#' . $a['value'])), 'follow' => 'obserwuje: ' . ($usrN[(int)$a['value']] ?? ('#' . $a['value'])), default => $a['type'],
    };
};
$slotC = $edit['cond'] ?? []; $slotA = $edit['acts'] ?? [];

include __DIR__ . '/includes/header_crm.php';
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= APP_URL ?>/crm/inbox.php" class="btn btn-sm btn-outline-secondary" aria-label="Wróć do Skrzynki CRM"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
  <h1 class="h4 mb-0"><i class="bi bi-diagram-3 me-2" aria-hidden="true"></i>Reguły automatyczne Skrzynki</h1>
  <?php if ($ready): ?><form method="post" class="ms-auto"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="run">
    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Przetwórz nowe wiadomości teraz</button></form><?php endif; ?>
</div>
<?php if (!$ready): ?><div class="alert alert-warning">Moduł Poczty nie jest dostępny — reguły wymagają skrzynek Poczty.</div><?php else: ?>
<p class="small text-muted" style="max-width:860px">Reguła sprawdza <b>nowe wiadomości przychodzące</b> (cron co 5 minut) i wykonuje akcje: przenosi do kolejki, dodaje tag, przypisuje opiekuna, dodaje obserwującego.
  Stara poczta nie jest ruszana<?= $since !== '' ? ' (znacznik: wiadomości po #' . h($since) . ')' : ' (znacznik ustawi pierwszy przebieg)' ?> — historię obejmiesz przyciskiem przy regule.
  Opiekun jest ustawiany tylko, gdy rozmowa go nie ma i osoba nie jest poza biurem; kolejka — tylko gdy rozmowa nie ma jeszcze jawnej kolejki. Reguły działają w podanej kolejności.</p>

<div class="table-responsive mb-3"><table class="table table-sm align-middle" style="font-size:.88rem">
  <caption class="visually-hidden">Reguły automatyczne w kolejności wykonywania</caption>
  <thead class="table-light"><tr><th scope="col">#</th><th scope="col">Reguła</th><th scope="col">Jeśli</th><th scope="col">To</th><th scope="col" class="text-end">Trafień</th><th scope="col" class="text-end">Akcje</th></tr></thead><tbody>
  <?php if (!$rules): ?><tr><td colspan="6" class="text-center text-muted py-4">Brak reguł — dodaj pierwszą poniżej (np. wiadomość z załącznikiem na faktury@ → kolejka Faktury + tag „faktura”).</td></tr><?php endif; ?>
  <?php foreach ($rules as $i => $r): ?>
    <tr class="<?= (int)$r['is_active'] ? '' : 'text-muted' ?>">
      <td class="text-nowrap"><?= $i + 1 ?>
        <form method="post" class="d-inline"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="_op" value="move">
          <button name="dir" value="up" class="btn btn-sm btn-link p-0" aria-label="Przesuń regułę <?= h($r['name']) ?> wyżej"<?= $i === 0 ? ' disabled' : '' ?>><i class="bi bi-chevron-up" aria-hidden="true"></i></button>
          <button name="dir" value="down" class="btn btn-sm btn-link p-0" aria-label="Przesuń regułę <?= h($r['name']) ?> niżej"<?= $i === count($rules) - 1 ? ' disabled' : '' ?>><i class="bi bi-chevron-down" aria-hidden="true"></i></button></form></td>
      <td><b><?= h($r['name']) ?></b> <?= (int)$r['is_active'] ? '' : '<span class="badge bg-secondary">wyłączona</span>' ?><?= (int)$r['stop_on_match'] ? ' <span class="badge bg-warning text-dark" title="Po dopasowaniu kolejne reguły nie są sprawdzane">stop</span>' : '' ?></td>
      <td class="small"><?= h(implode(($r['match_mode'] === 'any' ? ' LUB ' : ' ORAZ '), array_map($condLabel, $r['cond']))) ?></td>
      <td class="small"><?= h(implode(', ', array_map($actLabel, $r['acts']))) ?></td>
      <td class="text-end"><?= (int)($matchCount[(int)$r['id']] ?? 0) ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-secondary py-0 px-2" href="?edit=<?= (int)$r['id'] ?>#form" aria-label="Edytuj regułę <?= h($r['name']) ?>">Edytuj</a>
        <form method="post" class="d-inline"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" name="_op" value="toggle"><?= (int)$r['is_active'] ? 'Wyłącz' : 'Włącz' ?></button>
          <button class="btn btn-sm btn-outline-primary py-0 px-2" name="_op" value="apply" onclick="return confirm('Zastosować regułę do ostatnich 500 wiadomości przychodzących? Przeniesie, otaguje i przypisze pasujące rozmowy.')" title="Wykonaj akcje na pasujących wiadomościach z historii">Do historii</button>
          <button class="btn btn-sm btn-outline-danger py-0 px-2" name="_op" value="delete" onclick="return confirm('Usunąć regułę?')" aria-label="Usuń regułę <?= h($r['name']) ?>">Usuń</button></form>
      </td>
    </tr>
  <?php endforeach; ?></tbody></table></div>

<div class="card shadow-sm mb-3" id="form" style="max-width:900px"><div class="card-body">
  <h2 class="h6 mb-2"><?= $edit ? 'Edytuj regułę' : 'Nowa reguła' ?></h2>
  <form method="post" class="row g-2">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="col-12 col-md-6"><label for="mr-n" class="form-label small mb-1">Nazwa</label><input id="mr-n" name="name" required maxlength="120" value="<?= h($edit['name'] ?? '') ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-3"><label for="mr-m" class="form-label small mb-1">Warunki</label>
      <select id="mr-m" name="match_mode" class="form-select form-select-sm"><option value="all"<?= ($edit['match_mode'] ?? 'all') === 'all' ? ' selected' : '' ?>>wszystkie muszą pasować</option><option value="any"<?= ($edit['match_mode'] ?? '') === 'any' ? ' selected' : '' ?>>wystarczy jeden</option></select></div>
    <div class="col-6 col-md-3"><label for="mr-s" class="form-label small mb-1">Kolejność</label><input id="mr-s" type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 50) ?>" class="form-control form-control-sm"></div>

    <fieldset class="col-12 border rounded p-2 mt-2"><legend class="small fw-semibold float-none w-auto px-1 mb-1">Jeśli (puste wiersze są pomijane)</legend>
      <?php for ($i = 0; $i < 5; $i++): $c = $slotC[$i] ?? null; $fo = $c ? $c['field'] . ':' . $c['op'] : ''; ?>
      <div class="row g-2 mb-1 mr-cond">
        <div class="col-12 col-md-5"><label class="visually-hidden" for="mr-c<?= $i ?>">Warunek <?= $i + 1 ?></label>
          <select id="mr-c<?= $i ?>" name="cond[<?= $i ?>][fo]" class="form-select form-select-sm mr-fo"><option value="">— wybierz warunek —</option>
            <?php foreach (CRM_MR_OPS as $f => $ops): foreach ($ops as $o => $ol): ?><option value="<?= h($f . ':' . $o) ?>"<?= $fo === $f . ':' . $o ? ' selected' : '' ?>><?= h(CRM_MR_FIELDS[$f] . ' ' . $ol) ?></option><?php endforeach; endforeach; ?></select></div>
        <div class="col-12 col-md-7 d-flex gap-2">
          <input name="cond[<?= $i ?>][value]" maxlength="200" class="form-control form-control-sm mr-v-text" placeholder="wartość" aria-label="Wartość warunku <?= $i + 1 ?>" value="<?= $c && !in_array($c['field'], ['mailbox', 'attachment'], true) ? h($c['value']) : '' ?>">
          <select name="cond[<?= $i ?>][mailbox]" class="form-select form-select-sm mr-v-mailbox" aria-label="Skrzynka warunku <?= $i + 1 ?>"><?php foreach ($boxes as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $c && $c['field'] === 'mailbox' && (int)$c['value'] === (int)$b['id'] ? ' selected' : '' ?>><?= h($b['mailbox']) ?></option><?php endforeach; ?></select>
          <select name="cond[<?= $i ?>][att]" class="form-select form-select-sm mr-v-att" aria-label="Załącznik warunku <?= $i + 1 ?>"><option value="yes"<?= $c && $c['field'] === 'attachment' && $c['value'] === 'yes' ? ' selected' : '' ?>>jest (tak)</option><option value="no"<?= $c && $c['field'] === 'attachment' && $c['value'] === 'no' ? ' selected' : '' ?>>nie ma</option></select>
        </div>
      </div>
      <?php endfor; ?>
    </fieldset>

    <fieldset class="col-12 border rounded p-2 mt-2"><legend class="small fw-semibold float-none w-auto px-1 mb-1">To (puste wiersze są pomijane)</legend>
      <?php for ($i = 0; $i < 4; $i++): $a = $slotA[$i] ?? null; ?>
      <div class="row g-2 mb-1 mr-act">
        <div class="col-12 col-md-4"><label class="visually-hidden" for="mr-a<?= $i ?>">Akcja <?= $i + 1 ?></label>
          <select id="mr-a<?= $i ?>" name="act[<?= $i ?>][type]" class="form-select form-select-sm mr-type"><option value="">— wybierz akcję —</option>
            <?php foreach (CRM_MR_ACTIONS as $t => $tl): ?><option value="<?= h($t) ?>"<?= $a && $a['type'] === $t ? ' selected' : '' ?>><?= h($tl) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-8 d-flex gap-2">
          <input name="act[<?= $i ?>][tag]" maxlength="30" list="mrTags" class="form-control form-control-sm mr-a-tag" placeholder="nazwa tagu" aria-label="Tag akcji <?= $i + 1 ?>" value="<?= $a && $a['type'] === 'tag' ? h($a['value']) : '' ?>">
          <select name="act[<?= $i ?>][queue_id]" class="form-select form-select-sm mr-a-queue" aria-label="Kolejka akcji <?= $i + 1 ?>"><?php foreach ($queues as $q): ?><option value="<?= (int)$q['id'] ?>"<?= $a && $a['type'] === 'queue' && (int)$a['value'] === (int)$q['id'] ? ' selected' : '' ?>><?= h($q['name']) ?></option><?php endforeach; ?></select>
          <select name="act[<?= $i ?>][user_id]" class="form-select form-select-sm mr-a-user" aria-label="Osoba akcji <?= $i + 1 ?>"><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $a && in_array($a['type'], ['assign', 'follow'], true) && (int)$a['value'] === (int)$u['id'] ? ' selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?></select>
        </div>
      </div>
      <?php endfor; ?>
      <datalist id="mrTags"><?php foreach (crm_mt_suggest() as $ts): ?><option value="<?= h($ts) ?>"><?php endforeach; ?></datalist>
    </fieldset>
    <div class="col-12 d-flex flex-wrap gap-3 align-items-center mt-1">
      <div class="form-check"><input id="mr-st" class="form-check-input" type="checkbox" name="stop_on_match" value="1"<?= !empty($edit['stop_on_match']) ? ' checked' : '' ?>><label for="mr-st" class="form-check-label small">Zatrzymaj po dopasowaniu (kolejne reguły nie są sprawdzane)</label></div>
      <?php if ($edit): ?><div class="form-check"><input id="mr-ac" class="form-check-input" type="checkbox" name="is_active" value="1"<?= (int)$edit['is_active'] ? ' checked' : '' ?>><label for="mr-ac" class="form-check-label small">Aktywna</label></div><?php endif; ?>
    </div>
    <div class="col-12"><button class="btn btn-sm btn-primary">Zapisz</button> <?php if ($edit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/crm/mail_rules.php">Anuluj</a><?php endif; ?></div>
  </form>
</div></div>

<div class="card shadow-sm mb-3" style="max-width:900px"><div class="card-body">
  <h2 class="h6 mb-2">Sprawdź reguły na wiadomości (bez zmian)</h2>
  <form method="get" class="d-flex gap-2 align-items-end flex-wrap"><div><label for="mr-t" class="form-label small mb-1">Numer wiadomości (id)</label><input id="mr-t" type="number" min="1" name="test" value="<?= $testMsg ? (int)$testMsg['id'] : '' ?>" class="form-control form-control-sm" style="width:9rem"></div>
    <button class="btn btn-sm btn-outline-primary">Sprawdź</button></form>
  <?php if (isset($_GET['test'])): ?>
    <?php if (!$testMsg): ?><p class="small text-danger mt-2 mb-0">Nie znaleziono wiadomości przychodzącej o tym numerze.</p>
    <?php else: ?>
    <p class="small mt-2 mb-1">„<?= h($testMsg['subject'] ?: '(bez tematu)') ?>” od <?= h($testMsg['from_email']) ?> — <?= $test ? 'dopasowane reguły:' : 'żadna aktywna reguła nie pasuje.' ?></p>
    <?php if ($test): ?><ul class="small mb-0"><?php foreach ($test as $t): ?><li><b><?= h($t['rule']['name']) ?></b> → <?= h(implode('; ', $t['actions'])) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div></div>

<?php if ($log): ?>
<div class="table-responsive" style="max-width:900px"><table class="table table-sm small"><caption class="visually-hidden">Ostatnie wykonania reguł</caption>
  <thead class="table-light"><tr><th scope="col">Czas</th><th scope="col">Reguła</th><th scope="col">Wiadomość</th><th scope="col">Wykonano</th></tr></thead><tbody>
  <?php foreach ($log as $l): ?><tr><td class="text-nowrap"><?= h(date('d.m H:i', strtotime((string)$l['created_at']))) ?></td><td><?= h($l['rname'] ?? '(usunięta)') ?></td>
    <td><a href="<?= APP_URL ?>/crm/inbox.php?view=all&amp;msg=<?= (int)$l['comm_id'] ?>">#<?= (int)$l['comm_id'] ?> <?= h(mb_substr((string)$l['subject'], 0, 50)) ?></a></td><td><?= h($l['summary']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>

<script>
// Pokazuj tylko to pole wartości, które pasuje do wybranego warunku/akcji (bez JS widać wszystkie — serwer i tak bierze właściwe)
(function () {
  function condSync(row) { var f = (row.querySelector('.mr-fo').value || '').split(':')[0];
    row.querySelector('.mr-v-text').hidden = (f === 'mailbox' || f === 'attachment' || f === '');
    row.querySelector('.mr-v-mailbox').hidden = f !== 'mailbox'; row.querySelector('.mr-v-att').hidden = f !== 'attachment'; }
  function actSync(row) { var t = row.querySelector('.mr-type').value;
    row.querySelector('.mr-a-tag').hidden = t !== 'tag'; row.querySelector('.mr-a-queue').hidden = t !== 'queue'; row.querySelector('.mr-a-user').hidden = !(t === 'assign' || t === 'follow'); }
  document.querySelectorAll('.mr-cond').forEach(function (r) { condSync(r); r.querySelector('.mr-fo').addEventListener('change', function () { condSync(r); }); });
  document.querySelectorAll('.mr-act').forEach(function (r) { actSync(r); r.querySelector('.mr-type').addEventListener('change', function () { actSync(r); }); });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer_crm.php'; ?>
