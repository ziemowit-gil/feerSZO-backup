<?php
/**
 * crm/invoices/generate.php — panel zbiorczego generowania faktur.
 *
 * Jeden ekran dla dwóch źródeł:
 *   • TI  — rozliczenia miesięczne uczestników (wybrany okres, opcjonalnie grupa),
 *   • CRM — oferty zaakceptowane i zrealizowane, jeszcze niezafakturowane.
 *
 * Panel pokazuje TYLKO pozycje bez faktury i celowo tworzy SZKICE, a nie gotowe
 * dokumenty: numer nadaje się świadomie, po sprawdzeniu nabywcy i pozycji.
 * Wystawienie hurtem jest osobnym, jawnie potwierdzanym wyborem.
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/invoices.php';

require_login();
require_module_enabled('invoices_enabled', 'Moduł Faktury');
crm_require('invoices', 'write');
invoices_migrate();

if (!is_admin() && !can_write('crm')) {
    flash_set('error', 'Brak uprawnień do wystawiania faktur.');
    header('Location: ' . APP_URL . '/crm/invoices/index.php'); exit;
}

$PAGE_TITLE = 'Generowanie faktur';
$uid        = (int)(current_user()['id'] ?? 0);
$src        = ($_GET['src'] ?? $_POST['src'] ?? 'ti') === 'crm' ? 'crm' : 'ti';
$month      = (int)($_GET['month'] ?? $_POST['month'] ?? date('n'));
$year       = (int)($_GET['year']  ?? $_POST['year']  ?? date('Y'));
$group      = (int)($_GET['group'] ?? $_POST['group'] ?? 0);

// Moduł TI jest opcjonalny — bez niego zostaje zakładka CRM.
$ti_on = is_file(dirname(dirname(__DIR__)) . '/includes/karty30.php');
if ($ti_on) require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
if (!$ti_on && $src === 'ti') $src = 'crm';

// ── Generowanie ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'generate') {
    csrf_check();

    $ids    = array_values(array_unique(array_map('intval', (array)($_POST['pick'] ?? []))));
    $issue  = !empty($_POST['issue']);          // od razu nadać numer
    $demo   = is_admin() && !empty($_POST['demo']);
    $made   = 0; $skipped = 0; $errors = [];

    foreach ($ids as $sid) {
        if ($sid <= 0) continue;
        $r = $src === 'ti'
            ? invoice_from_ti_billing($sid, $uid, $demo)
            : invoice_from_offer($sid, $uid, $demo);

        if (empty($r['ok'])) { $errors[] = '#' . $sid . ': ' . (string)$r['error']; continue; }
        if (!empty($r['existing'])) { $skipped++; continue; }
        foreach (($r['warnings'] ?? []) as $w) $errors[] = '#' . $sid . ' (ostrzeżenie): ' . $w;

        $made++;
        if ($issue) {
            $iss = invoice_issue_local((int)$r['id']);
            if (empty($iss['ok'])) $errors[] = '#' . $sid . ': ' . (string)$iss['error'];
        }
    }

    $msg = $made . ' ' . ($made === 1 ? 'faktura' : 'faktur')
         . ($issue ? ' wystawionych' : ' — szkice utworzone')
         . ($demo ? ' (DEMO)' : '') . '.';
    if ($skipped) $msg .= ' Pominięto już zafakturowane: ' . $skipped . '.';
    if ($errors)  $msg .= ' Błędy: ' . implode('; ', array_slice($errors, 0, 3))
                        . (count($errors) > 3 ? ' (i ' . (count($errors) - 3) . ' więcej)' : '');

    flash_set($errors ? 'warning' : 'success', $msg);
    header('Location: ' . APP_URL . '/crm/invoices/generate.php?'
        . http_build_query(['src' => $src, 'month' => $month, 'year' => $year, 'group' => $group ?: null]));
    exit;
}

// ── Kandydaci ────────────────────────────────────────────────────────────────
$rows   = [];
$groups = [];

if ($src === 'ti' && $ti_on) {
    try {
        $groups = db_all("SELECT id, name FROM k30_ti_courses
                           WHERE is_active=1 AND COALESCE(no_invoice,0)=0 ORDER BY name");
    } catch (\Throwable $e) { $groups = []; }

    // Bez faktury = brak wpisu w invoices dla tego źródła (poza demo i usuniętymi).
    $params = [$month, $year];
    $gsql   = '';
    if ($group > 0) { $gsql = ' AND COALESCE(b.course_id,0) = ?'; $params[] = $group; }

    try {
        $rows = db_all(
            "SELECT b.id, b.amount, b.hours_billed, b.status, b.payer_name,
                    COALESCE(b.course_id,0) AS course_id,
                    c.name AS client_name, co.name AS course_name
               FROM k30_ti_billing b
               JOIN k30_clients    c  ON c.id = b.client_id
               LEFT JOIN k30_ti_courses co ON co.id = b.course_id
              WHERE b.month = ? AND b.year = ?
                AND b.amount > 0
                AND b.status != 'cancelled'
                {$gsql}
                AND COALESCE(co.no_invoice,0) = 0
                AND NOT EXISTS (SELECT 1 FROM invoices i
                                 WHERE i.source='ti_billing' AND i.source_id=b.id
                                   AND i.is_test=0 AND i.deleted_at IS NULL)
           ORDER BY c.name",
            $params
        );
    } catch (\Throwable $e) { $rows = []; }
} else {
    try {
        $rows = db_all(
            "SELECT o.id, o.offer_number, o.title, o.total_gross, o.currency, o.status,
                    o.requires_confirmation, o.confirmation_id,
                    c.imie_nazwisko AS contact_name, c.nip
               FROM crm_offers o
               JOIN crm_contacts c ON c.id = o.contact_id
              WHERE o.deleted_at IS NULL
                AND o.status IN ('zaakceptowana','zrealizowana')
                AND NOT EXISTS (SELECT 1 FROM invoices i
                                 WHERE i.source='offer' AND i.source_id=o.id
                                   AND i.is_test=0 AND i.deleted_at IS NULL)
           ORDER BY o.decided_at DESC, o.id DESC
              LIMIT 200"
        );
    } catch (\Throwable $e) { $rows = []; }
}

$qs = fn(array $o = []) => '?' . http_build_query(array_merge(
    ['src' => $src, 'month' => $month, 'year' => $year, 'group' => $group ?: null], $o));

include dirname(__DIR__) . '/includes/header_crm.php';
?>
<div class="container-fluid px-0" style="max-width:1100px">

  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a href="<?= APP_URL ?>/crm/invoices/index.php" class="btn btn-sm btn-crm-ghost"><i class="bi bi-arrow-left"></i></a>
    <h1 class="h5 mb-0 me-auto"><i class="bi bi-layer-forward me-2"></i>Generowanie faktur</h1>
    <span class="badge bg-secondary"><?= count($rows) ?> do zafakturowania</span>
  </div>

  <ul class="nav nav-tabs mb-3" role="tablist">
    <?php if ($ti_on): ?>
    <li class="nav-item">
      <a class="nav-link<?= $src === 'ti' ? ' active' : '' ?>" href="<?= h($qs(['src' => 'ti'])) ?>" role="tab">
        <i class="bi bi-mortarboard me-1"></i>Rozliczenia TI
      </a>
    </li>
    <?php endif; ?>
    <li class="nav-item">
      <a class="nav-link<?= $src === 'crm' ? ' active' : '' ?>" href="<?= h($qs(['src' => 'crm'])) ?>" role="tab">
        <i class="bi bi-file-earmark-ruled me-1"></i>Oferty CRM
      </a>
    </li>
  </ul>

  <?php if ($src === 'ti'): ?>
  <form method="get" class="card mb-3">
    <input type="hidden" name="src" value="ti">
    <div class="card-body py-2">
      <div class="row g-2 align-items-end">
        <div class="col-sm-3">
          <label class="form-label small mb-1" for="gm">Miesiąc</label>
          <select class="form-select form-select-sm" id="gm" name="month">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>"<?= $m === $month ? ' selected' : '' ?>>
              <?= str_pad((string)$m, 2, '0', STR_PAD_LEFT) ?>
            </option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-sm-3">
          <label class="form-label small mb-1" for="gy">Rok</label>
          <input type="number" class="form-control form-control-sm" id="gy" name="year"
                 value="<?= (int)$year ?>" min="2020" max="2100">
        </div>
        <div class="col-sm-4">
          <label class="form-label small mb-1" for="gg">Grupa</label>
          <select class="form-select form-select-sm" id="gg" name="group">
            <option value="0">— wszystkie —</option>
            <?php foreach ($groups as $g): ?>
            <option value="<?= (int)$g['id'] ?>"<?= $group === (int)$g['id'] ? ' selected' : '' ?>>
              <?= h($g['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-2">
          <button class="btn btn-sm btn-crm-outline w-100" type="submit">
            <i class="bi bi-funnel me-1"></i>Pokaż
          </button>
        </div>
      </div>
    </div>
  </form>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="generate">
    <input type="hidden" name="src"   value="<?= h($src) ?>">
    <input type="hidden" name="month" value="<?= (int)$month ?>">
    <input type="hidden" name="year"  value="<?= (int)$year ?>">
    <input type="hidden" name="group" value="<?= (int)$group ?>">

    <div class="card">
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <caption class="visually-hidden">Pozycje do zafakturowania</caption>
          <thead class="table-light">
            <tr>
              <th scope="col" style="width:2.5rem">
                <input type="checkbox" class="form-check-input" id="pickAll" aria-label="Zaznacz wszystkie">
              </th>
              <?php if ($src === 'ti'): ?>
              <th scope="col">Uczestnik</th>
              <th scope="col">Grupa</th>
              <th scope="col" class="text-end">Godziny</th>
              <th scope="col">Nabywca</th>
              <th scope="col" class="text-end">Kwota</th>
              <?php else: ?>
              <th scope="col">Oferta</th>
              <th scope="col">Klient</th>
              <th scope="col">Status</th>
              <th scope="col" class="text-end">Wartość</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">
              <?= $src === 'ti'
                  ? 'Brak rozliczeń bez faktury w wybranym okresie.'
                  : 'Brak ofert zaakceptowanych lub zrealizowanych, które nie mają jeszcze faktury.' ?>
            </td></tr>
          <?php else: foreach ($rows as $r): ?>
            <?php
              // Oferta dla osoby fizycznej bez potwierdzenia nie może być fakturowana —
              // ta sama reguła co w invoice_from_offer(), tu tylko sygnalizowana.
              $blocked = $src === 'crm' && !empty($r['requires_confirmation']) && empty($r['confirmation_id']);
            ?>
            <tr<?= $blocked ? ' class="table-warning"' : '' ?>>
              <td>
                <input type="checkbox" class="form-check-input pick" name="pick[]" value="<?= (int)$r['id'] ?>"
                       <?= $blocked ? 'disabled' : '' ?>
                       aria-label="Wybierz pozycję <?= (int)$r['id'] ?>">
              </td>
              <?php if ($src === 'ti'): ?>
              <td><?= h((string)$r['client_name']) ?></td>
              <td><?= h((string)($r['course_name'] ?: '—')) ?></td>
              <td class="text-end"><?= h(rtrim(rtrim(number_format((float)$r['hours_billed'], 2, ',', ' '), '0'), ',')) ?></td>
              <?php $bp = invoice_ti_buyer_preview($r); ?>
              <td class="small">
                <?= h($bp['name'] ?: 'uczestnik') ?>
                <?php if ($bp['kind'] === 'OF'): ?>
                <span class="badge bg-light text-dark border" style="font-size:.6rem"
                      title="Brak NIP — osoba fizyczna, faktura poza KSeF">OF</span>
                <?php else: ?>
                <span class="badge bg-info bg-opacity-10 text-info border border-info" style="font-size:.6rem"
                      title="NIP <?= h($bp['tax_no']) ?> — podatnik, faktura podlega KSeF">NIP</span>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= number_format((float)$r['amount'], 2, ',', ' ') ?></td>
              <?php else: ?>
              <td>
                <span class="font-monospace small"><?= h((string)$r['offer_number']) ?></span><br>
                <span class="small"><?= h(mb_strimwidth((string)$r['title'], 0, 50, '…')) ?></span>
              </td>
              <td>
                <?= h((string)$r['contact_name']) ?>
                <?php if (empty($r['nip'])): ?>
                <span class="badge bg-light text-dark border" style="font-size:.6rem" title="Bez NIP — poza KSeF">os. fiz.</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge bg-light text-dark border"><?= h((string)$r['status']) ?></span>
                <?php if ($blocked): ?>
                <span class="badge bg-warning text-dark" style="font-size:.6rem"
                      title="Oferta dla osoby fizycznej wymaga potwierdzenia">bez potwierdzenia</span>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= number_format((float)$r['total_gross'], 2, ',', ' ') ?> <?= h((string)$r['currency']) ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($rows): ?>
    <div class="card mt-3">
      <div class="card-body py-2 d-flex flex-wrap gap-3 align-items-center">
        <div class="form-check">
          <input type="checkbox" class="form-check-input" id="issueNow" name="issue" value="1">
          <label class="form-check-label small" for="issueNow">
            Od razu <strong>wystaw</strong> (nadaj numery)
          </label>
        </div>
        <?php if (is_admin()): ?>
        <div class="form-check">
          <input type="checkbox" class="form-check-input" id="demoMode" name="demo" value="1">
          <label class="form-check-label small text-danger" for="demoMode">
            Tryb <strong>DEMO</strong> (numery TEST/, bez wysyłki)
          </label>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-sm btn-crm-primary ms-auto"
                onclick="return this.form.querySelectorAll('.pick:checked').length ? true : (alert('Zaznacz co najmniej jedną pozycję.'), false)">
          <i class="bi bi-layer-forward me-1"></i>Generuj dla zaznaczonych
        </button>
      </div>
      <div class="card-body pt-0">
        <p class="form-text mb-0">
          Domyślnie powstają <strong>szkice</strong> — numer nadaje się świadomie, po sprawdzeniu nabywcy
          i pozycji. Pozycje już zafakturowane nie są tu pokazywane.
          <strong>OF</strong> oznacza nabywcę bez NIP-u, czyli osobę fizyczną — taka faktura jest poza KSeF
          i przekazuje się ją nabywcy bezpośrednio.
        </p>
      </div>
    </div>
    <?php endif; ?>
  </form>
</div>

<script>
// Zaznacz wszystkie — pomijając pozycje zablokowane regułą biznesową.
document.getElementById('pickAll')?.addEventListener('change', function () {
  document.querySelectorAll('.pick:not([disabled])').forEach(c => { c.checked = this.checked; });
});
</script>
<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
