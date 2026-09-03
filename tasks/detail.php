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
<?php require_once __DIR__ . '/includes/detail_properties.php'; ?>
<?php require_once __DIR__ . '/includes/detail_fields.php'; ?>
<?php require_once __DIR__ . '/includes/detail_subtasks_time.php'; ?>
<?php require_once __DIR__ . '/includes/detail_files.php'; ?>
<?php require_once __DIR__ . '/includes/detail_activity.php'; ?>
<?php require_once __DIR__ . '/includes/detail_modals.php'; ?>
</div><!-- /td-root -->

<?php require_once __DIR__ . '/includes/detail_js_core.php'; ?>
<?php require_once __DIR__ . '/includes/detail_js_files.php'; ?>
<?php require_once __DIR__ . '/includes/detail_js_review.php'; ?>
<?php require_once __DIR__ . '/includes/detail_js_subtasks.php'; ?>
<?php require_once __DIR__ . '/includes/detail_js_time.php'; ?>
<?php require_once __DIR__ . '/includes/detail_js_newws.php'; ?>
<?php require_once __DIR__ . '/includes/detail_js_takeover.php'; ?>
<?php require_once __DIR__ . '/includes/detail_js_notify.php'; ?>
