<?php
/**
 * crm/includes/cv/tab_data.php — Zakładka „Dane i powiązania" kartoteki kontaktu.
 *
 * Wydzielone z crm/contact/view.php: plik miał 3562 wiersze i każda zmiana
 * w jednej zakładce wymagała przewijania przez wszystkie pozostałe.
 *
 * Włączany przez include w zakresie crm/contact/view.php — korzysta z jego
 * zmiennych ($contact, $id, $crm_can_write, ...). Nie wywołuj samodzielnie.
 */
if (!isset($contact)) { http_response_code(400); exit; }
?>
      <!-- ZAKŁADKA: Dane i powiązania (dawna kolumna boczna) -->
      <div class="tab-pane fade" id="cv-tab-data" role="tabpanel"
           aria-labelledby="cv-tab-data-btn" tabindex="0">
        <div class="cv-datagrid">

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
    <!-- Zgody na komunikację (per cel) -->
    <?php
      $_consent_states = crm_consent_states($id);
      $_consent_yes    = count(array_filter($_consent_states, fn($e) => (int)$e['granted'] === 1));
    ?>
    <div class="cv-panel" id="cv-panel-consents"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-shield-check cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Zgody na komunikację</h2>
        <div class="cv-shead__aside">
          <span class="cv-count" id="cv-consents-count"><?= $_consent_yes ?></span>
          <a href="<?= APP_URL ?>/crm/settings/consents.php" class="cv-meta ms-2" style="text-decoration:none">Cele</a>
        </div>
      </div>
      <?= _cv_consents_html($contact, $id, $crm_can_write) ?>
    </div></div>

    <?php /* Osoby kontaktowe mają własną zakładkę (#cv-tab-persons) */ ?>
    <?php endif; ?>

    <?php
      /* Widok odwrotny: gdzie TA osoba jest osobą kontaktową. Bez tego z karty
         osoby nie dało się zobaczyć, przy jakich podmiotach figuruje — a to
         najczęstsze pytanie przy telefonie „dzwoni pani X, z ramienia kogo?". */
      $_as_person = [];
      try {
          $_as_person = crm_all(
              "SELECT p.id, p.stanowisko, p.is_primary, c.id AS org_id, c.imie_nazwisko AS org_name, c.type AS org_type
                 FROM crm_contact_persons p
                 JOIN crm_contacts c ON c.id = p.contact_id AND c.crm_active = 1
                WHERE p.linked_contact_id = ?
             ORDER BY c.imie_nazwisko", [$id]
          );
      } catch (\Throwable $e) {}
    ?>
    <?php if ($_as_person): ?>
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-diagram-2 cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Jest osobą kontaktową przy</h2>
        <div class="cv-shead__aside"><span class="cv-count"><?= count($_as_person) ?></span></div>
      </div>
      <ul class="list-unstyled mb-0">
        <?php foreach ($_as_person as $ap): ?>
        <li class="crm-relation-item align-items-center">
          <div class="crm-avatar sm" aria-hidden="true" style="background:var(--crm-navy)">
            <?= h(CrmManager::makeInitials($ap['org_name'])) ?>
          </div>
          <div class="flex-grow-1">
            <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$ap['org_id'] ?>"
               style="font-size:.83rem;font-weight:600;text-decoration:none"><?= h($ap['org_name']) ?></a>
            <?php if (!empty($ap['is_primary'])): ?>
            <span class="cv-chip" title="Osoba główna tego podmiotu">główna</span>
            <?php endif; ?>
            <?php if (!empty($ap['stanowisko'])): ?>
            <div class="crm-name-sub"><?= h($ap['stanowisko']) ?></div>
            <?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <p class="cv-meta mt-2 mb-0">
        Kartoteka tej osoby jest jedna — przy podmiotach figuruje jako powiązanie,
        więc zmiana e-maila czy telefonu tutaj nie wymaga poprawiania tamtych wpisów.
      </p>
    </div></div>
    <?php endif; ?>

    <?php /* Umowy mają teraz własną zakładkę (#cv-tab-contracts) */ ?>

    <!-- Darowizny -->
    <?php if (module_enabled('donations_enabled')): ?>
    <?php $_dons = donations_for_contact($id); ?>
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-gift cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Darowizny</h2>
        <div class="cv-shead__aside">
          <?php if ($_dons): ?><span class="cv-count"><?= count($_dons) ?></span><?php endif; ?>
          <a href="<?= APP_URL ?>/crm/donations/index.php" class="cv-meta ms-2" style="text-decoration:none">Rejestr</a>
        </div>
      </div>
      <?= _cv_donations_html($contact, $id, $crm_can_write) ?>
    </div></div>
    <?php endif; ?>

    <!-- Beneficjent działań (tylko dla osób z dostępem do Dydaktyki 3) -->
    <?php
      $_show_benef = crm_beneficiary_can_view()
                  && empty(CRM_CONTACT_TYPES[$contact['type']]['org_like']);
      $_benef = $_show_benef ? crm_contact_beneficiaries($contact) : ['linked' => [], 'suggested' => []];
    ?>
    <?php if ($_show_benef): ?>
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-mortarboard cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Beneficjent działań</h2>
        <div class="cv-shead__aside">
          <?php if ($_benef['linked']): ?>
          <span class="cv-count"><?= count($_benef['linked']) ?></span>
          <?php endif; ?>
          <?php if ($_benef['suggested']): ?>
          <span class="cv-chip ms-1" style="font-size:.7rem;color:#B45309;border-color:#FCD34D;background:#FFFBEB">
            <?= count($_benef['suggested']) ?> do potwierdzenia
          </span>
          <?php endif; ?>
        </div>
      </div>
      <?= _cv_beneficiaries_html($contact, $id, $crm_can_write) ?>
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
              <td><?= h(CRM_CONTACT_TYPES[$contact['type']]['label'] ?? $contact['type']) ?></td></tr>
          <tr><td class="cv-muted pe-2">Opiekun</td>
              <td><?= !empty($contact['owner_id'])
                      ? h(crm_audit_format('owner_id', (string)$contact['owner_id']))
                      : '<span class="cv-muted">nieprzypisany</span>' ?></td></tr>
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

    <!-- Historia zmian -->
    <?php $_audit = crm_audit_history($id, 60); ?>
    <?php if ($_audit): ?>
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-clock-history cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Historia zmian</h2>
        <div class="cv-shead__aside"><span class="cv-count"><?= count($_audit) ?></span></div>
      </div>
      <?php /* Zwinięta domyślnie — to materiał do sprawdzenia „kto zmienił e-mail”,
               a nie coś, co ma zajmować ekran przy każdym wejściu w kartotekę. */ ?>
      <details>
        <summary class="cv-meta" style="cursor:pointer">Pokaż <?= count($_audit) ?> ostatnich zmian</summary>
        <table class="table table-sm table-borderless mb-0 mt-2" style="font-size:.8rem">
          <caption class="visually-hidden">Historia zmian kartoteki</caption>
          <tbody>
          <?php foreach ($_audit as $a): ?>
            <tr>
              <td class="cv-muted pe-2 text-nowrap" style="width:8.5rem"><?= date_pl($a['created_at']) ?></td>
              <td>
                <span class="fw-semibold"><?= h(crm_audit_field_label($a['field'])) ?></span>
                <div class="cv-muted" style="font-size:.76rem">
                  <span style="text-decoration:line-through"><?= h(crm_audit_format($a['field'], $a['old_value'])) ?></span>
                  <i class="bi bi-arrow-right mx-1" aria-hidden="true"></i>
                  <?= h(crm_audit_format($a['field'], $a['new_value'])) ?>
                </div>
              </td>
              <td class="cv-muted text-end" style="width:9rem"><?= h($a['user_name'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    </div></div>
    <?php endif; ?>

    <?php /* Stan retencji przy kartotece: czy jest oznaczona do przeglądu, czy
             wyłączona, czy już zanonimizowana. Bez tego decyzja o wyłączeniu
             zapadałaby wyłącznie na ekranie ustawień, w oderwaniu od kartoteki. */ ?>
    <?php require_once dirname(dirname(dirname(__DIR__))) . '/includes/crm_retention.php';
          crm_retention_migrate();
          $cv_ret = db_one("SELECT anonymized_at, retention_flagged_at, COALESCE(retention_hold,0) AS hold
                              FROM crm_contacts WHERE id=?", [$id]) ?: []; ?>
    <?php if (!empty($cv_ret['anonymized_at']) || !empty($cv_ret['retention_flagged_at']) || !empty($cv_ret['hold'])): ?>
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-shield-check cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Retencja danych</h2>
      </div>
      <?php if (!empty($cv_ret['anonymized_at'])): ?>
      <p class="cv-meta mb-0">
        <i class="bi bi-eraser me-1" aria-hidden="true"></i>
        Dane osobowe usunięte <?= h(date('d.m.Y', strtotime((string)$cv_ret['anonymized_at']))) ?>.
        Kartoteka została w bazie jako ślad, że obowiązek wykonano.
      </p>
      <?php else: ?>
      <p class="cv-meta">
        <?php if (!empty($cv_ret['hold'])): ?>
        <i class="bi bi-lock me-1" aria-hidden="true"></i>
        Kartoteka jest <strong>wyłączona z retencji</strong> — reguły jej nie ruszą.
        <?php else: ?>
        <i class="bi bi-clock-history me-1" aria-hidden="true"></i>
        Oznaczona do przeglądu <?= h(date('d.m.Y', strtotime((string)$cv_ret['retention_flagged_at']))) ?>
        — spełnia regułę retencji. Nic się nie dzieje automatycznie.
        <?php endif; ?>
      </p>
      <?php if ($crm_can_write): ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="ret_hold">
        <input type="hidden" name="on" value="<?= empty($cv_ret['hold']) ? '1' : '0' ?>">
        <button class="btn btn-sm btn-crm-outline">
          <i class="bi bi-<?= empty($cv_ret['hold']) ? 'lock' : 'unlock' ?> me-1" aria-hidden="true"></i>
          <?= empty($cv_ret['hold']) ? 'Wyłącz z retencji' : 'Cofnij wyłączenie' ?>
        </button>
      </form>
      <?php endif; ?>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <!-- Strefa zagrożenia -->
    <?php if ($crm_can_delete): ?>
    <div class="cv-panel cv-panel--danger"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-exclamation-octagon cv-shead__icon" style="color:#B42318" aria-hidden="true"></i>
        <h2 class="cv-shead__title danger">Strefa zagrożenia</h2>
      </div>
      <form method="post">
        <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
        <input type="hidden" name="_action"    value="delete_contact">
        <input type="hidden" name="contact_id" value="<?= $id ?>">

        <label class="form-label small fw-semibold mb-1" for="cv_del_reason">
          Powód usunięcia <span class="text-danger" aria-hidden="true">*</span>
        </label>
        <select name="delete_reason" id="cv_del_reason" class="form-select form-select-sm mb-2" required>
          <option value="">— wybierz powód —</option>
          <?php foreach (CRM_DELETE_REASONS as $rk => $rl): ?>
          <option value="<?= h($rk) ?>"><?= h($rl) ?></option>
          <?php endforeach; ?>
        </select>

        <label class="visually-hidden" for="cv_del_note">Uzasadnienie</label>
        <textarea name="delete_note" id="cv_del_note" rows="2"
                  class="form-control form-control-sm mb-2"
                  placeholder="Uzasadnienie (wymagane przy powodzie „Inny”)"></textarea>

        <?php if (!empty($contact['email'])): ?>
        <div id="cv_del_spam" class="mb-2" hidden>
          <div class="alert alert-warning py-2 px-2 mb-1" style="font-size:.78rem">
            <i class="bi bi-shield-slash-fill me-1" aria-hidden="true"></i>
            Nadawca trafi do filtra i <strong>nie wróci już do CRM</strong> — auto-kartoteka
            przestanie go zakładać przy skanowaniu poczty.
          </div>
          <label class="form-label small mb-1" for="cv_del_scope">Zakres blokady</label>
          <select name="block_scope" id="cv_del_scope" class="form-select form-select-sm" aria-label="Zakres blokady nadawcy">
            <option value="email">tylko ten adres — <?= h($contact['email']) ?></option>
            <option value="domain">cała domena — @<?= h(crm_email_domain((string)$contact['email'])) ?></option>
          </select>
        </div>
        <?php endif; ?>

        <button class="btn btn-outline-danger btn-sm w-100" type="submit"
                data-contact-name="<?= h($contact['imie_nazwisko']) ?>"
                id="cv-delete-btn"
                aria-label="Usuń rekord <?= h($contact['imie_nazwisko']) ?>">
          <i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń rekord
        </button>
      </form>
      <p class="cv-meta mt-2 mb-0">
        Usunięcie miękkie — dane zostają w bazie, kartoteka znika z list. Powód zapisuje się
        w notatkach i w historii zmian.
      </p>
    </div></div>
    <?php endif; ?>

    <!-- ── Powiązane kartoteki ───────────────────────────────────────────── -->
    <?php /* Powiązanie łączy DWIE pełne kartoteki (osoba ↔ organizacja, podmiot ↔
             podmiot). Nie mylić z osobami kontaktowymi: tam osoba nie ma własnej
             kartoteki, jest danymi przy podmiocie. Zob. includes/crm_relations.php. */ ?>
    <?php require_once dirname(dirname(dirname(__DIR__))) . '/includes/crm_relations.php';
          $cv_rels  = crm_relations_for($id);
          $cv_rtypes = crm_relation_types(); ?>
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-diagram-3-fill cv-shead__icon" style="color:#7C3AED" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Powiązane kartoteki</h2>
        <div class="cv-shead__aside">
          <?php if ($cv_rels): ?><span class="cv-count"><?= count($cv_rels) ?></span><?php endif; ?>
        </div>
      </div>

      <?php if (!$cv_rels): ?>
      <p class="cv-meta mb-2">
        Brak powiązań. Tu zapisuje się, kto z kim i jak jest związany: członek zarządu,
        pracownik, opiekun prawny, oddział, partner — obie strony widzą to u siebie.
      </p>
      <?php else: ?>
      <ul class="list-unstyled mb-2">
        <?php foreach ($cv_rels as $rel): ?>
        <li class="d-flex align-items-center gap-2 py-1" style="border-bottom:1px solid var(--crm-border)">
          <i class="bi <?= h($rel['icon']) ?> text-muted" aria-hidden="true"></i>
          <div class="flex-grow-1 overflow-hidden">
            <div style="font-size:.83rem">
              <span class="text-muted"><?= h($rel['label']) ?>:</span>
              <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$rel['other_id'] ?>"
                 class="fw-semibold"><?= h($rel['other_name']) ?></a>
            </div>
            <?php if ($rel['notes']): ?>
            <div class="cv-meta"><?= h($rel['notes']) ?></div>
            <?php endif; ?>
          </div>
          <?php if ($crm_can_write): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć to powiązanie?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="rel_del">
            <input type="hidden" name="rel_id" value="<?= (int)$rel['id'] ?>">
            <button class="btn btn-link p-0 border-0 text-muted" style="line-height:1"
                    title="Usuń powiązanie" aria-label="Usuń powiązanie z <?= h($rel['other_name']) ?>">
              <i class="bi bi-x-lg" style="font-size:.75rem" aria-hidden="true"></i>
            </button>
          </form>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <?php if ($crm_can_write): ?>
      <form method="post" class="cv-rel-form" id="cvRelForm">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="rel_add">
        <input type="hidden" name="rel_other" id="cvRelOther" value="">
        <div class="row g-2">
          <div class="col-12 position-relative">
            <label class="form-label small fw-semibold mb-1" for="cvRelSearch">Powiąż z kartoteką</label>
            <input type="text" class="form-control form-control-sm" id="cvRelSearch" autocomplete="off"
                   placeholder="Zacznij pisać nazwę albo e-mail…" role="combobox"
                   aria-expanded="false" aria-controls="cvRelHits" aria-autocomplete="list">
            <div class="cv-rel-hits" id="cvRelHits" role="listbox" aria-label="Wyniki wyszukiwania" hidden></div>
          </div>
          <div class="col-7">
            <label class="form-label small fw-semibold mb-1" for="cvRelType">Kim jest ta kartoteka</label>
            <select name="rel_type" id="cvRelType" class="form-select form-select-sm">
              <?php foreach ($cv_rtypes as $rk => $rv): ?>
              <?php /* Etykieta „od A": wybieramy rolę TEJ kartoteki wobec wskazanej */ ?>
              <option value="<?= h($rk) ?>"><?= h($rv['a']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-5 d-flex align-items-end">
            <button class="btn btn-crm-primary btn-sm w-100" id="cvRelBtn" disabled>
              <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Powiąż
            </button>
          </div>
        </div>
        <div class="form-text" style="font-size:.72rem">
          Kierunek ma znaczenie: wybierasz, kim jest <strong>ta</strong> kartoteka wobec wskazanej.
          Druga strona zobaczy powiązanie odwrotnie („ma w zarządzie", „zatrudnia").
        </div>
      </form>

      <script>
      /* Wyszukiwarka kartoteki do powiązania — ten sam endpoint co w wysyłce.
         Bez wybrania z listy przycisk zostaje nieaktywny: powiązanie potrzebuje
         identyfikatora, a nie wpisanego tekstu, który może pasować do wielu osób. */
      (function () {
        var box = document.getElementById('cvRelForm');
        if (!box || box.dataset.bound) return;
        box.dataset.bound = '1';

        var inp = document.getElementById('cvRelSearch');
        var hid = document.getElementById('cvRelOther');
        var hits = document.getElementById('cvRelHits');
        var btn = document.getElementById('cvRelBtn');
        var ME = <?= (int)$id ?>;
        var tmr = null, shown = [], cur = -1;

        function close() { hits.hidden = true; cur = -1; inp.setAttribute('aria-expanded', 'false'); }

        function paint() {
          if (!shown.length) {
            hits.innerHTML = '<div class="cv-rel-none">Nic nie znaleziono.</div>';
          } else {
            hits.innerHTML = shown.map(function (c, i) {
              return '<button type="button" class="cv-rel-hit" role="option" data-i="' + i + '">' +
                     String(c.name).replace(/</g, '&lt;') +
                     (c.organizacja ? '<small>' + String(c.organizacja).replace(/</g, '&lt;') + '</small>' : '') +
                     '</button>';
            }).join('');
          }
          hits.hidden = false;
          inp.setAttribute('aria-expanded', 'true');
        }

        function search(q) {
          fetch('<?= APP_URL ?>/crm/api/contacts_search.php?limit=8&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              shown = (Array.isArray(d) ? d : (d.items || [])).filter(function (c) { return Number(c.id) !== ME; });
              paint();
            })
            .catch(function () { close(); });
        }

        inp.addEventListener('input', function () {
          hid.value = ''; btn.disabled = true;
          var q = inp.value.trim();
          clearTimeout(tmr);
          if (q.length < 2) { close(); return; }
          tmr = setTimeout(function () { search(q); }, 200);
        });

        hits.addEventListener('click', function (e) {
          var b = e.target.closest('.cv-rel-hit');
          if (!b) return;
          var c = shown[parseInt(b.dataset.i, 10)];
          if (!c) return;
          inp.value = c.name; hid.value = c.id; btn.disabled = false; close();
        });

        inp.addEventListener('keydown', function (e) {
          if (hits.hidden) return;
          var items = hits.querySelectorAll('.cv-rel-hit');
          if (e.key === 'ArrowDown') { e.preventDefault(); cur = Math.min(cur + 1, items.length - 1); }
          else if (e.key === 'ArrowUp') { e.preventDefault(); cur = Math.max(cur - 1, 0); }
          else if (e.key === 'Enter' && cur > -1) { e.preventDefault(); items[cur].click(); return; }
          else if (e.key === 'Escape') { close(); return; }
          items.forEach(function (b, i) { b.classList.toggle('is-on', i === cur); });
        });

        document.addEventListener('click', function (e) {
          if (!e.target.closest('#cvRelForm')) close();
        });
      })();
      </script>
      <?php endif; ?>
    </div></div>

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
        <?php /* Gdy automat działa, przycisk jest tylko przyspieszeniem — warto,
                 żeby było to widać, zamiast pozwalać klikać „na wszelki wypadek". */ ?>
        <?php if (!empty($office_st['auto_mail'])): ?>
        <br><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>
        Dociąga się automatycznie — agent sprawdza co 10 minut, do tej kartoteki wraca
        co <?= (int)$office_st['auto_mail_hours'] ?> godz. Przycisk odświeża od razu.
        <?php endif; ?>
        <?php if (!empty($office_st['auto_push'])): ?>
        <br><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>
        Zmiany w kartotece trafiają do książki adresowej same.
        <?php endif; ?>
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

        </div>
      </div>
