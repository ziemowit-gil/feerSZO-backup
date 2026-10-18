<?php
/**
 * modules/it_portal/edit.php — Portal Usług IT: edycja wpisu (admin/editor).
 * ?type=notice|category|audience|article|contact[&id=N] — bez id: nowy wpis. Artykuł: &k=N podpowiada usługę.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/itPortal.php';

require_role('admin', 'editor');
itPortalMigrate();

$types = [
    'notice'   => ['tab' => 'komunikaty', 'new' => 'Nowy komunikat',  'edit' => 'Komunikat'],
    'category' => ['tab' => 'uslugi',     'new' => 'Nowa usługa',     'edit' => 'Usługa'],
    'audience' => ['tab' => 'grupy',      'new' => 'Nowa grupa',      'edit' => 'Grupa'],
    'article'  => ['tab' => 'artykuly',   'new' => 'Nowy artykuł',    'edit' => 'Artykuł'],
    'contact'  => ['tab' => 'kontakt',    'new' => 'Nowy wiersz kontaktu', 'edit' => 'Kontakt'],
];
$type = isset($types[$_GET['type'] ?? '']) ? $_GET['type'] : 'notice';
$id   = (int)($_GET['id'] ?? 0);
$back = APP_URL . '/modules/it_portal/index.php?tab=' . $types[$type]['tab'];
$user = current_user();

$load = fn() => match ($type) {
    'notice'   => itPortalNotice($id),
    'category' => itPortalCategory($id),
    'audience' => itPortalAudience($id),
    'article'  => itPortalArticle($id),
    'contact'  => itPortalContact($id),
};
$row = $id ? $load() : null;
if ($id && !$row) { flash_set('danger', 'Nie ma takiego wpisu.'); header('Location: ' . $back); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $savedId = match ($type) {
            'notice'   => itPortalNoticeSave($_POST, $id, (int)$user['id']),
            'category' => itPortalCategorySave($_POST, $id),
            'audience' => itPortalAudienceSave($_POST, $id),
            'article'  => itPortalArticleSave($_POST, $id, (int)$user['id']),
            'contact'  => itPortalContactSave($_POST, $id),
        };
        if ($type === 'notice' && trim((string)($_POST['signature'] ?? '')) !== '') org_setting_set('itp_last_signature', trim((string)$_POST['signature']));
        flash_set('success', 'Zapisano.');
        header('Location: ' . (!empty($_POST['_stay']) ? APP_URL . '/modules/it_portal/edit.php?type=' . $type . '&id=' . $savedId : $back));
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
        $row = array_merge($row ?? [], $_POST);   // zostaw to, co wpisano
    }
}

// Wartości domyślne nowego wpisu
$row ??= match ($type) {
    'notice'   => ['title' => '', 'body_html' => '', 'status' => 'w_toku', 'signature' => (org_setting('itp_last_signature') ?: 'Dział IT'), 'notice_date' => date('Y-m-d'), 'is_published' => 1],
    'category' => ['name' => '', 'slug' => '', 'icon' => 'help', 'intro_html' => '', 'external_url' => '', 'audiences' => '[]', 'related' => '[]', 'sort_order' => (int)(db_one("SELECT MAX(sort_order) AS m FROM itp_categories")['m'] ?? 0) + 10, 'is_active' => 1],
    'audience' => ['name' => '', 'slug' => '', 'icon' => 'user', 'description' => '', 'services' => [], 'sort_order' => (int)(db_one("SELECT MAX(sort_order) AS m FROM itp_audiences")['m'] ?? 0) + 1, 'is_active' => 1],
    'article'  => ['category_id' => (int)($_GET['k'] ?? 0), 'title' => '', 'slug' => '', 'body_html' => '', 'keywords' => '', 'related' => '[]', 'is_pinned' => 0, 'is_published' => 1, 'sort_order' => 0],
    'contact'  => ['unit' => '', 'contact_html' => '', 'team' => '', 'sort_order' => (int)(db_one("SELECT MAX(sort_order) AS m FROM itp_contacts")['m'] ?? 0) + 10],
};
$v = fn(string $k) => h((string)($row[$k] ?? ''));
$chk = fn(string $k) => !empty($row[$k]) ? ' checked' : '';

$PAGE_TITLE = ($id ? $types[$type]['edit'] : $types[$type]['new']) . ' — Portal Usług IT';
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<nav aria-label="Ścieżka" class="small mb-2"><a href="<?= h($back) ?>"><i class="bi bi-arrow-left"></i> Portal Usług IT</a></nav>
<h4 class="mb-3"><?= h($id ? $types[$type]['edit'] . ': ' . ($row['title'] ?? $row['name'] ?? $row['unit'] ?? '') : $types[$type]['new']) ?></h4>
<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>

<form method="post" class="card card-body" style="max-width:980px">
  <?= csrf_field() ?>

<?php if ($type === 'notice'): ?>
  <div class="row g-3">
    <div class="col-md-8"><label class="form-label" for="f-t">Tytuł</label><input id="f-t" class="form-control" name="title" value="<?= $v('title') ?>" required></div>
    <div class="col-md-4"><label class="form-label" for="f-d">Data</label><input id="f-d" type="date" class="form-control" name="notice_date" value="<?= $v('notice_date') ?>"></div>
    <div class="col-md-6">
      <span class="form-label d-block">Status</span>
      <?php foreach (ITP_NOTICE_STATUSES as $k => $s): ?>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="status" id="st-<?= $k ?>" value="<?= $k ?>"<?= ($row['status'] ?? '') === $k ? ' checked' : '' ?>>
          <label class="form-check-label" for="st-<?= $k ?>"><span class="d-inline-block rounded-circle me-1" style="width:10px;height:10px;background:<?= h($s['color']) ?>"></span><?= h($s['label']) ?></label>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="col-md-6"><label class="form-label" for="f-s">Podpis (dział)</label><input id="f-s" class="form-control" name="signature" value="<?= $v('signature') ?>"></div>
    <div class="col-12"><label class="form-label" for="f-b">Treść</label><textarea id="f-b" class="form-control itp-rich" name="body_html" rows="10"><?= $v('body_html') ?></textarea></div>
    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="f-p" name="is_published" value="1"<?= $chk('is_published') ?>><label class="form-check-label" for="f-p">Opublikowany</label></div></div>
  </div>

<?php elseif ($type === 'category'):
    $auds = itPortalAudiences(); $avail = itPortalIds($row['audiences'] ?? '[]'); $rel = itPortalIds($row['related'] ?? '[]');
    $allArts = itPortalAllArticles(); ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="f-n">Nazwa</label><input id="f-n" class="form-control" name="name" value="<?= $v('name') ?>" required></div>
    <div class="col-md-4"><label class="form-label" for="f-sl">Adres <span class="text-muted small">(puste = z nazwy)</span></label>
      <div class="input-group"><span class="input-group-text small">/uslugi/</span><input id="f-sl" class="form-control" name="slug" value="<?= $v('slug') ?>" pattern="[a-z0-9-]*"></div></div>
    <div class="col-md-2"><label class="form-label" for="f-o">Kolejność</label><input id="f-o" type="number" class="form-control" name="sort_order" value="<?= $v('sort_order') ?>"></div>
    <div class="col-12">
      <span class="form-label d-block">Ikona</span>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach (itPortalIconLabels() as $k => $label): ?>
          <input type="radio" class="btn-check" name="icon" id="ic-<?= $k ?>" value="<?= $k ?>"<?= ($row['icon'] ?? '') === $k ? ' checked' : '' ?>>
          <label class="btn btn-outline-primary p-1" for="ic-<?= $k ?>" title="<?= h($label) ?>" style="width:56px;height:56px"><?= itPortalIcon($k) ?><span class="visually-hidden"><?= h($label) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-12"><label class="form-label" for="f-u">Link zewnętrzny <span class="text-muted small">(opcjonalnie — kafel prowadzi wtedy np. do Helpdesku zamiast do instrukcji)</span></label>
      <input id="f-u" class="form-control" name="external_url" value="<?= $v('external_url') ?>" placeholder="https://… albo /helpdesk/new.php"></div>
    <div class="col-12"><label class="form-label" for="f-i">Wstęp usługi <span class="text-muted small">(strona usługi bez wybranego artykułu; puste = od razu pierwszy artykuł)</span></label>
      <textarea id="f-i" class="form-control itp-rich" name="intro_html" rows="8"><?= $v('intro_html') ?></textarea></div>
    <fieldset class="col-md-5">
      <legend class="form-label fs-6">Dla kogo dostępna</legend>
      <?php foreach ($auds as $a): ?>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="audiences[]" id="au-<?= (int)$a['id'] ?>" value="<?= (int)$a['id'] ?>"<?= in_array((int)$a['id'], $avail, true) ? ' checked' : '' ?>><label class="form-check-label" for="au-<?= (int)$a['id'] ?>"><?= h($a['name']) ?></label></div>
      <?php endforeach; ?>
      <?php if (!$auds): ?><p class="small text-muted">Brak grup.</p><?php endif; ?>
      <a class="small" href="<?= h(APP_URL . '/modules/it_portal/index.php?tab=grupy') ?>"><i class="bi bi-people"></i> Zarządzaj grupami</a>
    </fieldset>
    <div class="col-md-7"><label class="form-label" for="f-r">Powiązane tematy przy wstępie <span class="text-muted small">(Ctrl/⌘ = kilka)</span></label>
      <select id="f-r" class="form-select" name="related[]" multiple size="8">
        <?php foreach ($allArts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= in_array((int)$a['id'], $rel, true) ? ' selected' : '' ?>><?= h($a['category_name'] . ' › ' . $a['title']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="f-a" name="is_active" value="1"<?= $chk('is_active') ?>><label class="form-check-label" for="f-a">Widoczna w portalu</label></div></div>
  </div>

<?php elseif ($type === 'audience'):
    $cats = itPortalCategories(false);
    $sel = isset($row['services']) ? itPortalIds($row['services'])
         : array_map(fn($c) => (int)$c['id'], array_filter($cats, fn($c) => in_array($id, itPortalIds($c['audiences']), true))); ?>
  <input type="hidden" name="services_sent" value="1">
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="f-n">Nazwa grupy</label><input id="f-n" class="form-control" name="name" value="<?= $v('name') ?>" required placeholder="np. Wolontariusz"></div>
    <div class="col-md-4"><label class="form-label" for="f-sl">Adres <span class="text-muted small">(puste = z nazwy)</span></label>
      <div class="input-group"><span class="input-group-text small">/dla/</span><input id="f-sl" class="form-control" name="slug" value="<?= $v('slug') ?>" pattern="[a-z0-9-]*"></div></div>
    <div class="col-md-2"><label class="form-label" for="f-o">Kolejność</label><input id="f-o" type="number" class="form-control" name="sort_order" value="<?= $v('sort_order') ?>"></div>
    <div class="col-12">
      <span class="form-label d-block">Sylwetka</span>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach (ITP_AUDIENCE_ICONS as $k => $label): ?>
          <input type="radio" class="btn-check" name="icon" id="ai-<?= $k ?>" value="<?= $k ?>"<?= ($row['icon'] ?? '') === $k ? ' checked' : '' ?>>
          <label class="btn btn-outline-primary p-1 d-flex flex-column align-items-center" for="ai-<?= $k ?>" style="width:96px"><span style="width:36px;height:36px"><?= itPortalAudienceIcon($k) ?></span><span class="small lh-sm"><?= h($label) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-12"><label class="form-label" for="f-d">Opis <span class="text-muted small">(pokazywany nad katalogiem grupy, np. kto do niej należy i jak uzyskać dostęp)</span></label>
      <textarea id="f-d" class="form-control" name="description" rows="3"><?= $v('description') ?></textarea></div>
    <fieldset class="col-12">
      <legend class="form-label fs-6">Usługi dostępne dla tej grupy</legend>
      <div class="row row-cols-1 row-cols-md-3 g-1">
        <?php foreach ($cats as $c): ?>
          <div class="col"><div class="form-check"><input class="form-check-input" type="checkbox" name="services[]" id="sv-<?= (int)$c['id'] ?>" value="<?= (int)$c['id'] ?>"<?= in_array((int)$c['id'], $sel, true) ? ' checked' : '' ?>><label class="form-check-label<?= $c['is_active'] ? '' : ' text-muted' ?>" for="sv-<?= (int)$c['id'] ?>"><?= h($c['name']) ?></label></div></div>
        <?php endforeach; ?>
      </div>
      <?php if (!$cats): ?><p class="small text-muted">Brak usług.</p><?php endif; ?>
    </fieldset>
    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="f-a" name="is_active" value="1"<?= $chk('is_active') ?>><label class="form-check-label" for="f-a">Widoczna w portalu (filtr „Dla kogo” i katalog <code>/dla/…</code>)</label></div></div>
  </div>

<?php elseif ($type === 'article'):
    $cats = itPortalCategories(false); $rel = itPortalIds($row['related'] ?? '[]'); $allArts = itPortalAllArticles(); ?>
  <div class="row g-3">
    <div class="col-md-8"><label class="form-label" for="f-t">Tytuł</label><input id="f-t" class="form-control" name="title" value="<?= $v('title') ?>" required></div>
    <div class="col-md-4"><label class="form-label" for="f-c">Usługa</label>
      <select id="f-c" class="form-select" name="category_id" required>
        <option value="">— wybierz —</option>
        <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)($row['category_id'] ?? 0) === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-6"><label class="form-label" for="f-sl">Adres <span class="text-muted small">(puste = z tytułu)</span></label><input id="f-sl" class="form-control" name="slug" value="<?= $v('slug') ?>" pattern="[a-z0-9-]*"></div>
    <div class="col-md-2"><label class="form-label" for="f-o">Kolejność</label><input id="f-o" type="number" class="form-control" name="sort_order" value="<?= $v('sort_order') ?>"></div>
    <div class="col-md-4"><label class="form-label" for="f-k">Słowa kluczowe <span class="text-muted small">(dla wyszukiwarki)</span></label><input id="f-k" class="form-control" name="keywords" value="<?= $v('keywords') ?>" placeholder="np. hasło, reset, logowanie"></div>
    <div class="col-12"><label class="form-label" for="f-b">Treść</label><textarea id="f-b" class="form-control itp-rich" name="body_html" rows="14"><?= $v('body_html') ?></textarea></div>
    <div class="col-md-7"><label class="form-label" for="f-r">Powiązane tematy <span class="text-muted small">(Ctrl/⌘ = kilka)</span></label>
      <select id="f-r" class="form-select" name="related[]" multiple size="8">
        <?php foreach ($allArts as $a): if ((int)$a['id'] === $id) continue; ?><option value="<?= (int)$a['id'] ?>"<?= in_array((int)$a['id'], $rel, true) ? ' selected' : '' ?>><?= h($a['category_name'] . ' › ' . $a['title']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-5 pt-md-4">
      <div class="form-check"><input class="form-check-input" type="checkbox" id="f-p" name="is_published" value="1"<?= $chk('is_published') ?>><label class="form-check-label" for="f-p">Opublikowany</label></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="f-pin" name="is_pinned" value="1"<?= $chk('is_pinned') ?>><label class="form-check-label" for="f-pin">Przypnij do „Popularne instrukcje” na Start</label></div>
      <?php if ($id): ?><p class="small text-muted mt-2 mb-0">Wyświetlenia: <?= (int)($row['views'] ?? 0) ?></p><?php endif; ?>
    </div>
  </div>

<?php else: ?>
  <div class="row g-3">
    <div class="col-md-9"><label class="form-label" for="f-u">Jednostka</label><input id="f-u" class="form-control" name="unit" value="<?= $v('unit') ?>" required></div>
    <div class="col-md-3"><label class="form-label" for="f-o">Kolejność</label><input id="f-o" type="number" class="form-control" name="sort_order" value="<?= $v('sort_order') ?>"></div>
    <div class="col-md-7"><label class="form-label" for="f-c">Kontakt <span class="text-muted small">(nazwa działu, telefon, e-mail, pokój…)</span></label><textarea id="f-c" class="form-control itp-rich" name="contact_html" rows="6"><?= $v('contact_html') ?></textarea></div>
    <div class="col-md-5"><label class="form-label" for="f-team">Zespół</label>
      <textarea id="f-team" class="form-control font-monospace small" name="team" rows="9" placeholder="Jan Kowalski | jan.kowalski@feer.org.pl"><?= $v('team') ?></textarea>
      <div class="form-text">Jedna osoba w wierszu: <code>Imię Nazwisko | e-mail, telefon albo link</code>.</div></div>
  </div>
<?php endif; ?>

  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary">Zapisz</button>
    <button class="btn btn-outline-primary" name="_stay" value="1">Zapisz i edytuj dalej</button>
    <a class="btn btn-link" href="<?= h($back) ?>">Anuluj</a>
  </div>
</form>
<?php include __DIR__ . '/_editor.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
