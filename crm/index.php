<?php
/**
 * crm/index.php — CRM: Lista Kontaktów
 *
 * AJAX: filtry, paginacja i szybkie akcje (status / usuń) bez przeładowania strony.
 *       GET ?_ajax=1 → JSON { ok, total, list_html }
 *       POST z nagłówkiem X-Requested-With: XMLHttpRequest → JSON { ok }
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');

$crm_can_write    = can_write('crm') || is_admin();
$crm_can_delete   = can_delete('crm') || is_admin();
$crm_can_export   = can_read('crm_eksport') || is_admin();
$crm_can_import   = can_write('crm_import') || is_admin();
$crm_can_mailing  = can_write('crm_mailing') || is_admin();

crm_migrate();

$PAGE_TITLE = 'CRM — Kontakty';

// ── Filtry ────────────────────────────────────────────────────────────────────
$filters = [
    'q'            => trim($_GET['q']            ?? ''),
    'status'       => trim($_GET['status']       ?? ''),
    'type'         => trim($_GET['type']         ?? ''),
    'tag'          => trim($_GET['tag']          ?? ''),
    'group'        => (int)($_GET['group']       ?? 0) ?: '',
    'wojewodztwo'  => trim($_GET['wojewodztwo']  ?? ''),
    'action_id'    => (int)($_GET['action_id']   ?? 0) ?: '',
    // Wyszukiwanie zaawansowane
    'q_all'        => trim($_GET['q_all']        ?? ''),
    'source'       => trim($_GET['source']       ?? ''),
    'branza'       => trim($_GET['branza']       ?? ''),
    'powiat'       => trim($_GET['powiat']       ?? ''),
    'gmina'        => trim($_GET['gmina']        ?? ''),
    'created_from' => trim($_GET['created_from'] ?? ''),
    'created_to'   => trim($_GET['created_to']   ?? ''),
    'last_from'    => trim($_GET['last_from']    ?? ''),
    'last_to'      => trim($_GET['last_to']      ?? ''),
    'stale_days'   => (int)($_GET['stale_days']  ?? 0) ?: '',
    'has_email'    => !empty($_GET['has_email'])  ? '1' : '',
    'has_phone'    => !empty($_GET['has_phone'])  ? '1' : '',
];
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

// ── Dane ──────────────────────────────────────────────────────────────────────
$result  = CrmManager::getContacts($filters, $page, $per_page);
$rows    = $result['rows'];
$total   = $result['total'];
$paging  = paginate($total, $per_page, $page, APP_URL . '/crm/index.php?' . http_build_query(array_filter($filters)));
$stats   = CrmManager::getStats();

$all_tags   = db_all("SELECT tag, COUNT(*) AS cnt FROM crm_tags
                      JOIN crm_contacts ON crm_contacts.id = crm_tags.contact_id
                      WHERE crm_contacts.crm_active=1
                      GROUP BY tag ORDER BY cnt DESC LIMIT 30");
$all_groups = CrmManager::getGroups();

$crm_actions = [];
try {
    $crm_actions = db_all(
        "SELECT DISTINCT a.id, a.nazwa FROM actions a
         JOIN crm_action_links al ON al.action_id=a.id
         ORDER BY a.nazwa"
    );
} catch (\Throwable $e) {}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $crm_can_write) {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $xhr    = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
              && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($action === 'quick_status' && !empty($_POST['contact_id'])) {
        $cid    = (int)$_POST['contact_id'];
        $status = trim($_POST['new_status'] ?? '');
        if (array_key_exists($status, crm_statuses())) {
            CrmManager::updateContact($cid, ['status' => $status]);
            if ($xhr) { header('Content-Type: application/json'); echo json_encode(['ok' => true, 'new_status' => $status]); exit; }
            flash_set('success', 'Status kontaktu zaktualizowany.');
        } elseif ($xhr) { header('Content-Type: application/json'); echo json_encode(['ok' => false]); exit; }
    } elseif ($action === 'quick_note' && !empty($_POST['contact_id']) && can_write('crm')) {
        $cid  = (int)$_POST['contact_id'];
        $body = trim($_POST['body'] ?? '');
        header('Content-Type: application/json');
        if ($body && $cid) {
            db_insert('crm_notes', [
                'contact_id' => $cid,
                'body'       => $body,
                'is_pinned'  => 0,
                'created_by' => (int)current_user()['id'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            echo json_encode(['ok' => true, 'msg' => 'Notatka dodana.']);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Pusta notatka.']);
        }
        exit;
    }

    if ($action === 'delete' && !empty($_POST['contact_id']) && $crm_can_delete) {
        CrmManager::deleteContact((int)$_POST['contact_id']);
        if ($xhr) { header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit; }
        flash_set('success', 'Kontakt usunięty.');
    }

    header('Location: ' . APP_URL . '/crm/index.php?' . http_build_query(array_filter($filters)));
    exit;
}

// ── Renderer listy (używany przez pełną stronę I przez AJAX ?_ajax=1) ─────────
function _crm_table_html(
    array $rows, int $total, array $paging, int $per_page,
    array $filters, bool $can_w, bool $can_d
): string {
    $page = $paging['page'];
    $lu   = function(array $m = []) use ($filters, $page): string {
        return APP_URL . '/crm/index.php?' . http_build_query(array_filter(array_merge(['page' => $page], $filters, $m)));
    };
    ob_start();
    ?>
    <div class="crm-list-card">
      <?php if ($rows): ?>
      <div class="table-responsive">
        <table class="crm-table" id="crmContactTable"
               aria-label="Kontakty CRM — <?= $total ?> rekordów">
          <thead>
            <tr>
              <th scope="col" style="width:36px">
                <input type="checkbox" id="chk-all" class="form-check-input"
                       aria-label="Zaznacz wszystkie kontakty"
                       onchange="Bulk.toggleAll(this.checked)">
              </th>
              <th scope="col">Kontakt <i class="bi bi-arrow-down-up sort-icon ms-1" aria-hidden="true"></i></th>
              <th scope="col">Status</th>
              <th scope="col" class="d-none d-md-table-cell">Firma / Stanowisko</th>
              <th scope="col" class="d-none d-lg-table-cell">Tagi</th>
              <th scope="col" class="d-none d-xl-table-cell">Ostatni kontakt</th>
              <th scope="col" class="d-none d-md-table-cell">Typ</th>
              <th scope="col"><span class="visually-hidden">Akcje</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row):
              $initials = $row['avatar_initials'] ?: CrmManager::makeInitials($row['imie_nazwisko']);
              $tags     = $row['tags_csv'] ? array_filter(explode(',', $row['tags_csv'])) : [];
              $sc       = crm_statuses()[$row['status']] ?? ['label' => $row['status'], 'color' => '#939393'];
              $is_org   = $row['type'] === 'organizacja';
            ?>
            <tr data-row-href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$row['id'] ?>">
              <td>
                <input type="checkbox" class="form-check-input crm-row-chk"
                       value="<?= (int)$row['id'] ?>"
                       aria-label="Zaznacz: <?= h($row['imie_nazwisko']) ?>"
                       onchange="Bulk.update()">
              </td>

              <!-- Kontakt: awatar + imię -->
              <td>
                <div class="crm-name-cell">
                  <div class="crm-avatar <?= $is_org ? 'org' : '' ?>" aria-hidden="true"
                       style="background:<?= $is_org ? 'var(--crm-navy)' : 'var(--crm-primary)' ?>">
                    <?= h($initials) ?>
                  </div>
                  <div>
                    <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$row['id'] ?>"
                       class="crm-name-link">
                      <?= h($row['imie_nazwisko']) ?>
                    </a>
                    <?php if ($row['email']): ?>
                    <div class="crm-name-sub">
                      <i class="bi bi-envelope" aria-hidden="true"></i>
                      <?= h($row['email']) ?>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>

              <!-- Status -->
              <td>
                <?php if ($can_w): ?>
                <div class="dropdown">
                  <button type="button"
                          class="crm-badge crm-badge-<?= h($row['status']) ?> border-0 bg-transparent dropdown-toggle"
                          data-bs-toggle="dropdown"
                          aria-haspopup="true"
                          aria-expanded="false"
                          aria-label="Status: <?= h($sc['label']) ?> — kliknij aby zmienić">
                    <?= h($sc['label']) ?>
                  </button>
                  <ul class="dropdown-menu shadow-sm" style="font-size:.83rem">
                    <?php foreach (crm_statuses() as $sk => $sv): ?>
                    <li>
                      <form method="post" class="d-inline" data-ajax-action="quick_status">
                        <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
                        <input type="hidden" name="_action"    value="quick_status">
                        <input type="hidden" name="contact_id" value="<?= (int)$row['id'] ?>">
                        <input type="hidden" name="new_status" value="<?= h($sk) ?>">
                        <button type="submit"
                                class="dropdown-item <?= $row['status'] === $sk ? 'fw-bold text-success' : '' ?>">
                          <?= h($sv['label']) ?>
                          <?= $row['status'] === $sk ? '<i class="bi bi-check2 ms-1" aria-hidden="true"></i>' : '' ?>
                        </button>
                      </form>
                    </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
                <?php else: ?>
                <span class="crm-badge crm-badge-<?= h($row['status']) ?>"><?= h($sc['label']) ?></span>
                <?php endif; ?>
              </td>

              <!-- Firma / Stanowisko -->
              <td class="d-none d-md-table-cell">
                <?php if ($row['organizacja']): ?>
                <div style="font-size:.84rem"><?= h($row['organizacja']) ?></div>
                <?php endif; ?>
                <?php if ($row['stanowisko']): ?>
                <div class="crm-name-sub"><?= h($row['stanowisko']) ?></div>
                <?php endif; ?>
                <?php if (!$row['organizacja'] && !$row['stanowisko']): ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
              </td>

              <!-- Tagi -->
              <td class="d-none d-lg-table-cell">
                <?php if ($tags): ?>
                <div class="crm-tags-list">
                  <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                  <a href="<?= h($lu(['tag' => $tag, 'page' => 1])) ?>"
                     class="crm-tag"
                     aria-label="Filtruj po tagu: <?= h($tag) ?>">
                    <?= h($tag) ?>
                  </a>
                  <?php endforeach; ?>
                  <?php if (count($tags) > 3): ?>
                  <span class="crm-tag" style="background:#f3f3f3;border-color:#ddd;color:var(--crm-text-light)"
                        aria-label="+<?= count($tags) - 3 ?> więcej tagów">
                    +<?= count($tags) - 3 ?>
                  </span>
                  <?php endif; ?>
                </div>
                <?php else: ?>
                <span class="text-muted" aria-label="Brak tagów">—</span>
                <?php endif; ?>
              </td>

              <!-- Ostatni kontakt -->
              <td class="d-none d-xl-table-cell">
                <?php if ($row['last_comm_at']): ?>
                <span style="font-size:.82rem;color:var(--crm-text-muted)">
                  <i class="bi bi-chat-dots me-1 text-muted" aria-hidden="true"></i>
                  <?= date_pl($row['last_comm_at']) ?>
                </span>
                <?php else: ?>
                <span class="text-muted small">Brak</span>
                <?php endif; ?>
              </td>

              <!-- Typ -->
              <td class="d-none d-md-table-cell">
                <span class="badge bg-light text-dark border" style="font-size:.72rem">
                  <i class="bi bi-<?= $is_org ? 'building' : 'person' ?> me-1" aria-hidden="true"></i>
                  <?= $is_org ? 'Org.' : 'Osoba' ?>
                </span>
              </td>

              <!-- Akcje -->
              <td class="text-end" style="white-space:nowrap">
                <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$row['id'] ?>"
                   class="btn btn-crm-ghost btn-sm"
                   aria-label="Podgląd: <?= h($row['imie_nazwisko']) ?>">
                  <i class="bi bi-eye" aria-hidden="true"></i>
                </a>
                <?php if ($can_w): ?>
                <a href="<?= APP_URL ?>/crm/contact/add.php?id=<?= (int)$row['id'] ?>"
                   class="btn btn-crm-ghost btn-sm"
                   aria-label="Edytuj: <?= h($row['imie_nazwisko']) ?>">
                  <i class="bi bi-pencil" aria-hidden="true"></i>
                </a>
                <button type="button"
                        class="btn btn-crm-ghost btn-sm"
                        aria-label="Wyślij wiadomość do <?= h($row['imie_nazwisko']) ?>"
                        onclick="openCommModal(<?= (int)$row['id'] ?>)">
                  <i class="bi bi-send" aria-hidden="true"></i>
                </button>
                <?php endif; ?>
                <?php if ($can_w): ?>
                <button type="button"
                        class="btn btn-link btn-sm p-0 ms-1 crm-quick-note-btn"
                        data-id="<?= (int)$row['id'] ?>"
                        data-name="<?= h($row['imie_nazwisko']) ?>"
                        title="Szybka notatka"
                        aria-label="Dodaj notatkę do <?= h($row['imie_nazwisko']) ?>">
                  <i class="bi bi-pencil-square text-warning" aria-hidden="true"></i>
                </button>
                <?php endif; ?>
                <button type="button"
                        class="btn btn-link btn-sm p-0 ms-1 crm-comms-btn"
                        data-id="<?= (int)$row['id'] ?>"
                        title="Historia komunikacji"
                        aria-label="Historia: <?= h($row['imie_nazwisko']) ?>">
                  <i class="bi bi-clock-history text-muted" aria-hidden="true"></i>
                </button>
                <?php if ($can_d): ?>
                <form method="post" class="d-inline"
                      data-ajax-action="delete"
                      data-contact-name="<?= h($row['imie_nazwisko']) ?>">
                  <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action"    value="delete">
                  <input type="hidden" name="contact_id" value="<?= (int)$row['id'] ?>">
                  <button type="submit"
                          class="btn btn-crm-ghost btn-sm text-danger"
                          aria-label="Usuń: <?= h($row['imie_nazwisko']) ?>">
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

      <!-- Paginacja -->
      <?php if ($paging['pages'] > 1): ?>
      <div class="p-3 border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span class="text-muted small">
          Pokazuję <?= (($paging['page'] - 1) * $per_page) + 1 ?>–<?= min($paging['page'] * $per_page, $total) ?>
          z <?= $total ?> rekordów
        </span>
        <nav aria-label="Strony wyników"><?= pagination_html($paging) ?></nav>
      </div>
      <?php endif; ?>

      <?php else: ?>
      <!-- Empty state -->
      <div class="crm-empty" role="status" aria-live="polite">
        <span class="crm-empty-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
        <?php if (array_filter($filters)): ?>
        <h5>Brak wyników dla wybranych filtrów</h5>
        <p class="text-muted">Spróbuj zmienić kryteria lub <a href="<?= APP_URL ?>/crm/index.php">wyczyść filtry</a>.</p>
        <?php elseif (!crm_access_unrestricted() && empty(crm_accessible_group_ids())): ?>
        <h5>Brak dostępu do kontaktów</h5>
        <p class="text-muted">Twoje konto nie jest przypisane do żadnej grupy CRM. Skontaktuj się z administratorem.</p>
        <?php else: ?>
        <h5>Brak kontaktów w systemie CRM</h5>
        <p class="text-muted">Zacznij od dodania pierwszego kontaktu lub zaimportuj dane.</p>
        <?php if ($can_w): ?>
        <a href="<?= APP_URL ?>/crm/contact/add.php" class="btn btn-crm-primary mt-2">
          <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj pierwszy kontakt
        </a>
        <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ── AJAX fragment endpoint ────────────────────────────────────────────────────
if (isset($_GET['_ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok'        => true,
        'total'     => $total,
        'list_html' => _crm_table_html($rows, $total, $paging, $per_page, $filters, $crm_can_write, $crm_can_delete),
    ]);
    exit;
}

// ── Pełna strona ──────────────────────────────────────────────────────────────
include __DIR__ . '/includes/header_crm.php';
?>

<!-- ══ STATYSTYKI ═══════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="crm-stat-card">
      <div class="crm-stat-value"><?= $stats['total'] ?></div>
      <div class="crm-stat-label">
        <i class="bi bi-people-fill me-1" style="color:var(--crm-primary)" aria-hidden="true"></i>Wszystkich kontaktów
      </div>
      <?php if ($stats['new_this_month'] > 0): ?>
      <div class="crm-stat-delta up">
        <i class="bi bi-arrow-up-short" aria-hidden="true"></i><?= $stats['new_this_month'] ?> nowych w tym miesiącu
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php foreach (array_slice($stats['by_status'], 0, 3) as $bs):
    $sc = crm_statuses()[$bs['status']] ?? ['label' => $bs['status'], 'color' => '#939393'];
  ?>
  <div class="col-6 col-md-3">
    <div class="crm-stat-card">
      <div class="crm-stat-value" style="color:<?= h($sc['color']) ?>"><?= (int)$bs['cnt'] ?></div>
      <div class="crm-stat-label"><?= h($sc['label']) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ══ OBJECT HEADER ══════════════════════════════════════════════════════════ -->
<div class="crm-object-header shadow-sm">
  <div class="crm-object-icon" aria-hidden="true"><i class="bi bi-diagram-2-fill"></i></div>
  <div>
    <h1 class="crm-object-title">Kontakty CRM</h1>
    <div class="crm-object-count" id="crm-total-count" aria-live="polite" aria-atomic="true">
      <?= $total ?> <?= $total === 1 ? 'rekord' : ($total < 5 ? 'rekordy' : 'rekordów') ?>
    </div>
  </div>
  <div class="crm-object-actions">
    <?php if ($crm_can_write): ?>
    <a href="<?= APP_URL ?>/crm/contact/quick_add.php" class="btn btn-crm-primary btn-sm">
      <i class="bi bi-lightning-fill me-1" aria-hidden="true"></i>Szybkie +
    </a>
    <a href="<?= APP_URL ?>/crm/contact/add.php" class="btn btn-crm-outline btn-sm">
      <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Pełny
    </a>
    <?php endif; ?>
    <?php if ($crm_can_import): ?>
    <a href="<?= APP_URL ?>/crm/import.php" class="btn btn-crm-outline btn-sm">
      <i class="bi bi-file-earmark-arrow-up me-1" aria-hidden="true"></i>Importuj CSV
    </a>
    <?php endif; ?>
    <?php if ($crm_can_mailing): ?>
    <a href="<?= APP_URL ?>/crm/communicate.php" class="btn btn-crm-outline btn-sm">
      <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij wiadomość
    </a>
    <?php endif; ?>
    <?php if ($crm_can_export): ?>
    <?php $export_q = http_build_query(array_filter($filters)); ?>
    <div class="dropdown">
      <button class="btn btn-crm-outline btn-sm dropdown-toggle"
              data-bs-toggle="dropdown"
              aria-expanded="false"
              aria-haspopup="true">
        <i class="bi bi-download me-1" aria-hidden="true"></i>Eksport
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size:.83rem">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/export.php?<?= $export_q ?>">
          <i class="bi bi-filetype-csv me-2 text-muted" aria-hidden="true"></i>CSV (Excel-kompatybilny)
        </a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/export.php?format=xlsx&<?= $export_q ?>">
          <i class="bi bi-file-earmark-spreadsheet me-2 text-success" aria-hidden="true"></i>Excel (.xlsx)
        </a></li>
        <li><hr class="dropdown-divider"></li>
        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#crmExportModal">
          <i class="bi bi-table me-2 text-primary" aria-hidden="true"></i>Wybór kolumn…
        </button></li>
      </ul>
    </div>

    <!-- ── Modal: eksport z wyborem kolumn ──────────────────────────────── -->
    <?php
    $crm_export_cols = [
        'id' => 'ID', 'type' => 'Typ', 'imie_nazwisko' => 'Imię i nazwisko',
        'email' => 'E-mail', 'telefon' => 'Telefon', 'organizacja' => 'Organizacja',
        'stanowisko' => 'Stanowisko', 'status' => 'Status', 'adres' => 'Adres',
        'nip' => 'NIP', 'krs' => 'KRS', 'regon' => 'REGON', 'pesel' => 'PESEL',
        'branza' => 'Branża', 'strona_www' => 'Strona WWW', 'wojewodztwo' => 'Województwo',
        'powiat' => 'Powiat', 'gmina' => 'Gmina', 'tags' => 'Tagi', 'notatka' => 'Notatka',
        'source' => 'Źródło', 'last_comm' => 'Ostatni kontakt', 'created' => 'Dodano',
    ];
    ?>
    <div class="modal fade" id="crmExportModal" tabindex="-1" aria-labelledby="crmExportModalLbl" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content" id="crmExportForm" method="get" action="<?= APP_URL ?>/crm/export.php">
          <?php foreach (array_filter($filters) as $fk => $fv): ?>
          <input type="hidden" name="<?= h($fk) ?>" value="<?= h($fv) ?>">
          <?php endforeach; ?>
          <div class="modal-header">
            <h5 class="modal-title" id="crmExportModalLbl">
              <i class="bi bi-table me-2" aria-hidden="true"></i>Eksport — wybór kolumn
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
          </div>
          <div class="modal-body">
            <p class="text-muted small mb-2">
              Eksport obejmuje kontakty pasujące do bieżących filtrów. Zaznacz kolumny do uwzględnienia.
            </p>
            <div class="d-flex gap-2 mb-2">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="crmExportAll">Zaznacz wszystkie</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="crmExportNone">Odznacz wszystkie</button>
            </div>
            <div class="row row-cols-2 row-cols-md-3 g-1" id="crmExportCols">
              <?php foreach ($crm_export_cols as $ck => $clabel): ?>
              <div class="col">
                <label class="form-check">
                  <input class="form-check-input" type="checkbox" name="cols[]" value="<?= h($ck) ?>" checked>
                  <span class="form-check-label"><?= h($clabel) ?></span>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
            <hr>
            <div class="d-flex align-items-center gap-3">
              <span class="small fw-semibold">Format:</span>
              <label class="form-check form-check-inline mb-0">
                <input class="form-check-input" type="radio" name="format" value="csv" checked>
                <span class="form-check-label">CSV</span>
              </label>
              <label class="form-check form-check-inline mb-0">
                <input class="form-check-input" type="radio" name="format" value="xlsx">
                <span class="form-check-label">Excel (.xlsx)</span>
              </label>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
            <button type="submit" class="btn btn-crm-primary btn-sm">
              <i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>
    <div class="crm-sync-label ms-2" id="crmSyncStatus"
         title="Synchronizacja heartbeat co 50 s"
         aria-live="polite" aria-atomic="true">
      <span class="crm-sync-dot" id="crmSyncDot" aria-hidden="true"></span>
      <span id="crmSyncText">Synchronizacja</span>
    </div>
  </div>
</div>

<!-- ══ FILTER STRIP ═══════════════════════════════════════════════════════════ -->
<form id="crm-filter-form" method="get" action="<?= APP_URL ?>/crm/index.php"
      class="crm-filter-bar" role="search" aria-label="Filtry kontaktów">

  <div class="crm-search-wrap">
    <i class="bi bi-search" aria-hidden="true"></i>
    <input type="text"
           name="q"
           id="crm-search-input"
           value="<?= h($filters['q']) ?>"
           class="form-control"
           placeholder="Szukaj kontaktów…  ( / )"
           title="Skrót: naciśnij / aby szukać, n aby dodać osobę"
           aria-label="Szukaj kontaktów (skrót: ukośnik)"
           autocomplete="off">
  </div>

  <select name="status" class="form-select" style="width:auto;min-width:130px"
          aria-label="Filtruj po statusie">
    <option value="">Wszystkie statusy</option>
    <?php foreach (crm_statuses() as $key => $s): ?>
    <option value="<?= h($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>>
      <?= h($s['label']) ?>
    </option>
    <?php endforeach; ?>
  </select>

  <select name="type" class="form-select" style="width:auto;min-width:120px"
          aria-label="Filtruj po typie">
    <option value="">Wszystkie typy</option>
    <option value="osoba"       <?= $filters['type'] === 'osoba'       ? 'selected' : '' ?>>Osoba</option>
    <option value="organizacja" <?= $filters['type'] === 'organizacja' ? 'selected' : '' ?>>Organizacja</option>
  </select>

  <?php
  $woj_in_crm = db_all("SELECT DISTINCT wojewodztwo FROM crm_contacts
                         WHERE crm_active=1 AND wojewodztwo IS NOT NULL AND wojewodztwo != ''
                         ORDER BY wojewodztwo");
  if ($woj_in_crm):
  ?>
  <select name="wojewodztwo" class="form-select" style="width:auto;min-width:150px"
          aria-label="Filtruj po województwie">
    <option value="">Wszystkie woj.</option>
    <?php foreach ($woj_in_crm as $w): ?>
    <option value="<?= h($w['wojewodztwo']) ?>"
            <?= $filters['wojewodztwo'] === $w['wojewodztwo'] ? 'selected' : '' ?>>
      <?= h(ucfirst($w['wojewodztwo'])) ?>
    </option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <?php if ($crm_actions): ?>
  <select name="action_id" class="form-select" style="width:auto;min-width:160px"
          aria-label="Filtruj po działaniu">
    <option value="">Wszystkie działania</option>
    <?php foreach ($crm_actions as $a): ?>
    <option value="<?= (int)$a['id'] ?>"
            <?= (int)$filters['action_id'] === (int)$a['id'] ? 'selected' : '' ?>>
      <?= h($a['nazwa']) ?>
    </option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <?php if ($all_groups): ?>
  <select name="group" class="form-select" style="width:auto;min-width:140px"
          aria-label="Filtruj po grupie">
    <option value="">Wszystkie grupy</option>
    <?php foreach ($all_groups as $g): ?>
    <option value="<?= (int)$g['id'] ?>"
            <?= (int)$filters['group'] === (int)$g['id'] ? 'selected' : '' ?>>
      <?= h($g['name']) ?> (<?= (int)$g['member_count'] ?>)
    </option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <button type="submit" class="btn btn-crm-primary btn-sm">
    <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtruj
  </button>

  <?php
  // Czy aktywny jest którykolwiek z filtrów zaawansowanych?
  $adv_keys   = ['q_all','source','branza','powiat','gmina','created_from','created_to','last_from','last_to','stale_days','has_email','has_phone'];
  $adv_active = (bool)array_filter(array_intersect_key($filters, array_flip($adv_keys)));
  ?>
  <button type="button" class="btn btn-crm-outline btn-sm" id="crm-adv-toggle"
          aria-expanded="<?= $adv_active ? 'true' : 'false' ?>" aria-controls="crm-adv-panel">
    <i class="bi bi-sliders me-1" aria-hidden="true"></i>Zaawansowane<?= $adv_active ? ' •' : '' ?>
  </button>

  <?php $active_filters = count(array_filter($filters)); if ($active_filters): ?>
  <a href="<?= APP_URL ?>/crm/index.php"
     class="btn btn-outline-danger btn-sm"
     aria-label="Wyczyść wszystkie filtry (aktywne: <?= $active_filters ?>)"
     title="Wyczyść wszystkie aktywne filtry">
    <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Wyczyść filtry
    <span class="badge bg-danger ms-1"><?= $active_filters ?></span>
  </a>
  <?php endif; ?>

  <?php
  $crm_sources = db_all("SELECT DISTINCT source FROM crm_contacts
                          WHERE crm_active=1 AND source IS NOT NULL AND source != ''
                          ORDER BY source");
  ?>
  <!-- ── Panel wyszukiwania zaawansowanego ─────────────────────────────── -->
  <div id="crm-adv-panel" class="crm-adv-panel<?= $adv_active ? '' : ' d-none' ?>"
       role="region" aria-label="Wyszukiwanie zaawansowane">
    <div class="crm-adv-grid">
      <label class="crm-adv-field crm-adv-wide">
        <span>Szukaj we wszystkich polach</span>
        <input type="text" name="q_all" value="<?= h($filters['q_all']) ?>"
               class="form-control form-control-sm"
               placeholder="NIP, REGON, KRS, PESEL, branża, adres, notatka…" autocomplete="off">
      </label>

      <label class="crm-adv-field">
        <span>Branża</span>
        <input type="text" name="branza" value="<?= h($filters['branza']) ?>"
               class="form-control form-control-sm" autocomplete="off">
      </label>

      <?php if ($crm_sources): ?>
      <label class="crm-adv-field">
        <span>Źródło</span>
        <select name="source" class="form-select form-select-sm">
          <option value="">Dowolne</option>
          <?php foreach ($crm_sources as $s): ?>
          <option value="<?= h($s['source']) ?>" <?= $filters['source'] === $s['source'] ? 'selected' : '' ?>>
            <?= h(ucfirst($s['source'])) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>

      <label class="crm-adv-field">
        <span>Powiat</span>
        <input type="text" name="powiat" value="<?= h($filters['powiat']) ?>"
               class="form-control form-control-sm" autocomplete="off">
      </label>

      <label class="crm-adv-field">
        <span>Gmina</span>
        <input type="text" name="gmina" value="<?= h($filters['gmina']) ?>"
               class="form-control form-control-sm" autocomplete="off">
      </label>

      <label class="crm-adv-field">
        <span>Dodano od</span>
        <input type="date" name="created_from" value="<?= h($filters['created_from']) ?>"
               class="form-control form-control-sm">
      </label>
      <label class="crm-adv-field">
        <span>Dodano do</span>
        <input type="date" name="created_to" value="<?= h($filters['created_to']) ?>"
               class="form-control form-control-sm">
      </label>

      <label class="crm-adv-field">
        <span>Kontakt od</span>
        <input type="date" name="last_from" value="<?= h($filters['last_from']) ?>"
               class="form-control form-control-sm">
      </label>
      <label class="crm-adv-field">
        <span>Kontakt do</span>
        <input type="date" name="last_to" value="<?= h($filters['last_to']) ?>"
               class="form-control form-control-sm">
      </label>

      <label class="crm-adv-field">
        <span>Bez kontaktu od (dni)</span>
        <input type="number" name="stale_days" min="1" step="1"
               value="<?= h($filters['stale_days']) ?>"
               class="form-control form-control-sm" placeholder="np. 30">
      </label>

      <div class="crm-adv-field crm-adv-checks">
        <label class="form-check form-check-inline mb-0">
          <input class="form-check-input" type="checkbox" name="has_email" value="1"
                 <?= $filters['has_email'] ? 'checked' : '' ?>>
          <span class="form-check-label">Ma e-mail</span>
        </label>
        <label class="form-check form-check-inline mb-0">
          <input class="form-check-input" type="checkbox" name="has_phone" value="1"
                 <?= $filters['has_phone'] ? 'checked' : '' ?>>
          <span class="form-check-label">Ma telefon</span>
        </label>
      </div>
    </div>
    <div class="crm-adv-actions">
      <button type="submit" class="btn btn-crm-primary btn-sm">
        <i class="bi bi-search me-1" aria-hidden="true"></i>Szukaj
      </button>
      <button type="button" class="btn btn-link btn-sm text-muted" id="crm-adv-clear">
        Wyczyść zaawansowane
      </button>
    </div>
  </div>

</form>

<!-- Filtry Tagi / Grupy — kompaktowe rozwijane (zamiast długich rzędów chipów) -->
<?php if ($all_tags || $all_groups): ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2" aria-label="Filtry tagów i grup">

  <?php if ($all_tags): $_tag_on = ($filters['tag'] ?? '') !== ''; ?>
  <div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary dropdown-toggle<?= $_tag_on ? ' active' : '' ?>"
            type="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-tags me-1"></i>Tagi<?= $_tag_on ? ': ' . h($filters['tag']) : '' ?>
      <span class="badge bg-secondary ms-1"><?= count($all_tags) ?></span>
    </button>
    <ul class="dropdown-menu" style="max-height:340px;overflow:auto;min-width:248px">
      <?php if ($_tag_on): ?>
      <li><a class="dropdown-item text-muted" href="<?= h(APP_URL.'/crm/index.php?'.http_build_query(array_filter(array_merge($filters,['tag'=>'','page'=>1])))) ?>"><i class="bi bi-x-circle me-2"></i>Wyczyść filtr</a></li>
      <li><hr class="dropdown-divider"></li>
      <?php endif; ?>
      <?php foreach ($all_tags as $t):
        $active = ($filters['tag'] ?? '') === $t['tag'];
        $chip_url = APP_URL . '/crm/index.php?' . http_build_query(array_filter(array_merge($filters, ['tag' => $active ? '' : $t['tag'], 'page' => 1])));
      ?>
      <li><a class="dropdown-item d-flex justify-content-between align-items-center<?= $active ? ' active' : '' ?>" href="<?= h($chip_url) ?>">
        <span><?= h($t['tag']) ?></span>
        <span class="badge bg-light text-muted border ms-2"><?= (int)$t['cnt'] ?></span>
      </a></li>
      <?php endforeach; ?>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item small text-muted" href="<?= APP_URL ?>/crm/tags.php"><i class="bi bi-gear me-2"></i>Zarządzaj tagami</a></li>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($all_groups): $_grp_on = (int)($filters['group'] ?? 0) > 0; ?>
  <div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary dropdown-toggle<?= $_grp_on ? ' active' : '' ?>"
            type="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-collection me-1"></i>Grupy<?php
        if ($_grp_on) { foreach ($all_groups as $g) { if ((int)$g['id'] === (int)$filters['group']) { echo ': ' . h($g['name']); break; } } }
      ?>
      <span class="badge bg-secondary ms-1"><?= count($all_groups) ?></span>
    </button>
    <ul class="dropdown-menu" style="max-height:340px;overflow:auto;min-width:248px">
      <?php if ($_grp_on): ?>
      <li><a class="dropdown-item text-muted" href="<?= h(APP_URL.'/crm/index.php?'.http_build_query(array_filter(array_merge($filters,['group'=>'','page'=>1])))) ?>"><i class="bi bi-x-circle me-2"></i>Wyczyść filtr</a></li>
      <li><hr class="dropdown-divider"></li>
      <?php endif; ?>
      <?php foreach ($all_groups as $g):
        $gactive  = (int)($filters['group'] ?? 0) === (int)$g['id'];
        $gchip_url = APP_URL . '/crm/index.php?' . http_build_query(array_filter(array_merge($filters, ['group' => $gactive ? '' : $g['id'], 'page' => 1])));
      ?>
      <li><a class="dropdown-item d-flex justify-content-between align-items-center<?= $gactive ? ' active' : '' ?>" href="<?= h($gchip_url) ?>">
        <span><i class="bi <?= h($g['icon']) ?> me-2" style="color:<?= h($g['color']) ?>"></i><?= h($g['name']) ?></span>
        <span class="badge bg-light text-muted border ms-2"><?= (int)$g['member_count'] ?></span>
      </a></li>
      <?php endforeach; ?>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item small text-muted" href="<?= APP_URL ?>/crm/groups.php"><i class="bi bi-gear me-2"></i>Zarządzaj grupami</a></li>
    </ul>
  </div>
  <?php endif; ?>

</div>
<?php endif; ?>

<!-- ══ LISTA — region AJAX ════════════════════════════════════════════════════ -->
<!-- Ukryty live region — ogłoszenia dla czytników ekranu -->
<div id="crm-live" role="status" aria-live="polite" aria-atomic="true"
     class="visually-hidden"></div>

<div id="crm-list-region" aria-label="Wyniki listy kontaktów">
  <?= _crm_table_html($rows, $total, $paging, $per_page, $filters, $crm_can_write, $crm_can_delete) ?>
</div>

<!-- ══ HEARTBEAT ══════════════════════════════════════════════════════════════ -->
<div id="crmHeartbeatData"
     data-token="<?= h(org_setting('crm_sync_token')) ?>"
     data-endpoint="<?= h(APP_URL) ?>/crm/api/heartbeat.php"
     aria-hidden="true" style="display:none"></div>

<script>
(function () {
  'use strict';
  var hb = document.getElementById('crmHeartbeatData');
  var dot = document.getElementById('crmSyncDot');
  var lbl = document.getElementById('crmSyncText');
  if (!hb) return;
  var TOKEN = hb.dataset.token, ENDPOINT = hb.dataset.endpoint, INTERVAL = 50000;
  var STORE_KEY = 'crm_last_sync_' + TOKEN.slice(0, 8);
  function getLastSync() { return parseInt(localStorage.getItem(STORE_KEY) || '0', 10); }
  function setLastSync(ts) { localStorage.setItem(STORE_KEY, String(ts)); }
  function fmt(d) {
    return d.getHours().toString().padStart(2, '0') + ':' +
           d.getMinutes().toString().padStart(2, '0') + ':' +
           d.getSeconds().toString().padStart(2, '0');
  }
  function setStatus(st, msg) {
    if (dot) dot.style.background = st === 'ok' ? 'var(--crm-primary)' : st === 'busy' ? '#FE9339' : '#EA001E';
    if (lbl) lbl.textContent = msg;
  }
  function doSync() {
    setStatus('busy', 'Synchronizacja…');
    var fd = new FormData();
    fd.append('token', TOKEN);
    fd.append('since', String(getLastSync()));
    fetch(ENDPOINT, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) {
          setLastSync(d.server_ts);
          var upd = d.count > 0 ? ' (+' + d.count + ')' : '';
          setStatus('ok', 'Zsync. ' + fmt(new Date()) + upd);
          if (d.count > 0 && window._CrmList) {
            window._CrmList.refresh(); // odśwież listę po wykryciu zmian
          }
        } else {
          setStatus('error', 'Błąd synchronizacji');
        }
      })
      .catch(function () { setStatus('error', 'Brak połączenia'); });
  }
  setTimeout(doSync, 2000);
  setInterval(doSync, INTERVAL);
})();
</script>

<!-- ══ BULK ACTIONS ══════════════════════════════════════════════════════════ -->
<div id="bulk-bar"
     style="display:none;position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);
            z-index:1060;background:#1e293b;color:#fff;border-radius:12px;
            padding:.65rem 1.25rem;box-shadow:0 8px 32px rgba(0,0,0,.28);
            align-items:center;gap:.75rem;flex-wrap:wrap;min-width:340px"
     role="toolbar" aria-label="Akcje dla zaznaczonych kontaktów">
  <span id="bulk-count" class="fw-semibold"
        style="font-size:.85rem;white-space:nowrap" aria-live="polite">0 zaznaczonych</span>

  <div class="dropdown">
    <button class="btn btn-sm btn-outline-light" data-bs-toggle="dropdown"
            aria-haspopup="true" aria-expanded="false">
      <i class="bi bi-bookmark me-1" aria-hidden="true"></i>Status
    </button>
    <ul class="dropdown-menu dropdown-menu-dark">
      <?php foreach (crm_statuses() as $sk => $sv): ?>
      <li>
        <a class="dropdown-item" href="#"
           onclick="Bulk.do('set_status','<?= h($sk) ?>');return false">
          <span aria-hidden="true"
                style="display:inline-block;width:10px;height:10px;border-radius:50%;
                       background:<?= h($sv['color']) ?>;margin-right:.4rem;vertical-align:middle"></span>
          <?= h($sv['label']) ?>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="d-flex gap-1">
    <input type="text" id="bulk-tag-inp" placeholder="Tag…"
           class="form-control form-control-sm"
           aria-label="Wpisz tag i naciśnij Enter"
           style="width:110px;background:#334155;border-color:#475569;color:#fff"
           onkeydown="if(event.key==='Enter'){Bulk.do('add_tag',this.value);this.value='';}">
    <button class="btn btn-sm btn-outline-light" aria-label="Dodaj tag do zaznaczonych"
            onclick="Bulk.do('add_tag',document.getElementById('bulk-tag-inp').value);document.getElementById('bulk-tag-inp').value=''">
      <i class="bi bi-tag" aria-hidden="true"></i>
    </button>
  </div>

  <?php if ($all_groups): ?>
  <div class="dropdown">
    <button class="btn btn-sm btn-outline-light" data-bs-toggle="dropdown"
            aria-haspopup="true" aria-expanded="false">
      <i class="bi bi-people me-1" aria-hidden="true"></i>Grupa
    </button>
    <ul class="dropdown-menu dropdown-menu-dark" style="max-height:220px;overflow-y:auto">
      <?php foreach ($all_groups as $g): ?>
      <li>
        <a class="dropdown-item" href="#"
           onclick="Bulk.do('add_to_group',<?= (int)$g['id'] ?>);return false">
          <i class="bi <?= h($g['icon']) ?>" aria-hidden="true"
             style="color:<?= h($g['color']) ?>"></i>
          <?= h($g['name']) ?>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div class="dropdown">
    <button class="btn btn-sm btn-outline-light" data-bs-toggle="dropdown"
            aria-haspopup="true" aria-expanded="false">
      <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Typ
    </button>
    <ul class="dropdown-menu dropdown-menu-dark">
      <li>
        <a class="dropdown-item" href="#"
           onclick="Bulk.do('convert_type','organizacja');return false">
          <i class="bi bi-building me-1" aria-hidden="true"></i>Na organizację
        </a>
      </li>
      <li>
        <a class="dropdown-item" href="#"
           onclick="Bulk.do('convert_type','osoba');return false">
          <i class="bi bi-person me-1" aria-hidden="true"></i>Na osobę
        </a>
      </li>
    </ul>
  </div>

  <?php if ($crm_can_delete): ?>
  <button class="btn btn-sm btn-outline-danger" aria-label="Usuń zaznaczone kontakty"
          onclick="Bulk.do('delete',null)">
    <i class="bi bi-trash3" aria-hidden="true"></i>
  </button>
  <?php endif; ?>

  <button class="btn btn-sm btn-link text-light p-0 ms-1"
          onclick="Bulk.clear()" aria-label="Odznacz wszystkie">
    <i class="bi bi-x-lg" aria-hidden="true"></i>
  </button>
</div>

<script>
/* ── Bulk actions ─────────────────────────────────────────────────────────── */
const Bulk = (function () {
  const bar   = document.getElementById('bulk-bar');
  const count = document.getElementById('bulk-count');
  const API   = '<?= APP_URL ?>/crm/api/bulk.php';
  const CSRF  = '<?= csrf_token() ?>';

  function getChecked() {
    return [...document.querySelectorAll('.crm-row-chk:checked')].map(c => parseInt(c.value));
  }

  function update() {
    const ids   = getChecked();
    const n     = ids.length;
    bar.style.display = n ? 'flex' : 'none';
    count.textContent = n + ' zaznaczon' + (n === 1 ? 'y' : 'ych');
    const all = document.getElementById('chk-all');
    if (all) {
      const total = document.querySelectorAll('.crm-row-chk').length;
      all.indeterminate = n > 0 && n < total;
      all.checked = n === total && n > 0;
    }
  }

  function toggleAll(checked) {
    document.querySelectorAll('.crm-row-chk').forEach(c => c.checked = checked);
    update();
  }

  function clear() {
    document.querySelectorAll('.crm-row-chk, #chk-all').forEach(c => c.checked = false);
    update();
  }

  async function doAction(action, value) {
    const ids = getChecked();
    if (!ids.length) return;
    if (action === 'delete' && !confirm('Usunąć ' + ids.length + ' kontaktów? Operacja jest odwracalna przez administratora.')) return;
    if (action === 'add_tag' && !value?.trim()) return;
    if (action === 'convert_type') {
      const label = value === 'organizacja' ? 'organizację' : 'osobę';
      const wiped = value === 'organizacja'
        ? 'dane osobowe (imię, nazwisko, PESEL, data urodzenia)'
        : 'dane rejestrowe (NIP, KRS, REGON, osoba kontaktowa, forma prawna)';
      if (!confirm('Zmienić typ ' + ids.length + ' kontaktów na ' + label + '?\n\nUWAGA: ' + wiped + ' zostaną trwale wyczyszczone dla konwertowanych rekordów.')) return;
    }

    const res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, ids, value: value ?? '', _csrf: CSRF }),
    }).then(r => r.json());

    if (res.ok) {
      const msgs = {
        set_status:   `Zmieniono status dla ${res.affected} kontaktów.`,
        add_tag:      `Dodano tag do ${res.affected} kontaktów.`,
        remove_tag:   `Usunięto tag z ${res.affected} kontaktów.`,
        add_to_group: `Dodano ${res.affected} kontaktów do grupy.`,
        convert_type: `Zmieniono typ ${res.affected} kontaktów.`,
        delete:       `Usunięto ${res.affected} kontaktów.`,
      };
      const url = new URL(location.href);
      url.searchParams.set('_bulk_ok', msgs[action] || 'Operacja wykonana.');
      location.replace(url.toString());
    } else {
      alert('Błąd: ' + (res.error || 'Nieznany błąd'));
    }
  }

  // Flash po bulk action
  const bp = new URLSearchParams(location.search).get('_bulk_ok');
  if (bp) {
    const div = document.createElement('div');
    div.className = 'alert alert-success alert-dismissible py-2 px-3 mb-2';
    div.setAttribute('role', 'status');
    div.innerHTML = `<i class="bi bi-check-circle me-1" aria-hidden="true"></i>${bp}`
      + `<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Zamknij"></button>`;
    document.querySelector('.crm-object-header')?.insertAdjacentElement('afterend', div);
    const clean = new URL(location.href);
    clean.searchParams.delete('_bulk_ok');
    history.replaceState({}, '', clean.toString());
  }

  return { update, toggleAll, clear, do: doAction };
})();
</script>

