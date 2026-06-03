<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

require_login();
kdok_migrate();

// ── Autoryzacja PIN archiwum ───────────────────────────────────────────────────

$pin_hash = org_setting('kdok_przeglad_pin_hash');
$pin_set  = $pin_hash !== '';

// Jeśli brak ustawionego PIN-u — tylko admin ma dostęp
if (!$pin_set) {
    if (!is_admin()) {
        $PAGE_TITLE = 'Archiwum — EOD Dokumentów Księgowych';
        require_once __DIR__ . '/../includes/header.php';
        ?>
        <div class="container py-5 text-center">
          <div class="alert alert-warning d-inline-block text-start" style="max-width:520px">
            <h5 class="mb-2"><i class="bi bi-lock-fill text-warning"></i> Dostęp ograniczony</h5>
            <p class="mb-0">Archiwum dokumentów księgowych nie jest jeszcze skonfigurowane.<br>
            Administrator musi ustawić PIN archiwum w <strong>Admin → EOD Dokumentów Księgowych → Ustawienia</strong>.</p>
          </div>
        </div>
        <?php
        require_once __DIR__ . '/../includes/footer.php';
        exit;
    }
    // Admin — dostęp bez PIN-u
} else {
    // PIN jest ustawiony — weryfikacja sesji
    auth_start();
    if (empty($_SESSION['kdok_przeglad_ok'])) {
        // Obsługa POSTa z PINem
        $pin_error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_pin'])) {
            csrf_check();
            $entered = $_POST['archive_pin'] ?? '';
            if ($entered !== '' && password_verify($entered, $pin_hash)) {
                $_SESSION['kdok_przeglad_ok'] = true;
                header('Location: ' . APP_URL . '/ksiegowosc/przegladaj.php' . (isset($_GET['id']) ? '?id=' . (int)$_GET['id'] : ''));
                exit;
            } else {
                $pin_error = 'Nieprawidłowy PIN. Spróbuj ponownie.';
            }
        }

        // Formularz PIN
        $PAGE_TITLE = 'Archiwum — EOD Dokumentów Księgowych';
        require_once __DIR__ . '/../includes/header.php';
        ?>
        <div class="d-flex justify-content-center align-items-center" style="min-height:60vh">
          <div class="card shadow" style="max-width:380px;width:100%">
            <div class="card-header bg-dark text-white text-center py-3">
              <i class="bi bi-archive-fill me-2"></i>
              <strong>Archiwum Dokumentów Księgowych</strong>
            </div>
            <div class="card-body p-4">
              <p class="text-muted small mb-3 text-center">
                Dostęp do archiwum jest chroniony dodatkowym kodem PIN.<br>
                Wprowadź PIN archiwalny, aby kontynuować.
              </p>
              <?php if ($pin_error): ?>
              <div class="alert alert-danger py-2 small"><i class="bi bi-x-circle"></i> <?= h($pin_error) ?></div>
              <?php endif; ?>
              <form method="post" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <div class="mb-3">
                  <label class="form-label fw-semibold">
                    <i class="bi bi-key-fill text-warning"></i> PIN archiwum
                  </label>
                  <input type="password" name="archive_pin" class="form-control form-control-lg text-center"
                    placeholder="••••••••" autofocus autocomplete="off" maxlength="64">
                </div>
                <button type="submit" class="btn btn-dark w-100">
                  <i class="bi bi-unlock-fill"></i> Odblokuj archiwum
                </button>
              </form>
            </div>
          </div>
        </div>
        <?php
        require_once __DIR__ . '/../includes/footer.php';
        exit;
    }
}

// ── Parametry filtrów ─────────────────────────────────────────────────────────

$current_year = (int)date('Y');
$filter_rok    = (int)($_GET['rok']    ?? $current_year);
$filter_miesiac = (int)($_GET['miesiac'] ?? 0);
$filter_status  = $_GET['status']  ?? '';
$filter_sha     = trim($_GET['sha'] ?? '');

// Walidacja roku
if ($filter_rok < $current_year - 5 || $filter_rok > $current_year) {
    $filter_rok = $current_year;
}

// ── Budowanie zapytania ───────────────────────────────────────────────────────

$where  = ['1=1'];
$params = [];

// Rok zawsze filtrujemy
$where[]  = '(rok = ? OR (rok IS NULL AND CAST(SUBSTR(created_at,1,4) AS INTEGER) = ?))';
$params[] = $filter_rok;
$params[] = $filter_rok;

