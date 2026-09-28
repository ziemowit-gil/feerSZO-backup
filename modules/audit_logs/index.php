<?php
/**
 * modules/audit_logs/index.php — przeglądarka wspólnego dziennika audytu (audit_logs).
 * Tylko do odczytu; wpisów nie da się edytować ani usuwać z poziomu aplikacji.
 * Logika: modules/audit_logs/logic/audit_logs.php.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/audit_logs.php';

require_role('admin');

$BASE = APP_URL . '/modules/audit_logs/index.php';
$isDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
$f = [
    'module'  => isset(AUDIT_MODULES[$_GET['module'] ?? '']) ? $_GET['module'] : '',
    'action'  => mb_substr(trim((string)($_GET['action'] ?? '')), 0, 100),
    'user_id' => max(0, (int)($_GET['user_id'] ?? 0)),
    'q'       => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
    'from'    => $isDate($_GET['from'] ?? ''),
    'to'      => $isDate($_GET['to'] ?? ''),
];
$PER_PAGE = 50;
$page  = max(1, (int)($_GET['page'] ?? 1));
$res   = audit_logs_search($f, $PER_PAGE, ($page - 1) * $PER_PAGE);
$pages = max(1, (int)ceil($res['total'] / $PER_PAGE));
$actions = audit_logs_actions();
$qs = fn(array $extra = []) => http_build_query(array_filter(array_merge($f, $extra), fn($v) => $v !== '' && $v !== 0 && $v !== null));

$PAGE_TITLE = 'Dziennik audytu';
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<style type="text/tailwindcss">
.aud *, .aud *::before, .aud *::after { border-width: 0; border-style: solid; }
.aud { @apply tw-max-w-[1200px]; color: #111827; }
.aud .f-label { @apply tw-block tw-text-[.8rem] tw-font-semibold tw-mb-1; }
.aud .f-input { @apply tw-w-full tw-rounded-[10px] tw-border tw-border-slate-300 tw-bg-white tw-px-3 tw-py-2 tw-text-sm; }
.aud .f-input:focus { @apply tw-outline-none tw-border-blue-700; box-shadow: 0 0 0 3px rgba(29,78,216,.18); }
.aud .btn { @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-[10px] tw-border tw-px-4 tw-py-2 tw-text-sm tw-font-semibold tw-no-underline tw-cursor-pointer; }
.aud .btn--primary { @apply tw-bg-blue-700 tw-border-blue-700 tw-text-white; }
.aud .btn--ghost { @apply tw-bg-white tw-border-slate-300 tw-text-slate-900; }
.aud .card { @apply tw-bg-white tw-border tw-border-slate-200 tw-rounded-2xl tw-shadow-sm; }
.aud table { @apply tw-w-full tw-text-sm tw-border-collapse; }
.aud th { @apply tw-text-left tw-text-[.72rem] tw-font-bold tw-uppercase tw-tracking-wide tw-px-4 tw-py-2.5 tw-bg-slate-50 tw-text-slate-600 tw-border-b tw-border-slate-200; }
.aud td { @apply tw-px-4 tw-py-2.5 tw-border-b tw-border-slate-100 tw-align-top; }
.aud .act { @apply tw-inline-block tw-rounded-md tw-bg-slate-100 tw-px-2 tw-py-0.5 tw-font-mono tw-text-xs tw-font-semibold tw-text-slate-800; }
.aud .kv { @apply tw-m-0 tw-grid tw-gap-x-3 tw-gap-y-0.5 tw-text-xs; grid-template-columns: max-content 1fr; }
.aud .kv dt { @apply tw-font-semibold tw-text-slate-600; }
.aud .kv dd { @apply tw-m-0 tw-break-all; }
</style>

<div class="aud">
  <header class="tw-mb-5">
    <h1 class="tw-text-2xl tw-font-extrabold tw-m-0 tw-flex tw-items-center tw-gap-2">
      <span class="tw-w-9 tw-h-9 tw-rounded-[10px] tw-inline-flex tw-items-center tw-justify-center tw-bg-slate-800 tw-text-white tw-text-lg" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
      Dziennik audytu
    </h1>
    <p class="tw-mt-1 tw-mb-0 tw-text-sm tw-text-slate-600">Operacje krytyczne modułów Hologramy i Karty dostępu: kto, co, kiedy i z jakiego adresu. Wpisy są niezmienialne.</p>
  </header>

  <form method="get" action="<?= h($BASE) ?>" class="card tw-p-4 tw-mb-5 tw-grid tw-grid-cols-1 md:tw-grid-cols-12 tw-gap-3 tw-items-end" role="search" aria-label="Filtry dziennika">
    <div class="md:tw-col-span-2">
      <label for="a_module" class="f-label">Moduł</label>
      <select id="a_module" name="module" class="f-input">
        <option value="">Wszystkie</option>
        <?php foreach (AUDIT_MODULES as $k => $lbl): ?>
        <option value="<?= h($k) ?>"<?= $f['module'] === $k ? ' selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:tw-col-span-3">
      <label for="a_action" class="f-label">Akcja</label>
      <select id="a_action" name="action" class="f-input">
        <option value="">Wszystkie</option>
        <?php foreach ($actions as $a): ?>
        <option value="<?= h($a) ?>"<?= $f['action'] === $a ? ' selected' : '' ?>><?= h($a) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:tw-col-span-3">
      <label for="a_q" class="f-label">Szukaj w szczegółach / IP</label>
      <input id="a_q" name="q" value="<?= h($f['q']) ?>" class="f-input" placeholder="np. HOLO-2026-001, UID karty">
    </div>
    <div class="md:tw-col-span-2">
      <label for="a_from" class="f-label">Od</label>
      <input id="a_from" type="date" name="from" value="<?= h($f['from']) ?>" class="f-input">
    </div>
    <div class="md:tw-col-span-2">
      <label for="a_to" class="f-label">Do</label>
      <input id="a_to" type="date" name="to" value="<?= h($f['to']) ?>" class="f-input">
    </div>
    <?php if ($f['user_id']): ?><input type="hidden" name="user_id" value="<?= (int)$f['user_id'] ?>"><?php endif; ?>
    <div class="md:tw-col-span-12 tw-flex tw-gap-2">
      <button class="btn btn--primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filtruj</button>
      <a class="btn btn--ghost" href="<?= h($BASE) ?>">Wyczyść</a>
      <span class="tw-ml-auto tw-self-center tw-text-sm tw-text-slate-600" aria-live="polite">Wyników: <strong><?= $res['total'] ?></strong></span>
    </div>
  </form>

  <section class="card tw-overflow-x-auto" aria-label="Wpisy dziennika">
    <?php if (!$res['rows']): ?>
      <p class="tw-text-center tw-py-12 tw-text-slate-500 tw-m-0"><i class="bi bi-journal-x tw-text-4xl tw-block tw-mb-2 tw-text-slate-300" aria-hidden="true"></i>Brak wpisów dla wybranych filtrów.</p>
    <?php else: ?>
    <table>
      <thead><tr><th scope="col">Kiedy</th><th scope="col">Akcja</th><th scope="col">Użytkownik</th><th scope="col">Szczegóły</th><th scope="col">IP</th></tr></thead>
      <tbody>
      <?php foreach ($res['rows'] as $r):
          $d = json_decode((string)$r['details'], true); ?>
        <tr>
          <td class="tw-whitespace-nowrap"><time datetime="<?= h($r['created_at']) ?>"><?= h(date('d.m.Y H:i:s', strtotime($r['created_at']))) ?></time></td>
          <td><a class="act tw-no-underline" href="<?= h($BASE . '?' . $qs(['action' => $r['action'], 'page' => ''])) ?>"><?= h($r['action']) ?></a></td>
          <td>
            <?php if ($r['user_id']): ?>
              <a href="<?= h($BASE . '?' . $qs(['user_id' => (int)$r['user_id'], 'page' => ''])) ?>" class="tw-font-medium"><?= h($r['user_name'] ?: '#' . $r['user_id']) ?></a>
              <?php if ($r['user_email']): ?><span class="tw-block tw-text-xs tw-text-slate-500"><?= h($r['user_email']) ?></span><?php endif; ?>
            <?php else: ?><span class="tw-text-slate-400">system</span><?php endif; ?>
          </td>
          <td>
            <?php if (is_array($d)): ?>
            <dl class="kv">
              <?php foreach ($d as $k => $v): ?>
              <dt><?= h($k) ?></dt><dd><?= h(is_scalar($v) || $v === null ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE)) ?></dd>
              <?php endforeach; ?>
            </dl>
            <?php else: ?><span class="tw-text-xs"><?= h((string)$r['details']) ?></span><?php endif; ?>
          </td>
          <td class="tw-font-mono tw-text-xs tw-whitespace-nowrap"><?= h($r['ip_address']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($pages > 1): ?>
    <nav class="tw-flex tw-justify-between tw-items-center tw-p-3 tw-text-sm" aria-label="Stronicowanie">
      <?php if ($page > 1): ?><a class="btn btn--ghost" rel="prev" href="<?= h($BASE . '?' . $qs(['page' => $page - 1])) ?>">‹ Poprzednia</a><?php else: ?><span></span><?php endif; ?>
      <span>Strona <strong><?= $page ?></strong> z <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn btn--ghost" rel="next" href="<?= h($BASE . '?' . $qs(['page' => $page + 1])) ?>">Następna ›</a><?php else: ?><span></span><?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
  </section>
</div>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
