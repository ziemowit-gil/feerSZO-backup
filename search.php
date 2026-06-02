<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$PAGE_TITLE = 'Szukaj';

$q       = trim($_GET['q'] ?? '');
$results = [];
$total   = 0;
$error   = '';

if ($q !== '' && mb_strlen($q) < 2) {
    $error = 'Wpisz co najmniej 2 znaki, aby wyszukać.';
}

if ($q !== '' && $error === '') {
    $like = '%' . $q . '%';

    // 1. umowy_wolontariat
    try {
        $rows = db_all(
            "SELECT id, imie_nazwisko, email, numer_umowy, data_od
             FROM umowy_wolontariat
             WHERE imie_nazwisko LIKE ? OR email LIKE ? OR numer_umowy LIKE ? OR pesel LIKE ?
             LIMIT 10",
            [$like, $like, $like, $like]
        );
        if ($rows) {
            $results[] = [
                'source'  => 'Umowy wolontariatu',
                'icon'    => 'bi-heart-fill',
                'color'   => 'success',
                'items'   => array_map(fn($r) => [
                    'title'    => $r['imie_nazwisko'],
                    'subtitle' => ($r['email'] ? h($r['email']) : '') . ($r['numer_umowy'] ? ' &middot; ' . h($r['numer_umowy']) : ''),
                    'badge'    => 'Wolontariat',
                    'badge_bg' => 'success',
                    'url'      => APP_URL . '/contracts/wolontariat/view.php?id=' . (int)$r['id'],
                ], $rows),
            ];
            $total += count($rows);
        }
    } catch (\Throwable $e) {}

    // 2. umowy_zlecenie
    try {
        $rows = db_all(
            "SELECT id, imie_nazwisko, email, numer_umowy, data_od
             FROM umowy_zlecenie
             WHERE imie_nazwisko LIKE ? OR email LIKE ? OR numer_umowy LIKE ?
             LIMIT 10",
            [$like, $like, $like]
        );
        if ($rows) {
            $results[] = [
                'source'  => 'Umowy zlecenia',
                'icon'    => 'bi-file-earmark-person-fill',
                'color'   => 'primary',
                'items'   => array_map(fn($r) => [
                    'title'    => $r['imie_nazwisko'],
                    'subtitle' => ($r['email'] ? h($r['email']) : '') . ($r['numer_umowy'] ? ' &middot; ' . h($r['numer_umowy']) : ''),
                    'badge'    => 'Zlecenie',
                    'badge_bg' => 'primary',
                    'url'      => APP_URL . '/contracts/zlecenie/view.php?id=' . (int)$r['id'],
                ], $rows),
            ];
            $total += count($rows);
        }
    } catch (\Throwable $e) {}

    // 3. umowy_dzielo
    try {
        $rows = db_all(
            "SELECT id, imie_nazwisko, numer_umowy, data_od
             FROM umowy_dzielo
             WHERE imie_nazwisko LIKE ? OR numer_umowy LIKE ?
             LIMIT 10",
            [$like, $like]
        );
        if ($rows) {
            $results[] = [
                'source'  => 'Umowy o dzieło',
                'icon'    => 'bi-file-earmark-text-fill',
                'color'   => 'warning',
                'items'   => array_map(fn($r) => [
                    'title'    => $r['imie_nazwisko'],
                    'subtitle' => $r['numer_umowy'] ? h($r['numer_umowy']) : '',
                    'badge'    => 'Dzieło',
                    'badge_bg' => 'warning',
                    'url'      => APP_URL . '/contracts/dzielo/view.php?id=' . (int)$r['id'],
                ], $rows),
            ];
            $total += count($rows);
        }
    } catch (\Throwable $e) {}

    // 4. persons
    try {
        $rows = db_all(
            "SELECT id, imie_nazwisko, email, telefon
             FROM persons
             WHERE imie_nazwisko LIKE ? OR email LIKE ? OR telefon LIKE ?
             LIMIT 10",
            [$like, $like, $like]
        );
        if ($rows) {
            $results[] = [
                'source'  => 'Osoby',
                'icon'    => 'bi-person-fill',
                'color'   => 'info',
                'items'   => array_map(fn($r) => [
                    'title'    => $r['imie_nazwisko'],
                    'subtitle' => ($r['email'] ? h($r['email']) : '') . ($r['telefon'] ? ' &middot; ' . h($r['telefon']) : ''),
                    'badge'    => 'Osoba',
                    'badge_bg' => 'info',
                    'url'      => APP_URL . '/persons/view.php?id=' . (int)$r['id'],
                ], $rows),
            ];
            $total += count($rows);
        }
    } catch (\Throwable $e) {}

    // 5. tasks
    try {
        $rows = db_all(
            "SELECT id, title, description
             FROM tasks
             WHERE deleted_at IS NULL AND (title LIKE ? OR description LIKE ?)
             LIMIT 10",
            [$like, $like]
        );
        if ($rows) {
            $results[] = [
                'source'  => 'Zadania',
                'icon'    => 'bi-check2-square',
                'color'   => 'secondary',
                'items'   => array_map(fn($r) => [
                    'title'    => $r['title'],
                    'subtitle' => $r['description'] ? mb_substr(strip_tags($r['description']), 0, 80) . (mb_strlen(strip_tags($r['description'])) > 80 ? '…' : '') : '',
                    'badge'    => 'Zadanie',
                    'badge_bg' => 'secondary',
                    'url'      => APP_URL . '/tasks/index.php',
                ], $rows),
            ];
            $total += count($rows);
        }
    } catch (\Throwable $e) {}

    // 6. crm_contacts
    try {
        $rows = db_all(
            "SELECT id, imie_nazwisko, email
             FROM crm_contacts
             WHERE imie_nazwisko LIKE ? OR email LIKE ?
             LIMIT 10",
            [$like, $like]
        );
        if ($rows) {
            $results[] = [
                'source'  => 'Kontakty CRM',
                'icon'    => 'bi-diagram-2-fill',
                'color'   => 'danger',
                'items'   => array_map(fn($r) => [
                    'title'    => $r['imie_nazwisko'],
                    'subtitle' => $r['email'] ? h($r['email']) : '',
                    'badge'    => 'CRM',
                    'badge_bg' => 'danger',
                    'url'      => APP_URL . '/crm/contacts/view.php?id=' . (int)$r['id'],
                ], $rows),
            ];
            $total += count($rows);
        }
    } catch (\Throwable $e) {}
}

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4" style="max-width:860px">

  <?php if ($error): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?= h($error) ?>
  </div>
  <?php endif; ?>

  <!-- Search form -->
  <div class="card shadow-sm mb-4">
    <div class="card-body py-3">
      <form method="get" action="<?= APP_URL ?>/search.php" class="d-flex gap-2" role="search">
        <div class="input-group">
          <span class="input-group-text bg-white border-end-0">
            <i class="bi bi-search text-muted"></i>
          </span>
          <input
            type="search"
            name="q"
            id="search-input"
            class="form-control border-start-0 ps-0 fs-5"
            placeholder="Szukaj umów, osób, zadań, kontaktów…"
            value="<?= h($q) ?>"
            autocomplete="off"
            autofocus
            aria-label="Pole wyszukiwania"
          >
        </div>
        <button type="submit" class="btn btn-primary px-4">
          <i class="bi bi-search me-1"></i>Szukaj
        </button>
      </form>
      <div class="text-muted mt-2" style="font-size:.8rem">
        <i class="bi bi-keyboard me-1"></i>Skrót: <kbd>Ctrl+K</kbd> lub <kbd>/</kbd> — aby skupić wyszukiwarkę
      </div>
    </div>
  </div>

  <?php if ($q !== '' && $error === ''): ?>

    <?php if ($total > 0): ?>
    <p class="text-muted mb-3">
      <i class="bi bi-check-circle-fill text-success me-1"></i>
      Znaleziono <strong><?= $total ?></strong> <?= $total === 1 ? 'wynik' : ($total < 5 ? 'wyniki' : 'wyników') ?> dla: <strong>&ldquo;<?= h($q) ?>&rdquo;</strong>
    </p>

    <?php foreach ($results as $group): ?>
    <div class="mb-4">
      <div class="d-flex align-items-center gap-2 mb-2 pb-1 border-bottom">
        <i class="bi <?= $group['icon'] ?> text-<?= $group['color'] ?>" style="font-size:1.1rem"></i>
        <span class="fw-semibold text-<?= $group['color'] ?>"><?= h($group['source']) ?></span>
        <span class="badge bg-<?= $group['color'] ?> bg-opacity-10 text-<?= $group['color'] ?> ms-1"><?= count($group['items']) ?></span>
      </div>

      <div class="list-group list-group-flush shadow-sm rounded">
        <?php foreach ($group['items'] as $item): ?>
        <a href="<?= $item['url'] ?>"
           class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-2 px-3">
          <i class="bi <?= $group['icon'] ?> text-<?= $group['color'] ?> flex-shrink-0" style="font-size:1rem;width:20px;text-align:center"></i>
          <div class="flex-grow-1 overflow-hidden">
            <div class="fw-semibold text-truncate"><?= h($item['title']) ?></div>
            <?php if ($item['subtitle']): ?>
            <div class="text-muted text-truncate" style="font-size:.8rem"><?= $item['subtitle'] ?></div>
            <?php endif; ?>
          </div>
          <span class="badge bg-<?= $item['badge_bg'] ?> bg-opacity-10 text-<?= $item['badge_bg'] ?> flex-shrink-0" style="font-size:.7rem"><?= h($item['badge']) ?></span>
          <span class="btn btn-sm btn-outline-<?= $group['color'] ?> flex-shrink-0 py-0 px-2" style="font-size:.75rem">
            Otwórz <i class="bi bi-arrow-right"></i>
          </span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php else: ?>
    <!-- Empty state -->
    <div class="text-center py-5 text-muted">
      <i class="bi bi-search" style="font-size:3rem;opacity:.3"></i>
      <p class="mt-3 fw-semibold fs-5">Brak wyników</p>
      <p class="mb-0">Nie znaleziono niczego dla: <strong>&ldquo;<?= h($q) ?>&rdquo;</strong></p>
      <p class="mt-1" style="font-size:.9rem">Spróbuj innej frazy lub skróconego imienia/numeru umowy.</p>
    </div>
    <?php endif; ?>

  <?php elseif ($q === ''): ?>
  <!-- Initial state — search tips -->
  <div class="text-center py-5 text-muted">
    <i class="bi bi-search" style="font-size:3rem;opacity:.25"></i>
    <p class="mt-3 fw-semibold fs-5">Wyszukiwarka globalna</p>
    <p class="mb-1" style="font-size:.9rem">Przeszukuje jednocześnie:</p>
    <div class="d-flex flex-wrap justify-content-center gap-2 mt-2">
      <span class="badge bg-success bg-opacity-10 text-success px-3 py-2"><i class="bi bi-heart-fill me-1"></i>Wolontariat</span>
      <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2"><i class="bi bi-file-earmark-person-fill me-1"></i>Zlecenia</span>
      <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-2"><i class="bi bi-file-earmark-text-fill me-1"></i>Dzieła</span>
      <span class="badge bg-info bg-opacity-10 text-info px-3 py-2"><i class="bi bi-person-fill me-1"></i>Osoby</span>
      <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-2"><i class="bi bi-check2-square me-1"></i>Zadania</span>
      <span class="badge bg-danger bg-opacity-10 text-danger px-3 py-2"><i class="bi bi-diagram-2-fill me-1"></i>CRM</span>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
// Keyboard shortcut: Ctrl+K or / focuses the search input
(function () {
  var input = document.getElementById('search-input');
  if (!input) return;

  document.addEventListener('keydown', function (e) {
    // Skip when focus is already inside a form field
    var tag = document.activeElement ? document.activeElement.tagName : '';
    var isEditable = (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' ||
                      document.activeElement.isContentEditable);

    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
      e.preventDefault();
      input.focus();
      input.select();
      return;
    }
    if (e.key === '/' && !isEditable) {
      e.preventDefault();
      input.focus();
      input.select();
    }
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
