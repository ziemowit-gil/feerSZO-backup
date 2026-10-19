<?php
/**
 * modules/zlecenie_oswiadczenie/view/staffIndex.php — lista oświadczeń zleceniobiorców + link dla osoby jeszcze bez umowy.
 * Wymaga: $rows, $newLink (['url','name','email'] tuż po utworzeniu albo null).
 */
$PAGE_TITLE = 'Oświadczenia zleceniobiorców';
include dirname(__DIR__, 3) . '/includes/header.php';
$_view = rtrim(APP_URL, '/') . '/contracts/zlecenie/view.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <h1 class="h4 mb-0"><i class="bi bi-file-earmark-check text-primary" aria-hidden="true"></i> Oświadczenia zleceniobiorców</h1>
</div>
<p class="text-body-secondary small">Oświadczenie dla celów podatkowych i ubezpieczenia ZUS oraz oświadczenie o numerze rachunku bankowego (ZLB), wypełniane online przez zleceniobiorcę.
  Link dla nowej osoby (bez umowy): po złożeniu oświadczeń system utworzy z tych danych <strong>projekt umowy zlecenia</strong>.</p>
<?= flash_html() ?>
<p class="small">Publiczny kreator (bez logowania, do udostępnienia na stronie lub w ogłoszeniu):
  <a href="<?= h(route('osw.public')) ?>" target="_blank" rel="noopener"><?= h(route('osw.public')) ?></a>.
  Po przesłaniu skanu osoba dostaje konto z dostępem tylko do swoich oświadczeń; z umową wiążesz je w szczegółach.</p>

<?php if ($newLink): ?>
<div class="alert alert-success" role="status">
  <div class="fw-semibold mb-1">Link dla: <?= h($newLink['name']) ?></div>
  <div class="input-group input-group-sm">
    <label class="input-group-text" for="osw-new-link">Link</label>
    <input class="form-control font-monospace" id="osw-new-link" value="<?= h($newLink['url']) ?>" readonly>
    <button class="btn btn-outline-primary" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('osw-new-link').value);this.textContent='Skopiowano'">Kopiuj</button>
    <?php if (!empty($newLink['email'])): ?>
    <a class="btn btn-outline-secondary" href="mailto:<?= h($newLink['email']) ?>?subject=<?= rawurlencode('Oświadczenia do umowy zlecenia') ?>&body=<?= rawurlencode("Dzień dobry,\n\nprzed zawarciem umowy zlecenia prosimy o wypełnienie oświadczeń (ZUS/PIT i numer rachunku bankowego):\n" . $newLink['url'] . "\n\nPo wypełnieniu wydrukuj je, podpisz i prześlij skan przez tę samą stronę.") ?>">E-mail</a>
    <?php endif; ?>
  </div>
  <div class="small mt-1">Link jest ważny <?= OswService::TOKEN_DAYS ?> dni. System nie wysyła go sam — przekaż go osobie.</div>
</div>
<?php endif; ?>

<details class="card border-0 shadow-sm mb-3"<?= $rows ? '' : ' open' ?>>
  <summary class="card-header bg-transparent fw-semibold">Link dla nowej osoby (jeszcze bez umowy)</summary>
  <form method="post" action="<?= h(route('osw.new')) ?>" class="card-body row g-2 align-items-end">
    <?= Router::csrfField() ?>
    <div class="col-sm-5"><label class="form-label small" for="osw-name">Imię i nazwisko <span class="text-danger" aria-hidden="true">*</span></label>
      <input class="form-control form-control-sm" id="osw-name" name="name" required aria-required="true" autocomplete="off"></div>
    <div class="col-sm-4"><label class="form-label small" for="osw-email">E-mail (do projektu umowy)</label>
      <input class="form-control form-control-sm" id="osw-email" name="email" type="email" autocomplete="off"></div>
    <div class="col-sm-3"><button class="btn btn-sm btn-primary w-100">Utwórz link</button></div>
  </form>
</details>

<div class="table-responsive">
<table class="table table-sm align-middle">
  <caption class="visually-hidden">Oświadczenia zleceniobiorców</caption>
  <thead><tr><th scope="col">Osoba</th><th scope="col">Umowa</th><th scope="col">Status</th><th scope="col">ZUS/PIT</th><th scope="col">ZLB</th><th scope="col">Zmiana</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $_r): [$_sl, $_sc] = OswService::summaryStatus($_r, $_r['_docs']);
        $_a = OswService::answers($_r); $_who = $_r['imie_nazwisko'] ?: ($_r['invite_name'] ?: ($_a['nazwisko_imiona'] ?? '—')); ?>
    <tr>
      <td><?= h($_who) ?><?= ($_r['source'] ?? '') === 'public' ? ' <span class="badge text-bg-light border">publiczny</span>' : '' ?></td>
      <td><?= $_r['contract_id'] ? '<a href="' . h($_view . '?id=' . (int)$_r['contract_id'] . '&tab=oswiadczenie') . '">' . h($_r['numer_umowy']) . '</a>' . ($_r['umowa_status'] === 'projekt' ? ' <span class="badge text-bg-light border">projekt</span>' : '') : '<span class="text-body-secondary">' . (($_r['source'] ?? '') === 'public' ? '— do powiązania' : '— po złożeniu powstanie projekt') . '</span>' ?></td>
      <td><span class="badge text-bg-<?= h($_sc) ?>"><?= h($_sl) ?></span></td>
      <?php foreach (['zus', 'zlb'] as $_k): $_d = $_r['_docs'][$_k] ?? null; ?>
      <td><?php if ($_d): [$_dl, $_dc] = OswService::DOC_STATUS[$_d['status']]; ?><span class="badge text-bg-<?= h($_dc) ?>"><?= h($_dl) ?></span><?php else: ?>—<?php endif; ?></td>
      <?php endforeach; ?>
      <td class="small text-nowrap"><?= h(date('d.m.Y H:i', (int)strtotime((string)($_r['updated_at'] ?: $_r['created_at'])))) ?></td>
      <td><a class="btn btn-sm btn-outline-primary" href="<?= h(route('osw.show', ['id' => (int)$_r['id']])) ?>">Szczegóły<span class="visually-hidden"> — <?= h($_who) ?></span></a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-body-secondary">Brak oświadczeń.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
