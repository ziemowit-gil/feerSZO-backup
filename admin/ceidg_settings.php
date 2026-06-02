<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ceidg.php';

require_role('admin');
$PAGE_TITLE = 'Ustawienia CEIDG';

$saved_key = ceidg_api_key();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $new_key = trim($_POST['ceidg_api_key'] ?? '');

    $existing = db_one("SELECT id FROM settings WHERE key_='ceidg_api_key'");
    if ($existing) {
        db()->prepare("UPDATE settings SET value=? WHERE key_='ceidg_api_key'")->execute([$new_key]);
    } else {
        db_insert('settings', ['key_' => 'ceidg_api_key', 'value' => $new_key]);
    }

    flash_set('success', 'Ustawienia CEIDG zapisane.');
    header('Location: ' . APP_URL . '/admin/ceidg_settings.php');
    exit;
}

include dirname(__DIR__) . '/includes/header.php';
?>

<h4 class="mb-3"><i class="bi bi-building-check text-primary"></i> Ustawienia CEIDG</h4>
<?= flash_html() ?>

<div class="row">
<div class="col-lg-7">

<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-key"></i> Klucz API CEIDG</div>
<div class="card-body">
  <p class="text-muted small mb-3">
    Klucz API umożliwia wyszukiwanie kontrahentów w Centralnej Ewidencji i Informacji o Działalności Gospodarczej
    bezpośrednio z formularzy umów. Działa dla jednoosobowych działalności gospodarczych i spółek cywilnych.<br>
    Klucz uzyskasz rejestrując się na
    <a href="https://dane.biznes.gov.pl" target="_blank" rel="noopener">dane.biznes.gov.pl</a>
    (zakładka API / Twoje konto).
  </p>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="mb-3">
      <label class="form-label fw-semibold">Klucz API (Bearer token)</label>
      <div class="input-group">
        <input type="password" name="ceidg_api_key" id="apiKeyInput" class="form-control font-monospace"
          value="<?= h($saved_key) ?>" placeholder="Wklej klucz API CEIDG...">
        <button type="button" class="btn btn-outline-secondary" id="toggleKey" title="Pokaż/ukryj klucz">
          <i class="bi bi-eye"></i>
        </button>
      </div>
      <?php if ($saved_key): ?>
      <div class="form-text text-success"><i class="bi bi-check-circle"></i> Klucz jest skonfigurowany.</div>
      <?php else: ?>
      <div class="form-text text-muted">Brak klucza — wyszukiwanie CEIDG jest wyłączone.</div>
      <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary"><i class="bi bi-floppy"></i> Zapisz</button>
      <?php if ($saved_key): ?>
      <button type="button" class="btn btn-outline-secondary" id="testBtn">
        <i class="bi bi-wifi"></i> Testuj połączenie
      </button>
      <?php endif; ?>
    </div>
  </form>
</div>
</div>

<?php if ($saved_key): ?>
<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-search"></i> Test wyszukiwania</div>
<div class="card-body">
  <div class="row g-2 align-items-end">
    <div class="col-md-5">
      <label class="form-label">NIP do sprawdzenia</label>
      <input type="text" id="testNip" class="form-control" placeholder="np. 5252248481" maxlength="13">
    </div>
    <div class="col-auto">
      <button type="button" class="btn btn-outline-primary" onclick="testCeidg()">
        <i class="bi bi-search"></i> Szukaj
      </button>
    </div>
  </div>
  <div id="testResult" class="mt-3"></div>
</div>
</div>
<?php endif; ?>

</div>
</div>

<script>
document.getElementById('toggleKey')?.addEventListener('click', function () {
  var inp = document.getElementById('apiKeyInput');
  inp.type = inp.type === 'password' ? 'text' : 'password';
  this.querySelector('i').className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
});

async function testCeidg() {
  var nip = document.getElementById('testNip').value.replace(/\D/g, '');
  var res = document.getElementById('testResult');
  if (!nip || nip.length !== 10) {
    res.innerHTML = '<div class="alert alert-warning py-2">Podaj poprawny NIP (10 cyfr).</div>';
    return;
  }
  res.innerHTML = '<div class="text-muted small"><span class="spinner-border spinner-border-sm"></span> Wyszukuję…</div>';
  try {
    var r = await fetch('<?= APP_URL ?>/api/ceidg.php?nip=' + nip);
    var d = await r.json();
    if (d.error) {
      res.innerHTML = '<div class="alert alert-danger py-2"><i class="bi bi-x-circle"></i> ' + d.error + '</div>';
    } else {
      res.innerHTML =
        '<div class="alert alert-success py-2">' +
        '<strong>' + d.nazwa + '</strong><br>' +
        'NIP: ' + d.nip + (d.regon ? ' | REGON: ' + d.regon : '') + '<br>' +
        (d.adres || '') + '<br>' +
        '<span class="badge bg-' + (d.aktywna ? 'success' : 'secondary') + '">' + (d.status || '—') + '</span>' +
        '</div>';
    }
  } catch(e) {
    res.innerHTML = '<div class="alert alert-danger py-2">Błąd komunikacji z serwerem.</div>';
  }
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
