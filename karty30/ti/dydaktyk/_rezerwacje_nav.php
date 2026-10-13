<?php
/**
 * karty30/ti/dydaktyk/_rezerwacje_nav.php — wspólny początek ekranów rezerwacji terminów konsultacji:
 * dostęp (uprawnienie do trybu Konsultacje — TiWorkModes), nagłówek strony i zakładki
 * Lista / Kalendarz / Szybka rezerwacja / Kolejka oczekujących (rz_nav), plakietka statusu (rz_badge).
 * Zwraca do strony: $me, $uid, $staff, $byName. Nagłówek HTML strona dołącza sama (_rezerwacje_head.php).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_bookings/logic/bookings.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consultations/logic/consultations.php';
$me     = dyd_require();
$uid    = (int)$me['user_id'];
$staff  = dyd_is_staff();
$byName = (string)($me['name'] ?? '');
karty30_migrate();
if (!in_array('konsultacje', dyd_modes_available(), true)) {
    flash_set('warning', 'Rezerwacje terminów są w trybie Konsultacje — uprawnienie nadaje kierownik w „Zespół i role”.');
    header('Location: index.php'); exit;
}
function rz_nav(string $cur): void {
    $tabs = ['lista' => ['Rezerwacje', 'rezerwacje.php', 'list-ul'], 'kalendarz' => ['Kalendarz', 'rezerwacjeKalendarz.php', 'calendar3'],
             'szybka' => ['Szybka rezerwacja', 'rezerwacja.php?szybka=1', 'lightning'], 'nowa' => ['Nowa rezerwacja', 'rezerwacja.php?nowa=1', 'plus-lg'],
             'kolejka' => ['Kolejka oczekujących', 'kolejka.php', 'hourglass-split'], 'karty' => ['Karty konsultacji', 'konsultacje.php', 'clipboard2-check'],
             'zestawienia' => ['Wydruki i zestawienia', 'konsultacjeZestawienia.php', 'printer']];
    if (dyd_is_staff()) $tabs['ustawienia'] = ['Ustawienia', 'konsultacjeUstawienia.php', 'gear']; ?>
<nav class="mb-3" aria-label="Rezerwacje terminów">
  <ul class="nav nav-pills flex-wrap gap-1">
    <?php foreach ($tabs as $k => [$l, $href, $ic]): ?>
    <li class="nav-item"><a class="nav-link py-1 px-2<?= $k === $cur ? ' active' : '' ?>" href="<?= h($href) ?>"<?= $k === $cur ? ' aria-current="page"' : '' ?>><i class="bi bi-<?= h($ic) ?> me-1" aria-hidden="true"></i><?= h($l) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
<?php }
function rz_badge(string $status): string {
    $st = K30_SCHEDULE_STATUSES[$status] ?? ['label' => $status, 'color' => '#555', 'bg' => '#eee', 'icon' => 'bi-circle'];
    return '<span class="badge" style="background:' . h($st['bg']) . ';color:#111827;border:1px solid ' . h($st['color']) . '"><i class="bi ' . h($st['icon'] ?? '') . ' me-1" aria-hidden="true"></i>' . h($st['label']) . '</span>';
}
