<?php
/**
 * rekrutacja/zapisy.php — Uproszczony nabór na zajęcia (bez logowania)
 * Zapis na wiele zajęć naraz, bez priorytetów (checkboxy). Integracja z CRM
 * (upsert kontaktu + historia interakcji) odbywa się w rekr_enrollment_create().
 * Zabezpieczenia: CSRF (token sesyjny), honeypot, limit zgłoszeń per IP/dobę.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/branding.php';
require_once dirname(__DIR__) . '/includes/rekrutacja.php';
require_once dirname(__DIR__) . '/modules/gdpr_clauses/logic/gdpr_clauses.php';

rekr_migrate();
if (!module_enabled('rekrutacja_enabled')) { http_response_code(404); die('Zapisy na zajęcia są obecnie zamknięte.'); }

$courses  = rekr_positions(true, 'zajecia');
$brand    = branding_load();
$org_name = $brand['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER');
$errors = [];
$sent = false;
$old = ['imie' => '', 'nazwisko' => '', 'email' => '', 'telefon' => '', 'message' => '',
        'rodzaj_niepelnosprawnosci' => '', 'wymagane_dostosowania' => '', 'courses' => []];
$result_courses = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Honeypot: pole ukryte dla ludzi — bot je wypełni; udaj sukces bez zapisu
    if (trim((string)($_POST['www'] ?? '')) !== '') {
        $sent = true;
    } else {
        // Limit: max 5 zgłoszeń z jednego IP na dobę (wspólny licznik z rekr_status_history)
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $cnt = (int)(db_one(
            "SELECT COUNT(*) AS c FROM rekr_status_history
             WHERE note LIKE ? AND created_at > ?",
            ['%[ip:' . $ip . ']%', date('Y-m-d H:i:s', time() - 86400)])['c'] ?? 0);
        if ($ip !== '' && $cnt >= 5) {
            $errors[] = 'Przekroczono limit zgłoszeń. Spróbuj ponownie jutro lub napisz do nas e-mailem.';
        } else {
            $old = array_merge($old, array_intersect_key($_POST, $old));
            $old['courses'] = array_map('intval', (array)($_POST['courses'] ?? []));
            $res = rekr_enrollment_create($_POST, $old['courses']);
            if ($res['ok']) {
                if ($ip !== '') {
                    db_exec("UPDATE rekr_status_history SET note = note || ' [ip:' || ? || ']'
                             WHERE application_id=? AND from_status=''", [$ip, $res['id']]);
                }
                $sent = true;
                $result_courses = $res['courses'];
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
<title>Zapisy na zajęcia — <?= h($org_name) ?></title>
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

.hero {
  display: grid;
  grid-template-columns: minmax(0, 5fr) minmax(0, 7fr);
  gap: 1.5rem;
  align-items: start;
  margin-top: 1rem;
}
@media (max-width: 900px) { .hero { grid-template-columns: 1fr; } }

.hero-panel {
  background: var(--feer-blue);
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
.hero-panel p  { color: #fff; margin: 0 0 1.25rem; }
.hero-logo { max-height: 56px; max-width: 200px; margin-bottom: 1.25rem; background: #fff; border-radius: .6rem; padding: .4rem .7rem; }
.hero-list { list-style: none; margin: 0; padding: 0; }
.hero-list li { display: flex; gap: .6rem; align-items: flex-start; margin-bottom: .7rem; color: #fff; }
.hero-list li::before { content: '✓'; font-weight: 700; color: #bcd4ff; flex: 0 0 auto; }

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
.f-input:focus, .f-select:focus, .f-textarea:focus {
  outline: none; border-color: var(--feer-blue);
  box-shadow: 0 0 0 3px rgba(29, 104, 242, .18);
}
.f-hint { font-size: .82rem; color: var(--feer-muted); margin-top: .3rem; }

/* Lista kursów — checkboxy jako karty, bez priorytetów */
.course-list { display: grid; gap: .7rem; }
.course-card { position: relative; }
.course-card input { position: absolute; opacity: 0; }
.course-card span.box {
  display: flex; gap: .75rem; align-items: flex-start;
  border: 1.5px solid var(--feer-line); border-radius: .8rem;
  padding: .9rem 1rem; cursor: pointer;
  transition: border-color .15s, background .15s, box-shadow .15s;
}
.course-card span.box .chk {
  width: 1.2rem; height: 1.2rem; border-radius: .3rem; border: 1.5px solid var(--feer-line);
  flex: 0 0 auto; margin-top: .1rem; display: inline-flex; align-items: center; justify-content: center;
  color: #fff; font-size: .8rem;
}
.course-card strong { font-family: 'Montserrat', sans-serif; display: block; }
.course-card small { color: var(--feer-muted); }
.course-card input:checked + span.box {
  border-color: var(--feer-blue); background: var(--feer-blue-bg);
  box-shadow: 0 0 0 3px rgba(29, 104, 242, .15);
}
.course-card input:checked + span.box .chk { background: var(--feer-blue); border-color: var(--feer-blue); }
.course-card input:checked + span.box .chk::after { content: '✓'; }
.course-card input:focus-visible + span.box { outline: 3px solid var(--feer-blue); outline-offset: 2px; }

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

