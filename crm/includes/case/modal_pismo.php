<?php
/**
 * crm/includes/case/modal_pismo.php — Modal nowego i edytowanego pisma przy sprawie.
 *
 * Wydzielone z crm/cases/view.php: plik miał 2279 wiersze i zmiana w jednej
 * sekcji wymagała przewijania przez wszystkie pozostałe.
 *
 * Włączany przez include w zakresie crm/cases/view.php — korzysta z jego
 * zmiennych ($case, $id, $can_write, ...). Nie wywołuj samodzielnie.
 */
if (!isset($case)) { http_response_code(400); exit; }
?>
<?php if ($can_write): ?>
<!-- ══ MODAL: Nowe / Edytuj pismo ══════════════════════════════════════════ -->
<div class="modal fade" id="modalPismo" tabindex="-1" aria-labelledby="modalPismoLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2 px-3" style="background:#1E3A5F;color:#fff">
        <h5 class="modal-title fw-bold" id="modalPismoLabel" style="font-size:.92rem">
          <i class="bi bi-envelope-plus me-2" id="pismoModalIcon"></i><span id="pismoModalTitle">Nowe pismo do sprawy</span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" enctype="multipart/form-data" id="formPismo">
        <div class="modal-body p-0" style="font-size:.85rem">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" id="pismoAction" value="add_pismo">
          <input type="hidden" name="letter_id" id="pismoLetterId" value="">

          <!-- ── SEKCJA 1: Klasyfikacja ──────────────────────────────── -->
          <div class="px-3 pt-3 pb-2">
            <div class="fw-bold text-uppercase mb-2" style="font-size:.72rem;letter-spacing:.08em;color:#5E6470"><i class="bi bi-tag me-1"></i>Klasyfikacja</div>
            <div class="row g-2">
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Kierunek <span class="text-danger">*</span></label>
                <select name="kierunek" id="p_kierunek" class="form-select form-select-sm" required>
                  <?php foreach (LETTER_DIRECTIONS as $dk => $dv): ?>
                  <option value="<?= $dk ?>"><?= h($dv['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Typ pisma</label>
                <select name="typ_pisma" id="p_typ_pisma" class="form-select form-select-sm">
                  <?php foreach (LETTER_TYPES as $tk => $tv): ?>
                  <option value="<?= $tk ?>"><?= h($tv['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Pilność</label>
                <select name="pilnosc" id="p_pilnosc" class="form-select form-select-sm">
                  <option value="zwykłe">Zwykłe</option>
                  <option value="pilne">Pilne</option>
                  <option value="poufne">Poufne</option>
                  <option value="ściśle_tajne">Ściśle tajne</option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Tytuł / przedmiot <span class="text-danger">*</span></label>
                <input type="text" name="tytul" id="p_tytul" class="form-control form-control-sm"
                       placeholder="np. Wezwanie do złożenia dokumentów" required>
              </div>
            </div>
          </div><hr class="my-0">

          <!-- ── SEKCJA 2: Metadane ──────────────────────────────────── -->
          <div class="px-3 py-2">
            <div class="fw-bold text-uppercase mb-2" style="font-size:.72rem;letter-spacing:.08em;color:#5E6470"><i class="bi bi-info-circle me-1"></i>Metadane pisma</div>
            <div class="row g-2">
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Sygnatura / numer</label>
                <input type="text" name="sygnatura" id="p_sygnatura" class="form-control form-control-sm" placeholder="np. CRM/2026/001">
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Miejsce wystawienia</label>
                <input type="text" name="miejsce" id="p_miejsce" class="form-control form-control-sm"
                       placeholder="np. Warszawa" value="<?= defined('ORG_CITY') ? h(ORG_CITY) : '' ?>">
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold mb-1">Data pisma</label>
                <input type="date" name="data_pisma" id="p_data_pisma" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Sposób doręczenia</label>
                <select name="sposob_doreczenia" id="p_sposob_doreczenia" class="form-select form-select-sm" onchange="pismoToggleDoreczenie(this.value)">
                  <option value="email">E-mail</option>
                  <option value="poczta">Poczta tradycyjna</option>
                  <option value="kurier">Kurier</option>
                  <option value="edoreczenia">eDoręczenia (PURDE)</option>
                  <option value="osobisty">Odbiór osobisty</option>
                  <option value="epuap">eDoręczenia (ePUAP)</option>
                  <option value="fax">Fax</option>
                  <option value="inny">Inny</option>
                </select>
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Termin odpowiedzi</label>
                <input type="date" name="termin_odpowiedzi" id="p_termin_odpowiedzi" class="form-control form-control-sm">
              </div>

              <!-- Pola kurier / poczta -->
              <div class="col-12" id="p_row_nadania" style="display:none">
                <label class="form-label small fw-semibold mb-1">
                  <i class="bi bi-truck me-1 text-warning"></i>Numer nadania / listu przewozowego
                </label>
                <input type="text" name="nr_nadania" id="p_nr_nadania" class="form-control form-control-sm"
                       placeholder="np. PL123456789PL lub numer listu kurierskiego" style="font-family:monospace">
              </div>

              <!-- Pola eDoręczenia -->
              <div id="p_row_edoreczenia" style="display:none" class="col-12">
                <div class="row g-2">
                  <div class="col-sm-6">
                    <label class="form-label small fw-semibold mb-1">
                      <i class="bi bi-shield-check me-1" style="color:#4338CA"></i>Adres eDoręczeń (ADE)
                    </label>
                    <input type="text" name="adres_edoreczenia" id="p_adres_edoreczenia" class="form-control form-control-sm"
                           placeholder="AE:PL-12345-67890-ABCDE-01" style="font-family:monospace;font-size:.8rem">
                  </div>
                  <div class="col-sm-6">
                    <label class="form-label small fw-semibold mb-1">
                      Numer referencyjny wiadomości
                    </label>
                    <input type="text" name="edoreczenia_ref" id="p_edoreczenia_ref" class="form-control form-control-sm"
                           placeholder="ID wiadomości po wysłaniu" style="font-family:monospace;font-size:.8rem">
                  </div>
                </div>
              </div>
            </div>
          </div><hr class="my-0">

          <!-- ── SEKCJA 3: Strony ────────────────────────────────────── -->
          <div class="px-3 py-2">
            <div class="fw-bold text-uppercase mb-2" style="font-size:.72rem;letter-spacing:.08em;color:#5E6470"><i class="bi bi-people me-1"></i>Strony</div>
            <div class="row g-2">
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Nadawca</label>
                <input type="text" name="nadawca" id="p_nadawca" class="form-control form-control-sm"
                       value="<?= defined('ORG_NAME') ? h(ORG_NAME) : '' ?>">
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Odbiorca</label>
                <input type="text" name="odbiorca" id="p_odbiorca" class="form-control form-control-sm"
                       value="<?= h($case['contact_name'] ?? '') ?>">
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">E-mail odbiorcy</label>
                <input type="email" name="odbiorca_email" id="p_odbiorca_email" class="form-control form-control-sm" placeholder="opcjonalnie">
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Kopia do (DW)</label>
                <input type="text" name="kopia_do" id="p_kopia_do" class="form-control form-control-sm" placeholder="imię, e-mail lub dział">
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Podpisujący</label>
                <select name="podpisujacy_id" id="p_podpisujacy_id" class="form-select form-select-sm">
                  <option value="">— nie wskazano —</option>
                  <?php foreach ($users_list as $u): ?>
                  <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div><hr class="my-0">

          <!-- ── SEKCJA 4: Treść ────────────────────────────────────── -->
          <div class="px-3 py-2">
            <div class="fw-bold text-uppercase mb-2" style="font-size:.72rem;letter-spacing:.08em;color:#5E6470"><i class="bi bi-file-text me-1"></i>Treść i załączniki</div>
            <div class="row g-2">
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Podstawa prawna</label>
                <input type="text" name="podstawa_prawna" id="p_podstawa_prawna" class="form-control form-control-sm"
                       placeholder="np. Art. 14 RODO, §5 umowy nr …">
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Treść pisma</label>
                <textarea name="tresc" id="p_tresc" class="form-control form-control-sm" rows="5"
                          placeholder="Treść pisma (opcjonalna)"></textarea>
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">
                  Załącznik <span id="p_plik_hint" class="text-muted fw-normal">(opcjonalny)</span>
                </label>
                <input type="file" name="pismo_plik" id="p_plik" class="form-control form-control-sm"
                       accept=".pdf,.docx,.doc,.png,.jpg,.jpeg">
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Uwagi wewnętrzne</label>
                <input type="text" name="uwagi" id="p_uwagi" class="form-control form-control-sm"
                       placeholder="widoczne tylko wewnętrznie">
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer py-2 bg-light">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-warning btn-sm" id="pismoSubmitBtn">
            <i class="bi bi-envelope-check me-1"></i>Zapisz pismo
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Pokaż/ukryj pola zależne od sposobu doręczenia
function pismoToggleDoreczenie(val) {
  document.getElementById('p_row_nadania').style.display =
    (val === 'kurier' || val === 'poczta') ? '' : 'none';
  document.getElementById('p_row_edoreczenia').style.display =
    val === 'edoreczenia' ? '' : 'none';
}

// Resetuj modal do trybu "Nowe pismo"
document.getElementById('modalPismo').addEventListener('show.bs.modal', function(e) {
  if (e.relatedTarget && e.relatedTarget.dataset.bsTarget) {
    // Otwarto przez przycisk "Nowe pismo" — wyczyść tylko jeśli nie jest edit
    if (!e.relatedTarget.getAttribute('onclick')) {
      pismoReset();
    }
  }
});

function pismoReset() {
  document.getElementById('pismoModalTitle').textContent = 'Nowe pismo do sprawy';
  document.getElementById('pismoModalIcon').className = 'bi bi-envelope-plus me-2';
  document.getElementById('pismoAction').value = 'add_pismo';
  document.getElementById('pismoLetterId').value = '';
  document.getElementById('formPismo').reset();
  // Przywróć domyślne wartości które reset() czyści
  document.getElementById('p_data_pisma').value = '<?= date('Y-m-d') ?>';
  document.getElementById('p_nadawca').value = <?= json_encode(defined('ORG_NAME') ? ORG_NAME : '') ?>;
  document.getElementById('p_odbiorca').value = <?= json_encode($case['contact_name'] ?? '') ?>;
  document.getElementById('p_miejsce').value = <?= json_encode(defined('ORG_CITY') ? ORG_CITY : '') ?>;
  pismoToggleDoreczenie('email');
  document.getElementById('p_plik_hint').textContent = '(opcjonalny)';
  document.getElementById('pismoSubmitBtn').innerHTML = '<i class="bi bi-envelope-check me-1"></i>Zapisz pismo';
}

// Wypełnij modal danymi istniejącego pisma
function pismoEdit(l) {
  pismoReset();
  document.getElementById('pismoModalTitle').textContent = 'Edytuj pismo';
  document.getElementById('pismoModalIcon').className = 'bi bi-pencil-square me-2';
  document.getElementById('pismoAction').value = 'edit_pismo';
  document.getElementById('pismoLetterId').value = l.id;

  var f = function(id, val) {
    var el = document.getElementById('p_' + id);
    if (!el) return;
    if (el.tagName === 'SELECT') {
      el.value = val || '';
    } else if (el.tagName === 'TEXTAREA') {
      el.value = val || '';
    } else {
      el.value = val || '';
    }
  };

  f('kierunek',          l.kierunek);
  f('typ_pisma',         l.typ_pisma);
  f('pilnosc',           l.pilnosc || 'zwykłe');
  f('tytul',             l.tytul);
  f('sygnatura',         l.sygnatura);
  f('miejsce',           l.miejsce);
  f('data_pisma',        l.data_pisma);
  f('sposob_doreczenia', l.sposob_doreczenia || 'email');
  f('termin_odpowiedzi', l.termin_odpowiedzi);
  f('nr_nadania',        l.nr_nadania);
  f('adres_edoreczenia', l.adres_edoreczenia);
  f('edoreczenia_ref',   l.edoreczenia_ref);
  f('nadawca',           l.nadawca);
  f('odbiorca',          l.odbiorca);
  f('odbiorca_email',    l.odbiorca_email);
  f('kopia_do',          l.kopia_do);
  f('podpisujacy_id',    l.podpisujacy_id || '');
  f('podstawa_prawna',   l.podstawa_prawna);
  f('tresc',             l.tresc);
  f('uwagi',             l.uwagi);

  pismoToggleDoreczenie(l.sposob_doreczenia || 'email');

  if (l.plik) {
    document.getElementById('p_plik_hint').textContent = '(pozostaw puste, aby zachować obecny)';
  }
  document.getElementById('pismoSubmitBtn').innerHTML = '<i class="bi bi-floppy me-1"></i>Zapisz zmiany';

  var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPismo'));
  modal.show();
}
</script>
<?php endif; ?>
