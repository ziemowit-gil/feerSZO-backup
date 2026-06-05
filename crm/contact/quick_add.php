<?php
/**
 * crm/contact/quick_add.php — Szybki kreator kontaktu (3 kroki).
 * GET ?action_id=X  — preselektuje działanie
 * GET ?group_id=X   — preselektuje grupę
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'CRM');
if (!can_write('crm') && !is_admin()) {
    flash_set('danger','Brak uprawnień.'); header('Location: '.APP_URL.'/crm/dashboard.php'); exit;
}
crm_migrate();

$PAGE_TITLE = 'Szybkie dodawanie kontaktu';

// Preselect
$pre_action_id = (int)($_GET['action_id'] ?? 0);
$pre_group_id  = (int)($_GET['group_id']  ?? 0);

// Dane pomocnicze
$actions = [];
try { $actions = db_all("SELECT id, nazwa, status FROM actions WHERE status NOT IN ('zakończone','anulowane') ORDER BY nazwa"); } catch (\Throwable $e) {}
$groups  = CrmManager::getGroups();

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $imie_nazwisko = trim($_POST['imie_nazwisko'] ?? '');
    $email         = trim($_POST['email'] ?? '') ?: null;
    $telefon       = trim($_POST['telefon'] ?? '') ?: null;
    $type          = in_array($_POST['type']??'', ['osoba','organizacja']) ? $_POST['type'] : 'osoba';
    $organizacja   = trim($_POST['organizacja'] ?? '') ?: null;
    $status        = array_key_exists($_POST['status']??'', crm_statuses()) ? $_POST['status'] : 'prospect';

    $wojewodztwo   = trim($_POST['wojewodztwo'] ?? '') ?: null;
    $powiat        = trim($_POST['powiat'] ?? '') ?: null;
    $gmina         = trim($_POST['gmina'] ?? '') ?: null;

    $action_ids    = array_filter(array_map('intval', (array)($_POST['action_ids'] ?? [])));
    $group_ids     = array_filter(array_map('intval', (array)($_POST['group_ids']  ?? [])));
    $tags_raw      = trim($_POST['tags'] ?? '');

    $errors = [];
    if (!$imie_nazwisko) $errors[] = 'Imię i nazwisko / nazwa jest wymagana.';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidłowy e-mail.';

    if (!$errors) {
        $uid = (int)(current_user()['id'] ?? 0);
        $contact_id = crm_insert('crm_contacts', array_filter([
            'type'          => $type,
            'imie_nazwisko' => $imie_nazwisko,
            'email'         => $email,
            'telefon'       => $telefon,
            'organizacja'   => $organizacja,
            'status'        => $status,
            'wojewodztwo'   => $wojewodztwo,
            'powiat'        => $powiat,
            'gmina'         => $gmina,
            'source'        => 'manual',
            'crm_active'    => 1,
            'created_by'    => $uid,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ], fn($v) => $v !== null));

        // Tagi
        if ($tags_raw) {
            $tags = array_unique(array_filter(array_map('trim', preg_split('/[,\s]+/', $tags_raw))));
            foreach ($tags as $tag) {
                try { crm_insert('crm_tags', ['contact_id'=>$contact_id,'tag'=>$tag,'created_at'=>date('Y-m-d H:i:s')]); } catch (\Throwable $e) {}
            }
        }

        // Grupy
        foreach ($group_ids as $gid) {
            try { crm_insert('crm_group_members', ['group_id'=>$gid,'contact_id'=>$contact_id,'added_by'=>$uid,'added_at'=>date('Y-m-d H:i:s')]); } catch (\Throwable $e) {}
        }

        // Działania
        foreach ($action_ids as $aid) {
            try { CrmManager::linkToAction($contact_id, $aid, current_user(), null, null); } catch (\Throwable $e) {}
        }

        flash_set('success', "Kontakt <strong>" . h($imie_nazwisko) . "</strong> dodany.");

        // Redirect zależnie od kontekstu
        if (!empty($_POST['_add_another'])) {
            $back = APP_URL . '/crm/contact/quick_add.php';
            if ($pre_action_id) $back .= '?action_id=' . $pre_action_id;
            elseif ($pre_group_id) $back .= '?group_id=' . $pre_group_id;
            header('Location: ' . $back); exit;
        }
        header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $contact_id); exit;
    }
}

include __DIR__ . '/../includes/header_crm.php';
?>
<style>
.qw-step { display:none; }
.qw-step.active { display:block; }
.qw-step-nav { display:flex; gap:0; margin-bottom:1.5rem; }
.qw-step-item {
  flex:1; text-align:center; padding:.5rem .25rem;
  font-size:.78rem; font-weight:600; color:#9CA3AF;
  border-bottom:3px solid #E5E7EB; transition:all .15s; cursor:pointer;
}
.qw-step-item.done  { color:var(--crm-primary); border-color:var(--crm-primary); }
.qw-step-item.active { color:var(--crm-primary); border-color:var(--crm-primary); font-size:.82rem; }
.qw-step-item .step-num {
  width:22px; height:22px; border-radius:50%; background:#E5E7EB; color:#6B7280;
  display:inline-flex; align-items:center; justify-content:center;
  font-size:.72rem; font-weight:700; margin-right:.35rem;
}
.qw-step-item.done .step-num, .qw-step-item.active .step-num {
  background:var(--crm-primary); color:#fff;
}
</style>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb mb-0 small">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php">Kontakty</a></li>
  <li class="breadcrumb-item active">Szybkie dodawanie</li>
</ol></nav>

<div style="max-width:620px">

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon"><i class="bi bi-person-plus-fill"></i></div>
  <div>
    <h1 class="crm-object-title">Szybkie dodawanie kontaktu</h1>
    <div class="crm-object-count">3-krokowy kreator — dane, lokalizacja, powiązania</div>
  </div>
  <div class="crm-object-actions">
    <a href="<?= APP_URL ?>/crm/contact/add.php" class="btn btn-crm-outline btn-sm">
      <i class="bi bi-list-columns me-1"></i>Pełny formularz
    </a>
  </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger py-2 px-3 small mb-3">
  <?php foreach ($errors as $e): ?><div><i class="bi bi-exclamation-triangle me-1"></i><?= h($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Nawigacja kroków -->
<div class="qw-step-nav" id="stepNav">
  <div class="qw-step-item active" data-step="1" onclick="QW.go(1)">
    <span class="step-num">1</span>Dane kontaktu
  </div>
  <div class="qw-step-item" data-step="2" onclick="QW.go(2)">
    <span class="step-num">2</span>Lokalizacja
  </div>
  <div class="qw-step-item" data-step="3" onclick="QW.go(3)">
    <span class="step-num">3</span>Powiązania
  </div>
</div>

<form method="post" id="qwForm" novalidate>
  <?= csrf_field() ?>

  <!-- ── KROK 1: Dane kontaktu ───────────────────────────────── -->
  <div class="qw-step active" id="step-1">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">

        <!-- Typ: osoba / org -->
        <div class="d-flex gap-2 mb-3">
          <label class="flex-1 text-center border rounded p-2" style="cursor:pointer;flex:1"
                 id="type_osoba_lbl">
            <input type="radio" name="type" value="osoba" class="d-none" checked
                   onchange="QW.setType('osoba')">
            <i class="bi bi-person-fill d-block" style="font-size:1.4rem;color:var(--crm-primary)"></i>
            <span class="small fw-semibold">Osoba</span>
          </label>
          <label class="flex-1 text-center border rounded p-2" style="cursor:pointer;flex:1"
                 id="type_org_lbl">
            <input type="radio" name="type" value="organizacja" class="d-none"
                   onchange="QW.setType('organizacja')">
            <i class="bi bi-building-fill d-block" style="font-size:1.4rem;color:#7F2B8B"></i>
            <span class="small fw-semibold">Organizacja</span>
          </label>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold small" id="name_label">Imię i nazwisko <span class="text-danger">*</span></label>
          <input type="text" name="imie_nazwisko" class="form-control"
                 value="<?= h($_POST['imie_nazwisko'] ?? '') ?>"
                 placeholder="np. Jan Kowalski" required autofocus>
        </div>

        <div id="org_field" style="display:none" class="mb-3">
          <label class="form-label small fw-semibold">Osoba kontaktowa</label>
          <input type="text" name="organizacja" class="form-control form-control-sm"
                 value="<?= h($_POST['organizacja'] ?? '') ?>" placeholder="Opcjonalnie">
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">E-mail</label>
            <input type="email" name="email" class="form-control form-control-sm"
                   value="<?= h($_POST['email'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Telefon</label>
            <input type="tel" name="telefon" class="form-control form-control-sm"
                   value="<?= h($_POST['telefon'] ?? '') ?>">
          </div>
        </div>

        <div class="mt-3">
          <label class="form-label small fw-semibold">Status</label>
          <div class="d-flex flex-wrap gap-1">
            <?php foreach (crm_statuses() as $sk => $sv): ?>
            <label class="badge fw-normal border" style="cursor:pointer;padding:.35em .75em;
                   background:<?= ($_POST['status']??'prospect')===$sk ? $sv['color'] : '#f8fafc' ?>;
                   color:<?= ($_POST['status']??'prospect')===$sk ? '#fff' : '#374151' ?>;
                   border-color:<?= $sv['color'] ?>!important">
              <input type="radio" name="status" value="<?= h($sk) ?>" class="d-none"
                     <?= ($_POST['status']??'prospect')===$sk ? 'checked' : '' ?>
                     onchange="this.closest('.d-flex').querySelectorAll('label').forEach(l=>{l.style.background=l.querySelector('input').value===this.value?'<?= $sv['color'] ?>':'#f8fafc';l.style.color=l.querySelector('input').value===this.value?'#fff':'#374151'})">
              <?= h($sv['label']) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="d-flex justify-content-end">
      <button type="button" class="btn btn-crm-primary" onclick="QW.go(2)">
        Dalej: Lokalizacja <i class="bi bi-arrow-right ms-1"></i>
      </button>
    </div>
  </div>

  <!-- ── KROK 2: Lokalizacja ─────────────────────────────────── -->
  <div class="qw-step" id="step-2">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title mb-3">Terytorium</div>
        <div class="row g-3">
          <div class="col-md-5">
            <label class="form-label small fw-semibold">Województwo</label>
            <select name="wojewodztwo" id="sel_woj" class="form-select form-select-sm">
              <option value="">— wybierz —</option>
              <?php
              $woj_db = db_all("SELECT kod_woj, nazwa FROM teryt_units WHERE level=1 ORDER BY nazwa");
              foreach ($woj_db as $w):
              ?>
              <option value="<?= h($w['nazwa']) ?>"
                      data-kod="<?= h($w['kod_woj']) ?>"
                      <?= ($pre_woj ?? $_POST['wojewodztwo'] ?? '') === $w['nazwa'] ? 'selected' : '' ?>>
                <?= h(ucfirst($w['nazwa'])) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold">Powiat</label>
            <select name="powiat" id="sel_pow" class="form-select form-select-sm">
              <option value="">— wybierz województwo —</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-semibold">Gmina / miejscowość</label>
            <input type="text" name="gmina" id="inp_gmi" class="form-control form-control-sm"
                   value="<?= h($_POST['gmina'] ?? '') ?>"
                   placeholder="Wpisz gminę…">
          </div>
        </div>
        <p class="text-muted small mt-2 mb-0">
          <i class="bi bi-info-circle me-1"></i>
          Powiaty i gminy są dostępne po
          <a href="<?= APP_URL ?>/admin/teryt_import.php" target="_blank">imporcie danych TERYT</a>.
        </p>
      </div>
    </div>
    <div class="d-flex justify-content-between">
      <button type="button" class="btn btn-outline-secondary" onclick="QW.go(1)">
        <i class="bi bi-arrow-left me-1"></i>Wstecz
      </button>
      <button type="button" class="btn btn-crm-primary" onclick="QW.go(3)">
        Dalej: Powiązania <i class="bi bi-arrow-right ms-1"></i>
      </button>
    </div>
  </div>

  <!-- ── KROK 3: Powiązania ──────────────────────────────────── -->
  <div class="qw-step" id="step-3">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">

        <!-- Działania -->
        <?php if ($actions): ?>
        <div class="mb-3">
          <label class="form-label small fw-semibold">
            <i class="bi bi-calendar-event me-1" style="color:var(--crm-primary)"></i>Przypisz do działania
          </label>
          <div style="max-height:180px;overflow-y:auto;border:1px solid #E5E7EB;border-radius:6px;padding:.5rem">
            <?php foreach ($actions as $a): ?>
            <label class="d-flex align-items-center gap-2 py-1 px-1 rounded" style="cursor:pointer;font-size:.83rem">
              <input type="checkbox" name="action_ids[]" value="<?= (int)$a['id'] ?>"
                     class="form-check-input mt-0"
                     <?= $pre_action_id === (int)$a['id'] ? 'checked' : '' ?>>
              <span><?= h($a['nazwa']) ?></span>
              <span class="badge bg-light text-dark border ms-auto" style="font-size:.65rem"><?= h($a['status']) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php else: ?>
        <div class="text-muted small mb-3">
          <i class="bi bi-info-circle me-1"></i>Brak aktywnych działań.
          <a href="<?= APP_URL ?>/strategy/actions/index.php">Utwórz działanie →</a>
        </div>
        <?php endif; ?>

        <!-- Grupy CRM -->
        <?php if ($groups): ?>
        <div class="mb-3">
          <label class="form-label small fw-semibold">
            <i class="bi bi-people-fill me-1" style="color:var(--crm-primary)"></i>Dodaj do grupy CRM
          </label>
          <div class="d-flex flex-wrap gap-1">
            <?php foreach ($groups as $g): ?>
            <label class="badge fw-normal border" style="cursor:pointer;padding:.35em .75em;background:#f8fafc;color:#374151;border-color:<?= h($g['color']) ?>!important">
              <input type="checkbox" name="group_ids[]" value="<?= (int)$g['id'] ?>"
                     class="d-none"
                     <?= $pre_group_id === (int)$g['id'] ? 'checked' : '' ?>>
              <i class="bi <?= h($g['icon']) ?>" style="color:<?= h($g['color']) ?>"></i>
              <?= h($g['name']) ?>
            </label>
            <?php endforeach; ?>
          </div>
          <script>
          document.querySelectorAll('[name="group_ids[]"]').forEach(chk => {
            const lbl = chk.closest('label');
            function syncStyle() {
              const color = lbl.style.borderColor.replace('!important','');
              lbl.style.background = chk.checked ? color : '#f8fafc';
              lbl.style.color = chk.checked ? '#fff' : '#374151';
            }
            syncStyle();
            chk.addEventListener('change', syncStyle);
          });
          </script>
        </div>
        <?php endif; ?>

        <!-- Tagi -->
        <div>
          <label class="form-label small fw-semibold">
            <i class="bi bi-tags me-1" style="color:var(--crm-primary)"></i>Tagi
          </label>
          <input type="text" name="tags" class="form-control form-control-sm"
                 placeholder="wolontariusz, darczyńca, vip — oddziel przecinkiem"
                 value="<?= h($_POST['tags'] ?? '') ?>">
        </div>
      </div>
    </div>

    <div class="d-flex justify-content-between gap-2 flex-wrap">
      <button type="button" class="btn btn-outline-secondary" onclick="QW.go(2)">
        <i class="bi bi-arrow-left me-1"></i>Wstecz
      </button>
      <div class="d-flex gap-2">
        <button type="submit" name="_add_another" value="1" class="btn btn-outline-primary">
          <i class="bi bi-plus-circle me-1"></i>Zapisz i dodaj kolejny
        </button>
        <button type="submit" class="btn btn-crm-primary">
          <i class="bi bi-check-lg me-1"></i>Zapisz i otwórz kartę
        </button>
      </div>
    </div>
  </div>
</form>
</div>

<script src="<?= APP_URL ?>/assets/js/teryt_cascade.js"></script>
<script>
// ── Kreator kroków ──────────────────────────────────────────────────────────
const QW = (function() {
  let current = <?= !empty($errors) ? 1 : 1 ?>;

  function go(n) {
    // Walidacja kroku 1 przed przejściem dalej
    if (current === 1 && n > 1) {
      const name = document.querySelector('[name="imie_nazwisko"]');
      if (!name.value.trim()) { name.focus(); name.classList.add('is-invalid'); return; }
      name.classList.remove('is-invalid');
    }
    document.querySelectorAll('.qw-step').forEach(s => s.classList.remove('active'));
    document.getElementById('step-'+n).classList.add('active');
    document.querySelectorAll('.qw-step-item').forEach(s => {
      const sn = parseInt(s.dataset.step);
      s.classList.remove('active','done');
      if (sn === n) s.classList.add('active');
      if (sn < n) s.classList.add('done');
    });
    current = n;
    window.scrollTo({top:0,behavior:'smooth'});
  }

  function setType(type) {
    document.getElementById('name_label').innerHTML = type === 'osoba'
      ? 'Imię i nazwisko <span class="text-danger">*</span>'
      : 'Nazwa firmy / organizacji <span class="text-danger">*</span>';
    document.getElementById('org_field').style.display = type === 'organizacja' ? '' : 'none';
    document.querySelector('[name="imie_nazwisko"]').placeholder = type === 'osoba' ? 'np. Jan Kowalski' : 'np. Fundacja XYZ';
    document.getElementById('type_osoba_lbl').style.borderColor = type==='osoba'?'var(--crm-primary)':'';
    document.getElementById('type_org_lbl').style.borderColor   = type==='organizacja'?'#7F2B8B':'';
  }

  return { go, setType };
})();

// ── Kaskada TERYT ───────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  // Zamień woj-select — używa nazwy a nie kodu, więc mapujemy przez data-kod
  const wojSel = document.getElementById('sel_woj');
  const powSel = document.getElementById('sel_pow');
  const gmiInp = document.getElementById('inp_gmi');
  const API    = '<?= APP_URL ?>/crm/api/teryt.php';

  wojSel.addEventListener('change', function() {
    const kod = this.options[this.selectedIndex]?.dataset?.kod || '';
    powSel.innerHTML = '<option value="">Ładowanie…</option>';
    gmiInp.value = '';
    if (!kod) { powSel.innerHTML = '<option value="">— wybierz województwo —</option>'; return; }
    fetch(`${API}?action=powiaty&woj=${encodeURIComponent(kod)}`)
      .then(r=>r.json()).then(rows=>{
        powSel.innerHTML = rows.length
          ? '<option value="">— wybierz powiat —</option>' + rows.map(r=>`<option value="${r.name}">${r.label}</option>`).join('')
          : '<option value="">(brak danych — zaimportuj TERYT)</option>';
      });
  });

  // Powiat → autocomplete dla gminy
  let gmiTimer = null, gmiDD = null;
  function showGmiDropdown(rows) {
    if (gmiDD) gmiDD.remove();
    if (!rows.length) return;
    gmiDD = document.createElement('div');
    gmiDD.style.cssText = 'position:absolute;z-index:1055;background:#fff;border:1px solid #E5E7EB;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.1);min-width:200px;max-height:200px;overflow-y:auto;left:0;top:calc(100% + 2px)';
    rows.slice(0,30).forEach(r=>{
      const d=document.createElement('div');
      d.style.cssText='padding:.35rem .7rem;cursor:pointer;font-size:.82rem';
      d.textContent=r.label;
      d.onmousedown=()=>{ gmiInp.value=r.name; if(gmiDD)gmiDD.remove(); };
      d.onmouseover=()=>d.style.background='#F3F4F6';
      d.onmouseout=()=>d.style.background='';
      gmiDD.appendChild(d);
    });
    gmiInp.parentElement.style.position='relative';
    gmiInp.parentElement.appendChild(gmiDD);
  }

  gmiInp.addEventListener('input', function() {
    clearTimeout(gmiTimer);
    const q = this.value.trim();
    const powNazwa = powSel.value;
    if (q.length < 2) { if(gmiDD)gmiDD.remove(); return; }
    gmiTimer = setTimeout(()=>{
      if (powNazwa) {
        // Szukaj w obrębie powiatu
        const wojKod = wojSel.options[wojSel.selectedIndex]?.dataset?.kod || '';
        // Pobierz gminy powiatu i filtruj
        const powOpt = [...powSel.options].find(o=>o.value===powNazwa);
        // Użyj search endpoint
        fetch(`${API}?action=search&q=${encodeURIComponent(q)}&level=3`)
          .then(r=>r.json()).then(rows=>showGmiDropdown(rows));
      } else {
        fetch(`${API}?action=search&q=${encodeURIComponent(q)}&level=3`)
          .then(r=>r.json()).then(rows=>showGmiDropdown(rows));
      }
    }, 220);
  });
  gmiInp.addEventListener('blur', ()=>setTimeout(()=>{ if(gmiDD)gmiDD.remove(); }, 200));
});
</script>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
