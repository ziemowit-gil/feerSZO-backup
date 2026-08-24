<?php
/**
 * crm/offers/form.php — Kreator / edytor oferty.
 *
 * Jeden formularz obsługuje tworzenie i edycję. Pozycje pochodzą z katalogu usług
 * odpłatnych (crm_offer_catalog) albo są wpisywane ręcznie. Kwoty przeliczane są
 * na żywo w JS, a autorytatywnie po zapisie przez crm_offer_recalc().
 *
 * Edycja jest możliwa tylko w statusach otwartych — po decyzji klienta tworzy się
 * NOWĄ WERSJĘ oferty (action.php?a=revision), by nie podmieniać dokumentu,
 * na który klient już odpowiedział.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_offers.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_perms.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('offers', 'write');
if (!crm_offer_can_write()) {
    flash_set('danger', 'Brak uprawnień do tworzenia ofert.');
    header('Location: ' . APP_URL . '/crm/offers/index.php'); exit;
}
crm_offers_migrate();

$id      = (int)($_GET['id'] ?? 0);
$offer   = $id ? crm_offer_full($id) : null;
if ($id && !$offer) { flash_set('danger', 'Oferta nie istnieje.'); header('Location: index.php'); exit; }

$EDITABLE_STATUSES = ['szkic', 'do_zatwierdzenia', 'wyslana'];
if ($offer && !in_array($offer['status'], $EDITABLE_STATUSES, true)) {
    flash_set('warning', 'Oferta w statusie „' . (CRM_OFFER_STATUSES[$offer['status']]['label'] ?? $offer['status'])
        . '" nie może być edytowana — utwórz nową wersję.');
    header('Location: view.php?id=' . $id); exit;
}

$PAGE_TITLE = $offer ? ('Edycja oferty ' . $offer['offer_number']) : 'Nowa oferta';
$uid        = (int)(current_user()['id'] ?? 0);
$errors     = [];
$notice     = '';
$nip_found  = null;

$contact_id = (int)($_GET['contact_id'] ?? ($offer['contact_id'] ?? 0));
$catalog    = crm_offer_catalog_list();
$objectives = crm_offer_objectives();
$limit_pct  = crm_offer_discount_limit();

// ── Wyszukiwanie kontrahenta po NIP + utworzenie kartoteki ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'contact_from_nip') {
    csrf_check();
    $nip = preg_replace('/\D/', '', (string)($_POST['nip'] ?? ''));
    $existing = $nip ? crm_one("SELECT id FROM crm_contacts WHERE nip=? AND crm_active=1 ORDER BY id LIMIT 1", [$nip]) : null;
    if ($existing) {
        header('Location: form.php?' . http_build_query(array_filter(['id' => $id ?: null, 'contact_id' => (int)$existing['id']])));
        exit;
    }
    require_once dirname(dirname(__DIR__)) . '/includes/ceidg.php';
    $res = $nip ? ceidg_lookup($nip) : ['error' => 'Podaj NIP.'];
    if (isset($res['error'])) {
        $errors[] = 'NIP: ' . $res['error'];
    } else {
        $new_id = CrmManager::createContact([
            'type'            => 'kontrahent',
            'status'          => 'prospect',
            'imie_nazwisko'   => $res['nazwa'] ?: ('Podmiot NIP ' . $nip),
            'nip'             => $res['nip'] ?? $nip,
            'regon'           => $res['regon'] ?? '',
            'adres'           => $res['adres'] ?? '',
            'source'          => 'oferta_nip',
            'created_by'      => $uid,
        ]);
        flash_set('success', 'Utworzono kartotekę kontrahenta z rejestru (NIP ' . $nip . ').');
        header('Location: form.php?' . http_build_query(array_filter(['id' => $id ?: null, 'contact_id' => $new_id])));
        exit;
    }
}

// ── Zapis oferty ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save') {
    csrf_check();

    $contact_id = (int)($_POST['contact_id'] ?? 0);
    $title      = trim((string)($_POST['title'] ?? ''));
    $disc       = round((float)str_replace(',', '.', (string)($_POST['discount_pct'] ?? '0')), 2);
    $disc       = max(0.0, min(100.0, $disc));
    $funding    = (string)($_POST['funding_source'] ?? 'odplatna');
    if (!isset(CRM_OFFER_FUNDING[$funding])) $funding = 'odplatna';

    $vin = $_POST['v'] ?? [];
    if (!is_array($vin)) $vin = [];
    // Odfiltruj puste warianty (bez nazwy i bez pozycji z nazwą)
    $variants_in = [];
    foreach ($vin as $vi => $v) {
        $items = [];
        foreach (($v['items'] ?? []) as $it) {
            if (trim((string)($it['name'] ?? '')) === '') continue;
            $items[] = $it;
        }
        if (trim((string)($v['name'] ?? '')) === '' && !$items) continue;
        $v['items'] = $items;
        $variants_in[$vi] = $v;
    }

    if (!$contact_id) $errors[] = 'Wskaż klienta (kontakt CRM).';
    if ($title === '') $errors[] = 'Tytuł oferty jest wymagany.';
    if (!$variants_in) $errors[] = 'Dodaj przynajmniej jeden wariant z pozycją.';
    if ($disc > 0 && trim((string)($_POST['discount_reason'] ?? '')) === '') {
        $errors[] = 'Rabat wymaga uzasadnienia (pole „Podstawa rabatu").';
    }

    $contact = $contact_id ? crm_one("SELECT * FROM crm_contacts WHERE id=? AND crm_active=1", [$contact_id]) : null;
    if ($contact_id && !$contact) $errors[] = 'Wybrany kontakt nie istnieje.';

    if (!$errors) {
        $needs_conf = crm_offer_confirm_person_required()
                      && in_array((string)$contact['type'], CRM_OFFER_PERSON_TYPES, true);

        $data = [
            'contact_id'         => $contact_id,
            'case_id'            => (int)($_POST['case_id'] ?? 0) ?: null,
            'owner_id'           => (int)($_POST['owner_id'] ?? 0) ?: $uid,
            'title'              => $title,
            // Pola z edytora WYSIWYG — whitelista tagów przy zapisie (crm_offer_sanitize_html).
            'intro'              => crm_offer_sanitize_html((string)($_POST['intro'] ?? '')) ?: null,
            'terms'              => crm_offer_sanitize_html((string)($_POST['terms'] ?? '')) ?: null,
            'delivery_terms'     => crm_offer_sanitize_html((string)($_POST['delivery_terms'] ?? '')) ?: null,
            'notes_internal'     => trim((string)($_POST['notes_internal'] ?? '')) ?: null,
            'currency'           => in_array($_POST['currency'] ?? 'PLN', ['PLN', 'EUR', 'USD'], true) ? $_POST['currency'] : 'PLN',
            'valid_until'        => trim((string)($_POST['valid_until'] ?? '')) ?: null,
            'payment_terms_days' => max(0, (int)($_POST['payment_terms_days'] ?? 14)),
            'discount_pct'       => $disc,
            'discount_reason'    => trim((string)($_POST['discount_reason'] ?? '')) ?: null,
            'funding_source'     => $funding,
            'objective_id'       => (int)($_POST['objective_id'] ?? 0) ?: null,
            'statutory_note'     => trim((string)($_POST['statutory_note'] ?? '')) ?: null,
            'accounting_note'    => trim((string)($_POST['accounting_note'] ?? '')) ?: null,
            'client_type'        => (string)$contact['type'],
            'client_snapshot'    => json_encode([
                'name'  => $contact['imie_nazwisko'], 'nip' => $contact['nip'],
                'adres' => $contact['adres'], 'email' => $contact['email'],
                'type'  => $contact['type'],
            ], JSON_UNESCAPED_UNICODE),
            'requires_confirmation' => $needs_conf ? 1 : 0,
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        if ($offer) {
            crm_update('crm_offers', $data, $id);
            $offer_id = $id;
            crm_offer_log($offer_id, 'updated', ['detail' => 'Zmieniono treść oferty.']);
        } else {
            $data['offer_number'] = crm_offer_next_number();
            $data['status']       = 'szkic';
            $data['access_token'] = crm_offer_token_new();
            $data['created_by']   = $uid;
            $data['created_at']   = date('Y-m-d H:i:s');
            if (!$data['valid_until']) {
                $data['valid_until'] = date('Y-m-d', strtotime('+' . crm_offer_default_validity() . ' days'));
            }
            $offer_id = crm_insert('crm_offers', $data);
            crm_offer_log($offer_id, 'created', ['detail' => 'Utworzono ofertę ' . $data['offer_number']]);
        }

        // Warianty + pozycje: pełna podmiana (oferta edytowalna tylko przed decyzją)
        crm_db()->prepare("DELETE FROM crm_offer_items WHERE offer_id=?")->execute([$offer_id]);
        crm_db()->prepare("DELETE FROM crm_offer_variants WHERE offer_id=?")->execute([$offer_id]);
        crm_update('crm_offers', ['selected_variant_id' => null], $offer_id);

        $rec_idx = (string)($_POST['recommended'] ?? '');
        $vsort = 0;
        foreach ($variants_in as $vi => $v) {
            $vsort++;
            $vid = crm_insert('crm_offer_variants', [
                'offer_id'       => $offer_id,
                'code'           => strtoupper(substr(trim((string)($v['code'] ?? '')) ?: chr(64 + $vsort), 0, 3)),
                'name'           => trim((string)($v['name'] ?? '')) ?: ('Wariant ' . chr(64 + $vsort)),
                'description'    => trim((string)($v['description'] ?? '')) ?: null,
                'is_recommended' => ((string)$vi === $rec_idx) ? 1 : 0,
                'sort_order'     => $vsort,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            $isort = 0;
            foreach ($v['items'] as $it) {
                $isort++;
                $vat = (string)($it['vat_rate'] ?? '23');
                if (!isset(CRM_OFFER_VAT_RATES[$vat])) $vat = '23';
                crm_insert('crm_offer_items', [
                    'offer_id'     => $offer_id,
                    'variant_id'   => $vid,
                    'catalog_id'   => (int)($it['catalog_id'] ?? 0) ?: null,
                    'name'         => trim((string)$it['name']),
                    'description'  => trim((string)($it['description'] ?? '')) ?: null,
                    'unit'         => trim((string)($it['unit'] ?? 'szt.')) ?: 'szt.',
                    'qty'          => max(0, (float)str_replace(',', '.', (string)($it['qty'] ?? 1))),
                    'unit_net'     => max(0, (float)str_replace(',', '.', (string)($it['unit_net'] ?? 0))),
                    'discount_pct' => max(0, min(100, (float)str_replace(',', '.', (string)($it['discount_pct'] ?? 0)))),
                    'vat_rate'     => $vat,
                    'vat_basis'    => trim((string)($it['vat_basis'] ?? '')) ?: null,
                    'objective_id' => (int)($it['objective_id'] ?? 0) ?: null,
                    'merit_note'   => trim((string)($it['merit_note'] ?? '')) ?: null,
                    'is_optional'  => !empty($it['is_optional']) ? 1 : 0,
                    'sort_order'   => $isort,
                ]);
            }
        }

        crm_offer_recalc($offer_id);
        crm_offer_map_objective($offer_id, (int)($_POST['objective_id'] ?? 0) ?: null);

        // Rabat ponad limit → obieg zatwierdzenia
        $cur = crm_one("SELECT status FROM crm_offers WHERE id=?", [$offer_id]);
        if ($disc > 0 && crm_offer_discount_needs_approval($disc)) {
            if (in_array($cur['status'] ?? '', ['szkic', 'do_zatwierdzenia'], true)) {
                crm_offer_set_status($offer_id, 'do_zatwierdzenia', [
                    'event'  => 'discount_requested',
                    'detail' => 'Rabat ' . $disc . '% przekracza limit ' . $limit_pct . '% dla roli — wymagane zatwierdzenie.',
                ]);
            }
            flash_set('warning', 'Zapisano. Rabat ' . $disc . '% przekracza Twój limit (' . $limit_pct . '%) — oferta czeka na zatwierdzenie.');
        } elseif ($disc > 0 && crm_offer_can_approve_discount()) {
            crm_update('crm_offers', [
                'discount_approved_by' => $uid, 'discount_approved_at' => date('Y-m-d H:i:s'),
            ], $offer_id);
            flash_set('success', 'Oferta zapisana (rabat w Twoim limicie).');
        } else {
            flash_set('success', 'Oferta została zapisana.');
        }

        header('Location: view.php?id=' . $offer_id); exit;
    }
}

// ── Dane do formularza ──────────────────────────────────────────────────────
$contact = $contact_id ? crm_one("SELECT * FROM crm_contacts WHERE id=? AND crm_active=1", [$contact_id]) : null;
$contacts_all = crm_all("SELECT id, imie_nazwisko, type, nip FROM crm_contacts WHERE crm_active=1 ORDER BY imie_nazwisko");
$cases = $contact ? crm_all("SELECT id, title FROM crm_cases WHERE contact_id=? ORDER BY updated_at DESC LIMIT 50", [$contact_id]) : [];
$users_all = [];
try { $users_all = db_all("SELECT * FROM users WHERE is_active=1 ORDER BY name"); }
catch (\Throwable $e) { try { $users_all = db_all("SELECT * FROM users ORDER BY name"); } catch (\Throwable $e2) {} }

// Stan wariantów do renderowania (POST → repost, edycja → z bazy, nowa → 1 pusty)
if (!empty($_POST['v']) && is_array($_POST['v'])) {
    $render_variants = [];
    foreach ($_POST['v'] as $v) {
        $render_variants[] = [
            'code' => $v['code'] ?? '', 'name' => $v['name'] ?? '', 'description' => $v['description'] ?? '',
            'items' => array_values(array_filter($v['items'] ?? [], static fn($i) => trim((string)($i['name'] ?? '')) !== '')),
        ];
    }
    $rec_selected = (string)($_POST['recommended'] ?? '0');
} elseif ($offer) {
    $render_variants = [];
    $rec_selected = '0';
    foreach ($offer['variants'] as $k => $v) {
        if ((int)$v['is_recommended'] === 1) $rec_selected = (string)$k;
        $render_variants[] = [
            'code' => $v['code'], 'name' => $v['name'], 'description' => $v['description'],
            'items' => array_map(static fn($i) => [
                'catalog_id' => $i['catalog_id'], 'name' => $i['name'], 'description' => $i['description'],
                'unit' => $i['unit'], 'qty' => $i['qty'], 'unit_net' => $i['unit_net'],
                'discount_pct' => $i['discount_pct'], 'vat_rate' => $i['vat_rate'], 'vat_basis' => $i['vat_basis'],
                'objective_id' => $i['objective_id'], 'merit_note' => $i['merit_note'], 'is_optional' => $i['is_optional'],
            ], $v['items']),
        ];
    }
} else {
    $render_variants = [['code' => 'A', 'name' => 'Wariant podstawowy', 'description' => '', 'items' => []]];
    $rec_selected = '0';
}

$val = static function (string $k, $d = '') use ($offer) {
    if (isset($_POST[$k])) return $_POST[$k];
    return $offer[$k] ?? $d;
};

include dirname(__DIR__) . '/includes/header_crm.php';
?>

<style>
.ofc-card { background:#fff;border:1px solid #E5E7EB;border-radius:10px;margin-bottom:1rem }
.ofc-card > .hd { padding:.6rem .9rem;border-bottom:1px solid #F3F4F6;display:flex;align-items:center;gap:.5rem;
  font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6B7280 }
.ofc-card > .bd { padding:.9rem }
.ofv { border:1px solid #E5E7EB;border-radius:9px;margin-bottom:.85rem;background:#FCFDFF }
.ofv > .vh { padding:.55rem .7rem;border-bottom:1px solid #EEF2F7;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap }
.ofv > .vb { padding:.6rem .7rem }
.ofi-table { width:100%;border-collapse:collapse;font-size:.82rem }
.ofi-table th { font-size:.66rem;text-transform:uppercase;letter-spacing:.05em;color:#6B7280;font-weight:700;
  padding:.3rem .35rem;border-bottom:1px solid #E5E7EB;text-align:left;white-space:nowrap }
.ofi-table td { padding:.3rem .35rem;border-bottom:1px solid #F6F7F9;vertical-align:top }
.ofi-table input, .ofi-table select, .ofi-table textarea { font-size:.8rem;padding:.2rem .4rem }
.ofi-num { text-align:right }
.ofi-sum { font-weight:700;white-space:nowrap;text-align:right;font-variant-numeric:tabular-nums }
.of-vsumbar { display:flex;justify-content:flex-end;gap:1.2rem;font-size:.83rem;padding:.45rem .7rem;background:#F8FAFC;border-top:1px solid #EEF2F7 }
.of-extra { display:none }
.of-extra.open { display:table-row }
.of-warn { background:#FFF7ED;border:1px dashed #EA580C;border-radius:8px;padding:.65rem .85rem;font-size:.84rem }
</style>

<nav aria-label="breadcrumb" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="index.php">Oferty</a></li>
    <li class="breadcrumb-item active"><?= $offer ? h($offer['offer_number']) : 'Nowa' ?></li>
  </ol>
</nav>

<div class="crm-page-header">
  <div>
    <div class="crm-page-title"><i class="bi bi-file-earmark-plus-fill" style="color:#0176D3"></i>
      <?= $offer ? 'Edycja oferty ' . h($offer['offer_number']) : 'Nowa oferta' ?></div>
    <div class="crm-page-subtitle">Katalog usług odpłatnych, warianty i automatyczne przeliczanie kwot</div>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<?php $_pw_on = $contact && in_array((string)$contact['type'], CRM_OFFER_PERSON_TYPES, true) && crm_offer_confirm_person_required(); ?>
<?php if (crm_offer_confirm_person_required()): ?>
<div class="of-warn mb-3" role="alert" id="personWarn" style="<?= $_pw_on ? '' : 'display:none' ?>">
  <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
  <strong>Klient jest osobą fizyczną.</strong> Ta oferta będzie wymagać <strong>potwierdzenia przez klienta</strong>
  (link „Potwierdź ofertę" w wysyłce albo rejestracja potwierdzenia: e-mail, skan, protokół).
  Zanim uruchomisz realizację, potwierdzenie musi być zarejestrowane w systemie.
</div>
<?php endif; ?>

<form method="post" id="offerForm" novalidate>
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="_action" id="actField" value="save">

<div class="row g-3">
<div class="col-lg-8">

  <!-- Klient -->
  <div class="ofc-card">
    <div class="hd"><i class="bi bi-person-vcard" style="color:#0176D3"></i> Klient</div>
    <div class="bd">
      <div class="row g-2">
        <div class="col-md-7">
          <label class="form-label fw-semibold small mb-1">Kontakt CRM <span class="text-danger">*</span></label>
          <select name="contact_id" id="contactSel" class="form-select form-select-sm" required
                  data-base="form.php?<?= $offer ? 'id=' . $id . '&' : '' ?>contact_id="
                  onchange="ofContactChange(this)">
            <option value="">— wybierz klienta —</option>
            <?php foreach ($contacts_all as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $contact_id === (int)$c['id'] ? 'selected' : '' ?>>
              <?= h($c['imie_nazwisko']) ?><?= $c['nip'] ? ' — NIP ' . h($c['nip']) : '' ?>
              [<?= h(CRM_CONTACT_TYPES[$c['type']]['short'] ?? $c['type']) ?>]
            </option>
            <?php endforeach; ?>
          </select>
          <?php if ($contact): ?>
          <div class="small text-muted mt-1">
            <i class="bi <?= h(CRM_CONTACT_TYPES[$contact['type']]['icon'] ?? 'bi-person') ?>"></i>
            <?= h(CRM_CONTACT_TYPES[$contact['type']]['label'] ?? $contact['type']) ?>
            <?php if ($contact['email']): ?> · <?= h($contact['email']) ?><?php endif; ?>
            · <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$contact['id'] ?>" target="_blank">kartoteka <i class="bi bi-box-arrow-up-right"></i></a>
          </div>
          <?php endif; ?>
        </div>
        <div class="col-md-5">
          <label class="form-label fw-semibold small mb-1">Sprawa / szansa sprzedaży</label>
          <select name="case_id" class="form-select form-select-sm" <?= $cases ? '' : 'disabled' ?>>
            <option value="">— brak powiązania —</option>
            <?php foreach ($cases as $cs): ?>
            <option value="<?= (int)$cs['id'] ?>" <?= (int)$val('case_id') === (int)$cs['id'] ? 'selected' : '' ?>><?= h($cs['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <!-- Warianty i pozycje -->
  <div class="ofc-card">
    <div class="hd"><i class="bi bi-layers-fill" style="color:#0176D3"></i> Warianty i pozycje
      <span class="ms-auto text-muted" style="text-transform:none;letter-spacing:0;font-weight:400">
        Rekomendowany wariant zaznacz kropką — klient widzi go jako sugerowany.
      </span>
    </div>
    <div class="bd">
      <div id="variants"></div>
      <button type="button" class="btn btn-crm-outline btn-sm" onclick="ofAddVariant()">
        <i class="bi bi-plus-lg me-1"></i>Dodaj wariant (pakiet)
      </button>
    </div>
  </div>

  <!-- Treść oferty -->
  <div class="ofc-card">
    <div class="hd"><i class="bi bi-file-text" style="color:#0176D3"></i> Treść dokumentu</div>
    <div class="bd">
      <div class="mb-2">
        <label class="form-label fw-semibold small mb-1">Tytuł oferty <span class="text-danger">*</span></label>
        <input name="title" class="form-control form-control-sm" required
               placeholder="np. Szkolenie z dostępności cyfrowej dla zespołu" value="<?= h($val('title')) ?>">
      </div>
      <div class="mb-2">
        <label class="form-label fw-semibold small mb-1">Wstęp / opis merytoryczny</label>
        <textarea name="intro" id="ofRichIntro" class="form-control form-control-sm of-rich" rows="6"
                  placeholder="Kontekst, potrzeba klienta, sposób realizacji…"><?= h($val('intro')) ?></textarea>
      </div>
      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label fw-semibold small mb-1">Warunki realizacji</label>
          <textarea name="delivery_terms" id="ofRichDelivery" class="form-control form-control-sm of-rich" rows="5"
                    placeholder="Terminy, miejsce, wymagania organizacyjne…"><?= h($val('delivery_terms')) ?></textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold small mb-1">Warunki oferty</label>
          <textarea name="terms" id="ofRichTerms" class="form-control form-control-sm of-rich" rows="5"
                    placeholder="Zastrzeżenia, zakres wyłączeń, warunki płatności…"><?= h($val('terms')) ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <!-- Działalność odpłatna: pola NGO -->
  <div class="ofc-card">
    <div class="hd"><i class="bi bi-bank" style="color:#2E844A"></i> Działalność odpłatna — kwalifikacja</div>
    <div class="bd">
      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label fw-semibold small mb-1">Rodzaj działalności / finansowanie <span class="text-danger">*</span></label>
          <select name="funding_source" class="form-select form-select-sm">
            <?php foreach (CRM_OFFER_FUNDING as $fk => $fl): ?>
            <option value="<?= h($fk) ?>" <?= (string)$val('funding_source', 'odplatna') === $fk ? 'selected' : '' ?>><?= h($fl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold small mb-1">Cel statutowy</label>
          <?php if ($objectives): ?>
          <select name="objective_id" class="form-select form-select-sm">
            <option value="">— nie wskazano —</option>
            <?php foreach ($objectives as $ob): ?>
            <option value="<?= (int)$ob['id'] ?>" <?= (int)$val('objective_id') === (int)$ob['id'] ? 'selected' : '' ?>>
              <?= h($ob['nazwa']) ?><?= $ob['sphere_nazwa'] ? ' · ' . h($ob['sphere_nazwa']) : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text" style="font-size:.72rem">Powiązanie zapisuje się w module Strategii (wkład oferty w cel).</div>
          <?php else: ?>
          <input type="hidden" name="objective_id" value="">
          <div class="form-text">Moduł Strategii nie ma zdefiniowanych celów — pole nieaktywne.</div>
          <?php endif; ?>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold small mb-1">Uzasadnienie statutowe (na dokumencie)</label>
          <textarea name="statutory_note" class="form-control form-control-sm" rows="2"
                    placeholder="np. Usługa mieści się w §7 ust. 2 statutu — działalność odpłatna w zakresie edukacji…"><?= h($val('statutory_note')) ?></textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold small mb-1">Opis dla księgowości (wewnętrznie)</label>
          <textarea name="accounting_note" class="form-control form-control-sm" rows="2"
                    placeholder="Klasyfikacja przychodu, konto, projekt/zadanie, MPK…"><?= h($val('accounting_note')) ?></textarea>
        </div>
      </div>
    </div>
  </div>

</div><!-- /col-8 -->

<!-- Prawa kolumna -->
<div class="col-lg-4">

  <div class="ofc-card">
    <div class="hd"><i class="bi bi-calculator" style="color:#0176D3"></i> Podsumowanie</div>
    <div class="bd">
      <div class="d-flex justify-content-between small"><span class="text-muted">Netto</span><strong id="sumNet">0,00</strong></div>
      <div class="d-flex justify-content-between small"><span class="text-muted">VAT</span><strong id="sumVat">0,00</strong></div>
      <hr class="my-2">
      <div class="d-flex justify-content-between"><span class="fw-semibold">Brutto</span>
        <strong id="sumGross" style="font-size:1.1rem;color:#194E31">0,00</strong></div>
      <div class="form-text" id="sumHint" style="font-size:.72rem">Wariant rekomendowany (lub pierwszy).</div>
    </div>
  </div>

  <div class="ofc-card">
    <div class="hd"><i class="bi bi-sliders" style="color:#0176D3"></i> Warunki handlowe</div>
    <div class="bd">
      <div class="row g-2">
        <div class="col-7">
          <label class="form-label fw-semibold small mb-1">Ważna do</label>
          <input type="date" name="valid_until" class="form-control form-control-sm"
                 value="<?= h($val('valid_until', date('Y-m-d', strtotime('+' . crm_offer_default_validity() . ' days')))) ?>">
        </div>
        <div class="col-5">
          <label class="form-label fw-semibold small mb-1">Waluta</label>
          <select name="currency" class="form-select form-select-sm">
            <?php foreach (['PLN', 'EUR', 'USD'] as $cu): ?>
            <option value="<?= $cu ?>" <?= (string)$val('currency', 'PLN') === $cu ? 'selected' : '' ?>><?= $cu ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-7">
          <label class="form-label fw-semibold small mb-1">Termin płatności (dni)</label>
          <input type="number" min="0" max="365" name="payment_terms_days" class="form-control form-control-sm"
                 value="<?= h($val('payment_terms_days', '14')) ?>">
        </div>
        <div class="col-5">
          <label class="form-label fw-semibold small mb-1">Rabat ogólny %</label>
          <input type="number" step="0.01" min="0" max="100" name="discount_pct" id="offDiscount"
                 class="form-control form-control-sm" value="<?= h($val('discount_pct', '0')) ?>" oninput="ofRecalc()">
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold small mb-1">Podstawa rabatu</label>
          <input name="discount_reason" class="form-control form-control-sm"
                 placeholder="np. stały klient, wolumen, cel statutowy" value="<?= h($val('discount_reason')) ?>">
          <div class="form-text" id="discHint" style="font-size:.72rem">
            Twój limit rabatu: <strong><?= h(number_format($limit_pct, 2, ',', ' ')) ?>%</strong>.
            Powyżej limitu oferta trafi do zatwierdzenia.
          </div>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold small mb-1">Opiekun (handlowiec / koordynator)</label>
          <select name="owner_id" class="form-select form-select-sm">
            <?php $sel_owner = (int)$val('owner_id', $uid); foreach ($users_all as $u):
              $un = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['name'] ?? ''); ?>
            <option value="<?= (int)$u['id'] ?>" <?= $sel_owner === (int)$u['id'] ? 'selected' : '' ?>><?= h($un) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="ofc-card">
    <div class="hd"><i class="bi bi-upc-scan" style="color:#0176D3"></i> Kontrahent po NIP</div>
    <div class="bd">
      <div class="input-group input-group-sm">
        <input name="nip" id="nipInput" class="form-control" placeholder="10 cyfr" inputmode="numeric">
        <button class="btn btn-outline-secondary" type="submit" formnovalidate
                onclick="document.getElementById('actField').value='contact_from_nip'">
          <i class="bi bi-search"></i> Znajdź
        </button>
      </div>
      <div class="form-text" style="font-size:.72rem">
        Szuka w kartotece CRM, a gdy nie znajdzie — pobiera dane z CEIDG / Białej listy VAT
        i tworzy kartotekę kontrahenta.
      </div>
    </div>
  </div>

  <div class="ofc-card">
    <div class="hd"><i class="bi bi-eye-slash" style="color:#6B7280"></i> Notatki wewnętrzne</div>
    <div class="bd">
      <textarea name="notes_internal" class="form-control form-control-sm" rows="3"
                placeholder="Nie trafia do klienta"><?= h($val('notes_internal')) ?></textarea>
    </div>
  </div>

  <div class="d-grid gap-2 mb-4">
    <button type="submit" class="btn btn-primary" onclick="document.getElementById('actField').value='save'">
      <i class="bi bi-check-lg me-1"></i><?= $offer ? 'Zapisz zmiany' : 'Utwórz ofertę' ?>
    </button>
    <a href="<?= $offer ? 'view.php?id=' . $id : 'index.php' ?>" class="btn btn-outline-secondary">Anuluj</a>
  </div>

</div>
</div>

</form>

<script>
/* ── Kreator ofert: warianty + pozycje + przeliczanie na żywo ───────────────── */
var OF_CATALOG   = <?= json_encode(array_map(static fn($c) => [
        'id' => (int)$c['id'], 'name' => $c['name'], 'unit' => $c['unit'],
        'net' => (float)$c['unit_net'], 'vat' => $c['vat_rate'], 'basis' => $c['vat_basis'],
        'desc' => $c['description'], 'cat' => $c['category'], 'min' => $c['min_unit_net'],
        'obj' => $c['objective_id'] ? (int)$c['objective_id'] : null,
    ], $catalog), JSON_UNESCAPED_UNICODE) ?>;
