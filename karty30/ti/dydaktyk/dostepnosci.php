<?php
/**
 * karty30/ti/dydaktyk/dostepnosci.php — Dostępności prowadzących (kierownik).
 *
 * Zarządzanie oknami dostępności WSZYSTKICH prowadzących z panelu kierownika:
 * macierz tygodnia (z ważnością okien i statusem), zatwierdzanie szkiców
 * (pojedynczo i hurtem), dodawanie/usuwanie okien dowolnemu prowadzącemu —
 * także okien czasowych („różna dostępność w różnych tygodniach”: to samo
 * dzień+godziny, inny zakres obowiązywania) i jednorazowych (od = do).
 *
 * Połączenie z generatorem godzin: rekrutacja.php?tab=grupy korzysta z tych
 * okien (tylko zatwierdzonych, z ważnością per dzień), a tury z włączonym
 * auto-generowaniem dogenerowują terminy cronem.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_rekrutacja.php';

karty30_migrate();
ti_planner_ext_migrate();
ti_rk_migrate();

$me       = dyd_require();
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '');
if (!dyd_is_staff()) { header('Location: index.php'); exit; }   // ekran kierownika

/* ══════════════════════════════════════════════════════════════════════════
   POST
   ══════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = $_POST['_op'] ?? '';
    $iid = (int)($_POST['instructor_id'] ?? 0);

    if ($op === 'win_add') {
        $dw = (int)($_POST['day_of_week'] ?? -1);
        $vf = trim($_POST['valid_from'] ?? '');
        $vt = trim($_POST['valid_to'] ?? '');
        if ($vf !== '' && $vf === $vt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $vf)) {
            $dw = (int)date('w', strtotime($vf));   // jednorazowa: dzień z daty
        }
        if (!ti_avail_add($iid, $dw, $_POST['time_from'] ?? '', $_POST['time_to'] ?? '',
                          in_array($_POST['status'] ?? '', ['draft','approved'], true) ? $_POST['status'] : 'approved',
                          $vf, $vt, trim($_POST['notes'] ?? ''))) {
            flash_set('danger', 'Podaj poprawny dzień, godziny od–do i zakres dat (od ≤ do).');
        } else {
            flash_set('success', 'Dodano okno dostępności.');
        }
        header('Location: dostepnosci.php?instr=' . $iid); exit;
    }

    // Edycja godzin/dnia/ważności istniejącego okna (bez usuwania i dodawania od nowa)
    if ($op === 'win_edit') {
        $dw = (int)($_POST['day_of_week'] ?? -1);
        $vf = trim($_POST['valid_from'] ?? '');
        $vt = trim($_POST['valid_to'] ?? '');
        if ($vf !== '' && $vf === $vt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $vf)) {
            $dw = (int)date('w', strtotime($vf));   // jednorazowa: dzień z daty
        }
        if (!ti_avail_update((int)($_POST['avail_id'] ?? 0), $iid, $dw,
                             $_POST['time_from'] ?? '', $_POST['time_to'] ?? '',
                             $vf, $vt, trim($_POST['notes'] ?? ''))) {
            flash_set('danger', 'Nie zapisano zmian: podaj poprawny dzień, godziny od–do i zakres dat (od ≤ do).');
        } else {
            flash_set('success', 'Okno zaktualizowane.');
        }
        header('Location: dostepnosci.php?instr=' . $iid); exit;
    }

    if ($op === 'win_delete') {
        ti_avail_delete((int)($_POST['avail_id'] ?? 0), $iid);
        flash_set('success', 'Usunięto okno.');
        header('Location: dostepnosci.php?instr=' . $iid); exit;
    }

    if ($op === 'win_status') {
        $status = in_array($_POST['status'] ?? '', ['draft','approved'], true) ? $_POST['status'] : 'approved';
        ti_avail_set_status((int)($_POST['avail_id'] ?? 0), $iid, $status);
        flash_set('success', 'Status okna zmieniony.');
        header('Location: dostepnosci.php?instr=' . $iid); exit;
    }

    if ($op === 'approve_all') {
        $n = ti_avail_approve_all($iid);
        flash_set($n ? 'success' : 'info', $n ? "Zatwierdzono $n okien." : 'Brak szkiców do zatwierdzenia.');
        header('Location: dostepnosci.php?instr=' . $iid); exit;
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   DANE
   ══════════════════════════════════════════════════════════════════════════ */
