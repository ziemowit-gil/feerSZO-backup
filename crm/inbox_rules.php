<?php
/**
 * crm/inbox_rules.php — Reguły Skrzynki CRM: warunki → akcje (zob. includes/crmInboxRules.php).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/crmInboxRules.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();
crm_require('settings', 'write');
crm_inbox_rules_schema();

$PAGE_TITLE = 'Skrzynka CRM — reguły';
$uid = (int)(current_user()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($op === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        $data = [
            'name'             => $name,
            'is_active'        => !empty($_POST['is_active']) ? 1 : 0,
            'mailbox_id'       => (int)($_POST['mailbox_id'] ?? 0) ?: null,
            'from_contains'    => trim((string)($_POST['from_contains'] ?? '')) ?: null,
            'subject_contains' => trim((string)($_POST['subject_contains'] ?? '')) ?: null,
            'has_attachment'   => in_array($_POST['has_attachment'] ?? '', ['0', '1'], true) ? $_POST['has_attachment'] : null,
            'act_archive'      => !empty($_POST['act_archive']) ? 1 : 0,
            'act_read'         => !empty($_POST['act_read']) ? 1 : 0,
            'act_spam'         => !empty($_POST['act_spam']) ? 1 : 0,
            'act_hide'         => !empty($_POST['act_hide']) ? 1 : 0,
            'act_assign'       => (int)($_POST['act_assign'] ?? 0) ?: null,
            'act_tag'          => mb_substr(trim((string)($_POST['act_tag'] ?? '')), 0, 40) ?: null,
            'stop_after'       => !empty($_POST['stop_after']) ? 1 : 0,
        ];
        $has_cond = $data['mailbox_id'] || $data['from_contains'] || $data['subject_contains'] || $data['has_attachment'] !== null;
        $has_act  = $data['act_archive'] || $data['act_read'] || $data['act_spam'] || $data['act_hide'] || $data['act_assign'] || $data['act_tag'];
        if ($name === '') flash_set('danger', 'Podaj nazwę reguły.');
        elseif (!$has_cond) flash_set('danger', 'Reguła musi mieć co najmniej jeden warunek (skrzynka, nadawca, temat lub załącznik).');
        elseif (!$has_act) flash_set('danger', 'Wybierz co najmniej jedną akcję.');
        elseif ($id) { db_update('crm_inbox_rules', $data, $id); flash_set('success', 'Zapisano regułę.'); }
        else {
            $data['sort_order'] = (int)(db_one("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM crm_inbox_rules")['n'] ?? 1);
            $data['created_by'] = $uid; $data['created_at'] = date('Y-m-d H:i:s');
            db_insert('crm_inbox_rules', $data); flash_set('success', 'Dodano regułę.');
        }
    } elseif ($op === 'toggle' && $id) {
        db()->prepare("UPDATE crm_inbox_rules SET is_active=1-is_active WHERE id=?")->execute([$id]);
    } elseif ($op === 'delete' && $id) {
        db()->prepare("DELETE FROM crm_inbox_rules WHERE id=?")->execute([$id]);
        flash_set('success', 'Usunięto regułę.');
    } elseif ($op === 'apply_now') {
        $r = crm_inbox_rules_apply_existing();
        flash_set('success', 'Sprawdzono ' . $r['checked'] . ' wiadomości w skrzynce, reguły zadziałały na ' . $r['matched'] . '.');
    }
    header('Location: ' . APP_URL . '/crm/inbox_rules.php' . ($op === 'save' && $id ? '' : ''));
    exit;
}

$rules   = crm_inbox_rules_all();
$edit    = null;
if (isset($_GET['edit'])) foreach ($rules as $r) if ((int)$r['id'] === (int)$_GET['edit']) $edit = $r;
$boxes   = [];
try { $boxes = db_all("SELECT id, mailbox FROM poczta_mailboxes ORDER BY mailbox"); } catch (\Throwable $e) {}
$users   = db_all("SELECT id, name FROM users WHERE COALESCE(is_active,1)=1 ORDER BY name");

include __DIR__ . '/includes/header_crm.php';
$f = $edit ?? ['id' => 0, 'name' => '', 'is_active' => 1, 'mailbox_id' => null, 'from_contains' => '', 'subject_contains' => '',
               'has_attachment' => null, 'act_archive' => 0, 'act_read' => 0, 'act_spam' => 0, 'act_hide' => 0, 'act_assign' => null, 'act_tag' => '', 'stop_after' => 0];
?>
<style>
.ir-card{background:#fff;border:1px solid #D1D5DB;margin-bottom:1rem}
.ir-card > h2{margin:0;padding:.6rem 1rem;font-size:.95rem;font-weight:600;background:#F3F4F6;border-bottom:1px solid #D1D5DB;color:#111827}
.ir-body{padding:1rem}
.ir-table{width:100%;border-collapse:collapse;font-size:.86rem}
.ir-table th,.ir-table td{padding:.5rem .75rem;border-bottom:1px solid #E5E7EB;text-align:left;vertical-align:top}
.ir-table thead th{background:#F9FAFB;font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;color:#374151}
.ir-off td{background:#F3F4F6;color:#4B5563}
.ir-chip{display:inline-block;padding:.05rem .45rem;margin:.1rem .15rem .1rem 0;background:#E6F4F1;color:#0F4C47;font-size:.74rem;font-weight:600}
.ir-chip--c{background:#EEF2FF;color:#1E3A8A}
.ir-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:.75rem 1rem}
.ir-body .form-control,.ir-body .form-select{border-radius:0;border:1px solid #9CA3AF}
.ir-body .btn{border-radius:0}
.ir-body label.form-label{font-weight:600;color:#111827;font-size:.8rem}
.ir-help{font-size:.76rem;color:#4B5563}
.ir-acts{display:flex;flex-wrap:wrap;gap:.4rem 1.25rem}
</style>

<div class="crm-page-header mb-3">
  <div>
    <h1 class="crm-page-title"><i class="bi bi-funnel" style="color:var(--crm-primary)" aria-hidden="true"></i> Reguły Skrzynki CRM</h1>
    <div class="crm-page-subtitle">Automatycznie porządkują nowe wiadomości: załatwione, spam, przypisanie, tag kontaktu</div>
  </div>
  <div class="crm-page-actions">
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="_op" value="apply_now">
      <button class="btn btn-crm-outline btn-sm" title="Uruchom aktywne reguły na wiadomościach, które już są w skrzynce"><i class="bi bi-play-circle me-1" aria-hidden="true"></i>Zastosuj do bieżącej skrzynki</button>
    </form>
    <a href="<?= APP_URL ?>/crm/inbox.php" class="btn btn-crm-outline btn-sm"><i class="bi bi-inbox me-1" aria-hidden="true"></i>Skrzynka</a>
  </div>
</div>

<div class="ir-card">
  <h2><?= $f['id'] ? 'Edytuj regułę' : 'Nowa reguła' ?></h2>
  <form method="post" class="ir-body"><?= csrf_field() ?>
    <input type="hidden" name="_op" value="save"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
    <div class="ir-grid mb-3">
      <div><label class="form-label" for="irName">Nazwa</label>
        <input id="irName" name="name" class="form-control form-control-sm" required maxlength="80" value="<?= h($f['name']) ?>" placeholder="np. Powiadomienia systemowe → załatwione"></div>
      <div><label class="form-label" for="irBox">Skrzynka</label>
        <select id="irBox" name="mailbox_id" class="form-select form-select-sm"><option value="">— każda —</option>
          <?php foreach ($boxes as $b): ?><option value="<?= (int)$b['id'] ?>"<?= (int)$f['mailbox_id'] === (int)$b['id'] ? ' selected' : '' ?>><?= h($b['mailbox']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="form-label" for="irFrom">Nadawca zawiera</label>
        <input id="irFrom" name="from_contains" class="form-control form-control-sm" value="<?= h($f['from_contains']) ?>" placeholder="np. no-reply, powiadomienie">
        <div class="ir-help">Kilka fraz po przecinku — wystarczy jedna.</div></div>
      <div><label class="form-label" for="irSubj">Temat zawiera</label>
        <input id="irSubj" name="subject_contains" class="form-control form-control-sm" value="<?= h($f['subject_contains']) ?>" placeholder="np. automatyczna odpowiedź, newsletter">
        <div class="ir-help">Warunki łączą się przez „i”.</div></div>
      <div><label class="form-label" for="irAtt">Załącznik</label>
        <select id="irAtt" name="has_attachment" class="form-select form-select-sm">
          <option value="">— obojętnie —</option>
          <option value="1"<?= (string)$f['has_attachment'] === '1' ? ' selected' : '' ?>>Z załącznikiem</option>
          <option value="0"<?= (string)$f['has_attachment'] === '0' ? ' selected' : '' ?>>Bez załącznika</option>
        </select></div>
    </div>
    <div class="fw-semibold mb-2" style="font-size:.85rem">Akcje</div>
    <div class="ir-acts mb-3">
      <label><input type="checkbox" name="act_archive" value="1"<?= $f['act_archive'] ? ' checked' : '' ?>> Oznacz jako załatwione</label>
      <label><input type="checkbox" name="act_read" value="1"<?= $f['act_read'] ? ' checked' : '' ?>> Oznacz jako przeczytane</label>
      <label><input type="checkbox" name="act_spam" value="1"<?= $f['act_spam'] ? ' checked' : '' ?>> Przenieś do spamu</label>
      <label><input type="checkbox" name="act_hide" value="1"<?= $f['act_hide'] ? ' checked' : '' ?>> Ukryj w Skrzynce CRM</label>
    </div>
    <div class="ir-grid mb-3">
      <div><label class="form-label" for="irAs">Przypisz do</label>
        <select id="irAs" name="act_assign" class="form-select form-select-sm"><option value="">— nikogo —</option>
          <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (int)$f['act_assign'] === (int)$u['id'] ? ' selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="form-label" for="irTag">Dodaj tag kontaktowi</label>
        <input id="irTag" name="act_tag" class="form-control form-control-sm" maxlength="40" value="<?= h($f['act_tag']) ?>" placeholder="np. zapytanie-oferta"></div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-3">
      <label><input type="checkbox" name="is_active" value="1"<?= $f['is_active'] ? ' checked' : '' ?>> Reguła aktywna</label>
      <label><input type="checkbox" name="stop_after" value="1"<?= $f['stop_after'] ? ' checked' : '' ?>> Po dopasowaniu nie stosuj kolejnych reguł</label>
      <button class="btn btn-crm-primary btn-sm ms-auto"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz regułę</button>
      <?php if ($f['id']): ?><a class="btn btn-crm-outline btn-sm" href="<?= APP_URL ?>/crm/inbox_rules.php">Anuluj</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="ir-card">
  <h2>Reguły (<?= count($rules) ?>) — stosowane od góry</h2>
  <?php if (!$rules): ?>
  <div class="ir-body ir-help">Brak reguł. Dodaj pierwszą powyżej — np. „Temat zawiera: automatyczna odpowiedź → załatwione”.</div>
  <?php else: ?>
  <table class="ir-table">
    <thead><tr><th>Reguła</th><th>Warunki</th><th>Akcje</th><th>Trafień</th><th><span class="visually-hidden">Działania</span></th></tr></thead>
    <tbody>
    <?php foreach ($rules as $r):
      $cond = [];
      if ($r['mailbox_id']) { foreach ($boxes as $b) if ((int)$b['id'] === (int)$r['mailbox_id']) $cond[] = 'skrzynka: ' . $b['mailbox']; }
      if ($r['from_contains'])    $cond[] = 'nadawca: ' . $r['from_contains'];
      if ($r['subject_contains']) $cond[] = 'temat: ' . $r['subject_contains'];
      if ($r['has_attachment'] !== null && $r['has_attachment'] !== '') $cond[] = $r['has_attachment'] === '1' ? 'z załącznikiem' : 'bez załącznika';
      $act = [];
      if ($r['act_archive']) $act[] = 'załatwione'; if ($r['act_read']) $act[] = 'przeczytane'; if ($r['act_spam']) $act[] = 'spam';
      if ($r['act_hide']) $act[] = 'ukryj'; if ($r['act_assign']) $act[] = 'przypisz: ' . ($r['assign_name'] ?? '#' . $r['act_assign']);
      if ($r['act_tag']) $act[] = 'tag: ' . $r['act_tag'];
    ?>
      <tr class="<?= $r['is_active'] ? '' : 'ir-off' ?>">
        <td><strong><?= h($r['name']) ?></strong><?= $r['is_active'] ? '' : ' <span class="ir-help">(wyłączona)</span>' ?><?= $r['stop_after'] ? ' <span class="ir-help">· kończy</span>' : '' ?></td>
        <td><?php foreach ($cond as $c): ?><span class="ir-chip ir-chip--c"><?= h($c) ?></span><?php endforeach; ?></td>
        <td><?php foreach ($act as $c): ?><span class="ir-chip"><?= h($c) ?></span><?php endforeach; ?></td>
        <td><?= (int)$r['hits'] ?><?= $r['last_hit_at'] ? '<div class="ir-help">' . h(date('d.m.Y H:i', strtotime($r['last_hit_at']))) . '</div>' : '' ?></td>
        <td class="text-nowrap">
          <a class="btn btn-sm btn-outline-secondary" style="border-radius:0" href="?edit=<?= (int)$r['id'] ?>" aria-label="Edytuj regułę <?= h($r['name']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="_op" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary" style="border-radius:0" aria-label="<?= $r['is_active'] ? 'Wyłącz' : 'Włącz' ?> regułę <?= h($r['name']) ?>"><i class="bi bi-<?= $r['is_active'] ? 'pause-circle' : 'play-circle' ?>" aria-hidden="true"></i></button></form>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć regułę?')"><?= csrf_field() ?><input type="hidden" name="_op" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-outline-danger" style="border-radius:0" aria-label="Usuń regułę <?= h($r['name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer_crm.php'; ?>
