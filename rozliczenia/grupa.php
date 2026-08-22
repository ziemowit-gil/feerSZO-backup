<?php
/**
 * rozliczenia/grupa.php — Rozliczenia jednej grupy: uczestnicy, salda, akcje, wydruki.
 * GET: ?id=<course_id>&m=YYYY-MM
 */
require_once __DIR__ . '/_boot.php';

$course_id = (int)($_GET['id'] ?? 0);
$course    = $course_id ? k30_ti_course_get($course_id) : null;
if (!$course) { http_response_code(404); die('Nie znaleziono grupy.'); }

$rz_redirect = 'grupa.php?id=' . $course_id . '&m=' . $rz_ym;
require __DIR__ . '/_actions.php';

$sum   = ti_course_billing_summary($course_id, $rz_year, $rz_month);
$model = k30_ti_billing_model_label((int)($course['billing_model'] ?? 2) ?: 2);

// Rozliczenia tej grupy w miesiącu (per uczestnik) — numer faktury, skan, id do maila
$bills = db_all(
    "SELECT b.*, cl.name AS client_name
     FROM k30_ti_billing b JOIN k30_clients cl ON cl.id=b.client_id
     WHERE COALESCE(b.course_id,0)=CAST(? AS INTEGER) AND b.year=? AND b.month=? AND b.status IN ('issued','paid')",
    [$course_id, $rz_year, $rz_month]
);
$bill_by_client = [];
foreach ($bills as $b) $bill_by_client[(int)$b['client_id']] = $b;

$PAGE_TITLE = $course['name'];
$RZ_ACTIVE  = 'grupy';
include __DIR__ . '/_head.php';
?>
<div class="tz-h">
  <h1><?= h($course['name']) ?></h1>
  <p>
    <?php if (!empty($course['group_code'])): ?>Kod grupy: <strong><?= h($course['group_code']) ?></strong> · <?php endif; ?>
    Model rozliczania: <strong><?= h($model) ?></strong>
    <?php if (!empty($course['instructor_name'])): ?> · Prowadzący: <strong><?= h($course['instructor_name']) ?></strong><?php endif; ?>
  </p>
</div>

<?= rz_month_bar('grupa.php', $rz_year, $rz_month, $rz_month_label, 'id=' . $course_id) ?>
<?= flash_html() ?>

<dl class="rz-kpis">
  <div class="rz-kpi"><dt>Należności grupy (bieżące)</dt><dd><?= h(rz_zl($sum['totals']['charges'])) ?></dd>
    <small><?= count($sum['participants']) ?> uczestników</small></div>
  <div class="rz-kpi rz-kpi--ok"><dt>Pokryte</dt><dd><?= h(rz_zl($sum['totals']['paid'])) ?></dd>
    <small>w <?= h(mb_strtolower($rz_month_label)) ?>: <?= h(rz_zl($sum['totals']['m_paid'])) ?></small></div>
  <div class="rz-kpi rz-kpi--bad"><dt>Niedopłaty</dt><dd><?= h(rz_zl($sum['totals']['debt'])) ?></dd>
    <small>zaległości w tej grupie</small></div>
  <div class="rz-kpi rz-kpi--info"><dt>Nadpłaty grupy</dt><dd><?= h(rz_zl($sum['totals']['credit'])) ?></dd>
    <small>zaliczane na kolejne zajęcia w tej grupie</small></div>
</dl>

