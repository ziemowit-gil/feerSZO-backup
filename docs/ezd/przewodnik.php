<?php
/**
 * docs/ezd/przewodnik.php — przewodnik programisty po module EZD „Wirtualne
 * Biurko" (architektura, schemat bazy, opis funkcji, model uprawnień,
 * integracje). Uzupełnia Swagger UI (index.php), który dokumentuje sam
 * interfejs HTTP. Dostęp tylko dla zalogowanych przez Microsoft 365
 * (konto @feer.org.pl) — patrz index.php / openapi.php.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!current_user()) {
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
    if ($base !== '' && $base !== '/' && str_starts_with($uri, $base . '/')) {
        $uri = substr($uri, strlen($base));
    }
    header('Location: ' . APP_URL . '/auth/ms365.php?redirect=' . urlencode(APP_URL . $uri));
    exit;
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>EZD „Wirtualne Biurko" — przewodnik programisty</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Ctext y='14' font-size='14'%3E%F0%9F%97%83%EF%B8%8F%3C/text%3E%3C/svg%3E">
<style>
/* ============================================================ TOKENS ==== */
:root{
  --paper:#f7f5f2; --paper-2:#efece7; --card:#fffefc; --line:#e3ddd4;
  --ink:#211d1a; --ink-2:#4b443d; --ink-3:#7a7167;
  --accent:#8f1d2e; --accent-soft:#f3e0e2; --accent-ink:#6f1523;
  --steel:#3d5566;
  --ok:#2f6d4f; --warn:#8a5a12; --danger:#8f1d2e;
  --code-bg:#f1ede7; --code-ink:#5a2230;
  --mono:ui-monospace,"SF Mono",SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;
  --sans:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  --serif:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,"Times New Roman",serif;
  --shadow:0 1px 2px rgba(33,29,26,.05),0 4px 16px rgba(33,29,26,.05);
  --radius:10px;
}
@media (prefers-color-scheme:dark){
  :root{
    --paper:#171513; --paper-2:#1f1c19; --card:#201d1a; --line:#332e29;
    --ink:#efe9e2; --ink-2:#c3bab0; --ink-3:#8f857a;
    --accent:#e0808d; --accent-soft:#3a2225; --accent-ink:#f0b7bf;
    --steel:#8fb0c4;
    --ok:#7fc79f; --warn:#d8a862; --danger:#e0808d;
    --code-bg:#26211d; --code-ink:#e6a9b3;
    --shadow:0 1px 2px rgba(0,0,0,.3),0 6px 22px rgba(0,0,0,.35);
  }
}
:root[data-theme="light"]{
  --paper:#f7f5f2; --paper-2:#efece7; --card:#fffefc; --line:#e3ddd4;
  --ink:#211d1a; --ink-2:#4b443d; --ink-3:#7a7167;
  --accent:#8f1d2e; --accent-soft:#f3e0e2; --accent-ink:#6f1523; --steel:#3d5566;
  --ok:#2f6d4f; --warn:#8a5a12; --danger:#8f1d2e;
  --code-bg:#f1ede7; --code-ink:#5a2230; --shadow:0 1px 2px rgba(33,29,26,.05),0 4px 16px rgba(33,29,26,.05);
}
:root[data-theme="dark"]{
  --paper:#171513; --paper-2:#1f1c19; --card:#201d1a; --line:#332e29;
  --ink:#efe9e2; --ink-2:#c3bab0; --ink-3:#8f857a;
  --accent:#e0808d; --accent-soft:#3a2225; --accent-ink:#f0b7bf; --steel:#8fb0c4;
  --ok:#7fc79f; --warn:#d8a862; --danger:#e0808d; --code-bg:#26211d; --code-ink:#e6a9b3;
  --shadow:0 1px 2px rgba(0,0,0,.3),0 6px 22px rgba(0,0,0,.35);
}
*{box-sizing:border-box}
html{scroll-behavior:smooth;scroll-padding-top:1.5rem}
body{margin:0;background:var(--paper);color:var(--ink);font-family:var(--sans);
  font-size:16px;line-height:1.6;-webkit-font-smoothing:antialiased}
@media (prefers-reduced-motion:reduce){html{scroll-behavior:auto}}

/* ============================================================ LAYOUT ==== */
.wrap{display:grid;grid-template-columns:290px minmax(0,1fr);gap:0;max-width:1440px;margin:0 auto}
.sidebar{position:sticky;top:0;height:100vh;overflow-y:auto;border-right:1px solid var(--line);
  padding:1.6rem 1.1rem 3rem;background:var(--paper-2)}
main{padding:2.4rem clamp(1rem,4vw,3.4rem) 6rem;min-width:0}
@media (max-width:880px){
  .wrap{grid-template-columns:1fr}
  .sidebar{position:static;height:auto;border-right:none;border-bottom:1px solid var(--line)}
}

/* ============================================================ SIDEBAR === */
.brand{display:flex;align-items:center;gap:.6rem;margin-bottom:.3rem}
.brand .mark{width:34px;height:34px;border-radius:8px;background:var(--accent);color:#fff;
  display:grid;place-items:center;font-family:var(--serif);font-weight:700;font-size:16px;flex:none}
.brand b{font-size:1.02rem;letter-spacing:.01em}
.brand small{display:block;color:var(--ink-3);font-size:.72rem;font-weight:400;letter-spacing:.04em;text-transform:uppercase}
.navtitle{font-size:.68rem;text-transform:uppercase;letter-spacing:.13em;color:var(--ink-3);
  margin:1.5rem 0 .5rem;font-weight:700}
.sidebar a.nav{display:block;color:var(--ink-2);text-decoration:none;padding:.28rem .55rem;border-radius:7px;
  font-size:.88rem;border-left:2px solid transparent}
.sidebar a.nav:hover{background:var(--card);color:var(--ink)}
.sidebar a.sub{padding-left:1.1rem;font-size:.83rem;color:var(--ink-3)}
.swaggerlink{display:flex;align-items:center;gap:.5rem;margin-top:1rem;padding:.6rem .7rem;border:1px solid var(--line);
  border-radius:9px;background:var(--card);color:var(--accent-ink);text-decoration:none;font-size:.85rem;font-weight:600}
.swaggerlink:hover{border-color:var(--accent)}
.themebtn{margin-top:.8rem;width:100%;border:1px solid var(--line);background:var(--card);
  color:var(--ink-2);padding:.5rem;border-radius:8px;cursor:pointer;font:inherit;font-size:.82rem}
.themebtn:hover{border-color:var(--accent)}

/* ============================================================ TYPO ====== */
.eyebrow{font-size:.72rem;text-transform:uppercase;letter-spacing:.16em;color:var(--accent);font-weight:700}
h1{font-family:var(--serif);font-weight:700;font-size:clamp(2rem,4.5vw,2.9rem);line-height:1.08;
  margin:.4rem 0 .5rem;text-wrap:balance;letter-spacing:-.01em}
h2{font-family:var(--serif);font-weight:700;font-size:1.72rem;margin:3.2rem 0 .3rem;
  padding-top:1rem;scroll-margin-top:1rem;letter-spacing:-.01em;text-wrap:balance}
h3{font-size:1.16rem;margin:2rem 0 .5rem;letter-spacing:-.005em}
h4{font-size:.98rem;margin:1.3rem 0 .4rem;color:var(--ink-2)}
p{margin:.6rem 0;max-width:74ch}
.lead{font-size:1.12rem;color:var(--ink-2);max-width:66ch}
a{color:var(--accent-ink)}
hr{border:none;border-top:1px solid var(--line);margin:2.6rem 0}
code{font-family:var(--mono);font-size:.86em;background:var(--code-bg);color:var(--code-ink);
  padding:.1em .38em;border-radius:5px}
strong{color:var(--ink)}
ul,ol{max-width:74ch}
li{margin:.25rem 0}

/* ============================================================ CARDS ===== */
.grid{display:grid;gap:1rem;margin:1.2rem 0}
.g2{grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
.g3{grid-template-columns:repeat(auto-fit,minmax(190px,1fr))}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
  padding:1.1rem 1.2rem;box-shadow:var(--shadow)}
.card h4{margin:.1rem 0 .35rem;color:var(--ink)}
.card p{font-size:.9rem;color:var(--ink-2);margin:.25rem 0}
.card .ic{font-size:1.3rem;line-height:1}
.stat{display:flex;flex-direction:column;gap:.15rem}
.stat b{font-family:var(--serif);font-size:1.7rem;color:var(--accent);line-height:1.05}
.stat span{font-size:.78rem;color:var(--ink-3);text-transform:uppercase;letter-spacing:.05em}

