<?php
/**
 * crm/contact/view.php — Karta kontaktu CRM.
 *
 * AJAX: każda sekcja (notatki, tagi, relacje, grupy, działania) odświeżana
 * niezależnie bez przeładowania strony.
 * GET  ?id=X&_section=name  → JSON { ok, html }
 * POST z X-Requested-With: XMLHttpRequest → JSON { ok, section, html }
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/address.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_offers.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_office.php';

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

// ── Funkcje ładowania danych pomocniczych ─────────────────────────────────────
function _cv_load_activities(int $id): array {
    return db_all(
        "SELECT a.*, u.name AS assigned_name,
                u.first_name AS assigned_fn, u.last_name AS assigned_ln
         FROM crm_activities a
         LEFT JOIN users u ON u.id = a.assigned_to
         WHERE a.contact_id = ?
         ORDER BY a.status ASC, a.scheduled_at ASC NULLS LAST, a.created_at DESC
         LIMIT 20",
        [$id]
    );
}

function _cv_activity_cfg(): array {
    return [
        'call'    => ['label' => 'Telefon',   'color' => '#2E844A', 'bg' => '#EFF7ED', 'icon' => 'bi-telephone-fill'],
        'email'   => ['label' => 'E-mail',    'color' => '#0176D3', 'bg' => '#EEF4FF', 'icon' => 'bi-envelope-fill'],
        'meeting' => ['label' => 'Spotkanie', 'color' => '#7C3AED', 'bg' => '#F5F3FF', 'icon' => 'bi-people-fill'],
        'task'    => ['label' => 'Zadanie',   'color' => '#D97706', 'bg' => '#FEF3E2', 'icon' => 'bi-check2-square'],
        'demo'    => ['label' => 'Demo',      'color' => '#0891B2', 'bg' => '#ECFEFF', 'icon' => 'bi-display'],
        'lunch'   => ['label' => 'Lunch',     'color' => '#BE185D', 'bg' => '#FDF2F8', 'icon' => 'bi-cup-hot'],
        'other'   => ['label' => 'Inne',      'color' => '#6B7280', 'bg' => '#F3F4F6', 'icon' => 'bi-three-dots'],
    ];
}

// ── Renderery sekcji (używane zarówno przez pełną stronę jak i AJAX) ──────────

function _cv_tags_html(array $contact, int $id, bool $can_w, bool $can_d): string {
    ob_start(); ?>
    <div id="crm-section-tags">
      <?php if ($contact['tags']): ?>
      <div class="crm-tags-list mb-2" aria-label="Tagi kontaktu">
        <?php foreach ($contact['tags'] as $t): ?>
        <span class="crm-tag d-inline-flex align-items-center gap-1">
          <?= h($t['tag']) ?>
          <?php if ($can_w): ?>
          <form method="post" class="d-inline" data-ajax-section="tags">
            <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="remove_tag">
            <input type="hidden" name="tag"     value="<?= h($t['tag']) ?>">
            <button type="submit"
                    class="border-0 p-0 bg-transparent text-danger"
                    style="font-size:.7rem;line-height:1;cursor:pointer"
                    aria-label="Usuń tag <?= h($t['tag']) ?>">&times;</button>
          </form>
          <?php endif; ?>
        </span>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="text-muted small mb-2" id="cv-tags-empty">Brak tagów.</p>
      <?php endif; ?>
      <?php if ($can_w): ?>
      <form method="post" class="d-flex gap-2" data-ajax-section="tags">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="add_tag">
        <input type="text" name="tag" class="form-control form-control-sm"
               placeholder="Nowy tag…" aria-label="Dodaj tag"
               pattern="[a-zA-Z0-9ąćęłńóśźżĄĆĘŁŃÓŚŹŻ\-_]{1,30}" maxlength="30"
               autocomplete="off">
        <button class="btn btn-sm btn-crm-outline flex-shrink-0" type="submit" aria-label="Dodaj tag">
          <i class="bi bi-plus" aria-hidden="true"></i>
        </button>
      </form>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

function _cv_notes_html(array $contact, int $id, bool $can_w, bool $can_d): string {
    ob_start(); ?>
    <div id="crm-section-notes" aria-live="polite">
      <?php if ($can_w): ?>
      <form method="post" class="mb-3" data-ajax-section="notes" id="cv-note-form">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="add_note">
        <label for="cv_note_body" class="visually-hidden">Treść notatki</label>
        <textarea id="cv_note_body" name="note_body"
                  class="form-control form-control-sm mb-2 cv-growing-textarea"
                  rows="2" placeholder="Dodaj notatkę…"
                  aria-label="Treść notatki" required></textarea>
        <div class="d-flex align-items-center justify-content-between">
          <div class="form-check mb-0">
            <input type="checkbox" name="note_pinned" id="cv_note_pinned"
                   class="form-check-input" value="1">
            <label class="form-check-label small" for="cv_note_pinned" style="font-size:.8rem">
              <i class="bi bi-pin-angle me-1" aria-hidden="true"></i>Przypnij
            </label>
          </div>
          <button class="btn btn-sm btn-crm-primary" type="submit">
            <i class="bi bi-sticky me-1" aria-hidden="true"></i>Zapisz
          </button>
        </div>
      </form>
      <?php endif; ?>
      <?php if ($contact['notes']): ?>
        <?php foreach ($contact['notes'] as $note): ?>
        <div class="crm-note-item <?= $note['is_pinned'] ? 'pinned' : '' ?>" role="article"
             aria-label="Notatka<?= $note['is_pinned'] ? ' (przypięta)' : '' ?>">
          <?php if ($note['is_pinned']): ?>
          <i class="bi bi-pin-angle-fill text-success me-1" aria-hidden="true"></i>
          <?php endif; ?>
          <?= nl2br(h($note['body'])) ?>
          <div class="crm-note-meta d-flex align-items-center justify-content-between mt-1">
            <span>
              <i class="bi bi-person me-1" aria-hidden="true"></i><?= h($note['author_name'] ?? '—') ?>
              · <?= date_pl($note['created_at']) ?>
            </span>
            <?php if ($can_d): ?>
            <form method="post" class="d-inline" data-ajax-section="notes">
              <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete_note">
              <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
              <button class="btn btn-link p-0 text-danger" style="font-size:.75rem"
                      aria-label="Usuń tę notatkę">Usuń</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
      <p class="text-muted small mb-0">Brak notatek.</p>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

function _cv_relations_html(array $contact, int $id, bool $can_w, bool $can_d, array $all_contacts): string {
    ob_start(); ?>
    <div id="crm-section-relations" aria-live="polite">
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
          <?php if ($can_d): ?>
          <form method="post" class="d-inline" data-ajax-section="relations">
            <input type="hidden" name="_csrf"       value="<?= csrf_token() ?>">
            <input type="hidden" name="_action"     value="remove_relation">
            <input type="hidden" name="relation_id" value="<?= (int)$rel['id'] ?>">
            <button type="submit"
                    class="border-0 p-0 bg-transparent text-danger"
                    style="background:none;font-size:.85rem;cursor:pointer"
                    aria-label="Usuń relację z <?= h($rel['other_name']) ?>">
              <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
      <p class="text-muted small mb-2">Brak powiązanych kont.</p>
      <?php endif; ?>
      <?php if ($can_w && $all_contacts): ?>
      <form method="post" class="mt-2 border-top pt-2" data-ajax-section="relations">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="add_relation">
        <label class="visually-hidden" for="cv_rel_target">Powiązany kontakt</label>
        <select name="relation_target" id="cv_rel_target"
                class="form-select form-select-sm mb-2" required aria-label="Wybierz kontakt">
          <option value="">— Wybierz kontakt —</option>
          <?php foreach ($all_contacts as $rc): ?>
          <option value="<?= (int)$rc['id'] ?>">
            <?= h($rc['imie_nazwisko']) ?><?= $rc['organizacja'] ? ' (' . h($rc['organizacja']) . ')' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="cv_rel_type">Typ relacji</label>
        <select name="relation_type" id="cv_rel_type"
                class="form-select form-select-sm mb-2" aria-label="Typ relacji">
          <?php foreach (CRM_RELATION_TYPES as $rtk => $rtv): ?>
          <option value="<?= h($rtk) ?>"><?= h($rtv) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-crm-outline w-100" type="submit">
          <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Dodaj relację
        </button>
      </form>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

/** Osoby kontaktowe podmiotu — dowolna liczba osób do jednej firmy/organizacji. */
function _cv_persons_html(array $contact, int $id, bool $can_w, bool $can_d): string {
    $persons = $contact['persons'] ?? [];
    ob_start(); ?>
    <div id="crm-section-persons" aria-live="polite">
      <?php if ($persons): ?>
      <ul class="list-unstyled mb-2">
        <?php foreach ($persons as $p): ?>
        <li class="crm-relation-item align-items-start">
          <div class="crm-avatar sm" aria-hidden="true" style="background:var(--crm-primary)">
            <?= h(CrmManager::makeInitials($p['imie_nazwisko'])) ?>
          </div>
          <div class="flex-grow-1 overflow-hidden">
            <div style="font-size:.83rem;font-weight:600">
              <?= h($p['imie_nazwisko']) ?>
              <?php if (!empty($p['is_primary'])): ?>
              <span class="cv-chip" title="Osoba główna — jej nazwisko trafia na listy i do eksportu">główna</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($p['stanowisko'])): ?>
            <div class="crm-name-sub"><?= h($p['stanowisko']) ?></div>
            <?php endif; ?>
            <?php if (!empty($p['email'])): ?>
            <div style="font-size:.78rem"><a href="mailto:<?= h($p['email']) ?>"><?= h($p['email']) ?></a></div>
            <?php endif; ?>
            <?php if (!empty($p['telefon'])): ?>
            <div style="font-size:.78rem"><a href="tel:<?= h($p['telefon']) ?>"><?= h($p['telefon']) ?></a></div>
            <?php endif; ?>
            <?php if (!empty($p['notatka'])): ?>
            <div class="cv-meta" style="white-space:pre-line"><?= h($p['notatka']) ?></div>
            <?php endif; ?>
          </div>
          <div class="d-flex flex-column gap-1 align-items-end">
            <?php if ($can_w && empty($p['is_primary'])): ?>
            <form method="post" class="d-inline" data-ajax-section="persons">
              <input type="hidden" name="_csrf"     value="<?= csrf_token() ?>">
              <input type="hidden" name="_action"   value="set_primary_person">
              <input type="hidden" name="person_id" value="<?= (int)$p['id'] ?>">
              <button type="submit" class="border-0 p-0 bg-transparent cv-muted" style="font-size:.85rem;cursor:pointer"
                      aria-label="Ustaw <?= h($p['imie_nazwisko']) ?> jako osobę główną" title="Ustaw jako główną">
                <i class="bi bi-star" aria-hidden="true"></i>
              </button>
            </form>
            <?php endif; ?>
            <?php if ($can_d): ?>
            <form method="post" class="d-inline" data-ajax-section="persons">
              <input type="hidden" name="_csrf"     value="<?= csrf_token() ?>">
              <input type="hidden" name="_action"   value="delete_person">
              <input type="hidden" name="person_id" value="<?= (int)$p['id'] ?>">
              <button type="submit" class="border-0 p-0 bg-transparent text-danger" style="font-size:.85rem;cursor:pointer"
                      aria-label="Usuń osobę kontaktową <?= h($p['imie_nazwisko']) ?>" title="Usuń">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p class="cv-meta mb-2">Brak osób kontaktowych.</p>
      <?php endif; ?>

      <?php if ($can_w): ?>
      <details class="mt-1"<?= $persons ? '' : ' open' ?>>
        <summary style="cursor:pointer;font-size:.8rem;color:var(--crm-primary)">
          <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Dodaj osobę kontaktową
        </summary>
        <form method="post" class="mt-2 border-top pt-2" data-ajax-section="persons">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="add_person">
          <label class="visually-hidden" for="cv_p_name_<?= $id ?>">Imię i nazwisko</label>
          <input type="text" name="person_name" id="cv_p_name_<?= $id ?>" required maxlength="160"
                 class="form-control form-control-sm mb-2" placeholder="Imię i nazwisko *">
          <label class="visually-hidden" for="cv_p_role_<?= $id ?>">Stanowisko / rola</label>
          <input type="text" name="person_role" id="cv_p_role_<?= $id ?>" maxlength="120"
                 class="form-control form-control-sm mb-2" placeholder="Stanowisko / rola">
          <label class="visually-hidden" for="cv_p_mail_<?= $id ?>">E-mail</label>
          <input type="email" name="person_email" id="cv_p_mail_<?= $id ?>" maxlength="190"
                 class="form-control form-control-sm mb-2" placeholder="E-mail">
          <label class="visually-hidden" for="cv_p_tel_<?= $id ?>">Telefon</label>
          <input type="text" name="person_phone" id="cv_p_tel_<?= $id ?>" maxlength="40"
                 class="form-control form-control-sm mb-2" placeholder="Telefon">
          <label class="visually-hidden" for="cv_p_note_<?= $id ?>">Notatka</label>
          <textarea name="person_note" id="cv_p_note_<?= $id ?>" rows="2"
                    class="form-control form-control-sm mb-2" placeholder="Notatka (opcjonalnie)"></textarea>
          <button class="btn btn-sm btn-crm-outline w-100" type="submit">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj osobę
          </button>
        </form>
      </details>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

