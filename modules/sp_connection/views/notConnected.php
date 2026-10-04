<?php
/**
 * modules/sp_connection/views/notConnected.php — panel „funkcja zablokowana: SharePoint nie jest połączony”.
 *
 * Zmienne od SpConnection::renderBlocked(): $feature, $detail, $why, $isAdmin, $settings (adres kreatora), $m365Url, $noM365.
 * Tylko klasy Bootstrap + ikony bi — panel działa i w układzie administracji, i w module Zadań (Bootstrap + Tailwind).
 * Dostępność: sekcja z nagłówkiem, ikona ozdobna (aria-hidden), powód i działanie podane tekstem.
 */
?>
<section class="card border-warning shadow-sm my-3" data-sp-blocked="1" aria-labelledby="spc-blocked-h">
  <div class="card-body text-center py-4 px-3">
    <i class="bi bi-cloud-slash text-warning-emphasis d-block mb-2" style="font-size:2.4rem" aria-hidden="true"></i>
    <h2 class="h5 fw-bold mb-2" id="spc-blocked-h"><?= h($feature) ?> — zablokowane: SharePoint nie jest połączony</h2>
    <p class="mb-2"><?= h($why) ?></p>
    <?php if ($detail !== ''): ?><p class="small text-body-secondary mb-3"><?= h($detail) ?></p><?php endif; ?>
    <?php if ($isAdmin): ?>
      <a class="btn btn-primary btn-sm" href="<?= h($settings) ?>"><i class="bi bi-cloud-upload me-1" aria-hidden="true"></i>Połącz SharePoint</a>
      <?php if ($noM365): ?><a class="btn btn-outline-secondary btn-sm ms-1" href="<?= h($m365Url) ?>"><i class="bi bi-microsoft me-1" aria-hidden="true"></i>Ustawienia Microsoft 365</a><?php endif; ?>
    <?php else: ?>
      <p class="small mb-0">Poproś administratora o połączenie SharePoint (Administracja → SharePoint).</p>
    <?php endif; ?>
  </div>
</section>
