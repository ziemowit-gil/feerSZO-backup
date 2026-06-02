<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/zwroty_kosztow.php';

require_role('admin', 'editor');
$PAGE_TITLE = 'Nowy wniosek o zwrot kosztów';

$fm = new FinanceManager();

// Wstępnie wybrana umowa (np. z linku z view.php umowy)
$pre_contract_id   = (int)($_GET['umowa_id'] ?? 0);
$pre_contract_type = $_GET['umowa_type'] ?? 'wolontariat';

// Lista umów z włączoną flagą zwrotu kosztów
$umowy = db_all(
    "SELECT id, numer_umowy, imie_nazwisko, status, person_id
     FROM umowy_wolontariat
     WHERE zwrot_kosztow = 1
       AND status IN ('aktywna','w_realizacji','zatwierdzony','zatwierdzona')
     ORDER BY numer_umowy DESC"
);

// Mapa umowa_id → imie_nazwisko do JS autofill
$umowy_map = [];
foreach ($umowy as $u) {
    $umowy_map[$u['id']] = ['wolontariusz' => $u['imie_nazwisko']];
}

// Lista użytkowników (do wyboru składającego — admin składa w imieniu)
$wszyscy_uzytkownicy = db_all(
    "SELECT id, name, email FROM users WHERE is_active=1 AND email != 'serwis@local' ORDER BY name"
);

$errors      = [];
$eligibility = null;

