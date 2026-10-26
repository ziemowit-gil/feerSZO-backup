<?php
/**
 * crm/mail_queues.php — zarządzanie kolejkami wiadomości Skrzynki CRM (administrator) + domyślna skrzynka faktur (faktury@feer.org.pl).
 * Logika: includes/crm_mail_queues.php. Kolejka domyślna skrzynki przyjmuje wszystkie jej wiadomości, dopóki ktoś nie przeniesie ich ręcznie.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/crm_mailbox.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!is_admin()) { http_response_code(403); die('Zarządzanie kolejkami jest dostępne dla administratora.'); }
crm_migrate();
$ready = crm_mailbox_ready();   // tworzy tabele Poczty i kolejek, zakłada domyślne
$PAGE_TITLE = 'Kolejki wiadomości';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    try {
        if ($op === 'save') {
            $id = (int)($_POST['id'] ?? 0) ?: null;
            crm_mq_save($_POST, $id);
            flash_set('success', $id ? 'Kolejka zapisana.' : 'Kolejka dodana.');
        } elseif ($op === 'delete') {
            crm_mq_delete((int)($_POST['id'] ?? 0));
            flash_set('success', 'Kolejka usunięta — jej wiadomości wróciły do kolejek domyślnych.');
        } elseif ($op === 'toggle') {
            $q = db_one("SELECT * FROM crm_mail_queues WHERE id = ?", [(int)($_POST['id'] ?? 0)]);
            if ($q && !((int)$q['is_default'] === 1 && $q['mailbox_id'] === null)) {
                db()->prepare("UPDATE crm_mail_queues SET is_active = ? WHERE id = ?")->execute([(int)$q['is_active'] ? 0 : 1, (int)$q['id']]);
                flash_set('success', (int)$q['is_active'] ? 'Kolejka wyłączona.' : 'Kolejka włączona.');
            } else flash_set('warning', 'Kolejki ogólnej nie można wyłączyć.');
        } elseif ($op === 'invoice_mailbox') {
            $addr = strtolower(trim((string)($_POST['invoice_mailbox'] ?? '')));
            if ($addr === '' || $addr === '-') { org_setting_set('crm_invoice_mailbox', '-'); flash_set('success', 'Domyślna skrzynka faktur wyłączona (istniejąca skrzynka i kolejka zostają).'); }
            elseif (!filter_var($addr, FILTER_VALIDATE_EMAIL)) flash_set('danger', 'Podaj prawidłowy adres e-mail.');
            else { org_setting_set('crm_invoice_mailbox', $addr); crm_mq_ensure_invoice_mailbox($addr); flash_set('success', 'Skrzynka faktur ' . $addr . ' zapewniona (skrzynka współdzielona + kolejka „Faktury”).'); }
        }
    } catch (\Throwable $e) {
        flash_set('danger', $e->getMessage());
    }
    header('Location: ' . APP_URL . '/crm/mail_queues.php'); exit;
}

$queues = $ready ? crm_mq_list(false) : [];
$boxes = $ready ? db_all("SELECT id, mailbox FROM poczta_mailboxes ORDER BY mailbox") : [];
$counts = [];
if ($ready) {
    try {
        foreach (db_all("SELECT " . crm_mq_expr('c') . " AS qid, COUNT(*) AS n FROM crm_communications c WHERE c.direction='in' AND c.inbox_status IN ('active','archived') GROUP BY qid") as $r) $counts[(int)$r['qid']] = (int)$r['n'];
    } catch (\Throwable $e) {}
}
$inv = strtolower(trim(org_setting('crm_invoice_mailbox')));
$inv_show = $inv === '-' ? '' : ($inv ?: CRM_MQ_DEFAULT_INVOICE_MAILBOX);
$inv_row = $inv_show !== '' && $ready ? db_one("SELECT * FROM poczta_mailboxes WHERE LOWER(mailbox) = ?", [$inv_show]) : null;
$edit = null;
if (isset($_GET['edit'])) foreach ($queues as $q) if ((int)$q['id'] === (int)$_GET['edit']) $edit = $q;

include __DIR__ . '/includes/header_crm.php';
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= APP_URL ?>/crm/inbox.php" class="btn btn-sm btn-outline-secondary" aria-label="Wróć do Skrzynki CRM"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
  <h1 class="h4 mb-0"><i class="bi bi-collection me-2" aria-hidden="true"></i>Kolejki wiadomości</h1>
</div>

<?php if (!$ready): ?>
<div class="alert alert-warning">Moduł Poczty nie jest dostępny — kolejki wiadomości wymagają skrzynek Poczty.</div>
<?php else: ?>

<div class="card shadow-sm mb-3"><div class="card-body">
  <h2 class="h6 mb-2"><i class="bi bi-receipt me-1" aria-hidden="true"></i>Domyślna skrzynka faktur</h2>
  <p class="small text-muted mb-2">Ta skrzynka współdzielona jest dopisywana do Skrzynki CRM automatycznie i ma własną kolejkę „Faktury”. Odczyt dostają osoby z rolami EODoK
    (dodawanie dokumentów, główny księgowy, zatwierdzanie); kolejne osoby nadasz w Poczcie (edycja skrzynki).
    Konto M365 jest rozpoznawane przy pierwszym skanie — gdy go nie ma, skrzynka pokaże błąd w Poczcie.</p>
  <form method="post" class="row g-2 align-items-end">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="invoice_mailbox">
    <div class="col-12 col-md-6"><label for="mq-inv" class="form-label small mb-1">Adres skrzynki (puste lub „-” wyłącza)</label>
      <input id="mq-inv" type="email" name="invoice_mailbox" value="<?= h($inv_show) ?>" class="form-control form-control-sm" placeholder="faktury@feer.org.pl"></div>
    <div class="col-auto"><button class="btn btn-sm btn-primary">Zapisz i zapewnij skrzynkę</button></div>
    <?php if ($inv_row): ?><div class="col-12 small text-muted">Skrzynka #<?= (int)$inv_row['id'] ?> · status: <b><?= h($inv_row['status']) ?></b><?= $inv_row['status_detail'] ? ' — ' . h($inv_row['status_detail']) : '' ?>
      · <a href="<?= APP_URL ?>/poczta/edit.php?id=<?= (int)$inv_row['id'] ?>">edytuj dostęp w Poczcie</a></div><?php endif; ?>
  </form>
</div></div>

<div class="table-responsive mb-3">
<table class="table table-sm align-middle" style="font-size:.9rem">
  <caption class="visually-hidden">Kolejki wiadomości Skrzynki CRM</caption>
  <thead class="table-light"><tr><th scope="col">Kolejka</th><th scope="col">Skrzynka</th><th scope="col">Domyślna</th><th scope="col" class="text-end">Wiadomości</th><th scope="col">Stan</th><th scope="col" class="text-end">Akcje</th></tr></thead>
  <tbody>
  <?php foreach ($queues as $q): $general = (int)$q['is_default'] === 1 && $q['mailbox_id'] === null; ?>
    <tr class="<?= (int)$q['is_active'] ? '' : 'text-muted' ?>">
      <td><span class="d-inline-block rounded-circle me-1" style="width:.7rem;height:.7rem;background:<?= h($q['color']) ?>" aria-hidden="true"></span><b><?= h($q['name']) ?></b> <span class="text-muted small">(<?= h($q['slug']) ?>)</span></td>
      <td><?= $q['mailbox_name'] ? h($q['mailbox_name']) : '<span class="text-muted">dowolna (ogólna)</span>' ?></td>
      <td><?= (int)$q['is_default'] ? '<span class="badge bg-primary">domyślna' . ($general ? ' ogólna' : ' skrzynki') . '</span>' : '—' ?></td>
      <td class="text-end"><?= (int)($counts[(int)$q['id']] ?? 0) ?></td>
      <td><?= (int)$q['is_active'] ? 'aktywna' : 'wyłączona' ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-secondary py-0 px-2" href="?edit=<?= (int)$q['id'] ?>#form" aria-label="Edytuj kolejkę <?= h($q['name']) ?>">Edytuj</a>
        <?php if (!$general): ?>
        <form method="post" class="d-inline"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" name="_op" value="toggle"><?= (int)$q['is_active'] ? 'Wyłącz' : 'Włącz' ?></button>
          <button class="btn btn-sm btn-outline-danger py-0 px-2" name="_op" value="delete" onclick="return confirm('Usunąć kolejkę? Jej wiadomości wrócą do kolejek domyślnych.')" aria-label="Usuń kolejkę <?= h($q['name']) ?>">Usuń</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="card shadow-sm" id="form" style="max-width:760px"><div class="card-body">
  <h2 class="h6 mb-2"><?= $edit ? 'Edytuj kolejkę' : 'Nowa kolejka' ?></h2>
  <form method="post" class="row g-2">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="col-12 col-md-5"><label for="mq-n" class="form-label small mb-1">Nazwa</label>
      <input id="mq-n" name="name" required maxlength="120" value="<?= h($edit['name'] ?? '') ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label for="mq-c" class="form-label small mb-1">Kolor</label>
      <input id="mq-c" type="color" name="color" value="<?= h($edit['color'] ?? '#6B7280') ?>" class="form-control form-control-sm form-control-color"></div>
    <div class="col-6 col-md-2"><label for="mq-s" class="form-label small mb-1">Kolejność</label>
      <input id="mq-s" type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 50) ?>" class="form-control form-control-sm"></div>
    <div class="col-12 col-md-5"><label for="mq-m" class="form-label small mb-1">Skrzynka (dla kolejki domyślnej skrzynki)</label>
      <select id="mq-m" name="mailbox_id" class="form-select form-select-sm"><option value="">— dowolna (kolejka ogólna) —</option>
        <?php foreach ($boxes as $b): ?><option value="<?= (int)$b['id'] ?>"<?= (int)($edit['mailbox_id'] ?? 0) === (int)$b['id'] ? ' selected' : '' ?>><?= h($b['mailbox']) ?></option><?php endforeach; ?></select></div>
    <div class="col-12 col-md-7 d-flex align-items-end"><div class="form-check"><input id="mq-d" class="form-check-input" type="checkbox" name="is_default" value="1"<?= !empty($edit['is_default']) ? ' checked' : '' ?>>
      <label for="mq-d" class="form-check-label small">Domyślna dla tej skrzynki (przyjmuje jej wiadomości, dopóki nie zostaną przeniesione); bez skrzynki — kolejka ogólna</label></div></div>
    <div class="col-12"><button class="btn btn-sm btn-primary">Zapisz</button> <?php if ($edit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/crm/mail_queues.php">Anuluj</a><?php endif; ?></div>
  </form>
</div></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer_crm.php'; ?>