/**
 * Kategoria „świadczy usługi na rzecz FEER" + rodzaje usług z otwartego katalogu.
 *
 * Dopisywanie usług jest dostępne tylko dla kontaktów o statusie „Partner"
 * (crm_services_allowed()). Kontakt, który partnerem być przestał, nie traci
 * danych — widzi je w trybie tylko do odczytu i może je posprzątać.
 */
function _cv_services_html(array $contact, int $id, bool $can_w, bool $can_d): string {
    $services = $contact['services'] ?? [];
    $on       = !empty($contact['swiadczy_uslugi']);
    $used_ids = array_map('intval', array_column($services, 'service_type_id'));
    $catalog  = array_filter(crm_service_types(true), fn($t) => !in_array((int)$t['id'], $used_ids, true));

    $allowed   = crm_services_allowed($contact);
    $can_add   = $can_w && $allowed;      // dopisywanie / włączanie kategorii
    $statuses  = crm_statuses();
    $cur_label = (string)($statuses[$contact['status'] ?? '']['label'] ?? ($contact['status'] ?? '—'));
    ob_start(); ?>
    <div id="crm-section-services" aria-live="polite">
      <?php if (!$allowed): ?>
      <div class="alert alert-warning py-2 px-2 mb-2" style="font-size:.8rem" role="note">
        <i class="bi bi-info-circle-fill me-1" aria-hidden="true"></i>
        Usługi na rzecz <?= h(org_setting('org_short_name') ?: 'FEER') ?> są dostępne tylko przy statusie
        <strong><?= h(crm_services_status_label()) ?></strong>. Ten kontakt ma status
        <strong><?= h($cur_label) ?></strong><?= $services || $on ? ', więc poniższe dane są tylko do odczytu' : '' ?>.
        <?php if ($can_w): ?>
        <a href="<?= APP_URL ?>/crm/contact/<?= !empty(CRM_CONTACT_TYPES[$contact['type']]['org_like']) ? 'add_org' : 'add_person' ?>.php?id=<?= $id ?>">Zmień status</a>,
        aby móc je edytować<?= $services ? ', albo usuń wpisy poniżej' : '' ?>.
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($can_add || ($can_w && $on)): ?>
      <form method="post" class="mb-2" data-ajax-section="services">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="toggle_services">
        <input type="hidden" name="on"      value="<?= $on ? 0 : 1 ?>">
        <?php
          // Bez statusu partnera zostaje tylko wyłączenie kategorii — etykieta
          // musi to mówić wprost, bo przycisk „Świadczy…" sugerowałby edycję.
          if (!$allowed) { $btn_cls = 'btn-outline-secondary'; $btn_ico = 'bi-slash-circle'; $btn_txt = 'Wyłącz kategorię usług'; }
          elseif ($on)   { $btn_cls = 'btn-success';           $btn_ico = 'bi-check-circle-fill'; $btn_txt = 'Świadczy usługi na rzecz FEER'; }
          else           { $btn_cls = 'btn-crm-outline';       $btn_ico = 'bi-circle'; $btn_txt = 'Oznacz: świadczy usługi na rzecz FEER'; }
        ?>
        <button type="submit" class="btn btn-sm w-100 <?= $btn_cls ?>">
          <i class="bi <?= $btn_ico ?> me-1" aria-hidden="true"></i><?= h($btn_txt) ?>
        </button>
      </form>
      <?php elseif ($on): ?>
      <p class="mb-2"><span class="cv-chip"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Świadczy usługi na rzecz FEER</span></p>
      <?php endif; ?>

      <?php if ($services): ?>
      <ul class="list-unstyled mb-2">
        <?php foreach ($services as $sv): ?>
        <li class="d-flex align-items-start gap-2 py-1" style="border-bottom:1px solid var(--crm-border)">
          <i class="bi bi-tools cv-shead__icon mt-1" aria-hidden="true"></i>
          <div class="flex-grow-1 overflow-hidden">
            <div style="font-size:.83rem;font-weight:600"><?= h($sv['nazwa']) ?>
              <?php if (empty($sv['type_active'])): ?>
              <span class="cv-meta">(pozycja wycofana z katalogu)</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($sv['uwagi'])): ?>
            <div class="cv-meta" style="white-space:pre-line"><?= h($sv['uwagi']) ?></div>
            <?php endif; ?>
          </div>
          <?php if ($can_d): ?>
          <form method="post" class="d-inline" data-ajax-section="services">
            <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
            <input type="hidden" name="_action"  value="remove_service">
            <input type="hidden" name="link_id"  value="<?= (int)$sv['id'] ?>">
            <button type="submit" class="border-0 p-0 bg-transparent text-danger" style="font-size:.85rem;cursor:pointer"
                    aria-label="Usuń rodzaj usługi <?= h($sv['nazwa']) ?>" title="Usuń">
              <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
          </form>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p class="cv-meta mb-2">Nie wskazano rodzajów usług.</p>
      <?php endif; ?>

      <?php if ($can_add): ?>
      <form method="post" class="border-top pt-2" data-ajax-section="services">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="add_service">
        <label class="visually-hidden" for="cv_sv_type_<?= $id ?>">Rodzaj usługi z katalogu</label>
        <select name="service_type_id" id="cv_sv_type_<?= $id ?>" class="form-select form-select-sm mb-2">
          <option value="">— wybierz rodzaj usługi —</option>
          <?php foreach ($catalog as $t): ?>
          <option value="<?= (int)$t['id'] ?>"><?= h($t['nazwa']) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="cv_sv_new_<?= $id ?>">Nowy rodzaj usługi</label>
        <input type="text" name="service_new" id="cv_sv_new_<?= $id ?>" maxlength="120"
               class="form-control form-control-sm mb-1" placeholder="…lub wpisz nowy rodzaj">
        <div class="cv-meta mb-2">Katalog jest otwarty — wpisany rodzaj zostanie do niego dopisany.</div>
        <label class="visually-hidden" for="cv_sv_note_<?= $id ?>">Uwagi do usługi</label>
        <input type="text" name="service_note" id="cv_sv_note_<?= $id ?>" maxlength="255"
               class="form-control form-control-sm mb-2" placeholder="Uwagi (opcjonalnie)">
        <button class="btn btn-sm btn-crm-outline w-100" type="submit">
          <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj rodzaj usługi
        </button>
      </form>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

