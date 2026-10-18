<?php
/**
 * modules/ti/view/portal/_style.php — wygląd portalu TI w stylu METRO (jak dawne logowanie TI, czytelniejszy):
 * kwadratowe krawędzie, Segoe UI w większym rozmiarze, zakładki ról jako pivot (podkreślenie), kolorowe kafle,
 * ciemna kolumna informacyjna, płaskie przyciski, gruba żółta ramka fokusu. Tytuł: „Panel Kursanta SSO”.
 * Klasy pp- (oraz k-* konta kandydata) — działa obok Bootstrapa i Tailwinda; wstawiany do strony (na kursant.*
 * katalog /modules/ jest przepisywany do panelu kursanta, więc osobny plik CSS by nie doszedł).
 */
?>
<?php include __DIR__ . '/_a11yHead.php'; ?>
<style>
  :root { --pp-blue: #0f6fa8; --pp-blue-dark: #0c5b8a; --pp-dark: #1d1d1d; --pp-bg: #f3f4f6; --pp-green: #00a300; --pp-purple: #7e3878;
          --pp-ink: #1d1d1d; --pp-line: #c8ccd2; --pp-muted: #4b5563; --pp-focus: #facc15; --pp-radius: 0; }
  body.pp { margin: 0; background: var(--pp-bg); color: var(--pp-ink); font-family: "Segoe UI", "Selawik", system-ui, -apple-system, Roboto, sans-serif;
            font-size: 1.0625rem; line-height: 1.6; }
  .pp h1, .pp h2, .pp h3, .pp-title { font-family: inherit; }
  .pp a { color: #0b5f91; }
  .pp-wrap { max-width: 1120px; margin: 0 auto; padding: 0 1.25rem; }
  .pp-skip { position: absolute; left: -9999px; } .pp-skip:focus { left: 1rem; top: .5rem; background: var(--pp-focus); color: #000; padding: .5rem .9rem; z-index: 20; font-weight: 700; }

  /* Pasek górny */
  .pp-top { background: #fff; border-bottom: 1px solid #dde1e6; }
  .pp-top-in { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .6rem 1rem; min-height: 68px; }
  .pp-brand { display: flex; align-items: center; gap: .8rem; text-decoration: none; color: var(--pp-ink) !important; }
  .pp-brand img { height: 40px; width: auto; }
  .pp-title-sep { width: 1px; height: 30px; background: var(--pp-line); }
  .pp-mark { width: 40px; height: 40px; background: var(--pp-blue); color: #fff; display: grid; place-items: center; font-weight: 700; font-size: .8rem; }
  .pp-title { font-size: 1.2rem; font-weight: 600; }
  .pp-top-nav { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; }
  .pp-top-link { display: inline-flex; align-items: center; gap: .35rem; padding: .5rem .7rem; color: var(--pp-ink) !important; text-decoration: none; }
  .pp-top-link:hover { background: var(--pp-bg); }
  .pp-top-form { margin: 0; display: flex; }
  .pp-btn-acc, .pp-btn-log { display: inline-flex; align-items: center; justify-content: center; gap: .4rem; min-height: 44px; padding: 0 1.1rem;
                              border-radius: 0; font: inherit; font-weight: 600; text-decoration: none !important; cursor: pointer; }
  .pp-btn-acc { background: var(--pp-blue); color: #fff !important; border: 2px solid var(--pp-blue); }
  .pp-btn-acc:hover { background: var(--pp-blue-dark); border-color: var(--pp-blue-dark); }
  .pp-btn-log { background: #fff; color: var(--pp-ink) !important; border: 2px solid var(--pp-ink); }
  .pp-btn-log:hover { background: var(--pp-bg); }

  /* Ułatwienia dostępu — rozwijane z paska */
  .pp-a11y-menu { position: relative; }
  .pp-a11y-menu > summary { list-style: none; display: inline-flex; align-items: center; gap: .35rem; padding: .5rem .7rem; cursor: pointer; min-height: 44px; }
  .pp-a11y-menu > summary::-webkit-details-marker { display: none; }
  .pp-a11y-menu > summary:hover, .pp-a11y-menu[open] > summary { background: var(--pp-bg); }
  .pp-a11y-pop { position: absolute; right: 0; top: 100%; z-index: 30; background: #fff; border: 2px solid var(--pp-ink); padding: .8rem; width: min(92vw, 360px); }
  .pp-a11y-pop .pp-a11y { flex-direction: column; align-items: stretch; justify-content: flex-start; padding: 0; gap: .35rem; font-size: 1rem; }
  .pp-a11y-pop .pp-a11y-sep { display: none; }
  .pp-a11y-pop .pp-a11y button { border-radius: 0; text-align: left; border: 1px solid var(--pp-line); padding: .5rem .7rem; min-height: 44px; }
  .pp-a11y-pop .pp-a11y-sizes { display: flex; gap: .35rem; } .pp-a11y-pop .pp-a11y-sizes button { flex: 1; text-align: center; }
  .pp-a11y-pop .pp-a11y button[aria-pressed="true"] { background: var(--pp-blue); border-color: var(--pp-blue); color: #fff; }

  /* Treść */
  .pp-crumb { display: none; }
  #pp-main { background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.08); padding: 2.25rem 2.25rem 2.5rem; margin-top: 1.75rem; }
  .pp-h1 { font-size: 1.85rem; font-weight: 600; margin: 0 0 1.1rem; color: var(--pp-ink); }
  .pp-cols { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr); gap: 2rem; align-items: stretch; }
  .pp-cols > aside { background: var(--pp-dark); color: #e5e7eb; padding: 1.6rem 1.5rem; }
  .pp-cols > aside, .pp-cols > aside .pp-note, .pp-cols > aside p { color: #e5e7eb !important; }
  .pp-cols > aside strong { color: #fff !important; }
  .pp-cols > aside a:not(.pp-alt) { color: #7dd3fc !important; }
  .pp-cols > aside div[style*="border-top"] { border-top-color: #4b5563 !important; }

  .pp label, .pp .pp-label { display: block; font-weight: 600; font-size: 1rem; margin: 0 0 .4rem; }
  .pp input.pp-input, .pp .pp-input, .pp .form-control { width: 100%; box-sizing: border-box; border: 2px solid #8a9099; border-radius: 0 !important; padding: .7rem .85rem;
                                                         font: inherit; font-size: 1.05rem; background: #fff; box-shadow: none; }
  .pp input.pp-input:focus, .pp .form-control:focus { border-color: var(--pp-blue); }
  .pp-field { margin-bottom: 1.35rem; }
  .pp-submit, .pp .tz-btn { display: inline-flex; align-items: center; justify-content: center; gap: .45rem; min-width: 200px; min-height: 48px; padding: .55rem 1.6rem;
                            background: var(--pp-blue); color: #fff !important; border: 0; border-radius: 0; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
  .pp-submit:hover, .pp .tz-btn:hover { background: var(--pp-blue-dark); }
  .pp .tz-btn-ghost { background: #fff; color: var(--pp-ink) !important; border: 2px solid var(--pp-ink); }
  .pp-links { display: flex; flex-wrap: wrap; gap: .5rem 1.5rem; margin-top: 1.25rem; }
  .pp-note { font-size: .98rem; line-height: 1.6; color: var(--pp-muted); }
  .pp .tz-hint { font-size: .98rem; color: var(--pp-muted); }

  /* Zakładki ról — pivot METRO */
  .pp-tabs { display: flex; flex-wrap: wrap; gap: 0 1.6rem; list-style: none; padding: 0; margin: 0 0 1.6rem; border-bottom: 1px solid #dde1e6; background: none; }
  .pp-tabs a { display: block; padding: .55rem 0 .65rem; border-bottom: 4px solid transparent; margin-bottom: -1px; text-decoration: none; color: var(--pp-muted) !important;
               font-size: 1.12rem; font-weight: 400; }
  .pp-tabs a:hover { color: var(--pp-ink) !important; }
  .pp-tabs a[aria-current="page"] { color: var(--pp-ink) !important; font-weight: 600; border-bottom-color: var(--pp-blue); }

  /* Kafle (inne sposoby logowania, skróty) */
  .pp-alt { display: flex; align-items: center; gap: .75rem; min-height: 76px; padding: .8rem 1.1rem; margin: .55rem 0 .6rem; border: 0; border-radius: 0;
            background: var(--pp-blue); color: #fff !important; font-size: 1.12rem; font-weight: 600; text-decoration: none !important; }
  .pp-alt:hover { filter: brightness(.92); }
  .pp-alt .bi { font-size: 1.6rem; color: #fff; }
  aside .pp-alt:nth-of-type(2) { background: var(--pp-green); }
  aside .pp-alt:nth-of-type(3) { background: var(--pp-purple); }

  .pp-error { border-left: 5px solid #b91c1c; background: #fdecec; color: #7f1d1d; padding: .8rem 1rem; margin-bottom: 1.2rem; }
  .pp-ok { border-left: 5px solid #15803d; background: #e8f5ec; color: #14532d; padding: .8rem 1rem; margin-bottom: 1.2rem; }
  .pp-ok { border-left: 5px solid #15803d; background: #ecf8f0; color: #14532d; padding: .8rem 1rem; margin-bottom: 1.2rem; }

  .pp-foot-links { margin-top: 2rem; }
  .pp-foot-links .pp-wrap { display: flex; flex-wrap: wrap; justify-content: center; gap: .3rem 1.4rem; padding-top: .9rem; padding-bottom: .3rem; }
  .pp-foot-links a { font-size: .92rem; color: var(--pp-muted); text-decoration: none; } .pp-foot-links a:hover { text-decoration: underline; color: var(--pp-ink); }

  /* Konto kandydata (Tailwind k-*) i kafle prowadzącego — w METRO */
  .pp .k-card { border-radius: 0 !important; box-shadow: none !important; border: 1px solid #dde1e6 !important; background: #fff; }
  .pp .k-btn { border-radius: 0 !important; background: var(--pp-blue) !important; color: #fff !important; font-weight: 600; min-height: 44px; }
  .pp .k-btn:hover { background: var(--pp-blue-dark) !important; }
  .pp .k-btn-ghost { border-radius: 0 !important; background: #fff !important; color: var(--pp-ink) !important; border: 2px solid var(--pp-ink) !important; min-height: 44px; }
  .pp .k-input { border-radius: 0 !important; border: 2px solid #8a9099 !important; font-size: 1.05rem; padding: .65rem .8rem; }
  .pp .k-badge { border-radius: 0 !important; }
  .pp .tw-rounded-xl, .pp .tw-rounded-2xl, .pp .tw-rounded-lg, .pp .k-badge { border-radius: 0 !important; }
  .pp .tw-shadow-lg, .pp .tw-shadow { box-shadow: 0 2px 10px rgba(0,0,0,.08) !important; }
  /* Formularz zapisów (zp-*) */
  .pp .zp-input, .pp .zp-pick, .pp .zp-btn-primary, .pp .zp-btn-ghost { border-radius: 0 !important; }
  .pp .zp-input { border: 2px solid #8a9099 !important; font-size: 1.05rem; }
  .pp .zp-btn-primary { background: var(--pp-blue) !important; color: #fff !important; min-height: 48px; font-weight: 600; }
  .pp .zp-btn-primary:hover { background: var(--pp-blue-dark) !important; }
  .pp .zp-btn-ghost { border: 2px solid var(--pp-ink) !important; color: var(--pp-ink) !important; min-height: 44px; }
  .pp .zp-pick-on { border-color: var(--pp-blue) !important; box-shadow: inset 0 0 0 2px var(--pp-blue) !important; }
  .pp .zp-label { font-weight: 600; font-size: 1rem; }
  .pp .tz-tile, .pp .tz-tile:hover, .pp .tz-tile span { color: #fff !important; }
  .pp .tz-tile { border-radius: 0; }

  .pp :focus-visible { outline: 3px solid var(--pp-focus) !important; outline-offset: 2px !important; box-shadow: none !important; }
  @media (max-width: 860px) {
    .pp-cols { grid-template-columns: 1fr; gap: 1.5rem; }
    #pp-main { padding: 1.25rem; margin-top: 1rem; }
    .pp-top-nav { width: 100%; } .pp-top-link span, .pp-a11y-menu summary span { display: none; }
    .pp-title { font-size: 1.05rem; } .pp-brand img { height: 32px; }
    .pp-tabs { gap: 0 1rem; } .pp-tabs a { font-size: 1rem; }
  }
</style>
