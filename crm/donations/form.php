<?php
/**
 * crm/donations/form.php — dopisanie i edycja darowizny.
 *
 * Rodzaj darowizny (pieniężna / rzeczowa) przestawia formularz, bo dokumenty
 * i wymagane dane są inne: przy pieniężnej liczy się sposób wpłaty i tytuł
 * przelewu, przy rzeczowej — opis przedmiotu i jego wartość wskazana przez
 * darczyńcę.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/donations.php';

require_login();
require_module_enabled('donations_enabled', 'Moduł Darowizny');
crm_migrate();
crm_require('donations', 'write');
donations_migrate();

if (!is_admin() && !can_write('crm')) {
    flash_set('danger', 'Brak uprawnień do rejestru darowizn.');
    header('Location: ' . APP_URL . '/crm/donations/index.php'); exit;
}

$id  = (int)($_GET['id'] ?? 0);
$row = $id ? donation_get($id) : null;
if ($id && !$row) { http_response_code(404); exit('Nie znaleziono darowizny.'); }

// Wejście z kartoteki kontaktu — darczyńca z góry wskazany.
if (!$row && ($pre = (int)($_GET['contact'] ?? 0)) > 0) {
    $pc = crm_one("SELECT id, imie_nazwisko FROM crm_contacts WHERE id = ?", [$pre]);
    if ($pc) $row = ['contact_id' => (int)$pc['id'], 'donor_name' => (string)$pc['imie_nazwisko']];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $uid = (int)(current_user()['id'] ?? 0);

    $d = [
        'contact_id'    => (int)($_POST['contact_id'] ?? 0),
        'donor_name'    => trim((string)($_POST['donor_name'] ?? '')),
        'donor_address' => trim((string)($_POST['donor_address'] ?? '')),
        'donor_pesel'   => trim((string)($_POST['donor_pesel'] ?? '')),
        'donor_nip'     => trim((string)($_POST['donor_nip'] ?? '')),
        'kind'          => (string)($_POST['kind'] ?? 'pieniezna'),
        // Przecinek dziesiętny: kwoty wpisuje się po polsku, a nie po angielsku.
        'amount'        => (float)str_replace([' ', ','], ['', '.'], (string)($_POST['amount'] ?? '0')),
        'currency'      => (string)($_POST['currency'] ?? 'PLN'),
        'donation_date' => trim((string)($_POST['donation_date'] ?? '')),
        'channel'       => (string)($_POST['channel'] ?? 'przelew'),
        'purpose'       => trim((string)($_POST['purpose'] ?? '')),
        'description'   => trim((string)($_POST['description'] ?? '')),
        'bank_account'  => trim((string)($_POST['bank_account'] ?? '')),
        'bank_ref'      => trim((string)($_POST['bank_ref'] ?? '')),
        'is_anonymous'  => !empty($_POST['is_anonymous']) ? 1 : 0,
        'note'          => trim((string)($_POST['note'] ?? '')),
    ];

    // Nazwa darczyńcy z kartoteki, gdy operator jej nie wpisał — kartoteka jest
    // źródłem prawdy, a przepisywanie nazwiska ręcznie to zaproszenie do literówek.
    if ($d['contact_id'] > 0 && $d['donor_name'] === '') {
        $c = crm_one("SELECT imie_nazwisko FROM crm_contacts WHERE id = ?", [$d['contact_id']]);
        if ($c) $d['donor_name'] = (string)$c['imie_nazwisko'];
    }

    if ($d['donor_name'] === '')                 $errors[] = 'Podaj darczyńcę (kartoteka albo nazwa).';
    if ($d['amount'] <= 0)                       $errors[] = ($d['kind'] === 'rzeczowa' ? 'Wartość' : 'Kwota') . ' musi być większa od zera.';
    if ($d['donation_date'] === '')              $errors[] = 'Podaj datę darowizny.';
    if ($d['donation_date'] > date('Y-m-d'))     $errors[] = 'Data darowizny nie może być z przyszłości.';
    if ($d['kind'] === 'rzeczowa' && $d['description'] === '') {
        // Bez opisu oświadczenie o przyjęciu nie ma czego stwierdzać.
        $errors[] = 'Przy darowiźnie rzeczowej opisz przedmiot darowizny — bez tego oświadczenie o przyjęciu jest bezwartościowe.';
    }

    if (!$errors) {
        if ($row) {
            donation_update((int)$row['id'], $d);
            flash_set('success', 'Darowizna zaktualizowana.');
        } else {
            $new = donation_add($d, $uid);
            flash_set($new ? 'success' : 'danger', $new ? 'Darowizna dopisana do rejestru.' : 'Nie udało się zapisać darowizny.');
        }
        header('Location: ' . APP_URL . '/crm/donations/index.php?year=' . (int)substr($d['donation_date'], 0, 4));
        exit;
    }
    $row = array_merge($row ?? [], $d);
}

$contacts = crm_all("SELECT id, imie_nazwisko, organizacja FROM crm_contacts WHERE crm_active = 1 ORDER BY imie_nazwisko");
$purposes = array_column(crm_all(
    "SELECT DISTINCT purpose FROM donations WHERE deleted_at IS NULL AND COALESCE(purpose,'') <> '' ORDER BY purpose"
), 'purpose');

$v    = fn(string $k, $def = '') => h((string)($row[$k] ?? $def));
$kind = (string)($row['kind'] ?? 'pieniezna');

$PAGE_TITLE = $row && !empty($row['id']) ? 'Darowizna — edycja' : 'Nowa darowizna';
include dirname(__DIR__) . '/includes/header_crm.php';
?>
<div class="container-fluid px-0" style="max-width:900px">

  <nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb mb-0 small">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="index.php">Darowizny</a></li>
    <li class="breadcrumb-item active"><?= h($PAGE_TITLE) ?></li>
  </ol></nav>

  <h1 class="h5 mb-3"><i class="bi bi-gift me-2"></i><?= h($PAGE_TITLE) ?></h1>

  <?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
  </div>
  <?php endif; ?>

  <form method="post" class="card">
    <?= csrf_field() ?>
    <div class="card-body row g-3">

      <div class="col-12">
        <div class="d-flex gap-3">
          <?php foreach (DONATION_KINDS as $k => $l): ?>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="kind" value="<?= h($k) ?>"
                   id="dk_<?= h($k) ?>" onchange="dnKind()" <?= $kind === $k ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="dk_<?= h($k) ?>"><?= h($l) ?></label>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="form-text" id="dn_kind_hint"></div>
      </div>

      <div class="col-md-7">
        <label class="form-label small mb-1" for="dn_contact">Darczyńca — kartoteka CRM</label>
        <select name="contact_id" id="dn_contact" class="form-select form-select-sm">
          <option value="0">— bez kartoteki (wpisz nazwę poniżej) —</option>
          <?php foreach ($contacts as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= ((int)($row['contact_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
            <?= h($c['imie_nazwisko']) ?><?= $c['organizacja'] ? ' · ' . h($c['organizacja']) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text" style="font-size:.74rem">
          Potwierdzenie roczne wystawia się dla kartoteki — bez niej nie da się zebrać
          wpłat jednej osoby (imienniczki).
        </div>
      </div>
      <div class="col-md-5">
        <label class="form-label small mb-1" for="dn_name">Nazwa darczyńcy</label>
        <input type="text" name="donor_name" id="dn_name" class="form-control form-control-sm"
               maxlength="190" value="<?= $v('donor_name') ?>" placeholder="uzupełni się z kartoteki">
      </div>

      <div class="col-md-4">
        <label class="form-label small mb-1" for="dn_date">Data darowizny <span class="text-danger">*</span></label>
        <input type="date" name="donation_date" id="dn_date" class="form-control form-control-sm" required
               max="<?= h(date('Y-m-d')) ?>" value="<?= $v('donation_date', date('Y-m-d')) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label small mb-1" for="dn_amount">
          <span id="dn_amount_lbl">Kwota</span> <span class="text-danger">*</span>
        </label>
        <input type="text" name="amount" id="dn_amount" class="form-control form-control-sm" required
               inputmode="decimal" value="<?= h(!empty($row['amount']) ? number_format((float)$row['amount'], 2, ',', '') : '') ?>"
               placeholder="np. 250,00">
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1" for="dn_cur">Waluta</label>
        <input type="text" name="currency" id="dn_cur" class="form-control form-control-sm"
               maxlength="3" value="<?= $v('currency', 'PLN') ?>">
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <div class="form-check mb-1">
          <input type="checkbox" name="is_anonymous" class="form-check-input" id="dn_anon" value="1"
                 <?= !empty($row['is_anonymous']) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="dn_anon">Anonimowa</label>
        </div>
      </div>

      <div class="col-md-6" id="dn_money_channel">
        <label class="form-label small mb-1" for="dn_channel">Sposób wpłaty</label>
        <select name="channel" id="dn_channel" class="form-select form-select-sm" onchange="dnKind()">
          <?php foreach (DONATION_CHANNELS as $k => $l): ?>
          <option value="<?= h($k) ?>" <?= ((string)($row['channel'] ?? 'przelew') === $k) ? 'selected' : '' ?>><?= h($l) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text" id="dn_cash_warn" style="display:none;font-size:.74rem" role="status">
          <span class="text-warning-emphasis">
            <i class="bi bi-exclamation-triangle me-1"></i>Gotówka nie jest wpłatą na rachunek
            płatniczy — darczyńca nie będzie miał dowodu z art. 26 ust. 7 pkt 1 ustawy o PIT.
            Zapiszemy wpłatę, a potwierdzenie dostanie jawne zastrzeżenie.
          </span>
        </div>
      </div>
      <div class="col-md-6" id="dn_money_ref">
        <label class="form-label small mb-1" for="dn_ref">Tytuł / identyfikator wpłaty</label>
        <input type="text" name="bank_ref" id="dn_ref" class="form-control form-control-sm"
               maxlength="190" value="<?= $v('bank_ref') ?>" placeholder="np. Darowizna 02/2026">
      </div>

      <div class="col-12" id="dn_desc_row">
        <label class="form-label small mb-1" for="dn_desc">Przedmiot darowizny</label>
        <textarea name="description" id="dn_desc" class="form-control form-control-sm" rows="2"
                  placeholder="np. 5 laptopów Dell Latitude 5490 (używane)"><?= $v('description') ?></textarea>
        <div class="form-text" style="font-size:.74rem">
          Trafia na oświadczenie o przyjęciu. Wartość wskazuje darczyńca — obdarowany jej nie wycenia.
        </div>
      </div>

      <div class="col-md-6">
        <label class="form-label small mb-1" for="dn_purpose">Cel darowizny</label>
        <input type="text" name="purpose" id="dn_purpose" class="form-control form-control-sm" list="dn_purposes"
               maxlength="190" value="<?= $v('purpose') ?>" placeholder="np. Zajęcia dla dzieci">
        <datalist id="dn_purposes">
          <?php foreach ($purposes as $p): ?><option value="<?= h((string)$p) ?>"></option><?php endforeach; ?>
        </datalist>
      </div>
      <div class="col-md-6">
        <label class="form-label small mb-1" for="dn_acct">Rachunek, na który wpłynęła</label>
        <input type="text" name="bank_account" id="dn_acct" class="form-control form-control-sm"
               maxlength="40" value="<?= $v('bank_account') ?>">
      </div>

      <div class="col-12">
        <label class="form-label small mb-1" for="dn_note">Uwagi wewnętrzne</label>
        <input type="text" name="note" id="dn_note" class="form-control form-control-sm"
               maxlength="255" value="<?= $v('note') ?>">
      </div>

      <?php if (empty($row['contact_id'])): ?>
      <!-- Dane darczyńcy bez kartoteki — potrzebne na oświadczeniu o przyjęciu. -->
      <div class="col-12"><hr class="my-1"></div>
      <div class="col-md-6">
        <label class="form-label small mb-1" for="dn_addr">Adres darczyńcy</label>
        <input type="text" name="donor_address" id="dn_addr" class="form-control form-control-sm"
               maxlength="255" value="<?= $v('donor_address') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1" for="dn_pesel">PESEL</label>
        <input type="text" name="donor_pesel" id="dn_pesel" class="form-control form-control-sm"
               maxlength="11" value="<?= $v('donor_pesel') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1" for="dn_nip">NIP</label>
        <input type="text" name="donor_nip" id="dn_nip" class="form-control form-control-sm"
               maxlength="10" value="<?= $v('donor_nip') ?>">
      </div>
      <?php endif; ?>
    </div>

    <div class="card-footer bg-white d-flex gap-2">
      <button class="btn btn-crm-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
      <a href="index.php" class="btn btn-outline-secondary btn-sm">Anuluj</a>
    </div>
  </form>
</div>

<script>
function dnKind() {
  var rzecz = document.getElementById('dk_rzeczowa')?.checked;
  document.getElementById('dn_desc_row').style.display     = rzecz ? '' : 'none';
  document.getElementById('dn_money_channel').style.display = rzecz ? 'none' : '';
  document.getElementById('dn_money_ref').style.display     = rzecz ? 'none' : '';
  document.getElementById('dn_amount_lbl').textContent      = rzecz ? 'Wartość' : 'Kwota';
  document.getElementById('dn_kind_hint').textContent = rzecz
    ? 'Darowizna rzeczowa: wymaganym dokumentem jest oświadczenie obdarowanego o przyjęciu (art. 26 ust. 7 pkt 2 ustawy o PIT) — wystawimy je z rejestru.'
    : 'Darowizna pieniężna: podstawą odliczenia jest dowód wpłaty na rachunek. Nasze potwierdzenie roczne jest dokumentem pomocniczym.';
  var cash = document.getElementById('dn_channel')?.value === 'gotowka';
  document.getElementById('dn_cash_warn').style.display = (!rzecz && cash) ? '' : 'none';
}
dnKind();
</script>
<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
