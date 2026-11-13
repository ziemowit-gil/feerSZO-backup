<?php
/**
 * chatbot/index.php — Publiczna, samodzielna strona asystenta AI z Pomocą IT.
 *
 * Dostęp po tokenie: /chatbot/{token} (rewrite → ?t={token}). /chatbot/ bez tokenu przekierowuje na bieżący
 * adres z tokenem (stały link do artykułu w Pomocy IT i kafla w panelu — nie psuje się po zmianie tokenu).
 * Bez logowania — przeznaczona do udostępnienia współpracownikom. Strona samodzielna (NIE używa includes/header.php).
 *
 * Układ: rozmowa (lewa kolumna) + panel „Pomoc IT” (prawa): trwające awarie z Portalu Usług IT, wyszukiwarka
 * i popularne artykuły, droga do Helpdesku. Rozmowa idzie przez chatbot/ai.php (ten sam token); agent ma
 * narzędzie „pomoc_it” (artykuły i komunikaty /uslugi-it) obok bazy wiedzy i funkcji systemu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/branding.php';
require_once dirname(__DIR__) . '/includes/asystent_ai.php';

$token = trim($_GET['t'] ?? '');
if ($token === '' && asai_public_enabled()) {   // stały adres /chatbot/ → aktualny link z tokenem
    header('Location: ' . asai_public_url(), true, 302);
    exit;
}
$valid = asai_public_token_valid($token);

$brand    = branding_load();
$org_name = $brand['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : 'Organizacja');
$logo_url = $brand['logo_url'] ?? '';

/** Kolor marki przyciemniony tak, by biały tekst miał kontrast ≥ 4,5:1 (WCAG AA). */
function chatbot_accent(string $hex): string {
    if (!preg_match('/^#?([0-9a-f]{6})$/i', trim($hex), $m)) return '#1d4ed8';
    [$r, $g, $b] = array_map('hexdec', str_split($m[1], 2));
    $lum = static function (float $r, float $g, float $b): float {
        $c = array_map(static fn($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, [$r, $g, $b]);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    for ($i = 0; $i < 20 && 1.05 / ($lum($r, $g, $b) + 0.05) < 4.5; $i++) { $r *= .88; $g *= .88; $b *= .88; }
    return sprintf('#%02x%02x%02x', (int)$r, (int)$g, (int)$b);
}
$accent = chatbot_accent($brand['primary'] ?: '#2563eb');

$it_url     = rtrim(APP_URL, '/') . '/uslugi-it/';
$hd_url     = rtrim(APP_URL, '/') . '/helpdesk/new.php';
$login_url  = rtrim(APP_URL, '/') . '/auth/report_login_issue.php';
$contact_url= rtrim(APP_URL, '/') . '/uslugi-it/kontakt';
$notices    = $valid ? asai_it_open_notices(3) : [];
$popular    = $valid ? asai_it_popular(6) : [];
$it_on      = $valid && asai_it_available();

$chips_it = ['Jak zmienić hasło do poczty?', 'Jak skonfigurować pocztę w telefonie?', 'Nie mogę się zalogować — co robić?', 'Jak wysłać bardzo duży plik?'];
$chips_kb = ['Jak rozliczyć zwrot kosztów wolontariusza?', 'Gdzie wpisuje się godziny wolontariatu?', 'Jakie dokumenty przy rozwiązaniu umowy?', 'Zasady ochrony danych (RODO)'];

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
header('X-Robots-Tag: noindex, nofollow');
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Asystent i Pomoc IT · <?= $h($org_name) ?></title>
<link rel="icon" href="<?= APP_URL ?>/assets/img/icon-192.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  :root{
    --accent: <?= $h($accent) ?>;
    --bg:#f4f6fa; --card:#ffffff; --line:#d9dee7; --ink:#0f172a; --muted:#475569;
    --soft:#f1f4f9; --radius:18px; --shadow:0 18px 50px -26px rgba(15,23,42,.30);
    --focus:#c50f1f; --ok:#166534; --warn:#92400e; --bad:#b91c1c;
  }
  @media (prefers-color-scheme: dark){
    :root{ --bg:#0b1020; --card:#121a2e; --line:#2b3852; --ink:#e8ecf5; --muted:#a9b4c6;
           --soft:#18223b; --shadow:0 24px 70px -30px rgba(0,0,0,.7); --focus:#ff7b86;
           --ok:#86efac; --warn:#fcd34d; --bad:#fca5a5; }
  }
  *{ box-sizing:border-box; }
  html,body{ height:100%; margin:0; }
  body{ font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
    color:var(--ink); background:var(--bg); -webkit-font-smoothing:antialiased; line-height:1.5; }
  a{ color:inherit; }
  :focus-visible{ outline:3px solid var(--focus); outline-offset:2px; border-radius:6px; }
  [hidden]{ display:none !important; }
  .sr-only{ position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
  .skip{ position:absolute; left:-9999px; top:.5rem; z-index:10; background:var(--card); color:var(--ink);
    padding:.6rem 1rem; border:2px solid var(--focus); border-radius:10px; }
  .skip:focus{ left:.5rem; }

  .wrap{ max-width:1240px; margin:0 auto; min-height:100dvh; display:flex; flex-direction:column; padding:clamp(10px,2.4vw,24px); gap:14px; }

  /* Nagłówek */
  .top{ display:flex; align-items:center; gap:.85rem; flex-wrap:wrap; }
  .brand-logo{ height:40px; width:auto; border-radius:8px; }
  .brand-mark{ width:44px; height:44px; border-radius:13px; display:flex; align-items:center; justify-content:center;
    font-size:1.35rem; color:#fff; background:var(--accent); flex-shrink:0; }
  .top h1{ font-size:1.08rem; font-weight:750; margin:0; letter-spacing:-.01em; }
  .top .sub{ font-size:.82rem; color:var(--muted); }
  .top nav{ margin-left:auto; display:flex; gap:.5rem; flex-wrap:wrap; }
  .btn{ display:inline-flex; align-items:center; gap:.45rem; min-height:44px; padding:.5rem .95rem; border-radius:12px;
    font:inherit; font-size:.88rem; font-weight:600; text-decoration:none; cursor:pointer; border:1px solid var(--line);
    background:var(--card); color:var(--ink); }
  .btn:hover{ border-color:var(--accent); }
  .btn.primary{ background:var(--accent); border-color:var(--accent); color:#fff; }
  .btn.primary:hover{ filter:brightness(1.1); }

  /* Siatka: rozmowa + Pomoc IT */
  .grid{ flex:1; display:grid; grid-template-columns:minmax(0,1fr) 340px; gap:14px; min-height:0; }
  @media (max-width: 960px){ .grid{ grid-template-columns:1fr; } }
  .card{ background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); }

  /* Rozmowa */
  .chat{ display:flex; flex-direction:column; min-height:min(78dvh,760px); overflow:hidden; }
  .chat-head{ display:flex; align-items:center; gap:.6rem; padding:.75rem 1rem; border-bottom:1px solid var(--line); }
  .chat-head h2{ font-size:.95rem; margin:0; font-weight:700; }
  .chat-head .status{ font-size:.78rem; color:var(--muted); display:inline-flex; align-items:center; gap:.35rem; }
  .chat-head .status .dot{ width:8px; height:8px; border-radius:50%; background:#16a34a; }
  .chat-head .btn{ margin-left:auto; min-height:38px; padding:.35rem .75rem; font-size:.8rem; }
  .log{ flex:1; overflow-y:auto; padding:clamp(14px,2.6vw,24px); }

  .msg{ display:flex; gap:.7rem; margin-bottom:1.2rem; }
  .msg .av{ width:34px; height:34px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
  .msg.ai .av{ background:var(--soft); color:var(--accent); border:1px solid var(--line); }
  .msg.user{ flex-direction:row-reverse; }
  .msg.user .av{ background:var(--ink); color:var(--card); }
  .bubble{ max-width:min(82%,680px); border-radius:15px; padding:.75rem 1rem; font-size:.95rem; line-height:1.62; overflow-wrap:anywhere; }
  .msg.ai .bubble{ background:var(--soft); border:1px solid var(--line); border-top-left-radius:5px; }
  .msg.user .bubble{ background:var(--accent); color:#fff; border-top-right-radius:5px; }
  .bubble>*:first-child{ margin-top:0; } .bubble>*:last-child{ margin-bottom:0; }
  .bubble h1,.bubble h2,.bubble h3{ font-size:1rem; font-weight:700; margin:.7rem 0 .35rem; }
  .bubble ul,.bubble ol{ padding-left:1.25rem; margin:.4rem 0; }
  .bubble a{ color:var(--accent); text-decoration:underline; font-weight:600; }
  @media (prefers-color-scheme: dark){ .bubble a{ color:#93c5fd; } }
  .bubble code{ background:var(--card); border:1px solid var(--line); border-radius:5px; padding:.05em .35em; font-size:.86em; }
  .bubble pre{ background:var(--card); border:1px solid var(--line); border-radius:10px; padding:.7rem .85rem; overflow:auto; }
  .bubble table{ width:100%; border-collapse:collapse; margin:.5rem 0; font-size:.86rem; }
  .bubble th,.bubble td{ border:1px solid var(--line); padding:.35rem .55rem; }

  .intro-title{ font-weight:700; margin:0 0 .25rem; }
  .chips-label{ font-size:.78rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; margin:.9rem 0 .4rem; }
  .chips{ display:flex; flex-wrap:wrap; gap:.45rem; }
  .chip{ font:inherit; font-size:.85rem; min-height:40px; padding:.4rem .85rem; border:1px solid var(--line); border-radius:2rem;
    background:var(--card); color:var(--ink); cursor:pointer; text-align:left; }
  .chip:hover{ border-color:var(--accent); }
  .chip i{ color:var(--accent); margin-right:.3rem; }
  .note{ margin-top:.85rem; font-size:.8rem; color:var(--muted); }

  /* Źródła i kroki */
  .sources{ margin-top:.8rem; display:flex; flex-wrap:wrap; gap:.4rem; }
  .src{ display:inline-flex; align-items:center; gap:.4rem; text-decoration:none !important; font-size:.8rem; font-weight:500 !important;
    padding:.35rem .75rem; border:1px solid var(--line); border-radius:2rem; background:var(--card); color:var(--ink) !important; min-height:34px; }
  .src:hover{ border-color:var(--accent); }
  .src .tag{ font-size:.7rem; color:var(--muted); }
  .src.it{ border-color:color-mix(in srgb, var(--accent) 45%, var(--line)); }
  .tools{ margin-top:.7rem; display:flex; gap:.4rem; flex-wrap:wrap; }
  .tools button{ font:inherit; font-size:.76rem; min-height:32px; padding:.2rem .65rem; border-radius:8px; border:1px solid var(--line);
    background:var(--card); color:var(--muted); cursor:pointer; }
  .tools button:hover{ color:var(--ink); border-color:var(--accent); }
  details.steps{ margin-top:.6rem; font-size:.78rem; color:var(--muted); }
  details.steps summary{ cursor:pointer; }
  details.steps .st{ display:flex; gap:.45rem; padding:.12rem 0; }
  details.steps .st i{ color:var(--accent); }
  .err{ color:var(--bad); }

  .typing span{ display:inline-block; width:6px; height:6px; margin:0 1px; border-radius:50%; background:var(--accent); animation:bounce 1.2s infinite; }
  .typing span:nth-child(2){ animation-delay:.15s; } .typing span:nth-child(3){ animation-delay:.3s; }
  @keyframes bounce{ 0%,60%,100%{ transform:translateY(0); opacity:.4; } 30%{ transform:translateY(-4px); opacity:1; } }
  @media (prefers-reduced-motion: reduce){ .typing span{ animation:none; } }

  /* Pole pytania */
  .composer{ border-top:1px solid var(--line); padding:.75rem; }
  .field{ display:flex; gap:.6rem; align-items:flex-end; background:var(--bg); border:1px solid var(--line);
    border-radius:16px; padding:.4rem .5rem .4rem .9rem; }
  .field:focus-within{ border-color:var(--accent); box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 25%, transparent); }
  .field textarea{ flex:1; border:0; outline:0; background:transparent; color:var(--ink); font:inherit; font-size:.95rem;
    resize:none; max-height:160px; padding:.5rem 0; line-height:1.5; }
  .field textarea::placeholder{ color:var(--muted); }
  .send{ width:44px; height:44px; flex-shrink:0; border:0; border-radius:12px; color:#fff; cursor:pointer; background:var(--accent);
    display:flex; align-items:center; justify-content:center; font-size:1.05rem; }
  .send:disabled{ opacity:.55; cursor:default; }
  .foot{ font-size:.74rem; color:var(--muted); margin:.5rem .2rem 0; }

  /* Panel Pomoc IT */
  aside{ display:flex; flex-direction:column; gap:14px; }
  .side{ padding:1rem 1.05rem; }
  .side h2{ font-size:.95rem; font-weight:750; margin:0 0 .6rem; display:flex; align-items:center; gap:.45rem; }
  .side h2 i{ color:var(--accent); }
  .side p{ margin:.2rem 0 .7rem; font-size:.86rem; color:var(--muted); }
  .search{ display:flex; gap:.4rem; }
  .search input{ flex:1; min-width:0; min-height:44px; font:inherit; font-size:.9rem; padding:.45rem .7rem; border-radius:10px;
    border:1px solid var(--line); background:var(--bg); color:var(--ink); }
  .search .btn{ padding:.45rem .7rem; }
  .links{ list-style:none; margin:.7rem 0 0; padding:0; }
  .links li + li{ border-top:1px solid var(--line); }
  .links a{ display:flex; gap:.55rem; align-items:flex-start; padding:.55rem .1rem; text-decoration:none; font-size:.88rem; min-height:44px; }
  .links a:hover span{ text-decoration:underline; }
  .links a i{ color:var(--accent); margin-top:.15rem; }
  .more{ display:inline-block; margin-top:.6rem; font-size:.84rem; font-weight:600; color:var(--accent); }
  @media (prefers-color-scheme: dark){ .more{ color:#93c5fd; } }
  .notice{ display:flex; gap:.6rem; padding:.55rem .65rem; border-radius:12px; border:1px solid var(--line); background:var(--soft);
    text-decoration:none; font-size:.86rem; margin-top:.45rem; }
  .notice .badge{ font-size:.7rem; font-weight:700; padding:.1rem .45rem; border-radius:6px; border:1px solid currentColor; white-space:nowrap; height:fit-content; }
  .notice .badge.zgloszone{ color:var(--bad); } .notice .badge.w_toku{ color:var(--warn); }
  .side.alert{ border-color:var(--bad); }
  .side .btn{ width:100%; justify-content:center; margin-top:.4rem; }

  /* Link nieaktywny */
  .gate{ max-width:520px; margin:auto; text-align:center; padding:2.5rem 2rem; }
  .gate .ic{ width:64px; height:64px; border-radius:18px; margin:0 auto 1rem; display:flex; align-items:center; justify-content:center;
    font-size:1.8rem; background:var(--soft); color:var(--bad); }
</style>
</head>
<body>
<?php if (!$valid): ?>
  <main class="wrap" style="justify-content:center">
    <div class="card gate">
      <div class="ic" aria-hidden="true"><i class="bi bi-slash-circle"></i></div>
      <h1 style="font-size:1.15rem; font-weight:750; margin:0 0 .5rem">Link nieaktywny</h1>
      <p style="color:var(--muted); margin:0 0 1rem">
        Ten link do asystenta AI jest nieaktywny, został zmieniony lub wyłączony.
        Poproś administratora <?= $h($org_name) ?> o aktualny adres.
      </p>
      <a class="btn" href="<?= $h($it_url) ?>"><i class="bi bi-life-preserver" aria-hidden="true"></i>Przejdź do Pomocy IT</a>
    </div>
  </main>
<?php else: ?>
  <a class="skip" href="#input">Przejdź do pola pytania</a>
  <div class="wrap">
    <header class="top">
      <?php if ($logo_url): ?>
        <img class="brand-logo" src="<?= $h($logo_url) ?>" alt="<?= $h($org_name) ?>">
      <?php else: ?>
        <div class="brand-mark" aria-hidden="true"><i class="bi bi-chat-square-heart"></i></div>
      <?php endif; ?>
      <div>
        <h1>Asystent i Pomoc IT</h1>
        <div class="sub"><?= $h($org_name) ?> · procedury, obsługa systemu i sprawy techniczne</div>
      </div>
      <nav aria-label="Pomoc">
        <a class="btn" href="<?= $h($it_url) ?>" target="_blank" rel="noopener"><i class="bi bi-life-preserver" aria-hidden="true"></i>Pomoc IT</a>
        <a class="btn primary" href="<?= $h($hd_url) ?>" target="_blank" rel="noopener"><i class="bi bi-headset" aria-hidden="true"></i>Zgłoś problem</a>
      </nav>
    </header>

    <div class="grid">
      <main class="card chat" aria-labelledby="chat-title">
        <div class="chat-head">
          <h2 id="chat-title">Rozmowa z asystentem</h2>
          <span class="status"><span class="dot" aria-hidden="true"></span>dostępny</span>
          <button type="button" class="btn" id="reset" hidden><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Nowa rozmowa</button>
        </div>

        <div class="log" id="log" role="log" aria-live="polite" aria-relevant="additions" tabindex="-1">
          <div class="msg ai" id="intro">
            <div class="av" aria-hidden="true"><i class="bi bi-robot"></i></div>
            <div class="bubble">
              <p class="intro-title">Cześć! W czym mogę pomóc?</p>
              Odpowiadam na pytania o <strong>sprawy IT</strong> (poczta, hasła, logowanie, chmura, VPN),
              o <strong>procedury i dokumenty</strong> <?= $h($org_name) ?> oraz o to, <strong>gdzie coś zrobić w systemie</strong>.
              Przy każdej odpowiedzi podaję źródła, w tym artykuły z Pomocy IT.
              <?php if ($it_on): ?>
              <div class="chips-label" id="lbl-it">Pomoc IT</div>
              <div class="chips" role="group" aria-labelledby="lbl-it">
                <?php foreach ($chips_it as $c): ?><button type="button" class="chip"><i class="bi bi-life-preserver" aria-hidden="true"></i><?= $h($c) ?></button><?php endforeach; ?>
              </div>
              <?php endif; ?>
              <div class="chips-label" id="lbl-kb">Procedury i system</div>
              <div class="chips" role="group" aria-labelledby="lbl-kb">
                <?php foreach ($chips_kb as $c): ?><button type="button" class="chip"><i class="bi bi-journal-text" aria-hidden="true"></i><?= $h($c) ?></button><?php endforeach; ?>
              </div>
              <p class="note"><i class="bi bi-info-circle" aria-hidden="true"></i> Ten link działa bez logowania, więc nie widzę kont ani danych osobowych.
                Nie podawaj tu haseł ani numeru PESEL.</p>
            </div>
          </div>
        </div>

        <div class="composer">
          <form id="form" class="field">
            <label for="input" class="sr-only">Twoje pytanie</label>
            <textarea id="input" rows="1" placeholder="Napisz pytanie, np. „nie mogę wysłać maila z telefonu”" autocomplete="off" aria-describedby="foot"></textarea>
            <button type="submit" id="send" class="send"><i class="bi bi-send-fill" aria-hidden="true"></i><span class="sr-only">Wyślij pytanie</span></button>
          </form>
          <p class="foot" id="foot">Enter wysyła, Shift+Enter nowa linia. Odpowiedzi generuje AI — przy decyzjach formalnych sprawdź źródło.</p>
        </div>
      </main>

      <aside aria-label="Pomoc IT">
        <?php if ($notices): ?>
        <section class="card side alert" aria-labelledby="ntc-title">
          <h2 id="ntc-title"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Trwające utrudnienia</h2>
          <?php foreach ($notices as $n): ?>
            <a class="notice" href="<?= $h(rtrim(APP_URL, '/') . '/uslugi-it/komunikaty/' . (int)$n['id']) ?>" target="_blank" rel="noopener">
              <span class="badge <?= $h($n['status']) ?>"><?= $h(ITP_NOTICE_STATUSES[$n['status']]['label'] ?? $n['status']) ?></span>
              <span><?= $h($n['title']) ?><br><small style="color:var(--muted)"><?= $h(date('d.m.Y', strtotime((string)$n['notice_date']))) ?></small></span>
            </a>
          <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php if ($it_on): ?>
        <section class="card side" aria-labelledby="it-title">
          <h2 id="it-title"><i class="bi bi-life-preserver" aria-hidden="true"></i>Pomoc IT</h2>
          <p>Instrukcje krok po kroku: poczta, hasła, logowanie, chmura, VPN.</p>
          <form class="search" action="<?= $h($it_url . 'szukaj') ?>" method="get" target="_blank" role="search">
            <label for="itq" class="sr-only">Szukaj w Pomocy IT</label>
            <input id="itq" type="search" name="q" placeholder="Szukaj w Pomocy IT…">
            <button class="btn" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="sr-only">Szukaj</span></button>
          </form>
          <?php if ($popular): ?>
          <h3 class="chips-label" style="margin-top:1rem">Popularne artykuły</h3>
          <ul class="links">
            <?php foreach ($popular as $a): ?>
              <li><a href="<?= $h($a['url']) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><span><?= $h($a['title']) ?></span></a></li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
          <a class="more" href="<?= $h($it_url . 'uslugi') ?>" target="_blank" rel="noopener">Wszystkie usługi i artykuły <span aria-hidden="true">→</span></a>
        </section>
        <?php endif; ?>

        <section class="card side" aria-labelledby="hd-title">
          <h2 id="hd-title"><i class="bi bi-headset" aria-hidden="true"></i>Nie znalazłeś odpowiedzi?</h2>
          <p>Zgłoś sprawę do Helpdesku IT — odpowiemy e-mailem.</p>
          <a class="btn primary" href="<?= $h($hd_url) ?>" target="_blank" rel="noopener"><i class="bi bi-plus-circle" aria-hidden="true"></i>Zgłoś problem (po zalogowaniu)</a>
          <a class="btn" href="<?= $h($login_url) ?>" target="_blank" rel="noopener"><i class="bi bi-door-closed" aria-hidden="true"></i>Nie mogę się zalogować</a>
          <a class="btn" href="<?= $h($contact_url) ?>" target="_blank" rel="noopener"><i class="bi bi-telephone" aria-hidden="true"></i>Kontakt z działem IT</a>
        </section>
      </aside>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/marked@9/marked.min.js"></script>
  <script>
  (function(){
    const TOKEN    = <?= json_encode($token) ?>;
    const ENDPOINT = <?= json_encode(APP_URL . '/chatbot/ai.php') ?>;
    const log   = document.getElementById('log');
    const form  = document.getElementById('form');
    const input = document.getElementById('input');
    const send  = document.getElementById('send');
    const reset = document.getElementById('reset');
    const intro = document.getElementById('intro').cloneNode(true);
    let history = [];
    let busy = false;

    if (typeof marked !== 'undefined') marked.setOptions({ breaks:true, gfm:true });
    const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    // Odpowiedź modelu → HTML bez skryptów i zdarzeń (treść z AI nie jest zaufana).
    const clean = html => {
      const t=document.createElement('template'); t.innerHTML=html;
      t.content.querySelectorAll('script,style,iframe,object,embed,form').forEach(n=>n.remove());
      t.content.querySelectorAll('*').forEach(n=>{ [...n.attributes].forEach(a=>{
        if(/^on/i.test(a.name) || (/^(href|src)$/i.test(a.name) && /^\s*(javascript|data|vbscript):/i.test(a.value))) n.removeAttribute(a.name); });
        if(n.tagName==='A'){ n.target='_blank'; n.rel='noopener'; } });
      return t.innerHTML;
    };
    const md   = t => (typeof marked!=='undefined') ? clean(marked.parse(t)) : esc(t).replace(/\n/g,'<br>');
    const down = () => { log.scrollTop = log.scrollHeight; };

    function addUser(text){
      const el=document.createElement('div'); el.className='msg user';
      el.innerHTML='<div class="av" aria-hidden="true"><i class="bi bi-person-fill"></i></div><div class="bubble"><span class="sr-only">Ty: </span><span class="t"></span></div>';
      el.querySelector('.t').textContent=text; log.appendChild(el); down();
    }
    function addThinking(){
      const el=document.createElement('div'); el.className='msg ai';
      el.innerHTML='<div class="av" aria-hidden="true"><i class="bi bi-robot"></i></div><div class="bubble">'+
        '<div class="think" style="color:var(--muted);font-size:.88rem"><i class="bi bi-search" aria-hidden="true"></i> '+
        'Szukam w Pomocy IT i bazie wiedzy… <span class="typing" aria-hidden="true"><span></span><span></span><span></span></span></div>'+
        '<div class="body" hidden><span class="sr-only">Asystent: </span></div></div>';
      log.appendChild(el); down();
      return { think:el.querySelector('.think'), body:el.querySelector('.body') };
    }
    function renderSources(c, sources){
      if(!sources||!sources.length) return;
      // Najpierw artykuły Pomocy IT — to zwykle najbardziej praktyczny link.
      sources = sources.slice().sort((a,b)=>((b.type||'').endsWith('_it'))-((a.type||'').endsWith('_it')));
      const w=document.createElement('div'); w.className='sources'; w.setAttribute('aria-label','Źródła');
      sources.slice(0,8).forEach(s=>{
        const a=document.createElement('a'); a.className='src'+((s.type||'').endsWith('_it')?' it':''); a.href=s.url||'#';
        a.target='_blank'; a.rel='noopener';
        a.innerHTML='<i class="bi '+esc(s.icon||'bi-file-earmark')+'" aria-hidden="true"></i><span>'+esc(s.title)+
                    '</span><span class="tag">'+esc(s.label||s.type)+'</span>';
        w.appendChild(a);
      });
      c.appendChild(w);
    }
    function renderSteps(c, trace){
      if(!trace||!trace.length) return;
      const ICONS={otworz:'bi-file-earmark-text',funkcje:'bi-grid-3x3-gap',moje:'bi-person-badge',szukaj:'bi-search',pomoc_it:'bi-life-preserver'};
      const VERBS={otworz:'Otworzył',funkcje:'Sprawdził funkcje systemu',moje:'Sprawdził dane konta',szukaj:'Szukał w bazie wiedzy',pomoc_it:'Szukał w Pomocy IT'};
      const d=document.createElement('details'); d.className='steps';
      let h='<summary>Jak szukałem ('+trace.length+')</summary>';
      trace.forEach(t=>{
        h+='<div class="st"><i class="bi '+(ICONS[t.tool]||'bi-search')+'" aria-hidden="true"></i><span>'+esc(VERBS[t.tool]||t.tool)+': „'+esc(t.input)+'” — '+esc(t.summary)+'</span></div>';
      });
      d.innerHTML=h; c.appendChild(d);
    }
    function renderTools(c, answer){
      const w=document.createElement('div'); w.className='tools';
      const b=document.createElement('button'); b.type='button';
      b.innerHTML='<i class="bi bi-clipboard" aria-hidden="true"></i> Kopiuj odpowiedź';
      b.addEventListener('click',async()=>{ try{ await navigator.clipboard.writeText(answer); b.textContent='Skopiowano'; }catch(e){ b.textContent='Nie udało się skopiować'; } });
      w.appendChild(b); c.appendChild(w);
    }

    async function ask(text){
      if(busy) return;
      text=(text||'').trim(); if(!text) return;
      log.querySelectorAll('#intro .chips, #intro .chips-label').forEach(n=>n.remove());
      reset.hidden=false;

      busy=true; send.disabled=true; input.setAttribute('aria-busy','true');
      addUser(text); history.push({role:'user',text}); input.value=''; grow();
      const ui=addThinking();

      try{
        const resp=await fetch(ENDPOINT,{method:'POST',headers:{'Content-Type':'application/json'},
          body:JSON.stringify({token:TOKEN, history})});
        const data=await resp.json();
        ui.think.remove(); ui.body.hidden=false;
        if(!data.ok){
          ui.body.insertAdjacentHTML('beforeend','<p class="err" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> '+esc(data.error||'Wystąpił błąd.')+'</p>');
          renderSteps(ui.body,data.trace);
          history.pop();
        }else{
          ui.body.insertAdjacentHTML('beforeend', md(data.answer||'(brak odpowiedzi)'));
          renderSources(ui.body,data.sources); renderTools(ui.body,data.answer||''); renderSteps(ui.body,data.trace);
          history.push({role:'assistant',text:data.answer||''});
        }
      }catch(e){
        ui.think.remove(); ui.body.hidden=false;
        ui.body.insertAdjacentHTML('beforeend','<p class="err" role="alert"><i class="bi bi-wifi-off" aria-hidden="true"></i> Błąd połączenia. Spróbuj ponownie.</p>');
        history.pop();
      }finally{
        busy=false; send.disabled=false; input.removeAttribute('aria-busy'); input.focus(); down();
      }
    }
    function grow(){ input.style.height='auto'; input.style.height=Math.min(input.scrollHeight,160)+'px'; }

    form.addEventListener('submit',e=>{ e.preventDefault(); ask(input.value); });
    input.addEventListener('input',grow);
    input.addEventListener('keydown',e=>{ if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){ e.preventDefault(); ask(input.value); } });
    log.addEventListener('click',e=>{ const c=e.target.closest('.chip'); if(c) ask(c.textContent); });
    reset.addEventListener('click',()=>{
      if(busy) return;
      history=[]; log.innerHTML=''; log.appendChild(intro.cloneNode(true)); reset.hidden=true; input.focus();
    });
    input.focus();
  })();
  </script>
<?php endif; ?>
</body>
</html>