function _cv_groups_html(array $contact, int $id, bool $can_w): string {
    $all_groups = CrmManager::getGroups();
    $current_group_ids = array_column($contact['groups'], 'id');
    $available_groups = array_filter($all_groups, fn($g) => !in_array($g['id'], $current_group_ids));
    ob_start(); ?>
    <div id="crm-section-groups" aria-live="polite">
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
          <?php if ($can_w): ?>
          <form method="post" class="d-inline" data-ajax-section="groups">
            <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
            <input type="hidden" name="_action"  value="remove_from_group">
            <input type="hidden" name="group_id" value="<?= (int)$cg['id'] ?>">
            <button type="submit" class="border-0 p-0 bg-transparent text-danger"
                    style="font-size:.65rem;line-height:1;cursor:pointer"
                    aria-label="Usuń z grupy <?= h($cg['name']) ?>">&times;</button>
          </form>
          <?php endif; ?>
        </span>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="text-muted small mb-2">Brak grup.</p>
      <?php endif; ?>
      <?php if ($can_w && $available_groups): ?>
      <form method="post" class="d-flex gap-2" data-ajax-section="groups">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="add_to_group">
        <label class="visually-hidden" for="cv_group_sel">Dodaj do grupy</label>
        <select name="group_id" id="cv_group_sel" class="form-select form-select-sm" required>
          <option value="">Dodaj do grupy…</option>
          <?php foreach ($available_groups as $ag): ?>
          <option value="<?= (int)$ag['id'] ?>"><?= h($ag['name']) ?> (<?= (int)$ag['member_count'] ?>)</option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-crm-outline flex-shrink-0" type="submit" aria-label="Dodaj">
          <i class="bi bi-plus" aria-hidden="true"></i>
        </button>
      </form>
      <?php elseif ($can_w && !$all_groups): ?>
      <a href="<?= APP_URL ?>/crm/groups.php" class="btn btn-sm btn-crm-outline w-100 mt-1">
        <i class="bi bi-plus me-1" aria-hidden="true"></i>Utwórz grupę
      </a>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

function _cv_action_links_html(array $contact_actions, int $id, bool $can_w, array $available_actions, bool $has_any): string {
    $action_statuses_v = [
        'planowane'       => ['color' => '#64748b'],
        'w_przygotowaniu' => ['color' => '#0176D3'],
        'w_trakcie'       => ['color' => '#2E844A'],
        'zawieszone'      => ['color' => '#FE9339'],
        'zakończone'      => ['color' => '#032D60'],
        'anulowane'       => ['color' => '#E31010'],
    ];
    ob_start(); ?>
    <div id="crm-section-action-links" aria-live="polite">
      <?php if ($contact_actions): ?>
      <div class="d-flex flex-column gap-1 mb-2">
        <?php foreach ($contact_actions as $ca):
          $ast = $action_statuses_v[$ca['status']] ?? ['color' => '#939393'];
        ?>
        <div class="d-flex align-items-start gap-2 p-2 border rounded" style="font-size:.82rem;border-radius:.4rem!important">
          <i class="bi bi-calendar-event mt-1 flex-shrink-0" style="color:<?= h($ast['color']) ?>" aria-hidden="true"></i>
          <div class="flex-grow-1 overflow-hidden">
            <a href="<?= APP_URL ?>/strategy/actions/view.php?id=<?= (int)$ca['id'] ?>"
               class="fw-semibold text-dark text-decoration-none d-block"
               style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
               title="<?= h($ca['nazwa']) ?>"><?= h($ca['nazwa']) ?></a>
            <div class="text-muted" style="font-size:.72rem">
              <span style="color:<?= h($ast['color']) ?>;font-weight:600"><?= h($ca['status']) ?></span>
              <?php if ($ca['data_od']): ?> · <?= date('d.m.Y', strtotime($ca['data_od'])) ?><?php endif; ?>
              <?php if (($ca['rola'] ?? '') !== 'uczestnik'): ?> · <em><?= h($ca['rola']) ?></em><?php endif; ?>
            </div>
          </div>
          <?php if ($can_w): ?>
          <form method="post" class="flex-shrink-0" data-ajax-section="action-links">
            <input type="hidden" name="_csrf"     value="<?= csrf_token() ?>">
            <input type="hidden" name="_action"   value="unlink_from_action">
            <input type="hidden" name="action_id" value="<?= (int)$ca['id'] ?>">
            <button type="submit" class="border-0 p-0 bg-transparent text-danger"
                    style="font-size:.75rem;cursor:pointer"
                    aria-label="Usuń z działania <?= h($ca['nazwa']) ?>">&times;</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="text-muted small mb-2">Brak powiązanych działań.</p>
      <?php endif; ?>
      <?php if ($can_w && $available_actions): ?>
      <form method="post" data-ajax-section="action-links">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="link_to_action">
        <label class="visually-hidden" for="cv_act_sel">Działanie</label>
        <select name="action_id" id="cv_act_sel" class="form-select form-select-sm mb-1" required>
          <option value="">Dodaj do działania…</option>
          <?php foreach ($available_actions as $av):
            $lbl = $av['data_od'] ? date('Y', strtotime($av['data_od'])) . ' · ' : '';
          ?>
          <option value="<?= (int)$av['id'] ?>"><?= h($lbl . $av['nazwa']) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="cv_act_role">Rola</label>
        <select name="rola" id="cv_act_role" class="form-select form-select-sm mb-1">
          <?php foreach (['uczestnik','wolontariusz','prelegent','koordynator','beneficjent','inny'] as $r): ?>
          <option value="<?= $r ?>"><?= ucfirst($r) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-crm-outline w-100" type="submit">
          <i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>Dodaj do działania
        </button>
      </form>
      <?php elseif ($can_w && !$has_any): ?>
      <a href="<?= APP_URL ?>/strategy/actions/add.php" class="btn btn-sm btn-crm-outline w-100">
        <i class="bi bi-plus me-1" aria-hidden="true"></i>Utwórz działanie
      </a>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

function _cv_activities_html(int $id, bool $can_w, bool $can_d): string {
    $activities  = _cv_load_activities($id);
    $cfg         = _cv_activity_cfg();
    $pending     = array_filter($activities, fn($a) => $a['status'] === 'planned');
    $done        = array_filter($activities, fn($a) => $a['status'] === 'done');
    $editors     = db_all("SELECT id, CASE WHEN first_name!='' AND last_name!='' THEN first_name||' '||last_name ELSE name END AS n FROM users WHERE role IN ('admin','editor','crm_user') AND is_active=1 ORDER BY n");
    ob_start(); ?>
    <div id="crm-section-activities" aria-live="polite">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted" style="font-size:.78rem"><?= count($pending) ?> oczekuje</span>
        <?php if ($can_w): ?>
        <button class="btn btn-sm py-0 px-2"
                style="background:#D97706;color:#fff;font-size:.72rem;border-radius:6px"
                onclick="ActivityUI.openNew()" type="button">
          <i class="bi bi-plus me-1" aria-hidden="true"></i>Dodaj
        </button>
        <?php endif; ?>
      </div>
      <?php if ($pending): ?>
      <div id="activity-list-pending">
        <?php foreach ($pending as $act):
          $atc = $cfg[$act['type']] ?? $cfg['other'];
          $an  = trim(($act['assigned_fn']??'').' '.($act['assigned_ln']??'')) ?: ($act['assigned_name']??'');
        ?>
        <div class="act-row" data-id="<?= (int)$act['id'] ?>">
          <div class="act-type-icon" style="background:<?= $atc['bg'] ?>;color:<?= $atc['color'] ?>">
            <i class="bi <?= $atc['icon'] ?>" aria-hidden="true"></i>
          </div>
          <div class="act-info">
            <div class="act-title"><?= h($act['title']) ?></div>
            <div class="act-meta">
              <?= $atc['label'] ?>
              <?php if ($act['scheduled_at']): ?> · <i class="bi bi-clock me-1" aria-hidden="true"></i><?= date('d.m.Y H:i', strtotime($act['scheduled_at'])) ?><?php endif; ?>
              <?php if ($an): ?> · <?= h($an) ?><?php endif; ?>
            </div>
            <?php if ($act['description']): ?>
            <div class="act-desc"><?= h($act['description']) ?></div>
            <?php endif; ?>
          </div>
          <?php if ($can_w): ?>
          <div class="act-actions">
            <button class="btn btn-xs btn-success py-0 px-2" style="font-size:.7rem"
                    onclick="ActivityUI.complete(<?= (int)$act['id'] ?>)"
                    aria-label="Oznacz jako wykonane">
              <i class="bi bi-check-lg" aria-hidden="true"></i>
            </button>
            <button class="btn btn-xs btn-outline-danger py-0 px-1" style="font-size:.7rem"
                    onclick="ActivityUI.del(<?= (int)$act['id'] ?>)"
                    aria-label="Usuń działanie">
              <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="text-muted text-center py-2" style="font-size:.8rem">
        <i class="bi bi-check-circle me-1 text-success" aria-hidden="true"></i>Brak zaplanowanych działań
      </div>
      <?php endif; ?>
      <?php if ($done): ?>
      <details class="mt-2">
        <summary style="font-size:.8rem;color:#5E6470;cursor:pointer;list-style:none;display:flex;align-items:center;gap:.4rem">
          <i class="bi bi-chevron-right" style="font-size:.65rem;transition:transform .2s" id="done-chevron" aria-hidden="true"></i>
          Wykonane (<?= count($done) ?>)
        </summary>
        <?php foreach ($done as $act):
          $atc = $cfg[$act['type']] ?? $cfg['other'];
        ?>
        <div class="act-row done mt-1">
          <div class="act-type-icon" style="background:#F3F4F6;color:#6B7280">
            <i class="bi <?= $atc['icon'] ?>" aria-hidden="true"></i>
          </div>
          <div class="act-info">
            <div class="act-title" style="text-decoration:line-through;color:#6B7280"><?= h($act['title']) ?></div>
            <?php if ($act['outcome']): ?><div class="act-desc"><?= h($act['outcome']) ?></div><?php endif; ?>
            <div class="act-meta"><?= $act['completed_at'] ? date('d.m.Y', strtotime($act['completed_at'])) : '' ?></div>
          </div>
          <?php if ($can_w): ?>
          <div class="act-actions">
            <button class="btn btn-xs btn-outline-secondary py-0 px-1" style="font-size:.7rem"
                    onclick="ActivityUI.reopen(<?= (int)$act['id'] ?>)"
                    aria-label="Wróć do zaplanowanych">
              <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
            </button>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </details>
      <?php endif; ?>
    </div>
    <?php
    // Przechowujemy editors do modalu aktywności (dostępny przez data-attr)
    $editors_json = htmlspecialchars(json_encode($editors), ENT_QUOTES);
    echo '<div id="cv-act-editors-data" data-editors="' . $editors_json . '" style="display:none"></div>';
    return ob_get_clean();
}

function _cv_communications_html(array $contact, int $id): string {
    ob_start(); ?>
    <div id="crm-section-comms">
      <?php if ($contact['communications']): ?>
        <?php foreach ($contact['communications'] as $comm):
          $ch_info = CRM_CHANNELS[$comm['channel']] ?? ['label' => $comm['channel'], 'icon' => 'bi-chat'];
          $is_out  = in_array($comm['direction'] ?? '', ['out', 'outgoing'], true);
        ?>
        <div class="crm-comm-item">
          <div class="crm-comm-icon <?= h($comm['channel']) ?>" aria-hidden="true">
            <i class="bi <?= h($ch_info['icon']) ?>"></i>
          </div>
          <div class="flex-grow-1">
            <div style="font-size:.82rem">
              <i class="bi bi-arrow-<?= $is_out ? 'up-right text-primary' : 'down-left text-success' ?> me-1"
                 title="<?= $is_out ? 'Wychodząca' : 'Przychodząca' ?>" aria-hidden="true"></i>
              <span class="fw-semibold"><?= h($ch_info['label']) ?></span>
              <span class="visually-hidden"><?= $is_out ? 'wychodząca' : 'przychodząca' ?></span>
              <?php if ($comm['subject']): ?>
              — <span class="text-muted"><?= h($comm['subject']) ?></span>
              <?php endif; ?>
            </div>
            <div class="text-muted" style="font-size:.78rem;margin-top:2px">
              <?= h(mb_substr(strip_tags($comm['body']), 0, 120)) ?>
              <?= mb_strlen($comm['body'] ?? '') > 120 ? '…' : '' ?>
            </div>
            <div class="crm-note-meta">
              <i class="bi bi-person me-1" aria-hidden="true"></i><?= h($comm['sender_name'] ?? '—') ?>
              · <?= date_pl($comm['sent_at']) ?>
              <span class="badge ms-1" style="font-size:.65rem;background:<?= in_array($comm['status'], ['wysłana','zsynchronizowana','odebrana'], true)?'#EFF7ED;color:#2E844A':'#fef2f2;color:#dc2626' ?>">
                <?= h($comm['status']) ?>
              </span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
      <p class="text-muted small mb-0">Brak historii komunikacji.</p>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $crm_can_write) {
    csrf_check();
    $action  = $_POST['_action'] ?? '';
    $user_id = (int)(current_user()['id'] ?? 0);
    $xhr     = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
               && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $affected_section = null;

    if ($action === 'add_note') {
        $body   = trim($_POST['note_body'] ?? '');
        $pinned = !empty($_POST['note_pinned']);
        if ($body !== '') { CrmManager::addNote($id, $body, $user_id, $pinned); }
        $affected_section = 'notes';
    }
    if ($action === 'delete_note' && $crm_can_delete) {
        CrmManager::deleteNote((int)($_POST['note_id'] ?? 0));
        $affected_section = 'notes';
    }
    if ($action === 'add_tag') {
        $tag = trim($_POST['tag'] ?? '');
        if ($tag !== '') CrmManager::addTag($id, $tag);
        $affected_section = 'tags';
    }
    if ($action === 'remove_tag') {
        CrmManager::removeTag($id, trim($_POST['tag'] ?? ''));
        $affected_section = 'tags';
    }
    if ($action === 'add_relation') {
        $target = (int)($_POST['relation_target'] ?? 0);
        $type   = trim($_POST['relation_type'] ?? 'powiązany');
        if ($target > 0 && $target !== $id) CrmManager::addRelation($id, $target, $type, null);
        $affected_section = 'relations';
    }
    if ($action === 'remove_relation' && $crm_can_delete) {
        CrmManager::removeRelation((int)($_POST['relation_id'] ?? 0));
        $affected_section = 'relations';
    }
    if ($action === 'add_person') {
        CrmManager::addContactPerson($id, [
            'imie_nazwisko' => $_POST['person_name']  ?? '',
            'stanowisko'    => $_POST['person_role']  ?? '',
            'email'         => $_POST['person_email'] ?? '',
            'telefon'       => $_POST['person_phone'] ?? '',
            'notatka'       => $_POST['person_note']  ?? '',
        ], $user_id);
        $affected_section = 'persons';
    }
    if ($action === 'delete_person' && $crm_can_delete) {
        $pid = (int)($_POST['person_id'] ?? 0);
        $p   = $pid ? CrmManager::getContactPerson($pid) : null;
        if ($p && (int)$p['contact_id'] === $id) CrmManager::deleteContactPerson($pid);
        $affected_section = 'persons';
    }
    if ($action === 'set_primary_person') {
        $pid = (int)($_POST['person_id'] ?? 0);
        $p   = $pid ? CrmManager::getContactPerson($pid) : null;
        if ($p && (int)$p['contact_id'] === $id) CrmManager::setPrimaryContactPerson($id, $pid);
        $affected_section = 'persons';
    }
    if ($action === 'toggle_services') {
        // setProvidesServices() sam odrzuca włączenie dla nie-partnera;
        // wyłączenie przechodzi zawsze (porządki po zmianie statusu).
        CrmManager::setProvidesServices($id, !empty($_POST['on']));
        if (!empty($_POST['on']) && !crm_services_allowed($contact)) {
            flash_set('error', 'Usługi na rzecz organizacji można oznaczyć tylko przy statusie „'
                             . crm_services_status_label() . '".');
        }
        $affected_section = 'services';
    }
    if ($action === 'add_service' && !crm_services_allowed($contact)) {
        // Sprawdzamy PRZED find_or_create — inaczej próba dopisania usługi
        // nie-partnerowi zaśmiecałaby katalog nowym rodzajem bez efektu.
        flash_set('error', 'Usługi na rzecz organizacji można przypisać tylko przy statusie „'
                         . crm_services_status_label() . '".');
        $affected_section = 'services';
    }
    elseif ($action === 'add_service') {
        // Katalog otwarty: wpisany ręcznie rodzaj ma pierwszeństwo przed wyborem z listy.
        $new  = trim($_POST['service_new'] ?? '');
        $stid = $new !== ''
            ? crm_service_type_find_or_create($new, $user_id)
            : (int)($_POST['service_type_id'] ?? 0);
        if ($stid > 0) CrmManager::addContactService($id, $stid, $_POST['service_note'] ?? null, $user_id);
        $affected_section = 'services';
    }
    if ($action === 'remove_service' && $crm_can_delete) {
        CrmManager::removeContactService((int)($_POST['link_id'] ?? 0));
        $affected_section = 'services';
    }
    if ($action === 'add_to_group') {
        $gid = (int)($_POST['group_id'] ?? 0);
        if ($gid) CrmManager::addToGroup($gid, $id);
        $affected_section = 'groups';
    }
    if ($action === 'remove_from_group') {
        $gid = (int)($_POST['group_id'] ?? 0);
        if ($gid) CrmManager::removeFromGroup($gid, $id);
        $affected_section = 'groups';
    }
    if ($action === 'link_to_action') {
        $aid  = (int)($_POST['action_id'] ?? 0);
        $rola = trim($_POST['rola'] ?? 'uczestnik');
        if ($aid) CrmManager::linkToAction($id, $aid, $rola, null);
        $affected_section = 'action-links';
    }
    if ($action === 'unlink_from_action') {
        $aid = (int)($_POST['action_id'] ?? 0);
        if ($aid) CrmManager::unlinkFromAction($id, $aid);
        $affected_section = 'action-links';
    }
    if ($action === 'convert_type') {
        $current_type = $contact['type'];
        $target_type  = trim($_POST['target_type'] ?? '');
        if (!array_key_exists($target_type, CRM_CONTACT_TYPES) || $target_type === $current_type) {
            flash_set('danger', 'Nieprawidłowy typ docelowy.');
            header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $id);
            exit;
        }
        $target_meta = CRM_CONTACT_TYPES[$target_type];
        $update = ['type' => $target_type];
        if ($target_meta['org_like']) {
            // org-like: usuń pola tylko dla osoby fizycznej
            $update['imie'] = null; $update['nazwisko'] = null;
            $update['pesel'] = null; $update['data_urodzenia'] = null;
        } else {
            // osoba: usuń pola org
            $update['nip'] = null; $update['krs'] = null; $update['regon'] = null;
            $update['osoba_kontaktowa'] = null; $update['forma_prawna'] = null;
        }
        CrmManager::updateContact($id, $update);
        $from_label = CRM_CONTACT_TYPES[$current_type]['label'] ?? $current_type;
        $to_label   = $target_meta['label'];
        CrmManager::addNote($id, 'Konwersja typu kontaktu: ' . $from_label . ' → ' . $to_label . '.', $user_id);
        flash_set('success', 'Typ kontaktu zmieniony na: ' . $to_label . '.');
        header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $id);
        exit;
    }

    if ($xhr && $affected_section) {
        $contact = CrmManager::getContact($id);
        $all_contacts = db_all(
            "SELECT id, imie_nazwisko, organizacja FROM crm_contacts WHERE crm_active=1 AND id != ? ORDER BY imie_nazwisko",
            [$id]
        );
        $contact_actions    = CrmManager::getContactActions($id);
        $linked_action_ids  = array_column($contact_actions, 'id');
        $all_actions_raw    = db_all("SELECT id, nazwa, typ, status, data_od FROM actions ORDER BY data_od DESC, nazwa");
        $available_actions  = array_filter($all_actions_raw, fn($a) => !in_array($a['id'], $linked_action_ids));

        $html = match ($affected_section) {
            'notes'        => _cv_notes_html($contact, $id, $crm_can_write, $crm_can_delete),
            'tags'         => _cv_tags_html($contact, $id, $crm_can_write, $crm_can_delete),
            'relations'    => _cv_relations_html($contact, $id, $crm_can_write, $crm_can_delete, $all_contacts),
            'groups'       => _cv_groups_html($contact, $id, $crm_can_write),
            'persons'      => _cv_persons_html($contact, $id, $crm_can_write, $crm_can_delete),
            'services'     => _cv_services_html($contact, $id, $crm_can_write, $crm_can_delete),
            'action-links' => _cv_action_links_html($contact_actions, $id, $crm_can_write, $available_actions, !empty($all_actions_raw)),
            'activities'   => _cv_activities_html($id, $crm_can_write, $crm_can_delete),
            default        => '',
        };
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true, 'section' => $affected_section, 'html' => $html]);
        exit;
    }

    if (!$xhr) {
        flash_set('success', 'Zapisano.');
        header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $id);
        exit;
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Nieznana akcja']);
    exit;
}

