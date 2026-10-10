<?php
/**
 * karty30/ti/dydaktyk/moj_certyfikat.php — prowadzący sam wystawia sobie certyfikat do podpisu protokołów.
 * Plik .p12 zostaje zapisany w systemie (inc/tiCertStore), mail tylko informuje o wygenerowaniu (includes/ti_cert_issue.php). Kierownik może to zrobić
 * też za prowadzącego — ta strona dotyczy tylko własnego konta.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/x509_login.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_cert_issue.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/webauthn.php';
require_once dirname(dirname(dirname(__DIR__))) . '/inc/tiCertStore/tiCertStore.php';
webauthn_migrate();

$me      = dyd_require();
$uid     = (int)$me['user_id'];
$me_name = (string)($me['name'] ?? '');
$klucze  = webauthn_get_credentials($uid);
karty30_migrate();
x509_init();

$ma   = x509_user_has_active_cert($uid);
$konto = db_one("SELECT name, email, phone_number FROM users WHERE id = ?", [$uid]);
$brak_tel   = preg_replace('/\D/', '', (string)($konto['phone_number'] ?? '')) === '';
$brak_email = trim((string)($konto['email'] ?? '')) === '';

// Dane aktywnego certyfikatu — pokazywane w oknie kreatora, żeby było widać, co zostanie zastąpione.
$cert = db_one(
    "SELECT numer, subject_cn, issued_at, valid_to FROM admin_x509_certs
      WHERE user_id = ? AND revoked_at IS NULL AND valid_to > datetime('now')
      ORDER BY id DESC LIMIT 1",
    [$uid]
);
$cert_html = $cert
    ? '<table class="table table-sm small mb-3"><tbody>'
      . '<tr><th scope="row" class="fw-normal text-body-secondary">Numer</th><td class="font-monospace">' . h((string)($cert['numer'] ?: '—')) . '</td></tr>'
      . '<tr><th scope="row" class="fw-normal text-body-secondary">Podmiot</th><td>' . h((string)$cert['subject_cn']) . '</td></tr>'
      . '<tr><th scope="row" class="fw-normal text-body-secondary">Wystawiony</th><td>' . h(date('d.m.Y', strtotime((string)$cert['issued_at']))) . '</td></tr>'
      . '<tr><th scope="row" class="fw-normal text-body-secondary">Ważny do</th><td>' . h(date('d.m.Y', strtotime((string)$cert['valid_to']))) . '</td></tr>'
      . '</tbody></table>'
    : '<p class="small mb-3 text-body-secondary">Nie masz aktywnego certyfikatu — zostanie wystawiony nowy.</p>';

// Dane, na które zostanie wystawiony nowy certyfikat (ważność jak w generatorze: 730 dni).
$nowy_html = '<div class="small mb-3"><div class="fw-semibold mb-1">Nowy certyfikat będzie wystawiony na:</div>'
    . '<table class="table table-sm small mb-0"><tbody>'
    . '<tr><th scope="row" class="fw-normal text-body-secondary">Imię i nazwisko</th><td>' . h((string)($konto['name'] ?? '')) . '</td></tr>'
    . '<tr><th scope="row" class="fw-normal text-body-secondary">Organizacja</th><td>Fundacja FEER</td></tr>'
    . '<tr><th scope="row" class="fw-normal text-body-secondary">E-mail (plik)</th><td>' . h((string)($konto['email'] ?? '')) . '</td></tr>'
    . '<tr><th scope="row" class="fw-normal text-body-secondary">Ważny od</th><td>' . h(date('d.m.Y')) . '</td></tr>'
    . '<tr><th scope="row" class="fw-normal text-body-secondary">Ważny do</th><td>' . h(date('d.m.Y', strtotime('+730 days'))) . '</td></tr>'
    . '</tbody></table></div>';

// Odwołanie własnego certyfikatu i usunięcie własnego klucza — zawsze dostępne (także przed
// terminem otwarcia generowania): to zabezpieczenie, np. gdy ktoś poznał hasło.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['_op'] ?? '', ['revoke_cert', 'delete_key'], true)) {
    dyd_token_check();
    if ($_POST['_op'] === 'revoke_cert') {
        $cid = (int)($_POST['cert_id'] ?? 0);
        $c   = db_one("SELECT id, numer FROM admin_x509_certs WHERE id = ? AND user_id = ? AND revoked_at IS NULL", [$cid, $uid]);
        if (!$c) {
            flash_set('danger', 'Nie ma takiego aktywnego certyfikatu na Twoim koncie.');
        } else {
            x509_revoke((int)$c['id']);
            tiCertStorePurgeRevoked($uid);   // zapisany plik odwołanego certyfikatu znika z systemu
            ti_cert_sms((string)($konto['phone_number'] ?? ''), 'FEER: odwolano certyfikat ' . ($c['numer'] ?: '') . ' na Twoja prosbe. Nie bedzie dzialac przy podpisie protokolow.', 'ti_dydaktyka_notice');
            flash_set('success', 'Certyfikat ' . ($c['numer'] ?: '') . ' został odwołany i nie zadziała przy podpisie. Możesz wygenerować nowy.');
        }
    } else {
        $ok = webauthn_delete_credential((int)($_POST['key_id'] ?? 0), $uid);
        flash_set($ok ? 'success' : 'danger', $ok ? 'Klucz usunięty — nie zadziała już przy podpisie protokołu.' : 'Nie ma takiego klucza na Twoim koncie.');
    }
    header('Location: moj_certyfikat.php'); exit;
}

// Ustawienie własnego hasła: nowy certyfikat z tym hasłem, stare certyfikaty odwołane.
// Przed jakąkolwiek zmianą (także odwołaniem starego certyfikatu) sprawdzamy termin otwarcia.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !ti_cert_generation_open()) {
    dyd_token_check();
    flash_set('danger', ti_cert_generation_info());
    header('Location: moj_certyfikat.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'pwd') {
    dyd_token_check();
    $p1 = (string)($_POST['new_password'] ?? '');
    $p2 = (string)($_POST['new_password2'] ?? '');
    if ($p1 !== $p2) {
        flash_set('danger', 'Hasła nie są takie same.');
    } else {
        $r = ti_cert_set_own_password($uid, $p1);
        if ($r['ok']) flash_set('success', 'Certyfikat wygenerowany i zapisany w systemie. Stare certyfikaty są odwołane. Przy podpisie protokołu kliknij „Odczytaj certyfikat”.');
        else flash_set('danger', $r['error']);
    }
    header('Location: moj_certyfikat.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $tel = trim((string)($_POST['phone'] ?? ''));
    if ($brak_tel && strlen(preg_replace('/\D/', '', $tel)) < 9) {
        flash_set('danger', 'Podaj numer telefonu (min. 9 cyfr), na który przyjdzie hasło SMS-em.');
    } elseif ($brak_email) {
        flash_set('danger', 'Brak adresu e-mail w koncie — zgłoś to kierownikowi.');
    } else {
        if ($brak_tel) db_exec("UPDATE users SET phone_number = ? WHERE id = ?", [$tel, $uid]);
        if ($ma) {
            // Nowy certyfikat zastępuje stary — stary zostaje odwołany.
            foreach (x509_list_for_user($uid) as $c) {
                if (empty($c['revoked_at']) && strtotime((string)$c['valid_to']) > time()) x509_revoke((int)$c['id']);
            }
        }
        $wynik = ti_cert_issue_for_instructor($uid, $me_name);
        if (!$wynik['ok']) {
            flash_set('danger', $wynik['error']);
        } elseif ($wynik['sms']) {
            flash_set('success', 'Certyfikat wygenerowany i zapisany w systemie. Hasło przyszło SMS-em.');
        } else {
            // SMS się nie udał — hasło pokazujemy tylko raz, na tym ekranie.
            flash_set('warning', 'Certyfikat wygenerowany i zapisany w systemie, ale SMS z hasłem się nie wysłał. Hasło: ' . $wynik['password'] . ' — zapisz je teraz, drugi raz nie zostanie pokazane.');
        }
    }
    header('Location: moj_certyfikat.php'); exit;
}

$KP_TITLE      = 'Mój certyfikat — Panel dydaktyka';
$KP_TOPBAR     = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<link rel="stylesheet" href="../assets/ti_skin.css">

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1300px">
<?= flash_html() ?>

<h1 class="h4 fw-bold mb-2"><i class="bi bi-shield-check text-primary me-2" aria-hidden="true"></i>Mój certyfikat</h1>
<p class="text-body-secondary small mb-3">
  Certyfikat podpisuje Twoje protokoły miesięczne. Możesz go wygenerować samodzielnie: hasło ustawiasz sam,
  a certyfikat zostaje zapisany w systemie. Przy podpisie protokołu klikasz „Odczytaj certyfikat”.
</p>

<div class="row g-4">
<div class="col-lg-6">
<div class="card border-0 shadow-sm">
  <div class="card-body">
    <p class="mb-2">Status: <?= $ma ? '<span class="badge bg-success">aktywny</span>' : '<span class="badge bg-secondary">brak</span>' ?></p>
    <?= $cert_html ?>
    <?php if (!ti_cert_generation_open()): ?>
    <div class="alert alert-info small py-2"><i class="bi bi-calendar-event" aria-hidden="true"></i> <?= h(ti_cert_generation_info()) ?></div>
    <?php endif; ?>

    <?php if ($brak_email): ?>
    <div class="alert alert-warning small py-2">Konto nie ma adresu e-mail. Poproś kierownika o jego uzupełnienie.</div>
    <?php else: ?>
    <p class="small mb-2">
      Hasło do pliku ustawiasz <strong>sam</strong> w kreatorze — system go nie losuje i nigdzie nie wysyła.
      Certyfikat zostanie zapisany w systemie, a na e-mail przyjdzie tylko informacja o jego wygenerowaniu.
      <?= $ma ? 'Obecny certyfikat przestanie wtedy działać.' : '' ?>
    </p>
    <button type="button" class="btn <?= $ma ? 'btn-outline-warning' : 'btn-primary' ?>" data-bs-toggle="modal" data-bs-target="#ustawHaslo" <?= ti_cert_generation_open() ? '' : 'disabled' ?>>
      <i class="bi bi-<?= $ma ? 'arrow-repeat' : 'key' ?> me-1" aria-hidden="true"></i><?= $ma ? 'Wygeneruj nowy certyfikat' : 'Wygeneruj certyfikat' ?>
    </button>
    <?php endif; ?>
  </div>
</div>

<div class="card border-0 shadow-sm mt-3">
  <div class="card-body">
    <h2 class="h6 fw-semibold mb-2">Hasło do pliku certyfikatu</h2>
    <p class="small mb-0">Hasło wybierasz sam w kreatorze (przycisk powyżej). Zapamiętaj je — nie zapisujemy go i nie wysyłamy.</p>
  </div>
</div>

<?php if (!$brak_email): ?>
<div class="modal fade" id="ustawHaslo" tabindex="-1" aria-labelledby="ustawHasloLbl" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" id="kreatorForm" onsubmit="return confirm('Wygenerować certyfikat z tym hasłem? Stare certyfikaty zostaną odwołane.');">
        <div class="modal-header">
          <h2 class="modal-title h5" id="ustawHasloLbl">Certyfikat — kreator</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="pwd">
          <div class="small text-body-secondary mb-3" id="krokInfo">Krok 1 z 2: hasło</div>

          <div class="kr-step" data-step="1">
            <?= $cert_html ?><?= $nowy_html ?>
            <p class="small mb-3">Najpierw wybierz hasło do pliku certyfikatu. Zapamiętaj je — nie zapisujemy go i nie wysyłamy.</p>
            <div class="alert alert-danger small py-2 d-none" id="krokErr" role="alert"></div>
            <div class="mb-2">
              <label class="form-label small mb-1" for="newPwd">Hasło (min. 16 znaków, min. 3 rodzaje znaków)</label>
              <input type="password" class="form-control" id="newPwd" name="new_password" minlength="16" autocomplete="new-password" required>
              <div class="progress mt-2" style="height:6px"><div class="progress-bar" id="pwdBar" role="progressbar" style="width:0%"></div></div>
              <div class="small mt-1" id="pwdLabel" aria-live="polite"></div>
              <ul class="small mb-0 mt-1 ps-3 text-body-secondary" id="pwdRules">
                <li data-rule="len">co najmniej 16 znaków</li>
                <li data-rule="cls">co najmniej 3 rodzaje znaków (małe, wielkie, cyfry, znaki specjalne)</li>
              </ul>
            </div>
            <div>
              <label class="form-label small mb-1" for="newPwd2">Powtórz hasło</label>
              <input type="password" class="form-control" id="newPwd2" name="new_password2" minlength="16" autocomplete="new-password" required>
            </div>
          </div>

          <div class="kr-step d-none" data-step="2">
            <?= $cert_html ?><?= $nowy_html ?>
            <p class="small mb-2">Potwierdź, a system wygeneruje certyfikat:</p>
            <ul class="small mb-0 ps-3">
              <li>plik certyfikatu (.p12) przyjdzie na adres <strong><?= h((string)($konto['email'] ?? '')) ?></strong>,</li>
              <li><strong>stare certyfikaty przestaną działać</strong>,</li>
              <li>hasła nie wysyłamy nigdzie — podasz je przy zatwierdzaniu protokołu.</li>
            </ul>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="button" class="btn btn-outline-secondary d-none" id="krokWstecz">Wstecz</button>
          <button type="button" class="btn btn-primary" id="krokDalej">Dalej</button>
          <button type="submit" class="btn btn-success d-none" id="krokWyslij">Wygeneruj i wyślij</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
  var form = document.getElementById('kreatorForm');
  if (!form) return;
  var p1 = document.getElementById('newPwd'), p2 = document.getElementById('newPwd2');
  var err = document.getElementById('krokErr');

  // Sprawdzacz siły hasła: długość i liczba rodzajów znaków, na żywo.
  var bar = document.getElementById('pwdBar'), lbl = document.getElementById('pwdLabel');
  var rule = function (k) { return document.querySelector('#pwdRules [data-rule="' + k + '"]'); };
  function sila() {
    var v = p1.value, len = v.length;
    var klasy = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z\d]/].filter(function (r) { return r.test(v); }).length;
    var punkty = (len >= 16 ? 2 : len >= 12 ? 1 : 0) + (len >= 20 ? 1 : 0) + (klasy >= 3 ? 2 : klasy >= 2 ? 1 : 0);
    var proc = Math.min(100, Math.round(punkty / 5 * 100));
    var kolor = punkty >= 4 ? 'bg-success' : punkty >= 3 ? 'bg-warning' : 'bg-danger';
    var opis  = punkty >= 4 ? 'mocne' : punkty >= 3 ? 'średnie' : (len === 0 ? '' : 'słabe');
    bar.style.width = (len === 0 ? 0 : proc) + '%';
    bar.className = 'progress-bar ' + kolor;
    lbl.textContent = len === 0 ? '' : 'Siła hasła: ' + opis + ' (' + len + ' znaków)';
    // Spełnienie wymagania pokazujemy tekstem (✓ / •), nie tylko kolorem (WCAG 1.4.1).
    var okLen = len >= 16, okCls = klasy >= 3;
    rule('len').textContent = (okLen ? '✓ spełnione: ' : '• wymaganie: ') + 'co najmniej 16 znaków';
    rule('cls').textContent = (okCls ? '✓ spełnione: ' : '• wymaganie: ') + 'co najmniej 3 rodzaje znaków (małe, wielkie, cyfry, znaki specjalne)';
    rule('len').classList.toggle('text-success', okLen);
    rule('cls').classList.toggle('text-success', okCls);
  }
  p1.addEventListener('input', sila);
  function show(n) {
    form.querySelectorAll('.kr-step').forEach(function (s) { s.classList.toggle('d-none', Number(s.dataset.step) !== n); });
    document.getElementById('krokInfo').textContent = 'Krok ' + n + ' z 2: ' + (n === 1 ? 'hasło' : 'potwierdzenie');
    document.getElementById('krokWstecz').classList.toggle('d-none', n === 1);
    document.getElementById('krokDalej').classList.toggle('d-none', n === 2);
    document.getElementById('krokWyslij').classList.toggle('d-none', n !== 2);
  }
  function blad(t) { err.textContent = t; err.classList.remove('d-none'); }
  document.getElementById('krokDalej').addEventListener('click', function () {
    if (p1.value.length < 16) return blad('Hasło musi mieć co najmniej 16 znaków.');
    var klasy = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z\d]/].filter(function (r) { return r.test(p1.value); }).length;
    if (klasy < 3) return blad('Hasło musi zawierać co najmniej 3 rodzaje znaków: małe litery, wielkie litery, cyfry, znaki specjalne.');
    if (p1.value !== p2.value) return blad('Hasła nie są takie same.');
    err.classList.add('d-none');
    show(2);
  });
  document.getElementById('krokWstecz').addEventListener('click', function () { show(1); });
  document.getElementById('ustawHaslo').addEventListener('hidden.bs.modal', function () {
    show(1); p1.value = ''; p2.value = '';
  });
})();
</script>
<?php endif; ?>

<div class="card border-0 shadow-sm mt-3">
  <div class="card-body">
    <h2 class="h6 fw-semibold mb-2">Zamiast certyfikatu: klucz YubiKey</h2>
    <p class="small mb-2">
      Możesz zamiast certyfikatu używać klucza sprzętowego (YubiKey). Zatwierdzasz nim protokół bez pliku .p12 i bez SMS-a.
      Klucz zostaje w Twoich rękach — w systemie zapisujemy tylko jego identyfikator.
    </p>
    <p class="small mb-3">
      Zarejestrowane klucze: <strong><?= count($klucze) ?></strong>
      <?php if ($klucze): ?> (ostatni użyty: <?= h((string)($klucze[0]['last_used_at'] ?? '—')) ?>)<?php endif; ?>
    </p>
    <button type="button" class="btn btn-outline-success" id="ykRegBtn">
      <i class="bi bi-usb-drive me-1" aria-hidden="true"></i>Dodaj klucz YubiKey
    </button>
    <div class="small text-danger mt-2 d-none" id="ykRegErr" role="alert"></div>
  </div>
</div>
<script>
(function () {
  var b = document.getElementById('ykRegBtn'); if (!b) return;
  var base = <?= json_encode(APP_URL . '/karty30/ti/dydaktyk/yk_register.php') ?>;
  var tok  = <?= json_encode(dyd_token()) ?>;
  var err  = document.getElementById('ykRegErr');
  function b64uToBuf(s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '=';
    var bin = atob(s), a = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i); return a.buffer; }
  function bufToB64u(buf) { var u = new Uint8Array(buf), s = ''; for (var i = 0; i < u.length; i++) s += String.fromCharCode(u[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  function blad(t) { err.textContent = t; err.classList.remove('d-none'); }
  b.addEventListener('click', async function () {
    err.classList.add('d-none'); b.disabled = true;
    try {
      var r = await fetch(base + '?action=begin', { credentials: 'same-origin' });
      var j = await r.json(); if (!r.ok) throw new Error(j.error || 'Nie udało się rozpocząć rejestracji.');
      var o = j.data;
      var cred = await navigator.credentials.create({ publicKey: {
        challenge: b64uToBuf(o.challenge),
        rp: o.rp,
        user: { id: b64uToBuf(o.user.id), name: o.user.name, displayName: o.user.displayName },
        pubKeyCredParams: o.pubKeyCredParams,
        timeout: o.timeout || 60000,
        attestation: o.attestation || 'none',
        excludeCredentials: (o.excludeCredentials || []).map(function (c) { return { type: 'public-key', id: b64uToBuf(c.id) }; }),
        authenticatorSelection: o.authenticatorSelection || { userVerification: 'preferred' } } });
      var resp = { id: bufToB64u(cred.rawId), rawId: bufToB64u(cred.rawId), type: cred.type, response: {
        clientDataJSON: bufToB64u(cred.response.clientDataJSON),
        attestationObject: bufToB64u(cred.response.attestationObject) } };
      var r2 = await fetch(base + '?action=complete', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tok }, body: JSON.stringify(resp) });
      var j2 = await r2.json(); if (!r2.ok) throw new Error(j2.error || 'Nie udało się zapisać klucza.');
      location.reload();
    } catch (e) { blad(e.message || 'Nie udało się dodać klucza.'); b.disabled = false; }
  });
})();
</script>


</div><!-- /lewa kolumna -->

<?php
  // Prawa kolumna: wszystkie certyfikaty i klucze konta, rozpoznawane po odcisku (fingerprint).
  $_certs = x509_list_for_user($uid);
  $_w_sys = [];
  try { tiCertStoreMigrate(); foreach (db_all("SELECT fingerprint FROM k30_ti_cert_store WHERE user_id = ?", [$uid]) as $_r) $_w_sys[$_r['fingerprint']] = true; } catch (\Throwable $e) {}
  $_fp = fn(string $x) => strtoupper(implode(':', str_split(substr($x, 0, 16), 2))) . '…';
?>
<div class="col-lg-6">
  <section class="card border-0 shadow-sm" aria-labelledby="mcListaH">
    <div class="card-body">
      <h2 class="h6 fw-semibold mb-1" id="mcListaH"><i class="bi bi-fingerprint me-1" aria-hidden="true"></i>Moje certyfikaty i klucze</h2>
      <p class="small text-body-secondary mb-2">Każdy certyfikat i klucz ma swój odcisk (fingerprint) — po nim go rozpoznasz w protokole.
        Odwołany certyfikat i usunięty klucz od razu przestają działać.</p>
      <label class="form-label small fw-semibold mb-1" for="mcSzukaj">Szukaj po odcisku</label>
      <input type="search" class="form-control form-control-sm mb-3 font-monospace" id="mcSzukaj" placeholder="np. 9F:3A albo 9f3a" autocomplete="off"
             aria-describedby="mcSzukajWynik">
      <p class="visually-hidden" role="status" aria-live="polite" id="mcSzukajWynik"></p>

      <h3 class="h6 fw-semibold mb-2">Certyfikaty (<?= count($_certs) ?>)</h3>
      <?php if (!$_certs): ?>
      <p class="small text-body-secondary">Nie masz jeszcze certyfikatu.</p>
      <?php else: ?>
      <div class="table-responsive mb-3">
        <table class="table table-sm align-middle small mb-0">
          <caption class="visually-hidden">Twoje certyfikaty z odciskiem, stanem i ważnością</caption>
          <thead><tr><th scope="col">Numer</th><th scope="col">Odcisk</th><th scope="col">Stan</th><th scope="col">Ważny do</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
          <tbody>
          <?php foreach ($_certs as $_c):
            $_odw = !empty($_c['revoked_at']);
            $_wyg = !$_odw && strtotime((string)$_c['valid_to']) <= time(); ?>
            <tr data-fp="<?= h(strtolower((string)$_c['fingerprint'])) ?>">
              <td class="font-monospace"><?= h((string)($_c['numer'] ?: '—')) ?></td>
              <td><code title="<?= h((string)$_c['fingerprint']) ?>"><?= h($_fp((string)$_c['fingerprint'])) ?></code></td>
              <td>
                <?php if ($_odw): ?><span class="badge text-bg-secondary">odwołany</span>
                <?php elseif ($_wyg): ?><span class="badge text-bg-warning">wygasł</span>
                <?php else: ?><span class="badge text-bg-success">aktywny</span><?php endif; ?>
                <?php if (!empty($_w_sys[$_c['fingerprint']])): ?><span class="badge text-bg-light border text-dark">w systemie</span><?php endif; ?>
              </td>
              <td><?= h(date('d.m.Y', strtotime((string)$_c['valid_to']))) ?></td>
              <td class="text-end">
                <?php if (!$_odw && !$_wyg): ?>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Odwołać certyfikat <?= h((string)($_c['numer'] ?: '')) ?>? Przestanie działać przy podpisie protokołów. Tego nie da się cofnąć.');">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="revoke_cert">
                  <input type="hidden" name="cert_id" value="<?= (int)$_c['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Odwołaj<span class="visually-hidden"> certyfikat <?= h((string)($_c['numer'] ?: '')) ?></span>
                  </button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <h3 class="h6 fw-semibold mb-2">Klucze bezpieczeństwa (<?= count($klucze) ?>)</h3>
      <?php if (!$klucze): ?>
      <p class="small text-body-secondary mb-0">Nie masz zarejestrowanego klucza.</p>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle small mb-0">
          <caption class="visually-hidden">Twoje klucze bezpieczeństwa z odciskiem</caption>
          <thead><tr><th scope="col">Nazwa</th><th scope="col">Odcisk</th><th scope="col">Dodany</th><th scope="col">Ostatnio użyty</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
          <tbody>
          <?php foreach ($klucze as $_k):
            // Odcisk klucza = SHA-256 identyfikatora klucza (credential_id) — stały, niezależny od nazwy.
            $_kfp = hash('sha256', (string)$_k['credential_id']); ?>
            <tr data-fp="<?= h($_kfp) ?>">
              <td><?= h((string)($_k['name'] ?: 'Klucz')) ?></td>
              <td><code title="<?= h(strtoupper($_kfp)) ?>"><?= h($_fp($_kfp)) ?></code></td>
              <td><?= h($_k['created_at'] ? date('d.m.Y', strtotime((string)$_k['created_at'])) : '—') ?></td>
              <td><?= h($_k['last_used_at'] ? date('d.m.Y H:i', strtotime((string)$_k['last_used_at'])) : 'nigdy') ?></td>
              <td class="text-end">
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ten klucz? Przestanie działać przy podpisie protokołów.');">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="delete_key">
                  <input type="hidden" name="key_id" value="<?= (int)$_k['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń<span class="visually-hidden"> klucz <?= h((string)($_k['name'] ?: '')) ?></span>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </section>
</div>
</div><!-- /.row -->
<script>
// Szukaj po odcisku: dopasowanie bez dwukropków i wielkości liter, w obu tabelach naraz.
(function () {
  var inp = document.getElementById('mcSzukaj'), wynik = document.getElementById('mcSzukajWynik');
  if (!inp) return;
  inp.addEventListener('input', function () {
    var q = inp.value.toLowerCase().replace(/[^0-9a-f]/g, ''), n = 0, rows = document.querySelectorAll('tr[data-fp]');
    rows.forEach(function (tr) { var ok = !q || tr.dataset.fp.indexOf(q) !== -1; tr.hidden = !ok; if (ok) n++; });
    wynik.textContent = q ? ('Pasujące pozycje: ' + n + ' z ' + rows.length) : '';
  });
})();
</script>

<p class="small text-body-secondary mt-3">
  Przy zatwierdzaniu protokołu klikasz „Odczytaj certyfikat” i wpisujesz hasło do niego oraz kod z SMS-a. Hasło nie jest zapisywane w systemie.
</p>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
