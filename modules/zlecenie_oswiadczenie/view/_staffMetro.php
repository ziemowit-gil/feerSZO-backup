<?php
/**
 * modules/zlecenie_oswiadczenie/view/_staffMetro.php — szata Metro ekranów obsługi (pracownik) w ramie SZO:
 * kafle stanów, kolejka dokumentów z miniaturą skanu, ekran weryfikacji (podgląd obok danych). Style w zakresie .oswm.
 */
?>
<style>
.oswm{--acc:#0f6fa8;--acc-d:#0b5582;--dark:#1d1d1d;--light:#f2f2f2;--light-h:#e6e6e6;--line:#e1e1e1;--muted:#5f5f5f;
  --green:#007a00;--orange:#c25100;--red:#a20025;--indigo:#0050ef;--teal:#00808a;--grey:#5f5f5f;
  font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,Arial,sans-serif;color:var(--dark)}
.oswm *{border-radius:0!important}
.oswm .m-title{font:300 30px/1.15 "Segoe UI",system-ui,sans-serif;letter-spacing:-.02em;margin:0 0 4px}
.oswm .m-sub{color:var(--muted);margin:0 0 14px}
.oswm .m-head{margin:22px 0 8px;padding:0 0 6px;border-bottom:2px solid var(--dark);font:300 21px/1.2 "Segoe UI",system-ui,sans-serif;display:flex;justify-content:space-between;align-items:baseline;gap:10px}
.oswm .m-head small{font-size:13px}
.oswm .m-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:6px}
.oswm .m-tile{--c:var(--acc);display:flex;flex-direction:column;justify-content:space-between;min-height:118px;padding:10px 12px;background:var(--c);color:#fff!important;text-decoration:none;position:relative}
.oswm .m-tile:hover,.oswm .m-tile:focus-visible{filter:brightness(1.12);outline:3px solid var(--dark);outline-offset:2px}
.oswm .m-tile[aria-current="page"]{outline:4px solid var(--dark);outline-offset:-4px;box-shadow:inset 0 -6px 0 rgba(0,0,0,.35)}
.oswm .m-tile .n{font:300 40px/1 "Segoe UI",system-ui,sans-serif}
.oswm .m-tile b{font-size:15px;font-weight:600}
.oswm .m-tile small{font-size:12px;color:rgba(255,255,255,.9)}
.oswm .c-orange{--c:var(--orange)}.oswm .c-indigo{--c:var(--indigo)}.oswm .c-red{--c:var(--red)}.oswm .c-teal{--c:var(--teal)}.oswm .c-grey{--c:var(--grey)}.oswm .c-green{--c:var(--green)}
/* Kolejka dokumentów */
.oswm .m-queue{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px}
.oswm .m-card{display:flex;flex-direction:column;background:var(--light);color:var(--dark);text-decoration:none}
.oswm .m-card:hover,.oswm .m-card:focus-visible{background:var(--light-h);outline:3px solid var(--acc);outline-offset:0}
.oswm .m-thumb{height:150px;background:#fff center/cover no-repeat;border-bottom:4px solid var(--c,var(--acc));display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative}
.oswm .m-thumb .ph{font:300 34px/1 "Segoe UI",system-ui,sans-serif;color:#fff;background:var(--c,var(--acc));width:100%;height:100%;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:4px}
.oswm .m-thumb .ph small{font-size:12px;letter-spacing:.08em;text-transform:uppercase}
.oswm .m-thumb .kind{position:absolute;left:0;top:0;background:var(--dark);color:#fff;font-size:11px;font-weight:700;letter-spacing:.1em;padding:3px 8px}
.oswm .m-card .bd{padding:8px 10px 10px}
.oswm .m-card .nm{font-weight:600;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.oswm .m-card .mt{font-size:12.5px;color:var(--muted)}
.oswm .m-empty{background:var(--light);padding:22px;color:var(--muted)}
.oswm .m-badge{display:inline-block;color:#fff;font-size:12px;font-weight:600;padding:2px 9px;background:var(--c,var(--muted))}
/* Ekran weryfikacji */
.oswm .m-verify{display:grid;grid-template-columns:minmax(0,3fr) minmax(320px,2fr);gap:6px;align-items:start}
.oswm .m-preview{background:#525659;min-height:70vh;display:flex;align-items:flex-start;justify-content:center}
.oswm .m-preview iframe{width:100%;height:80vh;border:0;background:#fff}
.oswm .m-preview img{max-width:100%;height:auto;display:block;background:#fff}
.oswm .m-side{background:var(--light);padding:14px 16px}
.oswm .m-side h2{font:300 19px/1.2 "Segoe UI",system-ui,sans-serif;margin:14px 0 6px}
.oswm .m-side h2:first-child{margin-top:0}
.oswm .m-kv{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);margin:0;background:#fff}
.oswm .m-kv dt,.oswm .m-kv dd{margin:0;padding:5px 8px;border-bottom:1px solid var(--line);font-size:13.5px}
.oswm .m-kv dt{color:var(--muted);font-weight:400}
.oswm .m-kv dd{font-weight:600;overflow-wrap:anywhere}
.oswm .m-check{list-style:none;padding:0;margin:0}
.oswm .m-check li{display:flex;gap:8px;align-items:flex-start;padding:5px 0}
.oswm .m-check input{width:18px;height:18px;margin-top:2px;accent-color:var(--green)}
.oswm .m-btn{display:inline-flex;align-items:center;gap:6px;border:2px solid var(--acc);background:var(--acc);color:#fff;padding:8px 16px;font-weight:600;text-decoration:none;cursor:pointer;font-family:inherit;font-size:14px}
.oswm .m-btn:hover{background:var(--acc-d);border-color:var(--acc-d);color:#fff}
.oswm .m-btn.ok{background:var(--green);border-color:var(--green)}
.oswm .m-btn.no{background:#fff;color:var(--red);border-color:var(--red)}
.oswm .m-btn.no:hover{background:var(--red);color:#fff}
.oswm .m-btn.ghost{background:transparent;color:var(--dark);border-color:var(--dark)}
.oswm .m-btn.ghost:hover{background:var(--dark);color:#fff}
.oswm .m-chips{display:flex;flex-wrap:wrap;gap:4px;margin:6px 0}
.oswm .m-chips button{border:1px solid #8a8a8a;background:#fff;font-size:12.5px;padding:3px 8px;cursor:pointer;font-family:inherit}
.oswm .m-chips button:hover{background:var(--light-h)}
.oswm textarea,.oswm .m-in{width:100%;border:2px solid #8a8a8a;padding:6px 8px;font:inherit;background:#fff}
.oswm textarea:focus,.oswm .m-in:focus{border-color:var(--acc);outline:0}
@media (max-width:991px){.oswm .m-verify{grid-template-columns:1fr}.oswm .m-preview{min-height:40vh}.oswm .m-preview iframe{height:60vh}}
</style>