/* callouts */
.note{border:1px solid var(--line);border-left:3px solid var(--steel);background:var(--card);
  border-radius:8px;padding:.8rem 1rem;margin:1.1rem 0;font-size:.92rem;color:var(--ink-2)}
.note.warn{border-left-color:var(--warn)}
.note.key{border-left-color:var(--accent)}
.note b{color:var(--ink)}

/* ============================================================ TABLES ==== */
.tablewrap{overflow-x:auto;border:1px solid var(--line);border-radius:var(--radius);margin:1.1rem 0;box-shadow:var(--shadow)}
table{border-collapse:collapse;width:100%;font-size:.87rem;background:var(--card)}
th,td{text-align:left;padding:.55rem .8rem;border-bottom:1px solid var(--line);vertical-align:top}
th{background:var(--paper-2);font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;
  color:var(--ink-3);font-weight:700;white-space:nowrap}
tr:last-child td{border-bottom:none}
table.fn td:first-child{white-space:normal}
.col-key{color:var(--code-ink);font-family:var(--mono);font-size:.82rem;white-space:nowrap}

/* method + pill chips */
.m{font-family:var(--mono);font-size:.68rem;font-weight:700;padding:.14em .5em;border-radius:5px;
  letter-spacing:.03em;color:#fff;display:inline-block;min-width:44px;text-align:center}
.m.get{background:var(--steel)} .m.post{background:var(--accent)}
.pill{display:inline-block;font-size:.72rem;padding:.12em .55em;border-radius:20px;border:1px solid var(--line);
  background:var(--paper-2);color:var(--ink-2);white-space:nowrap}
.pill.acc{color:var(--accent-ink);border-color:color-mix(in srgb,var(--accent) 35%,var(--line));background:var(--accent-soft)}

pre{background:var(--code-bg);border:1px solid var(--line);border-radius:8px;padding:1rem;overflow-x:auto;
  font-family:var(--mono);font-size:.83rem;line-height:1.5;color:var(--ink)}
pre code{background:none;padding:0;color:inherit}

/* diagram */
.flow{display:flex;flex-wrap:wrap;align-items:stretch;gap:.5rem;margin:1.2rem 0}
.flow .node{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:.6rem .9rem;
  box-shadow:var(--shadow);min-width:120px}
.flow .node b{display:block;font-size:.9rem}
.flow .node span{font-size:.75rem;color:var(--ink-3)}
.flow .arr{align-self:center;color:var(--accent);font-weight:700}
.chain{display:flex;flex-wrap:wrap;gap:.35rem;align-items:center;font-family:var(--mono);font-size:.82rem;margin:.3rem 0}
.chain .step{background:var(--accent-soft);color:var(--accent-ink);padding:.2em .6em;border-radius:6px}
.chain .a{color:var(--ink-3)}

footer{border-top:1px solid var(--line);margin-top:4rem;padding-top:1.4rem;color:var(--ink-3);font-size:.82rem}
.anchor{color:var(--ink-3);text-decoration:none;font-weight:400;font-size:.7em;margin-left:.4rem;opacity:0}
h2:hover .anchor,h3:hover .anchor{opacity:1}
:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:4px}
</style>
</head>
<body>
<div class="wrap">

<!-- ================================================== SIDEBAR ========== -->
<aside class="sidebar">
  <div class="brand">
    <div class="mark">EZD</div>
    <div><b>Wirtualne Biurko</b><small>przewodnik programisty</small></div>
  </div>

  <a class="swaggerlink" href="./index.php">⇆ Interfejs HTTP (Swagger UI)</a>

  <div class="navtitle">Wprowadzenie</div>
  <a class="nav" href="#przeglad">Przegląd</a>
  <a class="nav" href="#architektura">Architektura i hierarchia</a>
  <a class="nav" href="#sygnatury">Wzorce znaków i sygnatur</a>

  <div class="navtitle">Model danych</div>
  <a class="nav" href="#schema">Schemat bazy (tabele)</a>
  <a class="nav" href="#slowniki">Stałe i słowniki</a>

  <div class="navtitle">Warstwa logiki</div>
  <a class="nav" href="#funkcje">Opis funkcji (API PHP)</a>
  <a class="nav sub" href="#fn-sprawy">Sprawy, dostęp</a>
  <a class="nav sub" href="#fn-teczki">Teczki, JRWA</a>
  <a class="nav sub" href="#fn-pisma">Pisma, umowy, dokumenty</a>
  <a class="nav sub" href="#fn-rpw">RPW, dekretacja</a>
  <a class="nav sub" href="#fn-pliki">Pliki, Office, spinacz</a>
  <a class="nav sub" href="#fn-kopia">Kopia dokumentu el.</a>

  <div class="navtitle">Interfejs HTTP</div>
  <a class="nav" href="#endpointy">Endpointy (przegląd)</a>
  <a class="nav" href="#openapi">Specyfikacja OpenAPI</a>

  <div class="navtitle">Zagadnienia przekrojowe</div>
  <a class="nav" href="#uprawnienia">Model uprawnień</a>
  <a class="nav" href="#workflow">Workflow (BPM)</a>
  <a class="nav" href="#archiwum">Archiwum zakładowe</a>
  <a class="nav" href="#integracje">Integracje</a>
  <a class="nav" href="#rejestry">Rejestry pochodne</a>
  <a class="nav" href="#admin">Panele administracyjne</a>
  <a class="nav" href="#pliki">Mapa plików</a>

  <button class="themebtn" id="themebtn" type="button">◐ Przełącz motyw</button>
</aside>

<!-- ================================================== MAIN ============= -->
<main>

<header>
  <div class="eyebrow">System Obsługi Organizacji · FEER</div>
  <h1>EZD „Wirtualne Biurko"</h1>
  <p class="lead">Moduł Elektronicznego Zarządzania Dokumentacją — kancelaria, obieg spraw
  i repozytorium akt oparte o Jednolity Rzeczowy Wykaz Akt (JRWA). Przewodnik programisty
  wygenerowany z kodu i komentarzy źródłowych. Interaktywną specyfikację interfejsu HTTP
  znajdziesz w <a href="./index.php">Swagger UI</a>.</p>

  <div class="grid g3" style="margin-top:1.6rem">
    <div class="card stat"><b>21</b><span>tabel <code>ezd_*</code></span></div>
    <div class="card stat"><b>47</b><span>ścieżek HTTP</span></div>
    <div class="card stat"><b>~150</b><span>funkcji PHP</span></div>
    <div class="card stat"><b>PHP · SQLite</b><span>stos technologiczny</span></div>
    <div class="card stat"><b>M365 Graph</b><span>Office / SharePoint</span></div>
    <div class="card stat"><b>Claude AI</b><span>klasyfikacja JRWA</span></div>
  </div>
</header>

<!-- ============================================ PRZEGLĄD ============== -->
<section id="przeglad">
<h2>Przegląd <a class="anchor" href="#przeglad">#</a></h2>
<p>EZD to samodzielny moduł SZO realizujący cyfrowe zarządzanie dokumentacją zgodnie z polskimi
zasadami kancelaryjno-archiwalnymi. Cała logika DB oraz auto-migracja schematu żyją w jednym
pliku <code>includes/ezd.php</code> (samowywołująca się funkcja migrująca z guardem
<code>static $done</code>), rozszerzonym o pliki pomocnicze dla archiwum, szablonów, AI i modali UI.</p>

<div class="note key"><b>Nagłówek modułu (includes/ezd.php):</b> „Moduł EZD / Wirtualne biurko —
helpery DB + auto-migracja. Hierarchia: Teczka (JRWA) → Sprawa → Pismo / Umowa. Każdy poziom:
Załączniki, Dekretacje, Log".</div>

<div class="grid g2">
  <div class="card"><div class="ic">🗂️</div><h4>Kancelaria i obieg</h4>
    <p>Dziennik podawczy (RPW), zakładanie koszulek, dekretacje z auto-przekazaniem, workflow BPM
    konfigurowalny per klasa JRWA.</p></div>
  <div class="card"><div class="ic">📎</div><h4>Repozytorium akt</h4>
    <p>Wersjonowane załączniki, grupy plików, edycja w Office Online, konwersja do PDF,
    „Spinacz" (scalanie w jeden PDF), synchronizacja z SharePoint.</p></div>
  <div class="card"><div class="ic">🔐</div><h4>Dostęp warstwowy</h4>
    <p>Role R/Z/U + relacje do konkretnej koszulki (właściciel/referent, współdzielenie),
    reguła domykania oraz opcjonalny dostęp tylko przez VPN.</p></div>
  <div class="card"><div class="ic">🗄️</div><h4>Cykl archiwalny</h4>
    <p>Kategorie archiwalne z JRWA, spisy zdawczo-odbiorcze, brakowanie po okresie
    przechowywania, ekspertyza dla kat. BE.</p></div>