var OF_VAT       = <?= json_encode(array_map(static fn($v) => $v['rate'], CRM_OFFER_VAT_RATES)) ?>;
var OF_VAT_LABEL = <?= json_encode(array_map(static fn($v) => $v['label'], CRM_OFFER_VAT_RATES), JSON_UNESCAPED_UNICODE) ?>;
var OF_OBJ       = <?= json_encode(array_map(static fn($o) => ['id' => (int)$o['id'], 'name' => $o['nazwa']], $objectives), JSON_UNESCAPED_UNICODE) ?>;
var OF_STATE     = <?= json_encode($render_variants, JSON_UNESCAPED_UNICODE) ?>;
var OF_REC       = <?= json_encode((string)$rec_selected) ?>;
var OF_CUR       = document.querySelector('[name="currency"]');
var ofVi = 0;
var OF_CTYPES  = <?= json_encode(array_column($contacts_all, 'type', 'id')) ?>;
var OF_PERSONS = <?= json_encode(array_values(CRM_OFFER_PERSON_TYPES)) ?>;
var OF_CONFIRM_ON = <?= crm_offer_confirm_person_required() ? 'true' : 'false' ?>;

/**
 * Zmiana klienta: gdy formularz jest jeszcze pusty — przeładuj stronę (dociągnie
 * sprawy klienta). Gdy użytkownik już coś wpisał, NIE przeładowujemy (utrata danych) —
 * pokazujemy tylko ostrzeżenie o osobie fizycznej; sprawy dociągną się po zapisie.
 */