<script>
/* ── AJAX filter + pagination + szybkie akcje ─────────────────────────────── */
(function () {
  'use strict';

  var region  = document.getElementById('crm-list-region');
  var form    = document.getElementById('crm-filter-form');
  var countEl = document.getElementById('crm-total-count');
  var liveEl  = document.getElementById('crm-live');

  if (!region || !form) return;

  var abort    = null;
  var debounce = null;

  /* ── Ładuje fragment listy ─────────────────────── */
  function load(params, push) {
    if (abort) { try { abort.abort(); } catch (e) {} }
    abort = (typeof AbortController !== 'undefined') ? new AbortController() : null;

    region.setAttribute('aria-busy', 'true');
    region.style.opacity = '0.5';
    region.style.transition = 'opacity .15s';
    region.style.pointerEvents = 'none';

    var url = new URL(window.location.pathname, window.location.origin);
    params.forEach(function (v, k) { if (v) url.searchParams.set(k, v); });
    url.searchParams.set('_ajax', '1');

    var opts = abort ? { signal: abort.signal } : {};

    fetch(url.toString(), opts)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) return;

        region.innerHTML = data.list_html;

        if (countEl && data.total !== undefined) {
          var t = data.total;
          countEl.textContent = t + ' ' + (t === 1 ? 'rekord' : t < 5 ? 'rekordy' : 'rekordów');
        }

        if (push !== false) {
          var histUrl = new URL(window.location.href);
          histUrl.search = params.toString();
          history.pushState({ crm: params.toString() }, '', histUrl.toString());
        }

        region.removeAttribute('aria-busy');
        region.style.opacity = '1';
        region.style.pointerEvents = '';

        bindRegion();
        Bulk.clear();

        if (liveEl) {
          liveEl.textContent = '';
          setTimeout(function () {
            var t = data.total || 0;
            liveEl.textContent = 'Załadowano ' + t + ' ' + (t === 1 ? 'kontakt' : 'kontaktów') + '.';
          }, 50);
        }
      })
      .catch(function (e) {
        if (e && e.name === 'AbortError') return;
        region.removeAttribute('aria-busy');
        region.style.opacity = '1';
        region.style.pointerEvents = '';
      });
  }

  /* ── Parametry z formularza filtrów ─────────────── */
  function formParams() {
    var p = new URLSearchParams();
    new FormData(form).forEach(function (v, k) { if (v) p.set(k, v); });
    return p;
  }

  /* ── Synchronizuje pola formularza z URLSearchParams ── */
  function syncForm(params) {
    form.querySelectorAll('select, input[type="text"], input[type="date"], input[type="number"], input[type="search"]').forEach(function (el) {
      if (el.name) el.value = params.get(el.name) || '';
    });
    form.querySelectorAll('input[type="checkbox"]').forEach(function (el) {
      if (el.name) el.checked = params.get(el.name) === el.value;
    });
  }

  /* ── Binduje zdarzenia w dynamicznie ładowanym regionie ─ */
  function bindRegion() {
    // Paginacja
    region.querySelectorAll('a.page-link').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var href = a.getAttribute('href');
        if (!href) return;
        var p = new URLSearchParams(new URL(href, window.location.href).search);
        load(p, true);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });

    // Zmiana statusu (AJAX)
    region.querySelectorAll('form[data-ajax-action="quick_status"]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(f);
        fetch(window.location.pathname, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: fd,
        })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (d.ok) load(formParams(), false); })
          .catch(function () {});
      });
    });

    // Usunięcie kontaktu (AJAX)
    region.querySelectorAll('form[data-ajax-action="delete"]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        e.preventDefault();
        var name = f.dataset.contactName || 'kontakt';
        if (!confirm('Usunąć kontakt ' + name + '?')) return;
        var fd = new FormData(f);
        fetch(window.location.pathname, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: fd,
        })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (d.ok) load(formParams(), false); })
          .catch(function () {});
      });
    });

    // Kliknięcie na wierszu → otwórz kartę kontaktu
    region.querySelectorAll('tr[data-row-href]').forEach(function (tr) {
      tr.style.cursor = 'pointer';
      tr.addEventListener('click', function (e) {
        if (e.target.closest('a,button,input,label,[data-copy]')) return;
        window.location.href = tr.dataset.rowHref;
      });
    });

    // Checkboxy bulk
    region.querySelectorAll('.crm-row-chk').forEach(function (chk) {
      chk.addEventListener('change', function () { Bulk.update(); });
    });
  }

  /* ── Submit formularza ───────────────────────────── */
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    load(formParams(), true);
  });

  /* ── Select → auto-załaduj ───────────────────────── */
  form.querySelectorAll('select').forEach(function (sel) {
    sel.addEventListener('change', function () { load(formParams(), true); });
  });

  /* ── Szukaj — debounce 380 ms ────────────────────── */
  var searchInp = form.querySelector('input[name="q"]');
  if (searchInp) {
    searchInp.addEventListener('input', function () {
      clearTimeout(debounce);
      debounce = setTimeout(function () { load(formParams(), true); }, 380);
    });
    searchInp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(debounce);
        load(formParams(), true);
      }
    });
  }

  /* ── Wyszukiwanie zaawansowane: toggle / clear / auto-load ── */
  var advToggle = document.getElementById('crm-adv-toggle');
  var advPanel  = document.getElementById('crm-adv-panel');
  if (advToggle && advPanel) {
    advToggle.addEventListener('click', function () {
      var open = advPanel.classList.toggle('d-none');
      advToggle.setAttribute('aria-expanded', open ? 'false' : 'true');
      if (!open) {
        var first = advPanel.querySelector('input, select');
        if (first) first.focus();
      }
    });
  }
  if (advPanel) {
    // Auto-załaduj po zmianie pól dat/liczb/checkboxów
    advPanel.querySelectorAll('input[type="date"], input[type="number"], input[type="checkbox"]').forEach(function (el) {
      el.addEventListener('change', function () { load(formParams(), true); });
    });
    // Debounce dla pól tekstowych panelu
    advPanel.querySelectorAll('input[type="text"]').forEach(function (el) {
      el.addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(function () { load(formParams(), true); }, 380);
      });
    });
    var advClear = document.getElementById('crm-adv-clear');
    if (advClear) {
      advClear.addEventListener('click', function () {
        advPanel.querySelectorAll('input, select').forEach(function (el) {
          if (el.type === 'checkbox') el.checked = false; else el.value = '';
        });
        load(formParams(), true);
      });
    }
  }

  /* ── Modal eksportu: zaznacz/odznacz kolumny ─────── */
  (function () {
    var box = document.getElementById('crmExportCols');
    if (!box) return;
    var all  = document.getElementById('crmExportAll');
    var none = document.getElementById('crmExportNone');
    if (all)  all.addEventListener('click',  function () { box.querySelectorAll('input[type="checkbox"]').forEach(function (c) { c.checked = true; }); });
    if (none) none.addEventListener('click', function () { box.querySelectorAll('input[type="checkbox"]').forEach(function (c) { c.checked = false; }); });
    var f = document.getElementById('crmExportForm');
    if (f) f.addEventListener('submit', function (e) {
      if (!box.querySelector('input[type="checkbox"]:checked')) {
        e.preventDefault();
        alert('Zaznacz przynajmniej jedną kolumnę do eksportu.');
      }
    });
  })();

  /* ── Chipy tagów i grup ─────────────────────────── */
  document.querySelectorAll('.crm-filter-chips .crm-chip').forEach(function (chip) {
    chip.addEventListener('click', function (e) {
      e.preventDefault();
      var href = chip.getAttribute('href');
      if (!href) return;
      var p = new URLSearchParams(new URL(href, window.location.href).search);
      syncForm(p);
      // Odśwież stan wizualny chipów
      var parent = chip.closest('.crm-filter-chips');
      if (parent) {
        var wasActive = chip.classList.contains('active');
        parent.querySelectorAll('.crm-chip').forEach(function (c) {
          c.classList.remove('active');
          c.setAttribute('aria-pressed', 'false');
        });
        if (!wasActive) {
          chip.classList.add('active');
          chip.setAttribute('aria-pressed', 'true');
        }
      }
      load(p, true);
    });
  });

  /* ── Przeglądarka: wstecz / dalej ───────────────── */
  window.addEventListener('popstate', function (e) {
    var p = (e.state && e.state.crm !== undefined)
      ? new URLSearchParams(e.state.crm)
      : new URLSearchParams(window.location.search);
    syncForm(p);
    load(p, false);
  });

  /* ── Inicjalizacja + eksport dla heartbeatu ─────── */
  bindRegion();
  window._CrmList = {
    load: function (p) { load(p || formParams(), false); },
    refresh: function () { load(formParams(), false); },
  };
})();
</script>

