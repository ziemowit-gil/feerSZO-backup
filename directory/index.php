<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/directory.php';

require_login();
directory_migrate();

$PAGE_TITLE = 'Wszyscy współpracownicy';

$q       = trim($_GET['q'] ?? '');
$unit_id = (int)($_GET['unit_id'] ?? 0);
$sort    = in_array($_GET['sort'] ?? '', ['first_name', 'last_name', 'unit_name']) ? $_GET['sort'] : 'last_name';

$where  = ['u.is_active = 1'];
$params = [];
if ($q !== '') {
    $like    = '%' . $q . '%';
    $where[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
    array_push($params, $like, $like, $like, $like);
}
if ($unit_id > 0) {
    $where[] = 'om_j.unit_id = ?';
    $params[] = $unit_id;
}
$order_map = [
    'first_name' => 'u.first_name, u.last_name',
    'last_name'  => 'u.last_name, u.first_name',
    'unit_name'  => 'ou.name, u.last_name',
];
$sql = "
    SELECT u.id, u.name, u.first_name, u.last_name, u.email,
           COALESCE(up.phone_public,0) AS phone_public,
           COALESCE(up.avatar_file,'') AS avatar_file,
           om_j.position_name, om_j.phone_direct, om_j.phone_mobile,
           u.phone_number, ou.name AS unit_name
    FROM users u
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN (
        SELECT om2.user_id, om2.unit_id, om2.position_name, om2.phone_direct, om2.phone_mobile
        FROM org_members om2
        WHERE om2.status = 'active'
        ORDER BY om2.is_primary DESC, om2.id DESC
    ) om_j ON om_j.user_id = u.id
    LEFT JOIN org_units ou ON ou.id = om_j.unit_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY " . $order_map[$sort];

try { $people = db_all($sql, $params); } catch (\Throwable $e) { $people = []; }
try { $units = db_all("SELECT id, name FROM org_units WHERE status='active' ORDER BY name"); }
catch (\Throwable $e) { $units = []; }

$count = count($people);
$result_label = $count === 0 ? 'Brak wyników'
    : ($count === 1 ? '1 osoba' : $count . ' ' . ($count < 5 ? 'osoby' : 'osób'));
if ($q || $unit_id) $result_label .= ' — wyniki wyszukiwania';

include __DIR__ . '/includes/header_dir.php';
?>

<div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h1 class="h4 mb-0 fw-bold" style="color:var(--dir-text)">
      <i class="bi bi-people-fill me-2" aria-hidden="true" style="color:var(--dir-primary)"></i>Katalog współpracowników
    </h1>
    <p class="text-muted small mb-0 mt-1">Znajdź osobę, sprawdź jej profil i dane kontaktowe</p>
  </div>
</div>

<section aria-label="Wyszukiwanie i filtry">
  <form method="get" role="search" aria-label="Szukaj współpracownika">
    <div class="dir-search-bar">
      <div class="row g-2 align-items-end w-100">
        <div class="col-12 col-md-5">
          <label for="dir-q" class="visually-hidden">Imię, nazwisko lub e-mail</label>
          <div class="input-group">
            <span class="input-group-text bg-white" aria-hidden="true"><i class="bi bi-search text-muted"></i></span>
            <input type="search" name="q" id="dir-q" class="form-control border-start-0"
                   placeholder="Imię, nazwisko, e-mail…"
                   value="<?= h($q) ?>" autocomplete="off">
          </div>
        </div>
        <div class="col-6 col-md-3">
          <label for="dir-unit" class="visually-hidden">Jednostka organizacyjna</label>
          <select name="unit_id" id="dir-unit" class="form-select">
            <option value="">Wszystkie jednostki</option>
            <?php foreach ($units as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= $unit_id === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label for="dir-sort" class="visually-hidden">Sortowanie</label>
          <select name="sort" id="dir-sort" class="form-select">
            <option value="last_name"  <?= $sort==='last_name'  ? 'selected':'' ?>>Nazwisko A–Z</option>
            <option value="first_name" <?= $sort==='first_name' ? 'selected':'' ?>>Imię A–Z</option>
            <option value="unit_name"  <?= $sort==='unit_name'  ? 'selected':'' ?>>Jednostka</option>
          </select>
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
          <button type="submit" class="btn flex-grow-1" style="background:var(--dir-primary);color:#fff">
            <i class="bi bi-funnel me-1" aria-hidden="true"></i>Szukaj
          </button>
          <?php if ($q || $unit_id): ?>
          <a href="?" class="btn btn-outline-secondary" aria-label="Wyczyść filtry">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </form>
</section>

<div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
  <p class="text-muted mb-0" style="font-size:.8rem"
     aria-live="polite" aria-atomic="true" id="dirResultCount">
    <i class="bi bi-people me-1" aria-hidden="true"></i><span id="dirCountLabel"><?= h($result_label) ?></span>
  </p>
  <div class="btn-group dir-view-toggle btn-group-sm" id="dirViewToggle" role="group" aria-label="Tryb widoku">
    <button type="button" class="btn btn-outline-secondary" id="viewGrid" aria-pressed="true" title="Kafelki">
      <i class="bi bi-grid-3x3-gap"></i>
    </button>
    <button type="button" class="btn btn-outline-secondary" id="viewList" aria-pressed="false" title="Lista">
      <i class="bi bi-list-ul"></i>
    </button>
  </div>
</div>

<section aria-label="Lista współpracowników" aria-describedby="dirResultCount">
<?php if (empty($people)): ?>
  <div class="text-center py-5" style="color:var(--dir-text-muted)" role="status">
    <i class="bi bi-person-x" aria-hidden="true" style="font-size:3rem;opacity:.35;display:block;margin-bottom:.75rem"></i>
    <p class="fs-5 mb-2">Nie znaleziono nikogo</p>
    <?php if ($q || $unit_id): ?>
    <a href="?" class="btn btn-outline-secondary btn-sm">Wyczyść filtry</a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <ul class="row g-3 list-unstyled dir-people-grid" id="dirPeopleList" role="list">
    <?php foreach ($people as $person):
        $display = directory_display_name($person);
        $phone_display = $person['phone_public']
            ? ($person['phone_direct'] ?: $person['phone_mobile'] ?: ($person['phone_number'] ?? ''))
            : '';
        $parts = [$display];
        if ($person['position_name']) $parts[] = $person['position_name'];
        if ($person['unit_name'])     $parts[] = 'jednostka ' . $person['unit_name'];
        if ($phone_display)           $parts[] = 'tel. ' . $phone_display;
        $search_text = mb_strtolower($display . ' ' . ($person['position_name'] ?? '') . ' ' . ($person['unit_name'] ?? '') . ' ' . $person['email'], 'UTF-8');
    ?>
    <li class="col-6 col-md-4 col-lg-3" role="listitem"
        data-text="<?= h($search_text) ?>">
      <a href="<?= APP_URL ?>/directory/profile.php?id=<?= (int)$person['id'] ?>"
         class="dir-person-card"
         aria-label="<?= h(implode(', ', $parts)) ?> — otwórz profil">
        <?= directory_avatar_html($person, 60) ?>
        <div class="dir-name" aria-hidden="true"><?= h($display) ?></div>
        <?php if ($person['position_name']): ?>
        <div class="dir-position" aria-hidden="true"><?= h($person['position_name']) ?></div>
        <?php endif; ?>
        <?php if ($person['unit_name']): ?>
        <div class="dir-unit" aria-hidden="true"><i class="bi bi-building me-1" aria-hidden="true"></i><?= h($person['unit_name']) ?></div>
        <?php endif; ?>
        <?php if ($phone_display): ?>
        <div class="dir-phone" aria-hidden="true"><i class="bi bi-telephone me-1" aria-hidden="true"></i><?= h($phone_display) ?></div>
        <?php endif; ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <div id="dirNoResults" class="text-center py-5 d-none" style="color:var(--dir-text-muted)" role="status">
    <i class="bi bi-search" aria-hidden="true" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:.75rem"></i>
    <p class="mb-1">Brak wyników dla wpisanej frazy.</p>
    <button class="btn btn-outline-secondary btn-sm mt-1" onclick="document.getElementById('dir-q').value='';dirFilter()">Wyczyść</button>
  </div>
<?php endif; ?>
</section>

<script>
(function () {
  // ── View toggle (grid / list) ─────────────────────────────────────
  var STORAGE_KEY = 'dirView';
  var list  = document.getElementById('dirPeopleList');
  var btnG  = document.getElementById('viewGrid');
  var btnL  = document.getElementById('viewList');

  function applyView(v) {
    if (!list) return;
    var isGrid = v !== 'list';
    list.classList.toggle('list-view', !isGrid);
    if (btnG) { btnG.setAttribute('aria-pressed', isGrid ? 'true' : 'false'); btnG.classList.toggle('active', isGrid); }
    if (btnL) { btnL.setAttribute('aria-pressed', !isGrid ? 'true' : 'false'); btnL.classList.toggle('active', !isGrid); }
    // In list view collapse column classes
    if (list) {
      Array.from(list.children).forEach(function (li) {
        if (!isGrid) {
          li.dataset.origClass = li.className;
          li.className = 'dir-list-li';
        } else if (li.dataset.origClass) {
          li.className = li.dataset.origClass;
        }
      });
    }
    try { localStorage.setItem(STORAGE_KEY, v); } catch(e) {}
  }

  if (btnG) btnG.addEventListener('click', function () { applyView('grid'); });
  if (btnL) btnL.addEventListener('click', function () { applyView('list'); });

  // Restore saved view
  try {
    var saved = localStorage.getItem(STORAGE_KEY);
    if (saved) applyView(saved);
    else applyView('grid');
  } catch(e) { applyView('grid'); }

  // ── Live search (text filter) ────────────────────────────────────
  var input     = document.getElementById('dir-q');
  var noResults = document.getElementById('dirNoResults');
  var counter   = document.getElementById('dirCountLabel');
  var allTotal  = <?= $count ?>;
  var timer;

  function normalize(s) {
    return s.toLowerCase()
      .replace(/ą/g,'a').replace(/ć/g,'c').replace(/ę/g,'e')
      .replace(/ł/g,'l').replace(/ń/g,'n').replace(/ó/g,'o')
      .replace(/ś/g,'s').replace(/ź/g,'z').replace(/ż/g,'z');
  }

  function dirFilter() {
    if (!list) return;
    var term = normalize((input ? input.value : '').trim());
    var items = Array.from(list.children);
    var visible = 0;
    items.forEach(function (li) {
      var text = normalize(li.dataset.text || '');
      var show = !term || text.indexOf(term) !== -1;
      li.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    // Update count label
    if (counter) {
      if (!term) {
        counter.textContent = allTotal === 1 ? '1 osoba' : allTotal + ' ' + (allTotal < 5 ? 'osoby' : 'osób');
      } else {
        counter.textContent = visible === 0 ? 'Brak wyników'
          : visible + ' ' + (visible === 1 ? 'osoba' : (visible < 5 ? 'osoby' : 'osób')) + ' — wyniki filtrowania';
      }
    }
    // Show/hide empty state
    if (noResults) noResults.classList.toggle('d-none', visible > 0 || !term);
    if (list)      list.style.display = (visible === 0 && term) ? 'none' : '';
  }

  // Make dirFilter global for the "Wyczyść" button
  window.dirFilter = dirFilter;

  if (input) {
    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(dirFilter, 180);
    });
    // Prevent form submit on Enter when filtering live
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && input.value.trim()) {
        e.preventDefault();
        dirFilter();
      }
    });
  }
})();
</script>

<?php include __DIR__ . '/includes/footer_dir.php'; ?>
