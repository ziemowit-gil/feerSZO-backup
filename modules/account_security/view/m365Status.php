<?php
/**
 * modules/account_security/view/m365Status.php — karta „Konto Microsoft 365 w Entra ID” w sekcji #uslugi (tozsamosc/index.php).
 *
 * Wymaga zmiennych z index.php: $uid, $db_user, $csrf, $m365_active (bool — stan konta zapisany w SZO).
 * Pokazuje ostatni odczyt z sesji (AccountSecurityM365::cached); odczyt robi przycisk (POST /m365/sprawdz) — strona sama
 * nie odpytuje Graph. Klasy ma-* / tz-* (bez Tailwinda). Bez powiązanego konta Microsoft karta się nie pokazuje.
 */
require_once dirname(__DIR__) . '/lib/accountSecurityM365.php';

$msOid = trim((string)($db_user['microsoft_id'] ?? ''));
if ($msOid === '') return;
$msRes = AccountSecurityM365::cached($uid, $msOid);
$msUrl = APP_URL . '/modules/account_security/router.php?_route=' . rawurlencode('/m365/sprawdz');
$msBadge = match ($msRes['state'] ?? '') {
    'ok'      => !empty($msRes['profile']['enabled']) ? ['tz-badge--ok', 'Włączone'] : ['tz-badge--err', 'Wyłączone'],
    'missing' => ['tz-badge--err', 'Brak konta w Entra ID'],
    'err'     => ['tz-badge--warn', 'Błąd odczytu'],
    'skip'    => ['tz-badge--off', 'Niedostępne'],
    default   => ['tz-badge--info', 'Nie sprawdzono'],
};
$msList = fn(?array $items, string $empty): string => $items === null
    ? '<span class="ma-muted">nie udało się odczytać</span>'
    : ($items ? h(implode(', ', $items)) : '<span class="ma-muted">' . h($empty) . '</span>');
?>
<h2 class="ma-h2" id="as-ms-h">Konto Microsoft 365 w Entra ID</h2>
<article class="ma-card" aria-labelledby="as-ms-h" aria-describedby="as-ms-d">
  <div class="ma-card-hd">
    <span class="ma-svc-ic" style="--c:#4f52b2;width:36px;height:36px;font-size:1.05rem" aria-hidden="true"><i class="bi bi-microsoft"></i></span>
    <span class="ma-card-t">Stan bezpośrednio w Microsoft</span>
    <span class="tz-badge <?= $msBadge[0] ?>"><?= h($msBadge[1]) ?></span>
  </div>
  <p id="as-ms-d" class="ma-muted">Odczyt z Entra ID: czy konto jest włączone, jakie ma licencje i grupy, kiedy zmieniono hasło. Tylko podgląd — nic nie zmieniamy w Microsoft.</p>

  <?php if (!$msRes): ?>
    <!-- jeszcze nie sprawdzono w tej sesji -->
  <?php elseif ($msRes['state'] === 'ok'): $p = $msRes['profile']; ?>
    <?php if ($m365_active && !$p['enabled']): ?>
    <div class="ma-bar ma-bar--warn" role="status"><i class="bi bi-exclamation-triangle-fill ma-bar-ic" aria-hidden="true"></i>
      <div class="ma-bar-tx">W SZO konto Microsoft 365 jest oznaczone jako aktywne, ale w Microsoft jest <strong>wyłączone</strong> — logowanie do Outlooka i Teams nie zadziała. Skontaktuj się z administratorem.</div></div>
    <?php elseif ($p['enabled'] && $p['license_count'] === 0): ?>
    <div class="ma-bar ma-bar--warn" role="status"><i class="bi bi-exclamation-triangle-fill ma-bar-ic" aria-hidden="true"></i>
      <div class="ma-bar-tx">Konto nie ma żadnej licencji — bez niej nie działają poczta Outlook ani aplikacje Office. Poproś administratora o nadanie licencji.</div></div>
    <?php endif; ?>
    <dl class="ma-rows m-0">
      <div class="ma-row"><dt>Stan konta</dt><dd><?= $p['enabled'] ? 'włączone — można się logować' : 'wyłączone — logowanie zablokowane' ?></dd></div>
      <div class="ma-row"><dt>Login (UPN)</dt><dd class="ma-mono"><?= h($p['upn'] ?: '—') ?></dd></div>
      <?php if ($p['mail'] !== '' && strcasecmp($p['mail'], $p['upn']) !== 0): ?>
      <div class="ma-row"><dt>Adres e-mail</dt><dd><?= h($p['mail']) ?></dd></div>
      <?php endif; ?>
      <div class="ma-row"><dt>Licencje</dt><dd><?= $msList($msRes['licenses'], 'brak licencji') ?></dd></div>
      <div class="ma-row"><dt>Ostatnia zmiana hasła</dt><dd><?= h($p['pwd_changed'] ?? '—') ?><?= $p['pwd_never_exp'] ? ' <span class="ma-sm">hasło nie wygasa</span>' : '' ?></dd></div>
      <div class="ma-row"><dt>Ostatnie logowanie do Microsoft</dt><dd><?= $msRes['last_signin'] !== null ? h($msRes['last_signin']) : '<span class="ma-muted">niedostępne</span>' ?></dd></div>
      <div class="ma-row"><dt>Grupy</dt><dd><?= $msList($msRes['groups'], 'brak grup') ?></dd></div>
      <?php if ($msRes['roles']): ?>
      <div class="ma-row"><dt>Role administracyjne</dt><dd><?= h(implode(', ', $msRes['roles'])) ?></dd></div>
      <?php endif; ?>
      <?php if ($p['created']): ?>
      <div class="ma-row"><dt>Konto utworzone</dt><dd><?= h($p['created']) ?></dd></div>
      <?php endif; ?>
    </dl>
  <?php else: ?>
    <div class="ma-bar <?= $msRes['state'] === 'skip' ? 'ma-bar--info' : 'ma-bar--err' ?>" role="status">
      <i class="bi <?= $msRes['state'] === 'skip' ? 'bi-info-circle-fill' : 'bi-x-octagon-fill' ?> ma-bar-ic" aria-hidden="true"></i>
      <div class="ma-bar-tx"><?= h($msRes['msg'] ?? 'Nie udało się odczytać stanu konta.') ?></div>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= h($msUrl) ?>" class="m-0 d-flex flex-wrap align-items-center gap-2" style="margin-top:1rem!important">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
    <button type="submit" class="tz-btn tz-btn--sm<?= $msRes ? ' tz-btn--ghost' : '' ?>"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>
      <?= $msRes ? 'Odśwież' : 'Sprawdź stan w Microsoft 365' ?></button>
    <?php if ($msRes): ?><span class="ma-muted" style="font-size:13px">Sprawdzono o <?= h(date('H:i', (int)$msRes['at'])) ?></span><?php endif; ?>
  </form>
</article>
