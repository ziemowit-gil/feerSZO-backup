<?php /* ═══════════════════════ TAB: SYLABUS (dawniej „Program zajęć”) ═══════════════════════ */ ?>
<?php
  /**
   * Zakładka nazywa się teraz „Sylabus”: to realizacja sylabusa przedmiotu
   * w tym kursie. Wzorzec przedmiotu prowadzi administracja
   * ([[project_ti_syllabus]]) — tutaj widać, czy kurs go ma, ile jego punktów
   * jest już w planie, i można je dociągnąć jednym przyciskiem.
   */
  require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_syllabus.php';
  $syl_course   = ti_course_syllabus($cur_course);
  $syl_coverage = $syl_course ? ti_syllabus_coverage((int)$syl_course['id'], $cur_course) : null;

  $curr_items = k30_ti_curriculum_list($cur_course);
  $curr_total_min = array_sum(array_map(fn($r) => (int)$r['est_minutes'], $curr_items));
  $currFormHtml = function(?array $r, string $pfx) use ($cur_course) {
    $isEdit = $r !== null; ?>
    <form method="post">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="curr_save">
      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
      <?php if ($isEdit): ?><input type="hidden" name="item_id" value="<?= (int)$r['id'] ?>"><?php endif; ?>
      <div class="modal-header">
        <h5 class="modal-title" id="<?= $pfx ?>_t"><i class="bi bi-<?= $isEdit?'pencil':'plus-lg' ?> me-2"></i><?= $isEdit?'Edytuj pozycję planu':'Nowa pozycja planu' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label fw-semibold" for="<?= $pfx ?>_title">Temat <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="<?= $pfx ?>_title" name="title" required maxlength="300" value="<?= h($r['title'] ?? '') ?>">
        </div>
        <div class="row g-2">
          <div class="col-8 mb-2">
            <label class="form-label" for="<?= $pfx ?>_section">Dział / moduł <span class="text-body-secondary small">(opc.)</span></label>
            <input type="text" class="form-control" id="<?= $pfx ?>_section" name="section" maxlength="200" value="<?= h($r['section'] ?? '') ?>" placeholder="np. Podstawy obsługi komputera">
          </div>
          <div class="col-4 mb-2">
            <label class="form-label" for="<?= $pfx ?>_min">Czas (min)</label>
            <input type="number" class="form-control" id="<?= $pfx ?>_min" name="est_minutes" min="0" step="5" value="<?= (int)($r['est_minutes'] ?? 0) ?>">
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label" for="<?= $pfx ?>_desc">Opis <span class="text-body-secondary small">(opc.)</span></label>
          <textarea class="form-control" id="<?= $pfx ?>_desc" name="description" rows="3"><?= h($r['description'] ?? '') ?></textarea>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="<?= $pfx ?>_act" name="is_active" value="1" <?= ($isEdit && (int)($r['is_active'] ?? 0) === 0) ? '' : 'checked' ?>>
          <label class="form-check-label" for="<?= $pfx ?>_act">Pozycja aktywna (widoczna w planie kursanta)</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Zapisz</button>
      </div>
    </form>
  <?php };
