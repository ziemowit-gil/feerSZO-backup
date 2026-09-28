<?php
/**
 * modules/holograms/view.php — karta pojedynczego hologramu: dane, historia
 * zdarzeń, zwrot / uszkodzenie / przywrócenie do puli.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/holograms.php';

require_role('admin', 'editor');
require_module_enabled('holograms_enabled', 'Moduł Hologramy');

$user = current_user();
$svc  = new HologramService($user);
$id   = (int)($_GET['id'] ?? 0);
$row  = $svc->find($id);
if (!$row) { http_response_code(404); flash_set('danger', 'Nie znaleziono hologramu.'); header('Location: ' . APP_URL . '/modules/holograms/index.php'); exit; }
$SELF = APP_URL . '/modules/holograms/view.php?id=' . $id;
$contract = $row['contract_type'] ? holo_contract_info((string)$row['contract_type'], (int)$row['contract_id']) : null;
// Szybki zwrot z karty „Hologramy” na umowie — wynik wraca na umowę
$backToContract = ($_GET['back'] ?? '') === 'contract' && $contract;

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $to = (string)($_POST['to_status'] ?? '');
    try {
        if ($to === 'issued') {
            $svc->issue([$row['holo_number']], (string)($_POST['assigned_to'] ?? ''), (string)($_POST['issued_by'] ?? ''), (string)($_POST['note'] ?? ''));
            // ponowne wydanie z karty naklejki — bez umowy (powiązanie ustawia się przy wydaniu z umowy)
        } else {
            $svc->changeStatus([$id], $to, (string)($_POST['note'] ?? ''));
        }
        flash_set('success', $row['holo_number'] . ': status zmieniony na „' . mb_strtolower(HOLO_STATUSES[$to]['label']) . '”.');
        header('Location: ' . ($backToContract ? $contract['url'] . '#hologramy' : $SELF)); exit;
    } catch (HologramException $e) {
        if ($backToContract) {
            flash_set('danger', $row['holo_number'] . ': ' . $e->getMessage());
            header('Location: ' . $contract['url'] . '#hologramy'); exit;
        }
        $error = $e->getMessage();
    } catch (\Throwable $e) {
        error_log('[holograms/view] ' . $e->getMessage());
        $error = 'Operacja nie powiodła się — nic nie zostało zapisane.';
    }
}

$history = $svc->history($id);
// Akcje dostępne z bieżącego statusu
$actions = [];
foreach (['issued' => 'Wydaj', 'returned' => 'Przyjmij zwrot', 'available' => 'Przywróć do puli', 'damaged' => 'Oznacz jako uszkodzony'] as $to => $lbl) {
    if (in_array($row['status'], HOLO_TRANSITIONS[$to], true)) $actions[$to] = $lbl;
}
$ACTION_LABELS = ['created' => 'Dodano do ewidencji', 'issued' => 'Wydano', 'returned' => 'Zwrot', 'damaged' => 'Uszkodzenie', 'restocked' => 'Przywrócono do puli'];

$ACTION_ICONS  = ['created' => 'bi-plus-lg', 'issued' => 'bi-box-arrow-up-right', 'returned' => 'bi-arrow-return-left', 'damaged' => 'bi-x-lg', 'restocked' => 'bi-arrow-counterclockwise'];
$ACTION_TONE   = ['created' => 'created', 'issued' => 'issued', 'returned' => 'returned', 'damaged' => 'damaged', 'restocked' => 'available'];
$ACTION_HINTS  = [
    'issued'    => 'Przypisz naklejkę osobie, dokumentowi lub sprzętowi.',
    'returned'  => 'Naklejka wraca nieużyta — przypisanie zostaje w historii.',
    'available' => 'Zwrócona naklejka wraca do puli i można ją wydać ponownie.',
    'damaged'   => 'Stan końcowy — naklejki nie będzie można już wydać.',
];
$fmtDt = fn(?string $d, string $f = 'd.m.Y H:i') => $d ? date($f, strtotime($d)) : '';

$PAGE_TITLE = 'Hologram ' . $row['holo_number'];
include dirname(__DIR__, 2) . '/includes/header.php';
include __DIR__ . '/partials/ui.php';
?>

<div class="holo tw-max-w-[1100px]">
<nav aria-label="Ścieżka" class="tw-mb-3 tw-text-sm">
  <a href="<?= APP_URL ?>/modules/holograms/index.php" class="tw-font-medium tw-no-underline" style="color: var(--holo-accent)"><i class="bi bi-arrow-left" aria-hidden="true"></i> Hologramy</a>
  <?php if ($row['batch_number']): ?>
  <span aria-hidden="true" style="color: var(--tz-muted)"> / </span>
  <a href="<?= APP_URL ?>/modules/holograms/index.php?batch=<?= h(rawurlencode($row['batch_number'])) ?>" class="tw-no-underline" style="color: var(--tz-muted)">seria <?= h($row['batch_number']) ?></a>
  <?php endif; ?>
</nav>

<header class="dash-h">
  <div>
    <h1><span class="dash-h__ico" aria-hidden="true"><i class="bi bi-patch-check"></i></span> <span class="tw-font-mono"><?= h($row['holo_number']) ?></span></h1>
    <p class="tw-flex tw-flex-wrap tw-items-center tw-gap-2"><?= holo_status_badge($row['status']) ?>
      <?php if ($row['status'] === 'issued' && $row['assigned_to']): ?><span>przypisany do <strong style="color: var(--tz-ink)"><?= h($row['assigned_to']) ?></strong></span><?php endif; ?>
    </p>
  </div>
</header>

<?= flash_html() ?>

<div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-3 tw-gap-5">
  <div class="lg:tw-col-span-2 tw-space-y-5">
    <section aria-labelledby="h-data" class="tz-card">
      <div class="tz-card__hd"><h2 id="h-data"><i class="bi bi-card-text" aria-hidden="true"></i> Dane naklejki</h2></div>
      <div class="tz-card__bd">
        <dl class="facts">
          <div><dt>Przypisano do</dt><dd class="tw-font-semibold"><?= h($row['assigned_to'] ?: '—') ?></dd></div>
          <div><dt>Umowa</dt><dd><?php if ($contract): ?><a href="<?= h($contract['url']) ?>#hologramy" class="tw-font-medium" style="color: var(--holo-accent)"><?= h($contract['number'] ?: '#' . $contract['id']) ?></a> <span class="tw-text-xs" style="color: var(--tz-muted)"><?= h($contract['label']) ?><?= $contract['person'] ? ' · ' . h($contract['person']) : '' ?></span><?php else: ?>—<?php endif; ?></dd></div>
          <div><dt>Seria dostawy</dt><dd><?= h($row['batch_number'] ?: '—') ?></dd></div>
          <div><dt>Data wydania</dt><dd><?= h($fmtDt($row['issued_at']) ?: '—') ?></dd></div>
          <div><dt>Wydał(a)</dt><dd><?= h($row['issued_by'] ?: '—') ?></dd></div>
          <div><dt>W ewidencji od</dt><dd><?= h($fmtDt($row['created_at'])) ?></dd></div>
          <div><dt>Liczba zdarzeń</dt><dd><?= count($history) ?></dd></div>
          <div class="sm:tw-col-span-2"><dt>Uwagi</dt><dd><?= $row['notes'] ? nl2br(h($row['notes'])) : '—' ?></dd></div>
        </dl>
      </div>
    </section>

    <section aria-labelledby="h-hist" class="tz-card">
      <div class="tz-card__hd"><h2 id="h-hist"><i class="bi bi-clock-history" aria-hidden="true"></i> Historia</h2></div>
      <div class="tz-card__bd">
        <?php if (!$history): ?>
        <p class="tw-m-0" style="color: var(--tz-muted)">Brak zdarzeń.</p>
        <?php else: ?>
        <ol class="tl">
          <?php foreach ($history as $e): $a = $e['action']; ?>
          <li>
            <span class="tl__ico is-<?= h($ACTION_TONE[$a] ?? 'x') ?>" aria-hidden="true"><i class="bi <?= h($ACTION_ICONS[$a] ?? 'bi-dot') ?>"></i></span>
            <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-2">
              <strong class="tw-text-sm" style="color: var(--tz-ink)"><?= h($ACTION_LABELS[$a] ?? $a) ?></strong>
              <?php if ($e['from_status'] && $e['to_status']): ?>
              <span class="tw-text-xs" style="color: var(--tz-muted)"><?= h(HOLO_STATUSES[$e['from_status']]['label'] ?? $e['from_status']) ?> → <?= h(HOLO_STATUSES[$e['to_status']]['label'] ?? $e['to_status']) ?></span>
              <?php endif; ?>
            </div>
            <?php if ($e['details']): ?><div class="tw-text-sm tw-mt-0.5" style="color: var(--tz-ink)"><?= h($e['details']) ?></div><?php endif; ?>
            <div class="tw-text-xs tw-mt-0.5" style="color: var(--tz-muted)"><time datetime="<?= h(date('c', strtotime($e['created_at']))) ?>"><?= h($fmtDt($e['created_at'])) ?></time><?= $e['user_name'] ? ' · ' . h($e['user_name']) : '' ?></div>
          </li>
          <?php endforeach; ?>
        </ol>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <section aria-labelledby="h-act" class="tz-card tw-self-start"
           x-data="{ to: <?= h(json_encode((string)($_POST['to_status'] ?? array_key_first($actions) ?? ''))) ?> }">
    <div class="tz-card__hd"><h2 id="h-act"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Zmień status</h2></div>
    <div class="tz-card__bd">
      <?php if ($error): ?>
      <div role="alert" class="alert-err"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= h($error) ?></div></div>
      <?php endif; ?>
      <?php if (!$actions): ?>
      <div class="empty !tw-py-6"><i class="bi bi-lock" aria-hidden="true"></i><p class="tw-m-0 tw-text-sm">Naklejka uszkodzona — to stan końcowy, bez dalszych zmian.</p></div>
      <?php else: ?>
      <form method="post" action="<?= h($SELF) ?>" class="tw-space-y-4">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <fieldset class="tw-m-0 tw-p-0 tw-border-0 tw-space-y-2">
          <legend class="f-label">Operacja</legend>
          <?php foreach ($actions as $to => $lbl): ?>
          <label class="tw-flex tw-cursor-pointer tw-gap-3 tw-rounded-xl tw-border tw-p-3 tw-transition-colors"
                 :style="to === '<?= h($to) ?>' ? 'border-color: var(--holo-accent); background: #eff6ff' : 'border-color: var(--tz-line)'">
            <input type="radio" name="to_status" value="<?= h($to) ?>" x-model="to" class="h-check tw-mt-0.5" aria-describedby="hint_<?= h($to) ?>">
            <span>
              <span class="tw-block tw-text-sm tw-font-semibold" style="color: var(--tz-ink)"><?= h($lbl) ?></span>
              <span id="hint_<?= h($to) ?>" class="tw-block tw-text-xs" style="color: var(--tz-muted)"><?= h($ACTION_HINTS[$to]) ?></span>
            </span>
          </label>
          <?php endforeach; ?>
        </fieldset>
        <template x-if="to === 'issued'">
          <div class="tw-space-y-4">
            <div>
              <label for="v_assigned" class="f-label">Przypisz do <span class="f-req" aria-hidden="true">*</span></label>
              <input id="v_assigned" name="assigned_to" required maxlength="255" class="f-input" value="<?= h($_POST['assigned_to'] ?? '') ?>" placeholder="osoba, dokument lub sprzęt">
            </div>
            <div>
              <label for="v_by" class="f-label">Wydał(a)</label>
              <input id="v_by" name="issued_by" maxlength="255" class="f-input" value="<?= h($_POST['issued_by'] ?? ($user['name'] ?? '')) ?>">
            </div>
          </div>
        </template>
        <div>
          <label for="v_note" class="f-label">Opis <span x-show="to === 'damaged'">(wymagany)</span></label>
          <textarea id="v_note" name="note" rows="2" maxlength="2000" class="f-input" :required="to === 'damaged'"><?= h($_POST['note'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="h-btn h-btn--primary tw-w-full"><i class="bi bi-check-lg" aria-hidden="true"></i> Zapisz</button>
      </form>
      <?php endif; ?>
    </div>
  </section>
</div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
