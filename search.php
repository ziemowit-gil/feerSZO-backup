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

    // 1–4. Osoby i umowy — globalna wyszukiwarka (PESEL, nazwisko, telefon, e-mail, nr umowy; uprawnienia per źródło)
    require_once __DIR__ . '/modules/global_search/logic/globalSearch.php';
    $global = GlobalSearch::run($q, 25, 60, 'strona');
    $globalHint = $global['hint'];
    foreach ($global['groups'] as $g) {
        $results[] = [
            'source' => $g['label'],
            'icon'   => $g['icon'],
            'color'  => $g['key'] === 'umowy' ? 'primary' : 'info',
            'items'  => array_map(fn($it) => [
                'title'    => $it['label'],
                'subtitle' => h($it['sub']) . ($it['match'] !== '' ? ' &middot; <span class="text-body-secondary">dopasowano: ' . h($it['match']) . '</span>' : ''),
                'badge'    => $it['badge'],
                'badge_bg' => $g['key'] === 'umowy' ? 'primary' : 'info',
                'url'      => $it['url'],
                'icon'     => $it['icon'],
            ], $g['items']),
        ];
        $total += count($g['items']);
    }

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
                    'subtitle' => $r['description'] ? h(mb_substr(strip_tags($r['description']), 0, 80) . (mb_strlen(strip_tags($r['description'])) > 80 ? '…' : '')) : '',
                    'badge'    => 'Zadanie',
                    'badge_bg' => 'secondary',
                    'url'      => APP_URL . '/tasks/index.php',
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
            placeholder="PESEL, nazwisko, telefon, e-mail albo numer umowy…"
            value="<?= h($q) ?>"
            autocomplete="off"
            autofocus
            aria-label="Szukaj osób po PESEL-u, nazwisku, telefonie lub e-mailu albo umów po numerze"
            aria-describedby="search-help"
          >
        </div>
        <button type="submit" class="btn btn-primary px-4">
          <i class="bi bi-search me-1"></i>Szukaj
        </button>
      </form>
      <div class="text-muted mt-2" style="font-size:.8rem" id="search-help">
        Osoby: pełny PESEL (11 cyfr), nazwisko, telefon, e-mail · Umowy: numer umowy lub rejestru (RU/…)
        · <i class="bi bi-keyboard ms-1 me-1" aria-hidden="true"></i>Skrót: <kbd>Ctrl+K</kbd> lub <kbd>/</kbd>
      </div>
    </div>
  </div>

  <?php if ($q !== '' && $error === ''): ?>

    <?php if (!empty($globalHint)): ?>
    <div class="alert alert-info d-flex align-items-center gap-2 py-2" role="status">
      <i class="bi bi-info-circle-fill" aria-hidden="true"></i><?= h($globalHint) ?>
    </div>
    <?php endif; ?>

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
        <a href="<?= h($item['url']) ?>"
           class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-2 px-3">
          <i class="bi <?= h($item['icon'] ?? $group['icon']) ?> text-<?= $group['color'] ?> flex-shrink-0" aria-hidden="true" style="font-size:1rem;width:20px;text-align:center"></i>
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
      <p class="mt-1" style="font-size:.9rem">PESEL podaj w całości (11 cyfr). Wyniki obejmują tylko dane, do których masz uprawnienia.</p>
    </div>
    <?php endif; ?>

  <?php elseif ($q === ''): ?>
  <!-- Initial state — search tips -->
  <div class="text-center py-5 text-muted">
    <i class="bi bi-search" style="font-size:3rem;opacity:.25"></i>
    <p class="mt-3 fw-semibold fs-5">Wyszukiwarka globalna</p>
    <p class="mb-1" style="font-size:.9rem">Przeszukuje jednocześnie (w zakresie Twoich uprawnień):</p>
    <ul class="list-unstyled d-inline-block text-start mt-2 mb-0" style="font-size:.9rem;color:#334155">
      <li><i class="bi bi-person-vcard me-2" aria-hidden="true"></i><strong>Osoby po PESEL-u</strong> — pełny numer, 11 cyfr</li>
      <li><i class="bi bi-people me-2" aria-hidden="true"></i><strong>Osoby po nazwisku</strong> — także „Kowalski Jan”</li>
      <li><i class="bi bi-telephone me-2" aria-hidden="true"></i><strong>Osoby po telefonie i e-mailu</strong> — z +48 lub bez, ze spacjami lub bez</li>
      <li><i class="bi bi-file-earmark-text me-2" aria-hidden="true"></i><strong>Umowy po numerze</strong> — numer umowy, rejestru (RU/…), roboczy</li>
    </ul>
    <p class="mt-3 mb-0" style="font-size:.85rem">Źródła: umowy wszystkich typów, strony umów, kontakty CRM, uczestnicy TI, konta (administrator) oraz zadania.</p>
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
