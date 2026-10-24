<?php
/**
 * Edytor komunikatów popup (okna informacyjne w Panelu współpracownika) — tylko administrator.
 * Logika wyświetlania: lib/popups.php. ?edit=<id> | ?new=1 — formularz; ?preview=<id> — podgląd okna na tej stronie.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/lib/popups.php';

require_role('admin');
popupsMigrate();
$SELF = APP_URL . '/modules/popups/index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['_action'] ?? '');
    $id  = (int)($_POST['id'] ?? 0);
    if ($act === 'save') {
        $title = trim((string)($_POST['title'] ?? ''));
        $slug  = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim((string)($_POST['slug'] ?? ''))));
        $slug  = trim($slug, '-') ?: ('popup-' . date('YmdHis'));
        $color = array_key_exists($_POST['color'] ?? '', POPUP_COLORS) ? $_POST['color'] : 'blue';
        $shell = 'panel';
        $roles = implode(',', array_map('trim', array_filter((array)($_POST['roles'] ?? []), 'is_string')));
        $icon  = preg_match('/^bi-[a-z0-9-]+$/', (string)($_POST['icon'] ?? '')) ? $_POST['icon'] : 'bi-info-circle-fill';
        $dt    = fn($k) => ($v = trim((string)($_POST[$k] ?? ''))) !== '' ? str_replace('T', ' ', $v) . (strlen($v) === 16 ? ':00' : '') : null;
        $body  = popupsSanitize((string)($_POST['body'] ?? ''));
        $url   = popupsSafeUrl((string)($_POST['btn_url'] ?? ''));
        if ($title === '') { flash_set('danger', 'Podaj tytuł komunikatu.'); header('Location: ' . $SELF . ($id ? '?edit=' . $id : '?new=1')); exit; }
        $f = [$slug, $title, $body, $icon, $color, $shell, $roles, $dt('starts_at'), $dt('ends_at'), empty($_POST['is_active']) ? 0 : 1,
              trim((string)($_POST['btn_label'] ?? '')), $url, empty($_POST['bug_button']) ? 0 : 1, (int)($_POST['sort'] ?? 100)];
        try {
            if ($id) {
                $bump = !empty($_POST['republish']) ? 1 : 0;
                db()->prepare("UPDATE popups SET slug=?,title=?,body=?,icon=?,color=?,shell=?,roles=?,starts_at=?,ends_at=?,is_active=?,btn_label=?,btn_url=?,bug_button=?,sort=?,
                               version=version+?, updated_at=datetime('now') WHERE id=?")->execute(array_merge($f, [$bump, $id]));
                flash_set('success', 'Komunikat zapisany' . ($bump ? ' — zobaczą go ponownie wszyscy.' : '.'));
            } else {
                db()->prepare("INSERT INTO popups (slug,title,body,icon,color,shell,roles,starts_at,ends_at,is_active,btn_label,btn_url,bug_button,sort,created_at,updated_at)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))")->execute($f);
                $id = (int)db()->lastInsertId();
                flash_set('success', 'Komunikat dodany.');
            }
        } catch (\Throwable $e) { flash_set('danger', 'Nie udało się zapisać (identyfikator „' . $slug . '” jest już użyty?).'); }
        header('Location: ' . $SELF . '?edit=' . $id); exit;
    }
    if ($act === 'toggle' && $id) { db()->prepare("UPDATE popups SET is_active = 1 - is_active WHERE id=?")->execute([$id]); }
    if ($act === 'delete' && $id) { db()->prepare("DELETE FROM popups WHERE id=?")->execute([$id]); flash_set('success', 'Komunikat usunięty.'); }
    header('Location: ' . $SELF); exit;
}

$edit = null; $isForm = isset($_GET['new']) || isset($_GET['edit']);
if (isset($_GET['edit'])) { $edit = db_one("SELECT * FROM popups WHERE id=?", [(int)$_GET['edit']]); if (!$edit) { header('Location: ' . $SELF); exit; } }
if (isset($_GET['preview'])) $GLOBALS['POPUPS_PREVIEW'] = (int)$_GET['preview'];
$rows   = db_all("SELECT * FROM popups ORDER BY sort, id");
$roleOpts = array_column(db_all("SELECT DISTINCT role FROM users WHERE role IS NOT NULL AND role<>'' ORDER BY role"), 'role');
$e = $edit ?: ['id' => 0, 'slug' => '', 'title' => '', 'body' => '', 'icon' => 'bi-info-circle-fill', 'color' => 'blue', 'shell' => 'panel', 'roles' => '',
               'starts_at' => '', 'ends_at' => '', 'is_active' => 1, 'version' => 1, 'btn_label' => '', 'btn_url' => '', 'bug_button' => 0, 'sort' => 100];
$sel  = array_filter(explode(',', (string)$e['roles']));
$dtl  = fn($v) => $v ? str_replace(' ', 'T', substr((string)$v, 0, 16)) : '';
$PAGE_TITLE = 'Komunikaty popup';
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<div class="container-fluid py-3" style="max-width:1100px">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
      <h1 class="h3 mb-1"><i class="bi bi-window-stack me-2" aria-hidden="true"></i>Komunikaty popup</h1>
      <p class="text-muted mb-0">Okna informacyjne pokazywane współpracownikom po wejściu do Panelu (nie w SZO administratorów i zarządu) — każdy raz na przeglądarkę.</p>
    </div>
    <?php if ($isForm): ?><a class="btn btn-outline-secondary" href="<?= h($SELF) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Lista</a>
    <?php else: ?><a class="btn btn-primary" href="<?= h($SELF) ?>?new=1"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nowy komunikat</a><?php endif; ?>
  </div>

<?php if (!$isForm): ?>
  <div class="table-responsive"><table class="table align-middle bg-white border">
    <thead><tr><th scope="col">Komunikat</th><th scope="col">Gdzie / dla kogo</th><th scope="col">Okres</th><th scope="col">Stan</th><th scope="col" class="text-end">Akcje</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): $c = POPUP_COLORS[$p['color']] ?? POPUP_COLORS['blue']; ?>
      <tr>
        <td><span class="d-inline-flex align-items-center justify-content-center text-white me-2" style="width:30px;height:30px;background:<?= $c[1] ?>"><i class="bi <?= h($p['icon']) ?>" aria-hidden="true"></i></span>
            <strong><?= h($p['title']) ?></strong> <span class="text-muted small">v<?= (int)$p['version'] ?></span></td>
        <td class="small"><?= 'Panel współpracownika' ?> · <?= $p['roles'] !== '' ? h($p['roles']) : 'wszyscy' ?></td>
        <td class="small"><?= $p['starts_at'] ? h(substr($p['starts_at'], 0, 16)) : 'od zaraz' ?> → <?= $p['ends_at'] ? h(substr($p['ends_at'], 0, 16)) : 'bez końca' ?></td>
        <td><?= $p['is_active'] ? '<span class="badge text-bg-success">aktywny</span>' : '<span class="badge text-bg-secondary">wyłączony</span>' ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-outline-secondary" href="<?= h($SELF) ?>?preview=<?= (int)$p['id'] ?>"><i class="bi bi-eye" aria-hidden="true"></i> Podgląd</a>
          <a class="btn btn-sm btn-outline-primary" href="<?= h($SELF) ?>?edit=<?= (int)$p['id'] ?>"><i class="bi bi-pencil" aria-hidden="true"></i> Edytuj</a>
          <form method="post" class="d-inline"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary"><?= $p['is_active'] ? 'Wyłącz' : 'Włącz' ?></button></form>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć komunikat?')"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-sm btn-outline-danger" aria-label="Usuń <?= h($p['title']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
        </td>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="text-muted text-center py-4">Brak komunikatów.</td></tr><?php endif; ?>
    </tbody></table></div>
<?php else: ?>
  <form method="post" class="row g-4">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="save"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
    <div class="col-lg-7">
      <div class="mb-3"><label class="form-label fw-semibold" for="pp-title">Tytuł</label>
        <input class="form-control" id="pp-title" name="title" value="<?= h($e['title']) ?>" maxlength="120" required></div>
      <div class="mb-3"><label class="form-label fw-semibold" for="pp-body">Treść</label>
        <div class="btn-group btn-group-sm mb-1" role="group" aria-label="Formatowanie">
          <button type="button" class="btn btn-outline-secondary" data-wrap="strong" aria-label="Pogrubienie"><i class="bi bi-type-bold" aria-hidden="true"></i></button>
          <button type="button" class="btn btn-outline-secondary" data-wrap="em" aria-label="Kursywa"><i class="bi bi-type-italic" aria-hidden="true"></i></button>
          <button type="button" class="btn btn-outline-secondary" data-wrap="li" aria-label="Pozycja listy"><i class="bi bi-list-ul" aria-hidden="true"></i></button>
          <button type="button" class="btn btn-outline-secondary" data-wrap="a" aria-label="Link"><i class="bi bi-link-45deg" aria-hidden="true"></i></button>
        </div>
        <textarea class="form-control font-monospace" id="pp-body" name="body" rows="10" aria-describedby="pp-body-h"><?= h($e['body']) ?></textarea>
        <div class="form-text" id="pp-body-h">Dozwolone znaczniki: &lt;p&gt; &lt;br&gt; &lt;strong&gt; &lt;em&gt; &lt;u&gt; &lt;ul&gt; &lt;ol&gt; &lt;li&gt; &lt;a href&gt; &lt;h4&gt; &lt;small&gt;. Pozostałe są usuwane.</div></div>
      <div class="row g-2">
        <div class="col-sm-6"><label class="form-label fw-semibold" for="pp-bl">Przycisk — napis (opcjonalnie)</label><input class="form-control" id="pp-bl" name="btn_label" value="<?= h($e['btn_label']) ?>" maxlength="60"></div>
        <div class="col-sm-6"><label class="form-label fw-semibold" for="pp-bu">Przycisk — adres</label><input class="form-control" id="pp-bu" name="btn_url" value="<?= h($e['btn_url']) ?>" placeholder="/helpdesk/new.php lub https://…"></div>
      </div>
      <div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="pp-bug" name="bug_button" value="1" <?= $e['bug_button'] ? 'checked' : '' ?>><label class="form-check-label" for="pp-bug">Pokaż przycisk „Zgłoś błąd”</label></div>
    </div>
    <div class="col-lg-5">
      <div class="row g-2 mb-3">
        <div class="col-6"><label class="form-label fw-semibold" for="pp-color">Kolor</label>
          <select class="form-select" id="pp-color" name="color"><?php foreach (POPUP_COLORS as $k => $c): ?><option value="<?= $k ?>" <?= $e['color'] === $k ? 'selected' : '' ?>><?= h($c[0]) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label fw-semibold" for="pp-icon">Ikona (Bootstrap Icons)</label><input class="form-control" id="pp-icon" name="icon" value="<?= h($e['icon']) ?>" pattern="bi-[a-z0-9-]+"></div>
        <div class="col-6"><label class="form-label fw-semibold" for="pp-sort">Kolejność</label><input class="form-control" id="pp-sort" type="number" name="sort" value="<?= (int)$e['sort'] ?>"></div>
        <div class="col-6"><label class="form-label fw-semibold" for="pp-from">Od</label><input class="form-control" id="pp-from" type="datetime-local" name="starts_at" value="<?= h($dtl($e['starts_at'])) ?>"></div>
        <div class="col-6"><label class="form-label fw-semibold" for="pp-to">Do</label><input class="form-control" id="pp-to" type="datetime-local" name="ends_at" value="<?= h($dtl($e['ends_at'])) ?>"></div>
        <div class="col-12"><label class="form-label fw-semibold" for="pp-slug">Identyfikator (bez spacji)</label><input class="form-control" id="pp-slug" name="slug" value="<?= h($e['slug']) ?>" placeholder="np. awaria-poczty"></div>
      </div>
      <fieldset class="mb-3"><legend class="form-label fw-semibold fs-6">Dla kogo (puste = wszyscy)</legend>
        <?php foreach ($roleOpts as $r): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="pp-r-<?= h($r) ?>" name="roles[]" value="<?= h($r) ?>" <?= in_array($r, $sel, true) ? 'checked' : '' ?>><label class="form-check-label" for="pp-r-<?= h($r) ?>"><?= h($r) ?></label></div><?php endforeach; ?>
      </fieldset>
      <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="pp-act" name="is_active" value="1" <?= $e['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="pp-act">Aktywny</label></div>
      <?php if ($e['id']): ?><div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="pp-rep" name="republish" value="1"><label class="form-check-label" for="pp-rep">Pokaż ponownie wszystkim (podbij wersję v<?= (int)$e['version'] ?> → v<?= (int)$e['version'] + 1 ?>)</label></div><?php endif; ?>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Zapisz</button>
        <?php if ($e['id']): ?><a class="btn btn-outline-secondary" href="<?= h($SELF) ?>?preview=<?= (int)$e['id'] ?>"><i class="bi bi-eye" aria-hidden="true"></i> Podgląd (zapisanej wersji)</a><?php endif; ?>
      </div>
    </div>
  </form>
  <script>
  document.querySelectorAll('[data-wrap]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = document.getElementById('pp-body'), s = t.selectionStart, e = t.selectionEnd, tag = b.dataset.wrap, sel = t.value.slice(s, e) || 'tekst';
      var open = tag === 'a' ? '<a href="https://">' : '<' + tag + '>';
      t.setRangeText(open + sel + '</' + tag + '>', s, e, 'end'); t.focus();
    });
  });
  </script>
<?php endif; ?>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
