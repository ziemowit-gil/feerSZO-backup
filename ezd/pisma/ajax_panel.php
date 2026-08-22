<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_kopia.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_mail.php';
require_login(); require_module_enabled('ezd_enabled', ''); ezd_require_access();

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$id    = (int)($_GET['id'] ?? 0);
$pismo = ezd_pismo_get($id);
if (!$pismo) { http_response_code(404); echo '<div class="text-danger p-3">Pismo nie istnieje.</div>'; exit; }

$sprawa_id = (int)$pismo['sprawa_id'];
$sprawa    = ezd_sprawa_get($sprawa_id);
$access    = $sprawa ? ezd_sprawa_access($sprawa, (int)current_user()['id']) : null;
if (!$access) { http_response_code(403); echo '<div class="text-danger p-3">Brak dostępu.</div>'; exit; }

$can_act = $access === 'write' && ($pismo['sprawa_status'] ?? '') !== 'closed';
$kier    = EZD_KIERUNKI[$pismo['kierunek']] ?? ['label' => $pismo['kierunek'], 'icon' => 'bi-envelope', 'class' => 'secondary'];
$zal     = ezd_zalaczniki_by($sprawa_id, $id);
$csrf    = csrf_token();

$statuses = ['nowe' => 'Nowe', 'w_toku' => 'W toku', 'odpowiedziano' => 'Odpowiedziano', 'archiwum' => 'Archiwum'];

// ── Oryginalna wiadomość e-mail ──────────────────────────────────────────────
// Pisma powstałe z poczty (import z koszulki albo przypisanie z Inboxu) trzymają
// ezd_pisma.comm_id. Pokazujemy treść maila wprost w panelu — bez tego trzeba było
// wychodzić z koszulki do modułu poczty, żeby przeczytać, o co w piśmie chodzi.
/** Rozmiar pliku po ludzku — lokalnie, bez wciągania modułu Koszulek dla jednej funkcji. */
$fmt_size = static function (int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024) . ' kB';
    return round($b / 1048576, 1) . ' MB';
};

$mail = null;
if (!empty($pismo['comm_id'])) {
    try {
        $mail = db_one(
            "SELECT c.*, m.mailbox AS mailbox_name
               FROM crm_communications c
               LEFT JOIN poczta_mailboxes m ON m.id = c.mailbox_id
              WHERE c.id = ?",
            [(int)$pismo['comm_id']]
        );
    } catch (\Throwable $e) { $mail = null; }

    $mail_att  = [];
    $mail_hist = [];
    $mail_users = [];
    if ($mail) {
        try {
            $_svc_m     = new EzdMailService();
            $mail_hist  = $_svc_m->assignHistory((int)$pismo['comm_id']);
            $mail_users = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");
        } catch (\Throwable $e) { $mail_hist = []; $mail_users = []; }
    }
    if ($mail) {
        try {
            $mail_att = db_all(
                "SELECT id, original_name, mime_type, size_bytes, stored_path
                   FROM poczta_attachments WHERE communication_id = ? ORDER BY id",
                [(int)$pismo['comm_id']]
            );
        } catch (\Throwable $e) { $mail_att = []; }
    }
}
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <span class="badge bg-<?= $kier['class'] ?> bg-opacity-15 text-<?= $kier['class'] ?> border border-<?= $kier['class'] ?>">
    <i class="bi <?= $kier['icon'] ?> me-1"></i><?= h($kier['label']) ?>
  </span>
  <span class="badge bg-secondary bg-opacity-10 text-secondary border font-monospace" style="font-size:.7rem"><?= h($pismo['sygnatura']) ?></span>
  <?php if (!empty($pismo['rodzaj_medium'])): ?>
  <span class="badge bg-secondary bg-opacity-10 text-secondary border" style="font-size:.68rem">
    <i class="bi <?= EZD_MEDIA[$pismo['rodzaj_medium']]['icon'] ?? 'bi-question-circle' ?> me-1"></i><?= h(EZD_MEDIA[$pismo['rodzaj_medium']]['label'] ?? $pismo['rodzaj_medium']) ?>
  </span>
  <?php endif; ?>
</div>

<h6 class="fw-bold mb-3"><?= h($pismo['title']) ?></h6>

