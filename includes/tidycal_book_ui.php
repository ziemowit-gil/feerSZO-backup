<?php
/**
 * includes/tidycal_book_ui.php — Wspólny widżet kreatora „Umów się na szkolenie".
 * Dołączany przez panel/szkolenie.php oraz szkolenia/index.php.
 *
 * Oczekiwane zmienne (ustawia strona dołączająca):
 *   $tc_self            string  — bazowy URL strony (bez query) — form action + AJAX
 *   $tc_ctx             array   — ['default_name','default_email','source']
 *   $tc_user_bookings   array   — rezerwacje użytkownika (tidycal_user_bookings)
 *   $tc_fallback_type   int     — ID typu do osadzenia (z $_GET['fallback']) lub 0
 */

$tc_types     = tidycal_exposed_types();
$tc_intro     = trim(org_setting('tidycal_intro'));
$tc_def_name  = (string)($tc_ctx['default_name']  ?? '');
$tc_def_email = (string)($tc_ctx['default_email'] ?? '');
$tc_source    = (string)($tc_ctx['source'] ?? 'panel');
$tc_fb_type   = isset($tc_fallback_type) ? (int)$tc_fallback_type : 0;
$tc_fb        = $tc_fb_type ? tidycal_exposed_type($tc_fb_type) : null;
?>
<?= flash_html() ?>

<?php if (!tidycal_enabled()): ?>
  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i>
    Moduł rezerwacji szkoleń nie jest jeszcze skonfigurowany. Skontaktuj się z administratorem.
  </div>
<?php elseif (!$tc_types): ?>
  <div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    Obecnie nie udostępniono żadnych typów szkoleń do rezerwacji. Zajrzyj później.
  </div>
<?php else: ?>

  <?php if ($tc_intro !== ''): ?>
  <div class="card border-0 shadow-sm mb-3"><div class="card-body py-3">
    <?= nl2br(h($tc_intro)) ?>
  </div></div>
  <?php endif; ?>

  <?php if ($tc_fb): ?>
  <!-- Fallback: osadzona strona TidyCal -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold d-flex align-items-center gap-2">
      <i class="bi bi-box-arrow-up-right text-primary"></i>
      Rezerwacja na stronie TidyCal — <?= h($tc_fb['title']) ?>
      <a href="<?= $tc_self ?>" class="btn btn-sm btn-outline-secondary ms-auto">
        <i class="bi bi-arrow-left"></i> Wróć do kreatora
      </a>
    </div>
    <div class="card-body p-0">
      <?php if (!empty($tc_fb['public_url'])): ?>
      <iframe src="<?= h($tc_fb['public_url']) ?>" title="Rezerwacja TidyCal"
              style="width:100%;height:760px;border:0;display:block"
              loading="lazy"></iframe>
      <?php else: ?>
      <div class="p-3 text-muted">Brak publicznego adresu strony rezerwacji dla tego szkolenia.</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ═══ KREATOR REZERWACJI ═══════════════════════════════════════════════ -->
  <div class="card border-0 shadow-sm mb-4" id="tcWizard">
    <div class="card-body">

      <!-- Krok 1: wybór typu szkolenia -->
      <div class="mb-1 fw-semibold small text-uppercase text-muted" style="letter-spacing:.04em">
        <span class="badge rounded-pill bg-primary me-1">1</span> Wybierz szkolenie
      </div>
      <div class="row g-2 mt-1" id="tcTypes">
        <?php foreach ($tc_types as $t): ?>
        <div class="col-12 col-md-6">
          <button type="button" class="tc-type w-100 text-start"
                  data-id="<?= (int)$t['id'] ?>"
                  data-duration="<?= (int)$t['duration'] ?>"
                  data-title="<?= h($t['title']) ?>"
                  data-url="<?= h($t['public_url']) ?>">
            <div class="fw-semibold"><?= h($t['title']) ?></div>
            <div class="small text-muted">
              <?php if ((int)$t['duration'] > 0): ?><i class="bi bi-clock"></i> <?= (int)$t['duration'] ?> min<?php endif; ?>
              <?php if ($t['description'] !== ''): ?> · <?= h(mb_substr($t['description'], 0, 90)) ?><?php endif; ?>
            </div>
          </button>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Krok 2: wybór dnia + terminu -->
      <div id="tcStep2" class="mt-4" hidden>
        <div class="mb-2 fw-semibold small text-uppercase text-muted" style="letter-spacing:.04em">
          <span class="badge rounded-pill bg-primary me-1">2</span> Wybierz dzień i godzinę
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap mb-3">
          <label class="small fw-semibold mb-0">Dzień:</label>
          <input type="date" id="tcDate" class="form-control form-control-sm" style="max-width:180px">
          <a href="#" id="tcOpenTidyCal" target="_blank" rel="noopener"
             class="btn btn-sm btn-outline-secondary ms-auto" hidden>
            <i class="bi bi-box-arrow-up-right"></i> Otwórz w TidyCal
          </a>
        </div>
        <div id="tcSlots" class="d-flex flex-wrap gap-2"></div>
        <div id="tcSlotsMsg" class="text-muted small mt-2"></div>
      </div>

      <!-- Krok 3: potwierdzenie -->
      <form method="post" id="tcConfirm" class="mt-4" hidden>
        <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
        <input type="hidden" name="_tc_book" value="1">
        <input type="hidden" name="type"      id="tcType">
        <input type="hidden" name="starts_at" id="tcStarts">
        <input type="hidden" name="timezone"  id="tcTz" value="Europe/Warsaw">

        <div class="mb-2 fw-semibold small text-uppercase text-muted" style="letter-spacing:.04em">
          <span class="badge rounded-pill bg-primary me-1">3</span> Potwierdź dane
        </div>
        <div class="alert alert-light border d-flex align-items-center gap-2 py-2">
          <i class="bi bi-calendar2-check text-primary"></i>
          <span id="tcSummary" class="small"></span>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-sm-6">
            <label class="form-label fw-semibold small">Imię i nazwisko <span class="text-danger">*</span></label>
            <input name="name" class="form-control form-control-sm" required value="<?= h($tc_def_name) ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold small">E-mail <span class="text-danger">*</span></label>
            <input name="email" type="email" class="form-control form-control-sm" required value="<?= h($tc_def_email) ?>">
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-check2-circle"></i> Zarezerwuj termin
        </button>
        <button type="button" class="btn btn-link btn-sm text-muted" id="tcReset">Zmień wybór</button>
      </form>

    </div>
  </div>

