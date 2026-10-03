<?php
/**
 * karty30/ti/dydaktyk/szablonyEmail.php — Edytor szablonów e-mail dla modułu Dydaktyka (TI) — strona kierownika.
 *
 * Lista:  (bez parametrów) — szablony grupy „TI — Zajęcia".
 * Edytor: ?key=ti_grade — wizualny edytor Quill + źródło HTML + podgląd na żywo. Akcje POST: save | reset | test.
 *
 * Ekran mieszkał w administracji (karty30/ti/email_templates.php); powiadomienia dla kursantów redaguje
 * kierownik, więc stoi tam, gdzie on pracuje — w panelu. Stary adres przekierowuje tutaj.
 *
 * BEZPIECZEŃSTWO: szablon HTML otwierają potem także edytory administratora (admin/email_templates.php), więc
 * zapis przez kierownika nie może przemycić aktywnej treści. Zapis jest odrzucany (nic się nie zmienia, edytor wraca
 * z wpisaną treścią), gdy HTML zawiera skrypt, ramkę, obiekt, atrybut zdarzenia (onclick…) albo adres javascript:.
 * Podgląd działa w piaskownicy bez skryptów, a Quill ładuje treść po oczyszczeniu w nieaktywnym dokumencie.
 * Treść (także odrzucony szkic) trafia do bloku <script> jako JSON z JSON_HEX_TAG — znaczniki jako \u003C, więc nawet sekwencja
 * „<!--<script>” nie rozbije bloku (nie wolno jej zostawić w komentarzu JS, który trafia do HTML).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
require_once dirname(__DIR__, 3) . '/includes/email_templates.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }        // ekran kierownika
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '') ?: 'Kierownik';
email_tpl_migrate();

$SELF      = 'szablonyEmail.php';
$SMS_URL   = 'szablonySms.php';
$ADMIN_URL = rtrim(APP_URL, '/') . '/admin/email_templates.php';     // wszystkie maile systemowe — tylko administrator SZO
$reg       = email_tpl_registry();
$ti_group  = 'TI — Zajęcia';
$ti_reg    = array_filter($reg, fn($t) => ($t['group'] ?? '') === $ti_group);

/** Wykrywa aktywną treść w HTML szablonu (fail-closed, bez przepisywania). Zwraca opis albo '' gdy czysto. */
function szablon_html_danger(string $html): string
{
    $n = html_entity_decode(str_replace("\0", '', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('~<\s*/?\s*(script|iframe|object|embed|applet|base|frame|frameset)\b~i', $n, $m)) return 'element <' . strtolower($m[1]) . '>';
    if (preg_match('~<\s*meta\b[^>]*http-equiv~i', $n)) return 'przekierowanie <meta http-equiv>';
    if (preg_match('~[\s"\'/]on[a-z]{3,}\s*=~i', $n, $m)) return 'atrybut zdarzenia „' . trim($m[0], " \t\r\n\"'/=") . '”';
    if (preg_match_all('~\b(?:href|src|action|formaction|xlink:href|background)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $n, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $a) {
            $v = strtolower((string)preg_replace('/[\x00-\x20]+/', '', ($a[1] ?? '') . ($a[2] ?? '') . ($a[3] ?? '')));
            if (preg_match('~^(javascript|vbscript|data:text/html)~', $v, $x)) return 'adres „' . $x[1] . '”';
        }
    }
    return '';
}

// ── POST ────────────────────────────────────────────────────────────────────
$draft = null; $draft_err = ''; $post_key = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $act = (string)($_POST['_action'] ?? '');
    $key = (string)($_POST['key'] ?? '');

    if (!isset($ti_reg[$key])) {
        flash_set('danger', 'Nieznany szablon TI.');
        header('Location: ' . $SELF); exit;
    }
    $back = $SELF . '?key=' . urlencode($key);

    if ($act === 'save' || $act === 'test') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body    = (string)($_POST['body'] ?? '');
        $enabled = email_tpl_is_auto($key) ? !empty($_POST['enabled']) : true;
        if ($act === 'save' && $subject === '') {
            flash_set('danger', 'Temat nie może być pusty.');
            header('Location: ' . $back); exit;
        }
        if (($danger = szablon_html_danger($body)) !== '') {
            // nic nie zapisujemy ani nie wysyłamy; edytor wraca z wpisaną treścią
            $post_key = $key; $draft = ['subject' => $subject, 'body' => $body, 'enabled' => $enabled];
            $draft_err = 'Nie zapisano — szablon zawiera niedozwoloną treść aktywną: ' . $danger . '. Usuń ją (skrypty, ramki, atrybuty zdarzeń, adresy javascript:) i zapisz ponownie.';
        } elseif ($act === 'save') {
            email_tpl_save($key, $subject, $body, $enabled, $uid);
            flash_set('success', 'Szablon „' . ($ti_reg[$key]['label'] ?? $key) . '” zapisany.');
            header('Location: ' . $back); exit;
        } else {                                                          // test
            $to = trim((string)($_POST['test_email'] ?? '')) ?: (string)($me['email'] ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                flash_set('danger', 'Podaj poprawny adres e-mail do testu.');
                header('Location: ' . $back); exit;
            }
            if ($subject !== '') email_tpl_save($key, $subject, $body, $enabled, $uid);   // test działa na zapisanej treści (jak dotąd)
            $r = email_tpl_render($key, email_tpl_sample_vars($key));
            try {
                mail_queue_add($to, $to, '[TEST] ' . $r['subject'], $r['html'], '', 'email_tpl_test', null, '', true);
                mail_queue_process();
                flash_set('success', 'Wysłano testowy e-mail na: ' . $to);
            } catch (\Throwable $e) {
                flash_set('danger', 'Błąd wysyłki testu: ' . $e->getMessage());
            }
            header('Location: ' . $back); exit;
        }
    } elseif ($act === 'reset') {
        email_tpl_reset($key);
        flash_set('success', 'Przywrócono domyślną treść szablonu.');
        header('Location: ' . $back); exit;
    } else {
        header('Location: ' . $SELF); exit;
    }
}