<dl class="row mb-3" style="font-size:.82rem;row-gap:.25rem">
  <?php if ($pismo['nadawca']): ?>
  <dt class="col-5 text-muted fw-normal">Nadawca</dt><dd class="col-7 mb-0"><?= h($pismo['nadawca']) ?></dd>
  <?php endif; ?>
  <?php if ($pismo['odbiorca']): ?>
  <dt class="col-5 text-muted fw-normal">Odbiorca</dt><dd class="col-7 mb-0"><?= h($pismo['odbiorca']) ?></dd>
  <?php endif; ?>
  <?php if ($pismo['data_pisma']): ?>
  <dt class="col-5 text-muted fw-normal">Data pisma</dt><dd class="col-7 mb-0"><?= date_pl($pismo['data_pisma']) ?></dd>
  <?php endif; ?>
  <?php if ($pismo['data_wplywu']): ?>
  <dt class="col-5 text-muted fw-normal">Data wpływu</dt><dd class="col-7 mb-0"><?= date_pl($pismo['data_wplywu']) ?></dd>
  <?php endif; ?>
  <?php if ($pismo['data_wysylki']): ?>
  <dt class="col-5 text-muted fw-normal">Data wysyłki</dt><dd class="col-7 mb-0"><?= date_pl($pismo['data_wysylki']) ?></dd>
  <?php endif; ?>
  <dt class="col-5 text-muted fw-normal">Referent</dt><dd class="col-7 mb-0"><?= h($pismo['owner_name'] ?? '—') ?></dd>
</dl>

<?php if ($pismo['tresc']): ?>
<div class="border rounded p-2 mb-3 bg-light" style="font-size:.82rem;white-space:pre-wrap;max-height:160px;overflow-y:auto"><?= h($pismo['tresc']) ?></div>
<?php endif; ?>

