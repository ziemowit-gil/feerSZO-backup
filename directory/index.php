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
// Jednostki wewnętrzne z liczbą aktywnych osób (kafle)
try {
    $units = db_all("SELECT u.id, u.name, u.code, u.parent_id,
               (SELECT COUNT(DISTINCT m.user_id) FROM org_members m JOIN users mu ON mu.id=m.user_id AND mu.is_active=1
                 WHERE m.unit_id=u.id AND m.status='active' AND (m.valid_to IS NULL OR m.valid_to >= date('now'))) AS cnt
        FROM org_units u
        WHERE u.status='active' AND COALESCE(u.kind,'internal') <> 'external'
        ORDER BY u.sort_order, u.name");
} catch (\Throwable $e) {
    try { $units = db_all("SELECT id, name, '' AS code, NULL AS parent_id, 0 AS cnt FROM org_units WHERE status='active' ORDER BY name"); }
    catch (\Throwable $e2) { $units = []; }
}
$unit_cur = null;
foreach ($units as $u) if ((int)$u['id'] === $unit_id) $unit_cur = $u;

$count = count($people);
$result_label = $count === 0 ? 'Brak wyników'
    : ($count === 1 ? '1 osoba' : $count . ' ' . ($count % 10 >= 2 && $count % 10 <= 4 && ($count % 100 < 10 || $count % 100 >= 20) ? 'osoby' : 'osób'));

// Własny profil — kafel „Ty” z postępem wymogu (modules/directory_profile)
require_once dirname(__DIR__) . '/modules/katalog_metro/lib/katalogMetro.php';
require_once dirname(__DIR__) . '/modules/directory_profile/lib/directoryProfile.php';
$cu    = current_user();
$me    = $cu ? directory_get_profile((int)$cu['id']) : null;
$meSt  = $me ? directoryProfileStatus((int)$cu['id'], $me) : null;
$base  = APP_URL . '/directory/';
$qsKeep = fn(array $add) => $base . '?' . http_build_query(array_filter(array_merge(['q' => $q, 'unit_id' => $unit_id ?: null, 'sort' => $sort !== 'last_name' ? $sort : null], $add), fn($v) => $v !== null && $v !== ''));

include __DIR__ . '/includes/header_dir.php';
katalogMetroAssets();
?>
<div class="pvm ktm">

  <div class="mt-head">
    <div>
      <div class="mt-kicker">Katalog współpracowników<?= defined('ORG_NAME') ? ' · ' . h(ORG_NAME) : '' ?></div>
      <h1 class="mt-title"><?= $unit_cur ? h($unit_cur['name']) : 'Kto jest kim' ?></h1>
      <p class="mt-lead">Znajdź osobę, sprawdź jej jednostkę i dane kontaktowe.</p>
      <form method="get" role="search" aria-label="Szukaj współpracownika" class="ktm-search">
        <label for="ktm-q" class="visually-hidden">Imię, nazwisko, jednostka lub e-mail</label>
        <input type="search" name="q" id="ktm-q" value="<?= h($q) ?>" placeholder="Imię, nazwisko, jednostka, e-mail…" autocomplete="off">
        <?php if ($unit_id): ?><input type="hidden" name="unit_id" value="<?= (int)$unit_id ?>"><?php endif; ?>
        <label for="ktm-sort" class="visually-hidden">Sortowanie</label>
        <select name="sort" id="ktm-sort" onchange="this.form.submit()">
          <option value="last_name"  <?= $sort === 'last_name'  ? 'selected' : '' ?>>Nazwisko A–Z</option>
          <option value="first_name" <?= $sort === 'first_name' ? 'selected' : '' ?>>Imię A–Z</option>
          <option value="unit_name"  <?= $sort === 'unit_name'  ? 'selected' : '' ?>>Jednostka</option>
        </select>
        <button type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Szukaj</span></button>
      </form>
    </div>
  </div>

  <?php if (!$q && !$unit_id && $me): ?>
  <section class="mt-grp" aria-labelledby="ktm-ty">
    <h2 class="mt-grp-h" id="ktm-ty">Ty</h2>
    <div class="mt-grid">
      <?php
      $me['phone'] = $me['phone_display'] ?? '';
      katalogPersonTile($me, ['size' => 'l', 'color' => KATALOG_ACCENT,
          'sub' => implode(' · ', array_filter([$me['position_name'], $me['unit_name']])) ?: 'mój profil w katalogu']);
      if ($meSt && $meSt['total']) {
          panelMetroTile(['href' => APP_URL . '/directory/profile_edit.php', 'icon' => 'bi-person-lines-fill',
              'label' => $meSt['complete'] ? 'Profil uzupełniony' : 'Uzupełnij profil', 'val' => $meSt['pct'] . '%',
              'sub' => $meSt['complete'] ? 'dziękujemy' : 'brakuje: ' . implode(', ', $meSt['missing']),
              'tag' => !$meSt['complete'] && $meSt['mode'] !== 'off' ? 'wymagane' : '',
              'color' => $meSt['complete'] ? 'green' : ($meSt['mode'] === 'require' ? 'red' : 'orange'), 'size' => 'w']);
      } else {
          panelMetroTile(['href' => APP_URL . '/directory/profile_edit.php', 'icon' => 'bi-pencil-square', 'label' => 'Edytuj profil',
              'sub' => 'zdjęcie, opis, umiejętności', 'color' => 'orange', 'size' => 'w']);
      }
      panelMetroTile(['href' => APP_URL . '/directory/org_chart.php', 'icon' => 'bi-diagram-3', 'label' => 'Struktura',
          'val' => (string)count($units), 'sub' => 'jednostek', 'color' => 'dark']);
      panelMetroTile(['href' => '#ktm-osoby-h', 'icon' => 'bi-people-fill', 'label' => 'Współpracownicy',
          'val' => (string)$count, 'sub' => 'w katalogu', 'color' => 'teal']);
      ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($units): ?>
  <section class="mt-grp" aria-labelledby="ktm-jedn" data-ktm-filter>
    <div class="ktm-bar">
      <h2 class="mt-grp-h" id="ktm-jedn">Jednostki</h2>
      <?php if ($unit_id): ?><a class="ktm-chip" href="<?= h($qsKeep(['unit_id' => null])) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i>Wszystkie jednostki</a><?php endif; ?>
    </div>
    <div class="mt-grid mt-grid--sm">
      <?php foreach ($units as $u)
          katalogUnitTile($u, $qsKeep(['unit_id' => (int)$u['id'] === $unit_id ? null : (int)$u['id']]) . '#ktm-osoby-h',
              ['on' => (int)$u['id'] === $unit_id]); ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="mt-grp" aria-labelledby="ktm-osoby-h" data-ktm-filter>
    <div class="ktm-bar">
      <h2 class="mt-grp-h" id="ktm-osoby-h"><?= $unit_cur ? 'Osoby · ' . h($unit_cur['name']) : 'Osoby' ?></h2>
      <span class="ktm-count" aria-live="polite"><?= h($result_label) ?><?= $q ? ' dla „' . h($q) . '”' : '' ?> <span id="ktm-live"></span></span>
    </div>
    <?php if ($unit_cur): ?>
    <p style="margin:-.2rem 0 .6rem"><a class="ktm-chip" href="<?= APP_URL ?>/directory/org_chart.php?unit=<?= (int)$unit_cur['id'] ?>#jednostka"><i class="bi bi-diagram-3" aria-hidden="true"></i>Karta jednostki</a></p>
    <?php endif; ?>
    <?php if (!$people): ?>
    <div class="ktm-empty" role="status">
      <i class="bi bi-person-x d-block mb-2" aria-hidden="true" style="font-size:2.5rem;opacity:.4"></i>
      Nie znaleziono nikogo.<?php if ($q || $unit_id): ?> <a href="<?= $base ?>">Wyczyść filtry</a><?php endif; ?>
    </div>
    <?php else: ?>
    <div class="mt-grid" role="list">
      <?php foreach ($people as $person) {
          $person['phone'] = $person['phone_public']
              ? ($person['phone_direct'] ?: $person['phone_mobile'] ?: ($person['phone_number'] ?? '')) : '';
          echo '<div role="listitem" style="display:contents">';
          katalogPersonTile($person, ['hideUnit' => (bool)$unit_cur]);
          echo '</div>';
      } ?>
    </div>
    <?php endif; ?>
  </section>
</div>

<?php katalogFilterScript(); ?>

<?php include __DIR__ . '/includes/footer_dir.php'; ?>
