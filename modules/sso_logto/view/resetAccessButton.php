<?php
/**
 * modules/sso_logto/view/resetAccessButton.php — przycisk „Resetuj dane dostępowe” w nagłówku umowy (includes/contract_view_header.php)
 * i na karcie osoby (persons/view.php); polecenie 2026-11-27. Otwiera okno z potwierdzeniem: jedno nowe hasło naraz w logowaniu
 * jednokrotnym (Logto) i w chmurze (SsoAccounts::resetPassword), opcjonalnie SMS. Hasło do poczty zostaje bez zmian.
 * Tylko administrator (POST /ssoadmin/osoba jest w grupie role:admin).
 *
 * Zmienne: $__raType ('wolontariat'|'zlecenie'|'dzielo'|'person'), $__raId (int), $__raRow (wiersz umowy albo osoby),
 * $__raOnly = 'result' — zamiast przycisku sam wynik (karta osoby, pod nagłówkiem).
 * Wynik: przy umowie pokazuje go karta „Logowanie jednokrotne i chmura” (zakładka M365, ret=contract), przy osobie — ten plik (ctx „person:id”).
 * Okno jest przenoszone do <body>, bo pasek akcji nagłówka nie powinien być przodkiem okna position:fixed.
 */
if (!function_exists('is_admin') || !is_admin()) return;
if (!in_array((string)($__raType ?? ''), ['wolontariat', 'zlecenie', 'dzielo', 'person'], true) || (int)($__raId ?? 0) <= 0) return;
require_once dirname(__DIR__) . '/logic/SsoController.php';
if (!SsoConfig::logtoConfigured() && !SsoConfig::cloudConfigured()) return;

$__raIsPerson = $__raType === 'person';

// ── Tryb „wynik”: nowe hasło po resecie z karty osoby, pokazane raz ──
if (($__raOnly ?? '') === 'result') {
    $__raRes = $__raIsPerson ? SsoController::takeResult('person:' . (int)$__raId) : null;
    if (!$__raRes) return;
    ?>
<div class="alert alert-warning" role="status" tabindex="-1" id="raResult">
  <?php foreach ($__raRes as $r): ?>
  <p class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Nowe dane dostępowe. Hasło widać tylko teraz, po odświeżeniu strony zniknie.</p>
  <dl class="row small mb-0">
    <dt class="col-sm-4 fw-normal">Login</dt><dd class="col-sm-8 mb-1"><code><?= h((string)($r['username'] ?? '')) ?></code><?php if (!empty($r['cloud_uid']) && $r['cloud_uid'] !== ($r['username'] ?? '')): ?> (w chmurze: <code><?= h((string)$r['cloud_uid']) ?></code>)<?php endif; ?></dd>
    <?php if (!empty($r['password'])): ?><dt class="col-sm-4 fw-normal">Hasło</dt><dd class="col-sm-8 mb-1"><code><?= h((string)$r['password']) ?></code></dd><?php endif; ?>
    <dt class="col-sm-4 fw-normal">Logowanie jednokrotne</dt><dd class="col-sm-8 mb-1"><?= ($r['logto'] ?? '') === 'password' ? 'to samo nowe hasło' : 'brak konta' ?></dd>
    <dt class="col-sm-4 fw-normal">Chmura</dt><dd class="col-sm-8 mb-1"><?= h((string)(($r['cloud'] ?? '') ?: '—')) ?></dd>
    <dt class="col-sm-4 fw-normal">SMS</dt><dd class="col-sm-8 mb-0"><?= h((string)(($r['sms'] ?? '') ?: 'nie wysyłano')) ?></dd>
  </dl>
  <?php endforeach; ?>
</div>
<script>document.getElementById('raResult')?.focus();</script>
    <?php
    return;
}

