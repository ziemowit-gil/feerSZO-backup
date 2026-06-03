<?php
/**
 * crm/contact/import.php — Import kontaktów z CSV lub XLSX.
 *
 * Krok 1 (GET)        : formularz uploadu pliku
 * Krok 2 (POST upload): podgląd + mapowanie kolumn
 * Krok 3 (POST import): właściwy import i raport wyników
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$crm_can_write = can_write('crm') || is_admin();
if (!$crm_can_write) {
    flash_set('danger', 'Brak uprawnień do importu kontaktów.');
    header('Location: ' . APP_URL . '/crm/index.php');
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// XLSX parser (natywny – brak zewnętrznych zależności)
// ════════════════════════════════════════════════════════════════════════════

function _imp_col_idx(string $col): int
{
    $col = strtoupper(trim($col));
    $idx = 0;
    for ($i = 0, $len = strlen($col); $i < $len; $i++) {
        $idx = $idx * 26 + (ord($col[$i]) - 64);
    }
    return $idx - 1;
}

/** Usuwa deklaracje xmlns żeby SimpleXML działał bez namespace magic. */
function _imp_strip_ns(string $xml): string
{
    return preg_replace('/\s+xmlns(?::[a-zA-Z0-9_]+)?="[^"]*"/', '', $xml);
}

