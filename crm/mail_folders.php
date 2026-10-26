<?php
/**
 * crm/mail_folders.php — własne foldery Skrzynki CRM (zapisane widoki; wzór: Custom Folders z FreeScout).
 * Każdy użytkownik zarządza swoimi folderami osobistymi; administrator dodatkowo folderami wspólnymi (widocznymi dla wszystkich z dostępem do Skrzynki).
 * Logika i reguły: includes/crm_mail_folders.php. Folder to widok — wiadomość jest w nim i dalej w widokach domyślnych.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/crm_mailbox.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('inbox', 'read');
crm_migrate();
$ready = crm_mailbox_ready();
$PAGE_TITLE = 'Foldery Skrzynki CRM';
$uid = (int)(current_user()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    try {
        if ($op === 'save') {
            $id = (int)($_POST['id'] ?? 0) ?: null;
            $rules = ['tags' => $_POST['tags'] ?? '', 'tag_mode' => $_POST['tag_mode'] ?? 'any', 'status' => $_POST['status'] ?? 'any', 'assignee' => $_POST['assignee'] ?? 'any',
                      'queue_id' => $_POST['queue_id'] ?? 0, 'mailbox_id' => $_POST['mailbox_id'] ?? 0, 'following' => $_POST['following'] ?? ''];
            crm_mf_save(['name' => $_POST['name'] ?? '', 'color' => $_POST['color'] ?? '', 'counter_mode' => $_POST['counter_mode'] ?? 'active', 'is_shared' => $_POST['is_shared'] ?? 0,
                         'sort_order' => $_POST['sort_order'] ?? 50, 'rules' => $rules], $id);
            flash_set('success', $id ? 'Folder zapisany.' : 'Folder dodany.');
        } elseif ($op === 'delete') {
            crm_mf_delete((int)($_POST['id'] ?? 0));
            flash_set('success', 'Folder usunięty (wiadomości i tagi zostają bez zmian).');
        }
    } catch (\Throwable $e) {
        flash_set('danger', $e->getMessage());
    }
    header('Location: ' . APP_URL . '/crm/mail_folders.php'); exit;
}

$folders = $ready ? crm_mf_list() : [];
$counts = $folders ? crm_mf_counts($folders) : [];
$queues = $ready ? crm_mq_list() : [];
$boxes = $ready ? crm_mailbox_list(false) : [];
$users = $ready ? db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name") : [];
$suggest = $ready ? crm_mt_suggest() : [];
$edit = null;
if (isset($_GET['edit'])) foreach ($folders as $f) if ((int)$f['id'] === (int)$_GET['edit'] && crm_mf_can_edit($f)) $edit = $f;
$er = $edit['rules_arr'] ?? crm_mf_rules([]);
$isAdmin = is_admin();

include __DIR__ . '/includes/header_crm.php';
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= APP_URL ?>/crm/inbox.php" class="btn btn-sm btn-outline-secondary" aria-label="Wróć do Skrzynki CRM"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
  <h1 class="h4 mb-0"><i class="bi bi-folder2-open me-2" aria-hidden="true"></i>Foldery Skrzynki</h1>
</div>
<?php if (!$ready): ?>
<div class="alert alert-warning">Moduł Poczty nie jest dostępny — foldery wymagają skrzynek Poczty.</div>
<?php else: ?>
<p class="small text-muted" style="max-width:820px">Folder to <b>zapisany widok</b>: wybiera rozmowy regułami (tag, status, opiekun, kolejka, skrzynka). Rozmowa jest w folderze <b>i jednocześnie</b> w widokach domyślnych
  (Nowe, Moje, Wszystkie…) — nic nie jest przenoszone. Tagi dodajesz przy wiadomości lub zbiorczo na liście; dopasowanie działa na poziomie całej rozmowy (wątku).
  Licznik pokazuje rozmowy: „aktywne” albo „wszystkie” (także załatwione).</p>

<div class="table-responsive mb-3">
<table class="table table-sm align-middle" style="font-size:.9rem">
  <caption class="visually-hidden">Foldery Skrzynki CRM</caption>
  <thead class="table-light"><tr><th scope="col">Folder</th><th scope="col">Rodzaj</th><th scope="col">Reguły</th><th scope="col" class="text-end">Rozmów</th><th scope="col" class="text-end">Akcje</th></tr></thead>
  <tbody>
  <?php if (!$folders): ?><tr><td colspan="5" class="text-center text-muted py-4">Brak folderów — dodaj pierwszy poniżej.</td></tr><?php endif; ?>
  <?php foreach ($folders as $f): $r = $f['rules_arr']; $can = crm_mf_can_edit($f); ?>
    <tr>
      <td><span class="d-inline-block rounded-circle me-1" style="width:.7rem;height:.7rem;background:<?= h($f['color']) ?>" aria-hidden="true"></span><b><?= h($f['name']) ?></b></td>
      <td><?= $f['is_shared'] ? '<span class="badge bg-primary">wspólny</span>' : '<span class="badge bg-secondary">osobisty</span>' ?>
        <?php if ($f['is_shared'] && $f['owner_name']): ?><span class="small text-muted">(<?= h($f['owner_name']) ?>)</span><?php endif; ?></td>
      <td class="small">
        <?php $bits = [];
          if ($r['tags']) $bits[] = 'tagi ' . ($r['tag_mode'] === 'all' ? 'wszystkie' : 'dowolny') . ': ' . implode(', ', $r['tags']);
          if ($r['status'] !== 'any') $bits[] = 'status: ' . ['active' => 'aktywne', 'archived' => 'załatwione', 'spam' => 'spam'][$r['status']];
          if ($r['assignee'] !== 'any') $bits[] = 'opiekun: ' . (['me' => 'ja', 'none' => 'brak'][$r['assignee']] ?? ('#' . $r['assignee']));
          if ($r['queue_id']) { foreach ($queues as $q) if ((int)$q['id'] === $r['queue_id']) $bits[] = 'kolejka: ' . $q['name']; }
          if ($r['mailbox_id']) { foreach ($boxes as $b) if ((int)$b['id'] === $r['mailbox_id']) $bits[] = 'skrzynka: ' . $b['mailbox']; }
          if ($r['following']) $bits[] = 'tylko obserwowane przeze mnie';
          echo $bits ? h(implode(' · ', $bits)) : '<span class="text-muted">wszystkie rozmowy (aktywne i załatwione)</span>'; ?>
        <div class="text-muted">licznik: <?= $f['counter_mode'] === 'all' ? 'wszystkie' : 'tylko aktywne' ?></div>
      </td>
      <td class="text-end"><?= (int)($counts[(int)$f['id']]['n'] ?? 0) ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-primary py-0 px-2" href="<?= APP_URL ?>/crm/inbox.php?folder=<?= (int)$f['id'] ?>" aria-label="Otwórz folder <?= h($f['name']) ?>">Otwórz</a>
        <?php if ($can): ?>
        <a class="btn btn-sm btn-outline-secondary py-0 px-2" href="?edit=<?= (int)$f['id'] ?>#form" aria-label="Edytuj folder <?= h($f['name']) ?>">Edytuj</a>
        <form method="post" class="d-inline"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <button class="btn btn-sm btn-outline-danger py-0 px-2" name="_op" value="delete" onclick="return confirm('Usunąć folder? Wiadomości i tagi zostają bez zmian.')" aria-label="Usuń folder <?= h($f['name']) ?>">Usuń</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="card shadow-sm" id="form" style="max-width:820px"><div class="card-body">
  <h2 class="h6 mb-2"><?= $edit ? 'Edytuj folder' : 'Nowy folder' ?></h2>
  <form method="post" class="row g-2">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="col-12 col-md-5"><label for="mf-n" class="form-label small mb-1">Nazwa (do 40 znaków)</label>
      <input id="mf-n" name="name" required maxlength="40" value="<?= h($edit['name'] ?? '') ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label for="mf-c" class="form-label small mb-1">Kolor</label>
      <input id="mf-c" type="color" name="color" value="<?= h($edit['color'] ?? '#0176D3') ?>" class="form-control form-control-sm form-control-color"></div>
    <div class="col-6 col-md-2"><label for="mf-s" class="form-label small mb-1">Kolejność</label>
      <input id="mf-s" type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 50) ?>" class="form-control form-control-sm"></div>
    <div class="col-12 col-md-3"><label for="mf-cm" class="form-label small mb-1">Licznik</label>
      <select id="mf-cm" name="counter_mode" class="form-select form-select-sm">
        <option value="active"<?= ($edit['counter_mode'] ?? 'active') === 'active' ? ' selected' : '' ?>>Tylko aktywne rozmowy</option>
        <option value="all"<?= ($edit['counter_mode'] ?? '') === 'all' ? ' selected' : '' ?>>Wszystkie rozmowy</option></select></div>

    <fieldset class="col-12 border rounded p-2 mt-2"><legend class="small fw-semibold float-none w-auto px-1 mb-1">Reguły folderu (puste = wszystkie rozmowy)</legend>
      <div class="row g-2">
        <div class="col-12 col-md-6"><label for="mf-t" class="form-label small mb-1">Tagi (po przecinku)</label>
          <input id="mf-t" name="tags" list="mfTagList" value="<?= h(implode(', ', $er['tags'])) ?>" class="form-control form-control-sm" placeholder="np. faktura, pilne" autocomplete="off">
          <datalist id="mfTagList"><?php foreach ($suggest as $ts): ?><option value="<?= h($ts) ?>"><?php endforeach; ?></datalist></div>
        <div class="col-6 col-md-3"><label for="mf-tm" class="form-label small mb-1">Dopasowanie tagów</label>
          <select id="mf-tm" name="tag_mode" class="form-select form-select-sm"><option value="any"<?= $er['tag_mode'] === 'any' ? ' selected' : '' ?>>dowolny z tagów</option><option value="all"<?= $er['tag_mode'] === 'all' ? ' selected' : '' ?>>wszystkie tagi</option></select></div>
        <div class="col-6 col-md-3"><label for="mf-st" class="form-label small mb-1">Status</label>
          <select id="mf-st" name="status" class="form-select form-select-sm">
            <?php foreach (['any' => 'aktywne i załatwione', 'active' => 'tylko aktywne', 'archived' => 'tylko załatwione', 'spam' => 'spam'] as $v => $l): ?><option value="<?= $v ?>"<?= $er['status'] === $v ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4"><label for="mf-as" class="form-label small mb-1">Opiekun</label>
          <select id="mf-as" name="assignee" class="form-select form-select-sm">
            <option value="any"<?= $er['assignee'] === 'any' ? ' selected' : '' ?>>dowolny</option><option value="me"<?= $er['assignee'] === 'me' ? ' selected' : '' ?>>ja (zalogowany)</option><option value="none"<?= $er['assignee'] === 'none' ? ' selected' : '' ?>>bez opiekuna</option>
            <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (string)$er['assignee'] === (string)$u['id'] ? ' selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4"><label for="mf-q" class="form-label small mb-1">Kolejka</label>
          <select id="mf-q" name="queue_id" class="form-select form-select-sm"><option value="">— dowolna —</option>
            <?php foreach ($queues as $q): ?><option value="<?= (int)$q['id'] ?>"<?= (int)$er['queue_id'] === (int)$q['id'] ? ' selected' : '' ?>><?= h($q['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-4"><label for="mf-m" class="form-label small mb-1">Skrzynka</label>
          <select id="mf-m" name="mailbox_id" class="form-select form-select-sm"><option value="">— dowolna —</option>
            <?php foreach ($boxes as $b): ?><option value="<?= (int)$b['id'] ?>"<?= (int)$er['mailbox_id'] === (int)$b['id'] ? ' selected' : '' ?>><?= h($b['mailbox']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><div class="form-check"><input id="mf-fo" class="form-check-input" type="checkbox" name="following" value="1"<?= !empty($er['following']) ? ' checked' : '' ?>>
          <label for="mf-fo" class="form-check-label small">Tylko rozmowy, które obserwuję (dla każdego użytkownika folderu — jego własne obserwowane)</label></div></div>
      </div>
    </fieldset>
    <?php if ($isAdmin): ?>
    <div class="col-12"><div class="form-check"><input id="mf-sh" class="form-check-input" type="checkbox" name="is_shared" value="1"<?= !empty($edit['is_shared']) ? ' checked' : '' ?>>
      <label for="mf-sh" class="form-check-label small">Folder wspólny — widoczny dla wszystkich z dostępem do Skrzynki CRM (w zakresie ich skrzynek)</label></div></div>
    <?php endif; ?>
    <div class="col-12"><button class="btn btn-sm btn-primary">Zapisz</button> <?php if ($edit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/crm/mail_folders.php">Anuluj</a><?php endif; ?></div>
  </form>
</div></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer_crm.php'; ?>
