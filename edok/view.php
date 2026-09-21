<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

$id  = (int)($_GET['id'] ?? 0);
$doc = edok_get($id);
if (!$doc) { http_response_code(404); die('Dokument nie istnieje.'); }

$user   = current_user();
$errors = [];

$locked_meryt      = in_array($doc['steps']['meryt']['status']      ?? null, ['ok', 'uwagi', 'odrzucono'], true);
$locked_formal     = in_array($doc['steps']['formal']['status']     ?? null, ['ok', 'uwagi', 'odrzucono'], true);
$locked_rachunkowa = in_array($doc['steps']['rachunkowa']['status'] ?? null, ['ok', 'uwagi', 'odrzucono'], true);
$locked_dekretacja = in_array($doc['steps']['dekretacja']['status'] ?? null, ['ok', 'uwagi', 'odrzucono'], true);
$is_terminal       = in_array($doc['status'], ['zaakceptowany', 'odrzucony', 'wycofany'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Edycja danych dokumentu — pola blokują się jedna po drugiej wraz z postępem obiegu,
    // żeby nie dało się zmienić danych na etapie już zdecydowanym (integralność decyzji).
    if ($action === 'update_meta' && (is_admin() || edok_has_role('upload') || edok_has_role('dekretacja')) && !$is_terminal) {
        $description      = $locked_meryt      ? $doc['description']      : trim($_POST['description'] ?? '');
        $kontrahent_nazwa = $locked_formal      ? $doc['kontrahent_nazwa'] : trim($_POST['kontrahent_nazwa'] ?? '');
        $kontrahent_nip   = $locked_formal      ? $doc['kontrahent_nip']   : preg_replace('/\D/', '', trim($_POST['kontrahent_nip'] ?? ''));
        $nr_faktury       = $locked_formal      ? $doc['nr_faktury']       : trim($_POST['nr_faktury'] ?? '');
        $zrodlo_przychodu = $locked_formal      ? $doc['zrodlo_przychodu'] : trim($_POST['zrodlo_przychodu'] ?? '');
        $kwota_netto      = $locked_rachunkowa  ? $doc['kwota_netto']      : trim($_POST['kwota_netto'] ?? '');
        $kwota_vat        = $locked_rachunkowa  ? $doc['kwota_vat']        : trim($_POST['kwota_vat'] ?? '');
        $kwota_brutto     = $locked_rachunkowa  ? $doc['kwota_brutto']     : trim($_POST['kwota_brutto'] ?? '');
        $rodzaj           = $locked_dekretacja  ? $doc['rodzaj_dzialalnosci'] : ($_POST['rodzaj_dzialalnosci'] ?? '');
        if (!isset(EDOK_RODZAJ_DZIALALNOSCI[$rodzaj])) $rodzaj = $locked_dekretacja ? $doc['rodzaj_dzialalnosci'] : '';
        $projekt          = $locked_dekretacja  ? $doc['projekt']         : trim($_POST['projekt'] ?? '');
        $mpk              = trim($_POST['mpk'] ?? '');
        $tytul_przelewu   = trim($_POST['tytul_przelewu'] ?? '');
        if ($doc['kierunek'] === 'przychod') {
            $tytul_przelewu = '';
        } elseif ($tytul_przelewu === '') {
            $tytul_przelewu = edok_generate_tytul_przelewu([
                'typ_dokumentu'    => $doc['typ_dokumentu'],
                'nr_faktury'       => $nr_faktury,
                'number'           => $doc['number'],
                'data_wystawienia' => $doc['data_wystawienia'],
                'description'      => $description,
                'kierunek'         => $doc['kierunek'],
            ]);
        }

        db_exec(
            "UPDATE edok_documents SET description=?, kontrahent_nazwa=?, kontrahent_nip=?, nr_faktury=?, zrodlo_przychodu=?,
                kwota_netto=?, kwota_vat=?, kwota_brutto=?, rodzaj_dzialalnosci=?, projekt=?, mpk=?, tytul_przelewu=?, updated_at=datetime('now')
             WHERE id=?",
            [$description, $kontrahent_nazwa, $kontrahent_nip, $nr_faktury, $zrodlo_przychodu, $kwota_netto, $kwota_vat, $kwota_brutto, $rodzaj, $projekt, $mpk, $tytul_przelewu, $id]
        );
        edok_log($id, 'edit', '', $doc['status'], $doc['status'], 'Zaktualizowano dane dokumentu.');
        flash_set('success', 'Dane zaktualizowane.');
        header('Location: ' . APP_URL . '/edok/view.php?id=' . $id);
        exit;
    }

    // Krok akceptacji: meryt / formal / rachunkowa / dekretacja / zatwierdza
    if (in_array($action, edok_step_order(), true)) {
        edok_require_role($action);

        if ($is_terminal) {
            $errors[] = 'Dokument jest już ' . ($doc['status'] === 'zaakceptowany' ? 'zaakceptowany' : ($doc['status'] === 'wycofany' ? 'wycofany' : 'odrzucony')) . ' — decyzja jest zablokowana.';
        }

        $existing_step = $doc['steps'][$action] ?? null;
        if (!$errors && $existing_step && in_array($existing_step['status'], ['ok', 'uwagi', 'odrzucono'], true)) {
            $errors[] = 'Decyzja dla tego etapu została już podjęta i nie może być zmieniona.';
        }

        if (!$errors) {
            $blocked = edok_step_blocked_reason($doc, $action);
            if ($blocked) $errors[] = $blocked;
        }

        $status = $_POST['step_status'] ?? '';
        $notes  = trim($_POST['step_notes'] ?? '');
        $pin    = trim($_POST['step_pin'] ?? '');
        $pin_verified = false;

        if (!$errors) {
            if (!in_array($status, ['ok', 'uwagi', 'odrzucono'], true)) {
                $errors[] = 'Wybierz decyzję.';
            } elseif ($status === 'ok') {
                foreach (edok_step_validation_errors($doc, $action) as $e) $errors[] = $e;
                // Weryfikacja tożsamości PIN-em — wymagana wyłącznie przy akceptacji
                // ("Tak/OK"), zgodnie z Uchwałą 5/2026 §1 pkt 4.
                if (!$errors) {
                    $pin_error = edok_pin_verify_for_decision((int)$user['id'], $pin, $id, $action);
                    if ($pin_error !== null) {
                        $errors[] = $pin_error;
                    } else {
                        $pin_verified = true;
                    }
                }
            } elseif ($status === 'odrzucono' && $notes === '') {
                $errors[] = 'Podaj powód odrzucenia.';
            }
        }

        if (!$errors) {
            $result = edok_decide_step($doc, $action, $status, (int)$user['id'], $notes, $pin_verified);
            flash_set($result['rejected'] ? 'warning' : 'success', $result['rejected'] ? 'Dokument odrzucony.' : 'Decyzja zapisana.');
            header('Location: ' . APP_URL . '/edok/view.php?id=' . $id);
            exit;
        }
    }

    // Cofnięcie decyzji — tylko admin / rola 'ksiegowy'
    if ($action === 'unlock_doc') {
        if (!edok_has_unlock_perm()) {
            flash_set('danger', 'Brak uprawnień do cofania decyzji.');
            header('Location: ' . APP_URL . '/edok/view.php?id=' . $id);
            exit;
        }
        $reason = trim($_POST['unlock_reason'] ?? '');
        if ($reason === '') {
            $errors[] = 'Podaj powód cofnięcia decyzji.';
        } else {
            try {
                edok_unlock($id, $reason);
                flash_set('warning', 'Decyzja cofnięta — obieg wznowiony od etapu 1.');
            } catch (Throwable $e) {
                flash_set('danger', 'Błąd: ' . $e->getMessage());
            }
            header('Location: ' . APP_URL . '/edok/view.php?id=' . $id);
            exit;
        }
    }

    // Wycofanie dokumentu przez wnioskodawcę / admina
    if ($action === 'withdraw_doc' && (is_admin() || (int)$doc['created_by'] === (int)($user['id'] ?? 0))) {
        $reason = trim($_POST['withdraw_reason'] ?? 'Wycofano przez wnioskodawcę.');
        try {
            edok_withdraw($id, $reason);
            flash_set('warning', 'Dokument wycofany.');
        } catch (Throwable $e) {
            flash_set('danger', 'Błąd: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/edok/view.php?id=' . $id);
        exit;
    }

    $doc = edok_get($id); // odśwież po ew. nieudanej próbie
}

$PAGE_TITLE = $doc['number'] . ' — EODoK';
require_once __DIR__ . '/../includes/header.php';

$jest_przychod = ($doc['kierunek'] ?? 'wydatek') === 'przychod';
$steps_config = [
    'meryt'      => ['sub' => $jest_przychod ? 'Weryfikacja zgodności wpływu z rzeczywistym zdarzeniem' : 'Weryfikacja wykonania usługi/dostawy przez zleceniodawcę', 'icon' => 'bi-patch-check',       'color' => 'primary'],
    'formal'     => ['sub' => $jest_przychod ? 'Źródło i data wpływu, powiązanie z dokumentem'          : 'NIP, stawki VAT, elementy ustawowe faktury',                 'icon' => 'bi-file-earmark-check', 'color' => 'info'],
    'rachunkowa' => ['sub' => 'Przeliczenia i zgodność kwot netto/VAT/brutto',              'icon' => 'bi-calculator',         'color' => 'warning'],
    'dekretacja' => ['sub' => $jest_przychod ? 'Rodzaj działalności, projekt/MPK przychodu'              : 'Rodzaj działalności, projekt, MPK',                          'icon' => 'bi-journal-bookmark',   'color' => 'dark'],
    'zatwierdza' => ['sub' => $jest_przychod ? 'Zatwierdzenie do ujęcia przychodu w ewidencji'           : 'Zatwierdzenie do wypłaty i księgowania',                     'icon' => 'bi-cash-coin',          'color' => 'success'],
];
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-2">
    <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><code><?= h($doc['number']) ?></code> <?= edok_status_badge($doc['status']) ?></h4>
  </div>
  <?php $generated = edok_latest_generated_pdf($id); ?>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/edok/ustaw_pin.php" class="btn btn-sm btn-outline-secondary" title="Twój PIN EODoK">
      <i class="bi bi-shield-lock"></i>
    </a>
    <?php if ($generated): ?>
    <a href="<?= APP_URL ?>/edok/file.php?id=<?= $id ?>&type=final" target="_blank" class="btn btn-sm btn-primary">
      <i class="bi bi-file-earmark-check"></i> Pobierz dokument końcowy
    </a>
    <?php else: ?>
    <a href="<?= APP_URL ?>/edok/print.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-printer"></i> Wydruk dekretacji i zatwierdzenia
    </a>
    <?php endif; ?>
    <?php if (edok_has_unlock_perm() && $is_terminal): ?>
    <button class="btn btn-sm btn-outline-warning" type="button" data-bs-toggle="modal" data-bs-target="#unlockModal">
      <i class="bi bi-arrow-counterclockwise"></i> Cofnij decyzję
    </button>
    <?php endif; ?>
    <?php if (!$is_terminal && (is_admin() || (int)$doc['created_by'] === (int)($user['id'] ?? 0))): ?>
    <form method="post" onsubmit="return confirm('Wycofać dokument z obiegu?');" class="d-inline">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="withdraw_doc">
      <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-x-lg"></i> Wycofaj</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <!-- Karta dokumentu -->
    <div class="card shadow-sm mb-3">
      <div class="card-header py-2"><strong><i class="bi bi-file-earmark-text"></i> <?= h(EDOK_TYPES[$doc['typ_dokumentu']] ?? $doc['typ_dokumentu']) ?></strong></div>
      <div class="card-body py-2">
        <table class="table table-sm table-borderless mb-2 small">
          <tbody>
            <tr><td class="text-muted" style="width:42%">Kontrahent</td><td class="fw-semibold"><?= h($doc['kontrahent_nazwa']) ?></td></tr>
            <?php if ($doc['kontrahent_nip']): ?>
            <tr><td class="text-muted">NIP</td><td class="font-monospace"><?= h($doc['kontrahent_nip']) ?></td></tr>
            <?php endif; ?>
            <tr><td class="text-muted">Numer dokumentu</td><td class="font-monospace"><?= h($doc['nr_faktury']) ?></td></tr>
            <?php if ($doc['data_wystawienia']): ?><tr><td class="text-muted">Data wystawienia</td><td><?= date_pl($doc['data_wystawienia']) ?></td></tr><?php endif; ?>
            <?php if ($doc['data_sprzedazy']): ?><tr><td class="text-muted">Data sprzedaży/wykonania</td><td><?= date_pl($doc['data_sprzedazy']) ?></td></tr><?php endif; ?>
            <?php if ($doc['data_wplywu']): ?><tr><td class="text-muted">Data wpływu</td><td><?= date_pl($doc['data_wplywu']) ?></td></tr><?php endif; ?>
            <?php if ($doc['kierunek'] === 'przychod' && $doc['zrodlo_przychodu']): ?>
            <tr><td class="text-muted">Źródło przychodu</td><td><?= h($doc['zrodlo_przychodu']) ?></td></tr>
            <?php endif; ?>
            <?php if ($doc['tytul_przelewu']): ?>
            <tr>
              <td class="text-muted">Tytuł przelewu</td>
              <td>
                <span class="font-monospace" id="tytul_przelewu_view"><?= h($doc['tytul_przelewu']) ?></span>
                <button type="button" class="btn btn-sm btn-link p-0 ms-1" title="Kopiuj"
                  onclick="navigator.clipboard.writeText(document.getElementById('tytul_przelewu_view').textContent)">
                  <i class="bi bi-clipboard"></i>
                </button>
              </td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="p-2 rounded border bg-light mb-2">
          <table class="table table-sm table-borderless mb-0 small">
            <tbody>
              <tr><td class="text-muted" style="width:42%">Netto</td><td class="text-end font-monospace"><?= h($doc['kwota_netto']) ?></td></tr>
              <tr><td class="text-muted">VAT</td><td class="text-end font-monospace"><?= h($doc['kwota_vat']) ?></td></tr>
              <tr class="border-top"><td class="fw-semibold">Brutto</td><td class="text-end font-monospace fw-semibold"><?= h($doc['kwota_brutto']) ?> <?= h($doc['waluta']) ?></td></tr>
            </tbody>
          </table>
        </div>

        <?php if ($doc['description']): ?>
        <p class="small mb-2"><strong><?= $doc['kierunek'] === 'przychod' ? 'Opis przychodu:' : 'Opis wydatku:' ?></strong> <?= nl2br(h($doc['description'])) ?></p>
        <?php endif; ?>

        <?php if ($doc['file_path']): ?>
        <a href="<?= APP_URL ?>/edok/file.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-file-earmark-pdf"></i> Dokument źródłowy
        </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Dekretacja — ledger -->
    <div class="card shadow-sm mb-3">
      <div class="card-header py-2"><strong><i class="bi bi-journal-bookmark"></i> Dekretacja</strong></div>
      <div class="card-body py-2">
        <?php if ($doc['rodzaj_dzialalnosci'] || $doc['projekt'] || $doc['mpk']): ?>
        <table class="table table-sm table-borderless mb-0 small">
          <tbody>
            <?php if ($doc['rodzaj_dzialalnosci']): ?>
            <tr><td class="text-muted" style="width:42%">Rodzaj działalności</td><td class="fw-semibold"><?= h(EDOK_RODZAJ_DZIALALNOSCI[$doc['rodzaj_dzialalnosci']] ?? $doc['rodzaj_dzialalnosci']) ?></td></tr>
            <?php endif; ?>
            <?php if ($doc['projekt']): ?>
            <tr><td class="text-muted">Projekt / działanie</td><td class="font-monospace"><?= h($doc['projekt']) ?></td></tr>
            <?php endif; ?>
            <?php if ($doc['mpk']): ?>
            <tr><td class="text-muted">MPK</td><td class="font-monospace"><?= h($doc['mpk']) ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        <?php else: ?>
        <p class="text-muted small mb-0">Nie uzupełniono — wymagane przed zaakceptowaniem etapu „Dekretacja i alokacja kosztów”.</p>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$is_terminal && (is_admin() || edok_has_role('upload') || edok_has_role('dekretacja'))): ?>
    <button class="btn btn-sm btn-outline-secondary mb-3" type="button" data-bs-toggle="collapse" data-bs-target="#metaForm">
      <i class="bi bi-pencil"></i> Edytuj dane dokumentu
    </button>
    <div class="collapse" id="metaForm">
      <form method="post" class="border rounded p-2 bg-light mb-3">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="update_meta">

        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1">Opis wydatku <?= $locked_meryt ? '<i class="bi bi-lock-fill text-muted"></i>' : '' ?></label>
          <textarea name="description" class="form-control form-control-sm" rows="2" <?= $locked_meryt ? 'readonly' : '' ?>><?= h($doc['description']) ?></textarea>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-sm-8">
            <label class="form-label small fw-semibold mb-1">Kontrahent <?= $locked_formal ? '<i class="bi bi-lock-fill text-muted"></i>' : '' ?></label>
            <input type="text" name="kontrahent_nazwa" class="form-control form-control-sm" value="<?= h($doc['kontrahent_nazwa']) ?>" <?= $locked_formal ? 'readonly' : '' ?>>
          </div>
          <div class="col-sm-4">
            <label class="form-label small fw-semibold mb-1">NIP</label>
            <input type="text" name="kontrahent_nip" class="form-control form-control-sm" value="<?= h($doc['kontrahent_nip']) ?>" <?= $locked_formal ? 'readonly' : '' ?>>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1">Numer dokumentu</label>
          <input type="text" name="nr_faktury" class="form-control form-control-sm" value="<?= h($doc['nr_faktury']) ?>" <?= $locked_formal ? 'readonly' : '' ?>>
        </div>
        <?php if ($doc['kierunek'] === 'przychod'): ?>
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1">Źródło przychodu <?= $locked_formal ? '<i class="bi bi-lock-fill text-muted"></i>' : '' ?></label>
          <input type="text" name="zrodlo_przychodu" class="form-control form-control-sm" value="<?= h($doc['zrodlo_przychodu']) ?>" <?= $locked_formal ? 'readonly' : '' ?>>
        </div>
        <?php endif; ?>
        <div class="row g-2 mb-2">
          <div class="col-sm-4">
            <label class="form-label small fw-semibold mb-1">Netto <?= $locked_rachunkowa ? '<i class="bi bi-lock-fill text-muted"></i>' : '' ?></label>
            <input type="text" id="e_netto" name="kwota_netto" class="form-control form-control-sm font-monospace text-end" value="<?= h($doc['kwota_netto']) ?>" <?= $locked_rachunkowa ? 'readonly' : 'oninput="edokViewRecalc()"' ?>>
          </div>
          <div class="col-sm-4">
            <label class="form-label small fw-semibold mb-1">VAT</label>
            <input type="text" id="e_vat" name="kwota_vat" class="form-control form-control-sm font-monospace text-end" value="<?= h($doc['kwota_vat']) ?>" <?= $locked_rachunkowa ? 'readonly' : 'oninput="edokViewRecalc()"' ?>>
          </div>
          <div class="col-sm-4">
            <label class="form-label small fw-semibold mb-1">Brutto</label>
            <input type="text" id="e_brutto" name="kwota_brutto" class="form-control form-control-sm font-monospace text-end fw-semibold" value="<?= h($doc['kwota_brutto']) ?>" <?= $locked_rachunkowa ? 'readonly' : '' ?>>
          </div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-sm-6">
            <label class="form-label small fw-semibold mb-1">Rodzaj działalności <?= $locked_dekretacja ? '<i class="bi bi-lock-fill text-muted"></i>' : '' ?></label>
            <select name="rodzaj_dzialalnosci" class="form-select form-select-sm" <?= $locked_dekretacja ? 'disabled' : '' ?>>
              <option value="">— wybierz —</option>
              <?php foreach (EDOK_RODZAJ_DZIALALNOSCI as $k => $l): ?>
              <option value="<?= h($k) ?>" <?= $doc['rodzaj_dzialalnosci'] === $k ? 'selected' : '' ?>><?= h($l) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($locked_dekretacja): ?><input type="hidden" name="rodzaj_dzialalnosci" value="<?= h($doc['rodzaj_dzialalnosci']) ?>"><?php endif; ?>
          </div>
          <div class="col-sm-6">
            <label class="form-label small fw-semibold mb-1">Projekt / działanie</label>
            <input type="text" name="projekt" class="form-control form-control-sm" value="<?= h($doc['projekt']) ?>" <?= $locked_dekretacja ? 'readonly' : '' ?>>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1">MPK</label>
          <input type="text" name="mpk" class="form-control form-control-sm" value="<?= h($doc['mpk']) ?>">
        </div>
        <?php if ($doc['kierunek'] !== 'przychod'): ?>
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1">Tytuł przelewu</label>
          <div class="input-group input-group-sm">
            <input type="text" id="e_tytul" name="tytul_przelewu" class="form-control form-control-sm" maxlength="140" value="<?= h($doc['tytul_przelewu']) ?>">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="edokViewSuggestTytul()"><i class="bi bi-magic"></i> Generuj</button>
          </div>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save"></i> Zapisz</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-7">
    <!-- Kroki akceptacji -->
    <?php $n = 0; foreach ($steps_config as $step_key => $cfg): $n++; ?>
    <?php
    $step    = $doc['steps'][$step_key] ?? null;
    $decided = $step && in_array($step['status'], ['ok', 'uwagi', 'odrzucono'], true);
    $blocked_reason = $decided ? null : edok_step_blocked_reason($doc, $step_key);
    $can_act = !$decided && !$blocked_reason && edok_has_role($step_key) && !$is_terminal;
    $pending_validation = (!$decided && !$blocked_reason) ? edok_step_validation_errors($doc, $step_key) : [];
    ?>
    <div class="card shadow-sm mb-3 <?= $blocked_reason ? 'opacity-75' : '' ?>">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <span>
          <span class="badge bg-secondary me-1">Etap <?= $n ?>/5</span>
          <i class="bi <?= $cfg['icon'] ?> text-<?= $cfg['color'] ?>"></i>
          <strong><?= h(edok_step_label($step_key, $doc)) ?></strong>
          <div class="text-muted small ms-4"><?= h($cfg['sub']) ?></div>
        </span>
        <?php if ($decided): ?>
          <?php if ($step['status'] === 'ok'): ?>
          <span class="badge bg-success"><i class="bi bi-check-lg"></i> Tak</span>
          <?php elseif ($step['status'] === 'odrzucono'): ?>
          <span class="badge bg-danger"><i class="bi bi-x-lg"></i> Odrzucono</span>
          <?php else: ?>
          <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> Z uwagami</span>
          <?php endif; ?>
        <?php elseif ($blocked_reason): ?>
          <span class="badge bg-secondary"><i class="bi bi-lock-fill"></i> Zablokowany</span>
        <?php else: ?>
          <span class="badge bg-secondary">Oczekuje</span>
        <?php endif; ?>
      </div>
      <div class="card-body py-2">
        <?php if ($decided): ?>
        <div class="small">
          <span class="text-muted">Przez:</span> <strong><?= h($step['user_name']) ?></strong>
          <?php if ($step['user_role']): ?><span class="text-muted">(<?= h($step['user_role']) ?>)</span><?php endif; ?>
          &nbsp;|&nbsp; <?= date_pl($step['decided_at']) ?> <?= date('H:i', strtotime($step['decided_at'])) ?>
        </div>
        <?php if ($step['notes']): ?>
        <div class="mt-1 text-warning-emphasis small"><strong>Uwagi:</strong> <?= nl2br(h($step['notes'])) ?></div>
        <?php endif; ?>
        <?php elseif ($blocked_reason): ?>
        <p class="text-muted small mb-0"><i class="bi bi-lock-fill"></i> <?= h($blocked_reason) ?></p>
        <?php elseif ($can_act): ?>
          <?php if ($step_key === 'rachunkowa'): ?>
          <?php
          $netto  = (float) str_replace(',', '.', str_replace(' ', '', (string)$doc['kwota_netto']));
          $vat    = (float) str_replace(',', '.', str_replace(' ', '', (string)$doc['kwota_vat']));
          $brutto = (float) str_replace(',', '.', str_replace(' ', '', (string)$doc['kwota_brutto']));
          $ok_sum = $brutto <= 0 || abs(($netto + $vat) - $brutto) <= 0.01;
          ?>
          <table class="table table-sm table-borderless mb-2 small">
            <tbody>
              <tr><td class="text-muted" style="width:42%">Netto + VAT</td><td class="text-end font-monospace"><?= number_format($netto + $vat, 2, ',', ' ') ?></td></tr>
              <tr class="border-top"><td class="fw-semibold">Brutto na dokumencie</td><td class="text-end font-monospace fw-semibold"><?= number_format($brutto, 2, ',', ' ') ?></td></tr>
            </tbody>
          </table>
          <div class="mb-2 small <?= $ok_sum ? 'text-success' : 'text-danger' ?>">
            <i class="bi <?= $ok_sum ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
            <?= $ok_sum ? 'Kwoty się zgadzają.' : 'Kwoty się NIE zgadzają — popraw dane dokumentu przed akceptacją.' ?>
          </div>
          <?php endif; ?>
          <?php if ($pending_validation): ?>
          <div class="alert alert-warning py-2 small mb-2">
            <?php foreach ($pending_validation as $e): ?><div><i class="bi bi-exclamation-triangle"></i> <?= h($e) ?></div><?php endforeach; ?>
          </div>
          <?php else: ?>
          <p class="text-muted small mb-2">Oczekuje na Twoją decyzję.</p>
          <?php endif; ?>
        <?php else: ?>
        <p class="text-muted small mb-0">Oczekuje — brak uprawnień.</p>
        <?php endif; ?>

        <?php if ($can_act): ?>
        <div class="mt-2 border-top pt-2">
          <?php if (!edok_pin_is_set((int)$user['id'])): ?>
          <div class="alert alert-warning py-2 small mb-2">
            <i class="bi bi-shield-exclamation"></i> Nie masz jeszcze ustawionego PIN-u EODoK — wymagany do zaakceptowania ("Tak/OK") etapu.
            <a href="<?= APP_URL ?>/edok/ustaw_pin.php" class="alert-link">Ustaw PIN</a>.
          </div>
          <?php endif; ?>
          <form method="post" class="edok-step-form" onsubmit="return edokStepSubmit(event, this)">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="<?= $step_key ?>">
            <input type="hidden" name="step_pin" class="step-pin-field">
            <div class="d-flex gap-3 mb-2 flex-wrap">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="step_status" id="<?= $step_key ?>_ok" value="ok">
                <label class="form-check-label text-success fw-semibold" for="<?= $step_key ?>_ok"><i class="bi bi-check-circle-fill"></i> Tak / OK</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="step_status" id="<?= $step_key ?>_uwagi" value="uwagi">
                <label class="form-check-label text-warning fw-semibold" for="<?= $step_key ?>_uwagi"><i class="bi bi-exclamation-circle-fill"></i> Z uwagami</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="step_status" id="<?= $step_key ?>_odrzucono" value="odrzucono">
                <label class="form-check-label text-danger fw-semibold" for="<?= $step_key ?>_odrzucono"><i class="bi bi-x-circle-fill"></i> Odrzuć</label>
              </div>
            </div>
            <div class="mb-2">
              <textarea name="step_notes" class="form-control form-control-sm" rows="2" placeholder="Ewentualne uwagi lub powód odrzucenia…"></textarea>
            </div>
            <button type="submit" class="btn btn-sm btn-<?= $cfg['color'] ?>"><i class="bi bi-check2"></i> Zapisz decyzję</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Historia / audyt -->
    <div class="card shadow-sm mb-3">
      <div class="card-header py-2"><strong><i class="bi bi-clock-history"></i> Historia obiegu (audyt)</strong></div>
      <div class="card-body py-2">
        <div class="table-responsive">
          <table class="table table-sm mb-0 small">
            <thead><tr><th>Kiedy</th><th>Kto</th><th>Zdarzenie</th></tr></thead>
            <tbody>
              <?php foreach (edok_events($id) as $ev): ?>
              <tr>
                <td class="text-nowrap font-monospace"><?= date_pl($ev['created_at']) ?> <?= date('H:i', strtotime($ev['created_at'])) ?></td>
                <td><?= h($ev['actor_name']) ?><?= $ev['actor_role'] ? ' <span class="text-muted">(' . h($ev['actor_role']) . ')</span>' : '' ?></td>
                <td><?= h($ev['comment']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (edok_has_unlock_perm()): ?>
<div class="modal fade" id="unlockModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="unlock_doc">
        <div class="modal-header"><h5 class="modal-title">Cofnij decyzję</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <p class="small text-muted">Resetuje wszystkie etapy i wznawia obieg od etapu 1. Wymaga uzasadnienia (zapisywane w audycie).</p>
          <textarea name="unlock_reason" class="form-control" rows="2" required placeholder="Powód cofnięcia…"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-warning">Cofnij decyzję</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- PIN jako wyskakujące okno — jeden modal współdzielony przez wszystkie etapy tej strony.
     Otwiera się tylko dla decyzji "Tak/OK" (jedyna, którą uchwała nazywa "akceptacją"),
     "Z uwagami"/"Odrzuć" wysyłają się od razu bez PIN-u — patrz edokStepSubmit(). -->
<div class="modal fade" id="pinModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="bi bi-shield-lock"></i> Potwierdź PIN-em</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-2">Akceptacja ("Tak/OK") etapu wymaga weryfikacji tożsamości PIN-em EODoK.</p>
        <input type="password" id="pinModalInput" class="form-control text-center font-monospace" style="letter-spacing:.4em;font-size:1.2rem"
               inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="off" placeholder="••••••">
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-primary btn-sm" id="pinModalConfirm"><i class="bi bi-check2"></i> Potwierdź</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var modalEl = document.getElementById('pinModal');
  var input   = document.getElementById('pinModalInput');
  var confirmBtn = document.getElementById('pinModalConfirm');
  var modal   = modalEl && window.bootstrap ? new bootstrap.Modal(modalEl) : null;
  var pendingForm = null;

  function confirmPin() {
    var pin = input.value.trim();
    if (!/^\d{6}$/.test(pin)) { input.classList.add('is-invalid'); input.focus(); return; }
    input.classList.remove('is-invalid');
    if (pendingForm) {
      pendingForm.querySelector('.step-pin-field').value = pin;
      var f = pendingForm; pendingForm = null;
      if (modal) modal.hide();
      f.submit();
    }
  }
  if (confirmBtn) confirmBtn.addEventListener('click', confirmPin);
  if (input) input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); confirmPin(); } });
  if (modalEl) modalEl.addEventListener('shown.bs.modal', function () { input.value = ''; input.classList.remove('is-invalid'); input.focus(); });

  window.edokStepSubmit = function (event, form) {
    var status = (form.querySelector('input[name="step_status"]:checked') || {}).value;
    if (status !== 'ok') return true; // "Z uwagami"/"Odrzuć" — bez PIN, wysyła się normalnie
    event.preventDefault();
    if (!modal) { form.submit(); return false; } // Bootstrap niedostępny — degradacja do zwykłego submitu
    pendingForm = form;
    modal.show();
    return false;
  };
})();

