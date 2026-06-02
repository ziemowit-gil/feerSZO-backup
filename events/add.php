<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/events.php';

require_login();
require_role('admin');
require_module_enabled('events_enabled', 'Moduł wydarzeń');

$PAGE_TITLE = 'Nowe wydarzenie';
$errors = [];
$f = [
    'title' => '', 'slug' => '', 'description' => '', 'type' => 'webinar',
    'venue' => '', 'address' => '', 'meeting_url' => '',
    'start_at' => '', 'end_at' => '', 'capacity' => '',
    'is_public' => '1', 'reg_open_at' => '', 'reg_close_at' => '',
    'pa_webhook_url' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($f as $k => $_) {
        $f[$k] = trim($_POST[$k] ?? '');
    }
    $f['is_public'] = isset($_POST['is_public']) ? '1' : '0';

    if (!$f['title'])    $errors[] = 'Tytuł jest wymagany.';
    if (!$f['slug'])     $errors[] = 'Slug jest wymagany.';
    if (!$f['start_at']) $errors[] = 'Data rozpoczęcia jest wymagana.';
    if ($f['type'] === 'webinar' && !$f['meeting_url']) $errors[] = 'Link do spotkania jest wymagany dla webinaru.';
    if ($f['type'] === 'stationary' && !$f['venue'])    $errors[] = 'Miejsce jest wymagane dla wydarzenia stacjonarnego.';

    // Slug uniqueness
    if (!$errors) {
        $exists = db_one("SELECT id FROM ev_events WHERE slug=?", [$f['slug']]);
        if ($exists) $errors[] = 'Ten slug jest już zajęty. Zmień tytuł lub slug.';
    }

    if (!$errors) {
        $uid = (int)(current_user()['id'] ?? 0);
        $new_id = db_insert('ev_events', [
            'slug'          => $f['slug'],
            'title'         => $f['title'],
            'description'   => $f['description'],
            'type'          => $f['type'],
            'status'        => 'draft',
            'venue'         => $f['venue'],
            'address'       => $f['address'],
            'meeting_url'   => $f['meeting_url'],
            'start_at'      => $f['start_at'] ?: null,
            'end_at'        => $f['end_at'] ?: null,
            'capacity'      => $f['capacity'] !== '' ? (int)$f['capacity'] : null,
            'is_public'     => (int)$f['is_public'],
            'reg_open_at'   => $f['reg_open_at'] ?: null,
            'reg_close_at'  => $f['reg_close_at'] ?: null,
            'pa_webhook_url'=> $f['pa_webhook_url'],
            'created_by'    => $uid,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        flash_set('success', 'Wydarzenie zostało utworzone jako szkic.');
        header('Location: ' . APP_URL . '/events/manage.php?id=' . $new_id);
        exit;
    }
}

include dirname(__DIR__) . '/events/includes/header_events.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
    <a href="<?= APP_URL ?>/events/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="mb-0 fw-bold"><i class="bi bi-plus-circle me-2" style="color:var(--ev-purple)"></i>Nowe wydarzenie</h4>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card shadow-sm border-0" style="max-width:760px">
    <div class="card-body">
        <form method="post" id="ev-add-form">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

            <div class="mb-3">
                <label for="title" class="form-label fw-semibold">Tytuł <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="title" name="title" value="<?= h($f['title']) ?>" required>
            </div>

            <div class="mb-3">
                <label for="slug" class="form-label fw-semibold">Slug (URL) <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text text-muted small">/events/public/register.php?slug=</span>
                    <input type="text" class="form-control" id="slug" name="slug" value="<?= h($f['slug']) ?>"
                           pattern="[a-z0-9\-]+" required>
                </div>
                <div class="form-text">Tylko małe litery, cyfry i myślniki. Generowany automatycznie z tytułu.</div>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label fw-semibold">Opis</label>
                <textarea class="form-control" id="description" name="description" rows="4"><?= h($f['description']) ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Typ wydarzenia <span class="text-danger">*</span></label>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="type" id="type_webinar" value="webinar"
                               <?= $f['type']==='webinar'?'checked':'' ?>>
                        <label class="form-check-label" for="type_webinar">
                            <i class="bi bi-camera-video me-1"></i>Webinar (online)
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="type" id="type_stationary" value="stationary"
                               <?= $f['type']==='stationary'?'checked':'' ?>>
                        <label class="form-check-label" for="type_stationary">
                            <i class="bi bi-geo-alt me-1"></i>Stacjonarne
                        </label>
                    </div>
                </div>
            </div>

            <div id="webinar-fields" class="mb-3" <?= $f['type']!=='webinar'?'style="display:none"':'' ?>>
                <label for="meeting_url" class="form-label fw-semibold">Link do spotkania</label>
                <input type="url" class="form-control" id="meeting_url" name="meeting_url" value="<?= h($f['meeting_url']) ?>"
                       placeholder="https://meet.example.com/xyz">
            </div>

            <div id="stationary-fields" <?= $f['type']!=='stationary'?'style="display:none"':'' ?>>
                <div class="mb-3">
                    <label for="venue" class="form-label fw-semibold">Miejsce</label>
                    <input type="text" class="form-control" id="venue" name="venue" value="<?= h($f['venue']) ?>"
                           placeholder="np. Sala konferencyjna A">
                </div>
                <div class="mb-3">
                    <label for="address" class="form-label fw-semibold">Adres</label>
                    <input type="text" class="form-control" id="address" name="address" value="<?= h($f['address']) ?>"
                           placeholder="ul. Przykładowa 1, 00-000 Warszawa">
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="start_at" class="form-label fw-semibold">Data i godzina rozpoczęcia <span class="text-danger">*</span></label>
                    <input type="datetime-local" class="form-control" id="start_at" name="start_at"
                           value="<?= h($f['start_at']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label for="end_at" class="form-label fw-semibold">Data i godzina zakończenia</label>
                    <input type="datetime-local" class="form-control" id="end_at" name="end_at"
                           value="<?= h($f['end_at']) ?>">
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="capacity" class="form-label fw-semibold">Limit miejsc</label>
                    <input type="number" class="form-control" id="capacity" name="capacity" min="1"
                           value="<?= h($f['capacity']) ?>" placeholder="bez limitu">
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="is_public" name="is_public" <?= $f['is_public']?'checked':'' ?>>
                        <label class="form-check-label fw-semibold" for="is_public">
                            Publiczne (rejestracja bez logowania)
                        </label>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="reg_open_at" class="form-label fw-semibold">Otwarcie rejestracji</label>
                    <input type="datetime-local" class="form-control" id="reg_open_at" name="reg_open_at"
                           value="<?= h($f['reg_open_at']) ?>">
                    <div class="form-text">Puste = od razu otwarte</div>
                </div>
                <div class="col-md-6">
                    <label for="reg_close_at" class="form-label fw-semibold">Zamknięcie rejestracji</label>
                    <input type="datetime-local" class="form-control" id="reg_close_at" name="reg_close_at"
                           value="<?= h($f['reg_close_at']) ?>">
                </div>
            </div>

            <details class="mb-3">
                <summary class="fw-semibold text-muted small text-uppercase" style="cursor:pointer;letter-spacing:.05em">
                    Zaawansowane
                </summary>
                <div class="mt-3">
                    <label for="pa_webhook_url" class="form-label fw-semibold">Power Automate Webhook URL</label>
                    <input type="url" class="form-control" id="pa_webhook_url" name="pa_webhook_url"
                           value="<?= h($f['pa_webhook_url']) ?>" placeholder="https://...">
                    <div class="form-text">Powiadomienie wysyłane przy każdej nowej rejestracji.</div>
                </div>
            </details>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)">
                    <i class="bi bi-check-lg me-1"></i>Utwórz wydarzenie
                </button>
                <a href="<?= APP_URL ?>/events/index.php" class="btn btn-outline-secondary">Anuluj</a>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    // Slug auto-generation
    const titleEl = document.getElementById('title');
    const slugEl  = document.getElementById('slug');
    let slugManual = <?= $f['slug'] ? 'true' : 'false' ?>;

    titleEl.addEventListener('input', function(){
        if (!slugManual) {
            const s = this.value.toLowerCase()
                .replace(/ą/g,'a').replace(/ć/g,'c').replace(/ę/g,'e')
                .replace(/ł/g,'l').replace(/ń/g,'n').replace(/ó/g,'o')
                .replace(/ś/g,'s').replace(/ź/g,'z').replace(/ż/g,'z')
                .replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
            slugEl.value = s;
        }
    });
    slugEl.addEventListener('input', function(){ slugManual = true; });

    // Type toggle
    const radios = document.querySelectorAll('input[name="type"]');
    const webinarFields    = document.getElementById('webinar-fields');
    const stationaryFields = document.getElementById('stationary-fields');

    function updateTypeFields(){
        const val = document.querySelector('input[name="type"]:checked').value;
        webinarFields.style.display    = val === 'webinar'    ? '' : 'none';
        stationaryFields.style.display = val === 'stationary' ? '' : 'none';
    }
    radios.forEach(r => r.addEventListener('change', updateTypeFields));
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
