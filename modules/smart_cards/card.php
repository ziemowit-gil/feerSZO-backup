<?php
/**
 * modules/smart_cards/card.php — szczegóły karty: podgląd, cykl życia (blokada,
 * odblokowanie, zagubienie, przedłużenie), strefy dostępu, tester dostępu, historia.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/smart_cards.php';

require_role('admin', 'editor');
require_module_enabled('smart_cards_enabled', 'Moduł Karty dostępu');

$svc = new SmartCardService(current_user());
$id  = (int)($_GET['id'] ?? 0);
$SELF = APP_URL . '/modules/smart_cards/card.php?id=' . $id;
$card = $svc->card($id);
if (!$card) { flash_set('danger', 'Karta nie istnieje.'); header('Location: ' . APP_URL . '/modules/smart_cards/index.php'); exit; }

$error = null;
$accessResult = null;
$modal = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['_action'] ?? '');
    try {
        switch ($action) {
            case 'block':   $modal = 'block';  $svc->block($id, (string)($_POST['reason'] ?? ''));   flash_set('warning', 'Karta zablokowana — uprawnienia do stref cofnięte natychmiast.'); break;
            case 'unblock': $svc->unblock($id, (string)($_POST['reason'] ?? ''));                   flash_set('success', 'Karta odblokowana, zawieszone strefy przywrócone.'); break;
            case 'lost':    $modal = 'lost';   $svc->markLost($id, (string)($_POST['reason'] ?? '')); flash_set('warning', 'Karta oznaczona jako zagubiona — uprawnienia usunięte.'); break;
            case 'renew':   $modal = 'renew';  $svc->renew($id, (string)($_POST['expires_at'] ?? '')); flash_set('success', 'Ważność karty przedłużona.'); break;
            case 'grant':   $svc->grantZone($id, (int)($_POST['zone_id'] ?? 0));                    flash_set('success', 'Nadano uprawnienie do strefy.'); break;
            case 'revoke':  $svc->revokeZone($id, (int)($_POST['zone_id'] ?? 0));                   flash_set('success', 'Cofnięto uprawnienie do strefy.'); break;
            case 'test':
                $accessResult = $svc->checkAccess($card['card_uid'], (int)($_POST['zone_id'] ?? 0));
                break;
            default: throw new SmartCardException('Nieznana operacja.');
        }
        if ($action !== 'test') { header('Location: ' . $SELF); exit; }
    } catch (SmartCardException $e) {
        $error = $e->getMessage();
    } catch (\Throwable $e) {
        error_log('[smart_cards/card] ' . $e->getMessage());
        $error = 'Operacja nie powiodła się — nic nie zostało zapisane.';
    }
    $card = $svc->card($id);
}

$design    = scard_design($card['design_config']);
$zones     = $svc->cardZones($card);
$suspended = $svc->suspendedZones($card);
$allZones  = $svc->zones(true);
$haveIds   = array_map('intval', array_column($zones, 'id'));
$grantable = array_values(array_filter($allZones, fn($z) => !in_array((int)$z['id'], $haveIds, true)));
$history   = $svc->history($id);
$st        = $card['status'];
$fmt = fn(?string $d, string $f = 'd.m.Y H:i') => $d ? date($f, strtotime($d)) : '—';

$ACTIONS = [
    'card_issued' => ['Wydano kartę', 'bi-credit-card', '#047857'], 'card_blocked' => ['Blokada', 'bi-lock', '#b45309'],
    'card_unblocked' => ['Odblokowanie', 'bi-unlock', '#047857'], 'card_lost' => ['Zagubienie', 'bi-question-lg', '#b91c1c'],
    'card_expired' => ['Wygaśnięcie', 'bi-hourglass-bottom', '#475569'], 'card_renewed' => ['Przedłużenie ważności', 'bi-calendar-plus', '#1d4ed8'],
    'zone_granted' => ['Nadano strefę', 'bi-plus-lg', '#1d4ed8'], 'zone_revoked' => ['Cofnięto strefę', 'bi-dash-lg', '#b45309'],
    'access_granted' => ['Test dostępu: przyznany', 'bi-door-open', '#047857'], 'access_denied' => ['Test dostępu: odmowa', 'bi-door-closed', '#b91c1c'],
    'nfc_prepared' => ['Chip przygotowany do zapisu', 'bi-broadcast', '#475569'], 'nfc_programmed' => ['Chip NFC zaprogramowany', 'bi-broadcast-pin', '#1d4ed8'],
];

$PAGE_TITLE = 'Karta ' . scard_uid_display($card['card_uid']);
include dirname(__DIR__, 2) . '/includes/header.php';
include __DIR__ . '/partials/ui.php';
?>
<div class="scm" x-data="{ modal: <?= h(json_encode($modal)) ?> }" @keydown.escape.window="modal = ''">
<header class="dash-h">
  <div>
    <p class="tw-m-0 tw-text-sm"><a href="<?= h(APP_URL . '/modules/smart_cards/index.php') ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Karty dostępu</a></p>
    <h1 class="tw-mt-1"><?= h($card['user_name'] ?: 'Użytkownik #' . $card['user_id']) ?> <?= scard_status_badge($st) ?></h1>
    <p>UID <span class="mono"><?= h(scard_uid_display($card['card_uid'])) ?></span> · wzór „<?= h($card['template_name']) ?>”</p>
  </div>
</header>
<?= flash_html() ?>
<?php if ($error): ?><div class="alert-err" role="alert"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= h($error) ?></div></div><?php endif; ?>

<div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-5">
  <div class="lg:tw-col-span-5 tw-space-y-5">
    <?= scard_render($design, ['holder' => $card['user_name'], 'uid' => $card['card_uid'], 'expires_at' => $card['expires_at'], 'status' => $st, 'template' => $card['template_name']]) ?>

    <section class="tz-card" aria-labelledby="h-act">
      <div class="tz-card__hd"><h2 id="h-act"><i class="bi bi-sliders" aria-hidden="true"></i> Działania</h2></div>
      <div class="tz-card__bd tw-flex tw-flex-wrap tw-gap-2">
        <?php if ($st === 'active'): ?>
          <button type="button" class="h-btn h-btn--warn" @click="modal = 'block'"><i class="bi bi-lock" aria-hidden="true"></i> Zablokuj</button>
        <?php endif; ?>
        <?php if ($st === 'blocked'): ?>
          <form method="post" action="<?= h($SELF) ?>"><?= csrf_field() ?><input type="hidden" name="_action" value="unblock">
            <button class="h-btn h-btn--primary" type="submit"><i class="bi bi-unlock" aria-hidden="true"></i> Odblokuj</button></form>
        <?php endif; ?>
        <?php if (in_array($st, ['active', 'expired'], true)): ?>
          <button type="button" class="h-btn h-btn--ghost" @click="modal = 'renew'"><i class="bi bi-calendar-plus" aria-hidden="true"></i> Przedłuż ważność</button>
        <?php endif; ?>
        <?php if ($st !== 'lost'): ?>
          <button type="button" class="h-btn h-btn--danger" @click="modal = 'lost'"><i class="bi bi-question-octagon" aria-hidden="true"></i> Zgłoś zagubienie</button>
        <?php else: ?>
          <p class="tw-m-0 tw-text-sm" style="color:var(--tz-muted)">Karta zagubiona to stan końcowy. Nową kartę wydaje się z <a href="<?= h(APP_URL . '/modules/smart_cards/applications.php?new=1&user_id=' . (int)$card['user_id']) ?>">nowego wniosku</a>.</p>
        <?php endif; ?>
        <?php if ($st === 'active' && !$card['programmed_at']): ?>
          <a class="h-btn h-btn--ghost" href="<?= h(APP_URL . '/modules/smart_cards/nfc/index.php?card=' . $id) ?>"><i class="bi bi-broadcast" aria-hidden="true"></i> Zaprogramuj chip</a>
        <?php endif; ?>
      </div>
    </section>

    <section class="tz-card" aria-labelledby="h-facts">
      <div class="tz-card__hd"><h2 id="h-facts"><i class="bi bi-info-circle" aria-hidden="true"></i> Dane karty</h2></div>
      <div class="tz-card__bd">
        <dl class="facts">
          <div><dt>Posiadacz</dt><dd><?= h($card['user_name']) ?><span class="sub"><?= h($card['user_email']) ?></span></dd></div>
          <div><dt>Ważna do</dt><dd><?= h($fmt($card['expires_at'], 'd.m.Y')) ?></dd></div>
          <div><dt>Wydana</dt><dd><?= h($fmt($card['issued_at'])) ?><span class="sub"><?= h($card['issued_by_name'] ?? '') ?></span></dd></div>
          <div><dt>Wniosek</dt><dd><?= $card['application_id'] ? '#' . (int)$card['application_id'] : '—' ?></dd></div>
          <div><dt>Chip NFC</dt><dd><?= $card['programmed_at'] ? 'zaprogramowany ' . h($fmt($card['programmed_at'])) : 'niezaprogramowany' ?>
            <span class="sub">UID <?= $card['uid_source'] === 'reader' ? 'odczytany z chipu' : 'tymczasowy (wygenerowany)' ?></span></dd></div>
          <?php if ($st !== 'active' && $card['status_reason']): ?>
          <div><dt>Powód statusu</dt><dd><?= h($card['status_reason']) ?><span class="sub"><?= h($fmt($card['status_changed_at'])) ?></span></dd></div>
          <?php endif; ?>
        </dl>
      </div>
    </section>
  </div>

  <div class="lg:tw-col-span-7 tw-space-y-5">
    <section class="tz-card" aria-labelledby="h-zones">
      <div class="tz-card__hd"><h2 id="h-zones"><i class="bi bi-door-closed" aria-hidden="true"></i> Strefy dostępu</h2></div>
      <div class="tz-card__bd">
        <?php if ($st !== 'active'): ?>
        <div class="alert-err" role="status"><i class="bi bi-shield-x" aria-hidden="true"></i><div>Karta nie jest aktywna — <strong>żadna strefa nie jest dostępna</strong>.
          <?php if ($st === 'blocked' && $suspended): ?>Po odblokowaniu wrócą indywidualne strefy: <?= h(implode(', ', array_column($suspended, 'zone_name'))) ?>.<?php endif; ?></div></div>
        <?php endif; ?>
        <?php if (!$zones): ?>
          <p class="empty tw-py-6"><i class="bi bi-door-closed" aria-hidden="true"></i>Brak przypisanych stref.</p>
        <?php else: ?>
        <ul class="tw-list-none tw-p-0 tw-m-0 tw-divide-y tw-divide-slate-100">
          <?php foreach ($zones as $z): ?>
          <li class="tw-flex tw-items-center tw-gap-3 tw-py-2.5">
            <span class="sc-lvl l<?= (int)$z['security_level'] ?>" title="Poziom bezpieczeństwa">L<?= (int)$z['security_level'] ?></span>
            <div class="tw-flex-1 tw-min-w-0">
              <span class="tw-font-semibold"><?= h($z['zone_name']) ?></span>
              <span class="sub"><?= $z['inherited'] ? 'ze wzoru karty' : '' ?><?= $z['inherited'] && $z['direct'] ? ' + ' : '' ?><?= $z['direct'] ? 'nadana indywidualnie' : '' ?><?= !(int)$z['is_active'] ? ' · strefa wyłączona' : '' ?></span>
            </div>
            <?= $z['effective'] ? '<span class="sc-badge is-active">działa</span>' : '<span class="sc-badge is-expired">nie działa</span>' ?>
            <?php if ($z['direct']): ?>
            <form method="post" action="<?= h($SELF) ?>"><?= csrf_field() ?><input type="hidden" name="_action" value="revoke"><input type="hidden" name="zone_id" value="<?= (int)$z['id'] ?>">
              <button class="h-btn h-btn--ghost h-btn--sm" type="submit" aria-label="Cofnij strefę <?= h($z['zone_name']) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button></form>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if ($st === 'active' && $grantable): ?>
        <form method="post" action="<?= h($SELF) ?>" class="tw-flex tw-gap-2 tw-mt-4 tw-items-end">
          <?= csrf_field() ?><input type="hidden" name="_action" value="grant">
          <div class="tw-flex-1"><label for="g_zone" class="f-label">Nadaj strefę indywidualnie</label>
            <select id="g_zone" name="zone_id" class="f-input" required>
              <option value="">— wybierz —</option>
              <?php foreach ($grantable as $z): ?><option value="<?= (int)$z['id'] ?>">L<?= (int)$z['security_level'] ?> · <?= h($z['zone_name']) ?></option><?php endforeach; ?>
            </select></div>
          <button class="h-btn h-btn--primary" type="submit"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nadaj</button>
        </form>
        <?php endif; ?>
      </div>
    </section>

    <section class="tz-card" aria-labelledby="h-test">
      <div class="tz-card__hd"><h2 id="h-test"><i class="bi bi-door-open" aria-hidden="true"></i> Tester dostępu</h2></div>
      <div class="tz-card__bd">
        <form method="post" action="<?= h($SELF) ?>" class="tw-flex tw-gap-2 tw-items-end">
          <?= csrf_field() ?><input type="hidden" name="_action" value="test">
          <div class="tw-flex-1"><label for="t_zone" class="f-label">Symuluj przyłożenie karty do czytnika strefy</label>
            <select id="t_zone" name="zone_id" class="f-input" required>
              <?php foreach ($svc->zones() as $z): ?><option value="<?= (int)$z['id'] ?>"<?= (int)($_POST['zone_id'] ?? 0) === (int)$z['id'] ? ' selected' : '' ?>>L<?= (int)$z['security_level'] ?> · <?= h($z['zone_name']) ?></option><?php endforeach; ?>
            </select></div>
          <button class="h-btn h-btn--ghost" type="submit">Sprawdź</button>
        </form>
        <?php if ($accessResult): ?>
        <div class="<?= $accessResult['granted'] ? 'alert-info' : 'alert-err' ?> tw-mt-3 tw-mb-0" role="status" aria-live="polite">
          <i class="bi <?= $accessResult['granted'] ? 'bi-door-open' : 'bi-door-closed' ?>" aria-hidden="true"></i><div><strong><?= $accessResult['granted'] ? 'Wejście' : 'Odmowa' ?>:</strong> <?= h($accessResult['reason']) ?> Zapisano w dzienniku audytu.</div>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="tz-card" aria-labelledby="h-hist">
      <div class="tz-card__hd"><h2 id="h-hist"><i class="bi bi-clock-history" aria-hidden="true"></i> Historia</h2>
        <a class="tw-ml-auto tw-text-sm" href="<?= h(APP_URL . '/modules/audit_logs/index.php?module=smart_cards&q=' . rawurlencode($card['card_uid'])) ?>">Dziennik audytu ›</a></div>
      <div class="tz-card__bd">
        <?php if (!$history): ?><p class="tw-m-0 tw-text-sm" style="color:var(--tz-muted)">Brak zdarzeń.</p><?php else: ?>
        <ol class="tl">
          <?php foreach ($history as $ev):
              $k = substr($ev['action'], strlen('smart_cards.'));
              [$lbl, $ico, $col] = $ACTIONS[$k] ?? [$k, 'bi-dot', '#64748b'];
              $d = json_decode((string)$ev['details'], true) ?: [];
              $extra = $d['reason'] ?? $d['zone'] ?? (isset($d['to']) && $k === 'card_renewed' ? 'do ' . date('d.m.Y', strtotime($d['to'])) : ''); ?>
          <li><span class="tl__dot" style="background:<?= h($col) ?>" aria-hidden="true"><i class="bi <?= h($ico) ?>"></i></span>
            <div class="tw-text-sm tw-font-semibold"><?= h($lbl) ?></div>
            <?php if ($extra): ?><div class="tw-text-sm"><?= h($extra) ?></div><?php endif; ?>
            <div class="sub"><time datetime="<?= h($ev['created_at']) ?>"><?= h($fmt($ev['created_at'])) ?></time> · <?= h($ev['user_name'] ?: 'system') ?></div></li>
          <?php endforeach; ?>
        </ol>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<?php foreach ([
    'block' => ['Zablokuj kartę', 'Karta natychmiast traci dostęp do wszystkich stref. Indywidualne strefy zostaną odłożone i wrócą po odblokowaniu.', 'Powód blokady', 'np. zawieszenie współpracy, podejrzenie użycia przez osobę trzecią', 'h-btn--warn', 'Zablokuj'],
    'lost'  => ['Zgłoś zagubienie karty', 'Stan końcowy: karta traci dostęp, indywidualne uprawnienia są usuwane bezpowrotnie. Odnalezionej karty nie można przywrócić.', 'Okoliczności zagubienia', 'kiedy i gdzie karta zaginęła', 'h-btn--danger', 'Oznacz jako zagubioną'],
] as $key => [$title, $desc, $lbl, $ph, $btn, $cta]): ?>
<div class="sc-modal" x-show="modal === '<?= $key ?>'" x-cloak x-transition.opacity role="dialog" aria-modal="true" aria-labelledby="m-<?= $key ?>-t" @click.self="modal = ''">
  <form method="post" action="<?= h($SELF) ?>" class="sc-modal__box tw-max-w-[520px]" x-trap.noscroll="modal === '<?= $key ?>'">
    <?= csrf_field() ?><input type="hidden" name="_action" value="<?= $key ?>">
    <div class="sc-modal__hd"><h2 id="m-<?= $key ?>-t"><?= h($title) ?></h2><button type="button" class="sc-modal__x" @click="modal = ''" aria-label="Zamknij"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
    <div class="sc-modal__bd">
      <p class="tw-mt-0 tw-text-sm"><?= h($desc) ?></p>
      <label for="m-<?= $key ?>-r" class="f-label"><?= h($lbl) ?> <span aria-hidden="true" style="color:#b91c1c">*</span></label>
      <textarea id="m-<?= $key ?>-r" name="reason" rows="3" maxlength="1000" required class="f-input" placeholder="<?= h($ph) ?>"></textarea>
    </div>
    <div class="sc-modal__ft"><button type="button" class="h-btn h-btn--ghost" @click="modal = ''">Anuluj</button><button type="submit" class="h-btn <?= $btn ?>"><?= h($cta) ?></button></div>
  </form>
</div>
<?php endforeach; ?>

<div class="sc-modal" x-show="modal === 'renew'" x-cloak x-transition.opacity role="dialog" aria-modal="true" aria-labelledby="m-renew-t" @click.self="modal = ''">
  <form method="post" action="<?= h($SELF) ?>" class="sc-modal__box tw-max-w-[440px]" x-trap.noscroll="modal === 'renew'">
    <?= csrf_field() ?><input type="hidden" name="_action" value="renew">
    <div class="sc-modal__hd"><h2 id="m-renew-t">Przedłuż ważność karty</h2><button type="button" class="sc-modal__x" @click="modal = ''" aria-label="Zamknij"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
    <div class="sc-modal__bd">
      <label for="m-renew-d" class="f-label">Nowa data ważności</label>
      <input id="m-renew-d" type="date" name="expires_at" required class="f-input"
             min="<?= h(max(date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime($card['expires_at'] . ' +1 day')))) ?>"
             max="<?= h(date('Y-m-d', strtotime('+' . SCARD_MAX_VALIDITY_YEARS . ' years'))) ?>"
             value="<?= h(date('Y-m-d', strtotime(max(date('Y-m-d'), substr($card['expires_at'], 0, 10)) . ' +' . SCARD_DEFAULT_VALIDITY_YEARS . ' years'))) ?>">
      <?php if ($st === 'expired'): ?><p class="f-help">Wygasła karta wróci do aktywnych z dotychczasowymi strefami.</p><?php endif; ?>
    </div>
    <div class="sc-modal__ft"><button type="button" class="h-btn h-btn--ghost" @click="modal = ''">Anuluj</button><button type="submit" class="h-btn h-btn--primary">Przedłuż</button></div>
  </form>
</div>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
