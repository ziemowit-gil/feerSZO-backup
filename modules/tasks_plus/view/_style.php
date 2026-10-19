<?php
/**
 * modules/tasks_plus/view/_style.php — wspólne style stron rozszerzeń Zadań (szata Metro modułu:
 * tokeny --tsk-* / --tm-* z tasks/includes/tasks_metro.php, zero zaokrągleń, płaskie kafle).
 * Selektory od `html body #tsk-main`, bo Tailwind CDN wstrzykuje swoje reguły później.
 */
?>
<style>
html body #tsk-main .tp-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:.75rem;margin-bottom:1rem}
html body #tsk-main .tp-kicker{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--tm-muted)}
html body #tsk-main .tp-title{font-size:1.9rem;font-weight:300;margin:0;color:var(--tsk-text);line-height:1.15}
html body #tsk-main .tp-lead{color:var(--tm-muted);font-size:.9rem;margin:.25rem 0 0;max-width:62rem}
html body #tsk-main .tp-bar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;background:var(--tm-light);padding:.75rem;margin-bottom:1rem}
html body #tsk-main .tp-bar label.tp-l{display:block;font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--tm-muted);margin-bottom:.15rem}
html body #tsk-main .tp-in{border:2px solid var(--tm-field);background:#fff;color:var(--tsk-text);font:inherit;font-size:.9rem;padding:.35rem .5rem;min-height:2.3rem}
html body #tsk-main .tp-in:focus{border-color:var(--tsk-green);outline:0}
html body #tsk-main .tp-check{display:inline-flex;align-items:center;gap:.35rem;font-size:.88rem;min-height:2.3rem}
html body #tsk-main .tp-check input{width:1.1rem;height:1.1rem}
html body #tsk-main .tp-btn{display:inline-flex;align-items:center;gap:.4rem;border:2px solid var(--tsk-green);background:var(--tsk-green);color:#fff;
  font-weight:600;font-size:.88rem;padding:.35rem .9rem;min-height:2.3rem;text-decoration:none;cursor:pointer;line-height:1.2}
html body #tsk-main .tp-btn:hover{background:var(--tm-dark);border-color:var(--tm-dark);color:#fff}
html body #tsk-main .tp-btn--ghost{background:transparent;color:var(--tsk-text);border-color:var(--tsk-text)}
html body #tsk-main .tp-btn--ghost:hover{background:var(--tsk-text);color:#fff}
html body #tsk-main .tp-btn--danger{background:var(--tm-red);border-color:var(--tm-red)}
html body #tsk-main .tp-btn--sm{min-height:1.9rem;padding:.2rem .6rem;font-size:.8rem}
html body #tsk-main .tp-seg{display:inline-flex}
html body #tsk-main .tp-seg a{border:2px solid var(--tsk-text);padding:.3rem .8rem;color:var(--tsk-text);text-decoration:none;font-size:.85rem;font-weight:600;min-height:2.3rem;display:inline-flex;align-items:center}
html body #tsk-main .tp-seg a+a{border-left:0}
html body #tsk-main .tp-seg a[aria-current]{background:var(--tsk-text);color:#fff}
html body #tsk-main .tp-section{font-size:1.15rem;font-weight:400;margin:1.6rem 0 .6rem;color:var(--tsk-text)}
html body #tsk-main .tp-group{background:var(--tm-light);padding:1rem;margin-bottom:1rem}
html body #tsk-main .tp-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.5rem;margin-bottom:1rem}
html body #tsk-main .tp-tile{background:var(--tsk-green);color:#fff;padding:.8rem 1rem;min-height:92px;display:flex;flex-direction:column;justify-content:space-between;text-decoration:none}
html body #tsk-main .tp-tile b{font-size:2rem;font-weight:300;line-height:1}
html body #tsk-main .tp-tile span{font-size:.82rem}
html body #tsk-main .tp-tile.is-red{background:var(--tm-red)}
html body #tsk-main .tp-tile.is-orange{background:var(--tm-orange)}
html body #tsk-main .tp-tile.is-blue{background:var(--tm-blue)}
html body #tsk-main .tp-tile.is-dark{background:var(--tm-dark)}
html body #tsk-main .tp-table{width:100%;border-collapse:collapse;font-size:.9rem;background:#fff}
html body #tsk-main .tp-table th{text-align:left;font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--tm-muted);
  border-bottom:2px solid var(--tsk-text);padding:.45rem .5rem;white-space:nowrap}
html body #tsk-main .tp-table td{border-bottom:1px solid var(--tm-line);padding:.5rem;vertical-align:top}
html body #tsk-main .tp-table tr:hover td{background:var(--tm-light)}
html body #tsk-main .tp-table .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
html body #tsk-main .tp-tag{display:inline-block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:1px 6px;color:#fff;background:var(--tm-muted);white-space:nowrap}
html body #tsk-main .tp-tag.is-red{background:var(--tm-red)}
html body #tsk-main .tp-tag.is-orange{background:var(--tm-orange)}
html body #tsk-main .tp-tag.is-green{background:var(--tsk-green)}
html body #tsk-main .tp-tag.is-blue{background:var(--tm-blue)}
html body #tsk-main .tp-muted{color:var(--tm-muted);font-size:.82rem}
html body #tsk-main .tp-empty{background:var(--tm-light);padding:1.5rem;color:var(--tm-muted);text-align:center}
html body #tsk-main .tp-scroll{overflow-x:auto}
html body #tsk-main .tp-meter{height:8px;background:var(--tm-light-h);min-width:80px;position:relative}
html body #tsk-main .tp-meter>i{position:absolute;left:0;top:0;bottom:0;background:var(--tsk-green)}
html body #tsk-main .tp-meter.is-over>i{background:var(--tm-red)}
html body #tsk-main .tp-dot{display:inline-block;width:10px;height:10px;border-radius:50%!important;vertical-align:middle;margin-right:.3rem}
html body #tsk-main .tp-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
</style>
