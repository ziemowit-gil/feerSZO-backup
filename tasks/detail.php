<?php
/**
 * tasks/detail.php
 * Partial HTML ładowany do offcanvas przez fetch() + createContextualFragment().
 * NIE zawiera DOCTYPE ani nagłówków strony.
 *
 * Przebudowa na Tailwind (2026-09-04) — podział na pliki, patrz tasks/includes/:
 *   - detail_load.php               — dane zadania + td_render_mentions()
 *   - detail_style.php              — <style> (Tailwind @apply, tokeny lokalne #td-root)
 *   - detail_header.php             — tytuł, statusy, belka akcji, panel „Przekaż"
 *   - detail_properties.php         — siatka właściwości + przypisani
 *   - detail_fields.php             — tagi + opis
 *   - detail_subtasks_time.php      — podzadania + czas pracy + powtarzalność
 *   - detail_files.php              — załączniki + pliki z Koszulek
 *   - detail_activity.php           — komentarze + historia
 *   - detail_modals.php             — modale (zgłoś problem, odrzuć, nowy obszar)
 *   - detail_js_*.php (8 plików)    — logika JS, każdy jako własny <script>
 *
 * WAŻNE: detail_js_*.php celowo NIE są zewnętrznymi plikami .js ładowanymi przez
 * <script src>, tylko inline <script> wstrzykiwane RAZEM z resztą fragmentu.
 * Ten fragment trafia do offcanvas przez fetch()+createContextualFragment(), NIE
 * przez normalne wczytanie strony — bezpieczeństwo wykonania zewnętrznych <script src>
 * w tym trybie nie było sprawdzone, więc zostało to celowo pominięte jako zbyt
 * ryzykowne. Każdy detail_js_*.php jest też BEZ własnego IIFE — działają jak jeden
 * wspólny skrypt (top-level const/let/function współdzielą "script scope" między
 * kolejnymi <script> tego samego dokumentu), dokładnie odtwarzając zachowanie
 * poprzedniego pojedynczego IIFE. Nie dodawać `(function(){...})()` do pojedynczego
 * pliku bez przemyślenia — odetnie go od zmiennych/funkcji innych plików.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';
require_once dirname(__DIR__) . '/includes/org.php';

require_login();
$uid = (int)(current_user()['id'] ?? 0);
$id  = (int)($_GET['id'] ?? 0);

if (!$id) { echo '<div class="alert alert-danger m-3">Brak ID zadania.</div>'; exit; }

require_once __DIR__ . '/includes/detail_load.php';
?>
<?php require_once __DIR__ . '/includes/detail_style.php'; ?>

<?php require_once __DIR__ . '/includes/detail_header.php'; ?>
<div class="td-layout">
  <div class="td-main">
<?php require_once __DIR__ . '/includes/detail_properties.php'; ?>
<?php require_once __DIR__ . '/includes/detail_mail_source.php'; ?>
<?php ob_start(); require_once __DIR__ . '/includes/detail_links.php'; $td_extra_links = ob_get_clean(); ?>
<?php ob_start(); require_once __DIR__ . '/includes/detail_dependencies.php'; $td_extra_deps = ob_get_clean(); ?>
<?php require_once __DIR__ . '/includes/detail_fields.php'; ?>
<?php require_once __DIR__ . '/includes/detail_subtasks_time.php'; ?>
<?php /* Rzadziej używane części zadania jako kafle: klik rozwija sekcję pod kaflami (jedna naraz).
         Bez skryptu w bloku detail_js_* — logika w onclick, bo fragment wstrzykiwany jest przy każdym otwarciu. */
  $td_link_n = count(tasksLinksOf((int)$id));
  $td_tiles = array_filter([
      'links' => ['Powiązania', 'bi-link-45deg', $td_link_n ? $td_link_n . ' ' . ($td_link_n === 1 ? 'powiązanie' : 'powiązania') : 'brak', $td_extra_links],
      'unit'  => ['Jednostka', 'bi-diagram-3', !empty($task['unit_id']) ? strip_tags(task_unit_badge((int)$task['unit_id'])) : 'brak', trim($td_extra_unit) !== '' ? '<div class="td-section">' . $td_extra_unit . '</div>' : ''],
      'deps'  => ['Zależności', 'bi-diagram-2', $blockers || $blocking ? trim(($blockers ? 'czeka na ' . count($blockers) : '') . ($blockers && $blocking ? ', ' : '') . ($blocking ? 'blokuje ' . count($blocking) : '')) : 'brak', $td_extra_deps],
      'tags'  => ['Tagi', 'bi-tags', $tag_ids ? count($tag_ids) . ' ' . (count($tag_ids) === 1 ? 'tag' : 'tagi') : 'brak', $td_extra_tags],
      'rec'   => ['Powtarzanie', 'bi-arrow-repeat', !empty($task['recurrence']) ? (['daily' => 'codziennie', 'weekly' => 'co tydzień', 'monthly' => 'co miesiąc', 'yearly' => 'co rok'][$task['recurrence']] ?? 'tak') : 'nie', $td_extra_rec],
  ], fn($t) => trim($t[3]) !== '');
  if ($td_tiles): ?>
