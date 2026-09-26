<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/events.php';

require_login();
require_module_enabled('events_enabled', 'Moduł wydarzeń');

// Auto-migracja kolumn dodanych po pierwszym wdrożeniu
foreach ([
    "ALTER TABLE ev_events ADD COLUMN rodo_clause      TEXT",
    "ALTER TABLE ev_events ADD COLUMN gdpr_clause_slug VARCHAR(64)",
    "ALTER TABLE ev_events ADD COLUMN notify_new_reg   INTEGER NOT NULL DEFAULT 1",
    "ALTER TABLE ev_events ADD COLUMN notify_email     TEXT",
    "ALTER TABLE ev_events ADD COLUMN crm_auto_sync    INTEGER NOT NULL DEFAULT 1",
    "ALTER TABLE ev_events ADD COLUMN reg_always_open  INTEGER NOT NULL DEFAULT 0",
] as $_sql) { try { db()->exec($_sql); } catch (\Throwable $e) {} }

$event_id = (int)($_GET['id'] ?? 0);
if (!$event_id) { flash_set('error', 'Brak ID wydarzenia.'); header('Location: ' . APP_URL . '/events/index.php'); exit; }

$event = db_one("SELECT * FROM ev_events WHERE id=?", [$event_id]);
if (!$event) { flash_set('error', 'Wydarzenie nie istnieje.'); header('Location: ' . APP_URL . '/events/index.php'); exit; }

ev_require_role($event_id, ['admin']);

$PAGE_TITLE = 'Edycja: ' . ($event['title'] ?? '');
$EV_ID = $event_id;
$tab = $_GET['tab'] ?? 'basic';
$errors = [];

// Load form fields
$form_fields = [];
try { $form_fields = db_all("SELECT * FROM ev_form_fields WHERE event_id=? ORDER BY position ASC", [$event_id]); } catch (\Throwable $e) {}

// Load CRM groups for selector
$crm_groups = [];
try { $crm_groups = db_all("SELECT id, name FROM crm_groups ORDER BY name ASC"); } catch (\Throwable $e) {}

// Klauzula z rejestru (modules/gdpr_clauses) — osobna akcja, bo update_basic
// przepisuje wszystkie pola z ukrytych inputów każdej zakładki.
require_once dirname(__DIR__) . '/modules/gdpr_clauses/logic/gdpr_clauses.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_gdpr_clause') {
    csrf_check();
    $gslug = (string)($_POST['gdpr_clause_slug'] ?? '');
    db()->prepare("UPDATE ev_events SET gdpr_clause_slug=?, updated_at=? WHERE id=?")
        ->execute([preg_match(GDPR_SLUG_RE, $gslug) ? $gslug : null, date('Y-m-d H:i:s'), $event_id]);
    flash_set('success', 'Klauzula z rejestru zapisana.');
    header('Location: ' . APP_URL . '/events/edit.php?id=' . $event_id . '&tab=rodo');
    exit;
}

// Handle POST for basic data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_basic') {
    csrf_check();
    $f = [];
    foreach (['title','slug','description','type','venue','address','meeting_url',
              'start_at','end_at','capacity','reg_open_at','reg_close_at','pa_webhook_url','cover_image',
              'crm_group_id','rodo_clause','notify_email'] as $k) {
        $f[$k] = trim($_POST[$k] ?? '');
    }
    $f['is_public']       = isset($_POST['is_public'])       ? '1' : '0';
    $f['notify_new_reg']  = isset($_POST['notify_new_reg'])  ? '1' : '0';
    $f['crm_auto_sync']   = isset($_POST['crm_auto_sync'])   ? '1' : '0';
    $f['reg_always_open'] = isset($_POST['reg_always_open']) ? '1' : '0';

    if (!$f['title'])   $errors[] = 'Tytuł jest wymagany.';
    if (!$f['slug'])    $errors[] = 'Slug jest wymagany.';
    if (!$f['start_at']) $errors[] = 'Data rozpoczęcia jest wymagana.';

    // Slug uniqueness (excluding self)
    if (!$errors) {
        $exists = db_one("SELECT id FROM ev_events WHERE slug=? AND id!=?", [$f['slug'], $event_id]);
        if ($exists) $errors[] = 'Ten slug jest już zajęty.';
    }

    if (!$errors) {
        db()->prepare(
            "UPDATE ev_events SET title=?,slug=?,description=?,type=?,venue=?,address=?,meeting_url=?,
             start_at=?,end_at=?,capacity=?,is_public=?,reg_open_at=?,reg_close_at=?,
             pa_webhook_url=?,cover_image=?,crm_group_id=?,
             rodo_clause=?,notify_new_reg=?,notify_email=?,crm_auto_sync=?,reg_always_open=?,
             updated_at=? WHERE id=?"
        )->execute([
            $f['title'], $f['slug'], $f['description'], $f['type'],
            $f['venue'], $f['address'], $f['meeting_url'],
            $f['start_at'] ?: null, $f['end_at'] ?: null,
            $f['capacity'] !== '' ? (int)$f['capacity'] : null,
            (int)$f['is_public'],
            $f['reg_open_at'] ?: null, $f['reg_close_at'] ?: null,
            $f['pa_webhook_url'], $f['cover_image'],
            $f['crm_group_id'] !== '' ? (int)$f['crm_group_id'] : null,
            $f['rodo_clause'],
            (int)$f['notify_new_reg'],
            $f['notify_email'] ?: null,
            (int)$f['crm_auto_sync'],
            (int)$f['reg_always_open'],
            date('Y-m-d H:i:s'),
            $event_id,
        ]);
        flash_set('success', 'Dane zostały zaktualizowane.');
        header('Location: ' . APP_URL . '/events/edit.php?id=' . $event_id . '&tab=basic');
        exit;
    }
    // Re-read event for re-rendering
    $event = db_one("SELECT * FROM ev_events WHERE id=?", [$event_id]);
}

