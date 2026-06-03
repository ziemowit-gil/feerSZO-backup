<?php
/**
 * crm/contact/view.php — Karta kontaktu CRM.
 *
 * Salesforce-style: contact header z gradientem, 3 kolumny:
 *   - Lewa: dane kontaktu, tagi, relacje
 *   - Środkowa: notatki, historia komunikacji
 *   - Prawa: szybkie akcje
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$crm_can_write  = can_write('crm') || is_admin();
$crm_can_delete = can_delete('crm') || is_admin();

$id      = (int)($_GET['id'] ?? 0);
$contact = CrmManager::getContact($id);

if (!$contact) {
    flash_set('danger', 'Kontakt nie istnieje lub został usunięty.');
    header('Location: ' . APP_URL . '/crm/index.php');
    exit;
}

$PAGE_TITLE = 'CRM — ' . $contact['imie_nazwisko'];

// ── POST: notatki, tagi, relacje (inline AJAX-fallback) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $crm_can_write) {
    csrf_check();
    $action  = $_POST['_action'] ?? '';
    $user_id = (int)(current_user()['id'] ?? 0);

    if ($action === 'add_note') {
        $body   = trim($_POST['note_body'] ?? '');
        $pinned = !empty($_POST['note_pinned']);
        if ($body !== '') {
            CrmManager::addNote($id, $body, $user_id, $pinned);
            flash_set('success', 'Notatka dodana.');
        }
    }

    if ($action === 'delete_note' && $crm_can_delete) {
        CrmManager::deleteNote((int)($_POST['note_id'] ?? 0));
        flash_set('success', 'Notatka usunięta.');
    }

    if ($action === 'add_tag') {
        $tag = trim($_POST['tag'] ?? '');
        if ($tag !== '') { CrmManager::addTag($id, $tag); flash_set('success', 'Tag dodany.'); }
    }

    if ($action === 'remove_tag') {
        CrmManager::removeTag($id, trim($_POST['tag'] ?? ''));
        flash_set('success', 'Tag usunięty.');
    }

    if ($action === 'add_relation') {
        $target = (int)($_POST['relation_target'] ?? 0);
        $type   = trim($_POST['relation_type'] ?? 'powiązany');
        $notes  = trim($_POST['relation_notes'] ?? '');
        if ($target > 0 && $target !== $id) {
            CrmManager::addRelation($id, $target, $type, $notes ?: null);
            flash_set('success', 'Relacja dodana.');
        }
    }

    if ($action === 'remove_relation' && $crm_can_delete) {
        CrmManager::removeRelation((int)($_POST['relation_id'] ?? 0));
        flash_set('success', 'Relacja usunięta.');
    }

    if ($action === 'link_to_action') {
        $aid  = (int)($_POST['action_id'] ?? 0);
        $rola = trim($_POST['rola'] ?? 'uczestnik');
        $nota = trim($_POST['nota'] ?? '');
        if ($aid) {
            CrmManager::linkToAction($id, $aid, $rola, $nota);
            $a = db_one("SELECT nazwa FROM actions WHERE id=?", [$aid]);
            flash_set('success', 'Dodano do działania: ' . ($a['nazwa'] ?? ''));
        }
    }

    if ($action === 'unlink_from_action') {
        $aid = (int)($_POST['action_id'] ?? 0);
        if ($aid) {
            CrmManager::unlinkFromAction($id, $aid);
            flash_set('success', 'Usunięto z działania.');
        }
    }

    if ($action === 'add_to_group') {
        $gid = (int)($_POST['group_id'] ?? 0);
        if ($gid) {
            CrmManager::addToGroup($gid, $id);
            $g = db_one("SELECT name FROM crm_groups WHERE id=?", [$gid]);
            flash_set('success', 'Dodano do grupy "' . ($g['name'] ?? '') . '".');
        }
    }

    if ($action === 'remove_from_group') {
        $gid = (int)($_POST['group_id'] ?? 0);
        if ($gid) {
            CrmManager::removeFromGroup($gid, $id);
            flash_set('success', 'Usunięto z grupy.');
        }
    }

    header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $id);
    exit;
}

// Przeładuj po POST
$contact = CrmManager::getContact($id);

// Działania systemu głównego (do selektu i do listy)
$contact_actions  = CrmManager::getContactActions($id);
$linked_action_ids = array_column($contact_actions, 'id');
$all_actions_raw  = db_all(
    "SELECT id, nazwa, typ, status, data_od FROM actions ORDER BY data_od DESC, nazwa"
);
$available_actions = array_filter($all_actions_raw, fn($a) => !in_array($a['id'], $linked_action_ids));

// Kontakty do wyboru w formularzu relacji (z wyłączeniem bieżącego)
$all_contacts_for_relation = db_all(
    "SELECT id, imie_nazwisko, organizacja FROM crm_contacts
     WHERE crm_active=1 AND id != ? ORDER BY imie_nazwisko",
    [$id]
);

// Dane wolontariatu z systemu głównego (dopasowanie po e-mail)
$volunteer_contracts    = CrmManager::getContactVolunteerContracts($contact['email'] ?? '');
$volunteer_recruitments = CrmManager::getContactRecruitments($contact['email'] ?? '');

// Dodatkowe pola kontaktu
$_custom_field_defs   = CrmManager::getFieldDefs($contact['type'] ?? '');
$_custom_field_values = CrmManager::getFieldValues($id);

include __DIR__ . '/../includes/header_crm.php';
?>

<!-- Breadcrumb -->
<nav aria-label="Ścieżka nawigacji" class="mb-2">
  <ol class="breadcrumb mb-0" style="font-size:.82rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php"><i class="bi bi-diagram-2-fill me-1" style="color:var(--crm-primary)"></i>CRM</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= h($contact['imie_nazwisko']) ?></li>
  </ol>
</nav>

<!-- ══ CONTACT HEADER ═════════════════════════════════════════════════════════ -->
<div class="card mb-3 border-0 overflow-hidden shadow-sm">
  <div class="crm-contact-header">
    <div class="crm-contact-avatar-lg" aria-hidden="true">
      <?= h($contact['avatar_initials'] ?: CrmManager::makeInitials($contact['imie_nazwisko'])) ?>
    </div>
    <div class="flex-grow-1">
      <h1 class="crm-contact-name"><?= h($contact['imie_nazwisko']) ?></h1>
      <div class="crm-contact-sub">
        <?php if ($contact['stanowisko']): ?>
          <span><?= h($contact['stanowisko']) ?></span>
          <?php if ($contact['organizacja']): ?> · <?php endif; ?>
        <?php endif; ?>
        <?php if ($contact['organizacja']): ?>
          <span><i class="bi bi-building me-1" aria-hidden="true"></i><?= h($contact['organizacja']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="d-flex flex-column align-items-end gap-2">
      <?php
        $sc = crm_statuses()[$contact['status']] ?? ['label' => $contact['status'], 'color' => '#939393'];
      ?>
      <span class="crm-badge crm-badge-<?= h($contact['status']) ?>" style="font-size:.8rem">
        <?= h($sc['label']) ?>
      </span>
      <div class="d-flex gap-2">
        <?php if ($crm_can_write): ?>
        <a href="<?= APP_URL ?>/crm/contact/add.php?id=<?= $id ?>"
           class="btn btn-sm btn-light"
           aria-label="Edytuj kontakt">
          <i class="bi bi-pencil-fill me-1"></i>Edytuj
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-light"
                onclick="openCommModal(<?= $id ?>,'email')"
                aria-label="Wyślij wiadomość">
          <i class="bi bi-send-fill me-1"></i>Wiadomość
        </button>
      </div>
    </div>
  </div>

  <!-- Quick info strip -->
  <div class="card-body py-2 border-top d-flex flex-wrap gap-3" style="background:#f9f9f9;font-size:.84rem">
    <?php if ($contact['email']): ?>
    <a href="mailto:<?= h($contact['email']) ?>" class="text-decoration-none text-muted">
      <i class="bi bi-envelope-fill me-1 text-secondary"></i><?= h($contact['email']) ?>
    </a>
    <?php endif; ?>
    <?php if ($contact['telefon']): ?>
    <a href="tel:<?= h($contact['telefon']) ?>" class="text-decoration-none text-muted">
      <i class="bi bi-telephone-fill me-1 text-secondary"></i><?= h($contact['telefon']) ?>
    </a>
    <?php endif; ?>
    <?php if ($contact['adres']): ?>
    <span class="text-muted">
      <i class="bi bi-geo-alt-fill me-1 text-secondary" aria-hidden="true"></i><?= h($contact['adres']) ?>
    </span>
    <?php endif; ?>
    <?php if ($contact['nip']): ?>
    <span class="text-muted">NIP: <?= h($contact['nip']) ?></span>
    <?php endif; ?>
    <span class="ms-auto text-muted" style="font-size:.75rem">
      Dodano: <?= date_pl($contact['created_at']) ?>
      <?php if ($contact['source'] !== 'manual'): ?>
        · Źródło: <?= h($contact['source']) ?>
      <?php endif; ?>
    </span>
  </div>
</div>

<!-- ══ TREŚĆ: 3 KOLUMNY ══════════════════════════════════════════════════════ -->
<div class="row g-3">

  <!-- ── Lewa: dane, tagi, relacje ──────────────────────────────────────────── -->
  <div class="col-lg-3">

    <!-- Tagi -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title">Tagi</div>

        <?php if ($contact['tags']): ?>
        <div class="crm-tags-list mb-2">
          <?php foreach ($contact['tags'] as $t): ?>
          <span class="crm-tag d-inline-flex align-items-center gap-1">
            <?= h($t['tag']) ?>
            <?php if ($crm_can_write): ?>
            <form method="post" class="d-inline ms-1">
              <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="remove_tag">
              <input type="hidden" name="tag"     value="<?= h($t['tag']) ?>">
              <button type="submit" class="btn-crm-ghost p-0 border-0 text-danger"
                      style="font-size:.7rem;line-height:1;background:none"
                      aria-label="Usuń tag <?= h($t['tag']) ?>">&times;</button>
            </form>
            <?php endif; ?>
          </span>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-muted small mb-2">Brak tagów.</p>
        <?php endif; ?>

        <?php if ($crm_can_write): ?>
        <form method="post" class="d-flex gap-2 mt-1">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="add_tag">
          <input type="text" name="tag" class="form-control form-control-sm"
                 placeholder="Nowy tag…" aria-label="Dodaj tag"
                 pattern="[a-zA-Z0-9ąćęłńóśźżĄĆĘŁŃÓŚŹŻ\-_]{1,30}"
                 maxlength="30">
          <button class="btn btn-sm btn-crm-outline" type="submit" aria-label="Dodaj tag">
            <i class="bi bi-plus" aria-hidden="true"></i>
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($_custom_field_defs): ?>
    <!-- Dodatkowe pola -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title d-flex align-items-center justify-content-between">
          <span>Dodatkowe informacje</span>
          <?php if ($crm_can_write): ?>
          <a href="<?= APP_URL ?>/crm/contact/add.php?id=<?= $id ?>"
             class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2"
             title="Edytuj pola">
            <i class="bi bi-pencil" style="font-size:.75rem"></i>
          </a>
          <?php endif; ?>
        </div>
        <dl class="mb-0" style="font-size:.84rem">
          <?php foreach ($_custom_field_defs as $fd):
            $val = $_custom_field_values[(int)$fd['id']] ?? '';
            if ($val === '' && $val !== '0') continue;
          ?>
          <dt class="text-muted fw-normal mb-0" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.04em">
            <?= h($fd['label']) ?>
          </dt>
          <dd class="mb-2 fw-semibold">
            <?php if ($fd['field_type'] === 'url'): ?>
              <a href="<?= h($val) ?>" target="_blank" rel="noopener"><?= h($val) ?></a>
            <?php elseif ($fd['field_type'] === 'email'): ?>
              <a href="mailto:<?= h($val) ?>"><?= h($val) ?></a>
            <?php elseif ($fd['field_type'] === 'checkbox'): ?>
              <?= $val ? '<i class="bi bi-check-circle-fill text-success"></i> Tak' : '<i class="bi bi-x-circle text-muted"></i> Nie' ?>
            <?php elseif ($fd['field_type'] === 'textarea'): ?>
              <span style="white-space:pre-line"><?= h($val) ?></span>
            <?php else: ?>
              <?= h($val) ?>
            <?php endif; ?>
          </dd>
          <?php endforeach; ?>
        </dl>
        <?php
        $any_value = false;
        foreach ($_custom_field_defs as $fd) {
            $v = $_custom_field_values[(int)$fd['id']] ?? '';
            if ($v !== '' && $v !== '0') { $any_value = true; break; }
        }
        if (!$any_value): ?>
        <p class="text-muted small mb-0">Brak wypełnionych pól.
          <?php if ($crm_can_write): ?>
          <a href="<?= APP_URL ?>/crm/contact/add.php?id=<?= $id ?>">Uzupełnij</a>
          <?php endif; ?>
        </p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Relacje (Powiązane Konta) -->
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="crm-section-title">Powiązane konta</div>

        <?php if ($contact['relations']): ?>
          <?php foreach ($contact['relations'] as $rel):
            $rt_label = CRM_RELATION_TYPES[$rel['relation_type']] ?? $rel['relation_type'];
          ?>
          <div class="crm-relation-item">
            <div class="crm-avatar sm" aria-hidden="true"
                 style="background:<?= $rel['other_type']==='organizacja' ? 'var(--crm-navy)' : 'var(--crm-primary)' ?>">
              <?= h($rel['other_initials'] ?: '?') ?>
            </div>
            <div class="flex-grow-1 overflow-hidden">
              <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$rel['contact_b_id'] ?>"
                 class="crm-name-link" style="font-size:.83rem">
                <?= h($rel['other_name']) ?>
              </a>
              <?php if ($rel['other_org']): ?>
              <div class="crm-name-sub"><?= h($rel['other_org']) ?></div>
              <?php endif; ?>
            </div>
            <span class="crm-relation-type-badge"><?= h($rt_label) ?></span>
            <?php if ($crm_can_delete): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"        value="<?= csrf_token() ?>">
              <input type="hidden" name="_action"      value="remove_relation">
              <input type="hidden" name="relation_id"  value="<?= (int)$rel['id'] ?>">
              <button type="submit" class="btn-crm-ghost p-0 text-danger border-0"
                      style="background:none;font-size:.85rem"
                      aria-label="Usuń relację z <?= h($rel['other_name']) ?>"
                      onclick="return confirm('Usunąć tę relację?')">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
        <p class="text-muted small">Brak powiązanych kont.</p>
        <?php endif; ?>

        <!-- Dodaj relację -->
        <?php if ($crm_can_write && $all_contacts_for_relation): ?>
        <form method="post" class="mt-2 border-top pt-2">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="add_relation">
          <div class="mb-2">
            <label class="form-label visually-hidden" for="relation_target">Powiązany kontakt</label>
            <select name="relation_target" id="relation_target"
                    class="form-select form-select-sm"
                    aria-label="Wybierz kontakt do powiązania" required>
              <option value="">— Wybierz kontakt —</option>
              <?php foreach ($all_contacts_for_relation as $rc): ?>
              <option value="<?= (int)$rc['id'] ?>">
                <?= h($rc['imie_nazwisko']) ?>
                <?= $rc['organizacja'] ? ' (' . h($rc['organizacja']) . ')' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label visually-hidden" for="relation_type">Typ relacji</label>
            <select name="relation_type" id="relation_type"
                    class="form-select form-select-sm"
                    aria-label="Typ relacji">
              <?php foreach (CRM_RELATION_TYPES as $rtk => $rtv): ?>
              <option value="<?= h($rtk) ?>"><?= h($rtv) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-sm btn-crm-outline w-100" type="submit">
            <i class="bi bi-link-45deg me-1"></i>Dodaj relację
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Grupy -->
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-body">
        <div class="crm-section-title d-flex align-items-center justify-content-between">
          <span><i class="bi bi-collection me-1" aria-hidden="true"></i>Grupy</span>
          <a href="<?= APP_URL ?>/crm/groups.php" class="text-muted" style="font-size:.72rem;text-decoration:none">
            Zarządzaj
          </a>
        </div>

        <?php if ($contact['groups']): ?>
        <div class="d-flex flex-wrap gap-1 mb-2">
          <?php foreach ($contact['groups'] as $cg): ?>
          <span class="d-inline-flex align-items-center gap-1"
                style="background:<?= h($cg['color']) ?>22;border:1px solid <?= h($cg['color']) ?>55;
                       border-radius:999px;padding:.2rem .6rem;font-size:.78rem;color:<?= h($cg['color']) ?>">
            <i class="bi <?= h($cg['icon']) ?>" aria-hidden="true"></i>
            <a href="<?= APP_URL ?>/crm/group/view.php?id=<?= (int)$cg['id'] ?>"
               style="color:inherit;text-decoration:none;font-weight:600">
              <?= h($cg['name']) ?>
            </a>
            <?php if ($crm_can_write): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"     value="<?= csrf_token() ?>">
              <input type="hidden" name="_action"   value="remove_from_group">
              <input type="hidden" name="group_id"  value="<?= (int)$cg['id'] ?>">
              <button type="submit"
                      class="border-0 p-0 bg-transparent text-danger"
                      style="font-size:.65rem;line-height:1;cursor:pointer"
                      aria-label="Usuń z grupy <?= h($cg['name']) ?>">&times;</button>
            </form>
            <?php endif; ?>
          </span>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-muted small mb-2">Kontakt nie należy do żadnej grupy.</p>
        <?php endif; ?>

        <?php if ($crm_can_write):
          // Grupy, do których kontakt jeszcze nie należy
          $all_groups = CrmManager::getGroups();
          $current_group_ids = array_column($contact['groups'], 'id');
          $available_groups = array_filter($all_groups, fn($g) => !in_array($g['id'], $current_group_ids));
          if ($available_groups):
        ?>
        <form method="post" class="d-flex gap-2 mt-1">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="add_to_group">
          <label class="visually-hidden" for="group_id_select">Dodaj do grupy</label>
          <select name="group_id" id="group_id_select"
                  class="form-select form-select-sm" required
                  aria-label="Wybierz grupę">
            <option value="">Dodaj do grupy…</option>
            <?php foreach ($available_groups as $ag): ?>
            <option value="<?= (int)$ag['id'] ?>">
              <?= h($ag['name']) ?>
              (<?= (int)$ag['member_count'] ?>)
            </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-crm-outline" type="submit"
                  aria-label="Dodaj do wybranej grupy">
            <i class="bi bi-plus" aria-hidden="true"></i>
          </button>
        </form>
        <?php elseif (!$all_groups): ?>
        <a href="<?= APP_URL ?>/crm/groups.php" class="btn btn-sm btn-crm-outline w-100 mt-1">
          <i class="bi bi-plus me-1"></i>Utwórz grupę
        </a>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Wolontariat -->
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-body">
        <div class="crm-section-title d-flex align-items-center justify-content-between">
          <span><i class="bi bi-people-fill me-1" aria-hidden="true"></i>Wolontariat</span>
          <a href="<?= APP_URL ?>/contracts/wolontariat/list.php" class="text-muted"
             style="font-size:.72rem;text-decoration:none" aria-label="Lista umów wolontariatu">Wszystkie</a>
        </div>

        <?php if (!($contact['email'] ?? '')): ?>
        <p class="text-muted small mb-0">Brak adresu e-mail — nie można dopasować wolontariatu.</p>
        <?php else: ?>

        <!-- Rekrutacje -->
        <div class="mb-2">
          <div class="d-flex align-items-center justify-content-between mb-1">
            <span style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b">
              Rekrutacje
            </span>
            <?php if ($volunteer_recruitments): ?>
            <span class="badge bg-light text-secondary border" style="font-size:.65rem">
              <?= count($volunteer_recruitments) ?>
            </span>
            <?php endif; ?>
          </div>
          <?php if ($volunteer_recruitments):
            $app_statuses = [
              'new'       => ['label' => 'Nowa',     'color' => '#0176D3'],
              'reviewing' => ['label' => 'W ocenie', 'color' => '#FE9339'],
              'interview' => ['label' => 'Rozmowa',  'color' => '#7F2B8B'],
              'accepted'  => ['label' => 'Przyjęta', 'color' => '#2E844A'],
              'rejected'  => ['label' => 'Odrzucona','color' => '#E31010'],
              'withdrawn' => ['label' => 'Wycofana', 'color' => '#939393'],
            ];
            foreach ($volunteer_recruitments as $app):
              $ast = $app_statuses[$app['status']] ?? ['label' => $app['status'], 'color' => '#939393'];
          ?>
          <div class="d-flex align-items-start gap-2 p-2 border rounded mb-1"
               style="font-size:.8rem;border-radius:.4rem!important">
            <i class="bi bi-person-check mt-1 flex-shrink-0"
               style="color:<?= h($ast['color']) ?>" aria-hidden="true"></i>
            <div class="flex-grow-1 overflow-hidden">
              <a href="<?= APP_URL ?>/contracts/rekrutacja/view.php?id=<?= (int)$app['offer_id'] ?>"
                 class="fw-semibold text-dark text-decoration-none d-block"
                 style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                 title="<?= h($app['offer_title'] ?? '') ?>">
                <?= h($app['offer_title'] ?: '—') ?>
              </a>
              <div class="text-muted" style="font-size:.72rem">
                <span style="color:<?= h($ast['color']) ?>;font-weight:600"><?= h($ast['label']) ?></span>
                <?php if ($app['created_at']): ?>
                  · <?= date('d.m.Y', strtotime($app['created_at'])) ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php else: ?>
          <p class="text-muted mb-1" style="font-size:.78rem">Brak rekrutacji.</p>
          <?php endif; ?>
        </div>

        <!-- Umowy wolontariackie -->
        <div class="border-top pt-2">
          <div class="d-flex align-items-center justify-content-between mb-1">
            <span style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b">
              Umowy wolontariackie
            </span>
            <?php if ($volunteer_contracts): ?>
            <span class="badge bg-light text-secondary border" style="font-size:.65rem">
              <?= count($volunteer_contracts) ?>
            </span>
            <?php endif; ?>
          </div>
          <?php if ($volunteer_contracts):
            $contract_statuses = [
              'projekt'      => ['label' => 'Projekt',      'color' => '#64748b'],
              'podpisana'    => ['label' => 'Podpisana',    'color' => '#0176D3'],
              'w realizacji' => ['label' => 'W realizacji', 'color' => '#2E844A'],
              'zakończona'   => ['label' => 'Zakończona',   'color' => '#032D60'],
              'rozwiązana'   => ['label' => 'Rozwiązana',   'color' => '#FE9339'],
              'anulowana'    => ['label' => 'Anulowana',    'color' => '#E31010'],
            ];
            foreach ($volunteer_contracts as $wol):
              $wst = $contract_statuses[$wol['status']] ?? ['label' => $wol['status'], 'color' => '#939393'];
          ?>
          <div class="d-flex align-items-start gap-2 p-2 border rounded mb-1"
               style="font-size:.8rem;border-radius:.4rem!important">
            <i class="bi bi-file-earmark-text mt-1 flex-shrink-0"
               style="color:<?= h($wst['color']) ?>" aria-hidden="true"></i>
            <div class="flex-grow-1 overflow-hidden">
              <a href="<?= APP_URL ?>/contracts/wolontariat/view.php?id=<?= (int)$wol['id'] ?>"
                 class="fw-semibold text-dark text-decoration-none d-block"
                 style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                 title="<?= h($wol['numer_umowy'] ?? '') ?>">
                <?= h($wol['numer_umowy'] ?: '#' . $wol['id']) ?>
              </a>
              <div class="text-muted" style="font-size:.72rem">
                <span style="color:<?= h($wst['color']) ?>;font-weight:600"><?= h($wst['label']) ?></span>
                <?php if ($wol['data_od']): ?>
                  · <?= date('d.m.Y', strtotime($wol['data_od'])) ?>
                  <?php if ($wol['data_do']): ?>–<?= date('d.m.Y', strtotime($wol['data_do'])) ?><?php endif; ?>
                <?php endif; ?>
                <?php if ($wol['stanowisko']): ?>
                  <span class="d-block" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%">
                    <?= h($wol['stanowisko']) ?>
                  </span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php else: ?>
          <p class="text-muted mb-0" style="font-size:.78rem">Brak umów wolontariackich.</p>
          <?php endif; ?>
        </div>

        <?php endif; /* /email check */ ?>
      </div>
    </div>

    <!-- Działania -->
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-body">
        <div class="crm-section-title d-flex align-items-center justify-content-between">
          <span><i class="bi bi-calendar-event me-1" aria-hidden="true"></i>Działania</span>
          <a href="<?= APP_URL ?>/actions/index.php" class="text-muted" style="font-size:.72rem;text-decoration:none"
             aria-label="Lista działań">Wszystkie</a>
        </div>

        <?php if ($contact_actions): ?>
        <div class="d-flex flex-column gap-1 mb-2">
          <?php
          $action_statuses_v = [
            'planowane'       => ['label' => 'Planowane',       'color' => '#64748b'],
            'w_przygotowaniu' => ['label' => 'W przygotowaniu', 'color' => '#0176D3'],
            'w_trakcie'       => ['label' => 'W trakcie',       'color' => '#2E844A'],
            'zawieszone'      => ['label' => 'Zawieszone',      'color' => '#FE9339'],
            'zakończone'      => ['label' => 'Zakończone',      'color' => '#032D60'],
            'anulowane'       => ['label' => 'Anulowane',       'color' => '#E31010'],
          ];
          foreach ($contact_actions as $ca):
            $ast = $action_statuses_v[$ca['status']] ?? ['label' => $ca['status'], 'color' => '#939393'];
          ?>
          <div class="d-flex align-items-start gap-2 p-2 border rounded" style="font-size:.82rem;border-radius:.4rem!important">
            <i class="bi bi-calendar-event mt-1 flex-shrink-0"
               style="color:<?= h($ast['color']) ?>" aria-hidden="true"></i>
            <div class="flex-grow-1 overflow-hidden">
              <a href="<?= APP_URL ?>/actions/view.php?id=<?= (int)$ca['id'] ?>"
                 class="fw-semibold text-dark text-decoration-none d-block"
                 style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                 title="<?= h($ca['nazwa']) ?>">
                <?= h($ca['nazwa']) ?>
              </a>
              <div class="text-muted" style="font-size:.72rem">
                <span style="color:<?= h($ast['color']) ?>;font-weight:600"><?= h($ast['label']) ?></span>
                <?php if ($ca['data_od']): ?> · <?= date('d.m.Y', strtotime($ca['data_od'])) ?><?php endif; ?>
                <?php if ($ca['rola'] !== 'uczestnik'): ?>
                  · <em><?= h($ca['rola']) ?></em>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($crm_can_write): ?>
            <form method="post" class="flex-shrink-0">
              <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
              <input type="hidden" name="_action"    value="unlink_from_action">
              <input type="hidden" name="action_id"  value="<?= (int)$ca['id'] ?>">
              <button type="submit"
                      class="border-0 p-0 bg-transparent text-danger"
                      style="font-size:.75rem;cursor:pointer;line-height:1"
                      aria-label="Usuń z działania <?= h($ca['nazwa']) ?>">&times;</button>
            </form>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-muted small mb-2">Brak powiązanych działań.</p>
        <?php endif; ?>

        <?php if ($crm_can_write && $available_actions): ?>
        <form method="post" aria-label="Dodaj kontakt do działania">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="link_to_action">
          <div class="mb-1">
            <label class="visually-hidden" for="action_id_sel">Wybierz działanie</label>
            <select name="action_id" id="action_id_sel"
                    class="form-select form-select-sm" required
                    aria-label="Wybierz działanie">
              <option value="">Dodaj do działania…</option>
              <?php foreach ($available_actions as $av):
                $lbl = $av['data_od'] ? date('Y', strtotime($av['data_od'])) . ' · ' : '';
              ?>
              <option value="<?= (int)$av['id'] ?>">
                <?= h($lbl . $av['nazwa']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-1">
            <label class="visually-hidden" for="action_rola_sel">Rola</label>
            <select name="rola" id="action_rola_sel" class="form-select form-select-sm"
                    aria-label="Rola w działaniu">
              <option value="uczestnik">Uczestnik</option>
              <option value="wolontariusz">Wolontariusz</option>
              <option value="prelegent">Prelegent</option>
              <option value="koordynator">Koordynator</option>
              <option value="beneficjent">Beneficjent</option>
              <option value="inny">Inny</option>
            </select>
          </div>
          <button class="btn btn-sm btn-crm-outline w-100" type="submit">
            <i class="bi bi-calendar-plus me-1"></i>Dodaj do działania
          </button>
        </form>
        <?php elseif ($crm_can_write && !$all_actions_raw): ?>
        <a href="<?= APP_URL ?>/actions/add.php" class="btn btn-sm btn-crm-outline w-100">
          <i class="bi bi-plus me-1"></i>Utwórz działanie
        </a>
        <?php endif; ?>

      </div>
    </div>

  </div><!-- /lewa -->

  <!-- ── Środkowa: planowane działania + notatki + historia ─────────────────── -->
  <div class="col-lg-6">

    <!-- Planowane działania -->
    <?php
    $activities = db_all(
        "SELECT a.*, u.name AS assigned_name, u.first_name AS assigned_fn, u.last_name AS assigned_ln
         FROM crm_activities a
         LEFT JOIN users u ON u.id=a.assigned_to
         WHERE a.contact_id=? ORDER BY a.status ASC, a.scheduled_at ASC NULLS LAST, a.created_at DESC
         LIMIT 20",
        [(int)$id]
    );
    $act_type_cfg = [
        'call'    => ['label'=>'Telefon',   'color'=>'#2E844A','bg'=>'#EFF7ED','icon'=>'bi-telephone-fill'],
        'email'   => ['label'=>'E-mail',    'color'=>'#0176D3','bg'=>'#EEF4FF','icon'=>'bi-envelope-fill'],
        'meeting' => ['label'=>'Spotkanie', 'color'=>'#7C3AED','bg'=>'#F5F3FF','icon'=>'bi-people-fill'],
        'task'    => ['label'=>'Zadanie',   'color'=>'#D97706','bg'=>'#FEF3E2','icon'=>'bi-check2-square'],
        'demo'    => ['label'=>'Demo',      'color'=>'#0891B2','bg'=>'#ECFEFF','icon'=>'bi-display'],
        'lunch'   => ['label'=>'Lunch',     'color'=>'#BE185D','bg'=>'#FDF2F8','icon'=>'bi-cup-hot'],
        'other'   => ['label'=>'Inne',      'color'=>'#6B7280','bg'=>'#F3F4F6','icon'=>'bi-three-dots'],
    ];
    $pending_acts = array_filter($activities, fn($a)=>$a['status']==='planned');
    $done_acts    = array_filter($activities, fn($a)=>$a['status']==='done');
    $editors_act  = db_all("SELECT id, CASE WHEN first_name!='' AND last_name!='' THEN first_name||' '||last_name ELSE name END AS n FROM users WHERE role IN ('admin','editor','crm_user') AND is_active=1 ORDER BY n");
    ?>
    <div class="card border-0 shadow-sm mb-3" id="activities-section">
      <div class="card-body">
        <div class="crm-section-title d-flex align-items-center justify-content-between mb-2">
          <span><i class="bi bi-lightning-charge-fill me-1" style="color:#D97706"></i>Planowane działania</span>
          <div class="d-flex align-items-center gap-2">
            <span class="text-muted" style="font-size:.78rem;text-transform:none;letter-spacing:0"><?= count($pending_acts) ?> oczekuje</span>
            <?php if ($crm_can_write): ?>
            <button class="btn btn-sm py-0 px-2" style="background:#D97706;color:#fff;font-size:.72rem;border-radius:6px"
                    onclick="ActivityUI.openNew()" type="button">
              <i class="bi bi-plus me-1"></i>Dodaj
            </button>
            <?php endif; ?>
          </div>
        </div>

        <!-- Lista oczekujących -->
        <?php if ($pending_acts): ?>
        <div id="activity-list-pending">
          <?php foreach ($pending_acts as $act):
            $atc = $act_type_cfg[$act['type']] ?? $act_type_cfg['other'];
            $an  = trim(($act['assigned_fn']??'').' '.($act['assigned_ln']??'')) ?: ($act['assigned_name']??'');
          ?>
          <div class="act-row" data-id="<?= (int)$act['id'] ?>">
            <div class="act-type-icon" style="background:<?= $atc['bg'] ?>;color:<?= $atc['color'] ?>">
              <i class="bi <?= $atc['icon'] ?>"></i>
            </div>
            <div class="act-info">
              <div class="act-title"><?= h($act['title']) ?></div>
              <div class="act-meta">
                <?= $atc['label'] ?>
                <?php if ($act['scheduled_at']): ?>
                · <i class="bi bi-clock me-1"></i><?= date('d.m.Y H:i', strtotime($act['scheduled_at'])) ?>
                <?php endif; ?>
                <?php if ($an): ?>· <?= h($an) ?><?php endif; ?>
              </div>
              <?php if ($act['description']): ?>
              <div class="act-desc"><?= h($act['description']) ?></div>
              <?php endif; ?>
            </div>
            <?php if ($crm_can_write): ?>
            <div class="act-actions">
              <button class="btn btn-xs btn-success py-0 px-2" style="font-size:.7rem"
                      onclick="ActivityUI.complete(<?= (int)$act['id'] ?>)" title="Oznacz jako wykonane">
                <i class="bi bi-check-lg"></i>
              </button>
              <button class="btn btn-xs btn-outline-danger py-0 px-1" style="font-size:.7rem"
                      onclick="ActivityUI.del(<?= (int)$act['id'] ?>)" title="Usuń">
                <i class="bi bi-trash"></i>
              </button>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-muted text-center py-2 mb-2" style="font-size:.8rem" id="no-pending-msg">
          <i class="bi bi-check-circle me-1 text-success"></i>Brak zaplanowanych działań
        </div>
        <?php endif; ?>

        <!-- Wykonane (zwijane) -->
        <?php if ($done_acts): ?>
        <details class="mt-2">
          <summary style="font-size:.75rem;color:#9CA3AF;cursor:pointer;list-style:none;display:flex;align-items:center;gap:.4rem">
            <i class="bi bi-chevron-right" style="font-size:.65rem;transition:transform .2s" id="done-chevron"></i>
            Wykonane (<?= count($done_acts) ?>)
          </summary>
          <?php foreach ($done_acts as $act):
            $atc = $act_type_cfg[$act['type']] ?? $act_type_cfg['other'];
          ?>
          <div class="act-row done mt-1">
            <div class="act-type-icon" style="background:#F3F4F6;color:#9CA3AF">
              <i class="bi <?= $atc['icon'] ?>"></i>
            </div>
            <div class="act-info">
              <div class="act-title" style="text-decoration:line-through;color:#9CA3AF"><?= h($act['title']) ?></div>
              <?php if ($act['outcome']): ?>
              <div class="act-desc"><?= h($act['outcome']) ?></div>
              <?php endif; ?>
              <div class="act-meta"><?= $act['completed_at'] ? date('d.m.Y', strtotime($act['completed_at'])) : '' ?></div>
            </div>
            <?php if ($crm_can_write): ?>
            <div class="act-actions">
              <button class="btn btn-xs btn-outline-secondary py-0 px-1" style="font-size:.7rem"
                      onclick="ActivityUI.reopen(<?= (int)$act['id'] ?>)" title="Wróć do zaplanowanych">
                <i class="bi bi-arrow-counterclockwise"></i>
              </button>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </details>
        <?php endif; ?>

      </div>
    </div>

    <!-- Modal: nowe działanie -->
    <?php if ($crm_can_write): ?>
    <div class="modal fade" id="activityModal" tabindex="-1" aria-labelledby="actModalLabel" aria-modal="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header py-2">
            <h5 class="modal-title" id="actModalLabel" style="font-size:.95rem">
              <i class="bi bi-lightning-charge-fill me-2" style="color:#D97706"></i>Nowe działanie
            </h5>
            <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <!-- Typ działania — karty -->
            <div class="d-flex flex-wrap gap-2 mb-3" id="actTypePicker">
              <?php foreach ($act_type_cfg as $tv=>$tc): ?>
              <button type="button" class="act-type-pill <?= $tv==='call'?'active':'' ?>"
                      data-type="<?= $tv ?>"
                      style="--at-color:<?= $tc['color'] ?>;--at-bg:<?= $tc['bg'] ?>"
                      onclick="ActivityUI.pickType(this)">
                <i class="bi <?= $tc['icon'] ?>"></i>
                <span><?= $tc['label'] ?></span>
              </button>
              <?php endforeach; ?>
            </div>
            <input type="hidden" id="actType" value="call">

            <div class="mb-2">
              <label class="form-label fw-semibold small">Tytuł <span class="text-danger">*</span></label>
              <input id="actTitle" type="text" class="form-control form-control-sm"
                     placeholder="np. Rozmowa telefoniczna, Ustalenie warunków…">
            </div>

            <div class="row g-2 mb-2">
              <div class="col-sm-7">
                <label class="form-label fw-semibold small">Termin</label>
                <input id="actDate" type="datetime-local" class="form-control form-control-sm">
              </div>
              <div class="col-sm-5">
                <label class="form-label fw-semibold small">Czas trwania (min)</label>
                <select id="actDuration" class="form-select form-select-sm">
                  <option value="">— —</option>
                  <option value="15">15 min</option>
                  <option value="30">30 min</option>
                  <option value="60" selected>1 godz.</option>
                  <option value="90">1,5 godz.</option>
                  <option value="120">2 godz.</option>
                </select>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label fw-semibold small">Przypisz do</label>
              <select id="actAssignee" class="form-select form-select-sm">
                <option value="">— aktualny użytkownik —</option>
                <?php foreach ($editors_act as $ed): ?>
                <option value="<?= (int)$ed['id'] ?>"><?= h($ed['n']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label fw-semibold small">Notatka</label>
              <textarea id="actDesc" class="form-control form-control-sm" rows="2"
                        placeholder="Cel działania, szczegóły…"></textarea>
            </div>
            <div id="actError" class="alert alert-danger py-1 mt-2" style="display:none;font-size:.8rem"></div>
          </div>
          <div class="modal-footer py-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
            <button type="button" class="btn btn-sm" id="actSaveBtn"
                    style="background:#D97706;color:#fff;border:none"
                    onclick="ActivityUI.save()">
              <i class="bi bi-check-lg me-1"></i>Zaplanuj działanie
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: wynik działania -->
    <div class="modal fade" id="actCompleteModal" tabindex="-1" aria-modal="true">
      <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
          <div class="modal-header py-2">
            <h5 class="modal-title" style="font-size:.9rem"><i class="bi bi-check-circle-fill text-success me-2"></i>Wykonano działanie</h5>
            <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="completeActId" value="">
            <label class="form-label fw-semibold small">Wynik / notatka (opcjonalnie)</label>
            <textarea id="actOutcome" class="form-control form-control-sm" rows="3"
                      placeholder="Efekt rozmowy, następny krok…"></textarea>
          </div>
          <div class="modal-footer py-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
            <button type="button" class="btn btn-success btn-sm" onclick="ActivityUI.confirmComplete()">
              <i class="bi bi-check-lg me-1"></i>Oznacz jako wykonane
            </button>
          </div>
        </div>
      </div>
    </div>

    <style>
    .act-row { display:flex;align-items:flex-start;gap:.6rem;padding:.55rem .5rem;border-radius:8px;margin-bottom:.3rem;transition:background .1s }
    .act-row:hover { background:#F9FAFB }
    .act-row.done { opacity:.6 }
    .act-type-icon { width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0;margin-top:.05rem }
    .act-info { flex:1;min-width:0 }
    .act-title { font-size:.84rem;font-weight:600;color:#111827;overflow:hidden;text-overflow:ellipsis;white-space:nowrap }
    .act-meta  { font-size:.72rem;color:#9CA3AF;margin-top:1px }
    .act-desc  { font-size:.76rem;color:#6B7280;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap }
    .act-actions { display:flex;gap:.25rem;flex-shrink:0;align-items:flex-start;margin-top:.05rem }
    .act-type-pill { display:flex;align-items:center;gap:.35rem;padding:.3rem .65rem;border-radius:2rem;border:1.5px solid #E5E7EB;font-size:.74rem;font-weight:600;color:#6B7280;background:#fff;cursor:pointer;transition:all .12s }
    .act-type-pill:hover { border-color:#9CA3AF;color:#374151 }
    .act-type-pill.active { border-color:var(--at-color);background:var(--at-bg);color:var(--at-color) }
    .act-type-pill i { font-size:.85rem }
    details summary::-webkit-details-marker { display:none }
    details[open] #done-chevron { transform:rotate(90deg) }
    </style>

    <script>
    const ActivityUI = (function() {
      const API = '<?= APP_URL ?>/crm/api/activities.php';
      const CONTACT_ID = <?= (int)$id ?>;

      function req(payload) {
        return fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) })
          .then(r=>r.json());
      }

      function openNew() {
        document.getElementById('actTitle').value = '';
        document.getElementById('actDate').value  = '';
        document.getElementById('actOutcome').value = '';
        document.getElementById('actError').style.display = 'none';
        document.getElementById('actDuration').value = '60';
        document.getElementById('actAssignee').value = '';
        document.getElementById('actDesc').value = '';
        pickType(document.querySelector('.act-type-pill[data-type="call"]'));
        new bootstrap.Modal(document.getElementById('activityModal')).show();
        setTimeout(()=>document.getElementById('actTitle').focus(), 200);
      }

      function pickType(btn) {
        document.querySelectorAll('.act-type-pill').forEach(p=>p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('actType').value = btn.dataset.type;
      }

      function save() {
        const title = document.getElementById('actTitle').value.trim();
        if (!title) {
          const er = document.getElementById('actError');
          er.textContent = 'Wpisz tytuł działania.';
          er.style.display = '';
          return;
        }
        const btn = document.getElementById('actSaveBtn');
        btn.disabled = true;

        req({
          action:       'create',
          contact_id:   CONTACT_ID,
          type:         document.getElementById('actType').value,
          title,
          description:  document.getElementById('actDesc').value.trim() || null,
          scheduled_at: document.getElementById('actDate').value || null,
          duration_min: document.getElementById('actDuration').value || null,
          assigned_to:  document.getElementById('actAssignee').value || null,
        })
        .then(res=>{
          btn.disabled = false;
          if (res.ok) {
            bootstrap.Modal.getInstance(document.getElementById('activityModal'))?.hide();
            location.reload();
          } else {
            document.getElementById('actError').textContent = res.error || 'Błąd.';
            document.getElementById('actError').style.display = '';
          }
        })
        .catch(()=>{ btn.disabled=false; });
      }

      function complete(id) {
        document.getElementById('completeActId').value = id;
        document.getElementById('actOutcome').value = '';
        new bootstrap.Modal(document.getElementById('actCompleteModal')).show();
        setTimeout(()=>document.getElementById('actOutcome').focus(), 200);
      }

      function confirmComplete() {
        const id = parseInt(document.getElementById('completeActId').value);
        req({ action:'complete', id, outcome: document.getElementById('actOutcome').value.trim() })
          .then(res=>{ if(res.ok){ bootstrap.Modal.getInstance(document.getElementById('actCompleteModal'))?.hide(); location.reload(); } });
      }

      function reopen(id) {
        req({ action:'reopen', id }).then(res=>{ if(res.ok) location.reload(); });
      }

      function del(id) {
        if (!confirm('Usunąć to działanie?')) return;
        req({ action:'delete', id }).then(res=>{ if(res.ok) location.reload(); });
      }

      return { openNew, pickType, save, complete, confirmComplete, reopen, del };
    })();
    </script>
    <?php endif; ?>

    <!-- Notatki -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title d-flex align-items-center justify-content-between">
          Notatki
          <span class="text-muted" style="font-size:.78rem;text-transform:none;letter-spacing:0">
            <?= count($contact['notes']) ?>
          </span>
        </div>

        <?php if ($crm_can_write): ?>
        <form method="post" class="mb-3">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="add_note">
          <label for="note_body" class="visually-hidden">Treść notatki</label>
          <textarea id="note_body" name="note_body" class="form-control form-control-sm mb-2"
                    rows="3" placeholder="Dodaj notatkę…"
                    aria-label="Treść notatki" required></textarea>
          <div class="d-flex align-items-center justify-content-between">
            <div class="form-check mb-0">
              <input type="checkbox" name="note_pinned" id="note_pinned"
                     class="form-check-input" value="1">
              <label class="form-check-label small" for="note_pinned" style="font-size:.8rem">
                <i class="bi bi-pin-angle me-1"></i>Przypnij
              </label>
            </div>
            <button class="btn btn-sm btn-crm-primary" type="submit">
              <i class="bi bi-sticky me-1"></i>Dodaj
            </button>
          </div>
        </form>
        <?php endif; ?>

        <?php if ($contact['notes']): ?>
          <?php foreach ($contact['notes'] as $note): ?>
          <div class="crm-note-item <?= $note['is_pinned'] ? 'pinned' : '' ?>" role="article">
            <?php if ($note['is_pinned']): ?>
            <i class="bi bi-pin-angle-fill text-success me-1" aria-label="Przypięta notatka"></i>
            <?php endif; ?>
            <?= nl2br(h($note['body'])) ?>
            <div class="crm-note-meta d-flex align-items-center justify-content-between">
              <span>
                <i class="bi bi-person me-1" aria-hidden="true"></i><?= h($note['author_name'] ?? '—') ?>
                · <?= date_pl($note['created_at']) ?>
              </span>
              <?php if ($crm_can_delete): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                <input type="hidden" name="_action"  value="delete_note">
                <input type="hidden" name="note_id"  value="<?= (int)$note['id'] ?>">
                <button class="btn btn-link p-0 text-danger" style="font-size:.75rem"
                        aria-label="Usuń notatkę"
                        onclick="return confirm('Usunąć notatkę?')">Usuń</button>
              </form>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
        <p class="text-muted small">Brak notatek.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Historia komunikacji -->
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="crm-section-title d-flex align-items-center justify-content-between">
          Historia komunikacji
          <button type="button" class="btn btn-sm btn-crm-outline"
                  style="font-size:.72rem;padding:.15rem .5rem;text-transform:none;letter-spacing:0"
                  onclick="openCommModal(<?= $id ?>,'email')"
                  aria-label="Wyślij nową wiadomość">
            <i class="bi bi-send me-1"></i>Wyślij
          </button>
        </div>

        <?php if ($contact['communications']): ?>
          <?php foreach ($contact['communications'] as $comm):
            $ch_info = CRM_CHANNELS[$comm['channel']] ?? ['label' => $comm['channel'], 'icon' => 'bi-chat'];
          ?>
          <div class="crm-comm-item">
            <div class="crm-comm-icon <?= h($comm['channel']) ?>" aria-hidden="true">
              <i class="bi <?= h($ch_info['icon']) ?>"></i>
            </div>
            <div class="flex-grow-1">
              <div style="font-size:.82rem">
                <span class="fw-semibold"><?= h($ch_info['label']) ?></span>
                <?php if ($comm['subject']): ?>
                — <span class="text-muted"><?= h($comm['subject']) ?></span>
                <?php endif; ?>
              </div>
              <div class="text-muted" style="font-size:.78rem;margin-top:2px">
                <?= h(mb_substr(strip_tags($comm['body']), 0, 120)) ?>
                <?= mb_strlen($comm['body']) > 120 ? '…' : '' ?>
              </div>
              <div class="crm-note-meta">
                <i class="bi bi-person me-1" aria-hidden="true"></i><?= h($comm['sender_name'] ?? '—') ?>
                · <?= date_pl($comm['sent_at']) ?>
                <span class="badge ms-1" style="font-size:.65rem;background:<?= $comm['status']==='wysłana'?'#EFF7ED;color:#2E844A':'#fef2f2;color:#dc2626' ?>">
                  <?= h($comm['status']) ?>
                </span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
        <p class="text-muted small">Brak historii komunikacji.</p>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /środkowa -->

  <!-- ── Prawa: quick actions + meta ────────────────────────────────────────── -->
  <div class="col-lg-3">

    <!-- Akcje -->
    <?php if ($crm_can_write): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title">Akcje</div>
        <div class="d-grid gap-2">
          <a href="<?= APP_URL ?>/crm/contact/add.php?id=<?= $id ?>"
             class="btn btn-sm btn-crm-outline">
            <i class="bi bi-pencil me-1"></i>Edytuj dane
          </a>
          <button type="button" class="btn btn-sm btn-crm-outline"
                  onclick="openCommModal(<?= $id ?>,'email')">
            <i class="bi bi-envelope me-1"></i>Wyślij e-mail
          </button>
          <button type="button" class="btn btn-sm btn-crm-outline"
                  onclick="openCommModal(<?= $id ?>,'sms')">
            <i class="bi bi-phone me-1"></i>Wyślij SMS
          </button>
          <?php if ($contact['email']): ?>
          <a href="mailto:<?= h($contact['email']) ?>"
             class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-envelope-at me-1"></i>Otwórz w kliencie mail
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Meta (info o rekordzie) -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body" style="font-size:.8rem">
        <div class="crm-section-title">Informacje o rekordzie</div>
        <table class="table table-sm table-borderless mb-0" style="font-size:.8rem">
          <tbody>
            <tr><td class="text-muted pe-2">ID</td><td>#<?= $id ?></td></tr>
            <tr><td class="text-muted pe-2">Typ</td>
                <td><?= $contact['type'] === 'organizacja' ? 'Organizacja' : 'Osoba' ?></td></tr>
            <tr><td class="text-muted pe-2">Źródło</td><td><?= h(ucfirst($contact['source'])) ?></td></tr>
            <tr><td class="text-muted pe-2">Dodano</td><td><?= date_pl($contact['created_at']) ?></td></tr>
            <tr><td class="text-muted pe-2">Zmieniono</td><td><?= date_pl($contact['updated_at']) ?></td></tr>
            <?php if ($contact['synced_at']): ?>
            <tr><td class="text-muted pe-2">Zsync.</td><td><?= date_pl($contact['synced_at']) ?></td></tr>
            <?php endif; ?>
            <?php if ($contact['person_id']): ?>
            <tr><td class="text-muted pe-2">Osoba</td>
                <td><a href="<?= APP_URL ?>/persons/view.php?id=<?= (int)$contact['person_id'] ?>">#<?= (int)$contact['person_id'] ?></a></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Sprawy -->
    <?php
    $contact_cases = db_all(
        "SELECT * FROM crm_cases WHERE contact_id=? ORDER BY updated_at DESC LIMIT 10",
        [(int)$id]
    );
    $case_status_cfg = [
        'open'        => ['label'=>'Otwarta',   'color'=>'#2563EB','bg'=>'#EEF4FF'],
        'in_progress' => ['label'=>'W toku',    'color'=>'#D97706','bg'=>'#FEF3E2'],
        'closed'      => ['label'=>'Zamknięta', 'color'=>'#2E844A','bg'=>'#EFF7ED'],
        'cancelled'   => ['label'=>'Anulowana', 'color'=>'#9CA3AF','bg'=>'#F3F4F6'],
    ];
    ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title d-flex align-items-center justify-content-between">
          <span><i class="bi bi-briefcase me-1" style="color:#0176D3"></i>Sprawy</span>
          <div class="d-flex align-items-center gap-2">
            <span class="text-muted" style="font-size:.78rem;text-transform:none;letter-spacing:0"><?= count($contact_cases) ?></span>
            <?php if ($crm_can_write): ?>
            <a href="<?= APP_URL ?>/crm/cases/add.php?contact_id=<?= (int)$id ?>"
               class="btn btn-crm-outline btn-sm py-0 px-2" style="font-size:.72rem;text-transform:none;letter-spacing:0">
              <i class="bi bi-plus me-1"></i>Nowa sprawa
            </a>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($contact_cases): ?>
        <div style="max-height:260px;overflow-y:auto">
          <?php foreach ($contact_cases as $cs):
            $csc = $case_status_cfg[$cs['status']] ?? $case_status_cfg['open'];
          ?>
          <a href="<?= APP_URL ?>/crm/cases/view.php?id=<?= (int)$cs['id'] ?>"
             class="d-flex align-items-center gap-2 py-2 border-bottom text-decoration-none"
             style="color:#111827;font-size:.83rem">
            <span style="width:7px;height:7px;border-radius:50%;background:<?= $csc['color'] ?>;flex-shrink:0"></span>
            <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600">
              <?= h($cs['title']) ?>
            </span>
            <span style="display:inline-flex;align-items:center;padding:.1rem .5rem;border-radius:2rem;font-size:.68rem;font-weight:600;background:<?= $csc['bg'] ?>;color:<?= $csc['color'] ?>;white-space:nowrap">
              <?= $csc['label'] ?>
            </span>
          </a>
          <?php endforeach; ?>
        </div>
        <div class="mt-2">
          <a href="<?= APP_URL ?>/crm/cases/index.php?contact_id=<?= (int)$id ?>"
             class="btn btn-crm-outline btn-sm w-100" style="font-size:.78rem">
            Wszystkie sprawy kontaktu
          </a>
        </div>
        <?php else: ?>
        <div class="text-muted text-center py-3" style="font-size:.82rem">
          <i class="bi bi-briefcase d-block mb-1 opacity-25" style="font-size:1.4rem"></i>
          Brak spraw
          <?php if ($crm_can_write): ?>
          <br><a href="<?= APP_URL ?>/crm/cases/add.php?contact_id=<?= (int)$id ?>" style="font-size:.78rem">Utwórz pierwszą sprawę →</a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Usuń kontakt -->
    <?php if ($crm_can_delete): ?>
    <div class="card border-0 shadow-sm border-danger-subtle">
      <div class="card-body">
        <div class="crm-section-title text-danger">Strefa zagrożenia</div>
        <form method="post" onsubmit="return confirm('Usunąć kontakt <?= h(addslashes($contact['imie_nazwisko'])) ?>?')">
          <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
          <input type="hidden" name="_action"    value="delete">
          <input type="hidden" name="contact_id" value="<?= $id ?>">
          <button class="btn btn-outline-danger btn-sm w-100" type="submit"
                  aria-label="Usuń kontakt <?= h($contact['imie_nazwisko']) ?>">
            <i class="bi bi-trash me-1"></i>Usuń kontakt
          </button>
        </form>
        <p class="text-muted mt-2 mb-0" style="font-size:.72rem">
          Kontakt zostanie ukryty (soft-delete). Dane nie zostaną trwale skasowane.
        </p>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /prawa -->

</div><!-- /row -->

<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
