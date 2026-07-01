<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';
require_once __DIR__ . '/../includes/kdok_ksef.php';

kdok_require_role('upload');
kdok_migrate();
kdok_ksef_migrate();

$ksef_enabled = org_setting('kdok_ksef_enabled') === '1';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Wykryj przekroczenie post_max_size — PHP wyrzuca wtedy cały $_POST,
    // więc CSRF token znika. Sprawdzamy to PRZED csrf_check().
    $content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $post_max       = _kdok_parse_size(ini_get('post_max_size'));
    if ($content_length > $post_max && $post_max > 0) {
        $errors[] = sprintf(
            'Plik jest za duży dla serwera (%.1f MB). Limit PHP post_max_size wynosi %s. '
            . 'Skontaktuj się z administratorem lub skompresuj plik.',
            $content_length / 1024 / 1024,
            ini_get('post_max_size')
        );
        goto render;
    }

    csrf_check();

    $type        = $_POST['type']        ?? '';
    $title       = trim($_POST['title']  ?? '');
    $description = trim($_POST['description'] ?? '');
    $uwagi       = trim($_POST['uwagi']       ?? '');
    $kwota       = trim($_POST['kwota']       ?? '');
    $grant_name  = trim($_POST['grant_name']  ?? '');
    $mpk         = trim($_POST['mpk']         ?? '');

    $ksef_ref = trim($_POST['ksef_reference'] ?? '');
    $is_ksef  = ($type === 'ksef');

    // Opcjonalne powiązanie z umową (tylko dla typu "rachunek")
    $contract_type = trim($_POST['contract_type'] ?? '');
    $contract_id   = (int)($_POST['contract_id'] ?? 0);
    if ($type !== 'rachunek' || !$contract_type || !$contract_id) {
        $contract_type = null; $contract_id = null;
    } elseif (!kdok_contract_label($contract_type, $contract_id)) {
        $errors[] = 'Wybrana umowa nie istnieje — wyszukaj ją ponownie.';
        $contract_type = null; $contract_id = null;
    }

    if (!isset(KDOK_TYPES[$type]))  $errors[] = 'Wybierz typ dokumentu.';
    if ($title === '')              $errors[] = 'Tytuł jest wymagany.';
    if ($is_ksef && $ksef_ref === '') $errors[] = 'Podaj numer referencyjny KSeF.';
    if (!$is_ksef && empty($_FILES['file']['tmp_name'])) $errors[] = 'Plik PDF jest wymagany.';

    $file_path = null; $file_sha256 = null; $file_size = null;

    if (!$errors) {
        $dir = UPLOAD_DIR . 'kdok_docs/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        if ($is_ksef) {
            // Pobierz wizualizację z KSeF automatycznie
            $nip = org_setting('kdok_ksef_nip');
            if (!$nip) {
                $errors[] = 'Brak konfiguracji KSeF (NIP). Skonfiguruj w panelu admina.';
            } else {
                try {
                    $auth = kdok_ksef_authenticate_auto($nip);
                    if (!$auth['ok']) throw new RuntimeException($auth['error'] ?? 'Błąd autoryzacji');
                    $jwt = $auth['token'];
                    $vis = kdok_ksef_get_visualisation($jwt, $ksef_ref);
                    kdok_ksef_session_terminate($jwt);

                    $ext  = $vis['ext'];
                    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    $dest = $dir . $name;
                    if (file_put_contents($dest, $vis['content']) === false) {
                        throw new RuntimeException('Nie można zapisać pliku wizualizacji.');
                    }
                    $file_path   = 'kdok_docs/' . $name;
                    $file_sha256 = hash_file('sha256', $dest);
                    $file_size   = filesize($dest);
                } catch (\Throwable $e) {
                    if (!empty($jwt)) { try { kdok_ksef_session_terminate($jwt); } catch (\Throwable $_) {} }
                    $errors[] = 'Błąd pobierania z KSeF: ' . $e->getMessage();
                }
            }
        } else {
            // Normalny upload pliku
            $f = $_FILES['file'];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Błąd uploadu pliku (kod: ' . $f['error'] . ').';
            } else {
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if ($ext !== 'pdf') {
                    $errors[] = 'Dozwolony jest tylko format PDF.';
                } elseif ($f['size'] > 30 * 1024 * 1024) {
                    $errors[] = 'Plik nie może przekraczać 30 MB.';
                } else {
                    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.pdf';
                    $dest = $dir . $name;
                    if (!move_uploaded_file($f['tmp_name'], $dest)) {
                        $errors[] = 'Nie można zapisać pliku.';
                    } else {
                        $file_path   = 'kdok_docs/' . $name;
                        $file_sha256 = hash_file('sha256', $dest);
                        $file_size   = filesize($dest);
                    }
                }
            }
        }
    }

    // Dokument źródłowy może być dodany do obiegu tylko raz — sprawdź po sumie SHA-256
    if (!$errors && $file_sha256) {
        $dup = kdok_one("SELECT id, number FROM kdok_documents WHERE file_sha256 = ?", [$file_sha256]);
        if ($dup) {
            $errors[] = 'Ten dokument źródłowy jest już w obiegu jako ' . $dup['number'] . ' — nie można dodać go ponownie.';
            if (isset($dest) && is_file($dest)) @unlink($dest);
        }
    }

    if (!$errors) {
        $number = kdok_next_number();
        $cu = current_user();
        $doc_id = kdok_insert('kdok_documents', [
            'number'       => $number,
            'type'         => $type,
            'title'        => $title,
            'description'  => $description,
            'uwagi'        => $uwagi,
            'kwota'        => $kwota,
            'grant_name'   => $grant_name,
            'mpk'          => $mpk,
            'creator_name' => $cu['name'] ?? '',
            'file_path'    => $file_path,
            'file_sha256'  => $file_sha256,
            'file_size'    => $file_size,
            'status'       => 'w_obiegu',
            'created_by'   => $cu['id'],
            'miesiac'      => (int)date('n'),
            'rok'          => (int)date('Y'),
            'contract_type'=> $contract_type,
            'contract_id'  => $contract_id,
        ]);

        foreach (array_keys(KDOK_STEPS) as $step) {
            kdok_insert('kdok_steps', ['doc_id' => $doc_id, 'step_type' => $step]);
        }

        kdok_log($doc_id, 'Dokument dodany do obiegu', 'SHA-256: ' . $file_sha256);

        flash_set('success', 'Dokument ' . $number . ' dodany do obiegu.');
        header('Location: ' . APP_URL . '/ksiegowosc/view.php?id=' . $doc_id);
        exit;
    }
}

