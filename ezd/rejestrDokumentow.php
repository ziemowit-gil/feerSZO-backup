<?php
/**
 * ezd/rejestrDokumentow.php — Rejestr dokumentów (dawniej docreg/list.php): wszystkie numery i kody nadane plikom wgranym do systemu (modules/doc_registry).
 * Wyszukiwarka (numer / kod), filtr po systemie, zakres dat nadania i stan (obowiązuje / nieaktualny — liczony na żywo ze źródła).
 * Linki: Otwórz (system źródłowy sprawdza uprawnienia), QR (strona z kodem), Etykieta (PDF; tylko numeracja losowa).
 * Dostęp: administrator i zarząd (ustawienie zarzad_user_ids).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/byli.php';   // is_zarzad()
require_once dirname(__DIR__) . '/modules/doc_registry/lib/DocRegistry.php';

require_login();
if (!is_zarzad()) { http_response_code(403); die('Rejestr dokumentów jest dostępny dla administratorów i zarządu.'); }

$PAGE_TITLE = 'Rejestr dokumentów';
$f = [
    'q'     => trim((string)($_GET['q'] ?? '')),
    'type'  => (string)($_GET['type'] ?? ''),
    'state' => (string)($_GET['state'] ?? ''),
    'from'  => (string)($_GET['from'] ?? ''),
    'to'    => (string)($_GET['to'] ?? ''),
    'paper' => (string)($_GET['paper'] ?? ''),
];
$page = max(1, (int)($_GET['p'] ?? 1));
$res  = DocRegistry::search($f, $page, 50);
$total_all = array_sum($res['counts']);
$base = rtrim(APP_URL, '/') . '/docreg/';
$qs = function (array $over = []) use ($f) {
    $q = array_filter(array_merge($f, ['p' => 1], $over), fn($v) => $v !== '' && $v !== null);
    if (($q['p'] ?? 1) == 1) unset($q['p']);
    return '?' . http_build_query($q);
};

include dirname(__DIR__) . '/includes/header.php';
?>
<style>
.dr-chip{display:inline-block;padding:.12rem .55rem;font-size:.78rem;font-weight:700;color:#fff}
.dr-valid{background:#006b00}.dr-void{background:#a20025}
.dr-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.85rem;white-space:nowrap}
.dr-tabs a{display:inline-block;padding:.3rem .7rem;border:1px solid #adb5bd;margin:0 .25rem .35rem 0;text-decoration:none;color:#1d1d1d;font-size:.88rem;background:#fff}
.dr-tabs a[aria-current="true"]{background:#0050ef;border-color:#0050ef;color:#fff;font-weight:600}
.dr-tabs a:focus-visible,.dr-act a:focus-visible{outline:3px solid #ff9800;outline-offset:2px}
.dr-void-row td{color:#6b7280}
</style>

<div class="container-fluid py-3" style="max-width:1300px">
  <h1 class="h4 mb-1"><i class="bi bi-qr-code me-2" aria-hidden="true"></i>Rejestr dokumentów</h1>
  <p class="text-muted small mb-3">Numery i kody QR nadane plikom wgranym do EZD, CRM, zadań, helpdesku, korespondencji i naboru. Razem: <b><?= (int)$total_all ?></b>. EODoK i KDOK mają własną numerację i nie są tu ujęte.</p>

  <nav class="dr-tabs" aria-label="Filtr po systemie">
    <a href="<?= h($qs(['type' => ''])) ?>"<?= $f['type'] === '' ? ' aria-current="true"' : '' ?>>Wszystkie (<?= (int)$total_all ?>)</a>
    <?php foreach (DocRegistry::SOURCES as $key => $def): $n = $res['counts'][$key] ?? 0; ?>
      <a href="<?= h($qs(['type' => $key])) ?>"<?= $f['type'] === $key ? ' aria-current="true"' : '' ?>><?= h($def[1]) ?> (<?= (int)$n ?>)</a>
    <?php endforeach; ?>
  </nav>

  <form method="get" class="row g-2 align-items-end mb-3" role="search" aria-label="Szukaj w rejestrze dokumentów">
    <?php if ($f['type'] !== ''): ?><input type="hidden" name="type" value="<?= h($f['type']) ?>"><?php endif; ?>
    <div class="col-12 col-md-4">
      <label for="dr-q" class="form-label small mb-1">Numer lub kod</label>
      <input id="dr-q" type="search" name="q" value="<?= h($f['q']) ?>" class="form-control form-control-sm" placeholder="np. HD/0012/2026 albo EZD/4839" autocomplete="off" spellcheck="false">
    </div>
    <div class="col-6 col-md-2">
      <label for="dr-state" class="form-label small mb-1">Stan</label>
      <select id="dr-state" name="state" class="form-select form-select-sm">
        <option value="">Wszystkie</option>
        <option value="valid"<?= $f['state'] === 'valid' ? ' selected' : '' ?>>Obowiązuje</option>
        <option value="void"<?= $f['state'] === 'void' ? ' selected' : '' ?>>Nieaktualny / usunięty</option>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label for="dr-paper" class="form-label small mb-1">Papier</label>
      <select id="dr-paper" name="paper" class="form-select form-select-sm">
        <option value="">Wszystkie</option>
        <?php foreach (['pending' => 'Do rozstrzygnięcia', 'todo' => 'Do zrobienia (etykieta / numer)', 'label' => 'Etykieta', 'manual' => 'Numer ręcznie', 'none' => 'Bez papieru'] as $pk => $pl): ?><option value="<?= h($pk) ?>"<?= $f['paper'] === $pk ? ' selected' : '' ?>><?= h($pl) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label for="dr-from" class="form-label small mb-1">Nadano od</label>
      <input id="dr-from" type="date" name="from" value="<?= h($f['from']) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-2">
      <label for="dr-to" class="form-label small mb-1">Nadano do</label>
      <input id="dr-to" type="date" name="to" value="<?= h($f['to']) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-2 d-flex gap-2">
      <button type="submit" class="btn btn-primary btn-sm">Szukaj</button>
      <a href="<?= h($base . 'list.php') ?>" class="btn btn-outline-secondary btn-sm">Wyczyść</a>
    </div>
  </form>

  <?php $pend = DocRegistry::paperPending(); ?>
  <?php if ($pend > 0): ?><div class="alert alert-warning py-2 small d-flex flex-wrap justify-content-between align-items-center gap-2" role="status">
    <span><strong><?= (int)$pend ?></strong> dokumentów czeka na decyzję: czy mają odpowiednik papierowy (etykieta albo numer ręcznie).</span>
    <a class="btn btn-sm btn-outline-dark" href="<?= h($qs(['paper' => 'pending'])) ?>">Pokaż do rozstrzygnięcia</a></div><?php endif; ?>
  <form id="sheetForm" method="get" action="<?= h($base) ?>" target="_blank" class="d-flex flex-wrap align-items-end gap-2 mb-2">
    <div><label class="form-label small mb-1" for="dr-start">Arkusz etykiet A4 (3×8): pierwsze wolne pole</label><input id="dr-start" class="form-control form-control-sm" type="number" name="start" min="1" max="24" value="1" style="width:6rem"></div>
    <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-printer me-1"></i>Drukuj etykiety zaznaczonych</button>
    <span class="small text-muted">Zaznacz pozycje w kolumnie „Etykieta”.</span>
  </form>
  <p class="small text-muted mb-2" role="status">Znaleziono: <b><?= (int)$res['total'] ?></b><?= $f['state'] !== '' ? ' (stan liczony na żywo; przeszukano do 5000 najnowszych pasujących)' : '' ?></p>

  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle" style="font-size:.88rem">
      <caption class="visually-hidden">Numery i kody dokumentów, od najnowszych</caption>
      <thead class="table-light">
        <tr>
          <th scope="col"><span class="visually-hidden">Etykieta</span></th>
          <th scope="col">Numer</th>
          <th scope="col">Kod (Code128 / QR)</th>
          <th scope="col">Rodzaj</th>
          <th scope="col">Plik</th>
          <th scope="col">Nadano</th>
          <th scope="col">Stan</th>
          <th scope="col">Papier</th>
          <th scope="col" class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$res['rows']): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">Brak pozycji dla wybranych kryteriów.</td></tr>
      <?php endif; ?>
      <?php foreach ($res['rows'] as $r): $code = (string)$r['code']; $void = $r['state'] === 'void'; ?>
        <tr class="<?= $void ? 'dr-void-row' : '' ?>">
          <td><?php if (!$r['seq']): ?><input type="checkbox" form="sheetForm" name="sheet[]" value="<?= h($code) ?>" aria-label="Etykieta: <?= h($r['doc_number']) ?>"><?php endif; ?></td>
          <td class="dr-code fw-semibold"><?= h($r['doc_number']) ?></td>
          <td class="dr-code"><?= $r['seq'] ? '<span class="text-muted" title="Numer jest zarazem kodem w QR">= numer</span>' : h($code) ?></td>
          <td><?= h($r['label']) ?></td>
          <td class="text-truncate" style="max-width:260px" title="<?= h($r['file_name']) ?>"><?= $r['file_name'] !== '' ? h($r['file_name']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime((string)$r['created_at']))) ?></td>
          <td><span class="dr-chip <?= $void ? 'dr-void' : 'dr-valid' ?>"><?= $void ? 'Nieaktualny' : 'Obowiązuje' ?></span><?php if ($r['note'] !== ''): ?><div class="small text-muted"><?= h(preg_replace('~\s*Skrót SHA-256.*$~u', '', $r['note'])) ?></div><?php endif; ?></td>
          <td class="small"><?php $pm = (string)($r['paper_mode'] ?? ''); if ($r['seq'] || str_starts_with((string)$r['source_type'], 'contract_') || in_array($r['source_type'], ['print_doc', 'gen_doc'], true)): ?><span class="text-muted">—</span>
            <?php elseif ($pm === ''): ?><a href="<?= h($base . '?view=' . rawurlencode($code)) ?>" target="_blank" rel="noopener" class="text-warning-emphasis">do rozstrzygnięcia</a>
            <?php else: ?><?= h(['none' => 'bez papieru', 'label' => 'etykieta', 'manual' => 'numer ręcznie'][$pm] ?? $pm) ?><?php if ($pm !== 'none'): ?> <?= !empty($r['paper_done_at']) ? '<span class="text-success" title="Zrobione ' . h(date('d.m.Y H:i', strtotime((string)$r['paper_done_at']))) . '">✓</span>' : '<span class="text-danger" title="Do zrobienia">●</span>' ?><?php endif; ?><?php endif; ?></td>
          <td class="text-end text-nowrap dr-act">
            <?php if (!$void): ?><a class="btn btn-sm btn-outline-primary py-0 px-2" href="<?= h(DocRegistry::openByCodeUrl($code)) ?>" target="_blank" rel="noopener" aria-label="Otwórz dokument <?= h($r['doc_number']) ?>">Otwórz</a><?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary py-0 px-2" href="<?= h($base . '?view=' . rawurlencode($code)) ?>" target="_blank" rel="noopener" aria-label="Kod QR dokumentu <?= h($r['doc_number']) ?>">QR</a>
            <?php if (!$r['seq']): ?><a class="btn btn-sm btn-outline-secondary py-0 px-2" href="<?= h($base . '?label=' . rawurlencode($code)) ?>" target="_blank" rel="noopener" aria-label="Etykieta do druku, dokument <?= h($r['doc_number']) ?>">Etykieta</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($res['pages'] > 1): ?>
  <nav aria-label="Strony wyników" class="mt-2">
    <ul class="pagination pagination-sm mb-0 flex-wrap">
      <?php for ($i = 1; $i <= $res['pages']; $i++): if ($i !== 1 && $i !== $res['pages'] && abs($i - $res['page']) > 3) { if (abs($i - $res['page']) === 4) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; continue; } ?>
        <li class="page-item<?= $i === $res['page'] ? ' active' : '' ?>"><a class="page-link" href="<?= h($qs(['p' => $i])) ?>"<?= $i === $res['page'] ? ' aria-current="page"' : '' ?>><?= $i ?></a></li>
      <?php endfor; ?>
    </ul>
  </nav>
  <?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
