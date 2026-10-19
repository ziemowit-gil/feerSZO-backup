<?php
/**
 * modules/zlecenie_oswiadczenie/view/done.php — oświadczenia złożone: pobranie wydruków (PDF z instrukcją, DOCX wzoru),
 * przesłanie podpisanych skanów, statusy weryfikacji. Wymaga: $osw, $contract, $docs, $a, $base; opcjonalnie $back, $flash.
 */
$title = 'Oświadczenia do umowy zlecenia';
$_anyVerified = (bool)array_filter($docs, fn($d) => $d['status'] === 'zweryfikowane');
?>
<?php include __DIR__ . '/_head.php'; ?>
<div class="card">
  <p class="muted small" style="margin:0">Umowa zlecenia<?= !empty($contract['numer_umowy']) ? ' ' . h($contract['numer_umowy']) : '' ?> · <?= h((string)($a['nazwisko_imiona'] ?? '')) ?></p>
  <h1>Twoje oświadczenia</h1>
  <p style="margin:0">Złożone online <?= h(date('d.m.Y, H:i', (int)strtotime((string)$osw['submitted_at']))) ?>. Teraz każdy dokument:</p>
  <ol style="margin:8px 0 0">
    <li><strong>pobierz i wydrukuj</strong> (PDF zawiera instrukcję i samo oświadczenie),</li>
    <li><strong>sprawdź dane, wpisz datę i podpisz</strong> własnoręcznie,</li>
    <li><strong>zeskanuj</strong> albo zrób wyraźne zdjęcie całej strony,</li>
    <li><strong>prześlij skan tutaj</strong> — przyciskiem „Prześlij skan” przy dokumencie. Nie wysyłaj skanu e-mailem.</li>
  </ol>
</div>

<?php foreach (OswService::KINDS as $_k => [$_name, $_short]): $_d = $docs[$_k] ?? null; if (!$_d) continue;
      [$_sl, $_sc] = OswService::DOC_STATUS[$_d['status']] ?? [$_d['status'], 'secondary']; ?>
<section class="doc card" id="dok-<?= h($_k) ?>" aria-labelledby="h-dok-<?= h($_k) ?>">
  <h3 id="h-dok-<?= h($_k) ?>"><?= h($_name) ?><?= $_d['numer'] ? ' <span class="muted small">(' . h($_d['numer']) . ')</span>' : '' ?></h3>
  <p style="margin:0 0 10px"><span class="badge b-<?= h($_sc) ?>"><?= h($_sl) ?></span>
    <?php if ($_d['scan_at']): ?><span class="small muted"> · skan z <?= h(date('d.m.Y H:i', (int)strtotime((string)$_d['scan_at']))) ?></span><?php endif; ?>
    <?php if ($_d['status'] === 'zweryfikowane' && $_d['verified_at']): ?><span class="small muted"> · sprawdzono <?= h(date('d.m.Y', (int)strtotime((string)$_d['verified_at']))) ?></span><?php endif; ?>
  </p>
  <?php if ($_d['status'] === 'odrzucone' && $_d['note']): ?>
  <div class="note note-danger" role="note"><strong>Fundacja odrzuciła skan:</strong> <?= h($_d['note']) ?> Popraw i prześlij skan ponownie.</div>
  <?php endif; ?>

  <div style="display:flex;flex-wrap:wrap;gap:10px">
    <a class="btn btn-sm" href="<?= h($base . '/druk/' . $_k . '/pdf') ?>" target="_blank" rel="noopener">Pobierz PDF do druku<span style="position:absolute;left:-999px"> — <?= h($_short) ?> (otwiera się w nowej karcie)</span></a>
    <?php if ($_k === 'zus'): ?>
    <a class="btn btn-sm btn-sec" href="<?= h($base . '/druk/zus/docx') ?>">Pobierz wzór Word (DOCX)<span style="position:absolute;left:-999px"> — oświadczenie ZUS/PIT</span></a>
    <?php endif; ?>
  </div>
  <?php if ($_k === 'zus'): ?><p class="small muted" style="margin:8px 0 0">Oświadczenie jest na oryginalnym wzorze Fundacji, bez zmian w treści — wpisaliśmy tylko Twoje dane, zaznaczyliśmy kratki i skreśliliśmy warianty, które Ciebie nie dotyczą.</p><?php endif; ?>

  <?php if ($_d['status'] !== 'zweryfikowane'): ?>
  <form method="post" action="<?= h($base . '/skan/' . $_k) ?>" enctype="multipart/form-data" class="drop">
    <?= Router::csrfField() ?>
    <label for="skan-<?= h($_k) ?>"><?= $_d['scan_path'] ? 'Prześlij skan ponownie' : 'Prześlij skan podpisanego dokumentu' ?></label>
    <span class="hint" id="skan-h-<?= h($_k) ?>">PDF, JPG lub PNG, najwyżej 20 MB. Na telefonie możesz od razu zrobić zdjęcie.</span>
    <input type="file" id="skan-<?= h($_k) ?>" name="skan" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required aria-describedby="skan-h-<?= h($_k) ?>">
    <div style="margin-top:10px"><button type="submit" class="btn btn-sm">Prześlij skan<span style="position:absolute;left:-999px"> — <?= h($_short) ?></span></button></div>
  </form>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<?php if (!$_anyVerified): ?>
<form method="post" action="<?= h($base . '/popraw') ?>" class="card">
  <?= Router::csrfField() ?>
  <h2>Coś się nie zgadza?</h2>
  <p class="small" style="margin-top:0">Nie poprawiaj wydruku długopisem. Popraw dane w kreatorze i wydrukuj oświadczenia ponownie — wcześniej przesłane skany przestaną obowiązywać.</p>
  <button type="submit" class="btn btn-sec btn-sm">Popraw dane w kreatorze</button>
</form>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
