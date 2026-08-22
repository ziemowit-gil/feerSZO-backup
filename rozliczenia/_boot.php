<?php
/**
 * rozliczenia/_boot.php — wspólny start stron modułu Rozliczenia.
 *
 * Moduł jest nakładką na dane rozliczeń TI (k30_ti_billing / k30_ti_payments) —
 * nie duplikuje logiki: korzysta z includes/karty30.php i includes/ti_payments.php.
 * Udostępnia: kontrolę dostępu, wybrany miesiąc ($rz_year/$rz_month) i drobne helpery.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/includes/ti_payments.php';

k30_require_access();
karty30_migrate();
ti_payments_migrate();

$rz_can_write = can_write('karty30') || is_admin();
$rz_can_admin = is_admin();

// Wybrany okres: ?m=YYYY-MM (domyślnie bieżący miesiąc)
$rz_ym = (string)($_GET['m'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $rz_ym)) $rz_ym = date('Y-m');
[$rz_year, $rz_month] = array_map('intval', explode('-', $rz_ym));
$rz_month = max(1, min(12, $rz_month));
$rz_ym    = sprintf('%04d-%02d', $rz_year, $rz_month);

const RZ_MONTHS_PL = [1=>'Styczeń',2=>'Luty',3=>'Marzec',4=>'Kwiecień',5=>'Maj',6=>'Czerwiec',
                      7=>'Lipiec',8=>'Sierpień',9=>'Wrzesień',10=>'Październik',11=>'Listopad',12=>'Grudzień'];
$rz_month_label = (RZ_MONTHS_PL[$rz_month] ?? $rz_month) . ' ' . $rz_year;

/** Kwota w złotych (spacja jako separator tysięcy). */
function rz_zl($x): string { return number_format((float)$x, 2, ',', ' ') . ' zł'; }

/** Saldo jako kolorowy HTML: + nadpłata / − niedopłata / 0 rozliczone. */
function rz_saldo(float $credit, float $debt): string {
    if ($debt   > 0.005) return '<span class="rz-neg">−' . h(rz_zl($debt)) . '</span>';
    if ($credit > 0.005) return '<span class="rz-pos">+' . h(rz_zl($credit)) . '</span>';
    return '<span class="rz-zero">' . h(rz_zl(0)) . '</span>';
}

/** Poprzedni / następny miesiąc jako YYYY-MM. */
function rz_shift(int $year, int $month, int $delta): string {
    $t = mktime(0, 0, 0, $month + $delta, 1, $year);
    return date('Y-m', $t);
}

/** Pasek nawigacji miesięcznej (ten sam plik, zmieniony parametr ?m=). */
function rz_month_bar(string $self, int $year, int $month, string $label, string $extra_qs = ''): string {
    $q  = $extra_qs !== '' ? '&amp;' . $extra_qs : '';
    $pv = rz_shift($year, $month, -1);
    $nx = rz_shift($year, $month, +1);
    return '<div class="rz-month">'
         . '<a class="btn btn-sm btn-outline-secondary" href="' . h($self) . '?m=' . h($pv) . $q . '" aria-label="Poprzedni miesiąc"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>'
         . '<span class="lbl">' . h($label) . '</span>'
         . '<a class="btn btn-sm btn-outline-secondary" href="' . h($self) . '?m=' . h($nx) . $q . '" aria-label="Następny miesiąc"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>'
         . '<a class="btn btn-sm btn-outline-secondary" href="' . h($self) . '?m=' . h(date('Y-m')) . $q . '">Bieżący miesiąc</a>'
         . '</div>';
}

/** Nazwa grupy (z kodem, gdy ustawiony). */
function rz_group_label(array $g): string {
    $code = trim((string)($g['group_code'] ?? ''));
    return $code !== '' ? $code : (string)($g['course_name'] ?? '—');
}
