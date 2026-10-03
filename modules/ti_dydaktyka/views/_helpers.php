<?php
/**
 * modules/ti_dydaktyka/views/_helpers.php — pomocnicze funkcje widoków karty kursanta.
 * Odznaki zawsze mają ikonę i tekst (kolor nie jest jedynym nośnikiem informacji, WCAG 1.4.1);
 * pary kolorów (tło/tekst) mają kontrast ≥ 7:1 — definicje klas .dk-* w kartaKursanta.php.
 */
if (!function_exists('dk_badge')) {
    function dk_badge(string $icon, string $cls, string $label): string {
        return '<span class="badge dk-b ' . h($cls) . '"><i class="bi ' . h($icon) . ' me-1" aria-hidden="true"></i>' . h($label) . '</span>';
    }
    function dk_att_badge(string $k): string {
        static $m = ['present' => ['bi-check-circle-fill', 'dk-ok'], 'excused' => ['bi-info-circle-fill', 'dk-info'],
                     'absent' => ['bi-x-octagon-fill', 'dk-bad'], 'planned' => ['bi-calendar-event', 'dk-muted']];
        return dk_badge($m[$k][0] ?? 'bi-dash', $m[$k][1] ?? 'dk-muted', TiDydaktykaManager::ATTENDANCE[$k] ?? $k);
    }
    function dk_mod_badge(string $k): string {
        static $m = ['not_started' => ['bi-circle', 'dk-muted'], 'in_progress' => ['bi-hourglass-split', 'dk-prog'],
                     'mastered' => ['bi-check-circle-fill', 'dk-ok'], 'needs_revision' => ['bi-arrow-repeat', 'dk-warn']];
        return dk_badge($m[$k][0] ?? 'bi-dash', $m[$k][1] ?? 'dk-muted', TiDydaktykaManager::MODULE_STATUSES[$k] ?? $k);
    }
    function dk_kind_badge(string $k): string {
        static $m = ['progress' => ['bi-graph-up-arrow', 'dk-prog'], 'difficulty' => ['bi-exclamation-triangle-fill', 'dk-warn'],
                     'goal' => ['bi-bullseye', 'dk-goal'], 'other' => ['bi-chat-left-text', 'dk-muted']];
        return dk_badge($m[$k][0] ?? 'bi-dash', $m[$k][1] ?? 'dk-muted', TiDydaktykaManager::NOTE_KINDS[$k] ?? $k);
    }
    function dk_vis_badge(string $k): string {
        return $k === 'student' ? dk_badge('bi-eye-fill', 'dk-ok', 'Dla kursanta i opiekuna') : dk_badge('bi-lock-fill', 'dk-dark', 'Wewnętrzna');
    }
    function dk_lic_badge(string $k): string {
        static $m = ['active' => ['bi-check-circle-fill', 'dk-ok'], 'expiring' => ['bi-hourglass-split', 'dk-warn'],
                     'expired' => ['bi-x-octagon-fill', 'dk-bad'], 'revoked' => ['bi-slash-circle-fill', 'dk-muted']];
        return dk_badge($m[$k][0] ?? 'bi-dash', $m[$k][1] ?? 'dk-muted', TiLicencjeManager::STATUSES[$k] ?? $k);
    }
    function dk_date(?string $d): string { return $d !== null && $d !== '' ? date('d.m.Y', strtotime($d)) : '—'; }
    function dk_dt(?string $d): string { return $d !== null && $d !== '' ? date('d.m.Y H:i', strtotime($d)) : '—'; }
    /** Link zwrotny z zachowaniem kontekstu (kursant, grupa). */
    function dk_url(int $clientId, int $courseCtx, array $extra = [], string $hash = ''): string {
        $q = array_merge(['client' => $clientId], $courseCtx ? ['course' => $courseCtx] : [], $extra);
        return 'kartaKursanta.php?' . http_build_query($q) . $hash;
    }
}
