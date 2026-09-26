<?php
/**
 * modules/gdpr_clauses/acceptances.php — rejestr akceptacji klauzul RODO.
 *
 * Dowód dla RODO art. 7 ust. 1 / art. 5 ust. 2: kto, kiedy, skąd i którą
 * wersję klauzuli zaakceptował. ?id=N — pojedynczy wpis z DOKŁADNYM tekstem
 * (migawka po podstawieniu zmiennych), ?export=csv — eksport wg filtrów.
 * Zawiera dane osobowe — dostęp jak do panelu klauzul (admin/editor).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/gdpr_clauses.php';

require_role('admin', 'editor');
$svc  = new GdprClauseService();
$base = APP_URL . '/modules/gdpr_clauses/acceptances.php';

$f = [
    'clause_id' => (int)($_GET['clause_id'] ?? 0),
    'context'   => (string)($_GET['context'] ?? ''),
    'q'         => trim((string)($_GET['q'] ?? '')),
    'from'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '',
    'to'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '',
];

// ── Eksport CSV ────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="akceptacje_klauzul_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Data', 'Klauzula', 'Slug', 'Język', 'Wersja', 'Kontekst', 'Rekord', 'Imię i nazwisko', 'E-mail', 'IP', 'Hash tekstu (SHA-256)'], ';');
    $off = 0;
    do {
        $rows = $svc->acceptances($f, 1000, $off);
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'], $r['accepted_at'], $r['tytul'], $r['slug'], $r['lang'], $r['version'],
                           GdprClauseService::CONTEXTS[$r['context']] ?? $r['context'],
                           $r['ref_type'] ? $r['ref_type'] . '#' . $r['ref_id'] : '',
                           $r['subject_name'], $r['subject_email'], $r['ip'], $r['snapshot_hash']], ';');
        }
        $off += 1000;
    } while (count($rows) === 1000);
    exit;
}

// ── Pojedynczy wpis ────────────────────────────────────────────────────────
$one = !empty($_GET['id']) ? $svc->acceptanceById((int)$_GET['id']) : null;

// PDF dowodowy: dokładny tekst + metryka akceptacji (do przekazania osobie lub UODO).
if ($one && !empty($_GET['pdf']) && $one['html'] !== null) {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $box = '<strong>Potwierdzenie akceptacji klauzuli nr ' . (int)$one['id'] . '</strong><br>'
         . 'Data i godzina: ' . $e(date('d.m.Y H:i:s', strtotime($one['accepted_at']))) . '<br>'
         . 'Osoba: ' . $e(trim($one['subject_name'] . ' ' . ($one['subject_email'] ? '<' . $one['subject_email'] . '>' : ''))) . '<br>'
         . 'Źródło: ' . $e(GdprClauseService::CONTEXTS[$one['context']] ?? $one['context'])
         . ($one['ref_type'] ? ' (' . $e($one['ref_type'] . '#' . $one['ref_id']) . ')' : '') . '<br>'
         . 'Klauzula: ' . $e($one['slug']) . ' · ' . $e(strtoupper($one['lang'])) . ' · wersja ' . (int)$one['version'] . '<br>'
         . 'IP: ' . $e($one['ip'] ?: '—') . '<br>'
         . 'SHA-256 tekstu: ' . $e($one['snapshot_hash']);
    $org = $svc->variables()['company_name'] ?? '';
    gdpr_clauses_send_pdf($one['snap_tytul'], $one['html'], [
        'lang' => $one['lang'], 'org' => $org, 'box' => $box,
        'footer' => $org . ' · rejestr akceptacji klauzul · wygenerowano ' . date('d.m.Y H:i'),
    ], 'akceptacja_' . (int)$one['id']);
}

$clauses = $svc->listClauses();
$total   = $one ? 0 : $svc->acceptanceCount($f);
$page    = max(1, (int)($_GET['p'] ?? 1));
$per     = 50;
$rows    = $one ? [] : $svc->acceptances($f, $per, ($page - 1) * $per);
$qs      = http_build_query(array_filter($f));

$PAGE_TITLE = 'Klauzule RODO — rejestr akceptacji';
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<style>
  .gdpr-snap { font-size: .95rem; line-height: 1.6; }
  .gdpr-snap h2 { font-size: 1.05rem; font-weight: 600; margin: 1.1rem 0 .4rem; }
  .gdpr-snap ul, .gdpr-snap ol { padding-left: 1.3rem; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-person-check text-primary"></i> Rejestr akceptacji klauzul</h4>
  <div class="d-flex gap-2">
    <?php if (!$one): ?>
      <a href="<?= h($base . '?' . $qs . ($qs ? '&' : '') . 'export=csv') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv me-1"></i>Eksport CSV</a>
    <?php else: ?>
      <a href="<?= h($base . '?id=' . (int)$one['id'] . '&pdf=1') ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-filetype-pdf me-1"></i>PDF potwierdzenia</a>
      <a href="<?= h($base) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Rejestr</a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/modules/gdpr_clauses/index.php" class="btn btn-sm btn-outline-secondary">Klauzule</a>
  </div>
</div>

<?php if ($one): ?>
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold small">Akceptacja #<?= (int)$one['id'] ?></div>
        <dl class="card-body small mb-0">
          <dt>Data i godzina</dt><dd><?= h(date('d.m.Y H:i:s', strtotime($one['accepted_at']))) ?></dd>
          <dt>Osoba</dt><dd><?= h($one['subject_name'] ?: '—') ?><br><?= h($one['subject_email'] ?: '') ?></dd>
          <dt>Kontekst</dt><dd><?= h(GdprClauseService::CONTEXTS[$one['context']] ?? $one['context']) ?>
            <?= $one['ref_type'] ? '<br><code>' . h($one['ref_type'] . '#' . $one['ref_id']) . '</code>' : '' ?></dd>
          <dt>Klauzula</dt><dd><code><?= h($one['slug']) ?></code> · <?= h(strtoupper($one['lang'])) ?> · <strong>v<?= (int)$one['version'] ?></strong></dd>
          <dt>IP / przeglądarka</dt><dd><?= h($one['ip'] ?: '—') ?><br><span class="text-muted"><?= h($one['user_agent']) ?></span></dd>
          <dt>Suma kontrolna tekstu (SHA-256)</dt><dd><code class="small text-break"><?= h($one['snapshot_hash']) ?></code></dd>
        </dl>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card shadow-sm">
        <div class="card-header bg-white small"><strong>Dokładny tekst zaakceptowany przez osobę</strong> — z wartościami zmiennych z chwili akceptacji</div>
        <div class="card-body gdpr-snap">
          <?php if ($one['html'] !== null): ?>
            <h1 class="h5"><?= h($one['snap_tytul']) ?></h1>
            <?= $one['html'] /* migawka z GdprClauseService::render() — już escapowana */ ?>
          <?php else: ?>
            <div class="alert alert-danger mb-0">Brak migawki tekstu dla tego wpisu.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php else: ?>

