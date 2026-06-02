<?php
/**
 * Admin — Masowa wysyłka e-mail
 *
 * Tryby:
 *   GET               — formularz
 *   POST _action=preview   — JSON {count, sample}
 *   POST _action=send_test — wyślij testowo do aktualnego admina
 *   POST _action=send_all  — wyślij do wszystkich pasujących, uruchom kolejkę
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';

require_role('admin');

// ── Pomocnicy ─────────────────────────────────────────────────────────────────

/**
 * Buduje zapytanie SQL i parametry na podstawie filtrów z POST/GET.
 * Obsługuje wiele typów umów — każdy ma własną tabelę.
 */
function bulk_build_query(array $f): array {
    $types   = $f['types']   ?? array_keys(CONTRACT_TYPES);
    $types   = array_intersect($types, array_keys(CONTRACT_TYPES));
    if (empty($types)) $types = array_keys(CONTRACT_TYPES);

    $statuses   = $f['statuses']  ?? [];
    $opiekun    = trim($f['opiekun'] ?? '');
    $date_from  = $f['date_from']  ?? '';
    $date_to    = $f['date_to']    ?? '';

    $unions = [];
    $params = [];

    foreach ($types as $type) {
        $table = 'umowy_' . $type;

        // Kolumna imienia — umowy_uslugi używa nazwa_wykonawcy zamiast imie_nazwisko
        $name_col = ($type === 'uslugi') ? 'nazwa_wykonawcy' : 'imie_nazwisko';

        $where   = ["email IS NOT NULL", "email != ''"];
        $tparams = [];

        if (!empty($statuses)) {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $where[]  = "status IN ($placeholders)";
            $tparams  = array_merge($tparams, $statuses);
        }

        if ($opiekun !== '') {
            $where[]  = "opiekun = ?";
            $tparams[] = $opiekun;
        }

        if ($date_from !== '') {
            $where[]  = "(data_zakonczenia IS NULL OR data_zakonczenia >= ?)";
            $tparams[] = $date_from;
        }

        if ($date_to !== '') {
            $where[]  = "(data_rozpoczecia IS NULL OR data_rozpoczecia <= ?)";
            $tparams[] = $date_to;
        }

        $w_sql    = implode(' AND ', $where);
        $unions[] = "SELECT id, " . $table . ".email, {$name_col} AS imie_nazwisko,
                            numer_umowy, opiekun, '{$type}' AS contract_type
                     FROM {$table}
                     WHERE {$w_sql}";
        $params   = array_merge($params, $tparams);
    }

    $sql = implode(' UNION ALL ', $unions) . ' ORDER BY imie_nazwisko';
    return [$sql, $params];
}

