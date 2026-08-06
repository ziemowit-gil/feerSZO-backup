<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/zaswiadczenia_ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

if (!ezd_is_manager() && !can_edit()) {
    flash_set('error', 'Brak uprawnień do zarządzania typami zaświadczeń.');
    header('Location:' . APP_URL . '/ezd/zaswiadczenia/index.php'); exit;
}

$user_id = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'save_typ') {
        $kod   = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['kod'] ?? '')));
        $nazwa = trim($_POST['nazwa'] ?? '');
        if (!$kod || !$nazwa) {
            flash_set('error', 'Kod i nazwa są wymagane.');
        } else {
            $pola_raw  = $_POST['szablon_pola'] ?? '[]';
            $pola_data = json_decode($pola_raw, true);
            $pola_json = json_encode($pola_data ?: [], JSON_UNESCAPED_UNICODE);
            $jrwa_id   = ($_POST['jrwa_id'] ?? '') !== '' ? (int)$_POST['jrwa_id'] : null;
            $eid       = (int)($_POST['typ_id'] ?? 0);
            if ($eid) {
                db()->prepare(
                    "UPDATE ezd_zas_typy SET kod=?,nazwa=?,opis=?,szablon_tresc=?,szablon_pola=?,
                     wymaga_akceptacji=?,jrwa_id=?,is_active=? WHERE id=?"
                )->execute([
                    $kod, $nazwa, trim($_POST['opis'] ?? ''),
                    $_POST['szablon_tresc'] ?? '',
                    $pola_json,
                    (int)($_POST['wymaga_akceptacji'] ?? 1),
                    $jrwa_id,
                    (int)($_POST['is_active'] ?? 1),
                    $eid,
                ]);
                flash_set('success', 'Typ zaktualizowany.');
            } else {
                db()->prepare(
                    "INSERT INTO ezd_zas_typy (kod,nazwa,opis,szablon_tresc,szablon_pola,
                     wymaga_akceptacji,jrwa_id,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $kod, $nazwa, trim($_POST['opis'] ?? ''),
                    $_POST['szablon_tresc'] ?? '',
                    $pola_json,
                    (int)($_POST['wymaga_akceptacji'] ?? 1),
                    $jrwa_id,
                    (int)($_POST['is_active'] ?? 1),
                    $user_id,
                ]);
                flash_set('success', 'Typ zaświadczenia dodany.');
            }
        }
        header('Location:' . APP_URL . '/ezd/zaswiadczenia/typy.php'); exit;
    }

    if ($act === 'toggle_active') {
        $tid = (int)($_POST['typ_id'] ?? 0);
        db()->prepare("UPDATE ezd_zas_typy SET is_active=1-is_active WHERE id=?")->execute([$tid]);
        header('Location:' . APP_URL . '/ezd/zaswiadczenia/typy.php'); exit;
    }

    if ($act === 'delete_typ') {
        $tid = (int)($_POST['typ_id'] ?? 0);
        $cnt = (int)(db_one("SELECT COUNT(*) AS c FROM ezd_zaswiadczenia_wlasne WHERE typ_id=?", [$tid])['c'] ?? 0);
        if ($cnt) flash_set('error', "Nie można usunąć: istnieje $cnt wniosek(ów) tego typu. Dezaktywuj zamiast usuwać.");
        else { db()->prepare("DELETE FROM ezd_zas_typy WHERE id=?")->execute([$tid]); flash_set('success', 'Typ usunięty.'); }
        header('Location:' . APP_URL . '/ezd/zaswiadczenia/typy.php'); exit;
    }
}