function _kdok_parse_size(string $s): int {
    $s = trim($s);
    if ($s === '') return 0;
    $unit = strtolower($s[strlen($s) - 1]);
    $val  = (int) $s;
    return match($unit) {
        'g' => $val * 1024 * 1024 * 1024,
        'm' => $val * 1024 * 1024,
        'k' => $val * 1024,
        default => $val,
    };
}

render:
$PAGE_TITLE = 'Dodaj dokument księgowy';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/ksiegowosc/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0">Dodaj dokument księgowy</h4>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:680px">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data" id="add-form">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="ksef_reference" id="ksef-reference-hidden" value="<?= h($_POST['ksef_reference'] ?? '') ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold">Typ dokumentu <span class="text-danger">*</span></label>
        <div class="d-flex gap-3 flex-wrap">
          <?php foreach (KDOK_TYPES as $k => $t): ?>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="type" id="type_<?= $k ?>" value="<?= $k ?>"
              <?= ($_POST['type'] ?? '') === $k ? 'checked' : '' ?> required>
            <label class="form-check-label" for="type_<?= $k ?>">
              <i class="<?= $t['icon'] ?>"></i> <?= h($t['label']) ?>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="mb-3">
        <label for="title" class="form-label fw-semibold">Tytuł / opis skrócony <span class="text-danger">*</span></label>
        <input type="text" id="title" name="title" class="form-control"
          value="<?= h($_POST['title'] ?? '') ?>" required maxlength="255"
          placeholder="np. Faktura FV 01/2026 – Dostawca XYZ">
      </div>

      <div class="mb-3">
        <label for="description" class="form-label fw-semibold">Opis merytoryczny</label>
        <textarea id="description" name="description" class="form-control" rows="4"
          placeholder="Cel wydatku, powiązanie z projektem/działaniem, uzasadnienie merytoryczne…"><?= h($_POST['description'] ?? '') ?></textarea>
        <div class="form-text">Pojawi się w historii obiegu i na wygenerowanym PDF.</div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label for="kwota" class="form-label fw-semibold">Kwota (PLN)</label>
          <input type="text" id="kwota" name="kwota" class="form-control"
            value="<?= h($_POST['kwota'] ?? '') ?>" maxlength="50"
            placeholder="np. 1 234,56">
        </div>
        <div class="col-sm-6">
          <label for="uwagi" class="form-label fw-semibold">Uwagi</label>
          <input type="text" id="uwagi" name="uwagi" class="form-control"
            value="<?= h($_POST['uwagi'] ?? '') ?>" maxlength="500"
            placeholder="Dodatkowe uwagi do dokumentu…">
        </div>
      </div>

      <div class="mb-3">
        <label for="grant_name" class="form-label fw-semibold">
          <i class="bi bi-award"></i> Finansowanie z grantu
          <span class="text-muted fw-normal small">(opcjonalnie)</span>
        </label>
        <input type="text" id="grant_name" name="grant_name" class="form-control"
          value="<?= h($_POST['grant_name'] ?? '') ?>" maxlength="255"
          placeholder="Nazwa grantu / projektu, z którego finansowany jest wydatek">
      </div>

      <?php if (kdok_mpk_enabled()): ?>
      <div class="mb-3">
        <label for="mpk" class="form-label fw-semibold">
          <i class="bi bi-diagram-3"></i> MPK / Centrum kosztów
        </label>
        <?php $mpk_list = kdok_mpk_list(); ?>
        <?php if ($mpk_list): ?>
        <select id="mpk" name="mpk" class="form-select">
          <option value="">— wybierz —</option>
          <?php foreach ($mpk_list as $m): ?>
          <option value="<?= h($m) ?>" <?= ($_POST['mpk'] ?? '') === $m ? 'selected' : '' ?>><?= h($m) ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" id="mpk" name="mpk" class="form-control"
          value="<?= h($_POST['mpk'] ?? '') ?>" maxlength="100"
          placeholder="Kod lub nazwa centrum kosztów">
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Sekcja umowy (widoczna tylko dla typu "rachunek") -->
      <?php $picked_contract = ($_POST['contract_type'] ?? '') && ($_POST['contract_id'] ?? '')
          ? kdok_contract_label($_POST['contract_type'], (int)$_POST['contract_id']) : null; ?>
      <div class="mb-3" id="contract-section" style="display:none">
        <label for="contract-search" class="form-label fw-semibold">
          <i class="bi bi-person-vcard text-primary"></i>
          Umowa <span class="text-muted fw-normal small">(opcjonalnie)</span>
        </label>
        <input type="text" id="contract-search" class="form-control" autocomplete="off"
               value="<?= h($picked_contract['label'] ?? '') ?>"
               placeholder="Szukaj po numerze umowy lub nazwisku…">
        <input type="hidden" id="contract-type" name="contract_type" value="<?= h($picked_contract ? $_POST['contract_type'] : '') ?>">
        <input type="hidden" id="contract-id"   name="contract_id"   value="<?= h($picked_contract ? $_POST['contract_id']   : '') ?>">
        <div id="contract-results" class="list-group mt-1" style="display:none;position:absolute;z-index:20;max-width:600px"></div>
        <div id="contract-picked" class="form-text mt-1"><?= $picked_contract ? 'Wybrano: ' . h($picked_contract['label']) : '' ?></div>
      </div>

      <!-- Sekcja KSeF (widoczna tylko dla typu ksef) -->
      <div class="mb-3" id="ksef-section" style="display:none">
        <label class="form-label fw-semibold">
          <i class="bi bi-receipt-cutoff text-primary"></i>
          Numer referencyjny KSeF <span class="text-danger">*</span>
        </label>
        <div class="d-flex gap-2">
          <input type="text" id="ksef-ref-input" class="form-control font-monospace"
                 placeholder="np. 1234560000-20260604-ABC123DEF456-AA"
                 autocomplete="off" value="<?= h($_POST['ksef_reference'] ?? '') ?>">
          <?php if ($ksef_enabled): ?>
          <button type="button" id="ksef-fetch-btn" class="btn btn-primary text-nowrap">
            <i class="bi bi-cloud-download"></i> Pobierz dane
          </button>
          <?php endif; ?>
        </div>
        <div id="ksef-result" class="mt-2" style="display:none"></div>
        <div class="form-text mt-1">
          <i class="bi bi-info-circle text-primary"></i>
          Wizualizacja faktury zostanie pobrana automatycznie z KSeF — nie trzeba uploadować pliku.
        </div>
      </div>

      <!-- Sekcja upload pliku (widoczna dla typów innych niż ksef) -->
      <div class="mb-4" id="file-section">
        <label for="file" class="form-label fw-semibold">Plik PDF <span class="text-danger">*</span></label>
        <input type="file" id="file" name="file" class="form-control" accept=".pdf">
        <div class="form-text">Maks. 30 MB. Wyłącznie PDF. Suma SHA-256 zostanie wyliczona automatycznie.</div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Dodaj do obiegu</button>
        <a href="<?= APP_URL ?>/ksiegowosc/index.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var ksefSection     = document.getElementById('ksef-section');
  var fileSection     = document.getElementById('file-section');
  var contractSection = document.getElementById('contract-section');
  var fileInput       = document.getElementById('file');
  var radios          = document.querySelectorAll('input[name="type"]');

  function toggleSections() {
    var selected   = document.querySelector('input[name="type"]:checked');
    var isKsef     = selected && selected.value === 'ksef';
    var isRachunek = selected && selected.value === 'rachunek';
    if (ksefSection)     ksefSection.style.display = isKsef ? '' : 'none';
    if (fileSection)     fileSection.style.display = isKsef ? 'none' : '';
    if (contractSection) contractSection.style.display = isRachunek ? '' : 'none';
    // required tylko na aktywnym polu
    if (fileInput) fileInput.required = !isKsef;
  }

  radios.forEach(function(r) { r.addEventListener('change', toggleSections); });
  toggleSections(); // ustaw stan przy ładowaniu strony

  // ── Wyszukiwanie umowy (dla typu "rachunek") ────────────────────────────────
  (function () {
    var search  = document.getElementById('contract-search');
    var typeF   = document.getElementById('contract-type');
    var idF     = document.getElementById('contract-id');
    var results = document.getElementById('contract-results');
    var picked  = document.getElementById('contract-picked');
    if (!search) return;

    function showPicked(label) {
      picked.textContent = label ? ('Wybrano: ' + label) : '';
    }
    // Jeśli formularz wraca po błędzie walidacji z już wybraną umową
    if (idF.value) {
      search.placeholder = 'Zmień wybraną umowę…';
    }

    var timer = null;
    search.addEventListener('input', function () {
      typeF.value = ''; idF.value = ''; showPicked('');
      clearTimeout(timer);
      var q = search.value.trim();
      if (q.length < 2) { results.style.display = 'none'; return; }
      timer = setTimeout(function () {
        fetch('<?= APP_URL ?>/ksiegowosc/search_contract.php?q=' + encodeURIComponent(q))
          .then(function (r) { return r.json(); })
          .then(function (data) {
            results.innerHTML = '';
            if (!data.results || !data.results.length) {
              results.style.display = 'none';
              return;
            }
            data.results.forEach(function (item) {
              var a = document.createElement('button');
              a.type = 'button';
              a.className = 'list-group-item list-group-item-action py-1 small';
              a.textContent = item.label;
              a.addEventListener('click', function () {
                typeF.value = item.type;
                idF.value   = item.id;
                search.value = item.label;
                showPicked(item.label);
                results.style.display = 'none';
              });
              results.appendChild(a);
            });
            results.style.display = '';
          })
          .catch(function () { results.style.display = 'none'; });
      }, 250);
    });

    document.addEventListener('click', function (e) {
      if (e.target !== search && !results.contains(e.target)) results.style.display = 'none';
    });
  })();

  <?php if ($ksef_enabled): ?>
  // ── Pobieranie danych z KSeF ────────────────────────────────────────────────
  var btn    = document.getElementById('ksef-fetch-btn');
  var input  = document.getElementById('ksef-ref-input');
  var result = document.getElementById('ksef-result');
  var hidden = document.getElementById('ksef-reference-hidden');

  function setResult(html) { result.innerHTML = html; result.style.display = ''; }
  function fillField(id, v) { var el = document.getElementById(id); if (el && v) el.value = v; }
  function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  if (btn) {
    btn.addEventListener('click', function () {
      var ref = input.value.trim();
      if (!ref) { input.focus(); input.classList.add('is-invalid'); return; }
      input.classList.remove('is-invalid');

      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Pobieranie…';
      result.style.display = 'none';

      var fd = new FormData();
      fd.append('_csrf', '<?= csrf_token() ?>');
      fd.append('ksef_reference', ref);

      fetch('<?= APP_URL ?>/ksiegowosc/ksef_fetch.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(json) {
          btn.disabled = false;
          btn.innerHTML = '<i class="bi bi-cloud-download"></i> Pobierz dane';

          if (!json.ok) {
            setResult('<div class="alert alert-danger py-2 mb-0 small"><i class="bi bi-x-circle me-1"></i>' + esc(json.error) + '</div>');
            return;
          }
          var d = json.data;
          fillField('title',       d.title);
          fillField('kwota',       d.kwota);
          fillField('description', d.description);
          if (hidden) hidden.value = d.ksef_reference;

          var info = '';
          if (d.invoice_number) info += '<strong>' + esc(d.invoice_number) + '</strong>';
          if (d.seller_name)    info += (info ? ' &mdash; ' : '') + esc(d.seller_name);
          if (d.seller_nip)     info += ' <span class="text-muted">NIP: ' + esc(d.seller_nip) + '</span>';
          if (d.kwota)          info += ' · ' + esc(d.kwota) + ' ' + esc(d.currency || 'PLN');
          if (d.issue_date)     info += ' · ' + esc(d.issue_date);

          setResult('<div class="alert alert-success py-2 mb-0 small">'
            + '<i class="bi bi-check-circle-fill text-success me-1"></i>'
            + 'Dane pobrane. Wizualizacja zostanie pobrana z KSeF przy zapisie. ' + info
            + '</div>');
        })
        .catch(function(err) {
          btn.disabled = false;
          btn.innerHTML = '<i class="bi bi-cloud-download"></i> Pobierz dane';
          setResult('<div class="alert alert-danger py-2 mb-0 small"><i class="bi bi-x-circle me-1"></i>Błąd sieci: ' + esc(err.message) + '</div>');
        });
    });

    input.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); btn.click(); }
    });
  }
  <?php endif; ?>
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
