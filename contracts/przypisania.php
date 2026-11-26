<?php
declare(strict_types=1);

/**
 * contracts/przypisania.php — przypisanie aktywnych umów zlecenia i wolontariackich (ContractLinks::TYPES): kontakt (konto SZO), jednostka, obszar Zadań.
 * Tylko administrator. Przypomnienie (pasek) w Rejestrze Umów i Strukturze prowadzi tutaj.
 * Akcje (POST + CSRF): save (wiersz), auto (pewne dopasowania po e-mailu/M365), ai (propozycja podziału),
 * ai_apply (zatwierdzone przypisania z propozycji). Logika: modules/contract_links/lib/ContractLinks.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/contract_links/lib/ContractLinks.php';

require_login();
if (!is_admin()) { flash_set('error', 'Przypisania umów ustawia administrator.'); header('Location: ' . APP_URL . '/contracts/rejestr.php'); exit; }
ContractLinks::migrate();
$uid = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    if ($op === 'save') {
        $type = (string)($_POST['contract_type'] ?? '');
        $id = (int)($_POST['contract_id'] ?? 0);
        if (ContractLinks::allows($type) && $id > 0) {
            ContractLinks::save($type, $id, [
                'user_id' => (int)($_POST['user_id'] ?? 0), 'unit_id' => (int)($_POST['unit_id'] ?? 0), 'workspace_id' => (int)($_POST['workspace_id'] ?? 0),
            ], 'manual', $uid);
            flash_set('success', 'Zapisano przypisanie umowy.');
        }
    } elseif ($op === 'auto') {
        $r = ContractLinks::autoMatch($uid);
        flash_set($r['contracts'] ? 'success' : 'info', $r['contracts']
            ? "Uzupełniono {$r['fields']} pól w {$r['contracts']} umowach (tylko pewne dopasowania)."
            : 'Nie znalazłem pewnych dopasowań — uzupełnij ręcznie albo poproś AI o propozycję.');
    } elseif ($op === 'ai') {
        $r = ContractLinks::aiProposal($uid);
        flash_set(isset($r['error']) ? 'danger' : 'success', $r['error'] ?? 'AI przygotowało propozycję podziału — przejrzyj ją niżej.');
        header('Location: ' . APP_URL . '/contracts/przypisania.php#ai'); exit;
    } elseif ($op === 'ai_apply') {
        $n = ContractLinks::applyProposal((int)($_POST['proposal_id'] ?? 0), array_map('strval', (array)($_POST['keys'] ?? [])), $uid);
        flash_set($n ? 'success' : 'info', $n ? "Zastosowano propozycję AI w {$n} umowach." : 'Nie zaznaczono przypisań do zastosowania.');
    }
    $ret = (string)($_POST['return'] ?? '');
    if ($ret !== '' && preg_match('#^/[A-Za-z0-9_/.\-]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$#', $ret)) { header('Location: ' . $ret . '#contract-links-panel'); exit; }
    header('Location: ' . APP_URL . '/contracts/przypisania.php' . (!empty($_POST['back']) ? '#' . preg_replace('/[^a-z0-9_-]/', '', (string)$_POST['back']) : '')); exit;
}

$all = ContractLinks::activeContracts();
$showAll = !empty($_GET['all']);
$rows = $showAll ? $all : array_values(array_filter($all, fn($c) => ContractLinks::missing($c)));
$missingN = count(array_filter($all, fn($c) => ContractLinks::missing($c)));
$users = db_all("SELECT id, name FROM users WHERE COALESCE(is_active,1)=1 ORDER BY name");
$units = org_units_all('active');
$wss = db_all("SELECT id, name FROM task_workspaces WHERE is_active=1 ORDER BY name");
$unitName = array_column($units, 'name', 'id');
$wsName = array_column($wss, 'name', 'id');
$prop = ContractLinks::lastProposal();
$contractByKey = [];
foreach ($all as $c) $contractByKey[$c['key']] = $c;

$PAGE_TITLE = 'Przypisanie umów';
include dirname(__DIR__) . '/includes/header.php';
?>
<style>
.cl-tbl td, .cl-tbl th { vertical-align: middle; }
.cl-tbl select { min-width: 10rem; min-height: 38px; }
.cl-miss { color: #9A3412; font-weight: 600; }
.cl-tbl :focus-visible, .cl-ai :focus-visible { outline: 3px solid #1559D6; outline-offset: 2px; }
</style>
<nav aria-label="Ścieżka" class="small mb-1"><a href="<?= APP_URL ?>/contracts/rejestr.php">Rejestr Umów</a> › <span aria-current="page">Przypisanie umów</span></nav>
<div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
  <div>
    <h1 class="h4 fw-bold mb-1"><i class="bi bi-diagram-2 text-primary me-2" aria-hidden="true"></i>Przypisanie aktywnych umów</h1>
    <p class="text-muted mb-0" style="max-width:52rem">Każda aktywna <strong>umowa zlecenia i wolontariacka</strong> powinna mieć <strong>kontakt</strong> (konto osoby w SZO), <strong>jednostkę</strong> ze Struktury i <strong>obszar Zadań</strong>. Dzięki temu osoba trafia do właściwych grup w chmurze, zespołów i list. Pozostałych rodzajów umów nie przypisujemy do jednostek ani zespołów.</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="op" value="auto">
      <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-magic me-1" aria-hidden="true"></i>Przypisz pewne dopasowania</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="op" value="ai">
      <button class="btn btn-outline-primary btn-sm" type="submit" onclick="this.disabled=true;this.form.submit();this.innerHTML='Proszę czekać…'"><i class="bi bi-stars me-1" aria-hidden="true"></i>Zaproponuj podział (AI)</button></form>
  </div>
</div>
<?= flash_html() ?>

<div class="alert <?= $missingN ? 'alert-warning' : 'alert-success' ?> py-2" role="status">
  <?php if ($missingN): ?>
  <strong><?= $missingN ?></strong> z <?= count($all) ?> aktywnych umów zlecenia i wolontariackich ma braki w przypisaniu.
  <?php else: ?>
  Wszystkie aktywne umowy zlecenia i wolontariackie (<?= count($all) ?>) mają kontakt, jednostkę i obszar.
  <?php endif; ?>
  <a class="ms-2" href="?<?= $showAll ? '' : 'all=1' ?>"><?= $showAll ? 'Pokaż tylko z brakami' : 'Pokaż wszystkie aktywne' ?></a>
</div>

<?php if ($rows): ?>
<div class="table-responsive bg-white border rounded-3 mb-4">
  <table class="table mb-0 cl-tbl">
    <caption class="visually-hidden">Aktywne umowy<?= $showAll ? '' : ' z brakami w przypisaniu' ?></caption>
    <thead class="table-light small"><tr><th scope="col">Umowa</th><th scope="col">Kontakt (konto SZO)</th><th scope="col">Jednostka</th><th scope="col">Obszar Zadań</th><th scope="col"><span class="visually-hidden">Zapisz</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $c): $s = ContractLinks::suggest($c); $fid = 'cl-f-' . $i; $miss = ContractLinks::missing($c); ?>
      <tr>
        <th scope="row" class="fw-normal">
          <a href="<?= h($c['url']) ?>" class="fw-semibold"><?= h($c['nr_rejestru'] ?: ($c['numer_umowy'] ?: '#' . $c['id'])) ?></a>
          <div class="small text-muted"><?= h($c['contract_type_label']) ?> · <?= h($c['strona']) ?></div>
          <?php if ($miss): ?><div class="small cl-miss"><span class="visually-hidden">Braki: </span>brak: <?= h(implode(', ', $miss)) ?></div><?php endif; ?>
        </th>
        <td>
          <label class="visually-hidden" for="<?= $fid ?>-u">Kontakt dla umowy <?= h($c['nr_rejestru'] ?: $c['numer_umowy']) ?></label>
          <select id="<?= $fid ?>-u" name="user_id" form="<?= $fid ?>" class="form-select form-select-sm">
            <option value="0">— brak —</option>
            <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === (int)$s['user_id'] ? ' selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
          </select>
          <?php if (!$c['user_id'] && $s['user_id']): ?><div class="small text-success">podpowiedź po e-mailu</div><?php elseif (!$c['user_id'] && !$s['user_candidates']): ?><div class="small text-muted">brak konta o e-mailu z umowy</div><?php endif; ?>
        </td>
        <td>
          <label class="visually-hidden" for="<?= $fid ?>-j">Jednostka</label>
          <select id="<?= $fid ?>-j" name="unit_id" form="<?= $fid ?>" class="form-select form-select-sm">
            <option value="0">— brak —</option>
            <?php foreach ($units as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === (int)$s['unit_id'] ? ' selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
          </select>
        </td>
        <td>
          <label class="visually-hidden" for="<?= $fid ?>-o">Obszar Zadań</label>
          <select id="<?= $fid ?>-o" name="workspace_id" form="<?= $fid ?>" class="form-select form-select-sm">
            <option value="0">— brak —</option>
            <?php foreach ($wss as $w): ?><option value="<?= (int)$w['id'] ?>"<?= (int)$w['id'] === (int)$s['workspace_id'] ? ' selected' : '' ?>><?= h($w['name']) ?></option><?php endforeach; ?>
          </select>
        </td>
        <td class="text-end">
          <form id="<?= $fid ?>" method="post"><?= csrf_field() ?><input type="hidden" name="op" value="save">
            <input type="hidden" name="contract_type" value="<?= h($c['contract_type']) ?>"><input type="hidden" name="contract_id" value="<?= (int)$c['id'] ?>">
            <button type="submit" class="btn btn-outline-primary btn-sm">Zapisz</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<section id="ai" class="cl-ai bg-white border rounded-3 p-3" aria-labelledby="ai-h">
  <h2 id="ai-h" class="h5 fw-bold"><i class="bi bi-stars text-primary me-1" aria-hidden="true"></i>Propozycja podziału (AI)</h2>
  <p class="small text-muted">AI dostaje tylko strukturę (jednostki, obszary, zespoły) i opis umów: rodzaj, zakres, projekt — <strong>bez imion, nazwisk i e-maili</strong>. Zatwierdzasz wybrane przypisania; nowe jednostki i obszary zakładasz sam.</p>
  <?php if (!$prop): ?>
  <p class="mb-0">Brak propozycji. Użyj przycisku „Zaproponuj podział (AI)”.</p>
  <?php else: $p = $prop['proposal']; ?>
  <p class="small">Propozycja z <?= h(date('d.m.Y H:i', strtotime($prop['created_at']))) ?><?= $prop['applied_at'] ? ' · zastosowana ' . h(date('d.m.Y H:i', strtotime($prop['applied_at']))) : '' ?></p>
  <?php if (!empty($p['podsumowanie'])): ?><p class="border-start border-3 border-primary ps-3"><?= nl2br(h($p['podsumowanie'])) ?></p><?php endif; ?>
  <?php if (!empty($p['nowe_jednostki']) || !empty($p['nowe_obszary'])): ?>
  <h3 class="h6 fw-bold mt-3">Proponowane nowe jednostki i obszary</h3>
  <ul>
    <?php foreach ((array)($p['nowe_jednostki'] ?? []) as $n): ?><li><strong>Jednostka: <?= h($n['nazwa'] ?? '') ?></strong><?= !empty($n['nadrzedna_id']) ? ' (w: ' . h($unitName[(int)$n['nadrzedna_id']] ?? '') . ')' : '' ?> — <?= h($n['uzasadnienie'] ?? '') ?> <a href="<?= APP_URL ?>/org/units/add.php?level=dzial">Utwórz</a></li><?php endforeach; ?>
    <?php foreach ((array)($p['nowe_obszary'] ?? []) as $n): ?><li><strong>Obszar: <?= h($n['nazwa'] ?? '') ?></strong> — <?= h($n['uzasadnienie'] ?? '') ?> <a href="<?= APP_URL ?>/tasks/settings/workspaces.php">Utwórz</a></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if (!empty($p['przypisania'])): ?>
  <form method="post" class="mt-3"><?= csrf_field() ?><input type="hidden" name="op" value="ai_apply"><input type="hidden" name="back" value="ai">
    <input type="hidden" name="proposal_id" value="<?= (int)$prop['id'] ?>">
    <fieldset>
      <legend class="h6 fw-bold">Proponowane przypisania umów</legend>
      <div class="table-responsive"><table class="table table-sm align-middle">
        <thead class="table-light small"><tr><th scope="col"><span class="visually-hidden">Zastosuj</span></th><th scope="col">Umowa</th><th scope="col">Jednostka</th><th scope="col">Obszar</th><th scope="col">Dlaczego</th></tr></thead>
        <tbody>
        <?php foreach ($p['przypisania'] as $i => $a): $k = (string)($a['klucz'] ?? ''); $c = $contractByKey[$k] ?? null; if (!$c) continue;
              $uOk = !empty($a['jednostka_id']) && isset($unitName[(int)$a['jednostka_id']]); $wOk = !empty($a['obszar_id']) && isset($wsName[(int)$a['obszar_id']]); ?>
          <tr>
            <td><input class="form-check-input" type="checkbox" name="keys[]" value="<?= h($k) ?>" id="ai-k-<?= $i ?>"<?= ($uOk || $wOk) ? ' checked' : ' disabled' ?>></td>
            <td><label for="ai-k-<?= $i ?>"><?= h($c['nr_rejestru'] ?: $c['numer_umowy']) ?> · <?= h($c['contract_type_label']) ?></label></td>
            <td><?= $uOk ? h($unitName[(int)$a['jednostka_id']]) : (!empty($a['nowa_jednostka']) ? '<em>nowa: ' . h($a['nowa_jednostka']) . '</em>' : '—') ?></td>
            <td><?= $wOk ? h($wsName[(int)$a['obszar_id']]) : (!empty($a['nowy_obszar']) ? '<em>nowy: ' . h($a['nowy_obszar']) . '</em>' : '—') ?></td>
            <td class="small"><?= h($a['uzasadnienie'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </fieldset>
    <button type="submit" class="btn btn-primary btn-sm">Zastosuj zaznaczone</button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