</div>

<h4>Konwencja nazewnicza (UI ↔ baza)</h4>
<div class="tablewrap"><table>
<tr><th>Termin w UI</th><th>Encja / kolumna</th><th>Znaczenie</th></tr>
<tr><td><b>Koszulka</b></td><td><code>ezd_sprawy</code></td><td>Sprawa — podstawowa jednostka postępowania</td></tr>
<tr><td><b>Segregator</b></td><td><code>ezd_teczki</code></td><td>Teczka — roczny zbiornik spraw jednej klasy JRWA</td></tr>
<tr><td><b>Podkoszulka</b></td><td><code>ezd_sprawy.parent_id</code></td><td>Podsprawa dziedzicząca teczkę i znak rodzica</td></tr>
<tr><td>Wykaz akt</td><td><code>ezd_jrwa</code></td><td>Jednolity Rzeczowy Wykaz Akt (słownik klasyfikacyjny)</td></tr>
</table></div>
</section>

<!-- ============================================ ARCHITEKTURA ========= -->
<section id="architektura">
<h2>Architektura i hierarchia <a class="anchor" href="#architektura">#</a></h2>
<p>Dokumentacja układa się w drzewo. Klasa JRWA determinuje segregator, segregator gromadzi
koszulki, a w koszulce toczy się obieg (pisma, umowy, dokumenty wewnętrzne, dekretacje, notatki).</p>

<div class="flow">
  <div class="node"><b>JRWA</b><span>klasa akt · kat. arch.</span></div>
  <div class="arr">→</div>
  <div class="node"><b>Teczka</b><span>segregator roczny</span></div>
  <div class="arr">→</div>
  <div class="node"><b>Sprawa</b><span>koszulka · znak</span></div>
  <div class="arr">→</div>
  <div class="node"><b>Pismo / Umowa / Dokument</b><span>+ załączniki, dekretacje, log</span></div>
</div>

<div class="note"><b>Wejście kancelaryjne.</b> Przesyłka rejestrowana jest najpierw w dzienniku
podawczym (<code>ezd_rpw</code>), a następnie „dekretowana do sprawy" (<code>assign</code>) —
co tworzy pismo przychodzące w koszulce i przenosi skan do akt. Możliwa jest też autorejestracja
korespondencji do sprawy ciągłej „Korespondencja {rok}".</div>

<h4>Powiązania międzymodułowe</h4>
<p>Integracja z innymi modułami odbywa się przez pola <code>ref_type</code> / <code>ref_id</code>
na <code>ezd_sprawy</code> i <code>ezd_umowy</code> oraz pola zwrotne w tabelach zewnętrznych:</p>
<ul>
<li><code>certificate_requests.ezd_pismo_id</code> — zaświadczenia zarejestrowane jako pisma,</li>
<li><code>users.rpts_ezd_sprawa_id</code> — sprawa weryfikacji RPTS wolontariusza,</li>
<li><code>umowy_wolontariat.zgoda_przedstawiciela_ezd_sprawa_id</code> — zgoda opiekuna.</li>
</ul>
</section>

<!-- ============================================ SYGNATURY ============ -->
<section id="sygnatury">
<h2>Wzorce znaków i sygnatur <a class="anchor" href="#sygnatury">#</a></h2>
<p>Znak sprawy generuje <code>ezd_sprawa_create()</code> w schemacie <code>SYMBOL.numer.rok</code>.
Podrzędne obiekty dziedziczą znak sprawy z sufiksem literowym i numerem kolejnym.</p>
<div class="chain">
  <span class="step">141.3.2026</span><span class="a">← znak sprawy (koszulka)</span>
</div>
<div class="chain">
  <span class="step">141.3.2026.P.1</span><span class="a">pismo</span>
  <span class="step">.U.1</span><span class="a">umowa</span>
  <span class="step">.D.1</span><span class="a">dokument wewnętrzny</span>
</div>
<p>Przesyłki w dzienniku podawczym numerowane są niezależnie: <code>RPW nr/rok</code>
(np. <code>RPW 128/2026</code>). Spisy archiwalne: <code>ZO 3/2026</code> (zdawczo-odbiorczy)
lub <code>BR 1/2026</code> (brakowanie).</p>
<div class="note"><b>Sprawy ciągłe</b> (<code>ciagla=1</code>) — rejestry, które nie zamykają się
automatycznie i są używane przez auto-prowizjonowane rejestry (zaświadczenia, korespondencja,
dokumenty księgowe, wolontariat).</div>
</section>

<!-- ============================================ SCHEMA =============== -->
<section id="schema">
<h2>Schemat bazy danych <a class="anchor" href="#schema">#</a></h2>
<p>Silnik: <b>SQLite</b>. Wszystkie tabele mają prefiks <code>ezd_</code> i powstają przez
<code>CREATE TABLE IF NOT EXISTS</code>; kolumny doraźnie dokładane są idempotentnymi
<code>ALTER TABLE</code>. Poniżej 21 tabel modułu z ich rolą.</p>

<h3>Rdzeń: klasyfikacja i obieg</h3>
<div class="tablewrap"><table class="fn">
<tr><th>Tabela</th><th>Rola</th><th>Kluczowe kolumny</th></tr>
<tr><td><code>ezd_jrwa</code></td><td>Jednolity Rzeczowy Wykaz Akt — hierarchiczny słownik klas (0–5 + podklasy)</td>
  <td><span class="col-key">symbol</span> (UNIQUE), <span class="col-key">title</span>, <span class="col-key">kat_arch</span>, <span class="col-key">parent_id</span>, <span class="col-key">sort_order</span></td></tr>
<tr><td><code>ezd_teczki</code></td><td>Teczka/segregator — roczny zbiornik spraw w klasie JRWA</td>
  <td><span class="col-key">jrwa_id</span>, <span class="col-key">symbol</span>, <span class="col-key">rok</span>, <span class="col-key">status</span>, <span class="col-key">arch_status</span>, <span class="col-key">rok_brakowania</span></td></tr>
<tr><td><code>ezd_sprawy</code></td><td>Sprawa/koszulka — jednostka postępowania</td>
  <td><span class="col-key">znak_sprawy</span> (UNIQUE), <span class="col-key">teczka_id</span>, <span class="col-key">status</span>, <span class="col-key">priority</span>, <span class="col-key">parent_id</span>, <span class="col-key">ciagla</span>, <span class="col-key">etap</span>, <span class="col-key">ref_type/ref_id</span></td></tr>
<tr><td><code>ezd_dekretacje</code></td><td>Zlecenia obiegu — kto komu przekazuje do załatwienia</td>
  <td><span class="col-key">zlecajacy_id</span>, <span class="col-key">wykonawca_id</span>, <span class="col-key">dyspozycja</span>, <span class="col-key">deadline</span>, <span class="col-key">status</span>, <span class="col-key">unit_id</span></td></tr>
<tr><td><code>ezd_workflows</code></td><td>Definicje workflow BPM per JRWA (kroki jako JSON)</td>
  <td><span class="col-key">jrwa_id</span> (UNIQUE), <span class="col-key">name</span>, <span class="col-key">steps</span> (JSON)</td></tr>
<tr><td><code>ezd_log</code></td><td>Dziennik zdarzeń / audit log (źródło metryki sprawy)</td>
  <td><span class="col-key">action</span>, <span class="col-key">details</span>, <span class="col-key">ip</span>, konteksty teczka/sprawa/pismo/umowa</td></tr>
</table></div>

<h3>Zawartość koszulki</h3>
<div class="tablewrap"><table class="fn">
<tr><th>Tabela</th><th>Rola</th><th>Kluczowe kolumny</th></tr>
<tr><td><code>ezd_pisma</code></td><td>Korespondencja w sprawie (sygnatura <code>.P.n</code>)</td>
  <td><span class="col-key">kierunek</span>, <span class="col-key">nadawca/odbiorca</span>, <span class="col-key">data_pisma/wplywu/wysylki</span>, <span class="col-key">rodzaj_medium</span></td></tr>
