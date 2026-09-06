<?php
/**
 * karty30/ti/dydaktyk/sale.php — Wykaz sal / lokalizacji (panel kierownika).
 *
 * CRUD nad k30_pl_rooms (istniejąca tabela z includes/ti_planner_ext.php —
 * dotąd bez ekranu administracyjnego). Sale wybiera się potem przy lekcji
 * pojedynczej, serii lekcji i regule „Zajęcia stałe" (index.php, _tab_lekcje.php).
 *
 * Usuwanie nie jest wystawione — sala używana w historii lekcji zostaje;
 * do wycofania z użycia służy przełącznik „Aktywna".
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';

karty30_migrate();
ti_planner_ext_migrate();

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }

$dyd_uid  = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '');

$form_err = [];
$op = $_POST['_op'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($op === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        if (trim($_POST['name'] ?? '') === '') {
            $form_err[] = 'Podaj nazwę sali.';
        } else {
            $rid = pl_room_save($_POST, $id ?: null);
            $audit_after = $_POST; unset($audit_after['_csrf'], $audit_after['_op'], $audit_after['id']);
            pl_audit('room', $rid, $id ? 'update' : 'create', [], $audit_after, $dyd_uid);
            flash_set('success', $id ? 'Zaktualizowano salę.' : 'Dodano salę.');
            header('Location: sale.php'); exit;
        }
    } elseif ($op === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $room = pl_room_get($id);
        if ($room) {
            pl_room_save(array_merge($room, ['is_active' => empty($room['is_active'])]), $id);
            flash_set('success', empty($room['is_active']) ? 'Sala aktywowana.' : 'Sala dezaktywowana.');
        }
        header('Location: sale.php'); exit;
    } elseif ($op === 'building_save') {
        $id = (int)($_POST['id'] ?? 0);
        if (trim($_POST['name'] ?? '') === '') {
            $form_err[] = 'Podaj nazwę budynku.';
        } else {
            $bid = pl_building_save($_POST, $id ?: null);
            $audit_after = $_POST; unset($audit_after['_csrf'], $audit_after['_op'], $audit_after['id']);
            pl_audit('building', $bid, $id ? 'update' : 'create', [], $audit_after, $dyd_uid);
            flash_set('success', $id ? 'Zaktualizowano budynek.' : 'Dodano budynek.');
            header('Location: sale.php'); exit;
        }
    } elseif ($op === 'building_toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $b = pl_building_get($id);
        if ($b) {
            pl_building_save(array_merge($b, ['is_active' => empty($b['is_active'])]), $id);
            flash_set('success', empty($b['is_active']) ? 'Budynek aktywowany.' : 'Budynek dezaktywowany.');
        }
        header('Location: sale.php'); exit;
    }
}

$rooms     = pl_rooms_list();
$buildings = pl_buildings_list();
// Ile nadchodzących terminów ma dana sala — kontekst przy dezaktywacji.
$upcoming_by_room = [];
foreach (db_all(
    "SELECT room_id, COUNT(*) AS n FROM k30_ti_sessions
      WHERE room_id IS NOT NULL AND lesson_date >= date('now') AND status NOT IN ('cancelled')
      GROUP BY room_id"
) as $r) { $upcoming_by_room[(int)$r['room_id']] = (int)$r['n']; }

$KP_TITLE  = 'Sale / lokalizacje — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'sale.php'; $KIER_LABEL = 'Sale / lokalizacje';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<?= flash_html() ?>

<?php if ($form_err): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($form_err as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-geo-alt me-2 text-primary"></i>Sale / lokalizacje</h1>
  <button class="btn btn-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#roomModal" id="btnAddRoom">
    <i class="bi bi-plus-lg me-1"></i>Dodaj salę
  </button>
</div>
<p class="text-body-secondary small">
  Sale zdefiniowane tu są dostępne przy dodawaniu lekcji (pojedynczej, serii lub reguły „Zajęcia stałe") w każdej
  grupie — patrz <a href="index.php?tab=lekcje">Zajęcia</a>. System sam odmawia zapisania terminu, jeśli wybrana sala
  jest w tym oknie czasowym już zajęta przez inną grupę. Zestawienia sal do rezerwacji i harmonogram lokalizacji
  wszystkich grup: <a href="wydruki.php">Wydruki</a>. Osobny raport per sala/budynek:
  <a href="raport_przestrzenie.php">Raport przestrzeni</a>.
</p>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-semibold bg-body-tertiary d-flex align-items-center">
    <i class="bi bi-building me-1"></i>Budynki
    <button class="btn btn-primary btn-sm ms-auto py-0 px-2" data-bs-toggle="modal" data-bs-target="#buildingModal" id="btnAddBuilding">
      <i class="bi bi-plus-lg me-1"></i>Dodaj budynek
    </button>
  </div>
  <?php if ($buildings): ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th scope="col">Nazwa</th>
          <th scope="col">Adres</th>
          <th scope="col" class="text-center">Sal</th>
          <th scope="col">Stan</th>
          <th scope="col" class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($buildings as $b): ?>
        <tr class="<?= $b['is_active'] ? '' : 'opacity-50' ?>">
          <td class="fw-semibold"><?= h($b['name']) ?></td>
          <td class="small"><?= trim((string)$b['address']) !== '' ? h($b['address']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-center"><?= (int)$b['room_count'] ?></td>
          <td>
            <?php if ($b['is_active']): ?><span class="badge text-bg-success">aktywny</span>
            <?php else: ?><span class="badge text-bg-secondary">nieaktywny</span><?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <button class="btn btn-sm btn-outline-secondary py-0 px-2" onclick='buildingEdit(<?= json_encode($b, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)'><i class="bi bi-pencil"></i></button>
            <form method="post" class="d-inline" onsubmit="return confirm('<?= $b['is_active'] ? 'Dezaktywować' : 'Aktywować' ?> budynek „<?= h(addslashes($b['name'])) ?>”<?= $b['room_count'] ? " ({$b['room_count']} sal)" : '' ?>?')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op" value="building_toggle_active">
              <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
              <button class="btn btn-sm <?= $b['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?> py-0 px-2">
                <i class="bi bi-<?= $b['is_active'] ? 'slash-circle' : 'check-circle' ?>"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="card-body text-body-secondary"><i class="bi bi-info-circle me-1"></i>Brak zdefiniowanych budynków — opcjonalne, sale działają też bez przypisania.</div>
  <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-semibold bg-body-tertiary"><i class="bi bi-list-ul me-1"></i>Zdefiniowane sale</div>
  <?php if ($rooms): ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th scope="col">Nazwa</th>
          <th scope="col">Budynek</th>
          <th scope="col">Lokalizacja</th>
          <th scope="col" class="text-center">Pojemność</th>
          <th scope="col" class="text-center">Stanowiska</th>
          <th scope="col">Wyposażenie</th>
          <th scope="col">Tryb</th>
          <th scope="col" class="text-center">Nadchodzące terminy</th>
          <th scope="col">Stan</th>
          <th scope="col" class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rooms as $r): $n_up = $upcoming_by_room[(int)$r['id']] ?? 0; ?>
        <tr class="<?= $r['is_active'] ? '' : 'opacity-50' ?>">
          <td class="fw-semibold">
            <?= h($r['name']) ?>
            <?php if (trim((string)($r['operator_label'] ?? '')) !== ''): ?>
            <div class="text-body-secondary fw-normal" style="font-size:.72rem" title="Nazwa zwyczajowa operatora przestrzeni">
              <i class="bi bi-quote me-1" aria-hidden="true"></i><?= h($r['operator_label']) ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="small"><?= trim((string)($r['building_name'] ?? '')) !== '' ? h($r['building_name']) : '<span class="text-muted">—</span>' ?></td>
          <td class="small">
            <?= trim((string)$r['location']) !== '' ? h($r['location']) : '<span class="text-muted">—</span>' ?>
            <?php if (trim((string)($r['short_label'] ?? '')) !== ''): ?>
            <span class="badge text-bg-light border ms-1" title="Skrót używany w siatkach planu i wydrukach"><?= h($r['short_label']) ?></span>
            <?php endif; ?>
          </td>
          <td class="text-center"><?= (int)$r['capacity'] ?></td>
          <td class="text-center"><?= (int)$r['workstations'] ?></td>
          <td class="small">
            <?php if ($r['has_projector']): ?><span class="badge text-bg-light border me-1"><i class="bi bi-projector me-1"></i>projektor</span><?php endif; ?>
            <?php if ($r['has_dual_mon']): ?><span class="badge text-bg-light border me-1"><i class="bi bi-display me-1"></i>2 monitory</span><?php endif; ?>
            <?php if ((int)$r['laptop_pool_cnt'] > 0): ?><span class="badge text-bg-light border"><i class="bi bi-laptop me-1"></i><?= (int)$r['laptop_pool_cnt'] ?> laptopów</span><?php endif; ?>
          </td>
          <td class="small"><?= h(['onsite'=>'stacjonarny','remote'=>'zdalny','hybrid'=>'hybrydowy','all'=>'dowolny'][$r['mode_support']] ?? $r['mode_support']) ?></td>
          <td class="text-center"><?= $n_up ?: '<span class="text-muted">0</span>' ?></td>
          <td>
            <?php if ($r['is_active']): ?><span class="badge text-bg-success">aktywna</span>
            <?php else: ?><span class="badge text-bg-secondary">nieaktywna</span><?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <button class="btn btn-sm btn-outline-secondary py-0 px-2" onclick='roomEdit(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)'><i class="bi bi-pencil"></i></button>
            <form method="post" class="d-inline" onsubmit="return confirm('<?= $r['is_active'] ? 'Dezaktywować' : 'Aktywować' ?> salę „<?= h(addslashes($r['name'])) ?>”<?= $n_up ? " ({$n_up} nadchodzących terminów)" : '' ?>?')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op" value="toggle_active">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm <?= $r['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?> py-0 px-2">
                <i class="bi bi-<?= $r['is_active'] ? 'slash-circle' : 'check-circle' ?>"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="card-body text-body-secondary"><i class="bi bi-info-circle me-1"></i>Brak zdefiniowanych sal. Kliknij „Dodaj salę".</div>
  <?php endif; ?>
</div>
</main>

<div class="modal fade" id="roomModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op" value="save">
        <input type="hidden" name="id" id="rf_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-geo-alt me-2"></i><span id="rf_title">Dodaj salę</span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label fw-semibold">Nazwa <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="name" id="rf_name" required maxlength="120" placeholder="np. Sala 12, Budynek B / p.2">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Budynek</label>
            <select class="form-select" name="building_id" id="rf_building">
              <option value="0">— brak / nie dotyczy —</option>
              <?php foreach ($buildings as $b): ?>
              <option value="<?= (int)$b['id'] ?>"><?= h($b['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Opcjonalnie — grupuje sale należące do tego samego budynku (raporty per budynek).</div>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Lokalizacja</label>
            <input type="text" class="form-control" name="location" id="rf_location" maxlength="200" placeholder="np. ul. Przykładowa 5, budynek B, piętro 2">
            <div class="form-text">Adres budynku, nazwa ośrodka lub numer piętra/budynku — pokazuje się w planach i wydrukach.</div>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Skrót</label>
            <input type="text" class="form-control" name="short_label" id="rf_short_label" maxlength="40" placeholder="np. KR-Centr">
            <div class="form-text">Opcjonalnie — gdy ustawiony, zastępuje pełną nazwę+lokalizację w zwięzłych widokach
              (siatki planu zajęć, wydruki). Wybór sali przy dodawaniu lekcji nadal pokazuje pełną nazwę.</div>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Nazwa zwyczajowa (operatora przestrzeni)</label>
            <input type="text" class="form-control" name="operator_label" id="rf_operator_label" maxlength="120" placeholder="np. nazwa używana przez najemcę/właściciela lokalu">
            <div class="form-text">Opcjonalnie — jak tę przestrzeń nazywa jej operator (właściciel/najemca), gdy różni się
              od naszej wewnętrznej nazwy/skrótu. Widoczne tylko na wykazie sal, nie w wyborze sali dla lekcji.</div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Pojemność</label>
              <input type="number" class="form-control" name="capacity" id="rf_capacity" min="1" value="20">
            </div>
            <div class="col-6">
              <label class="form-label">Stanowiska komputerowe</label>
              <input type="number" class="form-control" name="workstations" id="rf_workstations" min="0" value="0">
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <div class="form-check form-switch mt-4">
                <input class="form-check-input" type="checkbox" role="switch" name="has_projector" id="rf_projector" value="1">
                <label class="form-check-label" for="rf_projector">Projektor</label>
              </div>
            </div>
            <div class="col-6">
              <div class="form-check form-switch mt-4">
                <input class="form-check-input" type="checkbox" role="switch" name="has_dual_mon" id="rf_dualmon" value="1">
                <label class="form-check-label" for="rf_dualmon">Dwa monitory</label>
              </div>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label">Pula laptopów w sali</label>
            <input type="number" class="form-control" name="laptop_pool_cnt" id="rf_laptops" min="0" value="0">
          </div>
          <div class="mb-2">
            <label class="form-label">Tryb obsługiwany</label>
            <select class="form-select" name="mode_support" id="rf_mode">
              <option value="onsite">Stacjonarny</option>
              <option value="remote">Zdalny</option>
              <option value="hybrid">Hybrydowy</option>
              <option value="all">Dowolny</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Uwagi</label>
            <textarea class="form-control" name="notes" id="rf_notes" rows="2" maxlength="500"></textarea>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="rf_active" value="1" checked>
            <label class="form-check-label" for="rf_active">Aktywna (widoczna przy wyborze sali dla lekcji)</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary">Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="buildingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op" value="building_save">
        <input type="hidden" name="id" id="bf_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-building me-2"></i><span id="bf_title">Dodaj budynek</span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label fw-semibold">Nazwa <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="name" id="bf_name" required maxlength="150" placeholder="np. Centrum Reymonta 20">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Adres</label>
            <input type="text" class="form-control" name="address" id="bf_address" maxlength="200" placeholder="np. ul. Reymonta 20, Kraków">
          </div>
          <div class="mb-2">
            <label class="form-label">Uwagi</label>
            <textarea class="form-control" name="notes" id="bf_notes" rows="2" maxlength="500"></textarea>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="bf_active" value="1" checked>
            <label class="form-check-label" for="bf_active">Aktywny (widoczny przy przypisywaniu sali do budynku)</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary">Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function roomEdit(r) {
  document.getElementById('rf_id').value = r.id;
  document.getElementById('rf_name').value = r.name;
  document.getElementById('rf_building').value = r.building_id || '0';
  document.getElementById('rf_location').value = r.location || '';
  document.getElementById('rf_short_label').value = r.short_label || '';
  document.getElementById('rf_operator_label').value = r.operator_label || '';
  document.getElementById('rf_capacity').value = r.capacity;
  document.getElementById('rf_workstations').value = r.workstations;
  document.getElementById('rf_projector').checked = !!parseInt(r.has_projector, 10);
  document.getElementById('rf_dualmon').checked = !!parseInt(r.has_dual_mon, 10);
  document.getElementById('rf_laptops').value = r.laptop_pool_cnt;
  document.getElementById('rf_mode').value = r.mode_support;
  document.getElementById('rf_notes').value = r.notes || '';
  document.getElementById('rf_active').checked = !!parseInt(r.is_active, 10);
  document.getElementById('rf_title').textContent = 'Edytuj salę';
  new bootstrap.Modal(document.getElementById('roomModal')).show();
}
document.getElementById('btnAddRoom').addEventListener('click', function() {
  document.getElementById('rf_id').value = '0';
  document.getElementById('rf_name').value = '';
  document.getElementById('rf_building').value = '0';
  document.getElementById('rf_location').value = '';
  document.getElementById('rf_short_label').value = '';
  document.getElementById('rf_operator_label').value = '';
  document.getElementById('rf_capacity').value = '20';
  document.getElementById('rf_workstations').value = '0';
  document.getElementById('rf_projector').checked = false;
  document.getElementById('rf_dualmon').checked = false;
  document.getElementById('rf_laptops').value = '0';
  document.getElementById('rf_mode').value = 'onsite';
  document.getElementById('rf_notes').value = '';
  document.getElementById('rf_active').checked = true;
  document.getElementById('rf_title').textContent = 'Dodaj salę';
});

function buildingEdit(b) {
  document.getElementById('bf_id').value = b.id;
  document.getElementById('bf_name').value = b.name;
  document.getElementById('bf_address').value = b.address || '';
  document.getElementById('bf_notes').value = b.notes || '';
  document.getElementById('bf_active').checked = !!parseInt(b.is_active, 10);
  document.getElementById('bf_title').textContent = 'Edytuj budynek';
  new bootstrap.Modal(document.getElementById('buildingModal')).show();
}
document.getElementById('btnAddBuilding').addEventListener('click', function() {
  document.getElementById('bf_id').value = '0';
  document.getElementById('bf_name').value = '';
  document.getElementById('bf_address').value = '';
  document.getElementById('bf_notes').value = '';
  document.getElementById('bf_active').checked = true;
  document.getElementById('bf_title').textContent = 'Dodaj budynek';
});
</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