if ($filter_miesiac >= 1 && $filter_miesiac <= 12) {
    $where[]  = '(miesiac = ? OR (miesiac IS NULL AND CAST(SUBSTR(created_at,6,2) AS INTEGER) = ?))';
    $params[] = $filter_miesiac;
    $params[] = $filter_miesiac;
}

if ($filter_status) {
    $where[]  = 'status = ?';
    $params[] = $filter_status;
} else {
    // Domyślnie archiwum pokazuje tylko zaakceptowane i odrzucone
    $where[]  = "status IN ('zaakceptowany', 'odrzucony')";
}

// Wyszukiwanie SHA-256 (pełne lub częściowe)
$sha_verified      = false;
$sha_verified_doc  = null;
$is_full_sha       = strlen($filter_sha) === 64 && ctype_xdigit($filter_sha);

if ($filter_sha !== '') {
    $where[]  = 'file_sha256 LIKE ?';
    $params[] = '%' . $filter_sha . '%';

    // Weryfikacja pełnego skrótu
    if ($is_full_sha) {
        $sha_verified_doc = kdok_one(
            "SELECT id, number, title, file_sha256 FROM kdok_documents WHERE file_sha256 = ?",
            [$filter_sha]
        );
        $sha_verified = ($sha_verified_doc !== null);
    }
}

$where_sql = implode(' AND ', $where);

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

$total = (int)(kdok_one("SELECT COUNT(*) AS c FROM kdok_documents WHERE $where_sql", $params)['c'] ?? 0);
$pag   = paginate($total, $per_page, $page, '?');

$docs = kdok_all(
    "SELECT * FROM kdok_documents WHERE $where_sql ORDER BY id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$per_page, $pag['offset']])
);

// Pobierz kroki dla każdego dokumentu
foreach ($docs as &$doc) {
    $steps = kdok_all(
        "SELECT step_type, status FROM kdok_steps WHERE doc_id = ?",
        [$doc['id']]
    );
    $doc['steps'] = array_column($steps, 'status', 'step_type');
    $doc['generated'] = kdok_one(
        "SELECT id FROM kdok_generated_pdf WHERE doc_id = ? ORDER BY id DESC LIMIT 1",
        [$doc['id']]
    );
}
unset($doc);

// Miesiące PL
$months_pl = ['', 'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec',
              'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'];

$PAGE_TITLE = 'Archiwum — EOD Dokumentów Księgowych';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0">
    <i class="bi bi-archive-fill text-secondary"></i>
    Archiwum — EOD Dokumentów Księgowych
  </h4>
  <div class="d-flex gap-2 align-items-center">
    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
      <i class="bi bi-lock-fill"></i> Tryb tylko do odczytu
    </span>
    <a href="<?= APP_URL ?>/ksiegowosc/index.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left"></i> Powrót do EOD
    </a>
  </div>
</div>

<?= flash_html() ?>

<!-- SHA-256 wynik weryfikacji -->
<?php if ($filter_sha !== '' && $is_full_sha): ?>
<div class="alert <?= $sha_verified ? 'alert-success' : 'alert-danger' ?> d-flex align-items-center gap-2 py-2 mb-3">
  <?php if ($sha_verified): ?>
    <i class="bi bi-patch-check-fill fs-5"></i>
    <div>
      <strong>VERIFIED</strong> — Skrót SHA-256 potwierdzony.
      Dokument: <strong><?= h($sha_verified_doc['number']) ?></strong> — <?= h($sha_verified_doc['title']) ?>
    </div>
  <?php else: ?>
    <i class="bi bi-patch-x-fill fs-5"></i>
    <div>
      <strong>BRAK DOPASOWANIA</strong> — Nie znaleziono dokumentu o podanym skrócie SHA-256.
    </div>
  <?php endif; ?>
</div>
<?php elseif ($filter_sha !== '' && !$is_full_sha): ?>
<div class="alert alert-info py-2 mb-3 small">
  <i class="bi bi-info-circle"></i>
  Wyszukiwanie częściowe. Podaj pełny 64-znakowy skrót SHA-256, aby uzyskać wynik weryfikacji.
</div>
<?php endif; ?>

