<?php
/**
 * komunikaty/_shell.php — powłoka i język wizualny modułu „Komunikaty".
 *
 * Wzorzec jak w /resources/ i /panel/: strona renderuje się pod powłoką panelu
 * wolontariusza dla samych wolontariuszy, a pod powłoką SZO dla edytorów/adminów
 * (edytorzy i admini potrzebują pełnej nawigacji). Treść stron używa komponentów
 * modułu „Tożsamość" (pv_ui.php / pv_styles.php: .tz-card, .tz-badge, .tz-btn,
 * .tz-note, .tz-empty, .pv-table), a poniżej dokładamy tylko to, czego tam nie ma:
 * kartę ogłoszenia (.kom-ann) i pigułki filtra kategorii (.kom-chip).
 *
 * Wymaga: załadowanego config/db/auth/functions + wykonanego require_login().
 * Dołączać PO całej logice POST/redirect — plik zaczyna wypisywać HTML.
 */

$_kom_uid = (int)(current_user()['id'] ?? 0);

// Wolontariusz „czysty" (viewer bez roli konsultanta TI) → powłoka panelu.
$_is_volunteer_only = is_viewer()
    && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [$_kom_uid]);

if ($_is_volunteer_only) {
    include dirname(__DIR__) . '/panel/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
require_once dirname(__DIR__) . '/panel/includes/pv_ui.php';
pv_ui_styles();
?>
<style>
/* ── Karta ogłoszenia ─────────────────────────────────────────────────────── */
.kom-ann{--kom-accent:var(--tz-line);border-left:3px solid var(--kom-accent)}
.kom-ann--new{background:var(--tz-bg-sub)}
.kom-ann__top{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.6rem}
.kom-ann__meta{display:flex;align-items:center;gap:.35rem;flex-wrap:wrap}
.kom-ann__date{font-size:.75rem;color:var(--tz-muted);white-space:nowrap}
.kom-ann__ttl{font-size:1rem;font-weight:700;color:var(--tz-ink);margin:0 0 .5rem;line-height:1.3}
.kom-ann__ttl a{color:inherit;text-decoration:none}
.kom-ann__ttl a:hover,.kom-ann__ttl a:focus-visible{text-decoration:underline}
.kom-body{font-size:.89rem;color:var(--tz-ink);line-height:1.65;white-space:pre-wrap;word-break:break-word}
.kom-body--clip{position:relative;overflow:hidden;max-height:5.5rem;transition:max-height .25s ease}
.kom-body--clip::after{content:'';position:absolute;inset:auto 0 0 0;height:2.2rem;
  background:linear-gradient(transparent,var(--kom-fade,var(--tz-bg)));pointer-events:none}
.kom-body--clip.is-open{max-height:none}
.kom-body--clip.is-open::after{display:none}
.kom-more{background:none;border:none;padding:.15rem 0;margin-top:.35rem;font-size:.82rem;font-weight:600;
  color:var(--tz-strong);display:inline-flex;align-items:center;gap:.3rem;cursor:pointer}
.kom-more:hover{text-decoration:underline}
.kom-ann__ft{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap}
.kom-ann__author{font-size:.8rem;color:var(--tz-muted);display:inline-flex;align-items:center;gap:.35rem}
.kom-ann__acts{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.kom-btn-sm{padding:.32rem .75rem;min-height:34px;font-size:.8rem;border-radius:8px}

/* ── Filtr kategorii ──────────────────────────────────────────────────────── */
.kom-chips{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1.1rem}
.kom-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.32rem .75rem;border-radius:2rem;
  background:var(--tz-bg);border:1px solid var(--tz-line);font-size:.79rem;font-weight:500;
  color:var(--tz-ink);text-decoration:none;box-shadow:0 1px 2px rgba(16,24,40,.05)}
.kom-chip:hover,.kom-chip:focus-visible{border-color:var(--kom-accent,var(--tz));color:var(--tz-ink)}
.kom-chip__dot{width:8px;height:8px;border-radius:50%;background:var(--kom-accent,var(--tz-muted));flex-shrink:0}
.kom-chip[aria-current="page"]{background:var(--kom-accent,var(--tz));border-color:var(--kom-accent,var(--tz));color:#fff;font-weight:600}
.kom-chip[aria-current="page"] .kom-chip__dot{background:rgba(255,255,255,.85)}

/* ── Feed powiadomień ─────────────────────────────────────────────────────── */
.kom-notif{max-height:520px;overflow-y:auto}
.kom-notif-row{display:flex;align-items:center;gap:.7rem;padding:.7rem 1.1rem;
  border-bottom:1px solid var(--tz-line);font-size:.86rem;color:var(--tz-ink);text-decoration:none}
.kom-notif-row:last-child{border-bottom:none}
.kom-notif-row:hover{background:var(--tz-50);color:var(--tz-ink)}
.kom-notif-row--new{background:var(--tz-50);font-weight:600}
.kom-notif-ico{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.kom-notif-date{font-size:.73rem;color:var(--tz-muted);font-weight:400}
.kom-notif-dot{width:7px;height:7px;border-radius:50%;background:var(--tz);flex-shrink:0}

/* ── Podgląd/edytor w compose ─────────────────────────────────────────────── */
.kom-form .form-label{font-size:.82rem;font-weight:600;color:var(--tz-ink);margin-bottom:.3rem}
.kom-form .form-control,.kom-form .form-select{border-radius:10px;border-color:var(--tz-line);font-size:.9rem}
.kom-form .form-control:focus,.kom-form .form-select:focus{border-color:var(--tz);box-shadow:0 0 0 .2rem var(--tz-50)}
.kom-form .form-text{font-size:.78rem;color:var(--tz-muted)}
.kom-cnt{font-variant-numeric:tabular-nums}

[data-theme="hc"] .kom-ann{border-left-width:5px}
[data-theme="hc"] .kom-chip{border-width:2px;border-color:#000}
[data-theme="hc"] .kom-chip[aria-current="page"]{background:#000;border-color:#000;color:#fff}
[data-theme="hc"] .kom-notif-row{border-bottom-color:#000}
</style>
