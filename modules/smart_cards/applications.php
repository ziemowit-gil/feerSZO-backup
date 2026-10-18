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
require_once __DIR__ . '/partials/application_form.php';

require_role('admin', 'editor');
require_module_enabled('smart_cards_enabled', 'Moduł Karty dostępu');

$svc  = new SmartCardService(current_user());
$BASE = APP_URL . '/modules/smart_cards/applications.php';
$f_status = isset(SCARD_APP_STATUSES[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$SELF = $BASE . ($f_status ? '?status=' . $f_status : '');

// Podpowiedzi do formularza po wyborze osoby (jednostka, stanowisko, kontakt, dotychczasowe karty)
if (isset($_GET['defaults'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($svc->applicantDefaults((int)$_GET['defaults']), JSON_UNESCAPED_UNICODE);
    exit;
}

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
            $svc->submitApplication((int)($_POST['user_id'] ?? 0), $_POST, $_FILES['photo'] ?? null);
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
$zoneNames = array_column($svc->zones(), 'zone_name', 'id');
$zoneJs = array_map(fn($z) => ['id' => (int)$z['id'], 'name' => $z['zone_name'], 'level' => (int)$z['security_level']], $zones);
$appJs  = fn(array $a) => json_encode(['id' => (int)$a['id'], 'user' => (string)$a['user_name'], 'reason' => (string)$a['reason'], 'live' => (int)$a['user_live_cards'],
    'type' => (string)$a['app_type'], 'tpl' => (int)$a['template_id'], 'zones' => SmartCardService::idList((string)$a['zone_ids']), 'exp' => (string)$a['valid_to']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$oldApp = ($old['_action'] ?? '') === 'approve' ? $old : [];
$preUser = (int)($_GET['user_id'] ?? ($old['user_id'] ?? 0));
$oldNew  = ($old['_action'] ?? '') === 'new' ? $old : [];

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

<?php
$allApps = $f_status ? $svc->applications('') : $apps;
$cnt = ['' => count($allApps)] + array_fill_keys(array_keys(SCARD_APP_STATUSES), 0);
foreach ($allApps as $x) $cnt[$x['status']] = ($cnt[$x['status']] ?? 0) + 1;
$typeIco = ['new' => 'bi-credit-card-2-front', 'renewal' => 'bi-arrow-repeat', 'replacement' => 'bi-arrow-left-right', 'duplicate' => 'bi-files', 'lost' => 'bi-question-octagon'];
?>
<nav class="kpi-grid ap-kpi" aria-label="Filtr statusu">
  <?php foreach (['' => ['label' => 'Wszystkie', 'icon' => 'bi-inbox', 'cls' => 'is-all']] + array_map(fn($x) => $x + ['cls' => ''], SCARD_APP_STATUSES) as $k => $st): ?>
  <a class="kpi-tile <?= h($st['cls'] ?: 'is-' . $k) ?>" href="<?= h($BASE . ($k ? '?status=' . $k : '')) ?>"<?= $f_status === $k ? ' aria-current="true"' : '' ?>>
    <span class="kpi-tile__ico" aria-hidden="true"><i class="bi <?= h($st['icon']) ?>"></i></span>
    <span class="kpi-tile__val"><?= (int)$cnt[$k] ?></span><span class="kpi-tile__lbl"><?= h($st['label']) ?></span>
  </a>
  <?php endforeach; ?>
</nav>

<?php if (!$apps): ?>
  <section class="tz-card"><p class="empty"><i class="bi bi-inbox" aria-hidden="true"></i>Brak wniosków.</p></section>
<?php else: ?>
<div class="tw-flex tw-flex-wrap tw-items-center tw-gap-3 tw-mb-4">
  <label for="ap_q" class="tw-sr-only">Szukaj wniosku</label>
  <div class="tw-relative tw-flex-1 tw-min-w-[220px] tw-max-w-[420px]">
    <i class="bi bi-search ap-q__ico" aria-hidden="true"></i>
    <input id="ap_q" type="search" x-model="q" class="f-input ap-q" placeholder="Szukaj: osoba, numer, jednostka, uzasadnienie" autocomplete="off">
  </div>
  <p class="tw-m-0 tw-text-sm" style="color:var(--tz-muted)" aria-live="polite"><span x-text="shown"></span> z <?= count($apps) ?> wniosków</p>
</div>

<ul class="ap-grid" aria-label="Lista wniosków">
  <?php foreach ($apps as $a):
      $no = SmartCardService::applicationNumber($a);
      $zl = SmartCardService::idList((string)$a['zone_ids']);
      $det = APP_URL . '/modules/smart_cards/application.php?id=' . (int)$a['id'];
      $hay = mb_strtolower(implode(' ', [$no, $a['user_name'], $a['user_email'], $a['org_unit'], $a['job_title'], $a['reason'], SCARD_APP_TYPES[$a['app_type']]['label'] ?? '']));
  ?>
  <li class="ap-card is-<?= h($a['status']) ?>" data-q="<?= h($hay) ?>" x-show="match($el)">
    <div class="ap-card__hd">
      <span class="ap-card__type" title="<?= h(SCARD_APP_TYPES[$a['app_type']]['label'] ?? '') ?>"><i class="bi <?= h($typeIco[$a['app_type']] ?? 'bi-card-text') ?>" aria-hidden="true"></i> <?= h(SCARD_APP_TYPES[$a['app_type']]['short'] ?? $a['app_type']) ?></span>
      <?= scard_status_badge($a['status']) ?>
    </div>
    <h2 class="ap-card__name"><a href="<?= h($det) ?>"><?= h($a['user_name'] ?: '#' . $a['user_id']) ?></a></h2>
    <p class="ap-card__sub"><span class="mono"><?= h($no) ?></span><?php if ($a['job_title'] || $a['org_unit']): ?> · <?= h(implode(' · ', array_filter([$a['job_title'], $a['org_unit']]))) ?><?php endif; ?></p>
    <?php if ($a['status'] === 'pending' && (int)$a['user_live_cards']): ?><p class="ap-card__warn"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Osoba ma już aktywną kartę</p><?php endif; ?>
    <dl class="ap-card__dl">
      <dt>Wzór</dt><dd><?= h($a['template_name'] ?: 'domyślny') ?><?= $a['photo_path'] ? ' <i class="bi bi-person-square" title="ze zdjęciem" aria-label="ze zdjęciem"></i>' : '' ?></dd>
      <dt>Strefy</dt><dd><?php if ($zl): foreach ($zl as $z): ?><span class="ap-chip"><?= h($zoneNames[$z] ?? '#' . $z) ?></span><?php endforeach; else: ?>wg wzoru<?php endif; ?></dd>
      <dt>Ważność</dt><dd><?= $a['valid_to'] ? 'do ' . h(date('d.m.Y', strtotime($a['valid_to']))) : 'standardowa' ?></dd>
      <dt>Złożony</dt><dd><?= h(date('d.m.Y H:i', strtotime($a['created_at']))) ?></dd>
    </dl>
    <p class="ap-card__reason"><?= h($a['reason']) ?></p>
    <?php if ($a['decided_at']): ?>
    <p class="ap-card__dec"><i class="bi <?= $a['status'] === 'approved' ? 'bi-check2-circle' : 'bi-x-circle' ?>" aria-hidden="true"></i>
      <?= h(date('d.m.Y', strtotime($a['decided_at']))) ?> · <?= h($a['decided_by_name'] ?? '') ?>
      <?php if ($a['card_id']): ?> · <a class="mono" href="<?= h(APP_URL . '/modules/smart_cards/card.php?id=' . (int)$a['card_id']) ?>"><?= h(scard_uid_display((string)$a['card_uid'])) ?></a><?php endif; ?>
      <?php if ($a['decision_note']): ?><span class="sub"><?= h($a['decision_note']) ?></span><?php endif; ?></p>
    <?php endif; ?>
    <div class="ap-card__ft">
      <a class="h-btn h-btn--ghost h-btn--sm" href="<?= h($det) ?>"><i class="bi bi-eye" aria-hidden="true"></i> Szczegóły<span class="tw-sr-only"> wniosku <?= h($no) ?></span></a>
      <a class="h-btn h-btn--ghost h-btn--sm" href="<?= h(APP_URL . '/modules/smart_cards/application_pdf.php?id=' . (int)$a['id']) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF<span class="tw-sr-only"> wniosku <?= h($no) ?></span></a>
      <?php if ($a['status'] === 'pending'): ?>
      <span class="tw-flex-1"></span>
      <button type="button" class="h-btn h-btn--ghost h-btn--sm" @click="app = <?= h($appJs($a)) ?>; modal = 'reject'"><i class="bi bi-x" aria-hidden="true"></i> Odrzuć</button>
      <button type="button" class="h-btn h-btn--primary h-btn--sm" @click="openApprove(<?= h($appJs($a)) ?>)"><i class="bi bi-check2" aria-hidden="true"></i> Zatwierdź</button>
      <?php endif; ?>
    </div>
  </li>
  <?php endforeach; ?>
</ul>
<p class="empty" x-show="shown === 0" x-cloak><i class="bi bi-search" aria-hidden="true"></i>Nic nie pasuje do wyszukiwania.</p>
<?php endif; ?>

<style>
.scm .ap-kpi { grid-template-columns: repeat(2, minmax(0, 1fr)); }
@media (min-width: 640px) { .scm .ap-kpi { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
.scm .kpi-tile.is-approved { --c: var(--st-approved); } .scm .kpi-tile.is-rejected { --c: var(--st-rejected); }
.scm .ap-q { padding-left: 2.1rem; }
.scm .ap-q__ico { position: absolute; left: .75rem; top: 50%; transform: translateY(-50%); color: var(--tz-muted); }
.scm .ap-grid { list-style: none; margin: 0 0 1.5rem; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem; }
.scm .ap-card { display: flex; flex-direction: column; gap: .45rem; background: #fff; border: 1px solid var(--tz-line); border-left: 5px solid var(--c, #94a3b8); border-radius: 14px; padding: 1rem 1.1rem; }
.scm .ap-card.is-pending { --c: var(--st-pending); } .scm .ap-card.is-approved { --c: var(--st-approved); } .scm .ap-card.is-rejected { --c: var(--st-rejected); }
.scm .ap-card__hd { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
.scm .ap-card__type { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--tz-muted); }
.scm .ap-card__name { font-size: 1.05rem; font-weight: 800; margin: 0; line-height: 1.25; }
.scm .ap-card__name a { color: var(--tz-ink); text-decoration: none; } .scm .ap-card__name a:hover { color: var(--sc-brand); text-decoration: underline; }
.scm .ap-card__sub { margin: 0; font-size: .8rem; color: var(--tz-muted); }
.scm .ap-card__warn { margin: 0; font-size: .8rem; font-weight: 600; color: #b45309; }
.scm .ap-card__dl { display: grid; grid-template-columns: auto 1fr; gap: .2rem .8rem; margin: .2rem 0 0; font-size: .82rem; }
.scm .ap-card__dl dt { color: var(--tz-muted); } .scm .ap-card__dl dd { margin: 0; }
.scm .ap-chip { display: inline-block; margin: 0 .25rem .2rem 0; padding: .05rem .5rem; border-radius: 999px; background: #f1f5f9; font-size: .75rem; }
.scm .ap-card__reason { margin: 0; font-size: .85rem; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.scm .ap-card__dec { margin: 0; font-size: .8rem; padding: .45rem .6rem; border-radius: 8px; background: #f8fafc; }
.scm .ap-card__ft { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; margin-top: auto; padding-top: .6rem; border-top: 1px solid var(--tz-line); }
</style>

<!-- ── Nowy wniosek ─────────────────────────────────────────────────────────── -->
<div class="sc-modal" x-show="modal === 'new'" x-cloak x-transition.opacity role="dialog" aria-modal="true" aria-labelledby="m-new-t" @click.self="modal = ''">
  <form method="post" action="<?= h($SELF) ?>" enctype="multipart/form-data" class="sc-modal__box tw-max-w-[860px]" x-trap.noscroll="modal === 'new'">
    <?= csrf_field() ?><input type="hidden" name="_action" value="new">
    <div class="sc-modal__hd"><h2 id="m-new-t">Nowy wniosek o kartę</h2><button type="button" class="sc-modal__x" @click="modal = ''" aria-label="Zamknij"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
    <div class="sc-modal__bd">
      <?php if ($error && $modal === 'new'): ?><div class="alert-err" role="alert"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= h($error) ?></div></div><?php endif; ?>
      <?php scard_application_fields([
          'users' => $users, 'supervisors' => $users, 'user_id' => $preUser, 'old' => $oldNew,
          'defaults' => $preUser ? $svc->applicantDefaults($preUser) : null,
          'templates' => $templates, 'zones' => $zones, 'defaults_url' => $BASE . '?defaults=', 'prefix' => 'n',
      ]); ?>
    </div>
    <div class="sc-modal__ft"><button type="button" class="h-btn h-btn--ghost" @click="modal = ''">Anuluj</button></div>
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
      <p class="tw-mt-0 tw-text-sm" style="color:var(--tz-muted)"><i class="bi bi-magic" aria-hidden="true"></i> Wzór, ważność i strefy podpowiedziano z wniosku — możesz je zmienić.</p>
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
            <label class="f-check" style="color:#b45309"><input type="checkbox" name="replace" value="1" required :checked="replacesTypes.includes(app.type)">
              <span x-text="app.type === 'lost' ? 'Zastąp dotychczasową kartę — wskazana we wniosku zostanie oznaczona jako zgubiona, pozostałe zablokowane.' : 'Zastąp dotychczasową kartę — zostanie zablokowana z adnotacją o zastąpieniu.'"></span></label>
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
    app: <?= ($oldAppRow = $oldApp ? (array_column($apps, null, 'id')[(int)$oldApp['application_id']] ?? null) : null) ? $appJs($oldAppRow) : "{ id: 0, user: '', reason: '', live: 0, type: 'new', tpl: 0, zones: [], exp: '' }" ?>,
    tplId: <?= (int)($oldApp['template_id'] ?? $defTpl) ?>,
    expires: <?= json_encode((string)($oldApp['expires_at'] ?? SmartCardService::defaultExpiry())) ?>,
    uid: <?= json_encode((string)($oldApp['card_uid'] ?? ''), JSON_HEX_TAG) ?>,
    picked: <?= json_encode(array_map('intval', (array)($oldApp['zones'] ?? []))) ?>,
    get tpl() { return this.templates.find(t => t.id === this.tplId) || this.templates[0]; },
    get expShort() { const m = /^(\d{4})-(\d{2})/.exec(this.expires || ''); return m ? m[2] + '/' + m[1].slice(2) : '--/--'; },
    inherited(id) { return (this.tpl?.zones || []).includes(id); },
    q: '',
    get shown() { const t = this.q.trim().toLowerCase(); return [...document.querySelectorAll('.ap-card')].filter(el => !t || el.dataset.q.includes(t)).length; },
    match(el) { const t = this.q.trim().toLowerCase(); return !t || el.dataset.q.includes(t); },
    replacesTypes: <?= json_encode(array_keys(array_filter(SCARD_APP_TYPES, fn($t) => $t['replaces']))) ?>,
    defTpl: <?= (int)$defTpl ?>,
    // podpowiedzi z wniosku: wzór, ważność (gdy jeszcze w przyszłości), strefy spoza wzoru
    openApprove(a) {
      this.app = a; this.uid = '';
      this.tplId = this.templates.some(t => t.id === a.tpl) ? a.tpl : this.defTpl;
      this.expires = a.exp && a.exp > <?= json_encode(date('Y-m-d')) ?> ? a.exp : <?= json_encode(SmartCardService::defaultExpiry()) ?>;
      this.picked = (a.zones || []).filter(z => this.zones.some(x => x.id === z));
      this.modal = 'approve';
    },
  };
}
</script>
<?php scard_application_form_js(); ?>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
