<?php
/**
 * crm/form/[slug] — Publiczny formularz lead capture.
 * Routing: ?slug= LUB przez .htaccess RewriteRule
 *
 * Dwa sposoby dostępu:
 *   /crm/form/index.php?slug=wolontariusz
 *   /crm/form/wolontariusz  (wymaga RewriteRule w .htaccess)
 */
// Załaduj config PRZED użyciem APP_URL
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

// Routing: /crm/form/wolontariusz  lub  ?slug=
$slug = '';
$uri  = $_SERVER['REQUEST_URI'] ?? '';
$base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
$rel  = $base !== '' && $base !== '/' ? substr($uri, strlen($base)) : $uri;
if (preg_match('#^/crm/form/([a-z0-9\-]+)/?(\?.*)?$#', $rel, $m)) {
    $slug = $m[1];
}
if (!$slug) $slug = trim($_GET['slug'] ?? '');

if (!$slug) { http_response_code(404); die('Nie znaleziono formularza.'); }

$form = db_one("SELECT * FROM crm_web_forms WHERE slug=? AND is_active=1", [$slug]);
if (!$form) { http_response_code(404); die('Formularz nie istnieje lub jest nieaktywny.'); }

$fields_config    = json_decode($form['fields_json'],      true) ?: [];
$consents_config  = json_decode($form['consents_json']  ?? '[]', true) ?: [];
$style            = json_decode($form['style_json']     ?? '{}', true) ?: [];
$automations      = json_decode($form['automations_json'] ?? '[]', true) ?: [];

