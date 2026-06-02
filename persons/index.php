<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/persons.php';

require_role('admin', 'editor');
$PAGE_TITLE = 'Katalog osób';

$q = trim($_GET['q'] ?? '');
$view = in_array($_COOKIE['persons_view'] ?? '', ['simple', 'rich']) ? ($_COOKIE['persons_view']) : 'rich';
if (isset($_GET['view']) && in_array($_GET['view'], ['simple', 'rich'])) {
    $view = $_GET['view'];
    setcookie('persons_view', $view, time() + 60 * 60 * 24 * 365, '/');
}

if ($q !== '') {
    $persons = persons_search($q);
} else {
    $persons = persons_all();
}

try { $total = (int)(db_one("SELECT COUNT(*) AS c FROM persons")['c'] ?? 0); } catch(\Throwable $e) { $total = 0; }
try { $filled = (int)(db_one("SELECT COUNT(*) AS c FROM persons WHERE questionnaire_filled_at IS NOT NULL")['c'] ?? 0); } catch(\Throwable $e) { $filled = 0; }

$q_icons = [
    'filled'    => ['success', 'bi-patch-check-fill', 'Wypełniony'],
    'sent'      => ['primary',  'bi-send-check',       'Wysłany'],
    'generated' => ['warning',  'bi-link-45deg',       'Link'],
    'none'      => ['secondary','bi-clipboard2-x',     'Brak'],
];

function avatar_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $i = mb_strtoupper(mb_substr($parts[0], 0, 1));
    if (count($parts) > 1) $i .= mb_strtoupper(mb_substr(end($parts), 0, 1));
    return h($i);
}

// deterministic hue from name
function avatar_hue(string $name): int {
    $hash = 0;
    foreach (mb_str_split($name) as $c) $hash = (($hash << 5) - $hash) + mb_ord($c);
    return abs($hash) % 360;
}

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
/* ── shared ──────────────────────────────────────────── */
.persons-avatar {
    width: 2.5rem; height: 2.5rem;
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: .9rem; color: #fff;
    flex-shrink: 0;
    user-select: none;
}
.persons-avatar-lg {
    width: 3.5rem; height: 3.5rem;
    font-size: 1.25rem;
}

/* ── simple view ─────────────────────────────────────── */
#view-simple .person-row td { vertical-align: middle; }
#view-simple .person-row:focus-within { outline: 2px solid #2563eb; outline-offset: -1px; }

/* ── rich view ───────────────────────────────────────── */
.person-card {
    border: none;
    border-radius: .75rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.04);
    transition: transform .15s ease, box-shadow .15s ease;
    overflow: hidden;
    height: 100%;
}
.person-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0,0,0,.12), 0 8px 32px rgba(0,0,0,.06);
}
.person-card:focus-within {
    outline: 3px solid #2563eb;
    outline-offset: 2px;
}
.person-card .card-top {
    padding: 1.25rem 1.25rem .75rem;
    display: flex; align-items: flex-start; gap: .85rem;
}
.person-card .card-meta {
    padding: .6rem 1.25rem;
    background: rgba(0,0,0,.025);
    border-top: 1px solid rgba(0,0,0,.06);
    display: flex; gap: .5rem; flex-wrap: wrap; align-items: center;
}
.person-card .card-actions {
    padding: .6rem 1.25rem;
    display: flex; gap: .4rem; justify-content: flex-end;
    border-top: 1px solid rgba(0,0,0,.06);
}
.qs-badge { font-size: .68rem; padding: .2em .55em; }

/* ── view toggle ─────────────────────────────────────── */
.view-toggle .btn { min-width: 2.4rem; }
.view-toggle .btn.active { font-weight: 600; }

/* ── stat strip ──────────────────────────────────────── */
.stat-pill {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .35rem .8rem;
    background: var(--bs-light);
    border-radius: 2rem;
    font-size: .875rem;
    font-weight: 500;
}

