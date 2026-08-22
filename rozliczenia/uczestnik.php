<?php
/**
 * rozliczenia/uczestnik.php — Karta rozliczeń uczestnika: salda per grupa, rozliczenia,
 * faktury (numer + wymagany skan), wpłaty i wydruki.
 * GET: ?client_id=N&m=YYYY-MM
 */
require_once __DIR__ . '/_boot.php';

$client_id = (int)($_GET['client_id'] ?? 0);
$client    = $client_id ? db_one("SELECT * FROM k30_clients WHERE id=?", [$client_id]) : null;
if (!$client) { http_response_code(404); die('Nie znaleziono uczestnika.'); }

$rz_redirect = 'uczestnik.php?client_id=' . $client_id . '&m=' . $rz_ym;
require __DIR__ . '/_actions.php';

ti_billing_recompute($client_id);            // odśwież alokację przed prezentacją
$gb       = ti_client_group_balances($client_id);
$bal      = ti_client_balance($client_id);
$payments = ti_payments_for_client($client_id);
$enr      = db_all("SELECT e.course_id, c.name FROM k30_ti_enrollments e
                    JOIN k30_ti_courses c ON c.id=e.course_id
                    WHERE e.client_id=? AND e.status='active' ORDER BY c.name", [$client_id]);
$cnames   = [];
foreach ($enr as $e) $cnames[(int)$e['course_id']] = (string)$e['name'];
foreach ($gb['groups'] as $gc => $gg) $cnames[(int)$gc] = $cnames[(int)$gc] ?? (string)$gg['course_name'];

$bills = db_all(
    "SELECT b.*, c.name AS course_name
     FROM k30_ti_billing b LEFT JOIN k30_ti_courses c ON c.id=b.course_id AND b.course_id>0
     WHERE b.client_id=? AND b.status!='cancelled'
     ORDER BY b.year DESC, b.month DESC, c.name", [$client_id]
);

$PAGE_TITLE = $client['name'];
$RZ_ACTIVE  = 'uczestnicy';
include __DIR__ . '/_head.php';
?>
<div class="tz-h">
  <h1><?= h($client['name']) ?></h1>
  <p>Rozliczenia w podziale na grupy · <?= count($enr) ?> aktywnych grup ·
     <a href="<?= APP_URL ?>/karty30/ti/student_billing.php?client_id=<?= $client_id ?>">widok klasyczny</a></p>
</div>

<?= rz_month_bar('uczestnik.php', $rz_year, $rz_month, $rz_month_label, 'client_id=' . $client_id) ?>
<?= flash_html() ?>

<dl class="rz-kpis">
  <div class="rz-kpi"><dt>Suma należności</dt><dd><?= h(rz_zl($bal['charges'])) ?></dd><small>wszystkie okresy</small></div>
  <div class="rz-kpi rz-kpi--ok"><dt>Suma wpłat</dt><dd><?= h(rz_zl($bal['payments'])) ?></dd><small><?= count($payments) ?> wpłat</small></div>
  <div class="rz-kpi rz-kpi--bad"><dt>Do zapłaty</dt><dd><?= h(rz_zl($bal['debt'])) ?></dd><small>niepokryte należności</small></div>
  <div class="rz-kpi rz-kpi--info"><dt>Nadpłata</dt><dd><?= h(rz_zl($bal['credit'])) ?></dd>
    <small>w grupach <?= h(rz_zl($bal['group_credit'])) ?> · ogólna <?= h(rz_zl($bal['general_credit'])) ?></small></div>
</dl>

<div class="tz-card">
  <div class="tz-card__hd"><i class="bi bi-collection" aria-hidden="true"></i>Salda per grupa
    <span class="sp"></span>
    <a class="tz-btn tz-btn--ghost tz-btn--sm" target="_blank" rel="noopener"
       href="<?= APP_URL ?>/karty30/ti/hours_pdf.php?client_id=<?= $client_id ?>&amp;month=<?= $rz_month ?>&amp;year=<?= $rz_year ?>">
      <i class="bi bi-clock-history" aria-hidden="true"></i>Rozpiska godzin (PDF)
    </a>
  </div>
  <?php if (!$gb['groups']): ?>
  <div class="rz-empty">Brak rozliczeń.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="rz-tbl">
      <caption class="visually-hidden">Salda uczestnika w podziale na grupy</caption>
      <thead><tr>
        <th scope="col">Grupa / przedmiot</th>
        <th scope="col" class="num">Należności</th>
        <th scope="col" class="num">Wpłaty na grupę</th>
        <th scope="col" class="num">Pokryte</th>
        <th scope="col" class="num">Saldo</th>
      </tr></thead>
      <tbody>
        <?php foreach ($gb['groups'] as $g): ?>
        <tr>
          <th scope="row" style="font-weight:600">
            <?php if ((int)$g['course_id'] > 0): ?>
            <a href="grupa.php?id=<?= (int)$g['course_id'] ?>&amp;m=<?= h($rz_ym) ?>"><?= h($g['course_name']) ?></a>
            <?php else: ?><span class="rz-zero"><?= h($g['course_name']) ?></span><?php endif; ?>
          </th>
          <td class="num"><?= h(rz_zl($g['charges'])) ?></td>
          <td class="num"><?= h(rz_zl($g['payments'])) ?></td>
          <td class="num"><?= h(rz_zl($g['paid'])) ?></td>
          <td class="num"><?= rz_saldo((float)$g['credit'], (float)$g['debt']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="tz-card__ft">
    Nadpłata przypisana do grupy pokrywa tylko kolejne zajęcia w tej grupie.
    <?php if ($gb['general_credit'] > 0.005): ?>
    Nadpłata ogólna <strong><?= h(rz_zl($gb['general_credit'])) ?></strong> zostanie użyta w dowolnej grupie (od najstarszej należności).
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="tz-card">
  <div class="tz-card__hd"><i class="bi bi-receipt" aria-hidden="true"></i>Rozliczenia
    <span class="sp"></span>
    <?php if ($rz_can_write): ?>
    <form method="post" class="d-inline">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="issue">
      <input type="hidden" name="client_id" value="<?= $client_id ?>">
      <button class="tz-btn tz-btn--sm" title="Wystaw rozliczenia za <?= h($rz_month_label) ?> (osobno na każdą grupę)">
        <i class="bi bi-plus-lg" aria-hidden="true"></i>Wystaw za <?= h(mb_strtolower($rz_month_label)) ?>
      </button>
    </form>
    <?php endif; ?>
  </div>
  <?php if (!$bills): ?>
  <div class="rz-empty">Brak rozliczeń.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="rz-tbl">
      <caption class="visually-hidden">Rozliczenia uczestnika</caption>
      <thead><tr>
        <th scope="col">Okres</th>
        <th scope="col">Grupa</th>
        <th scope="col" class="num">Do zapłaty</th>
        <th scope="col" class="num">Pokryte</th>
        <th scope="col">Status</th>
        <th scope="col">Faktura</th>
        <th scope="col" class="num">Akcje</th>
      </tr></thead>
      <tbody>
        <?php foreach ($bills as $b):
          $tot  = (float)$b['amount'] + (float)($b['adjustment'] ?? 0);
          $paid = (float)($b['paid_amount'] ?? 0);
          $st   = K30_TI_BILLING_STATUSES[$b['status']] ?? ['label'=>$b['status'],'color'=>'#6b7280','bg'=>'#f3f4f6'];
        ?>
        <tr>
          <th scope="row" style="font-weight:600"><?= h((RZ_MONTHS_PL[(int)$b['month']] ?? $b['month']) . ' ' . (int)$b['year']) ?></th>
          <td><?= $b['course_name'] ? h($b['course_name']) : '<span class="rz-zero">rozliczenie łączne</span>' ?></td>
          <td class="num"><?= h(rz_zl($tot)) ?></td>
          <td class="num"><?= h(rz_zl($paid)) ?><?php if ($tot - $paid > 0.005): ?><br><span class="rz-neg" style="font-size:.78rem">brakuje <?= h(rz_zl($tot - $paid)) ?></span><?php endif; ?></td>
          <td><span class="tz-badge" style="background:<?= h($st['bg']) ?>;color:<?= h($st['color']) ?>;border:1px solid <?= h($st['color']) ?>44"><?= h($st['label']) ?></span></td>
          <td>
            <?php if (!empty($b['invoice_path'])): ?>
              <a href="<?= APP_URL ?>/karty30/ti/billing_invoice.php?id=<?= (int)$b['id'] ?>" target="_blank" rel="noopener"><?= h($b['invoice_no'] ?: 'skan') ?></a>
              <?php if (($b['invoice_kind'] ?? '') === 'oneoff'): ?><span class="tz-badge tz-badge--warn">jednorazowa</span><?php endif; ?>
            <?php else: ?>
              <span class="tz-badge tz-badge--warn">brak skanu</span>
            <?php endif; ?>
          </td>
          <td class="num">
            <?php if ($rz_can_write): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"   value="mail">
              <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
              <button class="tz-btn tz-btn--ghost tz-btn--sm" title="Wyślij mail z rozliczeniem"><i class="bi bi-envelope" aria-hidden="true"></i>Mail</button>
            </form>
            <button class="tz-btn tz-btn--ghost tz-btn--sm" type="button" data-bs-toggle="modal" data-bs-target="#fv<?= (int)$b['id'] ?>">
              <i class="bi bi-file-earmark-text" aria-hidden="true"></i>Faktura
            </button>
            <?php endif; ?>
            <a class="tz-btn tz-btn--ghost tz-btn--sm" target="_blank" rel="noopener"
               href="<?= APP_URL ?>/karty30/ti/billing_fv_summary.php?id=<?= (int)$b['id'] ?>"><i class="bi bi-printer" aria-hidden="true"></i>FVAT</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="tz-card">
  <div class="tz-card__hd"><i class="bi bi-cash-stack" aria-hidden="true"></i>Wpłaty
    <span class="sp"></span><span class="tz-badge tz-badge--off"><?= count($payments) ?></span></div>
  <?php if ($rz_can_write): ?>
  <div class="tz-card__bd">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="payment">
      <input type="hidden" name="client_id" value="<?= $client_id ?>">
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1" for="amt">Kwota (zł)</label>
        <input type="text" name="amount" id="amt" class="form-control form-control-sm" placeholder="0,00" inputmode="decimal" required>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1" for="dt">Data</label>
        <input type="date" name="paid_at" id="dt" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1" for="mth">Metoda</label>
        <select name="method" id="mth" class="form-select form-select-sm">
          <option value="transfer">Przelew</option><option value="cash">Gotówka</option><option value="other">Inna</option>
        </select>
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1" for="grp">Zaksięguj na grupę</label>
        <select name="course_id" id="grp" class="form-select form-select-sm">
          <option value="0">— wpłata ogólna (FIFO, dowolna grupa) —</option>
          <?php foreach ($enr as $e): ?>
          <option value="<?= (int)$e['course_id'] ?>"><?= h($e['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2"><button class="tz-btn w-100"><i class="bi bi-plus-lg" aria-hidden="true"></i>Zapisz</button></div>
      <div class="col-12"><input type="text" name="note" class="form-control form-control-sm" placeholder="Notatka (opcjonalnie)"></div>
    </form>
  </div>
  <?php endif; ?>
  <?php if ($payments): ?>
  <div class="table-responsive">
    <table class="rz-tbl">
      <caption class="visually-hidden">Historia wpłat uczestnika</caption>
      <thead><tr>
        <th scope="col">Data</th><th scope="col" class="num">Kwota</th><th scope="col">Grupa</th>
        <th scope="col">Metoda</th><th scope="col">Notatka</th>
        <?php if ($rz_can_admin): ?><th scope="col" class="num">Akcja</th><?php endif; ?>
      </tr></thead>
      <tbody>
        <?php foreach ($payments as $pm):
          $pmc  = (int)($pm['course_id'] ?? 0);
          $mlab = ['transfer'=>'Przelew','cash'=>'Gotówka','stripe'=>'Stripe','payu'=>'PayU','other'=>'Inna'][$pm['method']] ?? $pm['method']; ?>
        <tr>
          <td><?= h(substr($pm['paid_at'] ?: $pm['created_at'], 0, 10)) ?></td>
          <td class="num rz-pos">+<?= h(rz_zl($pm['amount'])) ?></td>
          <td><?= $pmc > 0 ? '<span class="tz-badge tz-badge--info">' . h($cnames[$pmc] ?? ('Grupa #'.$pmc)) . '</span>' : '<span class="rz-zero">ogólna</span>' ?></td>
          <td><?= h($mlab) ?></td>
          <td class="rz-zero"><?= h(mb_substr((string)($pm['note'] ?? ''), 0, 60)) ?></td>
          <?php if ($rz_can_admin): ?>
          <td class="num">
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć tę wpłatę? Salda zostaną przeliczone.')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"   value="payment_del">
              <input type="hidden" name="payment_id" value="<?= (int)$pm['id'] ?>">
              <button class="tz-btn tz-btn--ghost tz-btn--sm" title="Usuń wpłatę"><i class="bi bi-trash" aria-hidden="true"></i></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="rz-empty">Brak zarejestrowanych wpłat.</div>
  <?php endif; ?>
</div>

<?php if ($rz_can_write): foreach ($bills as $b): ?>
<div class="modal fade" id="fv<?= (int)$b['id'] ?>" tabindex="-1" aria-labelledby="fvl<?= (int)$b['id'] ?>" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" enctype="multipart/form-data" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="invoice">
      <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
      <div class="modal-header">
        <h2 class="modal-title h6" id="fvl<?= (int)$b['id'] ?>">
          Faktura — <?= h((RZ_MONTHS_PL[(int)$b['month']] ?? '') . ' ' . (int)$b['year']) ?><?= $b['course_name'] ? ' · ' . h($b['course_name']) : '' ?>
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="small text-body-secondary">
          Faktury wystawiamy w systemie <strong><?= h(k30_ti_invoice_system()) ?></strong>.
          Tutaj rejestrujemy numer i <strong>obowiązkowy skan PDF</strong>.
        </p>
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1" for="k<?= (int)$b['id'] ?>">Rodzaj</label>
          <select name="invoice_kind" id="k<?= (int)$b['id'] ?>" class="form-select form-select-sm">
            <option value=""       <?= ($b['invoice_kind'] ?? '') !== 'oneoff' ? 'selected' : '' ?>>Faktura do rozliczenia (cykliczna)</option>
            <option value="oneoff" <?= ($b['invoice_kind'] ?? '') === 'oneoff' ? 'selected' : '' ?>>Faktura jednorazowa</option>
          </select>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-7">
            <label class="form-label small fw-semibold mb-1" for="n<?= (int)$b['id'] ?>">Numer faktury</label>
            <input type="text" name="invoice_no" id="n<?= (int)$b['id'] ?>" class="form-control form-control-sm"
                   value="<?= h($b['invoice_no'] ?? '') ?>" placeholder="FV/123/2026">
          </div>
          <div class="col-5">
            <label class="form-label small fw-semibold mb-1" for="d<?= (int)$b['id'] ?>">Data wystawienia</label>
            <input type="date" name="invoice_issued_on" id="d<?= (int)$b['id'] ?>" class="form-control form-control-sm"
                   value="<?= h($b['invoice_issued_on'] ?? '') ?>">
          </div>
        </div>
        <label class="form-label small fw-semibold mb-1" for="f<?= (int)$b['id'] ?>">
          Skan faktury (PDF)<?= empty($b['invoice_path']) ? ' — wymagany' : '' ?>
        </label>
        <input type="file" name="invoice" id="f<?= (int)$b['id'] ?>" accept="application/pdf"
               class="form-control form-control-sm" <?= empty($b['invoice_path']) ? 'required aria-required="true"' : '' ?>>
        <?php if (!empty($b['invoice_path'])): ?>
        <p class="small mt-2 mb-0">Załączono:
          <a href="<?= APP_URL ?>/karty30/ti/billing_invoice.php?id=<?= (int)$b['id'] ?>" target="_blank" rel="noopener"><?= h($b['invoice_name'] ?: 'faktura.pdf') ?></a>
        </p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="tz-btn tz-btn--ghost" data-bs-dismiss="modal">Anuluj</button>
        <button class="tz-btn"><i class="bi bi-save" aria-hidden="true"></i>Zapisz fakturę</button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