function edokViewRecalc() {
  var n = document.getElementById('e_netto'), v = document.getElementById('e_vat'), b = document.getElementById('e_brutto');
  if (!n || !v || !b) return;
  var netto = parseFloat((n.value || '0').replace(',', '.').replace(/\s/g, '')) || 0;
  var vat   = parseFloat((v.value || '0').replace(',', '.').replace(/\s/g, '')) || 0;
  if (netto + vat > 0) b.value = (netto + vat).toFixed(2).replace('.', ',');
}

// Ten sam wzorzec co edok_generate_tytul_przelewu() w PHP (Uchwała 5/2026 §2 pkt 8-9) —
// numer EODoK i typ dokumentu są tu stałe (nieedytowalne w tym formularzu), numer
// faktury i opis brane z pól edycji (opis skracany do 60 znaków tak jak w PHP).
var EDOK_NUMBER_VIEW       = <?= json_encode($doc['number'], JSON_UNESCAPED_UNICODE) ?>;
var EDOK_TYP_KEY_VIEW      = <?= json_encode($doc['typ_dokumentu'], JSON_UNESCAPED_UNICODE) ?>;
var EDOK_TYP_LABEL_VIEW    = <?= json_encode(EDOK_TYPES[$doc['typ_dokumentu']] ?? $doc['typ_dokumentu'], JSON_UNESCAPED_UNICODE) ?>;
var EDOK_FAKTURA_TYPES_VIEW = ['faktura_vat', 'faktura_korygujaca'];
function edokViewSuggestTytul() {
  var nrField = document.querySelector('#metaForm [name="nr_faktury"]');
  var opisField = document.querySelector('#metaForm [name="description"]');
  var nr = nrField ? nrField.value.trim() : '';
  var opis = (opisField ? opisField.value : '').trim().replace(/\s+/g, ' ');
  if (opis.length > 60) opis = opis.substring(0, 60) + '...';
  if (!opis) opis = EDOK_TYP_LABEL_VIEW;
  var jestFaktura = EDOK_FAKTURA_TYPES_VIEW.indexOf(EDOK_TYP_KEY_VIEW) !== -1 && nr !== '';
  var ident = jestFaktura ? ('FAK: ' + nr) : ('DOK: ' + (EDOK_TYP_LABEL_VIEW + (nr ? ' ' + nr : '')).trim());
  var t = 'PŁATNOŚĆ: ' + opis + (EDOK_NUMBER_VIEW ? (' - AKC: ' + EDOK_NUMBER_VIEW) : '') + ' - ' + ident;
  document.getElementById('e_tytul').value = t.trim().substring(0, 140);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
