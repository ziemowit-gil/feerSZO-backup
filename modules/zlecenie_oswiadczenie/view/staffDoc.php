<?php
/**
 * modules/zlecenie_oswiadczenie/view/staffDoc.php — ekran weryfikacji jednego dokumentu (szata Metro w ramie SZO):
 * po lewej podgląd skanu (PDF w ramce, zdjęcie w całości), po prawej dane z kreatora do porównania, lista kontrolna,
 * decyzja „Zweryfikuj” / „Odrzuć” z gotowymi powodami i od razu następny skan z kolejki.
 * Wymaga: $doc, $osw, $contract (null — bez umowy), $a (odpowiedzi), $docs (oba dokumenty pakietu), $next, $pending.
 */
$PAGE_TITLE = 'Weryfikacja oświadczenia';
include dirname(__DIR__, 3) . '/includes/header.php';
include __DIR__ . '/_staffMetro.php';
[$_dn, $_ds] = OswService::KINDS[$doc['kind']];
[$_sl] = OswService::DOC_STATUS[$doc['status']] ?? [$doc['status']];
$_docC = ['wypelnione' => 'var(--grey)', 'wydrukowane' => 'var(--indigo)', 'skan' => 'var(--orange)', 'zweryfikowane' => 'var(--green)', 'odrzucone' => 'var(--red)'];
$_name = OswForm::nameFirst((string)($a['nazwisko_imiona'] ?? ''));
$_scanUrl = route('osw.scan', ['id' => (int)$doc['id']]);
$_ext = strtolower(pathinfo((string)$doc['scan_path'], PATHINFO_EXTENSION));
$_has = $doc['scan_path'] && is_file(UPLOAD_DIR . $doc['scan_path']);
$_sum = OswForm::summary($a);
// Dane do porównania: ZLB — dane rachunku i osoby; ZUS — dane osobowe, adres, pkt 1–5, dobrowolne
$_rows = $doc['kind'] === 'zlb'
    ? array_merge([['Imię i nazwisko', $_name]], $_sum[5])
    : array_merge($_sum[1], $_sum[2], $_sum[3], $_sum[4]);
$_checks = $doc['kind'] === 'zlb'
    ? ['Czytelny podpis w polu „Czytelny podpis Zleceniobiorcy”', 'Numer rachunku na skanie zgodny z danymi obok', 'Imię i nazwisko posiadacza rachunku = zleceniobiorca', 'Widoczna cała strona (wszystkie rogi), skan czytelny']
    : ['Podpis wykonawcy i data pod oświadczeniem', 'Zaznaczone kratki i skreślenia zgodne z danymi obok', 'PESEL, adres i urząd skarbowy czytelne i zgodne', 'Widoczna cała strona (wszystkie rogi), bez dopisków długopisem'];