<script>
/* ── Quick note + Historia komunikacji ────────────────────────────────────── */
var CRM_CSRF = <?= json_encode(csrf_token()) ?>;
var CRM_URL  = <?= json_encode(APP_URL . '/crm/index.php') ?>;

// Quick note popover
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.crm-quick-note-btn');
    if (!btn) return;
    var cid  = btn.dataset.id;
    var name = btn.dataset.name;
    var existing = document.getElementById('crmNotePopover');
    if (existing) existing.remove();
    var pop = document.createElement('div');
    pop.id = 'crmNotePopover';
    pop.style.cssText = 'position:fixed;z-index:9999;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 4px 24px rgba(0,0,0,.15);padding:1rem;width:300px;font-family:system-ui,sans-serif;font-size:.85rem';
    var rect = btn.getBoundingClientRect();
    pop.style.top  = (rect.bottom + window.scrollY + 6) + 'px';
    pop.style.left = Math.min(rect.left, window.innerWidth - 320) + 'px';
    pop.innerHTML = '<div style="font-weight:700;margin-bottom:.5rem">&#128221; Notatka: ' + name + '</div>'
      + '<textarea id="crmNoteTA" rows="3" style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:.4rem;font-size:.84rem;resize:vertical" placeholder="Treść notatki…"></textarea>'
      + '<div style="display:flex;gap:.4rem;justify-content:flex-end;margin-top:.5rem">'
      + '<button onclick="document.getElementById(\'crmNotePopover\').remove()" style="background:#f1f5f9;border:1px solid #e2e8f0;padding:.3rem .7rem;border-radius:6px;cursor:pointer">Anuluj</button>'
      + '<button onclick="crmSaveNote(' + cid + ')" style="background:#1d4ed8;color:#fff;border:none;padding:.3rem .7rem;border-radius:6px;cursor:pointer;font-weight:600">Zapisz</button>'
      + '</div><div id="crmNoteMsg" style="font-size:.78rem;margin-top:.3rem"></div>';
    document.body.appendChild(pop);
    document.getElementById('crmNoteTA').focus();
    e.stopPropagation();
});
document.addEventListener('click', function(e) {
    var pop = document.getElementById('crmNotePopover');
    if (pop && !pop.contains(e.target) && !e.target.closest('.crm-quick-note-btn')) pop.remove();
});
function crmSaveNote(cid) {
    var body = document.getElementById('crmNoteTA').value.trim();
    if (!body) { document.getElementById('crmNoteMsg').textContent = 'Wpisz treść.'; return; }
    var fd = new FormData();
    fd.append('_csrf', CRM_CSRF);
    fd.append('_action', 'quick_note');
    fd.append('contact_id', cid);
    fd.append('body', body);
    fetch(CRM_URL, {method:'POST', body: fd})
      .then(function(r){ return r.json(); })
      .then(function(d){
        document.getElementById('crmNoteMsg').textContent = d.msg || (d.ok ? 'OK' : 'Błąd');
        if (d.ok) setTimeout(function(){ var p = document.getElementById('crmNotePopover'); if(p) p.remove(); }, 900);
      });
}

