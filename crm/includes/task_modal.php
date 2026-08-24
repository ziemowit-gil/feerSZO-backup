<?php
/**
 * crm/includes/task_modal.php — okno „Zleć zadanie".
 *
 * Jedno okno na cały moduł: kartoteka, sprawa i ekran zadań otwierają to samo,
 * różnią się tylko kontekstem wpisanym w pola ukryte. Formularz idzie POST-em
 * do crm/tasks.php, które po zapisie wraca pod adres z pola `back` — dzięki temu
 * zlecenie zadania nie wyrzuca nikogo z miejsca, w którym pracował.
 *
 * Zmienne opcjonalne przed include:
 *   $tm_contact_id, $tm_case_id — kontekst zadania
 *   $tm_title_hint              — podpowiedź w polu tytułu
 *
 * Zadania CRM to NIE są zadania modułu Zadań — zob. includes/crm_tasks.php.
 */
require_once dirname(dirname(__DIR__)) . '/includes/crm_tasks.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_owner_rules.php';

$tm_contact_id = (int)($tm_contact_id ?? 0);
$tm_case_id    = (int)($tm_case_id    ?? 0);
$tm_people     = crm_owner_candidates();
$tm_me         = (int)(current_user()['id'] ?? 0);
?>
<style>
.tm-pick { position:relative }
.tm-hits { position:absolute; left:0; right:0; top:100%; z-index:5; background:#fff;
  border:1px solid var(--crm-border); border-radius:8px; margin-top:2px; max-height:190px;
  overflow-y:auto; box-shadow:0 6px 18px rgba(0,0,0,.08) }
