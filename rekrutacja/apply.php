<?php
/**
 * rekrutacja/apply.php — Publiczny formularz aplikacyjny (bez logowania)
 * Wolontariat / etat, upload CV (PDF/DOCX) + listu motywacyjnego, zgoda RODO.
 * Zabezpieczenia: CSRF (token sesyjny), honeypot, limit zgłoszeń per IP/dobę.
 * Widok stylizowany na stronę feer.org.pl (Ubuntu/Montserrat/Pacifico,
 * niebieski #1d68f2, tło #edf3fe).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/branding.php';
require_once dirname(__DIR__) . '/includes/rekrutacja.php';

rekr_migrate();
if (!module_enabled('rekrutacja_enabled')) { http_response_code(404); die('Rekrutacja jest obecnie zamknięta.'); }

$positions = rekr_positions();
$brand     = branding_load();
$org_name  = $brand['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER');
$errors = [];
$sent = false;
$old = ['imie' => '', 'nazwisko' => '', 'email' => '', 'telefon' => '', 'type' => 'wolontariat', 'position_id' => 0, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Honeypot: pole ukryte dla ludzi — bot je wypełni; udaj sukces bez zapisu
    if (trim((string)($_POST['www'] ?? '')) !== '') {
        $sent = true;
    } else {
        // Limit: max 5 zgłoszeń z jednego IP na dobę
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $cnt = (int)(db_one(
            "SELECT COUNT(*) AS c FROM rekr_status_history
             WHERE note LIKE ? AND created_at > ?",
            ['%[ip:' . $ip . ']%', date('Y-m-d H:i:s', time() - 86400)])['c'] ?? 0);
        if ($ip !== '' && $cnt >= 5) {
            $errors[] = 'Przekroczono limit zgłoszeń. Spróbuj ponownie jutro lub napisz do nas e-mailem.';
        } else {
            $old = array_merge($old, array_intersect_key($_POST, $old));
            $res = rekr_application_create($_POST, ['cv' => $_FILES['cv'] ?? [], 'list' => $_FILES['list'] ?? []]);
            if ($res['ok']) {
                // Znacznik IP w notce pierwszego wpisu historii — na potrzeby rate-limitu
                if ($ip !== '') {
                    db_exec("UPDATE rekr_status_history SET note = note || ' [ip:' || ? || ']'
                             WHERE application_id=? AND from_status=''", [$ip, $res['id']]);
                }
                $sent = true;
                $errors = $res['errors']; // ewentualne problemy z plikami — informacyjnie
            } else {
                $errors = $res['errors'];
            }
        }
    }
}
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1d68f2">
<title>Dołącz do nas — <?= h($org_name) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@400;500;700&family=Montserrat:wght@600;700;800&family=Pacifico&display=swap">
<style>
:root {
  --feer-blue:       #1d68f2;
  --feer-blue-dark:  #164eb6;
  --feer-blue-bg:    #edf3fe;
  --feer-green:      #3f9a2d;
  --feer-ink:        #111827;
  --feer-muted:      #5b6472;
  --feer-line:       #d8e2f3;
  --feer-red:        #c31432;
  --feer-red-bg:     #fdecee;
  --radius:          1rem;
}
* { box-sizing: border-box; }
body {
  margin: 0;
  font-family: 'Ubuntu', ui-sans-serif, system-ui, sans-serif;
  color: var(--feer-ink);
  background: var(--feer-blue-bg);
  line-height: 1.55;
}
h1, h2, legend.f-legend { font-family: 'Montserrat', 'Ubuntu', sans-serif; }

.wrap { max-width: 1140px; margin: 0 auto; padding: 1.25rem; }

/* ── Hero (jak sekcje feer.org.pl) ─────────────────────────────────────── */
.hero {
  display: grid;
  grid-template-columns: minmax(0, 5fr) minmax(0, 7fr);
  gap: 1.5rem;
  align-items: start;
  margin-top: 1rem;
}
@media (max-width: 900px) { .hero { grid-template-columns: 1fr; } }

