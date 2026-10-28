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
require_once dirname(__DIR__) . '/includes/ezd.php';
require_once dirname(__DIR__) . '/includes/ezd_rpwy.php';
require_once dirname(__DIR__) . '/includes/postivo.php';

require_login();
if (!is_zarzad()) { http_response_code(403); die('Rejestr dokumentów jest dostępny dla administratorów i zarządu.'); }

$PAGE_TITLE = 'Rejestr dokumentów';

// Zbiorcze usuwanie kodów z rejestru (zaznaczone w liście): tylko kody ITSM przed nadaniem i wpisy nieaktualne.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_codes') {
    csrf_check();
    $back = rtrim(APP_URL, '/') . '/ezd/rejestrDokumentow.php' . (($_POST['back'] ?? '') !== '' ? '?' . ltrim((string)$_POST['back'], '?') : '');
    if (!is_admin()) { flash_set('error', 'Kody z rejestru może usuwać tylko administrator.'); header('Location: ' . $back); exit; }
    if (($_POST['mode'] ?? '') === 'range') {
        $rg = DocRegistry::codesInRange((string)($_POST['from_code'] ?? ''), (string)($_POST['to_code'] ?? ''));
        if ($rg['error'] !== '') { flash_set('error', $rg['error']); header('Location: ' . $back); exit; }
        $_POST['sheet'] = $rg['codes'];
    }
    $codes = array_values(array_unique(array_filter(array_map('trim', (array)($_POST['sheet'] ?? [])))));
    if (!$codes) { flash_set('error', 'Zaznacz kody do usunięcia w kolumnie „Etykieta”.'); header('Location: ' . $back); exit; }
    $ok = 0; $refused = [];
    foreach (array_slice($codes, 0, 500) as $c) { $why = DocRegistry::deleteCode($c); if ($why === '') $ok++; else $refused[$why] = ($refused[$why] ?? 0) + 1; }
    $msg = 'Usunięto kodów: ' . $ok . '.';
    foreach ($refused as $why => $n) $msg .= ' Pominięto ' . $n . ' — ' . $why . '.';
    flash_set($ok > 0 ? 'success' : 'error', $msg);
    header('Location: ' . $back); exit;
}

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
// Status kodu z puli ITSM: partnerskie = arkusz wydrukowany i nadany przez Postivo (nadana/doręczona); inaczej wygenerowany / w kolejce
$itsmStatus = function (?string $rpwyStatus, bool $printed = false): array {
    if (in_array($rpwyStatus, ['nadana', 'doreczona'], true)) return ['partnerskie', 'dr-valid'];
    if ($rpwyStatus === 'postivo_queued') return ['w kolejce Postivo', 'dr-wait'];
    if ($rpwyStatus === 'zwrocona') return ['zwrócone przez Postivo', 'dr-void'];
    return $printed ? ['wydrukowane samodzielnie', 'dr-valid'] : ['wygenerowane', 'dr-new'];
};
$itsmById = [];
foreach ($res['rows'] as $rr) if ($rr['source_type'] === 'reserved_code') $itsmById[(int)$rr['source_id']] = null;
if ($itsmById) {
    $in = implode(',', array_fill(0, count($itsmById), '?'));
    foreach (db_all("SELECT d.id, r.status, g.paper_done_at FROM doc_reserved d LEFT JOIN ezd_rpwy r ON r.id = d.attached_rpwy LEFT JOIN doc_registry g ON g.source_type = 'reserved_code' AND g.source_id = d.id WHERE d.id IN ($in)", array_keys($itsmById)) as $x) $itsmById[(int)$x['id']] = $x['status'] ?? ((string)($x['paper_done_at'] ?? '') !== '' ? '_printed' : null);
}
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
.dr-valid{background:#006b00}.dr-void{background:#a20025}.dr-wait{background:#9a4000}.dr-new{background:#525252}
.dr-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.85rem;white-space:nowrap}
.dr-void-row td{color:#6b7280}
/* układ strony — własny flex zamiast siatki Bootstrapa (skórka EZD dorysowywała ramki wokół kolumn) */
.dr-page .dr-head{display:flex;flex-wrap:wrap;align-items:baseline;gap:.25rem 1rem;margin-bottom:1rem}
.dr-page .dr-head h1{margin:0}
.dr-page .dr-head p{margin:0;color:#4b5563;font-size:.86rem}
.dr-page .dr-tabs{display:flex;flex-wrap:wrap;gap:.35rem;margin-bottom:.35rem}
html body .dr-page .dr-tabs a{display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .75rem;border:1px solid #9aa3ad;background:#fff;color:#1f2937;text-decoration:none;font-size:.86rem;line-height:1.3}
html body .dr-page .dr-tabs a:hover{background:#eef3f7}
html body .dr-page .dr-tabs a b{font-weight:700;color:#4b5563}
html body .dr-page .dr-tabs a[aria-current="true"]{background:#0f6fa8;border-color:#0f6fa8;color:#fff;font-weight:600}
html body .dr-page .dr-tabs a[aria-current="true"] b{color:#fff}
.dr-page .dr-more{margin:0 0 1rem;font-size:.84rem}
.dr-page .dr-more summary{cursor:pointer;color:#0f6fa8;padding:.15rem 0;width:max-content}
.dr-page .dr-more .dr-tabs{margin-top:.4rem}
.dr-page .dr-filters{display:flex;flex-wrap:wrap;align-items:flex-end;gap:.6rem .9rem;padding:.8rem 1rem;margin:0 0 1rem;background:#f3f4f6;border:1px solid #d1d5db}
.dr-page .dr-filters .f{display:flex;flex-direction:column;gap:.2rem;min-width:9rem}
.dr-page .dr-filters .f.grow{flex:1 1 16rem}
.dr-page .dr-filters label{font-size:.78rem;font-weight:600;color:#374151;margin:0}
.dr-page .dr-filters .btns{display:flex;gap:.5rem;margin-left:auto}
.dr-page .dr-tools{margin:0 0 1rem;border:1px solid #d1d5db;border-left:4px solid #0f6fa8;background:#f4f8fb}
.dr-page .dr-tools>summary{cursor:pointer;padding:.6rem 1rem;font-weight:600;list-style:none;display:flex;align-items:center;gap:.5rem}
.dr-page .dr-tools>summary::-webkit-details-marker{display:none}
.dr-page .dr-tools>summary::after{content:"▾";margin-left:auto;color:#4b5563}
.dr-page .dr-tools[open]>summary::after{content:"▴"}
.dr-page .dr-tools .dr-tools-body{padding:0 1rem .9rem}
.dr-page .dr-note{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem;padding:.55rem 1rem;margin:0 0 .8rem;background:#fff7e6;border-left:4px solid #b45309;font-size:.86rem}
.dr-page .dr-sheet{display:flex;flex-wrap:wrap;align-items:flex-end;gap:.5rem .75rem;margin:0 0 .6rem}
.dr-page .dr-sheet label{font-size:.78rem;font-weight:600;display:block;margin-bottom:.15rem}
.dr-page :focus-visible{outline:3px solid #d90429;outline-offset:2px}
</style>

<div class="container-fluid py-3 dr-page" style="max-width:1300px">
  <div class="dr-head">
    <h1 class="h4"><i class="bi bi-qr-code me-2" aria-hidden="true"></i>Rejestr dokumentów</h1>
    <p>Numery i kody QR plików z EZD, CRM, zadań, helpdesku, korespondencji i naboru · razem <b><?= (int)$total_all ?></b> · EODoK i KDOK mają własną numerację.</p>
  </div>

  <?php
    $kinds_on = []; $kinds_off = [];
    foreach (DocRegistry::SOURCES as $key => $def) { $n = (int)($res['counts'][$key] ?? 0); if ($n > 0 || $f['type'] === $key) $kinds_on[$key] = [$def[1], $n]; else $kinds_off[$key] = [$def[1], 0]; }
    $tabLink = fn($key, $label, $n) => '<a href="' . h($qs(['type' => $key])) . '"' . ($f['type'] === $key ? ' aria-current="true"' : '') . '>' . h($label) . ' <b>' . (int)$n . '</b></a>';
  ?>
  <nav class="dr-tabs" aria-label="Filtr po rodzaju dokumentu">
    <?= $tabLink('', 'Wszystkie', $total_all) ?>
    <?php foreach ($kinds_on as $key => [$lbl, $n]) echo $tabLink($key, $lbl, $n); ?>
  </nav>
  <?php if ($kinds_off): ?>
  <details class="dr-more"><summary>Rodzaje bez pozycji (<?= count($kinds_off) ?>)</summary>
    <nav class="dr-tabs" aria-label="Rodzaje bez pozycji"><?php foreach ($kinds_off as $key => [$lbl, $n]) echo $tabLink($key, $lbl, 0); ?></nav>
  </details>
  <?php else: ?><div class="mb-3"></div><?php endif; ?>

  <form method="get" class="dr-filters" role="search" aria-label="Szukaj w rejestrze dokumentów">
    <?php if ($f['type'] !== ''): ?><input type="hidden" name="type" value="<?= h($f['type']) ?>"><?php endif; ?>
    <div class="f grow"><label for="dr-q">Numer lub kod</label>
      <input id="dr-q" type="search" name="q" value="<?= h($f['q']) ?>" class="form-control form-control-sm" placeholder="np. HD/0012/2026 albo EZD/4839" autocomplete="off" spellcheck="false"></div>
    <div class="f"><label for="dr-state">Stan</label>
      <select id="dr-state" name="state" class="form-select form-select-sm">
        <option value="">Wszystkie</option>
        <option value="valid"<?= $f['state'] === 'valid' ? ' selected' : '' ?>>Obowiązuje</option>
        <option value="void"<?= $f['state'] === 'void' ? ' selected' : '' ?>>Nieaktualny / usunięty</option>
      </select></div>
    <div class="f"><label for="dr-paper">Papier</label>
      <select id="dr-paper" name="paper" class="form-select form-select-sm">
        <option value="">Wszystkie</option>
        <?php foreach (['pending' => 'Do rozstrzygnięcia', 'todo' => 'Do zrobienia (etykieta / numer)', 'label' => 'Etykieta', 'manual' => 'Numer ręcznie', 'none' => 'Bez papieru'] as $pk => $pl): ?><option value="<?= h($pk) ?>"<?= $f['paper'] === $pk ? ' selected' : '' ?>><?= h($pl) ?></option><?php endforeach; ?>
      </select></div>
    <div class="f"><label for="dr-from">Nadano od</label><input id="dr-from" type="date" name="from" value="<?= h($f['from']) ?>" class="form-control form-control-sm"></div>
    <div class="f"><label for="dr-to">Nadano do</label><input id="dr-to" type="date" name="to" value="<?= h($f['to']) ?>" class="form-control form-control-sm"></div>
    <div class="btns">
      <button type="submit" class="btn btn-primary btn-sm">Szukaj</button>
      <a href="<?= h($base . 'list.php') ?>" class="btn btn-outline-secondary btn-sm">Wyczyść</a>
    </div>
  </form>

  <?php if (is_admin()): ?>
  <p class="small mb-3"><a href="<?= APP_URL ?>/ezd/kodyPartnerskie.php"><i class="bi bi-qr-code me-1" aria-hidden="true"></i>Kody partnerskie (ITSM) — generowanie, wydruk i nadanie przez Postivo</a></p>
  <?php endif; ?>
  <?php $pend = DocRegistry::paperPending(); ?>
  <?php if ($pend > 0): ?><div class="dr-note" role="status">
    <span><strong><?= (int)$pend ?></strong> dokumentów czeka na decyzję: czy mają odpowiednik papierowy (etykieta albo numer ręcznie).</span>
    <a class="btn btn-sm btn-outline-dark" href="<?= h($qs(['paper' => 'pending'])) ?>">Pokaż do rozstrzygnięcia</a></div><?php endif; ?>
  <form id="sheetForm" method="get" action="<?= h($base) ?>" target="_blank" class="dr-sheet">
    <div><label class="form-label small mb-1" for="dr-start">Arkusz etykiet A4 (3×8): pierwsze wolne pole</label><input id="dr-start" class="form-control form-control-sm" type="number" name="start" min="1" max="24" value="1" style="width:6rem"></div>
    <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-printer me-1"></i>Drukuj etykiety zaznaczonych</button>
    <?php if (is_admin()): ?>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="back" value="<?= h((string)($_SERVER['QUERY_STRING'] ?? '')) ?>">
    <button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="delete_codes" formmethod="post" formaction="<?= h(rtrim(APP_URL, '/') . '/ezd/rejestrDokumentow.php') ?>" formtarget="_self"
            onclick="var n=document.querySelectorAll('input[name=&quot;sheet[]&quot;]:checked').length; if(!n){alert('Zaznacz kody do usunięcia.');return false;} return confirm('Usunąć zaznaczone kody ('+n+') z rejestru? Usunięte zostaną tylko kody ITSM przed nadaniem i wpisy nieaktualne — pozostałe są pomijane. Tej operacji nie da się cofnąć.')"><i class="bi bi-trash me-1"></i>Usuń zaznaczone</button>
    <?php endif; ?>
    <span class="small text-muted">Zaznacz pozycje w kolumnie „Etykieta” (<label class="d-inline"><input type="checkbox" onchange="document.querySelectorAll('input[name=&quot;sheet[]&quot;]').forEach(function(c){c.checked=this.checked}.bind(this))"> zaznacz wszystkie na stronie</label>).</span>
  </form>
  <?php if (is_admin()): ?>
  <details class="dr-more" style="margin-bottom:.8rem"><summary>Usuń zakres kodów od–do</summary>
    <form method="post" class="dr-sheet mt-2" onsubmit="return confirm('Usunąć kody z podanego zakresu (włącznie)? Usunięte zostaną tylko kody ITSM przed nadaniem i wpisy nieaktualne — pozostałe są pomijane. Tej operacji nie da się cofnąć.')">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="delete_codes">
      <input type="hidden" name="mode" value="range">
      <input type="hidden" name="back" value="<?= h((string)($_SERVER['QUERY_STRING'] ?? '')) ?>">
      <div><label for="dr-rf">Od (kod lub numer)</label><input id="dr-rf" name="from_code" class="form-control form-control-sm" required placeholder="np. ITSM/123456AB" autocomplete="off" spellcheck="false" style="width:14rem"></div>
      <div><label for="dr-rt">Do (kod lub numer)</label><input id="dr-rt" name="to_code" class="form-control form-control-sm" required placeholder="np. DOK/2026/000030" autocomplete="off" spellcheck="false" style="width:14rem"></div>
      <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń zakres</button>
      <span class="small text-muted">Zakres wg kolejności nadania w rejestrze, do 500 kodów.</span>
    </form>
  </details>
  <?php endif; ?>
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
          <td class="small"><?php $pm = (string)($r['paper_mode'] ?? ''); if ($r['source_type'] === 'reserved_code'): $iv = $itsmById[(int)$r['source_id']] ?? null; [$itl, $itc] = $itsmStatus($iv === '_printed' ? null : $iv, $iv === '_printed'); ?><span class="dr-chip <?= $itc ?>"><?= h($itl) ?></span>
          <?php elseif ($r['seq'] || str_starts_with((string)$r['source_type'], 'contract_') || in_array($r['source_type'], ['print_doc', 'gen_doc'], true)): ?><span class="text-muted">—</span>
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