<!-- Filtry -->
<form method="get" class="row g-2 mb-3">
  <!-- Rok -->
  <div class="col-sm-2">
    <select name="rok" class="form-select form-select-sm">
      <?php for ($yr = $current_year; $yr >= $current_year - 5; $yr--): ?>
      <option value="<?= $yr ?>" <?= $filter_rok === $yr ? 'selected' : '' ?>><?= $yr ?></option>
      <?php endfor; ?>
    </select>
  </div>

  <!-- Miesiąc -->
  <div class="col-sm-2">
    <select name="miesiac" class="form-select form-select-sm">
      <option value="">Wszystkie miesiące</option>
      <?php for ($m = 1; $m <= 12; $m++): ?>
      <option value="<?= $m ?>" <?= $filter_miesiac === $m ? 'selected' : '' ?>><?= $months_pl[$m] ?></option>
      <?php endfor; ?>
    </select>
  </div>

  <!-- Status -->
  <div class="col-sm-2">
    <select name="status" class="form-select form-select-sm">
      <option value="">Zaakceptowane i odrzucone</option>
      <option value="zaakceptowany" <?= $filter_status === 'zaakceptowany' ? 'selected' : '' ?>>Zaakceptowany</option>
      <option value="odrzucony"     <?= $filter_status === 'odrzucony'     ? 'selected' : '' ?>>Odrzucony</option>
    </select>
  </div>

  <!-- SHA-256 -->
  <div class="col-sm-4">
    <div class="input-group input-group-sm">
      <span class="input-group-text" title="Wyszukiwanie po skrócie SHA-256">
        <i class="bi bi-fingerprint"></i>
      </span>
      <input type="text" name="sha" class="form-control form-control-sm font-monospace"
        placeholder="SHA-256 (pełny lub częściowy)…"
        value="<?= h($filter_sha) ?>" maxlength="64" spellcheck="false">
    </div>
  </div>

  <div class="col-auto d-flex gap-1">
    <button type="submit" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-search"></i> Filtruj
    </button>
    <a href="<?= APP_URL ?>/ksiegowosc/przegladaj.php" class="btn btn-outline-secondary btn-sm">
      Wyczyść
    </a>
  </div>
</form>

<!-- Wyniki -->
<div class="d-flex justify-content-between align-items-center mb-2">
  <small class="text-muted">
    Znaleziono: <strong><?= $total ?></strong>
    <?= $filter_rok ? ' · rok ' . $filter_rok : '' ?>
    <?= $filter_miesiac ? ' · ' . $months_pl[$filter_miesiac] : '' ?>
  </small>
</div>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle small" id="archiveTable">
      <thead class="table-light">
        <tr>
          <th style="width:140px">Numer</th>
          <th style="width:110px">Typ</th>
          <th>Tytuł</th>
          <th style="width:110px">Status</th>
          <th style="width:110px">Dodał</th>
          <th style="width:90px">Data</th>
          <th style="width:130px" title="SHA-256 (pierwsze 16 znaków)">SHA-256</th>
          <th class="text-center" style="width:80px" title="Etapy akceptacji: merytoryczny, formalny, zatwierdzenie">Etapy</th>
          <th style="width:90px"></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$docs): ?>
        <tr>
          <td colspan="9" class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-3 d-block mb-1"></i>
            Brak dokumentów spełniających kryteria.
          </td>
        </tr>
      <?php endif; ?>
      <?php foreach ($docs as $doc): ?>
      <?php
        $sha   = $doc['file_sha256'] ?? '';
        $sha16 = $sha ? substr($sha, 0, 16) : '—';
      ?>
        <tr class="archive-row" data-id="<?= $doc['id'] ?>" style="cursor:pointer" title="Kliknij, aby zobaczyć szczegóły">
          <td><code class="small"><?= h($doc['number']) ?></code></td>
          <td>
            <i class="<?= h(KDOK_TYPES[$doc['type']]['icon'] ?? 'bi-file') ?>"></i>
            <?= h(KDOK_TYPES[$doc['type']]['label'] ?? $doc['type']) ?>
          </td>
          <td><?= h($doc['title']) ?></td>
          <td><?= kdok_status_badge($doc['status']) ?></td>
          <td class="text-muted"><?= h($doc['creator_name']) ?></td>
          <td class="text-muted"><?= date_pl($doc['created_at']) ?></td>
          <td>
            <?php if ($sha): ?>
            <code class="small" title="<?= h($sha) ?>"><?= h($sha16) ?>…</code>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php
            $step_icons = [
                'meryt'      => ['title' => 'Merytoryczny', 'short' => 'M'],
                'formal'     => ['title' => 'Formalny',     'short' => 'F'],
                'zatwierdza' => ['title' => 'Zatwierdzenie','short' => 'Z'],
            ];
            foreach ($step_icons as $skey => $scfg):
                $sts = $doc['steps'][$skey] ?? 'oczekuje';
                if ($sts === 'ok'):
            ?>
              <span class="badge rounded-circle bg-success p-1" title="<?= $scfg['title'] ?>: Zatwierdzono"
                    style="width:18px;height:18px;font-size:.55rem;display:inline-flex;align-items:center;justify-content:center">
                <i class="bi bi-check-lg"></i>
              </span>
            <?php elseif ($sts === 'uwagi'): ?>
              <span class="badge rounded-circle bg-warning p-1" title="<?= $scfg['title'] ?>: Z uwagami"
                    style="width:18px;height:18px;font-size:.55rem;display:inline-flex;align-items:center;justify-content:center">
                <i class="bi bi-exclamation-lg text-dark"></i>
              </span>
            <?php elseif ($sts === 'odrzucono'): ?>
              <span class="badge rounded-circle bg-danger p-1" title="<?= $scfg['title'] ?>: Odrzucono"
                    style="width:18px;height:18px;font-size:.55rem;display:inline-flex;align-items:center;justify-content:center">
                <i class="bi bi-x-lg"></i>
              </span>
            <?php else: ?>
              <span class="badge rounded-circle bg-light border p-1" title="<?= $scfg['title'] ?>: Oczekuje"
                    style="width:18px;height:18px;font-size:.55rem;display:inline-flex;align-items:center;justify-content:center">
                <span class="text-muted"><?= $scfg['short'] ?></span>
              </span>
            <?php endif; endforeach; ?>
          </td>
          <td class="text-end">
            <button type="button" class="btn btn-sm btn-outline-secondary open-detail-modal"
              data-id="<?= $doc['id'] ?>"
              title="Szczegóły dokumentu">
              <i class="bi bi-eye"></i>
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Paginacja -->
<?php
$qs = http_build_query(array_filter([
    'rok'     => $filter_rok ?: '',
    'miesiac' => $filter_miesiac ?: '',
    'status'  => $filter_status,
    'sha'     => $filter_sha,
]));
echo pagination_html($pag, '?' . $qs . ($qs ? '&' : ''));
?>

