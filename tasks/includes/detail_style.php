<style>
/* ══════════════════════════════════════════════════════════════
   Szczegóły zadania — offcanvas (fragment ładowany przez fetch())
   Zasady: żadnych inline width/margin; siatka przez CSS Grid/Flex

   UWAGA — CELOWO CSS ZWYKŁY, NIE Tailwind @apply: ten fragment jest
   wstrzykiwany do offcanvas przez fetch()+createContextualFragment() PO
   początkowym załadowaniu strony. Zweryfikowano empirycznie (2026-09-04),
   że silnik Tailwind CDN (cdn.tailwindcss.com) skanuje klasy/<style
   type="text/tailwindcss"> TYLKO RAZ przy starcie strony i NIE reaguje na
   późniejsze mutacje DOM (ani nowe klasy tw-*, ani nowe znaczniki <style
   type="text/tailwindcss"> dodane dynamicznie) — próbne wstrzyknięcie
   takiego znacznika/klasy do żywego DOM nie wygenerowało odpowiadającej
   reguły nawet po >2s. Dlatego ten plik zostaje zwykłym CSS (jak przed
   przebudową) — to świadomy wyjątek od reszty modułu, nie przeoczenie.
   Tokeny --c-* są LOKALNE dla #td-root, nie kolidują z --tsk-*/--tk-*
   stron, do których ten fragment jest wstrzykiwany.
   ══════════════════════════════════════════════════════════════ */

/* ── Tokeny ─────────────────────────────────────────────────── */
#td-root {
  --c-border   : #e5e9f0;
  --c-soft     : #f8fafc;
  --c-accent   : #2563eb;
  --c-text     : #0f172a;
  --c-muted    : #64748b;
  --c-radius   : .65rem;
  --c-radius-sm: .5rem;
  --c-shadow-xs: 0 1px 2px rgba(15,23,42,.05);
  --c-shadow-sm: 0 2px 8px rgba(15,23,42,.07);
  --c-shadow-md: 0 8px 24px rgba(15,23,42,.12);
  --c-section: .95rem 1.1rem;
  font-size: .875rem;
  color: var(--c-text);
  line-height: 1.6;
}

/* ── Reset offcanvas body padding ───────────────────────────── */
/* overflow-x:hidden dodane — Bootstrap .row ma ujemne marginesy (gutter),
   w wąskim offcanvasie to kilka px poza krawędź (niegroźna biała przestrzeń
   z gutter), co dawało niepotrzebny poziomy scrollbar. */
.offcanvas-body { padding: 0 !important; overflow-x: hidden; }

/* ── Sekcje ─────────────────────────────────────────────────── */
.td-section {
  padding: var(--c-section);
  border-bottom: 1px solid var(--c-border);
}
.td-section:last-child { border-bottom: none; }

.td-label {
  display: flex; align-items: center; gap: .35rem;
  font-size: .68rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .09em;
  color: var(--c-muted); margin-bottom: .5rem;
}
.td-label i { font-size: .72rem; }

.td-files-sub {
  font-size: .62rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .07em;
  color: var(--c-muted); margin-bottom: .35rem;
}