<tr><td><code>ezd_umowy</code></td><td>Umowy powiązane ze sprawą (sygnatura <code>.U.n</code>)</td>
  <td><span class="col-key">typ</span>, <span class="col-key">strona</span>, <span class="col-key">wartosc</span>, <span class="col-key">waluta</span>, <span class="col-key">data_od/do</span></td></tr>
<tr><td><code>ezd_dokumenty</code></td><td>Dokumenty wewnętrzne (notatka, opinia, protokół; sygn. <code>.D.n</code>)</td>
  <td><span class="col-key">rodzaj</span>, <span class="col-key">status</span>, <span class="col-key">owner_id</span></td></tr>
<tr><td><code>ezd_zalaczniki</code></td><td>Pliki repozytorium — wersjonowanie + integracja SharePoint</td>
  <td><span class="col-key">wersja</span>, <span class="col-key">prev_id</span>, <span class="col-key">grupa_id</span>, <span class="col-key">sp_web_url/drive_id/item_id</span>, <span class="col-key">converted_from_id</span></td></tr>
<tr><td><code>ezd_grupy_plikow</code></td><td>Grupy (foldery) plików w ramach sprawy</td>
  <td><span class="col-key">nazwa</span>, <span class="col-key">sort_order</span></td></tr>
<tr><td><code>ezd_notatki</code></td><td>Notatki w sprawie (przypinane)</td>
  <td><span class="col-key">tresc</span>, <span class="col-key">pinned</span>, <span class="col-key">created_by</span></td></tr>
</table></div>

<h3>Dostęp i współdzielenie</h3>
<div class="tablewrap"><table class="fn">
<tr><th>Tabela</th><th>Rola</th><th>Kluczowe kolumny</th></tr>
<tr><td><code>ezd_sprawa_users</code></td><td>Współdzielenie sprawy — dostęp ponad rolę/właściciela</td>
  <td><span class="col-key">user_id</span>, <span class="col-key">uprawnienie</span> (odczyt/edycja); UNIQUE(sprawa,user)</td></tr>
<tr><td><code>ezd_zalacznik_access</code></td><td>Dostęp do konkretnych plików niezależnie od dostępu do sprawy</td>
  <td><span class="col-key">zalacznik_id</span>, <span class="col-key">user_id</span>, <span class="col-key">note</span></td></tr>
</table></div>

<h3>Kancelaria, rejestry, archiwum</h3>
<div class="tablewrap"><table class="fn">
<tr><th>Tabela</th><th>Rola</th><th>Kluczowe kolumny</th></tr>
<tr><td><code>ezd_rpw</code></td><td>Rejestr Przesyłek Wpływających / dziennik podawczy</td>
  <td><span class="col-key">rpw_nr</span>, <span class="col-key">rok</span>, <span class="col-key">typ</span>, <span class="col-key">status</span>, <span class="col-key">scan_file</span>, <span class="col-key">przekazano_unit_id</span></td></tr>
<tr><td><code>ezd_pelnomocnictwa</code></td><td>Rejestr pełnomocnictw — metadane 1:1 ze sprawą JRWA 013</td>
  <td>PK=<span class="col-key">sprawa_id</span>, <span class="col-key">mocodawca</span>, <span class="col-key">pelnomocnik</span>, <span class="col-key">zakres</span>, <span class="col-key">data_waznosci</span></td></tr>
<tr><td><code>ezd_przerejestrowania</code></td><td>Protokół przerejestrowania spraw do Nowego JRWA (asystent AI)</td>
  <td><span class="col-key">stary_znak</span>, <span class="col-key">nowy_znak</span>, <span class="col-key">kod_jrwa</span>, <span class="col-key">forma</span>, <span class="col-key">zastosowano</span></td></tr>
<tr><td><code>ezd_szablony</code></td><td>Szablony pism / korespondencja seryjna (mail merge)</td>
  <td><span class="col-key">kategoria</span>, <span class="col-key">kierunek</span>, <span class="col-key">tytul_wzor</span>, <span class="col-key">tresc_wzor</span>, <span class="col-key">aktywny</span></td></tr>
<tr><td><code>ezd_arch_spisy</code></td><td>Spisy zdawczo-odbiorcze i protokoły brakowania</td>
  <td><span class="col-key">typ</span>, <span class="col-key">nr/rok</span>, <span class="col-key">status</span>, <span class="col-key">zgoda_ap</span>, <span class="col-key">approved_at</span>, <span class="col-key">realized_at</span></td></tr>
<tr><td><code>ezd_arch_pozycje</code></td><td>Pozycje spisu — snapshot metryki segregatora</td>
  <td><span class="col-key">spis_id</span>, <span class="col-key">teczka_id</span>, <span class="col-key">znak</span>, <span class="col-key">rok_brakowania</span></td></tr>
<tr><td><code>ezd_reminder_log</code></td><td>Dedup powiadomień o terminach (cron)</td>
  <td>UNIQUE(<span class="col-key">ref_type</span>, <span class="col-key">ref_id</span>, <span class="col-key">kind</span>)</td></tr>
</table></div>

<div class="note"><b>Snapshot archiwalny.</b> Pozycje spisu przechowują skopiowaną metrykę segregatora,
dzięki czemu „przetrwają brakowanie/usunięcie teczki" (komentarz w kodzie). Do <code>ezd_teczki</code>
dokładane są kolumny archiwalne: <code>arch_status</code>, <code>arch_spis_id</code>,
<code>rok_brakowania</code>, <code>arch_at</code>.</div>
</section>

<!-- ============================================ SŁOWNIKI ============= -->
<section id="slowniki">
<h2>Stałe i słowniki <a class="anchor" href="#slowniki">#</a></h2>
<div class="tablewrap"><table>
<tr><th>Stała</th><th>Wartości</th></tr>
<tr><td><code>EZD_STATUSES_SPRAWA</code></td><td>open · in_progress · suspended · closed</td></tr>
<tr><td><code>EZD_PRIORITIES</code></td><td>low · normal · high · urgent</td></tr>
<tr><td><code>EZD_ETAPY</code> (workflow)</td><td>wszczeta · dekretacja · realizacja · akceptacja · podpis · wysylka · zakonczona</td></tr>
<tr><td><code>EZD_KIERUNKI</code></td><td>przychodzace · wychodzace · wewnetrzne</td></tr>
<tr><td><code>EZD_MEDIA</code></td><td>papier · email · epuap · faks · inne</td></tr>
<tr><td><code>EZD_DYSPOZYCJE</code></td><td>do_zalat · do_akcept · do_wiadom · do_podpisu · do_realizacji</td></tr>
<tr><td><code>EZD_UMOWA_TYPY</code></td><td>umowa · aneks · porozumienie · zlecenie · ugoda · inne</td></tr>
<tr><td><code>EZD_RPW_TYPY</code></td><td>list · polecony · paczka · email · epuap · fax · osobiscie · inne</td></tr>
<tr><td><code>EZD_RPW_STATUSES</code></td><td>nowa · przekazana · w_sprawie · odrzucona</td></tr>
<tr><td><code>EZD_DOK_RODZAJE</code></td><td>notatka_sluzbowa · opinia · protokol · decyzja · projekt_pisma · raport · inne</td></tr>
<tr><td><code>EZD_KAT_ARCH</code></td><td>A · B5 · B10 · B25 · B50 · Bc · BE5 · BE10</td></tr>
<tr><td><code>EZD_SPRAWA_UPRAWNIENIA</code></td><td>odczyt · edycja</td></tr>
<tr><td><code>EZD_ALLOWED_EXT</code></td><td>pdf, doc(x), xls(x), odt, ods, pptx, png, jpg, gif, zip, txt, csv, eml, msg · limit <b>25 MB</b></td></tr>
</table></div>
</section>

<!-- ============================================ FUNKCJE ============== -->
<section id="funkcje">
<h2>Opis funkcji (API PHP) <a class="anchor" href="#funkcje">#</a></h2>
<p>Konwencja: funkcje z prefiksem <code>_ezd_</code> są wewnętrzne. Prawie każda operacja zapisu
woła <code>ezd_log()</code>. Poniżej wybór najważniejszych funkcji publicznych, pogrupowany tematycznie
(pełny wykaz ~150 funkcji w <code>includes/ezd.php</code>).</p>