?>
<div class="oswm">
  <nav aria-label="Ścieżka" class="small mb-2"><a href="<?= h(route('osw.index', ['f' => 'weryfikacja'])) ?>">Oświadczenia zleceniobiorców</a> ›
    <a href="<?= h(route('osw.show', ['id' => (int)$osw['id']])) ?>"><?= h($_name ?: 'zgłoszenie') ?></a> › <?= h($_ds) ?></nav>
  <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2">
    <h1 class="m-title"><?= h($_name ?: '—') ?> · <?= h($_ds) ?></h1>
    <span class="small text-body-secondary">Do weryfikacji w kolejce: <strong><?= (int)$pending ?></strong></span>
  </div>
  <p class="m-sub"><span class="m-badge" style="--c:<?= $_docC[$doc['status']] ?? 'var(--grey)' ?>"><?= h($_sl) ?></span>
    <?= h($_dn) ?><?= $doc['numer'] ? ' · ' . h($doc['numer']) : '' ?> ·
    <?= $contract ? 'umowa <a href="' . h(rtrim(APP_URL, '/') . '/contracts/zlecenie/view.php?id=' . (int)$contract['id'] . '&tab=oswiadczenie') . '">' . h($contract['numer_umowy']) . '</a> (' . h($contract['status']) . ')' : 'bez umowy — <a href="' . h(route('osw.show', ['id' => (int)$osw['id']])) . '">powiąż lub utwórz projekt</a>' ?>
    <?= $doc['scan_at'] ? ' · skan ' . h(date('d.m.Y H:i', (int)strtotime((string)$doc['scan_at']))) . ($doc['scan_ip'] ? ' (IP ' . h($doc['scan_ip']) . ')' : '') : '' ?></p>
  <?= flash_html() ?>

  <div class="m-verify">
    <section class="m-preview" aria-label="Podgląd skanu">
      <?php if (!$_has): ?>
        <div class="m-empty w-100 m-0" style="min-height:70vh">Zleceniobiorca nie przesłał jeszcze skanu tego dokumentu.
          <a href="<?= h(route('osw.staffPrint', ['id' => (int)$doc['id'], 'fmt' => 'pdf'])) ?>" target="_blank" rel="noopener">Zobacz wydruk</a>.</div>
      <?php elseif ($_ext === 'pdf'): ?>
        <iframe src="<?= h($_scanUrl) ?>#view=FitH" title="Skan: <?= h($_dn . ' — ' . $_name) ?>"></iframe>
      <?php else: ?>
        <a href="<?= h($_scanUrl) ?>" target="_blank" rel="noopener"><img src="<?= h($_scanUrl) ?>" alt="Skan: <?= h($_dn . ' — ' . $_name) ?> (otwórz w pełnym rozmiarze)"></a>
      <?php endif; ?>
    </section>

    <aside class="m-side">
      <div class="d-flex flex-wrap gap-1 mb-2">
        <?php if ($_has): ?><a class="m-btn ghost" href="<?= h($_scanUrl) ?>" target="_blank" rel="noopener">Skan w nowej karcie</a><?php endif; ?>
        <a class="m-btn ghost" href="<?= h(route('osw.staffPrint', ['id' => (int)$doc['id'], 'fmt' => 'pdf'])) ?>" target="_blank" rel="noopener">Wydruk do porównania</a>
      </div>

      <?php if ($doc['status'] === 'skan'): ?>
      <h2>Sprawdź</h2>
      <ul class="m-check">
        <?php foreach ($_checks as $_i => $_t): ?><li><input type="checkbox" id="chk<?= $_i ?>" data-chk><label for="chk<?= $_i ?>" class="fw-normal mb-0"><?= h($_t) ?></label></li><?php endforeach; ?>
      </ul>
      <form method="post" action="<?= h(route('osw.verify', ['id' => (int)$doc['id']])) ?>" class="mt-2">
        <?= Router::csrfField() ?><input type="hidden" name="back" value="next">
        <label for="osw-note" class="small fw-semibold mb-0">Powód odrzucenia (zobaczy go zleceniobiorca)</label>
        <div class="m-chips" role="group" aria-label="Gotowe powody">
          <?php foreach (['Nieczytelny skan', 'Brak podpisu', 'Brak daty', 'Niepełna strona', 'Dane na skanie różnią się od wydruku', 'Dopiski długopisem — wydrukuj ponownie z kreatora'] as $_r): ?>
          <button type="button" data-reason="<?= h($_r) ?>"><?= h($_r) ?></button>
          <?php endforeach; ?>
        </div>
        <textarea id="osw-note" name="note" rows="2" maxlength="500"></textarea>
        <div class="d-flex flex-wrap gap-1 mt-2">
          <button class="m-btn ok" name="decision" value="ok">Zweryfikuj<?= $next ? ' i dalej' : '' ?></button>
          <button class="m-btn no" name="decision" value="no">Odrzuć<?= $next ? ' i dalej' : '' ?></button>
          <?php if ($next): ?><a class="m-btn ghost ms-auto" href="<?= h(route('osw.doc', ['id' => (int)$next['id']])) ?>">Pomiń →</a><?php endif; ?>
        </div>
        <p class="small text-body-secondary mt-2 mb-0"><?= $doc['kind'] === 'zlb' ? 'Zweryfikowanie ustawi ten numer rachunku w umowie i dołączy skan do dokumentów umowy.' : 'Zweryfikowanie dołączy skan do dokumentów umowy i uzupełni jej puste pola (PESEL, adres, urząd skarbowy).' ?></p>
      </form>
      <?php else: ?>
      <div class="note-like small mb-2" style="background:#fff;padding:8px 10px;border-left:6px solid <?= $_docC[$doc['status']] ?? 'var(--grey)' ?>">
        <?php if ($doc['verified_at']): ?><?= $doc['status'] === 'zweryfikowane' ? 'Zweryfikował(a)' : 'Odrzucił(a)' ?> <?= h($doc['verified_name']) ?>, <?= h(date('d.m.Y H:i', (int)strtotime((string)$doc['verified_at']))) ?>.<?php else: ?><?= h($_sl) ?>.<?php endif; ?>
        <?php if ($doc['note']): ?><br>Powód: <?= h($doc['note']) ?><?php endif; ?>
      </div>
      <?php if ($next): ?><a class="m-btn" href="<?= h(route('osw.doc', ['id' => (int)$next['id']])) ?>">Następny do weryfikacji →</a><?php endif; ?>
      <?php endif; ?>

      <h2>Dane z kreatora</h2>
      <dl class="m-kv">
        <?php foreach ($_rows as [$_l, $_v]): ?><dt><?= h($_l) ?></dt><dd><?= h($_v !== '' ? $_v : '—') ?></dd><?php endforeach; ?>
      </dl>

      <h2>Drugi dokument</h2>
      <?php foreach ($docs as $_k => $_o): if ((int)$_o['id'] === (int)$doc['id']) continue; [$_ol] = OswService::DOC_STATUS[$_o['status']]; ?>
      <a class="d-flex align-items-center gap-2 text-decoration-none text-reset" href="<?= h(route('osw.doc', ['id' => (int)$_o['id']])) ?>">
        <span class="m-badge" style="--c:<?= $_docC[$_o['status']] ?? 'var(--grey)' ?>"><?= h($_ol) ?></span> <?= h(OswService::KINDS[$_k][0]) ?></a>
      <?php endforeach; ?>
    </aside>
  </div>
</div>
<script>
(function () {
  var note = document.getElementById('osw-note');
  document.querySelectorAll('[data-reason]').forEach(function (b) {
    b.addEventListener('click', function () {
      var r = b.getAttribute('data-reason');
      note.value = note.value.trim() ? note.value.trim().replace(/[.]$/, '') + '; ' + r.toLowerCase() : r;
      note.focus();
    });
  });
})();
</script>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