<?php if ($mail): ?>
<!-- Oryginalna wiadomość e-mail (pismo z poczty) -->
<div class="border rounded mb-3">
  <div class="d-flex align-items-center gap-2 px-2 py-1 bg-body-secondary border-bottom" style="font-size:.75rem">
    <i class="bi bi-envelope-open text-primary" aria-hidden="true"></i>
    <span class="fw-semibold">Wiadomość e-mail</span>
    <?php if (!empty($mail['mailbox_name'])): ?>
    <span class="font-monospace text-muted"><?= h($mail['mailbox_name']) ?></span>
    <?php endif; ?>
    <a class="ms-auto text-decoration-none"
       href="<?= APP_URL ?>/ezd/poczta/thread.php?comm_id=<?= (int)$mail['id'] ?>"
       title="Otwórz cały wątek w Poczcie EZD">wątek <i class="bi bi-box-arrow-up-right"></i></a>
  </div>
  <dl class="row mb-0 px-2 py-2" style="font-size:.78rem;row-gap:.2rem">
    <dt class="col-4 text-muted fw-normal">Od</dt>
    <dd class="col-8 mb-0">
      <?= h(trim((string)($mail['from_name'] ?? '')) ?: (string)($mail['from_email'] ?? '—')) ?>
      <?php if (!empty($mail['from_name']) && !empty($mail['from_email'])): ?>
      <span class="text-muted">&lt;<?= h($mail['from_email']) ?>&gt;</span>
      <?php endif; ?>
    </dd>
    <dt class="col-4 text-muted fw-normal">Data</dt>
    <dd class="col-8 mb-0"><?= h(!empty($mail['sent_at']) ? date('d.m.Y H:i', strtotime((string)$mail['sent_at'])) : '—') ?></dd>
    <?php if (!empty($mail['subject'])): ?>
    <dt class="col-4 text-muted fw-normal">Temat</dt><dd class="col-8 mb-0"><?= h($mail['subject']) ?></dd>
    <?php endif; ?>
  </dl>

  <?php
    // Treść maila może być HTML-em od dowolnego nadawcy — pokazujemy jako TEKST,
    // nigdy nie wstrzykujemy do DOM. Panel pisma nie jest miejscem na obcy HTML.
    $mail_text = trim((string)($mail['body'] ?? ''));
    if ($mail_text === '') $mail_text = trim((string)($mail['body_html'] ?? ''));
    if ($mail_text !== '' && strip_tags($mail_text) !== $mail_text) {
        $mail_text = trim(html_entity_decode(strip_tags(preg_replace(
            ['#<br\s*/?>#i', '#</(p|div|tr|li|h[1-6])>#i'], "\n", $mail_text
        ) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $mail_text = preg_replace("/\n{3,}/", "\n\n", $mail_text) ?? $mail_text;
    }
  ?>
  <?php if ($mail_text !== ''): ?>
  <div class="px-2 pb-2">
    <div class="border rounded p-2 bg-light" style="font-size:.8rem;white-space:pre-wrap;max-height:260px;overflow-y:auto"><?= h($mail_text) ?></div>
  </div>
  <?php else: ?>
  <div class="px-2 pb-2 text-muted" style="font-size:.78rem">Wiadomość bez treści tekstowej.</div>
  <?php endif; ?>

  <?php
    $assigned_name = null;
    if (!empty($mail['assigned_to'])) {
        $assigned_name = db_one("SELECT name FROM users WHERE id=?", [(int)$mail['assigned_to']])['name'] ?? null;
    }
  ?>
  <div class="px-2 pb-2">
    <div class="d-flex align-items-center gap-2 mb-1" style="font-size:.76rem">
      <span class="text-muted">Prowadzi:</span>
      <?php if ($assigned_name): ?>
      <span class="badge bg-primary bg-opacity-10 text-primary border border-primary"><?= h($assigned_name) ?></span>
      <?php else: ?>
      <span class="text-muted fst-italic">nieprzypisana</span>
      <?php endif; ?>
    </div>

    <?php if ($mail_hist): ?>
    <details class="mb-1">
      <summary style="cursor:pointer;font-size:.74rem" class="text-primary">
        Historia przekazań (<?= count($mail_hist) ?>)
      </summary>
      <ul class="list-unstyled mb-0 mt-1 ps-2" style="font-size:.74rem;border-left:2px solid var(--bs-border-color)">
        <?php foreach ($mail_hist as $hh): ?>
        <li class="mb-1">
          <span class="text-muted"><?= h(date('d.m.Y H:i', strtotime((string)$hh['created_at']))) ?></span>
          — <?= h($hh['from_name'] ?: 'kancelaria') ?> → <strong><?= h($hh['to_name'] ?: '(zdjęto)') ?></strong>
          <?php if (!empty($hh['by_name'])): ?><span class="text-muted">(<?= h($hh['by_name']) ?>)</span><?php endif; ?>
          <?php if (trim((string)$hh['note']) !== ''): ?>
          <div class="text-muted" style="white-space:pre-wrap"><?= h($hh['note']) ?></div>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </details>
    <?php endif; ?>

    <?php if ($can_act && $mail_users): ?>
    <details>
      <summary style="cursor:pointer;font-size:.74rem" class="text-primary">
        <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Przekaż dalej
      </summary>
      <form method="post" action="<?= APP_URL ?>/ezd/poczta/forward.php" class="mt-2">
        <input type="hidden" name="_csrf"   value="<?= h($csrf) ?>">
        <input type="hidden" name="comm_id" value="<?= (int)$mail['id'] ?>">
        <input type="hidden" name="back"    value="<?= h(APP_URL . '/ezd/sprawy/view.php?id=' . $sprawa_id) ?>">
        <label class="visually-hidden" for="fwdUser<?= $id ?>">Osoba, której przekazujesz</label>
        <select name="user_id" id="fwdUser<?= $id ?>" class="form-select form-select-sm mb-1" required>
          <option value="">— wybierz osobę —</option>
          <?php foreach ($mail_users as $uu): ?>
          <option value="<?= (int)$uu['id'] ?>"<?= (int)($mail['assigned_to'] ?? 0) === (int)$uu['id'] ? ' disabled' : '' ?>>
            <?= h($uu['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="fwdNote<?= $id ?>">Dyspozycja</label>
        <textarea name="note" id="fwdNote<?= $id ?>" rows="2" maxlength="500"
                  class="form-control form-control-sm mb-1" style="font-size:.78rem"
                  placeholder="Dyspozycja dla odbiorcy (opcjonalnie)"></textarea>
        <button type="submit" class="btn btn-sm btn-outline-primary w-100" style="font-size:.76rem">
          <i class="bi bi-send me-1" aria-hidden="true"></i>Przekaż
        </button>
      </form>
    </details>
    <?php endif; ?>
  </div>

  <?php if (!empty($mail_att)): ?>
  <div class="px-2 pb-2">
    <div class="text-muted mb-1" style="font-size:.72rem">Załączniki wiadomości (<?= count($mail_att) ?>)</div>
    <?php foreach ($mail_att as $a): ?>
    <div class="d-flex align-items-center gap-2" style="font-size:.78rem">
      <i class="bi bi-paperclip text-muted flex-shrink-0" aria-hidden="true"></i>
      <span class="text-truncate flex-grow-1"><?= h((string)$a['original_name']) ?></span>
      <?php if (!empty($a['size_bytes'])): ?>
      <span class="text-muted flex-shrink-0" style="font-size:.7rem"><?= h($fmt_size((int)$a['size_bytes'])) ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Załączniki -->
<?php if ($zal): ?>
<div class="mb-3">
  <div class="fw-semibold mb-2" style="font-size:.8rem"><i class="bi bi-paperclip me-1 text-primary"></i>Załączniki (<?= count($zal) ?>)</div>
  <?php foreach ($zal as $z): ?>
  <?php
    $zext    = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));
    $is_pdf  = $zext === 'pdf';
    $is_off  = in_array($zext, EZD_OFFICE_ONLINE_EXT, true);
  ?>
  <div class="d-flex align-items-center gap-1 py-1 border-bottom" style="font-size:.78rem">
    <i class="bi <?= ezd_file_icon($z['original_name']) ?> flex-shrink-0 text-muted"></i>
    <div class="flex-grow-1 overflow-hidden">
      <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>" target="_blank"
         class="text-decoration-none fw-semibold text-truncate d-block"><?= h($z['original_name']) ?></a>
      <span class="text-muted" style="font-size:.7rem"><?= ezd_filesize($z['file_size']) ?></span>
    </div>
    <?php if ($is_pdf): ?>
    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ezd-pdf-btn flex-shrink-0"
            data-url="<?= h(APP_URL . '/ezd/serve.php?id=' . $z['id']) ?>"
            data-name="<?= h($z['original_name']) ?>"
            title="Podgląd PDF"><i class="bi bi-eye"></i></button>
    <?php endif; ?>
    <?php if ($is_off): ?>
    <a href="<?= APP_URL ?>/ezd/office_online.php?id=<?= $z['id'] ?>" target="_blank" rel="noopener"
       class="btn btn-sm btn-outline-primary py-0 px-1 flex-shrink-0" title="Word Online"><i class="bi bi-microsoft"></i></a>
    <?php endif; ?>
    <?= ezd_kopia_btn_one('zalacznik', (int)$z['id'], $z['original_name'], 'icon', 'btn-sm py-0 px-1 flex-shrink-0', 'el') ?>
    <?php if ($can_act): ?>
    <button type="button" class="btn btn-sm btn-outline-info py-0 px-1 flex-shrink-0 ezd-panel-from-zal"
            data-zal="<?= (int)$z['id'] ?>" data-name="<?= h($z['original_name']) ?>"
            title="Utwórz pismo z pliku"><i class="bi bi-envelope-plus"></i></button>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Szybkie akcje -->
<?php if ($can_act): ?>
<div class="border rounded p-2 mb-3" style="background:#f8fafc">
  <div class="fw-semibold mb-2" style="font-size:.78rem;color:#64748b"><i class="bi bi-lightning me-1"></i>Szybkie akcje</div>

  <!-- Zmiana statusu -->
  <form class="d-flex gap-2 align-items-center mb-2 ezd-panel-status-form"
        data-pismo-id="<?= $id ?>" data-action-url="<?= h(APP_URL) ?>/ezd/pisma/ajax_action.php">
    <input type="hidden" name="_action" value="set_status">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <select name="status" class="form-select form-select-sm" style="font-size:.78rem">
      <?php foreach ($statuses as $sv => $sl): ?>
      <option value="<?= $sv ?>"<?= $pismo['status'] === $sv ? ' selected' : '' ?>><?= h($sl) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap" style="font-size:.78rem">Ustaw status</button>
  </form>

  <!-- Upload pliku -->
  <form class="d-flex gap-2 align-items-center ezd-panel-upload-form"
        data-pismo-id="<?= $id ?>" data-action-url="<?= h(APP_URL) ?>/ezd/pisma/ajax_action.php">
    <input type="hidden" name="_action" value="upload_file">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <input type="file" name="file" class="form-control form-control-sm" style="font-size:.78rem" required>
    <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap" style="font-size:.78rem"><i class="bi bi-upload me-1"></i>Dodaj</button>
  </form>
</div>
<?php endif; ?>

<!-- Przyciski -->
<div class="d-flex gap-2 flex-wrap">
  <a href="<?= APP_URL ?>/ezd/pisma/view.php?id=<?= $id ?>&from_sprawa=<?= $sprawa_id ?>" class="btn btn-sm btn-outline-primary">
    <i class="bi bi-box-arrow-up-right me-1"></i>Pełny widok
  </a>
  <?= ezd_kopia_btn_one('pismo', $id, $pismo['sygnatura'], 'label', 'btn-sm', 'el') ?>
  <?php if ($can_act): ?>
  <a href="<?= APP_URL ?>/ezd/pisma/edit.php?id=<?= $id ?>&from_sprawa=<?= $sprawa_id ?>" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-pencil me-1"></i>Edytuj
  </a>
  <?php endif; ?>
</div>
