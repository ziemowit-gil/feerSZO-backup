<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/postivo.php';

require_role('admin');
$PAGE_TITLE = 'Postivo (poczta)';

// ── Załaduj bieżącą konfigurację ──────────────────────────────────────────────
$cfg = [
    'postivo_enabled'        => postivo_setting('postivo_enabled'),
    'postivo_api_key'        => postivo_setting('postivo_api_key'),
    'postivo_config_id'      => postivo_setting('postivo_config_id'),
    'postivo_sender_name'    => postivo_setting('postivo_sender_name'),
    'postivo_return_address' => postivo_setting('postivo_return_address'),
];

$test_result = null;

// ── Obsługa POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'save') {
        $api_key = trim($_POST['postivo_api_key'] ?? '');

        $save = [
            'postivo_enabled'        => !empty($_POST['postivo_enabled']) ? '1' : '0',
            'postivo_api_key'        => $api_key ?: $cfg['postivo_api_key'],
            'postivo_config_id'      => trim($_POST['postivo_config_id']      ?? ''),
            'postivo_sender_name'    => trim($_POST['postivo_sender_name']    ?? ''),
            'postivo_return_address' => trim($_POST['postivo_return_address'] ?? ''),
        ];

        foreach ($save as $k => $v) {
            $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$k]);
            if ($exists) {
                db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$v, $k]);
            } else {
                db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute([$k, $v]);
            }
        }

        // Odśwież cache — wyczyść statyczne zmienne przez reload
        flash_set('success', 'Ustawienia Postivo.pl zapisane.');
        header('Location: postivo_settings.php');
        exit;
    }

    if ($action === 'test') {
        $client = new PostivoClient();
        if (!$client->is_configured()) {
            $test_result = ['ok' => false, 'msg' => 'Brak klucza API — najpierw zapisz konfigurację.'];
        } else {
            $test_result = $client->ping();
        }
    }
}

$client     = new PostivoClient();
$configured = $client->is_configured();
$enabled    = $cfg['postivo_enabled'] === '1';

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-mailbox text-primary"></i> Postivo.pl — wysyłka listów</h4>
  <?php if ($enabled && $configured): ?>
    <span class="badge bg-success">Aktywne</span>
  <?php elseif ($enabled): ?>
    <span class="badge bg-warning text-dark">Włączone — wymaga klucza API</span>
  <?php else: ?>
    <span class="badge bg-secondary">Wyłączone</span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<div class="row g-3">
<div class="col-xl-7">

<form method="post" id="postivoForm">
  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
  <input type="hidden" name="_action" value="save">

  <!-- ── Aktywacja ──────────────────────────────────────────────────────────── -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-toggles"></i> Aktywacja</div>
    <div class="card-body">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch"
               name="postivo_enabled" id="postivo_enabled" value="1"
               <?= $enabled ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="postivo_enabled">
          Wysyłka listów przez Postivo.pl aktywna
        </label>
      </div>
      <div class="form-text">
        Gdy włączone, na stronie podglądu pisma pojawi się sekcja <em>Wysyłka pocztą (Postivo.pl)</em>
        umożliwiająca nadanie listu poleconego.
      </div>
    </div>
  </div>

  <!-- ── Klucz API ──────────────────────────────────────────────────────────── -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-key"></i> Autoryzacja API</div>
    <div class="card-body">
      <div class="mb-0">
        <label class="form-label fw-semibold small">
          Klucz API <span class="text-danger">*</span>
        </label>
        <input type="password" name="postivo_api_key"
               class="form-control form-control-sm font-monospace"
               placeholder="<?= $configured
                   ? '(zapisany — zostaw puste by nie zmieniać)'
                   : 'Wklej klucz API z panelu Postivo.pl' ?>"
               autocomplete="new-password">
        <?php if ($configured): ?>
        <div class="form-text text-success">
          <i class="bi bi-check-circle"></i> Klucz API zapisany.
        </div>
        <?php else: ?>
        <div class="form-text">
          Pobierz klucz API w panelu <a href="https://postivo.pl" target="_blank" rel="noopener">postivo.pl</a>
          (Ustawienia → API).
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── ID konfiguracji ────────────────────────────────────────────────────── -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-gear"></i> Konfiguracja wysyłki</div>
    <div class="card-body">
      <div class="mb-0">
        <label class="form-label fw-semibold small">
          ID konfiguracji wstępnej <span class="text-danger">*</span>
        </label>
        <input type="number" name="postivo_config_id" min="1"
               class="form-control form-control-sm font-monospace"
               value="<?= h($cfg['postivo_config_id']) ?>"
               placeholder="np. 42">
        <div class="form-text">
          Numeryczne ID konfiguracji z panelu Postivo.pl (Konfiguracje → ID). Określa nośnik,
          usługę, papier, druk kolorowy i nadawcę. Utwórz konfigurację raz w Postivo,
          a wszystkie listy będą ją używać automatycznie.
        </div>
      </div>
    </div>
  </div>

  <!-- ── Dane nadawcy ───────────────────────────────────────────────────────── -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-person-vcard"></i> Dane nadawcy</div>
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label fw-semibold small">Nazwa nadawcy</label>
        <input type="text" name="postivo_sender_name"
               class="form-control form-control-sm"
               value="<?= h($cfg['postivo_sender_name']) ?>"
               placeholder="np. Fundacja XYZ">
        <div class="form-text">Wyświetlana na kopercie jako nadawca listu.</div>
      </div>
      <div class="mb-0">
        <label class="form-label fw-semibold small">Adres zwrotny</label>
        <textarea name="postivo_return_address" rows="3"
                  class="form-control form-control-sm"
                  placeholder="ul. Przykładowa 1&#10;00-001 Warszawa"><?= h($cfg['postivo_return_address']) ?></textarea>
        <div class="form-text">
          Adres do zwrotu niedoręczonej przesyłki. Wpisz każdą linię adresu w osobnym wierszu.
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2 mb-3">
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-check-lg"></i> Zapisz konfigurację
    </button>
  </div>
