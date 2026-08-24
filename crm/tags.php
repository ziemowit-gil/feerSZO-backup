<?php
/**
 * crm/tags.php — Zarządzanie tagami CRM.
 *
 * Widok wszystkich tagów: lista, liczba kontaktów, zmiana nazwy, usuwanie.
 * Tylko editorzy i administratorzy mogą modyfikować tagi.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();
crm_require('contacts', 'read');

$crm_can_write  = can_write('crm') || is_admin();
$crm_can_delete = can_delete('crm') || is_admin();

$PAGE_TITLE = 'CRM — Zarządzanie tagami';

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $crm_can_write) {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'rename') {
        $old = trim($_POST['old_tag'] ?? '');
        $new = trim($_POST['new_tag'] ?? '');
        if ($old !== '' && $new !== '') {
            $n = CrmManager::renameTag($old, $new);
            flash_set('success', 'Tag "' . $old . '" → "' . $new . '" zaktualizowany (' . $n . ' kontaktów).');
        } else {
            flash_set('danger', 'Nieprawidłowe dane — obie nazwy tagów są wymagane.');
        }
    }

    if ($action === 'delete' && $crm_can_delete) {
        $tag = trim($_POST['tag'] ?? '');
        if ($tag !== '') {
            $n = CrmManager::deleteTag($tag);
            flash_set('success', 'Tag "' . $tag . '" usunięty z ' . $n . ' kontaktów.');
        }
    }

    if ($action === 'merge') {
        // Scalanie: przenieś wszystkich kontaktów z kilku tagów do jednego
        $target = mb_strtolower(trim($_POST['target_tag'] ?? ''));
        $sources = array_filter(array_map('trim', explode(',', $_POST['source_tags'] ?? '')));
        $total = 0;
        foreach ($sources as $src) {
            if ($src !== $target) $total += CrmManager::renameTag($src, $target);
        }
        if ($target !== '' && $total > 0) {
            flash_set('success', 'Scalono ' . count($sources) . ' tagów → "' . $target . '" (' . $total . ' operacji).');
        }
    }

    header('Location: ' . APP_URL . '/crm/tags.php');
    exit;
}

$tags = CrmManager::getAllTags();
$total_tags     = count($tags);
$total_contacts = (int)(db_one("SELECT COUNT(DISTINCT contact_id) AS c FROM crm_tags")['c'] ?? 0);

include __DIR__ . '/includes/header_crm.php';
?>

<style>
.tag-row-actions { opacity: 0; transition: opacity .15s; }
.tag-table-row:hover .tag-row-actions { opacity: 1; }
.tag-cloud-item {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .3rem .7rem; border-radius: 999px;
  background: var(--crm-primary-bg); border: 1px solid var(--crm-primary-light);
  color: var(--crm-primary-dark); font-size: .82rem; font-weight: 500;
  text-decoration: none; cursor: pointer;
}
.tag-cloud-item:hover { background: var(--crm-primary); color: #fff; }
.tag-cloud-item.selected { background: var(--crm-primary); color: #fff; border-color: var(--crm-primary-dark); }
.rename-inline { display: none; }
.rename-inline.show { display: flex; }
</style>

<!-- Breadcrumb -->
<nav aria-label="Ścieżka nawigacji" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item active" aria-current="page">Zarządzanie tagami</li>
  </ol>
</nav>

<!-- Nagłówek -->
<div class="crm-object-header shadow-sm mb-4">
  <div class="crm-object-icon" aria-hidden="true" style="background:var(--crm-primary)">
    <i class="bi bi-tags-fill"></i>
  </div>
  <div>
    <h1 class="crm-object-title">Zarządzanie tagami</h1>
    <div class="crm-object-count">
      <?= $total_tags ?> <?= $total_tags === 1 ? 'tag' : ($total_tags < 5 ? 'tagi' : 'tagów') ?>
      · <?= $total_contacts ?> otagowanych kontaktów
    </div>
  </div>
  <div class="crm-object-actions">
    <a href="<?= APP_URL ?>/crm/index.php" class="btn btn-crm-outline btn-sm">
      <i class="bi bi-people me-1"></i>Kontakty
    </a>
  </div>
</div>

<?php if (!$tags): ?>
<div class="crm-empty" role="status">
  <i class="bi bi-tags" aria-hidden="true" style="font-size:2.5rem;color:var(--crm-border)"></i>
  <div class="mt-2 fw-semibold">Brak tagów</div>
  <p class="text-muted small mt-1">Tagi pojawiają się po dodaniu ich do kontaktów.</p>
  <a href="<?= APP_URL ?>/crm/index.php" class="btn btn-crm-primary btn-sm mt-2">
    <i class="bi bi-people me-1"></i>Przejdź do kontaktów
  </a>
</div>
<?php else: ?>

<div class="row g-3">

  <!-- ── Lewa: chmura tagów ──────────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-body">
        <div class="crm-section-title">Chmura tagów</div>
        <div class="d-flex flex-wrap gap-2 mb-1" role="list" aria-label="Lista wszystkich tagów">
          <?php foreach ($tags as $t): ?>
          <span class="tag-cloud-item" role="listitem"
                onclick="selectTag(<?= json_encode($t['tag']) ?>)"
                data-tag="<?= h($t['tag']) ?>"
                title="<?= (int)$t['cnt'] ?> kontaktów"
                aria-label="Tag: <?= h($t['tag']) ?>, <?= (int)$t['cnt'] ?> kontaktów"
                style="font-size: <?= min(1.1, .75 + (int)$t['cnt'] * .06) ?>rem">
            <?= h($t['tag']) ?>
            <span style="font-size:.72em;opacity:.6"><?= (int)$t['cnt'] ?></span>
          </span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Panel scalania -->
    <?php if ($crm_can_write && count($tags) >= 2): ?>
    <div class="card">
      <div class="card-body">
        <div class="crm-section-title">Scal tagi</div>
        <p class="text-muted small mb-3">
          Przenosi wszystkie kontakty z wybranych tagów źródłowych do jednego tagu docelowego.
        </p>
        <form method="post" onsubmit="return confirmMerge()">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="merge">
          <div class="mb-2">
            <label class="form-label small fw-semibold" for="target_tag">Tag docelowy</label>
            <select name="target_tag" id="target_tag" class="form-select form-select-sm" required>
              <option value="">— wybierz tag docelowy —</option>
              <?php foreach ($tags as $t): ?>
              <option value="<?= h($t['tag']) ?>"><?= h($t['tag']) ?> (<?= (int)$t['cnt'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="source_tags_input">Tagi źródłowe (oddziel przecinkiem)</label>
            <input type="text" name="source_tags" id="source_tags_input"
                   class="form-control form-control-sm"
                   placeholder="np. wolontariusz,wolontariusze"
                   autocomplete="off">
            <div class="text-muted" style="font-size:.72rem;margin-top:.2rem">
              Lub kliknij tagi w chmurze powyżej, aby je wybrać
            </div>
          </div>
          <button type="submit" class="btn btn-crm-outline btn-sm w-100">
            <i class="bi bi-node-plus me-1"></i>Scal tagi
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Prawa: tabela tagów z akcjami ──────────────────────── -->
  <div class="col-lg-8">
    <div class="crm-list-card">
      <div class="p-3 border-bottom d-flex align-items-center justify-content-between">
        <span class="fw-semibold" style="font-size:.88rem">
          <i class="bi bi-table me-1 text-muted"></i>Wszystkie tagi
        </span>
        <input type="text" id="tagSearch" class="form-control form-control-sm"
               style="max-width:200px" placeholder="Szukaj tagu…"
               oninput="filterTagRows(this.value)"
               aria-label="Szukaj tagu w tabeli">
      </div>
      <div class="table-responsive">
        <table class="crm-table" id="tagTable" aria-label="Tabela tagów CRM">
          <thead>
            <tr>
              <th scope="col">Tag</th>
              <th scope="col" class="text-center">Kontaktów</th>
              <th scope="col"><span class="visually-hidden">Akcje</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tags as $t): ?>
            <tr class="tag-table-row" data-tag="<?= h($t['tag']) ?>">
              <td>
                <div class="d-flex align-items-center gap-2">
                  <span class="crm-tag"><?= h($t['tag']) ?></span>
                </div>

                <!-- Inline rename form -->
                <?php if ($crm_can_write): ?>
                <form method="post"
                      class="rename-inline gap-2 mt-2 align-items-center"
                      id="rename-<?= h(preg_replace('/[^a-z0-9]/i', '-', $t['tag'])) ?>"
                      aria-label="Zmień nazwę tagu <?= h($t['tag']) ?>">
                  <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action"  value="rename">
                  <input type="hidden" name="old_tag"  value="<?= h($t['tag']) ?>">
                  <input type="text"   name="new_tag"
                         class="form-control form-control-sm"
                         value="<?= h($t['tag']) ?>"
                         placeholder="Nowa nazwa…"
                         maxlength="30"
                         style="max-width:180px"
                         aria-label="Nowa nazwa tagu"
                         required>
                  <button type="submit" class="btn btn-sm btn-crm-primary" aria-label="Zapisz nową nazwę">
                    <i class="bi bi-check-lg"></i>
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-secondary"
                          onclick="hideRename(this)" aria-label="Anuluj">
                    <i class="bi bi-x-lg"></i>
                  </button>
                </form>
                <?php endif; ?>
              </td>

              <td class="text-center">
                <a href="<?= APP_URL ?>/crm/index.php?tag=<?= urlencode($t['tag']) ?>"
                   class="crm-badge crm-badge-prospect"
                   title="Pokaż kontakty z tym tagiem"
                   aria-label="<?= (int)$t['cnt'] ?> kontaktów z tagiem <?= h($t['tag']) ?>">
                  <?= (int)$t['cnt'] ?>
                </a>
              </td>

              <td class="text-end tag-row-actions">
                <?php if ($crm_can_write): ?>
                <button type="button"
                        class="btn btn-crm-ghost btn-sm"
                        onclick="showRename('<?= h($t['tag']) ?>')"
                        aria-label="Zmień nazwę tagu <?= h($t['tag']) ?>">
                  <i class="bi bi-pencil" aria-hidden="true"></i>
                </button>
                <?php endif; ?>
                <?php if ($crm_can_delete): ?>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Usunąć tag &quot;<?= h(addslashes($t['tag'])) ?>&quot; z <?= (int)$t['cnt'] ?> kontaktów?')">
                  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action" value="delete">
                  <input type="hidden" name="tag"     value="<?= h($t['tag']) ?>">
                  <button type="submit" class="btn btn-crm-ghost btn-sm text-danger"
                          aria-label="Usuń tag <?= h($t['tag']) ?>">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                  </button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /row -->
<?php endif; ?>

<script>
function showRename(tag) {
  // Ukryj wszystkie inne
  document.querySelectorAll('.rename-inline.show').forEach(function(f) {
    f.classList.remove('show');
  });
  // Pokaż właściwy
  var slug = tag.replace(/[^a-z0-9]/gi, '-');
  var form = document.getElementById('rename-' + slug);
  if (form) {
    form.classList.add('show');
    form.querySelector('[name="new_tag"]').focus();
    form.querySelector('[name="new_tag"]').select();
  }
}

function hideRename(btn) {
  btn.closest('.rename-inline').classList.remove('show');
}

function filterTagRows(q) {
  q = q.toLowerCase();
  document.querySelectorAll('#tagTable tbody tr').forEach(function(row) {
    var tag = (row.dataset.tag || '').toLowerCase();
    row.style.display = q === '' || tag.includes(q) ? '' : 'none';
  });
}

// Chmura tagów → zaznaczenie i wpisanie do pola scalania
var selectedTags = [];
function selectTag(tag) {
  var el = document.querySelector('.tag-cloud-item[data-tag="' + tag.replace(/"/g, '\\"') + '"]');
  var idx = selectedTags.indexOf(tag);
  if (idx === -1) {
    selectedTags.push(tag);
    if (el) el.classList.add('selected');
  } else {
    selectedTags.splice(idx, 1);
    if (el) el.classList.remove('selected');
  }
  var inp = document.getElementById('source_tags_input');
  if (inp) inp.value = selectedTags.join(',');
}

function confirmMerge() {
  var target = document.getElementById('target_tag').value;
  var sources = document.getElementById('source_tags_input').value;
  if (!target || !sources) { alert('Wybierz tag docelowy i podaj tagi źródłowe.'); return false; }
  return confirm('Scal tagi "' + sources + '" → "' + target + '"? Tej operacji nie można cofnąć.');
}
</script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
