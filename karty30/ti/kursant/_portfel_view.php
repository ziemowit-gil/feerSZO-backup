<?php
/**
 * Partial: Portfel kursanta — dostępne środki, doładowanie online (Stripe/PayU)
 * lub przelewem, historia operacji. Bootstrap 5.3 + WCAG.
 * Wymaga: $pw_client_id (int), sesja kursanta ($student, student_token()).
 *
 * Model: portfel to księga wpłat k30_ti_payments (includes/ti_payments.php).
 * Doładowanie = wpłata OGÓLNA (course_id=0) — alokacja FIFO automatycznie
 * pobiera z niej opłaty za kolejne zajęcia. „Dostępne środki” = nadpłata
 * (credit), „Do zapłaty” = niedopłata (debt).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_payments.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/stripe.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/payu.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/p24.php';

$pw_cid = (int)$pw_client_id;
// Partial działa w dwóch panelach: kursanta (domyślne adresy) i rodzica (nadpisywane)
$pw_form_action     = $pw_form_action     ?? 'index.php?tab=portfel';
$pw_rozliczenia_url = $pw_rozliczenia_url ?? '?tab=rozliczenia';

$pw_flash = $_SESSION['wallet_flash'] ?? null;
unset($_SESSION['wallet_flash']);

// Płatności online rozpoczęte z portfela; oczekujące zweryfikuj aktywnie w API —
// powrót z bramki zwykle wyprzedza webhook, a kursant chce od razu widzieć środki.
stripe_migrate(); payu_migrate(); p24_migrate();
$pw_stripe = db_all("SELECT * FROM stripe_payments WHERE source_type='k30_ti_wallet' AND source_id=? ORDER BY id DESC LIMIT 10", [$pw_cid]);
foreach ($pw_stripe as &$_sp) {
    if ($_sp['status'] === 'pending') $_sp['status'] = stripe_reconcile_payment((int)$_sp['id']) ?: 'pending';
}
unset($_sp);
$pw_payu = db_all("SELECT * FROM payu_payments WHERE source_type='k30_ti_wallet' AND source_id=? ORDER BY id DESC LIMIT 10", [$pw_cid]);
foreach ($pw_payu as &$_pp) {
    if ($_pp['status'] === 'pending') $_pp['status'] = payu_reconcile_payment((int)$_pp['id']) ?: 'pending';
}
unset($_pp);
$pw_p24 = db_all("SELECT * FROM p24_payments WHERE source_type='k30_ti_wallet' AND source_id=? ORDER BY id DESC LIMIT 10", [$pw_cid]);
foreach ($pw_p24 as &$_p4) {
    if ($_p4['status'] === 'pending') $_p4['status'] = p24_reconcile_payment((int)$_p4['id']) ?: 'pending';
}
unset($_p4);

// Powrót z bramki płatności → komunikat wg faktycznego (zweryfikowanego) statusu
if (isset($_GET['wpay']) && !$pw_flash) {
    $_last = match ($_GET['wpay']) {
        'payu'  => $pw_payu[0] ?? null,
        'p24'   => $pw_p24[0] ?? null,
        default => $pw_stripe[0] ?? null,
    };
    if ($_last && $_last['status'] === 'paid') {
        $pw_flash = ['ok', 'Wpłata została zaksięgowana — środki są już w portfelu.'];
    } elseif ($_last && $_last['status'] === 'pending') {
        $pw_flash = ['info', 'Płatność jest przetwarzana przez operatora. Środki pojawią się w portfelu zaraz po potwierdzeniu — zwykle w ciągu kilku minut.'];
    }
}
if (isset($_GET['wcancel']) && !$pw_flash) {
    $pw_flash = ['info', 'Płatność została przerwana. Możesz spróbować ponownie w dowolnym momencie.'];
}

$pw_bal    = ti_client_balance($pw_cid);
$pw_wreqs  = ti_wallet_requests_for_client($pw_cid);
$pw_stripe_on = stripe_enabled();
$pw_payu_on   = payu_enabled();
$pw_p24_on    = p24_enabled();
$pw_online    = $pw_stripe_on || $pw_payu_on || $pw_p24_on;
$pw_pay    = k30_ti_client_payment($pw_cid);
$pw_months = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
              7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$pw_mlabels = ['transfer'=>'przelew','cash'=>'gotówka','stripe'=>'Stripe','payu'=>'PayU','p24'=>'Przelewy24','other'=>'inna'];

// Oczekujące doładowania online (do dokończenia)
$pw_pending = [];
foreach ($pw_stripe as $_p) {
    if ($_p['status'] === 'pending' && $_p['checkout_url'] !== '') {
        $pw_pending[] = ['amount' => (int)$_p['amount_grosze'] / 100, 'url' => (string)$_p['checkout_url'],
                         'label' => 'Stripe', 'created' => (string)$_p['created_at']];
    }
}
foreach ($pw_payu as $_p) {
    if ($_p['status'] === 'pending' && $_p['redirect_uri'] !== '') {
        $pw_pending[] = ['amount' => (int)$_p['amount_grosze'] / 100, 'url' => (string)$_p['redirect_uri'],
                         'label' => 'PayU', 'created' => (string)$_p['created_at']];
    }
}
foreach ($pw_p24 as $_p) {
    if ($_p['status'] === 'pending' && $_p['redirect_uri'] !== '') {
        $pw_pending[] = ['amount' => (int)$_p['amount_grosze'] / 100, 'url' => (string)$_p['redirect_uri'],
                         'label' => 'Przelewy24', 'created' => (string)$_p['created_at']];
    }
}

// ── Historia operacji: wpłaty (+) i należności za zajęcia (−) w jednej osi czasu ──
$pw_ops = [];
foreach (ti_payments_for_client($pw_cid) as $p) {
    $lbl = 'Wpłata do portfela';
    if ((int)($p['course_id'] ?? 0) > 0) {
        $c   = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [(int)$p['course_id']]);
        $lbl = 'Wpłata na grupę: ' . (string)($c['name'] ?? ('#' . (int)$p['course_id']));
    }
    $pw_ops[] = [
        'date'   => (string)($p['paid_at'] ?: substr((string)$p['created_at'], 0, 10)),
        'kind'   => 'in',
        'amount' => (float)$p['amount'],
        'label'  => $lbl,
        'note'   => trim(($pw_mlabels[$p['method']] ?? (string)$p['method'])
                    . ((string)$p['note'] !== '' ? ' · ' . (string)$p['note'] : '')),
        'status' => '',
    ];
}
foreach (k30_ti_client_billing($pw_cid) as $b) {
    if (!in_array($b['status'], ['issued', 'paid'], true)) continue;
    $due = (float)$b['amount'] + (float)($b['adjustment'] ?? 0);
    if (abs($due) < 0.005) continue;
    $mn = $pw_months[(int)$b['month']] ?? (string)$b['month'];
    $pw_ops[] = [
        'date'   => !empty($b['issued_at']) ? substr((string)$b['issued_at'], 0, 10)
                                            : sprintf('%04d-%02d-01', (int)$b['year'], (int)$b['month']),
        'kind'   => 'out',
        'amount' => $due,
        'label'  => 'Zajęcia — ' . ($b['course_name'] !== '' ? $b['course_name'] : 'rozliczenie łączne')
                    . ' (' . $mn . ' ' . (int)$b['year'] . ')',
        'note'   => (string)($b['adjustment_note'] ?? ''),
        'status' => (string)$b['status'],
        'covered'=> (float)($b['paid_amount'] ?? 0),
    ];
}
foreach ($pw_wreqs as $wr) {
    if ($wr['status'] !== 'pending') continue;   // zatwierdzone widać już jako wpłata; odrzucone nieistotne w historii
    $pw_ops[] = [
        'date'   => substr((string)$wr['created_at'], 0, 10),
        'kind'   => 'declared',
        'amount' => (float)$wr['amount'],
        'label'  => 'Zgłoszenie przelewu tradycyjnego',
        'note'   => (string)$wr['note'],
        'status' => '',
    ];
}
usort($pw_ops, fn($a, $b) => strcmp($b['date'], $a['date']));
?>
<h2 class="h5 fw-bold d-flex align-items-center gap-2 mb-3"><i class="bi bi-wallet2 text-primary" aria-hidden="true"></i>Portfel</h2>

<?php if ($pw_flash): [$pw_fk, $pw_fm] = $pw_flash; ?>
<div class="alert alert-<?= $pw_fk === 'ok' ? 'success' : ($pw_fk === 'err' ? 'danger' : 'info') ?> d-flex align-items-center gap-2" role="<?= $pw_fk === 'err' ? 'alert' : 'status' ?>">
  <i class="bi bi-<?= $pw_fk === 'ok' ? 'check-circle-fill' : ($pw_fk === 'err' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?>" aria-hidden="true"></i>
  <span><?= h($pw_fm) ?></span>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-12 col-md-4">
    <div class="card h-100 border-success-subtle"><div class="card-body">
      <div class="fs-3 fw-bold lh-1 text-success"><?= number_format($pw_bal['credit'], 2, ',', ' ') ?> zł</div>
      <div class="text-body-secondary small mt-1"><i class="bi bi-piggy-bank me-1" aria-hidden="true"></i>Dostępne środki</div>
    </div></div>
  </div>
  <div class="col-6 col-md-4">
    <div class="card h-100"><div class="card-body">
      <?php if ($pw_bal['debt'] > 0.005): ?>
      <div class="fs-3 fw-bold lh-1 text-danger"><?= number_format($pw_bal['debt'], 2, ',', ' ') ?> zł</div>
      <div class="text-body-secondary small mt-1">Do zapłaty (niepokryte zajęcia)</div>
      <?php else: ?>
      <div class="fs-3 fw-bold lh-1">0,00 zł</div>
      <div class="text-body-secondary small mt-1">Do zapłaty — wszystko pokryte</div>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-6 col-md-4">
    <div class="card h-100"><div class="card-body">
      <div class="fs-3 fw-bold lh-1"><?= number_format($pw_bal['payments'], 2, ',', ' ') ?> zł</div>
      <div class="text-body-secondary small mt-1">Suma wszystkich wpłat</div>
    </div></div>
  </div>
</div>

<div class="alert alert-light border small d-flex gap-2" role="note">
  <i class="bi bi-info-circle text-primary flex-shrink-0" aria-hidden="true"></i>
  <span>Portfel działa jak przedpłata: wpłacasz dowolną kwotę, a opłaty za kolejne zajęcia są
  <strong>pobierane z niego automatycznie</strong> (od najstarszej należności). Jeśli na koncie jest
  zaległość, doładowanie najpierw ją pokryje. Szczegóły należności znajdziesz w zakładce
  <a href="<?= h($pw_rozliczenia_url) ?>">Rozliczenia</a>.</span>
</div>

<?php if ($pw_pending): ?>
<div class="card mb-4 border-warning-subtle">
  <div class="card-header bg-warning bg-opacity-10 fw-semibold">
    <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Nieukończone doładowania
  </div>
  <ul class="list-group list-group-flush">
    <?php foreach ($pw_pending as $pp): ?>
    <li class="list-group-item d-flex flex-wrap align-items-center justify-content-between gap-2">
      <span><strong><?= number_format($pp['amount'], 2, ',', ' ') ?> zł</strong>
        <span class="text-body-secondary small">· <?= h($pp['label']) ?> · rozpoczęto <?= h(substr($pp['created'], 0, 16)) ?></span></span>
      <a href="<?= h($pp['url']) ?>" class="btn btn-sm btn-outline-primary" rel="noopener">
        <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Dokończ płatność
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <div class="card-footer bg-white small text-body-secondary">
    Jeśli płatność została już wykonana, środki pojawią się po potwierdzeniu przez operatora — odśwież stronę za chwilę.
  </div>
</div>
<?php endif; ?>

<div class="row g-4 mb-4">
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-header fw-semibold bg-white">
        <i class="bi bi-plus-circle text-primary me-1" aria-hidden="true"></i>Doładuj portfel online
      </div>
      <div class="card-body">
        <?php if ($pw_online): ?>
        <form method="post" action="<?= h($pw_form_action) ?>" class="vstack gap-3">
          <input type="hidden" name="_op" value="wallet_topup">
          <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
          <div>
            <label for="pwAmount" class="form-label">Kwota doładowania</label>
            <div class="input-group" style="max-width:260px">
              <input type="number" class="form-control" id="pwAmount" name="amount"
                     min="1" max="20000" step="0.01" inputmode="decimal" required
                     <?= $pw_bal['debt'] > 0.005 ? 'value="' . h(number_format($pw_bal['debt'], 2, '.', '')) . '"' : '' ?>>
              <span class="input-group-text">zł</span>
            </div>
            <div class="mt-2 d-flex flex-wrap gap-2" aria-label="Podpowiedzi kwot">
              <?php foreach ([50, 100, 200, 500] as $q): ?>
              <button type="button" class="btn btn-sm btn-outline-secondary pw-quick" data-amount="<?= $q ?>"><?= $q ?> zł</button>
              <?php endforeach; ?>
              <?php if ($pw_bal['debt'] > 0.005): ?>
              <button type="button" class="btn btn-sm btn-outline-danger pw-quick" data-amount="<?= h(number_format($pw_bal['debt'], 2, '.', '')) ?>">
                Spłać zaległość (<?= number_format($pw_bal['debt'], 2, ',', ' ') ?> zł)
              </button>
              <?php endif; ?>
            </div>
          </div>
          <fieldset>
            <legend class="form-label fs-6 mb-1">Metoda płatności</legend>
            <?php if ($pw_stripe_on): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="provider" id="pwProvStripe" value="stripe" checked>
              <label class="form-check-label" for="pwProvStripe">Karta / BLIK / Przelewy — Stripe</label>
            </div>
            <?php endif; ?>
            <?php if ($pw_payu_on): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="provider" id="pwProvPayu" value="payu" <?= $pw_stripe_on ? '' : 'checked' ?>>
              <label class="form-check-label" for="pwProvPayu">BLIK / szybki przelew — PayU</label>
            </div>
            <?php endif; ?>
            <?php if ($pw_p24_on): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="provider" id="pwProvP24" value="p24" <?= ($pw_stripe_on || $pw_payu_on) ? '' : 'checked' ?>>
              <label class="form-check-label" for="pwProvP24">BLIK / szybki przelew — Przelewy24</label>
            </div>
            <?php endif; ?>
          </fieldset>
          <div>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-lock me-1" aria-hidden="true"></i>Przejdź do płatności
            </button>
            <div class="form-text mt-2">Po opłaceniu wrócisz do panelu, a środki trafią do portfela automatycznie.</div>
          </div>
        </form>
        <?php else: ?>
        <p class="text-body-secondary mb-0">Płatności online nie są w tej chwili dostępne — portfel możesz
        doładować przelewem tradycyjnym (dane obok). Wpłata zostanie zaksięgowana przez placówkę.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-header fw-semibold bg-white">
        <i class="bi bi-bank2 text-primary me-1" aria-hidden="true"></i>Doładowanie przelewem tradycyjnym
      </div>
      <div class="card-body">
        <?php if ($pw_pay['account'] !== '' || $pw_pay['title'] !== ''): ?>
        <dl class="row small mb-0">
          <?php if ($pw_pay['account'] !== ''): ?>
          <dt class="col-sm-3 text-body-secondary fw-normal">Nr konta</dt>
          <dd class="col-sm-9 font-monospace mb-1"><?= h($pw_pay['account']) ?></dd>
          <?php endif; ?>
          <?php if ($pw_pay['title'] !== ''): ?>
          <dt class="col-sm-3 text-body-secondary fw-normal">Tytuł wpłaty</dt>
          <dd class="col-sm-9 mb-1"><?= h($pw_pay['title']) ?></dd>
          <?php endif; ?>
          <dt class="col-sm-3 text-body-secondary fw-normal">Kwota</dt>
          <dd class="col-sm-9 mb-0">dowolna — nadwyżka zostanie w portfelu na kolejne zajęcia</dd>
        </dl>
        <p class="small text-body-secondary mt-3 mb-2"><i class="bi bi-clock me-1" aria-hidden="true"></i>
        Przelew tradycyjny księgujemy ręcznie — środki pojawią się w portfelu w ciągu 1–2 dni roboczych.
        Możesz od razu zgłosić, że przelew został wykonany — placówka zaksięguje go szybciej.</p>
        <?php else: ?>
        <p class="text-body-secondary mb-2">Dane do wpłaty nie zostały jeszcze ustawione — skontaktuj się z placówką.</p>
        <?php endif; ?>
        <form method="post" action="<?= h($pw_form_action) ?>" class="row g-2 align-items-end border-top pt-3 mt-1">
          <input type="hidden" name="_op" value="wallet_declare">
          <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
          <div class="col-6">
            <label for="pwDeclAmt" class="form-label small mb-1">Kwota przelewu</label>
            <div class="input-group input-group-sm">
              <input type="number" class="form-control" id="pwDeclAmt" name="amount" min="1" max="20000" step="0.01" inputmode="decimal" required>
              <span class="input-group-text">zł</span>
            </div>
          </div>
          <div class="col-6">
            <label for="pwDeclNote" class="form-label small mb-1">Tytuł / referencja (opcjonalnie)</label>
            <input type="text" class="form-control form-control-sm" id="pwDeclNote" name="note" maxlength="500">
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-send-check me-1" aria-hidden="true"></i>Zgłoś wykonany przelew</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header fw-semibold bg-white">
    <i class="bi bi-clock-history text-primary me-1" aria-hidden="true"></i>Historia operacji
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <caption class="visually-hidden">Historia operacji portfela: wpłaty i opłaty za zajęcia</caption>
      <thead>
        <tr>
          <th scope="col">Data</th>
          <th scope="col">Operacja</th>
          <th scope="col" class="text-end">Kwota</th>
          <th scope="col">Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$pw_ops): ?>
        <tr><td colspan="4" class="text-center text-body-secondary py-4">Brak operacji — doładuj portfel powyżej.</td></tr>
        <?php endif; ?>
        <?php foreach ($pw_ops as $op): ?>
        <tr>
          <td class="text-nowrap"><?= h(date('d.m.Y', strtotime($op['date']))) ?></td>
          <td>
            <?php if ($op['kind'] === 'declared'): ?>
            <i class="bi bi-hourglass-split text-warning me-1" aria-hidden="true"></i><?= h($op['label']) ?>
            <?php elseif ($op['kind'] === 'in'): ?>
            <i class="bi bi-arrow-down-circle text-success me-1" aria-hidden="true"></i><?= h($op['label']) ?>
            <?php else: ?>
            <i class="bi bi-arrow-up-circle text-body-secondary me-1" aria-hidden="true"></i><?= h($op['label']) ?>
            <?php endif; ?>
            <?php if ($op['note'] !== ''): ?><div class="text-body-secondary" style="font-size:.78rem"><?= h($op['note']) ?></div><?php endif; ?>
          </td>
          <td class="text-end fw-semibold text-nowrap <?= $op['kind'] === 'in' ? 'text-success' : ($op['kind'] === 'declared' ? 'text-warning-emphasis' : '') ?>">
            <?= $op['kind'] === 'out' ? '−' : '+' ?><?= number_format($op['amount'], 2, ',', ' ') ?> zł
          </td>
          <td>
            <?php if ($op['kind'] === 'declared'): ?>
            <span class="badge text-bg-warning">oczekuje na zatwierdzenie</span>
            <?php elseif ($op['kind'] === 'in'): ?>
            <span class="badge text-bg-success">zaksięgowana</span>
            <?php elseif ($op['status'] === 'paid'): ?>
            <span class="badge text-bg-success">pokryta z portfela</span>
            <?php else: ?>
            <span class="badge text-bg-warning">
              <?php $pw_cov = (float)($op['covered'] ?? 0); ?>
              <?= $pw_cov > 0.005 ? 'częściowo pokryta (' . number_format($pw_cov, 2, ',', ' ') . ' zł)' : 'do pokrycia' ?>
            </span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white small text-body-secondary">
    Wpłaty oznaczone „na grupę” pokrywają wyłącznie zajęcia tej grupy; pozostałe wpłaty trafiają do portfela ogólnego.
  </div>
</div>

<script>
document.querySelectorAll('.pw-quick').forEach(function (b) {
  b.addEventListener('click', function () {
    var f = document.getElementById('pwAmount');
    if (f) { f.value = this.dataset.amount; f.focus(); }
  });
});
</script>
