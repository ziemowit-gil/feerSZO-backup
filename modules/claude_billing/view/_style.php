<?php
if (!isset($P)) return;   // tylko z widoków modułu
/**
 * modules/claude_billing/view/_style.php — wspólna szata widoków „Płatne licencje” w panelu dydaktyka (ekran zarządzania, karta abonamentu,
 * tabela opłat, strona testu): stonowany Metro, znaczniki cb-tag, przyciski cb-btn, kontrast ≥ 4,5:1 także dla starych btn-metro / chip.
 */
?>
<style>
  /* Ekran zarządzania Płatnymi licencjami — stonowany Metro: biel, szarości, jeden akcent; kontrast tekstu ≥ 4,5:1 */
  .ldm { --ink: #1d1d1d; --muted: #555; --line: #d6d6d6; --bg: #f3f4f6; --link: #0f6fa8; --ok: #107c10; --due: #8a5300; --bad: #a4262c; }
  .ldm .cb-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: .75rem; margin-bottom: .75rem; }
  .ldm .cb-prod { display: inline-flex; border: 1px solid var(--line); }
  .ldm .cb-prod a { padding: .35rem .9rem; color: var(--ink); text-decoration: none; }
  .ldm .cb-prod a[aria-current="page"] { background: var(--ink); color: #fff !important; text-decoration: none !important; }
  .ldm .cb-state { display: flex; flex-wrap: wrap; gap: .4rem 1.2rem; font-size: .88rem; color: var(--muted); margin-bottom: 1rem; }
  .ldm .cb-state b { color: var(--ink); font-weight: 600; }
  .ldm .cb-metrics { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0; border: 1px solid var(--line); background: #fff; margin-bottom: 1rem; }
  .ldm .cb-metric { display: block; padding: .6rem .8rem; border-right: 1px solid var(--line); color: var(--ink); text-decoration: none; border-top: 3px solid transparent; }
  .ldm a.cb-metric:hover, .ldm a.cb-metric:focus-visible { background: var(--bg); }
  .ldm .cb-metric.warn { border-top-color: var(--due); } .ldm .cb-metric.alert { border-top-color: var(--bad); }
  .ldm .cb-metric .n { display: block; font-size: 1.6rem; font-weight: 300; line-height: 1.1; font-variant-numeric: tabular-nums; }
  .ldm .cb-metric .l { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
  .ldm .cb-tag { display: inline-block; padding: 0 .45rem; font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; border: 1px solid currentColor; color: var(--muted); white-space: nowrap; }
  .ldm .cb-tag.ok { color: var(--ok); } .ldm .cb-tag.due { color: var(--due); } .ldm .cb-tag.bad { color: var(--bad); }
  .ldm .cb-btn { border: 1px solid var(--link); background: var(--link); color: #fff; font-weight: 600; padding: .3rem .8rem; font-size: .88rem; }
  .ldm .cb-btn:hover { background: #0b5a89; }
  .ldm .cb-btn.ghost { background: #fff; color: var(--ink); border-color: var(--ink); } .ldm .cb-btn.ghost:hover { background: var(--bg); }
  .ldm .cb-btn.danger { background: #fff; color: var(--bad); border-color: var(--bad); }
  .ldm .cb-btn.ok { background: var(--ok); border-color: var(--ok); }
  .ldm .cb-explain { color: var(--muted); font-size: .9rem; max-width: 80ch; }
  .ldm .table td, .ldm .table th { vertical-align: top; }
  .ldm .cb-steps { list-style: none; padding: 0; margin: 0; font-size: .82rem; }
  .ldm .cb-steps li.done { color: var(--ok); } .ldm .cb-steps li.todo { color: var(--muted); }

  /* stare przyciski i znaczniki modułu (strona testu) — kontrast WCAG zamiast jasnych kolorów pod białym tekstem */
  .ldm .btn-metro { background: var(--link); border: 1px solid var(--link); }
  .ldm .btn-metro:hover { background: #0b5a89; }
  .ldm .btn-metro.dark { background: var(--ink); border-color: var(--ink); } .ldm .btn-metro.red { background: var(--bad); border-color: var(--bad); }
  .ldm .btn-metro.green { background: var(--ok); border-color: var(--ok); } .ldm .btn-metro.orange { background: var(--due); border-color: var(--due); }
  .ldm .chip { background: transparent !important; color: var(--ink); border: 1px solid currentColor; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; }
  .ldm .cb-docs { list-style: none; padding: 0; margin: 0; font-size: .85rem; }
  .ldm .cb-docs li { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; padding: .15rem 0; }
  .ldm .cb-info dt { font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); font-weight: 600; }
  .ldm .cb-info dd { margin: 0 0 .6rem; }
</style>
