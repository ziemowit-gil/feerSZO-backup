<?php
/**
 * chatbot/index.php — Publiczna, samodzielna strona asystenta AI.
 *
 * Dostęp po tokenie: /chatbot/{token} (rewrite → ?t={token}).
 * Bez logowania — przeznaczona do udostępnienia współpracownikom w intranecie.
 * Strona jest samodzielna (NIE używa includes/header.php) — nowoczesny, pełnoekranowy UI.
 *
 * Rozmowa toczy się przez chatbot/ai.php (bramkowany tym samym tokenem).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/branding.php';
require_once dirname(__DIR__) . '/includes/asystent_ai.php';

$token = trim($_GET['t'] ?? '');
$valid = asai_public_token_valid($token);

$brand    = branding_load();
$org_name = $brand['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : 'Organizacja');
$logo_url = $brand['logo_url'] ?? '';
$accent   = $brand['primary'] ?: '#2563eb';

header('X-Robots-Tag: noindex, nofollow');
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Asystent AI · <?= htmlspecialchars($org_name, ENT_QUOTES) ?></title>
<link rel="icon" href="<?= APP_URL ?>/assets/img/icon-192.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  :root{
    --accent: <?= htmlspecialchars($accent, ENT_QUOTES) ?>;
    --bg:#f6f7fb; --card:#ffffff; --line:#e8ebf1; --ink:#0f172a; --muted:#64748b;
    --bubble-ai:#f5f7fb; --bubble-user:var(--accent);
    --radius:18px; --shadow:0 20px 60px -24px rgba(15,23,42,.28);
  }
  @media (prefers-color-scheme: dark){
    :root{ --bg:#0b1020; --card:#121a2e; --line:#243048; --ink:#e8ecf5; --muted:#94a3b8;
           --bubble-ai:#1a2440; --shadow:0 24px 70px -30px rgba(0,0,0,.7); }
  }
  *{ box-sizing:border-box; }
  html,body{ height:100%; margin:0; }
  body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
    color:var(--ink); background:var(--bg);
    background-image:
      radial-gradient(1100px 520px at 82% -8%, color-mix(in srgb, var(--accent) 20%, transparent), transparent 60%),
      radial-gradient(900px 460px at 8% 108%, color-mix(in srgb, var(--accent) 12%, transparent), transparent 55%);
    -webkit-font-smoothing:antialiased;
  }
  .app{ max-width:920px; margin:0 auto; min-height:100dvh; display:flex; flex-direction:column;
        padding:clamp(12px,3vw,28px); }

  /* Header */
  .top{ display:flex; align-items:center; gap:.85rem; padding:.5rem .25rem 1rem; }
  .brand-logo{ height:38px; width:auto; border-radius:8px; }
  .brand-mark{ width:44px; height:44px; border-radius:13px; flex-shrink:0; display:flex;
    align-items:center; justify-content:center; font-size:1.4rem; color:#fff;
    background:linear-gradient(135deg,var(--accent), color-mix(in srgb, var(--accent) 55%, #7c3aed));
    box-shadow:0 8px 22px -8px var(--accent); }
  .top h1{ font-size:1.02rem; font-weight:750; margin:0; letter-spacing:-.01em; }
  .top .sub{ font-size:.78rem; color:var(--muted); margin-top:1px; }
  .pill{ margin-left:auto; display:inline-flex; align-items:center; gap:.4rem; font-size:.72rem;
    font-weight:600; color:var(--muted); background:var(--card); border:1px solid var(--line);
    padding:.35rem .7rem; border-radius:2rem; }
  .pill .dot{ width:7px; height:7px; border-radius:50%; background:#22c55e; box-shadow:0 0 0 3px rgba(34,197,94,.18); }

  /* Chat card */
  .chat{ flex:1; display:flex; flex-direction:column; background:var(--card);
    border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; }
  .log{ flex:1; overflow-y:auto; padding:clamp(14px,3vw,26px); scroll-behavior:smooth; }

  .msg{ display:flex; gap:.7rem; margin-bottom:1.25rem; animation:rise .3s ease both; }
  @keyframes rise{ from{opacity:0; transform:translateY(8px);} to{opacity:1; transform:none;} }
  .msg .av{ width:34px; height:34px; border-radius:10px; flex-shrink:0; display:flex;
    align-items:center; justify-content:center; font-size:1rem; }
  .msg.ai   .av{ background:color-mix(in srgb, var(--accent) 16%, transparent); color:var(--accent); }
  .msg.user{ flex-direction:row-reverse; }
  .msg.user .av{ background:var(--ink); color:var(--bg); }
  .bubble{ max-width:80%; border-radius:15px; padding:.75rem 1rem; font-size:.925rem; line-height:1.62; }
  .msg.ai   .bubble{ background:var(--bubble-ai); border:1px solid var(--line); border-top-left-radius:5px; }
  .msg.user .bubble{ background:var(--bubble-user); color:#fff; border-top-right-radius:5px; }
  .bubble>*:first-child{ margin-top:0; } .bubble>*:last-child{ margin-bottom:0; }
  .bubble h1,.bubble h2,.bubble h3{ font-size:1rem; font-weight:700; margin:.7rem 0 .35rem; }
  .bubble ul,.bubble ol{ padding-left:1.2rem; margin:.4rem 0; }
  .bubble li{ margin-bottom:.2rem; }
  .bubble a{ color:var(--accent); }
  .bubble code{ background:color-mix(in srgb, var(--accent) 12%, transparent); border-radius:5px;
    padding:.1em .35em; font-size:.86em; }
  .bubble pre{ background:var(--bubble-ai); border:1px solid var(--line); border-radius:10px;
    padding:.7rem .85rem; overflow:auto; }
  .bubble table{ width:100%; border-collapse:collapse; margin:.5rem 0; font-size:.85rem; }
  .bubble th,.bubble td{ border:1px solid var(--line); padding:.35rem .55rem; }

  /* Sources + agent steps */
  .sources{ margin-top:.8rem; display:flex; flex-wrap:wrap; gap:.4rem; }
  .src{ display:inline-flex; align-items:center; gap:.4rem; text-decoration:none; font-size:.76rem;
    padding:.32rem .7rem; border:1px solid var(--line); border-radius:2rem; background:var(--card);
    color:var(--ink); transition:.14s; }
  .src:hover{ border-color:var(--accent); color:var(--accent); }
  .src .tag{ font-size:.64rem; color:var(--muted); }
  .steps{ margin-top:.7rem; font-size:.73rem; color:var(--muted); background:var(--bubble-ai);
    border:1px dashed var(--line); border-radius:11px; padding:.55rem .75rem; }
  .steps .st{ display:flex; gap:.45rem; padding:.12rem 0; }
  .steps .st i{ color:var(--accent); }

  /* Suggestions */
  .suggest{ display:flex; flex-wrap:wrap; gap:.5rem; margin-top:1rem; }
  .chip{ font-size:.82rem; padding:.42rem .85rem; border:1px solid var(--line); border-radius:2rem;
    background:var(--card); color:var(--ink); cursor:pointer; transition:.14s; }
  .chip:hover{ border-color:var(--accent); color:var(--accent); transform:translateY(-1px); }

  /* Input */
  .composer{ border-top:1px solid var(--line); padding:.8rem; background:var(--card); }
  .field{ display:flex; gap:.6rem; align-items:flex-end; background:var(--bg); border:1px solid var(--line);
    border-radius:16px; padding:.4rem .5rem .4rem .9rem; transition:border-color .15s; }
  .field:focus-within{ border-color:var(--accent); }
  .field textarea{ flex:1; border:0; outline:0; background:transparent; color:var(--ink);
    font:inherit; font-size:.94rem; resize:none; max-height:150px; padding:.45rem 0; line-height:1.5; }
  .send{ width:40px; height:40px; flex-shrink:0; border:0; border-radius:12px; color:#fff; cursor:pointer;
    background:var(--accent); display:flex; align-items:center; justify-content:center; font-size:1.05rem;
    transition:.15s; }
  .send:hover:not(:disabled){ filter:brightness(1.08); transform:translateY(-1px); }
  .send:disabled{ opacity:.5; cursor:default; }
  .foot{ text-align:center; font-size:.68rem; color:var(--muted); margin-top:.55rem; padding:0 .3rem; }

  .typing span{ display:inline-block; width:6px; height:6px; margin:0 1px; border-radius:50%;
    background:var(--accent); animation:bounce 1.2s infinite; }
  .typing span:nth-child(2){ animation-delay:.15s; } .typing span:nth-child(3){ animation-delay:.3s; }
  @keyframes bounce{ 0%,60%,100%{ transform:translateY(0); opacity:.4; } 30%{ transform:translateY(-4px); opacity:1; } }

  /* Blocked / invalid link state */
  .gate{ max-width:520px; margin:auto; text-align:center; background:var(--card); border:1px solid var(--line);
    border-radius:var(--radius); box-shadow:var(--shadow); padding:2.5rem 2rem; }
  .gate .ic{ width:64px; height:64px; border-radius:18px; margin:0 auto 1rem; display:flex;
    align-items:center; justify-content:center; font-size:1.8rem; background:#fef2f2; color:#dc2626; }
  @media (prefers-color-scheme: dark){ .gate .ic{ background:rgba(220,38,38,.14); } }
</style>
</head>
<body>
<?php if (!$valid): ?>
  <div class="app" style="justify-content:center">
    <div class="gate">
      <div class="ic"><i class="bi bi-slash-circle"></i></div>
      <h1 style="font-size:1.15rem; font-weight:750; margin:0 0 .5rem">Link nieaktywny</h1>
      <p style="color:var(--muted); margin:0">
        Ten link do asystenta AI jest nieaktywny, został zmieniony lub wyłączony.
        Poproś administratora <?= htmlspecialchars($org_name, ENT_QUOTES) ?> o aktualny adres.
      </p>
    </div>
  </div>
<?php else: ?>
  <div class="app">
    <header class="top">
      <?php if ($logo_url): ?>
        <img class="brand-logo" src="<?= htmlspecialchars($logo_url, ENT_QUOTES) ?>" alt="">
      <?php else: ?>
        <div class="brand-mark"><i class="bi bi-robot"></i></div>
      <?php endif; ?>
      <div>
        <h1>Asystent AI — procedury i dokumentacja</h1>
        <div class="sub"><?= htmlspecialchars($org_name, ENT_QUOTES) ?></div>
      </div>
      <span class="pill"><span class="dot"></span>Online</span>
    </header>

    <div class="chat">
      <div class="log" id="log">
        <div class="msg ai">
          <div class="av"><i class="bi bi-robot"></i></div>
          <div class="bubble">
            Cześć! 👋 Jestem asystentem wiedzy <strong><?= htmlspecialchars($org_name, ENT_QUOTES) ?></strong>.
            Zapytaj mnie o procedurę, dokument, uchwałę albo „jak coś załatwić" — przeszukam wewnętrzną
            bazę wiedzy i odpowiem, wskazując źródła.
            <div class="suggest" id="suggest">
              <span class="chip">Jak rozliczyć zwrot kosztów wolontariusza?</span>
              <span class="chip">Procedura onboardingu nowej osoby</span>
              <span class="chip">Jakie dokumenty przy rozwiązaniu umowy?</span>
              <span class="chip">Zasady ochrony danych (RODO)</span>
            </div>
          </div>
        </div>
      </div>

      <div class="composer">
        <form id="form" class="field">
          <textarea id="input" rows="1" placeholder="Napisz pytanie…" autocomplete="off"></textarea>
          <button type="submit" id="send" class="send" aria-label="Wyślij"><i class="bi bi-send-fill"></i></button>
        </form>
        <div class="foot">
          <i class="bi bi-shield-lock me-1"></i>Odpowiedzi generuje AI na podstawie bazy wiedzy — zweryfikuj przy decyzjach formalnych.
        </div>
      </div>
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
    const history = [];
    let busy = false;

    if (typeof marked !== 'undefined') marked.setOptions({ breaks:true, gfm:true });
    const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const md  = t => (typeof marked!=='undefined') ? marked.parse(t) : esc(t).replace(/\n/g,'<br>');
    const down = () => log.scrollTop = log.scrollHeight;

    function addUser(text){
      const el=document.createElement('div'); el.className='msg user';
      el.innerHTML='<div class="av"><i class="bi bi-person-fill"></i></div><div class="bubble"></div>';
      el.querySelector('.bubble').textContent=text; log.appendChild(el); down();
    }
    function addThinking(){
      const el=document.createElement('div'); el.className='msg ai';
      el.innerHTML='<div class="av"><i class="bi bi-robot"></i></div><div class="bubble">'+
        '<div class="think" style="color:var(--muted);font-size:.85rem"><i class="bi bi-search me-1"></i>'+
        'Przeszukuję bazę wiedzy… <span class="typing"><span></span><span></span><span></span></span></div>'+
        '<div class="body" style="display:none"></div></div>';
      log.appendChild(el); down();
      return { think:el.querySelector('.think'), body:el.querySelector('.body') };
    }
    function renderSources(c, sources){
      if(!sources||!sources.length) return;
      const w=document.createElement('div'); w.className='sources';
      sources.forEach(s=>{
        const a=document.createElement('a'); a.className='src'; a.href=s.url||'#';
        a.target='_blank'; a.rel='noopener';
        a.innerHTML='<i class="bi '+esc(s.icon||'bi-file-earmark')+'"></i><span>'+esc(s.title)+
                    '</span><span class="tag">'+esc(s.label||s.type)+'</span>';
        w.appendChild(a);
      });
      c.appendChild(w);
    }
    function renderSteps(c, trace){
      if(!trace||!trace.length) return;
      const b=document.createElement('div'); b.className='steps';
      let h='<div style="font-weight:600;margin-bottom:.2rem"><i class="bi bi-diagram-3 me-1"></i>Kroki asystenta</div>';
      trace.forEach(t=>{
        const ic=t.tool==='otworz'?'bi-file-earmark-text':'bi-search';
        const v =t.tool==='otworz'?'Otworzył':'Szukał';
        h+='<div class="st"><i class="bi '+ic+'"></i><span>'+esc(v)+': „'+esc(t.input)+'" — '+esc(t.summary)+'</span></div>';
      });
      b.innerHTML=h; c.appendChild(b);
    }

    async function ask(text){
      if(busy) return;
      text=(text||'').trim(); if(!text) return;
      const sug=document.getElementById('suggest'); if(sug) sug.remove();

      busy=true; send.disabled=true; input.disabled=true;
      addUser(text); history.push({role:'user',text}); input.value=''; grow();
      const ui=addThinking();

      try{
        const resp=await fetch(ENDPOINT,{method:'POST',headers:{'Content-Type':'application/json'},
          body:JSON.stringify({token:TOKEN, history})});
        const data=await resp.json();
        ui.think.style.display='none'; ui.body.style.display='';
        if(!data.ok){
          ui.body.innerHTML='<div style="color:#dc2626"><i class="bi bi-exclamation-circle me-1"></i>'+esc(data.error||'Wystąpił błąd.')+'</div>';
          renderSteps(ui.body,data.trace);
        }else{
          ui.body.innerHTML=md(data.answer||'(brak odpowiedzi)');
          renderSources(ui.body,data.sources); renderSteps(ui.body,data.trace);
          history.push({role:'assistant',text:data.answer||''});
        }
      }catch(e){
        ui.think.style.display='none'; ui.body.style.display='';
        ui.body.innerHTML='<div style="color:#dc2626"><i class="bi bi-wifi-off me-1"></i>Błąd połączenia. Spróbuj ponownie.</div>';
      }finally{
        busy=false; send.disabled=false; input.disabled=false; input.focus(); down();
      }
    }
    function grow(){ input.style.height='auto'; input.style.height=Math.min(input.scrollHeight,150)+'px'; }

    form.addEventListener('submit',e=>{ e.preventDefault(); ask(input.value); });
    input.addEventListener('input',grow);
    input.addEventListener('keydown',e=>{ if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); ask(input.value); } });
    document.addEventListener('click',e=>{ const c=e.target.closest('.chip'); if(c) ask(c.textContent); });
    input.focus();
  })();
  </script>
<?php endif; ?>
</body>
</html>
