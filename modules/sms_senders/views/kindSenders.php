<?php
/**
 * modules/sms_senders/views/kindSenders.php — karta „Nadawca wg rodzaju SMS” w Ustawieniach SMS (admin/sms_settings.php).
 *
 * Zmienne od strony: $ks_groups (SmsSenders::groups()), $ks_stored (SmsSenders::all()), $ks_providers (klucze dostawców łańcucha:
 * główny i zapasowy), $ks_labels (sms_providers()), $ks_suggest (podpowiedzi nazw nadawcy do <datalist>).
 * Osobny formularz (_action=save_kind_senders) — nie miesza się z konfiguracją dostawców. Dostępność: tabela z <caption> i nagłówkami
 * kolumn, każde pole ma etykietę, kolumna „Obowiązuje teraz” podaje wynik tekstem (nie samym kolorem).
 */
$ks_count = count($ks_stored);
?>
<div class="card shadow-sm mb-3" id="kind-senders">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <i class="bi bi-person-lines-fill" aria-hidden="true"></i> Nadawca wg rodzaju SMS
  <span class="badge ms-auto fw-normal <?= $ks_count ? 'bg-info-subtle text-info-emphasis border border-info-subtle' : 'bg-secondary-subtle text-secondary-emphasis border' ?>">
    <?= $ks_count ? 'własny nadawca: ' . (int)$ks_count : 'wszystkie z domyślnym nadawcą' ?>
  </span>
</div>
<div class="card-body">
  <p class="small text-muted mb-2" id="ks-help">
    Każdy SMS z systemu ma swój rodzaj (kod logowania, 2FA, powiadomienie o zajęciach…). Tu możesz dla wybranego rodzaju ustawić
    <strong>nazwę nadawcy</strong> albo wpisać <strong><code>ECO</code></strong> (bez własnej nazwy nadawcy). Puste pole = domyślny nadawca dostawcy.
    <strong>SerwerSMS</strong>: nazwa → kanał normalny (FULL) z tą nazwą, <code>ECO</code> → kanał ECO. <strong>smsapi.pl</strong>: nazwa trafia
    w pole „from”, <code>ECO</code> oznacza domyślnego nadawcę konta. <strong>Brama HTTP</strong>: nazwa trafia do <code>{sender}</code>.
    <strong>Twilio</strong> zawsze wysyła z numeru nadawcy z konfiguracji. Nazwa musi być zarejestrowana u dostawcy
    (litery, cyfry, spacja, kropka, myślnik, podkreślnik; maks. <?= (int)SmsSenders::NAME_MAX ?> znaków).
  </p>

  <form method="post" id="kindSendersForm" aria-describedby="ks-help">
    <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="save_kind_senders">
    <datalist id="sms-sender-suggest">
      <option value="ECO">
      <?php foreach ($ks_suggest as $name): ?><option value="<?= h($name) ?>"><?php endforeach; ?>
    </datalist>

    <?php foreach ($ks_groups as $group => $kinds): ?>
    <h3 class="h6 fw-semibold mt-3 mb-2"><?= h($group) ?></h3>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Nadawca SMS dla rodzajów: <?= h($group) ?></caption>
        <thead class="table-light">
          <tr>
            <th scope="col">Rodzaj SMS</th>
            <th scope="col" style="width:11rem">Nadawca</th>
            <th scope="col">Obowiązuje teraz</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($kinds as $kind => $info): $fid = 'ks_' . $kind; ?>
          <tr>
            <td>
              <label for="<?= h($fid) ?>" class="fw-semibold small mb-0"><?= h($info['label']) ?></label>
              <?php if ($info['hint'] !== ''): ?><div class="text-muted small"><?= h($info['hint']) ?></div><?php endif; ?>
            </td>
            <td>
              <input type="text" id="<?= h($fid) ?>" name="kind_sender[<?= h($kind) ?>]" value="<?= h($ks_stored[$kind] ?? '') ?>"
                     class="form-control form-control-sm" maxlength="<?= (int)SmsSenders::NAME_MAX ?>" list="sms-sender-suggest"
                     placeholder="(domyślny)" autocomplete="off">
            </td>
            <td class="small">
              <?php foreach ($ks_providers as $prov): $eff = SmsSenders::effective($prov, $kind); ?>
                <div>
                  <?php if (count($ks_providers) > 1): ?><span class="text-muted"><?= h($ks_labels[$prov] ?? $prov) ?>:</span><?php endif; ?>
                  <?= h($eff['label']) ?>
                  <?php if ($eff['own']): ?><span class="badge bg-info-subtle text-info-emphasis border border-info-subtle fw-normal">ustawiony</span><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>

    <div class="d-flex gap-2 mt-3">
      <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Zapisz nadawców wg rodzaju</button>
    </div>
  </form>
</div>
</div>
