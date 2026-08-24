<?php
/**
 * crm/settings/owner_rules.php — automatyczne przypisywanie opiekunów kartotek.
 *
 * Reguły („kontakty z tego źródła prowadzi X") plus rozdział po równo dla tego,
 * czego reguły nie złapały. Uruchomienie ma podgląd: widać, kto co dostanie,
 * zanim cokolwiek zostanie zapisane.
 *
 * Opiekunem może być wyłącznie osoba z dostępem do CRM — przypisanie komuś,
 * kto nie wejdzie do modułu, byłoby tylko wpisem w bazie.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_owner_rules.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('contacts', 'write');
crm_migrate();

$PAGE_TITLE = 'Opiekunowie — automat';
$preview    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');

    if ($op === 'rule_save') {
        $id = crm_owner_rule_save([
            'match_type'  => $_POST['match_type']  ?? 'source',
            'match_value' => $_POST['match_value'] ?? '',
            'owner_id'    => $_POST['owner_id']    ?? 0,
            'priority'    => $_POST['priority']    ?? 100,
            'is_active'   => !empty($_POST['is_active']),
        ], (int)($_POST['id'] ?? 0) ?: null);
        flash_set($id ? 'success' : 'danger', $id
            ? 'Reguła zapisana.'
            : 'Nie zapisano — sprawdź, czy wskazany opiekun ma dostęp do CRM i czy podałeś wartość warunku.');
        header('Location: ' . APP_URL . '/crm/settings/owner_rules.php'); exit;
    }

    if ($op === 'rule_delete') {
        crm_owner_rule_delete((int)($_POST['id'] ?? 0));
        flash_set('success', 'Reguła usunięta.');
        header('Location: ' . APP_URL . '/crm/settings/owner_rules.php'); exit;
    }

    if ($op === 'rr_save') {
        crm_owner_roundrobin_save((array)($_POST['rr'] ?? []));
        flash_set('success', 'Lista do rozdziału po równo zapisana.');
        header('Location: ' . APP_URL . '/crm/settings/owner_rules.php'); exit;
    }

    if ($op === 'run' || $op === 'run_apply') {
        $preview = crm_owner_assign_bulk([
            'apply'      => $op === 'run_apply',
            'overwrite'  => !empty($_POST['overwrite']),
            'roundrobin' => !empty($_POST['use_rr']),
            'limit'      => 1000,
        ]);
        if ($op === 'run_apply') {
            flash_set('success', 'Przypisano opiekunów: ' . count($preview['rows']) . '.');
            header('Location: ' . APP_URL . '/crm/settings/owner_rules.php'); exit;
        }
    }
}

$edit_param = trim((string)($_GET['edit'] ?? ''));
$edit_id    = ctype_digit($edit_param) ? (int)$edit_param : 0;
$rules_all  = crm_owner_rules(false);
$edit       = null;
foreach ($rules_all as $r) if ((int)$r['id'] === $edit_id) $edit = $r;
$show_form  = $edit_param !== '' && ($edit_id === 0 || $edit !== null);

$owners = crm_owner_candidates();
$rr_now  = array_map(static fn($u) => (int)$u['id'], crm_owner_roundrobin_users());
$types   = crm_owner_match_types();

// Działania (projekty) do reguły „per działanie" — wybór z listy zamiast wpisywania ID
$actions_list = [];
try {
    $actions_list = db_all("SELECT id, nazwa, typ, status FROM actions ORDER BY data_od DESC, nazwa LIMIT 200");
} catch (\Throwable $e) {}

$no_owner = 0;
try {
    $no_owner = (int)(db_one("SELECT COUNT(*) AS n FROM crm_contacts
                               WHERE crm_active=1 AND (owner_id IS NULL OR owner_id=0)")['n'] ?? 0);
} catch (\Throwable $e) {}

include dirname(__DIR__) . '/includes/header_crm.php';
?>
<style>
.ow-card { background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1rem 1.15rem;margin-bottom:.9rem }
.ow-tbl { width:100%;border-collapse:collapse;font-size:.84rem }
.ow-tbl th { font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:#9CA3AF;text-align:left;
  padding:.4rem .5rem;border-bottom:1px solid #E5E7EB }
.ow-tbl td { padding:.45rem .5rem;border-bottom:1px solid #F3F4F6 }
.ow-hint { font-size:.75rem;color:#9CA3AF }
</style>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-person-gear me-2" style="color:#0176D3"></i>Opiekunowie — automat</h1>
  <span class="text-muted small">reguły przypisania + rozdział po równo</span>
  <a class="btn btn-crm-primary btn-sm ms-auto" href="?edit=new"><i class="bi bi-plus-lg me-1"></i>Nowa reguła</a>
</div>

<div class="ow-card d-flex align-items-center gap-3 flex-wrap">
  <div>
    <div style="font-size:1.4rem;font-weight:700;line-height:1"><?= (int)$no_owner ?></div>
    <div class="ow-hint">kartotek bez opiekuna</div>
  </div>
  <form method="post" class="d-flex align-items-center gap-2 flex-wrap ms-auto">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="use_rr" id="useRr" value="1" checked>
      <label class="form-check-label small" for="useRr">rozdziel resztę po równo</label>
    </div>
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="overwrite" id="ovw" value="1">
      <label class="form-check-label small" for="ovw" title="Domyślnie ruszamy tylko kartoteki bez opiekuna">
        nadpisz istniejących opiekunów
      </label>
    </div>
    <button class="btn btn-crm-outline btn-sm" name="_op" value="run">
      <i class="bi bi-eye me-1"></i>Podgląd
    </button>
    <button class="btn btn-crm-primary btn-sm" name="_op" value="run_apply"
            onclick="return confirm('Przypisać opiekunów według reguł? Podgląd pokazuje, co się stanie.')">
      <i class="bi bi-play-fill me-1"></i>Przypisz teraz
    </button>
  </form>
</div>

<?php if ($preview !== null): ?>
<div class="ow-card">
  <p class="fw-bold mb-1" style="font-size:.9rem">Podgląd — nic jeszcze nie zapisano</p>
  <p class="ow-hint mb-2">
    Sprawdzono kartotek: <strong><?= (int)$preview['total'] ?></strong> ·
    z reguł: <strong><?= (int)$preview['matched'] ?></strong> ·
    po równo: <strong><?= (int)$preview['roundrobin'] ?></strong> ·
    bez zmian: <strong><?= (int)$preview['skipped'] ?></strong>
  </p>
  <?php if ($preview['rows']): ?>
  <div class="table-responsive" style="max-height:340px;overflow-y:auto">
    <table class="ow-tbl">
      <thead><tr><th>Kartoteka</th><th>Opiekun</th><th>Dlaczego</th></tr></thead>
      <tbody>
        <?php $names = []; foreach ($owners as $u) $names[(int)$u['id']] = $u['name']; ?>
        <?php foreach (array_slice($preview['rows'], 0, 300) as $row): ?>
        <tr>
          <td><a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$row['id'] ?>"><?= h($row['name']) ?></a></td>
          <td><?= h($names[(int)$row['owner_id']] ?? ('#' . (int)$row['owner_id'])) ?></td>
          <td class="ow-hint"><?= h($row['why']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($preview['rows']) > 300): ?>
  <p class="ow-hint mt-1">Pokazano pierwsze 300 z <?= count($preview['rows']) ?>.</p>
  <?php endif; ?>
  <?php else: ?>
  <p class="ow-hint mb-0">Nic do przypisania — albo wszystko ma opiekuna, albo żadna reguła nie pasuje.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="ow-card">
      <p class="fw-bold mb-2" style="font-size:.9rem">Reguły (od najwyższego priorytetu)</p>
      <?php if (!$rules_all): ?>
      <p class="ow-hint mb-0">
        Brak reguł. Pierwsza, która zwykle ma sens: źródło <code>import_rejestr</code> → osoba prowadząca
        pozyskiwanie partnerów. Resztę oddaj rozdziałowi po równo.
      </p>
      <?php else: ?>
      <table class="ow-tbl">
        <thead><tr><th style="width:52px">Prio</th><th>Warunek</th><th>Opiekun</th><th style="width:80px"></th></tr></thead>
        <tbody>
          <?php foreach ($rules_all as $r): ?>
          <tr<?= (int)$r['is_active'] !== 1 ? ' style="opacity:.55"' : '' ?>>
            <td><?= (int)$r['priority'] ?></td>
            <td>
              <?= h($types[$r['match_type']]['label'] ?? $r['match_type']) ?>
              <?php if (trim((string)$r['match_value']) !== ''): ?>
              <code><?= h($r['match_value']) ?></code>
              <?php endif; ?>
              <?php if ((int)$r['is_active'] !== 1): ?><span class="ow-hint">(wyłączona)</span><?php endif; ?>
            </td>
            <td><?= h($r['owner_name'] ?? ('#' . (int)$r['owner_id'])) ?></td>
            <td class="text-end">
              <a class="btn btn-outline-secondary btn-sm py-0 px-2" href="?edit=<?= (int)$r['id'] ?>"><i class="bi bi-pencil"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć regułę?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_op" value="rule_delete">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-outline-danger btn-sm py-0 px-2"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="ow-card">
      <p class="fw-bold mb-1" style="font-size:.9rem">Rozdział po równo</p>
      <p class="ow-hint mb-2">
        Dotyczy tylko kartotek, których nie złapała żadna reguła. Zaznacz osoby, między które
        mają iść po kolei. Lista zawiera wyłącznie użytkowników z dostępem do CRM.
      </p>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="rr_save">
        <div class="row g-1">
          <?php foreach ($owners as $u): ?>
          <div class="col-sm-6">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="rr[]" value="<?= (int)$u['id'] ?>"
                     id="rr<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $rr_now, true) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="rr<?= (int)$u['id'] ?>"><?= h($u['name']) ?></label>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-crm-outline btn-sm mt-2"><i class="bi bi-check-lg me-1"></i>Zapisz listę</button>
      </form>
    </div>
  </div>

  <div class="col-lg-5">
    <?php if ($show_form): ?>
    <div class="ow-card">
      <p class="fw-bold mb-2" style="font-size:.9rem"><?= $edit ? 'Edycja reguły' : 'Nowa reguła' ?></p>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="rule_save">
        <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">

        <div class="mb-2">
          <label class="form-label small fw-semibold" for="mt">Warunek</label>
          <select class="form-select form-select-sm" id="mt" name="match_type">
            <?php foreach ($types as $tk => $tv): ?>
            <option value="<?= h($tk) ?>" <?= ($edit['match_type'] ?? 'source') === $tk ? 'selected' : '' ?>>
              <?= h($tv['label']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="ow-hint">Podpowiedzi wartości: <?= h(implode(' · ', array_map(
              static fn($t) => $t['label'] . ' — ' . $t['hint'], array_slice($types, 0, 3)))) ?></div>
        </div>

        <div class="mb-2">
          <label class="form-label small fw-semibold" for="mv">Wartość</label>
          <input class="form-control form-control-sm" id="mv" name="match_value" maxlength="120"
                 value="<?= h($edit['match_value'] ?? '') ?>" placeholder="np. import_rejestr">
          <div class="ow-hint">Puste tylko dla warunków „Każda kartoteka" i „Krytyczny operacyjnie".</div>

          <?php if ($actions_list): ?>
          <!-- Przy regule „per działanie" wartością jest ID projektu — wybór z listy
               jest mniej podatny na pomyłkę niż przepisywanie numeru. -->
          <div id="mvActionRow" class="mt-2" hidden>
            <label class="form-label small fw-semibold" for="mvAction">Wybierz działanie</label>
            <select class="form-select form-select-sm" id="mvAction">
              <option value="">— wskaż projekt —</option>
              <?php foreach ($actions_list as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= (string)($edit['match_value'] ?? '') === (string)$a['id'] ? 'selected' : '' ?>>
                <?= h($a['nazwa']) ?><?= $a['typ'] ? ' · ' . h($a['typ']) : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
        </div>

        <div class="mb-2">
          <label class="form-label small fw-semibold" for="ow">Opiekun</label>
          <select class="form-select form-select-sm" id="ow" name="owner_id" required>
            <option value="">— wybierz —</option>
            <?php foreach ($owners as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)($edit['owner_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
              <?= h($u['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="ow-hint">Tylko osoby z dostępem do CRM — inne nie zobaczyłyby przypisanych kartotek.</div>
        </div>

        <div class="mb-2">
          <label class="form-label small fw-semibold" for="pr">Priorytet</label>
          <input type="number" min="1" max="999" class="form-control form-control-sm" id="pr" name="priority"
                 value="<?= (int)($edit['priority'] ?? 100) ?>">
          <div class="ow-hint">Niższa liczba = wcześniej sprawdzana. Reguła „każda kartoteka" powinna mieć najwyższą.</div>
        </div>

        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="is_active" id="ia" value="1"
                 <?= (!$edit || (int)$edit['is_active'] === 1) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="ia">Aktywna</label>
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-crm-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
          <a class="btn btn-outline-secondary btn-sm" href="<?= APP_URL ?>/crm/settings/owner_rules.php">Anuluj</a>
        </div>
      </form>
    </div>
    <?php else: ?>
    <div class="ow-card ow-hint">
      <strong class="d-block mb-1 text-dark">Jak to działa</strong>
      Reguły sprawdzane są po kolei według priorytetu; wygrywa pierwsza pasująca. Czego reguły nie
      złapią, trafia do rozdziału po równo — o ile ktoś jest na liście. Domyślnie ruszamy wyłącznie
      kartoteki bez opiekuna: nadpisywanie trzeba włączyć świadomie, żeby automat nie odbierał
      ludziom prowadzonych kontaktów. Każde przypisanie zostawia notatkę w kartotece.
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
/* Warunek „per działanie" korzysta z listy projektów; wybór wpisuje ID do pola
   wartości, żeby zapis szedł tą samą ścieżką co pozostałe reguły. */
(function () {
  var mt  = document.getElementById('mt');
  var mv  = document.getElementById('mv');
  var row = document.getElementById('mvActionRow');
  var sel = document.getElementById('mvAction');
  if (!mt || !mv) return;

  function sync() {
    var isAction = mt.value === 'action';
    if (row) row.hidden = !isAction;
    var noValue = (mt.value === 'any' || mt.value === 'critical');
    mv.disabled = noValue;
    mv.placeholder = noValue ? 'ten warunek nie potrzebuje wartości'
                   : (isAction ? 'ID działania — wybierz z listy niżej' : 'np. import_rejestr');
    if (noValue) mv.value = '';
  }
  mt.addEventListener('change', sync);
  if (sel) sel.addEventListener('change', function () { if (this.value) mv.value = this.value; });
  sync();
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
