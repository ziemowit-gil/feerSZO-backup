<?php
/**
 * modules/zlecenie_oswiadczenie/view/staffIndex.php — panel obsługi oświadczeń dla Fundacji (szata Metro w ramie SZO):
 * kafle stanów (filtr), kolejka dokumentów z miniaturą skanu → ekran weryfikacji, zgłoszenia do powiązania / w trakcie,
 * publiczny kreator, link dla nowej osoby i pełna lista.
 * Wymaga: $rows, $newLink, $f (aktywny kafel), $counts (OswService::dashboardCounts()), $queue (dokumenty dla $f).
 */
$PAGE_TITLE = 'Oświadczenia zleceniobiorców';
include dirname(__DIR__, 3) . '/includes/header.php';
include __DIR__ . '/_staffMetro.php';
$_view = rtrim(APP_URL, '/') . '/contracts/zlecenie/view.php';
$_docC = ['wypelnione' => 'var(--grey)', 'wydrukowane' => 'var(--indigo)', 'skan' => 'var(--orange)', 'zweryfikowane' => 'var(--green)', 'odrzucone' => 'var(--red)'];
$_fmt = fn($d) => $d ? date('d.m.Y H:i', (int)strtotime((string)$d)) : '';
$_who = function (array $r): string {
    $a = OswService::answers($r);
    return (string)(($r['imie_nazwisko'] ?? '') ?: (($r['invite_name'] ?? '') ?: OswForm::nameFirst((string)($a['nazwisko_imiona'] ?? '')) ?: '—'));
};
?>
<div class="oswm">
  <h1 class="m-title">Oświadczenia zleceniobiorców</h1>
  <p class="m-sub">Oświadczenie dla celów podatkowych i ubezpieczenia ZUS oraz oświadczenie o numerze rachunku bankowego (ZLB).
    Kliknij kafel, aby zobaczyć dokumenty w danym stanie; skan otwiera ekran weryfikacji z podglądem obok danych.</p>
  <?= flash_html() ?>

  <nav aria-label="Stany oświadczeń">
    <div class="m-tiles">
      <?php foreach ($counts as $_k => [$_l, $_s, $_c, $_n]): ?>
      <a class="m-tile c-<?= h($_c) ?>" href="<?= h(route('osw.index', ['f' => $_k])) ?>"<?= $_k === $f ? ' aria-current="page"' : '' ?>>
        <span class="n"><?= (int)$_n ?></span>
        <span><b><?= h($_l) ?></b><br><small><?= h($_s) ?></small></span>
      </a>
      <?php endforeach; ?>
    </div>
  </nav>

  <h2 class="m-head"><span><?= h($counts[$f][0]) ?></span>
    <?php if ($f === 'weryfikacja' && $queue): ?><a class="m-btn" href="<?= h(route('osw.doc', ['id' => (int)$queue[0]['id']])) ?>">Weryfikuj po kolei →</a><?php endif; ?></h2>

  <?php if (in_array($f, ['powiazanie', 'wypelnianie'], true)): ?>
    <?php if (!$rows): ?><div class="m-empty">Brak zgłoszeń w tym stanie.</div><?php else: ?>
    <div class="m-queue">
      <?php foreach ($rows as $_r): [$_sl] = OswService::summaryStatus($_r, $_r['_docs']); $_a = OswService::answers($_r); ?>
      <a class="m-card" href="<?= h(route('osw.show', ['id' => (int)$_r['id']])) ?>">
        <span class="m-thumb" style="--c:var(--<?= $f === 'powiazanie' ? 'teal' : 'grey' ?>)"><span class="ph"><?= $f === 'powiazanie' ? '⇄' : (int)$_r['step'] . '/6' ?><small><?= $f === 'powiazanie' ? 'powiąż z umową' : 'krok kreatora' ?></small></span>
          <?php if (($_r['source'] ?? '') === 'public'): ?><span class="kind">PUBLICZNY</span><?php endif; ?></span>
        <span class="bd"><span class="nm"><?= h($_who($_r)) ?></span>
          <span class="mt"><?= h($_sl) ?><br><?= h($_fmt($_r['submitted_at'] ?: ($_r['updated_at'] ?: $_r['created_at']))) ?><?= !empty($_a['email']) ? ' · ' . h($_a['email']) : '' ?></span></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  <?php elseif (!$queue): ?>
    <div class="m-empty"><?= $f === 'weryfikacja' ? 'Nie ma skanów do weryfikacji.' : 'Brak dokumentów w tym stanie.' ?></div>
  <?php else: ?>
    <div class="m-queue">
      <?php foreach ($queue as $_d): $_a = json_decode((string)$_d['answers'], true) ?: [];
            $_ext = strtolower(pathinfo((string)$_d['scan_path'], PATHINFO_EXTENSION));
            $_img = $_d['scan_path'] && in_array($_ext, ['jpg', 'jpeg', 'png'], true) && is_file(UPLOAD_DIR . $_d['scan_path']);
            [$_dl] = OswService::DOC_STATUS[$_d['status']] ?? [$_d['status']];
            $_name = OswForm::nameFirst((string)($_a['nazwisko_imiona'] ?? '')) ?: '—'; ?>
      <a class="m-card" href="<?= h(route('osw.doc', ['id' => (int)$_d['id']])) ?>">
        <span class="m-thumb" style="--c:<?= $_docC[$_d['status']] ?? 'var(--grey)' ?><?= $_img ? ';background-image:url(' . h(route('osw.scan', ['id' => (int)$_d['id']])) . ')' : '' ?>">
          <?php if (!$_img): ?><span class="ph"><?= $_d['scan_path'] ? 'PDF' : '—' ?><small><?= $_d['scan_path'] ? 'skan' : 'bez skanu' ?></small></span><?php endif; ?>
          <span class="kind"><?= $_d['kind'] === 'zus' ? 'ZUS/PIT' : 'ZLB' ?></span>
        </span>
        <span class="bd"><span class="nm"><?= h($_name) ?></span>
          <span class="mt"><?= h($_dl) ?><br>
            <?= $_d['scan_at'] ? 'skan ' . h($_fmt($_d['scan_at'])) : 'złożono ' . h($_fmt($_d['submitted_at'])) ?> ·
            <?= $_d['numer_umowy'] ? h($_d['numer_umowy']) : 'bez umowy' ?></span></span>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2 class="m-head"><span>Publiczny kreator i linki</span></h2>
  <div class="row g-2">
    <div class="col-lg-6"><div class="m-side h-100">
      <h2>Adres dla zleceniobiorców</h2>
      <div class="input-group input-group-sm">
        <input class="form-control font-monospace" id="osw-pub" value="<?= h(route('osw.public')) ?>" readonly aria-label="Adres publicznego kreatora">
        <button class="btn btn-outline-primary" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('osw-pub').value);this.textContent='Skopiowano'">Kopiuj</button>
        <a class="btn btn-outline-secondary" href="<?= h(route('osw.public')) ?>" target="_blank" rel="noopener">Otwórz</a>
      </div>
      <p class="small text-body-secondary mt-2 mb-0">Bez logowania. Po przesłaniu skanu osoba dostaje konto tylko do swoich oświadczeń; z umową wiążesz je w szczegółach („Do powiązania”).</p>
    </div></div>
    <div class="col-lg-6"><div class="m-side h-100">
      <h2>Link dla nowej osoby (bez umowy)</h2>
      <?php if ($newLink): ?>
      <div class="alert alert-success py-2 small" role="status">
        <div class="fw-semibold mb-1">Link dla: <?= h($newLink['name']) ?></div>
        <div class="input-group input-group-sm">
          <input class="form-control font-monospace" id="osw-new-link" value="<?= h($newLink['url']) ?>" readonly aria-label="Link dla nowej osoby">
          <button class="btn btn-outline-primary" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('osw-new-link').value);this.textContent='Skopiowano'">Kopiuj</button>
          <?php if (!empty($newLink['email'])): ?>
          <a class="btn btn-outline-secondary" href="mailto:<?= h($newLink['email']) ?>?subject=<?= rawurlencode('Oświadczenia do umowy zlecenia') ?>&body=<?= rawurlencode("Dzień dobry,\n\nprzed zawarciem umowy zlecenia prosimy o wypełnienie oświadczeń (ZUS/PIT i numer rachunku bankowego):\n" . $newLink['url'] . "\n\nPo wypełnieniu wydrukuj je, podpisz i prześlij skan przez tę samą stronę.") ?>">E-mail</a>
          <?php endif; ?>
        </div>
        <div class="mt-1">Ważny <?= OswService::TOKEN_DAYS ?> dni. System nie wysyła go sam.</div>
      </div>
      <?php endif; ?>
      <form method="post" action="<?= h(route('osw.new')) ?>" class="row g-2 align-items-end">
        <?= Router::csrfField() ?>
        <div class="col-sm-5"><label class="form-label small mb-0" for="osw-name">Imię i nazwisko <span class="text-danger" aria-hidden="true">*</span></label>
          <input class="m-in" id="osw-name" name="name" required aria-required="true" autocomplete="off"></div>
        <div class="col-sm-4"><label class="form-label small mb-0" for="osw-email">E-mail</label>
          <input class="m-in" id="osw-email" name="email" type="email" autocomplete="off"></div>
        <div class="col-sm-3"><button class="m-btn w-100 justify-content-center">Utwórz link</button></div>
      </form>
      <p class="small text-body-secondary mt-2 mb-0">Po złożeniu oświadczeń system sam utworzy projekt umowy zlecenia.</p>
    </div></div>
  </div>

  <?php $_all = OswService::listAll(); ?>
  <details class="mt-3">
    <summary class="m-head" style="cursor:pointer;display:list-item"><span>Wszystkie oświadczenia (<?= count($_all) ?>)</span></summary>
    <div class="table-responsive">
    <table class="table table-sm align-middle">
      <caption class="visually-hidden">Wszystkie oświadczenia zleceniobiorców</caption>
      <thead><tr><th scope="col">Osoba</th><th scope="col">Umowa</th><th scope="col">Status</th><th scope="col">ZUS/PIT</th><th scope="col">ZLB</th><th scope="col">Zmiana</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
      <tbody>
      <?php foreach ($_all as $_r): [$_sl, $_sc] = OswService::summaryStatus($_r, $_r['_docs']); $_w = $_who($_r); ?>
        <tr>
          <td><?= h($_w) ?><?= ($_r['source'] ?? '') === 'public' ? ' <span class="badge text-bg-light border">publiczny</span>' : '' ?></td>
          <td><?= $_r['contract_id'] ? '<a href="' . h($_view . '?id=' . (int)$_r['contract_id'] . '&tab=oswiadczenie') . '">' . h($_r['numer_umowy']) . '</a>' . ($_r['umowa_status'] === 'projekt' ? ' <span class="badge text-bg-light border">projekt</span>' : '') : '<span class="text-body-secondary">' . (($_r['source'] ?? '') === 'public' ? '— do powiązania' : '— po złożeniu powstanie projekt') . '</span>' ?></td>
          <td><span class="badge text-bg-<?= h($_sc) ?>"><?= h($_sl) ?></span></td>
          <?php foreach (['zus', 'zlb'] as $_k): $_d = $_r['_docs'][$_k] ?? null; ?>
          <td><?php if ($_d): [$_dl, $_dc] = OswService::DOC_STATUS[$_d['status']]; ?><a class="badge text-bg-<?= h($_dc) ?> text-decoration-none" href="<?= h(route('osw.doc', ['id' => (int)$_d['id']])) ?>"><?= h($_dl) ?></a><?php else: ?>—<?php endif; ?></td>
          <?php endforeach; ?>
          <td class="small text-nowrap"><?= h($_fmt($_r['updated_at'] ?: $_r['created_at'])) ?></td>
          <td><a class="btn btn-sm btn-outline-primary" href="<?= h(route('osw.show', ['id' => (int)$_r['id']])) ?>">Szczegóły<span class="visually-hidden"> — <?= h($_w) ?></span></a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$_all): ?><tr><td colspan="7" class="text-body-secondary">Brak oświadczeń.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </details>
</div>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