/* ── a11y: reduce motion ─────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
    .person-card { transition: none; }
}
</style>

<!-- ── Header row ──────────────────────────────────────────────────────── -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0">
    <i class="bi bi-person-lines-fill text-primary" aria-hidden="true"></i>
    Katalog osób
  </h1>
  <div class="d-flex align-items-center gap-2">
    <!-- View toggle -->
    <div class="btn-group view-toggle" role="group" aria-label="Styl widoku">
      <a href="?q=<?= h($q) ?>&view=simple"
         class="btn btn-sm btn-outline-secondary <?= $view==='simple'?'active':'' ?>"
         aria-pressed="<?= $view==='simple'?'true':'false' ?>"
         title="Widok prosty (tabela)">
        <i class="bi bi-list-ul" aria-hidden="true"></i>
        <span class="d-none d-sm-inline ms-1">Prosty</span>
      </a>
      <a href="?q=<?= h($q) ?>&view=rich"
         class="btn btn-sm btn-outline-secondary <?= $view==='rich'?'active':'' ?>"
         aria-pressed="<?= $view==='rich'?'true':'false' ?>"
         title="Widok bogaty (karty)">
        <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>
        <span class="d-none d-sm-inline ms-1">Karty</span>
      </a>
    </div>
    <a href="add.php" class="btn btn-primary btn-sm">
      <i class="bi bi-person-plus" aria-hidden="true"></i>
      <span class="d-none d-sm-inline ms-1">Dodaj osobę</span>
    </a>
  </div>
</div>

<!-- ── Stats strip ─────────────────────────────────────────────────────── -->
<div class="d-flex flex-wrap gap-2 mb-3" role="region" aria-label="Statystyki">
  <span class="stat-pill">
    <i class="bi bi-people text-primary" aria-hidden="true"></i>
    <strong><?= $total ?></strong> <span class="text-muted">osób</span>
  </span>
  <span class="stat-pill">
    <i class="bi bi-patch-check text-success" aria-hidden="true"></i>
    <strong><?= $filled ?></strong> <span class="text-muted">kwestionariuszy</span>
  </span>
  <?php if ($q): ?>
  <span class="stat-pill text-primary">
    <i class="bi bi-funnel" aria-hidden="true"></i>
    Wyniki dla: <strong><?= h($q) ?></strong>
    &nbsp;<a href="index.php" class="text-muted" aria-label="Wyczyść wyszukiwanie"><i class="bi bi-x-circle"></i></a>
  </span>
  <?php endif; ?>
</div>

<!-- ── Search ──────────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="get" role="search">
      <input type="hidden" name="view" value="<?= h($view) ?>">
      <div class="input-group">
        <label for="persons-search" class="visually-hidden">Szukaj osób</label>
        <span class="input-group-text" aria-hidden="true"><i class="bi bi-search"></i></span>
        <input type="search" id="persons-search" name="q"
               class="form-control"
               placeholder="Szukaj po nazwisku, PESEL lub e-mail…"
               value="<?= h($q) ?>"
               autocomplete="off"
               aria-label="Szukaj osób po nazwisku, PESEL lub e-mail">
        <button type="submit" class="btn btn-outline-primary">Szukaj</button>
        <?php if ($q): ?>
        <a href="index.php?view=<?= h($view) ?>" class="btn btn-outline-secondary" aria-label="Wyczyść wyszukiwanie">Wyczyść</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if (!$persons): ?>
<!-- ── Empty state ─────────────────────────────────────────────────────── -->
<?php if ($q): ?>
<div class="alert alert-info" role="alert">
  <i class="bi bi-search me-1" aria-hidden="true"></i>
  Nie znaleziono osób pasujących do „<strong><?= h($q) ?></strong>".
  <a href="add.php">Dodaj nową osobę</a>.
</div>
<?php else: ?>
<div class="card text-center py-5">
  <div class="card-body">
    <i class="bi bi-people display-4 text-muted" aria-hidden="true"></i>
    <p class="mt-3 text-muted">Brak osób w bazie.</p>
    <a href="add.php" class="btn btn-primary"><i class="bi bi-person-plus"></i> Dodaj pierwszą osobę</a>
  </div>
</div>
<?php endif; ?>

<?php elseif ($view === 'simple'): ?>
<!-- ════════════════════════════════════════════════════════════════════════
     WIDOK PROSTY — tabela z naciskiem na a11y
     ════════════════════════════════════════════════════════════════════════ -->
<div id="view-simple">
<div class="card shadow-sm">
<div class="table-responsive">
<table class="table table-hover table-sm align-middle mb-0"
       aria-label="Lista osób — <?= count($persons) ?> wyników">
  <caption class="visually-hidden">
    Tabela zawiera <?= count($persons) ?> <?= count($persons) === 1 ? 'osobę' : 'osób' ?>.
    Kolumny: imię i nazwisko, PESEL, e-mail i telefon, status kwestionariusza, data dodania, akcje.
  </caption>
  <thead class="table-light">
    <tr>
      <th scope="col">Osoba</th>
      <th scope="col">PESEL</th>
      <th scope="col">Kontakt</th>
      <th scope="col" class="text-center">Kwestionariusz</th>
      <th scope="col">Dodano</th>
      <th scope="col"><span class="visually-hidden">Akcje</span></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($persons as $p):
    $qs = questionnaire_status($p);
    [$qcol, $qico, $qlbl] = $q_icons[$qs];
  ?>
  <tr class="person-row">
    <td>
      <div class="d-flex align-items-center gap-2">
        <div class="persons-avatar"
             style="background: hsl(<?= avatar_hue($p['imie_nazwisko']) ?>,55%,48%)"
             aria-hidden="true">
          <?= avatar_initials($p['imie_nazwisko']) ?>
        </div>
        <a href="view.php?id=<?= $p['id'] ?>"
           class="fw-semibold text-decoration-none stretched-link-td"
           aria-label="Otwórz profil: <?= h($p['imie_nazwisko']) ?>">
          <?= h($p['imie_nazwisko']) ?>
        </a>
      </div>
    </td>
    <td class="font-monospace small"><?= h($p['pesel']) ?: '<span class="text-muted">—</span>' ?></td>
    <td>
      <?php if ($p['email']): ?>
        <div class="small"><a href="mailto:<?= h($p['email']) ?>"><?= h($p['email']) ?></a></div>
      <?php endif; ?>
      <?php if ($p['telefon']): ?>
        <div class="small text-muted"><a href="tel:<?= h($p['telefon']) ?>"><?= h($p['telefon']) ?></a></div>
      <?php endif; ?>
      <?php if (!$p['email'] && !$p['telefon']): ?><span class="text-muted">—</span><?php endif; ?>
    </td>
    <td class="text-center">
      <span class="badge bg-<?= $qcol ?> <?= $qcol==='warning'?'text-dark':'' ?> qs-badge"
            title="Status kwestionariusza: <?= $qlbl ?>">
        <i class="bi <?= $qico ?>" aria-hidden="true"></i>
        <span class="d-none d-md-inline ms-1"><?= $qlbl ?></span>
      </span>
    </td>
    <td class="small text-muted text-nowrap"><?= date_pl($p['created_at']) ?></td>
    <td class="text-end text-nowrap">
      <a href="view.php?id=<?= $p['id'] ?>"
         class="btn btn-sm btn-outline-secondary"
         aria-label="Podgląd: <?= h($p['imie_nazwisko']) ?>">
        <i class="bi bi-eye" aria-hidden="true"></i>
      </a>
      <a href="edit.php?id=<?= $p['id'] ?>"
         class="btn btn-sm btn-outline-secondary"
         aria-label="Edytuj: <?= h($p['imie_nazwisko']) ?>">
        <i class="bi bi-pencil" aria-hidden="true"></i>
      </a>
      <?php if (is_admin()): ?>
        <?= delete_btn('persons', (int)$p['id'], $p['imie_nazwisko'] ?? '#'.$p['id']) ?>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>
</div><!-- #view-simple -->

<?php else: ?>
<!-- ════════════════════════════════════════════════════════════════════════
     WIDOK BOGATY — siatka kart z awatarami
     ════════════════════════════════════════════════════════════════════════ -->
<div id="view-rich" role="list" aria-label="Katalog osób — <?= count($persons) ?> wyników">
<div class="row g-3">
<?php foreach ($persons as $p):
  $qs = questionnaire_status($p);
  [$qcol, $qico, $qlbl] = $q_icons[$qs];
  $hue = avatar_hue($p['imie_nazwisko']);
?>
<div class="col-sm-6 col-lg-4 col-xl-3" role="listitem">
  <article class="card person-card" aria-label="<?= h($p['imie_nazwisko']) ?>">

    <!-- top: avatar + name + qs badge -->
    <div class="card-top">
      <div class="persons-avatar persons-avatar-lg flex-shrink-0"
           style="background: hsl(<?= $hue ?>,55%,48%)"
           aria-hidden="true">
        <?= avatar_initials($p['imie_nazwisko']) ?>
      </div>
      <div class="flex-grow-1 overflow-hidden">
        <div class="fw-semibold text-truncate">
          <a href="view.php?id=<?= $p['id'] ?>"
             class="text-decoration-none text-reset stretched-link"
             aria-label="Otwórz profil: <?= h($p['imie_nazwisko']) ?>">
            <?= h($p['imie_nazwisko']) ?>
          </a>
        </div>
        <?php if ($p['email']): ?>
          <div class="small text-muted text-truncate"><?= h($p['email']) ?></div>
        <?php elseif ($p['telefon']): ?>
          <div class="small text-muted"><?= h($p['telefon']) ?></div>
        <?php else: ?>
          <div class="small text-muted">brak kontaktu</div>
        <?php endif; ?>
        <div class="mt-1">
          <span class="badge bg-<?= $qcol ?> <?= $qcol==='warning'?'text-dark':'' ?> qs-badge"
                title="Kwestionariusz: <?= $qlbl ?>">
            <i class="bi <?= $qico ?>" aria-hidden="true"></i> <?= $qlbl ?>
          </span>
        </div>
      </div>
    </div>

    <!-- meta strip -->
    <div class="card-meta small text-muted">
      <?php if ($p['pesel']): ?>
        <span title="PESEL"><i class="bi bi-card-text" aria-hidden="true"></i> <span class="font-monospace"><?= h($p['pesel']) ?></span></span>
      <?php endif; ?>
      <?php if ($p['telefon'] && $p['email']): ?>
        <span title="Telefon"><i class="bi bi-telephone" aria-hidden="true"></i> <?= h($p['telefon']) ?></span>
      <?php endif; ?>
      <span class="ms-auto" title="Data dodania"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= date_pl($p['created_at']) ?></span>
    </div>

    <!-- actions -->
    <div class="card-actions position-relative" style="z-index:1">
      <a href="view.php?id=<?= $p['id'] ?>"
         class="btn btn-sm btn-outline-secondary"
         aria-label="Podgląd: <?= h($p['imie_nazwisko']) ?>">
        <i class="bi bi-eye" aria-hidden="true"></i>
      </a>
      <a href="edit.php?id=<?= $p['id'] ?>"
         class="btn btn-sm btn-outline-secondary"
         aria-label="Edytuj: <?= h($p['imie_nazwisko']) ?>">
        <i class="bi bi-pencil" aria-hidden="true"></i>
      </a>
      <?php if (is_admin()): ?>
        <?= delete_btn('persons', (int)$p['id'], $p['imie_nazwisko'] ?? '#'.$p['id']) ?>
      <?php endif; ?>
    </div>

  </article>
</div>
<?php endforeach; ?>
</div>
</div><!-- #view-rich -->

<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
