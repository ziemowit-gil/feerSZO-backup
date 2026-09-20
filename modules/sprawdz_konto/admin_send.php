<?php
/**
 * modules/sprawdz_konto/admin_send.php — wysyłka linku weryfikacyjnego
 * numeru konta kontrahentowi (pracownik, zalogowany).
 *
 * Kontrahenci nie mają własnego konta w systemie (patrz logic/sprawdz_konto.php),
 * więc — inaczej niż dla kursanta (link generowany automatycznie przy
 * zatwierdzeniu numeru konta w karty30/ti/dydaktyk/konta.php) — link trzeba
 * wysłać ręcznie: pracownik wskazuje umowę po typie i numerze, system
 * znajduje stronę umowy i jej e-mail, i po potwierdzeniu wysyła link.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/sprawdz_konto.php';
sprawdz_konto_migrate();

require_role('admin', 'editor');
$user = current_user();

$found  = null;
$type   = trim($_GET['type'] ?? ($_POST['type'] ?? ''));
$numer  = trim($_GET['numer_umowy'] ?? ($_POST['numer_umowy'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'search') {
        if ($type === '' || $numer === '') {
            flash_set('danger', 'Podaj typ umowy i numer umowy.');
        } else {
            $found = sprawdz_konto_find_kontrahent_contract($type, $numer);
            if (!$found) flash_set('warning', 'Nie znaleziono umowy o takim numerze w wybranym typie.');
        }
    }

    if ($action === 'send') {
        $contract_id = (int)($_POST['contract_id'] ?? 0);
        $email       = trim($_POST['email'] ?? '');
        $name        = trim($_POST['name'] ?? '');
        if (!$type || !$contract_id || !$email || !$name) {
            flash_set('danger', 'Brak danych do wysyłki — wyszukaj umowę ponownie.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('danger', 'Nieprawidłowy adres e-mail.');
        } else {
            $ok = sprawdz_konto_send_kontrahent_link($type, $contract_id, $email, $name, (int)$user['id']);
            if ($ok) {
                log_contract_action($type, $contract_id, (int)$user['id'], 'note',
                    'Wysłano link weryfikacyjny numeru konta do wpłat na adres: ' . $email);
                flash_set('success', 'Link weryfikacyjny wysłany na adres: ' . h($email));
            } else {
                flash_set('danger', 'Nie udało się wysłać wiadomości (sprawdź adres e-mail i konfigurację poczty).');
            }
        }
    }
}

$PAGE_TITLE = 'Link weryfikacyjny numeru konta — kontrahent';
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-shield-lock text-primary"></i> Link weryfikacyjny numeru konta — kontrahent</h4>
</div>

<?= flash_html() ?>

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <p class="text-muted small">
      Wyślij kontrahentowi spersonalizowany link, pod którym może zweryfikować oficjalny numer konta
      organizacji do wpłat — ochrona przed podmianą numeru konta na fakturze/w mailu.
    </p>
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="search">
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Typ umowy</label>
        <select name="type" class="form-select" required>
          <option value="">— wybierz —</option>
          <?php foreach (SPRAWDZ_KONTO_CONTRACT_NAME_COLS as $slug => $col): ?>
          <option value="<?= h($slug) ?>" <?= $type === $slug ? 'selected' : '' ?>><?= h(CONTRACT_TYPES[$slug] ?? $slug) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Numer umowy</label>
        <input type="text" name="numer_umowy" class="form-control" value="<?= h($numer) ?>" required placeholder="np. Z/2026/014">
      </div>
      <div class="col-md-4">
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Szukaj</button>
      </div>
    </form>
  </div>
</div>

<?php if ($found): ?>
<div class="card shadow-sm border-success">
  <div class="card-body">
    <h6 class="fw-bold mb-3"><i class="bi bi-check-circle text-success"></i> Znaleziono umowę</h6>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="send">
      <input type="hidden" name="type" value="<?= h($type) ?>">
      <input type="hidden" name="contract_id" value="<?= (int)$found['id'] ?>">
      <div class="mb-3">
        <label class="form-label small fw-semibold">Strona umowy</label>
        <input type="text" name="name" class="form-control" value="<?= h($found['name']) ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label small fw-semibold">Adres e-mail</label>
        <input type="email" name="email" class="form-control" value="<?= h($found['email']) ?>" required
               placeholder="<?= $found['email'] === '' ? 'brak adresu w umowie — uzupełnij ręcznie' : '' ?>">
        <?php if ($found['email'] === ''): ?>
        <div class="form-text text-warning">W umowie nie ma zapisanego adresu e-mail — podaj go ręcznie przed wysyłką.</div>
        <?php endif; ?>
      </div>
      <button type="submit" class="btn btn-success"><i class="bi bi-send me-1"></i>Wyślij link weryfikacyjny</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
