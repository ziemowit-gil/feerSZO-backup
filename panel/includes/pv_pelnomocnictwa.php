<?php
/**
 * panel/includes/pv_pelnomocnictwa.php — widżet „Moje pełnomocnictwa".
 * Renderuje kartę tylko gdy zalogowany użytkownik jest pełnomocnikiem
 * w co najmniej jednym ważnym pełnomocnictwie (po powiązaniu konta lub nazwisku).
 *
 * Zmienna wejściowa: $user (z panel/index.php).
 */
if (!isset($user) || empty($user['id'])) return;
if (!function_exists('module_enabled') || !module_enabled('pelnomocnictwa_enabled')) return;

require_once dirname(__DIR__, 2) . '/includes/pelnomocnictwa.php';

$_peln_moje = [];
try { $_peln_moje = pelnomocnictwa_for_user((int)$user['id'], (string)($user['name'] ?? ''), true); } catch (\Throwable $e) {}
if (!$_peln_moje) return;
?>
<div class="card shadow-sm mb-3">
  <div class="card-header d-flex align-items-center justify-content-between py-2">
    <span class="fw-semibold" style="font-size:.9rem"><i class="bi bi-person-badge me-1 text-primary"></i>Moje pełnomocnictwa</span>
    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary" style="font-size:.72rem"><?= count($_peln_moje) ?> ważne</span>
  </div>
  <ul class="list-group list-group-flush">
    <?php foreach ($_peln_moje as $_p): ?>
    <li class="list-group-item py-2">
      <div class="d-flex justify-content-between align-items-start gap-2">
        <div>
          <div class="fw-semibold" style="font-size:.85rem"><?= h($_p['numer']) ?>
            <span class="text-muted fw-normal">· od <?= h($_p['mocodawca']) ?></span>
          </div>
          <?php if (($_p['rodzaj'] ?? 'ogolne') === 'korespondencja'): ?>
          <div class="text-muted" style="font-size:.78rem"><i class="bi bi-envelope me-1"></i><?= h(mb_substr(pelnomocnictwo_kor_opis($_p), 0, 140)) ?></div>
          <?php else: $_z = pelnomocnictwo_zakres_items($_p); if ($_z): ?>
          <div class="text-muted" style="font-size:.78rem"><?= h(mb_substr(implode('; ', $_z), 0, 140)) ?></div>
          <?php endif; endif; ?>
          <div class="text-muted" style="font-size:.72rem">
            <i class="bi bi-calendar3 me-1"></i>ważne do <?= $_p['data_waznosci'] ? h(date_pl($_p['data_waznosci'])) : 'bezterminowo' ?>
          </div>
        </div>
        <?php if (!empty($_p['dokument_plik'])): ?>
        <a href="<?= APP_URL ?>/pelnomocnictwa/dokument_download.php?id=<?= (int)$_p['id'] ?>"
           class="btn btn-outline-secondary btn-sm" title="Pobierz dokument"><i class="bi bi-download"></i></a>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</div>
