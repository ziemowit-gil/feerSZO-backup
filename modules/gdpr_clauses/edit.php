<?php
/**
 * modules/gdpr_clauses/edit.php — dodawanie/edycja klauzuli RODO z podglądem
 * na żywo (preview.php), zmiennymi lokalnymi, kodem do osadzenia i historią
 * wersji. Nowa klauzula: pusta, z szablonu (?template=, logic/templates.php),
 * jako kopia (?duplicate=) albo tłumaczenie (?translate_from=).
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

$form  = $clause ?? ['slug' => '', 'lang' => GDPR_DEFAULT_LANG, 'tytul' => '', 'content' => '', 'is_published' => 0, 'updated_at' => null, 'local_vars' => '{}'];
$error = null;
$templates = gdpr_clauses_templates();

$tplKey = !$clause ? (string)($_GET['template'] ?? '') : '';
if (isset($templates[$tplKey])) {
    $t = $templates[$tplKey];
    $form = array_merge($form, ['slug' => $tplKey, 'tytul' => $t['tytul'], 'content' => $t['content'],
                                'local_vars' => json_encode($t['local_vars'], JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT)]);
}
$duplicateOf = !$clause && !empty($_GET['duplicate']) ? $svc->getById((int)$_GET['duplicate']) : null;
if ($duplicateOf) {
    $form = array_merge($form, ['slug' => $duplicateOf['slug'] . '-kopia', 'lang' => $duplicateOf['lang'],
                                'tytul' => $duplicateOf['tytul'] . ' (kopia)', 'content' => $duplicateOf['content'],
                                'local_vars' => $duplicateOf['local_vars'] ?? '{}']);
}

// Nowe tłumaczenie istniejącej klauzuli: ten sam slug, treść źródłowa do przełożenia.
$translateFrom = !$clause && !empty($_GET['translate_from']) ? $svc->getById((int)$_GET['translate_from']) : null;
if ($translateFrom) {
    $taken = array_column(array_filter($svc->listClauses(), fn($c) => $c['slug'] === $translateFrom['slug']), 'lang');
    $free  = array_values(array_diff(array_keys(GDPR_LANGS), $taken));
    $form  = ['slug' => $translateFrom['slug'], 'lang' => $free[0] ?? 'en', 'tytul' => $translateFrom['tytul'],
              'content' => $translateFrom['content'], 'is_published' => 0, 'updated_at' => null,
              'local_vars' => $translateFrom['local_vars'] ?? '{}'];
}

// Wersja robocza (obieg akceptacji) — edytujemy ją, nie opublikowaną treść.
$draft = GdprClauseService::draft($clause);
if ($draft && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $form = array_merge($form, $draft, ['local_vars' => json_encode($draft['local_vars'] ?? [], JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
                                        'is_published' => !empty($draft['is_published']) ? 1 : 0]);
}
$canApprove = GdprClauseService::canApprove((int)$user['id']);
$self = APP_URL . '/modules/gdpr_clauses/edit.php?id=' . $id;

$wfAction = $_POST['_wf'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $clause && in_array($wfAction, ['approve', 'reject', 'discard'], true)) {
    csrf_check();
    try {
        if ($wfAction === 'approve') {
            $svc->approveDraft($id, (int)$user['id'], trim((string)($_POST['note'] ?? '')));
            flash_set('success', 'Zmiana zatwierdzona i opublikowana.');
        } elseif ($wfAction === 'reject') {
            $svc->rejectDraft($id, (int)$user['id'], (string)($_POST['note'] ?? ''));
            flash_set('warning', 'Zmiana odrzucona — autor dostał powiadomienie.');
        } else {
            if (!$canApprove && (int)$clause['draft_by'] !== (int)$user['id']) throw new InvalidArgumentException('Możesz wycofać tylko własną wersję roboczą.');
            $svc->discardDraft($id);
            flash_set('info', 'Wersja robocza wycofana.');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('danger', $e->getMessage());
    }
    header('Location: ' . $self);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form['slug']         = (string)($_POST['slug'] ?? '');
    $form['lang']         = (string)($_POST['lang'] ?? GDPR_DEFAULT_LANG);
    $form['tytul']        = (string)($_POST['tytul'] ?? '');
    $form['content']      = (string)($_POST['content'] ?? '');
    $form['is_published'] = !empty($_POST['is_published']) ? 1 : 0;
    $local = [];
    foreach ((array)($_POST['lv_key'] ?? []) as $i => $k) {
        if (trim((string)$k) !== '') $local[(string)$k] = (string)($_POST['lv_val'][$i] ?? '');
    }
    $form['local_vars'] = json_encode($local, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
    try {
        $payload = ['local_vars' => $local] + $form;
        if ($svc->needsApproval($clause, $payload, (int)$user['id'])) {
            $newId = $svc->submitDraft($id, $payload, (int)$user['id']);
            $msg = 'Zmiana zapisana jako wersja robocza i wysłana do akceptacji. Do czasu zatwierdzenia obowiązuje dotychczasowa treść.';
        } else {
            $newId = $svc->saveClause($id, $payload, (int)$user['id'],
                                      GdprClauseService::approvalRequired() && $canApprove ? (int)$user['id'] : null);
            if ($draft) $svc->discardDraft($newId);  // zapis bezpośredni zastępuje wersję roboczą
            $msg = 'Klauzula zapisana.';
        }
        $unknown = $svc->unknownTags($form['content'], $local);
        flash_set($unknown ? 'warning' : 'success', $msg
            . ($unknown ? ' Uwaga: nieznane tagi (na stronie publicznej będą puste): {{' . implode('}}, {{', $unknown) . '}}.' : ''));
        header('Location: ' . APP_URL . '/modules/gdpr_clauses/edit.php?id=' . $newId);
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

$vars    = $svc->listVariables();
$localVars = GdprClauseService::localVars($form);
$history = $clause ? $svc->history($id) : [];
// Akceptacje per wersja — widać, której wersji dotyczą zebrane zgody.
$accByVer = $clause ? array_column(db_all("SELECT version, COUNT(*) n FROM gdpr_clause_acceptances WHERE clause_id = ? GROUP BY version", [$id]), 'n', 'version') : [];
$accUrl = APP_URL . '/modules/gdpr_clauses/acceptances.php?clause_id=' . $id;
$stats  = $clause ? $svc->viewStats($id, 30) : [];
$series = $clause ? $svc->viewSeries($id, 30) : [];
$hosts  = $clause ? $svc->embedHosts($id) : [];
$pubUrl  = $clause ? gdpr_clauses_public_url($clause['slug'], false, $clause['lang']) : '';
$embUrl  = $clause ? gdpr_clauses_public_url($clause['slug'], true, $clause['lang']) : '';
$siblings = $clause ? array_values(array_filter($svc->listClauses(), fn($c) => $c['slug'] === $clause['slug'] && (int)$c['id'] !== $id)) : [];
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
  .gdpr-preview .gdpr-var-local { background: #e6f4ea; }
  .gdpr-preview .gdpr-unknown { background: #fde2e1; color: #b02a37; border-radius: 3px; }
  .gdpr-diff { white-space: normal; line-height: 1.5; max-height: 320px; overflow: auto; }
  .gdpr-diff del, del.gdpr-d { background: #fde2e1; color: #842029; text-decoration: line-through; }
  .gdpr-diff ins, ins.gdpr-i { background: #d1e7dd; color: #0f5132; text-decoration: none; }
  #gdpr-content { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-shield-lock text-primary"></i> <?= $clause ? h($clause['tytul']) : 'Nowa klauzula' ?>
    <?php if ($clause): ?><span class="badge bg-light text-dark border fs-6 align-middle" title="Bieżąca wersja treści">v<?= (int)$clause['version'] ?></span><?php endif; ?></h4>
  <div class="d-flex gap-2">
    <?php foreach ($siblings as $sb): ?>
      <a href="<?= APP_URL ?>/modules/gdpr_clauses/edit.php?id=<?= (int)$sb['id'] ?>" class="btn btn-sm btn-outline-secondary text-uppercase" title="<?= h(GDPR_LANGS[$sb['lang']] ?? $sb['lang']) ?>"><?= h($sb['lang']) ?></a>
    <?php endforeach; ?>
    <?php if ($clause && count($siblings) + 1 < count(GDPR_LANGS)): ?>
      <a href="<?= APP_URL ?>/modules/gdpr_clauses/edit.php?translate_from=<?= (int)$id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-translate me-1"></i>Dodaj tłumaczenie</a>
    <?php endif; ?>
    <?php if ($clause): ?>
      <a href="<?= h($accUrl) ?>" class="btn btn-sm btn-outline-secondary" title="Rejestr akceptacji tej klauzuli"><i class="bi bi-person-check me-1"></i><?= array_sum($accByVer) ?></a>
      <a href="<?= APP_URL ?>/modules/gdpr_clauses/edit.php?duplicate=<?= (int)$id ?>" class="btn btn-sm btn-outline-secondary" title="Nowa klauzula na wzór tej"><i class="bi bi-files me-1"></i>Duplikuj</a>
    <?php else: ?>
      <div class="dropdown">
        <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-journal-text me-1"></i>Z szablonu</button>
        <ul class="dropdown-menu dropdown-menu-end">
          <?php foreach ($templates as $tk => $t): ?>
            <li><a class="dropdown-item small <?= $tplKey === $tk ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/gdpr_clauses/edit.php?template=<?= h($tk) ?>"><?= h($t['tytul']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if ($clause && (int)$clause['is_published'] === 1): ?>
      <a href="<?= h($pubUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success"><i class="bi bi-box-arrow-up-right me-1"></i>Strona publiczna</a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/modules/gdpr_clauses/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Lista</a>
  </div>
</div>

<?= flash_html() ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if ($clause && $draft):
  $author = db_one("SELECT name FROM users WHERE id = ?", [(int)$clause['draft_by']]);
  $pending = $clause['draft_status'] === 'pending'; ?>
  <div class="card shadow-sm mb-3 border-<?= $pending ? 'warning' : 'danger' ?>">
    <div class="card-header bg-<?= $pending ? 'warning' : 'danger' ?>-subtle small fw-semibold">
      <i class="bi bi-<?= $pending ? 'hourglass-split' : 'x-octagon' ?> me-1"></i>
      <?= $pending ? 'Wersja robocza czeka na akceptację' : 'Wersja robocza odrzucona' ?>
      — <?= h($author['name'] ?? '?') ?>, <?= h(date('d.m.Y H:i', strtotime($clause['draft_at']))) ?>.
      Publicznie obowiązuje nadal v<?= (int)$clause['version'] ?><?= (int)$clause['is_published'] === 1 ? '' : ' (szkic, niepubliczny)' ?>.
    </div>
    <div class="card-body small">
      <?php if (!$pending && $clause['draft_note']): ?>
        <div class="alert alert-danger py-2"><strong>Powód odrzucenia:</strong> <?= h($clause['draft_note']) ?></div>
      <?php endif; ?>
      <?php if ((int)$clause['is_published'] !== (int)!empty($draft['is_published'])): ?>
        <div class="mb-2"><i class="bi bi-broadcast me-1"></i><?= !empty($draft['is_published']) ? 'Prośba o <strong>publikację</strong> klauzuli.' : 'Prośba o <strong>wycofanie z publikacji</strong>.' ?></div>
      <?php endif; ?>
      <div class="text-muted mb-1">Zmiany względem obowiązującej wersji:</div>
      <div class="gdpr-diff bg-light p-2"><?= gdpr_clauses_diff_html(gdpr_clauses_diff_source($clause),
          gdpr_clauses_diff_source(['tytul' => $draft['tytul'], 'content' => $draft['content'],
                                    'local_vars' => json_encode($draft['local_vars'] ?? [], JSON_UNESCAPED_UNICODE)])) ?></div>
      <div class="d-flex flex-wrap gap-2 mt-3 align-items-start">
        <?php if ($pending && $canApprove): ?>
          <form method="post" class="d-flex gap-2 flex-grow-1">
            <?= csrf_field() ?>
            <input type="text" name="note" class="form-control form-control-sm" placeholder="Komentarz (wymagany przy odrzuceniu)" aria-label="Komentarz do decyzji">
            <button name="_wf" value="approve" class="btn btn-sm btn-success text-nowrap"><i class="bi bi-check2-circle me-1"></i>Zatwierdź i opublikuj</button>
            <button name="_wf" value="reject" class="btn btn-sm btn-outline-danger text-nowrap"><i class="bi bi-x-circle me-1"></i>Odrzuć</button>
          </form>
        <?php endif; ?>
        <?php if ($canApprove || (int)$clause['draft_by'] === (int)$user['id']): ?>
          <form method="post" onsubmit="return confirm('Wycofać wersję roboczą? Zmiany przepadną.');">
            <?= csrf_field() ?>
            <button name="_wf" value="discard" class="btn btn-sm btn-outline-secondary text-nowrap">Wycofaj wersję roboczą</button>
          </form>
        <?php endif; ?>
      </div>
      <?php if ($pending && (int)$clause['draft_by'] === (int)$user['id'] && !$canApprove): ?>
        <div class="text-muted mt-2">Możesz dalej poprawiać wersję roboczą w formularzu poniżej — zapis zaktualizuje prośbę.</div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php if ($tplKey !== '' && isset($templates[$tplKey])): ?>
  <div class="alert alert-info small"><i class="bi bi-journal-text me-1"></i>Wypełniono szablonem <strong><?= h($templates[$tplKey]['tytul']) ?></strong>. To wzór do weryfikacji przez IOD — sprawdź cele, podstawy prawne i zmienne lokalne przed publikacją.</div>
<?php endif; ?>
<?php if ($translateFrom): ?>
  <div class="alert alert-info small"><i class="bi bi-translate me-1"></i>Nowa wersja językowa klauzuli <strong><?= h($translateFrom['tytul']) ?></strong> — treść skopiowana z wersji <?= h(GDPR_LANGS[$translateFrom['lang']] ?? $translateFrom['lang']) ?> do przetłumaczenia. Tagi <code>{{…}}</code> zostaw bez zmian.</div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="row g-2 mb-2">
            <div class="col-md-5">
              <label class="form-label small mb-1" for="gdpr-title">Tytuł</label>
              <input type="text" id="gdpr-title" name="tytul" class="form-control" required maxlength="255" value="<?= h($form['tytul']) ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-1" for="gdpr-lang">Język</label>
              <select id="gdpr-lang" name="lang" class="form-select">
                <?php foreach (GDPR_LANGS as $code => $name): ?>
                  <option value="<?= $code ?>" <?= $form['lang'] === $code ? 'selected' : '' ?>><?= h($name) ?></option>
                <?php endforeach; ?>
              </select>
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

          <fieldset class="mt-3 border rounded p-2">
            <legend class="float-none w-auto px-1 mb-0 small fw-semibold">Zmienne lokalne tej klauzuli</legend>
            <p class="small text-muted mb-2">Szczegóły tylko tej klauzuli (np. okres przechowywania). Nadpisują zmienną globalną o tym samym kluczu. W treści: <code>{{klucz}}</code>.</p>
            <div id="gdpr-lv">
              <?php foreach ($localVars + ['' => ''] as $lk => $lv): ?>
                <div class="input-group input-group-sm mb-1 gdpr-lv-row">
                  <span class="input-group-text">{{</span>
                  <input type="text" name="lv_key[]" class="form-control gdpr-lv-key" style="max-width:12rem" value="<?= h($lk) ?>" placeholder="klucz" pattern="[a-z][a-z0-9_]{0,63}" aria-label="Klucz zmiennej lokalnej">
                  <span class="input-group-text">}}</span>
                  <textarea name="lv_val[]" class="form-control gdpr-lv-val" rows="1" aria-label="Wartość zmiennej lokalnej"><?= h($lv) ?></textarea>
                  <button type="button" class="btn btn-outline-secondary gdpr-lv-ins" title="Wstaw do treści"><i class="bi bi-arrow-bar-up"></i></button>
                  <button type="button" class="btn btn-outline-danger gdpr-lv-del" title="Usuń"><i class="bi bi-x-lg"></i></button>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-link px-0" id="gdpr-lv-add"><i class="bi bi-plus-lg"></i> Dodaj zmienną lokalną</button>
          </fieldset>
        </div>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="gdpr-pub" name="is_published" value="1" <?= (int)$form['is_published'] === 1 ? 'checked' : '' ?>>
            <label class="form-check-label small" for="gdpr-pub">Opublikowana (dostępna publicznie)</label>
          </div>
          <div class="text-end">
            <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
            <?php if (GdprClauseService::approvalRequired() && !$canApprove): ?>
              <div class="small text-muted mt-1">Zmiana opublikowanej klauzuli lub publikacja trafi do akceptacji.</div>
            <?php endif; ?>
          </div>
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
            'PDF (bieżąca wersja)' => gdpr_clauses_pdf_url($clause['slug'], $clause['lang']),
            'Iframe (stała wysokość)' => '<iframe src="' . $embUrl . '" title="' . h($clause['tytul']) . '" style="width:100%;height:600px;border:0" loading="lazy"></iframe>',
            'Skrypt (iframe dopasowujący wysokość)' => '<div data-gdpr-clause="' . h($clause['slug']) . '"'
                . ($clause['lang'] !== GDPR_DEFAULT_LANG ? ' data-lang="' . h($clause['lang']) . '"' : '') . '></div>' . "\n" . '<script src="' . $jsUrl . '" async></script>',
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
    <div class="card shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold small"><i class="bi bi-graph-up me-1"></i>Wyświetlenia — ostatnie 30 dni</div>
      <div class="card-body small">
        <?php $max = max(1, max($series)); ?>
        <svg viewBox="0 0 300 48" width="100%" height="48" role="img" aria-label="Wyświetlenia dziennie w ostatnich 30 dniach, razem <?= (int)$stats['total'] ?>" preserveAspectRatio="none">
          <?php $i = 0; foreach ($series as $day => $n): $bh = $n ? max(2, round($n / $max * 44)) : 0; ?>
            <rect x="<?= $i * 10 + 1 ?>" y="<?= 46 - $bh ?>" width="8" height="<?= $bh ?>" rx="1" fill="currentColor" class="text-primary" opacity=".75"><title><?= h(date('d.m', strtotime($day))) ?>: <?= $n ?></title></rect>
          <?php $i++; endforeach; ?>
          <line x1="0" y1="46.5" x2="300" y2="46.5" stroke="currentColor" class="text-secondary" opacity=".3"/>
        </svg>
        <div class="d-flex flex-wrap gap-3 mt-2">
          <span><strong><?= (int)$stats['total'] ?></strong> razem</span>
          <?php foreach (GdprClauseService::VIEW_CHANNELS as $ch => $lbl): ?>
            <span class="text-muted"><?= h($lbl) ?>: <strong class="text-body"><?= (int)$stats[$ch] ?></strong></span>
          <?php endforeach; ?>
        </div>
        <?php if ($hosts): ?>
          <div class="mt-2 text-muted">Osadzona na:</div>
          <ul class="list-unstyled mb-0">
            <?php foreach ($hosts as $hh): ?>
              <li><code><?= h($hh['host']) ?></code> <span class="text-muted">· <?= (int)$hh['cnt'] ?> wyśw., ostatnio <?= h(date('d.m.Y', strtotime($hh['last_seen']))) ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <div class="text-muted mt-2" style="font-size:.75rem">Liczone zbiorczo, bez adresów IP; ruch botów pomijany.</div>
      </div>
    </div>
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold small"><i class="bi bi-clock-history me-1"></i>Historia wersji</div>
      <?php if (!$history): ?>
        <div class="card-body small text-muted">Brak wcześniejszych wersji. Poprzednia treść zapisuje się tu przy każdej zmianie.</div>
      <?php else: ?>
        <div class="list-group list-group-flush small" style="max-height:340px;overflow:auto">
          <?php foreach ($history as $hi => $hv):
                // Następna wersja = poprzedni element listy (historia malejąco) albo bieżąca.
                $next = $hi === 0 ? $clause : $history[$hi - 1]; ?>
            <details class="list-group-item">
              <summary>
                <span class="badge bg-light text-dark border">v<?= (int)$hv['version'] ?></span>
                <?= $hv['valid_from'] ? h(date('d.m.Y H:i', strtotime($hv['valid_from']))) : '?' ?>
                – <?= h(date('d.m.Y H:i', strtotime($hv['valid_to']))) ?>
                <?php if (!empty($accByVer[$hv['version']])): ?>
                  <span class="badge bg-success-subtle text-success-emphasis ms-1" title="Akceptacje tej wersji"><i class="bi bi-person-check"></i> <?= (int)$accByVer[$hv['version']] ?></span>
                <?php endif; ?>
              </summary>
              <div class="small text-muted mt-2">Zmiany w v<?= (int)$next['version'] ?> względem v<?= (int)$hv['version'] ?>
                (<del class="gdpr-d">usunięte</del> / <ins class="gdpr-i">dodane</ins>):</div>
              <div class="gdpr-diff small bg-light p-2 mt-1"><?= gdpr_clauses_diff_html(gdpr_clauses_diff_source($hv), gdpr_clauses_diff_source($next)) ?></div>
              <details class="mt-1">
                <summary class="small text-muted">Pełna treść v<?= (int)$hv['version'] ?></summary>
                <pre class="small bg-light p-2 mt-1 mb-0" style="white-space:pre-wrap"><?= h(gdpr_clauses_diff_source($hv)) ?></pre>
              </details>
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
    const lv = {};
    document.querySelectorAll('.gdpr-lv-row').forEach(r => {
      const k = r.querySelector('.gdpr-lv-key').value.trim();
      if (k) lv[k] = r.querySelector('.gdpr-lv-val').value;
    });
    const body = new URLSearchParams({ _csrf: csrf, content: ta.value, updated_at: updatedAt || '', local_vars: JSON.stringify(lv) });
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
  const schedule = () => { clearTimeout(timer); timer = setTimeout(refresh, 300); };
  ta.addEventListener('input', schedule);
  const lvBox = document.getElementById('gdpr-lv');
  lvBox.addEventListener('input', schedule);
  lvBox.addEventListener('click', e => {
    const row = e.target.closest('.gdpr-lv-row');
    if (!row) return;
    if (e.target.closest('.gdpr-lv-del')) {
      if (lvBox.querySelectorAll('.gdpr-lv-row').length > 1) row.remove();
      else row.querySelectorAll('input,textarea').forEach(i => { i.value = ''; });
      schedule();
    } else if (e.target.closest('.gdpr-lv-ins')) {
      const k = row.querySelector('.gdpr-lv-key').value.trim();
      if (k) { ta.setRangeText('{{' + k + '}}', ta.selectionStart, ta.selectionEnd, 'end'); ta.focus(); schedule(); }
    }
  });
  document.getElementById('gdpr-lv-add').addEventListener('click', () => {
    const tpl = lvBox.querySelector('.gdpr-lv-row').cloneNode(true);
    tpl.querySelectorAll('input,textarea').forEach(i => { i.value = ''; });
    lvBox.appendChild(tpl);
    tpl.querySelector('input').focus();
  });
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