?>
<div class="card">
  <div class="card-header">Sylabus kursu — skąd się bierze</div>
  <div class="card-body">
    <p class="small mb-2">
      Ta zakładka to <strong>sylabus tego kursu</strong>: lista tematów, które realizujesz z grupą.
      Wcześniej nazywała się <strong>„Program zajęć”</strong> — to ta sama rzecz i te same dane,
      zmieniła się tylko nazwa.
    </p>
    <?php if (!$syl_course): ?>
    <p class="small text-body-secondary mb-0">
      Przedmiot tego kursu nie ma jeszcze sylabusa wzorcowego prowadzonego przez administrację —
      tematy prowadzisz tu samodzielnie. Możesz je dodawać pojedynczo albo
      <strong>wgrać cały sylabus plikiem CSV</strong> (przycisk niżej).
    </p>
    <?php else: ?>
    <div class="d-flex align-items-center gap-2 flex-wrap small">
      <span>Sylabus przedmiotu: <strong><?= h($syl_course['title']) ?></strong></span>
      <span class="badge text-bg-light border text-dark">wersja <?= h($syl_course['version']) ?></span>
      <?php if (!empty($syl_course['inherited'])): ?>
      <span class="badge text-bg-info" title="Kurs nie ma własnego przypisania — obowiązuje sylabus przedmiotu">dziedziczony po przedmiocie</span>
      <?php endif; ?>
      <?php if ($syl_coverage && $syl_coverage['total'] > 0): ?>
      <span class="badge <?= $syl_coverage['covered'] >= $syl_coverage['total'] ? 'text-bg-success' : 'text-bg-warning' ?>">
        w Twoim wykazie <?= (int)$syl_coverage['covered'] ?> z <?= (int)$syl_coverage['total'] ?> punktów wzorca
      </span>
      <?php endif; ?>
    </div>
    <p class="small text-body-secondary mb-0 mt-2">
      Wzorzec przedmiotu prowadzi administracja; punkty poniżej to jego realizacja w tej grupie
      i one łączą się z konkretnymi zajęciami.
    </p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center flex-wrap gap-2">
    <span>Tematy w tym kursie</span>
    <span class="badge bg-secondary"><?= count($curr_items) ?> pozycji</span>
    <?php if ($curr_total_min > 0): ?><span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"><?= round($curr_total_min/60,1) ?> h łącznie</span><?php endif; ?>
    <div class="ms-auto d-flex gap-2">
      <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#currImport"><i class="bi bi-upload me-1"></i>Wgraj sylabus (CSV)</button>
      <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#currAdd"><i class="bi bi-plus-lg me-1"></i>Dodaj pozycję</button>
    </div>
  </div>
  <div class="list-group list-group-flush">
    <?php if (!$curr_items): ?>
    <div class="list-group-item text-body-secondary py-3">Brak pozycji planu. Dodaj pierwszą lub zaimportuj z CSV.</div>
    <?php endif; ?>
    <?php $curr_n = count($curr_items); foreach ($curr_items as $i => $it): ?>
    <div class="list-group-item <?= (int)$it['is_active']?'':'opacity-50' ?>">
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="badge bg-light text-dark border"><?= $i+1 ?></span>
        <?php if (trim((string)$it['section']) !== ''): ?><span class="badge badge-soft"><?= h($it['section']) ?></span><?php endif; ?>
        <span class="fw-semibold"><?= h($it['title']) ?></span>
        <?php if (!(int)$it['is_active']): ?><span class="badge bg-secondary">ukryte</span><?php endif; ?>
        <?php if ((int)$it['est_minutes'] > 0): ?><span class="text-body-secondary small"><i class="bi bi-clock me-1"></i><?= (int)$it['est_minutes'] ?> min</span><?php endif; ?>
        <div class="ms-auto d-flex gap-1">
          <form method="post" class="d-inline"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="curr_move"><input type="hidden" name="course_id" value="<?= $cur_course ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="dir" value="up">
            <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="W górę" <?= $i===0?'disabled':'' ?>><i class="bi bi-arrow-up"></i></button></form>
          <form method="post" class="d-inline"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="curr_move"><input type="hidden" name="course_id" value="<?= $cur_course ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="dir" value="down">
            <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="W dół" <?= $i===$curr_n-1?'disabled':'' ?>><i class="bi bi-arrow-down"></i></button></form>
          <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-bs-toggle="modal" data-bs-target="#currEd<?= (int)$it['id'] ?>"><i class="bi bi-pencil me-1"></i>Edytuj</button>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć pozycję planu? Powiązania z lekcjami zostaną usunięte.')"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="curr_delete"><input type="hidden" name="course_id" value="<?= $cur_course ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
            <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń"><i class="bi bi-trash"></i></button></form>
        </div>
      </div>
      <?php if (trim((string)$it['description']) !== ''): ?><div class="small text-body-secondary mt-1"><?= nl2br(h($it['description'])) ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php
  // Wymagania i kryteria z sylabusa przedmiotu — do wglądu, prowadzi je administracja
  $syl_ref_collapsed = false;
  $syl_ref_title     = 'Wymagania i kryteria oceniania';
  include __DIR__ . '/_syllabus_ref.php';