include dirname(__DIR__) . '/events/includes/header_events.php';
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
    <a href="<?= APP_URL ?>/events/manage.php?id=<?= $event_id ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="mb-0 fw-bold"><i class="bi bi-pencil me-2" style="color:var(--ev-purple)"></i><?= h($event['title']) ?></h4>
    <span class="badge bg-<?= h(EV_STATUS[$event['status']]['class'] ?? 'secondary') ?> ms-1">
        <?= h(EV_STATUS[$event['status']]['label'] ?? $event['status']) ?>
    </span>
    <a href="<?= APP_URL ?>/events/public/preview.php?id=<?= $event_id ?>" target="_blank"
       class="btn btn-sm ms-auto d-flex align-items-center gap-1"
       style="background:var(--ev-purple-bg);color:var(--ev-purple);border:1px solid #ddd6fe">
        <i class="bi bi-eye"></i><span>Podgląd formularza</span>
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab==='basic'?'active':'' ?>" href="?id=<?= $event_id ?>&tab=basic">
            <i class="bi bi-info-circle me-1"></i>Dane podstawowe
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='fields'?'active':'' ?>" href="?id=<?= $event_id ?>&tab=fields">
            <i class="bi bi-ui-checks me-1"></i>Pola formularza
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='rodo'?'active':'' ?>" href="?id=<?= $event_id ?>&tab=rodo">
            <i class="bi bi-shield-check me-1"></i>RODO
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='notifications'?'active':'' ?>" href="?id=<?= $event_id ?>&tab=notifications">
            <i class="bi bi-bell me-1"></i>Powiadomienia & CRM
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='advanced'?'active':'' ?>" href="?id=<?= $event_id ?>&tab=advanced">
            <i class="bi bi-gear me-1"></i>Zaawansowane
        </a>
    </li>
</ul>

