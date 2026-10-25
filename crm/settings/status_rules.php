<?php
/**
 * crm/settings/status_rules.php — reguły automatycznego nadawania statusu relacji (includes/crmStatusRules.php).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crmStatusRules.php';

require_login();
require_module_enabled('crm_enabled', 'CRM');
if (!can_write('crm_ustawienia') && !is_admin()) { flash_set('danger', 'Brak uprawnień do ustawień CRM.'); header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }
crm_migrate();
crm_require('settings', 'write');
crm_status_rules_schema();
$PAGE_TITLE = 'CRM — Automatyczne statusy';
$statuses = crm_statuses();
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($op === 'toggle' && $id) {
        db()->prepare("UPDATE crm_status_rules SET is_active=1-is_active WHERE id=?")->execute([$id]);
    } elseif ($op === 'delete' && $id) {
        db()->prepare("DELETE FROM crm_status_rules WHERE id=?")->execute([$id]);
        flash_set('success', 'Usunięto regułę.');
    } elseif ($op === 'save') {
        $cond = (string)($_POST['cond'] ?? '');
        $to   = (string)($_POST['to_status'] ?? '');
        $from = array_values(array_filter(array_map('strval', (array)($_POST['from_statuses'] ?? [])), fn($s) => isset($statuses[$s])));
        $param = trim((string)($_POST['param'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || !isset(CRM_STATUS_RULE_CONDITIONS[$cond]) || !isset($statuses[$to])) flash_set('danger', 'Podaj nazwę, warunek i status docelowy.');
        elseif ($cond === 'inactive_days' && (int)$param < 30) flash_set('danger', 'Liczba dni nieaktywności: co najmniej 30.');
        elseif ($cond === 'category' && $param === '') flash_set('danger', 'Wybierz kategorię.');
        else {
            $data = [$name, $cond, $cond === 'active_contract' ? '' : $param, implode(',', $from), $to];
            if ($id) db()->prepare("UPDATE crm_status_rules SET name=?, cond=?, param=?, from_statuses=?, to_status=? WHERE id=?")->execute([...$data, $id]);
            else {
                $max = (int)(db_one("SELECT COALESCE(MAX(sort_order),0) AS n FROM crm_status_rules")['n'] ?? 0);
                db()->prepare("INSERT INTO crm_status_rules (name,cond,param,from_statuses,to_status,sort_order,is_active) VALUES (?,?,?,?,?,?,0)")->execute([...$data, $max + 1]);
            }
            flash_set('success', 'Zapisano regułę' . ($id ? '.' : ' (wyłączona — włącz po obejrzeniu podglądu).'));
        }
    } elseif ($op === 'run') {
        $r = crm_status_rules_run(true);
        flash_set('success', 'Zmieniono status u ' . $r['applied'] . ' kontaktów (aktywne reguły).');
    } elseif ($op === 'preview') {
        $preview = crm_status_rules_run(false, $id ?: null);
    }
    if ($preview === null) { header('Location: ' . APP_URL . '/crm/settings/status_rules.php'); exit; }
}
$rules = crm_status_rules();
$cats  = crm_categories();
$edit  = null;
if (isset($_GET['edit'])) foreach ($rules as $r) if ((int)$r['id'] === (int)$_GET['edit']) $edit = $r;
$f = $edit ?? ['id' => 0, 'name' => '', 'cond' => 'active_contract', 'param' => '', 'from_statuses' => '', 'to_status' => array_key_first($statuses)];
$f_from = array_filter(explode(',', (string)$f['from_statuses']));
$log = db_all("SELECT l.*, c.imie_nazwisko FROM crm_status_auto_log l LEFT JOIN crm_contacts c ON c.id=l.contact_id ORDER BY l.id DESC LIMIT 25");
include dirname(__DIR__) . '/includes/header_crm.php';
include __DIR__ . '/_nav.php';
?>
<style>
.sr-card{background:#fff;border:1px solid #D1D5DB;margin-bottom:1rem}
.sr-card>h2{margin:0;padding:.6rem 1rem;font-size:.95rem;font-weight:600;background:#F3F4F6;border-bottom:1px solid #D1D5DB}
.sr-body{padding:1rem}.sr-t{width:100%;border-collapse:collapse;font-size:.85rem}
.sr-t th,.sr-t td{padding:.5rem .75rem;border-bottom:1px solid #E5E7EB;text-align:left;vertical-align:top}
.sr-t thead th{background:#F9FAFB;font-size:.74rem;text-transform:uppercase;letter-spacing:.04em;color:#374151}
.sr-off td{background:#F3F4F6;color:#4B5563}.sr-help{font-size:.78rem;color:#4B5563}
.sr-body .form-control,.sr-body .form-select{border-radius:0;border:1px solid #9CA3AF}.sr-body .btn{border-radius:0}
.sr-chip{display:inline-block;padding:.05rem .45rem;background:#E6F4F1;color:#0F4C47;font-size:.74rem;font-weight:600;margin:.1rem .15rem .1rem 0}
</style>
<div class="crm-page-header mb-3">
  <div><h1 class="crm-page-title"><i class="bi bi-magic" style="color:var(--crm-primary)" aria-hidden="true"></i> Automatyczne statusy relacji</h1>
    <div class="crm-page-subtitle">CRM sam nadaje status relacji (drugi status: np. „Urząd + aktywny”); kategorię główną wylicza z danych (Kategorie kontaktów)</div></div>
  <div class="crm-page-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="_op" value="run">
    <button class="btn btn-crm-outline btn-sm" onclick="return confirm('Uruchomić aktywne reguły teraz? Zmieni statusy kontaktów.')"><i class="bi bi-play-circle me-1" aria-hidden="true"></i>Uruchom aktywne reguły teraz</button></form></div>
</div>
<p class="sr-help" style="max-width:62rem">Reguły działają automatycznie co 6 godzin. Reguła zmienia status tylko kontaktom, których <strong>obecny status</strong> jest na liście „z jakich statusów” (puste = każdy), więc nie nadpisuje świadomych decyzji. Każdą zmianę widać w dzienniku niżej. Nowe reguły są wyłączone — włącz po obejrzeniu podglądu.</p>

<?php if ($preview): ?>
<div class="sr-card"><h2>Podgląd (nic nie zmieniono): <?= count($preview['changes']) ?> kontaktów</h2>
  <?php if (!$preview['changes']): ?><div class="sr-body sr-help">Żaden kontakt nie spełnia reguł lub ma już właściwy status.</div>
  <?php else: ?><table class="sr-t"><thead><tr><th>Kontakt</th><th>Reguła</th><th>Zmiana</th></tr></thead><tbody>
    <?php $nm = []; foreach (array_slice($preview['changes'], 0, 100) as $c): $nm[$c['contact_id']] ??= (db_one("SELECT imie_nazwisko FROM crm_contacts WHERE id=?", [$c['contact_id']])['imie_nazwisko'] ?? '#' . $c['contact_id']); ?>
    <tr><td><a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$c['contact_id'] ?>"><?= h($nm[$c['contact_id']]) ?></a></td><td><?= h($c['rule']) ?></td><td><?= h($statuses[$c['from']]['label'] ?? $c['from']) ?> → <strong><?= h($statuses[$c['to']]['label'] ?? $c['to']) ?></strong></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php if (count($preview['changes']) > 100): ?><div class="sr-body sr-help">… i <?= count($preview['changes']) - 100 ?> więcej.</div><?php endif; ?>
  <?php endif; ?></div>
<?php endif; ?>

<div class="sr-card"><h2><?= $f['id'] ? 'Edytuj regułę' : 'Nowa reguła' ?></h2>
  <form method="post" class="sr-body"><?= csrf_field() ?><input type="hidden" name="_op" value="save"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label small fw-semibold" for="srName">Nazwa</label><input id="srName" name="name" class="form-control form-control-sm" required maxlength="80" value="<?= h($f['name']) ?>"></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="srCond">Warunek</label>
        <select id="srCond" name="cond" class="form-select form-select-sm" onchange="document.getElementById('srParamBox').hidden = this.value==='active_contract'">
          <?php foreach (CRM_STATUS_RULE_CONDITIONS as $k => $l): ?><option value="<?= h($k) ?>"<?= $f['cond'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2" id="srParamBox"<?= $f['cond'] === 'active_contract' ? ' hidden' : '' ?>><label class="form-label small fw-semibold" for="srParam">Parametr</label>
        <input id="srParam" name="param" class="form-control form-control-sm" list="srCats" value="<?= h($f['param']) ?>" placeholder="dni lub kategoria"><datalist id="srCats"><?php foreach ($cats as $c): ?><option value="<?= h($c['slug']) ?>"><?= h($c['label']) ?></option><?php endforeach; ?></datalist></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="srTo">Nadaj status</label>
        <select id="srTo" name="to_status" class="form-select form-select-sm"><?php foreach ($statuses as $k => $v): ?><option value="<?= h($k) ?>"<?= $f['to_status'] === $k ? ' selected' : '' ?>><?= h($v['label']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><div class="form-label small fw-semibold mb-1">Tylko gdy obecny status to (puste = każdy)</div>
        <?php foreach ($statuses as $k => $v): ?><label class="me-3 small"><input type="checkbox" name="from_statuses[]" value="<?= h($k) ?>"<?= in_array($k, $f_from, true) ? ' checked' : '' ?>> <?= h($v['label']) ?></label><?php endforeach; ?></div>
      <div class="col-12"><button class="btn btn-crm-primary btn-sm"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz regułę</button>
        <?php if ($f['id']): ?><a class="btn btn-crm-outline btn-sm" href="<?= APP_URL ?>/crm/settings/status_rules.php">Anuluj</a><?php endif; ?></div>
    </div>
  </form></div>

<div class="sr-card"><h2>Reguły (stosowane od góry)</h2>
  <table class="sr-t"><thead><tr><th>Reguła</th><th>Warunek</th><th>Z → na</th><th>Zmian</th><th></th></tr></thead><tbody>
  <?php foreach ($rules as $r): $fr = array_filter(explode(',', (string)$r['from_statuses'])); ?>
    <tr class="<?= $r['is_active'] ? '' : 'sr-off' ?>">
      <td><strong><?= h($r['name']) ?></strong><?= $r['is_active'] ? '' : ' <span class="sr-help">(wyłączona)</span>' ?></td>
      <td><?= h(CRM_STATUS_RULE_CONDITIONS[$r['cond']] ?? $r['cond']) ?><?= $r['param'] !== '' ? ': <code>' . h($r['param']) . '</code>' : '' ?></td>
      <td><?php foreach ($fr as $s): ?><span class="sr-chip"><?= h($statuses[$s]['label'] ?? $s) ?></span><?php endforeach; ?><?= $fr ? '' : '<span class="sr-help">każdy</span>' ?> → <strong><?= h($statuses[$r['to_status']]['label'] ?? $r['to_status']) ?></strong></td>
      <td><?= (int)$r['hits'] ?><?= $r['last_run_at'] ? '<div class="sr-help">' . h(date('d.m.Y H:i', strtotime($r['last_run_at']))) . '</div>' : '' ?></td>
      <td class="text-nowrap">
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="_op" value="preview"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-secondary" style="border-radius:0" title="Pokaż, kogo reguła by zmieniła">Podgląd</button></form>
        <a class="btn btn-sm btn-outline-secondary" style="border-radius:0" href="?edit=<?= (int)$r['id'] ?>" aria-label="Edytuj regułę <?= h($r['name']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="_op" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-secondary" style="border-radius:0" aria-label="<?= $r['is_active'] ? 'Wyłącz' : 'Włącz' ?> regułę <?= h($r['name']) ?>"><i class="bi bi-<?= $r['is_active'] ? 'pause-circle' : 'play-circle' ?>" aria-hidden="true"></i></button></form>
        <form method="post" class="d-inline" onsubmit="return confirm('Usunąć regułę?')"><?= csrf_field() ?><input type="hidden" name="_op" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger" style="border-radius:0" aria-label="Usuń regułę <?= h($r['name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
      </td></tr>
  <?php endforeach; ?></tbody></table></div>

<div class="sr-card"><h2>Dziennik automatycznych zmian (ostatnie 25)</h2>
  <?php if (!$log): ?><div class="sr-body sr-help">Jeszcze nic nie zmieniono automatycznie.</div><?php else: ?>
  <table class="sr-t"><thead><tr><th>Kiedy</th><th>Kontakt</th><th>Reguła</th><th>Zmiana</th></tr></thead><tbody>
  <?php foreach ($log as $l): ?><tr><td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime($l['created_at']))) ?></td>
    <td><a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$l['contact_id'] ?>"><?= h($l['imie_nazwisko'] ?: '#' . $l['contact_id']) ?></a></td><td><?= h($l['rule_name']) ?></td>
    <td><?= h($statuses[$l['from_status']]['label'] ?? $l['from_status']) ?> → <strong><?= h($statuses[$l['to_status']]['label'] ?? $l['to_status']) ?></strong></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?></div>
<?php include __DIR__ . '/_nav_end.php'; include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
