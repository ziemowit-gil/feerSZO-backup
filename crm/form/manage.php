<?php
/**
 * crm/form/manage.php — Zarządzanie formularzami webowymi (lead capture).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'CRM');
if (!is_admin() && !can_write('crm')) {
    flash_set('danger','Brak uprawnień.'); header('Location: '.APP_URL.'/crm/dashboard.php'); exit;
}
crm_migrate();

$PAGE_TITLE = 'CRM — Formularze webowe';

// Dostępne pola formularza
$FORM_FIELDS = [
    'imie_nazwisko' => ['label'=>'Imię i nazwisko','required'=>true,'type'=>'text'],
    'email'         => ['label'=>'Adres e-mail',   'required'=>false,'type'=>'email'],
    'telefon'       => ['label'=>'Telefon',         'required'=>false,'type'=>'tel'],
    'organizacja'   => ['label'=>'Organizacja',     'required'=>false,'type'=>'text'],
    'stanowisko'    => ['label'=>'Stanowisko',      'required'=>false,'type'=>'text'],
    'adres'         => ['label'=>'Adres',           'required'=>false,'type'=>'text'],
    'notatka'       => ['label'=>'Wiadomość / Notatka','required'=>false,'type'=>'textarea'],
    'wojewodztwo'   => ['label'=>'Województwo',     'required'=>false,'type'=>'select'],
    'powiat'        => ['label'=>'Powiat',          'required'=>false,'type'=>'text'],
    'gmina'         => ['label'=>'Gmina',           'required'=>false,'type'=>'text'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $slug  = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['slug'] ?? '')));
        if (!$title || !$slug) { flash_set('danger','Tytuł i slug są wymagane.'); goto redirect; }

        // Pola wbudowane
        $sel_fields = [];
        foreach (array_keys($FORM_FIELDS) as $fname) {
            if (isset($_POST['field_' . $fname])) {
                $sel_fields[] = ['name'=>$fname, 'required'=>isset($_POST['req_'.$fname])];
            }
        }
        if (!in_array('imie_nazwisko', array_column($sel_fields,'name'))) {
            array_unshift($sel_fields, ['name'=>'imie_nazwisko','required'=>true]);
        }
        // Pola niestandardowe (crm_contact_field_defs)
        foreach ((array)($_POST['custom_field_id'] ?? []) as $i => $def_id) {
            $def_id = (int)$def_id;
            if (!$def_id) continue;
            $sel_fields[] = [
                'type'         => 'custom',
                'field_def_id' => $def_id,
                'required'     => isset($_POST['custom_req'][$i]),
                'placeholder'  => trim($_POST['custom_placeholder'][$i] ?? ''),
            ];
        }

        // Automatyzacje
        $automations = [];
        $auto_types  = (array)($_POST['auto_type']    ?? []);
        $auto_en     = (array)($_POST['auto_enabled']  ?? []);
        $auto_cfg    = (array)($_POST['auto_config']   ?? []);
        foreach ($auto_types as $ai => $atype) {
            if (!$atype) continue;
            $cfg = [];
            foreach ((array)($auto_cfg[$ai] ?? []) as $k => $v) {
                $cfg[$k] = trim($v);
            }
            $automations[] = [
                'id'      => 'a' . ($ai + 1),
                'type'    => $atype,
                'enabled' => isset($auto_en[$ai]),
                'config'  => $cfg,
            ];
        }

        // Zgody RODO — z tablicy POST
        $consents = [];
        $consent_texts = (array)($_POST['consent_text'] ?? []);
        $consent_reqs  = (array)($_POST['consent_required'] ?? []);
        $consent_links = (array)($_POST['consent_link'] ?? []);
        $consent_ltxt  = (array)($_POST['consent_link_text'] ?? []);
        foreach ($consent_texts as $i => $ct) {
            $ct = trim($ct);
            if ($ct === '') continue;
            $consents[] = [
                'id'        => 'c' . ($i + 1),
                'text'      => $ct,
                'required'  => isset($consent_reqs[$i]),
                'link'      => trim($consent_links[$i] ?? '') ?: null,
                'link_text' => trim($consent_ltxt[$i] ?? '') ?: 'Więcej informacji',
            ];
        }

        // Styl formularza
        $style = [
            'accent'      => preg_replace('/[^#a-fA-F0-9]/', '', $_POST['style_accent'] ?? '#0176D3'),
            'bg'          => preg_replace('/[^#a-fA-F0-9]/', '', $_POST['style_bg']     ?? '#f8fafc'),
            'max_width'   => min(900, max(320, (int)($_POST['style_max_width'] ?? 520))),
            'btn_text'    => trim($_POST['style_btn_text'] ?? 'Wyślij zgłoszenie'),
            'logo_url'    => trim($_POST['style_logo_url'] ?? '') ?: null,
            'header_text' => trim($_POST['style_header_text'] ?? '') ?: null,
            'font'        => in_array($_POST['style_font']??'', ['system','serif','mono']) ? $_POST['style_font'] : 'system',
            'rounded'     => (int)($_POST['style_rounded'] ?? 14),
        ];

        $data = [
            'slug'           => $slug,
            'title'          => $title,
            'description'    => trim($_POST['description'] ?? '') ?: null,
            'fields_json'    => json_encode($sel_fields, JSON_UNESCAPED_UNICODE),
            'consents_json'    => json_encode($consents,    JSON_UNESCAPED_UNICODE),
            'style_json'       => json_encode($style,       JSON_UNESCAPED_UNICODE),
            'automations_json' => json_encode($automations, JSON_UNESCAPED_UNICODE),
            'group_id'       => (int)($_POST['group_id'] ?? 0) ?: null,
            'default_status' => trim($_POST['default_status'] ?? 'prospect'),
            'success_msg'    => trim($_POST['success_msg'] ?? 'Dziękujemy! Twoje zgłoszenie zostało przyjęte.'),
            'notify_email'   => trim($_POST['notify_email'] ?? '') ?: null,
            'is_active'      => isset($_POST['is_active']) ? 1 : 0,
        ];

        try {
            if ($id) {
                db_update('crm_web_forms', $data, $id);
                flash_set('success','Formularz zaktualizowany.');
            } else {
                $data['created_by'] = current_user()['id'] ?? null;
                $data['created_at'] = date('Y-m-d H:i:s');
                db_insert('crm_web_forms', $data);
                flash_set('success','Formularz utworzony.');
            }
        } catch (\Throwable $e) {
            flash_set('danger','Slug już istnieje lub błąd: '.$e->getMessage());
        }

    } elseif ($op === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $cur = db_one("SELECT is_active FROM crm_web_forms WHERE id=?",[$id]);
        if ($cur) db()->prepare("UPDATE crm_web_forms SET is_active=? WHERE id=?")->execute([$cur['is_active']?0:1,$id]);
    } elseif ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM crm_web_forms WHERE id=?")->execute([$id]);
        flash_set('success','Formularz usunięty.');
    }
    redirect:
    header('Location: '.$_SERVER['PHP_SELF']); exit;
}

$forms   = db_all("SELECT f.*, g.name AS group_name FROM crm_web_forms f LEFT JOIN crm_groups g ON g.id=f.group_id ORDER BY f.created_at DESC");
$edit_id = (int)($_GET['edit'] ?? 0);
$edit    = $edit_id ? db_one("SELECT * FROM crm_web_forms WHERE id=?",[$edit_id]) : null;
$edit_fields   = $edit ? (json_decode($edit['fields_json'],   true) ?: []) : [];
$edit_consents    = $edit ? (json_decode($edit['consents_json'],    true) ?: []) : [];
$edit_style       = $edit ? (json_decode($edit['style_json'],       true) ?: []) : [];
$edit_automations = $edit ? (json_decode($edit['automations_json'] ?? '[]', true) ?: []) : [];
// Pola niestandardowe dostępne do dodania
$all_custom_fields = CrmManager::getFieldDefs('', true);
$all_groups    = CrmManager::getGroups();

/** Pobiera listę projektów Nozbe RAZ na całe żądanie (nie osobno dla każdej automatyzacji). */
function getNozbeProjectsOnce(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        require_once dirname(dirname(__DIR__)) . '/includes/nozbe.php';
        if (nozbe_setting('nozbe_enabled') === '1') {
            $nz = NozbeAPI::from_settings();
            if ($nz->is_configured()) $cache = $nz->get_projects();
        }
    } catch (\Throwable $e) {}
    return $cache;
}

