<?php
/**
 * modules/ti/view/portal/_style.php — wspólny wygląd stron logowania TI i konta kandydata w barwach Fundacji FEER
 * (jak formularze feer.org.pl: płaski niebieski #1d68f2 bez gradientów, tło #edf3fe, Ubuntu + Montserrat, białe karty).
 * Układ: nagłówek z logo i przyciskami „Załóż konto” / „Zaloguj”, menu, okruszki, dwie kolumny.
 * Czysty CSS z prefiksem pp- (działa obok Bootstrapa i Tailwinda); wstawiany do strony — na kursant.* katalog
 * /modules/ jest przepisywany do panelu kursanta, więc osobny plik CSS by nie doszedł.
 */
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@400;500;700&family=Montserrat:wght@600;700;800&display=swap">
<?php include __DIR__ . '/_a11yHead.php'; ?>
<style>
  :root { --pp-blue: #1d68f2; --pp-blue-dark: #164eb6; --pp-bg: #edf3fe; --pp-green: #3f9a2d; --pp-ink: #111827; --pp-line: #d8e2f3; --pp-muted: #5b6472; --pp-radius: 1rem; }
  body.pp { margin: 0; background: var(--pp-bg); color: var(--pp-ink); font-family: 'Ubuntu', ui-sans-serif, system-ui, sans-serif; font-size: 1rem; line-height: 1.55; }
  .pp h1, .pp h2, .pp h3, .pp-title { font-family: 'Montserrat', 'Ubuntu', sans-serif; }
  .pp a { color: var(--pp-blue); }
  .pp-wrap { max-width: 1140px; margin: 0 auto; padding: 0 1.25rem; }
  .pp-skip { position: absolute; left: -9999px; } .pp-skip:focus { left: 1rem; top: .5rem; background: #fff; padding: .4rem .8rem; z-index: 10; }
  .pp-util { display: flex; justify-content: flex-end; gap: 1.2rem; font-size: .85rem; padding: .6rem 0 0; }
  .pp-util a { color: var(--pp-muted); text-decoration: none; } .pp-util a:hover { color: var(--pp-blue); }
  .pp-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem 1.5rem; padding: 1rem 0 1.2rem; }
  .pp-brand { display: flex; align-items: center; gap: .9rem; text-decoration: none; color: var(--pp-ink); min-width: 0; }
  .pp-logo-box { display: inline-flex; align-items: center; flex: none; }
  .pp-brand img { height: 60px; width: auto; }   /* logo FEER w oryginalnych kolorach, bez tła */
  .pp-brand .pp-title-sep { width: 1px; align-self: stretch; background: var(--pp-line); margin: .2rem .2rem; }
  html.pp-contrast .pp-brand img { filter: brightness(0) invert(1); }   /* tryb kontrastu: logo białe na czarnym */
  .pp-mark { width: 52px; height: 52px; flex: none; border-radius: .8rem; background: var(--pp-blue); color: #fff; display: grid; place-items: center;
             font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.05rem; letter-spacing: .02em; }
  .pp-title { font-size: clamp(1.4rem, 3vw, 2rem); font-weight: 800; line-height: 1.15; }
  .pp-title small { display: block; font-family: 'Ubuntu', sans-serif; font-size: .5em; font-weight: 500; color: var(--pp-muted); margin-top: .2rem; }
  .pp-cta { display: flex; gap: .7rem; flex-wrap: wrap; }
  .pp-btn-acc, .pp-btn-log { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; min-height: 46px; padding: 0 1.4rem;
                              border-radius: .7rem; font-weight: 700; text-decoration: none !important; font-size: 1rem; }
  .pp-btn-acc { background: var(--pp-blue); color: #fff !important; border: 2px solid var(--pp-blue); }
  .pp-btn-acc:hover { background: var(--pp-blue-dark); border-color: var(--pp-blue-dark); }
  .pp-btn-log { background: #fff; color: var(--pp-blue) !important; border: 2px solid var(--pp-blue); }
  .pp-btn-log:hover { background: #f5f8ff; }
  .pp-nav { margin-bottom: .2rem; }
  .pp-menu { display: flex; flex-wrap: wrap; gap: .3rem; background: #fff; border-radius: var(--pp-radius); padding: .35rem; box-shadow: 0 4px 16px rgba(22, 78, 182, .06); }
  .pp-menu a { display: flex; align-items: center; gap: .4rem; padding: .55rem 1rem; border-radius: .7rem; text-decoration: none; font-weight: 500; color: var(--pp-ink); }
  .pp-menu a:hover { background: var(--pp-bg); color: var(--pp-blue); }
  .pp-menu a:first-child { color: var(--pp-blue); }
  .pp-crumb { font-size: .85rem; margin: 1.3rem 0 .2rem; color: var(--pp-muted); }
  .pp-crumb a { color: var(--pp-muted); } .pp-crumb span { color: var(--pp-ink); font-weight: 500; }
  #pp-main { background: #fff; border-radius: var(--pp-radius); box-shadow: 0 10px 30px rgba(22, 78, 182, .10); padding: 2rem; margin-top: .8rem; }
  .pp-h1 { color: var(--pp-ink); font-weight: 800; font-size: 1.7rem; margin: 0 0 1.2rem; }
  .pp-cols { display: grid; grid-template-columns: minmax(0, 7fr) minmax(0, 5fr); gap: 2rem 3.5rem; align-items: start; }
  .pp label, .pp .pp-label { display: block; font-weight: 500; font-size: .95rem; margin: 0 0 .4rem; }
  .pp input.pp-input, .pp .pp-input { width: 100%; box-sizing: border-box; border: 1px solid var(--pp-line); border-radius: .6rem; padding: .65rem .85rem; font: inherit; background: #fff; }
  .pp input.pp-input:focus { outline: 3px solid #b9d1fc; outline-offset: 0; border-color: var(--pp-blue); }
  .pp-field { margin-bottom: 1.3rem; }
  .pp-submit { display: inline-flex; align-items: center; justify-content: center; min-width: 180px; min-height: 44px; padding: .5rem 1.5rem; background: var(--pp-blue);
               color: #fff; border: 0; border-radius: .7rem; font: inherit; font-weight: 700; cursor: pointer; text-decoration: none; }
  .pp-submit:hover { background: var(--pp-blue-dark); color: #fff; }
  .pp-links { display: flex; flex-wrap: wrap; gap: .4rem 1.4rem; font-size: .9rem; margin-top: 1.3rem; }
  .pp-links a { text-decoration: none; } .pp-links a:hover { text-decoration: underline; }
  .pp-alt { display: flex; align-items: center; gap: .8rem; min-height: 64px; padding: 0 1.1rem; border: 2px solid var(--pp-line); border-radius: var(--pp-radius);
            font-size: 1.15rem; font-weight: 700; color: var(--pp-ink) !important; text-decoration: none !important; margin: .5rem 0 .6rem; background: #fff; }
  .pp-alt:hover { border-color: var(--pp-blue); background: #f8fbff; }
  .pp-alt .bi { font-size: 1.5rem; color: var(--pp-blue); }
  .pp-note { font-size: .9rem; line-height: 1.6; color: var(--pp-muted); }
  .pp-tabs { display: flex; flex-wrap: wrap; gap: .4rem; margin: 0 0 1.4rem; padding: .3rem; list-style: none; background: var(--pp-bg); border-radius: .9rem; }
  .pp-tabs a { display: block; padding: .5rem 1rem; border-radius: .7rem; text-decoration: none; color: var(--pp-ink); font-size: .95rem; font-weight: 500; }
  .pp-tabs a:hover { background: #fff; }
  .pp-tabs a[aria-current="page"] { background: var(--pp-blue); color: #fff; }
  .pp-error { border-radius: .7rem; background: #fdecee; color: #8a1024; padding: .75rem 1rem; margin-bottom: 1.2rem; }
  .pp-ok { border-radius: .7rem; background: #ecf7e9; color: #24601a; padding: .75rem 1rem; margin-bottom: 1.2rem; }
  .pp-foot-links { margin-top: 2.5rem; }
  .pp-foot-links .pp-wrap { display: flex; flex-wrap: wrap; justify-content: center; gap: .3rem 1.2rem; padding-top: 1rem; padding-bottom: .4rem; }
  .pp-foot-links a { font-size: .85rem; color: var(--pp-muted); text-decoration: none; } .pp-foot-links a:hover { color: var(--pp-blue); }
  .pp :focus-visible { outline: 3px solid #b9d1fc; outline-offset: 2px; }
  .pp .tz-tile, .pp .tz-tile:hover, .pp .tz-tile span { color: #fff !important; }   /* kafle wyboru konta prowadzącego — biały tekst na kolorze */
  .pp .tz-tile { border-radius: .8rem; }
  @media (max-width: 860px) {
    .pp-cols { grid-template-columns: 1fr; gap: 1.8rem; }
    #pp-main { padding: 1.25rem; }
    .pp-cta { width: 100%; } .pp-btn-acc, .pp-btn-log { flex: 1; }
    .pp-brand img { height: 44px; } .pp-mark { width: 42px; height: 42px; font-size: .9rem; }
  }
</style>
