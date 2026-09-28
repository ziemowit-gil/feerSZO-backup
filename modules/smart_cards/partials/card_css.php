<?php
/**
 * modules/smart_cards/partials/card_css.php — wygląd karty (.sc-card) w czystym CSS
 * + pomocnicze funkcje JS podglądu. Wspólny dla stron modułu (przez ui.php)
 * i Programatora NFC, który nie ładuje nagłówka SZO ani Tailwinda.
 */
?>
<style>
/* ── Karta w stylu płatniczym (ISO/IEC 7810 ID-1: 85,6 × 53,98 mm) ─────────── */
.sc-card {
  position: relative; width: 100%; max-width: 380px; aspect-ratio: 85.6 / 53.98;
  border-radius: 16px; overflow: hidden; isolation: isolate; box-sizing: border-box;
  padding: 6.5% 7%; display: flex; flex-direction: column; justify-content: space-between;
  color: var(--sc-text, #fff); font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
  background: linear-gradient(var(--sc-angle, 135deg), var(--sc-from, #1e3a8a), var(--sc-to, #0f172a));
  box-shadow: 0 18px 40px -18px rgba(15,23,42,.65), inset 0 1px 0 rgba(255,255,255,.18);
  container-type: inline-size;
}
.sc-card::before { content: ''; position: absolute; inset: 0; z-index: -1; opacity: .22; pointer-events: none; }
.sc-card::after  { content: ''; position: absolute; inset: 0; z-index: -1; pointer-events: none;
  background: linear-gradient(115deg, rgba(255,255,255,.22) 0%, rgba(255,255,255,0) 38%); }
.sc-card.pat-waves::before   { background: repeating-radial-gradient(circle at 110% 120%, transparent 0 14px, var(--sc-accent) 14px 15px); }
.sc-card.pat-grid::before    { background-image: linear-gradient(var(--sc-accent) 1px, transparent 1px), linear-gradient(90deg, var(--sc-accent) 1px, transparent 1px); background-size: 18px 18px; opacity: .14; }
.sc-card.pat-circles::before { background: radial-gradient(circle at 85% 15%, var(--sc-accent) 0 22%, transparent 22.5%), radial-gradient(circle at 100% 90%, var(--sc-accent) 0 30%, transparent 30.5%); opacity: .28; }
.sc-card.pat-lines::before   { background: repeating-linear-gradient(-35deg, transparent 0 10px, var(--sc-accent) 10px 11px); opacity: .16; }
.sc-card__top { display: flex; align-items: center; justify-content: space-between; }
.sc-card__label { font-size: 4.2cqw; font-weight: 800; letter-spacing: .14em; }
.sc-card__nfc { width: 7.5cqw; height: 7.5cqw; opacity: .9; transform: rotate(0deg); }
.sc-card__chip { width: 14cqw; aspect-ratio: 1.3; border-radius: 1.6cqw; position: relative; overflow: hidden;
  background: linear-gradient(135deg, #f8e7a1, #d4a93c 45%, #f3d98b 60%, #b8892a); box-shadow: inset 0 0 0 1px rgba(0,0,0,.25); }
.sc-card.chip-silver .sc-card__chip { background: linear-gradient(135deg, #f1f5f9, #94a3b8 45%, #e2e8f0 60%, #64748b); }
.sc-card__chip span, .sc-card__chip::before, .sc-card__chip::after { content: ''; position: absolute; border: 1px solid rgba(0,0,0,.28); }
.sc-card__chip::before { left: 33%; right: 33%; top: -1px; bottom: -1px; border-top: 0; border-bottom: 0; }
.sc-card__chip::after  { top: 33%; bottom: 33%; left: -1px; right: -1px; border-left: 0; border-right: 0; }
.sc-card__chip span    { left: 33%; right: 33%; top: 33%; bottom: 33%; border-radius: 1cqw; }
.sc-card__uid { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; font-size: 6.4cqw; letter-spacing: .08em; text-shadow: 0 1px 1px rgba(0,0,0,.35); white-space: nowrap; }
.sc-card__bottom { display: flex; justify-content: space-between; align-items: flex-end; gap: 4cqw; }
.sc-card__cap { display: block; font-size: 2.6cqw; letter-spacing: .12em; text-transform: uppercase; opacity: .75; }
.sc-card__holder { display: block; font-size: 4.3cqw; font-weight: 700; letter-spacing: .06em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 62cqw; }
.sc-card__exp { display: block; font-family: ui-monospace, Menlo, monospace; font-size: 4.3cqw; font-weight: 700; }
.sc-card.is-inactive { filter: grayscale(.75); }
.sc-card__ribbon { position: absolute; top: 44%; left: -10%; right: -10%; transform: rotate(-12deg); text-align: center;
  background: rgba(185,28,28,.92); color: #fff; font-weight: 800; letter-spacing: .3em; font-size: 4.5cqw; padding: 1.2cqw 0; }
.sc-card.is-sm { max-width: 150px; border-radius: 8px; }
</style>
<script>
/* Zmienne wyglądu karty dla podglądu w Alpine — lustrzane do scard_vars() w PHP. */
window.scardVars = d => `--sc-from:${d.bg_from};--sc-to:${d.bg_to};--sc-angle:${+d.angle || 0}deg;--sc-text:${d.text};--sc-accent:${d.accent}`;
window.scardUid  = u => (u || '').replace(/:/g, '').replace(/(.{4})/g, '$1 ').trim();
</script>
