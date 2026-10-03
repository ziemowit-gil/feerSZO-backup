<?php
/**
 * karty30/ti/dydaktyk/szablonySms.php — Edytor szablonów SMS dla modułu Dydaktyka (TI) — strona kierownika.
 *
 * Lista:  (bez parametrów) — wszystkie szablony z rejestru (grupa „TI — Zajęcia").
 * Edytor: ?key=ti_lesson_reminder — zwykły textarea (SMS = czysty tekst), licznik znaków i segmentów,
 *         wstawianie zmiennych, podgląd z przykładowymi danymi, test wysyłki. Akcje POST: save | reset | test.
 *
 * Ekran mieszkał w administracji (karty30/ti/sms_templates.php); powiadomienia dla kursantów redaguje
 * kierownik, więc stoi tam, gdzie on pracuje — w panelu. Stary adres przekierowuje tutaj.
 * Świadomie NIE obejmuje SMS-ów z hasłami i kodami logowania — patrz komentarz na górze includes/sms_templates.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/includes/sms.php';
require_once dirname(__DIR__, 3) . '/includes/sms_templates.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }        // ekran kierownika
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '') ?: 'Kierownik';
sms_tpl_migrate();

$SELF      = 'szablonySms.php';
$EMAIL_URL = 'szablonyEmail.php';
$reg       = sms_tpl_registry();

// ── POST ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $act = (string)($_POST['_action'] ?? '');
    $key = (string)($_POST['key'] ?? '');

    if (!isset($reg[$key])) {
        flash_set('danger', 'Nieznany szablon SMS.');
        header('Location: ' . $SELF); exit;
    }
    $back = $SELF . '?key=' . urlencode($key);

    if ($act === 'save') {
        $message = trim((string)($_POST['message'] ?? ''));
        $enabled = sms_tpl_is_auto($key) ? !empty($_POST['enabled']) : true;
        if ($message === '') {
            flash_set('danger', 'Treść SMS-a nie może być pusta.');
            header('Location: ' . $back); exit;
        }
        sms_tpl_save($key, $message, $enabled, $uid);
        flash_set('success', 'Szablon „' . ($reg[$key]['label'] ?? $key) . '” zapisany.');
        header('Location: ' . $back); exit;
    }

    if ($act === 'reset') {
        sms_tpl_reset($key);
        flash_set('success', 'Przywrócono domyślną treść szablonu.');
        header('Location: ' . $back); exit;
    }

    if ($act === 'test') {
        $phone = trim((string)($_POST['test_phone'] ?? ''));
        if ($phone === '') {
            flash_set('danger', 'Podaj numer telefonu do testu.');
            header('Location: ' . $back); exit;
        }
        $message = trim((string)($_POST['message'] ?? ''));
        $enabled = sms_tpl_is_auto($key) ? !empty($_POST['enabled']) : true;
        if ($message !== '') sms_tpl_save($key, $message, $enabled, $uid);   // test działa na zapisanej treści (jak dotąd)
        if (!sms_channel_ready()) {
            flash_set('danger', 'Kanał SMS nie jest skonfigurowany — nie można wysłać testu.');
            header('Location: ' . $back); exit;
        }
        $r = sms_tpl_render($key, sms_tpl_sample_vars($key));
        try {
            sms_send($phone, '[TEST] ' . $r['message']);
            flash_set('success', 'Wysłano testowy SMS na: ' . $phone);
        } catch (\Throwable $e) {
            flash_set('danger', 'Błąd wysyłki testu: ' . $e->getMessage());
        }
        header('Location: ' . $back); exit;
    }

    header('Location: ' . $SELF); exit;
}

// ── GET ────────────────────────────────────────────────────────────────────
$key     = (string)($_GET['key'] ?? '');
$is_list = $key === '' || !isset($reg[$key]);
if (!$is_list) { $t = $reg[$key]; $cur = sms_tpl_get($key); $vars = $t['vars']; }

$KP_TITLE  = ($is_list ? 'Szablony SMS' : 'SMS: ' . $t['label']) . ' — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  .tpl-card, body.ti-skin a.tpl-card { display: block; height: 100%; border: 1px solid #adb5bd; border-radius: 6px; padding: .75rem; background: #fff; color: #1b2430; text-decoration: none; transition: border-color .12s, box-shadow .12s; }
  .tpl-card:hover, body.ti-skin a.tpl-card:hover { border-color: #10335c; box-shadow: 0 3px 10px rgba(16,51,92,.12); text-decoration: none; }
  .tpl-ico { flex-shrink: 0; width: 38px; height: 38px; border-radius: 9px; display: flex; align-items: center; justify-content: center; background: #eff6ff; color: #1d3f8f; font-size: 1.1rem; }
  .var-btn { font-family: ui-monospace, Menlo, monospace; font-size: .76rem; background: #eff6ff; color: #0b3a99; border: 1px solid #5b8def; border-radius: 4px; padding: .15rem .45rem; }
  .var-btn:hover { background: #dbeafe; }
  #stPreview { background: #f1f5f9; border-radius: 12px; padding: .9rem 1rem; font-size: .92rem; white-space: pre-wrap; word-break: break-word; max-width: 340px; }
  #st-message { font-family: ui-monospace, Menlo, monospace; font-size: .92rem; }
</style>

<?php $KIER_CUR = 'szablonySms.php'; $KIER_LABEL = 'Szablony SMS';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
<?= flash_html() ?>

<?php if ($is_list): ?>
  <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <h1 class="h4 mb-0 fw-bold"><i class="bi bi-chat-left-text text-primary me-2" aria-hidden="true"></i>Szablony SMS — Dydaktyka (TI)</h1>
    <a href="<?= $EMAIL_URL ?>" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-envelope-paper me-1" aria-hidden="true"></i>Szablony e-mail</a>
  </div>
  <p class="text-body-secondary small mb-4" style="max-width:760px">
    Edytuj treść powiadomień SMS wysyłanych przez moduł Dydaktyka (TI). Zmiany wchodzą w życie natychmiast — bez nadpisania
    używana jest treść domyślna. Wstawiaj zmienne <code>{{nazwa}}</code>, które system podstawia przy wysyłce.
    Nie obejmuje SMS-ów z hasłami i kodami logowania — te zostają na stałe w kodzie ze względów bezpieczeństwa.
  </p>
  <ul class="row g-3 list-unstyled" aria-label="Szablony SMS">
    <?php foreach ($reg as $k => $tp): $c = sms_tpl_get($k); ?>
    <li class="col-md-6 col-xl-4">
      <a href="<?= $SELF ?>?key=<?= urlencode($k) ?>" class="tpl-card">
        <span class="d-flex align-items-start gap-2">
          <span class="tpl-ico" aria-hidden="true"><i class="bi <?= h($tp['icon']) ?>"></i></span>
          <span class="flex-grow-1" style="min-width:0">
            <span class="fw-semibold d-flex align-items-center gap-2 flex-wrap">
              <?= h($tp['label']) ?>
              <?php if ($c['is_custom']): ?><span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">zmieniony</span><?php endif; ?>
              <?php if (!$c['enabled']): ?><span class="badge bg-secondary-subtle text-secondary-emphasis border">wyłączony</span><?php endif; ?>
            </span>
            <span class="d-block text-body-secondary mt-1" style="font-size:.8rem;line-height:1.35"><?= h($tp['description']) ?></span>
            <span class="d-block text-body-secondary mt-1" style="font-size:.74rem"><?= mb_strlen($c['message']) ?> znaków</span>
          </span>
          <i class="bi bi-chevron-right text-body-secondary flex-shrink-0" aria-hidden="true"></i>
        </span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>

<?php else: ?>
  <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
    <a href="<?= $SELF ?>" class="btn btn-sm btn-outline-secondary" aria-label="Wróć do listy szablonów SMS"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
    <h1 class="h5 mb-0 fw-bold"><i class="bi <?= h($t['icon']) ?> text-primary me-1" aria-hidden="true"></i><?= h($t['label']) ?></h1>
    <?php if ($cur['is_custom']): ?><span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">zmieniony</span>
    <?php else: ?><span class="badge bg-light text-body-secondary border">domyślny</span><?php endif; ?>
  </div>
  <p class="text-body-secondary small mb-3"><?= h($t['description']) ?></p>

  <form method="post" id="stForm" action="<?= $SELF ?>?key=<?= urlencode($key) ?>">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="key" value="<?= h($key) ?>">
    <input type="hidden" name="_action" id="stAction" value="save">
    <div class="row g-3">
      <div class="col-lg-7">
        <?php if (sms_tpl_is_auto($key)): ?>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch" name="enabled" id="st-enabled" aria-describedby="st-enabled-help"<?= $cur['enabled'] ? ' checked' : '' ?>>
          <label class="form-check-label" for="st-enabled">Automatyczna wysyłka</label>
          <div class="form-text" id="st-enabled-help">Wyłączenie wstrzymuje automatyczną wysyłkę tego SMS-a.</div>
        </div>
        <?php else: ?>
        <span class="badge bg-light text-body-secondary border mb-2"><i class="bi bi-hand-index me-1" aria-hidden="true"></i>wysyłka ręczna</span>
        <?php endif; ?>

        <label class="form-label fw-semibold" for="st-message">Treść SMS-a</label>
        <textarea name="message" id="st-message" class="form-control" rows="5" maxlength="2000" required aria-describedby="stLen stSegments"><?= h($cur['message']) ?></textarea>
        <div class="d-flex justify-content-between mt-1">
          <span class="small text-body-secondary" id="stLen"></span>
          <span class="small text-body-secondary" id="stSegments"></span>
        </div>

        <fieldset class="border rounded p-2 mt-2">
          <legend class="float-none w-auto px-1 fs-6 fw-semibold mb-1">Zmienne <small class="fw-normal text-body-secondary">— naciśnij, aby wstawić w miejscu kursora</small></legend>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($vars as $name => $meta): ?>
            <button type="button" class="var-btn" data-var="{{<?= h($name) ?>}}" title="<?= h($meta['label']) ?>">{{<?= h($name) ?>}}</button>
            <?php endforeach; ?>
          </div>
          <dl class="mb-0 mt-2 pt-2 border-top small text-body-secondary">
            <?php foreach ($vars as $name => $meta): ?>
            <div><dt class="d-inline fw-normal"><code>{{<?= h($name) ?>}}</code></dt> <dd class="d-inline ms-0">— <?= h($meta['label']) ?></dd></div>
            <?php endforeach; ?>
          </dl>
        </fieldset>

        <div class="d-flex align-items-center gap-2 mt-3 flex-wrap">
          <button type="submit" class="btn btn-success" onclick="document.getElementById('stAction').value='save'"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz szablon</button>
          <div class="input-group input-group-sm ms-auto" style="max-width:300px">
            <span class="input-group-text" aria-hidden="true"><i class="bi bi-send"></i></span>
            <input type="text" name="test_phone" class="form-control" placeholder="numer telefonu do testu" aria-label="Numer telefonu do testu">
            <button type="submit" class="btn btn-outline-primary" onclick="document.getElementById('stAction').value='test'">Wyślij test</button>
          </div>
          <button type="submit" class="btn btn-outline-danger btn-sm"
                  onclick="if(!confirm('Przywrócić domyślną treść? Twoje zmiany zostaną usunięte.')){return false;}document.getElementById('stAction').value='reset'">
            <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Przywróć domyślny
          </button>
        </div>
      </div>

      <div class="col-lg-5">
        <h2 class="h6 fw-semibold text-body-secondary mb-2"><i class="bi bi-phone me-1" aria-hidden="true"></i>Podgląd (z przykładowymi danymi)</h2>
        <div id="stPreview" role="region" aria-label="Podgląd wiadomości SMS"></div>
      </div>
    </div>
  </form>

  <script>
  (function () {
    var SAMPLES = <?= json_encode(sms_tpl_sample_vars($key), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var ta = document.getElementById('st-message');
    function applySamples(txt) {
      return txt.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, function (m, name) {
        return Object.prototype.hasOwnProperty.call(SAMPLES, name) ? SAMPLES[name] : m;
      });
    }
    // Segment SMS: 160 znaków bez polskich diakrytyków (GSM-7), 70 z nimi (UCS-2) — przybliżenie; dokładne liczenie zależy od bramki.
    function updateCounters() {
      var v = ta.value, hasPl = /[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/.test(v), per = hasPl ? 70 : 160, segs = v.length === 0 ? 0 : Math.ceil(v.length / per);
      document.getElementById('stLen').textContent = v.length + ' znaków' + (hasPl ? ' (zawiera polskie znaki → UCS-2)' : '');
      document.getElementById('stSegments').textContent = segs + ' ' + (segs === 1 ? 'segment SMS' : 'segmenty/ów SMS') + ' (~' + per + ' zn./segment)';
      document.getElementById('stPreview').textContent = applySamples(v);
    }
    function insertVar(v) {
      var s = ta.selectionStart, e = ta.selectionEnd;
      ta.value = ta.value.slice(0, s) + v + ta.value.slice(e);
      ta.selectionStart = ta.selectionEnd = s + v.length;
      ta.focus(); updateCounters();
    }
    document.querySelectorAll('.var-btn').forEach(function (b) { b.addEventListener('click', function () { insertVar(b.getAttribute('data-var')); }); });
    ta.addEventListener('input', updateCounters);
    updateCounters();
  })();
  </script>
<?php endif; ?>

</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