.tm-hit { display:block; width:100%; text-align:left; border:none; background:none;
  padding:.35rem .6rem; font-size:.83rem; color:#111827; cursor:pointer }
.tm-hit:hover, .tm-hit.is-on { background:var(--crm-primary-bg); color:var(--crm-primary) }
.tm-hit small { color:#6B7280; display:block; font-size:.72rem }
.tm-none { padding:.4rem .6rem; font-size:.78rem; color:#6B7280 }
</style>

<div class="modal fade" id="crmTaskModal" tabindex="-1" aria-labelledby="crmTaskModalLbl" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= APP_URL ?>/crm/tasks.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" value="add">
      <input type="hidden" name="contact_id" value="<?= $tm_contact_id ?: '' ?>">
      <input type="hidden" name="case_id"    value="<?= $tm_case_id ?: '' ?>">
      <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">

      <div class="modal-header py-2">
        <h5 class="modal-title" id="crmTaskModalLbl" style="font-size:.95rem">
          <i class="bi bi-person-up me-2" style="color:var(--crm-primary)" aria-hidden="true"></i>Zleć zadanie
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>

      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label small fw-semibold mb-1" for="crmTaskTitle">Co jest do zrobienia <span class="text-danger">*</span></label>
          <input class="form-control form-control-sm" id="crmTaskTitle" name="title" required maxlength="300"
                 placeholder="<?= h($tm_title_hint ?? 'np. Oddzwonić w sprawie oferty') ?>">
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold mb-1" for="crmTaskWho">Komu</label>
          <?php /* Wpisywanie zamiast rozwijania listy: przy kilkudziesięciu osobach
                   szukanie nazwiska w selekcie trwa dłużej niż napisanie trzech liter.
                   Lista jest wpisana w stronę (kilkadziesiąt pozycji), więc filtrowanie
                   idzie bez zapytania do serwera — bez opóźnienia i bez migotania. */ ?>
          <div class="tm-pick">
            <input type="text" class="form-control form-control-sm" id="crmTaskWho"
                   placeholder="Wpisz imię lub nazwisko… (puste = mnie)" autocomplete="off"
                   role="combobox" aria-expanded="false" aria-controls="crmTaskWhoList" aria-autocomplete="list">
            <input type="hidden" name="owner_id" id="crmTaskOwner" value="<?= $tm_me ?>">
            <div class="tm-hits" id="crmTaskWhoList" role="listbox" aria-label="Osoby z dostępem do CRM" hidden></div>
          </div>
          <div class="form-text" style="font-size:.72rem">
            Tylko osoby z dostępem do CRM — zadanie zlecone komuś, kto nie otworzy kartoteki,
            nie zostanie zrobione. Zlecone zadanie pojawi się w kolejce tej osoby.
          </div>
        </div>

        <div class="row g-2">
          <div class="col-7">
            <label class="form-label small fw-semibold mb-1" for="crmTaskDue">Termin</label>
            <input type="date" class="form-control form-control-sm" id="crmTaskDue" name="due_date">
          </div>
          <div class="col-5">
            <label class="form-label small fw-semibold mb-1" for="crmTaskPrio">Pilność</label>
            <select class="form-select form-select-sm" id="crmTaskPrio" name="priority">
              <?php foreach (CRM_TASK_PRIORITIES as $pk => $pv): ?>
              <option value="<?= h($pk) ?>" <?= $pk === 'medium' ? 'selected' : '' ?>><?= h($pv['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="mt-3">
          <label class="form-label small fw-semibold mb-1" for="crmTaskDesc">Szczegóły</label>
          <textarea class="form-control form-control-sm" id="crmTaskDesc" name="description" rows="2"
                    placeholder="Nieobowiązkowe — co trzeba wiedzieć, żeby to zrobić"></textarea>
        </div>

        <?php if ($tm_case_id || $tm_contact_id): ?>
        <p class="text-muted mb-0 mt-3" style="font-size:.74rem">
          <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>
          Zadanie zostanie powiązane z <?= $tm_case_id ? 'tą sprawą' : 'tą kartoteką' ?> — widać je będzie w obu miejscach.
        </p>
        <?php endif; ?>
      </div>

      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-crm-outline" data-bs-dismiss="modal">Anuluj</button>
        <button class="btn btn-sm btn-crm-primary"><i class="bi bi-check-lg me-1"></i>Zleć</button>
      </div>
    </form>
  </div>
</div>

<script>
/* Wyszukiwarka osoby w oknie „Zleć zadanie".
   Filtruje listę wpisaną w stronę — bez zapytań do serwera, bo osób z dostępem
   do CRM są dziesiątki, nie tysiące. Obsługa klawiatury (strzałki, Enter, Esc)
   jest obowiązkowa: to pole leży w torze Tab między tytułem a terminem. */
(function () {
  var box = document.getElementById('crmTaskModal');
  if (!box || box.dataset.pickBound) return;
  box.dataset.pickBound = '1';

  var PEOPLE = <?= json_encode(array_map(
      static fn($u) => ['id' => (int)$u['id'], 'name' => (string)$u['name'], 'email' => (string)($u['email'] ?? '')],
      $tm_people), JSON_UNESCAPED_UNICODE) ?>;
  var ME = <?= $tm_me ?>;

  var inp  = document.getElementById('crmTaskWho');
  var hid  = document.getElementById('crmTaskOwner');
  var list = document.getElementById('crmTaskWhoList');
  var cur  = -1, shown = [];

  function norm(s) {
    return String(s || '').toLowerCase()
      .replace(/[ąćęłńóśźż]/g, function (c) { return 'acelnoszz'['ąćęłńóśźż'.indexOf(c)]; });
  }

  function close() { list.hidden = true; cur = -1; inp.setAttribute('aria-expanded', 'false'); }

  function paint(q) {
    var nq = norm(q);
    shown = nq === '' ? PEOPLE.slice(0, 8)
                      : PEOPLE.filter(function (u) { return norm(u.name).indexOf(nq) > -1 || norm(u.email).indexOf(nq) > -1; }).slice(0, 8);
    if (!shown.length) {
      list.innerHTML = '<div class="tm-none">Nikt taki nie ma dostępu do CRM.</div>';
    } else {
      list.innerHTML = shown.map(function (u, i) {
        return '<button type="button" class="tm-hit" role="option" data-i="' + i + '">' +
               String(u.name).replace(/</g, '&lt;') +
               (u.email ? '<small>' + String(u.email).replace(/</g, '&lt;') + '</small>' : '') +
               '</button>';
      }).join('');
    }
    list.hidden = false;
    inp.setAttribute('aria-expanded', 'true');
    cur = -1;
  }

  function pick(i) {
    var u = shown[i];
    if (!u) return;
    inp.value = u.name;
    hid.value = u.id;
    close();
  }

  function mark() {
    Array.prototype.forEach.call(list.querySelectorAll('.tm-hit'), function (b, i) {
      b.classList.toggle('is-on', i === cur);
    });
  }

  inp.addEventListener('input', function () {
    // Puste pole = zadanie dla siebie; inaczej zlecenie wisiałoby bez adresata
    if (inp.value.trim() === '') hid.value = ME;
    paint(inp.value);
  });
  inp.addEventListener('focus', function () { paint(inp.value); });

  inp.addEventListener('keydown', function (e) {
    if (list.hidden) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); cur = Math.min(cur + 1, shown.length - 1); mark(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); cur = Math.max(cur - 1, 0); mark(); }
    else if (e.key === 'Enter' && cur > -1) { e.preventDefault(); pick(cur); }
    else if (e.key === 'Escape') { close(); }
  });

  list.addEventListener('click', function (e) {
    var b = e.target.closest('.tm-hit');
    if (b) pick(parseInt(b.dataset.i, 10));
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.tm-pick')) close();
  });

  // Po zamknięciu okna wracamy do stanu „dla mnie" — inaczej kolejne zlecenie
  // dziedziczyłoby adresata z poprzedniego, czego nikt się nie spodziewa
  box.addEventListener('hidden.bs.modal', function () {
    inp.value = ''; hid.value = ME; close();
  });
})();
</script>
