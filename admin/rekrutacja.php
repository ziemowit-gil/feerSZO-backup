<?php
/**
 * admin/rekrutacja.php — Ustawienia modułu Rekrutacja
 * Sekcje: pipeline statusów, stanowiska, szablony e-mail, automatyzacje,
 * reguły autotagów, operatorzy rekrutacji.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rekrutacja.php';

rekr_migrate();
require_login();
if (!is_admin()) { http_response_code(403); die('Tylko administrator.'); }

$COLORS = ['primary','secondary','success','danger','warning','info','dark'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    switch ($action) {
        // ── Statusy ────────────────────────────────────────────────────────
        case 'status_save':
            $sid   = (int)($_POST['id'] ?? 0);
            $slug  = strtolower(preg_replace('/[^a-z0-9_-]/', '', $_POST['slug'] ?? ''));
            $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 100);
            $color = in_array($_POST['color'] ?? '', $COLORS, true) ? $_POST['color'] : 'secondary';
            $sort  = (int)($_POST['sort_order'] ?? 0);
            $term  = !empty($_POST['is_terminal']) ? 1 : 0;
            if ($slug !== '' && $label !== '') {
                if ($sid) {
                    db_exec("UPDATE rekr_statuses SET label=?, color=?, sort_order=?, is_terminal=? WHERE id=?",
                        [$label, $color, $sort, $term, $sid]);
                } else {
                    try {
                        db_insert('rekr_statuses', ['slug'=>$slug, 'label'=>$label, 'color'=>$color, 'sort_order'=>$sort, 'is_terminal'=>$term]);
                    } catch (\Throwable $e) { flash_set('error', 'Status o tym identyfikatorze już istnieje.'); }
                }
                if (empty($_SESSION['flash'])) flash_set('success', 'Status zapisany.');
            }
            break;

        case 'status_del':
            $sid = (int)($_POST['id'] ?? 0);
            $st  = db_one("SELECT * FROM rekr_statuses WHERE id=?", [$sid]);
            if ($st) {
                $used = (int)(db_one("SELECT COUNT(*) AS c FROM rekr_applications WHERE status=?", [$st['slug']])['c'] ?? 0);
                if ($used) flash_set('error', "Nie można usunąć — {$used} zgłoszeń ma ten status.");
                else { db_exec("DELETE FROM rekr_statuses WHERE id=?", [$sid]); flash_set('success', 'Status usunięty.'); }
            }
            break;

        // ── Stanowiska ─────────────────────────────────────────────────────
        case 'position_save':
            $pid = (int)($_POST['id'] ?? 0);
            $data = [
                'name'         => mb_substr(trim((string)($_POST['name'] ?? '')), 0, 200),
                'type'         => isset(REKR_TYPES[$_POST['type'] ?? '']) ? $_POST['type'] : 'wolontariat',
                'description'  => mb_substr(trim((string)($_POST['description'] ?? '')), 0, 5000),
                'auto_tags'    => mb_substr(trim(mb_strtolower((string)($_POST['auto_tags'] ?? ''))), 0, 500),
                'crm_group_id' => (int)($_POST['crm_group_id'] ?? 0) ?: null,
                'is_active'    => !empty($_POST['is_active']) ? 1 : 0,
            ];
            if ($data['name'] !== '') {
                $pid ? db_update('rekr_positions', $data, $pid) : db_insert('rekr_positions', $data);
                flash_set('success', 'Stanowisko zapisane.');
            }
            break;

        case 'position_del':
            db_exec("DELETE FROM rekr_positions WHERE id=?", [(int)($_POST['id'] ?? 0)]);
            flash_set('success', 'Stanowisko usunięte (zgłoszenia pozostają, bez przypisania).');
            break;

        // ── Szablony e-mail ────────────────────────────────────────────────
        case 'tpl_save':
            $tid = (int)($_POST['id'] ?? 0);
            $data = [
                'name'      => mb_substr(trim((string)($_POST['name'] ?? '')), 0, 200),
                'subject'   => mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 300),
                'body_html' => rekr_sanitize_html((string)($_POST['body_html'] ?? '')),
                'enabled'   => !empty($_POST['enabled']) ? 1 : 0,
            ];
            if ($data['name'] !== '' && $data['subject'] !== '') {
                $tid ? db_update('rekr_email_templates', $data, $tid) : db_insert('rekr_email_templates', $data);
                flash_set('success', 'Szablon zapisany.');
            }
            break;

        case 'tpl_del':
            db_exec("DELETE FROM rekr_email_templates WHERE id=?", [(int)($_POST['id'] ?? 0)]);
            flash_set('success', 'Szablon usunięty (wraz z automatyzacjami, które go używały).');
            break;

        // ── Automatyzacje ──────────────────────────────────────────────────
        case 'auto_save':
            $slug   = (string)($_POST['status_slug'] ?? '');
            $act    = isset(REKR_AUTOMATION_ACTIONS[$_POST['auto_action'] ?? '']) ? $_POST['auto_action'] : '';
            $tplid  = (int)($_POST['template_id'] ?? 0) ?: null;
            $value  = mb_substr(trim((string)($_POST['value'] ?? '')), 0, 200);
            if (isset(rekr_statuses()[$slug]) && $act !== ''
                && ($act !== 'send_template' || $tplid)
                && ($act === 'send_template' || $value !== '')) {
                db_insert('rekr_automations', ['status_slug'=>$slug, 'action'=>$act, 'template_id'=>$tplid, 'value'=>$value]);
                flash_set('success', 'Automatyzacja dodana.');
            } else {
                flash_set('error', 'Uzupełnij poprawnie pola automatyzacji.');
            }
            break;

        case 'auto_toggle':
            db_exec("UPDATE rekr_automations SET enabled = 1-enabled WHERE id=?", [(int)($_POST['id'] ?? 0)]);
            break;

        case 'auto_del':
            db_exec("DELETE FROM rekr_automations WHERE id=?", [(int)($_POST['id'] ?? 0)]);
            flash_set('success', 'Automatyzacja usunięta.');
            break;

        // ── Autotagi ───────────────────────────────────────────────────────
        case 'rule_save':
            $kw  = mb_substr(trim(mb_strtolower((string)($_POST['keyword'] ?? ''))), 0, 100);
            $tag = mb_substr(trim(mb_strtolower((string)($_POST['tag'] ?? ''))), 0, 60);
            if ($kw !== '' && $tag !== '') {
                db_exec("INSERT OR IGNORE INTO rekr_autotag_rules (keyword, tag) VALUES (?,?)", [$kw, $tag]);
                flash_set('success', 'Reguła dodana.');
            }
            break;

        case 'rule_del':
            db_exec("DELETE FROM rekr_autotag_rules WHERE id=?", [(int)($_POST['id'] ?? 0)]);
            break;

        // ── TidyCal ────────────────────────────────────────────────────────
        case 'tidycal_save':
            org_setting_set('rekrutacja_tidycal_type_id', (string)(int)($_POST['type_id'] ?? 0));
            flash_set('success', 'Zapisano typ rezerwacji TidyCal dla rozmów rekrutacyjnych.');
            break;

        // ── Operatorzy ─────────────────────────────────────────────────────
        case 'operator_toggle':
            $tuid = (int)($_POST['user_id'] ?? 0);
            if ($tuid) db_exec("UPDATE users SET rekrutacja_operator = 1-COALESCE(rekrutacja_operator,0) WHERE id=?", [$tuid]);
            break;
    }
    header('Location: ' . APP_URL . '/admin/rekrutacja.php' . (isset($_POST['_anchor']) ? '#' . preg_replace('/[^a-z-]/', '', $_POST['_anchor']) : ''));
    exit;
}

$statuses   = array_values(rekr_statuses());
$positions  = rekr_positions(false);
$templates  = db_all("SELECT * FROM rekr_email_templates ORDER BY name");
$autos      = db_all("SELECT a.*, t.name AS tpl_name FROM rekr_automations a
                      LEFT JOIN rekr_email_templates t ON t.id=a.template_id
                      ORDER BY a.status_slug, a.id");
$rules      = db_all("SELECT * FROM rekr_autotag_rules ORDER BY keyword");
$users      = db_all("SELECT id, name, email, role, COALESCE(rekrutacja_operator,0) AS op FROM users WHERE active=1 ORDER BY name");
$crm_groups = [];
try { $crm_groups = db_all("SELECT id, name FROM crm_groups ORDER BY name"); } catch (\Throwable $e) {}

$PAGE_TITLE = 'Nabór — ustawienia';
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-gear me-2" aria-hidden="true"></i>Nabór — ustawienia</h1>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h(APP_URL) ?>/rekrutacja/index.php">Do listy zgłoszeń</a>
  </div>

  <!-- ── Pipeline statusów ─────────────────────────────────────────────── -->
  <section class="card mb-4" id="statusy" aria-labelledby="h-statusy">
    <div class="card-header"><h2 class="h6 mb-0" id="h-statusy">Pipeline statusów</h2></div>
    <div class="card-body">
      <p class="small text-muted">Kolejność wg pola „sortowanie". Pierwszy status na liście jest nadawany nowym zgłoszeniom. Statusy końcowe oznaczają zakończony proces.</p>
      <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>Identyfikator</th><th>Etykieta</th><th>Kolor</th><th>Sortowanie</th><th>Końcowy</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($statuses as $s): ?>
        <tr>
          <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="status_save"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <input type="hidden" name="_anchor" value="statusy"><input type="hidden" name="slug" value="<?= h($s['slug']) ?>">
          <td><code><?= h($s['slug']) ?></code></td>
          <td><input class="form-control form-control-sm" name="label" value="<?= h($s['label']) ?>" aria-label="Etykieta statusu <?= h($s['slug']) ?>"></td>
          <td><select class="form-select form-select-sm" name="color" aria-label="Kolor statusu <?= h($s['slug']) ?>">
            <?php foreach ($COLORS as $c): ?><option value="<?= $c ?>" <?= $s['color']===$c?'selected':'' ?>><?= $c ?></option><?php endforeach; ?>
          </select></td>
          <td style="width:90px"><input type="number" class="form-control form-control-sm" name="sort_order" value="<?= (int)$s['sort_order'] ?>" aria-label="Sortowanie statusu <?= h($s['slug']) ?>"></td>
          <td class="text-center"><input type="checkbox" class="form-check-input" name="is_terminal" value="1" <?= $s['is_terminal']?'checked':'' ?> aria-label="Status końcowy <?= h($s['slug']) ?>"></td>
          <td class="text-nowrap">
            <button class="btn btn-sm btn-outline-primary">Zapisz</button>
          </form>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć status?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="status_del"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="_anchor" value="statusy">
              <button class="btn btn-sm btn-outline-danger" aria-label="Usuń status <?= h($s['label']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <tr>
          <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="status_save"><input type="hidden" name="_anchor" value="statusy">
          <td><input class="form-control form-control-sm" name="slug" placeholder="np. test_prakt" pattern="[a-z0-9_-]+" aria-label="Identyfikator nowego statusu"></td>
          <td><input class="form-control form-control-sm" name="label" placeholder="Etykieta" aria-label="Etykieta nowego statusu"></td>
          <td><select class="form-select form-select-sm" name="color" aria-label="Kolor nowego statusu"><?php foreach ($COLORS as $c): ?><option><?= $c ?></option><?php endforeach; ?></select></td>
          <td><input type="number" class="form-control form-control-sm" name="sort_order" value="<?= (count($statuses)+1)*10 ?>" aria-label="Sortowanie nowego statusu"></td>
          <td class="text-center"><input type="checkbox" class="form-check-input" name="is_terminal" value="1" aria-label="Nowy status końcowy"></td>
          <td><button class="btn btn-sm btn-primary">Dodaj</button></td>
          </form>
        </tr>
        </tbody>
      </table></div>
    </div>
  </section>

  <!-- ── Stanowiska ────────────────────────────────────────────────────── -->
  <section class="card mb-4" id="stanowiska" aria-labelledby="h-stanowiska">
    <div class="card-header"><h2 class="h6 mb-0" id="h-stanowiska">Stanowiska / obszary rekrutacji</h2></div>
    <div class="card-body">
      <p class="small text-muted">„Autotagi" (CSV) są nadawane każdemu zgłoszeniu na to stanowisko. „Grupa CRM" — kandydat trafia do niej automatycznie po złożeniu aplikacji.</p>
      <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>Nazwa</th><th>Typ</th><th>Autotagi (CSV)</th><th>Grupa CRM</th><th>Aktywne</th><th></th></tr></thead>
        <tbody>
        <?php foreach (array_merge($positions, [null]) as $p): $new = $p === null; ?>
        <tr>
          <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="position_save"><input type="hidden" name="_anchor" value="stanowiska">
          <?php if (!$new): ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><?php endif; ?>
          <td><input class="form-control form-control-sm" name="name" value="<?= h($p['name'] ?? '') ?>" placeholder="np. Wolontariusz IT" aria-label="Nazwa stanowiska" required></td>
          <td><select class="form-select form-select-sm" name="type" aria-label="Typ stanowiska">
            <?php foreach (REKR_TYPES as $k => $t): ?><option value="<?= h($k) ?>" <?= ($p['type'] ?? '')===$k?'selected':'' ?>><?= h($t['label']) ?></option><?php endforeach; ?>
          </select></td>
          <td><input class="form-control form-control-sm" name="auto_tags" value="<?= h($p['auto_tags'] ?? '') ?>" placeholder="it, wolontariat" aria-label="Autotagi"></td>
          <td><select class="form-select form-select-sm" name="crm_group_id" aria-label="Grupa CRM">
            <option value="">— domyślna wg typu —</option>
            <?php foreach ($crm_groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= (int)($p['crm_group_id'] ?? 0)===(int)$g['id']?'selected':'' ?>><?= h($g['name']) ?></option><?php endforeach; ?>
          </select></td>
          <td class="text-center"><input type="checkbox" class="form-check-input" name="is_active" value="1" <?= ($new || $p['is_active'])?'checked':'' ?> aria-label="Stanowisko aktywne"></td>
          <td class="text-nowrap">
            <button class="btn btn-sm btn-<?= $new?'primary':'outline-primary' ?>"><?= $new?'Dodaj':'Zapisz' ?></button>
          </form>
            <?php if (!$new): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć stanowisko?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="position_del"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="_anchor" value="stanowiska">
              <button class="btn btn-sm btn-outline-danger" aria-label="Usuń stanowisko <?= h($p['name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </section>

  <!-- ── Szablony e-mail ───────────────────────────────────────────────── -->
  <section class="card mb-4" id="szablony" aria-labelledby="h-szablony">
    <div class="card-header"><h2 class="h6 mb-0" id="h-szablony">Szablony e-mail</h2></div>
    <div class="card-body">
      <p class="small text-muted">Dostępne zmienne: <code>{{imie}}</code> <code>{{nazwisko}}</code> <code>{{email}}</code> <code>{{stanowisko}}</code> <code>{{typ}}</code> <code>{{status}}</code> <code>{{termin_spotkania}}</code> <code>{{link_do_spotkania}}</code> <code>{{link_tidycal}}</code> <code>{{organizacja}}</code></p>
      <div class="accordion" id="tplAccordion">
        <?php foreach (array_merge($templates, [null]) as $i => $t): $new = $t === null; $aid = $new ? 'new' : (int)$t['id']; ?>
        <div class="accordion-item">
          <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#tpl-<?= $aid ?>" aria-expanded="false">
              <?= $new ? '➕ Nowy szablon' : h($t['name']) . (!$t['enabled'] ? ' (wyłączony)' : '') ?>
            </button>
          </h3>
          <div id="tpl-<?= $aid ?>" class="accordion-collapse collapse" data-bs-parent="#tplAccordion">
            <div class="accordion-body">
              <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="tpl_save"><input type="hidden" name="_anchor" value="szablony">
                <?php if (!$new): ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><?php endif; ?>
                <div class="row g-2">
                  <div class="col-md-6">
                    <label class="form-label small mb-1" for="tpl-name-<?= $aid ?>">Nazwa</label>
                    <input class="form-control form-control-sm" id="tpl-name-<?= $aid ?>" name="name" value="<?= h($t['name'] ?? '') ?>" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small mb-1" for="tpl-subject-<?= $aid ?>">Temat</label>
                    <input class="form-control form-control-sm" id="tpl-subject-<?= $aid ?>" name="subject" value="<?= h($t['subject'] ?? '') ?>" required>
                  </div>
                  <div class="col-12">
                    <label class="form-label small mb-1" for="tpl-body-<?= $aid ?>">Treść (HTML — dozwolone p, br, b, i, u, ul, ol, li, a, h3, h4, blockquote)</label>
                    <textarea class="form-control form-control-sm font-monospace" id="tpl-body-<?= $aid ?>" name="body_html" rows="7"><?= h($t['body_html'] ?? '') ?></textarea>
                  </div>
                  <div class="col-12 d-flex gap-2 align-items-center">
                    <div class="form-check">
                      <input type="checkbox" class="form-check-input" id="tpl-en-<?= $aid ?>" name="enabled" value="1" <?= ($new || $t['enabled'])?'checked':'' ?>>
                      <label class="form-check-label small" for="tpl-en-<?= $aid ?>">Włączony</label>
                    </div>
                    <button class="btn btn-sm btn-primary ms-auto"><?= $new?'Dodaj szablon':'Zapisz' ?></button>
              </form>
                    <?php if (!$new): ?>
                    <form method="post" onsubmit="return confirm('Usunąć szablon?')">
                      <?= csrf_field() ?><input type="hidden" name="action" value="tpl_del"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="_anchor" value="szablony">
                      <button class="btn btn-sm btn-outline-danger">Usuń</button>
                    </form>
                    <?php endif; ?>
                  </div>
                </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ── Automatyzacje ─────────────────────────────────────────────────── -->
  <section class="card mb-4" id="automatyzacje" aria-labelledby="h-auto">
    <div class="card-header"><h2 class="h6 mb-0" id="h-auto">Automatyzacje przy zmianie statusu</h2></div>
    <div class="card-body">
      <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>Gdy status</th><th>Akcja</th><th>Parametr</th><th>Stan</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($autos as $a): ?>
        <tr class="<?= $a['enabled'] ? '' : 'text-muted' ?>">
          <td><?= rekr_status_badge($a['status_slug']) ?></td>
          <td><?= h(REKR_AUTOMATION_ACTIONS[$a['action']] ?? $a['action']) ?></td>
          <td><?= $a['action'] === 'send_template' ? h($a['tpl_name'] ?? '?') : h($a['value']) ?></td>
          <td>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="auto_toggle"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="_anchor" value="automatyzacje">
              <button class="btn btn-sm btn-outline-<?= $a['enabled']?'success':'secondary' ?>"><?= $a['enabled']?'Włączona':'Wyłączona' ?></button>
            </form>
          </td>
          <td>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć automatyzację?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="auto_del"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="_anchor" value="automatyzacje">
              <button class="btn btn-sm btn-outline-danger" aria-label="Usuń automatyzację"><i class="bi bi-trash" aria-hidden="true"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>

      <form method="post" class="row g-2 align-items-end border-top pt-3">
        <?= csrf_field() ?><input type="hidden" name="action" value="auto_save"><input type="hidden" name="_anchor" value="automatyzacje">
        <div class="col-md-3">
          <label class="form-label small mb-1" for="au-status">Gdy zgłoszenie wejdzie w status</label>
          <select class="form-select form-select-sm" id="au-status" name="status_slug">
            <?php foreach (rekr_statuses() as $s): ?><option value="<?= h($s['slug']) ?>"><?= h($s['label']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small mb-1" for="au-action">Akcja</label>
          <select class="form-select form-select-sm" id="au-action" name="auto_action">
            <?php foreach (REKR_AUTOMATION_ACTIONS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small mb-1" for="au-tpl">Szablon (dla wysyłki e-mail)</label>
          <select class="form-select form-select-sm" id="au-tpl" name="template_id">
            <option value="">—</option>
            <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1" for="au-value">Tag / ID grupy CRM</label>
          <input class="form-control form-control-sm" id="au-value" name="value" placeholder="np. odrzucony">
        </div>
        <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Dodaj</button></div>
      </form>
    </div>
  </section>

  <!-- ── Autotagi ──────────────────────────────────────────────────────── -->
  <section class="card mb-4" id="autotagi" aria-labelledby="h-rules">
    <div class="card-header"><h2 class="h6 mb-0" id="h-rules">Reguły autotagów (słowo kluczowe → tag)</h2></div>
    <div class="card-body">
      <p class="small text-muted">Słowa kluczowe są wyszukiwane w wiadomości kandydata i wyekstrahowanej treści CV/listu (bez rozróżniania wielkości liter).</p>
      <div class="d-flex flex-wrap gap-2 mb-3">
        <?php foreach ($rules as $r): ?>
        <form method="post" class="d-inline">
          <?= csrf_field() ?><input type="hidden" name="action" value="rule_del"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="_anchor" value="autotagi">
          <span class="badge text-bg-light border p-2">
            „<?= h($r['keyword']) ?>" → <strong><?= h($r['tag']) ?></strong>
            <button class="btn btn-link btn-sm p-0 ms-1 align-baseline" style="line-height:1" aria-label="Usuń regułę <?= h($r['keyword']) ?>">×</button>
          </span>
        </form>
        <?php endforeach; ?>
        <?php if (!$rules): ?><span class="text-muted small">Brak reguł.</span><?php endif; ?>
      </div>
      <form method="post" class="row g-2 align-items-end">
        <?= csrf_field() ?><input type="hidden" name="action" value="rule_save"><input type="hidden" name="_anchor" value="autotagi">
        <div class="col-md-4">
          <label class="form-label small mb-1" for="rl-kw">Słowo kluczowe</label>
          <input class="form-control form-control-sm" id="rl-kw" name="keyword" placeholder="np. księgowość" required>
        </div>
        <div class="col-md-4">
          <label class="form-label small mb-1" for="rl-tag">Tag</label>
          <input class="form-control form-control-sm" id="rl-tag" name="tag" placeholder="np. finanse" required>
        </div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Dodaj regułę</button></div>
      </form>
    </div>
  </section>

  <!-- ── TidyCal ───────────────────────────────────────────────────────── -->
  <section class="card mb-4" id="tidycal" aria-labelledby="h-tidycal">
    <div class="card-header"><h2 class="h6 mb-0" id="h-tidycal">Umawianie rozmów przez TidyCal</h2></div>
    <div class="card-body">
      <?php
      require_once dirname(__DIR__) . '/includes/tidycal.php';
      $tc_types = tidycal_enabled() ? tidycal_types_cache() : [];
      $tc_sel   = (int)org_setting('rekrutacja_tidycal_type_id');
      ?>
      <?php if (!tidycal_enabled()): ?>
        <div class="alert alert-warning mb-0">
          Integracja TidyCal nie jest skonfigurowana — uzupełnij klucz API w
          <a href="<?= h(APP_URL) ?>/admin/tidycal_settings.php">ustawieniach TidyCal</a>.
        </div>
      <?php elseif (!$tc_types): ?>
        <div class="alert alert-warning mb-0">
          Brak pobranych typów rezerwacji — wykonaj test połączenia w
          <a href="<?= h(APP_URL) ?>/admin/tidycal_settings.php">ustawieniach TidyCal</a>, aby odświeżyć listę.
        </div>
      <?php else: ?>
        <p class="small text-muted">Wybrany typ rezerwacji będzie dostępny na karcie kandydata oraz jako zmienna <code>{{link_tidycal}}</code> w szablonach — kandydat sam wybiera dogodny termin rozmowy.</p>
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?><input type="hidden" name="action" value="tidycal_save"><input type="hidden" name="_anchor" value="tidycal">
          <div class="col-md-6">
            <label class="form-label small mb-1" for="tc-type">Typ rezerwacji dla rozmów rekrutacyjnych</label>
            <select class="form-select form-select-sm" id="tc-type" name="type_id">
              <option value="0">— wyłączone —</option>
              <?php foreach ($tc_types as $t): ?>
              <option value="<?= (int)$t['id'] ?>" <?= $tc_sel === (int)$t['id'] ? 'selected' : '' ?>>
                <?= h($t['title']) ?><?= $t['duration'] ? ' (' . (int)$t['duration'] . ' min)' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Zapisz</button></div>
        </form>
      <?php endif; ?>
    </div>
  </section>

  <!-- ── Operatorzy ────────────────────────────────────────────────────── -->
  <section class="card mb-4" id="operatorzy" aria-labelledby="h-op">
    <div class="card-header"><h2 class="h6 mb-0" id="h-op">Operatorzy rekrutacji</h2></div>
    <div class="card-body">
      <p class="small text-muted">Administratorzy mają dostęp zawsze. Poniżej można nadać dostęp pozostałym kontom.</p>
      <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>Użytkownik</th><th>Rola</th><th>Dostęp do rekrutacji</th></tr></thead>
        <tbody>
        <?php foreach ($users as $usr): if ($usr['role'] === 'admin') continue; ?>
        <tr>
          <td><?= h($usr['name']) ?> <span class="text-muted small"><?= h($usr['email']) ?></span></td>
          <td><code><?= h($usr['role']) ?></code></td>
          <td>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="operator_toggle"><input type="hidden" name="user_id" value="<?= (int)$usr['id'] ?>"><input type="hidden" name="_anchor" value="operatorzy">
              <button class="btn btn-sm btn-outline-<?= $usr['op']?'success':'secondary' ?>"><?= $usr['op'] ? 'Tak — odbierz' : 'Nie — nadaj' ?></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </section>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
