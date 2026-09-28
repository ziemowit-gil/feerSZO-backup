<?php
/**
 * modules/smart_cards/zones.php — strefy dostępu: dodawanie, edycja, poziom
 * bezpieczeństwa 1–5, wyłączanie (natychmiastowa utrata dostępu) i usuwanie nieużywanych.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/smart_cards.php';

require_role('admin', 'editor');
require_module_enabled('smart_cards_enabled', 'Moduł Karty dostępu');

$svc  = new SmartCardService(current_user());
$SELF = APP_URL . '/modules/smart_cards/zones.php';
$error = null;
$reopen = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['_action'] ?? '');
    try {
        if ($action === 'save') {
            $zid = (int)($_POST['id'] ?? 0);
            $svc->saveZone($zid ?: null, (string)($_POST['zone_name'] ?? ''), (string)($_POST['description'] ?? ''), (int)($_POST['security_level'] ?? 0), !empty($_POST['is_active']));
            flash_set('success', $zid ? 'Zapisano zmiany strefy.' : 'Dodano strefę.');
        } elseif ($action === 'delete') {
            $svc->deleteZone((int)($_POST['id'] ?? 0));
            flash_set('success', 'Usunięto strefę.');
        } else {
            throw new SmartCardException('Nieznana operacja.');
        }
        header('Location: ' . $SELF); exit;
    } catch (SmartCardException $e) {
        $error = $e->getMessage();
        if ($action === 'save') $reopen = [
            'id' => (int)($_POST['id'] ?? 0), 'zone_name' => (string)($_POST['zone_name'] ?? ''), 'description' => (string)($_POST['description'] ?? ''),
            'security_level' => (int)($_POST['security_level'] ?? 1), 'is_active' => !empty($_POST['is_active']),
        ];
    } catch (\Throwable $e) {
        error_log('[smart_cards/zones] ' . $e->getMessage());
        $error = 'Operacja nie powiodła się — nic nie zostało zapisane.';
    }
}
$zones = $svc->zones();
$zJs = fn(array $z) => json_encode(['id' => (int)$z['id'], 'zone_name' => $z['zone_name'], 'description' => (string)$z['description'],
    'security_level' => (int)$z['security_level'], 'is_active' => (bool)$z['is_active']], JSON_UNESCAPED_UNICODE);

$PAGE_TITLE = 'Strefy dostępu';
include dirname(__DIR__, 2) . '/includes/header.php';
include __DIR__ . '/partials/ui.php';
?>
<div class="scm" x-data="{ modal: <?= $reopen ? "'zone'" : "''" ?>, z: <?= h(json_encode($reopen ?? ['id' => 0, 'zone_name' => '', 'description' => '', 'security_level' => 1, 'is_active' => true], JSON_UNESCAPED_UNICODE)) ?>,
     blank() { return { id: 0, zone_name: '', description: '', security_level: 1, is_active: true }; } }" @keydown.escape.window="modal = ''">
<header class="dash-h">
  <div>
    <h1><span class="dash-h__ico" aria-hidden="true"><i class="bi bi-door-closed"></i></span> Strefy dostępu</h1>
    <p>Pomieszczenia i obszary biura obsługiwane czytnikami. Wyłączenie strefy odbiera do niej dostęp wszystkim kartom od razu.</p>
  </div>
  <button type="button" class="h-btn h-btn--primary" @click="z = blank(); modal = 'zone'"><i class="bi bi-plus-lg" aria-hidden="true"></i> Dodaj strefę</button>
</header>
<?php scard_tabs('zones', $svc->stats()['pending']); ?>
<?= flash_html() ?>
<?php if ($error && !$reopen): ?><div class="alert-err" role="alert"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= h($error) ?></div></div><?php endif; ?>

<section class="tz-card" aria-label="Lista stref">
  <?php if (!$zones): ?>
    <p class="empty"><i class="bi bi-door-closed" aria-hidden="true"></i>Nie ma jeszcze stref. Dodaj np. „Recepcja”, „Biuro open space”, „Serwerownia”.</p>
  <?php else: ?>
  <div class="tw-overflow-x-auto">
  <table class="h-table">
    <thead><tr><th scope="col">Poziom</th><th scope="col">Strefa</th><th scope="col">Przypisania</th><th scope="col">Stan</th><th scope="col"><span class="tw-sr-only">Akcje</span></th></tr></thead>
    <tbody>
    <?php foreach ($zones as $z): $used = (int)$z['cards_direct'] + (int)$z['templates']; ?>
      <tr>
        <td><span class="sc-lvl l<?= (int)$z['security_level'] ?>">L<?= (int)$z['security_level'] ?> · <?= h(SCARD_LEVELS[(int)$z['security_level']]) ?></span></td>
        <td><span class="tw-font-semibold"><?= h($z['zone_name']) ?></span><?php if ($z['description']): ?><span class="sub"><?= h($z['description']) ?></span><?php endif; ?></td>
        <td class="tw-text-sm"><?= (int)$z['templates'] ?> wzorów · <?= (int)$z['cards_direct'] ?> kart indywidualnie</td>
        <td><?= $z['is_active'] ? '<span class="sc-badge is-active">Włączona</span>' : '<span class="sc-badge is-expired">Wyłączona</span>' ?></td>
        <td class="tw-whitespace-nowrap tw-text-right">
          <button type="button" class="h-btn h-btn--ghost h-btn--sm" @click="z = <?= h($zJs($z)) ?>; modal = 'zone'" aria-label="Edytuj strefę <?= h($z['zone_name']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></button>
          <?php if (!$used): ?>
          <form method="post" action="<?= h($SELF) ?>" class="tw-inline" onsubmit="return confirm('Usunąć strefę?')">
            <?= csrf_field() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= (int)$z['id'] ?>">
            <button class="h-btn h-btn--ghost h-btn--sm" type="submit" aria-label="Usuń strefę <?= h($z['zone_name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<div class="sc-modal" x-show="modal === 'zone'" x-cloak x-transition.opacity role="dialog" aria-modal="true" aria-labelledby="m-z-t" @click.self="modal = ''">
  <form method="post" action="<?= h($SELF) ?>" class="sc-modal__box tw-max-w-[520px]" x-trap.noscroll="modal === 'zone'">
    <?= csrf_field() ?><input type="hidden" name="_action" value="save"><input type="hidden" name="id" :value="z.id">
    <div class="sc-modal__hd"><h2 id="m-z-t" x-text="z.id ? 'Edytuj strefę' : 'Nowa strefa'"></h2><button type="button" class="sc-modal__x" @click="modal = ''" aria-label="Zamknij"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
    <div class="sc-modal__bd tw-space-y-4">
      <?php if ($error && $reopen): ?><div class="alert-err" role="alert"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= h($error) ?></div></div><?php endif; ?>
      <div><label for="z_name" class="f-label">Nazwa <span aria-hidden="true" style="color:#b91c1c">*</span></label>
        <input id="z_name" name="zone_name" x-model="z.zone_name" required maxlength="80" class="f-input"></div>
      <div><label for="z_desc" class="f-label">Opis</label>
        <textarea id="z_desc" name="description" x-model="z.description" rows="2" maxlength="500" class="f-input" placeholder="np. piętro, numer czytnika"></textarea></div>
      <div><label for="z_lvl" class="f-label">Poziom bezpieczeństwa</label>
        <select id="z_lvl" name="security_level" x-model.number="z.security_level" class="f-input">
          <?php foreach (SCARD_LEVELS as $l => $lbl): ?><option value="<?= $l ?>"><?= $l ?> — <?= h($lbl) ?></option><?php endforeach; ?>
        </select></div>
      <label class="f-check"><input type="checkbox" name="is_active" value="1" x-model="z.is_active"> <span>Strefa włączona <span class="sub">Wyłączona nie wpuszcza nikogo, przypisania zostają.</span></span></label>
    </div>
    <div class="sc-modal__ft"><button type="button" class="h-btn h-btn--ghost" @click="modal = ''">Anuluj</button><button type="submit" class="h-btn h-btn--primary">Zapisz</button></div>
  </form>
</div>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
