<?php
/**
 * modules/dhosting_migrator/view/_metro.php — wygląd METRO stron /migracjapoczty (wejście i strona migracji), wołany w <head>.
 * Bazuje na zestawie Panelu współpracownika (panelMetroAssets: kafle, grupy, paski koloru, przyciski) + elementy stron
 * samodzielnych: ciemny pasek, komunikaty, formularze, kroki. Strona ma data-theme="light" (bez ciemnego motywu systemu,
 * bo strona samodzielna nie ma zmiennych motywu SZO). Kontrast tekstu ≥ 4,5:1, fokus: żółta ramka 3 px.
 */
require_once dirname(__DIR__, 2) . '/panel_metro/lib/panelMetro.php';
panelMetroAssets(panelMetroColor('blue'));
?>
<style>
  html{background:#fff}
  body{margin:0;background:#fff;color:#111827;font:16px/1.5 "Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,Arial,sans-serif}
  .visually-hidden{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
  .skip{position:absolute;left:-999px;top:0;background:#fff;color:#111;padding:.5rem 1rem;z-index:10}
  .skip:focus{left:1rem}
  /* pasek */
  .dm-bar{background:#1d1d1d;color:#fff}
  .dm-bar__in{max-width:960px;margin:0 auto;padding:.25rem 1rem;display:flex;flex-wrap:wrap;align-items:center;gap:.25rem 1.25rem}
  .dm-bar__org{font-weight:600;letter-spacing:.02em;display:inline-flex;align-items:center;gap:.5rem}
  .dm-bar__org .sq{width:28px;height:28px;background:#0f6fa8;display:inline-flex;align-items:center;justify-content:center}
  .dm-bar__sp{flex:1}
  .dm-bar__who{font-size:.875rem;color:#e5e5e5}
  .dm-bar a,.dm-bar button{color:#fff;font:inherit;font-size:.875rem;background:none;border:0;padding:.4rem .1rem;min-height:44px;
    text-decoration:underline;text-underline-offset:3px;cursor:pointer;display:inline-flex;gap:.35rem;align-items:center}
  .dm-bar a:hover,.dm-bar button:hover{text-decoration-thickness:2px}
  .dm-bar form{margin:0}
  .dm-bar :focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
  /* treść */
  .pvm.dm{max-width:960px;padding:1.75rem 1rem 3rem}
  .pvm .dm-sec{margin:0 0 1rem}
  .pvm .dm-sec>.mt-h2{margin-top:2rem}
  .pvm .mt-group p{margin:.4rem 0}
  .pvm .mt-group p:first-of-type{margin-top:0}
  .pvm .mt-group>:last-child{margin-bottom:0}
  .pvm .mt-tile.dm-static{cursor:default}
  .pvm .mt-tile.dm-static:hover{filter:none}
  .pvm .mt-tile.dm-static:active{transform:none}
  .pvm .mt-tile .val{word-break:break-word}
  .pvm .mt-tile .sub{white-space:normal}
  /* komunikaty */
  .pvm .dm-msg{background:var(--pvm-light);border-left:8px solid var(--c,var(--m-blue));padding:.85rem 1rem;margin:0 0 1rem;color:var(--pvm-ink);display:flex;gap:.65rem;align-items:flex-start}
  .pvm .dm-msg>i{font-size:1.15rem;line-height:1.3;color:var(--c,var(--m-blue))}
  .pvm .dm-msg--success{--c:var(--m-green)} .pvm .dm-msg--danger{--c:var(--m-red)}
  .pvm .dm-msg--warning{--c:var(--m-orange)} .pvm .dm-msg--info{--c:var(--m-blue)}
  /* formularze */
  .pvm label.dm-fld{display:block;font-weight:600;font-size:.95rem;margin:0 0 .3rem}
  .pvm .dm-inp{display:block;width:100%;max-width:24rem;min-height:44px;padding:.5rem .7rem;border:2px solid #767676;border-radius:0;font:inherit;background:#fff;color:#111}
  .pvm .dm-inp--code{max-width:11rem;font-size:1.35rem;letter-spacing:.3em}
  .pvm .dm-inp:focus-visible{outline:3px solid #fbbf24;outline-offset:1px;border-color:#111}
  .pvm .dm-help{font-size:.875rem;color:var(--pvm-muted);margin:.3rem 0 1.1rem}
  .pvm .dm-chk{display:flex;gap:.6rem;align-items:flex-start;margin:.3rem 0 .9rem}
  .pvm .dm-chk input{width:1.25rem;height:1.25rem;margin-top:.15rem;flex-shrink:0;accent-color:var(--m-blue)}
  .pvm .mt-btn{min-height:44px;font-size:.95rem;font-weight:600;cursor:pointer;font-family:inherit}
  .pvm .mt-btn--danger{background:#fff;color:var(--m-red);border-color:var(--m-red)}
  .pvm .mt-btn--danger:hover{background:var(--m-red);color:#fff;filter:none}
  .pvm .mt-btn[disabled]{opacity:.75;cursor:wait}
  .pvm .dm-row{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center}
  .pvm .dm-row form{margin:0}
  /* kroki (wiersze z paskiem koloru, stan zawsze tekstem) */
  .pvm .dm-steps{list-style:none;margin:0;padding:0}
  .pvm .dm-steps .mt-svc{margin-bottom:6px}
  .pvm .dm-st--done{--c:var(--m-green)} .pvm .dm-st--doing{--c:var(--m-orange)} .pvm .dm-st--todo{--c:var(--m-blue)} .pvm .dm-st--info{--c:var(--m-grey)}
  .pvm .dm-state{display:inline-block;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#fff;background:var(--c);padding:.1rem .45rem}
  /* postęp wejścia */
  .pvm .dm-path{display:flex;gap:1.6rem;list-style:none;margin:0 0 1.4rem;padding:0;border-bottom:1px solid var(--pvm-line);overflow-x:auto}
  .pvm .dm-path li{padding:.35rem 0 .6rem;font-size:1.1rem;font-weight:300;color:var(--pvm-muted);border-bottom:3px solid transparent;margin-bottom:-1px;white-space:nowrap}
  .pvm .dm-path li[aria-current="step"]{color:var(--pvm-ink);font-weight:600;border-bottom-color:var(--pvm-acc)}
  .pvm .dm-path li.done{color:var(--pvm-ink)}
  /* tabela ustawień programu pocztowego */
  .pvm table.dm-cfg{border-collapse:collapse;width:100%;font-size:.95rem;background:var(--pvm-bg)}
  .pvm table.dm-cfg th,.pvm table.dm-cfg td{text-align:left;padding:.55rem .7rem;border-top:1px solid var(--pvm-line);vertical-align:top}
  .pvm table.dm-cfg th{width:40%;color:var(--pvm-muted);font-weight:600}
  .pvm code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:var(--pvm-bg);padding:.05rem .35rem;word-break:break-all;font-size:.9em}
  .pvm ul.dm-list{margin:.25rem 0 0;padding-left:1.2rem}
  .pvm ul.dm-list li{margin:.2rem 0}
  .pvm .mt-progress{margin-top:.5rem}
  .pvm .mt-progress--done>span{background:var(--m-green)}
  .pvm a{color:#0b5a8a}
  @media(max-width:480px){.pvm table.dm-cfg th{width:auto}.pvm .mt-title{font-size:1.75rem}}
</style>
