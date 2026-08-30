<?php
/**
 * admin/contract_templates.php
 * Zarządzanie wzorami dokumentów (szablony umów).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/contract_template_engine.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

require_role('admin');
cte_migrate();

$PAGE_TITLE = 'Wzory dokumentów';
$SELF       = APP_URL . '/admin/contract_templates.php';

$type_labels = [
    'universal'   => 'Uniwersalny',
    'wolontariat' => 'Wolontariat',
    'zlecenie'    => 'Zlecenie',
    'dzielo'      => 'Dzieło',
    'praca'       => 'Praca',
];

/**
 * Konwertuje plik DOCX do uproszczonego HTML używając PhpWord.
 * Obsługuje akapity, nagłówki, listy, pogrubienie, kursywę, podkreślenie.
 */
function cte_docx_to_html(string $path): string {
    if (!file_exists($path)) return '';

    try {
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($path, 'Word2007');
    } catch (\Throwable $e) {
        return '';
    }

    $html      = '';
    $listItems = '';

    $flushList = function () use (&$listItems, &$html) {
        if ($listItems !== '') {
            $html     .= "<ul>$listItems</ul>\n";
            $listItems = '';
        }
    };

    foreach ($phpWord->getSections() as $section) {
        foreach ($section->getElements() as $el) {
            $elName = substr(get_class($el), strrpos(get_class($el), '\\') + 1);

            if ($elName === 'TextBreak') {
                $flushList();
                $html .= "<p>&nbsp;</p>\n";
                continue;
            }

            if ($elName === 'ListItem') {
                $listItems .= '<li>' . htmlspecialchars($el->getText() ?? '', ENT_QUOTES) . "</li>\n";
                continue;
            }

            if ($elName === 'ListItemRun') {
                $listItems .= '<li>' . _cte_runs_to_html($el) . "</li>\n";
                continue;
            }

            $flushList();

            if ($elName === 'Title') {
                $depth = max(1, min(3, (int)($el->getDepth() ?: 1)));
                $val   = $el->getText();
                $inner = ($val instanceof \PhpOffice\PhpWord\Element\TextRun)
                    ? _cte_runs_to_html($val)
                    : htmlspecialchars((string)$val, ENT_QUOTES);
                if ($inner) $html .= "<h{$depth}>$inner</h{$depth}>\n";
                continue;
            }

            if ($elName === 'TextRun') {
                $inner = _cte_runs_to_html($el);
                $html .= $inner ? "<p>$inner</p>\n" : "<p>&nbsp;</p>\n";
                continue;
            }

            if ($elName === 'Text') {
                $inner = _cte_text_to_html($el);
                if ($inner) $html .= "<p>$inner</p>\n";
                continue;
            }
        }
    }
    $flushList();

    return trim($html) ?: '';
}

/** Rekurencyjnie konwertuje zawartość kontenera (TextRun, ListItemRun) na HTML. */
function _cte_runs_to_html($container): string {
    $out = '';
    foreach ($container->getElements() as $child) {
        $name = substr(get_class($child), strrpos(get_class($child), '\\') + 1);
        if ($name === 'Text')      $out .= _cte_text_to_html($child);
        elseif ($name === 'TextBreak') $out .= '<br>';
    }
    return $out;
}

/** Konwertuje element Text na HTML z obsługą bold/italic/underline. */
function _cte_text_to_html(\PhpOffice\PhpWord\Element\Text $el): string {
    $text = htmlspecialchars($el->getText() ?? '', ENT_QUOTES);
    if ($text === '') return '';

    $font = $el->getFontStyle();
    if (is_string($font)) $font = \PhpOffice\PhpWord\Style::getStyle($font);

    if ($font instanceof \PhpOffice\PhpWord\Style\Font) {
        if ($font->isBold())   $text = "<strong>$text</strong>";
        if ($font->isItalic()) $text = "<em>$text</em>";
        $u = $font->getUnderline();
        if ($u && $u !== 'none') $text = "<u>$text</u>";
    }
    return $text;
}

