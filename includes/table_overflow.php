<?php
/**
 * includes/table_overflow.php — tabele nie ucinają kolumn (wspólne dla powłok SZO i CRM).
 *
 * 1) Kontener, którego BEZPOŚREDNIM dzieckiem jest tabela (karta, karta-body, zakładka,
 *    dowolny div/section), przewija się w poziomie zamiast ucinać kolumny (wiele stron ma
 *    zaokrąglone karty z overflow:hidden). Selektor w :where() = zerowa specyficzność:
 *    każda reguła strony ustawiająca overflow (np. własne przewijanie z max-height) wygrywa.
 *    Wyjątek: tabele z przyklejonym nagłówkiem (thead.sticky-top) — przewijany kontener
 *    zepsułby przyklejanie do strony.
 * 2) Rozwijane menu Bootstrapa pozycjonowane „fixed”, żeby przewijany kontener tabeli
 *    nie ucinał menu akcji w wierszach.
 *
 * Panel współpracownika i moduł Zadania mają to samo we własnych warstwach Metro
 * (panel/includes/pv_metro.php, tasks/includes/tasks_metro.php).
 *
 * Użycie: require_once __DIR__ . '/table_overflow.php'; table_overflow_assets(); (w <head>)
 */
function table_overflow_assets(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
<style>
:where(div,section,article):has(> table):not(:has(> table > thead.sticky-top)){overflow-x:auto}
:where(.table-responsive){overflow-x:auto;-webkit-overflow-scrolling:touch}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.bootstrap && bootstrap.Dropdown && bootstrap.Dropdown.Default) {
    bootstrap.Dropdown.Default.popperConfig = function (cfg) { return Object.assign({}, cfg, { strategy: 'fixed' }); };
  }
});
</script>
    <?php
}
