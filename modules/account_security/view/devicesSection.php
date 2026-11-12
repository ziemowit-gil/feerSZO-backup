<?php
/**
 * modules/account_security/view/devicesSection.php — sekcja „Urządzenia i logowania” (#urzadzenia) w tozsamosc/index.php.
 *
 * Wymaga zmiennych z index.php: $uid (int), $db_user (wiersz users), $csrf (h(csrf_token())), $sec_title (closure nagłówka).
 * Klasy widoku ma-* / tz-* z index.php i _head.php (zwykły CSS — bez klas Tailwind). Akcje: modules/account_security/router.php.
 * WCAG: nagłówki h2 w kartach, przyciski z nazwą urządzenia dla czytników (visually-hidden), czasy w <time datetime>.
 */
require_once dirname(__DIR__) . '/lib/accountSecurityDevices.php';

$asSessions = AccountSecurityDevices::sessions($uid);
$asOthers   = count(array_filter($asSessions, fn($s) => !$s['current']));
$asDevices  = AccountSecurityDevices::devices($uid);
$asHistory  = AccountSecurityDevices::history($uid, 30);
$asFails    = AccountSecurityDevices::recentFailures($uid, 14);
$asHasMs    = trim((string)($db_user['microsoft_id'] ?? '')) !== '';
$asUrl      = fn(string $route): string => APP_URL . '/modules/account_security/router.php?_route=' . rawurlencode($route);
$asTone     = ['ok' => 'tz-badge--ok', 'warn' => 'tz-badge--warn', 'err' => 'tz-badge--err', 'info' => 'tz-badge--info', 'off' => 'tz-badge--off'];
$asIcon     = fn(string $device): string => preg_match('/Android|iOS/', $device) ? 'bi-phone' : 'bi-laptop';
$asWhere    = fn(string $ip, string $geo): string => implode(' · ', array_filter([$geo, $ip !== '' ? 'IP ' . $ip : ''], fn($x) => $x !== ''));
?>
<!-- ═══════════ URZĄDZENIA I LOGOWANIA ═══════════ -->
<section id="urzadzenia" class="ma-sec" aria-labelledby="urzadzenia-h" x-show="tab === 'urzadzenia'" x-cloak>
  <?php $sec_title('urzadzenia', 'Urządzenia i logowania', 'Gdzie jesteś teraz zalogowany, które urządzenia system zna i kto ostatnio logował się na Twoje konto.'); ?>

  <?php if ($asFails > 0): ?>
  <div class="ma-bar ma-bar--warn" role="status">
    <i class="bi bi-exclamation-triangle-fill ma-bar-ic" aria-hidden="true"></i>
    <div class="ma-bar-tx">W ciągu ostatnich 14 dni odnotowaliśmy <strong><?= (int)$asFails ?></strong> nieudanych lub odrzuconych prób logowania na Twoje konto.
      Jeśli to nie Ty — użyj przycisku <strong>„To nie ja”</strong> na dole strony.</div>
  </div>
  <?php endif; ?>

  <div class="ma-stack">
    <!-- Aktywne sesje -->
    <article class="ma-card" aria-labelledby="as-sess-h">
      <div class="ma-card-hd">
        <h2 id="as-sess-h" class="ma-card-t">Aktywne sesje</h2>
        <span class="tz-badge tz-badge--info"><?= count($asSessions) ?></span>
      </div>
      <?php if (!$asSessions): ?>
        <p class="ma-muted m-0">Brak zarejestrowanych sesji — rejestr obejmuje logowania od wdrożenia tej funkcji.</p>
      <?php else: ?>
      <ul class="ma-methods">
        <?php foreach ($asSessions as $s): ?>
        <li class="ma-method">
          <i class="bi <?= $asIcon($s['device']) ?>" aria-hidden="true" style="font-size:1.3rem;color:var(--tz-fg2)"></i>
          <div class="ma-method-tx">
            <b><?= h($s['device']) ?></b>
            <span><?= h($asWhere($s['ip'], $s['geo'])) ?></span>
            <span>Ostatnia aktywność: <time datetime="<?= h(AccountSecurityDevices::isoTime($s['last_active'])) ?>"><?= h(AccountSecurityDevices::localTime($s['last_active'])) ?></time>
              · zalogowano <time datetime="<?= h(AccountSecurityDevices::isoTime($s['created_at'])) ?>"><?= h(AccountSecurityDevices::localTime($s['created_at'])) ?></time></span>
          </div>
          <?php if ($s['current']): ?>
            <span class="tz-badge tz-badge--ok">To urządzenie</span>
          <?php else: ?>
          <form method="post" action="<?= h($asUrl('/sesje/zakoncz')) ?>" class="m-0">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="tz-btn tz-btn--ghost tz-btn--sm">Wyloguj<span class="visually-hidden"> sesję: <?= h($s['device']) ?>, <?= h($asWhere($s['ip'], $s['geo'])) ?></span></button>
          </form>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <?php if ($asOthers > 0 || $asHasMs): ?>
      <form method="post" action="<?= h($asUrl('/sesje/zakoncz-pozostale')) ?>" class="m-0"
            onsubmit="return confirm('Wylogować wszystkie pozostałe urządzenia? Ta sesja zostaje.');">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <?php if ($asHasMs): ?>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="m365" value="1" id="as-m365" checked>
          <label class="form-check-label" for="as-m365">Wyloguj także z Microsoft 365 (Outlook, Teams, OneDrive — również na telefonie)</label>
        </div>
        <?php endif; ?>
        <button type="submit" class="tz-btn tz-btn--subtle tz-btn--sm"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>
          Wyloguj wszystkie inne<?= $asOthers > 0 ? ' (' . $asOthers . ')' : '' ?></button>
      </form>
      <?php endif; ?>
    </article>

    <!-- Znane urządzenia -->
    <article class="ma-card" aria-labelledby="as-dev-h">
      <div class="ma-card-hd">
        <h2 id="as-dev-h" class="ma-card-t">Znane urządzenia</h2>
        <span class="tz-badge tz-badge--info"><?= count($asDevices) ?></span>
      </div>
      <p class="ma-muted">Logowanie z tych urządzeń nie wymaga dodatkowego potwierdzenia. Zapomnij urządzenie, którego już nie używasz albo którego nie rozpoznajesz — następne logowanie z niego trzeba będzie potwierdzić.</p>
      <?php if (!$asDevices): ?>
        <p class="ma-muted m-0">Brak zapamiętanych urządzeń.</p>
      <?php else: ?>
      <ul class="ma-methods">
        <?php foreach ($asDevices as $d): ?>
        <li class="ma-method">
          <i class="bi <?= $asIcon($d['label']) ?>" aria-hidden="true" style="font-size:1.3rem;color:var(--tz-fg2)"></i>
          <div class="ma-method-tx">
            <b><?= h($d['label']) ?></b>
            <span><?= h($d['geo'] !== '' ? $d['geo'] . ' · ' : '') ?>pierwsze logowanie <time datetime="<?= h(AccountSecurityDevices::isoTime($d['first_seen'])) ?>"><?= h(AccountSecurityDevices::localTime($d['first_seen'], 'd.m.Y')) ?></time>,
              ostatnio <time datetime="<?= h(AccountSecurityDevices::isoTime($d['last_seen'])) ?>"><?= h(AccountSecurityDevices::localTime($d['last_seen'])) ?></time></span>
          </div>
          <?php if ($d['current']): ?><span class="tz-badge tz-badge--ok">To urządzenie</span><?php endif; ?>
          <form method="post" action="<?= h($asUrl('/urzadzenia/zapomnij')) ?>" class="m-0"
                onsubmit="return confirm('Zapomnieć to urządzenie? Następne logowanie z niego trzeba będzie potwierdzić.');">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button type="submit" class="tz-btn tz-btn--ghost tz-btn--sm">Zapomnij<span class="visually-hidden"> urządzenie: <?= h($d['label']) ?></span></button>
          </form>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </article>

    <!-- Historia -->
    <article class="ma-card" aria-labelledby="as-hist-h">
      <div class="ma-card-hd"><h2 id="as-hist-h" class="ma-card-t">Ostatnia aktywność</h2></div>
      <?php if (!$asHistory): ?>
        <p class="ma-muted m-0">Brak zapisanych logowań.</p>
      <?php else: ?>
      <ol class="ma-tl">
        <?php foreach ($asHistory as $e): ?>
        <li>
          <span class="ma-tl-ic<?= in_array($e['tone'], ['warn', 'err'], true) ? ' is-mod' : '' ?>" aria-hidden="true"><i class="bi <?= h($e['icon']) ?>"></i></span>
          <div class="tw-min-w-0">
            <div class="ma-tl-t"><?= h($e['label']) ?>
              <?php if ($e['tone'] === 'err'): ?><span class="tz-badge tz-badge--err">Uwaga</span><?php endif; ?></div>
            <div class="ma-tl-m"><time datetime="<?= h(AccountSecurityDevices::isoTime($e['at'])) ?>"><?= h(AccountSecurityDevices::localTime($e['at'])) ?></time><?php
              $meta = implode(' · ', array_filter([$e['device'], $asWhere($e['ip'], $e['geo'])], fn($x) => $x !== ''));
              echo $meta !== '' ? ' · ' . h($meta) : ''; ?></div>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
      <p class="ma-card-note">Pokazujemy 30 ostatnich zdarzeń. Czas: strefa polska.</p>
      <?php endif; ?>
    </article>

    <!-- To nie ja -->
    <article class="ma-card" aria-labelledby="as-lock-h">
      <div class="ma-card-hd"><h2 id="as-lock-h" class="ma-card-t">Nie rozpoznajesz jakiejś aktywności?</h2></div>
      <div class="ma-bar ma-bar--err">
        <i class="bi bi-shield-exclamation ma-bar-ic" aria-hidden="true"></i>
        <div class="ma-bar-tx">Jeśli widzisz logowanie, którego nie rozpoznajesz, wybierz „To nie ja”. Wylogujemy wszystkie inne urządzenia<?= $asHasMs ? ' (także Microsoft 365)' : '' ?>,
          zapomnimy nieznane miejsca logowania, a Ty od razu ustawisz nowe hasło.</div>
      </div>
      <form method="post" action="<?= h($asUrl('/to-nie-ja')) ?>" class="m-0"
            onsubmit="return confirm('Zabezpieczyć konto? Wszystkie inne urządzenia zostaną wylogowane, a potem ustawisz nowe hasło.');">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <button type="submit" class="tz-btn tz-btn--danger"><i class="bi bi-shield-lock" aria-hidden="true"></i> To nie ja — zabezpiecz konto</button>
      </form>
    </article>
  </div>
</section>