<div class="tz-card">
  <div class="tz-card__hd">
    <i class="bi bi-people" aria-hidden="true"></i>Uczestnicy
    <span class="sp"></span>
    <a class="tz-btn tz-btn--ghost tz-btn--sm" target="_blank" rel="noopener"
       href="<?= APP_URL ?>/karty30/ti/billing_fv_summary.php?course_id=<?= $course_id ?>&amp;month=<?= $rz_month ?>&amp;year=<?= $rz_year ?>">
      <i class="bi bi-printer" aria-hidden="true"></i>Pozycje do FVAT
    </a>
    <a class="tz-btn tz-btn--ghost tz-btn--sm" target="_blank" rel="noopener"
       href="<?= APP_URL ?>/karty30/ti/group_monthly.php?course_id=<?= $course_id ?>&amp;m=<?= h($rz_ym) ?>">
      <i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i>Raport grupy
    </a>
  </div>
  <?php if (!$sum['participants']): ?>
  <div class="rz-empty">Brak uczestników i rozliczeń w tej grupie.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="rz-tbl">
      <caption class="visually-hidden">Uczestnicy grupy i ich rozliczenia</caption>
      <thead><tr>
        <th scope="col">Uczestnik</th>
        <th scope="col" class="num">Należności <?= h(mb_strtolower(RZ_MONTHS_PL[$rz_month] ?? '')) ?></th>
        <th scope="col" class="num">Pokryte (bieżąco)</th>
        <th scope="col" class="num">Saldo w grupie</th>
        <th scope="col">Faktura</th>
        <th scope="col" class="num">Akcje</th>
      </tr></thead>
      <tbody>
        <?php foreach ($sum['participants'] as $p):
          $b = $bill_by_client[(int)$p['client_id']] ?? null; ?>
        <tr>
          <th scope="row" style="font-weight:600">
            <a href="uczestnik.php?client_id=<?= (int)$p['client_id'] ?>&amp;m=<?= h($rz_ym) ?>"><?= h($p['client_name']) ?></a>
          </th>
          <td class="num"><?= $p['m_charges'] > 0.005 ? h(rz_zl($p['m_charges'])) : '<span class="rz-zero">—</span>' ?></td>
          <td class="num"><?= h(rz_zl($p['paid'])) ?></td>
          <td class="num"><?= rz_saldo((float)$p['credit'], (float)$p['debt']) ?></td>
          <td>
            <?php if ($b && !empty($b['invoice_path'])): ?>
              <a href="<?= APP_URL ?>/karty30/ti/billing_invoice.php?id=<?= (int)$b['id'] ?>" target="_blank" rel="noopener">
                <?= h($b['invoice_no'] ?: 'skan') ?></a>
              <?php if (($b['invoice_kind'] ?? '') === 'oneoff'): ?>
              <span class="tz-badge tz-badge--warn">jednorazowa</span>
              <?php endif; ?>
            <?php elseif ($b): ?>
              <span class="tz-badge tz-badge--warn"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>brak skanu</span>
            <?php else: ?>
              <span class="rz-zero">—</span>
            <?php endif; ?>
          </td>
          <td class="num">
            <?php if ($rz_can_write): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"   value="issue">
              <input type="hidden" name="client_id" value="<?= (int)$p['client_id'] ?>">
              <button class="tz-btn tz-btn--ghost tz-btn--sm" title="Wystaw rozliczenia za ten miesiąc">
                <i class="bi bi-receipt" aria-hidden="true"></i>Wystaw
              </button>
            </form>
            <?php if ($b): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"   value="mail">
              <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
              <button class="tz-btn tz-btn--ghost tz-btn--sm" title="Wyślij mail z rozliczeniem">
                <i class="bi bi-envelope" aria-hidden="true"></i>Mail
              </button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
            <a class="tz-btn tz-btn--ghost tz-btn--sm" target="_blank" rel="noopener"
               href="<?= APP_URL ?>/karty30/ti/hours_pdf.php?client_id=<?= (int)$p['client_id'] ?>&amp;month=<?= $rz_month ?>&amp;year=<?= $rz_year ?>&amp;course_id=<?= $course_id ?>"
               title="Rozpiska godzin dla beneficjenta">
              <i class="bi bi-clock-history" aria-hidden="true"></i>Rozpiska
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($rz_can_write && $sum['participants']): ?>
<div class="tz-card">
  <div class="tz-card__hd"><i class="bi bi-cash-stack" aria-hidden="true"></i>Zaksięguj wpłatę na tę grupę</div>
  <div class="tz-card__bd">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="payment">
      <input type="hidden" name="course_id" value="<?= $course_id ?>">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1" for="pcl">Uczestnik</label>
        <select name="client_id" id="pcl" class="form-select form-select-sm" required>
          <?php foreach ($sum['participants'] as $p): ?>
          <option value="<?= (int)$p['client_id'] ?>"><?= h($p['client_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1" for="pamt">Kwota (zł)</label>
        <input type="text" name="amount" id="pamt" class="form-control form-control-sm" placeholder="0,00" inputmode="decimal" required>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1" for="pdt">Data</label>
        <input type="date" name="paid_at" id="pdt" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1" for="pmt">Metoda</label>
        <select name="method" id="pmt" class="form-select form-select-sm">
          <option value="transfer">Przelew</option><option value="cash">Gotówka</option><option value="other">Inna</option>
        </select>
      </div>
      <div class="col-sm-2">
        <button class="tz-btn w-100"><i class="bi bi-plus-lg" aria-hidden="true"></i>Zapisz wpłatę</button>
      </div>
      <div class="col-12">
        <input type="text" name="note" class="form-control form-control-sm" placeholder="Notatka (opcjonalnie), np. tytuł przelewu">
      </div>
    </form>
  </div>
  <div class="tz-card__ft">
    Wpłata zaksięgowana na grupę pokrywa wyłącznie należności tej grupy; nadwyżka zostaje jako nadpłata tej grupy.
    Wpłatę ogólną (FIFO po wszystkich grupach) zapiszesz na karcie uczestnika.
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