<!-- Basic Tab -->
<?php if ($tab === 'basic'): ?>
<div class="card shadow-sm border-0" style="max-width:760px">
    <div class="card-body">
        <form method="post" id="ev-edit-form">
            <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action"  value="update_basic">

            <div class="mb-3">
                <label for="title" class="form-label fw-semibold">Tytuł <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="title" name="title" value="<?= h($event['title']) ?>" required>
            </div>

            <div class="mb-3">
                <label for="slug" class="form-label fw-semibold">Slug <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text text-muted small">/register.php?slug=</span>
                    <input type="text" class="form-control" id="slug" name="slug" value="<?= h($event['slug']) ?>"
                           pattern="[a-z0-9\-]+" required>
                </div>
                <?php if ($event['is_public'] && $event['slug']): ?>
                <div class="mt-2 p-2 rounded d-flex align-items-center gap-2" style="background:#f5f3ff;border:1px solid #ede9fe">
                    <i class="bi bi-link-45deg text-purple" style="color:#7c3aed;font-size:1rem"></i>
                    <span class="text-muted small">Publiczny link do rejestracji:</span>
                    <a href="<?= APP_URL ?>/events/public/register.php?slug=<?= urlencode($event['slug']) ?>"
                       target="_blank" class="small fw-semibold text-break" style="color:#7c3aed">
                        <?= APP_URL ?>/events/public/register.php?slug=<?= h($event['slug']) ?>
                    </a>
                    <button type="button" class="btn btn-sm ms-auto py-0 px-2" style="color:#7c3aed;border:1px solid #ede9fe;background:#fff"
                            onclick="navigator.clipboard.writeText('<?= APP_URL ?>/events/public/register.php?slug=<?= h(addslashes($event['slug'])) ?>');this.innerHTML='<i class=\'bi bi-check2\'></i>';setTimeout(()=>this.innerHTML='<i class=\'bi bi-clipboard\'></i>',1500)"
                            title="Kopiuj link">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
                <?php elseif (!$event['is_public']): ?>
                <div class="mt-2 text-muted small"><i class="bi bi-lock me-1"></i>Wydarzenie niepubliczne — formularz rejestracji nie jest dostępny bez logowania.</div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label fw-semibold">Opis</label>
                <textarea class="form-control" id="description" name="description" rows="4"><?= h($event['description']) ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Typ wydarzenia</label>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="type" id="type_webinar" value="webinar"
                               <?= $event['type']==='webinar'?'checked':'' ?>>
                        <label class="form-check-label" for="type_webinar"><i class="bi bi-camera-video me-1"></i>Webinar</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="type" id="type_stationary" value="stationary"
                               <?= $event['type']==='stationary'?'checked':'' ?>>
                        <label class="form-check-label" for="type_stationary"><i class="bi bi-geo-alt me-1"></i>Stacjonarne</label>
                    </div>
                </div>
            </div>

            <div id="webinar-fields" class="mb-3" <?= $event['type']!=='webinar'?'style="display:none"':'' ?>>
                <label for="meeting_url" class="form-label fw-semibold">Link do spotkania</label>
                <input type="url" class="form-control" id="meeting_url" name="meeting_url" value="<?= h($event['meeting_url']) ?>">
            </div>

            <div id="stationary-fields" <?= $event['type']!=='stationary'?'style="display:none"':'' ?>>
                <div class="mb-3">
                    <label for="venue" class="form-label fw-semibold">Miejsce</label>
                    <input type="text" class="form-control" id="venue" name="venue" value="<?= h($event['venue']) ?>">
                </div>
                <div class="mb-3">
                    <label for="address" class="form-label fw-semibold">Adres</label>
                    <input type="text" class="form-control" id="address" name="address" value="<?= h($event['address']) ?>">
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="start_at" class="form-label fw-semibold">Rozpoczęcie <span class="text-danger">*</span></label>
                    <input type="datetime-local" class="form-control" id="start_at" name="start_at"
                           value="<?= h(str_replace(' ','T', $event['start_at'] ?? '')) ?>" required>
                </div>
                <div class="col-md-6">
                    <label for="end_at" class="form-label fw-semibold">Zakończenie</label>
                    <input type="datetime-local" class="form-control" id="end_at" name="end_at"
                           value="<?= h(str_replace(' ','T', $event['end_at'] ?? '')) ?>">
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="capacity" class="form-label fw-semibold">Limit miejsc</label>
                    <input type="number" class="form-control" id="capacity" name="capacity" min="1"
                           value="<?= h($event['capacity'] ?? '') ?>" placeholder="bez limitu">
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="is_public" name="is_public" <?= $event['is_public']?'checked':'' ?>>
                        <label class="form-check-label fw-semibold" for="is_public">Publiczne</label>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3" id="reg-window-fields" <?= ($event['reg_always_open'] ?? 0) ? 'style="opacity:.5;pointer-events:none"' : '' ?>>
                <div class="col-md-6">
                    <label for="reg_open_at" class="form-label fw-semibold">Otwarcie rejestracji</label>
                    <input type="datetime-local" class="form-control" id="reg_open_at" name="reg_open_at"
                           value="<?= h(str_replace(' ','T', $event['reg_open_at'] ?? '')) ?>">
                    <div class="form-text">Czas według strefy PL (Europe/Warsaw).</div>
                </div>
                <div class="col-md-6">
                    <label for="reg_close_at" class="form-label fw-semibold">Zamknięcie rejestracji</label>
                    <input type="datetime-local" class="form-control" id="reg_close_at" name="reg_close_at"
                           value="<?= h(str_replace(' ','T', $event['reg_close_at'] ?? '')) ?>">
                    <div class="form-text">Czas według strefy PL (Europe/Warsaw).</div>
                </div>
            </div>
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="reg_always_open" name="reg_always_open"
                           <?= ($event['reg_always_open'] ?? 0) ? 'checked' : '' ?>
                           onchange="document.getElementById('reg-window-fields').style.opacity=this.checked?'.5':'1';document.getElementById('reg-window-fields').style.pointerEvents=this.checked?'none':'auto'">
                    <label class="form-check-label fw-semibold" for="reg_always_open">
                        Rejestracja ciągle otwarta
                    </label>
                    <div class="form-text">Ignoruje daty otwarcia i zamknięcia — rejestracja zawsze dostępna.</div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)">
                    <i class="bi bi-check-lg me-1"></i>Zapisz zmiany
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    const radios = document.querySelectorAll('input[name="type"]');
    const wf = document.getElementById('webinar-fields');
    const sf = document.getElementById('stationary-fields');
    function toggle(){
        const v = document.querySelector('input[name="type"]:checked').value;
        wf.style.display = v==='webinar'?'':'none';
        sf.style.display = v==='stationary'?'':'none';
    }
    radios.forEach(r=>r.addEventListener('change',toggle));
})();
</script>