<h3 id="fn-sprawy">Sprawy (koszulki)</h3>
<div class="tablewrap"><table class="fn">
<tr><th>Sygnatura</th><th>Opis</th></tr>
<tr><td><code>ezd_sprawa_create(array $d, int $user_id): int</code></td><td>Zakłada sprawę; generuje znak <code>SYMBOL.numer.rok</code>, waliduje segregator (istnieje/otwarty) i zgodność podkoszulki z rodzicem.</td></tr>
<tr><td><code>ezd_sprawa_update(int $id, array $d, int $user_id): void</code></td><td>Edycja; blokuje zamkniętą sprawę dla nie-adminów; sprawa ciągła nie może być zamknięta; przy zamknięciu zamyka oczekujące dekretacje.</td></tr>
<tr><td><code>ezd_sprawy_all(array $f=[], ?int $viewer_id=null): array</code></td><td>Lista z filtrami (status, priorytet, teczka, właściciel, wyszukiwanie, deadline). Gdy <code>viewer_id</code> podany a użytkownik bez roli — zawęża do własnych/współdzielonych. Limit 200.</td></tr>
<tr><td><code>ezd_sprawa_get(int $id): ?array</code></td><td>Sprawa + teczka, JRWA, właściciel, twórca, dane rodzica.</td></tr>
<tr><td><code>ezd_sprawa_reregister(int $id, $target, int $user_id): array</code></td><td>Przerejestrowanie koszulki do innego segregatora/klasy z nadaniem nowego znaku (nieodwracalne). Blokuje sprawy z hierarchią.</td></tr>
<tr><td><code>ezd_sprawa_set_etap(int $id, string $etap, int $user_id): void</code></td><td>Zmiana etapu obiegu; ostatni krok zamyka sprawę (o ile nie ciągła), cofnięcie reotwiera.</td></tr>
</table></div>

<h4>Dostęp i współdzielenie</h4>
<div class="tablewrap"><table class="fn">
<tr><th>Sygnatura</th><th>Opis</th></tr>
<tr><td><code>ezd_sprawa_access(array $sprawa, int $user_id): ?string</code></td><td>Efektywny dostęp: <code>'write'</code> | <code>'read'</code> | <code>null</code>. Kolejność: admin/rola-zapis i właściciel/twórca → write; jawne współdzielenie → wg uprawnienia; rola-odczyt → read.</td></tr>
<tr><td><code>ezd_sprawa_can_manage_share(array $sprawa, int $uid): bool</code></td><td>Czy użytkownik może zarządzać listą współdzielenia koszulki.</td></tr>
<tr><td><code>ezd_sprawa_share_add(...) / _remove(...)</code></td><td>Dodanie/usunięcie współdzielenia (INSERT OR REPLACE po parze sprawa+user).</td></tr>
<tr><td><code>ezd_zal_access_grant(array $ids, int $uid, int $by, string $note=''): int</code></td><td>Przyznanie dostępu do wybranych plików niezależnie od dostępu do sprawy; zwraca liczbę nowych wpisów.</td></tr>
</table></div>

<h3 id="fn-teczki">Teczki i JRWA</h3>
<div class="tablewrap"><table class="fn">
<tr><th>Sygnatura</th><th>Opis</th></tr>
<tr><td><code>ezd_teczka_create(array $d, int $user_id): int</code></td><td>Tworzy segregator (domyślny rok = bieżący).</td></tr>
<tr><td><code>ezd_teczki_all(string $status=''): array</code></td><td>Lista teczek z liczbą spraw otwartych/wszystkich.</td></tr>
<tr><td><code>ezd_jrwa_create / _update / _delete</code></td><td>CRUD haseł JRWA; usunięcie rzuca wyjątek, gdy hasło jest używane w teczkach; waliduje <code>kat_arch</code>.</td></tr>
<tr><td><code>ezd_jrwa_all(): array</code></td><td>Cały wykaz + liczba teczek na hasło, sortowany po sort_order/symbol.</td></tr>
</table></div>

<h3 id="fn-pisma">Pisma, umowy, dokumenty</h3>
<div class="tablewrap"><table class="fn">
<tr><th>Sygnatura</th><th>Opis</th></tr>
<tr><td><code>ezd_pismo_create(array $d, int $user_id): int</code></td><td>Tworzy pismo; generuje sygnaturę <code>.P.n</code>, waliduje medium, odświeża <code>updated_at</code> sprawy.</td></tr>
<tr><td><code>ezd_umowa_create(array $d, int $user_id): int</code></td><td>Tworzy umowę; sygnatura <code>.U.n</code>.</td></tr>
<tr><td><code>ezd_dokument_create(array $d, int $user_id): int</code></td><td>Tworzy dokument wewnętrzny; sygnatura <code>.D.n</code>, waliduje rodzaj/status.</td></tr>
<tr><td><code>ezd_timeline(int $sprawa_id): array</code></td><td>Pisma + umowy zmiksowane chronologicznie (oś czasu koszulki).</td></tr>
</table></div>

<h3 id="fn-rpw">RPW i dekretacja</h3>
<div class="tablewrap"><table class="fn">
<tr><th>Sygnatura</th><th>Opis</th></tr>
<tr><td><code>ezd_rpw_create(array $d, int $user_id): array</code></td><td>Rejestruje przesyłkę; zwraca <code>{id, rpw_nr, rok}</code>.</td></tr>
<tr><td><code>ezd_rpw_assign(int $id, array $d, int $user_id): int</code></td><td>Konwersja przesyłki na pismo w sprawie (opcjonalnie zakłada nową sprawę), przenosi skan jako załącznik; zwraca id pisma.</td></tr>
<tr><td><code>ezd_rpw_przekaz / _odrzuc / _delete</code></td><td>Przekazanie do jednostki (status 'przekazana'), odrzucenie z powodem, usunięcie (blok gdy powiązana ze sprawą).</td></tr>
<tr><td><code>ezd_dekretacja_create(array $d, int $user_id): int</code></td><td>Tworzy dekretację; <b>dyspozycja przesuwa etap obiegu do przodu</b> wg workflow JRWA. Fallback bez <code>unit_id</code> dla starych baz.</td></tr>
<tr><td><code>ezd_dekretacja_complete(int $id, int $user_id): void</code></td><td>Zamyka dekretację (dostępne dla wykonawcy).</td></tr>
</table></div>

<h3 id="fn-pliki">Pliki, Office Online, PDF, spinacz</h3>
<div class="tablewrap"><table class="fn">
<tr><th>Sygnatura</th><th>Opis</th></tr>
<tr><td><code>ezd_upload(string $field, int $sprawa_id, int $user_id, …): ?string</code></td><td>Upload z <code>$_FILES</code>: walidacja, wersjonowanie przy replace, własna nazwa, sync SharePoint w tle. Zwraca komunikat błędu lub null; id przez referencję.</td></tr>
<tr><td><code>ezd_new_office_file(int $sprawa_id, int $uid, string $type, string $name, …): array</code></td><td>Tworzy pusty plik Word/Excel (z szablonu lub z nagłówkiem znaku); synchronizuje z SharePoint.</td></tr>
<tr><td><code>ezd_office_online_url(int $zal_id, int $uid): array</code></td><td>Adres otwarcia pliku w Office Online (wysyła na SharePoint przy 1. wywołaniu).</td></tr>
<tr><td><code>ezd_office_online_pull(int $zal_id, int $uid, string $mode='version'): array</code></td><td>Ściąga treść po edycji online; tryb <code>version</code> (nowa wersja) lub <code>replace</code>; pomija zapis gdy plik bez zmian (porównanie hasha).</td></tr>
<tr><td><code>ezd_convert_to_pdf(int $zal_id, int $uid): array</code></td><td>Konwersja Word/Excel → PDF przez Graph (<code>?format=pdf</code>), zapis jako osobny załącznik.</td></tr>
<tr><td><code>ezd_spinacz_merge(int $sprawa_id, int $uid, string $files_field, …): array</code></td><td>„Spinacz": łączy 2+ plików (Office konwertowane na PDF) w jeden PDF; operacja atomowa z cleanupem plików tymczasowych.</td></tr>
<tr><td><code>ezd_merge_pdf_files(array $paths, string $out): void</code></td><td>Scala PDF-y — preferuje <code>qpdf</code> (każda wersja PDF), fallback FPDI (≤1.4).</td></tr>
</table></div>

