<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/persons.php';
require_once dirname(dirname(__DIR__)) . '/includes/address.php';

require_role('admin', 'editor');

// Moduł w przygotowaniu — blokuj dodawanie/edycję
require_once dirname(dirname(__DIR__)) . '/includes/contract_preview_notice.php';
if (contract_is_preview('praca') && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('warning', 'Moduł praca jest w przygotowaniu. Dodawanie i edycja są tymczasowo wyłączone.');
    header('Location: list.php'); exit;
}
$TYPE  = 'praca';
$TABLE = 'umowy_praca';
$id  = intval($_GET['id'] ?? 0);
$row = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); die('Nie znaleziono umowy.'); }
$PAGE_TITLE = 'Edycja: ' . $row['numer_umowy'];
$errors = [];

if (!empty($row['person_id'])) {
    $person_row = person_by_id((int)$row['person_id']);
    $row['_person_name'] = $person_row['imie_nazwisko'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data = $_POST;
    unset($data['_csrf']);

    if (empty($data['numer_umowy'])) $errors[] = 'Numer umowy jest wymagany.';
    if (empty($data['status']))      $errors[] = 'Status jest wymagany.';

    if (!$errors) {
        // Pola checkboxowe
        foreach (['ppk', 'pit2', 'klauzula_rodo'] as $f) {
            $data[$f] = isset($_POST[$f]) ? 1 : 0;
        }
        // Puste pola liczbowe → null
        foreach (['wynagrodzenie_brutto', 'urlop_wymiar', 'urlop_zalegly'] as $f) {
            if (isset($data[$f]) && $data[$f] === '') $data[$f] = null;
        }
        // Upload plików — zachowaj stare jeśli nie przesłano nowego
        $plik_umowy = handle_upload('plik_umowy', $TYPE);
        $plik_potw  = handle_upload('plik_potwierdzenia', $TYPE);
        $data['plik_umowy']         = $plik_umowy  ?: $row['plik_umowy'];
        $data['plik_potwierdzenia'] = $plik_potw   ?: $row['plik_potwierdzenia'];

        $data['updated_at'] = date('Y-m-d H:i:s');

        $allowed = [
            'numer_umowy', 'status', 'imie_nazwisko', 'pesel', 'adres', 'email', 'seria_nr_dowodu',
            'addr_street', 'addr_house', 'addr_flat', 'addr_postal', 'addr_city', 'addr_country',
            'urzad_skarbowy', 'rachunek_bankowy', 'email_login', 'stanowisko', 'dzial_projekt', 'wymiar_etatu',
            'rodzaj_umowy', 'data_zawarcia', 'data_rozpoczecia', 'data_zakonczenia',
            'wynagrodzenie_brutto', 'skladniki_wynagrodzenia', 'urlop_wymiar', 'urlop_zalegly',
            'okres_wypowiedzenia', 'ppk', 'pit2', 'badania_data_waznosci', 'bhp_data_waznosci',
            'klauzula_rodo', 'opiekun_przelozony', 'forma_podpisania', 'platforma_el',
            'id_dokumentu_el', 'plik_potwierdzenia', 'plik_umowy', 'aneksy', 'uwagi',
            'nr_roboczy', 'nr_system', 'nr_rejestru', 'updated_at',
            'person_id', 'org_unit_id',
        ];
        $save = array_intersect_key($data, array_flip($allowed));
        require_once dirname(dirname(__DIR__)) . '/includes/amendments.php';
        $diff = format_field_diff($row, $save);
        db_update($TABLE, $save, $id);
        require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
        log_contract_action($TYPE, $id, current_user()['id'], 'edit', $diff ?: 'Edytowano umowę');
        flash_set('success', 'Zmiany zostały zapisane.');
        header('Location: view.php?id=' . $id);
        exit;
    }
    $row = array_merge($row, $_POST);
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-pencil text-primary"></i> Edycja: <?= h($row['numer_umowy']) ?></h4>
  <div class="d-flex gap-2">
    <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> Podgląd</a>
    <a href="list.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Lista</a>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<!-- Numery referencyjne -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-hash"></i> Numery referencyjne</div>
<div class="card-body"><div class="row">
  <div class="col-md-4 mb-3">
    <label class="form-label">Nr roboczy umowy</label>
    <input name="nr_roboczy" class="form-control" value="<?= h($row['nr_roboczy']??'') ?>" placeholder="np. PR-2026-001">
    <div class="form-text">Numer roboczy w projekcie.</div>
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Nr ogólny <span class="text-muted small">(webNGO, opcjonalne)</span></label>
    <input name="nr_system" class="form-control" value="<?= h($row['nr_system']??'') ?>">
  </div>
  <div class="col-md-4 mb-3">
    <label class="form-label">Nr rejestru <span class="text-muted small">RU/{nr}/{rok}/{inicjały}</span></label>
    <input name="nr_rejestru" class="form-control font-monospace"
      value="<?= h($row['nr_rejestru']??'') ?>"
      placeholder="<?= h(suggest_nr_rejestru($row['opiekun_przelozony']??'')) ?>">
    <div class="form-text">Zostaw puste — zostanie nadany automatycznie.</div>
  </div>
</div></div>
</div>

<div class="row">
<div class="col-lg-8">

<!-- DANE PODSTAWOWE -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold">Dane podstawowe</div>
  <div class="card-body">
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Numer umowy *</label>
        <input name="numer_umowy" class="form-control fw-bold" value="<?= h($row['numer_umowy']) ?>" required>
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Status *</label>
        <select name="status" class="form-select" required>
          <?php foreach (['obowiązująca' => 'Obowiązująca', 'rozwiązana' => 'Rozwiązana', 'wygasła' => 'Wygasła'] as $k => $v):
            $sel = $row['status'] === $k ? 'selected' : ''; ?>
          <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Rodzaj umowy</label>
        <select name="rodzaj_umowy" class="form-select">
          <option value="">—</option>
          <?php foreach (['okres próbny' => 'Okres próbny', 'czas określony' => 'Czas określony', 'czas nieokreślony' => 'Czas nieokreślony'] as $k => $v):
            $sel = ($row['rodzaj_umowy'] ?? '') === $k ? 'selected' : ''; ?>
          <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Data zawarcia</label>
        <input name="data_zawarcia" type="date" class="form-control" value="<?= h($row['data_zawarcia']) ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Data rozpoczęcia</label>
        <input name="data_rozpoczecia" type="date" class="form-control" value="<?= h($row['data_rozpoczecia']) ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Data zakończenia</label>
        <input name="data_zakonczenia" type="date" class="form-control" value="<?= h($row['data_zakonczenia']) ?>">
      </div>
    </div>
  </div>
</div>

<!-- PRACOWNIK -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold">Pracownik</div>
  <div class="card-body">
    <!-- Person picker -->
    <div class="mb-3">
      <label class="form-label fw-semibold">Osoba powiązana w rejestrze</label>
      <div class="input-group">
        <input type="text" id="person_search" class="form-control"
               placeholder="Szukaj po imieniu, PESEL lub email…"
               value="<?= h($row['_person_name'] ?? '') ?>"
               autocomplete="off">
        <a href="<?= APP_URL ?>/persons/add.php" class="btn btn-outline-secondary" target="_blank" title="Dodaj nową osobę">
          <i class="bi bi-person-plus"></i>
        </a>
      </div>
      <input type="hidden" name="person_id" id="person_id" value="<?= h($row['person_id'] ?? '') ?>">
      <div id="person_results" class="list-group mt-1" style="display:none;position:absolute;z-index:1000;max-width:500px"></div>
      <div class="form-text">Opcjonalnie: wybierz istniejącą osobę lub <a href="<?= APP_URL ?>/persons/add.php" target="_blank">dodaj nową</a>.</div>
    </div>
    <!-- Pozycja w strukturze -->
    <div class="mb-3">
      <label class="form-label">Komórka organizacyjna</label>
      <select name="org_unit_id" class="form-select">
        <option value="">— wybierz —</option>
        <?php
        try {
          $units = db_all("SELECT id, name FROM org_units WHERE status='active' ORDER BY sort_order, name");
          foreach ($units as $pos):
            $sel = ($row['org_unit_id'] ?? '') == $pos['id'] ? 'selected' : '';
        ?>
        <option value="<?= h($pos['id']) ?>" <?= $sel ?>><?= h($pos['name']) ?></option>
        <?php endforeach; } catch(\Throwable $e) {} ?>
      </select>
    </div>
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label">Imię i nazwisko</label>
        <input name="imie_nazwisko" class="form-control" value="<?= h($row['imie_nazwisko']) ?>">
      </div>
      <div class="col-md-3 mb-3">
        <label class="form-label">PESEL</label>
        <input name="pesel" class="form-control" maxlength="11" value="<?= h($row['pesel']) ?>">
      </div>
      <div class="col-md-3 mb-3">
        <label class="form-label">Seria i nr dowodu</label>
        <input name="seria_nr_dowodu" class="form-control" value="<?= h($row['seria_nr_dowodu']) ?>">
      </div>
    </div>
    <div class="row">
      <div class="col-12 mb-3">
        <label class="form-label fw-semibold"><i class="bi bi-house me-1 text-secondary"></i>Adres zamieszkania</label>
        <?= address_widget($row) ?>
      </div>
      <div class="col-md-6 mb-3"><label class="form-label">Adres e-mail</label>
        <input type="email" name="email" class="form-control" placeholder="np. jan.kowalski@email.pl" value="<?= h($row['email'])?>"></div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Urząd skarbowy</label>
        <input name="urzad_skarbowy" class="form-control" value="<?= h($row['urzad_skarbowy']) ?>">
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label">Rachunek bankowy</label>
      <input name="rachunek_bankowy" class="form-control" value="<?= h($row['rachunek_bankowy']) ?>">
    </div>
    <div class="mb-2">
      <label class="form-label">Email do logowania w panelu</label>
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
        <input name="email_login" type="email" class="form-control"
          value="<?= h($row['email_login'] ?? '') ?>"
          placeholder="imie.nazwisko@feer.org.pl  lub  prywatny@email.com">
      </div>
      <div class="form-text">Adres Microsoft 365 (<code>@feer.org.pl</code>) lub prywatny e-mail — umożliwia dostęp do panelu umów.</div>
    </div>
  </div>
</div>

<!-- STANOWISKO -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold">Stanowisko</div>
  <div class="card-body">
    <div class="row">
      <div class="col-md-5 mb-3">
        <label class="form-label">Stanowisko</label>
        <input name="stanowisko" class="form-control" value="<?= h($row['stanowisko']) ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Dział / projekt</label>
        <input name="dzial_projekt" class="form-control" value="<?= h($row['dzial_projekt']) ?>">
      </div>
      <div class="col-md-3 mb-3">
        <label class="form-label">Wymiar etatu</label>
        <select name="wymiar_etatu" class="form-select">
          <option value="">—</option>
          <?php foreach (['pełny' => 'Pełny etat', '1/2' => '1/2 etatu', '3/4' => '3/4 etatu', '1/4' => '1/4 etatu', 'inny' => 'Inny'] as $k => $v):
            $sel = ($row['wymiar_etatu'] ?? '') === $k ? 'selected' : ''; ?>
          <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label">Opiekun / przełożony</label>
        <input name="opiekun_przelozony" class="form-control" value="<?= h($row['opiekun_przelozony']) ?>">
      </div>
    </div>
  </div>
</div>

<!-- WYNAGRODZENIE -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold">Wynagrodzenie</div>
  <div class="card-body">
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Wynagrodzenie brutto (PLN)</label>
        <input name="wynagrodzenie_brutto" type="number" step="0.01" min="0" class="form-control" value="<?= h($row['wynagrodzenie_brutto']) ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Wymiar urlopu (dni/rok)</label>
        <input name="urlop_wymiar" type="number" min="0" class="form-control" value="<?= h($row['urlop_wymiar']) ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Urlop zaległy (dni)</label>
        <input name="urlop_zalegly" type="number" min="0" class="form-control" value="<?= h($row['urlop_zalegly']) ?>">
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label">Składniki wynagrodzenia</label>
      <textarea name="skladniki_wynagrodzenia" class="form-control" rows="3"><?= h($row['skladniki_wynagrodzenia']) ?></textarea>
    </div>
    <div class="col-md-6 mb-3">
      <label class="form-label">Okres wypowiedzenia</label>
      <input name="okres_wypowiedzenia" class="form-control" value="<?= h($row['okres_wypowiedzenia']) ?>">
    </div>
  </div>
</div>

<!-- FORMALNOŚCI -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold">Formalności</div>
  <div class="card-body">
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Badania lekarskie — ważne do</label>
        <input name="badania_data_waznosci" type="date" class="form-control" value="<?= h($row['badania_data_waznosci']) ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">BHP — szkolenie ważne do</label>
        <input name="bhp_data_waznosci" type="date" class="form-control" value="<?= h($row['bhp_data_waznosci']) ?>">
      </div>
    </div>
    <div class="row">
      <div class="col-md-4 mb-3 pt-2">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="ppk" id="ppk" value="1" <?= $row['ppk'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="ppk">PPK (Pracownicze Plany Kapitałowe)</label>
        </div>
      </div>
      <div class="col-md-4 mb-3 pt-2">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="pit2" id="pit2" value="1" <?= $row['pit2'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="pit2">PIT-2 złożony</label>
        </div>
      </div>
      <div class="col-md-4 mb-3 pt-2">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="klauzula_rodo" id="klauzula_rodo" value="1" <?= $row['klauzula_rodo'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="klauzula_rodo">Klauzula RODO podpisana</label>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- PODPISANIE I ANEKSY -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold">Podpisanie i aneksy</div>
  <div class="card-body">
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Forma podpisania</label>
        <select name="forma_podpisania" class="form-select" id="forma_podpisania">
          <option value="">—</option>
          <option value="papierowa" <?= $row['forma_podpisania'] === 'papierowa' ? 'selected' : '' ?>>Papierowa</option>
          <option value="elektroniczna" <?= $row['forma_podpisania'] === 'elektroniczna' ? 'selected' : '' ?>>Elektroniczna</option>
      <option value="epodpis_kwalifikowany" <?= ($row['forma_podpisania'] === 'epodpis_kwalifikowany') ? 'selected' : '' ?>>ePodpis kwalifikowany</option>
        </select>
      </div>
    </div>
    <div id="el_fields" class="row" style="display:<?= $row['forma_podpisania'] === 'elektroniczna' ? '' : 'none' ?>">
      <div class="col-md-4 mb-3">
        <label class="form-label">Platforma</label>
        <input name="platforma_el" class="form-control" value="<?= h($row['platforma_el']) ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">ID dokumentu</label>
        <input name="id_dokumentu_el" class="form-control" value="<?= h($row['id_dokumentu_el']) ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Nowy plik potwierdzenia</label>
        <input name="plik_potwierdzenia" type="file" class="form-control" accept=".pdf">
        <?php if ($row['plik_potwierdzenia']): ?>
        <div class="mt-1"><?= upload_link($row['plik_potwierdzenia']) ?></div>
        <?php endif; ?>
      </div>
    </div>
<div id="epodpis_fields" class="row" style="display:none">
  <div class="col-md-4 mb-3"><label class="form-label">Dostawca podpisu (TSP)</label>
    <input name="epodpis_dostawca" class="form-control" placeholder="Certum, SimplySign, mSzafir, Autenti…" value="<?= h($row['epodpis_dostawca'] ?? '') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Numer seryjny certyfikatu</label>
    <input name="epodpis_nr_certyfikatu" class="form-control font-monospace" value="<?= h($row['epodpis_nr_certyfikatu'] ?? '') ?>"></div>
  <div class="col-md-4 mb-3"><label class="form-label">Ważność certyfikatu</label>
    <input name="epodpis_data_waznosci" type="date" class="form-control" value="<?= h($row['epodpis_data_waznosci'] ?? '') ?>"></div>
</div>
    <div class="mb-3">
      <label class="form-label">Aneksy</label>
      <textarea name="aneksy" class="form-control" rows="3"><?= h($row['aneksy']) ?></textarea>
    </div>
  </div>
</div>

<div class="mb-3">
  <label class="form-label">Uwagi</label>
  <textarea name="uwagi" class="form-control" rows="3"><?= h($row['uwagi']) ?></textarea>
</div>

</div><!-- /col-lg-8 -->

<!-- SIDEBAR: Plik umowy -->
<div class="col-lg-4">
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-paperclip"></i> Plik umowy</div>
    <div class="card-body">
      <?php if ($row['plik_umowy']): ?>
      <div class="mb-2"><?= upload_link($row['plik_umowy']) ?></div>
      <label class="form-label small text-muted">Zastąp nowym plikiem:</label>
      <?php else: ?>
      <label class="form-label">Plik umowy (PDF / DOCX)</label>
      <?php endif; ?>
      <input name="plik_umowy" type="file" class="form-control" accept=".pdf,.docx">
    </div>
  </div>
</div>
</div><!-- /row -->

<div class="d-flex gap-2 mb-4">
  <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Zapisz zmiany</button>
  <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Anuluj</a>
</div>
</form>

<script>
document.getElementById('forma_podpisania').addEventListener('change', function () {
  var _fp = this.value;
  document.getElementById('el_fields').style.display = _fp === 'elektroniczna' ? '' : 'none';
  document.getElementById('epodpis_fields').style.display = _fp === 'epodpis_kwalifikowany' ? '' : 'none';
});
</script>

<script>
(function() {
  var searchInput = document.getElementById('person_search');
  var hiddenId    = document.getElementById('person_id');
  var results     = document.getElementById('person_results');
  if (!searchInput) return;
  var timer;
  searchInput.addEventListener('input', function() {
    clearTimeout(timer);
    var q = this.value.trim();
    if (q.length < 2) { results.style.display = 'none'; return; }
    timer = setTimeout(function() {
      fetch('<?= APP_URL ?>/persons/search.php?q=' + encodeURIComponent(q))
        .then(r => r.json()).then(function(data) {
          results.innerHTML = '';
          if (!data.length) {
            results.innerHTML = '<div class="list-group-item text-muted small">Nie znaleziono. <a href="<?= APP_URL ?>/persons/add.php" target="_blank">Dodaj nową osobę</a>.</div>';
          } else {
            data.forEach(function(p) {
              var btn = document.createElement('button');
              btn.type = 'button';
              btn.className = 'list-group-item list-group-item-action small';
              btn.innerHTML = '<strong>' + p.imie_nazwisko + '</strong>'
                + (p.pesel ? ' <span class="text-muted">' + p.pesel.substring(0,6) + '…</span>' : '')
                + (p.email ? ' <span class="text-muted">' + p.email + '</span>' : '');
              btn.addEventListener('click', function() {
                hiddenId.value    = p.id;
                searchInput.value = p.imie_nazwisko;
                results.style.display = 'none';
              });
              results.appendChild(btn);
            });
          }
          results.style.display = '';
        }).catch(function() {});
    }, 250);
  });
  document.addEventListener('click', function(e) {
    if (!results.contains(e.target) && e.target !== searchInput) {
      results.style.display = 'none';
    }
  });
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
