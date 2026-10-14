<?php
/**
 * modules/it_portal/index.php — Portal Usług IT: panel edycji (admin/editor).
 *
 * Zakładki (?tab=): komunikaty | uslugi | artykuly | kontakt | ustawienia.
 * Edycja pojedynczych wpisów: edit.php?type=notice|category|article|contact&id=N.
 * Tu obsługiwane POST-y: usuwanie wpisów, ustawienia (nagłówek, baner, wstęp Kontaktu,
 * stopka) i grupy odbiorców „Dostępność usługi”.
 * Strony publiczne: <APP_URL>/uslugi-it/ (linkowane z it.feer.org.pl).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/itPortal.php';

require_role('admin', 'editor');
itPortalMigrate();

$tabs = ['komunikaty' => 'Komunikaty', 'uslugi' => 'Usługi', 'artykuly' => 'Artykuły', 'kontakt' => 'Kontakt', 'ustawienia' => 'Ustawienia'];
$tab  = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'komunikaty';
$self = APP_URL . '/modules/it_portal/index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        switch ($op) {
            case 'delete_notice':   itPortalNoticeDelete($id);   flash_set('success', 'Komunikat usunięty.'); break;
            case 'delete_category': itPortalCategoryDelete($id); flash_set('success', 'Usługa usunięta razem z jej artykułami.'); break;
            case 'delete_article':  itPortalArticleDelete($id);  flash_set('success', 'Artykuł usunięty.'); break;
            case 'delete_contact':  itPortalContactDelete($id);  flash_set('success', 'Wiersz kontaktu usunięty.'); break;
            case 'settings':
                foreach (['title', 'subtitle', 'logo_url', 'footer_links'] as $k) itPortalSettingSet($k, trim((string)($_POST[$k] ?? '')));
                itPortalSettingSet('banner_on', !empty($_POST['banner_on']) ? '1' : '0');
                itPortalSettingSet('banner_html', itPortalSanitizeHtml((string)($_POST['banner_html'] ?? '')));
                itPortalSettingSet('contact_intro', itPortalSanitizeHtml((string)($_POST['contact_intro'] ?? '')));
                flash_set('success', 'Ustawienia zapisane.');
                break;
            case 'audiences':
                foreach ((array)($_POST['aud'] ?? []) as $aid => $row) {
                    $aid = (int)$aid;
                    if (!empty($row['delete'])) { itPortalAudienceDelete($aid); continue; }
                    if ($aid > 0) itPortalAudienceSave($row, $aid);
                }
                if (trim((string)($_POST['new']['name'] ?? '')) !== '') itPortalAudienceSave($_POST['new']);
                flash_set('success', 'Grupy odbiorców zapisane.');
                break;
        }
    } catch (InvalidArgumentException $e) {
        flash_set('danger', $e->getMessage());
    }
    header('Location: ' . $self . '?tab=' . $tab);
    exit;
}

$PAGE_TITLE = 'Portal Usług IT';
include dirname(__DIR__, 2) . '/includes/header.php';

$del = function (string $op, int $id, string $what): string {
    return '<form method="post" class="d-inline" onsubmit="return confirm(' . h(json_encode('Usunąć: ' . $what . '?')) . ')">'
         . csrf_field() . '<input type="hidden" name="_op" value="' . h($op) . '"><input type="hidden" name="id" value="' . $id . '">'
         . '<button class="btn btn-sm btn-outline-danger" title="Usuń"><i class="bi bi-trash"></i><span class="visually-hidden">Usuń</span></button></form>';
};
$edit = fn(string $type, int $id = 0) => APP_URL . '/modules/it_portal/edit.php?type=' . $type . ($id ? '&id=' . $id : '');
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-life-preserver text-primary"></i> Portal Usług IT</h4>
  <a class="btn btn-sm btn-outline-primary" href="<?= h(itPortalUrl()) ?>" target="_blank" rel="noopener">
    <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz portal <span class="text-muted small"><?= h(preg_replace('#^https?://#', '', itPortalUrl())) ?></span>
  </a>
</div>
<?= flash_html() ?>

<ul class="nav nav-tabs mb-3">
  <?php foreach ($tabs as $k => $label): ?>
    <li class="nav-item"><a class="nav-link<?= $k === $tab ? ' active' : '' ?>" href="<?= h($self . '?tab=' . $k) ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?>><?= h($label) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'komunikaty'): $rows = itPortalNotices(0, false); ?>
  <div class="d-flex justify-content-between align-items-center mb-2">
    <p class="text-muted small mb-0">Trzy najnowsze opublikowane widać na stronie Start; wszystkie — w zakładce Komunikaty portalu.</p>
    <a class="btn btn-sm btn-primary" href="<?= h($edit('notice')) ?>"><i class="bi bi-plus-lg me-1"></i>Nowy komunikat</a>
  </div>
  <div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th>Data</th><th>Status</th><th>Tytuł</th><th>Podpis</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $st = ITP_NOTICE_STATUSES[$r['status']] ?? ITP_NOTICE_STATUSES['info']; ?>
      <tr class="<?= $r['is_published'] ? '' : 'text-muted' ?>">
        <td class="text-nowrap"><?= h($r['notice_date']) ?></td>
        <td class="text-nowrap"><span class="d-inline-block rounded-circle me-1" style="width:10px;height:10px;background:<?= h($st['color']) ?>"></span><?= h($st['label']) ?></td>
        <td><a href="<?= h($edit('notice', (int)$r['id'])) ?>"><?= h($r['title']) ?></a><?= $r['is_published'] ? '' : ' <span class="badge bg-secondary">szkic</span>' ?></td>
        <td class="small"><?= h($r['signature']) ?></td>
        <td class="text-end text-nowrap"><?= $del('delete_notice', (int)$r['id'], $r['title']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="text-muted">Brak komunikatów.</td></tr><?php endif; ?>
    </tbody>
  </table></div>

<?php elseif ($tab === 'uslugi'): $rows = itPortalCategories(false); ?>
  <div class="d-flex justify-content-between align-items-center mb-2">
    <p class="text-muted small mb-0">Każda usługa to kafel na stronie „Usługi”. Usługa z linkiem zewnętrznym prowadzi od razu poza portal.</p>
    <a class="btn btn-sm btn-primary" href="<?= h($edit('category')) ?>"><i class="bi bi-plus-lg me-1"></i>Nowa usługa</a>
  </div>
  <div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th style="width:48px"></th><th>Usługa</th><th>Adres</th><th class="text-end">Artykuły</th><th class="text-end">Kolejność</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>">
        <td class="text-primary"><span style="display:inline-block;width:32px;height:32px"><?= itPortalIcon($r['icon']) ?></span></td>
        <td><a href="<?= h($edit('category', (int)$r['id'])) ?>"><?= h($r['name']) ?></a><?= $r['is_active'] ? '' : ' <span class="badge bg-secondary">ukryta</span>' ?>
          <?php if ($r['external_url'] !== ''): ?><div class="small text-muted"><i class="bi bi-box-arrow-up-right"></i> <?= h($r['external_url']) ?></div><?php endif; ?></td>
        <td class="small"><a href="<?= h(itPortalServiceUrl($r)) ?>" target="_blank" rel="noopener">/<?= h($r['slug']) ?></a></td>
        <td class="text-end"><a href="<?= h($self . '?tab=artykuly&k=' . (int)$r['id']) ?>"><?= (int)$r['n_articles'] ?></a></td>
        <td class="text-end"><?= (int)$r['sort_order'] ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-outline-secondary" href="<?= h($edit('article') . '&k=' . (int)$r['id']) ?>" title="Dodaj artykuł do tej usługi"><i class="bi bi-file-earmark-plus"></i></a>
          <?= $del('delete_category', (int)$r['id'], $r['name'] . ' (z artykułami)') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-muted">Brak usług.</td></tr><?php endif; ?>
    </tbody>
  </table></div>

<?php elseif ($tab === 'artykuly'):
    $filter = (int)($_GET['k'] ?? 0);
    $rows = array_values(array_filter(itPortalAllArticles(), fn($a) => !$filter || (int)$a['category_id'] === $filter));
    $cats = itPortalCategories(false); ?>
  <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <form method="get" class="d-flex gap-2 align-items-center">
      <input type="hidden" name="tab" value="artykuly">
      <label class="small text-muted" for="f-k">Usługa</label>
      <select id="f-k" name="k" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="0">— wszystkie —</option>
        <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $filter === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
      </select>
    </form>
    <a class="btn btn-sm btn-primary" href="<?= h($edit('article') . ($filter ? '&k=' . $filter : '')) ?>"><i class="bi bi-plus-lg me-1"></i>Nowy artykuł</a>
  </div>
  <div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th>Usługa</th><th>Tytuł</th><th class="text-end">Wyświetlenia</th><th class="text-end">Kolejność</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['is_published'] ? '' : 'text-muted' ?>">
        <td class="small"><?= h($r['category_name']) ?></td>
        <td><a href="<?= h($edit('article', (int)$r['id'])) ?>"><?= h($r['title']) ?></a>
          <?= $r['is_pinned'] ? ' <span class="badge text-bg-info" title="Na liście „Najczęściej wyszukiwane”">przypięty</span>' : '' ?>
          <?= $r['is_published'] ? '' : ' <span class="badge bg-secondary">szkic</span>' ?></td>
        <td class="text-end"><?= (int)$r['views'] ?></td>
        <td class="text-end"><?= (int)$r['sort_order'] ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-outline-secondary" href="<?= h(itPortalUrl('uslugi/' . rawurlencode($r['category_slug']) . '/' . rawurlencode($r['slug']))) ?>" target="_blank" rel="noopener" title="Podgląd"><i class="bi bi-eye"></i></a>
          <?= $del('delete_article', (int)$r['id'], $r['title']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="text-muted">Brak artykułów.</td></tr><?php endif; ?>
    </tbody>
  </table></div>

<?php elseif ($tab === 'kontakt'): $rows = itPortalContacts(); ?>
  <div class="d-flex justify-content-between align-items-center mb-2">
    <p class="text-muted small mb-0">Tabela na stronie „Kontakt”. Tekst nad tabelą (np. linki do Helpdesku) ustawisz w zakładce Ustawienia.</p>
    <a class="btn btn-sm btn-primary" href="<?= h($edit('contact')) ?>"><i class="bi bi-plus-lg me-1"></i>Nowy wiersz</a>
  </div>
  <div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th>Jednostka</th><th>Kontakt</th><th>Zespół</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="<?= h($edit('contact', (int)$r['id'])) ?>"><?= h($r['unit']) ?></a></td>
        <td class="small"><?= h(itPortalExcerpt(itPortalPlain($r['contact_html']), 90)) ?></td>
        <td class="small"><?= count(itPortalLinkLines($r['team'])) ?> os.</td>
        <td class="text-end"><?= $del('delete_contact', (int)$r['id'], $r['unit']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="text-muted">Brak wierszy.</td></tr><?php endif; ?>
    </tbody>
  </table></div>

<?php else: $auds = itPortalAudiences(); $top = itPortalTopSearches(); ?>
  <div class="row g-4">
    <div class="col-lg-7">
      <form method="post" class="card card-body">
        <?= csrf_field() ?><input type="hidden" name="_op" value="settings">
        <h5 class="h6">Nagłówek</h5>
        <div class="row g-2 mb-3">
          <div class="col-md-6"><label class="form-label small" for="s-t">Tytuł</label><input id="s-t" class="form-control" name="title" value="<?= h(itPortalSetting('title')) ?>"></div>
          <div class="col-md-6"><label class="form-label small" for="s-s">Podtytuł</label><input id="s-s" class="form-control" name="subtitle" value="<?= h(org_setting('itp_subtitle')) ?>" placeholder="<?= h(ORG_NAME) ?>"></div>
          <div class="col-12"><label class="form-label small" for="s-l">Adres logo (puste = znak domyślny)</label><input id="s-l" class="form-control" name="logo_url" value="<?= h(org_setting('itp_logo_url')) ?>" placeholder="https://…/logo.svg"></div>
        </div>
        <h5 class="h6">Baner ostrzegawczy na stronie Start</h5>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="s-bo" name="banner_on" value="1"<?= itPortalSetting('banner_on') === '1' ? ' checked' : '' ?>><label class="form-check-label" for="s-bo">Pokazuj baner (czerwona ramka, np. „UWAGA PHISHING!”)</label></div>
        <textarea class="form-control itp-rich mb-3" name="banner_html" rows="4" aria-label="Treść banera"><?= h(itPortalSetting('banner_html')) ?></textarea>
        <h5 class="h6 mt-3">Tekst nad tabelą „Kontakt”</h5>
        <textarea class="form-control itp-rich mb-3" name="contact_intro" rows="5" aria-label="Tekst nad tabelą Kontakt"><?= h(itPortalSetting('contact_intro')) ?></textarea>
        <h5 class="h6 mt-3">Linki w stopce</h5>
        <label class="form-label small text-muted" for="s-f">Jeden w wierszu: <code>Etykieta | adres</code> (adres e-mail, telefon albo link). Sama etykieta = zwykły tekst.</label>
        <textarea id="s-f" class="form-control font-monospace small mb-3" name="footer_links" rows="4"><?= h(itPortalSetting('footer_links')) ?></textarea>
        <div><button class="btn btn-primary">Zapisz ustawienia</button></div>
      </form>
    </div>
    <div class="col-lg-5">
      <form method="post" class="card card-body mb-4">
        <?= csrf_field() ?><input type="hidden" name="_op" value="audiences">
        <h5 class="h6">Grupy „Dostępność usługi”</h5>
        <p class="small text-muted">Pokazywane przy każdej usłudze; w edycji usługi zaznaczasz, dla których jest dostępna.</p>
        <?php $icons = ['user' => 'Pracownik', 'volunteer' => 'Wolontariusz', 'student' => 'Kursant / uczeń', 'it' => 'IT']; ?>
        <?php foreach ($auds as $a): ?>
          <div class="row g-1 mb-1 align-items-center">
            <div class="col-5"><input class="form-control form-control-sm" name="aud[<?= (int)$a['id'] ?>][name]" value="<?= h($a['name']) ?>" aria-label="Nazwa grupy"></div>
            <div class="col-4"><select class="form-select form-select-sm" name="aud[<?= (int)$a['id'] ?>][icon]" aria-label="Ikona">
              <?php foreach ($icons as $k => $l): ?><option value="<?= $k ?>"<?= $a['icon'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="col-2"><input class="form-control form-control-sm" type="number" name="aud[<?= (int)$a['id'] ?>][sort_order]" value="<?= (int)$a['sort_order'] ?>" aria-label="Kolejność"></div>
            <div class="col-1 text-center"><input class="form-check-input" type="checkbox" name="aud[<?= (int)$a['id'] ?>][delete]" value="1" title="Usuń" aria-label="Usuń grupę <?= h($a['name']) ?>"></div>
          </div>
        <?php endforeach; ?>
        <div class="row g-1 mt-2 align-items-center">
          <div class="col-5"><input class="form-control form-control-sm" name="new[name]" placeholder="Nowa grupa…" aria-label="Nazwa nowej grupy"></div>
          <div class="col-4"><select class="form-select form-select-sm" name="new[icon]" aria-label="Ikona nowej grupy"><?php foreach ($icons as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-2"><input class="form-control form-control-sm" type="number" name="new[sort_order]" value="<?= count($auds) + 1 ?>" aria-label="Kolejność"></div>
        </div>
        <div class="mt-3"><button class="btn btn-sm btn-primary">Zapisz grupy</button> <span class="small text-muted ms-2">☐ po prawej = usuń</span></div>
      </form>
      <div class="card card-body">
        <h5 class="h6">Najczęstsze wyszukiwania (30 dni)</h5>
        <?php if (!$top): ?><p class="small text-muted mb-0">Brak danych.</p><?php else: ?>
          <table class="table table-sm small mb-0"><tbody>
            <?php foreach ($top as $t): ?><tr><td><?= h($t['q']) ?><?= (int)$t['min_hits'] === 0 ? ' <span class="badge text-bg-warning" title="Przynajmniej raz bez wyników — warto dopisać artykuł albo słowa kluczowe">0 wyników</span>' : '' ?></td><td class="text-end"><?= (int)$t['n'] ?></td></tr><?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php include __DIR__ . '/_editor.php'; ?>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
