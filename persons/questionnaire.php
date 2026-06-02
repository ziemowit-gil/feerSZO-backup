<?php
/**
 * Publiczny kwestionariusz wolontariusza — bez logowania, dostęp przez token.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/persons.php';

$token  = trim($_GET['token'] ?? '');
$person = $token ? person_by_questionnaire_token($token) : null;

if (!$person) {
?><!DOCTYPE html>
<html lang="pl">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kwestionariusz — nieprawidłowy link</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="text-center p-4">
  <div class="fs-1 mb-3">🔗</div>
  <h4>Nieprawidłowy lub wygasły link</h4>
  <p class="text-muted">Ten link do kwestionariusza jest nieważny lub już został użyty.<br>Skontaktuj się z organizacją, aby otrzymać nowy link.</p>
</div></body></html>
<?php exit; }

$org_name = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
$already_filled = !empty($person['questionnaire_filled_at']);
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already_filled) {
    // Nie używamy csrf_check() — publiczny formularz
    // Walidacja podstawowa
    $d = $_POST;
    if (empty(trim($d['imie_nazwisko'] ?? ''))) $errors[] = 'Imię i nazwisko jest wymagane.';
    if (empty(trim($d['adres'] ?? '')))          $errors[] = 'Adres zamieszkania jest wymagany.';
    if (empty($d['zgoda_rodo'] ?? ''))           $errors[] = 'Wymagana jest zgoda na przetwarzanie danych osobowych.';

    if (!$errors) {
        $allowed = ['imie_nazwisko','pesel','data_urodzenia','email','telefon',
                    'adres','adres_korespondencyjny','seria_nr_dowodu',
                    'urzad_skarbowy','rachunek_bankowy'];
        $save = [];
        foreach ($allowed as $f) {
            $v = trim($d[$f] ?? '');
            $save[$f] = ($v === '') ? null : $v;
        }
        $save['imie_nazwisko'] = trim($d['imie_nazwisko'] ?? '') ?: $person['imie_nazwisko'];
        $save['zgoda_rodo']    = 1;
        $save['questionnaire_filled_at'] = date('Y-m-d H:i:s');
        $save['updated_at']              = date('Y-m-d H:i:s');

        db_update('persons', $save, (int)$person['id']);
        $success = true;
        $person  = array_merge($person, $save);
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kwestionariusz wolontariusza — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box}
body { background: #f1f5f9; font-family: system-ui,-apple-system,sans-serif; }

.q-shell {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* Header */
.q-header {
  background: linear-gradient(135deg,#0f2044 0%,#1d4ed8 100%);
  color: #fff;
  padding: 1.5rem 1.5rem 3.5rem;
  position: relative;
  overflow: hidden;
}
.q-header::after {
  content: '';
  position: absolute;
  width: 300px; height: 300px;
  border-radius: 50%;
  border: 50px solid rgba(255,255,255,.05);
  bottom: -100px; right: -60px;
  pointer-events: none;
}
.q-header-inner { max-width: 680px; margin: 0 auto; position: relative; }
.q-org-label { font-size: .7rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: rgba(255,255,255,.5); margin-bottom: .3rem; }
.q-org-name  { font-size: 1rem; font-weight: 600; color: rgba(255,255,255,.75); margin-bottom: 1.25rem; }
.q-title     { font-size: 1.6rem; font-weight: 800; line-height: 1.2; }
.q-subtitle  { font-size: .9rem; color: rgba(255,255,255,.65); margin-top: .5rem; }

/* Karta formularza */
.q-card-wrap { max-width: 680px; margin: -2rem auto 2rem; padding: 0 1rem; flex: 1; }
.q-card {
  background: #fff;
  border-radius: 1rem;
  box-shadow: 0 4px 24px rgba(0,0,0,.08);
  overflow: hidden;
}
.q-section-header {
  display: flex; align-items: center; gap: .6rem;
  font-weight: 700; font-size: .9rem;
  color: #1e293b;
  padding: 1rem 1.25rem .75rem;
  border-bottom: 1px solid #f1f5f9;
  background: #f8fafc;
}
.q-section-header i { font-size: 1rem; color: #2563eb; }
.q-body { padding: 1.25rem; }

/* Pola */
.form-label { font-size: .81rem; font-weight: 600; color: #374151; margin-bottom: .25rem; }
.form-control, .form-select {
  border-color: #e2e8f0; border-radius: .5rem;
  font-size: .92rem; padding: .55rem .8rem;
}
.form-control:focus, .form-select:focus {
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}
.form-text { font-size: .75rem; color: #94a3b8; }

/* Przyciski */
.btn-submit {
  background: #2563eb; color: #fff; border: none;
  border-radius: .6rem; padding: .8rem 2rem;
  font-weight: 700; font-size: 1rem;
  width: 100%; transition: background .15s, box-shadow .15s;
}
.btn-submit:hover { background: #1d4ed8; box-shadow: 0 3px 10px rgba(37,99,235,.35); }
.btn-submit:disabled { background: #93c5fd; cursor: not-allowed; }

/* Zgoda RODO */
.rodo-box {
  background: #fafaf9; border: 1.5px solid #e7e7e3;
  border-radius: .6rem; padding: 1rem;
  font-size: .83rem; color: #374151;
  line-height: 1.6;
}

/* Pesel helper */
.pesel-derived { font-size: .75rem; color: #64748b; margin-top: .3rem; display: flex; gap: .6rem; flex-wrap: wrap; }
.pesel-chip { background: #f0f7ff; color: #2563eb; border-radius: 2rem; padding: .1rem .6rem; font-weight: 600; }

/* Sukces */
.q-success {
  text-align: center; padding: 3rem 1.5rem;
}
.q-success-icon { font-size: 4rem; color: #22c55e; margin-bottom: 1rem; }

/* Progress bar */
.q-progress { height: 3px; background: #e2e8f0; }
.q-progress-bar { height: 100%; background: linear-gradient(90deg,#2563eb,#38bdf8); transition: width .4s; }

/* Mobile */
@media(max-width:600px) {
  .q-header { padding: 1.2rem 1rem 3rem; }
  .q-title { font-size: 1.3rem; }
  .q-card-wrap { padding: 0 .6rem; }
  .q-body { padding: 1rem; }
}
</style>
</head>
<body>
<div class="q-shell">

  <!-- Header -->
  <div class="q-header">
    <div class="q-header-inner">
      <div class="q-org-label">Platforma NGO</div>
      <div class="q-org-name"><?= h($org_name) ?></div>
      <div class="q-title"><i class="bi bi-clipboard2-heart me-2"></i>Kwestionariusz wolontariusza</div>
      <div class="q-subtitle">Uzupełnij swoje dane, aby mogliśmy przygotować porozumienie wolontariackie.</div>
    </div>
  </div>

  <!-- Karta -->
  <div class="q-card-wrap">
  <div class="q-card">

    <?php if ($success || $already_filled): ?>
    <!-- ══ Sukces ══════════════════════════════════════════════════════════ -->
    <div class="q-success">
      <div class="q-success-icon"><i class="bi bi-patch-check-fill"></i></div>
      <h3 class="fw-bold mb-2">Dziękujemy!</h3>
      <p class="text-muted mb-3">
        Twoje dane zostały zapisane.<br>
        Skontaktujemy się z Tobą w sprawie dalszych kroków.
      </p>
      <?php if ($already_filled && !$success): ?>
      <div class="alert alert-info d-inline-flex align-items-center gap-2 py-2 px-3" style="font-size:.85rem">
        <i class="bi bi-info-circle"></i>
        Formularz został już wcześniej wypełniony
        <?php if ($person['questionnaire_filled_at']): ?>
        dnia <?= date('d.m.Y', strtotime($person['questionnaire_filled_at'])) ?>.
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- ══ Formularz ═══════════════════════════════════════════════════════ -->

    <!-- Pasek postępu -->
    <div class="q-progress"><div class="q-progress-bar" id="progress-bar" style="width:0%"></div></div>

    <?php if ($errors): ?>
    <div class="alert alert-danger mx-3 mt-3 mb-0 py-2" style="font-size:.85rem">
      <i class="bi bi-exclamation-triangle me-1"></i>
      <strong>Sprawdź poniższe pola:</strong>
      <ul class="mb-0 mt-1">
        <?php foreach ($errors as $err): ?>
        <li><?= h($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <form method="post" id="q-form" novalidate>

      <!-- ── Sekcja 1: Dane osobowe ──────────────────────────────────────── -->
      <div class="q-section-header">
        <i class="bi bi-person-vcard"></i>
        <span>1. Dane osobowe</span>
      </div>
      <div class="q-body">
        <div class="row g-3">

          <div class="col-12">
            <label class="form-label">Imię i nazwisko <span class="text-danger">*</span></label>
            <input name="imie_nazwisko" class="form-control"
                   value="<?= h($person['imie_nazwisko'] ?? '') ?>"
                   placeholder="np. Jan Kowalski" required autofocus>
          </div>

          <div class="col-md-6">
            <label class="form-label">PESEL</label>
            <input name="pesel" id="pesel_input" class="form-control font-monospace"
                   maxlength="11" inputmode="numeric"
                   value="<?= h($person['pesel'] ?? '') ?>"
                   placeholder="00000000000">
            <div class="pesel-derived" id="pesel_info" style="display:none">
              <span class="pesel-chip" id="pesel_dob"></span>
              <span class="pesel-chip" id="pesel_gender"></span>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Data urodzenia</label>
            <input name="data_urodzenia" id="data_urodzenia_input" type="date"
                   class="form-control"
                   value="<?= h($person['data_urodzenia'] ?? '') ?>">
          </div>

          <div class="col-md-6">
            <label class="form-label">Seria i numer dowodu osobistego</label>
            <input name="seria_nr_dowodu" class="form-control font-monospace"
                   value="<?= h($person['seria_nr_dowodu'] ?? '') ?>"
                   placeholder="ABC 123456">
          </div>

          <div class="col-md-6">
            <label class="form-label">Numer telefonu</label>
            <input name="telefon" type="tel" class="form-control"
                   value="<?= h($person['telefon'] ?? '') ?>"
                   placeholder="+48 123 456 789">
          </div>

          <div class="col-12">
            <label class="form-label">Adres e-mail</label>
            <input name="email" type="email" class="form-control"
                   value="<?= h($person['email'] ?? '') ?>"
                   placeholder="jan.kowalski@example.pl">
          </div>

        </div>
      </div>

      <!-- ── Sekcja 2: Adres ─────────────────────────────────────────────── -->
      <div class="q-section-header">
        <i class="bi bi-geo-alt"></i>
        <span>2. Adres zamieszkania</span>
      </div>
      <div class="q-body">
        <div class="row g-3">

          <div class="col-12">
            <label class="form-label">Adres zamieszkania <span class="text-danger">*</span></label>
            <textarea name="adres" class="form-control" rows="2"
                      placeholder="ul. Przykładowa 1, 00-001 Warszawa" required><?= h($person['adres'] ?? '') ?></textarea>
            <div class="form-text">Ulica i numer, kod pocztowy, miejscowość</div>
          </div>

          <div class="col-12">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" id="diff_addr_check">
              <label class="form-check-label small fw-semibold" for="diff_addr_check">
                Adres korespondencyjny jest inny niż adres zamieszkania
              </label>
            </div>
            <div id="adres_koresp_wrap" style="display:none">
              <textarea name="adres_korespondencyjny" class="form-control" rows="2"
                        placeholder="ul. Inna 2, 00-002 Warszawa"
                        id="adres_koresp_input"><?= h($person['adres_korespondencyjny'] ?? '') ?></textarea>
            </div>
          </div>

        </div>
      </div>

      <!-- ── Sekcja 3: Dane finansowe ───────────────────────────────────── -->
      <div class="q-section-header">
        <i class="bi bi-bank"></i>
        <span>3. Dane finansowe i podatkowe</span>
      </div>
      <div class="q-body">
        <div class="row g-3">

          <div class="col-12">
            <label class="form-label">Rachunek bankowy</label>
            <input name="rachunek_bankowy" class="form-control font-monospace"
                   value="<?= h($person['rachunek_bankowy'] ?? '') ?>"
                   placeholder="PL XX XXXX XXXX XXXX XXXX XXXX XXXX"
                   maxlength="34" inputmode="numeric"
                   oninput="this.value=this.value.replace(/[^0-9]/g,'')">
            <div class="form-text">Tylko cyfry — bez spacji (26 cyfr dla rachunku polskiego)</div>
          </div>

          <div class="col-md-8">
            <label class="form-label">Właściwy urząd skarbowy</label>
            <input name="urzad_skarbowy" class="form-control"
                   value="<?= h($person['urzad_skarbowy'] ?? '') ?>"
                   placeholder="np. US Warszawa-Bemowo">
          </div>

        </div>
      </div>

      <!-- ── Sekcja 4: Zgody ────────────────────────────────────────────── -->
      <div class="q-section-header">
        <i class="bi bi-shield-check"></i>
        <span>4. Zgody i oświadczenia</span>
      </div>
      <div class="q-body">

        <div class="rodo-box mb-3">
          <strong>Klauzula informacyjna RODO</strong><br>
          Administratorem Twoich danych osobowych jest <?= h($org_name) ?>.
          Dane są przetwarzane w celu zawarcia i realizacji porozumienia wolontariackiego
          na podstawie art. 6 ust. 1 lit. b RODO.
          Dane będą przechowywane przez okres niezbędny do realizacji umowy oraz
          wynikający z przepisów prawa.
          Masz prawo dostępu do danych, ich sprostowania, usunięcia lub ograniczenia przetwarzania.
        </div>

        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" name="zgoda_rodo" id="zgoda_rodo"
                 value="1" required <?= !empty($person['zgoda_rodo']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="zgoda_rodo" style="font-size:.87rem">
            <strong>Wyrażam zgodę</strong> na przetwarzanie moich danych osobowych przez <?= h($org_name) ?>
            w celu zawarcia porozumienia wolontariackiego i prowadzenia rejestru wolontariuszy.
            <span class="text-danger">*</span>
          </label>
        </div>

        <div class="form-check mb-4">
          <input class="form-check-input" type="checkbox" id="oswiadczenie_prawdziwosc">
          <label class="form-check-label" for="oswiadczenie_prawdziwosc" style="font-size:.87rem">
            Oświadczam, że podane przeze mnie dane są zgodne z prawdą.
          </label>
        </div>

        <button type="submit" class="btn-submit" id="submit-btn">
          <i class="bi bi-send-check me-2"></i>Wyślij kwestionariusz
        </button>

        <div class="text-center mt-3 text-muted" style="font-size:.75rem">
          <i class="bi bi-lock-fill me-1"></i>Dane są bezpieczne i szyfrowane (HTTPS)
        </div>

      </div>
    </form>
    <?php endif; ?>

  </div><!-- /q-card -->
  </div><!-- /q-card-wrap -->

  <div class="text-center pb-4 text-muted" style="font-size:.72rem">
    &copy; <?= date('Y') ?> <?= h($org_name) ?> &nbsp;·&nbsp; Platforma NGO
  </div>

</div><!-- /q-shell -->

<script>
// ── PESEL → data urodzenia + płeć ─────────────────────────────────────────
function peselParse(p) {
    p = p.replace(/\D/g,'');
    if (p.length !== 11) return null;
    let y = +p.slice(0,2), m = +p.slice(2,4), d = +p.slice(4,6);
    if      (m>=81&&m<=92) { y+=1800; m-=80; }
    else if (m>=1 &&m<=12) { y+=1900; }
    else if (m>=21&&m<=32) { y+=2000; m-=20; }
    else if (m>=41&&m<=52) { y+=2100; m-=40; }
    else if (m>=61&&m<=72) { y+=2200; m-=60; }
    else return null;
    const gender = (+p[9] % 2 === 0) ? 'Kobieta' : 'Mężczyzna';
    const dob = y + '-' + String(m).padStart(2,'0') + '-' + String(d).padStart(2,'0');
    return { dob, gender };
}

const peselInput = document.getElementById('pesel_input');
const dobInput   = document.getElementById('data_urodzenia_input');
const peselInfo  = document.getElementById('pesel_info');
const peselDob   = document.getElementById('pesel_dob');
const peselGen   = document.getElementById('pesel_gender');

peselInput?.addEventListener('input', function() {
    const parsed = peselParse(this.value);
    if (parsed) {
        dobInput.value = parsed.dob;
        peselDob.textContent = '🎂 ' + parsed.dob.split('-').reverse().join('.');
        peselGen.textContent = parsed.gender === 'Kobieta' ? '♀ Kobieta' : '♂ Mężczyzna';
        peselInfo.style.display = 'flex';
    } else {
        peselInfo.style.display = 'none';
    }
});

// trigger on page load if PESEL already filled
if (peselInput?.value.length === 11) peselInput.dispatchEvent(new Event('input'));

// ── Adres korespondencyjny toggle ────────────────────────────────────────
const diffCheck  = document.getElementById('diff_addr_check');
const korWrap    = document.getElementById('adres_koresp_wrap');
const korInput   = document.getElementById('adres_koresp_input');

// Pokaż jeśli pole już wypełnione
if (korInput?.value.trim()) { diffCheck.checked = true; korWrap.style.display = ''; }

diffCheck?.addEventListener('change', function() {
    korWrap.style.display = this.checked ? '' : 'none';
    if (!this.checked && korInput) korInput.value = '';
});

// ── Pasek postępu (wypełnianie pól) ──────────────────────────────────────
const progressBar = document.getElementById('progress-bar');
const allInputs   = document.querySelectorAll('#q-form input:not([type=hidden]):not([type=checkbox]), #q-form textarea');
function updateProgress() {
    const filled = [...allInputs].filter(el => el.offsetParent !== null && el.value.trim()).length;
    const total  = [...allInputs].filter(el => el.offsetParent !== null).length;
    const pct    = total ? Math.round((filled / total) * 100) : 0;
    if (progressBar) progressBar.style.width = pct + '%';
}
allInputs.forEach(el => el.addEventListener('input', updateProgress));
updateProgress();

// ── Format rachunku bankowego (grupy po 4) ────────────────────────────────
// (tylko walidacja długości)
document.querySelector('[name=rachunek_bankowy]')?.addEventListener('input', function() {
    if (this.value.length > 26) this.value = this.value.slice(0,26);
});
</script>
</body>
</html>