<!-- Fields Tab -->
<?php elseif ($tab === 'fields'): ?>
<div class="card shadow-sm border-0" style="max-width:760px">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <span class="fw-semibold"><i class="bi bi-ui-checks me-2" style="color:var(--ev-purple)"></i>Pola formularza rejestracji</span>
        <button class="btn btn-sm btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)" onclick="showAddField()">
            <i class="bi bi-plus-lg me-1"></i>Dodaj pole
        </button>
    </div>
    <div class="card-body p-0">
        <?php if (empty($form_fields)): ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-ui-checks" style="font-size:2rem;opacity:.3"></i>
            <p class="mt-2 mb-0 small">Brak dodatkowych pól. Domyślnie zbierane: imię, nazwisko, email, telefon.</p>
        </div>
        <?php else: ?>
        <div id="fields-list">
            <?php foreach ($form_fields as $ff): ?>
            <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom field-row" data-id="<?= $ff['id'] ?>">
                <i class="bi bi-grip-vertical text-muted" style="cursor:grab"></i>
                <div class="flex-grow-1">
                    <span class="fw-semibold"><?= h($ff['label']) ?></span>
                    <span class="badge bg-light text-dark border ms-1"><?= h($ff['type']) ?></span>
                    <?php if ($ff['is_required']): ?><span class="badge bg-danger ms-1">wymagane</span><?php endif; ?>
                    <?php if ($ff['placeholder']): ?><span class="text-muted small ms-2"><?= h($ff['placeholder']) ?></span><?php endif; ?>
                </div>
                <div class="d-flex gap-1 flex-shrink-0">
                    <button class="btn btn-sm btn-outline-secondary" onclick="editField(<?= $ff['id'] ?>, <?= htmlspecialchars(json_encode($ff), ENT_QUOTES) ?>)">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteField(<?= $ff['id'] ?>)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Field Modal -->
<div class="modal fade" id="fieldModal" tabindex="-1" aria-labelledby="fieldModalLabel" aria-modal="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="fieldModalLabel">Pole formularza</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ff_id" value="">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Etykieta <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="ff_label">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Klucz (field_key)</label>
                    <input type="text" class="form-control" id="ff_key" pattern="[a-z0-9_]+">
                    <div class="form-text">Tylko małe litery, cyfry, podkreślnik. Generowany z etykiety.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Typ</label>
                    <select class="form-select" id="ff_type">
                        <option value="text">Tekst</option>
                        <option value="email">Email</option>
                        <option value="tel">Telefon</option>
                        <option value="number">Liczba</option>
                        <option value="textarea">Długi tekst</option>
                        <option value="select">Lista wyboru</option>
                        <option value="checkbox">Checkbox</option>
                    </select>
                </div>
                <div class="mb-3" id="ff_options_wrap" style="display:none">
                    <label class="form-label fw-semibold">Opcje (jedna per linia)</label>
                    <textarea class="form-control" id="ff_options" rows="4"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Placeholder</label>
                    <input type="text" class="form-control" id="ff_placeholder">
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="ff_required">
                    <label class="form-check-label fw-semibold" for="ff_required">Pole wymagane</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)"
                        onclick="saveField()">Zapisz pole</button>
            </div>
        </div>
    </div>
</div>

<script>
const EV_ID = <?= $event_id ?>;
const CSRF  = <?= json_encode(csrf_token()) ?>;

function showAddField(){
    document.getElementById('ff_id').value='';
    document.getElementById('ff_label').value='';
    document.getElementById('ff_key').value='';
    document.getElementById('ff_type').value='text';
    document.getElementById('ff_options').value='';
    document.getElementById('ff_options_wrap').style.display='none';
    document.getElementById('ff_placeholder').value='';
    document.getElementById('ff_required').checked=false;
    new bootstrap.Modal(document.getElementById('fieldModal')).show();
}