// ── GET / ponowne wyświetlenie edytora po odrzuconym zapisie ─────────────────
$key     = $post_key !== '' ? $post_key : (string)($_GET['key'] ?? '');
$is_list = $key === '' || !isset($ti_reg[$key]);
if (!$is_list) {
    $t    = $ti_reg[$key];
    $cur  = email_tpl_get($key);
    if ($draft) { $cur['subject'] = $draft['subject']; $cur['body'] = $draft['body']; $cur['enabled'] = $draft['enabled']; }
    $vars    = $t['vars'];
    $samples = email_tpl_sample_vars($key);
    $my_email = (string)($me['email'] ?? '');
}

$KP_TITLE  = ($is_list ? 'Szablony e-mail' : 'E-mail: ' . $t['label']) . ' — Panel dydaktyka';
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
  #etWrap { display: flex; gap: 0; border: 1px solid #adb5bd; border-radius: 0 0 8px 8px; overflow: hidden; }
  #etMain { flex: 1; display: flex; flex-direction: column; min-width: 0; }
  #etPreview { width: 46%; flex-shrink: 0; border-left: 1px solid #adb5bd; display: flex; flex-direction: column; background: #f8fafc; }
  #etPreview iframe { flex: 1; width: 100%; border: 0; background: #fff; min-height: 380px; }
  #etEditor { min-height: 360px; font-size: .92rem; }
  #et-html-src { flex: 1; resize: none; border: 0; border-radius: 0; font-size: .82rem; padding: .75rem; min-height: 360px; font-family: ui-monospace, Menlo, monospace; }
  @media (max-width: 991px) { #etWrap { flex-direction: column; } #etPreview { width: auto; border-left: 0; border-top: 1px solid #adb5bd; } }
</style>

<?php $KIER_CUR = 'szablonyEmail.php'; $KIER_LABEL = 'Szablony e-mail';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
<?= flash_html() ?>
<?php if ($draft_err !== ''): ?>
<div class="alert alert-danger" role="alert" id="tpl-err" tabindex="-1"><?= h($draft_err) ?></div>
<script>document.getElementById('tpl-err').focus();</script>
<?php endif; ?>

<?php if ($is_list): ?>
  <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <h1 class="h4 mb-0 fw-bold"><i class="bi bi-envelope-paper text-primary me-2" aria-hidden="true"></i>Szablony e-mail — Dydaktyka (TI)</h1>
    <span class="ms-auto d-flex gap-2 flex-wrap">
      <a href="<?= $SMS_URL ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i>Szablony SMS</a>
      <?php if (dyd_is_admin()): ?><a href="<?= h($ADMIN_URL) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="bi bi-grid me-1" aria-hidden="true"></i>Wszystkie szablony systemu<span class="visually-hidden"> (nowa karta)</span></a><?php endif; ?>
    </span>
  </div>
  <p class="text-body-secondary small mb-4" style="max-width:760px">
    Edytuj temat i treść powiadomień e-mail wysyłanych przez moduł Dydaktyka (TI). Zmiany wchodzą w życie natychmiast —
    bez nadpisania używana jest treść domyślna. Wstawiaj zmienne <code>{{nazwa}}</code>, które system podstawia przy wysyłce.
  </p>
  <ul class="row g-3 list-unstyled" aria-label="Szablony e-mail">
    <?php foreach ($ti_reg as $k => $tp): $c = email_tpl_get($k); ?>
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
          </span>
          <i class="bi bi-chevron-right text-body-secondary flex-shrink-0" aria-hidden="true"></i>
        </span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>

<?php else: ?>
  <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
    <a href="<?= $SELF ?>" class="btn btn-sm btn-outline-secondary" aria-label="Wróć do listy szablonów e-mail"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
    <h1 class="h5 mb-0 fw-bold"><i class="bi <?= h($t['icon']) ?> text-primary me-1" aria-hidden="true"></i><?= h($t['label']) ?></h1>
    <?php if ($cur['is_custom'] || $draft): ?><span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle"><?= $draft ? 'niezapisane zmiany' : 'zmieniony' ?></span>
    <?php else: ?><span class="badge bg-light text-body-secondary border">domyślny</span><?php endif; ?>
  </div>
  <p class="text-body-secondary small mb-3"><?= h($t['description']) ?></p>

  <form method="post" id="etForm" action="<?= $SELF ?>?key=<?= urlencode($key) ?>">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="key" value="<?= h($key) ?>">
    <input type="hidden" name="_action" id="etAction" value="save">
    <input type="hidden" name="body" id="et-body">

    <div class="row g-2 mb-3 align-items-center">
      <div class="col-md-9">
        <div class="input-group input-group-sm">
          <label class="input-group-text" for="et-subject">Temat</label>
          <input name="subject" id="et-subject" class="form-control fw-semibold" value="<?= h($cur['subject']) ?>" maxlength="300" required>
        </div>
      </div>
      <div class="col-md-3">
        <?php if (email_tpl_is_auto($key)): ?>
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" role="switch" name="enabled" id="et-enabled" aria-describedby="et-enabled-help"<?= $cur['enabled'] ? ' checked' : '' ?>>
          <label class="form-check-label" for="et-enabled">Automatyczna wysyłka</label>
          <div class="form-text" id="et-enabled-help">Wyłączenie wstrzymuje automatyczną wysyłkę.</div>
        </div>
        <?php else: ?>
        <span class="badge bg-light text-body-secondary border"><i class="bi bi-hand-index me-1" aria-hidden="true"></i>wysyłka ręczna</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Pasek narzędzi edytora -->
    <div class="d-flex align-items-center gap-2 px-2 py-1 border rounded-top bg-light flex-wrap">
      <div class="d-flex gap-1" role="group" aria-label="Tryb edycji">
        <button type="button" class="btn btn-sm btn-primary active" id="btnVisual" aria-pressed="true"><i class="bi bi-type me-1" aria-hidden="true"></i>Wizualny</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnHtml" aria-pressed="false"><i class="bi bi-code me-1" aria-hidden="true"></i>HTML</button>
      </div>
      <div id="quillToolbarWrap" class="flex-grow-1">
        <div id="quillToolbar" style="border:0;padding:0">
          <span class="ql-formats"><select class="ql-header"><option selected></option><option value="1"></option><option value="2"></option><option value="3"></option></select></span>
          <span class="ql-formats"><button class="ql-bold"></button><button class="ql-italic"></button><button class="ql-underline"></button></span>
          <span class="ql-formats"><select class="ql-color"></select><select class="ql-background"></select></span>
          <span class="ql-formats"><select class="ql-align"></select></span>
          <span class="ql-formats"><button class="ql-list" value="ordered"></button><button class="ql-list" value="bullet"></button></span>
          <span class="ql-formats"><button class="ql-link"></button></span>
          <span class="ql-formats"><button class="ql-clean"></button></span>
        </div>
      </div>
      <button type="button" class="btn btn-sm btn-outline-primary" id="btnRefresh"><i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Odśwież podgląd</button>
    </div>

    <!-- Edytor + podgląd -->
    <div id="etWrap">
      <div id="etMain">
        <div id="modeVisual" class="d-flex flex-column flex-grow-1"><div id="etEditor" style="flex:1" aria-label="Treść wiadomości — edytor wizualny"></div></div>
        <div id="modeHtml" class="d-flex flex-column flex-grow-1" style="display:none!important">
          <label class="visually-hidden" for="et-html-src">Treść wiadomości — źródło HTML</label>
          <textarea id="et-html-src" class="form-control flex-grow-1"></textarea>
        </div>
      </div>
      <div id="etPreview">
        <div class="px-2 py-1 border-bottom bg-white"><h2 class="h6 mb-0 fw-semibold text-body-secondary"><i class="bi bi-eye me-1" aria-hidden="true"></i>Podgląd (z przykładowymi danymi)</h2></div>
        <iframe id="etFrame" title="Podgląd wiadomości e-mail" sandbox="allow-same-origin"></iframe>
      </div>
    </div>

    <fieldset class="border rounded p-2 mt-3">
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
      <button type="submit" class="btn btn-success" onclick="document.getElementById('etAction').value='save'"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz szablon</button>
      <div class="input-group input-group-sm ms-auto" style="max-width:360px">
        <span class="input-group-text" aria-hidden="true"><i class="bi bi-send"></i></span>
        <input type="email" name="test_email" class="form-control" placeholder="adres do testu" aria-label="Adres e-mail do testu" value="<?= h($my_email) ?>">
        <button type="submit" class="btn btn-outline-primary" onclick="document.getElementById('etAction').value='test'">Wyślij test</button>
      </div>
      <button type="submit" class="btn btn-outline-danger btn-sm"
              onclick="if(!confirm('Przywrócić domyślną treść? Twoje zmiany zostaną usunięte.')){return false;}document.getElementById('etAction').value='reset'">
        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Przywróć domyślny
      </button>
    </div>
  </form>

  <link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
  <script>
  (function () {
    var SAMPLES = <?= json_encode($samples, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var INIT_BODY = <?= json_encode($cur['body'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    // Treść ładujemy do Quilla po oczyszczeniu w dokumencie bez kontekstu przeglądania (nic się nie wykonuje):
    // bez elementów aktywnych, atrybutów on… i adresów javascript:.
    function cleanHtml(html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      doc.querySelectorAll('script,iframe,object,embed,applet,base,frame,frameset').forEach(function (n) { n.remove(); });
      doc.querySelectorAll('*').forEach(function (el) {
        Array.prototype.slice.call(el.attributes).forEach(function (a) {
          var n = a.name.toLowerCase(), v = a.value.replace(/[\u0000- ]+/g, '').toLowerCase();
          if (n.indexOf('on') === 0 || (/^(href|src|action|formaction|xlink:href|background)$/.test(n) && /^(javascript|vbscript|data:text\/html)/.test(v))) el.removeAttribute(a.name);
        });
      });
      return doc.documentElement.innerHTML;
    }

    var quill = new Quill('#etEditor', { theme: 'snow', modules: { toolbar: '#quillToolbar' }, placeholder: 'Treść maila… wstaw zmienne, np. {{org}}' });
    quill.root.innerHTML = cleanHtml(INIT_BODY);
    document.getElementById('et-html-src').value = INIT_BODY;
    var mode = 'visual';

    function getHtml() { return mode === 'visual' ? quill.root.innerHTML : document.getElementById('et-html-src').value; }
    function syncBody() { document.getElementById('et-body').value = getHtml(); }
    function applySamples(html) {
      return html.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, function (m, name) {
        return Object.prototype.hasOwnProperty.call(SAMPLES, name) ? SAMPLES[name] : m;
      });
    }
    function refreshPreview() {
      var frame = document.getElementById('etFrame'), doc = frame.contentDocument || frame.contentWindow.document;
      doc.open(); doc.write(applySamples(getHtml())); doc.close();     // iframe w piaskownicy: skrypty się nie wykonają
    }
    function setMode(m) {
      var html = getHtml(); mode = m;
      document.getElementById('modeVisual').style.setProperty('display', m === 'visual' ? 'flex' : 'none', 'important');
      document.getElementById('modeHtml').style.setProperty('display', m === 'html' ? 'flex' : 'none', 'important');
      document.getElementById('btnVisual').className = 'btn btn-sm ' + (m === 'visual' ? 'btn-primary active' : 'btn-outline-secondary');
      document.getElementById('btnHtml').className   = 'btn btn-sm ' + (m === 'html'   ? 'btn-primary active' : 'btn-outline-secondary');
      document.getElementById('btnVisual').setAttribute('aria-pressed', m === 'visual' ? 'true' : 'false');
      document.getElementById('btnHtml').setAttribute('aria-pressed', m === 'html' ? 'true' : 'false');
      document.getElementById('quillToolbarWrap').style.display = m === 'visual' ? '' : 'none';
      if (m === 'visual') quill.root.innerHTML = cleanHtml(html); else document.getElementById('et-html-src').value = html;
      refreshPreview();
    }
    function insertVar(v) {
      if (mode === 'visual') {
        quill.focus();
        var r = quill.getSelection() || { index: quill.getLength() - 1 };
        quill.insertText(r.index, v, 'user'); quill.setSelection(r.index + v.length);
      } else {
        var ta = document.getElementById('et-html-src'), s = ta.selectionStart, e = ta.selectionEnd;
        ta.value = ta.value.slice(0, s) + v + ta.value.slice(e);
        ta.selectionStart = ta.selectionEnd = s + v.length; ta.focus();
      }
      refreshPreview();
    }

    document.getElementById('btnVisual').addEventListener('click', function () { setMode('visual'); });
    document.getElementById('btnHtml').addEventListener('click', function () { setMode('html'); });
    document.getElementById('btnRefresh').addEventListener('click', refreshPreview);
    document.querySelectorAll('.var-btn').forEach(function (b) { b.addEventListener('click', function () { insertVar(b.getAttribute('data-var')); }); });
    document.getElementById('etForm').addEventListener('submit', syncBody);
    document.querySelectorAll('#etForm button[type=submit]').forEach(function (b) { b.addEventListener('click', syncBody); });
    quill.on('text-change', function () { if (mode === 'visual') refreshPreview(); });
    document.getElementById('et-html-src').addEventListener('input', function () { if (mode === 'html') refreshPreview(); });
    refreshPreview();
  })();
  </script>
<?php endif; ?>

</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