// ── GET: fragment sekcji ──────────────────────────────────────────────────────
if (isset($_GET['_section'])) {
    $sec     = $_GET['_section'];
    $contact = CrmManager::getContact($id);
    if (!$contact) { header('Content-Type: application/json'); echo json_encode(['ok'=>false]); exit; }

    $all_contacts  = db_all("SELECT id, imie_nazwisko, organizacja FROM crm_contacts WHERE crm_active=1 AND id != ? ORDER BY imie_nazwisko", [$id]);
    $contact_actions    = CrmManager::getContactActions($id);
    $linked_action_ids  = array_column($contact_actions, 'id');
    $all_actions_raw    = db_all("SELECT id, nazwa, typ, status, data_od FROM actions ORDER BY data_od DESC, nazwa");
    $available_actions  = array_filter($all_actions_raw, fn($a) => !in_array($a['id'], $linked_action_ids));

    $html = match ($sec) {
        'notes'        => _cv_notes_html($contact, $id, $crm_can_write, $crm_can_delete),
        'tags'         => _cv_tags_html($contact, $id, $crm_can_write, $crm_can_delete),
        'relations'    => _cv_relations_html($contact, $id, $crm_can_write, $crm_can_delete, $all_contacts),
        'groups'       => _cv_groups_html($contact, $id, $crm_can_write),
        'persons'      => _cv_persons_html($contact, $id, $crm_can_write, $crm_can_delete),
        'services'     => _cv_services_html($contact, $id, $crm_can_write, $crm_can_delete),
        'action-links' => _cv_action_links_html($contact_actions, $id, $crm_can_write, $available_actions, !empty($all_actions_raw)),
        'activities'   => _cv_activities_html($id, $crm_can_write, $crm_can_delete),
        'comms'        => _cv_communications_html($contact, $id),
        default        => '',
    };
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => true, 'html' => $html]);
    exit;
}

// ── Załaduj dane do pełnej strony ─────────────────────────────────────────────
$contact = CrmManager::getContact($id);

$contact_actions   = CrmManager::getContactActions($id);
$linked_action_ids = array_column($contact_actions, 'id');
$all_actions_raw   = db_all("SELECT id, nazwa, typ, status, data_od FROM actions ORDER BY data_od DESC, nazwa");
$available_actions = array_filter($all_actions_raw, fn($a) => !in_array($a['id'], $linked_action_ids));

$all_contacts_for_relation = db_all(
    "SELECT id, imie_nazwisko, organizacja FROM crm_contacts
     WHERE crm_active=1 AND id != ? ORDER BY imie_nazwisko",
    [$id]
);

$volunteer_contracts    = CrmManager::getContactVolunteerContracts($contact['email'] ?? '');
$volunteer_recruitments = CrmManager::getContactRecruitments($contact['email'] ?? '');

// Dane osobowe z umów dostępne do zaciągnięcia na kartę (tylko osoby fizyczne)
$contract_import_data = (($contact['type'] ?? '') === 'osoba')
    ? CrmManager::getContractDataForContact($contact)
    : [];

$_custom_field_defs   = array_filter(CrmManager::getFieldDefs($contact['type'] ?? ''), 'crm_field_visible');
$_custom_field_values = CrmManager::getFieldValues($id);

// Widoczność pól systemowych dla bieżącego użytkownika
$_sfv = [
    'email'       => crm_sys_field_visible('email'),
    'telefon'     => crm_sys_field_visible('telefon'),
    'adres'       => crm_sys_field_visible('adres'),
    'nip'         => crm_sys_field_visible('nip'),
    'stanowisko'  => crm_sys_field_visible('stanowisko'),
    'organizacja' => crm_sys_field_visible('organizacja'),
    'notatka'     => crm_sys_field_visible('notatka'),
];

$contact_cases = db_all(
    "SELECT * FROM crm_cases WHERE contact_id=? ORDER BY updated_at DESC LIMIT 10",
    [(int)$id]
);

// ── Integracja Microsoft 365 (książka adresowa + korespondencja) ─────────────
crm_office_migrate();
$office_st  = crm_office_status();
$office_row = crm_one(
    "SELECT outlook_id, office_pushed_at, office_push_error, office_mail_pulled_at
     FROM crm_contacts WHERE id=?", [(int)$id]
) ?: [];

// ── Oferty kontaktu (moduł działalności odpłatnej) ───────────────────────────
crm_offers_migrate();
$contact_offers  = crm_offer_history((int)$id, 25);
$offers_summary  = crm_offer_contact_summary((int)$id);
$offers_can_write = crm_offer_can_write();

// Roundcube URL (ustawiane w CRM → Ustawienia)
$roundcube_url = crm_setting('roundcube_url');

include __DIR__ . '/../includes/header_crm.php';
?>

<!-- Breadcrumb + przełącznik widoku -->
<div class="d-flex align-items-center justify-content-between mb-3">
  <nav aria-label="Ścieżka nawigacji">
    <ol class="breadcrumb mb-0" style="font-size:.82rem">
      <li class="breadcrumb-item">
        <a href="<?= APP_URL ?>/crm/index.php">
          <i class="bi bi-diagram-2-fill me-1" style="color:var(--crm-primary)" aria-hidden="true"></i>CRM
        </a>
      </li>
      <li class="breadcrumb-item active" aria-current="page"><?= h($contact['imie_nazwisko']) ?></li>
    </ol>
  </nav>
  <button type="button"
          id="crm-fullscreen-btn"
          class="btn btn-outline-secondary btn-sm"
          onclick="crmToggleFullscreen()"
          title="Przełącz widok pełnoekranowy (bez menu bocznego)"
          aria-label="Przełącz widok pełnoekranowy">
    <i class="bi bi-layout-sidebar-reverse" id="crm-fs-icon"></i>
  </button>
</div>

<script>
(function () {
  const LS_KEY = 'crm_contact_fullscreen';
  const body   = document.body;
  const icon   = document.getElementById('crm-fs-icon');

  function apply(full) {
    body.classList.toggle('crm-fullscreen', full);
    if (icon) icon.className = full ? 'bi bi-layout-sidebar' : 'bi bi-layout-sidebar-reverse';
  }

  // Przywróć stan z localStorage
  apply(localStorage.getItem(LS_KEY) === '1');

  window.crmToggleFullscreen = function () {
    const next = !body.classList.contains('crm-fullscreen');
    localStorage.setItem(LS_KEY, next ? '1' : '0');
    apply(next);
  };
})();
</script>

