<?php
/**
 * ezd/kodyPartnerskie.php — Kody partnerskie (ITSM): pula kodów do dołączania do dokumentów papierowych.
 * Admin generuje 10/20/30 etykiet (z napisem jednostki), nadaje arkusz kurierem przez Postivo na adres organizacji
 * (dopisek ITSM na kopercie) albo drukuje samodzielnie; usuwa pule przed nadaniem. Kody żyją w rejestrze dokumentów
 * (source_type reserved_code) — tu jest ich własny ekran. Dostęp: administrator i zarząd (generowanie: administrator).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/byli.php';   // is_zarzad()
require_once dirname(__DIR__) . '/modules/doc_registry/lib/DocRegistry.php';
require_once dirname(__DIR__) . '/includes/ezd.php';
require_once dirname(__DIR__) . '/includes/ezd_rpwy.php';

require_login();
if (!is_zarzad()) { http_response_code(403); die('Kody partnerskie są dostępne dla administratorów i zarządu.'); }

$PAGE_TITLE = 'Kody partnerskie';

// Samodzielny wydruk: generuje pulę kodów ITSM bez wysyłki Postivo i od razu zwraca PDF (arkusz A4 3×8).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'itsm_print') {
    csrf_check();
    try {
        if (!is_admin()) throw new \RuntimeException('Generować kody może tylko administrator.');
        $qty = (int)($_POST['qty'] ?? 0);
        if (!in_array($qty, [10, 20, 30], true)) throw new \RuntimeException('Wybierz 10, 20 albo 30 kodów.');
        $unitId = (int)($_POST['unit_id'] ?? 0);
        if ($unitId && !db_one("SELECT 1 AS x FROM org_units WHERE id = ?", [$unitId])) throw new \RuntimeException('Nie znaleziono wybranej jednostki.');
        $rows = DocRegistry::reserveBatch($qty, (int)(current_user()['id'] ?? 0), $unitId);
        $pdf = DocRegistry::labelsPdf($rows, true, max(1, min(24, (int)($_POST['start'] ?? 1)))) ?? throw new \RuntimeException('Generator PDF jest niedostępny.');
        foreach ($rows as $r) DocRegistry::paperDone((string)$r['code']);   // wydrukowane samodzielnie
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="kody-itsm.pdf"'); header('Content-Length: ' . strlen($pdf)); header('Cache-Control: private, no-store');
        echo $pdf; exit;
    } catch (\Throwable $e) {
        flash_set('error', 'Nie wygenerowano kodów: ' . $e->getMessage());
        header('Location: ' . rtrim(APP_URL, '/') . '/ezd/kodyPartnerskie.php'); exit;
    }
}

// Kasowanie puli ITSM — tak jak wpisu książki nadawczej: do nadania wolno, po nadaniu nie (wtedy kody mogły zostać naklejone).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'itsm_delete') {
    csrf_check();
    $back = rtrim(APP_URL, '/') . '/ezd/kodyPartnerskie.php';
    try {
        if (!is_admin()) throw new \RuntimeException('Pule kodów może usuwać tylko administrator.');
        $batch = (string)($_POST['batch'] ?? '');
        $b = null;
        foreach (DocRegistry::reservedBatches() as $x) if ($x['batch'] === $batch) $b = $x;
        if (!$b) throw new \RuntimeException('Nie znaleziono puli kodów.');
        if ($b['rpwy'] > 0 && ($r = ezd_rpwy_get((int)$b['rpwy']))) {
            if (in_array($r['status'], ['nadana', 'doreczona', 'zwrocona'], true))
                throw new \RuntimeException('Arkusz z tą pulą został już nadany przez Postivo — kody mogą być naklejone na dokumentach, więc puli nie można usunąć.');
            if ($r['postivo_pending_pdf']) { $f = UPLOAD_DIR . EZD_RPWY_SUBDIR . (int)$r['id'] . '/' . $r['postivo_pending_pdf']; if (is_file($f)) @unlink($f); }
            ezd_rpwy_delete((int)$r['id'], (int)(current_user()['id'] ?? 0));
        }
        $n = DocRegistry::reservedDelete($batch);
        flash_set('success', 'Usunięto pulę ' . $n . ' kodów' . ($b['rpwy'] > 0 ? ' razem z niewysłanym wpisem w książce nadawczej (zlecenie Postivo wycofane z kolejki).' : '.'));
    } catch (\Throwable $e) {
        flash_set('error', 'Nie usunięto puli: ' . $e->getMessage());
    }
    header('Location: ' . $back); exit;
}

// Pula kodów do dołączenia: admin generuje 10/20/30 etykiet kodowych i nadaje je przez Postivo na adres organizacji (na kopercie dopisek ITSM).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'itsm_batch') {
    csrf_check();
    $back = rtrim(APP_URL, '/') . '/ezd/kodyPartnerskie.php';
    $qty = (int)($_POST['qty'] ?? 0);
    $uid = (int)(current_user()['id'] ?? 0);
    $pdfTmp = null;
    try {
        if (!is_admin()) throw new \RuntimeException('Generować i nadawać kody może tylko administrator.');
        [$carrierId, $serviceId] = array_map('intval', explode(':', (string)($_POST['service'] ?? '0:0')) + [0, 0]);
        if ($carrierId <= 0 || $serviceId <= 0) throw new \RuntimeException('Wybierz usługę kurierską Postivo — kody nie zostały wygenerowane.');
        if (!in_array($qty, [10, 20, 30], true)) throw new \RuntimeException('Wybierz 10, 20 albo 30 kodów.');
        require_once dirname(__DIR__) . '/includes/postivo.php';   // tylko nadanie przez Postivo; wydruk samodzielny go nie dotyka
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

// Status puli: partnerskie = arkusz wydrukowany i nadany przez Postivo (nadana/doręczona); inaczej wygenerowany / w kolejce
$itsmStatus = function (?string $rpwyStatus, bool $printed = false): array {
    if (in_array($rpwyStatus, ['nadana', 'doreczona'], true)) return ['partnerskie', 'dr-valid'];
    if ($rpwyStatus === 'postivo_queued') return ['w kolejce Postivo', 'dr-wait'];
    if ($rpwyStatus === 'zwrocona') return ['zwrócone przez Postivo', 'dr-void'];
    return $printed ? ['wydrukowane samodzielnie', 'dr-valid'] : ['wygenerowane', 'dr-new'];
};
$base = rtrim(APP_URL, '/') . '/docreg/';

include dirname(__DIR__) . '/includes/header.php';
?>
<style>
.dr-chip{display:inline-block;padding:.12rem .55rem;font-size:.78rem;font-weight:700;color:#fff}
.dr-valid{background:#006b00}.dr-void{background:#a20025}.dr-wait{background:#9a4000}.dr-new{background:#525252}
.dr-page .dr-head{display:flex;flex-wrap:wrap;align-items:baseline;gap:.25rem 1rem;margin-bottom:1rem}
.dr-page .dr-head h1{margin:0}
.dr-page .dr-head p{margin:0;color:#4b5563;font-size:.86rem}
.dr-page .dr-tools{margin:0 0 1rem;border:1px solid #d1d5db;border-left:4px solid #0f6fa8;background:#f4f8fb}
.dr-page .dr-tools .dr-tools-body{padding:0 1rem .9rem}
.dr-page :focus-visible{outline:3px solid #d90429;outline-offset:2px}
</style>
<div class="container-fluid py-3 dr-page" style="max-width:1300px">
  <div class="dr-head">
    <h1 class="h4"><i class="bi bi-qr-code me-2" aria-hidden="true"></i>Kody partnerskie (ITSM)</h1>
    <p>Pula kodów do dołączania do dokumentów papierowych · pojedyncze kody w <a href="<?= APP_URL ?>/ezd/rejestrDokumentow.php?type=reserved_code">Rejestrze dokumentów</a>.</p>
  </div>
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
  <section class="dr-tools" aria-label="Wydruk samodzielny kodów partnerskich">
  <div class="dr-tools-body" style="padding-top:.9rem">
  <form method="post" class="dr-itsm" target="_blank" aria-labelledby="dr-print-h" onsubmit="return confirm('Wygenerować pulę kodów i otworzyć arkusz do wydruku?')">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="itsm_print">
    <h2 id="dr-print-h">Wydruk samodzielny</h2>
    <p>Generuje pulę kodów i od razu otwiera arkusz etykiet A4 (3×8) do wydruku na własnej drukarce. Nic nie jest wysyłane.</p>
    <div class="row2">
      <div><label for="dr-pqty">Liczba etykiet</label>
        <select id="dr-pqty" name="qty" class="form-select form-select-sm"><?php foreach ([10, 20, 30] as $q): ?><option value="<?= $q ?>"<?= $q === 30 ? ' selected' : '' ?>><?= $q ?></option><?php endforeach; ?></select></div>
      <div><label for="dr-punit">Napis jednostki na etykiecie</label>
        <select id="dr-punit" name="unit_id" class="form-select form-select-sm"><option value="0">— bez jednostki (nazwa organizacji) —</option><?php foreach (ezd_org_units() as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h(ezd_unit_label($u)) ?></option><?php endforeach; ?></select></div>
      <div><label for="dr-pstart">Pierwsze wolne pole</label>
        <input id="dr-pstart" type="number" name="start" min="1" max="24" value="1" class="form-control form-control-sm" style="width:6rem"></div>
      <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-printer me-1" aria-hidden="true"></i>Generuj i drukuj</button>
    </div>
  </form>
  </div></section>
  <section class="dr-tools" aria-label="Nadanie kodów partnerskich przez Postivo">
  <div class="dr-tools-body" style="padding-top:.9rem">
  <form method="post" class="dr-itsm" aria-labelledby="dr-itsm-h" onsubmit="return confirm('Wygenerować kody i zlecić nadanie arkusza kurierem przez Postivo (płatna wysyłka)?')">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="itsm_batch">
    <h2 id="dr-itsm-h">Nadanie przez Postivo</h2>
    <p>Generuje pulę kodów partnerskich i wysyła arkusz etykiet A4 kurierem Postivo na adres organizacji, z dopiskiem „ITSM” na kopercie.</p>
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
  <?php $pools = DocRegistry::reservedBatches(); if ($pools): ?>
  <table class="table table-sm align-middle mt-3 mb-0" style="font-size:.84rem">
    <caption class="visually-hidden">Wygenerowane pule kodów ITSM</caption>
    <thead><tr><th scope="col">Wygenerowano</th><th scope="col">Jednostka</th><th scope="col">Kodów</th><th scope="col">Status</th><th scope="col">Przesyłka</th><th scope="col" class="text-end">Akcje</th></tr></thead>
    <tbody>
    <?php foreach ($pools as $pl): $rp = $pl['rpwy'] > 0 ? ezd_rpwy_get((int)$pl['rpwy']) : null; $sent = $rp && in_array($rp['status'], ['nadana', 'doreczona', 'zwrocona'], true); ?>
      <tr>
        <td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime((string)$pl['at']))) ?></td>
        <td><?= h($pl['unit'] !== '' ? $pl['unit'] : '—') ?></td>
        <td><?= (int)$pl['n'] ?></td>
        <td><?php [$itl, $itc] = $itsmStatus($rp['status'] ?? null, (int)$pl['printed'] > 0); ?><span class="dr-chip <?= $itc ?>"><?= h($itl) ?></span></td>
        <td><?php if ($rp): ?><a href="<?= APP_URL ?>/ezd/rpwy/view.php?id=<?= (int)$rp['id'] ?>"><?= h(ezd_rpwy_label($rp)) ?></a> · <?= h(EZD_RPWY_STATUSY[$rp['status']]['label'] ?? $rp['status']) ?><?php else: ?>—<?php endif; ?></td>
        <td class="text-end">
          <?php if ($sent): ?><a class="btn btn-sm btn-outline-secondary py-0 px-2" href="<?= h($base . '?sheet=' . rawurlencode(implode(',', DocRegistry::reservedCodes($pl['batch'])))) ?>" target="_blank" rel="noopener" aria-label="Wydrukuj ponownie arkusz <?= (int)$pl['n'] ?> kodów">Drukuj</a> <span class="text-muted small">nadana — nie można usunąć</span>
          <?php else: ?>
          <a class="btn btn-sm btn-outline-secondary py-0 px-2" href="<?= h($base . '?sheet=' . rawurlencode(implode(',', DocRegistry::reservedCodes($pl['batch'])))) ?>" target="_blank" rel="noopener" aria-label="Wydrukuj ponownie arkusz <?= (int)$pl['n'] ?> kodów">Drukuj</a>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć pulę <?= (int)$pl['n'] ?> kodów<?= $rp ? ' i wycofać zlecenie z kolejki Postivo' : '' ?>? Tej operacji nie da się cofnąć.')">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="itsm_delete">
            <input type="hidden" name="batch" value="<?= h($pl['batch']) ?>">
            <button class="btn btn-sm btn-outline-danger py-0 px-2" type="submit" aria-label="Usuń pulę <?= (int)$pl['n'] ?> kodów z <?= h(date('d.m.Y H:i', strtotime((string)$pl['at']))) ?>">Usuń pulę</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  </div></section>
  <?php endif; ?>
  <?php if (!is_admin()): ?><p class="text-muted">Generować i nadawać kody może tylko administrator.</p><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