function editField(id, data){
    document.getElementById('ff_id').value=id;
    document.getElementById('ff_label').value=data.label||'';
    document.getElementById('ff_key').value=data.field_key||'';
    document.getElementById('ff_type').value=data.type||'text';
    const opts = data.options ? (typeof data.options==='string'?JSON.parse(data.options):data.options) : [];
    document.getElementById('ff_options').value=opts.join('\n');
    document.getElementById('ff_options_wrap').style.display=(data.type==='select')?'':'none';
    document.getElementById('ff_placeholder').value=data.placeholder||'';
    document.getElementById('ff_required').checked=!!parseInt(data.is_required);
    new bootstrap.Modal(document.getElementById('fieldModal')).show();
}

document.getElementById('ff_type').addEventListener('change',function(){
    document.getElementById('ff_options_wrap').style.display=this.value==='select'?'':'none';
});

document.getElementById('ff_label').addEventListener('input',function(){
    if(!document.getElementById('ff_id').value){
        document.getElementById('ff_key').value=this.value.toLowerCase()
            .replace(/ą/g,'a').replace(/ć/g,'c').replace(/ę/g,'e').replace(/ł/g,'l')
            .replace(/ń/g,'n').replace(/ó/g,'o').replace(/ś/g,'s').replace(/ź/g,'z').replace(/ż/g,'z')
            .replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'');
    }
});

async function saveField(){
    const label = document.getElementById('ff_label').value.trim();
    if(!label){ alert('Etykieta jest wymagana.'); return; }
    const id = document.getElementById('ff_id').value;
    const type = document.getElementById('ff_type').value;
    const optRaw = document.getElementById('ff_options').value;
    const opts = optRaw.split('\n').map(s=>s.trim()).filter(Boolean);

    const body = {
        _csrf: CSRF,
        action: id ? 'update' : 'add',
        event_id: EV_ID,
        id: id ? parseInt(id) : undefined,
        field_key: document.getElementById('ff_key').value || label.toLowerCase().replace(/[^a-z0-9]/g,'_'),
        label: label,
        type: type,
        options: opts,
        placeholder: document.getElementById('ff_placeholder').value,
        is_required: document.getElementById('ff_required').checked ? 1 : 0,
    };

    const r = await fetch('<?= APP_URL ?>/events/api/form_fields.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)
    });
    const data = await r.json();
    if(data.ok){ location.reload(); } else { alert(data.error||'Błąd.'); }
}

async function deleteField(id){
    if(!confirm('Usunąć to pole?')) return;
    const r = await fetch('<?= APP_URL ?>/events/api/form_fields.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body:JSON.stringify({_csrf:CSRF, action:'remove', id:id, event_id:EV_ID})
    });
    const data = await r.json();
    if(data.ok){ location.reload(); } else { alert(data.error||'Błąd.'); }
}
</script>

<!-- RODO Tab -->
<?php elseif ($tab === 'rodo'): ?>
<?php $ev_gdpr = ev_gdpr_clause($event); $ev_def = gdpr_clauses_default_slug('event'); ?>
<div class="card shadow-sm border-0 mb-3" style="max-width:760px">
    <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_gdpr_clause">
            <div class="col-md-9">
                <label for="gdpr_clause_slug" class="form-label fw-semibold">Klauzula z rejestru <a href="<?= APP_URL ?>/modules/gdpr_clauses/index.php" target="_blank" class="small fw-normal">Klauzule RODO</a></label>
                <?= gdpr_clauses_select('gdpr_clause_slug', (string)($event['gdpr_clause_slug'] ?? ''),
                        $ev_def !== '' ? '— domyślna dla wydarzeń (' . (gdpr_clauses_options()[$ev_def] ?? $ev_def) . ') —' : '— bez klauzuli z rejestru —', 'gdpr_clause_slug') ?>
            </div>
            <div class="col-md-3 d-grid"><button class="btn btn-outline-primary">Zapisz wybór</button></div>
            <div class="col-12 form-text">
                Klauzula z rejestru ma pierwszeństwo przed treścią poniżej, pokazuje aktualne dane administratora
                i zapisuje w <a href="<?= APP_URL ?>/modules/gdpr_clauses/acceptances.php">rejestrze akceptacji</a>, którą wersję zaakceptował uczestnik.
                <?php if ($ev_gdpr): ?><br><strong class="text-success">Formularz używa teraz: <?= h($ev_gdpr['clause']['tytul']) ?> (v<?= (int)$ev_gdpr['clause']['version'] ?>)</strong> — treść poniżej jest pomijana.<?php endif; ?>
            </div>
        </form>
    </div>