</form>

<!-- ── Test połączenia ────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold"><i class="bi bi-wifi"></i> Test połączenia</div>
  <div class="card-body">
    <?php if ($test_result !== null): ?>
    <div class="alert alert-<?= $test_result['ok'] === true ? 'success' : ($test_result['ok'] === false ? 'danger' : 'info') ?> py-2 small mb-3 d-flex align-items-start gap-2">
      <i class="bi bi-<?= $test_result['ok'] === true ? 'check-circle-fill' : ($test_result['ok'] === false ? 'x-circle-fill' : 'info-circle-fill') ?> mt-1 flex-shrink-0"></i>
      <?= h($test_result['msg']) ?>
    </div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="test">
      <button type="submit" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-arrow-right-circle"></i> Testuj połączenie z API
      </button>
    </form>
    <div class="form-text mt-2">
      Wysyła zapytanie do GET /account i sprawdza poprawność klucza. Przy sukcesie pokazuje saldo konta.
    </div>
  </div>
</div>

</div><!-- /col-xl-7 -->

<!-- ── Panel informacyjny ──────────────────────────────────────────────────── -->
<div class="col-xl-5">

  <!-- Status -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-shield-check"></i> Status konfiguracji</div>
    <div class="card-body small">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted">Moduł</span>
        <span class="badge <?= $enabled ? 'bg-success' : 'bg-secondary' ?>">
          <?= $enabled ? 'Włączony' : 'Wyłączony' ?>
        </span>
      </div>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted">Klucz API</span>
        <span class="badge <?= $configured ? 'bg-success' : 'bg-danger' ?>">
          <?= $configured ? 'Zapisany' : 'Brak' ?>
        </span>
      </div>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted">Nazwa nadawcy</span>
        <span class="fw-semibold"><?= h($cfg['postivo_sender_name'] ?: '—') ?></span>
      </div>
      <div class="d-flex justify-content-between align-items-center">
        <span class="text-muted">Adres zwrotny</span>
        <span class="badge <?= $cfg['postivo_return_address'] ? 'bg-success' : 'bg-secondary' ?>">
          <?= $cfg['postivo_return_address'] ? 'Ustawiony' : 'Brak' ?>
        </span>
      </div>
    </div>
  </div>

  <!-- Co to jest Postivo.pl -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-info-circle"></i> Czym jest Postivo.pl?</div>
    <div class="card-body small">
      <p class="mb-2">
        <a href="https://postivo.pl" target="_blank" rel="noopener"><strong>Postivo.pl</strong></a>
        to polska usługa wysyłki fizycznych listów przez internet. Wystarczy załączyć plik PDF —
        Postivo drukuje, składa, kopertuje i nadaje list polecony w Twoim imieniu.
      </p>
      <p class="mb-1 fw-semibold">Jak zacząć:</p>
      <ol class="ps-3 mb-2">
        <li>Zarejestruj konto na <a href="https://postivo.pl" target="_blank" rel="noopener">postivo.pl</a></li>
        <li>Doładuj konto lub podaj dane do faktury</li>
        <li>Pobierz klucz API z panelu użytkownika</li>
        <li>Wklej klucz powyżej i zapisz konfigurację</li>
      </ol>
      <p class="mb-0 fw-semibold">Cennik:</p>
      <ul class="ps-3 mb-0">
        <li>Cena zależy od wybranej konfiguracji (nośnik, usługa, papier)</li>
        <li>Druk i kopertowanie wliczone w cenę</li>
        <li>Szczegóły na <a href="https://postivo.pl" target="_blank" rel="noopener">postivo.pl</a></li>
      </ul>
    </div>
  </div>

  <!-- Jak to działa w systemie -->
  <div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-diagram-3"></i> Jak to działa</div>
    <div class="card-body small">
      <p class="mb-2">
        Po skonfigurowaniu, na stronie podglądu każdego pisma pojawi się sekcja
        <em>Wysyłka pocztą</em>. Aby wysłać list:
      </p>
      <ol class="ps-3 mb-2">
        <li>Do pisma musi być załączony plik PDF</li>
        <li>Wypełnij adres odbiorcy w formularzu</li>
        <li>Kliknij <em>Wyślij listem poleconym</em></li>
        <li>System przekaże PDF do Postivo.pl</li>
        <li>Status przesyłki możesz odświeżać na stronie pisma</li>
      </ol>
      <p class="mb-0 text-muted">
        Wysyłka jest bezpowrotna — sprawdź adres przed zleceniem.
      </p>
    </div>
  </div>

</div><!-- /col-xl-5 -->
</div><!-- /row -->

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