?>

<div class="card">
  <div class="card-header d-flex align-items-center flex-wrap gap-2">
    <span>Dodaj tematy formularzem</span>
    <span class="ms-auto small fw-normal text-body-secondary">bez pliku — wpisujesz i zapisujesz</span>
  </div>
  <div class="card-body">
    <p class="small text-body-secondary">
      Wypełnij tyle wierszy, ile potrzebujesz — puste zostaną pominięte. Wymagany jest tylko
      <strong>temat</strong>. „Dział” grupuje tematy (np. <em>Podstawy obsługi</em>), „czas” to
      szacowany czas realizacji w minutach.
    </p>
    <form method="post">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="curr_bulk">
      <input type="hidden" name="course_id" value="<?= (int)$cur_course ?>">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-2" id="currBulkTable">
          <caption class="visually-hidden">Formularz dodawania wielu tematów sylabusa</caption>
          <thead><tr>
            <th scope="col" style="width:2.5rem">#</th>
            <th scope="col" style="width:12rem">Dział</th>
            <th scope="col">Temat <span class="text-danger" aria-hidden="true">*</span></th>
            <th scope="col">Opis</th>
            <th scope="col" style="width:6.5rem">Czas (min)</th>
          </tr></thead>
          <tbody>
            <?php for ($i = 0; $i < 5; $i++): ?>
            <tr>
              <td class="small text-body-secondary"><?= $i + 1 ?></td>
              <td>
                <label class="visually-hidden" for="b_section_<?= $i ?>">Dział, wiersz <?= $i + 1 ?></label>
                <input type="text" class="form-control form-control-sm" id="b_section_<?= $i ?>"
                       name="b_section[<?= $i ?>]" list="currSections" maxlength="200"
                       placeholder="<?= $i === 0 ? 'np. Podstawy obsługi' : '' ?>">
              </td>
              <td>
                <label class="visually-hidden" for="b_title_<?= $i ?>">Temat, wiersz <?= $i + 1 ?></label>
                <input type="text" class="form-control form-control-sm" id="b_title_<?= $i ?>"
                       name="b_title[<?= $i ?>]" maxlength="300"
                       placeholder="<?= $i === 0 ? 'np. Włączanie i logowanie' : '' ?>">
              </td>
              <td>
                <label class="visually-hidden" for="b_desc_<?= $i ?>">Opis, wiersz <?= $i + 1 ?></label>
                <input type="text" class="form-control form-control-sm" id="b_desc_<?= $i ?>"
                       name="b_desc[<?= $i ?>]" maxlength="500"
                       placeholder="<?= $i === 0 ? 'np. Uruchomienie komputera, logowanie' : '' ?>">
              </td>
              <td>
                <label class="visually-hidden" for="b_min_<?= $i ?>">Czas w minutach, wiersz <?= $i + 1 ?></label>
                <input type="number" class="form-control form-control-sm" id="b_min_<?= $i ?>"
                       name="b_min[<?= $i ?>]" min="0" step="5" placeholder="<?= $i === 0 ? '45' : '' ?>">
              </td>
            </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>
      <datalist id="currSections">
        <?php foreach (array_values(array_unique(array_filter(array_map(fn($r) => (string)$r['section'], $curr_items)))) as $_s): ?>
        <option value="<?= h($_s) ?>"></option>
        <?php endforeach; ?>
      </datalist>
      <div class="d-flex gap-2 flex-wrap align-items-center">
        <button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj tematy</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="currBulkAddRow()">
          <i class="bi bi-plus" aria-hidden="true"></i> Kolejny wiersz
        </button>
        <span class="form-text mb-0">Tematy dokładają się na koniec sylabusa; kolejność zmienisz strzałkami w wykazie.</span>
      </div>
    </form>
  </div>
</div>

