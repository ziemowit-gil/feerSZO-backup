<?php
/**
 * panel/includes/pv_action_hub.php — „Centrum akcji" panelu wolontariusza.
 *
 * Siatka „Szybkich akcji" renderowana serwerowo + wyszukiwarka tekstowa i filtr
 * „Wymaga uwagi". Interakcja w waniliowym JS ze wspólnego pv_enhance.php
 * ([data-pv-hub]) — gdy JS zawiedzie, widać pełną siatkę linków.
 *
 * Wymaga w zasięgu: $_pv_actions (array), APP_URL, h(). Reużywa klas
 * .vol-action* zdefiniowanych w panel/index.php.
 */
if (empty($_pv_actions) || !is_array($_pv_actions)) return;

// Tylko akcje z poprawnym href + etykietą
$_pv_actions = array_values(array_filter($_pv_actions, fn($a) => !empty($a['href']) && !empty($a['label'])));
if (!$_pv_actions) return;

$_pv_attention = count(array_filter($_pv_actions, fn($a) => (int)($a['badge'] ?? 0) > 0));
?>
<section class="pv-hub mb-2" aria-labelledby="pv-hub-heading" data-pv-hub<?= isset($_vol_rgb) ? ' style="--vol-rgb:'.h($_vol_rgb).'"' : '' ?>>
  <h2 id="pv-hub-heading" class="visually-hidden">Szybkie akcje</h2>

  <div class="pv-hub-bar">
    <p class="pv-hub-title" id="pv-hub-vis-title">
      <i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i>Szybkie akcje
    </p>
    <div class="pv-hub-search">
      <i class="bi bi-search" aria-hidden="true"></i>
      <label class="visually-hidden" for="pvHubSearch">Szukaj akcji</label>
      <input id="pvHubSearch" type="search" autocomplete="off" placeholder="Szukaj akcji…"
             aria-controls="pvHubGrid" data-pv-hub-search>
    </div>
    <div class="pv-hub-chips" role="group" aria-label="Filtruj akcje">
      <button type="button" class="pv-hub-chip" data-hub-filter="all" aria-pressed="true">Wszystkie</button>
      <button type="button" class="pv-hub-chip" data-hub-filter="attention" aria-pressed="false"<?= $_pv_attention === 0 ? ' disabled' : '' ?>>
        Wymaga uwagi
        <?php if ($_pv_attention): ?><span class="pv-hub-chip-num"><?= (int)$_pv_attention ?></span><?php endif; ?>
      </button>
    </div>
  </div>

  <div data-pv-hub-live aria-live="polite" class="visually-hidden"></div>

  <nav aria-labelledby="pv-hub-vis-title">
    <ul class="vol-actions" id="pvHubGrid" data-pv-hub-grid>
      <?php foreach ($_pv_actions as $a):
        $_badge = (int)($a['badge'] ?? 0);
        $_bcls  = $a['badgeClass'] ?? 'danger';
        $_aria  = $a['label'] . ($_badge ? " — {$_badge} wymaga uwagi" : '');
        $_search = trim(($a['label'] ?? '') . ' ' . ($a['sub'] ?? ''));
      ?>
      <li data-pv-hub-item data-attention="<?= $_badge > 0 ? '1' : '0' ?>" data-search="<?= h($_search) ?>">
        <a href="<?= h($a['href']) ?>" class="vol-action-btn" aria-label="<?= h($_aria) ?>">
          <?php if ($_badge): ?>
          <span class="vol-action-badge badge rounded-pill bg-<?= h($_bcls) ?><?= $_bcls === 'warning' ? ' text-dark' : '' ?>" aria-hidden="true"><?= $_badge ?></span>
          <?php endif; ?>
          <div class="vol-action-icon-wrap" aria-hidden="true"<?= !empty($a['iconBg']) ? ' style="background:'.h($a['iconBg']).'"' : '' ?>>
            <i class="bi <?= h($a['icon']) ?> vol-action-icon"<?= !empty($a['iconColor']) ? ' style="color:'.h($a['iconColor']).'"' : '' ?>></i>
          </div>
          <div>
            <?php if (isset($a['count']) && $a['count'] !== ''): ?>
            <div class="vol-action-count" aria-hidden="true"<?= !empty($a['iconColor']) ? ' style="color:'.h($a['iconColor']).'"' : '' ?>><?= h((string)$a['count']) ?></div>
            <?php endif; ?>
            <div class="vol-action-label"><?= h($a['label']) ?></div>
            <?php if (!empty($a['sub'])): ?>
            <div class="vol-action-sub"><?= h($a['sub']) ?></div>
            <?php endif; ?>
          </div>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <div class="pv-hub-empty" role="status" data-pv-hub-empty style="display:none">
    <i class="bi bi-search" aria-hidden="true"></i>
    <div>Brak akcji pasujących do „<span data-pv-hub-empty-q></span>".</div>
    <button type="button" class="pv-hub-clear" data-pv-hub-clear>Wyczyść filtry</button>
  </div>
</section>

<?php require_once __DIR__ . '/pv_enhance.php'; ?>