// Historia komunikacji — expand row
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.crm-comms-btn');
    if (!btn) return;
    var cid = btn.dataset.id;
    var tr  = btn.closest('tr');
    var next = tr.nextElementSibling;
    if (next && next.classList.contains('crm-comms-expand')) {
        next.remove(); return;
    }
    var expandTr = document.createElement('tr');
    expandTr.className = 'crm-comms-expand';
    var colspan = tr.children.length;
    expandTr.innerHTML = '<td colspan="' + colspan + '" style="background:#f8fafc;padding:.6rem 1rem"><div class="text-muted small">⏳ Ładowanie…</div></td>';
    tr.insertAdjacentElement('afterend', expandTr);
    fetch(CRM_URL.replace('index.php', 'api/contact_comms.php') + '?contact_id=' + cid)
      .then(function(r){ return r.json(); })
      .then(function(d){ if(d.ok) expandTr.querySelector('td').innerHTML = d.html; });
});
</script>

<script>
// ── Skróty klawiszowe CRM ──────────────────────────────────────────────────
//   /  → fokus na pole szukania
//   n  → Dodaj osobę (dla uprawnionych)
(function(){
  function isTyping(el){
    if (!el) return false;
    var t = el.tagName;
    return t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT' || el.isContentEditable;
  }
  document.addEventListener('keydown', function(e){
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    if (isTyping(document.activeElement)) return;
    if (e.key === '/') {
      var s = document.getElementById('crm-search-input');
      if (s) { e.preventDefault(); s.focus(); s.select(); }
    } else if (e.key === 'n' || e.key === 'N') {
      <?php if (can_write('crm') || is_admin()): ?>
      e.preventDefault();
      window.location.href = '<?= APP_URL ?>/crm/contact/add_person.php';
      <?php endif; ?>
    }
  });
})();
</script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
