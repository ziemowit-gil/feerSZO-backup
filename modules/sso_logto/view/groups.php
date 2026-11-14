<?php
/**
 * modules/sso_logto/view/groups.php — /ssoadmin/grupy: grupy w chmurze z SZO dla Koordynatorów IT (administrator albo uprawnienie
 * grupy_chmury, rola koordynator_it; polecenie 2026-11-14). Sekcja grup (view/groupsSection.php), wynik zakładania konta z okna
 * problemów (ctx „grupy-konto”, hasło tylko raz) i okno „Problemy z przypisaniami” (view/assignIssuesModal.php, poza resztą treści).
 * Zmienne: $flash.
 */
$PAGE_TITLE = 'Grupy w chmurze';
include dirname(__DIR__, 3) . '/includes/header.php';
$base  = rtrim(APP_URL, '/');
$grRes = SsoController::takeResult('grupy-konto');
?>
<div class="sso-wrap">
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-people" aria-hidden="true"></i> Grupy w chmurze</h1>
  <a href="<?= h($base) ?>/uslugi-it/uslugi/koordynatorzy-it/grupy-w-chmurze" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-question-circle" aria-hidden="true"></i> Pomoc dla Koordynatorów IT</a>
  <?php if (is_admin()): ?>
    <a href="<?= h(route('sso.index')) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear" aria-hidden="true"></i> Ustawienia logowania i chmury</a>
  <?php endif; ?>
</div>

<p class="text-body-secondary small">SZO zakłada w chmurze plików grupy dla zespołów, obszarów, działań i jednostek organizacyjnych i co 2 minuty ustawia w nich skład.
  Tutaj sprawdzisz wynik, uruchomisz synchronizację od razu i poprawisz przypisania, przez które ktoś nie trafia do grupy.</p>

<?php if ($flash): ?>
  <div class="alert alert-<?= h($flash['type']) ?> small" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($grRes): foreach ($grRes as $r): ?>
  <div class="alert alert-warning small" role="status" tabindex="-1" id="konto">
    <p class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Konto założone: <?= h((string)($r['name'] ?? '')) ?>.
      <?= !empty($r['password']) ? 'Hasło widać tylko teraz. Po odświeżeniu strony zniknie.' : '' ?></p>
    <dl class="row mb-0">
      <dt class="col-sm-3 fw-normal">Login</dt><dd class="col-sm-9 mb-1"><code><?= h((string)($r['username'] ?? '')) ?></code></dd>
      <?php if (!empty($r['password'])): ?><dt class="col-sm-3 fw-normal">Hasło</dt><dd class="col-sm-9 mb-1"><code><?= h((string)$r['password']) ?></code></dd><?php endif; ?>
      <dt class="col-sm-3 fw-normal">Chmura</dt><dd class="col-sm-9 mb-1"><?= h((string)(($r['cloud'] ?? '') ?: '—')) ?></dd>
      <dt class="col-sm-3 fw-normal">Logowanie jednokrotne</dt><dd class="col-sm-9 mb-1"><?= h((string)(($r['logto'] ?? '') ?: '—')) ?></dd>
      <dt class="col-sm-3 fw-normal">SMS</dt><dd class="col-sm-9 mb-1"><?= h((string)(($r['sms'] ?? '') ?: 'nie wysyłano (brak numeru)')) ?></dd>
      <?php if (!empty($r['ticket'])): ?><dt class="col-sm-3 fw-normal">Zgłoszenie w Helpdesku</dt><dd class="col-sm-9 mb-0"><?= h((string)$r['ticket']) ?> (osoba nie ma aktywnej umowy)</dd><?php endif; ?>
    </dl>
  </div>
  <script>document.getElementById('konto')?.focus();</script>
<?php endforeach; endif; ?>

<?php include __DIR__ . '/groupsSection.php'; ?>
</div>

<?php include __DIR__ . '/assignIssuesModal.php';   // poza resztą treści ?>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
