<?php
/**
 * crm/settings/categories.php — kategorie kontaktów CRM (podział „kim jest dla organizacji”), zob. includes/crmCategories.php.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crmCategories.php';

require_login();
require_module_enabled('crm_enabled', 'CRM');
if (!can_write('crm_ustawienia') && !is_admin()) { flash_set('danger', 'Brak uprawnień do ustawień CRM.'); header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }
crm_migrate();
crm_require('settings', 'write');
crm_categories_schema();
$PAGE_TITLE = 'CRM — Kategorie kontaktów';

$rule_labels = ['vol_active' => 'aktywna umowa wolontariacka', 'vol_former' => 'wyłącznie zakończone umowy wolontariackie',
    'zlec_active' => 'aktywna umowa zlecenie lub o dzieło', 'praca_active' => 'aktywna umowa o pracę', 'donor' => 'ma darowiznę',
    'client' => 'ma fakturę', 'partner' => 'status kontaktu „partner”', 'beneficiary' => 'powiązany jako beneficjent'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'recalc') {
        $r = crm_categories_recalc();
        flash_set('success', 'Przeliczono kategorie: dodano ' . array_sum(array_column($r, 'added')) . ', zdjęto ' . array_sum(array_column($r, 'removed')) . '.');
    } elseif ($op === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $label = trim((string)($_POST['label'] ?? ''));
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['color'] ?? '')) ? $_POST['color'] : '#6B7280';
        if ($label === '') flash_set('danger', 'Podaj nazwę kategorii.');
        elseif ($id) {
            db()->prepare("UPDATE crm_categories SET label=?, color=?, sort_order=?, is_active=? WHERE id=?")
                ->execute([$label, $color, (int)($_POST['sort_order'] ?? 0), !empty($_POST['is_active']) ? 1 : 0, $id]);
            flash_set('success', 'Zapisano kategorię.');
        } else {
            $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtr(mb_strtolower($label), ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z'])), '_') ?: 'kat';
            if (db_one("SELECT 1 FROM crm_categories WHERE slug=?", [$slug])) $slug .= '_' . substr(bin2hex(random_bytes(2)), 0, 3);
            $max = (int)(db_one("SELECT COALESCE(MAX(sort_order),0) AS n FROM crm_categories")['n'] ?? 0);
            db()->prepare("INSERT INTO crm_categories (slug,label,color,sort_order,is_active,auto_rule) VALUES (?,?,?,?,1,NULL)")->execute([$slug, $label, $color, $max + 1]);
            flash_set('success', 'Dodano kategorię (nadawana ręcznie).');
        }
    }
    header('Location: ' . APP_URL . '/crm/settings/categories.php'); exit;
}
$cats = crm_categories(false);
include dirname(__DIR__) . '/includes/header_crm.php';
include __DIR__ . '/_nav.php';
?>
<div class="crm-page-header mb-3">
  <div>
    <h1 class="crm-page-title"><i class="bi bi-diagram-2" style="color:var(--crm-primary)" aria-hidden="true"></i> Kategorie kontaktów</h1>
    <div class="crm-page-subtitle">Podział „kim jest dla organizacji” — niezależny od statusu relacji. Kontakt może mieć kilka kategorii.</div>
  </div>
  <div class="crm-page-actions">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="_op" value="recalc">
      <button class="btn btn-crm-outline btn-sm"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Przelicz teraz</button></form>
  </div>
</div>
<p class="small" style="color:#374151;max-width:60rem">Kategorie z regułą są nadawane i zdejmowane automatycznie (co 6 godzin oraz po kliknięciu „Przelicz teraz”) na podstawie umów, darowizn, faktur i powiązań.
Kategorie ręczne nadajesz w kartotece kontaktu — przeliczenie ich nie rusza. Po kategoriach filtrujesz listę kontaktów i wybierasz adresatów w Wysyłce masowej.</p>
<table class="table table-sm align-middle" style="border:1px solid #D1D5DB;background:#fff">
  <thead><tr style="background:#F3F4F6"><th>Kategoria</th><th>Reguła</th><th class="text-end">Kontaktów</th><th>Kolor</th><th>Kolejność</th><th>Aktywna</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($cats as $c): $fid = 'cf' . (int)$c['id']; ?>
  <tr>
    <td><form method="post" id="<?= $fid ?>"><?= csrf_field() ?><input type="hidden" name="_op" value="save"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"></form>
        <input form="<?= $fid ?>" name="label" class="form-control form-control-sm" style="border-radius:0" value="<?= h($c['label']) ?>" aria-label="Nazwa kategorii" maxlength="60"></td>
    <td class="small" style="color:#374151"><?= $c['auto_rule'] ? h($rule_labels[$c['auto_rule']] ?? $c['auto_rule']) : 'ręczna' ?></td>
    <td class="text-end"><?= (int)$c['cnt'] ?></td>
    <td><input form="<?= $fid ?>" type="color" name="color" value="<?= h($c['color']) ?>" aria-label="Kolor kategorii <?= h($c['label']) ?>"></td>
    <td><input form="<?= $fid ?>" type="number" name="sort_order" class="form-control form-control-sm" style="width:5rem;border-radius:0" value="<?= (int)$c['sort_order'] ?>" aria-label="Kolejność"></td>
    <td><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1"<?= $c['is_active'] ? ' checked' : '' ?> aria-label="Aktywna"></td>
    <td><button form="<?= $fid ?>" class="btn btn-sm btn-crm-outline" style="border-radius:0">Zapisz</button></td>
  </tr>
  <?php endforeach; ?>
  <tr style="background:#F9FAFB">
    <td colspan="2"><form method="post" id="cfnew"><?= csrf_field() ?><input type="hidden" name="_op" value="save"><input type="hidden" name="id" value="0"></form>
        <input form="cfnew" name="label" class="form-control form-control-sm" style="border-radius:0" placeholder="Nowa kategoria (nadawana ręcznie)" aria-label="Nowa kategoria" maxlength="60"></td>
    <td></td><td><input form="cfnew" type="color" name="color" value="#6B7280" aria-label="Kolor nowej kategorii"></td><td></td><td></td>
    <td><button form="cfnew" class="btn btn-sm btn-crm-primary" style="border-radius:0">Dodaj</button></td>
  </tr>
  </tbody>
</table>
<?php include __DIR__ . '/_nav_end.php'; include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