function ofContactChange(sel) {
    ofTogglePersonWarn(sel.value);
    var t = document.querySelector('[name="title"]');
    var dirty = (t && t.value.trim() !== '');
    if (!dirty) {
        document.querySelectorAll('#variants .ofi-name').forEach(function (el) { if (el.value.trim() !== '') dirty = true; });
    }
    if (!dirty && sel.value) location.href = sel.dataset.base + encodeURIComponent(sel.value);
}
function ofTogglePersonWarn(cid) {
    var box = document.getElementById('personWarn');
    if (!box || !OF_CONFIRM_ON) return;
    var t = OF_CTYPES[cid] || '';
    box.style.display = (OF_PERSONS.indexOf(t) >= 0) ? '' : 'none';
}

function ofEsc(v) {
    return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function ofNum(v) { var n = parseFloat(String(v == null ? '' : v).replace(',', '.')); return isNaN(n) ? 0 : n; }
function ofFmt(n) { return n.toLocaleString('pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

function ofVatOptions(sel) {
    var out = '';
    for (var k in OF_VAT_LABEL) {
        out += '<option value="' + k + '"' + (String(sel) === k ? ' selected' : '') + '>' + OF_VAT_LABEL[k] + '</option>';
    }
    return out;
}
function ofCatOptions() {
    var out = '<option value="">— wpisz ręcznie —</option>';
    var lastCat = null;
    for (var i = 0; i < OF_CATALOG.length; i++) {
        var c = OF_CATALOG[i];
        if (c.cat && c.cat !== lastCat) { if (lastCat !== null) out += '</optgroup>'; out += '<optgroup label="' + c.cat + '">'; lastCat = c.cat; }
        out += '<option value="' + c.id + '">' + c.name + ' · ' + ofFmt(c.net) + '</option>';
    }
    if (lastCat !== null) out += '</optgroup>';
    return out;
}
function ofObjOptions(sel) {
    var out = '<option value="">—</option>';
    for (var i = 0; i < OF_OBJ.length; i++) {
        out += '<option value="' + OF_OBJ[i].id + '"' + (String(sel) === String(OF_OBJ[i].id) ? ' selected' : '') + '>' + OF_OBJ[i].name + '</option>';
    }
    return out;
}

function ofItemRow(vi, it) {
    it = it || {};
    var n = 'v[' + vi + '][items][__I__]';
    var tr = document.createElement('tbody');
    tr.className = 'ofi-group';
    tr.innerHTML =
      '<tr class="ofi-main">' +
      '<td><select class="form-select form-select-sm ofi-cat" onchange="ofFromCatalog(this)">' + ofCatOptions() + '</select>' +
        '<input type="hidden" name="' + n + '[catalog_id]" class="ofi-catid" value="' + (it.catalog_id || '') + '">' +
        '<input class="form-control form-control-sm mt-1 ofi-name" name="' + n + '[name]" placeholder="Nazwa pozycji" value="' + ofEsc(it.name) + '"></td>' +
      '<td><input class="form-control form-control-sm ofi-qty" name="' + n + '[qty]" value="' + (it.qty != null ? it.qty : 1) + '" oninput="ofRecalc()" style="width:66px"></td>' +
      '<td><input class="form-control form-control-sm ofi-unit" name="' + n + '[unit]" value="' + ofEsc(it.unit || 'szt.') + '" style="width:66px"></td>' +
      '<td><input class="form-control form-control-sm ofi-net ofi-num" name="' + n + '[unit_net]" value="' + (it.unit_net != null ? it.unit_net : 0) + '" oninput="ofRecalc()" style="width:92px"></td>' +
      '<td><input class="form-control form-control-sm ofi-disc ofi-num" name="' + n + '[discount_pct]" value="' + (it.discount_pct != null ? it.discount_pct : 0) + '" oninput="ofRecalc()" style="width:62px"></td>' +
      '<td><select class="form-select form-select-sm ofi-vat" name="' + n + '[vat_rate]" onchange="ofRecalc()" style="width:74px">' + ofVatOptions(it.vat_rate || '23') + '</select></td>' +
      '<td class="ofi-sum ofi-linegross">0,00</td>' +
      '<td style="white-space:nowrap">' +
        '<button type="button" class="btn btn-sm btn-link p-0 me-1" title="Szczegóły pozycji" onclick="ofToggleExtra(this)"><i class="bi bi-chevron-down"></i></button>' +
        '<button type="button" class="btn btn-sm btn-link text-danger p-0" title="Usuń pozycję" onclick="ofDelItem(this)"><i class="bi bi-trash"></i></button>' +
      '</td></tr>' +
      '<tr class="of-extra"><td colspan="8">' +
        '<div class="row g-2">' +
        '<div class="col-md-6"><label class="form-label small mb-0">Opis pozycji (dla klienta)</label>' +
          '<textarea class="form-control form-control-sm" rows="2" name="' + n + '[description]">' + ofEsc(it.description) + '</textarea></div>' +
        '<div class="col-md-6"><label class="form-label small mb-0">Notatka merytoryczna (wewnętrznie)</label>' +
          '<textarea class="form-control form-control-sm" rows="2" name="' + n + '[merit_note]">' + ofEsc(it.merit_note) + '</textarea></div>' +
        '<div class="col-md-6"><label class="form-label small mb-0">Podstawa zwolnienia / niepodlegania VAT</label>' +
          '<input class="form-control form-control-sm" name="' + n + '[vat_basis]" value="' + ofEsc(it.vat_basis) + '" placeholder="np. art. 43 ust. 1 pkt 29 lit. c ustawy o VAT"></div>' +
        '<div class="col-md-4"><label class="form-label small mb-0">Cel statutowy pozycji</label>' +
          '<select class="form-select form-select-sm" name="' + n + '[objective_id]">' + ofObjOptions(it.objective_id) + '</select></div>' +
        '<div class="col-md-2 d-flex align-items-end"><div class="form-check">' +
          '<input class="form-check-input" type="checkbox" name="' + n + '[is_optional]" value="1"' + (Number(it.is_optional) ? ' checked' : '') + ' onchange="ofRecalc()">' +
          '<label class="form-check-label small">Pozycja opcjonalna</label></div></div>' +
        '</div>' +
      '</td></tr>';
    return tr;
}

function ofRenumber() {
    document.querySelectorAll('#variants .ofv').forEach(function (v, vi) {
        v.dataset.vi = vi;
        v.querySelector('.ofv-title').textContent = 'Wariant ' + String.fromCharCode(65 + vi);
        v.querySelectorAll('.ofv-field').forEach(function (f) {
            f.name = 'v[' + vi + '][' + f.dataset.f + ']';
        });
        var rec = v.querySelector('.ofv-rec');
        rec.value = String(vi);
        v.querySelectorAll('.ofi-group').forEach(function (g, ii) {
            g.querySelectorAll('[name]').forEach(function (el) {
                el.name = el.name.replace(/^v\[\d+\]\[items\]\[[^\]]*\]/, 'v[' + vi + '][items][' + ii + ']');
            });
        });
    });
}

function ofAddVariant(data) {
    data = data || {};
    var vi = ofVi++;
    var wrap = document.createElement('div');
    wrap.className = 'ofv';
    wrap.dataset.vi = vi;
    wrap.innerHTML =
      '<div class="vh">' +
        '<div class="form-check m-0" title="Wariant rekomendowany">' +
          '<input class="form-check-input ofv-rec" type="radio" name="recommended" value="' + vi + '">' +
        '</div>' +
        '<strong class="ofv-title" style="font-size:.8rem;color:#194E31">Wariant</strong>' +
        '<input class="form-control form-control-sm ofv-field" data-f="code" style="width:58px" placeholder="A" value="' + ofEsc(data.code) + '">' +
        '<input class="form-control form-control-sm ofv-field" data-f="name" style="max-width:280px" placeholder="Nazwa pakietu" value="' + ofEsc(data.name) + '">' +
        '<button type="button" class="btn btn-sm btn-link text-danger ms-auto p-0" onclick="ofDelVariant(this)" title="Usuń wariant"><i class="bi bi-trash"></i> usuń</button>' +
      '</div>' +
      '<div class="vb">' +
        '<textarea class="form-control form-control-sm mb-2 ofv-field" data-f="description" rows="2" placeholder="Krótki opis wariantu — co obejmuje pakiet">' + ofEsc(data.description) + '</textarea>' +
        '<div class="table-responsive"><table class="ofi-table">' +
        '<thead><tr><th>Pozycja (z katalogu lub własna)</th><th>Ilość</th><th>J.m.</th><th class="ofi-num">Cena netto</th>' +
        '<th class="ofi-num">Rabat %</th><th>VAT</th><th class="ofi-num">Brutto</th><th></th></tr></thead>' +
        '</table></div>' +
        '<button type="button" class="btn btn-crm-outline btn-sm mt-2" onclick="ofAddItem(this)"><i class="bi bi-plus"></i> Dodaj pozycję</button>' +
      '</div>' +
      '<div class="of-vsumbar"><span>Netto: <strong class="v-net">0,00</strong></span>' +
      '<span>VAT: <strong class="v-vat">0,00</strong></span>' +
      '<span>Brutto: <strong class="v-gross" style="color:#194E31">0,00</strong></span></div>';
    document.getElementById('variants').appendChild(wrap);

    var table = wrap.querySelector('.ofi-table');
    (data.items || []).forEach(function (it) { table.appendChild(ofItemRow(vi, it)); });
    if (!(data.items || []).length) table.appendChild(ofItemRow(vi, {}));
    // Ustaw wartości selectów katalogu po wstawieniu
    (data.items || []).forEach(function (it, ii) {
        var g = table.querySelectorAll('.ofi-group')[ii];
        if (g && it.catalog_id) {
            var s = g.querySelector('.ofi-cat');
            if (s) s.value = String(it.catalog_id);
        }
    });
    ofRenumber();
    ofRecalc();
}

function ofDelVariant(btn) {
    var v = btn.closest('.ofv');
    if (document.querySelectorAll('#variants .ofv').length <= 1) { alert('Oferta musi mieć co najmniej jeden wariant.'); return; }
    v.remove();
    ofRenumber();
    ofRecalc();
}
function ofAddItem(btn) {
    var v = btn.closest('.ofv');
    v.querySelector('.ofi-table').appendChild(ofItemRow(Number(v.dataset.vi), {}));
    ofRenumber();
    ofRecalc();
}
function ofDelItem(btn) {
    var g = btn.closest('.ofi-group');
    var v = btn.closest('.ofv');
    if (v.querySelectorAll('.ofi-group').length <= 1) { alert('Wariant musi mieć co najmniej jedną pozycję.'); return; }
    g.remove();
    ofRenumber();
    ofRecalc();
}
function ofToggleExtra(btn) {
    var g = btn.closest('.ofi-group');
    var ex = g.querySelector('.of-extra');
    ex.classList.toggle('open');
    btn.querySelector('i').className = ex.classList.contains('open') ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
}
function ofFromCatalog(sel) {
    var id = sel.value;
    if (!id) return;
    var c = OF_CATALOG.filter(function (x) { return String(x.id) === String(id); })[0];
    if (!c) return;
    var g = sel.closest('.ofi-group');
    g.querySelector('.ofi-catid').value = c.id;
    g.querySelector('.ofi-name').value = c.name;
    g.querySelector('.ofi-unit').value = c.unit || 'szt.';
    g.querySelector('.ofi-net').value = c.net;
    g.querySelector('.ofi-vat').value = c.vat;
    var basis = g.querySelector('[name$="[vat_basis]"]');
    if (basis && c.basis) basis.value = c.basis;
    var desc = g.querySelector('textarea[name$="[description]"]');
    if (desc && !desc.value && c.desc) desc.value = c.desc;
    var obj = g.querySelector('select[name$="[objective_id]"]');
    if (obj && c.obj) obj.value = String(c.obj);
    ofRecalc();
}

function ofRecalc() {
    var cur = OF_CUR ? OF_CUR.value : 'PLN';
    var odisc = ofNum(document.getElementById('offDiscount').value);
    if (odisc < 0) odisc = 0; if (odisc > 100) odisc = 100;
    var lead = null, first = null, rec = null;

    document.querySelectorAll('#variants .ofv').forEach(function (v) {
        var net = 0, vat = 0;
        v.querySelectorAll('.ofi-group').forEach(function (g) {
            var q = ofNum(g.querySelector('.ofi-qty').value);
            var p = ofNum(g.querySelector('.ofi-net').value);
            var d = ofNum(g.querySelector('.ofi-disc').value);
            var vr = g.querySelector('.ofi-vat').value;
            var opt = g.querySelector('input[type="checkbox"][name$="[is_optional]"]');
            var base = q * p * (1 - Math.min(100, Math.max(0, d)) / 100) * (1 - odisc / 100);
            var ln = Math.round(base * 100) / 100;
            var lv = Math.round(ln * (OF_VAT[vr] || 0)) / 100;
            g.querySelector('.ofi-linegross').textContent = ofFmt(ln + lv);
            if (opt && opt.checked) return;
            net += ln; vat += lv;
        });
        v.querySelector('.v-net').textContent = ofFmt(net);
        v.querySelector('.v-vat').textContent = ofFmt(vat);
        v.querySelector('.v-gross').textContent = ofFmt(net + vat) + ' ' + cur;
        var o = {net: net, vat: vat};
        if (!first) first = o;
        if (v.querySelector('.ofv-rec').checked) rec = o;
    });
    lead = rec || first || {net: 0, vat: 0};
    document.getElementById('sumNet').textContent = ofFmt(lead.net) + ' ' + cur;
    document.getElementById('sumVat').textContent = ofFmt(lead.vat) + ' ' + cur;
    document.getElementById('sumGross').textContent = ofFmt(lead.net + lead.vat) + ' ' + cur;
    document.getElementById('sumHint').textContent = rec ? 'Wariant rekomendowany.' : 'Pierwszy wariant (brak rekomendacji).';

    var lim = <?= json_encode($limit_pct) ?>;
    var hint = document.getElementById('discHint');
    if (odisc > lim) {
        hint.innerHTML = '<span class="text-danger fw-semibold">Rabat ' + odisc + '% przekracza Twój limit ' + lim + '% — po zapisie oferta trafi do zatwierdzenia.</span>';
    } else {
        hint.innerHTML = 'Twój limit rabatu: <strong>' + lim + '%</strong>. Powyżej limitu oferta trafi do zatwierdzenia.';
    }
}

(function () {
    OF_STATE.forEach(function (v) { ofAddVariant(v); });
    var r = document.querySelector('#variants .ofv[data-vi="' + OF_REC + '"] .ofv-rec')
         || document.querySelectorAll('#variants .ofv .ofv-rec')[Number(OF_REC) || 0]
         || document.querySelector('#variants .ofv .ofv-rec');
    if (r) r.checked = true;
    document.querySelectorAll('.ofv-rec').forEach(function (el) { el.addEventListener('change', ofRecalc); });
    if (OF_CUR) OF_CUR.addEventListener('change', ofRecalc);
    ofRecalc();
})();
</script>


<!-- ── Edytor WYSIWYG dla pól treści oferty ─────────────────────────────────
     Textarea zostaje WIDOCZNA (bez display:none) — TinyMCE przenosi display
     na swój kontener, a przy braku CDN pole działa jako zwykły textarea.      -->
<style>
  .tox-tinymce { border-radius:.375rem !important; border-color:#dee2e6 !important }
  .tox .tox-toolbar__primary { background:#f8f9fa !important }
</style>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function () {
  if (!window.tinymce) return;   // brak CDN → zostają zwykłe textarea

  var OF_TINY = {
    license_key: 'gpl',
    menubar: false,
    branding: false,
    promotion: false,
    statusbar: false,
    entity_encoding: 'raw',
    language: 'pl',
    language_url: 'https://cdn.jsdelivr.net/npm/tinymce-i18n@latest/langs7/pl.js',
    plugins: 'lists link autolink',
    toolbar: 'undo redo | bold italic underline | bullist numlist | link | removeformat',
    // Tagi spoza whitelisty i tak wytnie crm_offer_sanitize_html przy zapisie.
    invalid_elements: 'script,style,img,iframe,object,embed,form,input',
    link_default_target: '_blank',
    content_style: 'body{font-family:system-ui,-apple-system,sans-serif;font-size:13px;line-height:1.55;'
                 + 'color:#1f2937;padding:6px 10px} p{margin:0 0 .5rem} ul,ol{margin:0 0 .5rem 1.1rem}',
    setup: function (ed) { ed.on('change input undo redo', function () { ed.save(); }); }
  };

  function ofTiny(sel, h) {
    tinymce.init(Object.assign({}, OF_TINY, { selector: sel, height: h }));
  }
  ofTiny('#ofRichIntro', 260);
  ofTiny('#ofRichDelivery, #ofRichTerms', 200);

  var f = document.getElementById('offerForm');
  if (f) f.addEventListener('submit', function () { tinymce.triggerSave(); });
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