<!-- ══ NAGŁÓWEK REKORDU ═══════════════════════════════════════════════════════ -->
<div class="cv-panel" style="overflow:hidden">
  <div class="crm-contact-header">
    <div class="crm-contact-avatar-lg" aria-hidden="true">
      <?= h($contact['avatar_initials'] ?: CrmManager::makeInitials($contact['imie_nazwisko'])) ?>
    </div>
    <div class="flex-grow-1 min-w-0">
      <h1 class="crm-contact-name"><?= h($contact['imie_nazwisko']) ?></h1>
      <div class="crm-contact-sub">
        <i class="bi <?= $contact['type'] === 'organizacja' ? 'bi-building' : 'bi-person' ?> me-1" aria-hidden="true"></i>
        <?= $contact['type'] === 'organizacja' ? 'Organizacja / firma' : 'Osoba fizyczna' ?>
        <?php if ($_sfv['stanowisko'] && $contact['stanowisko']): ?>
          · <?= h($contact['stanowisko']) ?>
        <?php endif; ?>
        <?php if ($_sfv['organizacja'] && $contact['organizacja'] && $contact['type'] !== 'organizacja'): ?>
          · <i class="bi bi-building me-1" aria-hidden="true"></i><?= h($contact['organizacja']) ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="flex-shrink-0">
      <?php $sc = crm_statuses()[$contact['status']] ?? ['label' => $contact['status'], 'color' => '#939393']; ?>
      <span class="crm-badge crm-badge-<?= h($contact['status']) ?>" style="font-size:.82rem">
        <?= h($sc['label']) ?>
      </span>
    </div>
  </div>

  <!-- Pasek akcji -->
  <div class="cv-panel__body d-flex flex-wrap gap-2" style="border-top:1px solid var(--crm-border)">
    <?php if ($crm_can_write): ?>
    <a href="<?= APP_URL ?>/crm/contact/add.php?id=<?= $id ?>" class="btn btn-sm btn-crm-primary">
      <i class="bi bi-pencil-fill me-1" aria-hidden="true"></i>Edytuj dane
    </a>
    <?php endif; ?>
    <button type="button" class="btn btn-sm btn-crm-outline" onclick="openCommModal(<?= $id ?>,'email')">
      <i class="bi bi-envelope-fill me-1" aria-hidden="true"></i>Wyślij e-mail
    </button>
    <button type="button" class="btn btn-sm btn-crm-outline" onclick="openCommModal(<?= $id ?>,'sms')">
      <i class="bi bi-phone-fill me-1" aria-hidden="true"></i>Wyślij SMS
    </button>
    <?php if ($crm_can_write): ?>
    <button type="button" class="btn btn-sm btn-outline-secondary"
            data-bs-toggle="modal" data-bs-target="#convertTypeModal"
            title="Zmień typ kontaktu: Osoba ↔ Organizacja">
      <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Konwertuj typ
    </button>
    <?php endif; ?>
    <?php if ($crm_can_write && $contract_import_data): ?>
    <button type="button" class="btn btn-sm btn-outline-secondary"
            data-bs-toggle="modal" data-bs-target="#importContractModal"
            title="Zaciągnij dane osobowe z umów tej osoby">
      <i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i>Zaciągnij z umowy
    </button>
    <?php endif; ?>
    <?php if ($roundcube_url && $contact['email']): ?>
    <a href="<?= APP_URL ?>/crm/webmail.php?compose_to=<?= urlencode($contact['email']) ?>"
       class="btn btn-sm btn-outline-secondary" title="Napisz przez Roundcube">
      <i class="bi bi-envelope-at me-1" aria-hidden="true"></i>Roundcube
    </a>
    <?php endif; ?>
  </div>

  <!-- Pasek danych kontaktowych -->
  <div class="cv-panel__body cv-quickbar" style="background:#F9FAFB;border-top:1px solid var(--crm-border)">
    <?php if ($_sfv['email'] && $contact['email']): ?>
    <a href="mailto:<?= h($contact['email']) ?>" class="cv-chip">
      <i class="bi bi-envelope-fill" aria-hidden="true"></i><span><?= h($contact['email']) ?></span>
    </a>
    <?php endif; ?>
    <?php if ($_sfv['telefon'] && $contact['telefon']): ?>
    <a href="tel:<?= h($contact['telefon']) ?>" class="cv-chip">
      <i class="bi bi-telephone-fill" aria-hidden="true"></i><span><?= h($contact['telefon']) ?></span>
    </a>
    <?php endif; ?>
    <?php $addr_display = address_format($contact); if ($_sfv['adres'] && $addr_display): ?>
    <span class="cv-chip">
      <i class="bi bi-geo-alt-fill" aria-hidden="true"></i><span><?= h($addr_display) ?></span>
    </span>
    <?php endif; ?>
    <?php if ($_sfv['nip'] && $contact['nip']): ?>
    <span class="cv-chip">
      <i class="bi bi-hash" aria-hidden="true"></i><span>NIP: <?= h($contact['nip']) ?></span>
    </span>
    <?php endif; ?>
    <span class="ms-auto cv-meta">
      Dodano: <?= date_pl($contact['created_at']) ?>
      <?php if (($contact['source'] ?? '') !== 'manual'): ?>· Źródło: <?= h($contact['source'] ?? '') ?><?php endif; ?>
    </span>
  </div>
</div>

<!-- ══ TREŚĆ: aside referencyjny + główna z zakładkami ════════════════════════ -->
<!-- Live region dla ogłoszeń AJAX -->
<div id="cv-live" role="status" aria-live="polite" aria-atomic="true" class="visually-hidden"></div>

<?php
// Konfiguracja statusów spraw (mini-lista w zakładce „Sprawy")
$case_status_cfg = [
    'open'        => ['label'=>'Otwarta',   'color'=>'#1D4ED8','bg'=>'#EEF4FF'],
    'in_progress' => ['label'=>'W toku',    'color'=>'#B45309','bg'=>'#FEF3E2'],
    'closed'      => ['label'=>'Zamknięta', 'color'=>'#2E844A','bg'=>'#EFF7ED'],
    'cancelled'   => ['label'=>'Anulowana', 'color'=>'#5E6470','bg'=>'#F3F4F6'],
];
?>

