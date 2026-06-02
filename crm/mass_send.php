<?php
/**
 * crm/mass_send.php — Masowa wysyłka e-mail / SMS do grup i tagów.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!can_write('crm') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień.');
    header('Location: ' . APP_URL . '/crm/dashboard.php'); exit;
}
crm_migrate();

$PAGE_TITLE = 'Wysyłka masowa';

require_once dirname(__DIR__) . '/includes/mail_queue.php';
require_once dirname(__DIR__) . '/includes/sms.php';

$sms_ok   = sms_is_enabled();
$email_ok = true;
$m365_ok  = _mail_m365_configured();

// Grupy z liczbą członków
$groups = db_all(
    "SELECT g.*,
            (SELECT COUNT(DISTINCT gm.contact_id) FROM crm_group_members gm WHERE gm.group_id=g.id) AS member_count,
            pg.name AS parent_name
     FROM crm_groups g
     LEFT JOIN crm_groups pg ON pg.id=g.parent_id
     ORDER BY g.parent_id IS NOT NULL, g.parent_id, g.sort_order, g.name"
);

// Tagi z liczbą użyć
$tags = db_all("SELECT tag, COUNT(*) AS cnt FROM crm_tags GROUP BY tag ORDER BY cnt DESC LIMIT 100");

// Historia wysyłek
$history = db_all(
    "SELECT ms.*, g.name AS group_name, u.name AS user_name
     FROM crm_mass_sends ms
     LEFT JOIN crm_groups g ON g.id=ms.group_id
     LEFT JOIN users u ON u.id=ms.created_by
     ORDER BY ms.created_at DESC LIMIT 20"
);

$templates = db_all("SELECT * FROM crm_templates WHERE is_active=1 ORDER BY channel, name");

include __DIR__ . '/includes/header_crm.php';
?>

<style>
.ms-section { background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1.25rem;margin-bottom:1rem;box-shadow:0 1px 3px rgba(0,0,0,.04) }
.ms-section-title { font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6B7280;padding-bottom:.5rem;border-bottom:1px solid #F3F4F6;margin-bottom:.85rem }
.group-pill { display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .75rem;border-radius:2rem;border:1.5px solid #E5E7EB;cursor:pointer;font-size:.78rem;font-weight:500;color:#374151;background:#fff;transition:all .12s;margin:.15rem }
.group-pill:hover { border-color:#9CA3AF }
.group-pill.selected { color:#fff;border-color:transparent;font-weight:600 }
.tag-chip { display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .6rem;border-radius:2rem;border:1.5px solid #E5E7EB;cursor:pointer;font-size:.73rem;color:#374151;background:#fff;transition:all .12s;margin:.1rem }
.tag-chip.selected { background:#EFF7ED;border-color:#2E844A;color:#2E844A;font-weight:600 }
.ms-progress-bar { height:8px;background:#E5E7EB;border-radius:4px;overflow:hidden }
.ms-progress-fill { height:100%;border-radius:4px;background:linear-gradient(90deg,#2E844A,#16A34A);transition:width .4s }
.recipient-preview { background:#F9FAFB;border-radius:8px;padding:.75rem;max-height:200px;overflow-y:auto;font-size:.8rem }
.channel-btn { display:flex;flex-direction:column;align-items:center;gap:.3rem;padding:.85rem 1.25rem;border-radius:10px;border:2px solid #E5E7EB;cursor:pointer;transition:all .15s;flex:1;text-align:center }
.channel-btn.active { border-color:var(--ch-color);background:var(--ch-bg);color:var(--ch-color) }
.channel-btn i { font-size:1.5rem }
.channel-btn span { font-size:.78rem;font-weight:600 }
.hist-row { display:flex;align-items:center;gap:.75rem;padding:.55rem 0;border-bottom:1px solid #F3F4F6;font-size:.82rem }
.hist-row:last-child { border-bottom:none }
</style>

<div class="crm-page-header">
  <div>
    <div class="crm-page-title"><i class="bi bi-send-fill" style="color:#0176D3"></i> Wysyłka masowa</div>
    <div class="crm-page-subtitle">E-mail i SMS do grup, podgrup i tagów kontaktów</div>
  </div>
</div>

<div class="row g-3">

<!-- ══ LEWA: formularz ══════════════════════════════════════════════════════ -->
<div class="col-lg-8">

  <!-- Kanał -->
  <div class="ms-section">
    <div class="ms-section-title">1. Kanał wysyłki</div>
    <div class="d-flex gap-2">
      <div class="channel-btn active" id="ch-email" data-channel="email"
           style="--ch-color:#0176D3;--ch-bg:#EEF4FF" onclick="MS.setChannel('email')">
        <i class="bi bi-envelope-fill" style="color:#0176D3"></i>
        <span>E-mail</span>
        <small class="text-muted" style="font-size:.68rem"><?= $m365_ok ? 'Microsoft 365' : (_mail_setting('smtp_host') ? 'SMTP' : 'PHP mail()') ?></small>
      </div>
      <?php if ($sms_ok): ?>
      <div class="channel-btn" id="ch-sms" data-channel="sms"
           style="--ch-color:#D97706;--ch-bg:#FEF3E2" onclick="MS.setChannel('sms')">
        <i class="bi bi-phone-fill" style="color:#D97706"></i>
        <span>SMS</span>
        <small class="text-muted" style="font-size:.68rem">SMSAPI.pl</small>
      </div>
      <?php else: ?>
      <div class="channel-btn" style="opacity:.4;cursor:default">
        <i class="bi bi-phone" style="color:#9CA3AF"></i>
        <span style="color:#9CA3AF">SMS</span>
        <small style="font-size:.68rem;color:#9CA3AF">Niekonfigurowany</small>
      </div>
      <?php endif; ?>
    </div>
    <input type="hidden" id="ms_channel" value="email">
  </div>

  <!-- Odbiorcy: grupy -->
  <div class="ms-section">
    <div class="ms-section-title d-flex align-items-center justify-content-between">
      2. Odbiorcy — grupy
      <span class="text-muted fw-normal" style="text-transform:none;letter-spacing:0;font-size:.75rem">kliknij grupę lub podgrupę, by wybrać</span>
    </div>

    <?php
    // Grupuj: rodzice i dzieci
    $parent_groups = array_filter($groups, fn($g)=>!$g['parent_id']);
    $child_groups  = [];
    foreach ($groups as $g) {
      if ($g['parent_id']) $child_groups[$g['parent_id']][] = $g;
    }
    ?>

    <div id="groupPills" class="mb-2">
      <?php foreach ($parent_groups as $g): ?>
      <div style="margin-bottom:.35rem">
        <button type="button" class="group-pill" data-id="<?= (int)$g['id'] ?>"
                style="background:<?= h($g['color']) ?>1A;border-color:<?= h($g['color']) ?>55"
                onclick="MS.toggleGroup(this)">
          <i class="bi <?= h($g['icon']) ?>" style="color:<?= h($g['color']) ?>"></i>
          <?= h($g['name']) ?>
          <span class="badge bg-light text-dark border ms-1" style="font-size:.65rem"><?= (int)$g['member_count'] ?></span>
        </button>
        <?php foreach (($child_groups[$g['id']] ?? []) as $cg): ?>
        <button type="button" class="group-pill ms-2" data-id="<?= (int)$cg['id'] ?>"
                style="background:<?= h($cg['color']) ?>0D;border-color:<?= h($cg['color']) ?>33;font-size:.73rem"
                onclick="MS.toggleGroup(this)">
          <i class="bi <?= h($cg['icon']) ?>" style="color:<?= h($cg['color']) ?>;font-size:.8rem"></i>
          <?= h($cg['name']) ?>
          <span class="badge bg-light text-dark border ms-1" style="font-size:.62rem"><?= (int)$cg['member_count'] ?></span>
        </button>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
      <?php if (!$groups): ?>
      <div class="text-muted small">Brak grup. <a href="<?= APP_URL ?>/crm/groups.php">Utwórz grupę →</a></div>
      <?php endif; ?>
    </div>

    <!-- Filtry tagów -->
    <?php if ($tags): ?>
    <div class="ms-section-title mt-3" style="margin-top:.75rem">lub filtruj wg tagów</div>
    <div id="tagChips">
      <?php foreach ($tags as $t): ?>
      <span class="tag-chip" data-tag="<?= h($t['tag']) ?>" onclick="MS.toggleTag(this)">
        <i class="bi bi-tag" style="font-size:.65rem"></i><?= h($t['tag']) ?>
        <span class="text-muted" style="font-size:.65rem">(<?= (int)$t['cnt'] ?>)</span>
      </span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Podgląd odbiorców -->
    <div class="mt-3" id="recipientPreviewBox" style="display:none">
      <div class="d-flex align-items-center justify-content-between mb-1">
        <span class="fw-semibold" style="font-size:.82rem">
          Podgląd odbiorców: <span id="recipientCount" class="text-primary">0</span>
        </span>
        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.72rem"
                onclick="MS.preview()"><i class="bi bi-arrow-repeat me-1"></i>Odśwież</button>
      </div>
      <div class="recipient-preview" id="recipientList">
        <div class="text-muted">Kliknij grupę lub tag, by zobaczyć odbiorców.</div>
      </div>
    </div>
  </div>

  <!-- Temat i treść -->
  <div class="ms-section">
    <div class="ms-section-title d-flex align-items-center justify-content-between">
      3. Treść wiadomości
      <select id="ms_tpl_select" class="form-select form-select-sm" style="width:auto;font-size:.75rem" onchange="MS.loadTemplate()">
        <option value="">— Wybierz szablon —</option>
        <?php foreach ($templates as $t): ?>
        <option value="<?= (int)$t['id'] ?>"
                data-channel="<?= h($t['channel']) ?>"
                data-subject="<?= h($t['subject']??'') ?>"
                data-body="<?= h($t['body']) ?>">
          [<?= strtoupper(h($t['channel'])) ?>] <?= h($t['name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div id="emailSubjectRow" class="mb-2">
      <label class="form-label fw-semibold small">Temat e-maila <span class="text-danger">*</span></label>
      <input id="ms_subject" type="text" class="form-control form-control-sm" placeholder="Temat wiadomości…">
    </div>

    <div class="mb-2">
      <span class="text-muted" style="font-size:.73rem">Zmienne: </span>
      <?php foreach (['{imie}','{imie_nazwisko}','{email}','{organizacja}','{data}'] as $v): ?>
      <button type="button" class="btn btn-outline-secondary py-0 px-1 me-1"
              style="font-size:.68rem;font-family:monospace;line-height:1.6"
              onclick="MS.insertVar('<?= $v ?>')">
        <?= h($v) ?>
      </button>
      <?php endforeach; ?>
    </div>

    <!-- Quill dla email -->
    <div id="ms_quill_wrap">
      <div id="ms_quill" style="min-height:180px;font-size:.9rem"></div>
    </div>
    <!-- Textarea dla SMS -->
    <div id="ms_plain_wrap" style="display:none">
      <textarea id="ms_plain_body" class="form-control form-control-sm" rows="6"
                placeholder="Treść SMS (max 160 znaków)…"></textarea>
      <div id="ms_sms_count" class="text-muted mt-1" style="font-size:.73rem"></div>
    </div>
  </div>

</div><!-- /col-8 -->

<!-- ══ PRAWA: akcje + historia ══════════════════════════════════════════════ -->
<div class="col-lg-4">

  <!-- Podgląd + send -->
  <div class="ms-section mb-3">
    <div class="ms-section-title">4. Wyślij</div>

    <div class="mb-3 p-3 rounded" style="background:#F9FAFB;border:1px solid #E5E7EB">
      <div class="d-flex justify-content-between mb-1" style="font-size:.82rem">
        <span class="text-muted">Odbiorcy</span>
        <strong id="sendCount">0</strong>
      </div>
      <div class="d-flex justify-content-between" style="font-size:.82rem">
        <span class="text-muted">Kanał</span>
        <strong id="sendChannel">E-mail</strong>
      </div>
    </div>

    <!-- Pasek postępu -->
    <div id="progressSection" style="display:none" class="mb-3">
      <div class="d-flex justify-content-between mb-1" style="font-size:.78rem">
        <span id="progressLabel">Wysyłanie…</span>
        <span id="progressNum"></span>
      </div>
      <div class="ms-progress-bar"><div class="ms-progress-fill" id="progressFill" style="width:0%"></div></div>
      <div id="progressResult" class="mt-2" style="font-size:.78rem"></div>
    </div>

    <button type="button" id="previewBtn" class="btn btn-outline-primary btn-sm w-100 mb-2" onclick="MS.preview()">
      <i class="bi bi-eye me-1"></i>Podgląd odbiorców
    </button>
    <button type="button" id="sendBtn" class="btn btn-success w-100" onclick="MS.send()" disabled>
      <i class="bi bi-send-fill me-1"></i>Wyślij do <span id="sendBtnCount">0</span> odbiorców
    </button>
    <div class="form-text text-center mt-1" style="font-size:.72rem">
      Wysyłka uruchamiana synchronicznie — nie zamykaj okna.
    </div>
  </div>

  <!-- Historia -->
  <?php if ($history): ?>
  <div class="ms-section">
    <div class="ms-section-title">Ostatnie wysyłki</div>
    <?php foreach ($history as $hs): ?>
    <div class="hist-row">
      <div style="flex:1;min-width:0">
        <div style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <?= h($hs['group_name'] ?? 'Bez grupy') ?>
        </div>
        <div class="text-muted" style="font-size:.72rem">
          <?= strtoupper(h($hs['channel'])) ?> · <?= h($hs['subject'] ?? '(SMS)') ?> · <?= date('d.m H:i', strtotime($hs['created_at'])) ?>
        </div>
      </div>
      <div class="text-end flex-shrink-0" style="font-size:.75rem">
        <div class="text-success fw-semibold"><?= (int)$hs['sent_ok'] ?> ✓</div>
        <?php if ($hs['sent_fail']): ?><div class="text-danger"><?= (int)$hs['sent_fail'] ?> ✗</div><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>
</div><!-- /row -->

<!-- Quill -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<style>
#ms_quill_wrap .ql-toolbar.ql-snow { border:1px solid #E5E7EB;border-bottom:none;border-radius:.375rem .375rem 0 0;background:#F9FAFB;padding:.3rem .5rem }
#ms_quill_wrap .ql-container.ql-snow { border:1px solid #E5E7EB;border-radius:0 0 .375rem .375rem;font-size:.9rem }
#ms_quill_wrap .ql-editor { min-height:180px }
</style>

<script>
const MS = (function() {
  'use strict';
  const API = '<?= APP_URL ?>/crm/api/mass_send.php';

  let _channel     = 'email';
  let _group_ids   = new Set();
  let _tag_filters = new Set();
  let _quill       = null;
  let _total       = 0;

  function initQuill() {
    _quill = new Quill('#ms_quill', {
      theme: 'snow',
      placeholder: 'Treść e-maila… Użyj zmiennych {imie}, {imie_nazwisko}, {email}',
      modules: { toolbar: [
        [{ header: [1,2,false] }],
        ['bold','italic','underline'],
        [{ color: [] }],
        [{ list: 'ordered' },{ list: 'bullet' }],
        ['link','clean']
      ]}
    });
  }
  initQuill();

  function setChannel(ch) {
    _channel = ch;
    ['email','sms'].forEach(c => {
      document.getElementById('ch-'+c)?.classList.toggle('active', c===ch);
    });
    document.getElementById('ms_quill_wrap').style.display = ch==='email' ? '' : 'none';
    document.getElementById('ms_plain_wrap').style.display  = ch==='sms'   ? '' : 'none';
    document.getElementById('emailSubjectRow').style.display= ch==='email' ? '' : 'none';
    document.getElementById('sendChannel').textContent = ch==='email' ? 'E-mail' : 'SMS';
    updateSmsCount();
    preview();
  }

  function toggleGroup(btn) {
    const id = parseInt(btn.dataset.id);
    if (_group_ids.has(id)) {
      _group_ids.delete(id);
      btn.classList.remove('selected');
      btn.style.background = btn.style.background.replace(/[^#]+$/, btn.dataset.origBg||'#fff');
    } else {
      _group_ids.add(id);
      btn.classList.add('selected');
      btn.dataset.origColor = btn.style.color;
      btn.style.background = btn.style.borderColor.replace('55','');
      btn.style.color = '#fff';
      btn.style.borderColor = 'transparent';
    }
    preview();
  }

  function toggleTag(chip) {
    const tag = chip.dataset.tag;
    if (_tag_filters.has(tag)) {
      _tag_filters.delete(tag);
      chip.classList.remove('selected');
    } else {
      _tag_filters.add(tag);
      chip.classList.add('selected');
    }
    preview();
  }

  function preview() {
    const groups = [..._group_ids];
    const tags   = [..._tag_filters].join(',');
    if (!groups.length && !tags) {
      _total = 0;
      document.getElementById('recipientPreviewBox').style.display='none';
      document.getElementById('recipientCount').textContent='0';
      document.getElementById('sendCount').textContent='0';
      document.getElementById('sendBtnCount').textContent='0';
      document.getElementById('sendBtn').disabled = true;
      return;
    }
    document.getElementById('recipientPreviewBox').style.display='';

    const payload = {
      action: 'preview',
      group_id: groups[0] || 0,
      tag_filter: tags,
      contact_ids: [],
      channel: _channel
    };

    // If multiple groups, collect all separately and merge
    if (groups.length > 1) {
      Promise.all(groups.map(gid =>
        fetch(API, { method:'POST', headers:{'Content-Type':'application/json'},
                     body: JSON.stringify({action:'preview',group_id:gid,channel:_channel}) })
          .then(r=>r.json())
          .then(res=>res.ok ? res.data.contacts.map(c=>c.id) : [])
      )).then(arrays => {
        const unique = [...new Set(arrays.flat())];
        _total = unique.length;
        updateCounters();
        document.getElementById('recipientList').innerHTML =
          '<span class="text-success fw-semibold">'+unique.length+' unikalnych odbiorców</span> ze wszystkich wybranych grup.';
      });
      return;
    }

    fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) })
      .then(r=>r.json())
      .then(res=>{
        if (!res.ok) return;
        _total = res.data.total;
        updateCounters();
        const list = res.data.contacts.slice(0,15).map(c=>
          `<div class="d-flex gap-2 py-1 border-bottom" style="border-color:#F3F4F6">
            <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500">${esc(c.name)}</span>
            <span class="text-muted">${esc(_channel==='sms'?c.telefon||'—brak tel.':c.email||'—brak e-mail')}</span>
          </div>`
        ).join('');
        document.getElementById('recipientList').innerHTML = list +
          (res.data.total > 15 ? `<div class="text-muted mt-1" style="font-size:.72rem">… i ${res.data.total-15} więcej</div>` : '');
        document.getElementById('recipientCount').textContent = res.data.total;
      });
  }

  function updateCounters() {
    document.getElementById('recipientCount').textContent = _total;
    document.getElementById('sendCount').textContent      = _total;
    document.getElementById('sendBtnCount').textContent   = _total;
    document.getElementById('sendBtn').disabled = (_total === 0);
  }

  function updateSmsCount() {
    if (_channel !== 'sms') return;
    const ta = document.getElementById('ms_plain_body');
    const cc = document.getElementById('ms_sms_count');
    if (!ta||!cc) return;
    const len = ta.value.length, msgs = Math.ceil(len/160)||1;
    cc.textContent = len+' znaków ('+msgs+' SMS'+(msgs>1?'-y':'')+')';
    cc.style.color = len>160?'#D97706':'#9CA3AF';
  }

  document.getElementById('ms_plain_body')?.addEventListener('input', updateSmsCount);

  function getBody() {
    if (_channel === 'sms') return document.getElementById('ms_plain_body').value.trim();
    return _quill ? _quill.root.innerHTML : '';
  }

  function insertVar(v) {
    if (_channel==='sms') {
      const ta=document.getElementById('ms_plain_body');
      const s=ta.selectionStart,e=ta.selectionEnd;
      ta.value=ta.value.slice(0,s)+v+ta.value.slice(e);
      ta.selectionStart=ta.selectionEnd=s+v.length;
    } else if (_quill) {
      const r=_quill.getSelection(true);
      _quill.insertText(r?r.index:_quill.getLength(),v,'user');
    }
  }

  function loadTemplate() {
    const sel = document.getElementById('ms_tpl_select');
    const opt = sel.options[sel.selectedIndex];
    if (!opt.value) return;
    const ch=opt.dataset.channel||'email';
    setChannel(ch);
    const subj=opt.dataset.subject||'';
    const body=opt.dataset.body||'';
    if (ch==='email') {
      document.getElementById('ms_subject').value=subj;
      if (_quill) {
        if (/<[a-z]/i.test(body)) _quill.root.innerHTML=body;
        else _quill.setText(body);
      }
    } else {
      document.getElementById('ms_plain_body').value=body;
      updateSmsCount();
    }
  }

  function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

  async function send() {
    const body = getBody();
    const subject = document.getElementById('ms_subject').value.trim();
    const groups = [..._group_ids];
    const tags   = [..._tag_filters].join(',');

    if (!body) { alert('Wpisz treść wiadomości.'); return; }
    if (_channel==='email'&&!subject) { alert('Podaj temat e-maila.'); return; }
    if (!groups.length&&!tags) { alert('Wybierz grupę lub tag.'); return; }
    if (_total===0) { alert('Brak odbiorców dla wybranego kanału.'); return; }
    if (!confirm('Wysłać '+_channel.toUpperCase()+' do '+_total+' odbiorców?')) return;

    document.getElementById('sendBtn').disabled=true;
    document.getElementById('progressSection').style.display='';
    document.getElementById('progressLabel').textContent='Przygotowywanie wysyłki…';
    document.getElementById('progressFill').style.width='10%';

    const tplText = document.getElementById('ms_tpl_select').options[document.getElementById('ms_tpl_select').selectedIndex]?.text||'';

    // Startuj dla każdej grupy osobno i zbieraj send_ids
    const payloads = groups.length
      ? groups.map(gid=>({ action:'start',group_id:gid,tag_filter:tags,channel:_channel,subject,body,template_name:tplText }))
      : [{ action:'start',group_id:0,tag_filter:tags,channel:_channel,subject,body }];

    let totalOk=0,totalFail=0;

    for (let i=0;i<payloads.length;i++) {
      const startRes = await fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payloads[i])}).then(r=>r.json());
      if (!startRes.ok) { alert('Błąd: '+startRes.error); break; }

      document.getElementById('progressLabel').textContent=`Wysyłanie (${i+1}/${payloads.length})…`;
      document.getElementById('progressFill').style.width = (10+80*(i+1)/payloads.length)+'%';

      const execRes = await fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'execute',send_id:startRes.data.send_id})}).then(r=>r.json());
      totalOk   += execRes.data?.sent_ok   || 0;
      totalFail += execRes.data?.sent_fail || 0;
    }

    document.getElementById('progressFill').style.width='100%';
    document.getElementById('progressLabel').textContent='Zakończono!';
    document.getElementById('progressResult').innerHTML =
      `<span class="text-success fw-semibold"><i class="bi bi-check-circle me-1"></i>${totalOk} wysłano</span>` +
      (totalFail ? ` <span class="text-danger ms-2"><i class="bi bi-x-circle me-1"></i>${totalFail} błędów</span>` : '');

    setTimeout(()=>location.reload(), 2500);
  }

  return { setChannel, toggleGroup, toggleTag, preview, send, insertVar, loadTemplate };
})();
</script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