</div>
<div class="card shadow-sm border-0" style="max-width:760px">
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="_csrf"        value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action"       value="update_basic">
            <input type="hidden" name="title"        value="<?= h($event['title']) ?>">
            <input type="hidden" name="slug"         value="<?= h($event['slug']) ?>">
            <input type="hidden" name="description"  value="<?= h($event['description']) ?>">
            <input type="hidden" name="type"         value="<?= h($event['type']) ?>">
            <input type="hidden" name="venue"        value="<?= h($event['venue']) ?>">
            <input type="hidden" name="address"      value="<?= h($event['address']) ?>">
            <input type="hidden" name="meeting_url"  value="<?= h($event['meeting_url']) ?>">
            <input type="hidden" name="start_at"     value="<?= h($event['start_at']) ?>">
            <input type="hidden" name="end_at"       value="<?= h($event['end_at']) ?>">
            <input type="hidden" name="capacity"     value="<?= h($event['capacity'] ?? '') ?>">
            <input type="hidden" name="is_public"    value="<?= h($event['is_public']) ?>">
            <input type="hidden" name="reg_open_at"  value="<?= h($event['reg_open_at']) ?>">
            <input type="hidden" name="reg_close_at" value="<?= h($event['reg_close_at']) ?>">
            <input type="hidden" name="cover_image"  value="<?= h($event['cover_image'] ?? '') ?>">
            <input type="hidden" name="pa_webhook_url" value="<?= h($event['pa_webhook_url'] ?? '') ?>">
            <input type="hidden" name="crm_group_id"   value="<?= h($event['crm_group_id'] ?? '') ?>">
            <input type="hidden" name="notify_new_reg"  value="<?= h($event['notify_new_reg'] ?? '1') ?>">
            <input type="hidden" name="notify_email"    value="<?= h($event['notify_email'] ?? '') ?>">
            <input type="hidden" name="crm_auto_sync"   value="<?= h($event['crm_auto_sync'] ?? '1') ?>">
            <input type="hidden" name="reg_always_open" value="<?= h($event['reg_always_open'] ?? '0') ?>">

            <div class="d-flex align-items-start gap-3 mb-4 p-3 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0">
                <i class="bi bi-shield-check text-success" style="font-size:1.5rem;margin-top:.1rem"></i>
                <div>
                    <div class="fw-semibold text-success">Klauzula informacyjna RODO</div>
                    <div class="text-muted small">Treść wyświetlana uczestnikom przy formularzu rejestracji (checkbox wymagany). Zgodna z art. 13 RODO.</div>
                </div>
            </div>

            <div class="mb-4">
                <label for="rodo_clause" class="form-label fw-semibold">Treść klauzuli <span class="text-muted fw-normal">(opcjonalna — jeśli pusta, używana klauzula globalna z ustawień)</span></label>
                <textarea class="form-control" id="rodo_clause" name="rodo_clause" rows="10"
                          placeholder="Administratorem Pani/Pana danych osobowych jest…"><?= h($event['rodo_clause'] ?? '') ?></textarea>
                <div class="form-text">
                    Dostępne zmienne: <code>{event_title}</code>, <code>{org_name}</code>.
                    Jeśli pole jest puste, formularz rejestracji użyje domyślnej klauzuli z
                    <a href="<?= APP_URL ?>/admin/events_settings.php">ustawień modułu</a>.
                </div>
            </div>

            <?php
            $global_rodo = '';
            try { $global_rodo = org_setting('ev_rodo_clause'); } catch(\Throwable $e) {}
            if ($global_rodo && !($event['rodo_clause'] ?? '')): ?>
            <div class="p-3 rounded border mb-4" style="background:#f8fafc">
                <div class="text-muted small fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>Aktualnie używana klauzula globalna:</div>
                <div class="small" style="white-space:pre-wrap"><?= h($global_rodo) ?></div>
            </div>
            <?php elseif (!$global_rodo && !($event['rodo_clause'] ?? '')): ?>
            <div class="alert alert-warning d-flex gap-2">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span>Brak klauzuli RODO. Uzupełnij tutaj lub ustaw globalną w <a href="<?= APP_URL ?>/admin/events_settings.php">ustawieniach modułu</a>.</span>
            </div>
            <?php endif; ?>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)">
                    <i class="bi bi-check-lg me-1"></i>Zapisz klauzulę
                </button>
                <?php if ($event['rodo_clause'] ?? ''): ?>
                <button type="button" class="btn btn-outline-secondary"
                        onclick="if(confirm('Wyczyścić klauzulę i użyć globalnej?')){document.getElementById('rodo_clause').value='';this.form.submit();}">
                    <i class="bi bi-x-circle me-1"></i>Wyczyść (użyj globalnej)
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Notifications & CRM Tab -->
<?php elseif ($tab === 'notifications'): ?>
<div class="row g-4" style="max-width:900px">
<div class="col-12">
<form method="post">
    <input type="hidden" name="_csrf"        value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action"       value="update_basic">
    <input type="hidden" name="title"        value="<?= h($event['title']) ?>">
    <input type="hidden" name="slug"         value="<?= h($event['slug']) ?>">
    <input type="hidden" name="description"  value="<?= h($event['description']) ?>">
    <input type="hidden" name="type"         value="<?= h($event['type']) ?>">
    <input type="hidden" name="venue"        value="<?= h($event['venue']) ?>">
    <input type="hidden" name="address"      value="<?= h($event['address']) ?>">
    <input type="hidden" name="meeting_url"  value="<?= h($event['meeting_url']) ?>">
    <input type="hidden" name="start_at"     value="<?= h($event['start_at']) ?>">
    <input type="hidden" name="end_at"       value="<?= h($event['end_at']) ?>">
    <input type="hidden" name="capacity"     value="<?= h($event['capacity'] ?? '') ?>">
    <input type="hidden" name="is_public"    value="<?= h($event['is_public']) ?>">
    <input type="hidden" name="reg_open_at"  value="<?= h($event['reg_open_at']) ?>">
    <input type="hidden" name="reg_close_at" value="<?= h($event['reg_close_at']) ?>">
    <input type="hidden" name="cover_image"  value="<?= h($event['cover_image'] ?? '') ?>">
    <input type="hidden" name="pa_webhook_url" value="<?= h($event['pa_webhook_url'] ?? '') ?>">
    <input type="hidden" name="rodo_clause"       value="<?= h($event['rodo_clause'] ?? '') ?>">
    <input type="hidden" name="reg_always_open"   value="<?= h($event['reg_always_open'] ?? '0') ?>">

    <!-- Powiadomienia -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white fw-semibold d-flex align-items-center gap-2">
            <i class="bi bi-bell-fill text-warning"></i>Powiadomienia o nowych rejestracjach
        </div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch"
                       id="notify_new_reg" name="notify_new_reg"
                       <?= ($event['notify_new_reg'] ?? '1') !== '0' ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="notify_new_reg">
                    Wysyłaj powiadomienie email przy każdej nowej rejestracji
                </label>
                <div class="form-text">Organizator/admin otrzyma email gdy ktoś się zapisze lub wypisze.</div>
            </div>
            <div class="mb-3">
                <label for="notify_email" class="form-label fw-semibold">Adres email do powiadomień</label>
                <input type="email" class="form-control" id="notify_email" name="notify_email"
                       value="<?= h($event['notify_email'] ?? '') ?>"
                       placeholder="np. organizator@example.org">
                <div class="form-text">Jeśli puste — używany jest adres z <a href="<?= APP_URL ?>/admin/events_settings.php">ustawień modułu</a>.</div>
            </div>
            <?php
            $global_mail = '';
            try { $global_mail = org_setting('ev_mail_from'); } catch(\Throwable $e) {}
            if ($global_mail): ?>
            <div class="small text-muted p-2 rounded" style="background:#f8fafc;border:1px solid #e2e8f0">
                <i class="bi bi-info-circle me-1"></i>
                Globalny adres z ustawień: <strong><?= h($global_mail) ?></strong>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- CRM -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white fw-semibold d-flex align-items-center gap-2">
            <i class="bi bi-diagram-2-fill" style="color:#7c3aed"></i>Integracja z CRM
        </div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch"
                       id="crm_auto_sync" name="crm_auto_sync"
                       <?= ($event['crm_auto_sync'] ?? '1') !== '0' ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="crm_auto_sync">
                    Automatycznie synchronizuj uczestników z CRM
                </label>
                <div class="form-text">Każdy nowy uczestnik zostanie dodany jako kontakt CRM i przypisany do grupy poniżej.</div>
            </div>

            <div class="mb-3">
                <label for="crm_group_id_n" class="form-label fw-semibold">Grupa CRM dla uczestników</label>
                <select class="form-select" id="crm_group_id_n" name="crm_group_id">
                    <option value="">— utwórz automatycznie —</option>
                    <?php foreach ($crm_groups as $g): ?>
                    <option value="<?= $g['id'] ?>" <?= (int)($event['crm_group_id']??0) === (int)$g['id'] ? 'selected' : '' ?>>
                        <?= h($g['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">
                    Jeśli wybierzesz „utwórz automatycznie" — grupa „Wydarzenie: <?= h($event['title']) ?>"
                    zostanie założona przy pierwszej rejestracji.
                </div>
            </div>

            <?php if ($event['crm_group_id']): ?>
            <?php $crm_grp = null; try { $crm_grp = db_one("SELECT id, name FROM crm_groups WHERE id=?", [$event['crm_group_id']]); } catch(\Throwable $e){} ?>
            <?php if ($crm_grp): ?>
            <div class="d-flex align-items-center gap-2 p-2 rounded" style="background:#f5f3ff;border:1px solid #ede9fe">
                <i class="bi bi-people-fill" style="color:#7c3aed"></i>
                <span class="small">Powiązana grupa CRM:</span>
                <a href="<?= APP_URL ?>/crm/group.php?id=<?= $crm_grp['id'] ?>" class="fw-semibold small" style="color:#7c3aed"><?= h($crm_grp['name']) ?></a>
                <?php $crm_count = 0; try { $crm_count = (int)(db_one("SELECT COUNT(*) AS n FROM crm_group_members WHERE group_id=?", [$crm_grp['id']])['n'] ?? 0); } catch(\Throwable $e){} ?>
                <span class="badge ms-auto" style="background:#ede9fe;color:#7c3aed"><?= $crm_count ?> kontaktów</span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)">
            <i class="bi bi-check-lg me-1"></i>Zapisz
        </button>
    </div>
</form>
</div>
</div>

<!-- Advanced Tab -->
<?php elseif ($tab === 'advanced'): ?>
<div class="card shadow-sm border-0" style="max-width:760px">
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action"  value="update_basic">
            <input type="hidden" name="title"       value="<?= h($event['title']) ?>">
            <input type="hidden" name="slug"        value="<?= h($event['slug']) ?>">
            <input type="hidden" name="description" value="<?= h($event['description']) ?>">
            <input type="hidden" name="type"        value="<?= h($event['type']) ?>">
            <input type="hidden" name="venue"       value="<?= h($event['venue']) ?>">
            <input type="hidden" name="address"     value="<?= h($event['address']) ?>">
            <input type="hidden" name="meeting_url" value="<?= h($event['meeting_url']) ?>">
            <input type="hidden" name="start_at"    value="<?= h($event['start_at']) ?>">
            <input type="hidden" name="end_at"      value="<?= h($event['end_at']) ?>">
            <input type="hidden" name="capacity"    value="<?= h($event['capacity'] ?? '') ?>">
            <input type="hidden" name="is_public"   value="<?= h($event['is_public']) ?>">
            <input type="hidden" name="reg_open_at" value="<?= h($event['reg_open_at']) ?>">
            <input type="hidden" name="reg_close_at" value="<?= h($event['reg_close_at']) ?>">
            <input type="hidden" name="rodo_clause"      value="<?= h($event['rodo_clause'] ?? '') ?>">
            <input type="hidden" name="notify_new_reg"  value="<?= h($event['notify_new_reg'] ?? '1') ?>">
            <input type="hidden" name="notify_email"    value="<?= h($event['notify_email'] ?? '') ?>">
            <input type="hidden" name="crm_auto_sync"   value="<?= h($event['crm_auto_sync'] ?? '1') ?>">
            <input type="hidden" name="reg_always_open" value="<?= h($event['reg_always_open'] ?? '0') ?>">

            <h6 class="fw-semibold text-muted text-uppercase small mb-3" style="letter-spacing:.06em">Wygląd</h6>
            <div class="mb-3">
                <label for="cover_image" class="form-label fw-semibold">Okładka (nazwa pliku lub URL)</label>
                <input type="text" class="form-control" id="cover_image" name="cover_image"
                       value="<?= h($event['cover_image'] ?? '') ?>" placeholder="np. cover.jpg">
            </div>

            <hr class="my-4">
            <h6 class="fw-semibold text-muted text-uppercase small mb-3" style="letter-spacing:.06em">Power Automate</h6>
            <div class="mb-3">
                <label for="pa_webhook_url" class="form-label fw-semibold">Webhook URL</label>
                <input type="url" class="form-control" id="pa_webhook_url" name="pa_webhook_url"
                       value="<?= h($event['pa_webhook_url'] ?? '') ?>">
                <div class="form-text">Przy każdej rejestracji wysyłane jest żądanie POST z danymi uczestnika.</div>
            </div>
            <!-- hidden carry-over for fields managed on other tabs -->
            <input type="hidden" name="crm_group_id" value="<?= h($event['crm_group_id'] ?? '') ?>">

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)">
                    <i class="bi bi-check-lg me-1"></i>Zapisz
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