<div class="row g-3">

  <!-- ── ASIDE: tożsamość i dane referencyjne ──────────────────────────────── -->
  <div class="col-lg-4">

    <!-- Tagi -->
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-tags cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Tagi</h2>
      </div>
      <?= _cv_tags_html($contact, $id, $crm_can_write, $crm_can_delete) ?>
    </div></div>

    <!-- Pola dodatkowe -->
    <?php if ($_custom_field_defs): ?>
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-card-list cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Dodatkowe informacje</h2>
        <?php if ($crm_can_write): ?>
        <div class="cv-shead__aside">
          <a href="<?= APP_URL ?>/crm/contact/add.php?id=<?= $id ?>"
             class="btn btn-sm btn-outline-secondary py-0 px-2" title="Edytuj pola" aria-label="Edytuj pola dodatkowe">
            <i class="bi bi-pencil" aria-hidden="true"></i>
          </a>
        </div>
        <?php endif; ?>
      </div>
      <?php
      $any_value = false;
      foreach ($_custom_field_defs as $fd) {
        $val = $_custom_field_values[(int)$fd['id']] ?? '';
        if ($val !== '' || $val === '0') { $any_value = true; break; }
      }
      ?>
      <?php if ($any_value): ?>
      <dl class="cv-dl">
        <?php foreach ($_custom_field_defs as $fd):
          $val = $_custom_field_values[(int)$fd['id']] ?? '';
          if ($val === '' && $val !== '0') continue;
        ?>
        <div>
          <dt><?= h($fd['label']) ?></dt>
          <dd>
            <?php if ($fd['field_type'] === 'url'): ?>
              <a href="<?= h($val) ?>" target="_blank" rel="noopener"><?= h($val) ?></a>
            <?php elseif ($fd['field_type'] === 'email'): ?>
              <a href="mailto:<?= h($val) ?>"><?= h($val) ?></a>
            <?php elseif ($fd['field_type'] === 'checkbox'): ?>
              <?= $val ? '<i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i> Tak' : '<i class="bi bi-x-circle cv-muted" aria-hidden="true"></i> Nie' ?>
            <?php elseif ($fd['field_type'] === 'textarea'): ?>
              <span style="white-space:pre-line"><?= h($val) ?></span>
            <?php else: ?><?= h($val) ?><?php endif; ?>
          </dd>
        </div>
        <?php endforeach; ?>
      </dl>
      <?php else: ?>
      <p class="cv-meta mb-0">Brak wypełnionych pól.
        <?php if ($crm_can_write): ?><a href="<?= APP_URL ?>/crm/contact/add.php?id=<?= $id ?>">Uzupełnij</a><?php endif; ?>
      </p>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <!-- Osoby kontaktowe (tylko podmioty: organizacja / kontrahent / partner) -->
    <?php if (!empty(CRM_CONTACT_TYPES[$contact['type']]['org_like'])): ?>
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-people cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Osoby kontaktowe</h2>
        <div class="cv-shead__aside"><span class="cv-count"><?= count($contact['persons'] ?? []) ?></span></div>
      </div>
      <?= _cv_persons_html($contact, $id, $crm_can_write, $crm_can_delete) ?>
    </div></div>
    <?php endif; ?>

    <!-- Usługi na rzecz FEER (tylko status „Partner"; nie-partner z danymi widzi je do odczytu) -->
    <?php if (crm_services_allowed($contact) || crm_services_has_data($contact)): ?>
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-tools cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Usługi na rzecz <?= h(org_setting('org_short_name') ?: 'FEER') ?></h2>
        <?php if (!empty($contact['services'])): ?>
        <div class="cv-shead__aside"><span class="cv-count"><?= count($contact['services']) ?></span></div>
        <?php endif; ?>
      </div>
      <?= _cv_services_html($contact, $id, $crm_can_write, $crm_can_delete) ?>
    </div></div>
    <?php endif; ?>

    <!-- Powiązane konta -->
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-link-45deg cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Powiązane konta</h2>
      </div>
      <?= _cv_relations_html($contact, $id, $crm_can_write, $crm_can_delete, $all_contacts_for_relation) ?>
    </div></div>

    <!-- Grupy -->
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-collection cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Grupy</h2>
        <div class="cv-shead__aside">
          <a href="<?= APP_URL ?>/crm/groups.php" class="cv-meta" style="text-decoration:none">Zarządzaj</a>
        </div>
      </div>
      <?= _cv_groups_html($contact, $id, $crm_can_write) ?>
    </div></div>

    <!-- Informacje o rekordzie -->
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-info-circle cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Informacje o rekordzie</h2>
      </div>
      <table class="table table-sm table-borderless mb-0" style="font-size:.84rem">
        <tbody>
          <tr><td class="cv-muted pe-2">ID</td><td>#<?= $id ?></td></tr>
          <tr><td class="cv-muted pe-2">Typ</td>
              <td><?= $contact['type'] === 'organizacja' ? 'Organizacja' : 'Osoba' ?></td></tr>
          <tr><td class="cv-muted pe-2">Źródło</td><td><?= h(ucfirst($contact['source'] ?? '')) ?></td></tr>
          <tr><td class="cv-muted pe-2">Dodano</td><td><?= date_pl($contact['created_at']) ?></td></tr>
          <tr><td class="cv-muted pe-2">Zmieniono</td><td><?= date_pl($contact['updated_at']) ?></td></tr>
          <?php if ($contact['synced_at']): ?>
          <tr><td class="cv-muted pe-2">Zsync.</td><td><?= date_pl($contact['synced_at']) ?></td></tr>
          <?php endif; ?>
          <?php if ($contact['person_id']): ?>
          <tr><td class="cv-muted pe-2">Osoba</td>
              <td><a href="<?= APP_URL ?>/persons/view.php?id=<?= (int)$contact['person_id'] ?>">#<?= (int)$contact['person_id'] ?></a></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div></div>

    <!-- Strefa zagrożenia -->
    <?php if ($crm_can_delete): ?>
    <div class="cv-panel cv-panel--danger"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-exclamation-octagon cv-shead__icon" style="color:#B42318" aria-hidden="true"></i>
        <h2 class="cv-shead__title danger">Strefa zagrożenia</h2>
      </div>
      <form method="post">
        <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
        <input type="hidden" name="_action"    value="delete">
        <input type="hidden" name="contact_id" value="<?= $id ?>">
        <button class="btn btn-outline-danger btn-sm w-100" type="submit"
                data-contact-name="<?= h($contact['imie_nazwisko']) ?>"
                id="cv-delete-btn"
                aria-label="Usuń kontakt <?= h($contact['imie_nazwisko']) ?>">
          <i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń kontakt
        </button>
      </form>
      <p class="cv-meta mt-2 mb-0">Soft-delete — dane nie zostaną trwale skasowane.</p>
    </div></div>
    <?php endif; ?>

    <!-- ── Microsoft 365 ─────────────────────────────────────────────────── -->
    <?php if ($crm_can_write && $office_st['graph_configured']): ?>
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-microsoft cv-shead__icon" style="color:#0176D3" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Microsoft 365</h2>
      </div>

      <dl class="mb-2" style="font-size:.8rem">
        <div class="d-flex justify-content-between py-1">
          <dt class="text-muted fw-normal">Książka adresowa</dt>
          <dd class="mb-0 fw-semibold">
            <?php if (!empty($office_row['outlook_id'])): ?>
              <span class="text-success"><i class="bi bi-check-circle-fill"></i> powiązany</span>
            <?php else: ?>
              <span class="text-muted">brak wpisu</span>
            <?php endif; ?>
          </dd>
        </div>
        <?php if (!empty($office_row['office_pushed_at'])): ?>
        <div class="d-flex justify-content-between py-1">
          <dt class="text-muted fw-normal">Ostatni zapis</dt>
          <dd class="mb-0"><?= h(date('d.m.Y H:i', strtotime((string)$office_row['office_pushed_at']))) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($office_row['office_mail_pulled_at'])): ?>
        <div class="d-flex justify-content-between py-1">
          <dt class="text-muted fw-normal">Maile pobrane</dt>
          <dd class="mb-0"><?= h(date('d.m.Y H:i', strtotime((string)$office_row['office_mail_pulled_at']))) ?></dd>
        </div>
        <?php endif; ?>
      </dl>

      <?php if (!empty($office_row['office_push_error'])): ?>
      <div class="alert alert-danger py-1 px-2 mb-2" style="font-size:.75rem">
        <?= h($office_row['office_push_error']) ?>
      </div>
      <?php endif; ?>

      <div class="d-grid gap-2">
        <button type="button" class="btn btn-crm-outline btn-sm" id="mo-push"
          <?= $office_st['push_enabled'] ? '' : 'disabled title="Włącz zapis w Ustawieniach CRM → Microsoft 365"' ?>>
          <i class="bi bi-person-plus me-1" aria-hidden="true"></i>
          <?= !empty($office_row['outlook_id']) ? 'Zaktualizuj w książce adresowej' : 'Dodaj do książki adresowej' ?>
        </button>
        <button type="button" class="btn btn-crm-outline btn-sm" id="mo-mail"
          <?= empty($contact['email']) ? 'disabled title="Kontakt nie ma adresu e-mail"' : '' ?>>
          <i class="bi bi-envelope-arrow-down me-1" aria-hidden="true"></i>
          Pobierz maile z Outlooka
        </button>
      </div>
      <div class="cv-meta mt-2" id="mo-status" aria-live="polite">
        Korespondencja z ostatnich <?= (int)$office_st['mail_days'] ?> dni ze skrzynki
        <?= h($office_st['mailbox'] ?: '—') ?>.
      </div>
    </div></div>
    <script>
    (function () {
      var st = document.getElementById('mo-status');
      function call(action, btn, done) {
        var label = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Pracuję…';
        fetch('<?= APP_URL ?>/crm/api/office.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json', 'X-CSRF-Token': '<?= csrf_token() ?>'},
          body: JSON.stringify({action: action, contact_id: <?= (int)$id ?>, _csrf: '<?= csrf_token() ?>'})
        }).then(function (r) { return r.json().then(function (j) { return {status: r.status, json: j}; }); })
          .then(function (res) {
            btn.innerHTML = label;
            btn.disabled = false;
            if (res.json && res.json.ok) {
              st.innerHTML = '<span class="text-success">' + (res.json.data.message || 'Gotowe.') + '</span>';
              if (done) done(res.json.data);
            } else {
              st.innerHTML = '<span class="text-danger">' + ((res.json && res.json.error) || 'Błąd operacji.') + '</span>';
            }
          })
          .catch(function () {
            btn.innerHTML = label; btn.disabled = false;
            st.innerHTML = '<span class="text-danger">Brak połączenia z serwerem.</span>';
          });
      }
      var p = document.getElementById('mo-push');
      if (p) p.addEventListener('click', function () { call('push_contact', p); });
      var m = document.getElementById('mo-mail');
      if (m) m.addEventListener('click', function () {
        call('pull_mail', m, function (d) {
          // Nowe wiadomości trafiły do historii komunikacji — odśwież widok
          if (d && d.logged > 0) setTimeout(function () { location.reload(); }, 900);
        });
      });
    })();
    </script>
    <?php endif; ?>

  </div><!-- /aside -->

  <!-- ── GŁÓWNA: zakładki ───────────────────────────────────────────────────── -->
  <div class="col-lg-8">

    <ul class="nav cv-tabs mb-3" id="cvTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="cv-tab-activity-btn" data-bs-toggle="tab"
                data-bs-target="#cv-tab-activity" type="button" role="tab"
                aria-controls="cv-tab-activity" aria-selected="true">
          <i class="bi bi-activity" aria-hidden="true"></i>Aktywność
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="cv-tab-notes-btn" data-bs-toggle="tab"
                data-bs-target="#cv-tab-notes" type="button" role="tab"
                aria-controls="cv-tab-notes" aria-selected="false">
          <i class="bi bi-sticky" aria-hidden="true"></i>Notatki
          <span class="cv-count"><?= count($contact['notes']) ?></span>
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="cv-tab-cases-btn" data-bs-toggle="tab"
                data-bs-target="#cv-tab-cases" type="button" role="tab"
                aria-controls="cv-tab-cases" aria-selected="false">
          <i class="bi bi-briefcase" aria-hidden="true"></i>Sprawy
          <span class="cv-count"><?= count($contact_cases) ?></span>
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="cv-tab-offers-btn" data-bs-toggle="tab"
                data-bs-target="#cv-tab-offers" type="button" role="tab"
                aria-controls="cv-tab-offers" aria-selected="false">
          <i class="bi bi-file-earmark-ruled" aria-hidden="true"></i>Oferty
          <span class="cv-count"><?= count($contact_offers) ?></span>
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="cv-tab-engage-btn" data-bs-toggle="tab"
                data-bs-target="#cv-tab-engage" type="button" role="tab"
                aria-controls="cv-tab-engage" aria-selected="false">
          <i class="bi bi-people-fill" aria-hidden="true"></i>Zaangażowanie
        </button>
      </li>
    </ul>

    <div class="tab-content">

      <!-- ZAKŁADKA: Aktywność -->
      <div class="tab-pane fade show active" id="cv-tab-activity" role="tabpanel"
           aria-labelledby="cv-tab-activity-btn" tabindex="0">

        <div class="cv-panel" id="activities-section"><div class="cv-panel__body">
          <div class="cv-shead">
            <i class="bi bi-lightning-charge-fill cv-shead__icon" style="color:#B45309" aria-hidden="true"></i>
            <h2 class="cv-shead__title">Planowane działania</h2>
          </div>
          <?= _cv_activities_html($id, $crm_can_write, $crm_can_delete) ?>
        </div></div>

        <div class="cv-panel"><div class="cv-panel__body">
          <div class="cv-shead">
            <i class="bi bi-chat-left-text cv-shead__icon" aria-hidden="true"></i>
            <h2 class="cv-shead__title">Historia komunikacji</h2>
            <div class="cv-shead__aside">
              <button type="button" class="btn btn-sm btn-crm-outline" onclick="openCommModal(<?= $id ?>,'email')">
                <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij
              </button>
            </div>
          </div>
          <?= _cv_communications_html($contact, $id) ?>
        </div></div>

      </div>

      <!-- ZAKŁADKA: Notatki -->
      <div class="tab-pane fade" id="cv-tab-notes" role="tabpanel"
           aria-labelledby="cv-tab-notes-btn" tabindex="0">
        <div class="cv-panel"><div class="cv-panel__body">
          <div class="cv-shead">
            <i class="bi bi-sticky cv-shead__icon" aria-hidden="true"></i>
            <h2 class="cv-shead__title">Notatki</h2>
            <div class="cv-shead__aside"><span class="cv-count"><?= count($contact['notes']) ?></span></div>
          </div>
          <?= _cv_notes_html($contact, $id, $crm_can_write, $crm_can_delete) ?>
        </div></div>
      </div>

      <!-- ZAKŁADKA: Sprawy -->
      <div class="tab-pane fade" id="cv-tab-cases" role="tabpanel"
           aria-labelledby="cv-tab-cases-btn" tabindex="0">
        <div class="cv-panel"><div class="cv-panel__body">
          <div class="cv-shead">
            <i class="bi bi-briefcase cv-shead__icon" style="color:#1D4ED8" aria-hidden="true"></i>
            <h2 class="cv-shead__title">Sprawy</h2>
            <div class="cv-shead__aside">
              <span class="cv-count"><?= count($contact_cases) ?></span>
              <?php if ($crm_can_write): ?>
              <a href="<?= APP_URL ?>/crm/cases/add.php?contact_id=<?= (int)$id ?>"
                 class="btn btn-crm-outline btn-sm py-0 px-2">
                <i class="bi bi-plus me-1" aria-hidden="true"></i>Nowa
              </a>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($contact_cases): ?>
          <div class="d-flex flex-column">
            <?php foreach ($contact_cases as $cs):
              $csc = $case_status_cfg[$cs['status']] ?? $case_status_cfg['open'];
            ?>
            <a href="<?= APP_URL ?>/crm/cases/view.php?id=<?= (int)$cs['id'] ?>"
               class="d-flex align-items-center gap-2 py-2 border-bottom text-decoration-none"
               style="color:#181818;font-size:.88rem">
              <span style="width:8px;height:8px;border-radius:50%;background:<?= $csc['color'] ?>;flex-shrink:0" aria-hidden="true"></span>
              <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600">
                <?= h($cs['title']) ?>
              </span>
              <span style="display:inline-flex;align-items:center;padding:.12rem .55rem;border-radius:2rem;font-size:.72rem;font-weight:600;background:<?= $csc['bg'] ?>;color:<?= $csc['color'] ?>;white-space:nowrap">
                <?= $csc['label'] ?>
              </span>
            </a>
            <?php endforeach; ?>
          </div>
          <a href="<?= APP_URL ?>/crm/cases/index.php?contact_id=<?= (int)$id ?>"
             class="btn btn-crm-outline btn-sm w-100 mt-3">
            Wszystkie sprawy tego kontaktu
          </a>
          <?php else: ?>
          <div class="cv-empty">
            <i class="bi bi-briefcase" aria-hidden="true"></i>
            Brak spraw
            <?php if ($crm_can_write): ?>
            <div class="mt-1"><a href="<?= APP_URL ?>/crm/cases/add.php?contact_id=<?= (int)$id ?>">Utwórz pierwszą →</a></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div></div>
      </div>

      <!-- ZAKŁADKA: Oferty (działalność odpłatna) -->
      <div class="tab-pane fade" id="cv-tab-offers" role="tabpanel"
           aria-labelledby="cv-tab-offers-btn" tabindex="0">
        <div class="cv-panel"><div class="cv-panel__body">
          <div class="cv-shead">
            <i class="bi bi-file-earmark-ruled cv-shead__icon" style="color:#0176D3" aria-hidden="true"></i>
            <h2 class="cv-shead__title">Oferty</h2>
            <div class="cv-shead__aside">
              <span class="cv-count"><?= count($contact_offers) ?></span>
              <?php if ($offers_can_write): ?>
              <a href="<?= APP_URL ?>/crm/offers/form.php?contact_id=<?= (int)$id ?>"
                 class="btn btn-crm-outline btn-sm py-0 px-2">
                <i class="bi bi-plus me-1" aria-hidden="true"></i>Nowa oferta
              </a>
              <?php endif; ?>
            </div>
          </div>

          <div class="row g-2 text-center mb-3">
            <div class="col-3"><div class="fw-bold" style="font-size:1.05rem"><?= (int)$offers_summary['cnt'] ?></div>
              <div class="text-muted" style="font-size:.7rem">ofert łącznie</div></div>
            <div class="col-3"><div class="fw-bold text-success" style="font-size:1.05rem"><?= (int)$offers_summary['won'] ?></div>
              <div class="text-muted" style="font-size:.7rem">wygrane</div></div>
            <div class="col-3"><div class="fw-bold text-danger" style="font-size:1.05rem"><?= (int)$offers_summary['lost'] ?></div>
              <div class="text-muted" style="font-size:.7rem">odrzucone</div></div>
            <div class="col-3"><div class="fw-bold" style="font-size:1.05rem"><?= (int)$offers_summary['open'] ?></div>
              <div class="text-muted" style="font-size:.7rem">w toku</div></div>
          </div>
          <?php if ((float)$offers_summary['won_value'] > 0): ?>
          <div class="mb-2" style="font-size:.85rem">
            Wartość wygranych ofert: <strong><?= h(crm_offer_money((float)$offers_summary['won_value'])) ?></strong>
          </div>
          <?php endif; ?>

          <?php if ($contact_offers): ?>
          <div class="d-flex flex-column">
            <?php foreach ($contact_offers as $co):
              $co_needs = crm_offer_requires_confirmation($co);
              $co_conf  = (int)($co['confirmation_id'] ?? 0); ?>
            <a href="<?= APP_URL ?>/crm/offers/view.php?id=<?= (int)$co['id'] ?>"
               class="d-flex align-items-center gap-2 py-2 border-bottom text-decoration-none"
               style="color:#181818;font-size:.86rem">
              <span style="font-family:monospace;font-size:.74rem;color:#1D4ED8;white-space:nowrap"><?= h($co['offer_number']) ?></span>
              <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600"><?= h($co['title']) ?></span>
              <?php if ($co_needs && !$co_conf): ?>
              <span title="Brak potwierdzenia klienta (osoba fizyczna)" style="color:#B45309">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
              </span>
              <?php endif; ?>
              <span style="white-space:nowrap;font-weight:600"><?= h(crm_offer_money((float)$co['total_gross'], (string)$co['currency'])) ?></span>
              <?= crm_offer_status_pill((string)$co['status'], true) ?>
            </a>
            <?php endforeach; ?>
          </div>
          <a href="<?= APP_URL ?>/crm/offers/index.php?contact_id=<?= (int)$id ?>"
             class="btn btn-crm-outline btn-sm w-100 mt-3">Rejestr ofert tego klienta</a>
          <?php else: ?>
          <div class="cv-empty">
            <i class="bi bi-file-earmark-ruled" aria-hidden="true"></i>
            Brak ofert
            <?php if ($offers_can_write): ?>
            <div class="mt-1"><a href="<?= APP_URL ?>/crm/offers/form.php?contact_id=<?= (int)$id ?>">Przygotuj pierwszą →</a></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div></div>
      </div>

      <!-- ZAKŁADKA: Zaangażowanie (działania + wolontariat) -->
      <div class="tab-pane fade" id="cv-tab-engage" role="tabpanel"
           aria-labelledby="cv-tab-engage-btn" tabindex="0">

        <!-- Działania powiązane -->
        <div class="cv-panel"><div class="cv-panel__body">
          <div class="cv-shead">
            <i class="bi bi-calendar-event cv-shead__icon" aria-hidden="true"></i>
            <h2 class="cv-shead__title">Działania</h2>
            <div class="cv-shead__aside">
              <a href="<?= APP_URL ?>/strategy/actions/index.php" class="cv-meta" style="text-decoration:none">Wszystkie</a>
            </div>
          </div>
          <?= _cv_action_links_html($contact_actions, $id, $crm_can_write, $available_actions, !empty($all_actions_raw)) ?>
        </div></div>

        <!-- Wolontariat -->
        <div class="cv-panel"><div class="cv-panel__body">
          <div class="cv-shead">
            <i class="bi bi-people-fill cv-shead__icon" aria-hidden="true"></i>
            <h2 class="cv-shead__title">Wolontariat</h2>
            <div class="cv-shead__aside">
              <a href="<?= APP_URL ?>/contracts/wolontariat/list.php" class="cv-meta" style="text-decoration:none">Wszystkie</a>
            </div>
          </div>
          <?php if (!($contact['email'] ?? '')): ?>
          <p class="cv-meta mb-0">Brak e-mail — nie można dopasować.</p>
          <?php else: ?>

          <!-- Rekrutacje -->
          <?php if ($volunteer_recruitments):
            $app_statuses = [
              'new'=>['label'=>'Nowa','color'=>'#1D4ED8'],'reviewing'=>['label'=>'W ocenie','color'=>'#B45309'],
              'interview'=>['label'=>'Rozmowa','color'=>'#7F2B8B'],'accepted'=>['label'=>'Przyjęta','color'=>'#2E844A'],
              'rejected'=>['label'=>'Odrzucona','color'=>'#DC2626'],'withdrawn'=>['label'=>'Wycofana','color'=>'#5E6470'],
            ];
            foreach ($volunteer_recruitments as $app):
              $ast = $app_statuses[$app['status']] ?? ['label'=>$app['status'],'color'=>'#5E6470'];
          ?>
          <div class="d-flex align-items-start gap-2 p-2 border rounded mb-1" style="font-size:.86rem">
            <i class="bi bi-person-check mt-1 flex-shrink-0" style="color:<?= h($ast['color']) ?>" aria-hidden="true"></i>
            <div class="flex-grow-1 overflow-hidden">
              <a href="<?= APP_URL ?>/contracts/rekrutacja/view.php?id=<?= (int)$app['offer_id'] ?>"
                 class="fw-semibold text-dark text-decoration-none d-block text-truncate">
                <?= h($app['offer_title'] ?: '—') ?>
              </a>
              <div class="cv-meta">
                <span style="color:<?= h($ast['color']) ?>;font-weight:600"><?= h($ast['label']) ?></span>
                <?php if ($app['created_at']): ?> · <?= date('d.m.Y', strtotime($app['created_at'])) ?><?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach;
          else: ?><p class="cv-meta mb-1">Brak rekrutacji.</p><?php endif; ?>

          <!-- Umowy wolontariackie -->
          <div class="border-top pt-2 mt-2">
            <?php if ($volunteer_contracts):
              $contract_statuses = [
                'projekt'=>['label'=>'Projekt','color'=>'#5E6470'],'podpisana'=>['label'=>'Podpisana','color'=>'#1D4ED8'],
                'w realizacji'=>['label'=>'W realizacji','color'=>'#2E844A'],'zakończona'=>['label'=>'Zakończona','color'=>'#032D60'],
                'rozwiązana'=>['label'=>'Rozwiązana','color'=>'#B45309'],'anulowana'=>['label'=>'Anulowana','color'=>'#DC2626'],
              ];
              foreach ($volunteer_contracts as $wol):
                $wst = $contract_statuses[$wol['status']] ?? ['label'=>$wol['status'],'color'=>'#5E6470'];
            ?>
            <div class="d-flex align-items-start gap-2 p-2 border rounded mb-1" style="font-size:.86rem">
              <i class="bi bi-file-earmark-text mt-1 flex-shrink-0" style="color:<?= h($wst['color']) ?>" aria-hidden="true"></i>
              <div class="flex-grow-1 overflow-hidden">
                <a href="<?= APP_URL ?>/contracts/wolontariat/view.php?id=<?= (int)$wol['id'] ?>"
                   class="fw-semibold text-dark text-decoration-none d-block text-truncate">
                  <?= h($wol['numer_umowy'] ?: '#' . $wol['id']) ?>
                </a>
                <div class="cv-meta">
                  <span style="color:<?= h($wst['color']) ?>;font-weight:600"><?= h($wst['label']) ?></span>
                  <?php if ($wol['data_od']): ?> · <?= date('d.m.Y', strtotime($wol['data_od'])) ?><?php endif; ?>
                </div>
              </div>
            </div>
            <?php endforeach;
            else: ?><p class="cv-meta mb-0">Brak umów wolontariackich.</p><?php endif; ?>
          </div>
          <?php endif; ?>
        </div></div>

      </div>

    </div><!-- /tab-content -->

  </div><!-- /główna -->
