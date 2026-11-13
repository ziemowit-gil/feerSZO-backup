<?php
/**
 * modules/sso_logto/view/admin.php — ustawienia logowania jednokrotnego (Logto) i chmury plików (administrator).
 * Zmienne: $flash, $test, $deliveries, $jobs, $cfg.
 */
$PAGE_TITLE = 'Logowanie jednokrotne i chmura plików';
include dirname(__DIR__, 3) . '/includes/header.php';

require_once dirname(__DIR__, 3) . '/includes/sms.php';
$smsOn      = function_exists('sms_channel_ready') && sms_channel_ready();
$logtoOk    = $cfg['m2m_id'] !== '' && $cfg['has_secret'];
$cloudOk    = $cfg['cloud_url'] !== '' && $cfg['cloud_user'] !== '' && $cfg['has_cloud_pass'];
$base       = rtrim(APP_URL, '/');
$logtoHost  = rtrim($cfg['logto_url'], '/');
$pill = fn(bool $ok, string $yes, string $no) => '<span class="sso-b ' . ($ok ? 'sso-b--ok' : 'sso-b--off') . '">' . h($ok ? $yes : $no) . '</span>';
$copyField = function (string $id, string $label, string $value) {
    return '<label class="form-label small fw-semibold mb-1" for="' . h($id) . '">' . h($label) . '</label>'
         . '<div class="input-group input-group-sm"><input type="text" class="form-control font-monospace" id="' . h($id) . '" value="' . h($value) . '" readonly>'
         . '<button type="button" class="btn btn-outline-secondary" data-copy="' . h($id) . '">Kopiuj<span class="visually-hidden"> ' . h($label) . '</span></button></div>';
};
?>
<style>
  .sso-b { display: inline-block; padding: .15rem .5rem; border-radius: 999px; font-size: .75rem; font-weight: 600; }
  .sso-b--ok  { background: #dcfae6; color: #085d3a; }
  .sso-b--off { background: #f2f4f7; color: #344054; }
  .sso-steps li { margin-bottom: .25rem; }
  .sso-wrap :focus-visible { outline: 3px solid #1d2939; outline-offset: 2px; }
</style>

<div class="sso-wrap">
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-key" aria-hidden="true"></i> Logowanie jednokrotne i chmura plików</h1>
  <a href="<?= h($base) ?>/tozsamosc/index.php#administracja" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-arrow-left" aria-hidden="true"></i> Tożsamość › Administracja</a>
</div>

<p class="text-body-secondary small">Jedno konto i jedno hasło do aplikacji organizacji (Logto pod <?= h($logtoHost) ?>). Konto w chmurze plików ma zawsze to samo hasło co Logto — SZO ustawia je w obu miejscach naraz. Hasło do poczty jest odrębne.</p>

<?php if ($flash): ?>
  <div class="alert alert-<?= h($flash['type']) ?> small" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>
<?php if ($test): ?>
  <div class="alert alert-<?= $test['ok'] ? 'success' : 'danger' ?> small" role="status"><strong>Test: <?= h($test['what']) ?></strong><br><?= implode('<br>', array_map('h', $test['lines'])) ?></div>
<?php endif; ?>

<div class="row g-2 mb-4" aria-label="Stan konfiguracji">
  <div class="col-sm-6 col-lg"><div class="border rounded p-2 bg-white h-100"><div class="small text-body-secondary">Logto (zakładanie kont)</div><?= $pill($logtoOk, 'skonfigurowane', 'brak danych M2M') ?></div></div>
  <div class="col-sm-6 col-lg"><div class="border rounded p-2 bg-white h-100"><div class="small text-body-secondary">Webhook Logto → SZO</div><?= $pill($cfg['has_webhook'], 'klucz zapisany', 'brak klucza') ?></div></div>
  <div class="col-sm-6 col-lg"><div class="border rounded p-2 bg-white h-100"><div class="small text-body-secondary">SMS z Logto przez SZO</div><?= $pill($smsOn, 'dostawca SMS gotowy', 'brak dostawcy SMS') ?></div></div>
  <div class="col-sm-6 col-lg"><div class="border rounded p-2 bg-white h-100"><div class="small text-body-secondary">Chmura (<?= h(SsoConfig::CLOUD_KINDS[$cfg['cloud_kind']]) ?>)</div><?= $pill($cloudOk, 'skonfigurowana', 'nieskonfigurowana') ?></div></div>
  <div class="col-sm-6 col-lg"><div class="border rounded p-2 bg-white h-100"><div class="small text-body-secondary">Pliki z OneDrive</div><?= $pill($cfg['graph'] && $cloudOk, 'gotowe', $cfg['graph'] ? 'brak chmury' : 'brak Microsoft 365') ?></div></div>
</div>

<form method="post" action="<?= h(route('sso.settings')) ?>" id="ustawienia" autocomplete="off">
  <?= Router::csrfField() ?>

  <section class="card mb-4" aria-labelledby="ssoLogtoH">
    <div class="card-header"><h2 id="ssoLogtoH" class="h6 mb-0"><i class="bi bi-person-badge" aria-hidden="true"></i> Logto — zakładanie kont z SZO</h2></div>
    <div class="card-body row g-3">
      <div class="col-md-6">
        <label class="form-label small fw-semibold" for="ssoUrl">Adres Logto</label>
        <input type="url" class="form-control form-control-sm" id="ssoUrl" name="logto_url" value="<?= h($cfg['logto_url']) ?>" placeholder="<?= h(SsoConfig::DEFAULT_LOGTO_URL) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold" for="ssoM2m">App ID aplikacji Machine-to-machine</label>
        <input type="text" class="form-control form-control-sm font-monospace" id="ssoM2m" name="logto_m2m_id" value="<?= h($cfg['m2m_id']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold" for="ssoSecret">App secret</label>
        <input type="password" class="form-control form-control-sm" id="ssoSecret" name="logto_m2m_secret" value="" autocomplete="new-password" aria-describedby="ssoSecretH">
        <div class="form-text" id="ssoSecretH"><?= $cfg['has_secret'] ? 'Zapisany (zaszyfrowany). Zostaw puste, żeby nie zmieniać.' : 'Nie ustawiono.' ?></div>
      </div>
      <div class="col-md-6 d-flex align-items-end">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="self_enabled" value="1" id="ssoSelf" <?= $cfg['self'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="ssoSelf">Użytkownik sam zakłada konto w Tożsamości (przycisk „Utwórz konto”)</label>
        </div>
      </div>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="login_prompt" value="1" id="ssoPrompt" aria-describedby="ssoPromptH" <?= $cfg['prompt'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="ssoPrompt">Po każdym zalogowaniu proś osoby bez konta o założenie konta logowania jednokrotnego i w chmurze</label>
          <div class="form-text" id="ssoPromptH">Ekran z formularzem hasła pojawia się raz po zalogowaniu (po regulaminie i kreatorze), z przyciskiem „Nie teraz”. Działa, gdy włączone jest samodzielne zakładanie konta; konto w chmurze powstaje, gdy zaznaczysz to niżej w sekcji chmury.</div>
        </div>
      </div>
      <details class="col-12 small">
        <summary>Jak założyć aplikację Machine-to-machine w Logto</summary>
        <ol class="sso-steps mt-2">
          <li>Konsola administratora Logto → <strong>Applications</strong> → <strong>Create application</strong> → <strong>Machine-to-machine</strong>, nazwa „SZO”.</li>
          <li>W kroku ról przypisz rolę <strong>Logto Management API access</strong> (bez niej Logto odmówi zakładania kont).</li>
          <li>Skopiuj <strong>App ID</strong> i <strong>App secret</strong> do pól powyżej i zapisz. Potem „Testuj Logto”.</li>
          <li>W <strong>Sign-in experience</strong> włącz logowanie <strong>adresem e-mail lub nazwą użytkownika</strong> z hasłem — SZO zakłada konta z oboma.</li>
        </ol>
      </details>
    </div>
  </section>

  <section class="card mb-4" id="logowanie-szo" aria-labelledby="ssoSzoH">
    <div class="card-header"><h2 id="ssoSzoH" class="h6 mb-0"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Logowanie do SZO przez Logto</h2></div>
    <div class="card-body row g-3">
      <div class="col-12 small">
        <div class="alert alert-info py-2 mb-0" role="note">Microsoft 365 loguje do SZO tylko <strong>administratorów</strong> (rola admin, konto @feer.org.pl).
          Wszyscy pozostali — także koordynatorzy — logują się przez Logto albo e-mailem i hasłem. Administratorzy przez Logto się nie zalogują.</div>
      </div>
      <div class="col-md-6"><?= $copyField('ssoSzoRedirect', 'Redirect URI (wklej w Logto)', LogtoOidc::redirectUri()) ?></div>
      <div class="col-md-6"><?= $copyField('ssoSzoLogout', 'Post sign-out redirect URI (wklej w Logto)', LogtoOidc::postLogoutUri()) ?></div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold" for="ssoSzoId">App ID aplikacji SZO (Traditional web)</label>
        <input type="text" class="form-control form-control-sm" id="ssoSzoId" name="szo_app_id" value="<?= h($cfg['szo_app_id']) ?>" maxlength="64" spellcheck="false">
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold" for="ssoSzoSecret">App secret</label>
        <input type="password" class="form-control form-control-sm" id="ssoSzoSecret" name="szo_app_secret" value="" autocomplete="new-password" aria-describedby="ssoSzoSecretH">
        <div class="form-text" id="ssoSzoSecretH"><?= $cfg['has_szo_secret'] ? 'Zapisany (zaszyfrowany). Zostaw puste, żeby nie zmieniać.' : 'Nie ustawiono.' ?></div>
      </div>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="szo_login" value="1" id="ssoSzoOn" aria-describedby="ssoSzoOnH" <?= $cfg['szo_login'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="ssoSzoOn">Pokaż na ekranie logowania przycisk „Zaloguj przez logowanie jednokrotne”</label>
          <div class="form-text" id="ssoSzoOnH"><?= $cfg['szo_ready'] ? 'Aplikacja SZO jest skonfigurowana.' : 'Przycisk pojawi się, gdy uzupełnisz App ID i App secret.' ?> Po wylogowaniu z SZO kończy się też sesja w Logto.</div>
        </div>
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold" for="ssoMsOff">Data wyłączenia Microsoft 365</label>
        <input type="date" class="form-control form-control-sm" id="ssoMsOff" name="ms_off_date" value="<?= h($cfg['ms_off_date']) ?>" aria-describedby="ssoMsOffH">
        <div class="form-text" id="ssoMsOffH">Pokazywana w lewej kolumnie ekranu logowania („Odchodzimy od Microsoft 365”, odliczanie dni). Puste = bez daty.</div>
      </div>
      <details class="col-12 small">
        <summary>Jak założyć aplikację SZO w Logto</summary>
        <ol class="sso-steps mt-2">
          <li>Konsola Logto → <strong>Applications</strong> → <strong>Create application</strong> → <strong>Traditional web</strong> (np. „PHP”), nazwa „SZO”.</li>
          <li>W ustawieniach aplikacji wklej <strong>Redirect URI</strong> i <strong>Post sign-out redirect URI</strong> z pól powyżej i zapisz.</li>
          <li>Skopiuj <strong>App ID</strong> i <strong>App secret</strong> do pól obok, zaznacz przycisk na ekranie logowania i zapisz ustawienia.</li>
        </ol>
        <p class="mb-0">Konto w SZO odnajdujemy po powiązaniu z Logto (konta założone z SZO) albo po potwierdzonym adresie e-mail. Logowanie dwuetapowe SZO i klucze bezpieczeństwa działają jak przy logowaniu hasłem.</p>
      </details>
    </div>
  </section>

  <section class="card mb-4" aria-labelledby="ssoHookH">
    <div class="card-header"><h2 id="ssoHookH" class="h6 mb-0"><i class="bi bi-broadcast" aria-hidden="true"></i> Webhook Logto → SZO</h2></div>
    <div class="card-body row g-3">
      <div class="col-md-7"><?= $copyField('ssoHookUrl', 'Endpoint URL (wklej w Logto)', SsoConfig::webhookUrl()) ?></div>
      <div class="col-md-5">
        <label class="form-label small fw-semibold mb-1" for="ssoHookKey">Signing key z Logto</label>
        <input type="password" class="form-control form-control-sm" id="ssoHookKey" name="webhook_key" value="" autocomplete="new-password" aria-describedby="ssoHookKeyH">
        <div class="form-text" id="ssoHookKeyH"><?= $cfg['has_webhook'] ? 'Zapisany (zaszyfrowany). Zostaw puste, żeby nie zmieniać.' : 'Bez klucza SZO odrzuca webhook (503).' ?></div>
      </div>
      <details class="col-12 small">
        <summary>Jak dodać webhook w Logto</summary>
        <ol class="sso-steps mt-2">
          <li>Konsola Logto → <strong>Developer</strong> → <strong>Webhooks</strong> → <strong>Create webhook</strong>, nazwa „SZO”, Endpoint URL jak wyżej.</li>
          <li>Zdarzenia: <code>PostRegister</code>, <code>PostSignIn</code>, <code>PostResetPassword</code>, <code>User.Created</code>, <code>User.Data.Updated</code>, <code>User.Deleted</code>.</li>
          <li>Po utworzeniu skopiuj <strong>Signing key</strong> (sekcja „Secure webhook”) do pola obok i zapisz. „Send test payload” w Logto powinien dać odpowiedź 200.</li>
        </ol>
        <p class="mb-0">Co robi SZO: dowiązuje konta założone poza SZO (po adresie e-mail), zapisuje datę ostatniego logowania, odnotowuje zmianę hasła w Logto (chmura ma wtedy stare hasło) i odpina usunięte konta.</p>
      </details>
    </div>
  </section>

  <section class="card mb-4" aria-labelledby="ssoSmsH">
    <div class="card-header"><h2 id="ssoSmsH" class="h6 mb-0"><i class="bi bi-phone" aria-hidden="true"></i> SMS z Logto przez bramkę SMS SZO (łącznik „HTTP SMS”)</h2></div>
    <div class="card-body row g-3">
      <div class="col-md-7"><?= $copyField('ssoSmsUrl', 'Endpoint (pole „Endpoint” w Logto)', SsoConfig::smsUrl()) ?></div>
      <div class="col-md-5">
        <label class="form-label small fw-semibold mb-1" for="ssoSmsPrefix">Początek SMS-a</label>
        <input type="text" class="form-control form-control-sm" id="ssoSmsPrefix" name="sms_prefix" value="<?= h($cfg['sms_prefix']) ?>" placeholder="FEER" maxlength="20" aria-describedby="ssoSmsPrefixH">
        <div class="form-text" id="ssoSmsPrefixH">Np. „FEER: kod logowania 123456…”. Bez polskich liter.</div>
      </div>
      <div class="col-12 small">
        <ol class="sso-steps mb-2">
          <li>W SZO: <a href="<?= h($base) ?>/admin/api_manage.php?tab=api">Klucze API</a> → nowy klucz „Logto SMS” z zakresem <code>logto:sms</code>. Skopiuj klucz (widać go raz).</li>
          <li>W Logto: <strong>Connectors</strong> → <strong>Email and SMS connectors</strong> → <strong>SMS connector</strong> → <strong>HTTP SMS</strong>.</li>
          <li><strong>Endpoint</strong>: adres powyżej. <strong>Authorization Header</strong>: <code>Bearer </code> i klucz API (np. <code>Bearer szo_…</code>).</li>
          <li>„Test connection” na swój numer. SMS wychodzi przez dostawcę z Ustawień SMS SZO (także w nocy — to kody jednorazowe).</li>
        </ol>
        <p class="mb-0 text-body-secondary">Limity: 5 SMS-ów na numer na godzinę, 60 na godzinę i 300 na dobę łącznie. SZO wysyła tylko kody (4–8 cyfr) w stałym szablonie, nie dowolną treść.<?= $smsOn ? '' : ' <strong>Uwaga: w SZO nie ma skonfigurowanego dostawcy SMS (Administracja › Ustawienia SMS).</strong>' ?></p>
      </div>
    </div>
  </section>

  <section class="card mb-4" id="chmura" aria-labelledby="ssoCloudH">
    <div class="card-header"><h2 id="ssoCloudH" class="h6 mb-0"><i class="bi bi-cloud" aria-hidden="true"></i> Chmura plików — Nextcloud / ownCloud na osobnym serwerze</h2></div>
    <div class="card-body row g-3">
      <div class="col-md-3">
        <label class="form-label small fw-semibold" for="ssoKind">Rodzaj</label>
        <select class="form-select form-select-sm" id="ssoKind" name="cloud_kind">
          <?php foreach (SsoConfig::CLOUD_KINDS as $k => $l): ?><option value="<?= h($k) ?>" <?= $cfg['cloud_kind'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-9">
        <label class="form-label small fw-semibold" for="ssoCloudUrl">Adres chmury</label>
        <input type="url" class="form-control form-control-sm" id="ssoCloudUrl" name="cloud_url" value="<?= h($cfg['cloud_url']) ?>" placeholder="https://chmura.feer.org.pl">
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="ssoCloudUser">Login administratora chmury</label>
        <input type="text" class="form-control form-control-sm" id="ssoCloudUser" name="cloud_user" value="<?= h($cfg['cloud_user']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="ssoCloudPass">Hasło aplikacji administratora</label>
        <input type="password" class="form-control form-control-sm" id="ssoCloudPass" name="cloud_pass" value="" autocomplete="new-password" aria-describedby="ssoCloudPassH">
        <div class="form-text" id="ssoCloudPassH"><?= $cfg['has_cloud_pass'] ? 'Zapisane (zaszyfrowane). Zostaw puste, żeby nie zmieniać.' : 'Ustawienia → Bezpieczeństwo → „Utwórz nowe hasło aplikacji”.' ?></div>
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-semibold" for="ssoGroup">Grupa</label>
        <input type="text" class="form-control form-control-sm" id="ssoGroup" name="cloud_group" value="<?= h($cfg['cloud_group']) ?>" placeholder="np. FEER">
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-semibold" for="ssoQuota">Miejsce</label>
        <input type="text" class="form-control form-control-sm" id="ssoQuota" name="cloud_quota" value="<?= h($cfg['cloud_quota']) ?>" placeholder="np. 5 GB">
      </div>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="cloud_self" value="1" id="ssoCloudSelf" <?= $cfg['cloud_self'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="ssoCloudSelf">Gdy użytkownik sam zakłada konto w Tożsamości, od razu załóż mu też konto w chmurze (to samo hasło)</label>
        </div>
      </div>
      <div class="col-12 small">
        <p class="mb-1"><strong>Hasło w chmurze = hasło w Logto.</strong> SZO ustawia je w obu miejscach naraz: przy zakładaniu konta, przy nowym haśle od administratora i gdy użytkownik zmienia hasło w Tożsamości. Login w chmurze to nazwa użytkownika z Logto.</p>
        <p class="mb-1">To inna chmura niż magazyn plików TI (Admin › Magazyn plików / ownCloud) — tamte ustawienia zostają bez zmian.</p>
        <details>
          <summary>Logowanie do chmury przyciskiem „Zaloguj przez Logto” (zalecane)</summary>
          <ol class="sso-steps mt-2">
            <li>W Logto: Applications → Create → <strong>Traditional web</strong>, nazwa „Chmura”. Redirect URI: <code><?= h(($cfg['cloud_url'] ?: 'https://chmura…') . '/apps/user_oidc/code') ?></code> (z <code>/index.php</code> przed <code>/apps</code>, jeśli chmura nie ma ładnych adresów).</li>
            <li>W Nextcloud: aplikacja <strong>OpenID Connect user backend</strong> (user_oidc) → dodaj dostawcę: Discovery <code><?= h($logtoHost) ?>/oidc/.well-known/openid-configuration</code>, Client ID i secret z Logto, scope <code>openid email profile</code>.</li>
            <li>W ustawieniach dostawcy wyłącz „Use unique user ID”. Uwaga: konta w chmurze mają login imie.nazwisko, a nazwa użytkownika w Logto ma „_” zamiast kropki (Logto nie dopuszcza kropki). Mapowanie identyfikatora na <code>username</code> założy więc osobne konto z „_”. Żeby logowanie przez Logto trafiało na konto założone przez SZO, identyfikator musi pochodzić z pola z loginem imie.nazwisko (np. własne pole w tokenie Logto). W ownCloud odpowiednikiem jest aplikacja OpenID Connect.</li>
          </ol>
        </details>
      </div>
    </div>
  </section>

  <button type="submit" class="btn btn-primary btn-sm mb-2"><i class="bi bi-save" aria-hidden="true"></i> Zapisz ustawienia</button>
</form>

<div class="d-flex flex-wrap gap-2 mb-4">
  <form method="post" action="<?= h(route('sso.test')) ?>" class="m-0"><?= Router::csrfField() ?><button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-plug" aria-hidden="true"></i> Testuj Logto</button></form>
  <form method="post" action="<?= h(route('sso.testCloud')) ?>" class="m-0"><?= Router::csrfField() ?><button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cloud-check" aria-hidden="true"></i> Testuj chmurę</button></form>
</div>

<?php $renames = SsoAccounts::cloudUnderscoreList(); $renamed = SsoController::takeResult('loginy'); ?>
<section class="card mb-4" id="loginy" aria-labelledby="ssoRenameH">
  <div class="card-header"><h2 id="ssoRenameH" class="h6 mb-0"><i class="bi bi-person-lines-fill" aria-hidden="true"></i> Loginy w chmurze: imie.nazwisko</h2></div>
  <div class="card-body small">
    <?php if ($renamed): ?>
      <div class="alert alert-warning" role="status" tabindex="-1">
        <p class="fw-semibold mb-2"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Hasła widać tylko teraz. Po odświeżeniu strony znikną.</p>
        <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
          <caption class="visually-hidden">Wynik zmiany loginów w chmurze</caption>
          <thead><tr><th scope="col">Osoba</th><th scope="col">Login</th><th scope="col">Hasło</th><th scope="col">Stare konto</th><th scope="col">Logto</th><th scope="col">Pliki z OneDrive</th><th scope="col">SMS</th><th scope="col">E-mail</th></tr></thead>
          <tbody>
          <?php foreach ($renamed as $r): ?>
            <tr>
              <td><?= h($r['name']) ?><br><span class="text-body-secondary"><?= h($r['email']) ?></span></td>
              <?php if ($r['error'] !== ''): ?>
                <td colspan="7" class="text-danger-emphasis"><?= h($r['error']) ?></td>
              <?php else: ?>
                <td><code><?= h($r['old']) ?></code> <span aria-label="na">→</span> <code><?= h($r['new']) ?></code></td>
                <td><code class="sso-pass"><?= h((string)$r['password']) ?></code></td>
                <td><?= h($r['old_disabled'] ?: '—') ?></td>
                <td><?= h($r['logto'] ?: '—') ?></td>
                <td><?= h($r['files'] ?: '—') ?></td>
                <td><?= h($r['sms'] ?: '—') ?></td>
                <td><?= h($r['mail'] ?: '—') ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    <?php endif; ?>
    <p class="mb-2">Nowe konta w chmurze dostają login imie.nazwisko. Starsze konta mają login z „_” (z nazwy w logowaniu jednokrotnym, które nie dopuszcza kropki). Loginu w chmurze nie da się zmienić, więc dla zaznaczonych osób zakładamy nowe konto z nowym hasłem, a stare wyłączamy. Pliki na starym koncie zostają, nic nie kasujemy.</p>
    <p class="mb-2">Osoba dostaje SMS z nowym loginem i hasłem oraz e-mail o zmianie (bez hasła). Jeśli ma konto logowania jednokrotnego, dostaje ono to samo nowe hasło. Kopiowanie plików z OneDrive, jeśli było zlecone, rusza od nowa na nowe konto.</p>
    <?php if (!$renames): ?>
      <p class="mb-0"><i class="bi bi-check-circle" aria-hidden="true"></i> Wszystkie loginy w chmurze mają już postać imie.nazwisko.</p>
    <?php else: ?>
      <form method="post" action="<?= h(route('sso.cloudRename')) ?>" onsubmit="return confirm('Zmienić loginy zaznaczonym osobom? Każda dostanie nowe hasło SMS-em i e-mail o zmianie, a stare konto w chmurze zostanie wyłączone.')">
        <?= Router::csrfField() ?>
        <div class="table-responsive border rounded mb-2"><table class="table table-sm mb-0 align-middle">
          <caption class="visually-hidden">Konta w chmurze z „_” w loginie</caption>
          <thead class="table-light"><tr><th scope="col" style="width:2.5rem"><span class="visually-hidden">Zaznacz</span></th><th scope="col">Osoba</th><th scope="col">Stary login</th><th scope="col">Nowy login</th><th scope="col">Telefon</th></tr></thead>
          <tbody>
          <?php foreach ($renames as $i => $r): ?>
            <tr>
              <td><input class="form-check-input" type="checkbox" name="emails[]" value="<?= h($r['email']) ?>" id="ssoRn<?= $i ?>" checked></td>
              <td><label for="ssoRn<?= $i ?>" class="mb-0"><?= h($r['name'] ?: $r['email']) ?></label><br><span class="text-body-secondary"><?= h($r['email']) ?></span></td>
              <td><code><?= h($r['old']) ?></code></td>
              <td><code><?= h($r['new']) ?></code></td>
              <td><?= $r['phone'] !== '' ? h($r['phone']) : '<span class="text-danger-emphasis">brak numeru, SMS nie pójdzie</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <p class="text-body-secondary mb-2">Nowy login w tabeli to propozycja. Gdy jest zajęty przez inną osobę, dostanie numer na końcu (np. jan.kowalski2).</p>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Zmień loginy, wyślij SMS i e-mail</button>
      </form>
    <?php endif; ?>
  </div>
</section>

<?php $ncRows = SsoController::takeResult('ncuid'); $ncCount = SsoAccounts::cloudUidCandidates(); ?>
<section class="card mb-4" id="ncuid" aria-labelledby="ssoNcUidH">
  <div class="card-header"><h2 id="ssoNcUidH" class="h6 mb-0"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Logowanie do chmury przez logowanie jednokrotne</h2></div>
  <div class="card-body small">
    <p class="mb-2">Nextcloud loguje przez logowanie jednokrotne i bierze login z danych konta (<code>custom_data.<?= h(SsoAccounts::NC_UID_KEY) ?></code>).
      Nazwa użytkownika w logowaniu jednokrotnym nie może mieć kropki, więc login w chmurze (imie.nazwisko) zapisujemy tam osobno.
      Bez niego Nextcloud nie znajdzie istniejącego konta.</p>
    <p class="mb-2">Nowe konta dostają ten zapis same. Przycisk poniżej uzupełnia go kontom założonym wcześniej. Haseł nie zmienia i nic nie wysyła.
      Kont z logowaniem jednokrotnym i chmurą: <strong><?= (int)$ncCount ?></strong>.</p>
    <?php if ($ncRows !== null): $ncPreview = (bool)array_filter($ncRows, fn($r) => str_starts_with($r['result'], 'do zapisania'));
          $ncSum = array_count_values(array_map(fn($r) => preg_replace('/[:(].*$/u', '', $r['result']), $ncRows)); ?>
      <div class="alert alert-<?= $ncPreview ? 'info' : 'success' ?>" role="status" tabindex="-1">
        <p class="fw-semibold mb-2"><?= $ncPreview ? 'Podgląd — nic nie zapisano.' : 'Gotowe.' ?>
          <?= h(implode(', ', array_map(fn($k, $v) => trim($k) . ': ' . $v, array_keys($ncSum), $ncSum))) ?></p>
        <?php $ncShow = array_values(array_filter($ncRows, fn($r) => $r['result'] !== 'bez zmian')); if ($ncShow): ?>
        <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
          <caption class="visually-hidden">Wynik zapisu loginów chmury w logowaniu jednokrotnym</caption>
          <thead><tr><th scope="col">Osoba</th><th scope="col">Login w chmurze</th><th scope="col">Wynik</th></tr></thead>
          <tbody>
          <?php foreach ($ncShow as $r): ?>
            <tr><td><?= h($r['name'] ?: $r['email']) ?><br><span class="text-body-secondary"><?= h($r['email']) ?></span></td>
                <td><code><?= h($r['cloud_uid']) ?></code></td>
                <td class="<?= str_starts_with($r['result'], 'błąd') ? 'text-danger-emphasis' : '' ?>"><?= h($r['result']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2">
      <form method="post" action="<?= h(route('sso.ncUid')) ?>" class="m-0"><?= Router::csrfField() ?><input type="hidden" name="do" value="preview">
        <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-eye" aria-hidden="true"></i> Podgląd</button></form>
      <form method="post" action="<?= h(route('sso.ncUid')) ?>" class="m-0"><?= Router::csrfField() ?><input type="hidden" name="do" value="apply">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Zapisz loginy chmury w logowaniu jednokrotnym</button></form>
    </div>
  </div>
</section>

<?php $tmRes = SsoController::takeResult('zespoly'); $tmTeams = CloudTeamsSync::teams(); $tmWs = CloudTeamsSync::workspaces(); $tmKind = ['zespół' => 'Zespół', 'obszar' => 'Obszar']; ?>
<section class="card mb-4" id="zespoly" aria-labelledby="ssoTeamsH">
  <div class="card-header"><h2 id="ssoTeamsH" class="h6 mb-0"><i class="bi bi-people" aria-hidden="true"></i> Zespoły i obszary robocze z SZO jako grupy w chmurze</h2></div>
  <div class="card-body small">
    <?php if ($tmRes !== null): $tmDry = !empty($tmRes['dry']); ?>
      <div class="alert alert-<?= $tmRes['error'] !== '' ? 'danger' : ($tmDry ? 'info' : 'success') ?>" role="status" tabindex="-1" id="ssoTeamsResult">
        <p class="fw-semibold mb-2"><?= $tmRes['error'] !== '' ? 'Nie udało się połączyć z chmurą.' : ($tmDry ? 'Podgląd, nic nie zmieniono.' : 'Gotowe.') ?>
          <?= h(CloudTeamsSync::summary($tmRes)) ?></p>
        <?php if ($tmRes['teams']): ?>
        <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
          <caption class="visually-hidden">Wynik synchronizacji zespołów i obszarów roboczych z grupami w chmurze</caption>
          <thead><tr><th scope="col">Zespół albo obszar</th><th scope="col">Grupa w chmurze</th><th scope="col"><?= $tmDry ? 'Do dopisania' : 'Dopisani' ?></th><th scope="col"><?= $tmDry ? 'Do wypisania' : 'Wypisani' ?></th><th scope="col">Bez konta w chmurze</th></tr></thead>
          <tbody>
          <?php foreach ($tmRes['teams'] as $r): ?>
            <tr>
              <td><span class="text-body-secondary"><?= h($tmKind[$r['kind'] ?? 'zespół'] ?? '') ?>:</span> <?= h($r['name']) ?><?= !$r['active'] ? ' <span class="badge bg-secondary">wyłączony</span>' : '' ?></td>
              <td><code><?= h($r['gid']) ?></code><br><span class="text-body-secondary"><?= h($r['group']) ?><?= $r['renamed'] ? ', nazwa poprawiona' : '' ?></span>
                <?php if ($r['error'] !== ''): ?><br><span class="text-danger-emphasis"><?= h($r['error']) ?></span><?php endif; ?></td>
              <td><?= $r['added'] ? h(implode(', ', $r['added'])) : '—' ?></td>
              <td><?= $r['removed'] ? h(implode(', ', $r['removed'])) : '—' ?></td>
              <td><?= $r['without'] ? h(implode(', ', $r['without'])) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <p class="mb-2">Każdy aktywny zespół z modułu Zadań ma w chmurze grupę <code><?= h(CloudTeamsSync::PREFIX) ?>{numer}</code> z nazwą zespołu,
      a każdy aktywny obszar roboczy grupę <code><?= h(CloudTeamsSync::WS_PREFIX) ?>{numer}</code> o nazwie „Obszar: {nazwa}”.
      W grupie obszaru są osoby dopisane do obszaru i członkowie zespołów przypisanych do obszaru. Do grup trafiają tylko osoby z kontem w chmurze.
      Dzięki temu w chmurze udostępnisz plik albo folder całemu zespołowi albo obszarowi naraz.</p>
    <p class="mb-2">Grupa wyłączonego albo usuniętego zespołu czy obszaru zostaje, ale bez członków, więc udostępnienia nie znikają.
      Inne grupy w chmurze zostają bez zmian. Synchronizacja chodzi sama co 2 minuty, a przycisk uruchamia ją od razu.</p>
    <p class="mb-2">Zespołów w SZO: <strong><?= count($tmTeams) ?></strong>, w tym aktywnych: <strong><?= count(array_filter($tmTeams, fn($t) => $t['active'])) ?></strong>.
      Obszarów roboczych: <strong><?= count($tmWs) ?></strong>, w tym aktywnych: <strong><?= count(array_filter($tmWs, fn($t) => $t['active'])) ?></strong>.</p>
    <div class="d-flex flex-wrap gap-2">
      <form method="post" action="<?= h(route('sso.teamsSync')) ?>" class="m-0"><?= Router::csrfField() ?><input type="hidden" name="do" value="preview">
        <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-eye" aria-hidden="true"></i> Podgląd</button></form>
      <form method="post" action="<?= h(route('sso.teamsSync')) ?>" class="m-0"><?= Router::csrfField() ?><input type="hidden" name="do" value="apply">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Synchronizuj zespoły i obszary</button></form>
    </div>
  </div>
</section>
<?php if ($tmRes !== null): ?><script>document.getElementById('ssoTeamsResult')?.focus();</script><?php endif; ?>

<?php $pwRows = SsoController::takeResult('hasla'); $pwList = SsoAccounts::passwordResetList(); ?>
<section class="card mb-4" id="hasla" aria-labelledby="ssoPwH">
  <div class="card-header"><h2 id="ssoPwH" class="h6 mb-0"><i class="bi bi-key" aria-hidden="true"></i> Resetuj hasła dla użytkowników w NC</h2></div>
  <div class="card-body small">
    <?php if ($pwRows): ?>
      <div class="alert alert-warning" role="status" tabindex="-1" id="ssoPwResult">
        <p class="fw-semibold mb-2"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Nowe hasła widać tylko teraz. Po odświeżeniu strony znikną.</p>
        <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
          <caption class="visually-hidden">Wynik resetu haseł w chmurze i logowaniu jednokrotnym</caption>
          <thead><tr><th scope="col">Osoba</th><th scope="col">Login</th><th scope="col">Nowe hasło</th><th scope="col">Chmura</th><th scope="col">Logowanie jednokrotne</th><th scope="col">SMS</th></tr></thead>
          <tbody>
          <?php foreach ($pwRows as $r): ?>
            <tr>
              <td><?= h($r['name'] ?: $r['email']) ?><br><span class="text-body-secondary"><?= h($r['email']) ?></span></td>
              <?php if (($r['error'] ?? '') !== ''): ?>
                <td colspan="5" class="text-danger-emphasis"><?= h($r['error']) ?></td>
              <?php else: ?>
                <td><code><?= h((string)$r['username']) ?></code><?php if ($r['cloud_uid'] !== '' && $r['cloud_uid'] !== $r['username']): ?><br><span class="text-body-secondary">w chmurze: <code><?= h($r['cloud_uid']) ?></code></span><?php endif; ?></td>
                <td><code class="sso-pass"><?= h((string)$r['password']) ?></code></td>
                <td class="<?= str_starts_with((string)$r['cloud'], 'błąd') ? 'text-danger-emphasis' : '' ?>"><?= h($r['cloud'] ?: '—') ?></td>
                <td><?= $r['logto'] === 'password' ? 'to samo hasło' : 'brak konta' ?></td>
                <td><?= h($r['sms'] ?: '—') ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    <?php endif; ?>
    <p class="mb-2">Zaznaczone osoby dostają jedno nowe hasło do chmury plików (Nextcloud) i do logowania jednokrotnego. Dotychczasowe przestaje działać.
      Hasła do poczty to nie zmienia: poczta ma zawsze osobne, inne hasło.</p>
    <p class="mb-2">Gdy osoba woli ustawić hasło sama, wystarczy strona <a href="<?= h(rtrim(APP_URL, '/') . '/nowehaslo') ?>">Nowe hasło</a>.</p>
    <?php if (!$pwList): ?>
      <p class="mb-0">Nikt nie ma jeszcze konta w chmurze ani w logowaniu jednokrotnym.</p>
    <?php else: ?>
      <form method="post" action="<?= h(route('sso.passwordReset')) ?>" onsubmit="return confirm('Nadać nowe hasła zaznaczonym osobom? Dotychczasowe hasła do chmury i logowania jednokrotnego przestaną działać.')">
        <?= Router::csrfField() ?>
        <fieldset>
          <legend class="visually-hidden">Osoby do resetu hasła</legend>
          <div class="table-responsive border rounded mb-2"><table class="table table-sm mb-0 align-middle">
            <caption class="visually-hidden">Osoby z kontem w chmurze albo w logowaniu jednokrotnym</caption>
            <thead class="table-light"><tr><th scope="col" style="width:2.5rem"><span class="visually-hidden">Zaznacz</span></th><th scope="col">Osoba</th><th scope="col">Login w chmurze</th><th scope="col">Logowanie jednokrotne</th><th scope="col">Hasło ustawione</th><th scope="col">Telefon</th></tr></thead>
            <tbody>
            <?php foreach ($pwList as $i => $r): ?>
              <tr>
                <td><input class="form-check-input" type="checkbox" name="emails[]" value="<?= h($r['email']) ?>" id="ssoPw<?= $i ?>"></td>
                <td><label for="ssoPw<?= $i ?>" class="mb-0"><?= h($r['name'] ?: $r['email']) ?></label><br><span class="text-body-secondary"><?= h($r['email']) ?></span></td>
                <td><?= $r['cloud_uid'] !== '' ? '<code>' . h($r['cloud_uid']) . '</code>' : '—' ?></td>
                <td><?= $r['logto'] ? 'jest' : 'brak' ?></td>
                <td><?= $r['pwd_at'] !== '' ? h(date('d.m.Y H:i', strtotime($r['pwd_at']))) : '—' ?></td>
                <td><?= $r['phone'] !== '' ? h($r['phone']) : '<span class="text-danger-emphasis">brak numeru</span>' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        </fieldset>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="sms" value="1" id="ssoPwSms" checked>
          <label class="form-check-label" for="ssoPwSms">Wyślij każdej osobie SMS z loginem i nowym hasłem</label></div>
        <p class="text-body-secondary mb-2">Naraz najwyżej 25 osób. Osoba bez numeru telefonu SMS-a nie dostanie, hasło przekażesz jej sam.</p>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-key" aria-hidden="true"></i> Resetuj hasła zaznaczonym</button>
      </form>
    <?php endif; ?>
  </div>
</section>
<?php if ($pwRows): ?><script>document.getElementById('ssoPwResult')?.focus();</script><?php endif; ?>

<section class="card mb-4" id="pliki" aria-labelledby="ssoFilesH">
  <div class="card-header"><h2 id="ssoFilesH" class="h6 mb-0"><i class="bi bi-files" aria-hidden="true"></i> Pliki z OneDrive → chmura</h2></div>
  <div class="card-body small">
    <p class="mb-2">Kopiowanie zlecasz w <a href="<?= h($base) ?>/migracjaadmin#pliki">migratorze kont</a> (wiele osób naraz) albo w Zarządzaniu dostępem przy umowie. Pliki trafiają do folderu <code><?= h(CloudFilesCopy::TARGET) ?></code> w chmurze, w tle co 5 minut. Aplikacja SZO w Microsoft 365 potrzebuje uprawnienia aplikacji <code>Files.Read.All</code> ze zgodą administratora.</p>
    <?php $jobsBack = '/ssoadmin'; include __DIR__ . '/_jobs.php'; ?>
  </div>
</section>

<section class="card mb-4" aria-labelledby="ssoDelH">
  <div class="card-header"><h2 id="ssoDelH" class="h6 mb-0"><i class="bi bi-journal-text" aria-hidden="true"></i> Ostatnie zdarzenia z Logto (webhook)</h2></div>
  <div class="card-body small">
    <?php if (!$deliveries): ?>
      <p class="mb-0 text-body-secondary">Jeszcze nic nie przyszło.</p>
    <?php else: ?>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th scope="col">Kiedy</th><th scope="col">Zdarzenie</th><th scope="col">Osoba</th><th scope="col">Wynik</th></tr></thead>
        <tbody>
        <?php foreach ($deliveries as $d): ?>
          <tr><td><?= h(date('d.m.Y H:i', strtotime((string)$d['created_at']))) ?></td><td><code><?= h($d['event']) ?></code></td><td><?= h($d['email'] ?: '—') ?></td><td><?= h($d['note']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</section>
</div>

<script>
document.querySelectorAll('[data-copy]').forEach(function (b) {
  b.addEventListener('click', function () {
    var el = document.getElementById(b.getAttribute('data-copy'));
    navigator.clipboard.writeText(el.value).then(function () { var t = b.firstChild.textContent; b.firstChild.textContent = 'Skopiowano'; setTimeout(function () { b.firstChild.textContent = t; }, 1500); });
  });
});
</script>

<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
