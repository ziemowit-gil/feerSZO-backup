<?php /* modules/seeders/view/styles.php — style Metro Seederów (lista i zarządzanie). */ ?>
<style>
.sdr{max-width:1280px;margin:0 auto;font-family:"Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;color:#1d1d1d}
.sdr *{border-radius:0!important}
.sdr-kicker{font-size:.7rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:#5f5f5f;margin:0 0 .35rem}
.sdr-h1{font-size:2.4rem;font-weight:300;letter-spacing:-.02em;line-height:1.1;margin:0 0 .5rem}
.sdr-lead{color:#3f3f3f;max-width:62rem;margin:0 0 1rem}
.sdr-warn{display:flex;gap:.75rem;align-items:flex-start;background:#f2f2f2;border-left:6px solid #c25100;padding:.8rem 1rem;margin:0 0 1.5rem}
.sdr-warn .bi{color:#c25100;font-size:1.2rem}
.sdr-warn code{background:#fff;padding:.05rem .3rem;color:#1d1d1d}
.sdr-nav{display:flex;flex-wrap:wrap;gap:0;border-bottom:1px solid #e1e1e1;margin:0 0 1.5rem}
.sdr-nav a{padding:.5rem .9rem .45rem;border-bottom:3px solid transparent;color:#5f5f5f;text-decoration:none;font-size:1.02rem;font-weight:300}
.sdr-nav a:hover{background:#f2f2f2;color:#1d1d1d}
.sdr-group{margin:0 0 2rem}
.sdr-gh{font-size:1.5rem;font-weight:300;letter-spacing:-.01em;margin:0 0 .75rem;padding:0 0 .4rem;border-bottom:2px solid #1d1d1d}
.sdr-grid{display:grid;gap:.75rem;grid-template-columns:repeat(auto-fill,minmax(360px,1fr))}
.sdr-card{display:flex;flex-direction:column;background:#fff;border:1px solid #e1e1e1;border-top:4px solid var(--k,#0050ef)}
.sdr-card-h{padding:.8rem 1rem .4rem}
.sdr-card-t{font-size:1.1rem;font-weight:600;margin:0 0 .25rem;display:flex;gap:.5rem;align-items:baseline}
.sdr-card-t code{font-size:.68rem;font-weight:400;color:#5f5f5f;background:#f2f2f2;padding:.05rem .35rem}
.sdr-card-d{font-size:.84rem;color:#3f3f3f;margin:0}
.sdr-form{padding:.4rem 1rem 1rem;display:flex;flex-direction:column;gap:.6rem;flex:1}
.sdr-fields{display:grid;gap:.5rem .75rem;grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}
.sdr-f label{display:block;font-size:.66rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#5f5f5f;margin:0 0 .2rem}
.sdr-f input,.sdr-f select{width:100%;border:2px solid #8a8a8a;padding:.35rem .5rem;font:inherit;font-size:.88rem;background:#fff;color:#1d1d1d}
.sdr-f input:focus,.sdr-f select:focus{border-color:#0050ef;outline:0}
.sdr-f small{display:block;font-size:.7rem;color:#5f5f5f;margin-top:.15rem}
.sdr-actions{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:auto}
.sdr-btn{border:2px solid #1d1d1d;background:#fff;color:#1d1d1d;font-weight:600;font-size:.84rem;padding:.35rem .8rem;cursor:pointer}
.sdr-btn:hover{background:#1d1d1d;color:#fff}
.sdr-btn--apply{background:#0050ef;border-color:#0050ef;color:#fff}
.sdr-btn--apply:hover{background:#1d1d1d;border-color:#1d1d1d}
.sdr-btn--danger{border-color:#a20025;color:#a20025}
.sdr-btn--danger:hover{background:#a20025;color:#fff}
.sdr-btn:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
.sdr-cli{font-size:.74rem;color:#5f5f5f;background:#f2f2f2;padding:.4rem .6rem;border-left:4px solid #8a8a8a}
.sdr-cmds{margin-top:.6rem;font-size:.78rem}
.sdr-cmds summary{cursor:pointer;color:#1d1d1d;font-weight:600;padding:.3rem 0}
.sdr-cmds summary:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
.sdr-cmds dl{margin:.3rem 0 0;display:grid;grid-template-columns:auto 1fr;gap:.25rem .6rem;align-items:baseline}
.sdr-cmds dt{color:#5f5f5f;font-weight:600}
.sdr-cmds dd{margin:0;min-width:0}
.sdr-cmds code{display:block;background:#1d1d1d;color:#f2f2f2;padding:.3rem .5rem;white-space:pre-wrap;word-break:break-all;user-select:all}
.sdr-cmds p{margin:.4rem 0 0;color:#5f5f5f}
.sdr-out{margin:0 0 2rem}
.sdr-res{border:1px solid #e1e1e1;margin:0 0 .75rem}
.sdr-res-h{display:flex;flex-wrap:wrap;gap:.25rem 1rem;align-items:center;padding:.6rem .9rem;background:#f2f2f2;border-left:6px solid var(--k,#5f5f5f)}
.sdr-res-h strong{font-weight:600}
.sdr-res-h code{font-size:.78rem;color:#1d1d1d;background:#fff;padding:.1rem .4rem;word-break:break-all}
.sdr-res-st{margin-left:auto;font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#fff;background:var(--k,#5f5f5f);padding:.2rem .5rem}
.sdr-pre{margin:0;max-height:420px;overflow:auto;background:#1d1d1d;color:#e6e6e6;font-size:.78rem;line-height:1.45;padding:.8rem 1rem;white-space:pre-wrap;word-break:break-word}
.sdr details>summary{cursor:pointer;color:#003bb0;font-weight:600;margin:0 0 .5rem}
/* Menu boczne: wszystkie seedery w grupach, przyklejone; na wąskim ekranie zostaje pasek grup u góry */
.sdr-layout{display:grid;gap:2rem;grid-template-columns:260px minmax(0,1fr);align-items:start}
.sdr-side{position:sticky;top:1rem;max-height:calc(100vh - 2rem);overflow:auto;border-right:1px solid #e1e1e1;padding:0 .75rem 1rem 0}
.sdr-side-f{width:100%;border:2px solid #8a8a8a;padding:.35rem .5rem;font:inherit;font-size:.88rem;margin:0 0 .75rem;background:#fff;color:#1d1d1d}
.sdr-side-f:focus{border-color:#0050ef;outline:0}
.sdr-side h2{font-size:.66rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#5f5f5f;margin:.9rem 0 .25rem;padding-left:.6rem;border-left:4px solid var(--k,#5f5f5f)}
.sdr-side ul{list-style:none;margin:0;padding:0}
.sdr-side a{display:block;padding:.3rem .6rem;color:#1d1d1d;text-decoration:none;font-size:.86rem;border-left:4px solid transparent}
.sdr-side a:hover{background:#f2f2f2}
.sdr-side a[aria-current="true"]{background:#f2f2f2;border-left-color:var(--k,#0050ef);font-weight:600}
.sdr-side a:focus-visible{outline:3px solid #fbbf24;outline-offset:-3px}
.sdr-side-res{display:block;padding:.35rem .6rem;margin:0 0 .25rem;font-weight:600;font-size:.86rem;color:#1d1d1d;text-decoration:none;background:#f2f2f2}
.sdr-none{display:none;color:#5f5f5f;font-size:.84rem;padding:.3rem .6rem}
.sdr-card.is-hidden,.sdr-side li.is-hidden,.sdr-group.is-hidden,.sdr-side section.is-hidden{display:none}
.sdr-card:target{outline:3px solid #fbbf24;outline-offset:2px}
@media (min-width:961px){.sdr-nav{display:none}}
@media (max-width:960px){.sdr-layout{display:block}.sdr-side{display:none}}
/* Opis administratora i statystyka na karcie; ekran zarządzania */
.sdr-note{margin:.5rem 0 0;padding:.5rem .7rem;background:#f2f2f2;border-left:4px solid var(--k,#0050ef);font-size:.84rem;color:#1d1d1d}
.sdr-note b{display:block;font-size:.62rem;letter-spacing:.12em;text-transform:uppercase;color:#5f5f5f;margin:0 0 .15rem}
.sdr-run{margin:.4rem 0 0;font-size:.74rem;color:#5f5f5f}
.sdr-run .is-err{color:#a20025;font-weight:600}
.sdr-head{display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;justify-content:space-between}
.sdr-head .sdr-btn{text-decoration:none;display:inline-flex;align-items:center;gap:.4rem}
.sdr-mrow{display:grid;gap:.5rem .75rem;grid-template-columns:minmax(220px,1.1fr) minmax(0,2fr);border:1px solid #e1e1e1;border-left:6px solid var(--k,#0050ef);background:#fff;padding:.8rem 1rem;margin:0 0 .75rem}
.sdr-mrow.is-hidden-s{border-left-color:#8a8a8a;background:#fafafa}
.sdr-mrow h3{font-size:1.05rem;font-weight:600;margin:0 0 .2rem}
.sdr-mrow textarea{width:100%;min-height:5.5rem;border:2px solid #8a8a8a;padding:.4rem .5rem;font:inherit;font-size:.88rem}
.sdr-mrow textarea:focus{border-color:#0050ef;outline:0}
.sdr-tag{display:inline-block;font-size:.66rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:.1rem .4rem;background:#5f5f5f;color:#fff}
@media (max-width:760px){.sdr-mrow{grid-template-columns:1fr}}
</style>
