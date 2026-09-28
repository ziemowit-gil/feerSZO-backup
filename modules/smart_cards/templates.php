<?php
/**
 * modules/smart_cards/templates.php — wzory kart: wygląd (edytor z podglądem na żywo),
 * wzór domyślny i strefy dziedziczone przez wszystkie karty danego wzoru.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/smart_cards.php';

require_role('admin', 'editor');
require_module_enabled('smart_cards_enabled', 'Moduł Karty dostępu');

$svc  = new SmartCardService(current_user());
$SELF = APP_URL . '/modules/smart_cards/templates.php';
$error = null;
$reopen = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['_action'] ?? '');
    try {
        if ($action === 'save') {
            $tid = (int)($_POST['id'] ?? 0);
            $svc->saveTemplate($tid ?: null, (string)($_POST['name'] ?? ''), (array)($_POST['design'] ?? []), !empty($_POST['is_default']), (array)($_POST['zones'] ?? []));
            flash_set('success', 'Zapisano wzór karty.');
        } elseif ($action === 'delete') {
            $svc->deleteTemplate((int)($_POST['id'] ?? 0));
            flash_set('success', 'Usunięto wzór.');
        } else {
            throw new SmartCardException('Nieznana operacja.');
        }
        header('Location: ' . $SELF); exit;
    } catch (SmartCardException $e) {
        $error = $e->getMessage();
        if ($action === 'save') $reopen = [
            'id' => (int)($_POST['id'] ?? 0), 'name' => (string)($_POST['name'] ?? ''), 'is_default' => !empty($_POST['is_default']),
            'design' => scard_normalize_design((array)($_POST['design'] ?? [])), 'zones' => array_map('intval', (array)($_POST['zones'] ?? [])),
        ];
    } catch (\Throwable $e) {
        error_log('[smart_cards/templates] ' . $e->getMessage());
        $error = 'Operacja nie powiodła się — nic nie zostało zapisane.';
    }
}
$templates = $svc->templates();
$zones     = $svc->zones(true);
$zoneName  = array_column($svc->zones(), 'zone_name', 'id');
$tJs = fn(array $t) => json_encode(['id' => (int)$t['id'], 'name' => $t['name'], 'is_default' => (bool)$t['is_default'], 'design' => $t['design'], 'zones' => $t['zone_ids']], JSON_UNESCAPED_UNICODE);
$blank = ['id' => 0, 'name' => '', 'is_default' => false, 'design' => SCARD_DEFAULT_DESIGN, 'zones' => []];

$PAGE_TITLE = 'Wzory kart';
include dirname(__DIR__, 2) . '/includes/header.php';
include __DIR__ . '/partials/ui.php';
?>
<div class="scm" x-data="{ modal: <?= $reopen ? "'tpl'" : "''" ?>, t: <?= h(json_encode($reopen ?? $blank, JSON_UNESCAPED_UNICODE)) ?>,
     blank: <?= h(json_encode($blank, JSON_UNESCAPED_UNICODE)) ?>,
     open(x) { this.t = JSON.parse(JSON.stringify(x)); this.modal = 'tpl'; } }" @keydown.escape.window="modal = ''">
<header class="dash-h">
  <div>
    <h1><span class="dash-h__ico" aria-hidden="true"><i class="bi bi-palette"></i></span> Wzory kart</h1>
    <p>Wygląd nadruku oraz strefy, które dostaje każda karta danego wzoru (np. „Pracownik biura” → recepcja + open space).</p>
  </div>
  <button type="button" class="h-btn h-btn--primary" @click="open(blank)"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nowy wzór</button>
</header>
<?php scard_tabs('templates', $svc->stats()['pending']); ?>
<?= flash_html() ?>
<?php if ($error && !$reopen): ?><div class="alert-err" role="alert"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= h($error) ?></div></div><?php endif; ?>

<div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 xl:tw-grid-cols-3 tw-gap-5">
  <?php foreach ($templates as $t): ?>
  <article class="tz-card" aria-labelledby="tpl-<?= (int)$t['id'] ?>">
    <div class="tz-card__bd tw-space-y-3">
      <?= scard_render($t['design'], ['holder' => 'Jan Kowalski', 'uid' => '04:A1:B2:C3:D4:E5:F6', 'expires_at' => SmartCardService::defaultExpiry(), 'status' => 'active', 'template' => $t['name']]) ?>
      <div class="tw-flex tw-items-start tw-gap-2">
        <div class="tw-flex-1">
          <h2 id="tpl-<?= (int)$t['id'] ?>" class="tw-text-base tw-font-bold tw-m-0"><?= h($t['name']) ?>
            <?php if ($t['is_default']): ?><span class="sc-badge is-pending">domyślny</span><?php endif; ?></h2>
          <p class="sub tw-m-0"><?= (int)$t['cards_active'] ?> aktywnych z <?= (int)$t['cards_total'] ?> kart</p>
        </div>
        <button type="button" class="h-btn h-btn--ghost h-btn--sm" @click="open(<?= h($tJs($t)) ?>)" aria-label="Edytuj wzór <?= h($t['name']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></button>
        <?php if (!$t['is_default'] && !(int)$t['cards_total']): ?>
        <form method="post" action="<?= h($SELF) ?>" onsubmit="return confirm('Usunąć wzór?')"><?= csrf_field() ?>
          <input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button class="h-btn h-btn--ghost h-btn--sm" type="submit" aria-label="Usuń wzór <?= h($t['name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
        <?php endif; ?>
      </div>
      <p class="tw-m-0 tw-text-sm"><strong>Strefy ze wzoru:</strong>
        <?= $t['zone_ids'] ? h(implode(', ', array_map(fn($z) => $zoneName[$z] ?? '#' . $z, $t['zone_ids']))) : '<span style="color:var(--tz-muted)">brak — tylko indywidualne</span>' ?></p>
    </div>
  </article>
  <?php endforeach; ?>
</div>

<div class="sc-modal" x-show="modal === 'tpl'" x-cloak x-transition.opacity role="dialog" aria-modal="true" aria-labelledby="m-t-t" @click.self="modal = ''">
  <form method="post" action="<?= h($SELF) ?>" class="sc-modal__box tw-max-w-[900px]" x-trap.noscroll="modal === 'tpl'">
    <?= csrf_field() ?><input type="hidden" name="_action" value="save"><input type="hidden" name="id" :value="t.id">
    <div class="sc-modal__hd"><h2 id="m-t-t" x-text="t.id ? 'Edytuj wzór karty' : 'Nowy wzór karty'"></h2><button type="button" class="sc-modal__x" @click="modal = ''" aria-label="Zamknij"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
    <div class="sc-modal__bd">
      <?php if ($error && $reopen): ?><div class="alert-err" role="alert"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= h($error) ?></div></div><?php endif; ?>
      <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-6">
        <div class="tw-space-y-4">
          <div><label for="t_name" class="f-label">Nazwa wzoru <span aria-hidden="true" style="color:#b91c1c">*</span></label>
            <input id="t_name" name="name" x-model="t.name" required maxlength="80" class="f-input" placeholder="np. Pracownik biura"></div>
          <div><label for="t_label" class="f-label">Napis na karcie</label>
            <input id="t_label" name="design[label]" x-model="t.design.label" maxlength="32" class="f-input"></div>
          <div class="tw-grid tw-grid-cols-2 tw-gap-3">
            <?php foreach (['bg_from' => 'Tło — kolor 1', 'bg_to' => 'Tło — kolor 2', 'text' => 'Tekst', 'accent' => 'Akcent (wzór)'] as $k => $lbl): ?>
            <div><label for="t_<?= $k ?>" class="f-label"><?= h($lbl) ?></label>
              <div class="tw-flex tw-gap-2 tw-items-center">
                <input id="t_<?= $k ?>" type="color" name="design[<?= $k ?>]" x-model="t.design.<?= $k ?>" class="tw-h-9 tw-w-12 tw-rounded-lg tw-border tw-cursor-pointer" style="border-color:#cbd5e1;padding:2px">
                <span class="mono" x-text="t.design.<?= $k ?>" aria-hidden="true"></span></div></div>
            <?php endforeach; ?>
          </div>
          <div><label for="t_angle" class="f-label">Kąt gradientu: <span x-text="t.design.angle + '°'"></span></label>
            <input id="t_angle" type="range" min="0" max="360" step="5" name="design[angle]" x-model.number="t.design.angle" class="tw-w-full" style="accent-color:var(--sc-brand)"></div>
          <div class="tw-grid tw-grid-cols-2 tw-gap-3">
            <div><label for="t_pat" class="f-label">Wzór tła</label>
              <select id="t_pat" name="design[pattern]" x-model="t.design.pattern" class="f-input">
                <?php foreach (SCARD_PATTERNS as $k => $lbl): ?><option value="<?= h($k) ?>"><?= h($lbl) ?></option><?php endforeach; ?></select></div>
            <div><label for="t_chip" class="f-label">Chip</label>
              <select id="t_chip" name="design[chip]" x-model="t.design.chip" class="f-input">
                <?php foreach (SCARD_CHIPS as $k => $lbl): ?><option value="<?= h($k) ?>"><?= h($lbl) ?></option><?php endforeach; ?></select></div>
          </div>
          <label class="f-check"><input type="checkbox" name="is_default" value="1" x-model="t.is_default"> <span>Wzór domyślny <span class="sub">Podpowiadany przy zatwierdzaniu wniosków.</span></span></label>
        </div>
        <div>
          <p class="f-label">Podgląd</p>
          <div :class="'sc-card pat-' + t.design.pattern + ' chip-' + t.design.chip" :style="scardVars(t.design)" aria-hidden="true">
            <div class="sc-card__top"><span class="sc-card__label" x-text="t.design.label"></span>
              <svg class="sc-card__nfc" viewBox="0 0 24 24"><path d="M8.5 7.5a6.5 6.5 0 0 1 0 9M12 5a10 10 0 0 1 0 14M15.5 2.5a13.5 13.5 0 0 1 0 19M5 10a3 3 0 0 1 0 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></div>
            <div class="sc-card__chip"><span></span></div>
            <div class="sc-card__uid">04A1 B2C3 D4E5 F6</div>
            <div class="sc-card__bottom">
              <div><span class="sc-card__cap">Posiadacz</span><span class="sc-card__holder">JAN KOWALSKI</span></div>
              <div style="text-align:right"><span class="sc-card__cap">Ważna do</span><span class="sc-card__exp"><?= h(date('m/y', strtotime('+2 years'))) ?></span></div>
            </div>
          </div>
          <fieldset class="tw-mt-5 tw-m-0 tw-p-0">
            <legend class="f-label">Strefy dla wszystkich kart tego wzoru</legend>
            <?php if (!$zones): ?><p class="f-help">Brak aktywnych stref.</p><?php endif; ?>
            <div class="tw-space-y-1.5">
              <?php foreach ($zones as $z): ?>
              <label class="f-check zone-opt"><input type="checkbox" name="zones[]" value="<?= (int)$z['id'] ?>" x-model.number="t.zones">
                <span><span class="sc-lvl l<?= (int)$z['security_level'] ?>">L<?= (int)$z['security_level'] ?></span> <?= h($z['zone_name']) ?></span></label>
              <?php endforeach; ?>
            </div>
            <p class="f-help">Zmiana działa od razu na wszystkie aktywne karty tego wzoru.</p>
          </fieldset>
        </div>
      </div>
    </div>
    <div class="sc-modal__ft"><button type="button" class="h-btn h-btn--ghost" @click="modal = ''">Anuluj</button><button type="submit" class="h-btn h-btn--primary">Zapisz wzór</button></div>
  </form>
</div>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