<h3 id="fn-kopia">Wydruk kopii dokumentu elektronicznego</h3>
<p><code>includes/ezd_kopia.php</code> — jeden silnik dla <b>każdego</b> dokumentu EZD. Wydruk składa się
z odwzorowania treści (znak wodny na każdej stronie) i końcowej strony poświadczenia
<i>„Potwierdzam zgodność kopii z dokumentem elektronicznym"</i> z metryką: identyfikator dokumentu,
nazwa, tytuł, skrót SHA-256, wersja, data dokumentu, znak koszulki, akceptacja, data i autor wydruku.</p>
<div class="tablewrap"><table class="fn">
<tr><th>Sygnatura</th><th>Opis</th></tr>
<tr><td><code>ezd_kopia_resolve(string $type, int $id): ?array</code></td><td>Metryka + źródło treści dla typu <code>pismo</code> / <code>dokument</code> / <code>umowa</code> / <code>zalacznik</code> / <code>zaswiadczenie</code>. <code>null</code>, gdy typ nieznany lub dokumentu nie ma.</td></tr>
<tr><td><code>ezd_kopia_access(array $meta, int $uid): ?string</code></td><td>Uprawnienie: dostęp do koszulki dokumentu (<code>ezd_sprawa_access</code>); zaświadczenia bez koszulki — kancelaria/edytor albo autor wniosku.</td></tr>
<tr><td><code>ezd_kopia_stream(array $meta, int $uid, array $opts=[]): void</code></td><td>Buduje PDF (mPDF) i streamuje inline. <code>$opts</code>: <code>watermark</code>, <code>download</code>.</td></tr>
<tr><td><code>ezd_kopia_ident(string $type, int $id): string</code></td><td>Stały 32-znakowy identyfikator dokumentu — <code>md5(typ|id|sól instancji)</code>; sól w <code>settings.ezd_kopia_salt</code>.</td></tr>
<tr><td><code>ezd_kopia_watermark(): string</code></td><td>Tekst znaku wodnego z <code>settings.ezd_kopia_watermark</code> (domyślnie „KOPIA ELEKTRONICZNA").</td></tr>
<tr><td><code>ezd_kopia_cert_html(array $meta, int $uid): string</code></td><td>HTML strony poświadczenia (układ tabeli jak w EZD RP).</td></tr>
<tr><td><code>ezd_kopia_btn(string $type, int $id, string $name='', string $style='icon', string $cls=''): string</code></td><td>Przycisk „Wydruk kopii" dla list i widoków; otwiera PDF w modalu <code>.ezd-pdf-btn</code>.</td></tr>
</table></div>
<div class="note"><b>Odwzorowanie treści per źródło.</b> PDF → strony importowane przez FPDI
(przy błędzie parsera: normalizacja <code>qpdf --decrypt --stream-data=uncompress</code> i druga próba);
obraz → osadzony jako <code>data:</code> URI na stronie A4 w orientacji obrazu; pismo/dokument → treść
z metadanymi jako HTML; umowa → tabela parametrów; zaświadczenie → wgrany plik albo wydruk
z szablonu renderowany osobnym przebiegiem mPDF. Formatów nieodwzorowywalnych (DOCX, XLSX, ZIP…)
wydruk nie udaje: wstawia stronę informacyjną, a poświadczenie i tak podaje skrót SHA-256
oryginalnego pliku. <b>Skrót</b> dla plików liczony jest z bajtów pliku, dla rekordów bazy — z
kanonicznej serializacji pól merytorycznych (kolejność pól jest częścią definicji skrótu).</div>
<div class="note"><b>Akceptacja</b> nie jest osobną encją — jest wyprowadzana. Plik: kwalifikowany
podpis w samym pliku (<code>ezd_signature_info</code>), następnie zamknięty obieg podpisu
(<code>ezd_sign_requests</code>). Pismo/umowa: zrealizowana dekretacja <code>do_akcept</code>, następnie
status rekordu. Dokument: status <code>zatwierdzony</code>. Zaświadczenie: <code>zatwierdzone_przez</code> +
<code>zatwierdzone_at</code>. Brak przesłanek → wiersz „Dokument nie został zaakceptowany w systemie".</div>
</section>

<!-- ============================================ ENDPOINTY =========== -->
<section id="endpointy">
<h2>Interfejs HTTP — przegląd endpointów <a class="anchor" href="#endpointy">#</a></h2>
<p>Moduł to zestaw kontrolerów stron PHP, nie REST API. Uwierzytelnianie jest <b>sesyjne</b>
(cookie), a nie tokenem. Każdy endpoint woła <code>require_login()</code> +
<code>require_module_enabled('ezd_enabled')</code>; każdy POST wymaga pola <code>_csrf</code>.
Akcje POST rozróżniane są dyskryminatorem <code>_action</code> lub <code>_op</code>.
Pełną, interaktywną specyfikację otworzysz w <a href="./index.php">Swagger UI</a>.</p>

<div class="tablewrap"><table>
<tr><th>Metoda</th><th>Ścieżka</th><th>Funkcja</th></tr>
<tr><td><span class="m get">GET</span></td><td><code>/ezd/index.php</code></td><td>Pulpit — statystyki, moje koszulki/dekretacje, wyszukiwarka, eDoręczenia</td></tr>
<tr><td><span class="m get">GET</span></td><td><code>/ezd/szukaj.php</code></td><td>Wyszukiwarka (sygnatura, znak, tytuł) z poszanowaniem dostępu</td></tr>
<tr><td><span class="m get">GET</span></td><td><code>/ezd/sprawy/index.php</code></td><td>Lista koszulek + eksport CSV + zbiorcze przerejestrowanie (POST)</td></tr>
<tr><td><span class="m post">POST</span></td><td><code>/ezd/sprawy/view.php</code></td><td>Centrum koszulki — 17 akcji <code>_action</code> (pliki, pisma, dekretacja, obieg, notatki, współdzielenie)</td></tr>
<tr><td><span class="m get">GET</span></td><td><code>/ezd/sprawy/metryka.php</code></td><td>Metryka sprawy — chronologiczny rejestr z <code>ezd_log</code></td></tr>
<tr><td><span class="m get">GET</span></td><td><code>/ezd/sprawy/print.php</code></td><td>Wydruk koszulki → PDF (okładka + dokumenty)</td></tr>
<tr><td><span class="m post">POST</span></td><td><code>/ezd/sprawy/spinacz.php</code></td><td>Scalanie plików w jeden PDF</td></tr>
<tr><td><span class="m post">POST</span></td><td><code>/ezd/rpw/view.php</code></td><td>Akcje kancelaryjne: scan / edit / przekaz / odrzuc / delete / assign</td></tr>
<tr><td><span class="m get">GET</span></td><td><code>/ezd/rpw/scan.php</code></td><td>Bezpieczne serwowanie skanu przesyłki</td></tr>
<tr><td><span class="m post">POST</span></td><td><code>/ezd/dekretacja/complete.php</code></td><td>Oznaczenie dekretacji jako wykonanej (wykonawca/admin)</td></tr>
<tr><td><span class="m post">POST</span></td><td><code>/ezd/jrwa/index.php</code></td><td>CRUD wykazu akt (modyfikacja tylko admin)</td></tr>
<tr><td><span class="m get">GET</span></td><td><code>/ezd/serve.php</code></td><td>Bezpieczne serwowanie dowolnego załącznika</td></tr>
<tr><td><span class="m get">GET</span></td><td><code>/ezd/office_online.php</code></td><td>Redirect do edytora Office Online (przez SharePoint/Graph)</td></tr>
<tr><td><span class="m post">POST</span></td><td><code>/ezd/office_online_pull.php</code></td><td>Pull treści po edycji — <span class="pill acc">JSON (AJAX)</span></td></tr>
<tr><td><span class="m get">GET</span></td><td><code>/ezd/validate_signature.php</code></td><td>Walidacja podpisu elektronicznego — <span class="pill acc">JSON</span></td></tr>
<tr><td><span class="m get">GET</span></td><td><code>/ezd/kopia.php</code></td><td>Wydruk kopii dokumentu elektronicznego → PDF. <code>type</code> = pismo | dokument | umowa | zalacznik | zaswiadczenie, <code>id</code>, opcjonalnie <code>wm=0</code> (bez znaku wodnego), <code>dl=1</code> (pobranie)</td></tr>
</table></div>

<div class="note"><b>Endpointy nie-HTML.</b> Dwa realne endpointy JSON:
<code>validate_signature.php</code> (zawsze JSON) i <code>office_online_pull.php</code> (JSON tylko w trybie
<code>_ajax=1</code>). Strumienie binarne: <code>serve.php</code>, <code>rpw/scan.php</code>. CSV:
<code>sprawy/index.php?export=csv</code>. Wydruk PDF: <code>sprawy/print.php?out=pdf</code>,
<code>zaswiadczenia/pdf.php</code>, <code>kopia.php</code> (kopia dokumentu elektronicznego).</div>
</section>

<!-- ============================================ OPENAPI ============= -->
<section id="openapi">
<h2>Specyfikacja OpenAPI <a class="anchor" href="#openapi">#</a></h2>
<p>Kompletna, maszynowa specyfikacja całej powierzchni HTTP modułu (47 ścieżek, 15 schematów
komponentów) znajduje się w pliku <code>docs/ezd/openapi.yaml</code> (OpenAPI 3.0.3) i jest
renderowana interaktywnie w <a href="./index.php">Swagger UI</a>. Można ją też zaimportować do
Redoc / Postman lub wygenerować z niej klienta.</p>
<h4>Podgląd lokalny / walidacja</h4>
<pre><code># Swagger UI (Docker)
docker run -p 8080:8080 \
  -e SWAGGER_JSON=/spec/openapi.yaml \
  -v $(pwd)/docs/ezd:/spec swaggerapi/swagger-ui

# Redoc (npx)
npx @redocly/cli preview-docs docs/ezd/openapi.yaml

# Walidacja
npx @redocly/cli lint docs/ezd/openapi.yaml</code></pre>
<div class="note warn"><b>Uwaga o bezpieczeństwie.</b> To nie jest publiczne API — dostęp wymaga aktywnej
sesji SZO (schemat <code>sessionCookie</code>/PHPSESSID), włączonego modułu i (opcjonalnie) połączenia VPN.
Pole <code>_csrf</code> jest obowiązkowe w każdym POST. Sama dokumentacja (Swagger UI + ten przewodnik)
jest zabramkowana logowaniem Microsoft 365.</div>
</section>

<!-- ============================================ UPRAWNIENIA ========= -->
<section id="uprawnienia">
<h2>Model uprawnień <a class="anchor" href="#uprawnienia">#</a></h2>
<p>Model dostępu EZD jest bogatszy niż zwykłe R/Z/U na poziomie roli. Obok uprawnień ról działają
relacje do <b>konkretnej koszulki</b> oraz reguły domykania. Źródło:
<code>ezd_sprawa_access()</code>, <code>ezd_sprawa_can_manage_share()</code>.</p>

<div class="tablewrap"><table>
<tr><th>Czynność</th><th>admin</th><th>rola&nbsp;Z</th><th>rola&nbsp;R</th><th>właściciel/referent</th><th>współdz.&nbsp;edycja</th><th>współdz.&nbsp;odczyt</th></tr>
<tr><td>Podgląd koszulki</td><td>✔</td><td>✔</td><td>✔</td><td>✔</td><td>✔</td><td>✔</td></tr>
<tr><td>Edycja / akcje (pliki, pisma…)</td><td>✔</td><td>✔</td><td>—</td><td>✔</td><td>✔</td><td>—</td></tr>
<tr><td>Zarządzanie współdzieleniem</td><td>✔</td><td>✔</td><td>—</td><td>✔</td><td>—</td><td>—</td></tr>
<tr><td>Edycja / otwarcie <b>zamkniętej</b></td><td>✔</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td></tr>
<tr><td>Zarządzanie JRWA / teczkami</td><td>✔</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td></tr>
</table></div>

<div class="note key"><b>Reguła domknięcia (komentarz w kodzie):</b> „Koszulka domknięta jest
zablokowana dla wszystkich poza administratorem." Zarządzanie JRWA i segregatorami — „Wyłącznie
administrator." Utworzenie koszulki — każdy zalogowany z dostępem do modułu; twórca staje się właścicielem.</div>

<h4>Brama zapisu</h4>
<p><code>can_edit()</code> = <code>can_write('umowy') || can_write('granty') || can_write('ezd')</code>
— to podstawowa brama zapisu w kontrolerach. W widoku koszulki dodatkowo:</p>
<ul>
<li><code>$can_act</code> = write <b>i</b> koszulka niezamknięta — akcje na plikach/pismach/obiegu,</li>
<li><code>$can_manage_share</code> — dodawanie/usuwanie współdzielenia.</li>
</ul>

<h4>Dostęp tylko przez VPN</h4>
<p>Całe <code>/ezd/</code> może być objęte ograniczeniem sieciowym (<code>ezd_vpn_only</code> +
<code>ezd_vpn_allowlist</code>, CIDR/wildcard/IPv6), niezależnie od roli. Konto <code>serwis@local</code>
jest zawsze wykluczone (break-glass). Konfiguracja: <code>admin/org_settings.php?tab=security</code>;
podgląd modelu: <code>admin/ezd_access_matrix.php</code>.</p>
</section>

<!-- ============================================ WORKFLOW =========== -->
<section id="workflow">
<h2>Workflow (BPM) <a class="anchor" href="#workflow">#</a></h2>
<p>Każda klasa JRWA może mieć własną, wykonywalną ścieżkę etapów. Gdy brak własnej definicji,
używana jest ścieżka domyślna. Dyspozycja dekretacji przesuwa etap <b>tylko do przodu</b>
(nigdy wstecz).</p>

<div class="chain" style="margin:1rem 0">
  <span class="step">Wszczęcie</span><span class="a">→</span>
  <span class="step">Dekretacja</span><span class="a">→</span>
  <span class="step">Realizacja</span><span class="a">→</span>
  <span class="step">Akceptacja</span><span class="a">→</span>
  <span class="step">Podpis</span><span class="a">→</span>
  <span class="step">Wysyłka</span><span class="a">→</span>
  <span class="step">Zakończenie</span>
</div>

<div class="tablewrap"><table class="fn">
<tr><th>Funkcja</th><th>Opis</th></tr>
<tr><td><code>ezd_workflow_steps(?int $jrwa_id): array</code></td><td>Kroki własne dla JRWA lub domyślne.</td></tr>
<tr><td><code>ezd_workflow_save(int $jrwa_id, string $name, array $steps, int $uid)</code></td><td>Zapis/aktualizacja workflow (min. 1 etap). Krok = etykieta, kolor, ikona, dyspozycja, <code>sla_days</code>.</td></tr>
<tr><td><code>ezd_sprawa_set_etap(int $id, string $etap, int $uid)</code></td><td>Ustawia etap z walidacją; ostatni krok zamyka sprawę (o ile nie ciągła).</td></tr>
<tr><td><code>ezd_workflow_to_bpmn(array $steps, string $name): string</code></td><td>Eksport liniowej ścieżki do BPMN 2.0 XML (start → zadania → koniec).</td></tr>
</table></div>
<p>Edytor procesów: <code>admin/ezd_workflows.php</code> (podgląd SVG), eksport:
<code>admin/ezd_workflow_bpmn.php?jrwa_id=</code> (Content-Type <code>application/bpmn+xml</code>).</p>
</section>

<!-- ============================================ ARCHIWUM =========== -->
<section id="archiwum">
<h2>Archiwum zakładowe <a class="anchor" href="#archiwum">#</a></h2>
<p>Cykl życia dokumentacji po zakończeniu: teczka zamknięta → przekazanie do archiwum zakładowego
(spis zdawczo-odbiorczy) → po okresie przechowywania brakowanie (protokół) lub, dla kat. A,
przekazanie do archiwum państwowego. Kategoria archiwalna dziedziczona z JRWA.
Logika: <code>includes/ezd_archiwum.php</code>.</p>

<div class="tablewrap"><table>
<tr><th>Kategoria</th><th>Retencja</th><th>Postępowanie</th></tr>
<tr><td><code>A</code></td><td>wieczyste</td><td>Przekazanie do archiwum państwowego (arch_status = <code>ap</code>)</td></tr>
<tr><td><code>B5 / B10 / B25 / B50</code></td><td>5 / 10 / 25 / 50 lat</td><td>Brakowanie po upływie okresu</td></tr>
<tr><td><code>BE5 / BE10</code></td><td>5 / 10 lat + ekspertyza</td><td>Ekspertyza archiwum przed brakowaniem</td></tr>
<tr><td><code>Bc</code></td><td>0 (bieżące)</td><td>Brakowanie po utracie przydatności</td></tr>
</table></div>

<div class="note"><b>Rok brakowania</b> = rok zakończenia + retencja + 1. „Okres przechowywania liczony
od 1 stycznia roku następującego po zakończeniu sprawy, stąd +1" (komentarz w kodzie,
<code>ezd_rok_brakowania()</code>).</div>

