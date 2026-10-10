<?php
/**
 * tozsamosc/kursant_klucz.php — klucze sprzętowe / passkey kursanta (WebAuthn) w module Tożsamość.
 * Strona wymaga zalogowanej sesji kursanta. Parametr ?api=begin|complete wywołuje JS rejestracji klucza.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/includes/ti_messages.php';
require_once dirname(__DIR__) . '/includes/ti_student_webauthn.php';
require_once dirname(__DIR__) . '/karty30/ti/kursant/auth.php';

karty30_migrate();
ti_sw_migrate();
$student = student_current();
if (!$student) { header('Location: ' . APP_URL . '/tozsamosc/kursant.php'); exit; }
$acc_id = (int)$student['id'];
$acc    = db_one("SELECT login FROM k30_ti_student_accounts WHERE id = ?", [$acc_id]);

// ── API (JSON) ───────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new \RuntimeException('Metoda niedozwolona.');
        csrf_check();
        $api = (string)$_GET['api'];
        if ($api === 'begin') {
            echo json_encode(ti_sw_begin_register($acc_id, (string)($acc['login'] ?? 'kursant')));
        } elseif ($api === 'complete') {
            $payload = json_decode((string)($_POST['data'] ?? ''), true);
            if (!is_array($payload)) throw new \RuntimeException('Brak danych klucza.');
            ti_sw_complete_register($payload, trim((string)($_POST['name'] ?? '')));
            ti_account_log($acc_id, 'webauthn_added', 'Dodano klucz sprzętowy (Tożsamość).');
            echo json_encode(['ok' => true]);
        } else {
            throw new \RuntimeException('Nieznana operacja.');
        }
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Usuwanie klucza ───────────────────────────────────────────────────────────
$blad = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usun'])) {
    csrf_check();
    ti_sw_delete($acc_id, (int)$_POST['usun']);
    ti_account_log($acc_id, 'webauthn_removed', 'Usunięto klucz sprzętowy (Tożsamość).');
    header('Location: ' . APP_URL . '/tozsamosc/kursant_klucz.php?ok=usun'); exit;
}

$klucze = ti_sw_list($acc_id);
$ok     = $_GET['ok'] ?? '';
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Klucze sprzętowe — System Tożsamości</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { font-family: "Segoe UI", system-ui, sans-serif; background: #f3f4f6; margin: 0; }
  .metro-bar { background: #1d1d1d; color: #fff; padding: .75rem 1rem; font-weight: 600; }
  .metro-card { background: #fff; padding: 2rem; max-width: 560px; margin: 2rem auto; }
  .metro-btn { border-radius: 0; background: #1ba1e2; border-color: #1ba1e2; color: #fff; font-weight: 600; }
  .metro-btn:hover { background: #1790c9; border-color: #1790c9; color: #fff; }
  :focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
</style>
</head>
<body>
<header class="metro-bar">System Tożsamości · Klucze sprzętowe</header>
<main class="metro-card">
  <h1 class="h5 fw-bold mb-2">Klucze sprzętowe i passkey</h1>
  <div class="small mb-3">
    <p class="mb-2"><strong>Co to jest klucz sprzętowy?</strong> To dodatkowy sposób logowania. Zamiast czekać na kod SMS, potwierdzasz logowanie dotknięciem klucza (np. YubiKey) albo odciskiem palca lub PIN-em w telefonie czy komputerze (passkey).</p>
    <p class="mb-2">Klucz zostaje na Twoim urządzeniu. Serwer zapisuje tylko jego część publiczną, więc nie da się go skopiować z serwera ani ukraść przez hasło.</p>
    <p class="mb-0">Możesz mieć kilka kluczy. Logowanie kluczem działa po pierwszym logowaniu i po ustawieniu obrazka bezpieczeństwa.</p>
  </div>

  <?php if ($ok === 'usun'): ?><div class="alert alert-success small py-2 rounded-0" role="status">Klucz usunięty.</div><?php endif; ?>
  <?php if ($ok === 'dodany'): ?><div class="alert alert-success small py-2 rounded-0" role="status">Klucz dodany.</div><?php endif; ?>
  <div id="kl-blad" class="alert alert-danger small py-2 rounded-0 d-none" role="alert"></div>

  <?php if (!$klucze): ?>
  <div class="alert alert-info small py-2 rounded-0" role="status">Nie masz jeszcze klucza. Zalecamy dodać go teraz: logowanie będzie szybsze, bez czekania na SMS.</div>
  <?php else: ?>
  <ul class="list-group mb-3">
    <?php foreach ($klucze as $k): ?>
    <li class="list-group-item d-flex justify-content-between align-items-center rounded-0">
      <span><strong><?= h($k['name']) ?></strong>
        <span class="small text-body-secondary d-block">dodany: <?= h(substr((string)$k['created_at'], 0, 16)) ?><?= $k['last_used_at'] ? ' · użyty: ' . h(substr((string)$k['last_used_at'], 0, 16)) : '' ?></span>
      </span>
      <form method="post" onsubmit="return confirm('Usunąć ten klucz?')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="usun" value="<?= (int)$k['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger rounded-0">Usuń</button>
      </form>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <form id="kl-form" class="d-flex flex-column gap-2">
    <label for="kl-name" class="form-label fw-semibold">Nazwa klucza</label>
    <input type="text" class="form-control" id="kl-name" maxlength="60" value="Mój klucz" required>
    <button type="submit" class="btn metro-btn mt-2" id="kl-dodaj">Dodaj klucz</button>
  </form>
  <p class="small mt-3 mb-0"><a href="<?= h(APP_URL) ?>/karty30/ti/kursant/index.php">Wróć do panelu kursanta</a></p>
</main>

<script>
(function () {
  var csrf = '<?= csrf_token() ?>';
  var api = '<?= h(APP_URL) ?>/tozsamosc/kursant_klucz.php?api=';
  var blad = document.getElementById('kl-blad');
  function b64uToBuf(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '=';
    var bin = atob(s), buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
  }
  function bufToB64u(buf) {
    var bytes = new Uint8Array(buf), bin = '';
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function pokazBlad(t) { blad.textContent = t; blad.classList.remove('d-none'); }
  function post(url, fields) {
    var fd = new FormData();
    fd.append('_csrf', csrf);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Błąd'); return j; });
    });
  }
  if (!window.PublicKeyCredential || !navigator.credentials) {
    pokazBlad('Ta przeglądarka nie obsługuje kluczy sprzętowych.');
    document.getElementById('kl-dodaj').disabled = true;
    return;
  }
  document.getElementById('kl-form').addEventListener('submit', function (e) {
    e.preventDefault();
    blad.classList.add('d-none');
    var name = document.getElementById('kl-name').value.trim() || 'Klucz';
    post(api + 'begin', {}).then(function (opts) {
      opts.challenge = b64uToBuf(opts.challenge);
      opts.user.id = b64uToBuf(opts.user.id);
      opts.excludeCredentials = (opts.excludeCredentials || []).map(function (c) { return { id: b64uToBuf(c.id), type: c.type }; });
      return navigator.credentials.create({ publicKey: opts });
    }).then(function (cred) {
      var data = {
        clientDataJSON: bufToB64u(cred.response.clientDataJSON),
        attestationObject: bufToB64u(cred.response.attestationObject)
      };
      return post(api + 'complete', { data: JSON.stringify(data), name: name });
    }).then(function () {
      window.location = '<?= h(APP_URL) ?>/tozsamosc/kursant_klucz.php?ok=dodany';
    }).catch(function (err) {
      pokazBlad(err && err.message ? err.message : 'Nie udało się dodać klucza.');
    });
  });
})();
</script>
</body>
</html>
