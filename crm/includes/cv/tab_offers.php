<?php
/**
 * crm/includes/cv/tab_offers.php — Zakładka „Oferty" kartoteki kontaktu (działalność odpłatna).
 *
 * Wydzielone z crm/contact/view.php: plik miał 3562 wiersze i każda zmiana
 * w jednej zakładce wymagała przewijania przez wszystkie pozostałe.
 *
 * Włączany przez include w zakresie crm/contact/view.php — korzysta z jego
 * zmiennych ($contact, $id, $crm_can_write, ...). Nie wywołuj samodzielnie.
 */
if (!isset($contact)) { http_response_code(400); exit; }
?>
      <!-- ZAKŁADKA: Oferty (działalność odpłatna) -->
      <div class="tab-pane fade" id="cv-tab-offers" role="tabpanel"
           aria-labelledby="cv-tab-offers-btn" tabindex="0">
        <div class="cv-panel"><div class="cv-panel__body">
          <div class="cv-shead">
            <i class="bi bi-file-earmark-ruled cv-shead__icon" style="color:#0176D3" aria-hidden="true"></i>
            <h2 class="cv-shead__title">Oferty</h2>
            <div class="cv-shead__aside">
              <span class="cv-count"><?= count($contact_offers) ?></span>
              <?php if ($offers_can_write): ?>
              <a href="<?= APP_URL ?>/crm/offers/form.php?contact_id=<?= (int)$id ?>"
                 class="btn btn-crm-outline btn-sm py-0 px-2">
                <i class="bi bi-plus me-1" aria-hidden="true"></i>Nowa oferta
              </a>
              <?php endif; ?>
            </div>
          </div>

          <div class="row g-2 text-center mb-3">
            <div class="col-3"><div class="fw-bold" style="font-size:1.05rem"><?= (int)$offers_summary['cnt'] ?></div>
              <div class="text-muted" style="font-size:.7rem">ofert łącznie</div></div>
            <div class="col-3"><div class="fw-bold text-success" style="font-size:1.05rem"><?= (int)$offers_summary['won'] ?></div>
              <div class="text-muted" style="font-size:.7rem">wygrane</div></div>
            <div class="col-3"><div class="fw-bold text-danger" style="font-size:1.05rem"><?= (int)$offers_summary['lost'] ?></div>
              <div class="text-muted" style="font-size:.7rem">odrzucone</div></div>
            <div class="col-3"><div class="fw-bold" style="font-size:1.05rem"><?= (int)$offers_summary['open'] ?></div>
              <div class="text-muted" style="font-size:.7rem">w toku</div></div>
          </div>
          <?php if ((float)$offers_summary['won_value'] > 0): ?>
          <div class="mb-2" style="font-size:.85rem">
            Wartość wygranych ofert: <strong><?= h(crm_offer_money((float)$offers_summary['won_value'])) ?></strong>
          </div>
          <?php endif; ?>

          <?php if ($contact_offers): ?>
          <div class="d-flex flex-column">
            <?php foreach ($contact_offers as $co):
              $co_needs = crm_offer_requires_confirmation($co);
              $co_conf  = (int)($co['confirmation_id'] ?? 0); ?>
            <a href="<?= APP_URL ?>/crm/offers/view.php?id=<?= (int)$co['id'] ?>"
               class="d-flex align-items-center gap-2 py-2 border-bottom text-decoration-none"
               style="color:#181818;font-size:.86rem">
              <span style="font-family:monospace;font-size:.74rem;color:#1D4ED8;white-space:nowrap"><?= h($co['offer_number']) ?></span>
              <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600"><?= h($co['title']) ?></span>
              <?php if ($co_needs && !$co_conf): ?>
              <span title="Brak potwierdzenia klienta (osoba fizyczna)" style="color:#B45309">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
              </span>
              <?php endif; ?>
              <span style="white-space:nowrap;font-weight:600"><?= h(crm_offer_money((float)$co['total_gross'], (string)$co['currency'])) ?></span>
              <?= crm_offer_status_pill((string)$co['status'], true) ?>
            </a>
            <?php endforeach; ?>
          </div>
          <a href="<?= APP_URL ?>/crm/offers/index.php?contact_id=<?= (int)$id ?>"
             class="btn btn-crm-outline btn-sm w-100 mt-3">Rejestr ofert tego klienta</a>
          <?php else: ?>
          <div class="cv-empty">
            <i class="bi bi-file-earmark-ruled" aria-hidden="true"></i>
            Brak ofert
            <?php if ($offers_can_write): ?>
            <div class="mt-1"><a href="<?= APP_URL ?>/crm/offers/form.php?contact_id=<?= (int)$id ?>">Przygotuj pierwszą →</a></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div></div>
      </div>