// ── POST ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'import_docx') {
        $file = $_FILES['docx_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            flash_set('error', 'Błąd uploadu pliku DOCX.');
            header('Location: ' . $SELF); exit;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'docx') {
            flash_set('error', 'Dozwolony tylko format .docx.');
            header('Location: ' . $SELF); exit;
        }
        $html = cte_docx_to_html($file['tmp_name']);
        if (!$html) {
            flash_set('error', 'Nie udało się przetworzyć pliku DOCX. Sprawdź czy plik nie jest uszkodzony.');
            header('Location: ' . $SELF); exit;
        }
        $name = pathinfo($file['name'], PATHINFO_FILENAME);
        $type = array_key_exists($_POST['docx_type'] ?? '', $type_labels) ? $_POST['docx_type'] : 'universal';
        $id   = db_insert('contract_doc_templates', [
            'name'       => $name,
            'type'       => $type,
            'body'       => $html,
            'created_by' => (int)current_user()['id'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        flash_set('success', 'Zaimportowano „' . $name . '" z DOCX. Możesz teraz edytować treść.');
        header('Location: ' . $SELF . '?edit=' . $id); exit;
    }

    if (in_array($act, ['create', 'update'], true)) {
        $name = trim($_POST['name'] ?? '');
        $type = array_key_exists($_POST['type'] ?? '', $type_labels) ? $_POST['type'] : 'universal';
        $desc = trim($_POST['description'] ?? '');
        $body = $_POST['body'] ?? '';

        if (!$name) { flash_set('error', 'Nazwa szablonu jest wymagana.'); header('Location: ' . $SELF); exit; }

        if ($act === 'create') {
            db_insert('contract_doc_templates', [
                'name'        => $name,
                'type'        => $type,
                'description' => $desc,
                'body'        => $body,
                'created_by'  => (int)current_user()['id'],
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            flash_set('success', 'Szablon „' . $name . '" został utworzony.');
        } else {
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare(
                "UPDATE contract_doc_templates SET name=?, type=?, description=?, body=?, updated_at=datetime('now','localtime') WHERE id=?"
            )->execute([$name, $type, $desc, $body, $id]);
            flash_set('success', 'Szablon zaktualizowany.');
        }
        header('Location: ' . $SELF); exit;
    }

    if ($act === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = db_one("SELECT is_active FROM contract_doc_templates WHERE id=?", [$id]);
        if ($row) {
            db()->prepare("UPDATE contract_doc_templates SET is_active=?, updated_at=datetime('now','localtime') WHERE id=?")
                ->execute([$row['is_active'] ? 0 : 1, $id]);
            flash_set('success', 'Status zmieniony.');
        }
        header('Location: ' . $SELF); exit;
    }

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM contract_doc_templates WHERE id=?")->execute([$id]);
        flash_set('success', 'Szablon usunięty.');
        header('Location: ' . $SELF); exit;
    }

    if ($act === 'center_all') {
        $rows  = db_all("SELECT id, body FROM contract_doc_templates");
        $count = 0;
        foreach ($rows as $r) {
            $centered = cte_center_all_blocks($r['body']);
            if ($centered !== $r['body']) {
                db()->prepare("UPDATE contract_doc_templates SET body=?, updated_at=datetime('now','localtime') WHERE id=?")
                    ->execute([$centered, $r['id']]);
                $count++;
            }
        }
        flash_set('success', 'Wyśrodkowano treść ' . $count . ' z ' . count($rows) . ' wzorów.');
        header('Location: ' . $SELF); exit;
    }

    header('Location: ' . $SELF); exit;
}

// ── Pobierz listę + edytowany ────────────────────────────────────────────
$templates = db_all("SELECT * FROM contract_doc_templates ORDER BY type, name");
$edit_id   = (int)($_GET['edit'] ?? 0);
$editing   = $edit_id ? db_one("SELECT * FROM contract_doc_templates WHERE id=?", [$edit_id]) : null;
$variables = cte_variables();

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
#quillEditor { min-height: 420px; font-size: .93rem; }
.var-badge {
  display: inline-block; font-family: monospace; font-size: .72rem;
  background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;
  border-radius: 4px; padding: .1rem .35rem; cursor: pointer;
  transition: background .12s;
  user-select: none;
}
.var-badge:hover { background: #dbeafe; }
.tpl-row td { vertical-align: middle; }
</style>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Wzory dokumentów</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Wzory dokumentów</h4>
  <div class="d-flex gap-2 ms-auto">
    <form method="post" class="d-inline"
          onsubmit="return confirm('Wyśrodkować treść WSZYSTKICH wzorów dokumentów? Nadpisze bieżące formatowanie akapitów.')">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="center_all">
      <button class="btn btn-outline-secondary btn-sm" title="Wyśrodkuj treść wszystkich wzorów">
        <i class="bi bi-text-center me-1"></i>Wyśrodkuj wszystkie
      </button>
    </form>
    <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#importDocxModal">
      <i class="bi bi-file-earmark-word me-1"></i>Import DOCX
    </button>
    <a href="<?= APP_URL ?>/admin/template_editor.php?new=1" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Nowy wzór
    </a>
  </div>
</div>

<?= flash_html() ?>

<!-- ── Lista szablonów ──────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3">Nazwa</th>
          <th>Typ umowy</th>
          <th>Opis</th>
          <th class="text-center" style="width:90px">Status</th>
          <th style="width:140px"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$templates): ?>
        <tr><td colspan="5" class="text-center text-muted py-5">
          Brak wzorów. Kliknij <strong>Nowy wzór</strong> aby dodać pierwszy.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($templates as $t): ?>
        <tr class="tpl-row">
          <td class="ps-3 fw-semibold"><?= h($t['name']) ?></td>
          <td><span class="badge bg-secondary bg-opacity-25 text-secondary"><?= h($type_labels[$t['type']] ?? $t['type']) ?></span></td>
          <td class="small text-muted"><?= h(mb_substr($t['description'] ?? '', 0, 80)) ?></td>
          <td class="text-center">
            <span class="badge <?= $t['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
              <?= $t['is_active'] ? 'Aktywny' : 'Nieaktywny' ?>
            </span>
          </td>
          <td class="pe-3">
            <div class="d-flex gap-1 justify-content-end">
              <a href="<?= APP_URL ?>/admin/template_editor.php?id=<?= $t['id'] ?>"
                 class="btn btn-outline-primary btn-sm py-0 px-2"
                 aria-label="Edytuj <?= h($t['name']) ?>">
                <i class="bi bi-pencil"></i>
              </a>
              <a href="<?= APP_URL ?>/contracts/print_template.php?template_id=<?= $t['id'] ?>&preview=1"
                 target="_blank"
                 class="btn btn-outline-secondary btn-sm py-0 px-2"
                 aria-label="Podgląd wzoru <?= h($t['name']) ?>">
                <i class="bi bi-eye"></i>
              </a>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="toggle">
                <input type="hidden" name="id"      value="<?= $t['id'] ?>">
                <button class="btn btn-outline-warning btn-sm py-0 px-2"
                        aria-label="<?= $t['is_active'] ? 'Dezaktywuj' : 'Aktywuj' ?> <?= h($t['name']) ?>">
                  <i class="bi bi-<?= $t['is_active'] ? 'pause' : 'play' ?>"></i>
                </button>
              </form>
              <form method="post" class="d-inline"
                    onsubmit="return confirm('Usunąć wzór «<?= h(addslashes($t['name'])) ?>»?')">
                <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id"      value="<?= $t['id'] ?>">
                <button class="btn btn-outline-danger btn-sm py-0 px-2"
                        aria-label="Usuń <?= h($t['name']) ?>">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Modal: Import DOCX ──────────────────────────────────────────────── -->
<div class="modal fade" id="importDocxModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="import_docx">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold">
            <i class="bi bi-file-earmark-word text-primary me-1"></i>Import z DOCX
          </h6>
          <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Plik Word (.docx) <span class="text-danger">*</span></label>
            <input type="file" name="docx_file" class="form-control form-control-sm"
                   accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                   required>
            <div class="form-text">Obsługiwane: formatowanie (bold, italic, nagłówki, listy). Obrazy i tabele są ignorowane.</div>
          </div>
          <div class="mb-0">
            <label class="form-label small fw-semibold">Typ umowy</label>
            <select name="docx_type" class="form-select form-select-sm">
              <?php foreach ($type_labels as $k => $v): ?>
              <option value="<?= $k ?>"><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-upload me-1"></i>Importuj i edytuj
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Edytor szablonu ──────────────────────────────────────────── -->
<div class="modal fade" id="tplModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" id="tplForm">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" id="tpl-action" value="create">
        <input type="hidden" name="id"      id="tpl-id"     value="0">
        <input type="hidden" name="body"    id="tpl-body">

        <div class="modal-header py-2">
          <h5 class="modal-title fw-bold" id="tpl-modal-title">Nowy wzór dokumentu</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-sm-5">
              <label class="form-label small fw-semibold">Nazwa wzoru <span class="text-danger">*</span></label>
              <input type="text" name="name" id="tpl-name" class="form-control form-control-sm"
                     placeholder="np. Porozumienie wolontariackie — standardowe" required maxlength="200">
            </div>
            <div class="col-sm-3">
              <label class="form-label small fw-semibold">Typ umowy</label>
              <select name="type" id="tpl-type" class="form-select form-select-sm">
                <?php foreach ($type_labels as $k => $v): ?>
                <option value="<?= $k ?>"><?= h($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label small fw-semibold">Opis (opcjonalny)</label>
              <input type="text" name="description" id="tpl-desc" class="form-control form-control-sm"
                     placeholder="Krótki opis zastosowania">
            </div>
          </div>

          <div class="row g-3">
            <!-- Edytor — zakładki Visual / HTML / Tekst -->
            <div class="col-lg-8">
              <div class="d-flex align-items-center justify-content-between mb-1">
                <label class="form-label small fw-semibold mb-0">Treść dokumentu</label>
                <ul class="nav nav-pills nav-sm" id="editorTabs" style="gap:.25rem">
                  <li class="nav-item">
                    <button type="button" class="nav-link active py-0 px-2" style="font-size:.75rem" onclick="setEditorMode('visual')">
                      <i class="bi bi-type me-1"></i>Wizualny
                    </button>
                  </li>
                  <li class="nav-item">
                    <button type="button" class="nav-link py-0 px-2" style="font-size:.75rem" onclick="setEditorMode('html')">
                      <i class="bi bi-code me-1"></i>HTML
                    </button>
                  </li>
                  <li class="nav-item">
                    <button type="button" class="nav-link py-0 px-2" style="font-size:.75rem" onclick="setEditorMode('text')">
                      <i class="bi bi-fonts me-1"></i>Tekst
                    </button>
                  </li>
                </ul>
              </div>

              <!-- Tryb Wizualny (Quill) -->
              <div id="editorVisual">
                <div class="border rounded" style="overflow:hidden">
                  <div id="tplToolbar">
                    <span class="ql-formats">
                      <select class="ql-header"><option selected></option><option value="1"></option><option value="2"></option><option value="3"></option></select>
                    </span>
                    <span class="ql-formats">
                      <button class="ql-bold"></button>
                      <button class="ql-italic"></button>
                      <button class="ql-underline"></button>
                    </span>
                    <span class="ql-formats">
                      <select class="ql-align"></select>
                    </span>
                    <span class="ql-formats">
                      <button class="ql-list" value="ordered"></button>
                      <button class="ql-list" value="bullet"></button>
                    </span>
                    <span class="ql-formats">
                      <button class="ql-indent" value="-1"></button>
                      <button class="ql-indent" value="+1"></button>
                    </span>
                    <span class="ql-formats">
                      <button class="ql-clean"></button>
                    </span>
                  </div>
                  <div id="quillEditor"></div>
                </div>
              </div>

              <!-- Tryb HTML -->
              <div id="editorHtml" style="display:none">
                <textarea id="tpl-html-src"
                          class="form-control font-monospace"
                          style="min-height:420px;font-size:.8rem;resize:vertical"
                          placeholder="<p>Treść dokumentu w HTML...</p>&#10;<p>Zmienne: {imie_nazwisko}, {numer_umowy}</p>"></textarea>
              </div>

              <!-- Tryb Tekst -->
              <div id="editorText" style="display:none">
                <textarea id="tpl-text-src"
                          class="form-control"
                          style="min-height:420px;font-size:.88rem;resize:vertical;line-height:1.6"
                          placeholder="Treść w formacie tekstowym...&#10;Zmienne: {imie_nazwisko}, {numer_umowy}&#10;&#10;Puste linie = nowe akapity."></textarea>
                <div class="form-text">Tekst zostanie sformatowany automatycznie. Puste linie tworzą nowe akapity.</div>
              </div>

              <div class="form-text mt-1">Kliknij zmienną z listy po prawej, aby wstawić ją do dokumentu.</div>
            </div>

            <!-- Lista zmiennych -->
            <div class="col-lg-4">
              <label class="form-label small fw-semibold">Dostępne zmienne</label>
              <div class="border rounded p-2" style="max-height:460px;overflow-y:auto;background:#f8fafc">
                <?php foreach ($variables as $group => $vars): ?>
                <div class="small text-muted fw-semibold mb-1 mt-2"><?= h($group) ?></div>
                <?php foreach ($vars as $var => $desc): ?>
                <div class="mb-1">
                  <span class="var-badge" onclick="insertVar(<?= json_encode($var) ?>)" title="<?= h($desc) ?>">
                    <?= h($var) ?>
                  </span>
                  <span class="text-muted" style="font-size:.68rem"> <?= h($desc) ?></span>
                </div>
                <?php endforeach; ?>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer py-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary btn-sm" onclick="syncBody()">
            <i class="bi bi-check2 me-1"></i>Zapisz wzór
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Quill -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

<script>
var quill = new Quill('#quillEditor', {
    theme: 'snow',
    modules: { toolbar: '#tplToolbar' },
    placeholder: 'Wpisz treść dokumentu… Użyj zmiennych w formacie {zmienna}',
});

var _editorMode = 'visual'; // 'visual' | 'html' | 'text'

function setEditorMode(mode) {
    // Odczytaj zawartość PRZED zmianą trybu
    var currentHtml = getEditorHtml();

    _editorMode = mode;
    document.getElementById('editorVisual').style.display = mode === 'visual' ? '' : 'none';
    document.getElementById('editorHtml').style.display   = mode === 'html'   ? '' : 'none';
    document.getElementById('editorText').style.display   = mode === 'text'   ? '' : 'none';

    // Aktualizuj zakładki nav
    document.querySelectorAll('#editorTabs .nav-link').forEach(function(btn, i) {
        btn.classList.toggle('active', ['visual','html','text'][i] === mode);
    });

    // Załaduj zawartość do nowego trybu
    if (mode === 'html') {
        document.getElementById('tpl-html-src').value = currentHtml;
    } else if (mode === 'text') {
        var tmp = document.createElement('div');
        tmp.innerHTML = currentHtml;
        document.getElementById('tpl-text-src').value = (tmp.innerText || tmp.textContent || '').trim();
    } else {
        // Zawsze aktualizuj Quill przy przełączeniu na Wizualny
        quill.root.innerHTML = currentHtml;
    }
}

function getEditorHtml() {
    if (_editorMode === 'visual') return quill.root.innerHTML;
    if (_editorMode === 'html')   return document.getElementById('tpl-html-src').value;
    if (_editorMode === 'text') {
        // Tekst → HTML: puste linie = akapity
        var txt = document.getElementById('tpl-text-src').value;
        return txt.split(/\n{2,}/).map(function(p) {
            return '<p>' + p.replace(/\n/g, '<br>').trim() + '</p>';
        }).join('');
    }
    return '';
}

function syncBody() {
    document.getElementById('tpl-body').value = getEditorHtml();
}

function insertVar(varName) {
    if (_editorMode === 'visual') {
        quill.focus();
        const range = quill.getSelection() || { index: quill.getLength() - 1 };
        quill.insertText(range.index, varName, 'user');
        quill.setSelection(range.index + varName.length);
    } else if (_editorMode === 'html') {
        var ta = document.getElementById('tpl-html-src');
        var s = ta.selectionStart, e = ta.selectionEnd;
        ta.value = ta.value.slice(0, s) + varName + ta.value.slice(e);
        ta.selectionStart = ta.selectionEnd = s + varName.length;
        ta.focus();
    } else {
        var ta = document.getElementById('tpl-text-src');
        var s = ta.selectionStart, e = ta.selectionEnd;
        ta.value = ta.value.slice(0, s) + varName + ta.value.slice(e);
        ta.selectionStart = ta.selectionEnd = s + varName.length;
        ta.focus();
    }
}

function openCreate() {
    document.getElementById('tpl-action').value = 'create';
    document.getElementById('tpl-id').value     = '0';
    document.getElementById('tpl-name').value   = '';
    document.getElementById('tpl-type').value   = 'universal';
    document.getElementById('tpl-desc').value   = '';
    document.getElementById('tpl-modal-title').textContent = 'Nowy wzór dokumentu';
    quill.root.innerHTML = '';
    setEditorMode('visual');
}

function openEdit(id, name, type, desc, body) {
    document.getElementById('tpl-action').value = 'update';
    document.getElementById('tpl-id').value     = id;
    document.getElementById('tpl-name').value   = name;
    document.getElementById('tpl-type').value   = type;
    document.getElementById('tpl-desc').value   = desc;
    document.getElementById('tpl-modal-title').textContent = 'Edytuj wzór: ' + name;
    quill.root.innerHTML = body;
    setEditorMode('visual');
    new bootstrap.Modal(document.getElementById('tplModal')).show();
}

document.getElementById('tplForm').addEventListener('submit', function() {
    syncBody();
});

<?php if ($editing): ?>
// Auto-otwórz edytor po imporcie DOCX
window.addEventListener('load', function() {
    openEdit(
        <?= $editing['id'] ?>,
        <?= json_encode($editing['name']) ?>,
        <?= json_encode($editing['type']) ?>,
        <?= json_encode($editing['description'] ?? '') ?>,
        <?= json_encode($editing['body']) ?>
    );
});
<?php endif; ?>
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