$edit_id   = (int)($_GET['edit'] ?? 0);
$edit      = $edit_id ? ezd_zas_typ_get($edit_id) : null;
$typy      = ezd_zas_typy_all();
$jrwa_list = db_all("SELECT id,symbol,title FROM ezd_jrwa WHERE is_active=1 ORDER BY symbol");
$PAGE_TITLE = 'Typy zaświadczeń';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.pola-row{background:#f8fafc;border:1px solid #e2e8f0;border-radius:.5rem;padding:.75rem 1rem;margin-bottom:.5rem;position:relative;}
.pola-row .btn-del-pole{position:absolute;top:.5rem;right:.5rem;}
.token-badge{font-family:monospace;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:.3rem;padding:.05rem .35rem;font-size:.75rem;cursor:pointer;}
.token-badge:hover{background:#dbeafe;}
</style>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/zaswiadczenia/index.php">Zaświadczenia</a></li>
  <li class="breadcrumb-item active">Typy zaświadczeń</li>
</ol></nav>

<?= flash_html() ?>

<div class="row g-4">
  <!-- Lista typów -->
  <div class="col-lg-5">
    <div class="card shadow-sm h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span class="fw-semibold" style="font-size:.88rem"><i class="bi bi-list-ul me-1 text-primary"></i>Zdefiniowane typy (<?= count($typy) ?>)</span>
        <a href="<?= APP_URL ?>/ezd/zaswiadczenia/typy.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nowy typ</a>
      </div>
      <div class="list-group list-group-flush" style="font-size:.84rem">
        <?php foreach($typy as $t): ?>
        <div class="list-group-item px-3 py-2 d-flex align-items-start gap-2">
          <div class="flex-grow-1 overflow-hidden">
            <div class="d-flex align-items-center gap-2">
              <span class="font-monospace fw-bold text-primary" style="font-size:.78rem"><?= h($t['kod']) ?></span>
              <?php if(!$t['is_active']): ?><span class="badge bg-secondary" style="font-size:.66rem">Nieaktywny</span><?php endif; ?>
              <?php if($t['wymaga_akceptacji']): ?><span class="badge bg-warning text-dark" style="font-size:.66rem">Wymaga akceptacji</span><?php endif; ?>
            </div>
            <div class="fw-semibold"><?= h($t['nazwa']) ?></div>
            <?php if($t['opis']): ?><div class="text-muted text-truncate" style="font-size:.76rem"><?= h($t['opis']) ?></div><?php endif; ?>
            <?php if($t['jrwa_symbol']): ?><div class="text-muted" style="font-size:.73rem"><i class="bi bi-tag me-1"></i>JRWA <?= h($t['jrwa_symbol']) ?></div><?php endif; ?>
          </div>
          <div class="d-flex gap-1 flex-shrink-0">
            <a href="?edit=<?= $t['id'] ?>" class="btn btn-sm btn-outline-secondary btn-xs py-0 px-1" title="Edytuj"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="toggle_active">
              <input type="hidden" name="typ_id" value="<?= $t['id'] ?>">
              <button class="btn btn-sm btn-outline-<?= $t['is_active']?'warning':'success' ?> py-0 px-1" title="<?= $t['is_active']?'Dezaktywuj':'Aktywuj' ?>"><i class="bi bi-<?= $t['is_active']?'pause-circle':'check-circle' ?>"></i></button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć typ <?= h(addslashes($t['nazwa'])) ?>?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete_typ">
              <input type="hidden" name="typ_id" value="<?= $t['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Usuń"><i class="bi bi-trash3"></i></button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if(!$typy): ?>
        <div class="text-center py-5 text-muted" style="font-size:.83rem">
          <i class="bi bi-card-list" style="font-size:2rem;display:block;margin-bottom:.4rem;opacity:.3"></i>
          Brak zdefiniowanych typów zaświadczeń. Dodaj pierwszy.
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Formularz edycji / dodawania -->
  <div class="col-lg-7">
    <form method="post" id="form-typ">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="save_typ">
      <input type="hidden" name="typ_id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">

      <div class="card shadow-sm">
        <div class="card-header fw-semibold" style="font-size:.88rem">
          <i class="bi bi-<?= $edit?'pencil':'plus-circle' ?> me-1 text-primary"></i>
          <?= $edit ? 'Edytuj typ: '.h($edit['nazwa']) : 'Nowy typ zaświadczenia' ?>
        </div>
        <div class="card-body">
          <div class="row g-3 mb-3">
            <div class="col-sm-5">
              <label class="form-label fw-semibold mb-1" style="font-size:.78rem">Kod (unikalny, a–z, cyfry, _)</label>
              <input type="text" name="kod" class="form-control form-control-sm font-monospace"
                     value="<?= h($edit['kod'] ?? '') ?>" required
                     pattern="[a-z0-9_]+" <?= $edit?'readonly':'' ?>>
              <?php if($edit): ?><div class="text-muted mt-1" style="font-size:.72rem">Kodu nie można zmienić po zapisaniu.</div><?php endif; ?>
            </div>
            <div class="col-sm-7">
              <label class="form-label fw-semibold mb-1" style="font-size:.78rem">Nazwa wyświetlana</label>
              <input type="text" name="nazwa" class="form-control form-control-sm"
                     value="<?= h($edit['nazwa'] ?? '') ?>" required>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold mb-1" style="font-size:.78rem">Opis (opcjonalny, widoczny przy wyborze)</label>
            <textarea name="opis" class="form-control form-control-sm" rows="2"><?= h($edit['opis'] ?? '') ?></textarea>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label class="form-label fw-semibold mb-1" style="font-size:.78rem">Teczka JRWA (opcjonalna)</label>
              <select name="jrwa_id" class="form-select form-select-sm">
                <option value="">— brak —</option>
                <?php foreach($jrwa_list as $j): ?>
                <option value="<?= $j['id'] ?>" <?= ($edit['jrwa_id'] ?? null)==$j['id']?'selected':'' ?>>
                  <?= h($j['symbol']) ?> — <?= h(mb_substr($j['title'],0,35)) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-3">
              <label class="form-label fw-semibold mb-1" style="font-size:.78rem">Workflow</label>
              <div class="form-check mt-1">
                <input class="form-check-input" type="checkbox" name="wymaga_akceptacji" value="1" id="chk-wym-ak"
                       <?= ($edit['wymaga_akceptacji']??1)?'checked':'' ?>>
                <label class="form-check-label" for="chk-wym-ak" style="font-size:.82rem">Wymaga akceptacji</label>
              </div>
            </div>
            <div class="col-sm-3">
              <label class="form-label fw-semibold mb-1" style="font-size:.78rem">Status</label>
              <div class="form-check mt-1">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="chk-active"
                       <?= ($edit['is_active']??1)?'checked':'' ?>>
                <label class="form-check-label" for="chk-active" style="font-size:.82rem">Aktywny</label>
              </div>
            </div>
          </div>

          <!-- Szablon treści -->
          <div class="mb-3">
            <label class="form-label fw-semibold mb-1" style="font-size:.78rem">
              Szablon treści zaświadczenia
              <button type="button" class="btn btn-link btn-sm p-0 ms-1" data-bs-toggle="collapse" data-bs-target="#tokens-help" style="font-size:.74rem">Dostępne tokeny <i class="bi bi-chevron-down"></i></button>
            </label>
            <div class="collapse mb-2" id="tokens-help">
              <div class="p-2 rounded" style="background:#f0f9ff;border:1px solid #bae6fd;font-size:.76rem">
                <div class="fw-semibold mb-1">Tokeny systemowe (zawsze dostępne):</div>
                <span class="token-badge me-1" onclick="insertToken('{{nr_zaswiadczenia}}')">{{nr_zaswiadczenia}}</span>
                <span class="token-badge me-1" onclick="insertToken('{{data_wydania}}')">{{data_wydania}}</span>
                <span class="token-badge me-1" onclick="insertToken('{{data_wydania_dl}}')">{{data_wydania_dl}}</span>
                <span class="token-badge me-1" onclick="insertToken('{{organizacja}}')">{{organizacja}}</span>
                <div class="fw-semibold mt-2 mb-1">Tokeny z pól wniosku (zdefiniowanych poniżej):</div>
                <span class="text-muted" style="font-size:.74rem">Użyj nazwy pola w podwójnych nawiasach klamrowych, np. <span class="token-badge">{{imie_nazwisko}}</span></span>
              </div>
            </div>
            <textarea name="szablon_tresc" id="szablon-tresc" class="form-control form-control-sm font-monospace" rows="9"
                      placeholder="Niniejszym zaświadcza się, że {{imie_nazwisko}} w okresie od {{okres_od}} do {{okres_do}} pełnił(a) funkcję {{stanowisko}} w organizacji {{organizacja}}.&#10;&#10;Zaświadczenie wydano w dniu {{data_wydania_dl}}.&#10;Nr: {{nr_zaswiadczenia}}"><?= h($edit['szablon_tresc'] ?? '') ?></textarea>
          </div>

          <!-- Pola formularza wniosku -->
          <div class="mb-2">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span class="fw-semibold" style="font-size:.82rem">Pola formularza wniosku</span>
              <button type="button" class="btn btn-outline-primary btn-sm" id="btn-add-pole"><i class="bi bi-plus-lg me-1"></i>Dodaj pole</button>
            </div>
            <div id="pola-container"></div>
            <div class="text-muted mt-1" style="font-size:.72rem"><i class="bi bi-info-circle me-1"></i>Nazwa pola staje się tokenem w szablonie — użyj <span class="token-badge">{{nazwa_pola}}</span>.</div>
            <input type="hidden" name="szablon_pola" id="szablon-pola-hidden" value="<?= h(json_encode($edit['pola'] ?? [], JSON_UNESCAPED_UNICODE)) ?>">
          </div>
        </div>
        <div class="card-footer d-flex gap-2 justify-content-end">
          <?php if($edit): ?><a href="<?= APP_URL ?>/ezd/zaswiadczenia/typy.php" class="btn btn-outline-secondary btn-sm">Anuluj</a><?php endif; ?>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i><?= $edit?'Zapisz zmiany':'Dodaj typ' ?></button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
const POLE_TYPES = ['text','date','textarea','select'];

let pola = <?= json_encode($edit['pola'] ?? [], JSON_UNESCAPED_UNICODE) ?>;

function renderPola() {
  const c = document.getElementById('pola-container');
  c.innerHTML = '';
  pola.forEach((p, i) => {
    const div = document.createElement('div');
    div.className = 'pola-row';
    div.innerHTML = `
      <button type="button" class="btn btn-link btn-sm text-danger p-0 btn-del-pole" onclick="delPole(${i})"><i class="bi bi-x-circle"></i></button>
      <div class="row g-2 align-items-end">
        <div class="col-sm-3">
          <label class="form-label fw-semibold mb-1" style="font-size:.72rem">Nazwa (token)</label>
          <input type="text" class="form-control form-control-sm font-monospace" value="${escHtml(p.name||'')}"
                 placeholder="np. imie_nazwisko" pattern="[a-z0-9_]+"
                 oninput="pola[${i}].name=this.value;syncPola()">
        </div>
        <div class="col-sm-3">
          <label class="form-label fw-semibold mb-1" style="font-size:.72rem">Etykieta</label>
          <input type="text" class="form-control form-control-sm" value="${escHtml(p.label||'')}"
                 placeholder="Wyświetlana etykieta"
                 oninput="pola[${i}].label=this.value;syncPola()">
        </div>
        <div class="col-sm-2">
          <label class="form-label fw-semibold mb-1" style="font-size:.72rem">Typ</label>
          <select class="form-select form-select-sm" onchange="pola[${i}].type=this.value;renderPola()">
            ${POLE_TYPES.map(t=>`<option value="${t}" ${p.type===t?'selected':''}>${t}</option>`).join('')}
          </select>
        </div>
        <div class="col-sm-2">
          <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" id="req-${i}" ${p.required?'checked':''}
                   onchange="pola[${i}].required=this.checked;syncPola()">
            <label class="form-check-label" for="req-${i}" style="font-size:.78rem">Wymagane</label>
          </div>
        </div>
        ${p.type==='select'?`
        <div class="col-sm-12">
          <label class="form-label fw-semibold mb-1" style="font-size:.72rem">Opcje (każda w osobnym wierszu)</label>
          <textarea class="form-control form-control-sm" rows="3"
                    oninput="pola[${i}].options=this.value.split('\\n').map(s=>s.trim()).filter(Boolean);syncPola()">${escHtml((p.options||[]).join('\n'))}</textarea>
        </div>`:''}
      </div>`;
    c.appendChild(div);
  });
  syncPola();
}

function escHtml(s){ const d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML; }

function syncPola() {
  document.getElementById('szablon-pola-hidden').value = JSON.stringify(pola);
}

function delPole(i) {
  pola.splice(i,1);
  renderPola();
}

document.getElementById('btn-add-pole').addEventListener('click', () => {
  pola.push({name:'',label:'',type:'text',required:false});
  renderPola();
});

function insertToken(token) {
  const ta = document.getElementById('szablon-tresc');
  const s = ta.selectionStart, e = ta.selectionEnd;
  ta.value = ta.value.substring(0,s) + token + ta.value.substring(e);
  ta.selectionStart = ta.selectionEnd = s + token.length;
  ta.focus();
}

renderPola();

document.getElementById('form-typ').addEventListener('submit', () => syncPola());
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
