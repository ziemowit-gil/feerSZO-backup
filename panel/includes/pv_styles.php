<?php
/**
 * panel/includes/pv_styles.php — Wspólny system stylów podstron panelu.
 *
 * Jedno źródło prawdy dla klas .pv-page-*, .pv-card, .vol-detail-*,
 * .vol-activity-*, .pv-stat-* używanych na podstronach panelu.
 *
 * Ładowany przez header_panel.php (powłoka wolontariusza) oraz przez
 * pv_ui.php (gdy strona renderuje się pod powłoką admina). Używa zmiennej
 * --vol-color / --vol-bg z fallbackiem, więc działa pod obiema powłokami.
 *
 * Dołączać przez require_once — emituje <style> tylko raz na żądanie.
 */
?>
<style>
/* ── System stylów podstron panelu (pv_styles) ───────────────────────────── */

/* Nagłówek strony */
.pv-page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.4rem}
.pv-page-head-main{min-width:0}
.pv-page-back{display:inline-flex;align-items:center;gap:.35rem;font-size:.8rem;color:#6B7280;text-decoration:none;margin-bottom:.45rem}
.pv-page-back:hover{color:var(--vol-color,#1D4ED8)}
.pv-page-title{font-size:1.2rem;font-weight:800;color:#111827;margin:0 0 .15rem;line-height:1.3;display:flex;align-items:center;gap:.55rem}
.pv-page-title i{color:var(--vol-color,#1D4ED8);font-size:1.15rem}
.pv-page-sub{font-size:.84rem;color:#4B5563;margin:0}
.pv-page-warmup{font-size:.87rem;font-weight:500;margin-top:.3rem;color:var(--vol-color,#1D4ED8)}
.pv-page-actions{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}

/* Karta uniwersalna */
.pv-card{background:#fff;border:1px solid #EEF0F2;border-radius:12px;overflow:hidden;margin-bottom:1rem;box-shadow:0 1px 6px rgba(0,0,0,.06)}
.pv-card-hd{display:flex;align-items:center;gap:.45rem;padding:.8rem 1.05rem;border-bottom:1px solid #F3F4F6;font-size:.9rem;font-weight:700;color:#374151}
.pv-card-hd i{color:var(--vol-color,#1D4ED8)}
.pv-card-hd .pv-card-hd-actions{margin-left:auto;display:flex;align-items:center;gap:.5rem}
.pv-card-bd{padding:1rem 1.05rem}

/* Notka / informacja */
.pv-note{display:flex;gap:.6rem;align-items:flex-start;background:var(--vol-bg,#EFF4FF);border:1px solid rgba(0,0,0,.06);border-radius:10px;padding:.7rem .9rem;font-size:.86rem;color:#374151;margin-bottom:1rem}
.pv-note i{color:var(--vol-color,#1D4ED8);margin-top:.1rem;flex-shrink:0;font-size:1rem}
.pv-note.pv-note-warn{background:#FFF7ED;border-color:#FED7AA}
.pv-note.pv-note-warn i{color:#C2410C}

/* Pusty stan */
.pv-empty{text-align:center;padding:2.75rem 1rem;color:#6B7280}
.pv-empty>i{font-size:2.5rem;opacity:.35;display:block;margin-bottom:.55rem}
.pv-empty-title{font-weight:600;color:#374151}
.pv-empty-sub{font-size:.85rem;margin-top:.3rem}

/* Karty szczegółów (definition cards) */
.vol-detail-card{background:#fff;border-radius:12px;overflow:hidden;margin-bottom:1rem;box-shadow:0 1px 6px rgba(0,0,0,.06)}
.vol-detail-header{display:flex;align-items:center;gap:.4rem;padding:.75rem 1rem;border-bottom:1px solid #F3F4F6;font-size:.85rem;font-weight:700;color:#374151}
.vol-detail-header i{color:var(--vol-color,#1D4ED8)}
.vol-detail-body{padding:.6rem 1rem}
.vol-detail-row{display:flex;justify-content:space-between;align-items:baseline;gap:.5rem;padding:.38rem 0;border-bottom:1px solid #F9FAFB;font-size:.86rem}
.vol-detail-row:last-child{border-bottom:none}
.vol-detail-row-lbl{color:#6B7280;min-width:130px;flex-shrink:0;font-size:.8rem}
.vol-detail-row-val{color:#111827;font-weight:500;text-align:right}

/* Wiersze aktywności / listy */
.vol-activity{background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.05)}
.vol-activity-header{display:flex;align-items:center;justify-content:space-between;padding:.75rem 1rem;border-bottom:1px solid #F3F4F6}
.vol-activity-title{font-size:.83rem;font-weight:700;color:#374151}
.vol-activity-row{display:flex;align-items:center;gap:.65rem;padding:.55rem 1rem;border-bottom:1px solid #F9FAFB;font-size:.83rem}
.vol-activity-row:last-child{border-bottom:none}
.vol-activity-icon{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0}

/* Znaczniki tak/nie */
.vol-badge-yes{display:inline-flex;align-items:center;gap:.25rem;color:#15803D;font-weight:600;font-size:.82rem}
.vol-badge-no{display:inline-flex;align-items:center;gap:.25rem;color:#6B7280;font-size:.82rem}

/* Paski statystyk (pills) */
.pv-stats-bar{display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1.1rem}
.pv-stat-pill{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .7rem;border-radius:2rem;background:#fff;border:1px solid #E5E7EB;font-size:.79rem;font-weight:500;color:#374151;box-shadow:0 1px 3px rgba(0,0,0,.05);text-decoration:none}
.pv-stat-pill:hover{border-color:var(--vol-color,#1D4ED8);color:var(--vol-color,#1D4ED8)}
.pv-stat-num{font-weight:700;color:var(--vol-color,#1D4ED8)}
</style>
