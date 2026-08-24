<?php
/**
 * crm/includes/cv/tab_cases.php — Zakładka „Sprawy" kartoteki kontaktu.
 *
 * Wydzielone z crm/contact/view.php: plik miał 3562 wiersze i każda zmiana
 * w jednej zakładce wymagała przewijania przez wszystkie pozostałe.
 *
 * Włączany przez include w zakresie crm/contact/view.php — korzysta z jego
 * zmiennych ($contact, $id, $crm_can_write, ...). Nie wywołuj samodzielnie.
 */
if (!isset($contact)) { http_response_code(400); exit; }
?>
      <!-- ZAKŁADKA: Sprawy -->
      <div class="tab-pane fade" id="cv-tab-cases" role="tabpanel"
           aria-labelledby="cv-tab-cases-btn" tabindex="0">
        <div class="cv-panel"><div class="cv-panel__body">
          <div class="cv-shead">
            <i class="bi bi-briefcase cv-shead__icon" style="color:#1D4ED8" aria-hidden="true"></i>
            <h2 class="cv-shead__title">Sprawy</h2>
            <div class="cv-shead__aside">
              <span class="cv-count"><?= count($contact_cases) ?></span>
              <?php if ($crm_can_write): ?>
              <a href="<?= APP_URL ?>/crm/cases/add.php?contact_id=<?= (int)$id ?>"
                 class="btn btn-crm-outline btn-sm py-0 px-2">
                <i class="bi bi-plus me-1" aria-hidden="true"></i>Nowa
              </a>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($contact_cases): ?>
          <div class="d-flex flex-column">
            <?php foreach ($contact_cases as $cs):
              $csc = $case_status_cfg[$cs['status']] ?? $case_status_cfg['open'];
            ?>
            <a href="<?= APP_URL ?>/crm/cases/view.php?id=<?= (int)$cs['id'] ?>"
               class="d-flex align-items-center gap-2 py-2 border-bottom text-decoration-none"
               style="color:#181818;font-size:.88rem">
              <span style="width:8px;height:8px;border-radius:50%;background:<?= $csc['color'] ?>;flex-shrink:0" aria-hidden="true"></span>
              <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600">
                <?= h($cs['title']) ?>
              </span>
              <span style="display:inline-flex;align-items:center;padding:.12rem .55rem;border-radius:2rem;font-size:.72rem;font-weight:600;background:<?= $csc['bg'] ?>;color:<?= $csc['color'] ?>;white-space:nowrap">
                <?= $csc['label'] ?>
              </span>
            </a>
            <?php endforeach; ?>
          </div>
          <a href="<?= APP_URL ?>/crm/cases/index.php?contact_id=<?= (int)$id ?>"
             class="btn btn-crm-outline btn-sm w-100 mt-3">
            Wszystkie sprawy tego kontaktu
          </a>
          <?php else: ?>
          <div class="cv-empty">
            <i class="bi bi-briefcase" aria-hidden="true"></i>
            Brak spraw
            <?php if ($crm_can_write): ?>
            <div class="mt-1"><a href="<?= APP_URL ?>/crm/cases/add.php?contact_id=<?= (int)$id ?>">Utwórz pierwszą →</a></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div></div>
      </div>