/** Renderuj pola konfiguracji automatyzacji dla edytora. */
function renderAutoConfig(int $idx, string $type, array $cfg): string {
    $n = fn(string $k) => "auto_config[{$idx}][{$k}]";
    $v = fn(string $k, string $d = '') => htmlspecialchars($cfg[$k] ?? $d, ENT_QUOTES);

    $all_statuses   = crm_statuses();
    $nozbe_projects = getNozbeProjectsOnce();

    $html = '';
    switch ($type) {
        case 'send_email':
            $tpl = $v('body', "Witaj {imie_nazwisko},\n\nDziękujemy za Twoje zgłoszenie!\n\nPozdrawiamy,\n" . (defined('ORG_NAME') ? ORG_NAME : ''));
            $html = '
            <div class="row g-2">
              <div class="col-12">
                <label class="form-label small mb-1">Temat</label>
                <input type="text" name="'.$n('subject').'" class="form-control form-control-sm"
                       placeholder="np. Dziękujemy za zgłoszenie!" value="'.$v('subject','Dziękujemy za Twoje zgłoszenie!').'">
              </div>
              <div class="col-12">
                <label class="form-label small mb-1">Treść (zmienne: {imie_nazwisko} {email} {organizacja})</label>
                <textarea name="'.$n('body').'" rows="4" class="form-control form-control-sm font-monospace"
                          placeholder="Treść maila...">' . htmlspecialchars($cfg['body'] ?? $tpl, ENT_QUOTES) . '</textarea>
              </div>
            </div>';
            break;
        case 'send_notification':
            $html = '
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label small mb-1">Adres e-mail</label>
                <input type="email" name="'.$n('email').'" class="form-control form-control-sm"
                       placeholder="admin@org.pl" value="'.$v('email').'">
              </div>
              <div class="col-md-6">
                <label class="form-label small mb-1">Temat</label>
                <input type="text" name="'.$n('subject').'" class="form-control form-control-sm"
                       value="'.$v('subject','Nowe zgłoszenie z formularza').'">
              </div>
            </div>';
            break;
        case 'add_tag':
            $html = '
            <div>
              <label class="form-label small mb-1">Tagi (oddziel przecinkami)</label>
              <input type="text" name="'.$n('tags').'" class="form-control form-control-sm"
                     placeholder="np. webform, nowy, 2026" value="'.$v('tags').'">
            </div>';
            break;
        case 'set_status':
            $opts = '';
            foreach ($all_statuses as $sk => $sv) {
                $sel = ($cfg['status'] ?? '') === $sk ? ' selected' : '';
                $opts .= "<option value=\"$sk\"$sel>" . htmlspecialchars($sv['label']) . "</option>";
            }
            $html = '
            <div>
              <label class="form-label small mb-1">Nowy status</label>
              <select name="'.$n('status').'" class="form-select form-select-sm">'.$opts.'</select>
            </div>';
            break;
        case 'nozbe_task':
            $proj_opts = '<option value="">— domyślny projekt —</option>';
            foreach ($nozbe_projects as $p) {
                $sel = ($cfg['project_id'] ?? '') === $p['id'] ? ' selected' : '';
                $proj_opts .= "<option value=\"{$p['id']}\"$sel>" . htmlspecialchars($p['name'] ?? $p['id']) . "</option>";
            }
            $html = '
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label small mb-1">Nazwa zadania</label>
                <input type="text" name="'.$n('task_name').'" class="form-control form-control-sm"
                       placeholder="np. Sprawdź: {imie_nazwisko}" value="'.$v('task_name','Nowe zgłoszenie: {imie_nazwisko}').'">
              </div>
              <div class="col-md-4">
                <label class="form-label small mb-1">Projekt</label>
                <select name="'.$n('project_id').'" class="form-select form-select-sm">'.$proj_opts.'</select>
              </div>
              <div class="col-md-2">
                <label class="form-label small mb-1">Za (dni)</label>
                <input type="number" name="'.$n('due_days').'" class="form-control form-control-sm"
                       min="0" max="365" value="'.$v('due_days','1').'">
              </div>
            </div>';
            break;
        case 'webhook':
            $html = '
            <div class="row g-2">
              <div class="col-md-8">
                <label class="form-label small mb-1">URL</label>
                <input type="url" name="'.$n('url').'" class="form-control form-control-sm font-monospace"
                       placeholder="https://example.com/webhook" value="'.$v('url').'">
              </div>
              <div class="col-md-4">
                <label class="form-label small mb-1">Secret (HMAC header)</label>
                <input type="text" name="'.$n('secret').'" class="form-control form-control-sm font-monospace"
                       placeholder="opcjonalnie" value="'.$v('secret').'">
              </div>
            </div>
            <div class="form-text">Wysyłamy POST JSON: {form_id, contact_id, data, timestamp}. Nagłówek <code>X-Form-Secret</code> gdy podano secret.</div>';
            break;
    }
    return '<div class="auto-config">' . $html . '</div>';
}

include __DIR__ . '/../includes/header_crm.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb mb-0 small">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
  <li class="breadcrumb-item active">Formularze webowe</li>
</ol></nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon"><i class="bi bi-window-split"></i></div>
  <div>
    <h1 class="crm-object-title">Formularze webowe</h1>
    <div class="crm-object-count">Publiczne formularze do zbierania kontaktów (lead capture)</div>
  </div>
  <div class="crm-object-actions">
    <a href="?new=1" class="btn btn-crm-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Nowy formularz
    </a>
  </div>
</div>

<?= flash_get() ?>

