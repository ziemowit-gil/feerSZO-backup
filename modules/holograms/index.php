<?php
/**
 * modules/holograms/index.php — Ewidencja hologramów: podsumowanie, dodanie serii,
 * wydanie, lista z filtrami i zbiorczą zmianą statusu (zwrot / uszkodzenie).
 * Logika: modules/holograms/logic/holograms.php.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/holograms.php';

require_role('admin', 'editor');
require_module_enabled('holograms_enabled', 'Moduł Hologramy');

$user = current_user();
$svc  = new HologramService($user);
$BASE = APP_URL . '/modules/holograms/index.php';

// Filtry listy (GET) — zachowywane po akcjach POST
$f_status = isset(HOLO_STATUSES[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$f_q      = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 64);
$f_batch  = mb_substr(trim((string)($_GET['batch'] ?? '')), 0, 100);
$page     = max(1, (int)($_GET['page'] ?? 1));
$filterQs = http_build_query(array_filter(['status' => $f_status, 'q' => $f_q, 'batch' => $f_batch], 'strlen'));

$errors = ['series' => null, 'issue' => null, 'bulk' => null];
$old    = [];
$openPanel = '';

// Wydanie w ramach umowy (link z karty „Hologramy” na widoku umowy)
$issueFor = holo_contract_info((string)($_REQUEST['issue_type'] ?? ''), (int)($_REQUEST['issue_id'] ?? 0));
if ($issueFor) $openPanel = 'issue';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['_action'] ?? '');
    $old = $_POST;
    try {
        if ($action === 'series') {
            $openPanel = 'series';
            $pad = trim((string)($_POST['pad'] ?? ''));
            $numbers = HologramService::buildRange(
                (string)($_POST['prefix'] ?? ''), (string)($_POST['from'] ?? ''), (string)($_POST['to'] ?? ''),
                $pad === '' ? null : (int)$pad
            );
            $res = $svc->addSeries($numbers, (string)($_POST['batch_number'] ?? ''), (string)($_POST['notes'] ?? ''), !empty($_POST['skip_duplicates']));
            $msg = 'Dodano ' . $res['added'] . ' hologramów (' . $numbers[0] . ' – ' . end($numbers) . ').';
            if ($res['skipped']) $msg .= ' Pominięto istniejące: ' . count($res['skipped']) . '.';
            flash_set($res['added'] ? 'success' : 'warning', $msg);
        } elseif ($action === 'issue') {
            $openPanel = 'issue';
            $numbers = ($_POST['issue_mode'] ?? 'range') === 'list'
                ? HologramService::parseList((string)($_POST['numbers'] ?? ''))
                : HologramService::buildRange((string)($_POST['prefix'] ?? ''), (string)($_POST['from'] ?? ''), (string)($_POST['to'] ?? ''));
            $n = $svc->issue($numbers, (string)($_POST['assigned_to'] ?? ''), (string)($_POST['issued_by'] ?? ''), (string)($_POST['notes'] ?? ''),
                             $issueFor ? ['type' => $issueFor['type'], 'id' => $issueFor['id']] : null);
            flash_set('success', 'Wydano ' . $n . ' hologramów: ' . trim((string)$_POST['assigned_to']) . '.');
            if ($issueFor) { header('Location: ' . $issueFor['url'] . '#hologramy'); exit; }
        } elseif ($action === 'bulk') {
            $to = (string)($_POST['to_status'] ?? '');
            $n  = $svc->changeStatus((array)($_POST['ids'] ?? []), $to, (string)($_POST['note'] ?? ''));
            flash_set('success', 'Zmieniono status ' . $n . ' hologramów na „' . mb_strtolower(HOLO_STATUSES[$to]['label']) . '”.');
        } else {
            throw new HologramException('Nieznana operacja.');
        }
        header('Location: ' . $BASE . ($filterQs ? '?' . $filterQs : '')); exit;
    } catch (HologramException $e) {
        $key = in_array($action, ['series', 'issue', 'bulk'], true) ? $action : 'bulk';
        $errors[$key] = $e->getMessage();
    } catch (\Throwable $e) {
        error_log('[holograms] ' . $e->getMessage());
        $key = in_array($action, ['series', 'issue', 'bulk'], true) ? $action : 'bulk';
        $errors[$key] = 'Operacja nie powiodła się — nic nie zostało zapisane. Szczegóły w logu serwera.';
    }
}

$PER_PAGE = 50;
$stats   = $svc->stats();
$batches = $svc->batches();
$total   = $svc->search($f_status, $f_q, $f_batch, 1, 0)['total'];
$pages   = max(1, (int)ceil($total / $PER_PAGE));
$page    = min($page, $pages);
$list    = $svc->search($f_status, $f_q, $f_batch, $PER_PAGE, ($page - 1) * $PER_PAGE)['rows'];

// Wartości po błędzie walidacji wracają tylko do formularza, który został wysłany
$oldFor = fn(string $form) => ($old['_action'] ?? '') === $form ? $old : [];
$oS = $oldFor('series'); $oI = $oldFor('issue'); $oB = $oldFor('bulk');
$pageUrl = fn(int $p) => $BASE . '?' . http_build_query(array_filter(['status' => $f_status, 'q' => $f_q, 'batch' => $f_batch, 'page' => $p > 1 ? $p : ''], 'strlen'));

$statusUrl = fn(string $st) => $BASE . (($qs = http_build_query(array_filter(['status' => $st, 'q' => $f_q, 'batch' => $f_batch], 'strlen'))) ? '?' . $qs : '');
$pct = fn(int $n, int $of) => $of > 0 ? round($n * 100 / $of, 1) : 0;
$postUrl = $BASE . ($filterQs ? '?' . $filterQs : '');
$contractCache = [];
$contractOf = function (array $r) use (&$contractCache) {
    $k = $r['contract_type'] . ':' . (int)$r['contract_id'];
    return $contractCache[$k] ??= holo_contract_info((string)$r['contract_type'], (int)$r['contract_id']);
};

$PAGE_TITLE = 'Hologramy';
include dirname(__DIR__, 2) . '/includes/header.php';
include __DIR__ . '/partials/ui.php';
?>

<div class="holo" x-data="{ panel: <?= h(json_encode($openPanel)) ?> }">

<header class="dash-h">
  <div>
    <h1><span class="dash-h__ico" aria-hidden="true"><i class="bi bi-patch-check"></i></span> Hologramy</h1>
    <p>Ewidencja naklejek zabezpieczających na dokumenty i sprzęt — serie, wydania, zwroty i uszkodzenia.</p>
  </div>
  <div class="tw-flex tw-flex-wrap tw-gap-2">
    <button type="button" class="h-btn h-btn--ghost" @click="panel = panel === 'issue' ? '' : 'issue'"
            :aria-expanded="(panel === 'issue').toString()" aria-controls="panel-issue">
      <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Wydaj hologramy
    </button>
    <button type="button" class="h-btn h-btn--primary" @click="panel = panel === 'series' ? '' : 'series'"
            :aria-expanded="(panel === 'series').toString()" aria-controls="panel-series">
      <i class="bi bi-plus-lg" aria-hidden="true"></i> Dodaj serię
    </button>
  </div>
</header>

<?= flash_html() ?>

<!-- ── Podsumowanie ───────────────────────────────────────────────────────── -->
<section aria-label="Podsumowanie stanów" class="tw-mb-5">
  <div class="kpi-grid">
    <a class="kpi-tile is-all" href="<?= h($statusUrl('')) ?>"<?= $f_status === '' ? ' aria-current="true"' : '' ?>>
      <span class="kpi-tile__ico" aria-hidden="true"><i class="bi bi-collection"></i></span>
      <span class="kpi-tile__val"><?= $stats['total'] ?></span>
      <span class="kpi-tile__lbl">Wszystkie w ewidencji</span>
    </a>
    <?php foreach (HOLO_STATUSES as $k => $s): ?>
    <a class="kpi-tile is-<?= h($k) ?>" href="<?= h($statusUrl($k)) ?>"<?= $f_status === $k ? ' aria-current="true"' : '' ?>>
      <span class="kpi-tile__ico" aria-hidden="true"><i class="bi <?= h($s['icon']) ?>"></i></span>
      <span class="kpi-tile__val"><?= $stats[$k] ?></span>
      <span class="kpi-tile__lbl"><?= h($s['plural']) ?><?php if ($stats['total']): ?> · <?= $pct($stats[$k], $stats['total']) ?>%<?php endif; ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php if ($stats['total']): ?>
  <div class="pool-bar" aria-hidden="true" title="Struktura puli">
    <?php foreach (array_keys(HOLO_STATUSES) as $k): if (!$stats[$k]) continue; ?>
    <span class="bg-<?= h($k) ?>" style="width: <?= $pct($stats[$k], $stats['total']) ?>%"></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<!-- ── Dodanie serii ──────────────────────────────────────────────────────── -->
<section id="panel-series" x-show="panel === 'series'" x-cloak x-transition.opacity aria-labelledby="h-series" class="tz-card tw-mb-5"
         x-data="{
           prefix: <?= h(json_encode((string)($oS['prefix'] ?? 'HOLO-' . date('Y') . '-'))) ?>,
           from: <?= h(json_encode((string)($oS['from'] ?? '001'))) ?>,
           to: <?= h(json_encode((string)($oS['to'] ?? '100'))) ?>,
           pad: <?= h(json_encode((string)($oS['pad'] ?? ''))) ?>,
           fmt(n) { const w = Math.max(this.pad ? +this.pad : this.from.length, String(+this.to).length); return this.prefix + String(n).padStart(w, '0'); },
           get valid() { return /^\d{1,12}$/.test(this.from) && /^\d{1,12}$/.test(this.to) && +this.to >= +this.from && this.count <= <?= HOLO_MAX_SERIES ?>; },
           get count() { return (/^\d+$/.test(this.from) && /^\d+$/.test(this.to) && +this.to >= +this.from) ? (+this.to - +this.from + 1) : 0; }
         }">
  <div class="tz-card__hd">
    <h2 id="h-series"><i class="bi bi-plus-square" aria-hidden="true"></i> Dodaj serię hologramów</h2>
    <button type="button" class="tz-card__close" @click="panel = ''" aria-label="Zamknij formularz dodawania serii"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
  </div>
  <div class="tz-card__bd">
    <?php if ($errors['series']): ?>
    <div role="alert" class="alert-err"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= h($errors['series']) ?></div></div>
    <?php endif; ?>
    <form method="post" action="<?= h($postUrl) ?>" class="tw-grid tw-grid-cols-1 md:tw-grid-cols-12 tw-gap-4">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="series">
      <div class="md:tw-col-span-4">
        <label for="s_prefix" class="f-label">Prefiks</label>
        <input id="s_prefix" name="prefix" x-model="prefix" class="f-input is-mono" maxlength="40" autocomplete="off" aria-describedby="s_prefix_help">
        <p id="s_prefix_help" class="f-help">Litery, cyfry i znaki - / _ . — może być pusty.</p>
      </div>
      <div class="md:tw-col-span-3">
        <label for="s_from" class="f-label">Od numeru <span class="f-req" aria-hidden="true">*</span></label>
        <input id="s_from" name="from" x-model="from" required inputmode="numeric" pattern="\d{1,12}" class="f-input is-mono" aria-describedby="s_from_help">
        <p id="s_from_help" class="f-help">Zera wiodące ustalają długość, np. „001”.</p>
      </div>
      <div class="md:tw-col-span-3">
        <label for="s_to" class="f-label">Do numeru <span class="f-req" aria-hidden="true">*</span></label>
        <input id="s_to" name="to" x-model="to" required inputmode="numeric" pattern="\d{1,12}" class="f-input is-mono">
      </div>
      <div class="md:tw-col-span-2">
        <label for="s_pad" class="f-label">Liczba cyfr</label>
        <input id="s_pad" name="pad" x-model="pad" type="number" min="1" max="12" class="f-input" placeholder="auto">
      </div>
      <div class="md:tw-col-span-4">
        <label for="s_batch" class="f-label">Nr serii dostawy</label>
        <input id="s_batch" name="batch_number" value="<?= h($oS['batch_number'] ?? '') ?>" maxlength="100" class="f-input" placeholder="np. FV/123/2026">
      </div>
      <div class="md:tw-col-span-8">
        <label for="s_notes" class="f-label">Uwagi</label>
        <input id="s_notes" name="notes" value="<?= h($oS['notes'] ?? '') ?>" maxlength="2000" class="f-input" placeholder="np. dostawca, miejsce przechowywania">
      </div>

      <div class="md:tw-col-span-12 preview" :class="!valid && 'is-bad'" aria-live="polite">
        <template x-if="valid">
          <span class="tw-contents">
            <span class="count-pill" x-text="count"></span>
            <span>naklejek:</span>
            <span class="chip" x-text="fmt(+from)"></span>
            <template x-if="count > 2"><span class="chip" x-text="fmt(+from + 1)"></span></template>
            <template x-if="count > 3"><span aria-hidden="true">…</span></template>
            <template x-if="count > 1"><span class="chip" x-text="fmt(+to)"></span></template>
          </span>
        </template>
        <template x-if="!valid">
          <span><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <span x-text="count > <?= HOLO_MAX_SERIES ?> ? 'Za duża seria — maks. <?= HOLO_MAX_SERIES ?> naklejek na raz.' : 'Podaj poprawny zakres: numer końcowy nie mniejszy niż początkowy.'"></span></span>
        </template>
      </div>

      <div class="md:tw-col-span-12 tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
        <label class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-cursor-pointer" style="color: var(--tz-ink)">
          <input type="checkbox" name="skip_duplicates" value="1" class="h-check" <?= !empty($oS['skip_duplicates']) ? 'checked' : '' ?>>
          Pomiń numery, które już są w ewidencji
        </label>
        <div class="tw-flex tw-gap-2">
          <button type="button" class="h-btn h-btn--ghost" @click="panel = ''">Anuluj</button>
          <button type="submit" class="h-btn h-btn--primary" :disabled="!valid">
            <i class="bi bi-check-lg" aria-hidden="true"></i> <span x-text="valid ? 'Dodaj ' + count + ' naklejek' : 'Dodaj serię'">Dodaj serię</span>
          </button>
        </div>
      </div>
    </form>
  </div>
</section>

<!-- ── Wydanie ────────────────────────────────────────────────────────────── -->
<section id="panel-issue" x-show="panel === 'issue'" x-cloak x-transition.opacity aria-labelledby="h-issue" class="tz-card tw-mb-5"
         x-data="{ mode: <?= h(json_encode((string)($oI['issue_mode'] ?? 'range'))) ?> }">
  <div class="tz-card__hd">
    <h2 id="h-issue"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Wydaj hologramy</h2>
    <button type="button" class="tz-card__close" @click="panel = ''" aria-label="Zamknij formularz wydania"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
  </div>
  <div class="tz-card__bd">
    <?php if ($errors['issue']): ?>
    <div role="alert" class="alert-err"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= h($errors['issue']) ?></div></div>
    <?php endif; ?>
    <form method="post" action="<?= h($postUrl) ?>" class="tw-grid tw-grid-cols-1 md:tw-grid-cols-12 tw-gap-4">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="issue">
      <?php if ($issueFor): ?>
      <input type="hidden" name="issue_type" value="<?= h($issueFor['type']) ?>">
      <input type="hidden" name="issue_id" value="<?= (int)$issueFor['id'] ?>">
      <div class="md:tw-col-span-12 preview">
        <i class="bi bi-link-45deg" aria-hidden="true"></i>
        <span>Wydanie w ramach umowy <a href="<?= h($issueFor['url']) ?>" class="tw-font-semibold" style="color: var(--holo-accent)"><?= h($issueFor['number'] ?: '#' . $issueFor['id']) ?></a>
          <span style="color: var(--tz-muted)">· <?= h($issueFor['label']) ?><?= $issueFor['person'] ? ' · ' . h($issueFor['person']) : '' ?></span>.
          Naklejki trzeba będzie rozliczyć przed zakończeniem umowy.</span>
        <a href="<?= h($BASE) ?>" class="tw-ml-auto tw-text-sm" style="color: var(--tz-muted)">odłącz</a>
      </div>
      <?php endif; ?>
      <div class="md:tw-col-span-12">
        <fieldset class="tw-m-0 tw-p-0 tw-border-0">
          <legend class="f-label">Które naklejki</legend>
          <div class="f-seg">
            <label><input type="radio" name="issue_mode" value="range" x-model="mode"><i class="bi bi-arrows-expand-vertical" aria-hidden="true"></i> Zakres numerów</label>
            <label><input type="radio" name="issue_mode" value="list" x-model="mode"><i class="bi bi-list-ol" aria-hidden="true"></i> Wybrane numery</label>
          </div>
        </fieldset>
      </div>
      <template x-if="mode === 'range'">
        <div class="md:tw-col-span-12 tw-grid tw-grid-cols-1 md:tw-grid-cols-12 tw-gap-4">
          <div class="md:tw-col-span-6">
            <label for="i_prefix" class="f-label">Prefiks</label>
            <input id="i_prefix" name="prefix" value="<?= h($oI['prefix'] ?? 'HOLO-' . date('Y') . '-') ?>" maxlength="40" class="f-input is-mono" autocomplete="off">
          </div>
          <div class="md:tw-col-span-3">
            <label for="i_from" class="f-label">Od numeru <span class="f-req" aria-hidden="true">*</span></label>
            <input id="i_from" name="from" value="<?= h($oI['from'] ?? '') ?>" required inputmode="numeric" pattern="\d{1,12}" class="f-input is-mono" placeholder="001">
          </div>
          <div class="md:tw-col-span-3">
            <label for="i_to" class="f-label">Do numeru <span class="f-req" aria-hidden="true">*</span></label>
            <input id="i_to" name="to" value="<?= h($oI['to'] ?? '') ?>" required inputmode="numeric" pattern="\d{1,12}" class="f-input is-mono" placeholder="010">
          </div>
        </div>
      </template>
      <template x-if="mode === 'list'">
        <div class="md:tw-col-span-12">
          <label for="i_numbers" class="f-label">Numery hologramów <span class="f-req" aria-hidden="true">*</span></label>
          <textarea id="i_numbers" name="numbers" rows="2" required class="f-input is-mono" aria-describedby="i_numbers_help"
                    placeholder="HOLO-2026-015, HOLO-2026-020"><?= h($oI['numbers'] ?? '') ?></textarea>
          <p id="i_numbers_help" class="f-help">Oddziel przecinkiem, spacją lub nową linią.</p>
        </div>
      </template>
      <div class="md:tw-col-span-7">
        <label for="i_assigned" class="f-label">Przypisz do <span class="f-req" aria-hidden="true">*</span></label>
        <input id="i_assigned" name="assigned_to" value="<?= h($oI['assigned_to'] ?? ($issueFor ? trim($issueFor['person'] . ($issueFor['number'] ? ' · umowa ' . $issueFor['number'] : '')) : '')) ?>" required maxlength="255" class="f-input"
               aria-describedby="i_assigned_help" placeholder="np. Jan Kowalski · umowa ZL/12/2026 · laptop INW/0042">
        <p id="i_assigned_help" class="f-help">Osoba, dokument albo sprzęt (nr inwentarzowy), na który trafia naklejka.</p>
      </div>
      <div class="md:tw-col-span-5">
        <label for="i_by" class="f-label">Wydał(a)</label>
        <input id="i_by" name="issued_by" value="<?= h($oI['issued_by'] ?? ($user['name'] ?? '')) ?>" maxlength="255" class="f-input">
      </div>
      <div class="md:tw-col-span-12">
        <label for="i_notes" class="f-label">Uwagi</label>
        <input id="i_notes" name="notes" value="<?= h($oI['notes'] ?? '') ?>" maxlength="2000" class="f-input">
      </div>
      <div class="md:tw-col-span-12 tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
        <p class="f-help tw-m-0"><i class="bi bi-info-circle" aria-hidden="true"></i> Wydać można naklejki dostępne lub zwrócone. Jeśli choć jedna nie spełnia warunku, nic nie zostanie wydane.</p>
        <div class="tw-flex tw-gap-2">
          <button type="button" class="h-btn h-btn--ghost" @click="panel = ''">Anuluj</button>
          <button type="submit" class="h-btn h-btn--primary"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Wydaj</button>
        </div>
      </div>
    </form>
  </div>
</section>

<!-- ── Serie dostaw ───────────────────────────────────────────────────────── -->
<?php if ($batches): ?>
<details class="tz-card tw-mb-5 tw-group"<?= $f_batch !== '' ? ' open' : '' ?>>
  <summary class="tz-card__hd tw-cursor-pointer tw-list-none tw-border-b-0 group-open:tw-border-b">
    <i class="bi bi-boxes" aria-hidden="true" style="color: var(--holo-accent)"></i>
    Serie dostaw <span class="pill__n" style="color: var(--tz-muted)">(<?= count($batches) ?>)</span>
    <i class="bi bi-chevron-down tw-ml-auto tw-transition-transform group-open:tw-rotate-180" aria-hidden="true" style="color: var(--tz-muted)"></i>
  </summary>
  <div class="tw-overflow-x-auto">
    <table class="h-table">
      <thead>
        <tr><th scope="col">Seria</th><th scope="col">Zakres</th><th scope="col" class="!tw-text-right">Razem</th>
          <?php foreach (HOLO_STATUSES as $s): ?><th scope="col" class="!tw-text-right"><?= h($s['plural']) ?></th><?php endforeach; ?>
          <th scope="col">Wykorzystanie</th></tr>
      </thead>
      <tbody>
        <?php foreach ($batches as $b): $bt = (int)$b['total']; ?>
        <tr>
          <td><a class="num" href="<?= h($BASE . '?batch=' . rawurlencode($b['batch_number'])) ?>"><?= $b['batch_number'] !== '' ? h($b['batch_number']) : '(bez numeru)' ?></a></td>
          <td class="tw-font-mono tw-text-xs tw-whitespace-nowrap"><?= h($b['first_no']) ?> – <?= h($b['last_no']) ?></td>
          <td class="tw-text-right tw-font-bold"><?= $bt ?></td>
          <?php foreach (array_keys(HOLO_STATUSES) as $k): ?><td class="tw-text-right<?= (int)$b[$k] ? '' : ' muted' ?>"><?= (int)$b[$k] ?></td><?php endforeach; ?>
          <td>
            <div class="pool-bar is-mini" aria-hidden="true">
              <?php foreach (array_keys(HOLO_STATUSES) as $k): if (!(int)$b[$k]) continue; ?><span class="bg-<?= h($k) ?>" style="width: <?= $pct((int)$b[$k], $bt) ?>%"></span><?php endforeach; ?>
            </div>
            <span class="sub"><?= $pct($bt - (int)$b['available'], $bt) ?>% wykorzystane</span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>
<?php endif; ?>

<!-- ── Lista ──────────────────────────────────────────────────────────────── -->
<section aria-labelledby="h-list" class="tz-card"
         x-data="{ sel: <?= h(json_encode(array_map('strval', array_map('intval', (array)($oB['ids'] ?? []))))) ?>, to: <?= h(json_encode((string)($oB['to_status'] ?? ''))) ?>,
                   boxes() { return [...$root.querySelectorAll('input[name=\'ids[]\']')]; },
                   toggleAll(on) { this.sel = on ? this.boxes().map(x => x.value) : []; } }">
  <div class="tz-card__hd">
    <h2 id="h-list"><i class="bi bi-list-ul" aria-hidden="true"></i> Lista hologramów</h2>
    <span class="tw-text-sm tw-font-medium" style="color: var(--tz-muted)"><?= $total ?> <?= $total === 1 ? 'pozycja' : 'pozycji' ?></span>
    <form method="get" action="<?= h($BASE) ?>" role="search" class="tw-ml-auto tw-flex tw-items-center tw-gap-2">
      <?php if ($f_status !== ''): ?><input type="hidden" name="status" value="<?= h($f_status) ?>"><?php endif; ?>
      <?php if ($f_batch !== ''): ?><input type="hidden" name="batch" value="<?= h($f_batch) ?>"><?php endif; ?>
      <label for="f_q" class="tw-sr-only">Szukaj po numerze lub przypisaniu</label>
      <div class="tw-relative">
        <i class="bi bi-search tw-absolute tw-left-3 tw-top-1/2 -tw-translate-y-1/2 tw-text-sm" aria-hidden="true" style="color: var(--tz-muted)"></i>
        <input id="f_q" type="search" name="q" value="<?= h($f_q) ?>" placeholder="Numer lub przypisanie…" class="f-input tw-w-64 !tw-pl-9">
      </div>
      <button type="submit" class="h-btn h-btn--ghost">Szukaj</button>
    </form>
  </div>

  <nav aria-label="Filtr statusu" class="tw-flex tw-flex-wrap tw-items-center tw-gap-2 tw-px-[1.15rem] tw-py-3 tw-border-b" style="border-color: var(--tz-line)">
    <a class="pill" href="<?= h($statusUrl('')) ?>"<?= $f_status === '' ? ' aria-current="true"' : '' ?>>Wszystkie <span class="pill__n"><?= $stats['total'] ?></span></a>
    <?php foreach (HOLO_STATUSES as $k => $s): ?>
    <a class="pill" href="<?= h($statusUrl($k)) ?>"<?= $f_status === $k ? ' aria-current="true"' : '' ?>><?= holo_status_dot($k) ?><?= h($s['plural']) ?> <span class="pill__n"><?= $stats[$k] ?></span></a>
    <?php endforeach; ?>
    <?php if ($f_batch !== '' || $f_q !== ''): ?>
    <span class="tw-ml-auto tw-flex tw-flex-wrap tw-items-center tw-gap-2 tw-text-sm" style="color: var(--tz-muted)">
      <?php if ($f_batch !== ''): ?><span class="chip">seria: <?= h($f_batch) ?></span><?php endif; ?>
      <?php if ($f_q !== ''): ?><span class="chip">„<?= h($f_q) ?>”</span><?php endif; ?>
      <a href="<?= h($f_status !== '' ? $BASE . '?status=' . $f_status : $BASE) ?>" class="tw-font-medium" style="color: var(--holo-accent)">Wyczyść filtry</a>
    </span>
    <?php endif; ?>
  </nav>

  <form method="post" action="<?= h($postUrl) ?>">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="bulk">
    <?php if ($errors['bulk']): ?>
    <div class="tw-px-[1.15rem] tw-pt-4"><div role="alert" class="alert-err tw-mb-0"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= h($errors['bulk']) ?></div></div></div>
    <?php endif; ?>

    <?php if (!$list): ?>
    <div class="empty">
      <i class="bi bi-patch-question" aria-hidden="true"></i>
      <?php if ($filterQs): ?>
      <p class="tw-m-0">Brak hologramów pasujących do filtrów.</p>
      <?php else: ?>
      <p class="tw-m-0 tw-mb-3">Ewidencja jest pusta.</p>
      <button type="button" class="h-btn h-btn--primary" @click="panel = 'series'; $nextTick(() => document.getElementById('s_from').focus())"><i class="bi bi-plus-lg" aria-hidden="true"></i> Dodaj pierwszą serię</button>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="tw-overflow-x-auto">
      <table class="h-table">
        <caption class="tw-sr-only">Hologramy — strona <?= $page ?> z <?= $pages ?></caption>
        <thead>
          <tr>
            <th scope="col" class="tw-w-10">
              <input type="checkbox" class="h-check" aria-label="Zaznacz wszystkie na tej stronie"
                     @change="toggleAll($event.target.checked)" :checked="sel.length > 0 && sel.length === boxes().length"
                     :indeterminate="sel.length > 0 && sel.length < boxes().length">
            </th>
            <th scope="col">Numer</th>
            <th scope="col">Status</th>
            <th scope="col">Przypisano do</th>
            <th scope="col">Wydanie</th>
            <th scope="col">Seria</th>
            <th scope="col">Uwagi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list as $r): $rid = (int)$r['id']; ?>
          <tr :class="sel.includes('<?= $rid ?>') && 'is-selected'">
            <td><input type="checkbox" name="ids[]" value="<?= $rid ?>" x-model="sel" class="h-check" aria-label="Zaznacz <?= h($r['holo_number']) ?>"></td>
            <td><a class="num" href="<?= APP_URL ?>/modules/holograms/view.php?id=<?= $rid ?>"><?= h($r['holo_number']) ?></a></td>
            <td><?= holo_status_badge($r['status']) ?></td>
            <td>
              <?= $r['assigned_to'] ? '<span class="tw-font-medium">' . h($r['assigned_to']) . '</span>' : '<span class="muted" aria-label="brak">—</span>' ?>
              <?php if ($r['contract_type'] && ($ci = $contractOf($r))): ?>
              <a class="sub tw-no-underline" href="<?= h($ci['url']) ?>#hologramy"><i class="bi bi-link-45deg" aria-hidden="true"></i> umowa <?= h($ci['number'] ?: '#' . $ci['id']) ?></a>
              <?php endif; ?>
            </td>
            <td class="tw-whitespace-nowrap">
              <?php if ($r['issued_at']): ?>
              <time datetime="<?= h(date('c', strtotime($r['issued_at']))) ?>"><?= h(date('d.m.Y', strtotime($r['issued_at']))) ?></time>
              <?php if ($r['issued_by']): ?><span class="sub"><?= h($r['issued_by']) ?></span><?php endif; ?>
              <?php else: ?><span class="muted" aria-label="brak">—</span><?php endif; ?>
            </td>
            <td class="tw-whitespace-nowrap" style="color: var(--tz-muted)"><?= h($r['batch_number'] ?? '') ?></td>
            <td class="tw-max-w-[16rem] tw-truncate" style="color: var(--tz-muted)" title="<?= h($r['notes'] ?? '') ?>"><?= h($r['notes'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
    <nav aria-label="Stronicowanie" class="tw-flex tw-items-center tw-justify-between tw-gap-3 tw-px-[1.15rem] tw-py-3 tw-text-sm">
      <?php if ($page > 1): ?><a href="<?= h($pageUrl($page - 1)) ?>" class="h-btn h-btn--ghost" rel="prev"><i class="bi bi-chevron-left" aria-hidden="true"></i> Poprzednia</a><?php else: ?><span></span><?php endif; ?>
      <span style="color: var(--tz-muted)">Strona <strong style="color: var(--tz-ink)"><?= $page ?></strong> z <?= $pages ?> · pozycje <?= ($page - 1) * $PER_PAGE + 1 ?>–<?= min($total, $page * $PER_PAGE) ?></span>
      <?php if ($page < $pages): ?><a href="<?= h($pageUrl($page + 1)) ?>" class="h-btn h-btn--ghost" rel="next">Następna <i class="bi bi-chevron-right" aria-hidden="true"></i></a><?php else: ?><span></span><?php endif; ?>
    </nav>
    <?php endif; ?>

    <!-- Zbiorcza zmiana statusu — pojawia się po zaznaczeniu -->
    <div class="bulk-bar" x-show="sel.length > 0" x-cloak x-transition.opacity role="region" aria-label="Akcje dla zaznaczonych">
      <p class="bulk-bar__count tw-m-0" aria-live="polite"><strong x-text="sel.length"></strong> zaznaczonych
        <button type="button" class="link-btn" @click="sel = []">odznacz</button></p>
      <div>
        <label for="b_to" class="f-label">Zmień status na</label>
        <select id="b_to" name="to_status" x-model="to" required class="f-input tw-w-60">
          <option value="">— wybierz —</option>
          <option value="returned">Zwrócony — zwrot wydanej</option>
          <option value="damaged">Uszkodzony</option>
          <option value="available">Dostępny — przywróć zwróconą do puli</option>
        </select>
      </div>
      <div>
        <label for="b_note" class="f-label">Opis <span x-show="to === 'damaged'">(wymagany)</span></label>
        <input id="b_note" name="note" value="<?= h($oB['note'] ?? '') ?>" maxlength="2000" class="f-input tw-w-72"
               :required="to === 'damaged'" placeholder="np. rozdarta przy naklejaniu">
      </div>
      <button type="submit" class="h-btn h-btn--primary" :disabled="!to">Zastosuj</button>
    </div>
    <?php endif; ?>
  </form>
</section>

</div>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