<div class="td-section td-tiles-wrap">
  <div class="td-tiles" role="group" aria-label="Dodatkowe informacje o zadaniu">
    <?php foreach ($td_tiles as $k => [$lab, $ico, $state]): $pid = 'td-tile-' . $k . '-' . (int)$id; ?>
    <button type="button" class="td-tile <?= $state === 'brak' || $state === 'nie' ? '' : 'is-set' ?>" aria-expanded="false" aria-controls="<?= $pid ?>"
            onclick="(function(b){var r=b.closest('.td-tiles-wrap');r.querySelectorAll('.td-tile').forEach(function(x){if(x!==b){x.setAttribute('aria-expanded','false');document.getElementById(x.getAttribute('aria-controls')).hidden=true;}});var o=b.getAttribute('aria-expanded')==='true';b.setAttribute('aria-expanded',o?'false':'true');document.getElementById(b.getAttribute('aria-controls')).hidden=o;})(this)">
      <i class="bi <?= $ico ?>" aria-hidden="true"></i>
      <span class="td-tile-lab"><?= h($lab) ?></span>
      <span class="td-tile-state"><?= h($state) ?></span>
    </button>
    <?php endforeach; ?>
  </div>
  <?php foreach ($td_tiles as $k => $t): ?>
  <div class="td-tile-panel" id="td-tile-<?= $k ?>-<?= (int)$id ?>" hidden><?= $t[3] ?></div>
  <?php endforeach; ?>
</div>
<?php endif; unset($td_extra_links, $td_extra_unit, $td_extra_deps, $td_extra_tags, $td_extra_rec, $td_tiles, $td_link_n); ?>
<?php require_once __DIR__ . '/includes/detail_activity.php'; ?>
  </div>
  <aside class="td-side" aria-label="Komentarze i pliki zadania">
<?php require_once __DIR__ . '/includes/detail_comments.php'; ?>
<?php require_once __DIR__ . '/includes/detail_files.php'; ?>
  </aside>
</div>
<?php require_once __DIR__ . '/includes/detail_modals.php'; ?>
</div><!-- /td-root -->

<?php
// Wszystkie detail_js_*.php sklejone w JEDEN <script> z blokiem { … }.
// Fragment jest wstrzykiwany do offcanvas przy KAŻDYM otwarciu zadania w tym samym
// dokumencie — top-level `const TID/CSRF/…` w osobnych <script> przy drugim otwarciu
// rzucały SyntaxError („already declared”) i cały JS szczegółów przestawał działać.
// W bloku const/let są świeże per otwarcie, a deklaracje `function` (tryb nie-strict,
// Annex B) nadal trafiają do window — inline onclick="…" działają jak dotąd.
ob_start();
foreach (['core', 'files', 'review', 'subtasks', 'time', 'newws', 'takeover', 'notify'] as $_td_js) {
    require __DIR__ . '/includes/detail_js_' . $_td_js . '.php';
}
$_td_js_src = preg_replace(['#^\s*<script>#', '#</script>\s*$#'], '', ob_get_clean());
$_td_js_src = preg_replace('#</script>\s*<script>#', "\n", $_td_js_src);
echo "<script>\n{\n", $_td_js_src, "\n}\n</script>\n";
unset($_td_js, $_td_js_src);
?>