<p>Realizacja spisu (<code>ezd_arch_spis_realize()</code>) działa w transakcji z rollbackiem:
zdawczo-odbiorczy → <code>arch_status='archiwum'</code> (lub <code>'ap'</code> dla kat. A) i zamknięcie teczki;
brakowanie → <code>arch_status='wybrakowana'</code>. Realizacja i usunięcie spisu wymaga uprawnień admina.</p>
</section>

<!-- ============================================ INTEGRACJE ========= -->
<section id="integracje">
<h2>Integracje zewnętrzne <a class="anchor" href="#integracje">#</a></h2>
<div class="grid g2">
  <div class="card"><h4>Microsoft 365 Graph / SharePoint</h4>
    <p>Rdzeń podsystemu plików: wysyłka na SharePoint, edycja w Office Online, konwersja do PDF,
    spinacz. Klasa <code>M365Graph</code> (<code>includes/m365.php</code>). Wszystkie synchronizacje są
    <b>fire-and-forget</b> — błąd nie blokuje operacji lokalnej.</p></div>
  <div class="card"><h4>Asystent AI (Claude / Anthropic)</h4>
    <p><code>includes/ezd_ai.php</code> — kwalifikacja spraw do klasy JRWA i przerejestrowanie
    (structured outputs, model domyślnie <code>claude-opus-4-8</code>). Buduje wykaz JRWA jako
    system-prompt; zwraca walidowany JSON z kodem klasy, adnotacjami i instrukcją.</p></div>
  <div class="card"><h4>Podpis elektroniczny (walidacja)</h4>
    <p><code>ezd/validate_signature.php</code> + <code>includes/sigcheck.php</code> — kryptograficzna
    walidacja certyfikatu i integralności przez <code>openssl</code> CLI; przy braku narzędzia
    fallback do zewnętrznego walidatora.</p></div>
  <div class="card"><h4>eDoręczenia</h4>
    <p>Kafelek na pulpicie z adresem ADE i linkiem do app.edopost.pl; media pism obejmują
    <code>epuap</code>. Konfiguracja w ustawieniach EZD.</p></div>