<script>
// Kolejny wiersz formularza zbiorczego — klonujemy ostatni i czyścimy wartości.
function currBulkAddRow() {
  var tb = document.querySelector('#currBulkTable tbody');
  if (!tb) return;
  var last = tb.rows[tb.rows.length - 1];
  var row  = last.cloneNode(true);
  var n    = tb.rows.length;
  row.cells[0].textContent = String(n + 1);
  row.querySelectorAll('input').forEach(function (inp) {
    var name = inp.getAttribute('name') || '';
    inp.value = '';
    inp.placeholder = '';
    inp.setAttribute('name', name.replace(/\[\d+\]$/, '[' + n + ']'));
    var id = inp.id ? inp.id.replace(/_\d+$/, '_' + n) : '';
    if (id) {
      var lab = row.querySelector('label[for="' + inp.id + '"]');
      inp.id = id;
      if (lab) { lab.setAttribute('for', id); lab.textContent = lab.textContent.replace(/\d+$/, String(n + 1)); }
    }
  });
  tb.appendChild(row);
  var first = row.querySelector('input');
  if (first) first.focus();
}
</script>

<div class="modal fade" id="currAdd" tabindex="-1" aria-labelledby="currAdd_t" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><?php $currFormHtml(null, 'currAdd'); ?></div></div>
</div>
<?php foreach ($curr_items as $it): ?>
<div class="modal fade" id="currEd<?= (int)$it['id'] ?>" tabindex="-1" aria-labelledby="currEd<?= (int)$it['id'] ?>_t" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><?php $currFormHtml($it, 'currEd'.(int)$it['id']); ?></div></div>
</div>
<?php endforeach; ?>
<div class="modal fade" id="currImport" tabindex="-1" aria-labelledby="currImport_t" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op" value="curr_import">
    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
    <div class="modal-header">
      <h5 class="modal-title" id="currImport_t"><i class="bi bi-upload me-2"></i>Wgraj sylabus (CSV)</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <p class="text-body-secondary small mb-2">Kolumny (separator <code>;</code> lub <code>,</code>): <strong>dział; temat; opis; czas_min</strong>. Pierwszy wiersz może być nagłówkiem. Wymagany jest tylko temat.</p>
      <div class="mb-2">
        <a href="syllabus_wzor.php" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz wzór CSV
        </a>
        <span class="form-text ms-1">Otwórz w arkuszu, wpisz swoje tematy, zapisz jako CSV i wgraj poniżej.</span>
      </div>
      <p class="small mb-1 fw-semibold">Przykład zawartości pliku</p>
      <pre class="border rounded p-2 bg-body-tertiary small mb-3" style="white-space:pre-wrap">dział;temat;opis;czas_min
Podstawy obsługi;Włączanie i logowanie;Uruchomienie komputera, logowanie do systemu;45
Podstawy obsługi;Pulpit i okna;Ikony, menu Start, przełączanie okien;30
Czytnik ekranu;Pierwsze uruchomienie NVDA;Skróty klawiszowe, odczyt ekranu;60
Czytnik ekranu;Nawigacja po stronie internetowej;;90</pre>
      <div class="mb-3">
        <label class="form-label fw-semibold" for="curr_csv_file">Plik CSV z sylabusem</label>
        <input type="file" class="form-control" id="curr_csv_file" name="csv_file" accept=".csv,text/csv,text/plain">
        <div class="form-text">Do 2 MB. Pliki z arkusza (Windows-1250, BOM) są przeliczane na UTF-8 automatycznie.</div>
      </div>
      <label class="form-label" for="curr_csv_text">… albo wklej treść</label>
      <textarea class="form-control font-monospace" id="curr_csv_text" name="csv" rows="6" placeholder="Podstawy;Uruchamianie komputera;Włączanie i logowanie;45&#10;Podstawy;Pulpit i okna;;30"></textarea>
      <div class="form-text">Wgrany plik ma pierwszeństwo nad wklejoną treścią. Import <strong>dokłada</strong> pozycje — nie usuwa istniejących.</div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
      <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Importuj</button>
    </div>
  </form></div></div>
</div>