// Załaduj definicje pól custom potrzebne w tym formularzu
$custom_def_ids = array_column(array_filter($fields_config, fn($f)=>($f['type']??'')==='custom'), 'field_def_id');
$custom_defs    = [];
if ($custom_def_ids) {
    foreach ($custom_def_ids as $did) {
        $def = CrmManager::getFieldDef((int)$did);
        if ($def) $custom_defs[$did] = $def;
    }
}
$WOJ_LIST = ['dolnośląskie','kujawsko-pomorskie','lubelskie','lubuskie','łódzkie','małopolskie','mazowieckie','opolskie','podkarpackie','podlaskie','pomorskie','śląskie','świętokrzyskie','warmińsko-mazurskie','wielkopolskie','zachodniopomorskie'];

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [];
    foreach ($fields_config as $fc) {
        $fname = $fc['name'];
        $val   = trim($_POST[$fname] ?? '');
        if ($fc['required'] && $val === '') {
            $errors[$fname] = 'To pole jest wymagane.';
        }
        $data[$fname] = $val ?: null;
    }
    if (isset($data['email']) && $data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Nieprawidłowy adres e-mail.';
    }
    // Pola niestandardowe
    $custom_values = [];
    foreach ($fields_config as $fc) {
        if (($fc['type'] ?? '') !== 'custom') continue;
        $did = (int)($fc['field_def_id'] ?? 0);
        $val = trim($_POST['custom_' . $did] ?? '');
        if (!empty($fc['required']) && $val === '') {
            $def = $custom_defs[$did] ?? null;
            $errors['custom_' . $did] = ($def ? $def['label'] : 'Pole') . ' jest wymagane.';
        }
        if ($val !== '') $custom_values[$did] = $val;
    }
    // Walidacja zgód wymaganych
    $granted_consents = [];
    foreach ($consents_config as $ci => $con) {
        $checked = !empty($_POST['consent_' . $con['id']]);
        if (!empty($con['required']) && !$checked) {
            $errors['consent_' . $con['id']] = 'Ta zgoda jest wymagana.';
        }
        if ($checked) $granted_consents[] = $con['id'];
    }

    if (!$errors) {
        // Cała obsługa zgłoszenia siedzi teraz w includes/crm_form_intake.php,
        // bo dokładnie tą samą ścieżką musi przejść zgłoszenie z zewnętrznego
        // CMS-a (api/v1/forms.php). Dwie kopie tej logiki rozjechałyby się przy
        // pierwszej zmianie w którejkolwiek.
        require_once dirname(dirname(__DIR__)) . '/includes/crm_form_intake.php';
        $res = crm_form_intake($form, $data, [
            'custom'   => $custom_values,
            'consents' => $granted_consents,
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        if (empty($res['ok'])) {
            $errors['_form'] = $res['error'] ?: 'Nie udało się zapisać zgłoszenia.';
        } else {
            $success = true;
        }
    }
}

// ── Renderuj HTML ─────────────────────────────────────────────────────────────
$org_name = defined('ORG_NAME') ? ORG_NAME : '';

// Styl z konfiguracji
$s_accent   = preg_replace('/[^#a-fA-F0-9]/', '', $style['accent']    ?? '#0176D3');
$s_bg       = preg_replace('/[^#a-fA-F0-9]/', '', $style['bg']        ?? '#f8fafc');
$s_mw       = min(900, max(320, (int)($style['max_width'] ?? 520)));
$s_rounded  = min(24, max(0, (int)($style['rounded'] ?? 14)));
$s_btn_text = h($style['btn_text'] ?? 'Wyślij zgłoszenie');
$s_logo     = $style['logo_url'] ? h($style['logo_url']) : '';
$s_hdr_text = $style['header_text'] ? h($style['header_text']) : '';
$s_font_map = ['system'=>'system-ui,-apple-system,sans-serif','serif'=>'Georgia,serif','mono'=>'monospace'];
$s_font_fam = $s_font_map[$style['font'] ?? 'system'] ?? $s_font_map['system'];

// Oblicz kontrast tekstu na tle akcentu
function form_lum(string $hex): float {
    $hex = ltrim($hex,'#');
    if (strlen($hex)===3) $hex=$hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    [$r,$g,$b]=[hexdec(substr($hex,0,2))/255,hexdec(substr($hex,2,2))/255,hexdec(substr($hex,4,2))/255];
    $l=fn($c)=>$c<=.03928?$c/12.92:(($c+.055)/1.055)**2.4;
    return .2126*$l($r)+.7152*$l($g)+.0722*$l($b);
}
$s_txt = form_lum($s_accent) > 0.35 ? '#1f2937' : '#ffffff';

?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($form['title']) ?><?= $org_name ? ' — '.h($org_name) : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<style>
:root {
  --accent:  <?= $s_accent ?>;
  --txt-on:  <?= $s_txt ?>;
  --bg:      <?= $s_bg ?>;
  --mw:      <?= $s_mw ?>px;
  --rounded: <?= $s_rounded ?>px;
  --font:    <?= $s_font_fam ?>;
}
/* ── Podstawa ── */
*, *::before, *::after { box-sizing:border-box; }
body { background:var(--bg); font-family:var(--font); min-height:100vh; margin:0; }

/* ── Skip link (WCAG 2.4.1) ── */
.skip-link {
  position:absolute; top:-100%; left:.75rem; z-index:9999;
  background:var(--accent); color:var(--txt-on);
  padding:.5rem 1.1rem; border-radius:0 0 6px 6px;
  font-size:.88rem; font-weight:700; text-decoration:none;
  border:2px solid var(--txt-on);
}
.skip-link:focus { top:0; }

/* ── Focus ring globalny (WCAG 2.4.7) ── */
*:focus-visible {
  outline: 3px solid var(--accent) !important;
  outline-offset: 2px !important;
  border-radius: 3px;
}
*:focus:not(:focus-visible) { outline: none; }

.form-card { max-width:var(--mw); margin:2.5rem auto 3rem; background:#fff; border-radius:var(--rounded); box-shadow:0 4px 24px rgba(0,0,0,.08); overflow:hidden; }
.form-header { background:var(--accent); color:var(--txt-on); padding:1.75rem; }
.form-header h1 { font-size:1.25rem; font-weight:700; margin:0 0 .3rem; }
.form-header p  { font-size:.85rem; opacity:.85; margin:0; }
.form-body  { padding:1.75rem; }

/* ── Pola (WCAG 1.3.1, 3.3.2) ── */
.field-group { margin-bottom:1.1rem; }
.form-label { font-size:.84rem; font-weight:600; color:#374151; display:block; margin-bottom:.3rem; }
.required-star { color:#ef4444; margin-left:.2rem; }
.form-control, .form-select {
  width:100%; padding:.5rem .75rem; font-size:.9rem;
  border:1.5px solid #D1D5DB; border-radius:6px;
  color:#111827; background:#fff; font-family:inherit;
  transition:border-color .15s, box-shadow .15s;
}
.form-control:focus, .form-select:focus {
  border-color:var(--accent); outline:none;
  box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 20%, transparent);
}
.form-control[aria-invalid="true"], .form-select[aria-invalid="true"] {
  border-color:#dc2626;
}
.field-error { color:#dc2626; font-size:.76rem; margin-top:.3rem; display:flex; align-items:center; gap:.3rem; }

/* ── Przycisk (WCAG 1.4.3 kontrast) ── */
.btn-submit {
  background:var(--accent); color:var(--txt-on);
  border:none; padding:.7rem 1rem; width:100%; border-radius:6px;
  font-weight:700; font-size:.9rem; cursor:pointer;
  transition:opacity .15s; margin-top:.5rem;
  font-family:inherit;
}
.btn-submit:hover  { opacity:.88; }
.btn-submit:active { opacity:.75; transform:scale(.99); }

/* ── Sukces ── */
.success-box { text-align:center; padding:2rem 1rem; }
.success-icon { font-size:3rem; color:#16a34a; margin-bottom:.75rem; display:block; }

/* ── Zgody (WCAG 1.3.3, 4.1.2) ── */
.consent-box {
  border:1.5px solid #E5E7EB; border-radius:8px; padding:.9rem 1rem;
  margin-bottom:.75rem;
}
.consent-box.required { border-color:var(--accent); }
.consent-box.has-error { border-color:#dc2626; background:#fef2f2; }
.consent-label {
  display:flex; align-items:flex-start; gap:.65rem;
  font-size:.82rem; color:#374151; cursor:pointer; line-height:1.5;
}
.consent-label input[type="checkbox"] {
  margin-top:.15rem; flex-shrink:0;
  accent-color:var(--accent); width:16px; height:16px;
  cursor:pointer;
}
.consent-error { color:#dc2626; font-size:.75rem; margin-top:.35rem; display:flex; align-items:center; gap:.3rem; }

/* ── Alert błędów zbiorczy (WCAG 3.3.1) ── */
.error-summary {
  border:1.5px solid #dc2626; border-radius:8px; padding:.75rem 1rem;
  background:#fef2f2; margin-bottom:1.25rem; font-size:.83rem;
}
.error-summary h2 { font-size:.88rem; font-weight:700; color:#dc2626; margin:0 0 .35rem; }
.error-summary ul { margin:0; padding-left:1.25rem; color:#374151; }

@media(max-width:600px) {
  .form-card { margin:.75rem; border-radius:calc(var(--rounded) * 0.6); }
  .form-body { padding:1.25rem; }
}
@media(prefers-reduced-motion:reduce) { *, *::before, *::after { transition:none !important; } }
</style>
</head>
<body>
<!-- Skip link (WCAG 2.4.1) -->
<a href="#main-form" class="skip-link">Przejdź do formularza</a>

<div class="form-card" role="main">
  <div class="form-header">
    <?php if ($s_logo): ?>
    <img src="<?= $s_logo ?>" alt="<?= h($org_name ?: 'Logo') ?>"
         style="height:32px;max-width:120px;object-fit:contain;margin-bottom:.75rem;display:block">
    <?php endif; ?>
    <?php if ($s_hdr_text): ?>
    <div aria-hidden="true" style="font-size:.72rem;opacity:.7;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.06em"><?= $s_hdr_text ?></div>
    <?php elseif ($org_name): ?>
    <div aria-hidden="true" style="font-size:.72rem;opacity:.65;margin-bottom:.3rem"><?= h($org_name) ?></div>
    <?php endif; ?>
    <h1 id="form-title"><?= h($form['title']) ?></h1>
    <?php if ($form['description']): ?><p id="form-desc"><?= h($form['description']) ?></p><?php endif; ?>
  </div>
  <div class="form-body">

    <?php if ($success): ?>
    <div class="success-box" role="status" aria-live="polite" aria-label="Formularz wysłany pomyślnie">
      <span class="success-icon" aria-hidden="true"><i class="bi bi-check-circle-fill"></i></span>
      <p class="fw-semibold" style="font-size:1rem"><?= h($form['success_msg']) ?></p>
    </div>

    <?php else: ?>

    <?php if ($errors): ?>
    <!-- Error summary (WCAG 3.3.1 Error Identification) -->
    <div class="error-summary" role="alert" aria-labelledby="error-summary-heading" tabindex="-1" id="error-summary">
      <h2 id="error-summary-heading"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Proszę poprawić błędy w formularzu</h2>
      <ul>
        <?php foreach ($errors as $field => $msg): ?>
        <li><a href="#<?= str_starts_with($field,'consent_') ? 'c_'.substr($field,8) : 'f_'.$field ?>"><?= h($msg) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; /* errors */ ?>

    <form method="post" id="main-form" novalidate
          aria-labelledby="form-title"
          <?= $form['description'] ? 'aria-describedby="form-desc"' : '' ?>>
      <?php
      // Pola wymagane — info dla czytników
      $has_required = !empty(array_filter($fields_config, fn($f) => !empty($f['required'])));
      ?>
      <?php if ($has_required): ?>
      <p class="small text-muted mb-3" id="required-note">
        Pola oznaczone <span class="required-star" aria-label="gwiazdką">*</span> są wymagane.
      </p>
      <?php endif; ?>

      <?php foreach ($fields_config as $fc):
        if (($fc['type']??'') === 'custom') continue; // renderowane osobno poniżej
        $fname = $fc['name'];
        $req   = !empty($fc['required']);
        $val   = $_POST[$fname] ?? '';
        $err   = $errors[$fname] ?? null;
        $fid   = 'f_' . $fname;
        $eid   = 'err_' . $fname;
        $label = match ($fname) {
          'imie_nazwisko' => 'Imię i nazwisko',
          'email'         => 'Adres e-mail',
          'telefon'       => 'Telefon',
          'organizacja'   => 'Organizacja',
          'stanowisko'    => 'Stanowisko',
          'adres'         => 'Adres',
          'notatka'       => 'Wiadomość',
          'wojewodztwo'   => 'Województwo',
          'powiat'        => 'Powiat',
          'gmina'         => 'Gmina',
          default         => ucfirst($fname),
        };
        $autocomplete = match ($fname) {
          'imie_nazwisko' => 'name',
          'email'         => 'email',
          'telefon'       => 'tel',
          'organizacja'   => 'organization',
          'adres'         => 'street-address',
          default         => null,
        };
      ?>
      <div class="field-group">
        <label for="<?= $fid ?>" class="form-label">
          <?= h($label) ?>
          <?php if ($req): ?><span class="required-star" aria-hidden="true">*</span><?php endif; ?>
        </label>

        <?php if ($fname === 'notatka'): ?>
        <textarea name="<?= $fname ?>" id="<?= $fid ?>" rows="3"
                  class="form-control"
                  placeholder="<?= h($label) ?>"
                  <?= $req ? 'required aria-required="true"' : '' ?>
                  <?= $err ? 'aria-invalid="true" aria-describedby="'.$eid.'"' : '' ?>><?= h($val) ?></textarea>

        <?php elseif ($fname === 'wojewodztwo'): ?>
        <select name="<?= $fname ?>" id="<?= $fid ?>"
                class="form-select"
                <?= $req ? 'required aria-required="true"' : '' ?>
                <?= $err ? 'aria-invalid="true" aria-describedby="'.$eid.'"' : '' ?>>
          <option value="">— wybierz województwo —</option>
          <?php foreach ($WOJ_LIST as $w): ?>
          <option value="<?= h($w) ?>" <?= $val===$w?'selected':'' ?>><?= h(ucfirst($w)) ?></option>
          <?php endforeach; ?>
        </select>

        <?php else: ?>
        <input type="<?= $fname==='email'?'email':($fname==='telefon'?'tel':'text') ?>"
               name="<?= $fname ?>" id="<?= $fid ?>"
               class="form-control"
               value="<?= h($val) ?>"
               placeholder="<?= h($label) ?>"
               <?= $req ? 'required aria-required="true"' : '' ?>
               <?= $err ? 'aria-invalid="true" aria-describedby="'.$eid.'"' : '' ?>
               <?= $autocomplete ? 'autocomplete="'.$autocomplete.'"' : '' ?>>
        <?php endif; ?>

        <?php if ($err): ?>
        <div class="field-error" id="<?= $eid ?>" role="alert">
          <span aria-hidden="true">⚠</span><?= h($err) ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <?php
      // Pola niestandardowe
      foreach ($fields_config as $fc):
        if (($fc['type'] ?? '') !== 'custom') continue;
        $did  = (int)($fc['field_def_id'] ?? 0);
        $def  = $custom_defs[$did] ?? null;
        if (!$def) continue;
        $req  = !empty($fc['required']);
        $val  = $_POST['custom_' . $did] ?? '';
        $err  = $errors['custom_' . $did] ?? null;
        $ph   = $fc['placeholder'] ?? $def['label'];
        $opts = $def['options'] ? json_decode($def['options'], true) : [];
        $cfid = 'cf_' . $did;
        $ceid = 'cerr_' . $did;
      ?>
      <div class="field-group">
        <label for="<?= $cfid ?>" class="form-label">
          <?= h($def['label']) ?><?php if ($req): ?><span class="required-star" aria-hidden="true">*</span><?php endif; ?>
        </label>
        <?php if ($def['field_type'] === 'textarea'): ?>
        <textarea name="custom_<?= $did ?>" id="<?= $cfid ?>" rows="3"
                  class="form-control" placeholder="<?= h($ph) ?>"
                  <?= $req?'required aria-required="true"':'' ?>
                  <?= $err?'aria-invalid="true" aria-describedby="'.$ceid.'"':'' ?>><?= h($val) ?></textarea>
        <?php elseif ($def['field_type'] === 'select' && $opts): ?>
        <select name="custom_<?= $did ?>" id="<?= $cfid ?>"
                class="form-select"
                <?= $req?'required aria-required="true"':'' ?>
                <?= $err?'aria-invalid="true" aria-describedby="'.$ceid.'"':'' ?>>
          <option value="">— wybierz —</option>
          <?php foreach ($opts as $opt): ?>
          <option value="<?= h($opt) ?>" <?= $val===$opt?'selected':'' ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
        <?php elseif ($def['field_type'] === 'checkbox'): ?>
        <div style="display:flex;align-items:center;gap:.5rem">
          <input type="checkbox" name="custom_<?= $did ?>" id="<?= $cfid ?>"
                 style="width:16px;height:16px;accent-color:var(--accent);cursor:pointer"
                 value="1" <?= $val?'checked':'' ?>
                 <?= $req?'required aria-required="true"':'' ?>
                 <?= $err?'aria-invalid="true" aria-describedby="'.$ceid.'"':'' ?>>
          <label for="<?= $cfid ?>" style="font-size:.84rem;cursor:pointer"><?= h($ph) ?></label>
        </div>
        <?php else: ?>
        <input type="<?= in_array($def['field_type'],['email','number','date','url','tel'])?$def['field_type']:'text' ?>"
               name="custom_<?= $did ?>" id="<?= $cfid ?>"
               class="form-control"
               value="<?= h($val) ?>" placeholder="<?= h($ph) ?>"
               <?= $req?'required aria-required="true"':'' ?>
               <?= $err?'aria-invalid="true" aria-describedby="'.$ceid.'"':'' ?>>
        <?php endif; ?>
        <?php if ($err): ?>
        <div class="field-error" id="<?= $ceid ?>" role="alert"><span aria-hidden="true">⚠</span><?= h($err) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <?php if ($consents_config): ?>
      <fieldset style="border:none;padding:0;margin:1rem 0 0" aria-label="Zgody wymagane do złożenia formularza">
        <legend class="visually-hidden">Zgody</legend>
        <?php foreach ($consents_config as $con):
          $ckey = 'consent_' . $con['id'];
          $err  = $errors[$ckey] ?? null;
          $chkd = !empty($_POST[$ckey]);
        ?>
        <?php
          $cid_attr  = 'c_' . $con['id'];
          $cerr_attr = 'cerr_' . $con['id'];
          $has_err   = !empty($err);
        ?>
        <div class="consent-box <?= !empty($con['required']) ? 'required' : '' ?> <?= $has_err ? 'has-error' : '' ?>"
             role="group">
          <label class="consent-label" for="<?= h($cid_attr) ?>">
            <input type="checkbox" id="<?= h($cid_attr) ?>"
                   name="<?= h($ckey) ?>" value="1"
                   <?= $chkd ? 'checked' : '' ?>
                   <?= !empty($con['required']) ? 'required aria-required="true"' : '' ?>
                   <?= $has_err ? 'aria-invalid="true" aria-describedby="'.$cerr_attr.'"' : '' ?>>
            <span>
              <?= h($con['text']) ?>
              <?php if (!empty($con['required'])): ?>
              <span class="required-star" aria-hidden="true">*</span>
              <?php endif; ?>
              <?php if (!empty($con['link'])): ?>
              <a href="<?= h($con['link']) ?>" target="_blank" rel="noopener noreferrer"
                 style="color:var(--accent);margin-left:.3rem;font-size:.78rem"
                 aria-label="<?= h($con['link_text'] ?? 'Więcej informacji') ?> (otwiera nowe okno)">
                <?= h($con['link_text'] ?? 'Więcej informacji') ?>
                <span aria-hidden="true"> ↗</span>
              </a>
              <?php endif; ?>
            </span>
          </label>
          <?php if ($has_err): ?>
          <div class="consent-error" id="<?= $cerr_attr ?>" role="alert">
            <span aria-hidden="true">⚠</span><?= h($err) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </fieldset>
      <?php endif; ?>

      <button type="submit" class="btn-submit"
              aria-label="<?= h(strip_tags($s_btn_text)) ?>">
        <?= $s_btn_text ?>
      </button>
    </form>

    <?php if ($errors): ?>
    <script>document.getElementById('error-summary')?.focus();</script>
    <?php endif; ?>
    <?php endif; /* else success */ ?>
  </div>
</div>
</body>
</html>