// ── Tryb „przycisk”: przycisk + okno potwierdzenia ──
$__raPerson = SsoController::personFromContract((array)($__raRow ?? []));
$__raEmail  = $__raPerson['email'];
$__raAcc    = $__raEmail !== '' ? SsoAccounts::get($__raEmail) : null;
$__raLogto  = $__raAcc && !empty($__raAcc['logto_id']);
$__raCloud  = $__raAcc && !empty($__raAcc['cloud_uid']);
$__raHas    = $__raLogto || $__raCloud;
$__raSms    = false;
try { require_once dirname(__DIR__, 3) . '/includes/sms.php'; $__raSms = function_exists('sms_channel_ready') && sms_channel_ready() && $__raPerson['phone'] !== ''; } catch (\Throwable $e) {}
$__raMgmt   = $__raIsPerson ? '' : rtrim(APP_URL, '/') . '/tozsamosc/access.php?type=' . rawurlencode((string)$__raType) . '&id=' . (int)$__raId . '#sso';
$__raMid    = 'raModal' . preg_replace('/[^a-z]/', '', (string)$__raType) . (int)$__raId;
$__raWhat   = trim(($__raLogto ? 'do logowania jednokrotnego' : '') . ($__raLogto && $__raCloud ? ' i ' : '') . ($__raCloud ? 'do chmury plików' : ''));
?>
<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#<?= h($__raMid) ?>">
  <i class="bi bi-key" aria-hidden="true"></i> <span>Resetuj dane dostępowe</span>
</button>

<div class="modal fade" id="<?= h($__raMid) ?>" tabindex="-1" aria-labelledby="<?= h($__raMid) ?>H" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="<?= h(rtrim(APP_URL, '/') . '/ssoadmin/osoba') ?>" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="type" value="<?= h((string)$__raType) ?>">
      <input type="hidden" name="id" value="<?= (int)$__raId ?>">
      <input type="hidden" name="do" value="reset">
      <?php if (!$__raIsPerson): ?><input type="hidden" name="ret" value="contract"><?php endif; ?>
      <div class="modal-header">
        <h2 class="modal-title h5" id="<?= h($__raMid) ?>H">Resetuj dane dostępowe</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body text-start">
        <dl class="row small mb-3">
          <dt class="col-sm-5 fw-normal text-body-secondary">Osoba</dt><dd class="col-sm-7 mb-1"><?= h($__raPerson['name'] ?: '—') ?></dd>
          <dt class="col-sm-5 fw-normal text-body-secondary">Login (e-mail)</dt><dd class="col-sm-7 mb-1"><?= $__raEmail !== '' ? h($__raEmail) : '<span class="text-danger-emphasis">brak e-maila</span>' ?></dd>
          <dt class="col-sm-5 fw-normal text-body-secondary">Logowanie jednokrotne</dt><dd class="col-sm-7 mb-1"><?= $__raLogto ? 'jest' . (!empty($__raAcc['logto_username']) ? ' (<code>' . h((string)$__raAcc['logto_username']) . '</code>)' : '') : 'brak konta' ?></dd>
          <dt class="col-sm-5 fw-normal text-body-secondary">Chmura plików</dt><dd class="col-sm-7 mb-0"><?= $__raCloud ? 'login <code>' . h((string)$__raAcc['cloud_uid']) . '</code>' : 'brak konta' ?></dd>
        </dl>
        <?php if ($__raHas): ?>
          <p class="mb-2" id="<?= h($__raMid) ?>D">SZO nada jedno nowe hasło <?= h($__raWhat) ?>. Dotychczasowe hasło przestanie działać. Nowe hasło zobaczysz raz, po zapisaniu. Hasło do poczty się nie zmienia.</p>
          <?php if ($__raSms): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="sms" value="1" id="<?= h($__raMid) ?>Sms" checked>
              <label class="form-check-label" for="<?= h($__raMid) ?>Sms">Wyślij login i nowe hasło SMS-em na <?= h($__raPerson['phone']) ?></label></div>
          <?php else: ?>
            <p class="small text-body-secondary mb-0">SMS nie wyjdzie (brak numeru telefonu albo bramki SMS). Hasło przekażesz osobie sam.</p>
          <?php endif; ?>
        <?php else: ?>
          <p class="mb-0">Ta osoba nie ma jeszcze konta logowania jednokrotnego ani w chmurze, więc nie ma czego resetować.<?php if ($__raMgmt !== ''): ?> Konto założysz w <a href="<?= h($__raMgmt) ?>">Zarządzaniu dostępem</a>.<?php endif; ?></p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <?php if ($__raHas): ?>
        <button type="submit" class="btn btn-sm btn-danger" aria-describedby="<?= h($__raMid) ?>D"><i class="bi bi-key" aria-hidden="true"></i> Resetuj i pokaż nowe hasło</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>
<script>(function(){var m=document.getElementById(<?= json_encode($__raMid) ?>);if(m&&m.parentElement!==document.body)document.body.appendChild(m);})();</script>