<!-- ══ Modal szczegółów dokumentu ══════════════════════════════════════════════ -->
<div class="modal fade" id="docDetailModal" tabindex="-1" aria-labelledby="docDetailModalLabel">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white py-2">
        <h5 class="modal-title" id="docDetailModalLabel">
          <i class="bi bi-archive-fill me-2"></i>
          <span id="modalDocNumber">Ładowanie…</span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0" id="docDetailBody">
        <div class="text-center py-5">
          <div class="spinner-border text-secondary" role="status"></div>
          <div class="text-muted mt-2 small">Pobieranie danych…</div>
        </div>
      </div>
      <div class="modal-footer py-2 gap-2" id="docDetailFooter">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ JavaScript ══════════════════════════════════════════════════════════════ -->
<script>
(function () {
  'use strict';

  var modalEl     = document.getElementById('docDetailModal');
  var modalBody   = document.getElementById('docDetailBody');
  var modalFooter = document.getElementById('docDetailFooter');
  var modalNumber = document.getElementById('docDetailModalLabel');

  // Kliknięcie w wiersz tabeli
  document.getElementById('archiveTable').addEventListener('click', function (e) {
    var row = e.target.closest('tr.archive-row');
    if (!row) return;
    openModal(row.dataset.id);
  });

  // Przycisk oka w tabeli
  document.querySelectorAll('.open-detail-modal').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      openModal(this.dataset.id);
    });
  });

  function openModal(id) {
    // Reset
    modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-secondary" role="status"></div>'
      + '<div class="text-muted mt-2 small">Pobieranie danych…</div></div>';
    modalFooter.innerHTML = '<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>';
    document.getElementById('docDetailModalLabel').innerHTML = '<i class="bi bi-archive-fill me-2"></i>Ładowanie…';

    bootstrap.Modal.getOrCreateInstance(modalEl).show();

    fetch('<?= APP_URL ?>/ksiegowosc/przegladaj_ajax.php?id=' + encodeURIComponent(id), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.error) {
        modalBody.innerHTML = '<div class="alert alert-danger m-3">' + escHtml(data.error) + '</div>';
        return;
      }
      renderModal(data);
    })
    .catch(function (err) {
      modalBody.innerHTML = '<div class="alert alert-danger m-3">Błąd komunikacji z serwerem.</div>';
    });
  }

  function renderModal(d) {
    var doc     = d.doc;
    var steps   = d.steps;
    var history = d.history;
    var gen     = d.generated;

    document.getElementById('docDetailModalLabel').innerHTML =
      '<i class="bi bi-archive-fill me-2"></i>'
      + escHtml(doc.number) + ' <span class="fw-normal fs-6 opacity-75">' + escHtml(doc.title) + '</span>';

    // Buduj footer (download linki)
    var footer = '<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>';
    if (doc.has_orig) {
      footer += ' <a href="<?= APP_URL ?>/ksiegowosc/download.php?id=' + doc.id + '&type=orig" target="_blank"'
        + ' class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-pdf"></i> Pobierz oryginał PDF</a>';
    }
    if (gen) {
      footer += ' <a href="<?= APP_URL ?>/ksiegowosc/download.php?id=' + doc.id + '&type=final" target="_blank"'
        + ' class="btn btn-sm btn-success"><i class="bi bi-file-earmark-check"></i> Pobierz finalny PDF</a>';
    }
    modalFooter.innerHTML = footer;

    // Step labels
    var stepLabels = {
      meryt:      { label: 'Sprawdzono merytorycznie',        color: 'primary', icon: 'bi-patch-check' },
      formal:     { label: 'Sprawdzono formalnie i rachunkowo', color: 'info',    icon: 'bi-calculator' },
      zatwierdza: { label: 'Zatwierdzono do wypłaty',          color: 'success', icon: 'bi-cash-coin'  },
    };

    // Buduj treść
    var html = '<div class="row g-0">';

    // Lewa kolumna
    html += '<div class="col-lg-8 border-end">';

    // Karta info
    html += '<div class="p-3 border-bottom">';
    html += '<div class="d-flex justify-content-between align-items-start mb-2">';
    html += '<div><span class="badge bg-secondary me-1">' + escHtml(doc.type_label) + '</span>'
          + statusBadge(doc.status) + '</div>';
    html += '<div class="text-muted small">Dodano: ' + escHtml(doc.created_at_pl) + ' przez ' + escHtml(doc.creator_name) + '</div>';
    html += '</div>';

    // SHA-256 + pliki
    if (doc.file_sha256) {
      html += '<div class="mb-2 small">'
        + '<strong>SHA-256:</strong> <code style="word-break:break-all;font-size:.72rem">' + escHtml(doc.file_sha256) + '</code>'
        + '</div>';
    }
    if (doc.file_size) {
      html += '<div class="small text-muted mb-2">'
        + '<i class="bi bi-file-earmark-pdf text-danger"></i> '
        + 'Plik oryginalny: ' + escHtml(doc.file_size_kb) + ' KB'
        + '</div>';
    }

    // Metadane finansowe
    if (doc.kwota || doc.mpk || doc.grant_name) {
      html += '<div class="d-flex flex-wrap gap-3 small mb-2">';
      if (doc.kwota)      html += '<div><span class="text-muted">Kwota:</span> <strong>' + escHtml(doc.kwota) + ' PLN</strong></div>';
      if (doc.mpk)        html += '<div><span class="text-muted">MPK:</span> <strong>' + escHtml(doc.mpk) + '</strong></div>';
      if (doc.grant_name) html += '<div><i class="bi bi-award text-warning"></i> <span class="text-muted">Grant:</span> <strong>' + escHtml(doc.grant_name) + '</strong></div>';
      html += '</div>';
    }
    if (doc.description) {
      html += '<div class="small"><strong>Opis merytoryczny:</strong><br><span class="text-secondary">' + escHtml(doc.description) + '</span></div>';
    }
    if (doc.uwagi) {
      html += '<div class="small mt-1"><strong>Uwagi:</strong> <span class="text-secondary">' + escHtml(doc.uwagi) + '</span></div>';
    }
    html += '</div>'; // end karta info

    // Finalny PDF
    if (gen) {
      html += '<div class="p-3 border-bottom bg-success bg-opacity-10">'
        + '<div class="d-flex align-items-center gap-2 small">'
        + '<i class="bi bi-file-earmark-check-fill text-success fs-5"></i>'
        + '<div><strong class="text-success">Finalny PDF wygenerowany</strong><br>'
        + '<span class="text-muted">Przez: ' + escHtml(gen.gen_name) + ' · ' + escHtml(gen.generated_at_pl) + '</span><br>'
        + '<code style="font-size:.68rem;word-break:break-all">' + escHtml(gen.file_sha256) + '</code>'
        + ' <span class="text-muted">(' + escHtml(gen.file_size_kb) + ' KB)</span>'
        + '</div></div></div>';
    }

    // Kroki akceptacji
    html += '<div class="p-3">';
    html += '<h6 class="fw-semibold mb-3"><i class="bi bi-list-check"></i> Etapy akceptacji</h6>';

    var stepOrder = ['meryt', 'formal', 'zatwierdza'];
    stepOrder.forEach(function (skey) {
      var cfg  = stepLabels[skey];
      var step = steps[skey] || null;
      var decided = step && (step.status === 'ok' || step.status === 'uwagi' || step.status === 'odrzucono');

      var badgeHtml = '';
      if (decided) {
        if (step.status === 'ok')        badgeHtml = '<span class="badge bg-success"><i class="bi bi-check-lg"></i> Tak</span>';
        else if (step.status === 'odrzucono') badgeHtml = '<span class="badge bg-danger"><i class="bi bi-x-lg"></i> Odrzucono</span>';
        else                             badgeHtml = '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> Z uwagami</span>';
      } else {
        badgeHtml = '<span class="badge bg-secondary">Oczekuje</span>';
      }

      html += '<div class="border rounded mb-2 overflow-hidden">';
      html += '<div class="d-flex justify-content-between align-items-center px-3 py-2 bg-light">'
        + '<span><i class="bi ' + cfg.icon + ' text-' + cfg.color + ' me-1"></i><strong>' + escHtml(cfg.label) + '</strong></span>'
        + badgeHtml
        + '</div>';

      if (decided) {
        html += '<div class="px-3 py-2 small">';
        html += '<div><span class="text-muted">Przez:</span> <strong>'
          + escHtml(step.cert_cn || step.user_name || '—') + '</strong>';
        if (step.decided_at) {
          html += ' &nbsp;|&nbsp; ' + escHtml(step.decided_at_pl);
        }
        html += '</div>';
        if (step.cert_fingerprint) {
          html += '<div class="mt-1">'
            + '<span class="badge bg-secondary me-1"><i class="bi bi-patch-check"></i> X.509</span>'
            + '<code style="font-size:.66rem;word-break:break-all">' + escHtml(step.cert_fingerprint) + '</code>'
            + '</div>';
          if (step.cert_subject) {
            html += '<div class="text-muted mt-1" style="font-size:.7rem">' + escHtml(step.cert_subject) + '</div>';
          }
        }
        if (step.notes) {
          html += '<div class="mt-1 text-warning-emphasis"><strong>Uwagi:</strong> ' + escHtml(step.notes) + '</div>';
        }
        html += '</div>';
      } else {
        html += '<div class="px-3 py-2 text-muted small">Etap nie został jeszcze podjęty.</div>';
      }
      html += '</div>';
    });
    html += '</div>'; // end steps

    html += '</div>'; // end lewa kolumna

    // Prawa kolumna: historia
    html += '<div class="col-lg-4">';
    html += '<div class="p-3">';
    html += '<h6 class="fw-semibold mb-2"><i class="bi bi-clock-history"></i> Historia obiegu</h6>';
    html += '<ul class="list-group list-group-flush small" style="max-height:520px;overflow-y:auto">';

    if (!history || !history.length) {
      html += '<li class="list-group-item text-muted">Brak wpisów.</li>';
    } else {
      var hRev = history.slice().reverse();
      hRev.forEach(function (h) {
        html += '<li class="list-group-item py-2 px-0">'
          + '<div class="fw-semibold">' + escHtml(h.user_name) + '</div>'
          + '<div>' + escHtml(h.action) + '</div>'
          + (h.note ? '<div class="text-muted fst-italic">' + escHtml(h.note) + '</div>' : '')
          + '<div class="text-muted" style="font-size:.7rem">' + escHtml(h.created_at) + '</div>'
          + '</li>';
      });
    }

    html += '</ul>';
    html += '</div>';
    html += '</div>'; // end prawa kolumna

    html += '</div>'; // end row

    modalBody.innerHTML = html;
  }

  function statusBadge(status) {
    var map = {
      nowy:          ['secondary', 'Nowy'],
      w_obiegu:      ['warning',   'W obiegu'],
      zaakceptowany: ['success',   'Zaakceptowany'],
      odrzucony:     ['danger',    'Odrzucony'],
    };
    var s = map[status] || ['secondary', status];
    return '<span class="badge bg-' + s[0] + '">' + escHtml(s[1]) + '</span>';
  }

  function escHtml(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
