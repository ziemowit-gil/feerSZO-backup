<?php
/**
 * extforms/konsultacjeADNGO/admin.php
 * Panel administracyjny kart doradztwa — Formularz zewnętrzny ADNGO.
 */
$_root = dirname(__DIR__, 2);
require_once $_root . '/config.php';
require_once $_root . '/includes/db.php';
require_once $_root . '/includes/auth.php';
require_once $_root . '/includes/functions.php';
require_once $_root . '/includes/consultations.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('dostepnosc_ngo_enabled', 'Moduł Dostępność NGO');
cc_migrate();

$can_edit = can_edit();
$base     = APP_URL . '/extforms/konsultacjeADNGO';

/* ── Szybkie akcje POST (status / usuń) ───────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($id && $action === 'status' && $can_edit) {
        $new = ($_POST['status'] ?? '') === 'closed' ? 'closed' : 'new';
        db()->prepare("UPDATE szo_consultation_cards SET status=? WHERE id=?")->execute([$new, $id]);
        flash_set('success', 'Status karty #' . $id . ' został zmieniony.');
    } elseif ($id && $action === 'delete' && is_admin()) {
        db()->prepare("DELETE FROM szo_consultation_cards WHERE id=?")->execute([$id]);
        flash_set('success', 'Karta #' . $id . ' została usunięta.');
    }
    $qs = http_build_query(array_filter([
        'from' => $_POST['from'] ?? '', 'to' => $_POST['to'] ?? '',
    ]));
    header('Location: ' . $base . '/admin.php' . ($qs ? '?' . $qs : ''));
    exit;
}

/* ── Filtr dat ─────────────────────────────────────────────────────────────── */
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to']   ?? '');
if ($from !== '' && !cc_valid_date($from)) $from = '';
if ($to   !== '' && !cc_valid_date($to))   $to   = '';

$rows = cc_list($from, $to);

$sum_hours = 0.0;
$cnt_new   = 0;
foreach ($rows as $r) {
    $sum_hours += (float)$r['hours'];
    if ($r['status'] === 'new') $cnt_new++;
}

$by_org   = cc_hours_by_org($from, $to);
$areas    = cc_areas();
$forms    = cc_forms();
$statuses = cc_statuses();
$zip_qs   = http_build_query(array_filter(['from' => $from, 'to' => $to]));

