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

// Pula kodów do dołączenia: admin generuje 10/20/30 etykiet kodowych i nadaje je przez Postivo na adres organizacji (na kopercie dopisek ITSM).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'itsm_batch') {
    csrf_check();
    $back = rtrim(APP_URL, '/') . '/ezd/rejestrDokumentow.php?type=reserved_code';
    $qty = (int)($_POST['qty'] ?? 0);
    $uid = (int)(current_user()['id'] ?? 0);
    $pdfTmp = null;
    try {
        if (!is_admin()) throw new \RuntimeException('Generować i nadawać kody może tylko administrator.');
        [$carrierId, $serviceId] = array_map('intval', explode(':', (string)($_POST['service'] ?? '0:0')) + [0, 0]);
        if ($carrierId <= 0 || $serviceId <= 0) throw new \RuntimeException('Wybierz usługę kurierską Postivo — kody nie zostały wygenerowane.');
        if (!in_array($qty, [10, 20, 30], true)) throw new \RuntimeException('Wybierz 10, 20 albo 30 kodów.');
        if (postivo_setting('postivo_enabled') !== '1') throw new \RuntimeException('Wysyłka przez Postivo.pl jest wyłączona (Administracja → Postivo) — kody nie zostały wygenerowane.');
        $orgName = trim(org_setting('org_name'));
        $adres   = trim(org_setting('org_adres'));
        if ($orgName === '' || !preg_match('~^(.*?)[,\s]+(\d{2}-\d{3})\s+(.+)$~u', $adres, $m)) throw new \RuntimeException('Uzupełnij adres organizacji w formacie „ulica nr, 00-000 Miasto” (ustawienie org_adres) — kody nie zostały wygenerowane.');
        [$street, $postcode, $city] = [trim($m[1], " ,"), $m[2], trim($m[3])];

        $unitId = (int)($_POST['unit_id'] ?? 0);
        $unitName = $unitId ? (string)(db_one("SELECT name FROM org_units WHERE id = ?", [$unitId])['name'] ?? '') : '';
        if ($unitId && $unitName === '') throw new \RuntimeException('Nie znaleziono wybranej jednostki.');
        $rows = DocRegistry::reserveBatch($qty, $uid, $unitId);
        $pdf = DocRegistry::labelsPdf($rows, true, 1) ?? throw new \RuntimeException('Generator PDF jest niedostępny.');
        $pdfTmp = sys_get_temp_dir() . '/itsm_kody_' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($pdfTmp, $pdf);

        $res = ezd_rpwy_create([
            'sposob'   => 'kurier',
            'odbiorca' => $orgName . ' (ITSM)',
            'adres'    => $street . "\n" . $postcode . ' ' . $city,
            'uwagi'    => 'ITSM' . ($unitName !== '' ? ' (' . $unitName . ')' : '') . ' — arkusz ' . $qty . ' kodów do dołączenia: ' . $rows[0]['code'] . ' … ' . $rows[count($rows) - 1]['code'],
        ], $uid);
        DocRegistry::reservedAttach($rows, (int)$res['id']);
        ezd_rpwy_postivo_queue((int)$res['id'], $pdfTmp, [
            'recipient_name' => $orgName,
            'address_line1'  => $street,
            'address_line2'  => 'ITSM',          // dopisek na kopercie
            'city'           => $city,
            'postcode'       => $postcode,
            'country'        => 'PL',
            'phone_number'   => trim(org_setting('org_telefon')) ?: null,   // kurier zwykle wymaga telefonu odbiorcy
            'carrier_id'     => $carrierId,
            'service_id'     => $serviceId,
        ], $uid);
        foreach ($rows as $r) DocRegistry::paperDone((string)$r['code']);   // etykiety drukuje Postivo
        flash_set('success', 'Wygenerowano ' . $qty . ' kodów (' . $rows[0]['code'] . ' … ' . $rows[count($rows) - 1]['code'] . '). Arkusz zakolejkowano do Postivo.pl (kurier) — wyjdzie dziś o 16:30 na adres organizacji z dopiskiem ITSM (' . ezd_rpwy_label($res) . ').');
    } catch (\Throwable $e) {
        flash_set('error', 'Nie wygenerowano / nie nadano kodów: ' . $e->getMessage());
    } finally {
        if ($pdfTmp && is_file($pdfTmp)) @unlink($pdfTmp);
    }
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
  <style>
  .dr-itsm{margin:0}
  .dr-itsm h2{font-size:1rem;font-weight:600;margin:0 0 .15rem}
  .dr-itsm p{margin:0 0 .6rem;font-size:.84rem;color:#4b5563}
  .dr-itsm .row2{display:flex;flex-wrap:wrap;gap:.5rem .75rem;align-items:flex-end}
  .dr-itsm label{display:block;font-size:.78rem;font-weight:600;margin-bottom:.15rem}
  .dr-itsm select{min-width:7rem}
  .dr-itsm #dr-svc{min-width:18rem;max-width:100%}
  .dr-itsm .btn{border-radius:0}
  .dr-itsm :focus-visible{outline:3px solid #d90429;outline-offset:2px}
  </style>
  <details class="dr-tools"<?= $f['type'] === 'reserved_code' ? ' open' : '' ?>>
  <summary><i class="bi bi-truck" aria-hidden="true"></i>Kody do dołączenia (ITSM) — generuj i nadaj kurierem</summary>
  <div class="dr-tools-body">
  <form method="post" class="dr-itsm" aria-labelledby="dr-itsm-h" onsubmit="return confirm('Wygenerować kody i zlecić nadanie arkusza kurierem przez Postivo (płatna wysyłka)?')">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="itsm_batch">
    <h2 id="dr-itsm-h" class="visually-hidden">Kody do dołączenia (ITSM)</h2>
    <p>Generuje pulę kodów i wysyła arkusz etykiet A4 kurierem Postivo na adres organizacji, z dopiskiem „ITSM” na kopercie.</p>
    <div class="row2">
      <div><label for="dr-qty">Liczba etykiet</label>
        <select id="dr-qty" name="qty" class="form-select form-select-sm"><?php foreach ([10, 20, 30] as $q): ?><option value="<?= $q ?>"<?= $q === 30 ? ' selected' : '' ?>><?= $q ?></option><?php endforeach; ?></select></div>
      <div><label for="dr-unit">Napis jednostki na etykiecie</label>
        <select id="dr-unit" name="unit_id" class="form-select form-select-sm"><option value="0">— bez jednostki (nazwa organizacji) —</option><?php foreach (ezd_org_units() as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h(ezd_unit_label($u)) ?></option><?php endforeach; ?></select></div>
      <div><label for="dr-svc">Usługa kurierska Postivo</label>
        <select id="dr-svc" name="service" class="form-select form-select-sm" required><option value="">Ładowanie usług…</option></select></div>
      <button class="btn btn-sm btn-primary" type="submit" id="dr-itsm-go" disabled><i class="bi bi-truck me-1" aria-hidden="true"></i>Generuj i nadaj kurierem</button>
    </div>
    <div class="small text-muted mt-2" id="dr-svc-msg" role="status" aria-live="polite"></div>
  </form>
  <script>
  (function () {
    var sel = document.getElementById('dr-svc'), go = document.getElementById('dr-itsm-go'), msg = document.getElementById('dr-svc-msg');
    fetch('<?= APP_URL ?>/ezd/sprawy/postivo_services.php', {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) throw new Error(d.error || 'Brak danych z Postivo.');
      var pick = '', n = 0; sel.innerHTML = '';
      (d.carriers || []).forEach(function (c) {
        var kur = /kurier/i.test(c.carrier_name);
        (c.services || []).forEach(function (s) {
          var o = document.createElement('option'); o.value = c.carrier_id + ':' + s.service_id;
          o.textContent = c.carrier_name + ' — ' + s.service_name; sel.appendChild(o); n++;
          if (!pick && (kur || /kurier/i.test(s.service_name))) pick = o.value;
        });
      });
      if (!n) throw new Error('Postivo nie zwróciło żadnych usług.');
      if (pick) sel.value = pick; else { sel.insertBefore(new Option('— wybierz usługę kurierską —', ''), sel.firstChild); sel.value = ''; msg.textContent = 'Nie rozpoznano usługi kurierskiej po nazwie — wybierz ją z listy.'; }
      go.disabled = false;
    }).catch(function (e) { sel.innerHTML = '<option value="">Niedostępne</option>'; msg.textContent = 'Nie pobrano usług Postivo: ' + e.message; });
  })();
  </script>
  </div></details>
  <?php endif; ?>
  <?php $pend = DocRegistry::paperPending(); ?>
  <?php if ($pend > 0): ?><div class="dr-note" role="status">
    <span><strong><?= (int)$pend ?></strong> dokumentów czeka na decyzję: czy mają odpowiednik papierowy (etykieta albo numer ręcznie).</span>
    <a class="btn btn-sm btn-outline-dark" href="<?= h($qs(['paper' => 'pending'])) ?>">Pokaż do rozstrzygnięcia</a></div><?php endif; ?>
  <form id="sheetForm" method="get" action="<?= h($base) ?>" target="_blank" class="dr-sheet">
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
          <td class="small"><?php $pm = (string)($r['paper_mode'] ?? ''); if ($r['seq'] || str_starts_with((string)$r['source_type'], 'contract_') || in_array($r['source_type'], ['print_doc', 'gen_doc', 'reserved_code'], true)): ?><span class="text-muted">—</span>
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
