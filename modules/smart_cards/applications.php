<?php
/**
 * modules/smart_cards/applications.php — wnioski o wydanie karty: nowy wniosek
 * (w imieniu osoby), zatwierdzenie = wydanie karty (wzór, ważność, strefy, UID
 * z czytnika lub wygenerowany), odrzucenie z powodem.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/smart_cards.php';

require_role('admin', 'editor');
require_module_enabled('smart_cards_enabled', 'Moduł Karty dostępu');

$svc  = new SmartCardService(current_user());
$BASE = APP_URL . '/modules/smart_cards/applications.php';
$f_status = isset(SCARD_APP_STATUSES[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$SELF = $BASE . ($f_status ? '?status=' . $f_status : '');

$error = null;
$modal = !empty($_GET['new']) ? 'new' : '';
$old = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['_action'] ?? '');
    $old = $_POST;
    try {
        if ($action === 'new') {
            $modal = 'new';
            $svc->submitApplication((int)($_POST['user_id'] ?? 0), (string)($_POST['reason'] ?? ''));
            flash_set('success', 'Wniosek złożony — czeka na decyzję.');
        } elseif ($action === 'approve') {
            $modal = 'approve';
            $cardId = $svc->approveApplication(
                (int)($_POST['application_id'] ?? 0), (int)($_POST['template_id'] ?? 0), (string)($_POST['expires_at'] ?? ''),
                (array)($_POST['zones'] ?? []), trim((string)($_POST['card_uid'] ?? '')), !empty($_POST['replace']), (string)($_POST['note'] ?? '')
            );
            flash_set('success', 'Wniosek zatwierdzony, karta wydana.' . (trim((string)($_POST['card_uid'] ?? '')) === '' ? ' Zaprogramuj chip NFC, żeby przypisać fizyczny UID.' : ''));
            header('Location: ' . APP_URL . '/modules/smart_cards/card.php?id=' . $cardId); exit;
        } elseif ($action === 'reject') {
            $modal = 'reject';
            $svc->rejectApplication((int)($_POST['application_id'] ?? 0), (string)($_POST['reason'] ?? ''));
            flash_set('success', 'Wniosek odrzucony.');
        } else {
            throw new SmartCardException('Nieznana operacja.');
        }
        header('Location: ' . $SELF); exit;
    } catch (SmartCardException $e) {
        $error = $e->getMessage();
    } catch (\Throwable $e) {
        error_log('[smart_cards/applications] ' . $e->getMessage());
        $error = 'Operacja nie powiodła się — nic nie zostało zapisane.';
    }
}

$apps      = $svc->applications($f_status);
$templates = $svc->templates();
$zones     = $svc->zones(true);
$users     = $svc->users();
$pending   = $svc->stats()['pending'];
$defTpl    = $svc->defaultTemplateId();

// Dane dla Alpine: wzory (wygląd + strefy dziedziczone), strefy
$tplJs  = array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'design' => $t['design'], 'zones' => $t['zone_ids']], $templates);
$zoneJs = array_map(fn($z) => ['id' => (int)$z['id'], 'name' => $z['zone_name'], 'level' => (int)$z['security_level']], $zones);
$appJs  = fn(array $a) => json_encode(['id' => (int)$a['id'], 'user' => (string)$a['user_name'], 'reason' => (string)$a['reason'], 'live' => (int)$a['user_live_cards']], JSON_UNESCAPED_UNICODE);
$oldApp = ($old['_action'] ?? '') === 'approve' ? $old : [];
$preUser = (int)($_GET['user_id'] ?? ($old['user_id'] ?? 0));

$PAGE_TITLE = 'Wnioski o karty';
include dirname(__DIR__, 2) . '/includes/header.php';
include __DIR__ . '/partials/ui.php';
?>
<div class="scm" x-data="scApps()" @keydown.escape.window="modal = ''">
<header class="dash-h">
  <div>
    <h1><span class="dash-h__ico" aria-hidden="true"><i class="bi bi-inbox"></i></span> Wnioski o karty</h1>
    <p>Zatwierdzenie wniosku od razu wydaje kartę z wybranym wzorem, ważnością i strefami.</p>
  </div>
  <button type="button" class="h-btn h-btn--primary" @click="modal = 'new'"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nowy wniosek</button>
</header>
<?php scard_tabs('applications', $pending); ?>
<?= flash_html() ?>
<?php if ($error && !in_array($modal, ['new', 'approve', 'reject'], true)): ?><div class="alert-err" role="alert"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= h($error) ?></div></div><?php endif; ?>

<nav class="tw-flex tw-flex-wrap tw-gap-2 tw-mb-4" aria-label="Filtr statusu">
  <?php foreach (['' => 'Wszystkie'] + array_map(fn($s) => $s['label'], SCARD_APP_STATUSES) as $k => $lbl): ?>
  <a class="h-btn h-btn--sm <?= $f_status === $k ? 'h-btn--primary' : 'h-btn--ghost' ?>" href="<?= h($BASE . ($k ? '?status=' . $k : '')) ?>"<?= $f_status === $k ? ' aria-current="true"' : '' ?>><?= h($lbl) ?></a>
  <?php endforeach; ?>
</nav>

<section class="tz-card" aria-label="Lista wniosków">
  <?php if (!$apps): ?>
    <p class="empty"><i class="bi bi-inbox" aria-hidden="true"></i>Brak wniosków.</p>
  <?php else: ?>
  <div class="tw-overflow-x-auto">
  <table class="h-table">
    <thead><tr><th scope="col">#</th><th scope="col">Osoba</th><th scope="col">Uzasadnienie</th><th scope="col">Złożony</th><th scope="col">Status</th><th scope="col"><span class="tw-sr-only">Akcje</span></th></tr></thead>
    <tbody>
    <?php foreach ($apps as $a): ?>
      <tr>
        <td class="mono">#<?= (int)$a['id'] ?></td>
        <td><span class="tw-font-semibold"><?= h($a['user_name'] ?: '#' . $a['user_id']) ?></span><span class="sub"><?= h($a['user_email']) ?></span>
          <?php if ($a['status'] === 'pending' && (int)$a['user_live_cards']): ?><span class="sub" style="color:#b45309"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ma już kartę</span><?php endif; ?></td>
        <td class="tw-max-w-[340px]"><?= nl2br(h($a['reason'])) ?>
          <?php if ($a['decision_note']): ?><span class="sub"><strong>Decyzja:</strong> <?= h($a['decision_note']) ?></span><?php endif; ?></td>
        <td class="tw-whitespace-nowrap"><?= h(date('d.m.Y H:i', strtotime($a['created_at']))) ?>
          <?php if ($a['decided_at']): ?><span class="sub">decyzja <?= h(date('d.m.Y', strtotime($a['decided_at']))) ?> · <?= h($a['decided_by_name'] ?? '') ?></span><?php endif; ?></td>
        <td><?= scard_status_badge($a['status']) ?>
          <?php if ($a['card_id']): ?><a class="sub" href="<?= h(APP_URL . '/modules/smart_cards/card.php?id=' . (int)$a['card_id']) ?>">karta <?= h(scard_uid_display((string)$a['card_uid'])) ?></a><?php endif; ?></td>
        <td class="tw-whitespace-nowrap tw-text-right">
          <?php if ($a['status'] === 'pending'): ?>
          <button type="button" class="h-btn h-btn--primary h-btn--sm" @click="openApprove(<?= h($appJs($a)) ?>)"><i class="bi bi-check2" aria-hidden="true"></i> Zatwierdź</button>
          <button type="button" class="h-btn h-btn--ghost h-btn--sm" @click="app = <?= h($appJs($a)) ?>; modal = 'reject'"><i class="bi bi-x" aria-hidden="true"></i> Odrzuć</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<!-- ── Nowy wniosek ─────────────────────────────────────────────────────────── -->
<div class="sc-modal" x-show="modal === 'new'" x-cloak x-transition.opacity role="dialog" aria-modal="true" aria-labelledby="m-new-t" @click.self="modal = ''">
  <form method="post" action="<?= h($SELF) ?>" class="sc-modal__box tw-max-w-[520px]" x-trap.noscroll="modal === 'new'">
    <?= csrf_field() ?><input type="hidden" name="_action" value="new">
    <div class="sc-modal__hd"><h2 id="m-new-t">Nowy wniosek o kartę</h2><button type="button" class="sc-modal__x" @click="modal = ''" aria-label="Zamknij"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
    <div class="sc-modal__bd tw-space-y-4">
      <?php if ($error && $modal === 'new'): ?><div class="alert-err" role="alert"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= h($error) ?></div></div><?php endif; ?>
      <div><label for="n_user" class="f-label">Osoba <span aria-hidden="true" style="color:#b91c1c">*</span></label>
        <select id="n_user" name="user_id" required class="f-input">
          <option value="">— wybierz —</option>
          <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $preUser === (int)$u['id'] ? ' selected' : '' ?>><?= h($u['name'] . ' — ' . $u['email']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="n_reason" class="f-label">Uzasadnienie <span aria-hidden="true" style="color:#b91c1c">*</span></label>
        <textarea id="n_reason" name="reason" rows="3" required minlength="5" maxlength="2000" class="f-input" placeholder="np. nowy pracownik biura, wymiana uszkodzonej karty"><?= h(($old['_action'] ?? '') === 'new' ? ($old['reason'] ?? '') : '') ?></textarea></div>
    </div>
    <div class="sc-modal__ft"><button type="button" class="h-btn h-btn--ghost" @click="modal = ''">Anuluj</button><button type="submit" class="h-btn h-btn--primary">Złóż wniosek</button></div>
  </form>
</div>

<!-- ── Zatwierdzenie = wydanie karty ────────────────────────────────────────── -->
<div class="sc-modal" x-show="modal === 'approve'" x-cloak x-transition.opacity role="dialog" aria-modal="true" aria-labelledby="m-ap-t" @click.self="modal = ''">
  <form method="post" action="<?= h($SELF) ?>" class="sc-modal__box tw-max-w-[860px]" x-trap.noscroll="modal === 'approve'">
    <?= csrf_field() ?><input type="hidden" name="_action" value="approve"><input type="hidden" name="application_id" :value="app.id">
    <div class="sc-modal__hd"><h2 id="m-ap-t">Wydaj kartę — <span x-text="app.user"></span></h2><button type="button" class="sc-modal__x" @click="modal = ''" aria-label="Zamknij"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
    <div class="sc-modal__bd">
      <?php if ($error && $modal === 'approve'): ?><div class="alert-err" role="alert"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= h($error) ?></div></div><?php endif; ?>
      <p class="tw-mt-0 tw-text-sm" style="color:var(--tz-muted)"><strong>Uzasadnienie:</strong> <span x-text="app.reason"></span></p>
      <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-5">
        <div class="tw-space-y-4">
          <div><label for="a_tpl" class="f-label">Wzór karty</label>
            <select id="a_tpl" name="template_id" x-model.number="tplId" class="f-input">
              <template x-for="t in templates" :key="t.id"><option :value="t.id" x-text="t.name" :selected="t.id === tplId"></option></template>
            </select></div>
          <div><label for="a_exp" class="f-label">Ważna do</label>
            <input id="a_exp" type="date" name="expires_at" x-model="expires" required class="f-input"
                   min="<?= h(date('Y-m-d', strtotime('+1 day'))) ?>" max="<?= h(date('Y-m-d', strtotime('+' . SCARD_MAX_VALIDITY_YEARS . ' years'))) ?>"></div>
          <div><label for="a_uid" class="f-label">UID chipu (opcjonalnie)</label>
            <input id="a_uid" name="card_uid" x-model="uid" class="f-input is-mono" autocomplete="off" placeholder="04:A1:B2:C3:D4:E5:F6" aria-describedby="a_uid_h">
            <p id="a_uid_h" class="f-help">Czytnik USB wpisze UID sam. Puste — tymczasowy UID, fizyczny przypisze Programator NFC.</p></div>
          <template x-if="app.live > 0">
            <label class="f-check" style="color:#b45309"><input type="checkbox" name="replace" value="1" required>
              <span>Zastąp dotychczasową kartę — zostanie zablokowana z adnotacją o zastąpieniu.</span></label>
          </template>
        </div>
        <div>
          <p class="f-label">Podgląd</p>
          <div :class="'sc-card pat-' + tpl.design.pattern + ' chip-' + tpl.design.chip" :style="scardVars(tpl.design)" aria-hidden="true">
            <div class="sc-card__top"><span class="sc-card__label" x-text="tpl.design.label"></span>
              <svg class="sc-card__nfc" viewBox="0 0 24 24"><path d="M8.5 7.5a6.5 6.5 0 0 1 0 9M12 5a10 10 0 0 1 0 14M15.5 2.5a13.5 13.5 0 0 1 0 19M5 10a3 3 0 0 1 0 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></div>
            <div class="sc-card__chip"><span></span></div>
            <div class="sc-card__uid" x-text="uid ? scardUid(uid.toUpperCase()) : '•••• •••• •••• ••'"></div>
            <div class="sc-card__bottom">
              <div><span class="sc-card__cap">Posiadacz</span><span class="sc-card__holder" x-text="(app.user || '').toUpperCase()"></span></div>
              <div style="text-align:right"><span class="sc-card__cap">Ważna do</span><span class="sc-card__exp" x-text="expShort"></span></div>
            </div>
          </div>
        </div>
      </div>
      <fieldset class="tw-mt-5 tw-m-0 tw-p-0">
        <legend class="f-label">Strefy dostępu</legend>
        <?php if (!$zones): ?><p class="f-help">Brak stref — zdefiniuj je w zakładce <a href="<?= h(APP_URL . '/modules/smart_cards/zones.php') ?>">Strefy dostępu</a>.</p><?php endif; ?>
        <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-2">
          <template x-for="z in zones" :key="z.id">
            <label class="f-check zone-opt">
              <input type="checkbox" name="zones[]" :value="z.id" :checked="inherited(z.id) || picked.includes(z.id)" :disabled="inherited(z.id)"
                     @change="$event.target.checked ? picked.push(z.id) : picked = picked.filter(i => i !== z.id)">
              <span><span :class="'sc-lvl l' + z.level" x-text="'L' + z.level"></span> <span x-text="z.name"></span>
                <span class="sub" x-show="inherited(z.id)">ze wzoru — dla wszystkich kart tego wzoru</span></span>
            </label>
          </template>
        </div>
      </fieldset>
      <div class="tw-mt-4"><label for="a_note" class="f-label">Notatka do decyzji</label>
        <input id="a_note" name="note" maxlength="1000" class="f-input" value="<?= h($oldApp['note'] ?? '') ?>"></div>
    </div>
    <div class="sc-modal__ft"><button type="button" class="h-btn h-btn--ghost" @click="modal = ''">Anuluj</button>
      <button type="submit" class="h-btn h-btn--primary"><i class="bi bi-credit-card" aria-hidden="true"></i> Zatwierdź i wydaj kartę</button></div>
  </form>
</div>

<!-- ── Odrzucenie ───────────────────────────────────────────────────────────── -->
<div class="sc-modal" x-show="modal === 'reject'" x-cloak x-transition.opacity role="dialog" aria-modal="true" aria-labelledby="m-rj-t" @click.self="modal = ''">
  <form method="post" action="<?= h($SELF) ?>" class="sc-modal__box tw-max-w-[480px]" x-trap.noscroll="modal === 'reject'">
    <?= csrf_field() ?><input type="hidden" name="_action" value="reject"><input type="hidden" name="application_id" :value="app.id">
    <div class="sc-modal__hd"><h2 id="m-rj-t">Odrzuć wniosek — <span x-text="app.user"></span></h2><button type="button" class="sc-modal__x" @click="modal = ''" aria-label="Zamknij"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
    <div class="sc-modal__bd">
      <?php if ($error && $modal === 'reject'): ?><div class="alert-err" role="alert"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= h($error) ?></div></div><?php endif; ?>
      <label for="r_reason" class="f-label">Powód odrzucenia <span aria-hidden="true" style="color:#b91c1c">*</span></label>
      <textarea id="r_reason" name="reason" rows="3" required maxlength="1000" class="f-input"></textarea>
      <p class="f-help">Wnioskujący zobaczy powód w zakładce „Moja karta”.</p>
    </div>
    <div class="sc-modal__ft"><button type="button" class="h-btn h-btn--ghost" @click="modal = ''">Anuluj</button><button type="submit" class="h-btn h-btn--danger">Odrzuć</button></div>
  </form>
</div>
</div>

<script>
function scApps() {
  return {
    modal: <?= json_encode($modal) ?>,
    templates: <?= json_encode($tplJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    zones: <?= json_encode($zoneJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    app: <?= $oldApp ? json_encode(['id' => (int)$oldApp['application_id'], 'user' => (string)(array_column($apps, 'user_name', 'id')[(int)$oldApp['application_id']] ?? ''), 'reason' => (string)(array_column($apps, 'reason', 'id')[(int)$oldApp['application_id']] ?? ''), 'live' => (int)(array_column($apps, 'user_live_cards', 'id')[(int)$oldApp['application_id']] ?? 0)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) : "{ id: 0, user: '', reason: '', live: 0 }" ?>,
    tplId: <?= (int)($oldApp['template_id'] ?? $defTpl) ?>,
    expires: <?= json_encode((string)($oldApp['expires_at'] ?? SmartCardService::defaultExpiry())) ?>,
    uid: <?= json_encode((string)($oldApp['card_uid'] ?? ''), JSON_HEX_TAG) ?>,
    picked: <?= json_encode(array_map('intval', (array)($oldApp['zones'] ?? []))) ?>,
    get tpl() { return this.templates.find(t => t.id === this.tplId) || this.templates[0]; },
    get expShort() { const m = /^(\d{4})-(\d{2})/.exec(this.expires || ''); return m ? m[2] + '/' + m[1].slice(2) : '--/--'; },
    inherited(id) { return (this.tpl?.zones || []).includes(id); },
    openApprove(a) { this.app = a; this.uid = ''; this.picked = []; this.modal = 'approve'; },
  };
}
</script>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
