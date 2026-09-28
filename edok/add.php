<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';
require_once __DIR__ . '/../includes/crm_offers.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

edok_require_role('upload');
edok_migrate();
edok_templates_migrate();
$edok_templates = edok_get_templates(true);

$errors = [];

// Tryb „Przelew składek ZUS” — bez obiegu akceptacji (składki wynikają z rozliczonych
// rachunków i umów): formularz od razu generuje plik przelewu. Patrz edok_zus_handle_post().
$tryb = ($_GET['tryb'] ?? '') === 'zus' || ($_POST['action'] ?? '') === 'export_zus' ? 'zus' : 'dokument';
$zus_form = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tryb === 'zus') {
    csrf_check();
    $zus_form = edok_zus_handle_post(); // przy sukcesie wysyła plik i kończy żądanie
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tryb === 'dokument') {
    csrf_check();

    $kierunek         = in_array($_POST['kierunek'] ?? '', ['wydatek', 'przychod'], true) ? $_POST['kierunek'] : 'wydatek';
    $typ_dokumentu    = $_POST['typ_dokumentu'] ?? '';
    $description      = trim($_POST['description'] ?? '');
    $zrodlo_przychodu = trim($_POST['zrodlo_przychodu'] ?? '');
    $kontrahent_nazwa = trim($_POST['kontrahent_nazwa'] ?? '');
    $kontrahent_nip   = preg_replace('/\D/', '', trim($_POST['kontrahent_nip'] ?? ''));
    $nr_faktury       = trim($_POST['nr_faktury'] ?? '');
    $data_wystawienia = trim($_POST['data_wystawienia'] ?? '');
    $data_sprzedazy   = trim($_POST['data_sprzedazy'] ?? '');
    $data_wplywu      = trim($_POST['data_wplywu'] ?? '') ?: date('Y-m-d');
    $kwota_netto      = trim($_POST['kwota_netto'] ?? '');
    $stawka_vat       = trim($_POST['stawka_vat'] ?? '');
    $kwota_vat        = trim($_POST['kwota_vat'] ?? '');
    $kwota_brutto     = trim($_POST['kwota_brutto'] ?? '');
    $waluta           = $_POST['waluta'] ?? 'PLN';
    $rodzaj           = $_POST['rodzaj_dzialalnosci'] ?? '';
    $projekt          = trim($_POST['projekt'] ?? '');
    $mpk              = trim($_POST['mpk'] ?? '');
    $rachunek_bankowy = preg_replace('/\s+/', '', trim($_POST['rachunek_bankowy'] ?? ''));
    $termin_platnosci = trim($_POST['termin_platnosci'] ?? '');
    $wymaga_mpp       = !empty($_POST['wymaga_mpp']) ? 1 : 0;
    // Wynagrodzenia (rachunek do umowy / lista płac): okres RRRR-MM i nr umowy z Rejestru Umów — do tytułu przelewu.
    $jest_wynagrodzenie = $kierunek === 'wydatek' && in_array($typ_dokumentu, ['rachunek', 'lista_plac'], true);
    $okres       = $jest_wynagrodzenie && preg_match('/^\d{4}-\d{2}$/', $_POST['okres'] ?? '') ? $_POST['okres'] : '';
    $umowa_numer = $jest_wynagrodzenie && $typ_dokumentu === 'rachunek' ? trim($_POST['umowa_numer'] ?? '') : '';
    // Powiązanie z umową z rejestru (link na karcie dokumentu).
    $umowa_ref = null;
    if ($umowa_numer !== '') foreach (edok_umowy_do_wyplat() as $u) if ($u['nr_rejestru'] === $umowa_numer) { $umowa_ref = $u; break; }
    [$proforma_id, $proforma_errors] = $kierunek === 'wydatek' ? edok_proforma_from_post($_POST, $typ_dokumentu) : [null, []];
    [$zaplata, $zaplata_errors]      = $kierunek === 'wydatek' ? edok_zaplata_from_post($_POST, $proforma_id) : edok_zaplata_from_post([]);
    $tytul_przelewu   = trim($_POST['tytul_przelewu'] ?? '');
    // Numer EODoK nie istnieje jeszcze w momencie renderowania formularza (nadawany
    // dopiero przy zapisie) — dopóki user ręcznie nie tknie pola, tytuł jest zawsze
    // dogenerowywany na serwerze z prawdziwym numerem, niezależnie od tego, co JS
    // pokazał w podglądzie (patrz edokSuggestTytul()/edokTytulDirty w skrypcie niżej).
    $tytul_przelewu_auto = ($_POST['tytul_przelewu_auto'] ?? '1') === '1';
    // Dokument testowy (tylko admin) — osobna numeracja EODoK-TEST/… zamiast realnej
    // sekwencji EODoK/NNNN/RRRR, żeby demo/testy nie zużywały prawdziwych numerów
    // akceptacji. Usuwane zbiorczo przyciskiem "Usuń dokumenty testowe" (edok/index.php).
    $is_test = is_admin() && !empty($_POST['is_test']);

    if (!isset(EDOK_TYPES[$typ_dokumentu]))               $errors[] = 'Wybierz typ dokumentu.';
    array_push($errors, ...$proforma_errors, ...$zaplata_errors);
    if (isset(EDOK_TYPES[$typ_dokumentu]) && in_array($typ_dokumentu, EDOK_TYPES_PRZYCHOD, true) !== ($kierunek === 'przychod')) {
        $errors[] = 'Wybrany typ dokumentu nie pasuje do zaznaczonego kierunku (wydatek/przychód).';
    }
    if ($description === '')                              $errors[] = 'Uzupełnij opis ' . ($kierunek === 'przychod' ? 'przychodu' : 'wydatku') . ' — jest wymagany do kontroli merytorycznej.';
    if ($kontrahent_nazwa === '')                          $errors[] = 'Podaj nazwę kontrahenta' . ($kierunek === 'przychod' ? '/darczyńcy.' : '.');
    if ($kontrahent_nip !== '' && !edok_nip_valid($kontrahent_nip)) $errors[] = 'NIP kontrahenta ma nieprawidłową sumę kontrolną.';
    if ($nr_faktury === '')                                $errors[] = 'Podaj numer dokumentu.';
    if ($kierunek === 'przychod' && $zrodlo_przychodu === '') $errors[] = 'Wskaż źródło przychodu (darczyńca, kontrahent albo tytuł wpływu).';
    if ($data_wystawienia !== '') {
        $granica = edok_typ_data_graniczna($typ_dokumentu);
        if ($granica !== null && $data_wystawienia < $granica) {
            $errors[] = 'Dokumenty typu „' . (EDOK_TYPES[$typ_dokumentu] ?? $typ_dokumentu) . '” wystawione przed '
                . date_pl($granica) . ' nie są przyjmowane w EODoK — skieruj je dotychczasowym obiegiem (KDOK).';
        }
    }
    $brutto_num = (float) str_replace([' ', ','], ['', '.'], $kwota_brutto);
    if ($brutto_num <= 0)                                  $errors[] = 'Podaj kwotę brutto większą od zera.';

    // Dokument źródłowy: albo ręczny upload, albo XML pobrany z KSeF (edok/ksef_fetch.php)
    // i wskazany w ukrytym polu ksef_file_path — walidujemy, że wskazuje na plik faktycznie
    // zapisany w uploads/edok_docs/ (bez wychodzenia poza ten katalog).
    $ksef_file_path = trim($_POST['ksef_file_path'] ?? '');
    if ($ksef_file_path !== '') {
        $abs = realpath(UPLOAD_DIR . $ksef_file_path);
        $base = realpath(UPLOAD_DIR . 'edok_docs');
        if (!$abs || !$base || !str_starts_with($abs, $base)) $ksef_file_path = '';
    }
    if ($ksef_file_path === '' && empty($_FILES['file']['tmp_name'])) {
        $errors[] = 'Skan dokumentu źródłowego jest wymagany — bez niego kontrola merytoryczna nie może się rozpocząć.';
    }
    if (!isset(EDOK_RODZAJ_DZIALALNOSCI[$rodzaj]))          $errors[] = 'Wybierz rodzaj działalności (projekt/działanie, statutowa odpłatna lub nieodpłatna).';
    if ($rodzaj === 'projekt' && $projekt === '')           $errors[] = 'Przy rodzaju „Projekt / działanie” podaj nazwę projektu.';
    if ($stawka_vat !== '' && !isset(CRM_OFFER_VAT_RATES[$stawka_vat])) $errors[] = 'Nieprawidłowa stawka VAT.';

    $file_path = null;
    $dowod_zaplaty_path = '';
    if (!$errors && $kierunek === 'wydatek') {
        [$dowod_zaplaty_path, $dowod_errors] = edok_dowod_zaplaty_upload($zaplata);
        array_push($errors, ...$dowod_errors);
    }
    if (!$errors) {
        $file_path = $ksef_file_path !== '' ? $ksef_file_path : handle_upload('file', 'edok_docs');
        if (!$file_path) $errors[] = 'Nie udało się zapisać pliku (dozwolone: PDF, JPG, PNG, DOCX, max 20 MB).';
    }

    if (!$errors) {
        $user = current_user();
        $number = $is_test ? edok_next_test_number() : edok_next_number();
        if ($tytul_przelewu === '' || $tytul_przelewu_auto) {
            $tytul_przelewu = edok_generate_tytul_przelewu([
                'kierunek'         => $kierunek,
                'typ_dokumentu'    => $typ_dokumentu,
                'nr_faktury'       => $nr_faktury,
                'number'           => $number,
                'data_wystawienia' => $data_wystawienia,
                'description'      => $description,
                'kwota_brutto'     => $kwota_brutto,
                'waluta'           => $waluta,
                'okres'            => $okres,
                'umowa_numer'      => $umowa_numer,
            ]);
        }
        $doc_id = db_insert('edok_documents', [
            'number'              => $number,
            'title'               => $nr_faktury !== '' ? $nr_faktury : $number,
            'kierunek'            => $kierunek,
            'typ_dokumentu'       => $typ_dokumentu,
            'description'         => $description,
            'kontrahent_nazwa'    => $kontrahent_nazwa,
            'kontrahent_nip'      => $kontrahent_nip,
            'nr_faktury'          => $nr_faktury,
            'zrodlo_przychodu'    => $zrodlo_przychodu,
            'data_wystawienia'    => $data_wystawienia ?: null,
            'data_sprzedazy'      => $data_sprzedazy ?: null,
            'data_wplywu'         => $data_wplywu ?: null,
            'kwota_netto'         => $kwota_netto,
            'stawka_vat'          => $stawka_vat,
            'kwota_vat'           => $kwota_vat,
            'kwota_brutto'        => $kwota_brutto,
            'waluta'              => $waluta ?: 'PLN',
            'rodzaj_dzialalnosci' => $rodzaj,
            'projekt'             => $projekt,
            'mpk'                 => $mpk,
            'rachunek_bankowy'    => $rachunek_bankowy,
            'termin_platnosci'    => $termin_platnosci ?: null,
            'wymaga_mpp'          => $wymaga_mpp,
            'zaplacono_przed'     => $zaplata['zaplacono_przed'],
            'data_zaplaty'        => $zaplata['data_zaplaty'],
            'forma_zaplaty'       => $zaplata['forma_zaplaty'],
            'zaplacil'            => $zaplata['zaplacil'],
            'zwrot_osoba'         => $zaplata['zwrot_osoba'],
            'zwrot_rachunek'      => $zaplata['zwrot_rachunek'],
            'proforma_id'         => $proforma_id,
            'dowod_zaplaty_path'  => $dowod_zaplaty_path,
            'okres'               => $okres,
            'umowa_numer'         => $umowa_numer,
            'contract_type'       => $umowa_ref['contract_type'] ?? null,
            'contract_id'         => isset($umowa_ref['id']) ? (int)$umowa_ref['id'] : null,
            'tytul_przelewu'      => $tytul_przelewu,
            'file_path'           => $file_path,
            'file_size'           => is_file(UPLOAD_DIR . $file_path) ? filesize(UPLOAD_DIR . $file_path) : null,
            'status'              => 'w_obiegu',
            'created_by'          => (int)$user['id'],
            'creator_name'        => $user['name'] ?? '',
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        // Zapisz kontrahenta do wspólnej kartoteki dostawców (KDOK + CRM), żeby był
        // podpowiadany przy kolejnych dokumentach — patrz edok/search_dostawca.php.
        if ($kontrahent_nip && $rachunek_bankowy) {
            try { kdok_migrate(); kdok_dostawcy_upsert($kontrahent_nip, $kontrahent_nazwa, $rachunek_bankowy); } catch (\Throwable $e) {}
        }

        edok_log($doc_id, 'submit', '', 'draft', 'w_obiegu', 'Dokument ' . $number . ' złożony do obiegu akceptacji przez ' . ($user['name'] ?? '—') . '.');

        flash_set('success', 'Dokument ' . $number . ' złożony do obiegu akceptacji.');
        header('Location: ' . APP_URL . '/edok/view.php?id=' . $doc_id);
        exit;
    }
}

$PAGE_TITLE = 'Nowy dokument — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-journal-plus"></i> Nowy dokument księgowy — EODoK</h4>
</div>

<ul class="nav nav-tabs mb-3" style="max-width:760px">
  <li class="nav-item">
    <a class="nav-link<?= $tryb === 'dokument' ? ' active' : '' ?>" <?= $tryb === 'dokument' ? 'aria-current="page"' : '' ?> href="<?= APP_URL ?>/edok/add.php">
      <i class="bi bi-journal-plus"></i> Dokument do obiegu
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?= $tryb === 'zus' ? ' active' : '' ?>" <?= $tryb === 'zus' ? 'aria-current="page"' : '' ?> href="<?= APP_URL ?>/edok/add.php?tryb=zus">
      <i class="bi bi-shield-check"></i> Przelew składek ZUS <span class="small text-muted">(bez obiegu)</span>
    </a>
  </li>
</ul>

<?php if ($tryb === 'zus'): ?>
<div style="max-width:760px">
  <?php $rachunki_org = edok_rachunki_list(); ?>
  <?php if ($rachunki_org): ?>
  <?php include __DIR__ . '/_zus_form.php'; ?>
  <?php else: ?>
  <div class="alert alert-warning">Brak rachunków organizacji — dodaj je w konfiguracji organizacji (zakładka Rachunki), żeby wygenerować przelew.</div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; ?>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:760px">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data" id="edok-add-form">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="ksef_file_path" id="ksef_file_path" value="">
      <input type="hidden" name="tytul_przelewu_auto" id="tytul_przelewu_auto" value="<?= ($_POST['tytul_przelewu_auto'] ?? '1') === '0' ? '0' : '1' ?>">

      <?php if (is_admin()): ?>
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="is_test" id="is_test" value="1" <?= !empty($_POST['is_test']) ? 'checked' : '' ?>>
        <label class="form-check-label small" for="is_test">
          Dokument testowy — numer <code>EODoK-TEST/…</code> zamiast realnej sekwencji, łatwy do zbiorczego usunięcia z listy EODoK.
        </label>
      </div>
      <?php endif; ?>

      <?php if (org_setting('kdok_ksef_enabled') === '1'): ?>
      <div class="mb-3 p-2 rounded border bg-light">
        <label class="form-label small fw-semibold mb-1"><i class="bi bi-cloud-download"></i> Pobierz z KSeF</label>
        <div class="input-group input-group-sm">
          <input type="text" id="ksef_ref" class="form-control" placeholder="Numer referencyjny KSeF">
          <button type="button" class="btn btn-outline-primary" id="ksef_fetch_btn" onclick="edokKsefFetch()">Pobierz i uzupełnij</button>
        </div>
        <div id="ksef_fetch_status" class="form-text"></div>
      </div>
      <?php endif; ?>

      <?php if ($edok_templates): ?>
      <div class="mb-3 p-2 rounded border bg-light">
        <label class="form-label small fw-semibold mb-1" for="edok_template_select"><i class="bi bi-file-earmark-richtext"></i> Zastosuj szablon</label>
        <select id="edok_template_select" class="form-select form-select-sm" onchange="edokApplyTemplate(this.value)">
          <option value="">— bez szablonu —</option>
          <?php foreach ($edok_templates as $t): ?>
          <option value="<?= (int)$t['id'] ?>"><?= $t['kierunek'] === 'przychod' ? '↑ ' : '↓ ' ?><?= h($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Uzupełnia formularz danymi powtarzalnego wydatku/przychodu (np. stały czynsz) — kwotę i skan zawsze uzupełnia się osobno.</div>
      </div>
      <?php endif; ?>

      <?php $kierunek_val = ($_POST['kierunek'] ?? 'wydatek') === 'przychod' ? 'przychod' : 'wydatek'; ?>
      <div class="mb-3">
        <label class="form-label">Kierunek dokumentu</label>
        <div class="btn-group d-block" role="group">
          <input type="radio" class="btn-check" name="kierunek" id="kierunek_wydatek" value="wydatek" <?= $kierunek_val === 'wydatek' ? 'checked' : '' ?> onchange="edokToggleKierunek()">
          <label class="btn btn-outline-secondary btn-sm" for="kierunek_wydatek"><i class="bi bi-cash-stack"></i> Wydatek</label>
          <input type="radio" class="btn-check" name="kierunek" id="kierunek_przychod" value="przychod" <?= $kierunek_val === 'przychod' ? 'checked' : '' ?> onchange="edokToggleKierunek()">
          <label class="btn btn-outline-secondary btn-sm" for="kierunek_przychod"><i class="bi bi-piggy-bank"></i> Przychód</label>
        </div>
        <div class="form-text">Dokumenty przychodowe (wyciągi, wpłaty, darowizny, granty) — Uchwała 5/2026 §1 pkt 2, obowiązkowo od 1.10.2026.</div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label">Typ dokumentu</label>
          <select name="typ_dokumentu" id="typ_dokumentu" class="form-select" required onchange="edokSuggestTytul(); edokCheckDataGraniczna()">
            <option value="">— wybierz —</option>
            <?php foreach (EDOK_TYPES as $k => $l): ?>
            <option value="<?= h($k) ?>" data-kierunek="<?= in_array($k, EDOK_TYPES_PRZYCHOD, true) ? 'przychod' : 'wydatek' ?>"
              <?= ($_POST['typ_dokumentu'] ?? '') === $k ? 'selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label">Numer dokumentu</label>
          <input type="text" name="nr_faktury" id="nr_faktury" class="form-control" maxlength="100"
            value="<?= h($_POST['nr_faktury'] ?? '') ?>" placeholder="np. FV/2026/01/001" required oninput="edokSuggestTytul()">
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label" id="description_label">Opis wydatku <span class="text-muted fw-normal">(kontrola merytoryczna)</span></label>
        <textarea name="description" class="form-control" rows="2" required oninput="edokSuggestTytul()"
          placeholder="Cel wydatku, potwierdzenie wykonania usługi/dostawy…"><?= h($_POST['description'] ?? '') ?></textarea>
      </div>

      <div class="mb-3" id="zrodlo_przychodu_wrap" style="display:none">
        <label class="form-label">Źródło przychodu <span class="text-muted fw-normal">(darczyńca, kontrahent albo tytuł wpływu)</span></label>
        <input type="text" name="zrodlo_przychodu" id="zrodlo_przychodu" class="form-control" maxlength="255"
          value="<?= h($_POST['zrodlo_przychodu'] ?? '') ?>" placeholder="np. Darowizna od Jan Kowalski / Grant NIW-CRSO nr …">
      </div>

      <div class="mb-2">
        <label class="form-label">Szukaj kontrahenta <span class="text-muted fw-normal">(kartoteka: zapisani dostawcy + CRM)</span></label>
        <input type="text" id="kontrahent-search" class="form-control" autocomplete="off"
          placeholder="Nazwa lub NIP…">
        <div id="kontrahent-results" class="list-group mt-1" style="display:none;position:absolute;z-index:20;max-width:600px"></div>
        <div id="kontrahent-picked" class="form-text"></div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label">Kontrahent</label>
          <input type="text" name="kontrahent_nazwa" id="kontrahent_nazwa" class="form-control" maxlength="255"
            value="<?= h($_POST['kontrahent_nazwa'] ?? '') ?>" required>
        </div>
        <div class="col-sm-3">
          <label class="form-label">NIP kontrahenta</label>
          <input type="text" id="kontrahent_nip" name="kontrahent_nip" class="form-control" maxlength="13"
            value="<?= h($_POST['kontrahent_nip'] ?? '') ?>" placeholder="9999999999">
        </div>
        <div class="col-sm-3">
          <label class="form-label">Nr rachunku kontrahenta</label>
          <input type="text" id="rachunek_bankowy" name="rachunek_bankowy" class="form-control" maxlength="34"
            value="<?= h($_POST['rachunek_bankowy'] ?? '') ?>" placeholder="26 cyfr lub IBAN">
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-sm-4">
          <label class="form-label">Data wystawienia</label>
          <input type="date" name="data_wystawienia" id="data_wystawienia" class="form-control" value="<?= h($_POST['data_wystawienia'] ?? '') ?>" onchange="edokSuggestTytul(); edokCheckDataGraniczna()">
          <div class="form-text text-danger d-none" id="data_graniczna_hint"></div>
        </div>
        <div class="col-sm-4">
          <label class="form-label">Data sprzedaży / wykonania</label>
          <input type="date" name="data_sprzedazy" class="form-control" value="<?= h($_POST['data_sprzedazy'] ?? '') ?>">
        </div>
        <div class="col-sm-4">
          <label class="form-label">Data wpływu</label>
          <input type="date" name="data_wplywu" class="form-control" value="<?= h($_POST['data_wplywu'] ?? date('Y-m-d')) ?>">
        </div>
      </div>

      <div class="row g-3 mb-2">
        <div class="col-sm-3">
          <label class="form-label">Kwota netto</label>
          <input type="text" id="kwota_netto" name="kwota_netto" class="form-control text-end font-monospace"
            value="<?= h($_POST['kwota_netto'] ?? '') ?>" placeholder="0,00" oninput="edokRecalc()">
        </div>
        <div class="col-sm-3">
          <label class="form-label">Stawka VAT</label>
          <select id="stawka_vat" name="stawka_vat" class="form-select" onchange="edokRecalc()">
            <option value="">— podaj VAT ręcznie —</option>
            <?php foreach (CRM_OFFER_VAT_RATES as $k => $r): ?>
            <option value="<?= h($k) ?>" data-rate="<?= h($r['rate']) ?>" <?= ($_POST['stawka_vat'] ?? '') === $k ? 'selected' : '' ?>><?= h($r['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-3">
          <label class="form-label">Kwota VAT</label>
          <input type="text" id="kwota_vat" name="kwota_vat" class="form-control text-end font-monospace"
            value="<?= h($_POST['kwota_vat'] ?? '') ?>" placeholder="0,00" oninput="edokRecalc()">
        </div>
        <div class="col-sm-3">
          <label class="form-label fw-semibold">Kwota brutto</label>
          <div class="input-group">
            <input type="text" id="kwota_brutto" name="kwota_brutto" class="form-control text-end font-monospace fw-semibold"
              value="<?= h($_POST['kwota_brutto'] ?? '') ?>" placeholder="0,00">
            <select name="waluta" id="waluta" class="form-select" style="max-width:90px" onchange="edokMppCheck()">
              <?php foreach (['PLN','EUR','USD','CHF','GBP'] as $w): ?>
              <option value="<?= $w ?>" <?= ($_POST['waluta'] ?? 'PLN') === $w ? 'selected' : '' ?>><?= $w ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="form-text mb-3"><i class="bi bi-magic"></i> Stawka VAT liczy VAT z netto; brutto liczy się automatycznie z netto + VAT (każdą wartość można nadpisać ręcznie).</div>

      <div class="row g-3 mb-2">
        <div class="col-sm-4">
          <label class="form-label">Termin płatności</label>
          <input type="date" name="termin_platnosci" class="form-control" value="<?= h($_POST['termin_platnosci'] ?? '') ?>">
        </div>
        <div class="col-sm-8 d-flex align-items-end">
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="wymaga_mpp" id="wymaga_mpp" value="1" <?= !empty($_POST['wymaga_mpp']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="wymaga_mpp">Wymaga mechanizmu podzielonej płatności (MPP)</label>
          </div>
        </div>
      </div>
      <div id="wynagrodzenie_wrap" class="row g-2 mb-3" style="display:none">
        <div class="col-sm-4">
          <label class="form-label" for="okres">Okres wynagrodzenia</label>
          <?= edok_okres_select_html('okres', 'okres', $_POST['okres'] ?? date('Y-m'), 'onchange="if (window.edokSuggestTytul) edokSuggestTytul()"') ?>
        </div>
        <div class="col-sm-8" id="umowa_numer_wrap">
          <label class="form-label" for="umowa_numer">Umowa nr (Rejestr Umów)</label>
          <?= edok_umowa_select_html('umowa_numer', 'umowa_numer', $_POST['umowa_numer'] ?? '', 'onchange="edokAddUmowa(this)"') ?>
        </div>
        <div class="col-12 form-text mt-0" id="wynagrodzenie_hint">Tytuł przelewu: „WYNAGRODZENIE MM/RRRR - umowa nr …”. Rachunki z rejestru umów zlecenie najwygodniej przekazać przyciskiem w umowie (zakładka Rachunki).</div>
        <div class="col-12 form-text mt-0" id="lista_plac_hint">Pozycje listy płac (osoby, rachunki, kwoty do wypłaty) dodasz na karcie dokumentu po zapisaniu — każda to osobny przelew.</div>
      </div>
      <script>
      (function () {
        // Wybór umowy podpowiada kontrahenta (zleceniobiorcę) i jego rachunek, jeśli pola są puste.
        window.edokAddUmowa = function (sel) {
          var o = sel.selectedOptions[0];
          if (o && o.value) {
            [['kontrahent_nazwa', o.dataset.osoba], ['rachunek_bankowy', o.dataset.rachunek]].forEach(function (p) {
              var f = document.querySelector('[name="' + p[0] + '"]');
              if (f && !f.value && p[1]) f.value = p[1];
            });
          }
          if (window.edokSuggestTytul) edokSuggestTytul();
        };
        var typ = document.getElementById('typ_dokumentu');
        function sync() {
          var t = typ.value, kier = (document.querySelector('input[name="kierunek"]:checked') || {}).value || 'wydatek';
          var on = kier === 'wydatek' && (t === 'rachunek' || t === 'lista_plac');
          document.getElementById('wynagrodzenie_wrap').style.display = on ? '' : 'none';
          document.getElementById('umowa_numer_wrap').style.display = t === 'rachunek' ? '' : 'none';
          document.getElementById('wynagrodzenie_hint').style.display = t === 'rachunek' ? '' : 'none';
          document.getElementById('lista_plac_hint').style.display = t === 'lista_plac' ? '' : 'none';
        }
        typ.addEventListener('change', sync);
        document.querySelectorAll('input[name="kierunek"]').forEach(function (r) { r.addEventListener('change', sync); });
        sync();
      })();
      </script>
      <div id="zaplacono_wrap">
        <?php $zp_vals = $_POST; $zp_typ_el = 'typ_dokumentu'; include __DIR__ . '/_zaplata_fields.php'; ?>
      </div>
      <div id="mpp-alert" class="alert alert-warning py-2 small mb-3" style="display:none">
        <i class="bi bi-exclamation-triangle-fill"></i> Kwota brutto ≥ 15 000 PLN — zwykle wymagany MPP.
      </div>

      <div class="mb-3">
        <label class="form-label" id="tytul_przelewu_label">Tytuł przelewu</label>
        <div class="input-group">
          <input type="text" name="tytul_przelewu" id="tytul_przelewu" class="form-control" maxlength="140"
            value="<?= h($_POST['tytul_przelewu'] ?? '') ?>" placeholder="Uzupełni się automatycznie z danych dokumentu…"
            oninput="edokTytulDirty = true; document.getElementById('tytul_przelewu_auto').value = '0';">
          <button type="button" class="btn btn-outline-secondary" onclick="edokSuggestTytul(true)"><i class="bi bi-magic"></i> Generuj</button>
        </div>
        <div class="form-text">Podpowiadany automatycznie z typu dokumentu, numeru, daty wystawienia i skróconego opisu wydatku — można nadpisać ręcznie. Numer EODoK zostanie dopisany na początku dopiero po zapisaniu (nadawany przy zapisie dokumentu).</div>
      </div>

      <hr>
      <h6 class="text-muted"><i class="bi bi-journal-bookmark"></i> Dekretacja</h6>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label">Rodzaj działalności</label>
          <select name="rodzaj_dzialalnosci" id="rodzaj_dzialalnosci" class="form-select" required onchange="edokToggleProjekt()">
            <option value="">— wybierz —</option>
            <?php foreach (EDOK_RODZAJ_DZIALALNOSCI as $k => $l): ?>
            <option value="<?= h($k) ?>" <?= ($_POST['rodzaj_dzialalnosci'] ?? '') === $k ? 'selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6" id="projekt_wrap">
          <label class="form-label">Nazwa projektu / działania</label>
          <input type="text" name="projekt" class="form-control" maxlength="200" value="<?= h($_POST['projekt'] ?? '') ?>">
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">MPK <span class="text-muted fw-normal">(opcjonalnie)</span></label>
        <input type="text" name="mpk" class="form-control" maxlength="100" value="<?= h($_POST['mpk'] ?? '') ?>">
      </div>

      <div class="mb-3" id="file_upload_wrap">
        <label class="form-label">Skan dokumentu źródłowego</label>
        <input type="file" name="file" id="file_input" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.docx" required>
        <div class="form-text">PDF, JPG, PNG lub DOCX, max 20 MB.</div>
      </div>
      <div class="mb-3 alert alert-success py-2" id="ksef_file_attached" style="display:none">
        <i class="bi bi-check-circle-fill"></i> Dokument źródłowy pobrany z KSeF (XML) — nie trzeba wgrywać skanu.
      </div>

      <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Złóż do obiegu akceptacji</button>
    </form>
  </div>
</div>

<script>
var EDOK_TEMPLATES = <?= json_encode(array_column($edok_templates, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;

// Zastosuj szablon — wypełnia pola formularza wartościami domyślnymi zapisanymi
// w edok_templates (edok/szablony.php); nie dotyka kwoty/daty/skanu, bo te są
// specyficzne dla każdego wystąpienia powtarzalnego dokumentu.
function edokApplyTemplate(id) {
  if (!id || !EDOK_TEMPLATES[id]) return;
  var t = EDOK_TEMPLATES[id];
  var set = function (name, val) { var el = document.querySelector('[name="' + name + '"]'); if (el) el.value = val || ''; };

  document.getElementById(t.kierunek === 'przychod' ? 'kierunek_przychod' : 'kierunek_wydatek').checked = true;
  edokToggleKierunek();

  set('typ_dokumentu', t.typ_dokumentu);
  set('description', t.description);
  set('kontrahent_nazwa', t.kontrahent_nazwa);
  set('kontrahent_nip', t.kontrahent_nip);
  set('rachunek_bankowy', t.rachunek_bankowy);
  set('zrodlo_przychodu', t.zrodlo_przychodu);
  set('waluta', t.waluta);
  set('stawka_vat', t.stawka_vat);
  set('rodzaj_dzialalnosci', t.rodzaj_dzialalnosci);
  set('projekt', t.projekt);
  set('mpk', t.mpk);
  if (t.kwota_netto)  set('kwota_netto', t.kwota_netto);
  if (t.kwota_vat)    set('kwota_vat', t.kwota_vat);
  if (t.kwota_brutto) set('kwota_brutto', t.kwota_brutto);

  edokToggleProjekt();
  edokRecalc();
  edokSuggestTytul();
}

function edokRecalc() {
  var netto = parseFloat((document.getElementById('kwota_netto').value || '0').replace(',', '.').replace(/\s/g, '')) || 0;
  var vatSel = document.getElementById('stawka_vat');
  var vatField = document.getElementById('kwota_vat');
  var rate = vatSel.selectedOptions[0] ? vatSel.selectedOptions[0].getAttribute('data-rate') : null;
  if (rate !== null && vatSel.value !== '') {
    vatField.value = (netto * parseFloat(rate) / 100).toFixed(2).replace('.', ',');
  }
  var vat = parseFloat((vatField.value || '0').replace(',', '.').replace(/\s/g, '')) || 0;
  var brutto = document.getElementById('kwota_brutto');
  if (netto + vat > 0) brutto.value = (netto + vat).toFixed(2).replace('.', ',');
  edokMppCheck();
}
function edokMppCheck() {
  var brutto = parseFloat((document.getElementById('kwota_brutto').value || '0').replace(',', '.').replace(/\s/g, '')) || 0;
  var waluta = document.getElementById('waluta').value;
  var alert = document.getElementById('mpp-alert');
  var mpp = document.getElementById('wymaga_mpp');
  var show = waluta === 'PLN' && brutto >= 15000;
  alert.style.display = show ? '' : 'none';
  if (show) mpp.checked = true;
}
function edokToggleProjekt() {
  var v = document.getElementById('rodzaj_dzialalnosci').value;
  document.getElementById('projekt_wrap').style.display = (v === 'projekt') ? '' : 'none';
}
edokToggleProjekt();
edokMppCheck();

// Kierunek dokumentu (Uchwała 5/2026 §1 pkt 2) — filtruje typy dostępne w selektorze,
// przełącza etykietę opisu i pokazuje pole źródła przychodu. Nie zmienia numeru
// dokumentu ani skanu — te są wspólne dla obu kierunków.
function edokToggleKierunek() {
  var kierunek = (document.querySelector('input[name="kierunek"]:checked') || {}).value || 'wydatek';
  var typSel = document.getElementById('typ_dokumentu');
  Array.from(typSel.options).forEach(function (o) {
    if (!o.value) return;
    var match = o.dataset.kierunek === kierunek;
    o.hidden = !match;
    if (!match && o.selected) typSel.value = '';
  });
  document.getElementById('description_label').firstChild.textContent = kierunek === 'przychod' ? 'Opis przychodu ' : 'Opis wydatku ';
  document.getElementById('zrodlo_przychodu_wrap').style.display = kierunek === 'przychod' ? '' : 'none';
  document.getElementById('zaplacono_wrap').style.display = kierunek === 'przychod' ? 'none' : '';
  document.getElementById('tytul_przelewu_label').textContent = kierunek === 'przychod' ? 'Sugerowana referencja wpłaty' : 'Tytuł przelewu';
  edokCheckDataGraniczna();
}
edokToggleKierunek();

// Faktury/rachunki wystawione przed datą graniczną nie są przyjmowane w EODoK
// (ten sam wzorzec co edok_typ_data_graniczna() w PHP — tylko podpowiedź, serwer
// waliduje niezależnie i ostatecznie).
var EDOK_CUTOFF_FAKTURA  = '2026-09-01';
var EDOK_CUTOFF_RACHUNEK = '2026-10-01';
function edokCheckDataGraniczna() {
  var typ  = document.getElementById('typ_dokumentu').value;
  var data = document.getElementById('data_wystawienia').value;
  var hint = document.getElementById('data_graniczna_hint');
  var granica = EDOK_FAKTURA_TYPES.indexOf(typ) !== -1 ? EDOK_CUTOFF_FAKTURA : (typ === 'rachunek' ? EDOK_CUTOFF_RACHUNEK : null);
  if (granica && data && data < granica) {
    hint.textContent = 'Ten typ dokumentu wystawiony przed ' + granica.split('-').reverse().join('.') + ' nie jest przyjmowany w EODoK — użyj dotychczasowego obiegu (KDOK).';
    hint.classList.remove('d-none');
  } else {
    hint.classList.add('d-none');
  }
}

// Podpowiedź tytułu przelewu — ten sam wzorzec co edok_generate_tytul_przelewu() w PHP:
// "PŁATNOŚĆ: {opis} - FAK/DOK: {identyfikator} - AKC: {numer} - {kwota} {waluta}",
// separator " - ", nie "|" — pionowa kreska bywa odrzucana przez systemy bankowości
// elektronicznej, np. PKO), żeby podgląd na żywo odpowiadał temu, co dogeneruje
// backend. Numer EODoK nie istnieje jeszcze na etapie formularza (nadawany dopiero
// przy zapisie) — podgląd pokazuje placeholder, ale to serwer
// (edok_generate_tytul_przelewu()) wstawi prawdziwy numer, dopóki pole nie zostanie
// ręcznie zmienione (edokTytulDirty / ukryte pole tytul_przelewu_auto).
var EDOK_TYPE_LABELS = <?= json_encode(EDOK_TYPES, JSON_UNESCAPED_UNICODE) ?>;
var EDOK_FAKTURA_TYPES = ['faktura_vat', 'faktura_korygujaca'];
var edokTytulDirty = <?= (($_POST['tytul_przelewu_auto'] ?? '1') === '0') ? 'true' : 'false' ?>;
function edokShortOpis(text) {
  text = (text || '').trim().replace(/\s+/g, ' ');
  if (!text) return '';
  return text.length > 60 ? text.substring(0, 60) + '...' : text;
}
function edokSuggestTytul(force) {
  var field = document.getElementById('tytul_przelewu');
  if (!field || (edokTytulDirty && !force)) return;
  var typKey = document.getElementById('typ_dokumentu').value;
  var kierunekW = (document.querySelector('input[name="kierunek"]:checked') || {}).value || 'wydatek';
  var umowaEl = document.getElementById('umowa_numer');
  if (kierunekW === 'wydatek' && (typKey === 'lista_plac' || (typKey === 'rachunek' && umowaEl && umowaEl.value.trim()))) {
    var ok = (document.getElementById('okres') || {}).value || '';
    var okres = /^\d{4}-\d{2}$/.test(ok) ? ok.substring(5, 7) + '/' + ok.substring(0, 4) : '';
    field.value = typKey === 'lista_plac' ? ('WYNAGRODZENIA ' + okres).trim() : (('WYNAGRODZENIE ' + okres).trim() + ' - umowa nr ' + umowaEl.value.trim());
    return;
  }
  var typ = EDOK_TYPE_LABELS[typKey] || 'Dokument księgowy';
  var nr = document.getElementById('nr_faktury').value.trim();
  var opisEl = document.querySelector('[name="description"]');
  var opis = edokShortOpis(opisEl ? opisEl.value : '') || typ;
  var numer = 'EODoK/.../' + new Date().getFullYear();
  var jestPrzychod = (document.querySelector('input[name="kierunek"]:checked') || {}).value === 'przychod';
  var jestFaktura = !jestPrzychod && EDOK_FAKTURA_TYPES.indexOf(typKey) !== -1 && nr !== '';
  var ident = jestFaktura ? ('FAK: ' + nr) : ('DOK: ' + (typ + (nr ? ' ' + nr : '')).trim());
  var kwota = (document.getElementById('kwota_brutto') || {}).value || '';
  var waluta = (document.getElementById('waluta') || {}).value || 'PLN';
  var t = (jestPrzychod ? 'PRZYCHÓD: ' : 'PŁATNOŚĆ: ') + opis + ' - ' + ident + ' - AKC: ' + numer + (kwota.trim() ? (' - ' + kwota.trim() + ' ' + waluta) : '');
  field.value = t.trim().substring(0, 140);
  document.getElementById('tytul_przelewu_auto').value = '1';
  edokTytulDirty = false;
}

// ── Selektor kontrahenta (kartoteka: zapisani dostawcy + CRM) ────────────────
(function () {
  var search  = document.getElementById('kontrahent-search');
  var results = document.getElementById('kontrahent-results');
  var picked  = document.getElementById('kontrahent-picked');
  if (!search) return;

  function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  function fillFields(r) {
    document.querySelector('[name="kontrahent_nazwa"]').value = r.nazwa || '';
    if (r.nip) document.getElementById('kontrahent_nip').value = r.nip;
    if (r.rachunek_bankowy) document.getElementById('rachunek_bankowy').value = r.rachunek_bankowy;
    picked.innerHTML = r.nazwa
      ? '<i class="bi bi-check-circle-fill text-success me-1"></i>Wybrano: <strong>' + esc(r.nazwa) + '</strong>'
         + (r.nip ? ' · NIP: ' + esc(r.nip) : '')
      : '';
    results.style.display = 'none';
  }

  var timer = null;
  search.addEventListener('input', function () {
    clearTimeout(timer);
    var q = search.value.trim();
    if (q.length < 2) { results.style.display = 'none'; return; }
    timer = setTimeout(function () {
      fetch('<?= APP_URL ?>/edok/search_dostawca.php?q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          results.innerHTML = '';
          if (!data.results || !data.results.length) { results.style.display = 'none'; return; }
          data.results.forEach(function (item) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action py-2';
            var src = item.source === 'crm'
              ? '<span class="badge bg-primary ms-1" style="font-size:.65rem">CRM</span>'
              : '<span class="badge bg-secondary ms-1" style="font-size:.65rem">Zapisany</span>';
            btn.innerHTML = '<span class="fw-semibold">' + esc(item.nazwa || '—') + '</span> '
              + (item.nip ? '<span class="text-muted small ms-1">NIP: ' + esc(item.nip) + '</span>' : '') + src;
            btn.addEventListener('click', function () { fillFields(item); });
            results.appendChild(btn);
          });
          results.style.display = '';
        })
        .catch(function () { results.style.display = 'none'; });
    }, 280);
  });

  document.addEventListener('click', function (e) {
    if (e.target !== search && !results.contains(e.target)) results.style.display = 'none';
  });
})();

function edokKsefFetch() {
  var ref = document.getElementById('ksef_ref').value.trim();
  var status = document.getElementById('ksef_fetch_status');
  var btn = document.getElementById('ksef_fetch_btn');
  if (!ref) { status.textContent = 'Podaj numer referencyjny KSeF.'; status.className = 'form-text text-danger'; return; }

  btn.disabled = true;
  status.textContent = 'Pobieranie z KSeF…';
  status.className = 'form-text text-muted';

  var fd = new FormData();
  fd.append('_csrf', document.querySelector('#edok-add-form input[name="_csrf"]').value);
  fd.append('ksef_reference', ref);

  fetch('<?= APP_URL ?>/edok/ksef_fetch.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      btn.disabled = false;
      if (!res.ok) { status.textContent = res.error || 'Błąd pobierania.'; status.className = 'form-text text-danger'; return; }

      var d = res.data;
      var set = function (id, val) { var el = document.getElementById(id); if (el && val) el.value = val; };
      set('kwota_brutto', d.kwota_brutto);
      document.querySelector('[name="typ_dokumentu"]').value = d.typ_dokumentu || 'faktura_vat';
      document.querySelector('[name="nr_faktury"]').value = d.nr_faktury || '';
      document.querySelector('[name="kontrahent_nazwa"]').value = d.kontrahent_nazwa || '';
      document.querySelector('[name="kontrahent_nip"]').value = d.kontrahent_nip || '';
      document.querySelector('[name="waluta"]').value = d.waluta || 'PLN';
      document.querySelector('[name="data_wystawienia"]').value = d.data_wystawienia || '';
      document.querySelector('[name="description"]').value = d.description || '';

      document.getElementById('ksef_file_path').value = res.file_path || '';
      document.getElementById('file_input').required = false;
      document.getElementById('file_upload_wrap').style.display = 'none';
      document.getElementById('ksef_file_attached').style.display = '';
      edokSuggestTytul();

      status.textContent = 'Uzupełniono dane z KSeF. Sprawdź i uzupełnij netto/VAT oraz dekretację.';
      status.className = 'form-text text-success';
    })
    .catch(function () {
      btn.disabled = false;
      status.textContent = 'Błąd sieci przy pobieraniu z KSeF.';
      status.className = 'form-text text-danger';
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