.alert { border-radius: .8rem; padding: 1rem 1.25rem; margin-bottom: 1.25rem; }
.alert-error { background: var(--feer-red-bg); border: 1.5px solid #f3c2cb; color: #7a0c20; }
.alert-error ul { margin: .4rem 0 0; padding-left: 1.2rem; }

.thanks { text-align: center; padding: 3rem 1.5rem; }
.thanks .mark {
  width: 4.2rem; height: 4.2rem; border-radius: 50%; background: var(--feer-green);
  color: #fff; font-size: 2.2rem; line-height: 4.2rem; margin: 0 auto 1.25rem;
}
.thanks h2 { font-size: 1.6rem; font-weight: 800; margin: 0 0 .5rem; }
.thanks p { color: var(--feer-muted); max-width: 34rem; margin: 0 auto 1rem; }
.thanks ul.res { list-style: none; margin: 0 auto 1rem; padding: 0; max-width: 26rem; text-align: left; }
.thanks ul.res li { padding: .4rem 0; border-bottom: 1px solid var(--feer-line); }
.thanks .badge-rez { color: #92650a; font-weight: 600; }

.page-footer { text-align: center; color: var(--feer-muted); font-size: .85rem; padding: 2rem 1rem 1rem; }
.page-footer a { color: var(--feer-blue); }
</style>
</head>
<body>
<main class="wrap">
  <div class="hero">

    <aside class="hero-panel" aria-label="O zapisach na zajęcia">
      <?php if ($brand['logo_url']): ?>
        <img class="hero-logo" src="<?= h($brand['logo_url']) ?>" alt="Logo <?= h($org_name) ?>">
      <?php endif; ?>
      <span class="accent" aria-hidden="true">Zapisz się!</span>
      <h1>Zapisy na zajęcia i warsztaty</h1>
      <p><?= h($org_name) ?> prowadzi zajęcia dostępne również dla osób z niepełnosprawnościami. Zaznacz
        wszystkie zajęcia, które Cię interesują — bez ustalania kolejności ważności.</p>
      <ul class="hero-list">
        <li>Zaznacz dowolną liczbę zajęć</li>
        <li>Jeśli miejsc zabraknie, trafisz na listę rezerwową</li>
        <li>Poinformuj nas o potrzebnych dostosowaniach</li>
        <li>Twoje dane przetwarzamy wyłącznie na potrzeby zapisów</li>
      </ul>
    </aside>

    <div class="card">
      <?php if ($sent): ?>
        <div class="thanks">
          <div class="mark" aria-hidden="true">✓</div>
          <h2>Dziękujemy za zgłoszenie!</h2>
          <p>Zapisaliśmy Cię na wybrane zajęcia. Potwierdzenie wyślemy na podany adres e-mail — sprawdź też folder SPAM.</p>
          <?php if ($result_courses): ?>
          <ul class="res">
            <?php foreach ($result_courses as $c): ?>
            <li><?= h($c['name']) ?> —
              <?= $c['status'] === 'rezerwa' ? '<span class="badge-rez">lista rezerwowa</span>' : '<strong>zapisano</strong>' ?>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
          <p><a href="https://feer.org.pl">← wróć na stronę <?= h($org_name) ?></a></p>
        </div>
      <?php else: ?>

      <?php if ($errors): ?>
        <div class="alert alert-error" role="alert" id="form-errors" tabindex="-1">
          <strong>Nie udało się zapisać zgłoszenia:</strong>
          <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div style="position:absolute;left:-9999px" aria-hidden="true">
          <label for="www">Strona WWW (zostaw puste)</label>
          <input type="text" id="www" name="www" tabindex="-1" autocomplete="off">
        </div>

        <fieldset class="f-section">
          <legend class="f-legend"><span class="f-step" aria-hidden="true">1</span>Wybierz zajęcia</legend>
          <?php if (!$courses): ?>
            <p class="f-hint">Obecnie brak otwartych zapisów. Sprawdź ponownie później.</p>
          <?php else: ?>
          <div class="course-list" role="group" aria-label="Lista zajęć do wyboru">
            <?php foreach ($courses as $c): ?>
            <label class="course-card">
              <input type="checkbox" name="courses[]" value="<?= (int)$c['id'] ?>"
                     <?= in_array((int)$c['id'], $old['courses'], true) ? 'checked' : '' ?>>
              <span class="box">
                <span class="chk" aria-hidden="true"></span>
                <span>
                  <strong><?= h($c['name']) ?></strong>
                  <?php if ($c['description'] !== ''): ?><small><?= h($c['description']) ?></small><?php endif; ?>
                </span>
              </span>
            </label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </fieldset>

        <fieldset class="f-section">
          <legend class="f-legend"><span class="f-step" aria-hidden="true">2</span>Twoje dane</legend>
          <div class="grid2">
            <div>
              <label class="f-label" for="z-imie">Imię <span class="req" aria-hidden="true">*</span></label>
              <input type="text" class="f-input" id="z-imie" name="imie" maxlength="100" required value="<?= h($old['imie']) ?>" autocomplete="given-name">
            </div>
            <div>
              <label class="f-label" for="z-nazwisko">Nazwisko <span class="req" aria-hidden="true">*</span></label>
              <input type="text" class="f-input" id="z-nazwisko" name="nazwisko" maxlength="100" required value="<?= h($old['nazwisko']) ?>" autocomplete="family-name">
            </div>
            <div>
              <label class="f-label" for="z-email">E-mail <span class="req" aria-hidden="true">*</span></label>
              <input type="email" class="f-input" id="z-email" name="email" maxlength="200" required value="<?= h($old['email']) ?>" autocomplete="email">
            </div>
            <div>
              <label class="f-label" for="z-telefon">Telefon</label>
              <input type="tel" class="f-input" id="z-telefon" name="telefon" maxlength="30" value="<?= h($old['telefon']) ?>" autocomplete="tel">
            </div>
          </div>
        </fieldset>

        <fieldset class="f-section">
          <legend class="f-legend"><span class="f-step" aria-hidden="true">3</span>Dostępność (opcjonalnie)</legend>
          <div class="grid2">
            <div>
              <label class="f-label" for="z-niepelnosprawnosc">Rodzaj niepełnosprawności</label>
              <input type="text" class="f-input" id="z-niepelnosprawnosc" name="rodzaj_niepelnosprawnosci" maxlength="200"
                     placeholder="np. wzrokowa, ruchowa — zostaw puste, jeśli nie dotyczy" value="<?= h($old['rodzaj_niepelnosprawnosci']) ?>">
            </div>
            <div>
              <label class="f-label" for="z-dostosowania">Wymagane dostosowania</label>
              <input type="text" class="f-input" id="z-dostosowania" name="wymagane_dostosowania" maxlength="2000"
                     placeholder="np. tłumacz PJM, dostęp dla wózka" value="<?= h($old['wymagane_dostosowania']) ?>">
            </div>
          </div>
          <div style="margin-top:1rem">
            <label class="f-label" for="z-message">Uwagi (opcjonalnie)</label>
            <textarea class="f-textarea" id="z-message" name="message" rows="3" maxlength="2000"><?= h($old['message']) ?></textarea>
          </div>
        </fieldset>

        <fieldset class="f-section">
          <legend class="f-legend"><span class="f-step" aria-hidden="true">4</span>Zgoda i wysyłka</legend>
          <label class="consent">
            <input type="checkbox" name="consent" value="1" required>
            <span>Wyrażam zgodę na przetwarzanie moich danych osobowych zawartych w zgłoszeniu na potrzeby
              zapisów na zajęcia prowadzonych przez <?= h($org_name) ?>. <span class="req" aria-hidden="true">*</span></span>
          </label>
          <div style="margin-top:1.5rem">
            <button class="btn-feer">Zapisz mnie</button>
          </div>
        </fieldset>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <footer class="page-footer">
    <?= h($org_name) ?> · <a href="https://feer.org.pl" rel="noopener">feer.org.pl</a> · Administratorem danych osobowych jest <?= h($org_name) ?>.<?= gdpr_clauses_footer_link() ?>
  </footer>
</main>

<script>
(function () {
  const errBox = document.getElementById('form-errors');
  if (errBox) errBox.focus();
})();
</script>
</body>
</html>
