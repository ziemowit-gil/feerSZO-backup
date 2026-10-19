<?php
/**
 * modules/strategy_actions/view/_head.php — rama ekranów Działań w szacie Metro UI (jak System Tożsamości):
 * ciemny pasek #1d1d1d, Segoe UI, kafle, zero zaokrągleń, cieni i gradientów. Style w zakresie .dz (zwykły CSS —
 * bez Tailwind CDN, bo widoki list/tablicy/osi czasu renderuje Alpine z danych JSON).
 * Wejście: $pageTitle, $navActive ('lista'|'nowe'|'raport').
 */
$__u    = current_user() ?? [];
$__org  = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$__name = (string)($__u['name'] ?? ($__u['email'] ?? 'Użytkownik'));
$__init = mb_strtoupper(mb_substr($__name, 0, 1));
if (preg_match('/\s(\S)/u', $__name, $__m)) $__init .= mb_strtoupper($__m[1]);
$navActive = $navActive ?? '';
$__nav = [
    'lista' => ['Działania',      'bi-calendar-event', ActionsService::url()],
    'nowe'  => ['Nowe działanie', 'bi-plus-lg',        ActionsService::url('nowe')],
    'raport' => ['Raport §6',     'bi-bar-chart-line', ActionsService::url('raport')],
];
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle ?? 'Działania') ?> — Działania<?= $__org ? ' · ' . h($__org) : '' ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
<style>
:root{--dz:#00808a;--dz-d:#006a72;--dz-50:#e0f2f3;--dark:#1d1d1d;--light:#f2f2f2;--light-h:#e6e6e6;--line:#e1e1e1;--muted:#5f5f5f;
  --green:#008a00;--orange:#c25100;--red:#a20025;--cobalt:#0050ef;--amber:#a86b00;--focus:#fbbf24}
[x-cloak]{display:none!important}
body{background:#fff;color:var(--dark);margin:0;font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,Arial,sans-serif;-webkit-font-smoothing:antialiased}
.dz *,.dzbar *{border-radius:0!important}
.dz-skip{position:absolute;left:-999px}.dz-skip:focus{left:1rem;top:1rem;z-index:1080;background:#fff;color:var(--dark);padding:.5rem 1rem;font-weight:600}
:focus-visible{outline:3px solid var(--focus)!important;outline-offset:2px}
/* Pasek aplikacji */
.dzbar{background:var(--dark);color:#fff;position:sticky;top:0;z-index:1030}
.dzbar .inner{max-width:1400px;margin:0 auto;padding:.4rem 1rem;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
.dzbar .brand{display:flex;align-items:center;gap:.55rem;text-decoration:none;color:#fff;margin-right:.4rem}
.dzbar .brand .mark{width:34px;height:34px;background:var(--dz);display:flex;align-items:center;justify-content:center;font-size:1rem}
.dzbar .brand b{font-weight:700;font-size:.95rem;line-height:1.05;display:block}
.dzbar .brand small{display:block;font-size:.65rem;color:rgba(255,255,255,.65)}
.dzbar .lnk{color:rgba(255,255,255,.85);text-decoration:none;font-size:.84rem;font-weight:500;padding:.42rem .7rem;display:inline-flex;align-items:center;gap:.4rem;background:transparent;border:0}
.dzbar .lnk:hover{background:rgba(255,255,255,.1);color:#fff}
.dzbar .lnk.on{background:var(--dz);color:#fff}
.dzbar .spacer{flex:1}
.dzbar .ava{width:30px;height:30px;background:var(--dz);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.72rem}
/* Treść */
.dz{max-width:1400px;margin:0 auto;padding:1.4rem 1rem 3rem}
.dz-title{font:300 2rem/1.1 "Segoe UI",system-ui,sans-serif;letter-spacing:-.02em;margin:0}
.dz-sub{color:var(--muted);margin:.2rem 0 0;font-size:.88rem}
.dz-top{display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;flex-wrap:wrap;margin-bottom:1rem}
.dz-head{margin:1.6rem 0 .6rem;padding:0 0 .35rem;border-bottom:2px solid var(--dark);font:300 1.35rem/1.2 "Segoe UI",system-ui,sans-serif;display:flex;justify-content:space-between;align-items:baseline;gap:.6rem;flex-wrap:wrap}
.dz-head small{font-size:.8rem;color:var(--muted)}
.dz-btn{display:inline-flex;align-items:center;gap:.4rem;border:2px solid var(--dz);background:var(--dz);color:#fff;padding:.42rem .95rem;font-weight:600;font-size:.86rem;text-decoration:none;cursor:pointer;font-family:inherit;line-height:1.3;white-space:nowrap}
.dz-btn:hover{background:var(--dz-d);border-color:var(--dz-d);color:#fff}
.dz-btn--ghost{background:transparent;color:var(--dark);border-color:var(--dark)}
.dz-btn--ghost:hover{background:var(--dark);border-color:var(--dark);color:#fff}
.dz-btn--danger{background:#fff;color:var(--red);border-color:var(--red)}
.dz-btn--danger:hover{background:var(--red);border-color:var(--red);color:#fff}
.dz-btn--sm{padding:.2rem .55rem;font-size:.78rem}
.dz-btn[disabled]{opacity:.55;cursor:not-allowed}
.dz-in,.dz select.dz-in,.dz textarea.dz-in{width:100%;border:2px solid #8a8a8a;padding:.38rem .55rem;font:inherit;font-size:.9rem;background:#fff;color:var(--dark)}
.dz-in:focus{border-color:var(--dz);outline:0}
.dz-lbl{display:block;font-size:.75rem;font-weight:600;color:var(--muted);margin:0 0 .2rem;text-transform:uppercase;letter-spacing:.04em}
.dz-badge{display:inline-block;color:#fff;font-size:.72rem;font-weight:600;padding:.12rem .5rem;background:var(--c,var(--muted));white-space:nowrap}
.dz-chip{display:inline-block;font-size:.72rem;padding:.08rem .45rem;background:var(--light);color:var(--dark);text-decoration:none;margin:0 .2rem .2rem 0;white-space:nowrap}
.dz-chip:hover{background:var(--light-h);color:var(--dark)}
.dz-block{background:var(--light);padding:1rem 1.1rem}
.dz-alert{padding:.75rem 1rem;margin-bottom:1rem;display:flex;gap:.6rem;align-items:flex-start;color:#fff;background:var(--c,var(--dz))}
.dz-alert--danger{--c:var(--red)}.dz-alert--success{--c:var(--green)}.dz-alert--warning{--c:var(--orange)}
.dz-alert .x{margin-left:auto;background:none;border:0;color:#fff;font-size:1.1rem;cursor:pointer}
/* Pivot (przełącznik widoków) */
.dz-pivot{display:flex;gap:1.3rem;flex-wrap:wrap;margin:.2rem 0 .9rem}
.dz-pivot button{background:none;border:0;padding:0;font:300 1.35rem/1.3 "Segoe UI",system-ui,sans-serif;color:#9a9a9a;cursor:pointer}
.dz-pivot button:hover{color:var(--muted)}
.dz-pivot button[aria-pressed="true"]{color:var(--dark);font-weight:400;box-shadow:inset 0 -3px 0 var(--dz)}
/* Filtry */
.dz-filters{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.5rem;background:var(--light);padding:.75rem;margin-bottom:.9rem}
.dz-filters .wide{grid-column:span 2}
@media(max-width:575px){.dz-filters .wide{grid-column:span 1}}
/* Tabela */
.dz-table{width:100%;border-collapse:collapse;font-size:.88rem}
.dz-table th{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600;text-align:left;padding:.45rem .55rem;border-bottom:2px solid var(--dark);white-space:nowrap}
.dz-table th button{background:none;border:0;padding:0;font:inherit;color:inherit;text-transform:inherit;letter-spacing:inherit;cursor:pointer}
.dz-table td{padding:.5rem .55rem;border-bottom:1px solid var(--line);vertical-align:top}
.dz-table tbody tr:hover{background:#fafafa}
.dz-table a.nm{font-weight:600;color:var(--dark);text-decoration:none}
.dz-table a.nm:hover{color:var(--dz);text-decoration:underline}
.dz-scroll{overflow-x:auto}
.dz-num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.dz-flag{display:inline-flex;align-items:center;gap:.2rem;font-size:.7rem;font-weight:600;color:#fff;padding:.05rem .35rem;margin:.15rem .2rem 0 0;background:var(--c)}
.dz-flag.noGrant{--c:var(--red)}.dz-flag.ending{--c:var(--amber)}.dz-flag.overdue{--c:var(--orange)}.dz-flag.behind{--c:var(--cobalt)}.dz-flag.msOverdue{--c:#6a00ff}
.dz-bar{height:6px;background:var(--line);min-width:60px;flex:1}
.dz-bar>span{display:block;height:100%;background:var(--c,var(--dz))}
.dz-empty{background:var(--light);padding:1.5rem;color:var(--muted);text-align:center}
.dz-muted{color:var(--muted)}
/* Karta działania */
.dz-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:.8rem;align-items:start}
@media(max-width:991px){.dz-grid{grid-template-columns:1fr}}
.dz-kv{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);margin:0;background:#fff}
.dz-kv dt,.dz-kv dd{margin:0;padding:.42rem .6rem;border-bottom:1px solid var(--line);font-size:.88rem}
.dz-kv dt{color:var(--muted);font-weight:400}
.dz-kv dd{font-weight:600;overflow-wrap:anywhere}
.dz-prose{white-space:pre-line;font-size:.92rem;margin:0}
/* Formularz */
.dz-form .row-g{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.7rem}
.dz-form .full{grid-column:1/-1}
.dz-form fieldset{border:0;padding:0;margin:0 0 .4rem}
.dz-check{display:flex;gap:.45rem;align-items:center;font-size:.9rem;cursor:pointer}
.dz-check input{width:18px;height:18px;accent-color:var(--dz)}
/* Kafle-liczniki (klik = filtr) */
.dz-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:6px;margin:0 0 1rem}
.dz-tile{--c:var(--dz);display:flex;flex-direction:column;justify-content:space-between;min-height:104px;padding:10px 12px;background:var(--c);color:#fff;border:0;text-align:left;cursor:pointer;font-family:inherit}
.dz-tile:hover{filter:brightness(1.12)}
.dz-tile[aria-pressed="true"]{outline:4px solid var(--dark);outline-offset:-4px;box-shadow:inset 0 -6px 0 rgba(0,0,0,.35)}
.dz-tile .n{font:300 2.4rem/1 "Segoe UI",system-ui,sans-serif}
.dz-tile b{font-size:.92rem;font-weight:600}
.dz-tile small{font-size:.72rem;color:rgba(255,255,255,.9)}
/* Tablica (kanban po statusie) */
.dz-board{display:grid;grid-template-columns:repeat(6,minmax(220px,1fr));gap:6px;overflow-x:auto;padding-bottom:.5rem}
.dz-col{background:var(--light);min-height:220px;display:flex;flex-direction:column}
.dz-col.over{background:var(--dz-50);outline:2px dashed var(--dz);outline-offset:-2px}
.dz-col h3{margin:0;padding:.5rem .65rem;color:#fff;background:var(--c);font:600 .85rem/1.3 "Segoe UI",system-ui,sans-serif;display:flex;justify-content:space-between}
.dz-col .cards{padding:6px;display:flex;flex-direction:column;gap:6px;flex:1}
.dz-card{background:#fff;padding:.5rem .6rem;border-left:4px solid var(--c);cursor:grab;font-size:.84rem}
.dz-card[aria-grabbed="true"]{opacity:.5}
.dz-card a.nm{font-weight:600;color:var(--dark);text-decoration:none;display:block;margin-bottom:.15rem}
.dz-card a.nm:hover{color:var(--dz);text-decoration:underline}
.dz-card .mv{display:flex;gap:4px;justify-content:flex-end;margin-top:.35rem}
.dz-card .mv button{background:#fff;border:1px solid #8a8a8a;font-size:.72rem;padding:.05rem .4rem;cursor:pointer}
.dz-card .mv button:hover{background:var(--dark);color:#fff}
/* Oś czasu */
.dz-tl{min-width:860px}
.dz-tl .hd,.dz-tl .rw{display:grid;grid-template-columns:260px 1fr;align-items:center}
.dz-tl .hd{border-bottom:2px solid var(--dark);font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600}
.dz-tl .months{display:grid;grid-template-columns:repeat(12,1fr)}
.dz-tl .months span{padding:.35rem .3rem;border-left:1px solid var(--line)}
.dz-tl .rw{border-bottom:1px solid var(--line);min-height:34px}
.dz-tl .rw:hover{background:#fafafa}
.dz-tl .lb{padding:.3rem .5rem .3rem 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.84rem}
.dz-tl .lb a{color:var(--dark);text-decoration:none;font-weight:600}
.dz-tl .lb a:hover{color:var(--dz);text-decoration:underline}
.dz-tl .track{position:relative;height:34px}
.dz-tl .mgrid{position:absolute;inset:0;display:grid;grid-template-columns:repeat(12,1fr)}
.dz-tl .mgrid span{border-left:1px solid var(--line)}
.dz-tl .bar{position:absolute;top:8px;height:18px;min-width:6px;background:var(--c);color:#fff;font-size:.68rem;line-height:18px;padding:0 4px;overflow:hidden;white-space:nowrap;text-decoration:none;border:0;cursor:pointer;font-family:inherit;text-align:left}
.dz-tl .bar:hover{filter:brightness(1.15)}
.dz-tl .cut-l{border-left:3px dotted #fff}.dz-tl .cut-r{border-right:3px dotted #fff}
.dz-tl .today{position:absolute;top:0;bottom:0;width:2px;background:var(--red);z-index:1}
/* Panel szczegółów (z prawej) */
.dz-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:1040}
.dz-panel{position:fixed;top:0;right:0;bottom:0;width:min(480px,100%);background:#fff;z-index:1050;display:flex;flex-direction:column;border-left:4px solid var(--dz)}
.dz-dialog{position:fixed;top:5vh;left:50%;transform:translateX(-50%);width:min(680px,calc(100% - 1.5rem));max-height:90vh;background:#fff;z-index:1050;display:flex;flex-direction:column;border-top:4px solid var(--dz)}
.dz-dialog .ph{background:var(--dark);color:#fff;padding:.8rem 1rem;display:flex;gap:.6rem;align-items:flex-start}
.dz-dialog .ph h2{font:300 1.35rem/1.2 "Segoe UI",system-ui,sans-serif;margin:0;flex:1}
.dz-dialog .ph .x{background:none;border:0;color:#fff;font-size:1.25rem;cursor:pointer}
.dz-dialog .pb{padding:1rem;overflow-y:auto}
.dz-dialog h3{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700;margin:1.2rem 0 .4rem;padding-bottom:.25rem;border-bottom:2px solid var(--dark)}
.dz-cols{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.15rem .8rem;border:0;padding:0;margin:0}
.dz-panel .ph{background:var(--dark);color:#fff;padding:.8rem 1rem;display:flex;gap:.6rem;align-items:flex-start}
.dz-panel .ph h2{font:300 1.35rem/1.2 "Segoe UI",system-ui,sans-serif;margin:0;flex:1;overflow-wrap:anywhere}
.dz-panel .ph .x{background:none;border:0;color:#fff;font-size:1.25rem;cursor:pointer;padding:0 .2rem}
.dz-panel .pb{padding:1rem;overflow-y:auto;flex:1}
.dz-panel h3{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700;margin:1.1rem 0 .35rem;padding-bottom:.25rem;border-bottom:2px solid var(--dark)}
.dz-panel .pf{padding:.75rem 1rem;border-top:1px solid var(--line);display:flex;gap:.4rem;flex-wrap:wrap;background:var(--light)}
.dz-ind{display:grid;grid-template-columns:minmax(0,1fr) 110px;gap:.5rem;align-items:center;padding:.35rem 0;border-bottom:1px solid var(--line);font-size:.86rem}
.dz-ind small{display:block;color:var(--muted)}
/* Etapy i historia */
.dz-ms{list-style:none;padding:0;margin:0 0 .6rem}
.dz-ms li{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:0 .5rem;padding:.4rem .5rem;border-left:4px solid var(--dz);background:var(--light);margin-bottom:4px;font-size:.88rem}
.dz-ms li small{grid-column:1}
.dz-ms li .del{grid-column:2;grid-row:1/span 2;align-self:center;background:none;border:0;color:var(--muted);cursor:pointer}
.dz-ms li .del:hover{color:var(--red)}
.dz-ms li.done{border-left-color:var(--green)}.dz-ms li.done .t{text-decoration:line-through;color:var(--muted)}
.dz-ms li.late{border-left-color:#6a00ff}.dz-ms .late-tx{color:#6a00ff;font-weight:600}
.dz-ms-add{display:grid;grid-template-columns:1fr 1fr;gap:.4rem;background:var(--light);padding:.6rem;margin-bottom:.4rem}
.dz-ms-add .full{grid-column:1/-1}
.dz-log{list-style:none;padding:0;margin:0}
.dz-log li{display:grid;grid-template-columns:20px minmax(0,1fr);gap:.4rem;padding:.4rem 0;border-bottom:1px solid var(--line);font-size:.86rem}
.dz-log li i{color:var(--muted);margin-top:.15rem}
.dz-log li.cm .msg{white-space:pre-line;background:var(--dz-50);display:block;padding:.3rem .5rem}
/* Komunikat po akcji */
.dz-toast{position:fixed;left:1rem;bottom:1rem;z-index:1060;max-width:420px}
.dz-toast span{display:block;background:var(--dark);color:#fff;padding:.65rem 1rem;font-size:.9rem}
/* FullCalendar */
.dz .fc .fc-button{background:#fff;color:var(--dark);border:2px solid var(--dark);font-size:.8rem;text-transform:none;box-shadow:none}
.dz .fc .fc-button:hover,.dz .fc .fc-button-active{background:var(--dark)!important;color:#fff!important;border-color:var(--dark)!important}
.dz .fc .fc-toolbar-title{font:300 1.4rem "Segoe UI",system-ui,sans-serif}
.dz .fc-event{cursor:pointer}
@media print{.dzbar,.dz-noprint{display:none!important}.dz{padding:0;max-width:none}}
</style>
</head>
<body>
<a href="#dz-main" class="dz-skip">Przejdź do treści</a>
<header class="dzbar">
  <div class="inner">
    <a href="<?= h(ActionsService::url()) ?>" class="brand">
      <span class="mark" aria-hidden="true"><i class="bi bi-calendar-event"></i></span>
      <span><b>Działania</b><small>Strategia<?= $__org ? ' · ' . h(mb_substr($__org, 0, 28)) : '' ?></small></span>
    </a>
    <nav class="d-flex align-items-center gap-1 flex-wrap" aria-label="Sekcje modułu Działania">
      <?php foreach ($__nav as $__k => [$__l, $__i, $__href]): $__on = $navActive === $__k; ?>
      <a class="lnk<?= $__on ? ' on' : '' ?>" href="<?= h($__href) ?>"<?= $__on ? ' aria-current="page"' : '' ?>><i class="bi <?= $__i ?>" aria-hidden="true"></i><?= h($__l) ?></a>
      <?php endforeach; ?>
      <a class="lnk" href="<?= APP_URL ?>/strategy/index.php"><i class="bi bi-bullseye" aria-hidden="true"></i>Strategia</a>
      <a class="lnk" href="<?= APP_URL ?>/grants/index.php"><i class="bi bi-cash-coin" aria-hidden="true"></i>Granty</a>
    </nav>
    <span class="spacer"></span>
    <?php if (org_setting('bug_report_enabled') !== '0'): ?>
    <button type="button" class="lnk" data-bs-toggle="modal" data-bs-target="#bugReportModal" title="Zgłoś błąd na tej stronie"><i class="bi bi-bug" aria-hidden="true"></i><span class="d-none d-lg-inline">Zgłoś błąd</span></button>
    <?php endif; ?>
    <?php $msw_active = 'actions'; $msw_dark = true; require dirname(__DIR__, 3) . '/includes/module_switcher.php'; ?>
    <a class="lnk" href="<?= APP_URL ?>/portal.php"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="d-none d-sm-inline">Wróć do SZO</span></a>
    <span class="ava" title="<?= h($__name) ?>" aria-hidden="true"><?= h($__init) ?></span>
    <a class="lnk" href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span class="d-none d-sm-inline">Wyloguj</span></a>
  </div>
</header>
<main id="dz-main" class="dz" tabindex="-1">
<?php if ($__f = flash_get()): $__t = in_array($__f['type'] ?? '', ['danger', 'success', 'warning'], true) ? $__f['type'] : 'info'; ?>
<div class="dz-alert dz-alert--<?= $__t ?>" role="<?= $__t === 'danger' ? 'alert' : 'status' ?>">
  <i class="bi <?= $__t === 'danger' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill' ?>" aria-hidden="true"></i>
  <span><?= h($__f['msg'] ?? '') ?></span>
  <button type="button" class="x" aria-label="Zamknij komunikat" onclick="this.parentNode.remove()"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
</div>
<?php endif; ?>