function _imp_parse_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Rozszerzenie PHP "zip" jest wymagane do obsługi plików XLSX.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Nie można otworzyć pliku XLSX — sprawdź czy plik nie jest uszkodzony.');
    }

    /* ── Shared strings ── */
    $shared = [];
    if (($ssXml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $ss = simplexml_load_string(_imp_strip_ns($ssXml));
        if ($ss) {
            foreach ($ss->si as $si) {
                if (count($si->r ?? []) > 0) {          // rich text
                    $t = '';
                    foreach ($si->r as $r) { $t .= (string)($r->t ?? ''); }
                    $shared[] = $t;
                } else {
                    $shared[] = (string)($si->t ?? '');
                }
            }
        }
    }

    /* ── Pierwszy arkusz (przez workbook.xml.rels) ── */
    $sheetFile = 'xl/worksheets/sheet1.xml';
    if (($relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
        $rels = simplexml_load_string(_imp_strip_ns($relsXml));
        if ($rels) {
            foreach ($rels->Relationship as $rel) {
                $rtype = (string)($rel['Type'] ?? '');
                if (str_ends_with($rtype, '/worksheet')) {
                    $target = (string)($rel['Target'] ?? '');
                    $sheetFile = str_starts_with($target, '/xl/')
                        ? ltrim($target, '/')
                        : 'xl/' . ltrim($target, '/');
                    break;
                }
            }
        }
    }

    $wsXml = $zip->getFromName($sheetFile);
    $zip->close();

    if ($wsXml === false) {
        throw new RuntimeException("Nie znaleziono arkusza w pliku XLSX ({$sheetFile}).");
    }
    $ws = simplexml_load_string(_imp_strip_ns($wsXml));
    if (!$ws) {
        throw new RuntimeException('Błąd parsowania arkusza XLSX.');
    }

    $rows = [];
    foreach (($ws->sheetData->row ?? []) as $row) {
        $cells   = [];
        $maxIdx  = -1;
        foreach ($row->c as $c) {
            $ref    = (string)($c['r'] ?? '');
            $colStr = preg_replace('/[0-9]/', '', $ref);
            if ($colStr === '') continue;
            $colIdx = _imp_col_idx($colStr);
            $maxIdx = max($maxIdx, $colIdx);
            $cells[$colIdx] = $c;
        }
        if ($maxIdx < 0) continue;

        $rowArr = array_fill(0, $maxIdx + 1, '');
        foreach ($cells as $idx => $c) {
            $type = (string)($c['t'] ?? '');
            $v    = (string)($c->v ?? '');
            if ($type === 's') {
                $rowArr[$idx] = $shared[(int)$v] ?? '';
            } elseif ($type === 'inlineStr') {
                $rowArr[$idx] = (string)($c->is->t ?? '');
            } elseif ($type === 'b') {
                $rowArr[$idx] = $v === '1' ? 'TAK' : 'NIE';
            } else {
                $rowArr[$idx] = $v;
            }
        }
        if (array_filter($rowArr, fn($x) => trim($x) !== '')) {
            $rows[] = $rowArr;
        }
    }
    return $rows;
}

function _imp_parse_csv(string $path): array
{
    $fh = @fopen($path, 'r');
    if (!$fh) throw new RuntimeException('Nie można otworzyć pliku CSV.');

    /* BOM UTF-8 */
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") { rewind($fh); }

    /* Auto-detekcja separatora na podstawie pierwszego wiersza */
    $first = fgets($fh);
    rewind($fh);
    if ($bom === "\xEF\xBB\xBF") fread($fh, 3); // skip BOM again

    $sc = substr_count($first, ';');
    $cc = substr_count($first, ',');
    $tc = substr_count($first, "\t");
    if ($tc > 0 && $tc >= $sc && $tc >= $cc)       $delim = "\t";
    elseif ($sc >= $cc)                             $delim = ';';
    else                                            $delim = ',';

    $rows = [];
    while (($row = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        $row = array_map('trim', $row);
        if (array_filter($row, fn($x) => $x !== '')) {
            $rows[] = $row;
        }
    }
    fclose($fh);
    return $rows;
}

// ════════════════════════════════════════════════════════════════════════════
// Definicje pól CRM dostępnych przy imporcie
// ════════════════════════════════════════════════════════════════════════════

const IMP_FIELDS = [
    ''                 => '— Pomiń tę kolumnę —',
    'imie_nazwisko'    => 'Imię i nazwisko / Nazwa',
    'email'            => 'E-mail',
    'telefon'          => 'Telefon',
    'type'             => 'Typ kontaktu (osoba/organizacja)',
    'status'           => 'Status CRM',
    'organizacja'      => 'Organizacja / Firma',
    'stanowisko'       => 'Stanowisko / Rola',
    'adres'            => 'Adres',
    'nip'              => 'NIP',
    'krs'              => 'KRS',
    'regon'            => 'REGON',
    'branza'           => 'Branża / Sektor',
    'strona_www'       => 'Strona WWW',
    'osoba_kontaktowa' => 'Osoba kontaktowa',
    'forma_prawna'     => 'Forma prawna',
    'imie'             => 'Imię (osobno)',
    'nazwisko'         => 'Nazwisko (osobno)',
    'pesel'            => 'PESEL',
    'data_urodzenia'   => 'Data urodzenia',
    'notatka'          => 'Notatka / Uwagi',
];

/** Próbuje odgadnąć pole CRM na podstawie nagłówka kolumny. */
function _imp_automap(string $h): string
{
    $h = mb_strtolower(trim($h));
    if (str_contains($h, 'email') || str_contains($h, 'e-mail') || $h === 'mail') return 'email';
    if (str_contains($h, 'komórka') || str_contains($h, 'mobile') || str_contains($h, 'telefon')
        || str_contains($h, 'phone') || (str_contains($h, 'tel') && strlen($h) <= 5)) return 'telefon';
    if (str_contains($h, 'imię i naz') || str_contains($h, 'imie i naz')
        || $h === 'imie_nazwisko' || $h === 'name' || $h === 'full name'
        || $h === 'pełna nazwa' || $h === 'kontakt' || $h === 'osoba') return 'imie_nazwisko';
    if ($h === 'imię' || $h === 'imie' || $h === 'first name' || $h === 'firstname') return 'imie';
    if ($h === 'nazwisko' || $h === 'last name' || $h === 'lastname' || $h === 'surname') return 'nazwisko';
    if (str_contains($h, 'organizacj') || str_contains($h, 'firma') || str_contains($h, 'company')
        || str_contains($h, 'organization') || str_contains($h, 'employer')) return 'organizacja';
    if (str_contains($h, 'stanowisk') || str_contains($h, 'position')
        || str_contains($h, 'tytuł') || $h === 'rola' || $h === 'title') return 'stanowisko';
    if (str_contains($h, 'adres') || str_contains($h, 'address')
        || str_contains($h, 'ulica') || str_contains($h, 'miasto')) return 'adres';
    if ($h === 'nip' || str_contains($h, 'tax id') || str_contains($h, 'vat')) return 'nip';
    if ($h === 'krs') return 'krs';
    if ($h === 'regon') return 'regon';
    if (str_contains($h, 'branż') || str_contains($h, 'branza') || str_contains($h, 'industry')
        || str_contains($h, 'sektor')) return 'branza';
    if (str_contains($h, 'www') || str_contains($h, 'website') || str_contains($h, 'strona')) return 'strona_www';
    if (str_contains($h, 'notatk') || str_contains($h, 'uwag') || str_contains($h, 'note')
        || str_contains($h, 'comment') || str_contains($h, 'opis')) return 'notatka';
    if ($h === 'typ' || $h === 'type' || str_contains($h, 'typ kontaktu')) return 'type';
    if ($h === 'status') return 'status';
    if (str_contains($h, 'pesel')) return 'pesel';
    if (str_contains($h, 'urodzeni') || str_contains($h, 'birthday') || str_contains($h, 'birth')) return 'data_urodzenia';
    if (str_contains($h, 'forma') || str_contains($h, 'legal')) return 'forma_prawna';
    if (str_contains($h, 'kontaktow') || str_contains($h, 'contact person')) return 'osoba_kontaktowa';
    return '';
}

// ════════════════════════════════════════════════════════════════════════════
// Główna logika kroków
// ════════════════════════════════════════════════════════════════════════════

$step       = 'upload';
$error      = '';
$headers    = [];
$previewRows = [];
$totalDataRows = 0;
$tmpName    = '';
$dupMode    = 'skip';   // skip | update | new
$result     = null;     // wyniki importu (krok 3)

$PAGE_TITLE = 'Import kontaktów CRM';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $step    = $_POST['_step'] ?? 'upload';
    $dupMode = in_array($_POST['dup_mode'] ?? '', ['skip','update','new']) ? $_POST['dup_mode'] : 'skip';

    // ── KROK 1→2: Wczytanie pliku ────────────────────────────────────────
    if ($step === 'upload') {
        $file = $_FILES['import_file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = match ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Plik jest zbyt duży.',
                UPLOAD_ERR_NO_FILE                       => 'Nie wybrano pliku.',
                default                                  => 'Błąd przesyłania pliku (kod ' . ($file['error'] ?? '') . ').',
            };
            $step = 'upload';
        } else {
            $origName = $file['name'] ?? 'plik';
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, ['csv', 'xlsx'], true)) {
                $error = 'Nieobsługiwany format pliku. Dopuszczalne: CSV, XLSX.';
                if ($ext === 'xls') {
                    $error = 'Format XLS (Excel 97-2003) nie jest obsługiwany. Zapisz plik jako XLSX (Excel 2007+) lub CSV i spróbuj ponownie.';
                }
                $step = 'upload';
            } else {
                try {
                    $rows = ($ext === 'csv')
                        ? _imp_parse_csv($file['tmp_name'])
                        : _imp_parse_xlsx($file['tmp_name']);

                    if (count($rows) < 2) {
                        throw new RuntimeException('Plik jest pusty lub zawiera tylko jeden wiersz. Upewnij się, że pierwszy wiersz to nagłówki kolumn.');
                    }

                    // Ujednolicenie szerokości wierszy
                    $colCount = max(array_map('count', $rows));
                    foreach ($rows as &$r) {
                        while (count($r) < $colCount) $r[] = '';
                    }
                    unset($r);

                    // Zapisz do pliku tymczasowego
                    $tmpName = 'crm_imp_' . bin2hex(random_bytes(10)) . '.json';
                    file_put_contents(sys_get_temp_dir() . '/' . $tmpName, json_encode($rows, JSON_UNESCAPED_UNICODE));

                    $headers       = $rows[0];
                    $previewRows   = array_slice($rows, 1, 5);
                    $totalDataRows = count($rows) - 1;
                    $step          = 'preview';

                } catch (\Throwable $e) {
                    $error = 'Błąd wczytywania pliku: ' . $e->getMessage();
                    $step  = 'upload';
                }
            }
        }
    }

    // ── KROK 2→3: Import ─────────────────────────────────────────────────
    elseif ($step === 'preview') {
        $tmpName = basename($_POST['_tmp'] ?? '');
        $tmpPath = sys_get_temp_dir() . '/' . $tmpName;

        if (!$tmpName || !file_exists($tmpPath)) {
            $error = 'Sesja importu wygasła. Zacznij od początku.';
            $step  = 'upload';
        } else {
            $rows    = json_decode(file_get_contents($tmpPath), true) ?? [];
            $mapping = $_POST['map'] ?? [];  // [colIdx => field_name]

            // Sprawdź, czy jest mapowanie na imie_nazwisko LUB (imie+nazwisko)
            $hasMapped = in_array('imie_nazwisko', $mapping)
                      || (in_array('imie', $mapping) && in_array('nazwisko', $mapping))
                      || in_array('email', $mapping);

            if (!$hasMapped) {
                $error = 'Mapowanie musi zawierać przynajmniej: „Imię i nazwisko / Nazwa" lub „E-mail" (do identyfikacji kontaktu).';
                // Przywróć dane podglądu
                $headers       = $rows[0] ?? [];
                $previewRows   = array_slice($rows, 1, 5);
                $totalDataRows = count($rows) - 1;
                $step          = 'preview';
            } else {
                // ── Właściwy import ──────────────────────────────────────
                $userId   = (int)(current_user()['id'] ?? 0);
                $imported = 0;
                $updated  = 0;
                $skipped  = 0;
                $errors   = [];

                $dataRows = array_slice($rows, 1);  // bez nagłówka

                foreach ($dataRows as $rowNum => $rowArr) {
                    try {
                        $contact = ['source' => 'import', 'created_by' => $userId];
                        foreach ($mapping as $colIdx => $fieldName) {
                            if ($fieldName === '') continue;
                            $val = trim($rowArr[(int)$colIdx] ?? '');
                            if ($val === '') continue;
                            $contact[$fieldName] = $val;
                        }

                        // Sklej imię+nazwisko jeśli zmapowano osobno
                        if (!isset($contact['imie_nazwisko'])
                            && (isset($contact['imie']) || isset($contact['nazwisko']))) {
                            $contact['imie_nazwisko'] = trim(
                                ($contact['imie'] ?? '') . ' ' . ($contact['nazwisko'] ?? '')
                            );
                        }

                        if (empty(trim($contact['imie_nazwisko'] ?? '')) && empty(trim($contact['email'] ?? ''))) {
                            $errors[] = "Wiersz " . ($rowNum + 2) . ": brak nazwy i e-maila — pominięto.";
                            $skipped++;
                            continue;
                        }

                        // Ustaw nazwę z emaila jeśli brak
                        if (empty(trim($contact['imie_nazwisko'] ?? ''))) {
                            $contact['imie_nazwisko'] = $contact['email'];
                        }

                        // Normalizuj typ
                        if (isset($contact['type'])) {
                            $tv = mb_strtolower($contact['type']);
                            $contact['type'] = (str_contains($tv,'org') || str_contains($tv,'firm') || str_contains($tv,'spółk'))
                                ? 'organizacja' : 'osoba';
                        } else {
                            $contact['type'] = 'osoba';
                        }

                        // Normalizuj status
                        $validStatuses = array_keys(crm_statuses());
                        if (isset($contact['status']) && !in_array($contact['status'], $validStatuses, true)) {
                            unset($contact['status']);
                        }

                        // Sprawdź duplikat po e-mail
                        $existingId = null;
                        if (!empty($contact['email'])) {
                            $existing = db_one(
                                "SELECT id FROM crm_contacts WHERE LOWER(email)=LOWER(?) AND crm_active=1 LIMIT 1",
                                [$contact['email']]
                            );
                            $existingId = $existing['id'] ?? null;
                        }

                        if ($existingId && $dupMode === 'skip') {
                            $skipped++;
                            continue;
                        } elseif ($existingId && $dupMode === 'update') {
                            CrmManager::updateContact($existingId, $contact);
                            $updated++;
                        } else {
                            CrmManager::createContact($contact);
                            $imported++;
                        }

                    } catch (\Throwable $e) {
                        $errors[] = "Wiersz " . ($rowNum + 2) . ": " . $e->getMessage();
                    }
                }

                // Usuń temp
                @unlink($tmpPath);

                $result = compact('imported', 'updated', 'skipped', 'errors');
                $step   = 'done';
            }
        }
    }
}

