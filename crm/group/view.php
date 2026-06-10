<?php
/**
 * crm/group/view.php — Szczegółowy widok grupy kontaktów.
 *
 * Wyświetla listę członków, pozwala dodawać i usuwać kontakty.
 * Link do wysyłania wiadomości do całej grupy.
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$id    = (int)($_GET['id'] ?? 0);

// Uprawnienia uwzględniają dostęp per-grupowy
$crm_can_write  = crm_group_can($id, 'write');
$crm_can_delete = crm_group_can($id, 'delete');
$group = CrmManager::getGroup($id);

if (!$group) {
    flash_set('danger', 'Grupa nie istnieje.');
    header('Location: ' . APP_URL . '/crm/groups.php');
    exit;
}

// Sprawdź dostęp do grupy dla użytkowników z ograniczeniami
$_accessible_ids = crm_accessible_group_ids();
if ($_accessible_ids !== null && !in_array($id, $_accessible_ids, true)) {
    flash_set('danger', 'Brak dostępu do tej grupy.');
    header('Location: ' . APP_URL . '/crm/groups.php');
    exit;
}

$is_vol_group = in_array($group['auto_source'] ?? '', ['wolontariusze', 'byli_wolontariusze']);
$PAGE_TITLE = 'CRM — Grupa: ' . $group['name'];

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $crm_can_write) {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'add_member') {
        $contact_id = (int)($_POST['contact_id'] ?? 0);
        if ($contact_id > 0) {
            CrmManager::addToGroup($id, $contact_id);
            $c = db_one("SELECT imie_nazwisko FROM crm_contacts WHERE id=?", [$contact_id]);
            flash_set('success', ($c['imie_nazwisko'] ?? 'Kontakt') . ' dodany do grupy.');
        }
    }

    if ($action === 'add_members_bulk') {
        $ids = array_filter(array_map('intval', explode(',', $_POST['contact_ids'] ?? '')));
        $added = 0;
        foreach ($ids as $cid) {
            if ($cid > 0) { CrmManager::addToGroup($id, $cid); $added++; }
        }
        if ($added) flash_set('success', 'Dodano ' . $added . ' kontaktów do grupy.');
    }

    if ($action === 'remove_member') {
        $contact_id = (int)($_POST['contact_id'] ?? 0);
        if ($contact_id > 0) {
            CrmManager::removeFromGroup($id, $contact_id);
            flash_set('success', 'Kontakt usunięty z grupy.');
        }
    }

    if ($action === 'remove_all' && $crm_can_delete) {
        db()->prepare("DELETE FROM crm_group_members WHERE group_id=?")->execute([$id]);
        flash_set('success', 'Wszyscy członkowie usunięci z grupy.');
    }

    // Zarządzanie dostępem użytkowników (tylko admin)
    if (is_admin()) {
        if ($action === 'grant_user') {
            $uid = (int)($_POST['grant_user_id'] ?? 0);
            if ($uid > 0) {
                CrmManager::setGroupUser($id, $uid,
                    isset($_POST['grant_write'])  ? 1 : 0,
                    isset($_POST['grant_delete']) ? 1 : 0
                );
                flash_set('success', 'Dostęp użytkownika zaktualizowany.');
            }
        }
        if ($action === 'revoke_user') {
            $uid = (int)($_POST['revoke_user_id'] ?? 0);
            if ($uid > 0) {
                CrmManager::removeGroupUser($id, $uid);
                flash_set('success', 'Dostęp użytkownika cofnięty.');
            }
        }
    }

    // Synchronizacja grupy wolontariuszy (admin only)
    if ($action === 'sync_volunteers' && is_admin() && $is_vol_group) {
        $result = SyncService::syncVolunteerGroup();
        flash_set('success', sprintf(
            'Synchronizacja: +%d/−%d w Wolontariusze; +%d/−%d w Byli wolontariusze.',
            $result['added'], $result['removed'],
            $result['former_added'] ?? 0, $result['former_removed'] ?? 0
        ));
    }

    // Import wolontariuszy do grupy wg kryteriów umowy
    if ($action === 'import_by_criteria' && $crm_can_write && $is_vol_group) {
        $crit_status = trim($_POST['crit_status'] ?? '');
        $crit_action = (int)($_POST['crit_action'] ?? 0);
        if ($crit_status || $crit_action) {
            $wh = ["w.email IS NOT NULL AND TRIM(w.email) != ''"]; $wp = [];
            if ($crit_status) { $wh[] = 'w.status = ?'; $wp[] = $crit_status; }
            if ($crit_action) { $wh[] = 'w.action_id = ?'; $wp[] = $crit_action; }
            $rows = db_all(
                "SELECT DISTINCT LOWER(TRIM(w.email)) AS email FROM umowy_wolontariat w WHERE " . implode(' AND ', $wh),
                $wp
            );
            $imported = 0;
            foreach ($rows as $r) {
                $c = db_one("SELECT id FROM crm_contacts WHERE LOWER(email)=? AND crm_active=1 LIMIT 1", [$r['email']]);
                if ($c) { CrmManager::addToGroup($id, (int)$c['id']); $imported++; }
            }
            flash_set('success', "Dodano $imported wolontariuszy do grupy.");
        } else {
            flash_set('warning', 'Wybierz przynajmniej jeden filtr (status lub działanie).');
        }
    }

    header('Location: ' . APP_URL . '/crm/group/view.php?id=' . $id);
    exit;
}

// Przeładuj
$group = CrmManager::getGroup($id);

// Dane umów wolontariackich — ładowane tylko dla grup wolontariuszy
$vol_contracts_map  = [];
$vol_filter_actions = [];
$import_actions     = [];
$vol_all_statuses   = ['projekt', 'podpisana', 'w realizacji', 'obowiązująca', 'zakończona', 'rozwiązana', 'anulowana'];
$vol_status_labels  = ['projekt'=>'Projekt','podpisana'=>'Podpisana','w realizacji'=>'W realizacji','obowiązująca'=>'Obowiązująca','zakończona'=>'Zakończona','rozwiązana'=>'Rozwiązana','anulowana'=>'Anulowana'];

if ($is_vol_group && $group['members']) {
    $member_emails = array_values(array_unique(array_filter(
        array_map(fn($m) => strtolower(trim($m['email'] ?? '')), $group['members'])
    )));
    if ($member_emails) {
        try {
            $ph_ = implode(',', array_fill(0, count($member_emails), '?'));
            $all_vol_c = db_all(
                "SELECT LOWER(TRIM(email)) AS email, id, numer_umowy, status, action_id, data_od, data_do
                 FROM umowy_wolontariat
                 WHERE LOWER(TRIM(email)) IN ({$ph_})
                 ORDER BY data_od DESC",
                $member_emails
            );
            $active_st = ['projekt', 'podpisana', 'w realizacji', 'obowiązująca'];
            foreach ($all_vol_c as $vc) {
                $e = $vc['email'];
                // Preferuj aktywną umowę; jeśli kilka aktywnych — bierz pierwszą (najnowszą)
                if (!isset($vol_contracts_map[$e]) || in_array($vc['status'], $active_st)) {
                    $vol_contracts_map[$e] = $vc;
                }
            }
            $aid_ = array_values(array_filter(array_unique(array_column($all_vol_c, 'action_id'))));
            if ($aid_) {
                $ph2_ = implode(',', array_fill(0, count($aid_), '?'));
                $vol_filter_actions = db_all("SELECT id, nazwa FROM actions WHERE id IN ({$ph2_}) ORDER BY nazwa", $aid_);
            }
        } catch (\Throwable $e_) {}
    }
    foreach ($group['members'] as &$_m) {
        $e = strtolower(trim($_m['email'] ?? ''));
        $_m['_vc'] = $vol_contracts_map[$e] ?? null;
    }
    unset($_m);
}

// Akcje dostępne do filtra importu (dla panelu "Importuj z umów")
if ($is_vol_group && $crm_can_write) {
    try {
        $import_actions = db_all(
            "SELECT DISTINCT a.id, a.nazwa FROM actions a
             JOIN umowy_wolontariat w ON w.action_id = a.id
             WHERE w.email IS NOT NULL AND TRIM(w.email) != ''
             ORDER BY a.nazwa"
        );
    } catch (\Throwable $e_) {}
}

// Kontakty spoza grupy (do selektu dodawania)
$not_in_group = CrmManager::getContactsNotInGroup($id);

// Dostęp do grupy (tylko admin wczytuje pełne dane)
$group_users = is_admin() ? CrmManager::getGroupUsers($id) : [];
$group_user_ids = array_column($group_users, 'user_id');
// Użytkownicy CRM (crm_only) nieprzpisani do tej grupy
$assignable_users = is_admin() ? db_all(
    "SELECT u.id, u.name, u.email, r.display_name AS role_name
     FROM users u
     JOIN roles r ON r.name = u.role
     WHERE u.is_active=1 AND r.crm_only=1 AND u.id NOT IN (
         SELECT user_id FROM crm_group_users WHERE group_id=?
     )
     ORDER BY u.name",
    [$id]
) : [];

include __DIR__ . '/../includes/header_crm.php';
?>

<style>
.group-view-header {
  border-radius: 10px;
  padding: 1.25rem 1.5rem;
  color: #fff;
  margin-bottom: 1.5rem;
  display: flex; align-items: center; gap: 1rem;
}
.group-view-icon {
  width: 52px; height: 52px; border-radius: 12px;
  background: rgba(255,255,255,.22); border: 2px solid rgba(255,255,255,.4);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.6rem; flex-shrink: 0;
}
.member-row-actions { opacity: 0; transition: opacity .15s; }
tr:hover .member-row-actions { opacity: 1; }
</style>

<!-- Breadcrumb -->
<nav aria-label="Ścieżka nawigacji" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/groups.php">Grupy</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= h($group['name']) ?></li>
  </ol>
</nav>

<!-- Header grupy -->
<div class="group-view-header shadow-sm"
     style="background:linear-gradient(135deg, <?= h($group['color']) ?> 0%, <?= h($group['color']) ?>cc 100%)">
  <div class="group-view-icon" aria-hidden="true">
    <i class="bi <?= h($group['icon']) ?>"></i>
  </div>
  <div class="flex-grow-1">
    <h1 style="font-size:1.3rem;font-weight:700;margin:0 0 .2rem"><?= h($group['name']) ?></h1>
    <?php if ($group['description']): ?>
    <div style="font-size:.84rem;opacity:.85"><?= h($group['description']) ?></div>
    <?php endif; ?>
    <div style="font-size:.8rem;opacity:.7;margin-top:.3rem">
      <i class="bi bi-people-fill me-1"></i>
      <?= count($group['members']) ?> <?= count($group['members']) === 1 ? 'kontakt' : (count($group['members']) < 5 ? 'kontakty' : 'kontaktów') ?>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($group['members']): ?>
    <a href="<?= APP_URL ?>/crm/mass_send.php?group_id=<?= $id ?>"
       class="btn btn-sm btn-light opacity-90">
      <i class="bi bi-megaphone-fill me-1"></i>Wysyłka masowa
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/crm/index.php?group=<?= $id ?>"
       class="btn btn-sm btn-light opacity-75">
      <i class="bi bi-funnel me-1"></i>Filtruj kontakty
    </a>
    <?php if ($crm_can_write): ?>
    <a href="<?= APP_URL ?>/crm/groups.php" class="btn btn-sm btn-light opacity-60">
      <i class="bi bi-pencil me-1"></i>Edytuj
    </a>
    <?php endif; ?>
    <?php if ($is_vol_group && is_admin()): ?>
    <form method="post" class="d-inline">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="sync_volunteers">
      <button type="submit" class="btn btn-sm btn-light opacity-80"
              title="Synchronizuj przynależność do grup wg aktualnych umów wolontariackich"
              onclick="return confirm('Zsynchronizować przynależność wolontariuszy?')">
        <i class="bi bi-arrow-repeat me-1"></i>Sync
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">

  <!-- ── Lewa: lista członków ────────────────────────────────── -->
  <div class="col-lg-8">
    <div class="crm-list-card">
      <div class="p-3 border-bottom d-flex align-items-center justify-content-between gap-2">
        <span class="fw-semibold" style="font-size:.88rem">
          <i class="bi bi-people me-1 text-muted"></i>
          Członkowie grupy
          <span class="badge bg-light text-dark border ms-1"><?= count($group['members']) ?></span>
        </span>
        <div class="d-flex gap-2 align-items-center">
          <input type="text" id="memberSearch" class="form-control form-control-sm"
                 style="max-width:200px" placeholder="Szukaj…"
                 oninput="filterMembers(this.value)"
                 aria-label="Szukaj w liście członków">
          <?php if ($crm_can_delete && $group['members']): ?>
          <form method="post" onsubmit="return confirm('Usunąć WSZYSTKICH członków z grupy?')">
            <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="remove_all">
            <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Usuń wszystkich">
              <i class="bi bi-trash me-1"></i>Wyczyść
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($is_vol_group): ?>
      <div class="p-2 border-bottom bg-light d-flex align-items-center gap-2 flex-wrap" style="font-size:.82rem">
        <span class="text-muted fw-semibold flex-shrink-0"><i class="bi bi-funnel me-1"></i>Filtr umów:</span>
        <select id="volStatusFilter" class="form-select form-select-sm" style="max-width:160px" onchange="applyVolFilters()">
          <option value="">Każdy status</option>
          <?php foreach ($vol_all_statuses as $_vs): ?>
          <option value="<?= h($_vs) ?>"><?= h($vol_status_labels[$_vs] ?? $_vs) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($vol_filter_actions): ?>
        <select id="volActionFilter" class="form-select form-select-sm" style="max-width:220px" onchange="applyVolFilters()">
          <option value="">Każde działanie</option>
          <?php foreach ($vol_filter_actions as $_va): ?>
          <option value="<?= (int)$_va['id'] ?>"><?= h(mb_substr($_va['nazwa'], 0, 38)) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button class="btn btn-outline-secondary btn-sm py-0" onclick="resetVolFilters()" title="Wyczyść filtry">
          <i class="bi bi-x-lg"></i>
        </button>
        <span id="volFilterCount" class="ms-auto text-muted" style="font-size:.75rem"></span>
      </div>
      <?php endif; ?>

      <?php if ($group['members']): ?>
      <div class="table-responsive">
        <table class="crm-table" id="memberTable" aria-label="Członkowie grupy <?= h($group['name']) ?>">
          <thead>
            <tr>
              <th scope="col">Kontakt</th>
              <th scope="col" class="d-none d-md-table-cell"><?= $is_vol_group ? 'Status CRM / Umowa' : 'Status' ?></th>
              <th scope="col" class="d-none d-lg-table-cell">Dodano</th>
              <th scope="col"><span class="visually-hidden">Akcje</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($group['members'] as $m):
              $is_org  = $m['type'] === 'organizacja';
              $ini     = $m['avatar_initials'] ?: CrmManager::makeInitials($m['imie_nazwisko']);
              $sc      = crm_statuses()[$m['status']] ?? ['label' => $m['status']];
              $vc      = $m['_vc'] ?? null;
              $vc_st   = $vc['status'] ?? '';
              $vc_aid  = (int)($vc['action_id'] ?? 0);
              $vc_color = match($vc_st) {
                'projekt','podpisana','w realizacji','obowiązująca' => '#2E844A',
                'zakończona' => '#374151', 'rozwiązana' => '#D97706', 'anulowana' => '#E31010',
                default => '#6B7280',
              };
              // Nazwa działania z mapy załadowanych wcześniej akcji
              $vc_action_name = null;
              foreach ($vol_filter_actions as $_va) {
                  if ((int)$_va['id'] === $vc_aid) { $vc_action_name = $_va['nazwa']; break; }
              }
            ?>
            <tr data-name="<?= h(mb_strtolower($m['imie_nazwisko'])) ?>"
                data-vol-status="<?= h($vc_st) ?>"
                data-vol-action="<?= $vc_aid ?>">
              <td>
                <div class="crm-name-cell">
                  <div class="crm-avatar <?= $is_org ? 'org' : '' ?>" aria-hidden="true"
                       style="background:<?= $is_org ? 'var(--crm-navy)' : 'var(--crm-primary)' ?>">
                    <?= h($ini) ?>
                  </div>
                  <div>
                    <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$m['id'] ?>"
                       class="crm-name-link"><?= h($m['imie_nazwisko']) ?></a>
                    <?php if ($m['organizacja']): ?>
                    <div class="crm-name-sub"><?= h($m['organizacja']) ?></div>
                    <?php endif; ?>
                    <?php if ($m['stanowisko']): ?>
                    <div class="crm-name-sub"><?= h($m['stanowisko']) ?></div>
                    <?php endif; ?>
                    <?php if ($vc_action_name): ?>
                    <div class="crm-name-sub" style="color:#6366f1">
                      <i class="bi bi-lightning-charge me-1" style="font-size:.62rem"></i><?= h(mb_substr($vc_action_name, 0, 42)) ?>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td class="d-none d-md-table-cell">
                <span class="crm-badge crm-badge-<?= h($m['status']) ?>"><?= h($sc['label']) ?></span>
                <?php if ($vc_st): ?>
                <div style="margin-top:.25rem">
                  <span style="font-size:.68rem;padding:.1rem .3rem;border-radius:3px;display:inline-flex;align-items:center;gap:.2rem;background:<?= $vc_color ?>22;color:<?= $vc_color ?>;border:1px solid <?= $vc_color ?>44"
                        title="<?= h($vc['numer_umowy'] ?? '') ?> · <?= h($vc['data_od'] ?? '') ?>–<?= h($vc['data_do'] ?? '') ?>">
                    <i class="bi bi-file-earmark-text" style="font-size:.6rem"></i>
                    <?= h($vol_status_labels[$vc_st] ?? $vc_st) ?>
                  </span>
                </div>
                <?php endif; ?>
              </td>
              <td class="d-none d-lg-table-cell" style="font-size:.8rem;color:#64748b">
                <?= date_pl($m['added_at']) ?>
                <?php if ($m['added_by_name']): ?>
                <div class="text-muted" style="font-size:.72rem"><?= h($m['added_by_name']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-end member-row-actions">
                <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$m['id'] ?>"
                   class="btn btn-crm-ghost btn-sm" aria-label="Otwórz kartę <?= h($m['imie_nazwisko']) ?>">
                  <i class="bi bi-eye" aria-hidden="true"></i>
                </a>
                <?php if ($crm_can_write): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf"       value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action"     value="remove_member">
                  <input type="hidden" name="contact_id"  value="<?= (int)$m['id'] ?>">
                  <button type="submit" class="btn btn-crm-ghost btn-sm text-danger"
                          aria-label="Usuń <?= h($m['imie_nazwisko']) ?> z grupy">
                    <i class="bi bi-person-dash" aria-hidden="true"></i>
                  </button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="p-4 text-center text-muted">
        <i class="bi bi-people" style="font-size:2rem;opacity:.3" aria-hidden="true"></i>
        <div class="mt-2" style="font-size:.88rem">Grupa jest pusta. Dodaj kontakty z panelu obok.</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Prawa: dodaj kontakty ──────────────────────────────── -->
  <div class="col-lg-4">

    <!-- Dodaj pojedynczy kontakt -->
    <?php if ($crm_can_write && $not_in_group): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title">Dodaj kontakt</div>
        <form method="post" aria-label="Dodaj pojedynczy kontakt do grupy">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="add_member">
          <div class="mb-2">
            <label class="visually-hidden" for="contact_id_select">Wybierz kontakt</label>
            <select name="contact_id" id="contact_id_select"
                    class="form-select form-select-sm"
                    required aria-label="Wybierz kontakt do dodania">
              <option value="">— Wybierz kontakt —</option>
              <?php foreach ($not_in_group as $c): ?>
              <option value="<?= (int)$c['id'] ?>">
                <?= h($c['imie_nazwisko']) ?>
                <?php if ($c['organizacja']): ?> (<?= h($c['organizacja']) ?>)<?php endif; ?>
                <?php if ($c['type'] === 'organizacja'): ?> 🏢<?php endif; ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-crm-primary btn-sm w-100">
            <i class="bi bi-person-plus me-1"></i>Dodaj do grupy
          </button>
        </form>
      </div>
    </div>
    <?php elseif ($crm_can_write && !$not_in_group): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body text-center py-4">
        <i class="bi bi-check-circle-fill text-success" style="font-size:1.5rem"></i>
        <div class="mt-2 small text-muted">Wszyscy aktywni kontakty są w tej grupie.</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Dodaj wielu naraz (bulk) -->
    <?php if ($crm_can_write && count($not_in_group) > 1): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title">Dodaj wielu naraz</div>
        <form method="post">
          <input type="hidden" name="_csrf"       value="<?= csrf_token() ?>">
          <input type="hidden" name="_action"     value="add_members_bulk">
          <input type="hidden" name="contact_ids" id="bulkContactIds" value="">
          <div style="max-height:200px;overflow-y:auto;border:1px solid var(--crm-border);border-radius:.4rem;padding:.5rem">
            <?php foreach ($not_in_group as $c): ?>
            <label class="d-flex align-items-center gap-2 p-1 rounded" style="cursor:pointer;font-size:.82rem">
              <input type="checkbox" class="form-check-input bulk-check mt-0" value="<?= (int)$c['id'] ?>">
              <span><?= h($c['imie_nazwisko']) ?><?= $c['type']==='organizacja' ? ' <i class="bi bi-building ms-1 text-muted" style="font-size:.7rem"></i>' : '' ?></span>
            </label>
            <?php endforeach; ?>
          </div>
          <div class="mt-2 d-flex justify-content-between gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAllBulk()" id="bulkToggleBtn">Zaznacz wszystkich</button>
            <button type="submit" class="btn btn-crm-primary btn-sm" id="bulkSubmitBtn" disabled>
              <i class="bi bi-person-plus me-1"></i>Dodaj zaznaczonych
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php
    // Dane do sekcji bocznych
    $parent_group  = $group['parent_id'] ? db_one("SELECT id,name,color,icon FROM crm_groups WHERE id=?", [(int)$group['parent_id']]) : null;
    $subgroups     = db_all("SELECT g.*, (SELECT COUNT(*) FROM crm_group_members gm WHERE gm.group_id=g.id) AS mc FROM crm_groups g WHERE g.parent_id=? ORDER BY g.name", [$id]);
    $group_tags    = db_all("SELECT tag FROM crm_group_tags WHERE group_id=? ORDER BY tag", [$id]);
    $linked_groups = db_all(
        "SELECT g.*, (SELECT COUNT(*) FROM crm_group_members gm WHERE gm.group_id=g.id) AS mc
         FROM crm_groups g JOIN crm_group_links gl ON gl.child_group_id=g.id
         WHERE gl.parent_group_id=? ORDER BY g.name", [$id]
    );
    $all_groups_for_link = db_all("SELECT id,name FROM crm_groups WHERE id!=? ORDER BY name", [$id]);
    ?>

    <?php if ($is_vol_group && $crm_can_write): ?>
    <!-- Import wolontariuszy wg kryteriów umowy -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title"><i class="bi bi-download me-1"></i>Importuj z umów</div>
        <p class="text-muted mb-2" style="font-size:.78rem">
          Dodaj wolontariuszy do grupy filtrując po statusie umowy lub działaniu.
        </p>
        <form method="post">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="import_by_criteria">
          <div class="mb-2">
            <label class="form-label fw-semibold small mb-1">Status umowy</label>
            <select name="crit_status" class="form-select form-select-sm">
              <option value="">— dowolny —</option>
              <?php foreach ($vol_status_labels as $_s => $_sl): ?>
              <option value="<?= h($_s) ?>"><?= h($_sl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($import_actions): ?>
          <div class="mb-2">
            <label class="form-label fw-semibold small mb-1">Działanie</label>
            <select name="crit_action" class="form-select form-select-sm">
              <option value="">— dowolne —</option>
              <?php foreach ($import_actions as $_ia): ?>
              <option value="<?= (int)$_ia['id'] ?>"><?= h(mb_substr($_ia['nazwa'], 0, 42)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-crm-primary btn-sm w-100">
            <i class="bi bi-person-plus me-1"></i>Importuj pasujących
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Nadrzędna / podgrupy -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title d-flex align-items-center justify-content-between">
          <span><i class="bi bi-diagram-3 me-1"></i>Hierarchia grup</span>
          <?php if ($crm_can_write): ?>
          <button class="btn btn-outline-secondary py-0 px-2" style="font-size:.7rem" data-bs-toggle="collapse" data-bs-target="#setParentCollapse">
            <i class="bi bi-pencil"></i>
          </button>
          <?php endif; ?>
        </div>

        <!-- Nadrzędna -->
        <?php if ($parent_group): ?>
        <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded" style="background:#F9FAFB;border:1px solid #E5E7EB;font-size:.82rem">
          <div style="width:24px;height:24px;border-radius:6px;background:<?= h($parent_group['color']) ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="bi <?= h($parent_group['icon']) ?>" style="color:#fff;font-size:.7rem"></i>
          </div>
          <a href="view.php?id=<?= (int)$parent_group['id'] ?>" style="font-size:.8rem;color:var(--crm-primary)">
            ↑ <?= h($parent_group['name']) ?>
          </a>
          <span class="text-muted" style="font-size:.72rem">(nadrzędna)</span>
        </div>
        <?php else: ?>
        <div class="text-muted mb-2" style="font-size:.78rem">Brak grupy nadrzędnej — to jest grupa główna.</div>
        <?php endif; ?>

        <!-- Podgrupy -->
        <?php if ($subgroups): ?>
        <div class="mb-2">
          <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#9CA3AF;letter-spacing:.05em;margin-bottom:.4rem">Podgrupy (<?= count($subgroups) ?>)</div>
          <?php foreach ($subgroups as $sg): ?>
          <a href="view.php?id=<?= (int)$sg['id'] ?>" class="d-flex align-items-center gap-2 py-1 text-decoration-none" style="color:#374151;font-size:.82rem">
            <div style="width:20px;height:20px;border-radius:5px;background:<?= h($sg['color']) ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="bi <?= h($sg['icon']) ?>" style="color:#fff;font-size:.6rem"></i>
            </div>
            <span><?= h($sg['name']) ?></span>
            <span class="ms-auto text-muted" style="font-size:.72rem"><?= (int)$sg['mc'] ?> kontaktów</span>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($crm_can_write): ?>
        <div class="collapse mt-2" id="setParentCollapse">
          <div class="p-2 border rounded" style="background:#F9FAFB">
            <div style="font-size:.75rem;font-weight:600;margin-bottom:.4rem">Zmień grupę nadrzędną:</div>
            <select id="setParentSel" class="form-select form-select-sm mb-2">
              <option value="">— brak (grupa główna) —</option>
              <?php foreach ($all_groups_for_link as $ag): ?>
              <option value="<?= (int)$ag['id'] ?>" <?= $group['parent_id']==$ag['id']?'selected':'' ?>>
                <?= h($ag['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-crm-primary w-100" onclick="setParent()">Zapisz</button>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($crm_can_write): ?>
        <div class="mt-2">
          <a href="<?= APP_URL ?>/crm/groups.php" class="btn btn-outline-secondary btn-sm w-100" style="font-size:.75rem;border-style:dashed">
            <i class="bi bi-plus me-1"></i>Dodaj podgrupę w panelu grup
          </a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Tagi grupy -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title"><i class="bi bi-tags me-1"></i>Tagi grupy</div>
        <div class="d-flex flex-wrap gap-1 mb-2" id="groupTagsList">
          <?php foreach ($group_tags as $gt): ?>
          <span class="badge bg-light text-dark border d-inline-flex align-items-center gap-1" style="font-size:.72rem">
            <i class="bi bi-tag" style="font-size:.6rem"></i><?= h($gt['tag']) ?>
            <?php if ($crm_can_write): ?>
            <button type="button" class="btn-close" style="font-size:.5rem" aria-label="Usuń tag"
                    onclick="removeGroupTag('<?= h(addslashes($gt['tag'])) ?>')"></button>
            <?php endif; ?>
          </span>
          <?php endforeach; ?>
          <?php if (!$group_tags): ?>
          <span class="text-muted" style="font-size:.78rem">Brak tagów</span>
          <?php endif; ?>
        </div>
        <?php if ($crm_can_write): ?>
        <div class="input-group input-group-sm">
          <input type="text" id="newGroupTag" class="form-control" placeholder="Nowy tag…" maxlength="40"
                 onkeydown="if(event.key==='Enter'){event.preventDefault();addGroupTag()}">
          <button class="btn btn-outline-secondary" onclick="addGroupTag()"><i class="bi bi-plus"></i></button>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Połączone grupy -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title d-flex align-items-center justify-content-between">
          <span><i class="bi bi-link-45deg me-1"></i>Połączone grupy</span>
          <span class="text-muted fw-normal" style="font-size:.72rem;text-transform:none;letter-spacing:0">dziedziczą memberów</span>
        </div>
        <?php foreach ($linked_groups as $lg): ?>
        <div class="d-flex align-items-center gap-2 mb-1" style="font-size:.82rem">
          <div style="width:20px;height:20px;border-radius:5px;background:<?= h($lg['color']) ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="bi <?= h($lg['icon']) ?>" style="color:#fff;font-size:.6rem"></i>
          </div>
          <a href="view.php?id=<?= (int)$lg['id'] ?>" style="color:var(--crm-primary);flex:1"><?= h($lg['name']) ?></a>
          <span class="text-muted" style="font-size:.72rem"><?= (int)$lg['mc'] ?></span>
          <?php if ($crm_can_write): ?>
          <button class="btn btn-crm-ghost btn-sm py-0 px-1 text-danger"
                  onclick="unlinkGroup(<?= (int)$lg['id'] ?>)" title="Rozłącz">
            <i class="bi bi-x"></i>
          </button>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$linked_groups): ?>
        <div class="text-muted mb-2" style="font-size:.78rem">Brak połączonych grup.</div>
        <?php endif; ?>
        <?php if ($crm_can_write): ?>
        <div class="input-group input-group-sm mt-2">
          <select id="linkGroupSel" class="form-select form-select-sm">
            <option value="">— wybierz grupę do połączenia —</option>
            <?php foreach ($all_groups_for_link as $ag): ?>
            <option value="<?= (int)$ag['id'] ?>"><?= h($ag['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-outline-secondary" onclick="linkGroup()"><i class="bi bi-link-45deg"></i></button>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Statystyki grupy -->
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="crm-section-title">Statystyki</div>
        <?php
          $by_status = [];
          foreach ($group['members'] as $m) $by_status[$m['status']] = ($by_status[$m['status']] ?? 0) + 1;
          arsort($by_status);
          $by_type = ['osoba'=>0,'organizacja'=>0];
          foreach ($group['members'] as $m) $by_type[$m['type']] = ($by_type[$m['type']] ?? 0) + 1;
        ?>
        <div class="d-flex gap-3 mb-3">
          <span style="font-size:.84rem"><i class="bi bi-person me-1 text-muted"></i><?= $by_type['osoba'] ?> osób</span>
          <span style="font-size:.84rem"><i class="bi bi-building me-1 text-muted"></i><?= $by_type['organizacja'] ?> firm</span>
        </div>
        <?php foreach ($by_status as $sk => $cnt):
          $sc = crm_statuses()[$sk] ?? ['label'=>$sk];
        ?>
        <div class="d-flex align-items-center justify-content-between mb-1" style="font-size:.82rem">
          <span class="crm-badge crm-badge-<?= h($sk) ?>"><?= h($sc['label']) ?></span>
          <span class="fw-semibold"><?= $cnt ?></span>
        </div>
        <?php endforeach; ?>
        <div class="border-top mt-2 pt-2" style="font-size:.73rem;color:#9CA3AF">
          Utworzono: <?= date_pl($group['created_at']) ?>
        </div>
      </div>
    </div>

    <?php if (is_admin()): ?>
    <!-- Dostęp użytkowników do grupy -->
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-body">
        <div class="crm-section-title d-flex align-items-center justify-content-between">
          <span><i class="bi bi-person-lock me-1"></i>Dostęp do grupy</span>
        </div>
        <p class="text-muted small mb-2">
          Użytkownicy z rolą CRM widzą tylko kontakty ze swoich grup.
          Tu możesz przypisać im dostęp do tej grupy.
        </p>

        <?php if ($group_users): ?>
        <ul class="list-group list-group-flush mb-3">
          <?php foreach ($group_users as $gu): ?>
          <li class="list-group-item px-0 py-2 d-flex align-items-center gap-2">
            <div class="flex-grow-1" style="font-size:.84rem">
              <span class="fw-semibold"><?= h($gu['user_name']) ?></span>
              <span class="text-muted small ms-1"><?= h($gu['role_name']) ?></span><br>
              <span class="text-muted" style="font-size:.75rem"><?= h($gu['user_email']) ?></span>
              <span class="ms-2">
                <?= $gu['can_write']  ? '<span class="badge bg-success-subtle text-success border border-success-subtle">zapis</span> ' : '' ?>
                <?= $gu['can_delete'] ? '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">usuwanie</span>' : '' ?>
              </span>
            </div>
            <div class="d-flex gap-1 flex-shrink-0">
              <!-- Edytuj uprawnienia -->
              <button type="button" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2"
                      data-bs-toggle="collapse"
                      data-bs-target="#editAccess_<?= (int)$gu['user_id'] ?>"
                      title="Edytuj uprawnienia">
                <i class="bi bi-pencil"></i>
              </button>
              <!-- Cofnij dostęp -->
              <form method="post" class="d-inline"
                    onsubmit="return confirm('Cofnąć dostęp użytkownika <?= h(addslashes($gu['user_name'])) ?>?')">
                <input type="hidden" name="_csrf"           value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_action"         value="revoke_user">
                <input type="hidden" name="revoke_user_id"  value="<?= (int)$gu['user_id'] ?>">
                <button type="submit" class="btn btn-xs btn-sm btn-outline-danger py-0 px-2">
                  <i class="bi bi-x-lg"></i>
                </button>
              </form>
            </div>
          </li>
          <!-- Edycja inline -->
          <li class="list-group-item px-0 py-0 collapse" id="editAccess_<?= (int)$gu['user_id'] ?>">
            <form method="post" class="p-2 bg-light rounded mb-1">
              <input type="hidden" name="_csrf"          value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_action"        value="grant_user">
              <input type="hidden" name="grant_user_id"  value="<?= (int)$gu['user_id'] ?>">
              <div class="d-flex gap-3 flex-wrap align-items-center">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" id="gw_<?= (int)$gu['user_id'] ?>"
                         name="grant_write" <?= $gu['can_write'] ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="gw_<?= (int)$gu['user_id'] ?>">Zapis</label>
                </div>
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" id="gd_<?= (int)$gu['user_id'] ?>"
                         name="grant_delete" <?= $gu['can_delete'] ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="gd_<?= (int)$gu['user_id'] ?>">Usuwanie</label>
                </div>
                <button type="submit" class="btn btn-xs btn-sm btn-primary py-0 px-2">Zapisz</button>
              </div>
            </form>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="text-muted small">Brak przypisanych użytkowników.</p>
        <?php endif; ?>

        <?php if ($assignable_users): ?>
        <form method="post">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="grant_user">
          <div class="mb-2">
            <label class="form-label small fw-semibold mb-1">Przypisz użytkownika</label>
            <select class="form-select form-select-sm" name="grant_user_id" required>
              <option value="">— wybierz —</option>
              <?php foreach ($assignable_users as $au): ?>
              <option value="<?= (int)$au['id'] ?>"><?= h($au['name']) ?> (<?= h($au['role_name']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="d-flex gap-3 mb-2">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="new_gw" name="grant_write" checked>
              <label class="form-check-label small" for="new_gw">Zapis</label>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="new_gd" name="grant_delete">
              <label class="form-check-label small" for="new_gd">Usuwanie</label>
            </div>
          </div>
          <button type="submit" class="btn btn-sm btn-outline-primary w-100">
            <i class="bi bi-person-plus me-1"></i>Przypisz dostęp
          </button>
        </form>
        <?php elseif (!$group_users): ?>
        <p class="text-muted small mb-0">
          Brak użytkowników z rolą CRM do przypisania.
          <a href="<?= APP_URL ?>/admin/crm_roles.php">Zarządzaj rolami CRM</a>.
        </p>
        <?php else: ?>
        <p class="text-muted small mb-0">Wszyscy użytkownicy CRM mają już przypisany dostęp.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /prawa -->

</div><!-- /row -->

<script>
const GROUP_ID   = <?= (int)$id ?>;
const LINK_API   = '<?= APP_URL ?>/crm/api/group_link.php';

function filterMembers(q) {
  applyVolFilters();
}

function applyVolFilters() {
  var q  = ((document.getElementById('memberSearch')   || {}).value || '').toLowerCase();
  var sf = ((document.getElementById('volStatusFilter') || {}).value || '');
  var af = ((document.getElementById('volActionFilter') || {}).value || '');
  var vis = 0, tot = 0;
  document.querySelectorAll('#memberTable tbody tr').forEach(function(row) {
    tot++;
    var name = (row.dataset.name  || '').toLowerCase();
    var show = (q  === '' || name.includes(q))
            && (sf === '' || (row.dataset.volStatus || '') === sf)
            && (af === '' || (row.dataset.volAction || '') === af);
    row.style.display = show ? '' : 'none';
    if (show) vis++;
  });
  var cnt = document.getElementById('volFilterCount');
  if (cnt) cnt.textContent = (sf || af || q) ? vis + ' z ' + tot : '';
}

function resetVolFilters() {
  ['volStatusFilter','volActionFilter','memberSearch'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.value = '';
  });
  applyVolFilters();
}

// ── Zmień grupę nadrzędną ──────────────────────────────────────────────────
function setParent() {
  var pid = parseInt(document.getElementById('setParentSel').value) || null;
  fetch(LINK_API, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ action:'set_parent', group_id:GROUP_ID, parent_id:pid })
  }).then(r=>r.json()).then(res=>{
    if (res.ok) location.reload();
    else alert('Błąd: ' + res.error);
  });
}

// ── Tagi grupy ────────────────────────────────────────────────────────────
function addGroupTag() {
  var inp = document.getElementById('newGroupTag');
  var tag = (inp?.value||'').trim().toLowerCase().replace(/\s+/g,'-');
  if (!tag) return;
  fetch(LINK_API, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ action:'add_tag', group_id:GROUP_ID, tag })
  }).then(r=>r.json()).then(res=>{
    if (res.ok) { if(inp) inp.value=''; location.reload(); }
    else alert('Błąd: ' + res.error);
  });
}
function removeGroupTag(tag) {
  if (!confirm('Usunąć tag „' + tag + '"?')) return;
  fetch(LINK_API, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ action:'remove_tag', group_id:GROUP_ID, tag })
  }).then(r=>r.json()).then(res=>{ if(res.ok) location.reload(); });
}

// ── Połącz / rozłącz grupy ────────────────────────────────────────────────
function linkGroup() {
  var cid = parseInt(document.getElementById('linkGroupSel').value);
  if (!cid) return;
  fetch(LINK_API, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ action:'link', parent_id:GROUP_ID, child_id:cid })
  }).then(r=>r.json()).then(res=>{
    if (res.ok) location.reload();
    else alert('Błąd: ' + res.error);
  });
}
function unlinkGroup(cid) {
  fetch(LINK_API, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ action:'unlink', parent_id:GROUP_ID, child_id:cid })
  }).then(r=>r.json()).then(res=>{ if(res.ok) location.reload(); });
}

// Bulk select
var bulkAllSelected = false;
function toggleAllBulk() {
  bulkAllSelected = !bulkAllSelected;
  document.querySelectorAll('.bulk-check').forEach(function(c) { c.checked = bulkAllSelected; });
  document.getElementById('bulkToggleBtn').textContent = bulkAllSelected ? 'Odznacz wszystkich' : 'Zaznacz wszystkich';
  updateBulkIds();
}

document.querySelectorAll('.bulk-check').forEach(function(c) {
  c.addEventListener('change', updateBulkIds);
});

function updateBulkIds() {
  var ids = [];
  document.querySelectorAll('.bulk-check:checked').forEach(function(c) { ids.push(c.value); });
  document.getElementById('bulkContactIds').value = ids.join(',');
  document.getElementById('bulkSubmitBtn').disabled = ids.length === 0;
}
</script>

<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