function bulk_substitute(string $tpl, array $row): string {
    $parts  = explode(' ', trim($row['imie_nazwisko'] ?? ''), 2);
    $imie   = $parts[0] ?? '';
    $nazw   = $parts[1] ?? '';
    return str_replace(
        ['{imie}', '{nazwisko}', '{numer_umowy}', '{opiekun}'],
        [
            htmlspecialchars($imie,   ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($nazw,   ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($row['numer_umowy'] ?? '', ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($row['opiekun']     ?? '', ENT_QUOTES, 'UTF-8'),
        ],
        $tpl
    );
}

// ── Obsługa POST (AJAX + akcje) ───────────────────────────────────────────────

$action = $_POST['_action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['preview','send_test','send_all'])) {
    csrf_check();

    $filters = [
        'types'     => (array)($_POST['types']   ?? []),
        'statuses'  => (array)($_POST['statuses'] ?? []),
        'opiekun'   => $_POST['opiekun']   ?? '',
        'date_from' => $_POST['date_from'] ?? '',
        'date_to'   => $_POST['date_to']   ?? '',
    ];

    [$sql, $params] = bulk_build_query($filters);

    if ($action === 'preview') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $rows   = db_all($sql, $params);
            $count  = count($rows);
            $sample = $count > 0 ? ['email' => $rows[0]['email'], 'name' => $rows[0]['imie_nazwisko']] : null;
            echo json_encode(['count' => $count, 'sample' => $sample]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // send_test lub send_all
    $subject_tpl = trim($_POST['subject'] ?? '');
    $body_tpl    = trim($_POST['body']    ?? '');

    if ($subject_tpl === '' || $body_tpl === '') {
        flash_set('danger', 'Temat i treść wiadomości są wymagane.');
        header('Location: bulk_email.php');
        exit;
    }

    $me = current_user();

    if ($action === 'send_test') {
        $test_email = $me['email'] ?? '';
        if (!$test_email) {
            flash_set('danger', 'Nie można ustalić adresu e-mail zalogowanego użytkownika.');
            header('Location: bulk_email.php');
            exit;
        }
        // Pobierz jeden przykładowy rekord do podstawienia
        $rows     = db_all($sql . ' LIMIT 1', $params);
        $sample   = $rows[0] ?? ['imie_nazwisko' => $me['name'] ?? '', 'numer_umowy' => 'TEST-001', 'opiekun' => ''];
        $subj     = bulk_substitute($subject_tpl, $sample);
        $body     = bulk_substitute($body_tpl, $sample);
        mail_queue_add($test_email, $me['name'] ?? $test_email, '[TEST] ' . $subj, $body, '', 'bulk_test');
        mail_queue_process(1);
        flash_set('success', 'Wiadomość testowa została wysłana na ' . htmlspecialchars($test_email) . '.');
        header('Location: bulk_email.php');
        exit;
    }

    // send_all
    $rows   = db_all($sql, $params);
    $queued = 0;
    $skipped = 0;
    foreach ($rows as $row) {
        $email = trim($row['email'] ?? '');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $skipped++;
            continue;
        }
        $subj = bulk_substitute($subject_tpl, $row);
        $body = bulk_substitute($body_tpl,    $row);
        mail_queue_add($email, $row['imie_nazwisko'] ?? $email, $subj, $body, '', 'bulk_email');
        $queued++;
    }
    mail_queue_process(50);

    $msg = "Dodano do kolejki: <strong>{$queued}</strong> wiadomości.";
    if ($skipped) $msg .= " Pominięto (brak e-mail): {$skipped}.";
    flash_set('success', $msg);
    header('Location: bulk_email.php');
    exit;
}

// ── Dane do formularza ────────────────────────────────────────────────────────

// Distinct opiekunowie ze wszystkich tabel
$opiekunowie = [];
foreach (array_keys(CONTRACT_TYPES) as $type) {
    try {
        $rows = db_all("SELECT DISTINCT opiekun FROM umowy_{$type} WHERE opiekun IS NOT NULL AND opiekun != '' ORDER BY opiekun");
        foreach ($rows as $r) {
            $opiekunowie[$r['opiekun']] = $r['opiekun'];
        }
    } catch (\Throwable $e) {}
}
ksort($opiekunowie);

$status_labels = STATUS_LABELS;

$PAGE_TITLE = 'Masowa wysyłka e-mail';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-send-fill text-primary me-2"></i>Masowa wysyłka e-mail</h4>
    <div class="text-muted small mt-1">Wyślij spersonalizowaną wiadomość do wybranych osób z rejestru umów.</div>
  </div>
</div>

<?= flash_html() ?>

<form method="post" id="bulkForm" autocomplete="off">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="_action" id="formAction" value="">

  <div class="row g-3">

    <!-- ── Filtry odbiorców ───────────────────────────────────────────────── -->
    <div class="col-12 col-xl-5">
      <div class="card shadow-sm h-100">
        <div class="card-header fw-semibold" style="font-size:.88rem">
          <i class="bi bi-funnel text-primary me-1"></i>Filtry odbiorców
        </div>
        <div class="card-body pb-2">

          <!-- Typ umowy -->
          <div class="mb-3">
            <label class="form-label fw-semibold small mb-1">Typ umowy</label>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach (CONTRACT_TYPES as $slug => $label): ?>
              <div class="form-check form-check-inline mb-0">
                <input class="form-check-input filter-input" type="checkbox"
                       name="types[]" value="<?= h($slug) ?>"
                       id="type_<?= h($slug) ?>" checked>
                <label class="form-check-label small" for="type_<?= h($slug) ?>"><?= h($label) ?></label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Status -->
          <div class="mb-3">
            <label class="form-label fw-semibold small mb-1">Status umowy <span class="text-muted fw-normal">(brak = wszystkie)</span></label>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ($status_labels as $slug => $info): ?>
              <div class="form-check form-check-inline mb-0">
                <input class="form-check-input filter-input" type="checkbox"
                       name="statuses[]" value="<?= h($slug) ?>"
                       id="status_<?= h(str_replace(' ','_',$slug)) ?>">
                <label class="form-check-label small" for="status_<?= h(str_replace(' ','_',$slug)) ?>">
                  <span class="badge bg-<?= $info['class'] ?>" style="font-size:.7rem"><?= h($info['label']) ?></span>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Opiekun -->
          <div class="mb-3">
            <label class="form-label fw-semibold small mb-1" for="filterOpiekun">Opiekun</label>
            <select class="form-select form-select-sm filter-input" name="opiekun" id="filterOpiekun">
              <option value="">— wszyscy opiekunowie —</option>
              <?php foreach ($opiekunowie as $op): ?>
              <option value="<?= h($op) ?>"><?= h($op) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Zakres dat aktywności umowy -->
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label fw-semibold small mb-1" for="filterDateFrom">Aktywna od</label>
              <input type="date" class="form-control form-control-sm filter-input"
                     name="date_from" id="filterDateFrom">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold small mb-1" for="filterDateTo">Aktywna do</label>
              <input type="date" class="form-control form-control-sm filter-input"
                     name="date_to" id="filterDateTo">
            </div>
          </div>

        </div>
        <div class="card-footer bg-transparent py-2">
          <div class="d-flex align-items-center gap-2">
            <span class="text-muted small">Liczba odbiorców:</span>
            <strong id="recipientCount" class="text-primary fs-5">—</strong>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-1" id="btnRefresh">
              <i class="bi bi-arrow-clockwise"></i> Odśwież
            </button>
          </div>
          <div id="sampleInfo" class="text-muted" style="font-size:.75rem; margin-top:3px"></div>
        </div>
      </div>
    </div>

    <!-- ── Kompozytor wiadomości ──────────────────────────────────────────── -->
    <div class="col-12 col-xl-7">
      <div class="card shadow-sm h-100">
        <div class="card-header fw-semibold" style="font-size:.88rem">
          <i class="bi bi-envelope-paper text-primary me-1"></i>Treść wiadomości
        </div>
        <div class="card-body">

          <div class="mb-2">
            <label class="form-label fw-semibold small mb-1" for="emailSubject">Temat</label>
            <input type="text" class="form-control" name="subject" id="emailSubject"
                   placeholder="np. Ważna informacja dla wolontariuszy — {numer_umowy}" required>
          </div>

          <div class="mb-2">
            <label class="form-label fw-semibold small mb-1" for="emailBody">Treść (HTML)</label>
            <textarea class="form-control font-monospace" name="body" id="emailBody"
                      rows="10" placeholder="Treść wiadomości w HTML..." required
                      style="font-size:.82rem"></textarea>
            <div class="form-text mt-1">
              <i class="bi bi-info-circle me-1 text-primary"></i>
              Dostępne zmienne:
              <code>{imie}</code>, <code>{nazwisko}</code>,
              <code>{numer_umowy}</code>, <code>{opiekun}</code>
            </div>
          </div>

          <!-- Podgląd zmiennych -->
          <div id="previewBox" class="d-none">
            <div class="fw-semibold small mb-1 text-secondary">
              <i class="bi bi-eye me-1"></i>Podgląd dla pierwszego rekordu
            </div>
            <div class="bg-light border rounded p-2" style="font-size:.8rem">
              <div class="mb-1"><span class="text-muted">Odbiorca:</span> <strong id="prvName"></strong> &lt;<span id="prvEmail"></span>&gt;</div>
              <div class="mb-1"><span class="text-muted">Temat:</span> <span id="prvSubject"></span></div>
              <div><span class="text-muted">Treść (fragment):</span>
                <div id="prvBody" style="max-height:80px;overflow:auto;border:1px solid #dee2e6;border-radius:4px;padding:6px;background:#fff;margin-top:4px"></div>
              </div>
            </div>
          </div>

        </div>
        <div class="card-footer bg-transparent">
          <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnPreviewMsg">
              <i class="bi bi-eye me-1"></i>Podgląd wiadomości
            </button>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-info btn-sm" id="btnSendTest">
                <i class="bi bi-send-check me-1"></i>Wyślij test do siebie
              </button>
              <button type="button" class="btn btn-primary btn-sm" id="btnSendAll" disabled>
                <i class="bi bi-send-fill me-1"></i>Wyślij do wszystkich
                <span id="sendAllBadge" class="badge bg-light text-dark ms-1">0</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /row -->
</form>

<script>
(function () {
    'use strict';

    let currentCount  = 0;
    let currentSample = null;
    let previewTimer  = null;

    function getFormData(action) {
        const fd = new FormData(document.getElementById('bulkForm'));
        fd.set('_action', action);
        return fd;
    }

    function updatePreview() {
        fetch('bulk_email.php', { method: 'POST', body: getFormData('preview') })
            .then(r => r.json())
            .then(data => {
                if (data.error) { console.error(data.error); return; }
                currentCount  = data.count;
                currentSample = data.sample;
                document.getElementById('recipientCount').textContent = data.count;
                document.getElementById('sendAllBadge').textContent   = data.count;
                document.getElementById('btnSendAll').disabled        = data.count === 0;
                const si = document.getElementById('sampleInfo');
                if (data.sample) {
                    si.textContent = 'Przykład: ' + data.sample.name + ' <' + data.sample.email + '>';
                } else {
                    si.textContent = 'Brak pasujących rekordów.';
                }
            })
            .catch(() => {
                document.getElementById('recipientCount').textContent = '?';
            });
    }

    function schedulePreview() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(updatePreview, 400);
    }

    // Podłącz filtry
    document.querySelectorAll('.filter-input').forEach(el => {
        el.addEventListener('change', schedulePreview);
        el.addEventListener('input',  schedulePreview);
    });

    document.getElementById('btnRefresh').addEventListener('click', updatePreview);

    // Podgląd treści
    document.getElementById('btnPreviewMsg').addEventListener('click', function () {
        if (!currentSample) { alert('Najpierw załaduj podgląd odbiorców.'); return; }
        const box  = document.getElementById('previewBox');
        const subj = document.getElementById('emailSubject').value;
        const body = document.getElementById('emailBody').value;

        function substitute(tpl) {
            if (!currentSample) return tpl;
            const parts = (currentSample.name || '').trim().split(/\s+/, 2);
            return tpl
                .replace(/{imie}/g,        parts[0] || '')
                .replace(/{nazwisko}/g,     parts[1] || '')
                .replace(/{numer_umowy}/g,  '')
                .replace(/{opiekun}/g,      '');
        }

        document.getElementById('prvName').textContent    = currentSample.name;
        document.getElementById('prvEmail').textContent   = currentSample.email;
        document.getElementById('prvSubject').textContent = substitute(subj);
        document.getElementById('prvBody').innerHTML      = substitute(body) || '<em class="text-muted">— brak treści —</em>';
        box.classList.remove('d-none');
    });

    // Wyślij test
    document.getElementById('btnSendTest').addEventListener('click', function () {
        const subj = document.getElementById('emailSubject').value.trim();
        const body = document.getElementById('emailBody').value.trim();
        if (!subj || !body) { alert('Uzupełnij temat i treść wiadomości.'); return; }
        if (!confirm('Wysłać testową wiadomość na Twój adres e-mail?')) return;
        document.getElementById('formAction').value = 'send_test';
        document.getElementById('bulkForm').submit();
    });

    // Wyślij do wszystkich
    document.getElementById('btnSendAll').addEventListener('click', function () {
        const subj = document.getElementById('emailSubject').value.trim();
        const body = document.getElementById('emailBody').value.trim();
        if (!subj || !body) { alert('Uzupełnij temat i treść wiadomości.'); return; }
        if (!confirm('Wysłać wiadomość do ' + currentCount + ' odbiorców?\n\nTej operacji nie można cofnąć.')) return;
        document.getElementById('formAction').value = 'send_all';
        document.getElementById('bulkForm').submit();
    });

    // Załaduj podgląd przy starcie
    updatePreview();
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
