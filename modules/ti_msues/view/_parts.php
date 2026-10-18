<?php
/**
 * modules/ti_msues/view/_parts.php — drobne elementy widoków MSUES: odznaka statusu, pasek gotowości, ikona kryterium.
 */
if (!function_exists('msuesBadge')) {
    function msuesBadge(DeclarationStatus $s): string
    {
        return '<span class="badge text-bg-' . $s->badge() . ($s === DeclarationStatus::Superseded ? ' border' : '') . '">' . h($s->label()) . '</span>';
    }

    function msuesBar(int $score, bool $ready, bool $blocked): string
    {
        $c = $ready ? 'bg-success' : ($blocked ? 'bg-danger' : ($score >= 50 ? 'bg-warning' : 'bg-secondary'));
        return '<div class="progress" style="height:1.1rem;min-width:120px" role="progressbar" aria-label="Gotowość do audytu" aria-valuenow="' . $score . '" aria-valuemin="0" aria-valuemax="100">'
             . '<div class="progress-bar ' . $c . '" style="width:' . max(4, $score) . '%">' . $score . '%</div></div>';
    }

    function msuesCheckIcon(bool $ok, bool $required = true): string
    {
        return $ok ? '<i class="bi bi-check-square-fill ok" aria-hidden="true"></i><span class="visually-hidden">spełnione</span>'
                   : '<i class="bi bi-' . ($required ? 'x-square-fill no' : 'dash-square text-secondary') . '" aria-hidden="true"></i><span class="visually-hidden">brak</span>';
    }

    function msuesCourseName(array $d): string
    {
        if (empty($d['course_id'])) return 'ogólne (wszystkie usługi)';
        return (trim((string)($d['course_display'] ?? '')) !== '' ? (string)$d['course_display'] : (string)$d['course_name']) . (trim((string)($d['course_code'] ?? '')) !== '' ? ' (' . $d['course_code'] . ')' : '');
    }
}
