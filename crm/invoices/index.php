<?php
/**
 * crm/invoices/index.php — Rejestr faktur.
 *
 * Wspólny rejestr dla wszystkich źródeł: ofert CRM, rozliczeń TI i faktur
 * wystawianych ręcznie. Kolumna „źródło" mówi, skąd dokument się wziął — bez
 * niej rejestr byłby nie do prześledzenia po kilku miesiącach.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/invoices.php';

require_login();
require_module_enabled('invoices_enabled', 'Moduł Faktury');
invoices_migrate();

$PAGE_TITLE = 'Faktury';
$can_write  = is_admin() || can_write('crm');

$f = [
    'q'      => trim($_GET['q'] ?? ''),
    'status' => (string)($_GET['status'] ?? ''),
    'source' => (string)($_GET['source'] ?? ''),
    'from'   => trim($_GET['from'] ?? ''),
    'to'     => trim($_GET['to']   ?? ''),
];
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 25;

$res   = invoice_list($f, $per, ($page - 1) * $per);
$rows  = $res['rows'];
$total = $res['total'];
$stats = invoice_stats();
$pages = max(1, (int)ceil($total / $per));

$qs = fn(array $over = []) => '?' . http_build_query(array_filter(array_merge($f, $over), fn($v) => $v !== '' && $v !== null));

include dirname(__DIR__) . '/includes/header_crm.php';
?>
<div class="container-fluid px-0">

  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h1 class="h5 mb-0 me-auto"><i class="bi bi-receipt me-2"></i>Faktury
      <span class="badge bg-secondary"><?= (int)$total ?></span>
    </h1>
    <?php if (!invoices_api_ready()): ?>
    <span class="badge bg-warning text-dark" title="Bez konfiguracji API można tworzyć szkice, ale nie wystawiać">
      <i class="bi bi-exclamation-triangle-fill me-1"></i>Fakturownia nieskonfigurowana
    </span>
    <?php endif; ?>
    <?php if ($can_write): ?>
    <a href="<?= APP_URL ?>/crm/invoices/form.php" class="btn btn-sm btn-crm-primary">
      <i class="bi bi-plus-lg me-1"></i>Nowa faktura
    </a>
    <?php endif; ?>
  </div>

  <!-- Podsumowanie -->
  <div class="row g-2 mb-3">
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted" style="font-size:.75rem">Wartość brutto</div>
        <div class="fw-bold"><?= number_format($stats['gross'], 2, ',', ' ') ?> zł</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted" style="font-size:.75rem">Nieopłacone</div>
        <div class="fw-bold text-warning-emphasis"><?= number_format($stats['unpaid'], 2, ',', ' ') ?> zł</div>
      </div></div>
    </div>
    <?php foreach (['szkic', 'zaplacona'] as $st): ?>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted" style="font-size:.75rem"><?= h(INVOICE_STATUSES[$st]['label']) ?></div>
        <div class="fw-bold"><?= (int)($stats['by_status'][$st]['count'] ?? 0) ?></div>
      </div></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Filtry -->
  <form method="get" class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
      <div class="row g-2 align-items-end">
        <div class="col-md-4">
          <label class="form-label small mb-1" for="fq">Szukaj</label>
          <input type="search" class="form-control form-control-sm" id="fq" name="q"
                 value="<?= h($f['q']) ?>" placeholder="Numer, nabywca, NIP…">
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1" for="fst">Status</label>
          <select class="form-select form-select-sm" id="fst" name="status">
            <option value="">— wszystkie —</option>
            <?php foreach (INVOICE_STATUSES as $k => $v): ?>
            <option value="<?= h($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= h($v['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1" for="fsr">Źródło</label>
          <select class="form-select form-select-sm" id="fsr" name="source">
            <option value="">— wszystkie —</option>
            <?php foreach (INVOICE_SOURCES as $k => $lbl): ?>
            <option value="<?= h($k) ?>"<?= $f['source'] === $k ? ' selected' : '' ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1" for="ffrom">Od</label>
          <input type="date" class="form-control form-control-sm" id="ffrom" name="from" value="<?= h($f['from']) ?>">
        </div>
        <div class="col-md-2 d-flex gap-1">
          <div class="flex-grow-1">
            <label class="form-label small mb-1" for="fto">Do</label>
            <input type="date" class="form-control form-control-sm" id="fto" name="to" value="<?= h($f['to']) ?>">
          </div>
          <button class="btn btn-sm btn-crm-outline align-self-end" type="submit" aria-label="Filtruj">
            <i class="bi bi-funnel"></i>
          </button>
        </div>
      </div>
    </div>
  </form>

  <!-- Lista -->
  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <caption class="visually-hidden">Rejestr faktur</caption>
        <thead class="table-light">
          <tr>
            <th scope="col">Numer</th>
            <th scope="col">Nabywca</th>
            <th scope="col">Źródło</th>
            <th scope="col" class="text-end">Brutto</th>
            <th scope="col">Wystawiona</th>
            <th scope="col">Termin</th>
            <th scope="col">Status</th>
            <th scope="col" class="text-end">Akcje</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">
            Brak faktur<?= array_filter($f) ? ' dla wybranych filtrów' : '' ?>.
          </td></tr>
        <?php else: foreach ($rows as $r):
          $st = INVOICE_STATUSES[$r['status']] ?? ['label' => $r['status'], 'color' => '#6B7280'];
          $overdue = $r['status'] === 'wystawiona' && $r['payment_to'] && $r['payment_to'] < date('Y-m-d');
        ?>
          <tr>
            <td>
              <a href="<?= APP_URL ?>/crm/invoices/view.php?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none">
                <?= h($r['number'] ?: '(szkic #' . (int)$r['id'] . ')') ?>
              </a>
              <?php if (!empty($r['is_test'])): ?>
              <span class="badge bg-danger ms-1" style="font-size:.6rem" title="Faktura testowa">TEST</span>
              <?php endif; ?>
            </td>
            <td>
              <?= h($r['buyer_name']) ?>
              <?php if (!empty($r['contact_id'])): ?>
              <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$r['contact_id'] ?>"
                 class="text-muted ms-1" title="Kartoteka kontaktu"><i class="bi bi-box-arrow-up-right"></i></a>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-light text-dark border"><?= h(INVOICE_SOURCES[$r['source']] ?? $r['source']) ?></span></td>
            <td class="text-end"><?= number_format((float)$r['total_gross'], 2, ',', ' ') ?></td>
            <td class="text-nowrap"><?= h($r['issue_date'] ? date('d.m.Y', strtotime($r['issue_date'])) : '—') ?></td>
            <td class="text-nowrap<?= $overdue ? ' text-danger fw-semibold' : '' ?>">
              <?= h($r['payment_to'] ? date('d.m.Y', strtotime($r['payment_to'])) : '—') ?>
              <?php if ($overdue): ?><i class="bi bi-exclamation-circle-fill" title="Po terminie"></i><?php endif; ?>
            </td>
            <td><span class="badge" style="background:<?= h($st['color']) ?>"><?= h($st['label']) ?></span></td>
            <td class="text-end text-nowrap">
              <?php if (!empty($r['pdf_path'])): ?>
              <a class="btn btn-sm btn-outline-secondary py-0 px-1"
                 href="<?= APP_URL ?>/crm/invoices/pdf.php?id=<?= (int)$r['id'] ?>"
                 aria-label="PDF faktury <?= h($r['number']) ?>" title="PDF"><i class="bi bi-file-earmark-pdf"></i></a>
              <?php endif; ?>
              <?php if (!empty($r['fakturownia_url'])): ?>
              <a class="btn btn-sm btn-outline-secondary py-0 px-1" target="_blank" rel="noopener"
                 href="<?= h($r['fakturownia_url']) ?>" title="Otwórz w Fakturowni"><i class="bi bi-box-arrow-up-right"></i></a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($pages > 1): ?>
  <nav class="mt-3" aria-label="Strony rejestru">
    <ul class="pagination pagination-sm mb-0">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
      <li class="page-item<?= $i === $page ? ' active' : '' ?>">
        <a class="page-link" href="<?= h($qs(['page' => $i])) ?>"><?= $i ?></a>
      </li>
      <?php endfor; ?>
    </ul>
  </nav>
  <?php endif; ?>

</div>
<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
