<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/events.php';

require_login();
require_role('admin');
require_module_enabled('events_enabled', 'Moduł wydarzeń');

$api_base = rtrim(APP_URL, '/') . '/events/public/embed_feed.php';

// Pobranie gotowego pliku PHP do osadzenia (widget) na zewnętrznym serwerze.
if (isset($_GET['download'])) {
    $tpl = file_get_contents(dirname(__DIR__) . '/public/feer_events_widget.php.tpl');
    $tpl = str_replace('__API_BASE__', $api_base, $tpl);
    header('Content-Type: text/x-php; charset=utf-8');
    header('Content-Disposition: attachment; filename="feer_events_widget.php"');
    header('Content-Length: ' . strlen($tpl));
    echo $tpl;
    exit;
}

$PAGE_TITLE = 'Osadzanie wydarzeń';

$public_events = db_all(
    "SELECT id, slug, title, start_at FROM ev_events
     WHERE status='published' AND is_public=1
     ORDER BY start_at ASC"
);

include dirname(__DIR__) . '/includes/header_events.php';

$embed_js = rtrim(APP_URL, '/') . '/events/public/embed.js';
?>

<div class="d-flex align-items-center mb-3 gap-2">
    <h4 class="mb-0 fw-bold"><i class="bi bi-code-slash me-2" style="color:var(--ev-purple)"></i>Osadzanie / API</h4>
</div>

<div class="alert alert-info small">
  <i class="bi bi-info-circle-fill me-1"></i>
  Poniższy kod pokazuje na zewnętrznej stronie tylko wydarzenia <strong>opublikowane</strong> i oznaczone jako
  <strong>publiczne</strong> — bez logowania i bez danych osobowych uczestników.
  Do pełnego dostępu (odczyt/zapis wydarzeń i rejestracji z zewnętrznego systemu) służy
  <a href="<?= APP_URL ?>/admin/api_manage.php">klucz API</a> ze scope <code>events:read</code> / <code>events:write</code>.
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-header bg-white fw-semibold">
        <i class="bi bi-sliders me-2" style="color:var(--ev-purple)"></i>Ustawienia widgetu
      </div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">Liczba wydarzeń</label>
          <select id="opt_limit" class="form-select" onchange="rebuildSnippets()">
            <option value="3">3</option>
            <option value="6" selected>6</option>
            <option value="9">9</option>
            <option value="12">12</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Typ</label>
          <select id="opt_type" class="form-select" onchange="rebuildSnippets()">
            <option value="">Wszystkie</option>
            <option value="webinar">Webinar</option>
            <option value="stationary">Stacjonarne</option>
          </select>
        </div>
        <div class="mb-1">
          <label class="form-label fw-semibold">
            Konkretne wydarzenia
            <span class="text-muted fw-normal">(opcjonalnie — nadpisuje filtry powyżej)</span>
          </label>
          <div class="border rounded p-2" style="max-height:220px;overflow:auto">
            <?php if (!$public_events): ?>
            <div class="text-muted small">Brak opublikowanych, publicznych wydarzeń.</div>
            <?php endif; ?>
            <?php foreach ($public_events as $pe): ?>
            <div class="form-check">
              <input class="form-check-input opt-slug" type="checkbox" value="<?= h($pe['slug']) ?>"
                     id="slug_<?= (int)$pe['id'] ?>" onchange="rebuildSnippets()">
              <label class="form-check-label small" for="slug_<?= (int)$pe['id'] ?>">
                <?= h($pe['title']) ?>
                <span class="text-muted"><?= $pe['start_at'] ? date('d.m.Y', strtotime($pe['start_at'])) : '' ?></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-braces me-2" style="color:var(--ev-purple)"></i>Kod JS (osadzenie jako widget)</span>
        <button class="btn btn-sm btn-outline-secondary" onclick="copySnippet('snippet_js')">
          <i class="bi bi-clipboard me-1"></i>Kopiuj
        </button>
      </div>
      <div class="card-body">
        <p class="text-muted small">Wklej ten kod w miejscu na swojej stronie, gdzie mają się pojawić wydarzenia.</p>
        <pre id="snippet_js" class="bg-light border rounded p-2 small" style="white-space:pre-wrap"></pre>
      </div>
    </div>

    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold">
        <i class="bi bi-filetype-php me-2" style="color:var(--ev-purple)"></i>Wariant PHP (include na zewnętrznym serwerze)
      </div>
      <div class="card-body">
        <p class="text-muted small mb-2">Jeśli Twoja strona działa w PHP, pobierz gotowy plik i dołącz go u siebie:</p>
        <pre class="bg-light border rounded p-2 small" style="white-space:pre-wrap"><code>require __DIR__ . '/feer_events_widget.php';
echo feer_events_widget_html([<span id="snippet_php_opts"></span>]);</code></pre>
        <a id="download_link" href="?download=1" class="btn btn-sm btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)">
          <i class="bi bi-download me-1"></i>Pobierz feer_events_widget.php
        </a>
      </div>
    </div>

    <div class="mt-3 small text-muted">
      Dane pochodzą z publicznego, bez-autoryzacyjnego feedu <code><?= h($api_base) ?></code>.
    </div>
  </div>
</div>

<script>
const EMBED_JS = <?= json_encode($embed_js) ?>;

function selectedSlugs() {
  return Array.from(document.querySelectorAll('.opt-slug:checked')).map(el => el.value);
}

function rebuildSnippets() {
  const limit = document.getElementById('opt_limit').value;
  const type  = document.getElementById('opt_type').value;
  const slugs = selectedSlugs();

  let attrs = ' data-target="#feer-events" data-limit="' + limit + '"';
  if (type) attrs += ' data-type="' + type + '"';
  if (slugs.length) attrs += ' data-ids="' + slugs.join(',') + '"';

  document.getElementById('snippet_js').textContent =
    '<div id="feer-events"></div>\n<script async src="' + EMBED_JS + '"' + attrs + '></' + 'script>';

  const opts = [];
  opts.push("'limit' => " + limit);
  if (type) opts.push("'type' => '" + type + "'");
  if (slugs.length) opts.push("'ids' => '" + slugs.join(',') + "'");
  document.getElementById('snippet_php_opts').textContent = opts.join(', ');
}

function copySnippet(id) {
  const text = document.getElementById(id).textContent;
  navigator.clipboard.writeText(text).then(() => {
    const btn = document.activeElement;
    if (btn && btn.innerHTML !== undefined) {
      const old = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-check2"></i> Skopiowano';
      setTimeout(() => { btn.innerHTML = old; }, 1500);
    }
  });
}

rebuildSnippets();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
