<?php
/**
 * modules/ti_licencje_dydaktyczne/view/_parts.php — drobne elementy widoków AM5: znacznik stanu (chip Metro), formularz POST.
 */
if (!function_exists('ldChip')) {
    function ldChip(string $label, string $color): string
    {
        $dark = in_array(strtolower($color), ['#f0a30a'], true);
        return '<span class="chip" style="background:' . h($color) . ($dark ? ';color:#1d1d1d' : '') . '">' . h($label) . '</span>';
    }

    /** Stan licencji: pula (gdy udzielona) albo etap wniosku. */
    function ldStateChip(array $l): string
    {
        if ($l['poolStatus']) return ldChip($l['poolStatus']->label(), $l['poolStatus']->color());
        $s = $l['requestStatus'];
        $chip = ldChip($s->label(), $s->color());
        if (LicenseRepository::overdue($l)) $chip .= ' ' . ldChip('po terminie 7 dni', '#a20025');
        return $chip;
    }

    /** Mały formularz POST z tokenem panelu (przycisk akcji). $fields — dodatkowe ukryte pola. */
    function ldPost(string $url, string $label, string $class = 'btn-metro', array $fields = [], string $confirm = ''): string
    {
        $h = '<form method="post" action="' . h($url) . '" class="d-inline"' . ($confirm !== '' ? ' onsubmit="return confirm(' . h(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>' . Router::csrfField();
        foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
        return $h . '<button type="submit" class="btn btn-sm ' . h($class) . '">' . h($label) . '</button></form>';
    }

    function ldDt(?string $dt): string
    {
        $dt = (string)$dt;
        return $dt === '' ? '' : ldDate(substr($dt, 0, 10)) . (strlen($dt) > 10 ? ' ' . substr($dt, 11, 5) : '');
    }
}