</div><!-- /row -->

<!-- ══ MODALS: Planowane działania ═══════════════════════════════════════════ -->
<?php if ($crm_can_write): ?>
<div class="modal fade" id="activityModal" tabindex="-1" aria-labelledby="actModalLabel" aria-modal="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title" id="actModalLabel" style="font-size:.95rem">
          <i class="bi bi-lightning-charge-fill me-2" style="color:#D97706" aria-hidden="true"></i>Nowe działanie
        </h5>
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal" aria-label="Zamknij modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex flex-wrap gap-2 mb-3" id="actTypePicker" role="group" aria-label="Typ działania">
          <?php foreach (_cv_activity_cfg() as $tv => $tc): ?>
          <button type="button" class="act-type-pill <?= $tv==='call'?'active':'' ?>"
                  data-type="<?= $tv ?>"
                  style="--at-color:<?= $tc['color'] ?>;--at-bg:<?= $tc['bg'] ?>"
                  onclick="ActivityUI.pickType(this)"
                  aria-pressed="<?= $tv==='call'?'true':'false' ?>">
            <i class="bi <?= $tc['icon'] ?>" aria-hidden="true"></i>
            <span><?= $tc['label'] ?></span>
          </button>
          <?php endforeach; ?>
        </div>
        <input type="hidden" id="actType" value="call">
        <div class="mb-2">
          <label class="form-label fw-semibold small" for="actTitle">Tytuł <span class="text-danger" aria-hidden="true">*</span></label>
          <input id="actTitle" type="text" class="form-control form-control-sm"
                 placeholder="np. Rozmowa, Ustalenie warunków…"
                 aria-required="true">
        </div>
        <div class="row g-2 mb-2">
          <div class="col-sm-7">
            <label class="form-label fw-semibold small" for="actDate">Termin</label>
            <input id="actDate" type="datetime-local" class="form-control form-control-sm">
          </div>
          <div class="col-sm-5">
            <label class="form-label fw-semibold small" for="actDuration">Czas (min)</label>
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
          <label class="form-label fw-semibold small" for="actAssignee">Przypisz do</label>
          <select id="actAssignee" class="form-select form-select-sm">
            <option value="">— aktualny użytkownik —</option>
          </select>
        </div>
        <div>
          <label class="form-label fw-semibold small" for="actDesc">Notatka</label>
          <textarea id="actDesc" class="form-control form-control-sm" rows="2"
                    placeholder="Cel działania, szczegóły…"></textarea>
        </div>
        <div id="actError" class="alert alert-danger py-1 mt-2" style="display:none;font-size:.8rem" role="alert"></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-sm" id="actSaveBtn"
                style="background:#D97706;color:#fff;border:none"
                onclick="ActivityUI.save()">
          <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zaplanuj
        </button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="actCompleteModal" tabindex="-1" aria-modal="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title" style="font-size:.9rem">
          <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>Wykonano działanie
        </h5>
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="completeActId" value="">
        <label class="form-label fw-semibold small" for="actOutcome">Wynik / notatka (opcjonalnie)</label>
        <textarea id="actOutcome" class="form-control form-control-sm" rows="3"
                  placeholder="Efekt rozmowy, następny krok…"></textarea>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-success btn-sm" onclick="ActivityUI.confirmComplete()">
          <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Oznacz jako wykonane
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<style>
/* ── Stylizacja komponentów view.php ─────────────────────────────── */
.act-row { display:flex;align-items:flex-start;gap:.6rem;padding:.55rem .5rem;border-radius:8px;margin-bottom:.3rem;transition:background .1s }
.act-row:hover { background:#F9FAFB }
.act-row.done  { opacity:.6 }
.act-type-icon { width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0;margin-top:.05rem }
.act-info { flex:1;min-width:0 }
.act-title { font-size:.84rem;font-weight:600;color:#111827;overflow:hidden;text-overflow:ellipsis;white-space:nowrap }
.act-meta  { font-size:.76rem;color:#5E6470;margin-top:1px }
.act-desc  { font-size:.76rem;color:#6B7280;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap }
.act-actions { display:flex;gap:.25rem;flex-shrink:0;align-items:flex-start;margin-top:.05rem }
.act-type-pill { display:flex;align-items:center;gap:.35rem;padding:.3rem .65rem;border-radius:2rem;border:1.5px solid #E5E7EB;font-size:.74rem;font-weight:600;color:#6B7280;background:#fff;cursor:pointer;transition:all .12s }
.act-type-pill:hover { border-color:#9CA3AF;color:#374151 }
.act-type-pill.active { border-color:var(--at-color);background:var(--at-bg);color:var(--at-color) }
.act-type-pill i { font-size:.85rem }
details summary::-webkit-details-marker { display:none }
details[open] #done-chevron { transform:rotate(90deg) }

/* Sekcje AJAX — loading state */
[data-loading="true"] { opacity:.5;pointer-events:none;transition:opacity .15s }

/* Growing textarea */
.cv-growing-textarea { resize:none;overflow:hidden;min-height:56px }

/* Quick contact chips */
.cv-contact-chip:hover { color:var(--crm-primary) !important }
</style>

<script>
/* ══ AJAX sekcji ══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var CONTACT_ID = <?= (int)$id ?>;
  var BASE_URL   = '<?= APP_URL ?>/crm/contact/view.php';
  var liveEl     = document.getElementById('cv-live');

  function announce(msg) {
    if (!liveEl) return;
    liveEl.textContent = '';
    setTimeout(function () { liveEl.textContent = msg; }, 50);
  }

  /* Odświeża jedną sekcję z serwera (GET ?_section=name) */
  function refreshSection(name) {
    return fetch(BASE_URL + '?id=' + CONTACT_ID + '&_section=' + encodeURIComponent(name))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok || !data.html) return;
        var target = document.getElementById('crm-section-' + name);
        if (!target) {
          // Obsługa aliasów (activities, comms)
          target = document.getElementById('crm-section-' +
            (name === 'action-links' ? 'action-links' : name));
        }
        if (target) {
          target.innerHTML = data.html;
          // Zastąp innerHTML (inner wrapping div)
          target.removeAttribute('data-loading');
        }
        rebindSection(name);
      })
      .catch(function () {});
  }

  /* Wysyła POST z XHR i po odpowiedzi aktualizuje wskazaną sekcję */
  function postSection(form, sectionEl) {
    var fd = new FormData(form);
    sectionEl.setAttribute('data-loading', 'true');

    fetch(BASE_URL + '?id=' + CONTACT_ID, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        sectionEl.removeAttribute('data-loading');
        if (data.ok && data.html && data.section) {
          // Znajdź kontener tej sekcji
          var wrapId = 'crm-section-' + data.section;
          var wrap = document.getElementById(wrapId);
          if (wrap) {
            wrap.outerHTML = data.html;
            rebindSection(data.section);
          }
          announce('Zapisano.');
          // Wyczyść textarea po dodaniu notatki
          if (data.section === 'notes') {
            var ta = document.getElementById('cv_note_body');
            if (ta) { ta.value = ''; ta.style.height = ''; }
            var cb = document.getElementById('cv_note_pinned');
            if (cb) cb.checked = false;
          }
          // Wyczyść input tagu
          if (data.section === 'tags') {
            var ti = document.querySelector('#crm-section-tags input[name="tag"]');
            if (ti) ti.value = '';
          }
        }
      })
      .catch(function () { sectionEl.removeAttribute('data-loading'); });
  }

  /* Delegacja zdarzeń — formularz wewnątrz sekcji AJAX */
  function bindForms(root) {
    root = root || document;
    root.querySelectorAll('form[data-ajax-section]').forEach(function (form) {
      if (form._cvBound) return;
      form._cvBound = true;
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var secName = form.dataset.ajaxSection;
        // Znajdź wrapping div sekcji
        var sectionDiv = document.getElementById('crm-section-' + secName);
        // Fallback: szukaj rodziców
        if (!sectionDiv) sectionDiv = form.closest('.card');
        if (!sectionDiv) return;
        postSection(form, sectionDiv);
      });
    });
  }

  function rebindSection(name) {
    var root = document.getElementById('crm-section-' + name);
    if (root) bindForms(root);
    // Dla activities — rebind ActivityUI
    if (name === 'activities') {
      // editors
      var edEl = document.getElementById('cv-act-editors-data');
      if (edEl) {
        try {
          var eds = JSON.parse(edEl.dataset.editors || '[]');
          var sel = document.getElementById('actAssignee');
          if (sel && eds.length) {
            sel.innerHTML = '<option value="">— aktualny użytkownik —</option>';
            eds.forEach(function (e) {
              var o = document.createElement('option');
              o.value = e.id; o.textContent = e.n;
              sel.appendChild(o);
            });
          }
        } catch(ex) {}
      }
    }
  }

  /* Przycisk usunięcia kontaktu — confirm */
  var delBtn = document.getElementById('cv-delete-btn');
  if (delBtn) {
    delBtn.addEventListener('click', function (e) {
      var name = delBtn.dataset.contactName || 'kontakt';
      if (!confirm('Usunąć kontakt ' + name + '?')) e.preventDefault();
    });
  }

  /* Growing textarea */
  document.querySelectorAll('.cv-growing-textarea').forEach(function (ta) {
    ta.addEventListener('input', function () {
      ta.style.height = 'auto';
      ta.style.height = (ta.scrollHeight) + 'px';
    });
  });

  /* Inicjalizacja */
  bindForms(document);
})();
</script>

