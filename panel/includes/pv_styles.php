<?php
/**
 * panel/includes/pv_styles.php — Wspólny system stylów podstron panelu.
 *
 * Jedno źródło prawdy dla klas .pv-page-*, .pv-card, .vol-detail-*,
 * .vol-activity-* ORAZ komponentów w stylu modułu „Tożsamość" (.tz-card,
 * .tz-tile, .tz-svc, .tz-dl, .tz-badge, .tz-btn, .tz-note, .tz-empty,
 * .tz-section-h). Estetyka tz: promień 14px, obrys --tz-line, akcent
 * per-user --vol-color (NIE stały #1E6DFF), tokeny --tz-* na :root.
 *
 * Ładowany przez header_panel.php (powłoka wolontariusza) oraz przez
 * pv_ui.php (gdy strona renderuje się pod powłoką admina). Używa --vol-color
 * z fallbackiem, więc działa pod obiema powłokami.
 *
 * Dołączać przez require_once — emituje <style> tylko raz na żądanie.
 */
$_pv_accent = $_vol_color ?? '#1E6DFF';
$_pv_rgb = (function (string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = str_repeat($hex[0],2).str_repeat($hex[1],2).str_repeat($hex[2],2);
    return hexdec(substr($hex,0,2)).','.hexdec(substr($hex,2,2)).','.hexdec(substr($hex,4,2));
})($_pv_accent);
$_pv_dark = (function (string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = str_repeat($hex[0],2).str_repeat($hex[1],2).str_repeat($hex[2],2);
    return sprintf('#%02x%02x%02x',
        (int)(hexdec(substr($hex,0,2))*.70),
        (int)(hexdec(substr($hex,2,2))*.70),
        (int)(hexdec(substr($hex,4,2))*.70));
})($_pv_accent);
?>
<style>
/* ── System stylów podstron panelu (pv_styles) — estetyka „Tożsamość" ────── */
:root{
  --tz:var(--vol-color,<?= h($_pv_accent) ?>);
  --tz-strong:<?= h($_pv_dark) ?>;
  --tz-rgb:<?= h($_pv_rgb) ?>;
  --tz-50:rgba(var(--tz-rgb),.08);
  --tz-line:#E5E9F0;--tz-muted:#5b6472;--tz-ink:#111827;
  --tz-bg:#fff;--tz-bg-page:#F4F6F9;--tz-bg-sub:#F8FAFC;
}
@media(prefers-color-scheme:dark){
  :root{--tz-line:#1e2535;--tz-muted:#94a3b8;--tz-ink:#f1f5f9;--tz-bg:#0f172a;--tz-bg-page:#090e1a;--tz-bg-sub:#111827;--tz-50:rgba(var(--tz-rgb),.16)}
}
:root[data-theme="dark"]{--tz-line:#1e2535;--tz-muted:#94a3b8;--tz-ink:#f1f5f9;--tz-bg:#0f172a;--tz-bg-page:#090e1a;--tz-bg-sub:#111827;--tz-50:rgba(var(--tz-rgb),.16)}
:root[data-theme="light"]{--tz-line:#E5E9F0;--tz-muted:#5b6472;--tz-ink:#111827;--tz-bg:#fff;--tz-bg-page:#F4F6F9;--tz-bg-sub:#F8FAFC;--tz-50:rgba(var(--tz-rgb),.08)}
:root[data-theme="hc"]{--tz-line:#000;--tz-muted:#111;--tz-ink:#000;--tz-bg:#fff;--tz-bg-page:#fff;--tz-bg-sub:#f5f5f5;--tz-50:rgba(0,0,0,.1)}

/* Nagłówek strony */
.pv-page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.3rem}
.pv-page-head-main{min-width:0}
.pv-page-back{display:inline-flex;align-items:center;gap:.35rem;font-size:.8rem;color:var(--tz-muted);text-decoration:none;margin-bottom:.45rem}
.pv-page-back:hover,.pv-page-back:focus-visible{color:var(--tz-strong)}
.pv-page-title{font-size:1.5rem;font-weight:800;letter-spacing:-.01em;color:var(--tz-ink);margin:0 0 .1rem;line-height:1.2;display:flex;align-items:center;gap:.55rem}
.pv-page-title i{color:var(--tz);font-size:1.25rem}
.pv-page-sub{font-size:.9rem;color:var(--tz-muted);margin:0}
.pv-page-warmup{font-size:.87rem;font-weight:500;margin-top:.3rem;color:var(--tz)}
.pv-page-actions{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}

/* Widoczny fokus (WCAG 2.4.7) */
.pv-page-header a:focus-visible,.vol-detail-card a:focus-visible,
.tz-tile:focus-visible,.tz-btn:focus-visible,.tz-copy:focus-visible,
.list-group-item-action:focus-visible{outline:3px solid #FBBF24;outline-offset:2px}

/* ── Karty tz ────────────────────────────────────────────────────────────── */
.tz-card,.pv-card,.vol-detail-card{background:var(--tz-bg);border:1px solid var(--tz-line);border-radius:14px;overflow:hidden;margin-bottom:1.1rem;box-shadow:0 1px 3px rgba(16,24,40,.08)}
.tz-card__hd,.pv-card-hd,.vol-detail-header{display:flex;align-items:center;gap:.6rem;padding:.9rem 1.15rem;border-bottom:1px solid var(--tz-line);font-size:.95rem;font-weight:700;color:var(--tz-ink)}
.tz-card__hd i,.pv-card-hd i,.vol-detail-header i{color:var(--tz)}
.pv-card-hd .pv-card-hd-actions{margin-left:auto;display:flex;align-items:center;gap:.5rem}
.tz-card__bd,.pv-card-bd{padding:1.15rem}
.vol-detail-body{padding:.6rem 1rem}
.vol-detail-row{display:flex;justify-content:space-between;align-items:baseline;gap:.5rem;padding:.4rem 0;border-bottom:1px solid #F3F4F6;font-size:.88rem}
.vol-detail-row:last-child{border-bottom:none}
.vol-detail-row-lbl{color:var(--tz-muted);min-width:130px;flex-shrink:0;font-size:.8rem}
.vol-detail-row-val{color:var(--tz-ink);font-weight:600;text-align:right}

/* Nagłówki sekcji */
.tz-section-h{font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--tz-muted);margin:1.4rem 0 .6rem}
.tz-section-h:first-child{margin-top:0}

/* Siatka danych (definition list) */
.tz-dl{display:grid;grid-template-columns:1fr;margin:0}
@media(min-width:576px){.tz-dl{grid-template-columns:repeat(2,1fr)}}
@media(min-width:992px){.tz-dl{grid-template-columns:repeat(3,1fr)}}
.tz-dl>div{padding:.8rem 1.1rem;border-top:1px solid var(--tz-line)}
.tz-dl dt{font-size:.7rem;color:var(--tz-muted);margin:0;text-transform:uppercase;letter-spacing:.03em;font-weight:600}
.tz-dl dd{font-weight:600;margin:.2rem 0 0;font-size:.94rem;word-break:break-word;color:var(--tz-ink)}
.tz-yes{color:#047857}.tz-no{color:var(--tz-muted);font-weight:500}

/* Kafelki */
.tz-tiles{display:grid;gap:.85rem;grid-template-columns:repeat(auto-fill,minmax(180px,1fr))}
.tz-tile{position:relative;display:flex;flex-direction:column;gap:.15rem;background:var(--tz-bg);border:1px solid var(--tz-line);border-radius:14px;padding:1.05rem;text-decoration:none;color:inherit;min-height:112px;transition:transform .15s,border-color .15s,box-shadow .15s}
.tz-tile:hover,.tz-tile:focus-visible{transform:translateY(-2px);border-color:var(--tz);box-shadow:0 8px 24px -6px rgba(var(--tz-rgb),.28);color:inherit}
.tz-tile__ico{width:42px;height:42px;border-radius:11px;background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:.55rem}
.tz-tile__ttl{font-weight:700;font-size:.9rem;line-height:1.25;color:var(--tz-ink)}
.tz-tile__sub{font-size:.76rem;color:var(--tz-muted)}
.tz-tile__badge{position:absolute;top:.6rem;right:.6rem;min-width:20px;height:20px;padding:0 .35rem;border-radius:999px;background:#dc2626;color:#fff;font-size:.7rem;font-weight:700;display:flex;align-items:center;justify-content:center}

/* Usługi / dostępy */
.tz-svc{display:flex;align-items:flex-start;gap:.9rem;padding:.95rem 0;border-top:1px solid var(--tz-line)}
.tz-svc:first-child{border-top:0;padding-top:.3rem}
.tz-svc__ico{width:44px;height:44px;border-radius:12px;background:var(--tz-50);color:var(--tz-strong);display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
.tz-svc__bd{flex:1;min-width:0}
.tz-svc__ttl{font-weight:700;font-size:.95rem}
.tz-kv{font-size:.85rem;color:var(--tz-muted);margin-top:.2rem;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.tz-kv code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--tz-ink);background:var(--tz-50);padding:.1rem .4rem;border-radius:6px;font-size:.85rem;word-break:break-all}
.tz-copy{background:none;border:none;padding:.15rem .3rem;cursor:pointer;color:var(--tz-muted);border-radius:6px;line-height:1}
.tz-copy:hover{color:var(--tz);background:var(--tz-50)}
.tz-svc__foot{margin-top:.5rem}
.tz-svc__foot a{font-size:.83rem;color:var(--tz-strong);text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:.35rem}
.tz-svc__foot a:hover{text-decoration:underline}

/* Znaczniki (badge) */
.tz-badge{font-size:.72rem;font-weight:600;padding:.18rem .55rem;border-radius:999px;display:inline-flex;align-items:center;gap:.3rem;border:1px solid transparent}
.tz-badge--ok{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
.tz-badge--wait{background:#fff7ed;color:#c2410c;border-color:#fed7aa}
.tz-badge--off{background:#f3f4f6;color:#6b7280;border-color:#e5e7eb}
.vol-badge-yes{display:inline-flex;align-items:center;gap:.25rem;color:#047857;font-weight:600;font-size:.82rem}
.vol-badge-no{display:inline-flex;align-items:center;gap:.25rem;color:var(--tz-muted);font-size:.82rem}

/* Przyciski tz */
.tz-btn{background:var(--tz-strong);color:#fff;border:none;border-radius:10px;padding:.6rem 1.2rem;font-weight:600;display:inline-flex;align-items:center;gap:.45rem;text-decoration:none;cursor:pointer;min-height:44px}
.tz-btn:hover{filter:brightness(.94);color:#fff}
.tz-btn--ghost{background:var(--tz-bg);color:var(--tz-strong);border:1px solid var(--tz-line)}
.tz-btn--ghost:hover{background:var(--tz-50);filter:none}

/* Notka / informacja */
.tz-note,.pv-note{display:flex;gap:.55rem;align-items:flex-start;background:var(--tz-bg-page);border:1px solid var(--tz-line);border-radius:14px;padding:.85rem 1.1rem;font-size:.85rem;color:var(--tz-muted);margin-bottom:1.1rem}
.tz-note i,.pv-note i{color:var(--tz);margin-top:.1rem;flex-shrink:0;font-size:1.05rem}
.pv-note.pv-note-warn{background:#FFF7ED;border-color:#FED7AA}
.pv-note.pv-note-warn i{color:#C2410C}

/* Pusty stan */
.tz-empty{background:var(--tz-bg);border:2px dashed var(--tz-line);border-radius:14px;text-align:center;padding:2rem 1rem;color:var(--tz-muted)}
.tz-empty>i{font-size:2.5rem;opacity:.5;display:block;margin-bottom:.6rem}
.pv-empty{text-align:center;padding:2.75rem 1rem;color:var(--tz-muted)}
.pv-empty>i{font-size:2.5rem;opacity:.4;display:block;margin-bottom:.55rem}
.pv-empty-title{font-weight:700;color:#374151}
.pv-empty-sub{font-size:.85rem;margin-top:.3rem}

/* Wiersze aktywności / listy */
.vol-activity{background:var(--tz-bg);border:1px solid var(--tz-line);border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(16,24,40,.08)}
.vol-activity-header{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.85rem 1.1rem;border-bottom:1px solid var(--tz-line)}
.vol-activity-title{font-size:.95rem;font-weight:700;color:var(--tz-ink);margin:0}
.vol-activity-row{display:flex;align-items:center;gap:.7rem;padding:.7rem 1.1rem;border-bottom:1px solid #F3F4F6;font-size:.86rem}
.vol-activity-row:last-child{border-bottom:none}
.vol-activity-icon{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.pv-filter-chips{display:flex;gap:.35rem;flex-wrap:wrap;padding:.6rem 1.1rem;border-bottom:1px solid #F3F4F6}

/* Listy Bootstrap w palecie tz (list-group na podstronach) */
.pv-list.list-group{border-radius:14px;overflow:hidden;border:1px solid var(--tz-line)}
.pv-list .list-group-item{border-color:var(--tz-line);padding:.85rem 1.1rem;background:var(--tz-bg);color:var(--tz-ink)}
.pv-list .list-group-item-action:hover{background:var(--tz-50)}
.pv-list .list-group-item i.text-primary{color:var(--tz)!important}

/* Max-width kontener dla podstron */
.pv-wrap{max-width:960px;margin:0 auto}

/* Nagłówek strony + kontener */
.pv-page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.3rem}

/* Powiadomieniwy flash (Bootstrap alert w palecie tz) */
.pv-alert{border-radius:12px;padding:.85rem 1.1rem;font-size:.9rem;margin-bottom:1rem;display:flex;align-items:flex-start;gap:.6rem}
.pv-alert i{flex-shrink:0;margin-top:.1rem}
.pv-alert-ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
.pv-alert-err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.pv-alert-warn{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}

/* Tabela w palecie tz */
.pv-table{width:100%;border-collapse:collapse;font-size:.87rem}
.pv-table th{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--tz-muted);font-weight:600;padding:.55rem .9rem;border-bottom:2px solid var(--tz-line);background:var(--tz-bg);text-align:left;white-space:nowrap}
.pv-table td{padding:.65rem .9rem;border-bottom:1px solid var(--tz-line);vertical-align:middle;color:var(--tz-ink);background:var(--tz-bg)}
.pv-table tr:last-child td{border-bottom:none}
.pv-table tr:hover td{background:var(--tz-50)}
.pv-table-wrap{overflow-x:auto;border-radius:14px;border:1px solid var(--tz-line);margin-bottom:1.1rem}

/* Status pills */
.pv-status-pill{display:inline-flex;align-items:center;gap:.3rem;font-size:.76rem;font-weight:600;padding:.2rem .6rem;border-radius:999px;white-space:nowrap;border:1px solid transparent}
.pv-sp-new{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.pv-sp-pending{background:#fff7ed;color:#c2410c;border-color:#fed7aa}
.pv-sp-ok{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
.pv-sp-closed{background:#f3f4f6;color:#4b5563;border-color:#e5e7eb}
.pv-sp-draft{background:#f5f3ff;color:#6d28d9;border-color:#ddd6fe}
.pv-sp-cancelled{background:#fef2f2;color:#b91c1c;border-color:#fecaca}

/* Paski statystyk (pills) */
.pv-stats-bar{display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1.1rem}
.pv-stat-pill{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .7rem;border-radius:2rem;background:var(--tz-bg);border:1px solid var(--tz-line);font-size:.79rem;font-weight:500;color:#374151;box-shadow:0 1px 2px rgba(16,24,40,.05);text-decoration:none}
.pv-stat-pill:hover{border-color:var(--tz);color:var(--tz)}
.pv-stat-num{font-weight:700;color:var(--tz)}

/* Wysoki kontrast — wzmocnione obrysy, brak cieni */
[data-theme="hc"] .tz-card,[data-theme="hc"] .pv-card,[data-theme="hc"] .vol-detail-card{box-shadow:none;border-width:2px;border-color:#000}
[data-theme="hc"] .tz-tile{box-shadow:none;border-width:2px;border-color:#000}
[data-theme="hc"] .tz-tile:hover,[data-theme="hc"] .tz-tile:focus-visible{box-shadow:none;border-color:#000;background:#000;color:#fff}
[data-theme="hc"] .tz-tile:hover .tz-tile__ttl,[data-theme="hc"] .tz-tile:focus-visible .tz-tile__ttl,[data-theme="hc"] .tz-tile:hover .tz-tile__sub,[data-theme="hc"] .tz-tile:focus-visible .tz-tile__sub{color:#fff}
[data-theme="hc"] .tz-btn{border:2px solid #000}
[data-theme="hc"] .tz-btn--ghost{border:2px solid #000}
[data-theme="hc"] .tz-note,[data-theme="hc"] .pv-note{border-width:2px;border-color:#000}
[data-theme="hc"] .pv-table-wrap{border-width:2px;border-color:#000}
[data-theme="hc"] .pv-table th{border-bottom:3px solid #000}
[data-theme="hc"] .pv-table tr:hover td{background:rgba(0,0,0,.08)}
[data-theme="hc"] .tz-badge{border-width:2px}
[data-theme="hc"] .tz-badge--ok{background:#fff;color:#000;border-color:#000}
[data-theme="hc"] .tz-badge--wait{background:#fff;color:#000;border-color:#000}
[data-theme="hc"] .tz-badge--off{background:#f5f5f5;color:#000;border-color:#000}
[data-theme="hc"] .pv-status-pill{background:#fff;color:#000;border:2px solid #000}
[data-theme="hc"] .vol-activity,[data-theme="hc"] .pv-list.list-group{border-width:2px;border-color:#000}
[data-theme="hc"] .tz-empty{border-width:3px;border-color:#000}
[data-theme="hc"] .vol-activity-row{border-bottom-color:#000}
</style>