// Sprawdź uprawnienia dla wstępnie wybranej umowy
if ($pre_contract_id) {
    $eligibility = $fm->validateEligibility($pre_contract_id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $contract_id          = (int)($_POST['umowa_id']   ?? 0);
    $contract_type        = $_POST['umowa_type'] ?? 'wolontariat';
    $bez_umowy            = isset($_POST['bez_umowy']) ? 1 : 0;
    $tytul                = trim($_POST['tytul']        ?? '');
    $opis                 = trim($_POST['opis']          ?? '');
    $kwota                = str_replace(',', '.', trim($_POST['kwota'] ?? ''));
    $data_wydatku         = trim($_POST['data_wydatku'] ?? '');
    $kategoria            = trim($_POST['kategoria']    ?? '');
    $faktura_elektroniczna= isset($_POST['faktura_elektroniczna']) ? 1 : 0;

    // Kto składa — admin może wybrać innego użytkownika lub wpisać imię wolontariusza
    $w_imieniu_id   = (int)($_POST['w_imieniu_id']   ?? 0);   // user_id z listy
    $w_imieniu_imie = trim($_POST['w_imieniu_imie'] ?? '');   // opis tekstowy

    // Walidacja
    $el = $fm->validateEligibility($contract_id, $contract_type, (bool)$bez_umowy);
    if (!$el['eligible'])   $errors[] = 'Umowa: ' . $el['reason'];
    if (!$bez_umowy && !$contract_id) $errors[] = 'Wybierz umowę lub zaznacz tryb bez umowy.';
    if (!$tytul)            $errors[] = 'Tytuł wydatku jest wymagany.';
    if (!is_numeric($kwota) || (float)$kwota <= 0) $errors[] = 'Kwota musi być liczbą większą od zera.';
    if (!$data_wydatku)     $errors[] = 'Data wydatku jest wymagana.';
    if ($data_wydatku > date('Y-m-d')) $errors[] = 'Data wydatku nie może być w przyszłości.';

    // Sprawdzenie czy kwota nie przekroczy dostępnego limitu
    if (!$errors && $el['dostepny'] !== null && (float)$kwota > $el['dostepny']) {
        $errors[] = sprintf(
            'Kwota wniosku (%.2f PLN) przekracza dostępny limit (%.2f PLN).',
            (float)$kwota, $el['dostepny']
        );
    }

    // Załączniki
    $zalaczniki = [];
    if (!empty($_FILES['zalaczniki']['name'][0])) {
        $upload_dir = dirname(dirname(__DIR__)) . '/uploads/zwroty/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        foreach ($_FILES['zalaczniki']['tmp_name'] as $i => $tmp) {
            if ($_FILES['zalaczniki']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $orig = $_FILES['zalaczniki']['name'][$i];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf','jpg','jpeg','png','gif','webp','xlsx','xls','csv','doc','docx'])) {
                $errors[] = "Niedozwolony format pliku: {$orig}";
                continue;
            }
            if ($_FILES['zalaczniki']['size'][$i] > 10 * 1024 * 1024) {
                $errors[] = "Plik {$orig} przekracza 10 MB.";
                continue;
            }
            $fname = date('Ymd_His') . '_' . $i . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $orig);
            if (move_uploaded_file($tmp, $upload_dir . $fname)) {
                $zalaczniki[] = ['name' => $orig, 'file' => $fname];
            }
        }
    }

    if (!$errors) {
        $current = current_user();
        // Wnioskodawca: wybrany użytkownik lub bieżący admin
        $wnioskodawca_id = ($w_imieniu_id > 0) ? $w_imieniu_id : (int)$current['id'];
        // Opis "w imieniu" — jeśli admin składa za kogoś
        $zlozone_przez = null;
        if ($w_imieniu_id > 0 && $w_imieniu_id !== (int)$current['id']) {
            $zlozone_przez = 'Złożono przez ' . h($current['name']) . ' w imieniu wolontariusza.';
        } elseif ($w_imieniu_imie !== '') {
            $zlozone_przez = 'Złożono przez ' . h($current['name']) . ' w imieniu: ' . $w_imieniu_imie . '.';
        }
        try {
            $rid = $fm->createRequest($contract_id, $contract_type, $wnioskodawca_id, [
                'tytul'                => $tytul,
                'opis'                 => trim(($zlozone_przez ? $zlozone_przez . "\n" : '') . $opis),
                'kwota'                => (float)$kwota,
                'data_wydatku'         => $data_wydatku,
                'kategoria'            => $kategoria ?: null,
                'zalaczniki'           => $zalaczniki,
                'bez_umowy'            => $bez_umowy,
                'faktura_elektroniczna'=> $faktura_elektroniczna,
                'zlozone_przez_id'     => (int)$current['id'],
            ]);
            $msg = 'Wniosek o zwrot kosztów został złożony.';
            if ($zlozone_przez) $msg .= ' Zarejestrowano w imieniu wolontariusza.';
            flash_set('success', $msg);
            header('Location: view.php?id=' . $rid); exit;
        } catch (\Throwable $e) {
            $errors[] = 'Błąd zapisu: ' . $e->getMessage();
        }
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="index.php">Zwroty kosztów</a></li>
    <li class="breadcrumb-item active">Nowy wniosek</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-2 mb-4">
  <div class="rounded-3 p-2 bg-success bg-opacity-10 text-success">
    <i class="bi bi-receipt fs-4"></i>
  </div>
  <h4 class="mb-0 fw-bold">Nowy wniosek o zwrot kosztów</h4>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Sprawdź pola:</strong>
  <ul class="mb-0 mt-1"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<div class="row g-4">
<div class="col-xl-8">

<!-- ── Wybór umowy ─────────────────────────────────────────────────────── -->
<?php $is_bez_umowy = !empty($_POST['bez_umowy']); ?>
<div class="card shadow-sm mb-4">
<div class="card-header fw-semibold"><i class="bi bi-file-earmark-text text-success me-1"></i> Powiązana umowa</div>
<div class="card-body">

  <!-- Toggle bez umowy (tylko admin) -->
  <div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" id="bez_umowy_toggle" name="bez_umowy"
           role="switch" <?= $is_bez_umowy ? 'checked' : '' ?>
           onchange="toggleBezUmowy(this.checked)">
    <label class="form-check-label" for="bez_umowy_toggle">
      <strong>Wniosek bez powiązanej umowy</strong>
      <span class="badge bg-warning text-dark ms-1" style="font-size:.7rem">admin</span>
    </label>
    <div class="form-text">Zaznacz, gdy wolontariusz nie ma aktywnej umowy z flagą zwrotu kosztów.</div>
  </div>

  <!-- Wybór umowy (ukryty gdy bez_umowy) -->
  <div id="umowa-selector" <?= $is_bez_umowy ? 'style="display:none"' : '' ?>>
    <div class="mb-3">
      <label class="form-label fw-semibold">Porozumienie wolontariackie</label>
      <select name="umowa_id" id="umowa_id_sel" class="form-select" onchange="checkEligibility(this.value)">
        <option value="">— wybierz umowę —</option>
        <?php foreach ($umowy as $u): ?>
        <option value="<?= $u['id'] ?>" <?= ($pre_contract_id===$u['id']||($_POST['umowa_id']??0)==$u['id']) ? 'selected':'' ?>>
          <?= h($u['numer_umowy']) ?> · <?= h($u['imie_nazwisko']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Tylko umowy z włączoną opcją zwrotu kosztów.</div>
    </div>
    <!-- Info o limicie -->
    <div id="eligibility-info" style="<?= $pre_contract_id ? '' : 'display:none' ?>">
      <?php if ($pre_contract_id && $eligibility): ?>
      <?php include __DIR__ . '/partials/eligibility_info.php'; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Info gdy bez umowy -->
  <div id="bez-umowy-info" <?= $is_bez_umowy ? '' : 'style="display:none"' ?>>
    <div class="alert alert-warning py-2 mb-0" style="font-size:.83rem">
      <i class="bi bi-exclamation-triangle me-1"></i>
      Wniosek zostanie zarejestrowany <strong>bez powiązanej umowy</strong>.
      Limit budżetowy nie jest sprawdzany. Stosuj tylko w uzasadnionych przypadkach.
    </div>
  </div>

  <input type="hidden" name="umowa_type" value="wolontariat">
</div>
</div>

<!-- ── W imieniu wolontariusza ────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
<div class="card-header fw-semibold d-flex align-items-center justify-content-between">
  <span><i class="bi bi-person-check text-primary me-1"></i> Składający wniosek</span>
  <span class="badge bg-primary bg-opacity-15 text-primary border border-primary border-opacity-25" style="font-size:.72rem">
    Admin może złożyć w imieniu wolontariusza
  </span>
</div>
<div class="card-body">

  <div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" id="w_imieniu_toggle" role="switch"
           <?= !empty($_POST['w_imieniu_toggle']) ? 'checked' : '' ?>>
    <label class="form-check-label fw-semibold" for="w_imieniu_toggle">
      Składam wniosek w imieniu wolontariusza
    </label>
  </div>

  <!-- Własny wniosek (domyślnie) -->
  <div id="box-sam" class="alert alert-light border py-2 mb-0 d-flex align-items-center gap-2" style="font-size:.87rem">
    <i class="bi bi-person-circle text-muted fs-5"></i>
    <div>
      Wniosek zostanie zarejestrowany jako złożony przez
      <strong><?= h(current_user()['name'] ?? current_user()['email']) ?></strong>.
    </div>
  </div>

  <!-- W imieniu -->
  <div id="box-imieniu" style="display:none">
    <div class="mb-3">
      <label class="form-label fw-semibold">Wybierz wolontariusza z systemu</label>
      <select name="w_imieniu_id" id="w_imieniu_id_sel" class="form-select">
        <option value="">— wybierz użytkownika —</option>
        <?php foreach ($wszyscy_uzytkownicy as $u): ?>
        <option value="<?= $u['id'] ?>"
                <?= ($_POST['w_imieniu_id'] ?? 0) == $u['id'] ? 'selected' : '' ?>>
          <?= h($u['name'] ?: $u['email']) ?>
          <?php if ($u['email']): ?><span class="text-muted">(<?= h($u['email']) ?>)</span><?php endif; ?>
        </option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Lub wpisz imię i nazwisko poniżej, jeśli wolontariusz nie ma konta w systemie.</div>
    </div>
    <div class="mb-0">
      <label class="form-label fw-semibold">Imię i nazwisko wolontariusza <span class="text-muted small fw-normal">(jeśli brak konta)</span></label>
      <input type="text" name="w_imieniu_imie" class="form-control"
             placeholder="np. Jan Kowalski"
             value="<?= h($_POST['w_imieniu_imie'] ?? '') ?>"
             id="w_imieniu_imie_input">
      <div class="form-text">
        Zostanie dodane w opisie wniosku jako adnotacja.
        Jeśli wybrano użytkownika powyżej, to pole jest ignorowane.
      </div>
    </div>
    <div class="alert alert-info py-2 mt-3 mb-0" style="font-size:.82rem" id="imieniu-info" <?= empty($_POST['w_imieniu_toggle']) ? '' : '' ?>>
      <i class="bi bi-info-circle me-1"></i>
      W imieniu: <strong id="imieniu-label">—</strong> ·
      Zarejestruje: <strong><?= h(current_user()['name'] ?? '') ?></strong>
    </div>
  </div>

</div>
</div>

<!-- ── Dane wniosku ───────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
<div class="card-header fw-semibold"><i class="bi bi-pencil-square text-success me-1"></i> Wniosek</div>
<div class="card-body">
  <div class="mb-3">
    <label class="form-label fw-semibold">Tytuł wydatku <span class="text-danger">*</span></label>
    <input name="tytul" class="form-control" required
           value="<?= h($_POST['tytul'] ?? '') ?>"
           placeholder="np. Bilet kolejowy Warszawa–Kraków">
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <label class="form-label fw-semibold">Kwota <span class="text-danger">*</span></label>
      <div class="input-group">
        <input name="kwota" type="number" step="0.01" min="0.01" class="form-control" required
               value="<?= h($_POST['kwota'] ?? '') ?>" placeholder="0,00">
        <span class="input-group-text">PLN</span>
      </div>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold">Data wydatku <span class="text-danger">*</span></label>
      <input name="data_wydatku" type="date" class="form-control" required
             max="<?= date('Y-m-d') ?>"
             value="<?= h($_POST['data_wydatku'] ?? '') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold">Kategoria</label>
      <select name="kategoria" class="form-select">
        <option value="">— wybierz —</option>
        <?php foreach (FinanceManager::KATEGORIE as $k => $l): ?>
        <option value="<?= $k ?>" <?= ($_POST['kategoria'] ?? '') === $k ? 'selected' : '' ?>><?= h($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Opis / uzasadnienie</label>
    <textarea name="opis" class="form-control" rows="3"
              placeholder="Krótki opis celu i okoliczności wydatku…"><?= h($_POST['opis'] ?? '') ?></textarea>
  </div>

  <!-- KSeF -->
  <div class="card border-0 bg-light rounded-3 p-3 mb-3">
    <div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" name="faktura_elektroniczna"
             id="faktura_elektroniczna" value="1"
             <?= !empty($_POST['faktura_elektroniczna']) ? 'checked' : '' ?>
             onchange="toggleKsef(this.checked)">
      <label class="form-check-label fw-semibold" for="faktura_elektroniczna">
        <i class="bi bi-qr-code me-1 text-primary"></i>
        Posiadam fakturę elektroniczną (KSeF)
      </label>
    </div>
    <div id="ksef-info-box" class="small text-muted ms-4" style="<?= !empty($_POST['faktura_elektroniczna']) ? '' : 'display:none' ?>">
      <i class="bi bi-info-circle me-1 text-primary"></i>
      Numer KSeF zostanie uzupełniony przez administratora po weryfikacji faktury w systemie KSeF.
      Dołącz plik faktury jako załącznik poniżej.
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Załączniki (faktury, paragony, bilety)</label>
    <input type="file" name="zalaczniki[]" class="form-control" multiple
           accept=".pdf,.jpg,.jpeg,.png,.webp,.xls,.xlsx,.doc,.docx">
    <div class="form-text">PDF, JPG, PNG, Excel, Word · max 10 MB / plik · można wybrać kilka plików</div>
  </div>
</div>
</div>

</div><!-- /col-xl-8 -->

<!-- Sidebar -->
<div class="col-xl-4">
<div class="card shadow-sm sticky-top" style="top:80px">
<div class="card-header fw-semibold"><i class="bi bi-info-circle text-muted me-1"></i> Informacje</div>
<div class="card-body small text-muted">
  <ul class="mb-3 ps-3">
    <li>Wniosek trafia do weryfikacji merytorycznej po złożeniu.</li>
    <li>Numer wniosku zostanie nadany automatycznie.</li>
    <li>Dołącz skany paragonów lub faktur jako załączniki.</li>
    <li>Kwota nie może przekraczać dostępnego limitu umowy.</li>
  </ul>
  <hr class="my-2">
  <div class="fw-semibold text-dark mb-1">Statusy workflow:</div>
  <?php foreach (FinanceManager::STATUSES as $s => $cfg): ?>
  <div class="mb-1">
    <?= zwroty_status_badge($s) ?>
    <span class="ms-1"><?= h($cfg['label']) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<div class="card-footer">
  <button type="submit" class="btn btn-success w-100">
    <i class="bi bi-send-check me-1"></i>Złóż wniosek
  </button>
  <a href="index.php" class="btn btn-outline-secondary w-100 mt-2">Anuluj</a>
</div>
</div>
</div>

</div><!-- /row -->
</form>

<script>
// ── Eligibility AJAX ─────────────────────────────────────────────────────
function checkEligibility(umowa_id) {
    const box = document.getElementById('eligibility-info');
    if (!umowa_id) { box.style.display = 'none'; return; }
    box.innerHTML = '<div class="text-muted small py-2"><i class="bi bi-hourglass-split me-1"></i>Sprawdzam...</div>';
    box.style.display = '';
    // Autofill imienia wolontariusza z mapy umów
    const mapa = <?= json_encode($umowy_map) ?>;
    if (mapa[umowa_id]) {
        const imieEl = document.getElementById('w_imieniu_imie_input');
        if (imieEl && !imieEl.value) imieEl.value = mapa[umowa_id].wolontariusz || '';
        updateImieniuLabel();
    }
    fetch('<?= APP_URL ?>/contracts/zwroty/eligibility_ajax.php?umowa_id=' + umowa_id + '&umowa_type=wolontariat')
        .then(r => r.text())
        .then(html => { box.innerHTML = html; })
        .catch(() => { box.innerHTML = '<div class="alert alert-warning py-1 small">Nie udało się sprawdzić dostępności.</div>'; });
}

// ── Bez umowy toggle ─────────────────────────────────────────────────────
function toggleBezUmowy(on) {
    document.getElementById('umowa-selector').style.display  = on ? 'none' : '';
    document.getElementById('bez-umowy-info').style.display  = on ? ''     : 'none';
    const sel = document.getElementById('umowa_id_sel');
    if (sel) sel.required = !on;
    if (on) document.getElementById('eligibility-info').style.display = 'none';
}

// ── KSeF toggle ───────────────────────────────────────────────────────────
function toggleKsef(on) {
    const box = document.getElementById('ksef-info-box');
    if (box) box.style.display = on ? '' : 'none';
}

// ── W imieniu toggle ─────────────────────────────────────────────────────
const toggle    = document.getElementById('w_imieniu_toggle');
const boxSam    = document.getElementById('box-sam');
const boxIm     = document.getElementById('box-imieniu');
const selUser   = document.getElementById('w_imieniu_id_sel');
const inputImie = document.getElementById('w_imieniu_imie_input');
const labelEl   = document.getElementById('imieniu-label');

function updateImieniuLabel() {
    if (!labelEl) return;
    const sel = selUser?.options[selUser.selectedIndex];
    if (sel && selUser.value) {
        labelEl.textContent = sel.text.replace(/\(.*\)/, '').trim();
    } else if (inputImie?.value.trim()) {
        labelEl.textContent = inputImie.value.trim();
    } else {
        labelEl.textContent = '—';
    }
}

function applyToggle() {
    const on = toggle?.checked;
    if (boxSam)  boxSam.style.display  = on ? 'none' : '';
    if (boxIm)   boxIm.style.display   = on ? ''     : 'none';
    // Wyczyść pola gdy wyłączamy
    if (!on && selUser)   selUser.value   = '';
    if (!on && inputImie) inputImie.value = '';
}

toggle?.addEventListener('change', applyToggle);
selUser?.addEventListener('change', updateImieniuLabel);
inputImie?.addEventListener('input', updateImieniuLabel);

// Inicjalizacja stanu przy załadowaniu strony (po błędzie walidacji)
applyToggle();
updateImieniuLabel();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