<?php endif; ?>

<!-- ═══ MOJE REZERWACJE ═══════════════════════════════════════════════════ -->
<?php if (!empty($tc_user_bookings)): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check text-primary"></i> Moje rezerwacje</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr>
        <th class="small">Szkolenie</th><th class="small">Termin</th><th class="small">Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($tc_user_bookings as $b): ?>
        <tr>
          <td class="small fw-semibold"><?= h($b['booking_type_title']) ?></td>
          <td class="small"><?= h(tidycal_fmt_dt($b['starts_at'], $b['timezone'] ?? 'Europe/Warsaw')) ?></td>
          <td><span class="badge bg-success-subtle text-success"><?= h($b['status']) ?></span></td>
          <td class="text-end">
            <?php if (!empty($b['meeting_url'])): ?>
            <a href="<?= h($b['meeting_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary py-0">
              <i class="bi bi-camera-video"></i> Dołącz
            </a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<style>
.tc-type{border:1.5px solid #e2e8f0;border-radius:10px;background:#fff;padding:.7rem .9rem;transition:all .12s;cursor:pointer}
.tc-type:hover{border-color:#a855f7;background:#faf5ff}
.tc-type.tc-on{border-color:#7c3aed;background:#f5f3ff;box-shadow:0 0 0 2px rgba(124,58,237,.15)}
.tc-slot{border:1px solid #cbd5e1;background:#fff;border-radius:8px;padding:.35rem .7rem;font-size:.85rem;cursor:pointer;transition:all .1s}
.tc-slot:hover{border-color:#7c3aed;background:#f5f3ff}
.tc-slot.tc-on{background:#7c3aed;border-color:#7c3aed;color:#fff}
</style>
<script>
(function(){
  var wiz = document.getElementById('tcWizard');
  if(!wiz) return;
  var AJAX = <?= json_encode($tc_self) ?>;
  var sel = {id:0,duration:0,title:'',url:''};
  var step2 = document.getElementById('tcStep2');
  var confirm = document.getElementById('tcConfirm');
  var dateInp = document.getElementById('tcDate');
  var slotsBox = document.getElementById('tcSlots');
  var slotsMsg = document.getElementById('tcSlotsMsg');
  var openTC = document.getElementById('tcOpenTidyCal');
  var tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Warsaw';
  document.getElementById('tcTz').value = tz;

  function pad(n){return (n<10?'0':'')+n;}
  function fmt(iso){
    try{ var d=new Date(iso);
      return d.toLocaleString('pl-PL',{weekday:'short',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
    }catch(e){return iso;}
  }

  // Krok 1 → wybór typu
  wiz.querySelectorAll('.tc-type').forEach(function(btn){
    btn.addEventListener('click',function(){
      wiz.querySelectorAll('.tc-type').forEach(function(b){b.classList.remove('tc-on');});
      btn.classList.add('tc-on');
      sel = {id:+btn.dataset.id, duration:+btn.dataset.duration, title:btn.dataset.title, url:btn.dataset.url};
      document.getElementById('tcType').value = sel.id;
      step2.hidden = false;
      confirm.hidden = true;
      slotsBox.innerHTML=''; slotsMsg.textContent='';
      if(sel.url){ openTC.href = sel.url; openTC.hidden = false; } else { openTC.hidden = true; }
      // domyślnie jutro
      var t=new Date(); t.setDate(t.getDate()+1);
      dateInp.min = (function(){var n=new Date();return n.getFullYear()+'-'+pad(n.getMonth()+1)+'-'+pad(n.getDate());})();
      dateInp.value = t.getFullYear()+'-'+pad(t.getMonth()+1)+'-'+pad(t.getDate());
      loadSlots();
      step2.scrollIntoView({behavior:'smooth',block:'nearest'});
    });
  });

  dateInp && dateInp.addEventListener('change', loadSlots);

  function loadSlots(){
    if(!sel.id || !dateInp.value) return;
    slotsBox.innerHTML=''; slotsMsg.textContent='Ładowanie wolnych terminów…';
    confirm.hidden = true;
    var from = dateInp.value+'T00:00:00';
    var to   = dateInp.value+'T23:59:59';
    var u = AJAX + (AJAX.indexOf('?')>=0?'&':'?') + '_ajax=tc_slots&type='+sel.id+'&from='+encodeURIComponent(from)+'&to='+encodeURIComponent(to);
    fetch(u,{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json();}).then(function(j){
      if(!j.ok){ slotsMsg.innerHTML='<span class="text-danger">'+(j.error||'Błąd pobierania terminów')+'</span>'; return; }
      if(!j.slots.length){ slotsMsg.textContent='Brak wolnych terminów w tym dniu. Wybierz inny dzień.'; return; }
      slotsMsg.textContent='';
      j.slots.forEach(function(s){
        var b=document.createElement('button');
        b.type='button'; b.className='tc-slot';
        var d=new Date(s.starts_at);
        b.textContent=d.toLocaleTimeString('pl-PL',{hour:'2-digit',minute:'2-digit'});
        b.addEventListener('click',function(){
          slotsBox.querySelectorAll('.tc-slot').forEach(function(x){x.classList.remove('tc-on');});
          b.classList.add('tc-on');
          document.getElementById('tcStarts').value = s.starts_at;
          document.getElementById('tcSummary').innerHTML='<strong>'+sel.title+'</strong> — '+fmt(s.starts_at)+' ('+tz+')';
          confirm.hidden=false;
          confirm.scrollIntoView({behavior:'smooth',block:'nearest'});
        });
        slotsBox.appendChild(b);
      });
    }).catch(function(){ slotsMsg.innerHTML='<span class="text-danger">Błąd połączenia z serwerem.</span>'; });
  }

  document.getElementById('tcReset') && document.getElementById('tcReset').addEventListener('click',function(){
    confirm.hidden=true; slotsBox.querySelectorAll('.tc-slot').forEach(function(x){x.classList.remove('tc-on');});
    document.getElementById('tcStarts').value='';
  });
})();
</script>