</div>
<div class="note"><b>Podpisy zewnętrzne (Autenti / DocuSign).</b> Webhooki
<code>api/autenti_webhook.php</code> i <code>api/docusign_webhook.php</code> obsługują moduł umów
i zaświadczeń (<code>umowy_*</code>, <code>certificate_requests</code>) — z EZD powiązane pośrednio
przez dowiązanie umów jako załączników koszulki. <code>api/m365_webhook.php</code> synchronizuje konta
Azure AD, nie dokumenty EZD.</div>
</section>

<!-- ============================================ REJESTRY =========== -->
<section id="rejestry">
<h2>Rejestry pochodne (auto-rejestracja) <a class="anchor" href="#rejestry">#</a></h2>
<p>Kilka modułów SZO automatycznie zakłada w EZD sprawy ciągłe i pisma pod dedykowanymi klasami JRWA
(konfigurowalnymi). Każda rejestracja jest idempotentna.</p>
<div class="tablewrap"><table class="fn">
<tr><th>Rejestr</th><th>JRWA (dom.)</th><th>Funkcja / źródło</th></tr>
<tr><td>Pełnomocnictwa</td><td><code>013</code></td><td><code>ezd_pelnomocnictwo_save()</code> — metadane 1:1 ze sprawą</td></tr>
<tr><td>Zaświadczenia</td><td><code>53</code></td><td><code>ezd_register_certificate()</code>, backfill zaległych</td></tr>
<tr><td>Korespondencja</td><td><code>KOR</code></td><td>Autorejestracja przychodzącej/wychodzącej do sprawy ciągłej</td></tr>
<tr><td>Dok. księgowe</td><td><code>KSG</code></td><td><code>kdok_register_in_ezd()</code> (w <code>includes/ksiegowosc.php</code>)</td></tr>
<tr><td>Pisma do wolontariuszy</td><td><code>WOL</code></td><td><code>ezd_register_volunteer_letter()</code> — ZIP + hasło SMS</td></tr>
<tr><td>Weryfikacja RPTS</td><td><code>KAD</code></td><td><code>ezd_register_rpts_consent()</code> — jedna sprawa/osoba</td></tr>
<tr><td>Zgoda opiekuna</td><td><code>WOL</code></td><td><code>ezd_register_guardian_consent_letter()</code></td></tr>
</table></div>
</section>

<!-- ============================================ ADMIN ============== -->
<section id="admin">
<h2>Panele administracyjne <a class="anchor" href="#admin">#</a></h2>
<div class="tablewrap"><table class="fn">
<tr><th>Ścieżka</th><th>Funkcja</th></tr>
<tr><td><code>/admin/ezd_settings.php</code></td><td>Włącznik modułu, tryb „mini", przypomnienia (cron), mapowanie symboli JRWA dla rejestrów, autorejestracja korespondencji</td></tr>
<tr><td><code>/admin/ezd_szablony.php</code></td><td>CRUD szablonów pism z tokenami <code>{{token}}</code> (mail merge)</td></tr>
<tr><td><code>/admin/ezd_access_matrix.php</code></td><td>Macierz uprawnień (tylko odczyt) + status VPN; <code>?print=1</code> A4</td></tr>
<tr><td><code>/admin/ezd_workflows.php</code></td><td>Edytor procesów BPM per JRWA (podgląd SVG)</td></tr>
<tr><td><code>/admin/ezd_workflow_bpmn.php</code></td><td>Eksport workflow do BPMN 2.0 (.bpmn)</td></tr>
</table></div>
<div class="note"><b>Tryb „mini".</b> Uproszczony rejestr spraw i dokumentów — ukrywa formalne
elementy postępowania w widoku sprawy (metrykę oraz obieg/workflow BPM). Ustawienie org
<code>ezd_mini</code>, funkcja <code>ezd_mini(): bool</code>.</div>
</section>

<!-- ============================================ PLIKI ============== -->
<section id="pliki">
<h2>Mapa plików źródłowych <a class="anchor" href="#pliki">#</a></h2>
<div class="tablewrap"><table class="fn">
<tr><th>Plik</th><th>Zawartość</th></tr>
<tr><td><code>includes/ezd.php</code></td><td>Rdzeń: auto-migracja schematu (21 tabel), ~150 funkcji, stałe/słowniki, seed JRWA</td></tr>
<tr><td><code>includes/ezd_archiwum.php</code></td><td>Archiwum zakładowe — spisy, retencja, brakowanie</td></tr>
<tr><td><code>includes/ezd_szablony.php</code></td><td>Szablony pism + silnik tokenów <code>{{…}}</code></td></tr>
<tr><td><code>includes/ezd_kopia.php</code></td><td>Wydruk kopii dokumentu elektronicznego — metryka, znak wodny, strona poświadczenia zgodności (dla każdego typu dokumentu)</td></tr>
<tr><td><code>includes/ezd_ai.php</code></td><td>Asystent AI — kwalifikacja JRWA (Claude, structured outputs)</td></tr>
<tr><td><code>includes/ezd_*_modal.php</code></td><td>Współdzielone modale UI: podgląd PDF, podpis, pull z Office Online</td></tr>
<tr><td><code>includes/sigcheck.php</code></td><td>Walidacja podpisu elektronicznego (openssl)</td></tr>
<tr><td><code>ezd/**/*.php</code></td><td>Kontrolery stron: sprawy, teczki, pisma, rpw, dekretacja, jrwa, archiwum, pełnomocnictwa, dokumenty, umowy, wolontariusze, zaświadczenia</td></tr>
<tr><td><code>admin/ezd_*.php</code></td><td>Panele: ustawienia, szablony, macierz uprawnień, workflow + eksport BPMN</td></tr>
<tr><td><code>docs/ezd/openapi.yaml</code></td><td>Maszynowa specyfikacja OpenAPI 3.0 interfejsu HTTP</td></tr>
<tr><td><code>docs/ezd/index.php</code></td><td>Swagger UI (renderuje openapi.yaml, zabramkowany MS365)</td></tr>
<tr><td><code>docs/ezd/przewodnik.php</code></td><td>Ten przewodnik programisty</td></tr>
</table></div>
</section>

<footer>
  <p>Przewodnik programisty modułu EZD „Wirtualne Biurko" — System Obsługi Organizacji (FEER).
  Wygenerowany z komentarzy i kodu źródłowego. Źródło prawdy:
  <code>includes/ezd.php</code>, <code>ezd/**</code>, <code>admin/ezd_*</code>.<br>
  Autorzy: Ziemowit Gil, Jarosław Połczyński, Sebastian Dzienisowicz — na potrzeby FEER'a.</p>
</footer>

</main>
</div>

<script>
(function(){
  var btn=document.getElementById('themebtn');
  function cur(){var t=document.documentElement.getAttribute('data-theme');
    if(t)return t;return matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';}
  btn.addEventListener('click',function(){
    var next=cur()==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-theme',next);
  });
})();
</script>
</body>
</html>