$instructors = db_all(
    "SELECT u.id, u.name, u.email FROM users u
     WHERE u.is_active = 1
       AND (u.id IN (SELECT instructor_id FROM k30_ti_courses WHERE instructor_id IS NOT NULL)
         OR u.id IN (SELECT user_id FROM k30_ti_instructor_accounts))
     ORDER BY u.name COLLATE NOCASE");

$matrix = []; $drafts = [];
if ($instructors) {
    $ph = implode(',', array_fill(0, count($instructors), '?'));
    foreach (db_all(
        "SELECT * FROM k30_ti_instructor_availability
          WHERE is_active=1 AND instructor_id IN ($ph)
          ORDER BY time_from",
        array_map(fn($i) => (int)$i['id'], $instructors)) as $w) {
        $matrix[(int)$w['instructor_id']][(int)$w['day_of_week']][] = $w;
        if (($w['status'] ?? '') === 'draft') $drafts[(int)$w['instructor_id']] = ($drafts[(int)$w['instructor_id']] ?? 0) + 1;
    }
}

$sel_instr = (int)($_GET['instr'] ?? 0);
// Prowadzący wprost z users — heurystyka listy (kursy/konta dydaktyka) nie może
// „zgubić” edycji: kliknięty prowadzący ma się otworzyć zawsze, póki jest aktywny.
$sel = $sel_instr
    ? db_one("SELECT id, name, email FROM users WHERE id=? AND is_active=1", [$sel_instr])
    : null;
$sel_windows = $sel ? ti_instructor_availability($sel_instr) : [];

$dow_order = [1,2,3,4,5,6,0];

/* ══════════════════════════════════════════════════════════════════════════
   HTML
   ══════════════════════════════════════════════════════════════════════════ */
$KP_TITLE  = 'Dostępności prowadzących — Panel dydaktyka';
$KP_TOPBAR = [
    'brand'  => 'Panel dydaktyka',
    'icon'   => 'easel2',
    'user'   => $dyd_name,
    'logout' => 'logout.php',
];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'dostepnosci.php'; $KIER_LABEL = 'Dostępności prowadzących';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">

<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <div>
    <h1 class="h4 fw-bold mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>Dostępności prowadzących</h1>
    <p class="text-body-secondary small mb-0">
      Okna tygodniowe z ważnością — z nich generator tworzy terminy zapisów (tylko zatwierdzone)
    </p>
  </div>
  <div class="ms-auto d-flex gap-2">
    <a href="rekrutacja_print.php?what=dostepnosci" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"
       title="Zestawienie wszystkich okien z rubrykami podpisu kierownika">
      <i class="bi bi-printer me-1"></i>Drukuj do podpisu</a>
    <a href="rekrutacja.php?tab=grupy" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-magic me-1"></i>Generator terminów</a>
    <a href="index.php?tab=grupy" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Panel</a>
  </div>
</div>

<?= flash_html() ?>

<!-- ── Macierz wszystkich prowadzących ─────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Macierz dostępności prowadzących</caption>
      <thead>
        <tr>
          <th scope="col" style="min-width:190px">Prowadzący</th>
          <?php foreach ($dow_order as $dw): ?>
          <th scope="col"><?= h(mb_substr(K30_TI_DAYS[$dw], 0, 3)) ?></th>
          <?php endforeach; ?>
          <th scope="col" class="text-end"><span class="visually-hidden">Akcje</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($instructors as $i): $rows = $matrix[(int)$i['id']] ?? []; $nd = $drafts[(int)$i['id']] ?? 0; ?>
        <tr class="<?= $sel_instr === (int)$i['id'] ? 'table-primary' : '' ?>">
          <th scope="row" class="fw-semibold">
            <a href="dostepnosci.php?instr=<?= (int)$i['id'] ?>#edit"><?= h($i['name'] ?: $i['email']) ?></a>
            <?php if ($nd): ?><span class="badge text-bg-warning ms-1" title="szkice do zatwierdzenia"><?= $nd ?></span><?php endif; ?>
            <?php if (!$rows): ?><div class="text-body-secondary fw-normal" style="font-size:.72rem">brak okien</div><?php endif; ?>
          </th>
          <?php foreach ($dow_order as $dw): ?>
          <td>
            <?php foreach ($rows[$dw] ?? [] as $w):
              $temp = !empty($w['valid_from']) || !empty($w['valid_to']); ?>
            <span class="badge <?= ($w['status'] ?? 'approved') === 'approved' ? 'text-bg-primary' : 'text-bg-secondary' ?> d-block mb-1"
                  title="<?= ($w['status'] ?? '') === 'draft' ? 'szkic — czeka na zatwierdzenie' : '' ?><?=
                         $temp ? ' obowiązuje ' . ($w['valid_from'] ?: '…') . ' – ' . ($w['valid_to'] ?: '…') : '' ?>">
              <?= h(substr((string)$w['time_from'],0,5)) ?>–<?= h(substr((string)$w['time_to'],0,5)) ?><?= $temp ? ' *' : '' ?>
            </span>
            <?php endforeach; ?>
          </td>
          <?php endforeach; ?>
          <td class="text-end text-nowrap">
            <?php if ($nd): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op" value="approve_all">
              <input type="hidden" name="instructor_id" value="<?= (int)$i['id'] ?>">
              <button class="btn btn-sm btn-outline-success py-0 px-2" title="Zatwierdź wszystkie szkice">
                <i class="bi bi-check2-all" aria-hidden="true"></i></button>
            </form>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary py-0 px-2" href="dostepnosci.php?instr=<?= (int)$i['id'] ?>#edit"
               title="Edytuj okna"><i class="bi bi-pencil" aria-hidden="true"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-body border-top py-2">
    <span class="text-body-secondary small">
      Szare odznaki = szkice (generator ich nie używa, dopóki nie zostaną zatwierdzone).
      Gwiazdka (*) = okno czasowe — obowiązuje tylko we wskazanym zakresie dat.
    </span>
  </div>
</div>

<!-- ── Edycja okien wybranego prowadzącego ─────────────────────────────────── -->
<?php if ($sel): ?>
<div class="row g-4" id="edit">
  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <h2 class="h6 fw-bold mb-2"><i class="bi bi-person-badge me-1" aria-hidden="true"></i>Okna: <?= h($sel['name'] ?: $sel['email']) ?></h2>
        <?php if (!$sel_windows): ?>
        <div class="text-body-secondary small">Brak okien — dodaj pierwsze w formularzu obok.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <caption class="visually-hidden">Okna dostępności prowadzącego</caption>
            <thead><tr>
              <th scope="col">Dzień</th><th scope="col">Godziny</th>
              <th scope="col">Obowiązuje</th><th scope="col">Status</th>
              <th scope="col" class="text-end"><span class="visually-hidden">Akcje</span></th>
            </tr></thead>
            <tbody>
              <?php foreach ($sel_windows as $w):
                $is_draft = ($w['status'] ?? 'approved') === 'draft'; ?>
              <tr>
                <td><?= h(K30_TI_DAYS[(int)$w['day_of_week']] ?? (string)$w['day_of_week']) ?></td>
                <td class="fw-semibold"><?= h(substr((string)$w['time_from'],0,5)) ?>–<?= h(substr((string)$w['time_to'],0,5)) ?></td>
                <td class="small text-body-secondary">
                  <?php if (!empty($w['valid_from']) && $w['valid_from'] === ($w['valid_to'] ?? '')): ?>
                    jednorazowo <?= h($w['valid_from']) ?>
                  <?php elseif (!empty($w['valid_from']) || !empty($w['valid_to'])): ?>
                    <?= $w['valid_from'] ? 'od ' . h($w['valid_from']) : '' ?><?= $w['valid_to'] ? ' do ' . h($w['valid_to']) : '' ?>
                  <?php else: ?>bezterminowo<?php endif; ?>
                  <?= !empty($w['notes']) ? '<br>' . h($w['notes']) : '' ?>
                </td>
                <td><span class="badge text-bg-<?= $is_draft ? 'warning' : 'success' ?>"><?= $is_draft ? 'szkic' : 'zatwierdzona' ?></span></td>
                <td class="text-end text-nowrap">
                  <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" title="Edytuj godziny i ważność okna"
                          aria-expanded="false" aria-controls="winEdit<?= (int)$w['id'] ?>"
                          onclick="var r=document.getElementById('winEdit<?= (int)$w['id'] ?>');r.classList.toggle('d-none');this.setAttribute('aria-expanded',r.classList.contains('d-none')?'false':'true')">
                    <i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Edytuj okno</span>
                  </button>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="_op" value="win_status">
                    <input type="hidden" name="instructor_id" value="<?= $sel_instr ?>">
                    <input type="hidden" name="avail_id" value="<?= (int)$w['id'] ?>">
                    <input type="hidden" name="status" value="<?= $is_draft ? 'approved' : 'draft' ?>">
                    <button class="btn btn-sm btn-outline-<?= $is_draft ? 'success' : 'secondary' ?> py-0 px-2"
                            title="<?= $is_draft ? 'Zatwierdź' : 'Cofnij do szkicu' ?>">
                      <i class="bi bi-<?= $is_draft ? 'check-circle' : 'arrow-counterclockwise' ?>" aria-hidden="true"></i></button>
                  </form>
                  <form method="post" class="d-inline" onsubmit="return confirm('Usunąć to okno?')">
                    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="_op" value="win_delete">
                    <input type="hidden" name="instructor_id" value="<?= $sel_instr ?>">
                    <input type="hidden" name="avail_id" value="<?= (int)$w['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń"><i class="bi bi-trash" aria-hidden="true"></i></button>
                  </form>
                </td>
              </tr>
              <tr id="winEdit<?= (int)$w['id'] ?>" class="d-none">
                <td colspan="5" class="bg-body-tertiary">
                  <form method="post" class="row g-2 align-items-end py-1">
                    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="_op" value="win_edit">
                    <input type="hidden" name="instructor_id" value="<?= $sel_instr ?>">
                    <input type="hidden" name="avail_id" value="<?= (int)$w['id'] ?>">
                    <div class="col-auto">
                      <label class="form-label small mb-1" for="we-dow-<?= (int)$w['id'] ?>">Dzień</label>
                      <select class="form-select form-select-sm" id="we-dow-<?= (int)$w['id'] ?>" name="day_of_week">
                        <?php foreach ($dow_order as $dw2): ?>
                        <option value="<?= $dw2 ?>" <?= $dw2 === (int)$w['day_of_week'] ? 'selected' : '' ?>><?= h(K30_TI_DAYS[$dw2]) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-auto">
                      <label class="form-label small mb-1" for="we-from-<?= (int)$w['id'] ?>">Od</label>
                      <input type="time" class="form-control form-control-sm" id="we-from-<?= (int)$w['id'] ?>" name="time_from" required value="<?= h(substr((string)$w['time_from'],0,5)) ?>">
                    </div>
                    <div class="col-auto">
                      <label class="form-label small mb-1" for="we-to-<?= (int)$w['id'] ?>">Do</label>
                      <input type="time" class="form-control form-control-sm" id="we-to-<?= (int)$w['id'] ?>" name="time_to" required value="<?= h(substr((string)$w['time_to'],0,5)) ?>">
                    </div>
                    <div class="col-auto">
                      <label class="form-label small mb-1" for="we-vf-<?= (int)$w['id'] ?>">Obowiązuje od</label>
                      <input type="date" class="form-control form-control-sm" id="we-vf-<?= (int)$w['id'] ?>" name="valid_from" value="<?= h((string)($w['valid_from'] ?? '')) ?>">
                    </div>
                    <div class="col-auto">
                      <label class="form-label small mb-1" for="we-vt-<?= (int)$w['id'] ?>">do</label>
                      <input type="date" class="form-control form-control-sm" id="we-vt-<?= (int)$w['id'] ?>" name="valid_to" value="<?= h((string)($w['valid_to'] ?? '')) ?>">
                    </div>
                    <div class="col">
                      <label class="form-label small mb-1" for="we-nt-<?= (int)$w['id'] ?>">Notatka</label>
                      <input type="text" class="form-control form-control-sm" id="we-nt-<?= (int)$w['id'] ?>" name="notes" maxlength="200" value="<?= h((string)($w['notes'] ?? '')) ?>">
                    </div>
                    <div class="col-auto">
                      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
                    </div>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <h2 class="h6 fw-bold mb-2"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Nowe okno</h2>
        <form method="post" class="d-flex flex-column gap-2">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="win_add">
          <input type="hidden" name="instructor_id" value="<?= $sel_instr ?>">
          <div>
            <label class="form-label small mb-1" for="wn-dow">Dzień tygodnia</label>
            <select class="form-select form-select-sm" id="wn-dow" name="day_of_week" required>
              <?php foreach ($dow_order as $dw): ?>
              <option value="<?= $dw ?>"><?= h(K30_TI_DAYS[$dw]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label small mb-1" for="wn-from">Od</label>
              <input type="time" class="form-control form-control-sm" id="wn-from" name="time_from" required value="09:00">
            </div>
            <div class="col-6">
              <label class="form-label small mb-1" for="wn-to">Do</label>
              <input type="time" class="form-control form-control-sm" id="wn-to" name="time_to" required value="13:00">
            </div>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label small mb-1" for="wn-vf">Obowiązuje od</label>
              <input type="date" class="form-control form-control-sm" id="wn-vf" name="valid_from">
            </div>
            <div class="col-6">
              <label class="form-label small mb-1" for="wn-vt">do</label>
              <input type="date" class="form-control form-control-sm" id="wn-vt" name="valid_to">
            </div>
          </div>
          <div>
            <label class="form-label small mb-1" for="wn-status">Status</label>
            <select class="form-select form-select-sm" id="wn-status" name="status">
              <option value="approved">Zatwierdzona</option>
              <option value="draft">Szkic</option>
            </select>
          </div>
          <div>
            <label class="form-label small mb-1" for="wn-notes">Notatka (opcjonalnie)</label>
            <input type="text" class="form-control form-control-sm" id="wn-notes" name="notes" maxlength="200"
                   placeholder="np. tylko na czas tury Q4">
          </div>
          <button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Dodaj okno</button>
        </form>
        <p class="form-text mb-0 mt-2">
          Puste daty = okno stałe. Różna dostępność w różnych tygodniach = kilka okien
          z rozłącznymi zakresami dat. Jednorazowa dostępność: od = do (dzień tygodnia
          wyliczy się z daty). Generator używa wyłącznie okien zatwierdzonych.
        </p>
      </div>
    </div>
  </div>
</div>
<?php elseif ($sel_instr): ?>
<div class="alert alert-warning" id="edit" role="alert">
  <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
  Nie można otworzyć edycji: prowadzący #<?= $sel_instr ?> nie istnieje albo jest nieaktywny.
</div>
<?php else: ?>
<div class="text-body-secondary small">Kliknij prowadzącego w macierzy, aby edytować jego okna.</div>
<?php endif; ?>

</main>
<?php $PRINT_TITLE = 'Dostępności prowadzących'; include __DIR__ . '/_print_page.php'; ?>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
