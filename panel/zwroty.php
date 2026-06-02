<?php
/**
 * Panel wolontariusza — Moje zwroty kosztów
 * Dostęp: każdy zalogowany użytkownik (require_login).
 * Pokazuje własne wnioski i umożliwia złożenie nowego dla aktywnych umów
 * wolontariackich z flagą zwrot_kosztow = 1.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/zwroty_kosztow.php';

require_login();
$PAGE_TITLE = 'Moje zwroty kosztów';
$user    = current_user();
$user_id = (int)$user['id'];
$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

$_db_user = db_one("SELECT microsoft_id FROM users WHERE id = ?", [$user_id]);
$user['microsoft_id'] = $_db_user['microsoft_id'] ?? '';

$fm = new FinanceManager();

// ── Umowy wolontariatu powiązane z tym użytkownikiem + flaga zwrot_kosztow ───
function panel_zwroty_contracts(array $user): array
{
    $email = $user['email'] ?? '';
    $ms_id = $user['microsoft_id'] ?? '';
    if (!$email && !$ms_id) return [];

    $conds = []; $params = [];
    foreach (['m365_user_id' => $ms_id, 'm365_login' => $email, 'email' => $email] as $col => $val) {
        if (!$val) continue;
        $conds[]  = "{$col} = ?";
        $params[] = $val;
    }
    if (!$conds) return [];

    $active = "'aktywna','w_realizacji','zatwierdzony','zatwierdzona','aktywny','obowiazuje'";

    return db_all(
        "SELECT id, numer_umowy, imie_nazwisko, status, data_zawarcia, data_zakonczenia,
                limit_zwrotu_kosztow
         FROM umowy_wolontariat
         WHERE zwrot_kosztow = 1
           AND status IN ({$active})
           AND (" . implode(' OR ', $conds) . ")
         ORDER BY data_zakonczenia DESC",
        $params
    );
}

$moje_umowy = panel_zwroty_contracts($user);
$umowy_ids  = array_map('intval', array_column($moje_umowy, 'id'));

// ── Moje wnioski (wszystkie, nie tylko dla powyższych umów) ──────────────────
$moje_wnioski = db_all(
    "SELECT z.*, w.numer_umowy, w.imie_nazwisko AS wolontariusz
     FROM zwroty_kosztow z
     LEFT JOIN umowy_wolontariat w ON w.id = z.umowa_id AND z.umowa_type = 'wolontariat'
     WHERE z.wnioskodawca_id = ?
     ORDER BY z.created_at DESC",
    [$user_id]
);

$errors = [];

// ── POST: złóż nowy wniosek ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $contract_id           = (int)($_POST['umowa_id'] ?? 0);
    $tytul                 = trim($_POST['tytul'] ?? '');
    $opis                  = trim($_POST['opis'] ?? '');
    $kwota                 = str_replace(',', '.', trim($_POST['kwota'] ?? ''));
    $data_wydatku          = trim($_POST['data_wydatku'] ?? '');
    $kategoria             = trim($_POST['kategoria'] ?? '');
    $faktura_elektroniczna = isset($_POST['faktura_elektroniczna']) ? 1 : 0;

    // Bezpieczeństwo: tylko własne umowy
    if (!in_array($contract_id, $umowy_ids, true)) {
        $errors[] = 'Wybrana umowa nie jest powiązana z Twoim kontem.';
    }

    // Eligibility
    $el = null;
    if ($contract_id && !$errors) {
        $el = $fm->validateEligibility($contract_id, 'wolontariat');
        if (!$el['eligible']) $errors[] = $el['reason'];
    }

    // Walidacja pól
    if (!$contract_id)    $errors[] = 'Wybierz umowę.';
    if ($tytul === '')    $errors[] = 'Tytuł wydatku jest wymagany.';
    if (!is_numeric($kwota) || (float)$kwota <= 0) {
        $errors[] = 'Kwota musi być liczbą większą od zera.';
    }
    if ($data_wydatku === '')             $errors[] = 'Data wydatku jest wymagana.';
    if ($data_wydatku > date('Y-m-d'))   $errors[] = 'Data wydatku nie może być w przyszłości.';
    if ($el && $el['dostepny'] !== null && !$errors && (float)$kwota > $el['dostepny']) {
        $errors[] = sprintf(
            'Kwota (%.2f PLN) przekracza dostępny limit (%.2f PLN).',
            (float)$kwota, $el['dostepny']
        );
    }

    // Załączniki
    $zalaczniki = [];
    if (!empty($_FILES['zalaczniki']['name'][0])) {
        $upload_dir = dirname(__DIR__) . '/uploads/zwroty/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        foreach ($_FILES['zalaczniki']['tmp_name'] as $i => $tmp) {
            if ($_FILES['zalaczniki']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $orig = $_FILES['zalaczniki']['name'][$i];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf','jpg','jpeg','png','gif','webp','xlsx','xls','csv','doc','docx'])) {
                $errors[] = "Niedozwolony format pliku: " . h($orig);
                continue;
            }
            if ($_FILES['zalaczniki']['size'][$i] > 10 * 1024 * 1024) {
                $errors[] = "Plik " . h($orig) . " przekracza 10 MB.";
                continue;
            }
            $fname = date('Ymd_His') . '_' . $i . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $orig);
            if (move_uploaded_file($tmp, $upload_dir . $fname)) {
                $zalaczniki[] = ['name' => $orig, 'file' => $fname];
            }
        }
    }

    if (!$errors) {
        try {
            $rid = $fm->createRequest($contract_id, 'wolontariat', $user_id, [
                'tytul'                 => $tytul,
                'opis'                  => $opis,
                'kwota'                 => (float)$kwota,
                'data_wydatku'          => $data_wydatku,
                'kategoria'             => $kategoria ?: null,
                'zalaczniki'            => $zalaczniki,
                'bez_umowy'             => 0,
                'faktura_elektroniczna' => $faktura_elektroniczna,
                'zlozone_przez_id'      => $user_id,
            ]);
            $nr_wniosku = db_one("SELECT nr_wniosku FROM zwroty_kosztow WHERE id = ?", [$rid])['nr_wniosku'] ?? '';
            require_once dirname(__DIR__) . '/includes/notifications.php';
            $admins = db_all("SELECT id FROM users WHERE role='admin' AND is_active=1");
            foreach ($admins as $adm) {
                notif_create((int)$adm['id'], 'system', 'Wniosek o zwrot kosztów — ' . $user['name'], '', APP_URL . '/admin/applications.php');
            }
            flash_set('success', "Wniosek o zwrot kosztów został złożony. Numer: <strong>" . h($nr_wniosku) . "</strong>");
            header('Location: ' . APP_URL . '/panel/zwroty.php'); exit;
        } catch (\Throwable $e) {
            $errors[] = 'Błąd zapisu: ' . $e->getMessage();
        }
    }
}

// ── Liczniki do nagłówka ─────────────────────────────────────────────────────
$pending_count = count(array_filter($moje_wnioski, fn($r) => in_array($r['status'], ['oczekuje','weryfikacja'])));
$paid_sum      = array_sum(array_column(
    array_filter($moje_wnioski, fn($r) => $r['status'] === 'wyplacono'),
    'kwota'
));

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<style>
.zwrot-row-detail { background: #f8f9fa; }
.zwrot-status-bar {
    display: flex; gap: .5rem; flex-wrap: wrap; align-items: center;
    margin-bottom: .5rem;
}
</style>

<?php if ($_is_volunteer_only): ?>

<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-receipt me-2" aria-hidden="true"></i>Zwroty kosztów</h1>
  <p class="pv-page-sub">Wnioski o zwrot kosztów wolontariatu</p>
</div>
<?php echo flash_html(); ?>

<div class="vol-data-grid mb-4">
  <div class="vol-data-item">
    <div class="vol-data-lbl">Złożone wnioski</div>
    <div class="vol-data-val"><?= count($moje_wnioski) ?></div>
  </div>
  <?php if ($pending_count): ?>
  <div class="vol-data-item" style="border-color:#FDE68A">
    <div class="vol-data-lbl">W toku</div>
    <div class="vol-data-val" style="color:#D97706"><?= $pending_count ?></div>
  </div>
  <?php endif; ?>
  <?php if ($paid_sum > 0): ?>
  <div class="vol-data-item" style="border-color:#A7F3D0">
    <div class="vol-data-lbl">Wypłacono łącznie</div>
    <div class="vol-data-val" style="color:#059669;font-size:.85rem"><?= number_format($paid_sum, 2, ',', ' ') ?> PLN</div>
  </div>
  <?php endif; ?>
</div>

<?php if ($moje_wnioski): ?>
<div class="vol-activity mb-4">
  <div class="vol-activity-header">
    <span class="vol-activity-title"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Historia wniosków</span>
    <span class="badge bg-secondary"><?= count($moje_wnioski) ?></span>
  </div>
  <?php foreach ($moje_wnioski as $wniosek):
    $st_map = ['oczekuje' => 'warning', 'weryfikacja' => 'info', 'zatwierdzone' => 'success', 'odrzucony' => 'danger', 'wyplacono' => 'success'];
    $w_color = $st_map[$wniosek['status']] ?? 'secondary';
    $w_icon  = ['oczekuje' => 'bi-hourglass-split', 'weryfikacja' => 'bi-search', 'zatwierdzone' => 'bi-check-circle', 'odrzucony' => 'bi-x-circle', 'wyplacono' => 'bi-cash-stack'][$wniosek['status']] ?? 'bi-circle';
  ?>
  <div class="vol-activity-row">
    <div class="vol-activity-icon bg-<?= $w_color ?> bg-opacity-15 text-<?= $w_color ?>">
      <i class="bi <?= $w_icon ?>" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1" style="min-width:0">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="fw-semibold <?= $wniosek['status'] === 'odrzucony' ? 'text-decoration-line-through' : '' ?>" style="font-size:.85rem"><?= h($wniosek['tytul']) ?></span>
        <?= zwroty_status_badge($wniosek['status']) ?>
      </div>
      <div class="text-muted" style="font-size:.78rem">
        <?= h($wniosek['numer_umowy'] ?? '—') ?> ·
        <strong><?= number_format((float)$wniosek['kwota'], 2, ',', ' ') ?> PLN</strong>
        <?php if ($wniosek['data_wydatku']): ?> · <?= date('d.m.Y', strtotime($wniosek['data_wydatku'])) ?><?php endif; ?>
      </div>
      <?php if ($wniosek['odrzucenie_powod']): ?>
      <div class="text-danger" style="font-size:.78rem">
        <i class="bi bi-x-circle me-1" aria-hidden="true"></i><?= h(mb_strimwidth($wniosek['odrzucenie_powod'], 0, 80, '…')) ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="text-muted text-nowrap" style="font-size:.77rem"><?= h(substr($wniosek['created_at'], 0, 10)) ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="vol-detail-card mb-4">
  <div class="vol-detail-body text-center py-4 text-muted">
    <i class="bi bi-receipt" style="font-size:2.5rem;opacity:.2" aria-hidden="true"></i>
    <p class="mt-3 mb-0 small">Nie złożono jeszcze żadnych wniosków o zwrot kosztów.</p>
  </div>
</div>
<?php endif; ?>

<?php if (!$moje_umowy): ?>
<div class="vol-detail-card mb-4">
  <div class="vol-detail-body text-center py-4 text-muted">
    <i class="bi bi-file-earmark-x" style="font-size:2.5rem;opacity:.2" aria-hidden="true"></i>
    <p class="mt-3 fw-semibold mb-1">Brak aktywnych umów z możliwością zwrotu kosztów</p>
    <p class="small mb-0">Musisz posiadać aktywne porozumienie wolontariackie z włączoną opcją zwrotu kosztów. Skontaktuj się z koordynatorem.</p>
  </div>
</div>
<?php else: ?>

<div class="vol-detail-card" id="form-nowy-wniosek">
  <div class="vol-detail-header"><i class="bi bi-plus-circle me-2" aria-hidden="true"></i>Złóż nowy wniosek o zwrot kosztów</div>
  <div class="vol-detail-body">

    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i><strong>Sprawdź pola:</strong>
      <ul class="mb-0 mt-1"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
    </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="row g-3">
        <div class="col-12">
          <label class="form-label fw-semibold">Umowa wolontariacka <span class="text-danger">*</span></label>
          <select name="umowa_id" id="pp_umowa_id" class="form-select" required
                  onchange="ppCheckEligibility(this.value)">
            <option value="">— wybierz umowę —</option>
            <?php foreach ($moje_umowy as $u): ?>
            <option value="<?= (int)$u['id'] ?>"
                    <?= ($_POST['umowa_id'] ?? 0) == $u['id'] ? 'selected' : '' ?>>
              <?= h($u['numer_umowy']) ?>
              <?= $u['imie_nazwisko'] ? ' · ' . h($u['imie_nazwisko']) : '' ?>
              <?= $u['data_zakonczenia'] ? ' (do ' . date('d.m.Y', strtotime($u['data_zakonczenia'])) . ')' : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div id="pp-eligibility-info" class="mt-2" style="display:none"></div>
        </div>

        <div class="col-12">
          <label class="form-label fw-semibold">Tytuł wydatku <span class="text-danger">*</span></label>
          <input type="text" name="tytul" class="form-control" required
                 placeholder="np. Bilet kolejowy Warszawa–Kraków, materiały do warsztatu…"
                 value="<?= h($_POST['tytul'] ?? '') ?>">
        </div>

        <div class="col-sm-4">
          <label class="form-label fw-semibold">Kwota <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="number" name="kwota" step="0.01" min="0.01" class="form-control" required
                   placeholder="0,00" value="<?= h($_POST['kwota'] ?? '') ?>">
            <span class="input-group-text">PLN</span>
          </div>
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Data wydatku <span class="text-danger">*</span></label>
          <input type="date" name="data_wydatku" class="form-control" required
                 max="<?= date('Y-m-d') ?>"
                 value="<?= h($_POST['data_wydatku'] ?? '') ?>">
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Kategoria</label>
          <select name="kategoria" class="form-select">
            <option value="">— wybierz —</option>
            <?php foreach (FinanceManager::KATEGORIE as $k => $l): ?>
            <option value="<?= $k ?>" <?= ($_POST['kategoria'] ?? '') === $k ? 'selected' : '' ?>>
              <?= h($l) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12">
          <label class="form-label fw-semibold">Opis / uzasadnienie</label>
          <textarea name="opis" class="form-control" rows="3"
                    placeholder="Krótki opis celu i okoliczności wydatku…"><?= h($_POST['opis'] ?? '') ?></textarea>
        </div>

        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="faktura_elektroniczna"
                   id="pp_faktura_el" value="1"
                   <?= !empty($_POST['faktura_elektroniczna']) ? 'checked' : '' ?>
                   onchange="document.getElementById('pp-ksef-note').style.display=this.checked?'':'none'">
            <label class="form-check-label" for="pp_faktura_el">
              <i class="bi bi-qr-code me-1 text-primary" aria-hidden="true"></i>
              Posiadam fakturę elektroniczną (KSeF)
            </label>
          </div>
          <div id="pp-ksef-note" class="small text-muted ms-4 mt-1"
               style="<?= !empty($_POST['faktura_elektroniczna']) ? '' : 'display:none' ?>">
            <i class="bi bi-info-circle me-1 text-primary" aria-hidden="true"></i>
            Numer KSeF zostanie uzupełniony przez administratora po weryfikacji.
          </div>
        </div>

        <div class="col-12">
          <label class="form-label fw-semibold">Załączniki <span class="text-muted fw-normal">(faktury, paragony, bilety)</span></label>
          <input type="file" name="zalaczniki[]" class="form-control" multiple
                 accept=".pdf,.jpg,.jpeg,.png,.webp,.xls,.xlsx,.doc,.docx">
          <div class="form-text">PDF, JPG, PNG, Excel, Word · max 10 MB / plik · można wybrać kilka</div>
        </div>

        <div class="col-12 d-flex gap-2">
          <button type="submit" style="background:var(--vol-color);color:#fff;border:none;border-radius:8px;padding:.55rem 1.25rem;font-weight:600">
            <i class="bi bi-send-check me-1" aria-hidden="true"></i>Złóż wniosek
          </button>
          <a href="<?= APP_URL ?>/panel/index.php" class="btn btn-outline-secondary">
            Wróć do panelu
          </a>
        </div>
      </div>
    </form>

  </div>
</div>

<?php endif; // moje_umowy ?>

<script>
// ── Eligibility AJAX (reuse istniejącego endpointu) ──────────────────────────
function ppCheckEligibility(umowa_id) {
    const box = document.getElementById('pp-eligibility-info');
    if (!umowa_id) { box.style.display = 'none'; return; }
    box.style.display = '';
    box.innerHTML = '<div class="text-muted small py-2"><i class="bi bi-hourglass-split me-1"></i>Sprawdzam dostępność…</div>';
    fetch('<?= APP_URL ?>/contracts/zwroty/eligibility_ajax.php?umowa_id=' + encodeURIComponent(umowa_id) + '&umowa_type=wolontariat')
        .then(r => r.text())
        .then(html => { box.innerHTML = html; })
        .catch(() => {
            box.innerHTML = '<div class="alert alert-warning py-1 small mb-0">Nie udało się sprawdzić dostępności.</div>';
        });
}

// Auto-sprawdź przy załadowaniu (np. po błędzie POST)
(function() {
    const sel = document.getElementById('pp_umowa_id');
    if (sel && sel.value) ppCheckEligibility(sel.value);

    // Obróć ikonę przycisku przy rozwijaniu szczegółów
    document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(btn => {
        const target = document.querySelector(btn.dataset.bsTarget);
        if (!target) return;
        target.addEventListener('show.bs.collapse',  () => btn.querySelector('i').className = 'bi bi-chevron-up');
        target.addEventListener('hide.bs.collapse',  () => btn.querySelector('i').className = 'bi bi-chevron-down');
    });
})();
</script>

<?php endif; // $_is_volunteer_only ?>

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
