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
<?php require_once __DIR__ . '/includes/detail_links.php'; ?>
<?php ob_start(); require_once __DIR__ . '/includes/detail_dependencies.php'; $td_extra_deps = ob_get_clean(); ?>
<?php require_once __DIR__ . '/includes/detail_fields.php'; ?>
<?php require_once __DIR__ . '/includes/detail_subtasks_time.php'; ?>
<?php /* Zależności, tagi i powtarzanie schowane w zwiniętej sekcji — w podsumowaniu widać, co jest ustawione */
  $td_extra_on = array_filter([
      !empty($task['unit_id']) ? 'jednostka' : '',
      $tag_ids ? count($tag_ids) . ' ' . (count($tag_ids) === 1 ? 'tag' : 'tagi') : '',
      !empty($task['recurrence']) ? 'powtarza się' : '',
      $blockers ? 'czeka na ' . count($blockers) : '',
      $blocking ? 'blokuje ' . count($blocking) : '',
  ]);
  $td_extra_unit = trim($td_extra_unit) !== '' ? '<div class="td-section">' . $td_extra_unit . '</div>' : '';
  if (trim($td_extra_unit . $td_extra_deps . $td_extra_tags . $td_extra_rec) !== ''): ?>
<details class="td-section td-extra">
  <summary class="td-label td-extra-sum">
    <i class="bi bi-three-dots" aria-hidden="true"></i>Jednostka, zależności, tagi, powtarzanie
    <?php if ($td_extra_on): ?><span class="td-extra-on"><?= h(implode(' · ', $td_extra_on)) ?></span><?php endif; ?>
  </summary>
  <div class="td-extra-body"><?= $td_extra_unit . $td_extra_deps . $td_extra_tags . $td_extra_rec ?></div>
</details>
<?php endif; unset($td_extra_unit, $td_extra_deps, $td_extra_tags, $td_extra_rec, $td_extra_on); ?>
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
