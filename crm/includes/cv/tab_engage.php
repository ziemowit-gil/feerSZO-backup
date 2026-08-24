<?php
/**
 * crm/includes/cv/tab_engage.php — Zakładka „Zaangażowanie" kartoteki kontaktu (działania i wolontariat).
 *
 * Wydzielone z crm/contact/view.php: plik miał 3562 wiersze i każda zmiana
 * w jednej zakładce wymagała przewijania przez wszystkie pozostałe.
 *
 * Włączany przez include w zakresie crm/contact/view.php — korzysta z jego
 * zmiennych ($contact, $id, $crm_can_write, ...). Nie wywołuj samodzielnie.
 */
if (!isset($contact)) { http_response_code(400); exit; }
?>
      <!-- ZAKŁADKA: Zaangażowanie (działania + wolontariat) -->
      <div class="tab-pane fade" id="cv-tab-engage" role="tabpanel"
           aria-labelledby="cv-tab-engage-btn" tabindex="0">

        <!-- Działania powiązane -->
        <div class="cv-panel"><div class="cv-panel__body">
          <div class="cv-shead">
            <i class="bi bi-calendar-event cv-shead__icon" aria-hidden="true"></i>
            <h2 class="cv-shead__title">Działania</h2>
            <div class="cv-shead__aside">
              <a href="<?= APP_URL ?>/strategy/actions/index.php" class="cv-meta" style="text-decoration:none">Wszystkie</a>
            </div>
          </div>
          <?= _cv_action_links_html($contact_actions, $id, $crm_can_write, $available_actions, !empty($all_actions_raw)) ?>
        </div></div>

        <!-- Wolontariat -->
        <div class="cv-panel"><div class="cv-panel__body">
          <div class="cv-shead">
            <i class="bi bi-people-fill cv-shead__icon" aria-hidden="true"></i>
            <h2 class="cv-shead__title">Wolontariat</h2>
            <div class="cv-shead__aside">
              <a href="<?= APP_URL ?>/contracts/wolontariat/list.php" class="cv-meta" style="text-decoration:none">Wszystkie</a>
            </div>
          </div>
          <?php if (!($contact['email'] ?? '')): ?>
          <p class="cv-meta mb-0">Brak e-mail — nie można dopasować.</p>
          <?php else: ?>

          <!-- Rekrutacje -->
          <?php if ($volunteer_recruitments):
            $app_statuses = [
              'new'=>['label'=>'Nowa','color'=>'#1D4ED8'],'reviewing'=>['label'=>'W ocenie','color'=>'#B45309'],
              'interview'=>['label'=>'Rozmowa','color'=>'#7F2B8B'],'accepted'=>['label'=>'Przyjęta','color'=>'#2E844A'],
              'rejected'=>['label'=>'Odrzucona','color'=>'#DC2626'],'withdrawn'=>['label'=>'Wycofana','color'=>'#5E6470'],
            ];
            foreach ($volunteer_recruitments as $app):
              $ast = $app_statuses[$app['status']] ?? ['label'=>$app['status'],'color'=>'#5E6470'];
          ?>
          <div class="d-flex align-items-start gap-2 p-2 border rounded mb-1" style="font-size:.86rem">
            <i class="bi bi-person-check mt-1 flex-shrink-0" style="color:<?= h($ast['color']) ?>" aria-hidden="true"></i>
            <div class="flex-grow-1 overflow-hidden">
              <a href="<?= APP_URL ?>/contracts/rekrutacja/view.php?id=<?= (int)$app['offer_id'] ?>"
                 class="fw-semibold text-dark text-decoration-none d-block text-truncate">
                <?= h($app['offer_title'] ?: '—') ?>
              </a>
              <div class="cv-meta">
                <span style="color:<?= h($ast['color']) ?>;font-weight:600"><?= h($ast['label']) ?></span>
                <?php if ($app['created_at']): ?> · <?= date('d.m.Y', strtotime($app['created_at'])) ?><?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach;
          else: ?><p class="cv-meta mb-1">Brak rekrutacji.</p><?php endif; ?>

          <!-- Umowy wolontariackie -->
          <div class="border-top pt-2 mt-2">
            <?php if ($volunteer_contracts):
              $contract_statuses = [
                'projekt'=>['label'=>'Projekt','color'=>'#5E6470'],'podpisana'=>['label'=>'Podpisana','color'=>'#1D4ED8'],
                'w realizacji'=>['label'=>'W realizacji','color'=>'#2E844A'],'zakończona'=>['label'=>'Zakończona','color'=>'#032D60'],
                'rozwiązana'=>['label'=>'Rozwiązana','color'=>'#B45309'],'anulowana'=>['label'=>'Anulowana','color'=>'#DC2626'],
              ];
              foreach ($volunteer_contracts as $wol):
                $wst = $contract_statuses[$wol['status']] ?? ['label'=>$wol['status'],'color'=>'#5E6470'];
            ?>
            <div class="d-flex align-items-start gap-2 p-2 border rounded mb-1" style="font-size:.86rem">
              <i class="bi bi-file-earmark-text mt-1 flex-shrink-0" style="color:<?= h($wst['color']) ?>" aria-hidden="true"></i>
              <div class="flex-grow-1 overflow-hidden">
                <a href="<?= APP_URL ?>/contracts/wolontariat/view.php?id=<?= (int)$wol['id'] ?>"
                   class="fw-semibold text-dark text-decoration-none d-block text-truncate">
                  <?= h($wol['numer_umowy'] ?: '#' . $wol['id']) ?>
                </a>
                <div class="cv-meta">
                  <span style="color:<?= h($wst['color']) ?>;font-weight:600"><?= h($wst['label']) ?></span>
                  <?php if ($wol['data_od']): ?> · <?= date('d.m.Y', strtotime($wol['data_od'])) ?><?php endif; ?>
                </div>
              </div>
            </div>
            <?php endforeach;
            else: ?><p class="cv-meta mb-0">Brak umów wolontariackich.</p><?php endif; ?>
          </div>
          <?php endif; ?>
        </div></div>

      </div>