/* ── Nagłówek ───────────────────────────────────────────────── */
.td-header {
  padding: 1.05rem 1.1rem .8rem;
  border-bottom: 1px solid var(--c-border);
  background: linear-gradient(180deg, #ffffff 0%, #fbfcfe 100%);
}

.td-title-input {
  display: block; width: 100%;
  font-size: 1.05rem; font-weight: 700; line-height: 1.4;
  border: none; padding: .2rem .35rem;
  box-shadow: none !important; background: transparent;
  color: var(--c-text); border-radius: .4rem;
  transition: background .12s;
}
.td-title-input:hover { background: var(--c-soft); }
.td-title-input:focus { outline: 2px solid var(--c-accent); outline-offset: 0; background: #fff; }

.td-title-static {
  font-size: 1.05rem; font-weight: 700; line-height: 1.4;
  color: var(--c-text); padding: .2rem 0;
}

/* Status badges pod tytułem */
.td-status-row {
  display: flex; flex-wrap: wrap; gap: .4rem;
  align-items: center; margin: .6rem 0 .45rem;
}
.td-status-row .badge { border-radius: 2rem; font-weight: 600; padding: .32rem .65rem; }

/* ── Belka akcji ─────────────────────────────────────────────── */
.td-action-bar {
  display: flex; flex-wrap: wrap; align-items: center;
  gap: .35rem;
  padding-top: .6rem;
  border-top: 1px solid var(--c-border);
}

/* Przycisk akcji — jeden spójny komponent */
.td-ab-btn {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .32rem .75rem;
  border-radius: var(--c-radius-sm); border: 1.5px solid #e5e9f0;
  font-size: .78rem; font-weight: 600;
  background: #fff; color: #374151;
  cursor: pointer; text-decoration: none; white-space: nowrap;
  transition: transform .12s ease, box-shadow .12s ease, background .12s ease, border-color .12s ease, color .12s ease;
  line-height: 1.3;
  box-shadow: var(--c-shadow-xs);
}
.td-ab-btn i { font-size: .8rem; flex-shrink: 0; }
.td-ab-btn:focus-visible { outline: 2px solid var(--c-accent); outline-offset: 2px; }
.td-ab-btn:hover        { background: #f9fafb; border-color: #94a3b8; color: #0f172a; transform: translateY(-1px); box-shadow: var(--c-shadow-sm); }
.td-ab-btn:active       { transform: translateY(0); }

/* Warianty */
.td-ab-primary { background: linear-gradient(180deg,#22c55e,#16a34a); color: #fff; border-color: #16a34a; }
.td-ab-primary:hover { background: linear-gradient(180deg,#1ea34e,#15803d); border-color: #15803d; color: #fff; }

.td-ab-confirm { background: linear-gradient(180deg,#8b5cf6,#7c3aed); color: #fff; border-color: #7c3aed; }
.td-ab-confirm:hover { background: linear-gradient(180deg,#7c4fe0,#6d28d9); border-color: #6d28d9; color: #fff; }

.td-ab-danger { color: #dc2626; border-color: #fecaca; }
.td-ab-danger:hover { background: #fef2f2; border-color: #dc2626; }

/* Separator pionowy */
.td-ab-sep { width: 1px; height: 1.3rem; background: var(--c-border); flex-shrink: 0; margin: 0 .1rem; }

/* ── Siatka właściwości ─────────────────────────────────────── */
.td-props-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .55rem;
}

/* ── Przypisani ─────────────────────────────────────────────── */
#td-users-select { width: 100%; }

/* ── Tagi ────────────────────────────────────────────────────── */
.td-tags { display: flex; flex-wrap: wrap; gap: .35rem; }
.td-tag-btn {
  display: inline-flex; align-items: center; gap: .25rem;
  padding: .2rem .6rem; border-radius: 2rem;
  font-size: .73rem; font-weight: 700;
  border: 1.5px solid transparent;
  cursor: pointer; transition: opacity .12s, transform .12s ease;
}
.td-tag-btn:hover:not(:disabled) { transform: translateY(-1px); }
.td-tag-btn:focus-visible { outline: 2px solid var(--c-accent); outline-offset: 2px; }
.td-tag-btn:disabled { opacity: .55; cursor: not-allowed; }

/* ── Podzadania ─────────────────────────────────────────────── */
.td-st-row {
  display: flex; align-items: center; gap: .45rem;
  padding: .48rem .65rem;
  border-radius: var(--c-radius-sm); border: 1px solid var(--c-border);
  background: var(--c-soft); margin-bottom: .32rem;
  transition: background .12s, box-shadow .12s;
}
.td-st-row:hover { background: #f1f5f9; box-shadow: var(--c-shadow-xs); }
.td-st-done .td-st-title { opacity: .6; text-decoration: line-through; color: var(--c-muted); }

/* ── Pasek postępu ──────────────────────────────────────────── */
.td-progress-wrap { display: flex; align-items: center; gap: .5rem; margin-bottom: .5rem; }
.td-progress-track {
  flex: 1; height: 5px; background: #e2e8f0; border-radius: 3px; overflow: hidden;
}
.td-progress-fill { height: 100%; border-radius: 3px; transition: width .25s; }
.td-pct-label { font-size: .69rem; color: var(--c-muted); min-width: 2.6rem; text-align: right; }

/* ── Komentarze ─────────────────────────────────────────────── */
.td-comment {
  background: var(--c-soft); border: 1px solid var(--c-border);
  border-radius: var(--c-radius); padding: .65rem .85rem; margin-bottom: .5rem;
  transition: box-shadow .12s;
}
.td-comment:hover { box-shadow: var(--c-shadow-xs); }
.td-comment-meta { display: flex; justify-content: space-between; align-items: center; margin-bottom: .25rem; }
.td-comment-author { font-weight: 700; font-size: .79rem; }
.td-comment-date   { font-size: .69rem; color: var(--c-muted); }
.td-comment-body   { font-size: .84rem; white-space: pre-wrap; word-break: break-word; line-height: 1.55; }

/* ── Pliki ──────────────────────────────────────────────────── */
.td-drop-zone {
  border: 1.5px dashed #cbd5e1; border-radius: 8px; padding: .6rem .75rem;
  font-size: .78rem; color: #475569; text-align: center; transition: background .12s, border-color .12s;
}
.td-drop-zone .bi { margin-right: .25rem; }
.td-drop-zone.is-over { background: #eff6ff; border-color: #3b82f6; color: #1d4ed8; }
.td-file-row {
  display: flex; align-items: center; gap: .5rem;
  padding: .48rem .65rem;
  border-radius: var(--c-radius-sm); border: 1px solid var(--c-border);
  background: var(--c-soft); margin-bottom: .32rem;
  transition: box-shadow .12s;
}
.td-file-row:hover { box-shadow: var(--c-shadow-xs); }
.td-file-name {
  flex-grow: 1; min-width: 0;
  background: none; border: none; padding: 0;
  text-align: left; color: var(--c-link, #2563eb);
  text-decoration: none; cursor: pointer;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  font-size: .82rem;
}
.td-file-name:hover { text-decoration: underline; }
.td-file-dl {
  flex-shrink: 0; color: var(--c-muted);
  display: inline-flex; align-items: center;
  text-decoration: none; font-size: .9rem;
}
.td-file-dl:hover { color: var(--c-link, #2563eb); }

/* ── Podgląd plików (lightbox) ──────────────────────────────── */
.td-preview-overlay {
  position: fixed; inset: 0; z-index: 2000;
  background: rgba(15,23,42,.82);
  display: flex; flex-direction: column;
  padding: clamp(.5rem, 2vw, 2rem);
}
.td-preview-bar {
  display: flex; align-items: center; gap: .75rem;
  color: #fff; margin-bottom: .6rem; flex-shrink: 0;
}
.td-preview-title {
  flex-grow: 1; min-width: 0;
  font-size: .9rem; font-weight: 600;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.td-preview-bar a, .td-preview-bar button {
  flex-shrink: 0; color: #fff; background: rgba(255,255,255,.14);
  border: 1px solid rgba(255,255,255,.3); border-radius: .4rem;
  padding: .3rem .7rem; font-size: .8rem; cursor: pointer;
  text-decoration: none; display: inline-flex; align-items: center; gap: .35rem;
}
.td-preview-bar a:hover, .td-preview-bar button:hover { background: rgba(255,255,255,.28); color: #fff; }
.td-preview-body {
  flex-grow: 1; min-height: 0;
  display: flex; align-items: center; justify-content: center;
  overflow: auto; border-radius: .5rem; background: #fff;
}
.td-preview-body img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }
.td-preview-body iframe { width: 100%; height: 100%; border: 0; background: #fff; }
.td-preview-body pre {
  width: 100%; height: 100%; margin: 0; padding: 1rem;
  overflow: auto; font-size: .8rem; line-height: 1.5;
  white-space: pre-wrap; word-break: break-word; color: #0f172a;
}
.td-preview-fallback { text-align: center; color: var(--c-muted); padding: 2rem; }
.td-preview-fallback .bi { font-size: 3rem; display: block; margin-bottom: .75rem; color: #94a3b8; }

/* ── Historia ───────────────────────────────────────────────── */
.td-history-item {
  display: flex; gap: .55rem; align-items: flex-start;
  padding: .4rem 0; border-bottom: 1px solid #f1f5f9;
  font-size: .77rem; color: var(--c-muted);
}
.td-history-item:last-child { border-bottom: none; }
.td-hi-icon {
  width: 22px; height: 22px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: .7rem; flex-shrink: 0; margin-top: .1rem;
}
.td-hi-badge {
  display: inline-flex; align-items: center; gap: .2rem;
  font-size: .68rem; font-weight: 700;
  padding: .08rem .42rem; border-radius: 2rem; white-space: nowrap;
}
.td-hi-from {
  display: inline-block; background: #fef2f2; color: #dc2626;
  border-radius: .2rem; padding: 0 .3rem;
  font-size: .71rem; text-decoration: line-through;
}
.td-hi-to {
  display: inline-block; background: #f0fdf4; color: #16a34a;
  border-radius: .2rem; padding: 0 .3rem;
  font-size: .71rem; font-weight: 600;
}
.td-hi-val {
  display: inline-block; background: #f1f5f9; color: #475569;
  border-radius: .2rem; padding: 0 .3rem; font-size: .71rem;
  max-width: 160px; vertical-align: middle;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.td-hi-meta { color: #94a3b8; font-size: .69rem; margin-top: .1rem; }

/* ── Wzmianki ───────────────────────────────────────────────── */
.td-mention { background:#dbeafe;color:#1e40af;border-radius:3px;padding:0 3px;font-weight:500; }
.td-mention-dd {
  position:fixed;background:#fff;border:1px solid #d1d5db;border-radius:.5rem;
  box-shadow:0 6px 18px rgba(0,0,0,.15);z-index:10050;
  min-width:180px;max-height:200px;overflow-y:auto;display:none;padding:.25rem 0;
}
.td-mention-dd .mi-item {
  padding:.3rem .65rem;cursor:pointer;font-size:.82rem;
  display:flex;align-items:center;gap:.5rem;
}
.td-mention-dd .mi-item:hover,
.td-mention-dd .mi-item.mi-active { background:#eff6ff;color:#1e40af; }
.td-mention-av {
  display:inline-flex;align-items:center;justify-content:center;
  width:22px;height:22px;border-radius:50%;background:var(--c-accent);
  color:#fff;font-size:.62rem;font-weight:700;flex-shrink:0;
}

/* ── Picker Przekaż ─────────────────────────────────────────── */
.td-takeover-wrap { position: relative; }
.td-takeover-panel {
  display: none; position: absolute;
  top: calc(100% + 5px); left: 0;
  width: 290px; max-width: calc(100vw - 2rem);
  background: #fff; border: 1.5px solid #e2e8f0;
  border-radius: .6rem; box-shadow: 0 8px 24px rgba(0,0,0,.13);
  z-index: 9000; overflow: hidden;
}
.td-takeover-panel.open { display: block; }
.td-tp-head {
  padding: .6rem .8rem .45rem;
  border-bottom: 1px solid #f1f5f9; background: var(--c-soft);
}
.td-tp-title {
  font-size: .7rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .07em;
  color: #64748b; margin: 0 0 .3rem;
}
.td-tp-search {
  width: 100%; font-size: .82rem;
  border: 1px solid #e2e8f0; border-radius: .35rem;
  padding: .28rem .5rem; background: #fff;
}
.td-tp-search:focus { outline: 2px solid var(--c-accent); border-color: transparent; }
.td-tp-list { max-height: 220px; overflow-y: auto; }
.td-tp-group {
  padding: .28rem .8rem .1rem;
  font-size: .66rem; font-weight: 700;
  text-transform: uppercase; color: #94a3b8;
}
.td-tp-person {
  display: flex; align-items: center; gap: .55rem;
  padding: .42rem .8rem; cursor: pointer;
  border: none; background: none; width: 100%; text-align: left;
  transition: background .1s;
}
.td-tp-person:hover,.td-tp-person:focus-visible { background: #eff6ff; }
.td-tp-person:focus-visible { outline: 2px solid var(--c-accent); outline-offset: -2px; }
.td-tp-av {
  width: 26px; height: 26px; border-radius: 50%;
  background: var(--c-accent); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: .6rem; font-weight: 700; flex-shrink: 0;
}
.td-tp-info { flex: 1; min-width: 0; text-align: left; }
.td-tp-name { font-size: .83rem; font-weight: 600; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.td-tp-pos  { font-size: .7rem; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.td-tp-msg { padding: .45rem .8rem; border-top: 1px solid #f1f5f9; }
.td-tp-msg textarea {
  width: 100%; font-size: .8rem;
  border: 1px solid #e2e8f0; border-radius: .35rem;
  padding: .3rem .5rem; resize: none;
}
.td-tp-msg textarea:focus { outline: 2px solid var(--c-accent); border-color: transparent; }
.td-tp-empty { text-align: center; padding: 1rem; color: #94a3b8; font-size: .82rem; }
.td-tp-selected {
  background: #eff6ff; border-top: 1px solid #dbeafe;
  padding: .42rem .8rem;
  display: none; align-items: center; gap: .45rem;
  font-size: .81rem; color: #1d4ed8;
}
.td-tp-selected.show { display: flex; }

/* ── Fanfary po ukończeniu zadania ────────────────────────────── */
.td-confetti-layer { position: fixed; inset: 0; pointer-events: none; z-index: 99999; overflow: hidden; }
.td-confetti-piece {
  position: absolute; top: -12px; opacity: .95; border-radius: 1px;
  animation-name: tdConfettiFall;
  animation-timing-function: cubic-bezier(.15,.6,.4,1);
  animation-fill-mode: forwards;
}
@keyframes tdConfettiFall {
  0%   { transform: translate(0, 0) rotate(0deg); opacity: 1; }
  100% { transform: translate(var(--td-drift), 100vh) rotate(var(--td-rot)); opacity: 0; }
}
@media (prefers-reduced-motion: reduce) {
  .td-confetti-piece { animation: none; display: none; }
}
</style>