<!-- Lista formularzy -->
<?php if ($forms): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Tytuł</th><th>Slug / URL</th><th>Grupa</th><th>Zgłoszeń</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($forms as $f): ?>
        <tr>
          <td class="fw-semibold"><?= h($f['title']) ?></td>
          <td>
            <code class="small">/crm/form/<?= h($f['slug']) ?></code>
            <a href="<?= APP_URL ?>/crm/form/<?= h($f['slug']) ?>" target="_blank" class="ms-1 text-muted">
              <i class="bi bi-box-arrow-up-right" style="font-size:.75rem"></i>
            </a>
            <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-muted"
                    data-copy="<?= h(APP_URL.'/crm/form/'.$f['slug']) ?>" title="Kopiuj URL">
              <i class="bi bi-clipboard" style="font-size:.75rem"></i>
            </button>
          </td>
          <td class="small text-muted"><?= h($f['group_name'] ?? '—') ?></td>
          <td><span class="badge bg-light text-dark border"><?= (int)$f['submissions'] ?></span></td>
          <td>
            <?= $f['is_active']
              ? '<span class="badge bg-success-subtle text-success border border-success-subtle">aktywny</span>'
              : '<span class="badge bg-secondary-subtle text-secondary border">nieaktywny</span>' ?>
          </td>
          <td class="d-flex gap-1">
            <a href="?edit=<?= $f['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="_op" value="toggle"><input type="hidden" name="id" value="<?= $f['id'] ?>">
              <button class="btn btn-sm <?= $f['is_active']?'btn-outline-warning':'btn-outline-success' ?> py-0 px-2">
                <?= $f['is_active']?'<i class="bi bi-pause"></i>':'<i class="bi bi-play"></i>' ?>
              </button>
            </form>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="_op" value="delete"><input type="hidden" name="id" value="<?= $f['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-2"
                      data-confirm="Usunąć formularz \"<?= addslashes(h($f['title'])) ?>\"? Zebrane kontakty pozostaną w CRM.">
                <i class="bi bi-trash3"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Formularz tworzenia/edycji -->
<?php if (isset($_GET['new']) || $edit):
  $s = $edit_style; // shortcut
