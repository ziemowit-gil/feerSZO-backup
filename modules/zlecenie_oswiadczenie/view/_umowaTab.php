<?php
/**
 * modules/zlecenie_oswiadczenie/view/_umowaTab.php — zakładka „Oświadczenie” w karcie umowy zlecenia
 * (contracts/zlecenie/view.php). Wymaga w scope: $row (umowa), $id, $_tab.
 * Pokazuje status oświadczeń ZUS/PIT i ZLB, link do kreatora dla zleceniobiorcy, wydruki, skany i weryfikację.
 */
require_once dirname(__DIR__) . '/logic/OswService.php';
try { $_oswO = OswService::forContract((int)$id); } catch (\Throwable $e) { $_oswO = null; error_log('[osw tab] ' . $e->getMessage()); }
$_oswDocs = $_oswO ? OswService::docs((int)$_oswO['id']) : [];
$_oswUrl = rtrim(APP_URL, '/') . '/modules/zlecenie_oswiadczenie/router.php';
$_oswBack = 'umowa';
$_oswLink = $_oswO && $_oswO['token'] && (!$_oswO['token_expires'] || $_oswO['token_expires'] >= date('Y-m-d H:i:s')) ? $_oswUrl . '/t/' . $_oswO['token'] : '';
[$_oswSl, $_oswSc] = $_oswO ? OswService::summaryStatus($_oswO, $_oswDocs) : ['Nie rozpoczęto', 'secondary'];
?>
<div class="tab-pane fade<?php if ($_tab === 'oswiadczenie') echo ' show active'; ?>" id="tab-oswiadczenie" role="tabpanel" aria-labelledby="tab-oswiadczenie-btn">
<div class="cv-section">
  <h2 class="h5 fw-bold mb-1"><i class="bi bi-file-earmark-check text-primary me-2" aria-hidden="true"></i>Oświadczenia zleceniobiorcy</h2>
  <p class="text-body-secondary small">Oświadczenie dla celów podatkowych i ubezpieczenia ZUS (oryginalny wzór, bez zmian treści) oraz oświadczenie o numerze rachunku bankowego (ZLB).
    Zleceniobiorca wypełnia je online, drukuje z instrukcją, podpisuje i przesyła skan przez system.</p>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="fw-semibold">Status:</span> <span class="badge text-bg-<?= h($_oswSc) ?>"><?= h($_oswSl) ?></span>
        <?php if ($_oswO && $_oswO['submitted_at']): ?><span class="small text-body-secondary">złożono <?= h(date('d.m.Y H:i', (int)strtotime((string)$_oswO['submitted_at']))) ?></span><?php endif; ?>
        <?php if ($_oswO && $_oswO['draft_created']): ?><span class="badge text-bg-light border">umowa utworzona z oświadczeń</span><?php endif; ?>
        <?php if ($_oswO && in_array(current_user()['role'] ?? '', ['admin', 'editor'], true)): ?>
        <a class="ms-auto small" href="<?= h($_oswUrl . '/pakiet/' . (int)$_oswO['id']) ?>">Szczegóły i odpowiedzi</a>
        <?php endif; ?>
      </div>
      <?php include __DIR__ . '/_staffDocs.php'; ?>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-transparent fw-semibold">Dostęp zleceniobiorcy</div>
    <div class="card-body small">
      <p class="mb-2">Zleceniobiorca zalogowany w panelu widzi kreator przy swojej umowie. Osobie bez konta przekaż link (ważny <?= OswService::TOKEN_DAYS ?> dni) — system nic nie wysyła sam.</p>
      <?php if ($_oswLink): ?>
      <div class="input-group input-group-sm mb-2">
        <label class="input-group-text" for="osw-link">Link</label>
        <input class="form-control font-monospace" id="osw-link" value="<?= h($_oswLink) ?>" readonly>
        <button class="btn btn-outline-primary" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('osw-link').value);this.textContent='Skopiowano'">Kopiuj</button>
        <?php if (!empty($row['email'])): ?>
        <a class="btn btn-outline-secondary" href="mailto:<?= h($row['email']) ?>?subject=<?= rawurlencode('Oświadczenia do umowy zlecenia ' . $row['numer_umowy']) ?>&body=<?= rawurlencode("Dzień dobry,\n\nprosimy o wypełnienie oświadczeń do umowy zlecenia (ZUS/PIT i numer rachunku bankowego):\n" . $_oswLink . "\n\nPo wypełnieniu wydrukuj je, podpisz i prześlij skan przez tę samą stronę.") ?>">E-mail</a>
        <?php endif; ?>
      </div>
      <p class="text-body-secondary mb-2">Ważny do <?= h(date('d.m.Y', (int)strtotime((string)$_oswO['token_expires']))) ?>.</p>
      <?php endif; ?>
      <div class="d-flex flex-wrap gap-2">
        <?php if (can_edit()): ?>
        <form method="post" action="<?= h($_oswUrl . '/link/' . (int)$id) ?>"><?= csrf_field() ?>
          <button class="btn btn-sm btn-primary"><?= $_oswLink ? 'Przedłuż ważność linku' : 'Utwórz link dla zleceniobiorcy' ?></button></form>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h($_oswUrl . '/umowa/' . (int)$id) ?>">Otwórz kreator (wypełnij w imieniu)</a>
      </div>
    </div>
  </div>
</div>
</div>
