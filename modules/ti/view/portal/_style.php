<?php
/**
 * modules/ti/view/portal/_style.php — wspólny wygląd stron logowania TI i konta kandydata (wzór: portal urzędowy:
 * niebieski nagłówek z dużym tytułem, przyciski ZAŁÓŻ KONTO / ZALOGUJ, pasek nawigacji, okruszki, dwie kolumny).
 * Czysty CSS z prefiksem pp- (działa obok Bootstrapa i Tailwinda); wstawiany do strony — na kursant.* katalog
 * /modules/ jest przepisywany do panelu kursanta, więc osobny plik CSS by nie doszedł.
 */
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fira+Sans:wght@300;400;500;600&display=swap">
<style>
  :root { --pp-blue: #0b5ea8; --pp-blue-dark: #084b87; --pp-yellow: #fcc634; --pp-ink: #1f2933; --pp-line: #c9ced6; --pp-muted: #5b6472; }
  body.pp { margin: 0; background: #fff; color: var(--pp-ink); font-family: 'Fira Sans', system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 16px; }
  .pp a { color: var(--pp-blue); }
  .pp-wrap { max-width: 1240px; margin: 0 auto; padding: 0 1rem; }
  .pp-skip { position: absolute; left: -9999px; } .pp-skip:focus { left: 1rem; top: .5rem; background: #fff; padding: .4rem .8rem; z-index: 10; }
  .pp-util { display: flex; justify-content: flex-end; gap: 1rem; font-size: .78rem; font-weight: 500; text-transform: uppercase; padding: .5rem 0; color: var(--pp-muted); }
  .pp-util a { color: var(--pp-muted); text-decoration: none; }
  .pp-head { display: grid; grid-template-columns: 1fr 300px; gap: 1rem 1.25rem; align-items: center; padding: .75rem 0 1rem; }
  .pp-brand { display: flex; align-items: center; gap: 1rem; text-decoration: none; color: var(--pp-blue); min-width: 0; }
  .pp-brand img { height: 64px; width: auto; }
  .pp-mark { width: 60px; height: 60px; flex: none; display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; }
  .pp-mark i { background: var(--pp-blue); display: block; } .pp-mark i:nth-child(5) { background: transparent; }
  .pp-title { font-size: clamp(1.6rem, 3.6vw, 3rem); font-weight: 400; line-height: 1.05; text-transform: uppercase; letter-spacing: .01em; }
  .pp-title small { display: block; font-size: .32em; letter-spacing: .08em; color: var(--pp-muted); margin-top: .35rem; }
  .pp-cta { display: grid; gap: .9rem; }
  .pp-btn-acc, .pp-btn-log { display: flex; align-items: center; justify-content: center; gap: .75rem; min-height: 50px; font-size: 1.35rem; font-weight: 600; text-transform: uppercase; text-decoration: none !important; }
  .pp-btn-acc { background: var(--pp-blue); color: var(--pp-yellow) !important; }
  .pp-btn-acc:hover { background: var(--pp-blue-dark); }
  .pp-btn-log { background: var(--pp-yellow); color: var(--pp-blue) !important; }
  .pp-btn-log:hover { filter: brightness(.95); }
  .pp-nav { display: grid; grid-template-columns: 1fr 300px; gap: 1.25rem; }
  .pp-menu { display: flex; border: 1px solid var(--pp-line); min-height: 50px; }
  .pp-menu a { flex: 1; display: flex; align-items: center; justify-content: center; text-decoration: none; text-transform: uppercase; font-size: .82rem; font-weight: 500; color: var(--pp-blue); border-left: 1px solid var(--pp-line); padding: 0 .5rem; text-align: center; }
  .pp-menu a:first-child { flex: 0 0 54px; background: var(--pp-blue); color: #fff; border-left: 0; font-size: 1.2rem; }
  .pp-menu a:hover { background: #f1f5fa; } .pp-menu a:first-child:hover { background: var(--pp-blue-dark); }
  .pp-crumb { font-size: .82rem; font-weight: 600; margin: 1.4rem 0 .4rem; color: var(--pp-blue); }
  .pp-crumb span { color: #e2a800; }
  .pp-h1 { color: var(--pp-blue); font-weight: 400; font-size: 2rem; margin: .2rem 0 1.4rem; }
  .pp-cols { display: grid; grid-template-columns: minmax(0, 7fr) minmax(0, 6fr); gap: 2.5rem 6rem; align-items: start; }
  .pp label, .pp .pp-label { display: block; font-weight: 600; font-size: 1.02rem; margin: 0 0 .55rem; }
  .pp input.pp-input, .pp .pp-input { width: 100%; box-sizing: border-box; border: 1px solid var(--pp-line); border-radius: 0; padding: .6rem .8rem; font: inherit; background: #fff; }
  .pp input.pp-input:focus { outline: 3px solid var(--pp-yellow); outline-offset: 0; border-color: var(--pp-blue); }
  .pp-field { margin-bottom: 1.6rem; }
  .pp-submit { display: inline-flex; align-items: center; justify-content: center; min-width: 200px; min-height: 36px; padding: .45rem 1.5rem; background: var(--pp-yellow); color: var(--pp-blue); border: 0; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
  .pp-submit:hover { filter: brightness(.95); }
  .pp-links { display: flex; flex-wrap: wrap; gap: .4rem 1.2rem; font-size: .85rem; margin-top: 1.6rem; }
  .pp-links a { color: var(--pp-muted); text-decoration: none; } .pp-links a:hover { text-decoration: underline; }
  .pp-alt { display: flex; align-items: center; justify-content: center; gap: .6rem; min-height: 90px; border: 3px solid #1f2933; border-radius: 6px; font-size: 1.6rem; font-weight: 500; color: #1f2933 !important; text-decoration: none !important; margin: .6rem 0 .9rem; background: #fff; }
  .pp-alt:hover { background: #f6f8fb; }
  .pp-alt .bi { font-size: 1.8rem; }
  .pp-note { font-size: .92rem; line-height: 1.6; color: var(--pp-ink); }
  .pp-tabs { display: flex; flex-wrap: wrap; gap: .4rem; margin: 0 0 1.4rem; padding: 0; list-style: none; }
  .pp-tabs a { display: block; padding: .45rem .9rem; border: 1px solid var(--pp-line); text-decoration: none; color: var(--pp-blue); font-size: .9rem; font-weight: 500; }
  .pp-tabs a[aria-current="page"] { background: var(--pp-blue); color: #fff; border-color: var(--pp-blue); }
  .pp-error { border-left: 4px solid #c0392b; background: #fdf0ee; padding: .7rem 1rem; margin-bottom: 1.2rem; }
  .pp-ok { border-left: 4px solid #2e8b57; background: #eef8f1; padding: .7rem 1rem; margin-bottom: 1.2rem; }
  .pp-foot-links { border-top: 1px solid #dfe3e8; border-bottom: 1px solid #dfe3e8; margin-top: 4rem; }
  .pp-foot-links .pp-wrap { display: flex; flex-wrap: wrap; gap: 0; padding-top: .9rem; padding-bottom: .9rem; }
  .pp-foot-links a { font-size: .75rem; text-transform: uppercase; color: var(--pp-muted); text-decoration: none; padding: 0 .7rem; border-right: 1px dotted var(--pp-line); }
  .pp :focus-visible { outline: 3px solid var(--pp-yellow); outline-offset: 2px; }
  @media (max-width: 860px) {
    .pp-head, .pp-nav { grid-template-columns: 1fr; }
    .pp-cta { grid-template-columns: 1fr 1fr; }
    .pp-btn-acc, .pp-btn-log { font-size: 1rem; min-height: 44px; }
    .pp-cols { grid-template-columns: 1fr; gap: 2rem; }
    .pp-menu { flex-wrap: wrap; } .pp-menu a { min-height: 44px; }
    .pp-brand img { height: 44px; } .pp-mark { width: 42px; height: 42px; }
  }
</style>