?>
<div class="row g-4">
<div class="col-lg-7">
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white py-2 fw-semibold d-flex align-items-center gap-2">
    <?= $edit ? '<i class="bi bi-pencil me-1"></i>Edytuj: '.h($edit['title']) : '<i class="bi bi-plus-lg me-1"></i>Nowy formularz' ?>
    <?php if ($edit): ?>
    <a href="<?= APP_URL ?>/crm/form/<?= h($edit['slug']) ?>" target="_blank"
       class="btn btn-sm btn-outline-secondary ms-auto py-0 px-2">
      <i class="bi bi-eye me-1"></i>Podgląd
    </a>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <form method="post" id="formEditor">
      <?= csrf_field() ?><input type="hidden" name="_op" value="save">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= $edit['id'] ?>"><?php endif; ?>

      <div class="row g-3 mb-3">
        <div class="col-md-7">
          <label class="form-label small fw-semibold">Tytuł formularza <span class="text-danger">*</span></label>
          <input type="text" name="title" class="form-control" required
                 value="<?= h($edit['title'] ?? '') ?>" placeholder="np. Formularz zgłoszeniowy wolontariusza">
        </div>
        <div class="col-md-5">
          <label class="form-label small fw-semibold">Slug (URL) <span class="text-danger">*</span></label>
          <div class="input-group">
            <span class="input-group-text text-muted small">/crm/form/</span>
            <input type="text" name="slug" class="form-control font-monospace" required
                   value="<?= h($edit['slug'] ?? '') ?>" placeholder="wolontariusz">
          </div>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold">Opis / nagłówek formularza</label>
          <textarea name="description" class="form-control" rows="2"
                    placeholder="Opcjonalny opis widoczny dla wypełniających"><?= h($edit['description'] ?? '') ?></textarea>
        </div>
      </div>

      <!-- Podstawowe -->
      <div class="row g-3 mb-3">
        <div class="col-md-7">
          <label class="form-label small fw-semibold">Tytuł <span class="text-danger">*</span></label>
          <input type="text" name="title" class="form-control" required
                 value="<?= h($edit['title'] ?? '') ?>" placeholder="np. Formularz wolontariusza"
                 oninput="FP.updatePreview()">
        </div>
        <div class="col-md-5">
          <label class="form-label small fw-semibold">Slug (URL) <span class="text-danger">*</span></label>
          <div class="input-group input-group-sm">
            <span class="input-group-text text-muted">/crm/form/</span>
            <input type="text" name="slug" class="form-control font-monospace" required
                   value="<?= h($edit['slug'] ?? '') ?>" placeholder="wolontariusz">
          </div>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold">Opis formularza</label>
          <textarea name="description" class="form-control form-control-sm" rows="2"
                    placeholder="Widoczny pod tytułem" oninput="FP.updatePreview()"><?= h($edit['description'] ?? '') ?></textarea>
        </div>
      </div>

      <!-- Zakładki -->
      <ul class="nav nav-tabs nav-sm mb-3" id="formEditorTabs" style="font-size:.78rem">
        <li class="nav-item"><a class="nav-link active small py-1" data-bs-toggle="tab" href="#ftab-fields">
          <i class="bi bi-list-check me-1"></i>Pola wbudowane
        </a></li>
        <li class="nav-item"><a class="nav-link small py-1" data-bs-toggle="tab" href="#ftab-custom">
          <i class="bi bi-plus-square me-1"></i>Pola dodatkowe
          <?php $custom_count = count(array_filter($edit_fields, fn($f)=>($f['type']??'')==='custom')); if ($custom_count): ?>
          <span class="badge bg-primary ms-1" style="font-size:.6rem"><?= $custom_count ?></span>
          <?php endif; ?>
        </a></li>
        <li class="nav-item"><a class="nav-link small py-1" data-bs-toggle="tab" href="#ftab-rodo">
          <i class="bi bi-shield-check me-1"></i>Zgody RODO
          <?php if ($edit_consents): ?><span class="badge bg-success ms-1" style="font-size:.6rem"><?= count($edit_consents) ?></span><?php endif; ?>
        </a></li>
        <li class="nav-item"><a class="nav-link small py-1" data-bs-toggle="tab" href="#ftab-auto">
          <i class="bi bi-lightning-charge me-1"></i>Automatyzacje
          <?php if ($edit_automations): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.6rem"><?= count($edit_automations) ?></span><?php endif; ?>
        </a></li>
        <li class="nav-item"><a class="nav-link small py-1" data-bs-toggle="tab" href="#ftab-style">
          <i class="bi bi-palette me-1"></i>Wygląd
        </a></li>
        <li class="nav-item"><a class="nav-link small py-1" data-bs-toggle="tab" href="#ftab-settings">
          <i class="bi bi-gear me-1"></i>Ustawienia
        </a></li>
      </ul>

      <div class="tab-content">

        <!-- ── POLA ── -->
        <div class="tab-pane fade show active" id="ftab-fields">
          <div class="card border" style="border-radius:6px">
            <div class="card-body p-0">
              <div class="d-flex px-3 py-1 border-bottom bg-light" style="font-size:.7rem;color:#9CA3AF;font-weight:700;letter-spacing:.06em">
                <span style="flex:1">POLE</span><span style="width:80px;text-align:center">WYMAGANE</span>
              </div>
              <?php
              $active_fields = array_column($edit_fields, null, 'name');
              foreach ($FORM_FIELDS as $fname => $fdef):
                $is_active   = isset($active_fields[$fname]) || $fname === 'imie_nazwisko';
                $is_required = isset($active_fields[$fname]) ? $active_fields[$fname]['required'] : ($fname === 'imie_nazwisko');
                $locked      = $fname === 'imie_nazwisko';
              ?>
              <div class="d-flex align-items-center px-3 py-2 border-bottom" style="border-color:#f1f5f9!important">
                <div class="form-check mb-0 flex-grow-1">
                  <input type="checkbox" class="form-check-input" name="field_<?= $fname ?>" id="f_<?= $fname ?>"
                         <?= $is_active?'checked':'' ?> <?= $locked?'disabled':'' ?>>
                  <?php if ($locked): ?><input type="hidden" name="field_<?= $fname ?>" value="on"><?php endif; ?>
                  <label class="form-check-label small" for="f_<?= $fname ?>"><?= h($fdef['label']) ?></label>
                </div>
                <div class="form-check mb-0" style="width:80px;text-align:center">
                  <input type="checkbox" class="form-check-input" name="req_<?= $fname ?>" id="r_<?= $fname ?>"
                         <?= $is_required?'checked':'' ?> <?= $locked?'disabled':'' ?>>
                  <?php if ($locked): ?><input type="hidden" name="req_<?= $fname ?>" value="on"><?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- ── POLA DODATKOWE ── -->
        <div class="tab-pane fade" id="ftab-custom">
          <div class="mb-2 small text-muted">
            <i class="bi bi-info-circle me-1"></i>
            Pola niestandardowe zdefiniowane w
            <a href="<?= APP_URL ?>/crm/settings/fields.php" target="_blank">CRM → Ustawienia → Pola formularza</a>.
            Wartości są zapisywane w profilu kontaktu.
          </div>

          <?php
          // Już wybrane pola custom
          $edit_custom_fields = array_filter($edit_fields, fn($f) => ($f['type'] ?? '') === 'custom');
          $edit_custom_ids    = array_column(array_values($edit_custom_fields), 'field_def_id');
          ?>

          <!-- Dodane pola custom -->
          <div id="customFieldList">
            <?php foreach (array_values($edit_custom_fields) as $ci => $cf):
              $def = CrmManager::getFieldDef((int)$cf['field_def_id']);
              if (!$def) continue;
            ?>
            <div class="custom-field-item card border mb-2 p-2">
              <input type="hidden" name="custom_field_id[]" value="<?= (int)$def['id'] ?>">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-grip-vertical text-muted"></i>
                <span class="fw-semibold small flex-grow-1">
                  <?= h($def['label']) ?>
                  <span class="badge bg-light text-dark border ms-1" style="font-size:.62rem"><?= h($def['field_type']) ?></span>
                </span>
                <div class="form-check mb-0">
                  <input type="checkbox" class="form-check-input" name="custom_req[<?= $ci ?>]"
                         value="1" <?= !empty($cf['required']) ? 'checked' : '' ?>>
                  <label class="form-check-label small text-danger fw-semibold">Wymagane</label>
                </div>
                <input type="text" name="custom_placeholder[<?= $ci ?>]" class="form-control form-control-sm"
                       style="width:160px" placeholder="Placeholder"
                       value="<?= h($cf['placeholder'] ?? '') ?>">
                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                        onclick="this.closest('.custom-field-item').remove()">
                  <i class="bi bi-x"></i>
                </button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <?php if ($all_custom_fields): ?>
          <!-- Dodaj istniejące pole -->
          <div class="d-flex gap-2 align-items-end mt-2">
            <div class="flex-grow-1">
              <label class="form-label small fw-semibold mb-1">Dodaj pole z definicji</label>
              <select id="customFieldSelect" class="form-select form-select-sm">
                <option value="">— wybierz pole —</option>
                <?php foreach ($all_custom_fields as $fd): ?>
                <option value="<?= $fd['id'] ?>"
                        data-label="<?= h($fd['label']) ?>"
                        data-type="<?= h($fd['field_type']) ?>"
                        data-options="<?= h($fd['options'] ?? '') ?>"
                        <?= in_array((int)$fd['id'], $edit_custom_ids) ? 'disabled' : '' ?>>
                  <?= h($fd['label']) ?> (<?= h($fd['field_type']) ?>)
                  <?= in_array((int)$fd['id'], $edit_custom_ids) ? '✓ dodane' : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="CF.addExisting()">
              <i class="bi bi-plus me-1"></i>Dodaj
            </button>
          </div>
          <?php endif; ?>

          <!-- Utwórz nowe pole inline -->
          <div class="mt-3">
            <button type="button" class="btn btn-link btn-sm p-0 text-muted"
                    data-bs-toggle="collapse" data-bs-target="#newFieldInline">
              <i class="bi bi-plus-circle me-1"></i>Utwórz nowe pole niestandardowe
            </button>
            <div class="collapse mt-2" id="newFieldInline">
              <div class="card border p-3">
                <div class="row g-2">
                  <div class="col-md-4">
                    <label class="form-label small fw-semibold mb-1">Etykieta <span class="text-danger">*</span></label>
                    <input type="text" id="nf_label" class="form-control form-control-sm" placeholder="np. Skąd nas znasz?">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Typ</label>
                    <select id="nf_type" class="form-select form-select-sm" onchange="CF.toggleNfOptions()">
                      <option value="text">Tekst</option>
                      <option value="textarea">Tekst długi</option>
                      <option value="select">Lista wyboru</option>
                      <option value="checkbox">Checkbox</option>
                      <option value="number">Liczba</option>
                      <option value="date">Data</option>
                    </select>
                  </div>
                  <div class="col-md-5" id="nf_options_wrap" style="display:none">
                    <label class="form-label small fw-semibold mb-1">Opcje (jedna na linię)</label>
                    <textarea id="nf_options" class="form-control form-control-sm font-monospace" rows="2"
                              placeholder="Opcja A&#10;Opcja B"></textarea>
                  </div>
                </div>
                <div class="mt-2">
                  <button type="button" class="btn btn-warning btn-sm" onclick="CF.createAndAdd()">
                    <i class="bi bi-plus-circle me-1"></i>Utwórz i dodaj do formularza
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ── AUTOMATYZACJE ── -->
        <div class="tab-pane fade" id="ftab-auto">
          <?php
          $AUTO_TYPES = [
            'send_email'       => ['label'=>'Wyślij e-mail do zgłaszającego', 'icon'=>'bi-envelope-fill',    'color'=>'#0176D3'],
            'send_notification'=> ['label'=>'Powiadom wewnętrzny adres',       'icon'=>'bi-bell-fill',        'color'=>'#7F2B8B'],
            'add_tag'          => ['label'=>'Dodaj tagi',                       'icon'=>'bi-tag-fill',         'color'=>'#2E844A'],
            'set_status'       => ['label'=>'Zmień status kontaktu',            'icon'=>'bi-bookmark-fill',   'color'=>'#FE9339'],
            'nozbe_task'       => ['label'=>'Utwórz zadanie w Nozbe',           'icon'=>'bi-check2-square',   'color'=>'#1a9c3e'],
            'webhook'          => ['label'=>'Wywołaj webhook (HTTP POST)',       'icon'=>'bi-braces',          'color'=>'#374151'],
          ];
          ?>
          <div class="mb-2 small text-muted">
            <i class="bi bi-info-circle me-1"></i>
            Automatyzacje uruchamiają się po każdym pomyślnym zgłoszeniu. Kolejność ma znaczenie.
          </div>
          <div id="autoList">
            <?php foreach ($edit_automations as $ai => $auto):
              $adef = $AUTO_TYPES[$auto['type']] ?? null;
              if (!$adef) continue;
            ?>
            <div class="auto-item card border mb-2" data-index="<?= $ai ?>">
              <div class="card-body p-2">
                <input type="hidden" name="auto_type[<?= $ai ?>]" value="<?= h($auto['type']) ?>">
                <div class="d-flex align-items-center gap-2 mb-2">
                  <i class="bi <?= h($adef['icon']) ?>" style="color:<?= h($adef['color']) ?>;font-size:1rem"></i>
                  <span class="fw-semibold small flex-grow-1"><?= h($adef['label']) ?></span>
                  <div class="form-check form-switch mb-0">
                    <input type="checkbox" class="form-check-input" name="auto_enabled[<?= $ai ?>]"
                           value="1" <?= !empty($auto['enabled']) ? 'checked' : '' ?>>
                    <label class="form-check-label small text-muted">Aktywna</label>
                  </div>
                  <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                          onclick="this.closest('.auto-item').remove()">
                    <i class="bi bi-x"></i>
                  </button>
                </div>
                <?= renderAutoConfig($ai, $auto['type'], $auto['config'] ?? []) ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="d-flex gap-2 flex-wrap mt-2">
            <?php foreach ($AUTO_TYPES as $atype => $adef): ?>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    onclick="AT.add('<?= $atype ?>')">
              <i class="bi <?= h($adef['icon']) ?>" style="color:<?= h($adef['color']) ?>"></i>
              <?= h($adef['label']) ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- ── ZGODY RODO ── -->
        <div class="tab-pane fade" id="ftab-rodo">
          <div class="mb-2 small text-muted">
            <i class="bi bi-info-circle me-1"></i>
            Zgody pojawią się jako checkboxy pod formularzem. Zaznaczenie zgody (wymaganej) blokuje wysyłkę.
            Udzielone zgody są logowane jako tagi kontaktu.
          </div>
          <div id="consentList">
            <?php foreach ($edit_consents as $ci => $con): ?>
            <div class="consent-item card border mb-2" data-index="<?= $ci ?>">
              <div class="card-body p-2">
                <div class="d-flex align-items-start gap-2 mb-2">
                  <span class="badge bg-secondary mt-1"><?= $ci + 1 ?></span>
                  <textarea name="consent_text[]" rows="2" class="form-control form-control-sm flex-grow-1"
                            placeholder="Treść zgody np. Wyrażam zgodę na przetwarzanie moich danych osobowych..."><?= h($con['text']) ?></textarea>
                  <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1 mt-1"
                          onclick="this.closest('.consent-item').remove(); FC.reindexConsents()">
                    <i class="bi bi-x"></i>
                  </button>
                </div>
                <div class="row g-2 align-items-center">
                  <div class="col-auto">
                    <div class="form-check mb-0">
                      <input type="checkbox" class="form-check-input" name="consent_required[<?= $ci ?>]"
                             value="1" <?= !empty($con['required'])?'checked':'' ?>>
                      <label class="form-check-label small fw-semibold text-danger">Wymagana</label>
                    </div>
                  </div>
                  <div class="col">
                    <input type="url" name="consent_link[]" class="form-control form-control-sm"
                           placeholder="Link do polityki prywatności (opcjonalnie)"
                           value="<?= h($con['link'] ?? '') ?>">
                  </div>
                  <div class="col-auto">
                    <input type="text" name="consent_link_text[]" class="form-control form-control-sm"
                           style="width:140px" placeholder="Tekst linku"
                           value="<?= h($con['link_text'] ?? 'Więcej informacji') ?>">
                  </div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-outline-primary btn-sm" onclick="FC.addConsent()">
            <i class="bi bi-plus-circle me-1"></i>Dodaj zgodę
          </button>
          <div class="mt-2 p-2 bg-light rounded small text-muted">
            <strong>Przykład:</strong> "Wyrażam zgodę na przetwarzanie moich danych osobowych przez
            <?= h(defined('ORG_NAME') ? ORG_NAME : 'Organizację') ?> w celu realizacji wolontariatu, zgodnie z
            <a href="#">Polityką prywatności</a>."
          </div>
        </div>

        <!-- ── WYGLĄD ── -->
        <div class="tab-pane fade" id="ftab-style">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Kolor akcentu</label>
              <div class="input-group input-group-sm">
                <input type="color" name="style_accent" class="form-control form-control-color"
                       value="<?= h($s['accent'] ?? '#0176D3') ?>"
                       oninput="FP.updatePreview()">
                <input type="text" class="form-control font-monospace"
                       value="<?= h($s['accent'] ?? '#0176D3') ?>"
                       oninput="this.previousElementSibling.value=this.value;FP.updatePreview()">
              </div>
              <div class="form-text">Nagłówek i przycisk</div>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Tło strony</label>
              <div class="input-group input-group-sm">
                <input type="color" name="style_bg" class="form-control form-control-color"
                       value="<?= h($s['bg'] ?? '#f8fafc') ?>"
                       oninput="FP.updatePreview()">
                <input type="text" class="form-control font-monospace"
                       value="<?= h($s['bg'] ?? '#f8fafc') ?>"
                       oninput="this.previousElementSibling.value=this.value;FP.updatePreview()">
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Szerokość karty (px)</label>
              <input type="range" name="style_max_width" class="form-range"
                     min="320" max="800" step="10"
                     value="<?= (int)($s['max_width'] ?? 520) ?>"
                     oninput="document.getElementById('mw_val').textContent=this.value+'px'; FP.updatePreview()">
              <div class="text-muted small">
                <span id="mw_val"><?= (int)($s['max_width'] ?? 520) ?>px</span>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Zaokrąglenie karty (px)</label>
              <input type="range" name="style_rounded" class="form-range"
                     min="0" max="24" step="2"
                     value="<?= (int)($s['rounded'] ?? 14) ?>"
                     oninput="document.getElementById('rounded_val').textContent=this.value+'px'; FP.updatePreview()">
              <div class="text-muted small"><span id="rounded_val"><?= (int)($s['rounded'] ?? 14) ?>px</span></div>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Czcionka</label>
              <select name="style_font" class="form-select form-select-sm" onchange="FP.updatePreview()">
                <option value="system" <?= ($s['font']??'system')==='system'?'selected':'' ?>>System (domyślna)</option>
                <option value="serif"  <?= ($s['font']??'')==='serif'?'selected':'' ?>>Szeryfowa</option>
                <option value="mono"   <?= ($s['font']??'')==='mono'?'selected':'' ?>>Monospacowa</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Tekst przycisku</label>
              <input type="text" name="style_btn_text" class="form-control form-control-sm"
                     value="<?= h($s['btn_text'] ?? 'Wyślij zgłoszenie') ?>"
                     oninput="FP.updatePreview()">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Logo (URL)</label>
              <input type="url" name="style_logo_url" class="form-control form-control-sm"
                     value="<?= h($s['logo_url'] ?? '') ?>"
                     placeholder="https://example.com/logo.png"
                     oninput="FP.updatePreview()">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Tekst w nagłówku (nadpis)</label>
              <input type="text" name="style_header_text" class="form-control form-control-sm"
                     value="<?= h($s['header_text'] ?? '') ?>"
                     placeholder="np. Fundacja XYZ"
                     oninput="FP.updatePreview()">
            </div>
          </div>
        </div>

        <!-- ── USTAWIENIA ── -->
        <div class="tab-pane fade" id="ftab-settings">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Domyślny status kontaktu</label>
              <select name="default_status" class="form-select form-select-sm">
                <?php foreach (crm_statuses() as $sk => $sv): ?>
                <option value="<?= h($sk) ?>" <?= ($edit['default_status']??'prospect')===$sk?'selected':'' ?>>
                  <?= h($sv['label']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Dodaj do grupy CRM</label>
              <select name="group_id" class="form-select form-select-sm">
                <option value="">— bez grupy —</option>
                <?php foreach ($all_groups as $g): ?>
                <option value="<?= $g['id'] ?>" <?= (int)($edit['group_id']??0)===(int)$g['id']?'selected':'' ?>>
                  <?= h($g['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Powiadom e-mail</label>
              <input type="email" name="notify_email" class="form-control form-control-sm"
                     value="<?= h($edit['notify_email'] ?? '') ?>" placeholder="admin@org.pl">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Komunikat po wysłaniu</label>
              <input type="text" name="success_msg" class="form-control form-control-sm"
                     value="<?= h($edit['success_msg'] ?? 'Dziękujemy! Twoje zgłoszenie zostało przyjęte.') ?>">
            </div>
            <div class="col-auto">
              <div class="form-check">
                <input type="checkbox" name="is_active" class="form-check-input" id="chkActive"
                       value="1" <?= ($edit['is_active']??1)?'checked':'' ?>>
                <label for="chkActive" class="form-check-label small">Formularz aktywny</label>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /tab-content -->

      <div class="d-flex gap-2 mt-3 pt-3 border-top">
        <button type="submit" class="btn btn-crm-primary btn-sm">
          <i class="bi bi-save me-1"></i><?= $edit ? 'Zapisz zmiany' : 'Utwórz formularz' ?>
        </button>
        <a href="<?= APP_URL ?>/crm/form/manage.php" class="btn btn-outline-secondary btn-sm">Anuluj</a>
        <?php if ($edit): ?>
        <a href="<?= APP_URL ?>/crm/form/<?= h($edit['slug']) ?>" target="_blank"
           class="btn btn-outline-primary btn-sm ms-auto">
          <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz formularz
        </a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>
</div><!-- /col-7 -->

<!-- Live preview -->
<div class="col-lg-5 d-none d-lg-block">
  <div class="sticky-top" style="top:1rem">
    <div class="text-muted small fw-semibold mb-2">
      <i class="bi bi-eye me-1"></i>Podgląd na żywo
    </div>
    <div id="formPreview" style="border-radius:10px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.1)">
      <!-- Wypełniane przez JS -->
    </div>
  </div>
</div>
</div><!-- /row -->
<?php endif; ?>

<?php if (isset($_GET['new']) || $edit): ?>
<script>
// ── Pola dodatkowe ────────────────────────────────────────────────────────────
const CF = {
  idx: <?= count(array_filter($edit_fields, fn($f)=>($f['type']??'')==='custom')) ?>,

  addExisting() {
    const sel = document.getElementById('customFieldSelect');
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;
    const id    = opt.value;
    const label = opt.dataset.label;
    const type  = opt.dataset.type;
    const i = this.idx++;
    const div = document.createElement('div');
    div.className = 'custom-field-item card border mb-2 p-2';
    div.innerHTML = `
      <input type="hidden" name="custom_field_id[]" value="${id}">
      <div class="d-flex align-items-center gap-2">
        <i class="bi bi-grip-vertical text-muted"></i>
        <span class="fw-semibold small flex-grow-1">${label} <span class="badge bg-light text-dark border ms-1" style="font-size:.62rem">${type}</span></span>
        <div class="form-check mb-0">
          <input type="checkbox" class="form-check-input" name="custom_req[${i}]" value="1">
          <label class="form-check-label small text-danger fw-semibold">Wymagane</label>
        </div>
        <input type="text" name="custom_placeholder[${i}]" class="form-control form-control-sm"
               style="width:160px" placeholder="Placeholder">
        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                onclick="this.closest('.custom-field-item').remove()">
          <i class="bi bi-x"></i>
        </button>
      </div>`;
    document.getElementById('customFieldList').appendChild(div);
    opt.disabled = true;
    sel.value = '';
  },

  toggleNfOptions() {
    const t = document.getElementById('nf_type').value;
    document.getElementById('nf_options_wrap').style.display = t === 'select' ? '' : 'none';
  },

  async createAndAdd() {
    const label   = document.getElementById('nf_label').value.trim();
    const type    = document.getElementById('nf_type').value;
    const options = document.getElementById('nf_options').value.trim();
    if (!label) { alert('Podaj etykietę pola.'); return; }

    const fd = new FormData();
    fd.append('_csrf', '<?= csrf_token() ?>');
    fd.append('_op',       'create_field');
    fd.append('label',     label);
    fd.append('field_type',type);
    fd.append('options_raw', options);
    const res = await fetch('<?= APP_URL ?>/crm/settings/fields.php', { method: 'POST', body: fd })
      .then(r => r.text()).catch(() => '');

    // Poczekaj chwilę i dodaj do listy bez odświeżania
    const i = this.idx++;
    const div = document.createElement('div');
    div.className = 'custom-field-item card border mb-2 p-2';
    div.innerHTML = `
      <input type="hidden" name="custom_field_id[]" value="__new_${label}">
      <div class="d-flex align-items-center gap-2 p-1 bg-warning-subtle rounded">
        <i class="bi bi-clock text-warning"></i>
        <span class="small flex-grow-1 fw-semibold">${label} <span class="badge bg-warning text-dark ms-1">nowe — zapisz formularz</span></span>
      </div>`;
    document.getElementById('customFieldList').appendChild(div);
    document.getElementById('nf_label').value = '';
    bootstrap.Collapse.getInstance(document.getElementById('newFieldInline'))?.hide();
    alert('Pole zostanie dodane po zapisaniu formularza. Jeśli potrzebujesz jego ID — najpierw utwórz w Ustawieniach CRM.');
  }
};

// ── Automatyzacje ─────────────────────────────────────────────────────────────
<?php
// Reużywa listę projektów Nozbe pobraną (co najwyżej raz) przez renderAutoConfig() —
// bez tego każda automatyzacja typu nozbe_task + ten blok JS robiły osobne wywołanie
// zewnętrznego API Nozbe na jednym ładowaniu strony (ryzyko timeoutu/502 przy wolnym API).
$nozbe_projects_for_js = [];
foreach (getNozbeProjectsOnce() as $p) {
    $name = trim($p['name'] ?? '');
    if ($name && empty($p['is_single_actions']) && empty($p['is_template'])) {
        $nozbe_projects_for_js[] = ['id'=>$p['id'], 'name'=>$name];
    }
}
$nozbe_default_proj_js = nozbe_setting('nozbe_default_project_id');
?>
const AT = {
  idx: <?= count($edit_automations) ?>,
  nozbeProjects: <?= json_encode($nozbe_projects_for_js, JSON_UNESCAPED_UNICODE) ?>,
  nozbeDefault:  <?= json_encode($nozbe_default_proj_js) ?>,
  configs: <?= json_encode([
    'send_email'        => ['subject'=>'Dziękujemy za Twoje zgłoszenie!','body'=>'Witaj {imie_nazwisko},\n\nDziękujemy za zgłoszenie!\n\nPozdrawiamy'],
    'send_notification' => ['email'=>'','subject'=>'Nowe zgłoszenie z formularza'],
    'add_tag'           => ['tags'=>'webform'],
    'set_status'        => ['status'=>'prospect'],
    'nozbe_task'        => ['task_name'=>'Nowe zgłoszenie: {imie_nazwisko}','project_id'=>'','due_days'=>'1'],
    'webhook'           => ['url'=>'','secret'=>''],
  ], JSON_UNESCAPED_UNICODE) ?>,
  labels: {
    send_email:        ['bi-envelope-fill','#0176D3','Wyślij e-mail do zgłaszającego'],
    send_notification: ['bi-bell-fill','#7F2B8B','Powiadom wewnętrzny adres'],
    add_tag:           ['bi-tag-fill','#2E844A','Dodaj tagi'],
    set_status:        ['bi-bookmark-fill','#FE9339','Zmień status kontaktu'],
    nozbe_task:        ['bi-check2-square','#1a9c3e','Utwórz zadanie w Nozbe'],
    webhook:           ['bi-braces','#374151','Wywołaj webhook'],
  },

  add(type) {
    const i    = this.idx++;
    const cfg  = this.configs[type] || {};
    const lbl  = this.labels[type]  || ['bi-gear','#6B7280',type];
    const div  = document.createElement('div');
    div.className = 'auto-item card border mb-2';
    div.dataset.index = i;

    let configHtml = this.buildConfigHtml(i, type, cfg);

    div.innerHTML = `
      <div class="card-body p-2">
        <input type="hidden" name="auto_type[${i}]" value="${type}">
        <div class="d-flex align-items-center gap-2 mb-2">
          <i class="bi ${lbl[0]}" style="color:${lbl[1]};font-size:1rem"></i>
          <span class="fw-semibold small flex-grow-1">${lbl[2]}</span>
          <div class="form-check form-switch mb-0">
            <input type="checkbox" class="form-check-input" name="auto_enabled[${i}]" value="1" checked>
            <label class="form-check-label small text-muted">Aktywna</label>
          </div>
          <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                  onclick="this.closest('.auto-item').remove()">
            <i class="bi bi-x"></i>
          </button>
        </div>
        <div class="auto-config">${configHtml}</div>
      </div>`;
    document.getElementById('autoList').appendChild(div);
  },

  buildConfigHtml(i, type, cfg) {
    const n = k => `auto_config[${i}][${k}]`;
    const v = (k, d='') => (cfg[k] || d).replace(/"/g,'&quot;');

    if (type === 'send_email') return `
      <div class="row g-2">
        <div class="col-12"><label class="form-label small mb-1">Temat</label>
          <input type="text" name="${n('subject')}" class="form-control form-control-sm"
                 value="${v('subject','Dziękujemy za zgłoszenie!')}"></div>
        <div class="col-12"><label class="form-label small mb-1">Treść (zmienne: {imie_nazwisko} {email})</label>
          <textarea name="${n('body')}" rows="3" class="form-control form-control-sm font-monospace">${v('body')}</textarea></div>
      </div>`;
    if (type === 'send_notification') return `
      <div class="row g-2">
        <div class="col-md-6"><label class="form-label small mb-1">Adres e-mail</label>
          <input type="email" name="${n('email')}" class="form-control form-control-sm" value="${v('email')}"></div>
        <div class="col-md-6"><label class="form-label small mb-1">Temat</label>
          <input type="text" name="${n('subject')}" class="form-control form-control-sm" value="${v('subject','Nowe zgłoszenie')}"></div>
      </div>`;
    if (type === 'add_tag') return `
      <label class="form-label small mb-1">Tagi (oddziel przecinkami)</label>
      <input type="text" name="${n('tags')}" class="form-control form-control-sm" value="${v('tags','webform')}">`;
    if (type === 'set_status') return `
      <label class="form-label small mb-1">Nowy status</label>
      <select name="${n('status')}" class="form-select form-select-sm">
        <?php foreach (crm_statuses() as $sk=>$sv): ?>
        <option value="<?= $sk ?>">${v('status')==='<?= $sk ?>'?'selected':''} style=""><?= h($sv['label']) ?></option>
        <?php endforeach; ?>
      </select>`;
    if (type === 'nozbe_task') {
      const projOpts = '<option value="">— domyślny projekt —</option>'
        + AT.nozbeProjects.map(p => `<option value="${p.id}" ${(v('project_id')||AT.nozbeDefault)===p.id?'selected':''}>${p.name}</option>`).join('');
      const nozbeNote = AT.nozbeProjects.length === 0
        ? '<div class="form-text text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Brak projektów — <a href="<?= APP_URL ?>/crm/settings/nozbe.php">skonfiguruj Nozbe</a></div>'
        : '';
      return `
      <div class="row g-2">
        <div class="col-md-5"><label class="form-label small mb-1">Nazwa zadania</label>
          <input type="text" name="${n('task_name')}" class="form-control form-control-sm"
                 placeholder="{imie_nazwisko}" value="${v('task_name','Nowe zgłoszenie: {imie_nazwisko}')}"></div>
        <div class="col-md-4"><label class="form-label small mb-1">Projekt</label>
          <select name="${n('project_id')}" class="form-select form-select-sm">${projOpts}</select>
          ${nozbeNote}</div>
        <div class="col-md-3"><label class="form-label small mb-1">Za (dni)</label>
          <input type="number" name="${n('due_days')}" class="form-control form-control-sm"
                 min="0" max="365" value="${v('due_days','1')}"></div>
      </div>`;
    }
    if (type === 'webhook') return `
      <div class="row g-2">
        <div class="col-md-8"><label class="form-label small mb-1">URL</label>
          <input type="url" name="${n('url')}" class="form-control form-control-sm font-monospace" value="${v('url')}"></div>
        <div class="col-md-4"><label class="form-label small mb-1">Secret</label>
          <input type="text" name="${n('secret')}" class="form-control form-control-sm" value="${v('secret')}"></div>
      </div>`;
    return '';
  }
};

// ── Zgody RODO ────────────────────────────────────────────────────────────────
const FC = {
  idx: <?= count($edit_consents) ?>,
  addConsent() {
    const i = this.idx++;
    const div = document.createElement('div');
    div.className = 'consent-item card border mb-2';
    div.dataset.index = i;
    div.innerHTML = `
      <div class="card-body p-2">
        <div class="d-flex align-items-start gap-2 mb-2">
          <span class="badge bg-secondary mt-1">${document.querySelectorAll('.consent-item').length + 1}</span>
          <textarea name="consent_text[]" rows="2" class="form-control form-control-sm flex-grow-1"
                    placeholder="Treść zgody..."></textarea>
          <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1 mt-1"
                  onclick="this.closest('.consent-item').remove(); FC.reindexConsents()">
            <i class="bi bi-x"></i>
          </button>
        </div>
        <div class="row g-2 align-items-center">
          <div class="col-auto">
            <div class="form-check mb-0">
              <input type="checkbox" class="form-check-input" name="consent_required[${i}]" value="1">
              <label class="form-check-label small fw-semibold text-danger">Wymagana</label>
            </div>
          </div>
          <div class="col">
            <input type="url" name="consent_link[]" class="form-control form-control-sm"
                   placeholder="Link do polityki prywatności (opcjonalnie)">
          </div>
          <div class="col-auto">
            <input type="text" name="consent_link_text[]" class="form-control form-control-sm"
                   style="width:140px" placeholder="Tekst linku" value="Więcej informacji">
          </div>
        </div>
      </div>`;
    document.getElementById('consentList').appendChild(div);
  },
  reindexConsents() {
    document.querySelectorAll('.consent-item').forEach((el, i) => {
      const badge = el.querySelector('.badge');
      if (badge) badge.textContent = i + 1;
    });
  }
};

// ── Live Preview ──────────────────────────────────────────────────────────────
const FP = {
  updatePreview() {
    const f    = document.getElementById('formEditor');
    const accent = f.querySelector('[name="style_accent"]')?.value || '#0176D3';
    const bg     = f.querySelector('[name="style_bg"]')?.value     || '#f8fafc';
    const mw     = f.querySelector('[name="style_max_width"]')?.value || 520;
    const rounded= f.querySelector('[name="style_rounded"]')?.value  || 14;
    const btnTxt = f.querySelector('[name="style_btn_text"]')?.value || 'Wyślij';
    const logo   = f.querySelector('[name="style_logo_url"]')?.value  || '';
    const htxt   = f.querySelector('[name="style_header_text"]')?.value || '';
    const title  = f.querySelector('[name="title"]')?.value || 'Tytuł formularza';
    const desc   = f.querySelector('[name="description"]')?.value || '';
    const font   = f.querySelector('[name="style_font"]')?.value || 'system';

    const fontFam = font === 'serif' ? 'Georgia,serif' : font === 'mono' ? 'monospace' : 'system-ui,-apple-system,sans-serif';
    const isLight = this.luminance(accent) > 0.35;
    const txtColor= isLight ? '#1f2937' : '#fff';

    // Zbierz zaznaczone pola
    const fields = [];
    f.querySelectorAll('[name^="field_"]:checked').forEach(chk => {
      const nm = chk.name.replace('field_','');
      const labels = {imie_nazwisko:'Imię i nazwisko',email:'E-mail',telefon:'Telefon',organizacja:'Organizacja',stanowisko:'Stanowisko',adres:'Adres',notatka:'Wiadomość',wojewodztwo:'Województwo',powiat:'Powiat',gmina:'Gmina'};
      fields.push(labels[nm] || nm);
    });

    // Zbierz zgody
    const consents = [];
    f.querySelectorAll('.consent-item textarea').forEach(ta => {
      if (ta.value.trim()) consents.push(ta.value.trim().slice(0,80) + (ta.value.length > 80 ? '...' : ''));
    });

    const preview = document.getElementById('formPreview');
    if (!preview) return;
    preview.innerHTML = `
<div style="background:${bg};padding:1.5rem;font-family:${fontFam};font-size:.9rem">
  <div style="max-width:${mw}px;margin:0 auto;background:#fff;border-radius:${rounded}px;box-shadow:0 4px 16px rgba(0,0,0,.08);overflow:hidden">
    <div style="background:${accent};color:${txtColor};padding:1.5rem 1.75rem">
      ${logo ? `<img src="${logo}" style="height:32px;max-width:100px;object-fit:contain;margin-bottom:.5rem;display:block" onerror="this.style.display='none'">` : ''}
      ${htxt ? `<div style="font-size:.7rem;opacity:.75;margin-bottom:.3rem">${this.esc(htxt)}</div>` : ''}
      <div style="font-size:1.1rem;font-weight:700">${this.esc(title)}</div>
      ${desc ? `<div style="font-size:.82rem;opacity:.8;margin-top:.25rem">${this.esc(desc)}</div>` : ''}
    </div>
    <div style="padding:1.25rem 1.75rem">
      ${fields.map(f => `
        <div style="margin-bottom:.75rem">
          <label style="font-size:.78rem;font-weight:600;color:#374151;display:block;margin-bottom:.2rem">${f}</label>
          <div style="height:32px;border:1px solid #D1D5DB;border-radius:6px;background:#fff"></div>
        </div>`).join('')}
      ${consents.map((c,i) => `
        <div style="margin:.6rem 0;display:flex;align-items:flex-start;gap:.5rem;font-size:.75rem;color:#374151">
          <div style="width:14px;height:14px;border:2px solid ${accent};border-radius:3px;flex-shrink:0;margin-top:1px"></div>
          <span>${this.esc(c)}</span>
        </div>`).join('')}
      <div style="margin-top:1rem;background:${accent};color:${txtColor};padding:.6rem;text-align:center;border-radius:6px;font-weight:600;font-size:.88rem;cursor:default">
        ${this.esc(btnTxt)}
      </div>
    </div>
  </div>
</div>`;
  },

  luminance(hex) {
    hex = hex.replace('#','');
    if (hex.length === 3) hex = hex.split('').map(c=>c+c).join('');
    const r=parseInt(hex.slice(0,2),16)/255, g=parseInt(hex.slice(2,4),16)/255, b=parseInt(hex.slice(4,6),16)/255;
    const lin = c => c <= .03928 ? c/12.92 : ((c+.055)/1.055)**2.4;
    return .2126*lin(r) + .7152*lin(g) + .0722*lin(b);
  },

  esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
};

// Inicjuj preview
document.addEventListener('DOMContentLoaded', () => FP.updatePreview());
// Kolor + hex sync
document.querySelectorAll('input[type="color"]').forEach(col => {
  col.addEventListener('input', () => {
    const nxt = col.nextElementSibling;
    if (nxt && nxt.tagName==='INPUT') nxt.value = col.value;
  });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