.hero-panel {
  background: linear-gradient(160deg, var(--feer-blue) 0%, var(--feer-blue-dark) 100%);
  color: #fff;
  border-radius: var(--radius);
  padding: 2.25rem 2rem;
  position: sticky;
  top: 1.25rem;
}
@media (max-width: 900px) { .hero-panel { position: static; } }
.hero-panel .accent {
  font-family: 'Pacifico', cursive;
  font-size: 1.35rem;
  color: #cfe0ff;
  display: block;
  margin-bottom: .25rem;
}
.hero-panel h1 { font-size: 1.9rem; font-weight: 800; margin: 0 0 .75rem; line-height: 1.2; }
.hero-panel p  { color: #e3ecff; margin: 0 0 1.25rem; }
.hero-logo { max-height: 56px; max-width: 200px; margin-bottom: 1.25rem; background: #fff; border-radius: .6rem; padding: .4rem .7rem; }
.hero-list { list-style: none; margin: 0; padding: 0; }
.hero-list li { display: flex; gap: .6rem; align-items: flex-start; margin-bottom: .7rem; color: #eef4ff; }
.hero-list li::before { content: '✓'; font-weight: 700; color: #9cc4ff; flex: 0 0 auto; }

/* ── Karta formularza ──────────────────────────────────────────────────── */
.card {
  background: #fff;
  border-radius: var(--radius);
  box-shadow: 0 10px 30px rgba(22, 78, 182, .10);
  padding: 2rem;
}
@media (max-width: 560px) { .card { padding: 1.25rem; } }

.f-section { border: 0; margin: 0 0 1.75rem; padding: 0; }
legend.f-legend {
  font-size: 1.05rem; font-weight: 700; padding: 0; margin-bottom: 1rem;
  display: flex; align-items: center; gap: .6rem; width: 100%;
}
.f-step {
  background: var(--feer-blue); color: #fff; font-size: .85rem; font-weight: 700;
  width: 1.7rem; height: 1.7rem; border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto;
}

.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 560px) { .grid2 { grid-template-columns: 1fr; } }

label.f-label { display: block; font-weight: 500; font-size: .92rem; margin-bottom: .3rem; }
.req { color: var(--feer-red); }
.f-input, .f-select, .f-textarea {
  width: 100%; font: inherit; color: var(--feer-ink);
  border: 1.5px solid var(--feer-line); border-radius: .65rem;
  padding: .65rem .8rem; background: #fff;
  transition: border-color .15s, box-shadow .15s;
}
.f-input:focus, .f-select:focus, .f-textarea:focus, .f-file:focus-within {
  outline: none; border-color: var(--feer-blue);
  box-shadow: 0 0 0 3px rgba(29, 104, 242, .18);
}
.f-hint { font-size: .82rem; color: var(--feer-muted); margin-top: .3rem; }

/* Karty wyboru typu współpracy (prawdziwe radio pod spodem) */
.type-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 560px) { .type-cards { grid-template-columns: 1fr; } }
.type-card input { position: absolute; opacity: 0; }
.type-card span.box {
  display: block; border: 1.5px solid var(--feer-line); border-radius: .8rem;
  padding: 1rem; cursor: pointer; height: 100%;
  transition: border-color .15s, background .15s, box-shadow .15s;
}
.type-card strong { font-family: 'Montserrat', sans-serif; display: block; margin-bottom: .15rem; }
.type-card small { color: var(--feer-muted); }
.type-card input:checked + span.box {
  border-color: var(--feer-blue); background: var(--feer-blue-bg);
  box-shadow: 0 0 0 3px rgba(29, 104, 242, .15);
}
.type-card input:focus-visible + span.box { outline: 3px solid var(--feer-blue); outline-offset: 2px; }

/* Pola plików */
.f-file {
  border: 1.5px dashed var(--feer-line); border-radius: .8rem;
  padding: .9rem; display: flex; align-items: center; gap: .75rem;
  background: #fafcff; cursor: pointer; transition: border-color .15s, background .15s;
}
.f-file:hover { border-color: var(--feer-blue); }
.f-file input { position: absolute; width: 1px; height: 1px; opacity: 0; }
.f-file .ico { font-size: 1.4rem; flex: 0 0 auto; }
.f-file .txt strong { display: block; font-size: .92rem; }
.f-file .txt small { color: var(--feer-muted); }
.f-file.has-file { border-style: solid; border-color: var(--feer-green); background: #f4faf2; }

/* Zgoda + przycisk */
.consent { display: flex; gap: .7rem; align-items: flex-start; font-size: .88rem; color: var(--feer-muted); }
.consent input { width: 1.15rem; height: 1.15rem; margin-top: .15rem; flex: 0 0 auto; accent-color: var(--feer-blue); }
.btn-feer {
  font: inherit; font-weight: 700; font-family: 'Montserrat', sans-serif;
  background: var(--feer-blue); color: #fff; border: 0; border-radius: 999px;
  padding: .85rem 2.4rem; cursor: pointer; font-size: 1.02rem;
  transition: background .15s, transform .1s, box-shadow .15s;
}
.btn-feer:hover { background: var(--feer-blue-dark); transform: translateY(-1px); box-shadow: 0 6px 18px rgba(29,104,242,.35); }
.btn-feer:focus-visible { outline: 3px solid var(--feer-blue-dark); outline-offset: 3px; }

/* Alerty */
.alert { border-radius: .8rem; padding: 1rem 1.25rem; margin-bottom: 1.25rem; }
.alert-error { background: var(--feer-red-bg); border: 1.5px solid #f3c2cb; color: #7a0c20; }
.alert-error ul { margin: .4rem 0 0; padding-left: 1.2rem; }
.alert-warn { background: #fffbeb; border: 1.5px solid #f0e0a8; color: #6d5304; }

/* Ekran podziękowania */
.thanks { text-align: center; padding: 3rem 1.5rem; }
.thanks .mark {
  width: 4.2rem; height: 4.2rem; border-radius: 50%; background: var(--feer-green);
  color: #fff; font-size: 2.2rem; line-height: 4.2rem; margin: 0 auto 1.25rem;
}
.thanks h2 { font-size: 1.6rem; font-weight: 800; margin: 0 0 .5rem; }
.thanks p { color: var(--feer-muted); max-width: 34rem; margin: 0 auto 1rem; }

.page-footer { text-align: center; color: var(--feer-muted); font-size: .85rem; padding: 2rem 1rem 1rem; }
.page-footer a { color: var(--feer-blue); }
</style>
</head>
<body>
<main class="wrap">
  <div class="hero">

    <!-- Panel marki -->
    <aside class="hero-panel" aria-label="O rekrutacji">
      <?php if ($brand['logo_url']): ?>
        <img class="hero-logo" src="<?= h($brand['logo_url']) ?>" alt="Logo <?= h($org_name) ?>">
      <?php endif; ?>
      <span class="accent" aria-hidden="true">Dołącz do nas!</span>
      <h1>Rekrutacja — wolontariat i praca</h1>
      <p><?= h($org_name) ?> tworzą ludzie. Wypełnij formularz, dołącz CV — odezwiemy się z informacją o kolejnych krokach.</p>
      <ul class="hero-list">
        <li>Odpowiadamy na każde zgłoszenie</li>
        <li>Rozmowę umawiamy w dogodnym dla Ciebie terminie</li>
        <li>Twoje dane przetwarzamy wyłącznie na potrzeby rekrutacji</li>
      </ul>
    </aside>

    <!-- Formularz -->
    <div class="card">
      <?php if ($sent): ?>
        <div class="thanks">
          <div class="mark" aria-hidden="true">✓</div>
          <h2>Dziękujemy za zgłoszenie!</h2>
          <p>Twoja aplikacja została przyjęta. Potwierdzenie wyślemy na podany adres e-mail — sprawdź też folder SPAM.</p>
          <?php foreach ($errors as $e): ?>
            <div class="alert alert-warn" role="alert"><?= h($e) ?></div>
          <?php endforeach; ?>
          <p><a href="https://feer.org.pl">← wróć na stronę <?= h($org_name) ?></a></p>
        </div>
      <?php else: ?>

      <?php if ($errors): ?>
        <div class="alert alert-error" role="alert" id="form-errors" tabindex="-1">
          <strong>Nie udało się wysłać zgłoszenia:</strong>
          <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <!-- honeypot: niewidoczne dla ludzi, boty wypełniają -->
        <div style="position:absolute;left:-9999px" aria-hidden="true">
          <label for="www">Strona WWW (zostaw puste)</label>
          <input type="text" id="www" name="www" tabindex="-1" autocomplete="off">
        </div>

        <fieldset class="f-section">
          <legend class="f-legend"><span class="f-step" aria-hidden="true">1</span>Rodzaj współpracy</legend>
          <div class="type-cards">
            <label class="type-card">
              <input type="radio" name="type" value="wolontariat" <?= $old['type'] === 'wolontariat' ? 'checked' : '' ?>>
              <span class="box">
                <strong>💚 Wolontariat</strong>
                <small>Działaj z nami społecznie — na miarę swoich możliwości i czasu.</small>
              </span>
            </label>
            <label class="type-card">
              <input type="radio" name="type" value="etat" <?= $old['type'] === 'etat' ? 'checked' : '' ?>>
              <span class="box">
                <strong>💼 Praca (etat)</strong>
                <small>Aplikuj na stanowisko w zespole fundacji.</small>
              </span>
            </label>
          </div>
          <div style="margin-top:1rem">
            <label class="f-label" for="a-pos">Stanowisko / obszar</label>
            <select class="f-select" id="a-pos" name="position_id">
              <option value="">— wybierz (opcjonalnie) —</option>
              <?php foreach ($positions as $p): ?>
              <option value="<?= (int)$p['id'] ?>" data-type="<?= h($p['type']) ?>" <?= (int)$old['position_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                <?= h($p['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <p class="f-hint">Lista zawęża się do wybranego rodzaju współpracy.</p>
          </div>
        </fieldset>

        <fieldset class="f-section">
          <legend class="f-legend"><span class="f-step" aria-hidden="true">2</span>Twoje dane</legend>
          <div class="grid2">
            <div>
              <label class="f-label" for="a-imie">Imię <span class="req" aria-hidden="true">*</span></label>
              <input type="text" class="f-input" id="a-imie" name="imie" maxlength="100" required value="<?= h($old['imie']) ?>" autocomplete="given-name">
            </div>
            <div>
              <label class="f-label" for="a-nazwisko">Nazwisko <span class="req" aria-hidden="true">*</span></label>
              <input type="text" class="f-input" id="a-nazwisko" name="nazwisko" maxlength="100" required value="<?= h($old['nazwisko']) ?>" autocomplete="family-name">
            </div>
            <div>
              <label class="f-label" for="a-email">E-mail <span class="req" aria-hidden="true">*</span></label>
              <input type="email" class="f-input" id="a-email" name="email" maxlength="200" required value="<?= h($old['email']) ?>" autocomplete="email">
            </div>
            <div>
              <label class="f-label" for="a-telefon">Telefon</label>
              <input type="tel" class="f-input" id="a-telefon" name="telefon" maxlength="30" value="<?= h($old['telefon']) ?>" autocomplete="tel">
            </div>
          </div>
          <div style="margin-top:1rem">
            <label class="f-label" for="a-message">Kilka słów o sobie</label>
            <textarea class="f-textarea" id="a-message" name="message" rows="4" maxlength="10000"
                      placeholder="Czym się zajmujesz, co Cię do nas przyciągnęło, w czym czujesz się dobrze…"><?= h($old['message']) ?></textarea>
          </div>
        </fieldset>

        <fieldset class="f-section">
          <legend class="f-legend"><span class="f-step" aria-hidden="true">3</span>Dokumenty</legend>
          <div class="grid2">
            <label class="f-file" id="ff-cv">
              <span class="ico" aria-hidden="true">📄</span>
              <span class="txt">
                <strong>CV <span class="req" aria-hidden="true">*</span></strong>
                <small data-default="PDF lub DOCX, do 10 MB">PDF lub DOCX, do 10 MB</small>
              </span>
              <input type="file" id="a-cv" name="cv" accept=".pdf,.docx" required aria-label="CV — PDF lub DOCX, do 10 MB, pole wymagane">
            </label>
            <label class="f-file" id="ff-list">
              <span class="ico" aria-hidden="true">✉️</span>
              <span class="txt">
                <strong>List motywacyjny</strong>
                <small data-default="opcjonalnie — PDF lub DOCX">opcjonalnie — PDF lub DOCX</small>
              </span>
              <input type="file" id="a-list" name="list" accept=".pdf,.docx" aria-label="List motywacyjny — opcjonalnie, PDF lub DOCX">
            </label>
          </div>
        </fieldset>

        <fieldset class="f-section">
          <legend class="f-legend"><span class="f-step" aria-hidden="true">4</span>Zgoda i wysyłka</legend>
          <label class="consent">
            <input type="checkbox" name="consent" value="1" required>
            <span>Wyrażam zgodę na przetwarzanie moich danych osobowych zawartych w zgłoszeniu na potrzeby
              procesu rekrutacji prowadzonego przez <?= h($org_name) ?>. <span class="req" aria-hidden="true">*</span></span>
          </label>
          <div style="margin-top:1.5rem">
            <button class="btn-feer">Wyślij zgłoszenie</button>
          </div>
        </fieldset>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <footer class="page-footer">
    <?= h($org_name) ?> · <a href="https://feer.org.pl" rel="noopener">feer.org.pl</a> · Administratorem danych osobowych jest <?= h($org_name) ?>.
  </footer>
</main>

<script>
(function () {
  // Ustaw focus na podsumowaniu błędów (czytniki ekranu + widoczność)
  const errBox = document.getElementById('form-errors');
  if (errBox) errBox.focus();

  // Stanowiska zawężane do wybranego rodzaju współpracy
  const posSel = document.getElementById('a-pos');
  function filterPositions() {
    const type = document.querySelector('input[name=type]:checked')?.value || '';
    let selectedVisible = false;
    posSel.querySelectorAll('option[data-type]').forEach(o => {
      const show = !type || o.dataset.type === type;
      o.hidden = !show;
      o.disabled = !show;
      if (show && o.selected) selectedVisible = true;
    });
    if (!selectedVisible && posSel.selectedOptions[0]?.dataset.type) posSel.value = '';
  }
  document.querySelectorAll('input[name=type]').forEach(r => r.addEventListener('change', filterPositions));
  filterPositions();

  // Pola plików: pokaż nazwę i rozmiar, waliduj wstępnie typ/rozmiar
  const MAX = <?= REKR_MAX_FILE_BYTES ?>;
  [['a-cv', 'ff-cv'], ['a-list', 'ff-list']].forEach(([inputId, wrapId]) => {
    const input = document.getElementById(inputId);
    const wrap  = document.getElementById(wrapId);
    const small = wrap.querySelector('small');
    input.addEventListener('change', () => {
      const f = input.files[0];
      if (!f) { wrap.classList.remove('has-file'); small.textContent = small.dataset.default; return; }
      if (f.size > MAX) {
        input.value = '';
        wrap.classList.remove('has-file');
        small.textContent = 'Plik jest za duży (limit 10 MB) — wybierz mniejszy.';
        return;
      }
      if (!/\.(pdf|docx)$/i.test(f.name)) {
        input.value = '';
        wrap.classList.remove('has-file');
        small.textContent = 'Dozwolone są tylko pliki PDF i DOCX.';
        return;
      }
      wrap.classList.add('has-file');
      small.textContent = f.name + ' (' + Math.round(f.size / 1024) + ' KB)';
    });
  });
})();
</script>
</body>
</html>
