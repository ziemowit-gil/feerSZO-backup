<?php
/**
 * Rejestr pełnomocnictw — samodzielny rejestr w SZO (inspirowany klasą JRWA 013 z EZD).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/pelnomocnictwa.php';

require_login();
require_module_enabled('pelnomocnictwa_enabled', 'Rejestr pełnomocnictw');
if (!can_edit()) { flash_set('error', 'Brak uprawnień do rejestru pełnomocnictw.'); header('Location:'.APP_URL.'/index.php'); exit; }

$user_id = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            pelnomocnictwo_save($id, $_POST, $user_id);
            flash_set('success', $id ? 'Zaktualizowano pełnomocnictwo.' : 'Dodano pełnomocnictwo do rejestru.');
            header('Location:'.APP_URL.'/pelnomocnictwa/index.php'); exit;
        }
        if ($action === 'revoke') {
            $id = (int)($_POST['id'] ?? 0);
            pelnomocnictwo_revoke($id, $_POST['data_odwolania'] ?? null, $user_id);
            flash_set('success', 'Pełnomocnictwo odwołane — możesz wygenerować dokument odwołania.');
            header('Location:'.APP_URL.'/pelnomocnictwa/index.php?edit='.$id.'#form-peln'); exit;
        }
        if ($action === 'delete' && is_admin()) {
            pelnomocnictwo_delete((int)($_POST['id'] ?? 0));
            flash_set('success', 'Wpis usunięty.');
            header('Location:'.APP_URL.'/pelnomocnictwa/index.php'); exit;
        }
        if ($action === 'upload_dokument') {
            $id  = (int)($_POST['id'] ?? 0);
            $typ = ($_POST['dokument_typ'] ?? '') === 'odwolanie' ? 'odwolanie' : 'pelnomocnictwo';
            $err = pelnomocnictwo_upload($id, $user_id, $typ);
            if ($err) throw new \RuntimeException($err);
            flash_set('success', 'Dokument dołączony do wpisu.');
            header('Location:'.APP_URL.'/pelnomocnictwa/index.php?edit='.$id.'#form-peln'); exit;
        }
        if ($action === 'delete_dokument') {
            $id = (int)($_POST['id'] ?? 0);
            pelnomocnictwo_document_delete($id);
            flash_set('success', 'Dokument usunięty ze wpisu.');
            header('Location:'.APP_URL.'/pelnomocnictwa/index.php?edit='.$id.'#form-peln'); exit;
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location:'.APP_URL.'/pelnomocnictwa/index.php'); exit;
}

$q      = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$rok    = (int)($_GET['rok'] ?? 0);
$rows   = pelnomocnictwa_all(['q' => $q, 'status' => $status, 'rok' => $rok ?: null]);
$edit   = !empty($_GET['edit']) ? pelnomocnictwo_get((int)$_GET['edit']) : null;
$edit_user_name = '';
if ($edit && !empty($edit['pelnomocnik_user_id'])) {
    $lu = db_one("SELECT name, email FROM users WHERE id=?", [(int)$edit['pelnomocnik_user_id']]);
    $edit_user_name = $lu ? trim(($lu['name'] ?? '') . ($lu['email'] ? ' · ' . $lu['email'] : '')) : ('#' . (int)$edit['pelnomocnik_user_id']);
}
$stats  = pelnomocnictwa_stats();
$PAGE_TITLE = 'Rejestr pełnomocnictw';

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-person-badge text-primary me-2"></i>Rejestr pełnomocnictw</h4>
    <div class="text-muted" style="font-size:.8rem;margin-top:.15rem">Mocodawca, pełnomocnik, zakres umocowania i okres ważności — inspirowany klasą JRWA 013</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <span class="badge bg-success bg-opacity-15 text-success border border-success" style="font-size:.72rem"><?= $stats['wazne'] ?> ważnych</span>
    <span class="badge bg-danger bg-opacity-15 text-danger border border-danger" style="font-size:.72rem"><?= $stats['wygasle'] ?> wygasłych</span>
    <span class="badge bg-secondary bg-opacity-15 text-secondary border border-secondary" style="font-size:.72rem"><?= $stats['odwolane'] ?> odwołanych</span>
    <a href="#form-peln" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nowe pełnomocnictwo</a>
  </div>
</div>

<?= flash_html() ?>

<?php $expiring = pelnomocnictwa_expiring(30); if ($expiring): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 py-2" role="alert">
  <i class="bi bi-bell-fill mt-1"></i>
  <div style="font-size:.84rem">
    <strong><?= count($expiring) ?></strong> pełnomocnictw wygasa w ciągu 30 dni:
    <?php foreach (array_slice($expiring, 0, 6) as $ex):
      $d = (int)round((strtotime($ex['data_waznosci']) - strtotime(date('Y-m-d'))) / 86400); ?>
      <a href="?edit=<?= $ex['id'] ?>#form-peln" class="text-decoration-none">
        <span class="badge bg-white text-warning-emphasis border border-warning me-1" style="font-size:.72rem">
          <?= h($ex['numer']) ?> · <?= $d <= 0 ? 'dziś' : 'za '.$d.' dni' ?>
        </span></a>
    <?php endforeach; ?>
    <?php if (count($expiring) > 6): ?><span class="text-muted">i <?= count($expiring)-6 ?> więcej…</span><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Formularz dodawania / edycji -->
<div class="card shadow-sm mb-3 <?= $edit ? 'border-primary' : '' ?>" id="form-peln">
  <div class="card-header py-2 <?= $edit ? 'bg-primary bg-opacity-10' : '' ?>">
    <span class="fw-semibold <?= $edit ? 'text-primary' : '' ?>" style="font-size:.84rem">
      <i class="bi bi-<?= $edit ? 'pencil' : 'plus-lg' ?> me-1"></i><?= $edit ? 'Edycja: '.h($edit['numer']) : 'Nowe pełnomocnictwo' ?>
    </span>
  </div>
  <div class="card-body">
    <form method="post" class="row g-2">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="save">
      <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
      <div class="col-md-3"><label class="form-label mb-1" style="font-size:.74rem">Numer</label>
        <input type="text" name="numer" class="form-control form-control-sm" value="<?= h($edit['numer'] ?? '') ?>" placeholder="<?= h(pelnomocnictwa_suggest_numer()) ?> (auto)"></div>
      <div class="col-md-3"><label class="form-label mb-1" style="font-size:.74rem">Forma</label>
        <input type="text" name="forma" class="form-control form-control-sm" value="<?= h($edit['forma'] ?? '') ?>" placeholder="pisemne / notarialne / elektroniczne"></div>
      <div class="col-md-3"><label class="form-label mb-1" style="font-size:.74rem">Data udzielenia</label>
        <input type="date" name="data_udzielenia" class="form-control form-control-sm" value="<?= h($edit['data_udzielenia'] ?? date('Y-m-d')) ?>"></div>
      <div class="col-md-3"><label class="form-label mb-1" style="font-size:.74rem">Ważne do <span class="text-muted">(puste = bezterminowe)</span></label>
        <input type="date" name="data_waznosci" class="form-control form-control-sm" value="<?= h($edit['data_waznosci'] ?? '') ?>"></div>

      <?php $_rodzaj = $edit['rodzaj'] ?? 'ogolne'; $_kor_tryb = $edit['kor_tryb'] ?? 'ogolne'; ?>
      <div class="col-md-4"><label class="form-label mb-1" style="font-size:.74rem">Rodzaj pełnomocnictwa</label>
        <select name="rodzaj" id="peln-rodzaj" class="form-select form-select-sm">
          <?php foreach (pelnomocnictwa_rodzaje() as $k=>$lbl): ?>
          <option value="<?= $k ?>" <?= $_rodzaj===$k?'selected':'' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-8 row g-2 m-0 p-0" id="peln-kor-block" style="display:none">
        <div class="col-md-5 ps-0"><label class="form-label mb-1" style="font-size:.74rem">Zakres korespondencji</label>
          <select name="kor_tryb" id="peln-kor-tryb" class="form-select form-select-sm">
            <?php foreach (pelnomocnictwo_kor_tryby() as $k=>$lbl): ?>
            <option value="<?= $k ?>" <?= $_kor_tryb===$k?'selected':'' ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-7 pe-0" id="peln-kor-szczegoly-wrap">
          <label class="form-label mb-1" style="font-size:.74rem" id="peln-kor-szczegoly-lbl">Szczegóły / wyłączenia</label>
          <input type="text" name="kor_szczegoly" id="peln-kor-szczegoly" class="form-control form-control-sm" value="<?= h($edit['kor_szczegoly'] ?? '') ?>" placeholder="np. przesyłka awizowana nr… / korespondencja od…">
        </div>
      </div>

      <div class="col-md-5"><label class="form-label mb-1" style="font-size:.74rem">Mocodawca <span class="text-danger">*</span></label>
        <input type="text" name="mocodawca" class="form-control form-control-sm" value="<?= h($edit['mocodawca'] ?? '') ?>" required placeholder="kto udziela pełnomocnictwa"></div>

      <div class="col-12 position-relative" id="peln-kontrahent-search-wrap">
        <label class="form-label mb-1" style="font-size:.74rem"><i class="bi bi-search me-1"></i>Wyszukaj osobę z umową <span class="text-muted">(uzupełni pełnomocnika i PESEL)</span></label>
        <input type="text" id="peln-kontrahent-search" class="form-control form-control-sm" autocomplete="off" placeholder="imię, nazwisko lub PESEL…">
        <ul id="peln-kontrahent-list" class="list-group shadow-sm" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:1052;max-height:280px;overflow-y:auto"></ul>
      </div>
      <div class="col-md-5"><label class="form-label mb-1" style="font-size:.74rem">Pełnomocnik <span class="text-danger">*</span></label>
        <input type="text" name="pelnomocnik" id="peln-pelnomocnik" class="form-control form-control-sm" value="<?= h($edit['pelnomocnik'] ?? '') ?>" required placeholder="komu udzielono pełnomocnictwa"></div>
      <div class="col-md-2"><label class="form-label mb-1" style="font-size:.74rem">PESEL</label>
        <input type="text" name="pelnomocnik_pesel" id="peln-pesel" class="form-control form-control-sm" value="<?= h($edit['pelnomocnik_pesel'] ?? '') ?>" maxlength="11" placeholder="opcjonalnie"></div>

      <div class="col-12 position-relative" id="peln-user-search-wrap">
        <label class="form-label mb-1" style="font-size:.74rem"><i class="bi bi-person-check me-1"></i>Powiąż z kontem użytkownika <span class="text-muted">(pełnomocnik zobaczy wpis w swoim panelu)</span></label>
        <input type="hidden" name="pelnomocnik_user_id" id="peln-user-id" value="<?= (int)($edit['pelnomocnik_user_id'] ?? 0) ?>">
        <div class="d-flex gap-2 align-items-center">
          <div class="flex-grow-1 position-relative">
            <input type="text" id="peln-user-search" class="form-control form-control-sm" autocomplete="off" placeholder="szukaj po nazwisku lub e-mailu…">
            <ul id="peln-user-list" class="list-group shadow-sm" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:1052;max-height:240px;overflow-y:auto"></ul>
          </div>
          <span id="peln-user-chip" class="badge bg-primary bg-opacity-10 text-primary border border-primary d-inline-flex align-items-center gap-1" style="font-size:.74rem;<?= $edit_user_name ? '' : 'display:none!important' ?>">
            <i class="bi bi-person-check"></i><span id="peln-user-chip-name"><?= h($edit_user_name) ?></span>
            <a href="#" id="peln-user-clear" class="text-primary" title="Odłącz konto" style="text-decoration:none"><i class="bi bi-x-lg"></i></a>
          </span>
        </div>
      </div>

      <div class="col-12"><label class="form-label mb-1" style="font-size:.74rem">Zakres umocowania <span class="text-muted">(jedna pozycja na linię — w dokumencie zostanie ponumerowana)</span></label>
        <textarea name="zakres" class="form-control form-control-sm" rows="3" placeholder="np.&#10;wydawania zaświadczeń potwierdzających przeprowadzenie szkolenia lub instruktażu&#10;podpisywania dokumentacji związanej z realizacją szkoleń"><?= h($edit['zakres'] ?? '') ?></textarea></div>

      <div class="col-md-4"><label class="form-label mb-1" style="font-size:.74rem">Data odwołania <span class="text-muted">(jeśli odwołane)</span></label>
        <input type="date" name="data_odwolania" class="form-control form-control-sm" value="<?= h($edit['data_odwolania'] ?? '') ?>"></div>
      <div class="col-md-8"><label class="form-label mb-1" style="font-size:.74rem">Uwagi</label>
        <input type="text" name="uwagi" class="form-control form-control-sm" value="<?= h($edit['uwagi'] ?? '') ?>"></div>

      <div class="col-12 mt-2"><hr class="my-1"><div class="text-muted mb-1" style="font-size:.72rem"><i class="bi bi-file-earmark-text me-1"></i>Dane do generowanego dokumentu</div></div>
      <div class="col-md-6">
        <label class="form-label mb-1" style="font-size:.74rem">Podpisujący w imieniu mocodawcy</label>
        <input type="text" name="podpisujacy" class="form-control form-control-sm" list="peln-reps" value="<?= h($edit['podpisujacy'] ?? '') ?>" placeholder="imię i nazwisko">
        <datalist id="peln-reps">
          <?php foreach (function_exists('org_representatives') ? org_representatives() : [] as $rep): ?>
          <option value="<?= h($rep['name']) ?>"><?php endforeach; ?>
        </datalist>
      </div>
      <div class="col-md-6"><label class="form-label mb-1" style="font-size:.74rem">Funkcja podpisującego</label>
        <input type="text" name="podpisujacy_funkcja" class="form-control form-control-sm" value="<?= h($edit['podpisujacy_funkcja'] ?? '') ?>" placeholder="np. Prezes Zarządu (uzupełni się automatycznie z listy przedstawicieli)"></div>

      <div class="col-12 d-flex gap-2 mt-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i><?= $edit ? 'Zapisz zmiany' : 'Dodaj' ?></button>
        <?php if($edit): ?><a href="<?= APP_URL ?>/pelnomocnictwa/index.php" class="btn btn-outline-secondary btn-sm">Anuluj</a><?php endif; ?>
      </div>
    </form>

    <?php if ($edit): ?>
    <hr class="my-3">
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <span class="text-muted" style="font-size:.72rem"><i class="bi bi-file-earmark-richtext me-1"></i>Generuj dokument:</span>
      <a href="<?= APP_URL ?>/pelnomocnictwa/dokument.php?id=<?= $edit['id'] ?>&typ=pelnomocnictwo" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-plus me-1"></i>Pełnomocnictwo</a>
      <a href="<?= APP_URL ?>/pelnomocnictwa/dokument.php?id=<?= $edit['id'] ?>&typ=odwolanie" target="_blank" class="btn btn-outline-danger btn-sm"><i class="bi bi-file-earmark-x me-1"></i>Odwołanie</a>
    </div>

    <div class="mt-3">
      <span class="text-muted" style="font-size:.72rem"><i class="bi bi-paperclip me-1"></i>Podpisany skan (PDF/JPG/PNG):</span>
      <?php if ($edit['dokument_plik']): ?>
      <div class="d-flex align-items-center gap-2 mt-1">
        <a href="<?= APP_URL ?>/pelnomocnictwa/dokument_download.php?id=<?= $edit['id'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i><?= h($edit['dokument_oryginal_nazwa'] ?: 'pobierz') ?></a>
        <form method="post" onsubmit="return confirm('Usunąć dołączony dokument?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="delete_dokument">
          <input type="hidden" name="id" value="<?= $edit['id'] ?>">
          <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3"></i></button>
        </form>
      </div>
      <?php else: ?>
      <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center mt-1">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="upload_dokument">
        <input type="hidden" name="id" value="<?= $edit['id'] ?>">
        <select name="dokument_typ" class="form-select form-select-sm" style="width:auto">
          <option value="pelnomocnictwo">Pełnomocnictwo</option>
          <option value="odwolanie">Odwołanie</option>
        </select>
        <input type="file" name="dokument" class="form-control form-control-sm" style="width:auto" required accept=".pdf,.jpg,.jpeg,.png">
        <button class="btn btn-outline-primary btn-sm"><i class="bi bi-upload me-1"></i>Wgraj</button>
      </form>
      <?php endif; ?>
    </div>

    <?php $history = pelnomocnictwo_history((int)$edit['id']); if ($history): ?>
    <hr class="my-3">
    <div>
      <span class="text-muted" style="font-size:.72rem"><i class="bi bi-clock-history me-1"></i>Historia zmian:</span>
      <ul class="list-unstyled mt-2 mb-0" style="font-size:.8rem">
        <?php foreach ($history as $ev): [$lbl,$icon] = pelnomocnictwo_log_meta($ev['action']); ?>
        <li class="d-flex align-items-start gap-2 mb-1">
          <i class="bi <?= $icon ?>" style="line-height:1.4"></i>
          <div>
            <span class="fw-semibold"><?= h($lbl) ?></span>
            <?php if ($ev['details']): ?><span class="text-muted"> — <?= h($ev['details']) ?></span><?php endif; ?>
            <div class="text-muted" style="font-size:.72rem">
              <?= h(date_pl(substr((string)$ev['created_at'], 0, 10))) ?> <?= h(substr((string)$ev['created_at'], 11, 5)) ?>
              <?= $ev['user_name'] ? '· ' . h($ev['user_name']) : '' ?>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Szukaj i filtruj -->
<form method="get" class="mb-3 d-flex flex-wrap gap-2 align-items-end">
  <div>
    <label class="form-label mb-1" style="font-size:.72rem">Szukaj</label>
    <input type="text" name="q" class="form-control form-control-sm" value="<?= h($q) ?>" placeholder="numer, mocodawca, pełnomocnik, zakres…" style="min-width:260px">
  </div>
  <div>
    <label class="form-label mb-1" style="font-size:.72rem">Status</label>
    <select name="status" class="form-select form-select-sm">
      <option value="">wszystkie</option>
      <?php foreach (pelnomocnictwa_statuses() as $k=>$lbl): ?>
      <option value="<?= $k ?>" <?= $status===$k?'selected':'' ?>><?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label mb-1" style="font-size:.72rem">Rok</label>
    <input type="number" name="rok" class="form-control form-control-sm" value="<?= $rok ?: '' ?>" style="width:100px" placeholder="rok">
  </div>
  <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-search"></i> Filtruj</button>
  <?php if($q || $status || $rok): ?><a href="<?= APP_URL ?>/pelnomocnictwa/index.php" class="btn btn-outline-secondary btn-sm">Wyczyść</a><?php endif; ?>
</form>

<!-- Lista -->
<div class="card shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold" style="font-size:.88rem"><i class="bi bi-person-badge me-1 text-primary"></i>Pełnomocnictwa (<?= count($rows) ?>)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.83rem">
      <thead class="table-light">
        <tr>
          <th>Numer</th>
          <th>Mocodawca</th>
          <th>Pełnomocnik</th>
          <th>Zakres</th>
          <th class="text-nowrap">Ważność</th>
          <th>Status</th>
          <th style="width:110px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): [$label,$color] = pelnomocnictwo_status_label(pelnomocnictwo_status($r)); ?>
        <tr>
          <td class="fw-semibold text-nowrap"><?= h($r['numer']) ?></td>
          <td><?= h($r['mocodawca']) ?></td>
          <td><?= h($r['pelnomocnik']) ?></td>
          <td>
            <?php if (($r['rodzaj'] ?? 'ogolne') === 'korespondencja'): ?>
            <span class="badge bg-info bg-opacity-10 text-info border border-info" style="font-size:.68rem"><i class="bi bi-envelope me-1"></i>Korespondencja</span>
            <span class="text-muted" style="font-size:.78rem"><?= h(mb_substr(pelnomocnictwo_kor_opis($r), 0, 60)) ?></span>
            <?php else: ?>
            <?= h(mb_substr($r['zakres'] ?: '—', 0, 70)) ?>
            <?php endif; ?>
          </td>
          <td class="text-nowrap text-muted" style="font-size:.78rem">
            <?= $r['data_udzielenia'] ? date_pl($r['data_udzielenia']) : '—' ?> – <?= $r['data_waznosci'] ? date_pl($r['data_waznosci']) : 'bezterminowo' ?>
          </td>
          <td><span class="badge bg-<?= $color ?> bg-opacity-15 text-<?= $color ?> border border-<?= $color ?>" style="font-size:.72rem"><?= h($label) ?></span></td>
          <td class="text-end text-nowrap">
            <?php if ($r['dokument_plik']): ?>
            <a href="<?= APP_URL ?>/pelnomocnictwa/dokument_download.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-success btn-sm" title="Pobierz podpisany skan"><i class="bi bi-paperclip"></i></a>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/pelnomocnictwa/dokument.php?id=<?= $r['id'] ?>&typ=pelnomocnictwo" class="btn btn-xs btn-outline-secondary btn-sm" title="Generuj dokument" target="_blank"><i class="bi bi-file-earmark-richtext"></i></a>
            <a href="<?= APP_URL ?>/pelnomocnictwa/print.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-secondary btn-sm" title="Wydruk rejestru" target="_blank"><i class="bi bi-printer"></i></a>
            <a href="?edit=<?= $r['id'] ?>#form-peln" class="btn btn-xs btn-outline-secondary btn-sm" title="Edytuj"><i class="bi bi-pencil"></i></a>
            <?php if (pelnomocnictwo_status($r) === 'wazne'): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Odwołać pełnomocnictwo <?= h($r['numer']) ?> ze skutkiem na dziś (<?= h(date_pl(date('Y-m-d'))) ?>)?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="revoke">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn btn-xs btn-outline-warning btn-sm" title="Odwołaj (na dziś)"><i class="bi bi-x-octagon"></i></button>
            </form>
            <?php endif; ?>
            <?php if(is_admin()): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć wpis z rejestru?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn btn-xs btn-outline-danger btn-sm" title="Usuń"><i class="bi bi-trash3"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?>
        <tr><td colspan="7" class="text-center text-muted py-5">
          <i class="bi bi-person-badge" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
          Rejestr jest pusty<?= ($q||$status||$rok) ? ' dla tego filtra' : '' ?>.
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  var input = document.getElementById('peln-kontrahent-search');
  var list  = document.getElementById('peln-kontrahent-list');
  var wrap  = document.getElementById('peln-kontrahent-search-wrap');
  var fName = document.getElementById('peln-pelnomocnik');
  var fPesel= document.getElementById('peln-pesel');
  if (!input || !list) return;

  var timer = null;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function closeList() { list.style.display = 'none'; list.innerHTML = ''; }

  function render(items, q) {
    list.innerHTML = '';
    if (!items.length) {
      var li = document.createElement('li');
      li.className = 'list-group-item text-muted small py-2';
      li.textContent = 'Brak wyników dla „' + q + '”.';
      list.appendChild(li);
    } else {
      items.forEach(function (p) {
        var li = document.createElement('li');
        li.className = 'list-group-item list-group-item-action py-2';
        li.style.cursor = 'pointer';
        li.innerHTML =
          '<div class="d-flex align-items-baseline gap-2">' +
            '<span class="fw-semibold">' + esc(p.imie_nazwisko) + '</span>' +
            (p.pesel_display ? '<span class="font-monospace text-muted small">' + esc(p.pesel_display) + '</span>' : '') +
          '</div>' +
          '<div class="small text-muted">' + esc((p.contract_types || []).join(', ')) +
            (p.numer_umowy ? ' · ' + esc(p.numer_umowy) : '') + '</div>';
        li.addEventListener('click', function () {
          if (fName) { fName.value = p.imie_nazwisko || ''; }
          if (fPesel && p.pesel) { fPesel.value = p.pesel; }
          input.value = '';
          closeList();
        });
        list.appendChild(li);
      });
    }
    list.style.display = '';
  }

  input.addEventListener('input', function () {
    clearTimeout(timer);
    var q = input.value.trim();
    if (q.length < 2) { closeList(); return; }
    timer = setTimeout(function () {
      fetch('<?= APP_URL ?>/pelnomocnictwa/search_kontrahent.php?q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (data) { render(data, q); })
        .catch(closeList);
    }, 280);
  });

  document.addEventListener('click', function (e) {
    if (wrap && !wrap.contains(e.target)) closeList();
  });
})();

// Powiązanie pełnomocnika z kontem użytkownika
(function () {
  var input = document.getElementById('peln-user-search');
  var list  = document.getElementById('peln-user-list');
  var wrap  = document.getElementById('peln-user-search-wrap');
  var idFld = document.getElementById('peln-user-id');
  var chip  = document.getElementById('peln-user-chip');
  var chipN = document.getElementById('peln-user-chip-name');
  var clear = document.getElementById('peln-user-clear');
  var fName = document.getElementById('peln-pelnomocnik');
  if (!input || !list) return;

  var timer = null;
  function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
  function closeList(){list.style.display='none';list.innerHTML='';}
  function setChip(name){ if(chipN) chipN.textContent=name; if(chip) chip.style.setProperty('display', name?'inline-flex':'none','important'); }

  if (clear) clear.addEventListener('click', function(e){ e.preventDefault(); if(idFld) idFld.value='0'; setChip(''); });

  function render(items, q){
    list.innerHTML='';
    if(!items.length){
      var li=document.createElement('li'); li.className='list-group-item text-muted small py-2';
      li.textContent='Brak kont dla „'+q+'”.'; list.appendChild(li);
    } else {
      items.forEach(function(u){
        var li=document.createElement('li');
        li.className='list-group-item list-group-item-action py-2'; li.style.cursor='pointer';
        li.innerHTML='<span class="fw-semibold">'+esc(u.name)+'</span>'+(u.email?' <span class="text-muted small">'+esc(u.email)+'</span>':'');
        li.addEventListener('click', function(){
          if(idFld) idFld.value=u.id;
          setChip(esc(u.name)+(u.email?' · '+esc(u.email):''));
          if(fName && !fName.value.trim()) fName.value=u.name||'';
          input.value=''; closeList();
        });
        list.appendChild(li);
      });
    }
    list.style.display='';
  }

  input.addEventListener('input', function(){
    clearTimeout(timer);
    var q=input.value.trim();
    if(q.length<2){closeList();return;}
    timer=setTimeout(function(){
      fetch('<?= APP_URL ?>/pelnomocnictwa/search_user.php?q='+encodeURIComponent(q))
        .then(function(r){return r.json();}).then(function(d){render(d,q);}).catch(closeList);
    },280);
  });
  document.addEventListener('click', function(e){ if(wrap && !wrap.contains(e.target)) closeList(); });
})();

// Rodzaj pełnomocnictwa → pokaż/ukryj pola korespondencji
(function () {
  var rodzaj = document.getElementById('peln-rodzaj');
  var block  = document.getElementById('peln-kor-block');
  var tryb   = document.getElementById('peln-kor-tryb');
  var szWrap = document.getElementById('peln-kor-szczegoly-wrap');
  var szLbl  = document.getElementById('peln-kor-szczegoly-lbl');
  if (!rodzaj || !block) return;
  function sync() {
    var isKor = rodzaj.value === 'korespondencja';
    block.style.display = isKor ? 'flex' : 'none';
    if (isKor && tryb && szWrap) {
      var needsDetail = tryb.value === 'konkretne' || tryb.value === 'wylaczenie';
      szWrap.style.display = needsDetail ? '' : 'none';
      if (szLbl) szLbl.textContent = tryb.value === 'wylaczenie' ? 'Co wyłączyć' : 'Jaką korespondencję';
    }
  }
  rodzaj.addEventListener('change', sync);
  if (tryb) tryb.addEventListener('change', sync);
  sync();
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
