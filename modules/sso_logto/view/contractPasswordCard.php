<?php
/**
 * modules/sso_logto/view/contractPasswordCard.php — karta „Logowanie jednokrotne i chmura” na stronie umowy (zakładka M365, obok
 * „Skrzynki pocztowej”; polecenie 2026-11-13 „resetowanie haseł dodaj w panelu umowy”). Stan kont i przycisk „Nowe hasło”:
 * jedno wygenerowane hasło naraz w chmurze (NC) i w logowaniu jednokrotnym (SsoAccounts::resetPassword), opcjonalnie SMS.
 * Hasło do poczty zmienia karta „Skrzynka pocztowa”. Pełne zarządzanie (zakładanie kont, pliki z OneDrive, zmiana loginu)
 * zostaje w Tożsamości (tozsamosc/access.php#sso). Akcja: POST /ssoadmin/osoba, do=reset, ret=contract (tylko administrator).
 * Zmienne ze strony umowy: $TYPE, $id, $row.
 */
if (!in_array((string)($TYPE ?? ''), ['wolontariat', 'zlecenie', 'dzielo'], true)) return;
require_once dirname(__DIR__) . '/logic/SsoController.php';
$__spEmail = strtolower(trim((string)($row['email'] ?? '')));
$__spAcc   = $__spEmail !== '' ? SsoAccounts::get($__spEmail) : null;
$__spLogto = $__spAcc && !empty($__spAcc['logto_id']);
$__spCloud = $__spAcc && !empty($__spAcc['cloud_uid']);
$__spRes   = SsoController::takeResult($TYPE . ':' . (int)$id);
$__spAdmin = function_exists('is_admin') && is_admin();
$__spPhone = SsoController::personFromContract($row)['phone'];
$__spSms   = false;
try { require_once dirname(__DIR__, 3) . '/includes/sms.php'; $__spSms = function_exists('sms_is_enabled') && sms_is_enabled() && $__spPhone !== ''; } catch (\Throwable $e) {}
$__spDate  = fn(?string $d) => $d ? date('d.m.Y H:i', strtotime($d)) : '—';
$__spMgmt  = rtrim(APP_URL, '/') . '/tozsamosc/access.php?type=' . rawurlencode((string)$TYPE) . '&id=' . (int)$id . '#sso';
?>
<section class="card shadow-sm mb-3" id="sso" aria-labelledby="spCardH">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <h2 id="spCardH" class="h6 mb-0"><i class="bi bi-person-badge" aria-hidden="true"></i> Logowanie jednokrotne i chmura</h2>
    <span class="badge <?= ($__spLogto || $__spCloud) ? 'bg-success' : 'bg-secondary' ?>"><?= ($__spLogto || $__spCloud) ? 'Konto jest' : 'Brak konta' ?></span>
  </div>
  <div class="card-body small">
    <?php if ($__spRes): foreach ($__spRes as $r): ?>
      <div class="alert alert-warning" role="status" tabindex="-1" id="spResult">
        <p class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Nowe hasło widać tylko teraz. Po odświeżeniu strony zniknie.</p>
        <dl class="row mb-0">
          <dt class="col-sm-4 fw-normal">Login</dt><dd class="col-sm-8 mb-1"><code><?= h((string)($r['username'] ?? '')) ?></code><?php if (!empty($r['cloud_uid']) && $r['cloud_uid'] !== ($r['username'] ?? '')): ?> (w chmurze: <code><?= h((string)$r['cloud_uid']) ?></code>)<?php endif; ?></dd>
          <?php if (!empty($r['password'])): ?><dt class="col-sm-4 fw-normal">Hasło</dt><dd class="col-sm-8 mb-1"><code><?= h((string)$r['password']) ?></code></dd><?php endif; ?>
          <dt class="col-sm-4 fw-normal">Chmura</dt><dd class="col-sm-8 mb-1"><?= h((string)(($r['cloud'] ?? '') ?: '—')) ?></dd>
          <dt class="col-sm-4 fw-normal">Logowanie jednokrotne</dt><dd class="col-sm-8 mb-1"><?= ($r['logto'] ?? '') === 'password' ? 'to samo nowe hasło' : 'brak konta' ?></dd>
          <dt class="col-sm-4 fw-normal">SMS</dt><dd class="col-sm-8 mb-0"><?= h((string)(($r['sms'] ?? '') ?: 'nie wysyłano')) ?></dd>
        </dl>
      </div>
    <?php endforeach; endif; ?>

    <dl class="row mb-2">
      <dt class="col-sm-4 text-body-secondary fw-normal">E-mail (login)</dt><dd class="col-sm-8 mb-1"><?= $__spEmail !== '' ? h($__spEmail) : '<span class="text-danger-emphasis">brak e-maila w umowie</span>' ?></dd>
      <dt class="col-sm-4 text-body-secondary fw-normal">Logowanie jednokrotne</dt><dd class="col-sm-8 mb-1"><?= $__spLogto ? 'jest' . (!empty($__spAcc['logto_username']) ? ' (<code>' . h((string)$__spAcc['logto_username']) . '</code>)' : '') : 'brak' ?></dd>
      <dt class="col-sm-4 text-body-secondary fw-normal">Chmura plików</dt><dd class="col-sm-8 mb-1"><?= $__spCloud ? 'login <code>' . h((string)$__spAcc['cloud_uid']) . '</code>' : 'brak' ?></dd>
      <dt class="col-sm-4 text-body-secondary fw-normal">Hasło ustawione</dt><dd class="col-sm-8 mb-0"><?= h($__spDate($__spAcc['logto_pwd_at'] ?? null)) ?></dd>
    </dl>

    <?php if (!$__spAdmin): ?>
      <p class="text-body-secondary mb-0">Nowe hasło nadaje administrator. Osoba może też zmienić je sama na stronie <a href="<?= h(rtrim(APP_URL, '/') . '/nowehaslo') ?>">Nowe hasło</a>.</p>
    <?php elseif ($__spLogto || $__spCloud): ?>
      <form method="post" action="<?= h(rtrim(APP_URL, '/') . '/ssoadmin/osoba') ?>" class="border-top pt-2"
            onsubmit="return confirm('Nadać nowe hasło? Dotychczasowe hasło do chmury<?= $__spLogto ? ' i logowania jednokrotnego' : '' ?> przestanie działać.')">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="type" value="<?= h((string)$TYPE) ?>"><input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="do" value="reset"><input type="hidden" name="ret" value="contract">
        <p class="mb-2" id="spResetD">Jedno nowe hasło do chmury<?= $__spLogto ? ' i logowania jednokrotnego' : '' ?>. Hasło do poczty zmienia karta „Skrzynka pocztowa”.</p>
        <?php if ($__spSms): ?>
          <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="sms" value="1" id="spSms" checked>
            <label class="form-check-label" for="spSms">Wyślij login i nowe hasło SMS-em na <?= h($__spPhone) ?></label></div>
        <?php else: ?>
          <p class="text-body-secondary mb-2">SMS nie wyjdzie (brak numeru albo bramki SMS). Hasło przekażesz osobie sam.</p>
        <?php endif; ?>
        <button type="submit" class="btn btn-sm btn-primary" aria-describedby="spResetD"><i class="bi bi-key" aria-hidden="true"></i> Nowe hasło</button>
      </form>
    <?php else: ?>
      <p class="mb-0">Ta osoba nie ma jeszcze konta. Załóż je w <a href="<?= h($__spMgmt) ?>">Zarządzaniu dostępem</a>.</p>
    <?php endif; ?>
    <p class="mt-2 mb-0"><a href="<?= h($__spMgmt) ?>">Więcej w Zarządzaniu dostępem</a> (zakładanie kont, pliki z OneDrive, zmiana loginu w chmurze).</p>
  </div>
</section>
<?php if ($__spRes): ?><script>document.getElementById('spResult')?.focus();</script><?php endif; ?>