$PAGE_TITLE = 'Karty doradztwa — ADNGO';
include $_root . '/includes/header.php';
?>
<div class="container-fluid px-3 px-md-4 py-3">

  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small mb-0">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/extforms/">Formularze zewnętrzne</a></li>
      <li class="breadcrumb-item active">Karty doradztwa ADNGO</li>
    </ol>
  </nav>

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 fw-bold mb-0">
      <i class="bi bi-clipboard2-pulse text-primary me-1" aria-hidden="true"></i>Karty doradztwa
      <span class="badge text-bg-light border fw-normal ms-1" style="font-size:.7em">ADNGO</span>
    </h1>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-outline-secondary btn-sm" href="<?= h($base) ?>/" target="_blank" rel="noopener">
        <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Formularz publiczny
      </a>
      <a class="btn btn-danger btn-sm <?= $rows ? '' : 'disabled' ?>"
         href="<?= h($base) ?>/export_pdf.php<?= $zip_qs ? '?' . h($zip_qs) : '' ?>"
         <?= $rows ? '' : 'aria-disabled="true" tabindex="-1"' ?>>
        <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Zbiorczy PDF
      </a>
      <a class="btn btn-success btn-sm <?= $rows ? '' : 'disabled' ?>"
         href="<?= h($base) ?>/zip.php<?= $zip_qs ? '?' . h($zip_qs) : '' ?>"
         <?= $rows ? '' : 'aria-disabled="true" tabindex="-1"' ?>>
        <i class="bi bi-file-earmark-zip me-1" aria-hidden="true"></i>Paczka ZIP
      </a>
    </div>
  </div>

  <!-- Statystyki -->
  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-secondary small text-uppercase">Kart w widoku</div>
        <div class="h3 fw-bold mb-0"><?= count($rows) ?></div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-secondary small text-uppercase">Suma godzin</div>
        <div class="h3 fw-bold mb-0"><?= h(cc_hours_label($sum_hours)) ?></div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-secondary small text-uppercase">Nowe</div>
        <div class="h3 fw-bold mb-0 text-primary"><?= $cnt_new ?></div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-secondary small text-uppercase">Zamknięte</div>
        <div class="h3 fw-bold mb-0 text-secondary"><?= count($rows) - $cnt_new ?></div>
      </div></div>
    </div>
  </div>

  <!-- Filtr dat -->
  <form method="get" action="<?= h($base) ?>/admin.php" class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
      <div class="row g-2 align-items-end">
        <div class="col-sm-4 col-md-3">
          <label for="f-from" class="form-label small fw-semibold mb-1">Data od</label>
          <input type="date" class="form-control form-control-sm" id="f-from" name="from" value="<?= h($from) ?>">
        </div>
        <div class="col-sm-4 col-md-3">
          <label for="f-to" class="form-label small fw-semibold mb-1">Data do</label>
          <input type="date" class="form-control form-control-sm" id="f-to" name="to" value="<?= h($to) ?>">
        </div>
        <div class="col-sm-4 col-md-6 d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtruj
          </button>
          <?php if ($from !== '' || $to !== ''): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= h($base) ?>/admin.php">Wyczyść</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </form>

  <!-- Suma godzin wg organizacji -->
  <?php if ($by_org): ?>
  <details class="card border-0 shadow-sm mb-3" <?= count($by_org) <= 12 ? 'open' : '' ?>>
    <summary class="card-body py-2 fw-semibold" style="cursor:pointer; list-style:revert">
      <i class="bi bi-building me-1" aria-hidden="true"></i>Suma godzin wg organizacji
      <span class="badge text-bg-light border ms-1"><?= count($by_org) ?></span>
    </summary>
    <div class="table-responsive border-top">
      <table class="table table-sm table-hover align-middle mb-0">
        <caption class="visually-hidden">Suma godzin doradztwa w rozbiciu na organizacje</caption>
        <thead class="table-light">
          <tr>
            <th scope="col">Organizacja</th>
            <th scope="col" class="text-end">Liczba kart</th>
            <th scope="col" class="text-end">Suma godzin</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($by_org as $o): ?>
          <tr>
            <td><?= h($o['org_name']) ?></td>
            <td class="text-end"><?= (int)$o['cards'] ?></td>
            <td class="text-end text-nowrap"><?= h(cc_hours_label((float)$o['hours'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-semibold">
          <tr>
            <td>Razem (<?= count($by_org) ?> org.)</td>
            <td class="text-end"><?= count($rows) ?></td>
            <td class="text-end text-nowrap"><?= h(cc_hours_label($sum_hours)) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </details>
  <?php endif; ?>

  <!-- Tabela kart -->
  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <caption class="visually-hidden">Lista kart konsultacyjnych posortowana od najnowszej</caption>
        <thead class="table-light">
          <tr>
            <th scope="col">Nr karty</th>
            <th scope="col">Data</th>
            <th scope="col">Organizacja</th>
            <th scope="col">Obszar</th>
            <th scope="col">Forma</th>
            <th scope="col" class="text-end">Godz.</th>
            <th scope="col">Konsultant</th>
            <th scope="col">Status</th>
            <th scope="col" class="text-end">Akcje</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="9" class="text-center text-secondary py-5">
              <i class="bi bi-inbox fs-3 d-block mb-2" aria-hidden="true"></i>
              Brak kart konsultacyjnych w wybranym zakresie.
            </td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td class="text-nowrap text-secondary small"><?= h(cc_card_number($r)) ?></td>
              <td class="text-nowrap"><?= h(date_pl($r['consultation_date'])) ?></td>
              <td>
                <?= h($r['org_name']) ?>
                <?php if (!empty($r['crm_org_id'])): ?>
                  <i class="bi bi-link-45deg text-primary" title="Powiązano z kontaktem CRM" aria-label="Powiązano z CRM"></i>
                <?php endif; ?>
              </td>
              <td><span class="badge text-bg-light border"><?= h(cc_label($areas, $r['area_type'])) ?></span></td>
              <td class="text-nowrap"><?= h(cc_label($forms, $r['form'])) ?></td>
              <td class="text-end text-nowrap"><?= h(rtrim(rtrim(number_format((float)$r['hours'], 1, ',', ' '), '0'), ',')) ?></td>
              <td><?= h($r['consultant'] ?: '—') ?></td>
              <td>
                <?php if ($can_edit): ?>
                  <form method="post" action="<?= h($base) ?>/admin.php" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="status">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="from" value="<?= h($from) ?>">
                    <input type="hidden" name="to" value="<?= h($to) ?>">
                    <input type="hidden" name="status" value="<?= $r['status'] === 'new' ? 'closed' : 'new' ?>">
                    <button type="submit"
                            class="btn btn-sm border-0 p-0 badge <?= $r['status'] === 'new' ? 'text-bg-primary' : 'text-bg-secondary' ?>"
                            title="Kliknij, aby zmienić status">
                      <?= h(cc_label($statuses, $r['status'])) ?>
                    </button>
                  </form>
                <?php else: ?>
                  <span class="badge <?= $r['status'] === 'new' ? 'text-bg-primary' : 'text-bg-secondary' ?>">
                    <?= h(cc_label($statuses, $r['status'])) ?>
                  </span>
                <?php endif; ?>
              </td>
              <td class="text-end text-nowrap">
                <a class="btn btn-outline-primary btn-sm" href="<?= h($base) ?>/pdf.php?id=<?= (int)$r['id'] ?>"
                   target="_blank" rel="noopener" title="Otwórz protokół do druku / PDF">
                  <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                  <span class="d-none d-xl-inline">PDF</span>
                </a>
                <?php if (is_admin()): ?>
                  <form method="post" action="<?= h($base) ?>/admin.php" class="d-inline"
                        onsubmit="return confirm('Usunąć kartę #<?= (int)$r['id'] ?>? Tej operacji nie można cofnąć.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="from" value="<?= h($from) ?>">
                    <input type="hidden" name="to" value="<?= h($to) ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Usuń kartę"
                            aria-label="Usuń kartę #<?= (int)$r['id'] ?>">
                      <i class="bi bi-trash" aria-hidden="true"></i>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
<?php include $_root . '/includes/footer.php'; ?>
