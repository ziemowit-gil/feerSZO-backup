<?php
/**
 * modules/zlecenie_oswiadczenie/view/_staffDocs.php — dokumenty pakietu dla pracownika: status, wydruk, skan, weryfikacja.
 * Działa w module i w karcie umowy (adresy budowane bez Routera). Wymaga: $_oswO (pakiet), $_oswDocs; opcjonalnie $_oswBack ('umowa').
 */
$_oswDocUrl = OswService::url('/obsluga/dokument');
$_oswDt = fn($d) => $d ? date('d.m.Y H:i', (int)strtotime((string)$d)) : '';
?>
<?php if (!$_oswDocs): ?>
<p class="text-body-secondary small mb-0">Dokumenty powstaną po złożeniu oświadczeń w kreatorze.</p>
<?php else: ?>
<ul class="list-group list-group-flush">
<?php foreach (OswService::KINDS as $_k => [$_name, $_short]): $_d = $_oswDocs[$_k] ?? null; if (!$_d) continue;
      [$_sl, $_sc] = OswService::DOC_STATUS[$_d['status']] ?? [$_d['status'], 'secondary']; ?>
  <li class="list-group-item px-0">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <strong><?= h($_name) ?></strong><?php if ($_d['numer']): ?><span class="text-body-secondary small"><?= h($_d['numer']) ?></span><?php endif; ?>
      <span class="badge text-bg-<?= h($_sc) ?>"><?= h($_sl) ?></span>
      <span class="ms-auto d-flex flex-wrap gap-1">
        <a class="btn btn-sm btn-outline-secondary" href="<?= h($_oswDocUrl . '/' . (int)$_d['id'] . '/druk/pdf') ?>" target="_blank" rel="noopener">Wydruk PDF<span class="visually-hidden"> — <?= h($_short) ?></span></a>
        <?php if ($_k === 'zus'): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h($_oswDocUrl . '/' . (int)$_d['id'] . '/druk/docx') ?>">DOCX<span class="visually-hidden"> — <?= h($_short) ?></span></a><?php endif; ?>
        <?php if ($_d['scan_path']): ?><a class="btn btn-sm btn-outline-primary" href="<?= h($_oswDocUrl . '/' . (int)$_d['id'] . '/skan') ?>" target="_blank" rel="noopener">Skan<span class="visually-hidden"> — <?= h($_short) ?></span></a><?php endif; ?>
        <?php if ($_d['scan_path']): ?><a class="btn btn-sm <?= $_d['status'] === 'skan' ? 'btn-warning' : 'btn-outline-secondary' ?>" href="<?= h($_oswDocUrl . '/' . (int)$_d['id']) ?>"><?= $_d['status'] === 'skan' ? 'Weryfikuj z podglądem' : 'Podgląd' ?><span class="visually-hidden"> — <?= h($_short) ?></span></a><?php endif; ?>
      </span>
    </div>
    <div class="small text-body-secondary mt-1">
      <?php if ($_d['printed_at']): ?>Pobrano wydruk: <?= h($_oswDt($_d['printed_at'])) ?> (<?= (int)$_d['print_count'] ?>×). <?php endif; ?>
      <?php if ($_d['scan_at']): ?>Skan: <?= h($_oswDt($_d['scan_at'])) ?><?= $_d['scan_name'] ? ' · ' . h($_d['scan_name']) : '' ?>. <?php endif; ?>
      <?php if ($_d['verified_at']): ?><?= $_d['status'] === 'zweryfikowane' ? 'Zweryfikował(a)' : 'Odrzucił(a)' ?>: <?= h($_d['verified_name']) ?>, <?= h($_oswDt($_d['verified_at'])) ?>.<?php endif; ?>
      <?php if ($_d['note']): ?><br>Powód odrzucenia: <?= h($_d['note']) ?><?php endif; ?>
    </div>
    <?php if ($_d['status'] === 'skan' && in_array(current_user()['role'] ?? '', ['admin', 'editor'], true)): ?>
    <form method="post" action="<?= h($_oswDocUrl . '/' . (int)$_d['id'] . '/weryfikacja') ?>" class="row g-2 align-items-end mt-1">
      <?= csrf_field() ?><input type="hidden" name="back" value="<?= h($_oswBack ?? '') ?>">
      <div class="col-sm-7"><label class="form-label small mb-0" for="osw-note-<?= (int)$_d['id'] ?>">Powód odrzucenia (wymagany przy odrzuceniu)</label>
        <input class="form-control form-control-sm" id="osw-note-<?= (int)$_d['id'] ?>" name="note" maxlength="500" placeholder="np. nieczytelny podpis, brak daty"></div>
      <div class="col-sm-5 d-flex gap-1">
        <button class="btn btn-sm btn-success" name="decision" value="ok">Zweryfikuj<span class="visually-hidden"> — <?= h($_short) ?></span></button>
        <button class="btn btn-sm btn-outline-danger" name="decision" value="no">Odrzuć<span class="visually-hidden"> — <?= h($_short) ?></span></button>
      </div>
      <div class="col-12 small text-body-secondary"><?= $_k === 'zlb' ? 'Zweryfikowanie ustawi numer rachunku z oświadczenia w umowie i dołączy skan do dokumentów umowy.' : 'Zweryfikowanie dołączy skan do dokumentów umowy i uzupełni puste pola umowy (PESEL, adres, urząd skarbowy).' ?></div>
    </form>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