include __DIR__ . '/../includes/header_crm.php';
?>

<style>
/* ── Import styles ─────────────────────────────────────────────── */
.imp-card        { border-radius:.75rem;border:0;box-shadow:0 1px 6px rgba(0,0,0,.08) }
.imp-drop-zone {
    border: 2.5px dashed #cbd5e1;
    border-radius: .75rem;
    padding: 3rem 2rem;
    text-align: center;
    cursor: pointer;
    transition: border-color .18s, background .18s;
    background: #f8fafc;
}
.imp-drop-zone.drag-over, .imp-drop-zone:hover { border-color:#2E844A; background:#f0fdf4; }
.imp-drop-icon  { font-size: 3rem; color:#94a3b8; display:block;margin-bottom:.75rem }
.imp-field-sel  { font-size:.82rem; min-width:210px }
.imp-preview th { font-size:.75rem; white-space:nowrap; background:#f8fafc }
.imp-preview td { font-size:.78rem; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.imp-map-header { font-size:.8rem; font-weight:700; color:#334155; padding:.4rem 0 .2rem }
.imp-result-ok  { color:#2E844A }
.imp-result-upd { color:#0176D3 }
.imp-result-skip{ color:#64748b }
.imp-result-err { color:#E31010 }
.format-badge   { display:inline-block;padding:.15em .55em;border-radius:.3em;font-size:.7rem;font-weight:700;letter-spacing:.04em }
.imp-step-dot {
    width:28px; height:28px; border-radius:50%;
    display:inline-flex; align-items:center; justify-content:center;
    font-size:.78rem; font-weight:700;
}
.imp-step-dot.active  { background:#2E844A; color:#fff }
.imp-step-dot.done    { background:#EFF7ED; color:#2E844A; border:1.5px solid #2E844A }
.imp-step-dot.pending { background:#f1f5f9; color:#94a3b8 }
</style>

<!-- Breadcrumb -->
<nav aria-label="Ścieżka nawigacji" class="mb-2">
  <ol class="breadcrumb mb-0" style="font-size:.82rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php"><i class="bi bi-diagram-2-fill me-1" style="color:var(--crm-primary)"></i>CRM</a></li>
    <li class="breadcrumb-item active">Import kontaktów</li>
  </ol>
</nav>

<!-- Nagłówek + pasek kroków -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <h1 class="h5 fw-bold mb-0">
    <i class="bi bi-cloud-upload me-2" style="color:var(--crm-primary)"></i>Import kontaktów
  </h1>
  <!-- Kroki -->
  <div class="d-flex align-items-center gap-2" style="font-size:.8rem;color:#64748b">
    <span class="imp-step-dot <?= $step==='upload'?'active':($step==='upload'?'pending':'done') ?>">1</span>
    <span>Wczytaj plik</span>
    <i class="bi bi-chevron-right" style="font-size:.7rem"></i>
    <span class="imp-step-dot <?= $step==='preview'?'active':($step==='done'?'done':'pending') ?>">2</span>
    <span>Mapowanie kolumn</span>
    <i class="bi bi-chevron-right" style="font-size:.7rem"></i>
    <span class="imp-step-dot <?= $step==='done'?'active':'pending' ?>">3</span>
    <span>Wynik</span>
  </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
  <div><?= h($error) ?></div>
</div>
<?php endif; ?>

<?php // ═══════════════════════════════ KROK 1: UPLOAD ════════════════════════
if ($step === 'upload'): ?>

<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="card imp-card">
      <div class="card-body p-4">
        <form method="post" enctype="multipart/form-data" id="uploadForm">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_step" value="upload">

          <!-- Drop zone -->
          <div class="imp-drop-zone mb-4" id="dropZone" onclick="document.getElementById('fileInput').click()">
            <span class="imp-drop-icon"><i class="bi bi-file-earmark-arrow-up"></i></span>
            <div class="fw-semibold mb-1" style="color:#334155">
              Przeciągnij plik tutaj lub kliknij, by wybrać
            </div>
            <div class="text-muted small">
              Obsługiwane formaty:
              <span class="format-badge" style="background:#dcfce7;color:#166534">CSV</span>
              <span class="format-badge ms-1" style="background:#dbeafe;color:#1e40af">XLSX</span>
            </div>
            <div class="text-muted mt-2" style="font-size:.75rem">Maks. <?= ini_get('upload_max_filesize') ?></div>
          </div>
          <input type="file" id="fileInput" name="import_file"
                 accept=".csv,.xlsx" class="d-none"
                 onchange="fileSelected(this)">

          <!-- Wybrany plik -->
          <div id="fileInfo" class="d-none alert alert-info py-2 px-3 mb-3 d-flex align-items-center gap-2">
            <i class="bi bi-file-earmark-check"></i>
            <span id="fileName"></span>
            <button type="button" class="btn-close btn-close-sm ms-auto" onclick="clearFile()" aria-label="Usuń"></button>
          </div>

          <!-- Opcja duplikatów -->
          <div class="mb-3">
            <label class="form-label fw-semibold" style="font-size:.85rem">
              <i class="bi bi-copy me-1"></i>Kontakty z tym samym e-mailem (duplikaty):
            </label>
            <div class="d-flex flex-wrap gap-3">
              <?php foreach([
                'skip'   => ['Pomiń (domyślnie)', 'Istniejące kontakty nie zostaną zmienione'],
                'update' => ['Aktualizuj',         'Nadpisz dane istniejącego kontaktu'],
                'new'    => ['Utwórz nowy',         'Stwórz nowy kontakt nawet jeśli e-mail istnieje'],
              ] as $val => [$lbl,$desc]): ?>
              <div class="form-check">
                <input class="form-check-input" type="radio"
                       name="dup_mode" id="dup_<?= $val ?>" value="<?= $val ?>"
                       <?= $val==='skip'?'checked':'' ?>>
                <label class="form-check-label" for="dup_<?= $val ?>">
                  <span class="fw-semibold" style="font-size:.83rem"><?= $lbl ?></span>
                  <div class="text-muted" style="font-size:.75rem"><?= $desc ?></div>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <button type="submit" class="btn btn-crm-primary w-100" id="submitBtn" disabled>
            <i class="bi bi-arrow-right-circle me-2"></i>Wczytaj i przejdź do mapowania
          </button>
        </form>

        <!-- Szablon CSV -->
        <div class="border-top mt-4 pt-3">
          <div class="text-muted small mb-2"><i class="bi bi-info-circle me-1"></i>Potrzebujesz szablonu?</div>
          <a href="<?= APP_URL ?>/crm/contact/import_template.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-download me-1"></i>Pobierz szablon CSV
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php // ═══════════════════════════════ KROK 2: MAPOWANIE ══════════════════
elseif ($step === 'preview'): ?>

<form method="post" id="importForm">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="_step"    value="preview">
  <input type="hidden" name="_tmp"     value="<?= h($tmpName) ?>">
  <input type="hidden" name="dup_mode" value="<?= h($dupMode) ?>">

  <div class="card imp-card mb-3">
    <div class="card-body p-4">
      <div class="crm-section-title mb-3">
        <i class="bi bi-table me-1"></i>Mapowanie kolumn
        <span class="text-muted ms-2" style="font-size:.8rem;font-weight:400">
          <?= $totalDataRows ?> <?= $totalDataRows===1?'wiersz':'wierszy' ?> danych · <?= count($headers) ?> kolumn
        </span>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-bordered imp-preview align-middle mb-0">
          <thead>
            <!-- Wiersz mapowania -->
            <tr style="background:#f0fdf4">
              <?php foreach ($headers as $colIdx => $colName): ?>
              <th style="min-width:200px">
                <div class="imp-map-header" title="<?= h($colName) ?>">
                  <i class="bi bi-arrow-down me-1 text-success" aria-hidden="true"></i>
                  <?= h(mb_substr($colName, 0, 24) . (mb_strlen($colName)>24?'…':'')) ?>
                </div>
                <select name="map[<?= $colIdx ?>]"
                        class="form-select form-select-sm imp-field-sel"
                        aria-label="Mapowanie kolumny <?= h($colName) ?>">
                  <?php
                    $auto = _imp_automap($colName);
                    foreach (IMP_FIELDS as $fVal => $fLabel):
                  ?>
                  <option value="<?= h($fVal) ?>" <?= $fVal===$auto?'selected':'' ?>>
                    <?= h($fLabel) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </th>
              <?php endforeach; ?>
            </tr>
            <!-- Wiersz nagłówka oryginalnego (cień) -->
            <tr class="table-secondary" style="font-size:.72rem;color:#64748b">
              <?php foreach ($headers as $h_): ?>
              <th><?= h($h_) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($previewRows as $pr): ?>
            <tr>
              <?php foreach ($headers as $ci => $_): ?>
              <td title="<?= h($pr[$ci] ?? '') ?>">
                <?= h(mb_substr($pr[$ci] ?? '', 0, 40)) ?>
                <?php if (mb_strlen($pr[$ci] ?? '') > 40): ?>…<?php endif; ?>
              </td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalDataRows > 5): ?>
      <div class="text-muted small mt-1">
        <i class="bi bi-eye me-1"></i>Pokazano 5 z <?= $totalDataRows ?> wierszy danych.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Przyciski -->
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= APP_URL ?>/crm/contact/import.php" class="btn btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Wróć
    </a>
    <button type="submit" class="btn btn-crm-primary">
      <i class="bi bi-upload me-2"></i>Importuj <?= $totalDataRows ?> <?= $totalDataRows===1?'kontakt':'kontaktów' ?>
    </button>
  </div>
</form>

<?php // ═══════════════════════════════ KROK 3: WYNIK ═══════════════════════
elseif ($step === 'done' && $result !== null): ?>

<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="card imp-card">
      <div class="card-body p-4">

        <!-- Ikona statusu -->
        <div class="text-center mb-4">
          <?php if ($result['imported'] > 0 || $result['updated'] > 0): ?>
          <i class="bi bi-check-circle-fill" style="font-size:3rem;color:#2E844A"></i>
          <div class="fw-bold mt-2" style="font-size:1.1rem;color:#1e293b">Import zakończony!</div>
          <?php else: ?>
          <i class="bi bi-info-circle-fill" style="font-size:3rem;color:#0176D3"></i>
          <div class="fw-bold mt-2" style="font-size:1.1rem;color:#1e293b">Import zakończony bez zmian</div>
          <?php endif; ?>
        </div>

        <!-- Statystyki -->
        <div class="row g-3 text-center mb-4">
          <div class="col-3">
            <div class="fw-bold fs-2 imp-result-ok"><?= $result['imported'] ?></div>
            <div class="text-muted small">dodanych</div>
          </div>
          <div class="col-3">
            <div class="fw-bold fs-2 imp-result-upd"><?= $result['updated'] ?></div>
            <div class="text-muted small">zaktualizowanych</div>
          </div>
          <div class="col-3">
            <div class="fw-bold fs-2 imp-result-skip"><?= $result['skipped'] ?></div>
            <div class="text-muted small">pominiętych</div>
          </div>
          <div class="col-3">
            <div class="fw-bold fs-2 imp-result-err"><?= count($result['errors']) ?></div>
            <div class="text-muted small">błędów</div>
          </div>
        </div>

        <!-- Błędy (jeśli są) -->
        <?php if ($result['errors']): ?>
        <details class="mb-3">
          <summary class="text-danger small fw-semibold" style="cursor:pointer">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Pokaż szczegóły błędów (<?= count($result['errors']) ?>)
          </summary>
          <div class="mt-2 p-3 bg-light rounded" style="font-size:.78rem;max-height:200px;overflow-y:auto">
            <?php foreach ($result['errors'] as $err): ?>
            <div class="text-danger mb-1"><i class="bi bi-x me-1"></i><?= h($err) ?></div>
            <?php endforeach; ?>
          </div>
        </details>
        <?php endif; ?>

        <!-- Akcje -->
        <div class="d-flex gap-2 flex-wrap">
          <a href="<?= APP_URL ?>/crm/index.php" class="btn btn-crm-primary">
            <i class="bi bi-diagram-2-fill me-1"></i>Przejdź do CRM
          </a>
          <a href="<?= APP_URL ?>/crm/contact/import.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-repeat me-1"></i>Importuj kolejny plik
          </a>
        </div>

      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<script>
/* ── Drag & drop + file info ──────────────────────────────────────── */
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const fileInfo  = document.getElementById('fileInfo');
const fileName  = document.getElementById('fileName');
const submitBtn = document.getElementById('submitBtn');

if (dropZone) {
    ['dragenter','dragover'].forEach(ev =>
        dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.add('drag-over'); })
    );
    ['dragleave','drop'].forEach(ev =>
        dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.remove('drag-over'); })
    );
    dropZone.addEventListener('drop', e => {
        const f = e.dataTransfer.files[0];
        if (f) { fileInput.files = e.dataTransfer.files; fileSelected(fileInput); }
    });
}

function fileSelected(input) {
    const f = input.files[0];
    if (!f) return;
    const ext = f.name.split('.').pop().toLowerCase();
    const ok  = ['csv','xlsx'].includes(ext);
    fileInfo.classList.remove('d-none');
    fileInfo.className = 'alert py-2 px-3 mb-3 d-flex align-items-center gap-2 '
                        + (ok ? 'alert-info' : 'alert-danger');
    fileName.textContent = f.name + ' (' + (f.size > 1048576
        ? (f.size/1048576).toFixed(1)+' MB'
        : Math.round(f.size/1024)+' KB') + ')';
    if (submitBtn) submitBtn.disabled = !ok;
    if (!ok) fileName.textContent += ' — nieobsługiwany format!';
}

function clearFile() {
    if (fileInput) fileInput.value = '';
    if (fileInfo)  fileInfo.classList.add('d-none');
    if (submitBtn) submitBtn.disabled = true;
}

/* ── Blokada podwójnego submitu importu ───────────────────────────── */
const importForm = document.getElementById('importForm');
if (importForm) {
    importForm.addEventListener('submit', function() {
        const btn = this.querySelector('[type=submit]');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Importowanie…';
        }
    });
}
</script>

<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
