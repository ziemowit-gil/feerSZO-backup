<?php
/**
 * modules/gdpr_clauses/edit.php — dodawanie/edycja klauzuli RODO z podglądem
 * na żywo (preview.php), kodem do osadzenia i historią wersji.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/gdpr_clauses.php';

require_role('admin', 'editor');
$user = current_user();
$svc  = new GdprClauseService();

$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$clause = $id ? $svc->getById($id) : null;
if ($id && !$clause) {
    flash_set('danger', 'Nie znaleziono klauzuli.');
    header('Location: ' . APP_URL . '/modules/gdpr_clauses/index.php');
    exit;
}

$form  = $clause ?? ['slug' => '', 'tytul' => '', 'content' => '', 'is_published' => 0, 'updated_at' => null];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form['slug']         = (string)($_POST['slug'] ?? '');
    $form['tytul']        = (string)($_POST['tytul'] ?? '');
    $form['content']      = (string)($_POST['content'] ?? '');
    $form['is_published'] = !empty($_POST['is_published']) ? 1 : 0;
    try {
        $newId = $svc->saveClause($id, $form['slug'], $form['tytul'], $form['content'], (bool)$form['is_published'], (int)$user['id']);
        $unknown = $svc->unknownTags($form['content']);
        flash_set($unknown ? 'warning' : 'success', 'Klauzula zapisana.'
            . ($unknown ? ' Uwaga: nieznane tagi (na stronie publicznej będą puste): {{' . implode('}}, {{', $unknown) . '}}.' : ''));
        header('Location: ' . APP_URL . '/modules/gdpr_clauses/edit.php?id=' . $newId);
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

$vars    = $svc->listVariables();
$history = $clause ? $svc->history($id) : [];
$pubUrl  = $clause ? gdpr_clauses_public_url($clause['slug']) : '';
$embUrl  = $clause ? gdpr_clauses_public_url($clause['slug'], true) : '';
$jsUrl   = rtrim(APP_URL, '/') . '/modules/gdpr_clauses/public/embed.js';

$PAGE_TITLE = $clause ? 'Klauzula: ' . $clause['tytul'] : 'Nowa klauzula RODO';
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<style>
  .gdpr-preview { font-size: .95rem; line-height: 1.6; max-height: 70vh; overflow: auto; }
  .gdpr-preview h2 { font-size: 1.05rem; font-weight: 600; margin: 1.1rem 0 .4rem; }
  .gdpr-preview h3 { font-size: .98rem; font-weight: 600; margin: .9rem 0 .3rem; }
  .gdpr-preview ul, .gdpr-preview ol { padding-left: 1.3rem; }
  .gdpr-preview .gdpr-var { background: #e7f1ff; border-radius: 3px; padding: 0 2px; }
  .gdpr-preview .gdpr-unknown { background: #fde2e1; color: #b02a37; border-radius: 3px; }
  #gdpr-content { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-shield-lock text-primary"></i> <?= $clause ? h($clause['tytul']) : 'Nowa klauzula' ?></h4>
  <div class="d-flex gap-2">
    <?php if ($clause && (int)$clause['is_published'] === 1): ?>
      <a href="<?= h($pubUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success"><i class="bi bi-box-arrow-up-right me-1"></i>Strona publiczna</a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/modules/gdpr_clauses/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Lista</a>
  </div>
</div>

<?= flash_html() ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="row g-2 mb-2">
            <div class="col-md-7">
              <label class="form-label small mb-1" for="gdpr-title">Tytuł</label>
              <input type="text" id="gdpr-title" name="tytul" class="form-control" required maxlength="255" value="<?= h($form['tytul']) ?>">
            </div>
            <div class="col-md-5">
              <label class="form-label small mb-1" for="gdpr-slug">Slug (adres)</label>
              <div class="input-group">
                <span class="input-group-text small">/klauzula/</span>
                <input type="text" id="gdpr-slug" name="slug" class="form-control" required pattern="[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?" value="<?= h($form['slug']) ?>" placeholder="rekrutacja">
              </div>
            </div>
          </div>

          <label class="form-label small mb-1" for="gdpr-content">Treść</label>
          <textarea id="gdpr-content" name="content" class="form-control" rows="22"><?= h($form['content']) ?></textarea>
          <div class="form-text">
            Pusta linia = nowy akapit · <code>## Nagłówek</code> · <code>- punkt</code> / <code>1. punkt</code> ·
            <code>**pogrubienie**</code> · <code>*kursywa*</code> · e-maile i adresy http(s) stają się linkami.
            HTML nie jest interpretowany.
          </div>

          <div class="mt-2">
            <span class="small text-muted me-1">Wstaw:</span>
            <?php foreach ($vars as $v): ?>
              <button type="button" class="btn btn-sm btn-light border py-0 px-1 mb-1 gdpr-ins" data-tag="{{<?= h($v['key_']) ?>}}" title="<?= h($v['label'] . ': ' . $v['value']) ?>"><code>{{<?= h($v['key_']) ?>}}</code></button>
            <?php endforeach; ?>
            <?php foreach (GDPR_BUILTIN_VARS as $b): ?>
              <button type="button" class="btn btn-sm btn-light border py-0 px-1 mb-1 gdpr-ins" data-tag="{{<?= $b ?>}}"><code class="text-muted">{{<?= $b ?>}}</code></button>
            <?php endforeach; ?>
            <a href="<?= APP_URL ?>/modules/gdpr_clauses/variables.php" class="small ms-1">zarządzaj zmiennymi</a>
          </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="gdpr-pub" name="is_published" value="1" <?= (int)$form['is_published'] === 1 ? 'checked' : '' ?>>
            <label class="form-check-label small" for="gdpr-pub">Opublikowana (dostępna publicznie)</label>
          </div>
          <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold small"><i class="bi bi-eye me-1"></i>Podgląd na żywo</span>
          <span class="small text-muted" id="gdpr-preview-status"></span>
        </div>
        <div id="gdpr-unknown" class="alert alert-warning small rounded-0 mb-0 py-2 d-none"></div>
        <div class="card-body gdpr-preview" id="gdpr-preview" aria-live="polite"></div>
      </div>
    </div>
  </div>
</form>

<?php if ($clause): ?>
<div class="row g-3 mt-1">
  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold small"><i class="bi bi-code-slash me-1"></i>Osadzanie na innej stronie</div>
      <div class="card-body small">
        <?php if ((int)$clause['is_published'] !== 1): ?>
          <div class="alert alert-secondary py-2">Klauzula jest szkicem — kody zaczną działać po publikacji.</div>
        <?php endif; ?>
        <?php
        $snippets = [
            'Link bezpośredni' => $pubUrl,
            'Iframe (stała wysokość)' => '<iframe src="' . $embUrl . '" title="' . h($clause['tytul']) . '" style="width:100%;height:600px;border:0" loading="lazy"></iframe>',
            'Skrypt (iframe dopasowujący wysokość)' => '<div data-gdpr-clause="' . h($clause['slug']) . '"></div>' . "\n" . '<script src="' . $jsUrl . '" async></script>',
        ];
        foreach ($snippets as $label => $code): $sid = 'snip-' . md5($label); ?>
          <label class="form-label mb-1 mt-2" for="<?= $sid ?>"><?= h($label) ?></label>
          <div class="input-group input-group-sm">
            <textarea id="<?= $sid ?>" class="form-control font-monospace" rows="<?= substr_count($code, "\n") + 1 ?>" readonly><?= h($code) ?></textarea>
            <button type="button" class="btn btn-outline-secondary gdpr-copy" data-target="<?= $sid ?>"><i class="bi bi-clipboard"></i></button>
          </div>
        <?php endforeach; ?>
        <div class="text-muted mt-2">Treść w osadzeniach pobierana jest przy każdym wyświetleniu — zmiany klauzuli i zmiennych widać od razu.</div>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold small"><i class="bi bi-clock-history me-1"></i>Historia wersji</div>
      <?php if (!$history): ?>
        <div class="card-body small text-muted">Brak wcześniejszych wersji. Poprzednia treść zapisuje się tu przy każdej zmianie.</div>
      <?php else: ?>
        <div class="list-group list-group-flush small" style="max-height:340px;overflow:auto">
          <?php foreach ($history as $hv): ?>
            <details class="list-group-item">
              <summary>
                obowiązywała <?= $hv['valid_from'] ? h(date('d.m.Y H:i', strtotime($hv['valid_from']))) : '?' ?>
                – <?= h(date('d.m.Y H:i', strtotime($hv['valid_to']))) ?>
              </summary>
              <div class="fw-semibold mt-2"><?= h($hv['tytul']) ?></div>
              <pre class="small bg-light p-2 mt-1 mb-0" style="white-space:pre-wrap"><?= h($hv['content']) ?></pre>
            </details>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  const ta = document.getElementById('gdpr-content');
  const out = document.getElementById('gdpr-preview');
  const warn = document.getElementById('gdpr-unknown');
  const status = document.getElementById('gdpr-preview-status');
  const csrf = <?= json_encode(csrf_token()) ?>;
  const updatedAt = <?= json_encode($form['updated_at']) ?>;
  let timer = null, seq = 0;

  function refresh() {
    const my = ++seq;
    status.textContent = 'odświeżanie…';
    const body = new URLSearchParams({ _csrf: csrf, content: ta.value, updated_at: updatedAt || '' });
    fetch(<?= json_encode(APP_URL . '/modules/gdpr_clauses/preview.php') ?>, { method: 'POST', body, credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : Promise.reject(r.status))
      .then(d => {
        if (my !== seq) return;
        out.innerHTML = d.html || '<p class="text-muted">Pusta treść.</p>';
        if (d.unknown && d.unknown.length) {
          warn.textContent = 'Nieznane tagi (na stronie publicznej będą puste): ' + d.unknown.map(t => '{{' + t + '}}').join(', ');
          warn.classList.remove('d-none');
        } else {
          warn.classList.add('d-none');
        }
        status.textContent = '';
      })
      .catch(() => { if (my === seq) status.textContent = 'błąd podglądu'; });
  }
  ta.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(refresh, 300); });
  refresh();

  document.querySelectorAll('.gdpr-ins').forEach(b => b.addEventListener('click', () => {
    const tag = b.dataset.tag, s = ta.selectionStart, e = ta.selectionEnd;
    ta.setRangeText(tag, s, e, 'end');
    ta.focus();
    ta.dispatchEvent(new Event('input'));
  }));

  document.querySelectorAll('.gdpr-copy').forEach(b => b.addEventListener('click', () => {
    const el = document.getElementById(b.dataset.target);
    navigator.clipboard.writeText(el.value).then(() => {
      b.innerHTML = '<i class="bi bi-check2"></i>';
      setTimeout(() => { b.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500);
    });
  }));

  // Podpowiedź sluga z tytułu dla nowej klauzuli
  const slug = document.getElementById('gdpr-slug'), title = document.getElementById('gdpr-title');
  if (!slug.value) {
    let touched = false;
    slug.addEventListener('input', () => { touched = true; });
    title.addEventListener('input', () => {
      if (touched) return;
      slug.value = title.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/ł/g, 'l').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 64);
    });
  }
})();
</script>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