<script>
/* ══ ActivityUI ═══════════════════════════════════════════════════════════════ */
const ActivityUI = (function () {
  const API        = '<?= APP_URL ?>/crm/api/activities.php';
  const CONTACT_ID = <?= (int)$id ?>;

  function req(payload) {
    return fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then(r => r.json());
  }

  function refreshActivities() {
    return fetch('<?= APP_URL ?>/crm/contact/view.php?id=' + CONTACT_ID + '&_section=activities')
      .then(r => r.json())
      .then(data => {
        if (!data.ok) return;
        var target = document.getElementById('crm-section-activities');
        if (target) target.outerHTML = data.html;
      });
  }

  function openNew() {
    document.getElementById('actTitle').value   = '';
    document.getElementById('actDate').value    = '';
    document.getElementById('actOutcome').value = '';
    document.getElementById('actError').style.display = 'none';
    document.getElementById('actDuration').value = '60';
    document.getElementById('actAssignee').value = '';
    document.getElementById('actDesc').value    = '';

    // Załaduj listę edytorów z data-attr (wyrenderowanych przez PHP w sekcji)
    var edEl = document.getElementById('cv-act-editors-data');
    if (edEl) {
      try {
        var eds = JSON.parse(edEl.dataset.editors || '[]');
        var sel = document.getElementById('actAssignee');
        if (sel) {
          sel.innerHTML = '<option value="">— aktualny użytkownik —</option>';
          eds.forEach(e => {
            var o = document.createElement('option');
            o.value = e.id; o.textContent = e.n;
            sel.appendChild(o);
          });
        }
      } catch(ex) {}
    }

    var pill = document.querySelector('.act-type-pill[data-type="call"]');
    if (pill) pickType(pill);
    new bootstrap.Modal(document.getElementById('activityModal')).show();
    setTimeout(() => document.getElementById('actTitle').focus(), 200);
  }

  function pickType(btn) {
    document.querySelectorAll('.act-type-pill').forEach(p => {
      p.classList.remove('active');
      p.setAttribute('aria-pressed', 'false');
    });
    btn.classList.add('active');
    btn.setAttribute('aria-pressed', 'true');
    document.getElementById('actType').value = btn.dataset.type;
  }

  function save() {
    const title = document.getElementById('actTitle').value.trim();
    if (!title) {
      const er = document.getElementById('actError');
      er.textContent = 'Wpisz tytuł działania.';
      er.style.display = '';
      document.getElementById('actTitle').focus();
      return;
    }
    const btn = document.getElementById('actSaveBtn');
    btn.disabled = true;
    req({
      action: 'create', contact_id: CONTACT_ID,
      type:         document.getElementById('actType').value,
      title,
      description:  document.getElementById('actDesc').value.trim() || null,
      scheduled_at: document.getElementById('actDate').value || null,
      duration_min: document.getElementById('actDuration').value || null,
      assigned_to:  document.getElementById('actAssignee').value || null,
    }).then(res => {
      btn.disabled = false;
      if (res.ok) {
        bootstrap.Modal.getInstance(document.getElementById('activityModal'))?.hide();
        refreshActivities();
      } else {
        document.getElementById('actError').textContent = res.error || 'Błąd.';
        document.getElementById('actError').style.display = '';
      }
    }).catch(() => { btn.disabled = false; });
  }

  function complete(id) {
    document.getElementById('completeActId').value = id;
    document.getElementById('actOutcome').value    = '';
    new bootstrap.Modal(document.getElementById('actCompleteModal')).show();
    setTimeout(() => document.getElementById('actOutcome').focus(), 200);
  }

  function confirmComplete() {
    const id = parseInt(document.getElementById('completeActId').value);
    req({ action: 'complete', id, outcome: document.getElementById('actOutcome').value.trim() })
      .then(res => {
        if (res.ok) {
          bootstrap.Modal.getInstance(document.getElementById('actCompleteModal'))?.hide();
          refreshActivities();
        }
      });
  }

  function reopen(id) {
    req({ action: 'reopen', id }).then(res => { if (res.ok) refreshActivities(); });
  }

  function del(id) {
    if (!confirm('Usunąć to działanie?')) return;
    req({ action: 'delete', id }).then(res => { if (res.ok) refreshActivities(); });
  }

  return { openNew, pickType, save, complete, confirmComplete, reopen, del };
})();
</script>

<?php if ($crm_can_write): ?>
<!-- Modal: konwersja typu kontaktu -->
<div class="modal fade" id="convertTypeModal" tabindex="-1"
     aria-labelledby="convertTypeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title fs-6" id="convertTypeModalLabel">
          <i class="bi bi-arrow-left-right me-2" aria-hidden="true"></i>Zmień typ kontaktu
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <form method="post">
        <div class="modal-body">
          <input type="hidden" name="_csrf"       value="<?= csrf_token() ?>">
          <input type="hidden" name="_action"     value="convert_type">
          <?php $ct_cur = CRM_CONTACT_TYPES[$contact['type']] ?? CRM_CONTACT_TYPES['osoba']; ?>
          <p class="mb-2" style="font-size:.88rem">
            Aktualny typ: <strong><?= h($ct_cur['label']) ?></strong>
          </p>
          <div class="mb-2">
            <label class="form-label small mb-1">Zmień na:</label>
            <select name="target_type" class="form-select form-select-sm">
              <?php foreach (CRM_CONTACT_TYPES as $tkey => $tmeta): ?>
              <?php if ($tkey === $contact['type']) continue; ?>
              <option value="<?= $tkey ?>">
                <?= h($tmeta['label']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="alert alert-warning py-2 mb-0" style="font-size:.8rem" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
            <?php if ($ct_cur['org_like']): ?>
            Jeśli zmieniasz na Osobę fizyczną — zostaną wyczyszczone: NIP, KRS, REGON.
            <?php else: ?>
            Jeśli zmieniasz na typ org-podobny — zostaną wyczyszczone: Imię, Nazwisko, PESEL.
            <?php endif; ?>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-warning btn-sm">
            <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Zmień typ
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($crm_can_write && $contract_import_data):
  $_imp_labels = [
      'imie_nazwisko'  => 'Imię i nazwisko',
      'pesel'          => 'PESEL',
      'adres'          => 'Adres',
      'telefon'        => 'Telefon',
      'email'          => 'E-mail',
      'data_urodzenia' => 'Data urodzenia',
  ];
?>
<!-- Modal: import danych z umowy -->
<div class="modal fade" id="importContractModal" tabindex="-1"
     aria-labelledby="importContractModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title fs-6" id="importContractModalLabel">
          <i class="bi bi-file-earmark-arrow-down me-2" aria-hidden="true"></i>Zaciągnij dane z umowy
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <form id="importContractForm">
        <div class="modal-body">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id"    value="<?= (int)$id ?>">
          <p class="text-muted mb-2" style="font-size:.85rem">
            Zaznacz pola, które chcesz nadpisać danymi z umów tej osoby. Domyślnie zaznaczone są tylko puste pola.
          </p>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
              <thead>
                <tr>
                  <th style="width:2rem"></th>
                  <th>Pole</th>
                  <th>Z umowy</th>
                  <th class="text-muted">Obecnie</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($contract_import_data as $f => $info):
                  $cur = trim((string)($contact[$f] ?? '')); ?>
                <tr>
                  <td>
                    <input class="form-check-input" type="checkbox" name="fields[]"
                           value="<?= h($f) ?>" id="imp_<?= h($f) ?>"
                           <?= $cur === '' ? 'checked' : '' ?>>
                  </td>
                  <td><label for="imp_<?= h($f) ?>" class="mb-0"><?= h($_imp_labels[$f] ?? $f) ?></label></td>
                  <td>
                    <strong><?= h($info['value']) ?></strong>
                    <div class="text-muted" style="font-size:.72rem">
                      <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i><?= h($info['source']) ?>
                    </div>
                  </td>
                  <td class="text-muted"><?= $cur !== '' ? h($cur) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary btn-sm" id="importContractSubmit">
            <i class="bi bi-download me-1" aria-hidden="true"></i>Zaciągnij zaznaczone
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
  const form = document.getElementById('importContractForm');
  if (!form) return;
  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('importContractSubmit');
    btn.disabled = true;
    try {
      const res = await fetch('<?= APP_URL ?>/crm/contact/import_contract.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form),
      });
      const data = await res.json();
      if (data.ok) {
        window.location.reload();
      } else {
        alert(data.error || 'Nie udało się zaciągnąć danych.');
        btn.disabled = false;
      }
    } catch (err) {
      alert('Błąd połączenia. Spróbuj ponownie.');
      btn.disabled = false;
    }
  });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