<form method="get" class="card shadow-sm mb-3">
  <div class="card-body row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label small mb-1" for="f-cl">Klauzula</label>
      <select id="f-cl" name="clause_id" class="form-select form-select-sm">
        <option value="">— wszystkie —</option>
        <?php foreach ($clauses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $f['clause_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['tytul']) ?> (<?= h($c['lang']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1" for="f-ctx">Skąd</label>
      <select id="f-ctx" name="context" class="form-select form-select-sm">
        <option value="">— wszystkie —</option>
        <?php foreach (GdprClauseService::CONTEXTS as $k => $l): ?>
          <option value="<?= h($k) ?>" <?= $f['context'] === $k ? 'selected' : '' ?>><?= h($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-1" for="f-q">Osoba (imię lub e-mail)</label>
      <input id="f-q" type="search" name="q" value="<?= h($f['q']) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1" for="f-from">Od</label>
      <input id="f-from" type="date" name="from" value="<?= h($f['from']) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1" for="f-to">Do</label>
      <div class="input-group input-group-sm">
        <input id="f-to" type="date" name="to" value="<?= h($f['to']) ?>" class="form-control">
        <button class="btn btn-primary" title="Filtruj"><i class="bi bi-funnel"></i></button>
      </div>
    </div>
  </div>
</form>

<div class="card shadow-sm">
  <?php if (!$rows): ?>
    <div class="card-body text-center text-muted py-5">
      <i class="bi bi-person-check fs-1 d-block mb-2 opacity-25"></i>
      Brak zarejestrowanych akceptacji<?= $qs ? ' dla tych filtrów' : '' ?>.
      <div class="small mt-1">Akceptacje zapisują się, gdy formularz (oferta wolontariatu, wydarzenie, CRM, onboarding, API) używa klauzuli z tego modułu.</div>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Data</th><th>Osoba</th><th>Klauzula</th><th>Skąd</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="small text-nowrap"><?= h(date('d.m.Y H:i', strtotime($r['accepted_at']))) ?></td>
            <td class="small"><?= h($r['subject_name'] ?: '—') ?><div class="text-muted"><?= h($r['subject_email']) ?></div></td>
            <td class="small"><?= h($r['tytul'] ?? $r['slug']) ?> <span class="badge bg-light text-dark border"><?= h(strtoupper($r['lang'])) ?> v<?= (int)$r['version'] ?></span></td>
            <td class="small"><?= h(GdprClauseService::CONTEXTS[$r['context']] ?? $r['context']) ?></td>
            <td class="text-end"><a href="<?= h($base . '?id=' . (int)$r['id']) ?>" class="btn btn-sm btn-outline-primary" title="Pokaż zaakceptowany tekst"><i class="bi bi-eye"></i></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center small">
      <span class="text-muted">Razem: <?= $total ?></span>
      <?php $pages = (int)ceil($total / $per); if ($pages > 1): ?>
        <nav aria-label="Strony"><ul class="pagination pagination-sm mb-0">
          <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= h($base . '?' . $qs . ($qs ? '&' : '') . 'p=' . $i) ?>"><?= $i ?></a></li>
          <?php endfor; ?>
        </ul></nav>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
