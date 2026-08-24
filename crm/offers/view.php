<?php
/**
 * crm/offers/view.php — Widok szczegółowy oferty.
 *
 * Jeden ekran = pełny kontekst rozmowy z klientem: dokument oferty (z wariantami),
 * kartoteka klienta z historią ofert, oś czasu zdarzeń, potwierdzenie i realizacja.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_offers.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('offers', 'read');
crm_offers_migrate();

$id    = (int)($_GET['id'] ?? 0);
$offer = $id ? crm_offer_full($id) : null;
if (!$offer) { flash_set('danger', 'Oferta nie istnieje.'); header('Location: index.php'); exit; }

$PAGE_TITLE = 'Oferta ' . $offer['offer_number'];
$can_write  = crm_offer_can_write();
$can_del    = crm_offer_can_delete();
$scfg       = CRM_OFFER_STATUSES[$offer['status']] ?? CRM_OFFER_STATUSES['szkic'];
$editable   = in_array($offer['status'], ['szkic', 'do_zatwierdzenia', 'wyslana'], true);

$needs_conf = crm_offer_requires_confirmation($offer);
$conf       = crm_offer_confirmation($id);
$warning    = crm_offer_warning($offer);
$events     = crm_offer_events($id, 60);
$history    = crm_offer_history((int)$offer['contact_id'], 12);
$summary    = crm_offer_contact_summary((int)$offer['contact_id']);
$comms      = [];
try {
    $comms = crm_all(
        "SELECT channel, direction, subject, sent_at, status FROM crm_communications
         WHERE contact_id=? ORDER BY sent_at DESC LIMIT 5", [(int)$offer['contact_id']]
    );
} catch (\Throwable $e) {}

$disc_pending = (float)$offer['discount_pct'] > 0 && empty($offer['discount_approved_at']);
$sel_variant  = crm_offer_selected_variant($offer);

include dirname(__DIR__) . '/includes/header_crm.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,400;0,700;1,400&family=Montserrat:wght@600;700;800&display=swap');
<?= crm_offer_document_css() ?>
.ov-card { background:#fff;border:1px solid #E5E7EB;border-radius:10px;margin-bottom:1rem }
.ov-card > .hd { padding:.55rem .9rem;border-bottom:1px solid #F3F4F6;display:flex;align-items:center;gap:.5rem;
  font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6B7280 }
.ov-card > .bd { padding:.85rem }
.ov-kv { display:flex;justify-content:space-between;gap:.75rem;padding:.28rem 0;font-size:.82rem;border-bottom:1px solid #F8F9FB }
.ov-kv:last-child { border-bottom:none }
.ov-kv dt { color:#6B7280;font-weight:500;margin:0 }
.ov-kv dd { margin:0;text-align:right;font-weight:600;color:#111827;max-width:62% ;word-break:break-word }
.ov-tl { position:relative;padding-left:1.1rem }
.ov-tl-i { position:relative;padding:.35rem 0 .35rem .55rem;border-left:2px solid #E5E7EB;font-size:.8rem }
.ov-tl-i::before { content:'';position:absolute;left:-5px;top:.65rem;width:8px;height:8px;border-radius:50%;background:#9CA3AF }
.ov-tl-i.acc::before { background:#2E844A } .ov-tl-i.warn::before { background:#EA580C } .ov-tl-i.rej::before { background:#DC2626 }
.ov-warn { border:2px dashed #EA580C;background:#FFF7ED;border-radius:10px;padding:.85rem 1rem;margin-bottom:1rem }
.ov-hist { display:flex;align-items:center;gap:.5rem;padding:.35rem 0;border-bottom:1px solid #F6F7F9;font-size:.8rem;text-decoration:none;color:#111827 }
.ov-hist:last-child { border-bottom:none }
.ov-hist:hover { background:#F9FAFB }
.of-doc { font-size:.86rem }
</style>

<nav aria-label="breadcrumb" class="mb-2" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="index.php">Oferty</a></li>
    <li class="breadcrumb-item active"><?= h($offer['offer_number']) ?></li>
  </ol>
</nav>

<!-- Nagłówek rekordu -->
<div class="crm-page-header">
  <div>
    <div class="crm-page-title" style="gap:.6rem">
      <i class="bi bi-file-earmark-ruled-fill" style="color:#0176D3"></i>
      <span style="font-family:monospace;font-size:1.05rem;color:#1D4ED8"><?= h($offer['offer_number']) ?></span>
      <?= crm_offer_status_pill((string)$offer['status']) ?>
      <?php if ((int)$offer['revision'] > 1): ?>
      <span class="badge bg-secondary">wersja <?= (int)$offer['revision'] ?></span>
      <?php endif; ?>
    </div>
    <div class="crm-page-subtitle">
      <?= h($offer['title']) ?> ·
      <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$offer['contact_id'] ?>"><?= h($offer['contact_name']) ?></a>
      · <strong><?= h(crm_offer_money((float)$offer['total_gross'], (string)$offer['currency'])) ?></strong> brutto
    </div>
  </div>
  <div class="crm-page-actions">
    <a href="print.php?id=<?= $id ?>" target="_blank" class="btn btn-crm-ghost btn-sm" title="Wydruk">
      <i class="bi bi-printer"></i>
    </a>
    <a href="print.php?id=<?= $id ?>&pdf=1" class="btn btn-crm-ghost btn-sm" title="Pobierz PDF">
      <i class="bi bi-file-earmark-pdf"></i>
    </a>
    <?php if ($can_write && $editable): ?>
    <a href="form.php?id=<?= $id ?>" class="btn btn-crm-outline btn-sm"><i class="bi bi-pencil me-1"></i>Edytuj</a>
    <?php endif; ?>
    <?php if ($can_write && in_array($offer['status'], ['szkic', 'wyslana', 'do_zatwierdzenia'], true)): ?>
    <button type="button" class="btn btn-crm-primary btn-sm" data-bs-toggle="modal" data-bs-target="#sendModal">
      <i class="bi bi-send me-1"></i><?= $offer['status'] === 'wyslana' ? 'Wyślij ponownie' : 'Wyślij do klienta' ?>
    </button>
    <?php endif; ?>
    <?php if ($can_write): ?>
    <div class="dropdown">
      <button class="btn btn-crm-ghost btn-sm" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Więcej akcji">
        <i class="bi bi-three-dots-vertical"></i>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size:.85rem">
        <?php if (module_enabled('invoices_enabled')): ?>
        <li><form method="post" action="action.php" class="d-inline w-100">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="a" value="invoice">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button type="submit" class="dropdown-item"><i class="bi bi-receipt me-2"></i>Wystaw fakturę</button>
        </form></li>
        <?php if (is_admin()): ?>
        <li><form method="post" action="action.php" class="d-inline w-100">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="a" value="invoice_demo">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button type="submit" class="dropdown-item text-danger"
                  title="Gotowy PDF z numerem TEST/… — nie idzie do KSeF ani do klienta">
            <i class="bi bi-file-earmark-pdf me-2"></i>FVAT demo (admin)</button>
        </form></li>
        <?php endif; ?>
        <li><hr class="dropdown-divider"></li>
        <?php endif; ?>
        <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#revisionModal">
          <i class="bi bi-files me-2"></i>Nowa wersja oferty</button></li>
        <li><form method="post" action="action.php" class="d-inline w-100">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="a" value="duplicate">
          <button class="dropdown-item" type="submit"><i class="bi bi-copy me-2"></i>Duplikuj</button></form></li>
        <li><form method="post" action="action.php" class="d-inline w-100"
                  onsubmit="return confirm('Nowy link unieważni poprzedni — klient utraci dostęp do starego adresu. Kontynuować?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="a" value="token_reset">
          <button class="dropdown-item" type="submit"><i class="bi bi-link-45deg me-2"></i>Nowy link publiczny</button></form></li>
        <?php if ($offer['status'] !== 'anulowana' && in_array($offer['status'], ['szkic','do_zatwierdzenia','wyslana'], true)): ?>
        <li><hr class="dropdown-divider"></li>
        <li><form method="post" action="action.php" class="d-inline w-100" onsubmit="return confirm('Anulować ofertę?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="a" value="status">
          <input type="hidden" name="status" value="anulowana">
          <button class="dropdown-item" type="submit"><i class="bi bi-slash-circle me-2"></i>Anuluj ofertę</button></form></li>
        <?php endif; ?>
        <?php if ($can_del): ?>
        <li><form method="post" action="action.php" class="d-inline w-100"
                  onsubmit="return confirm('Usunąć ofertę <?= h($offer['offer_number']) ?>?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="a" value="delete">
          <button class="dropdown-item text-danger" type="submit"><i class="bi bi-trash me-2"></i>Usuń</button></form></li>
        <?php endif; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php /* ══ OSTRZEŻENIE: osoba fizyczna wymaga potwierdzenia ══ */ ?>
<?php if ($warning): ?>
<div class="ov-warn" role="alert">
  <div class="d-flex gap-2 align-items-start">
    <i class="bi bi-exclamation-triangle-fill" style="color:#EA580C;font-size:1.2rem" aria-hidden="true"></i>
    <div class="flex-grow-1">
      <div class="fw-bold" style="color:#9A3412">Oferta dla osoby fizycznej — wymagane potwierdzenie klienta</div>
      <div style="font-size:.85rem"><?= h($warning) ?></div>
      <div class="mt-2 d-flex gap-2 flex-wrap">
        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#confirmModal">
          <i class="bi bi-clipboard-check me-1"></i>Zarejestruj potwierdzenie
        </button>
        <?php if (!empty($offer['access_token'])): ?>
        <button class="btn btn-sm btn-outline-dark" onclick="ovCopyLink()">
          <i class="bi bi-link-45deg me-1"></i>Kopiuj link do potwierdzenia
        </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($disc_pending && (float)$offer['discount_pct'] > 0): ?>
<div class="alert alert-<?= $offer['status'] === 'do_zatwierdzenia' ? 'warning' : 'secondary' ?> d-flex align-items-center gap-2 py-2">
  <i class="bi bi-percent" aria-hidden="true"></i>
  <div class="flex-grow-1" style="font-size:.85rem">
    Rabat <strong><?= h(rtrim(rtrim(number_format((float)$offer['discount_pct'], 2, ',', ' '), '0'), ',')) ?>%</strong>
    <?= $offer['discount_reason'] ? '— ' . h($offer['discount_reason']) : '' ?>
    <?= $offer['status'] === 'do_zatwierdzenia' ? ' — oferta oczekuje na zatwierdzenie.' : ' — niezatwierdzony.' ?>
  </div>
  <?php if (crm_offer_can_approve_discount()): ?>
  <form method="post" action="action.php">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="a" value="approve_discount">
    <button class="btn btn-sm btn-success"><i class="bi bi-check2 me-1"></i>Zatwierdź rabat</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3">

<!-- ══ LEWA: kontekst klienta ══════════════════════════════════════════════ -->
<div class="col-lg-4">

  <div class="ov-card">
    <div class="hd"><i class="bi bi-person-vcard" style="color:#0176D3"></i> Klient
      <a class="ms-auto" href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$offer['contact_id'] ?>"
         style="text-transform:none;letter-spacing:0;font-weight:500">kartoteka</a></div>
    <div class="bd">
      <div class="fw-bold" style="font-size:.95rem"><?= h($offer['contact_name']) ?></div>
      <div class="small text-muted mb-2">
        <i class="bi <?= h(CRM_CONTACT_TYPES[$offer['contact_type']]['icon'] ?? 'bi-person') ?>"></i>
        <?= h(CRM_CONTACT_TYPES[$offer['contact_type']]['label'] ?? $offer['contact_type']) ?>
        <?php if ($needs_conf): ?>
        <span class="badge bg-warning text-dark ms-1">wymaga potwierdzenia</span>
        <?php endif; ?>
      </div>
      <dl class="mb-0">
        <?php if ($offer['contact_nip']): ?><div class="ov-kv"><dt>NIP</dt><dd><?= h($offer['contact_nip']) ?></dd></div><?php endif; ?>
        <?php if ($offer['contact_email']): ?><div class="ov-kv"><dt>E-mail</dt><dd><a href="mailto:<?= h($offer['contact_email']) ?>"><?= h($offer['contact_email']) ?></a></dd></div><?php endif; ?>
        <?php if ($offer['contact_phone']): ?><div class="ov-kv"><dt>Telefon</dt><dd><?= h($offer['contact_phone']) ?></dd></div><?php endif; ?>
        <?php if ($offer['contact_address']): ?><div class="ov-kv"><dt>Adres</dt><dd><?= h($offer['contact_address']) ?></dd></div><?php endif; ?>
        <?php if ($offer['case_title']): ?>
        <div class="ov-kv"><dt>Sprawa</dt><dd><a href="<?= APP_URL ?>/crm/cases/view.php?id=<?= (int)$offer['case_id'] ?>"><?= h($offer['case_title']) ?></a></dd></div>
        <?php endif; ?>
      </dl>
      <div class="d-flex gap-2 mt-2">
        <button class="btn btn-crm-outline btn-sm flex-fill" onclick="openCommModal(<?= (int)$offer['contact_id'] ?>,'email')">
          <i class="bi bi-envelope me-1"></i>Napisz
        </button>
        <a class="btn btn-crm-ghost btn-sm" href="<?= APP_URL ?>/crm/cases/add.php?contact_id=<?= (int)$offer['contact_id'] ?>" title="Nowa sprawa">
          <i class="bi bi-briefcase"></i>
        </a>
      </div>
    </div>
  </div>

  <div class="ov-card">
    <div class="hd"><i class="bi bi-clock-history"></i> Historia ofert klienta</div>
    <div class="bd">
      <div class="row g-1 mb-2 text-center">
        <div class="col-3"><div class="fw-bold"><?= (int)$summary['cnt'] ?></div><div class="text-muted" style="font-size:.68rem">ofert</div></div>
        <div class="col-3"><div class="fw-bold text-success"><?= (int)$summary['won'] ?></div><div class="text-muted" style="font-size:.68rem">wygrane</div></div>
        <div class="col-3"><div class="fw-bold text-danger"><?= (int)$summary['lost'] ?></div><div class="text-muted" style="font-size:.68rem">odrzucone</div></div>
        <div class="col-3"><div class="fw-bold"><?= (int)$summary['open'] ?></div><div class="text-muted" style="font-size:.68rem">w toku</div></div>
      </div>
      <div class="small text-muted mb-2">Wartość wygranych: <strong><?= h(crm_offer_money((float)$summary['won_value'], (string)$offer['currency'])) ?></strong></div>
      <?php foreach ($history as $ho): if ((int)$ho['id'] === $id) continue; ?>
      <a class="ov-hist" href="view.php?id=<?= (int)$ho['id'] ?>">
        <span style="font-family:monospace;font-size:.72rem;color:#1D4ED8"><?= h($ho['offer_number']) ?></span>
        <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($ho['title']) ?></span>
        <span class="text-muted" style="font-size:.72rem;white-space:nowrap"><?= h(number_format((float)$ho['total_gross'], 0, ',', ' ')) ?></span>
        <?= crm_offer_status_pill((string)$ho['status'], true) ?>
      </a>
      <?php endforeach; ?>
      <?php if (count($history) <= 1): ?>
      <div class="text-muted small">To pierwsza oferta dla tego klienta.</div>
      <?php endif; ?>
      <a href="index.php?contact_id=<?= (int)$offer['contact_id'] ?>" class="btn btn-crm-outline btn-sm w-100 mt-2">Wszystkie oferty klienta</a>
    </div>
  </div>

  <div class="ov-card">
    <div class="hd"><i class="bi bi-bank"></i> Kwalifikacja</div>
    <div class="bd"><dl class="mb-0">
      <div class="ov-kv"><dt>Finansowanie</dt><dd><?= h(CRM_OFFER_FUNDING[$offer['funding_source']] ?? $offer['funding_source']) ?></dd></div>
      <div class="ov-kv"><dt>Cel statutowy</dt><dd><?= h(crm_offer_objective_label($offer['objective_id'] ? (int)$offer['objective_id'] : null) ?: '—') ?></dd></div>
      <div class="ov-kv"><dt>Opiekun</dt><dd><?= h($offer['owner_name'] ?: '—') ?></dd></div>
      <div class="ov-kv"><dt>Utworzył</dt><dd><?= h($offer['author_name'] ?: '—') ?> · <?= h(date_pl($offer['created_at'])) ?></dd></div>
      <div class="ov-kv"><dt>Ważna do</dt><dd><?= h(date_pl($offer['valid_until'])) ?></dd></div>
      <div class="ov-kv"><dt>Termin płatności</dt><dd><?= (int)$offer['payment_terms_days'] ?> dni</dd></div>
      <?php if ($offer['sent_at']): ?>
      <div class="ov-kv"><dt>Wysłana</dt><dd><?= h(date('d.m.Y H:i', strtotime((string)$offer['sent_at']))) ?></dd></div>
      <?php endif; ?>
      <?php if ((int)$offer['public_views'] > 0): ?>
      <div class="ov-kv"><dt>Odsłony klienta</dt><dd><?= (int)$offer['public_views'] ?> · <?= h(date_pl($offer['last_viewed_at'])) ?></dd></div>
      <?php endif; ?>
      <?php if ($offer['followup_at']): ?>
      <div class="ov-kv"><dt>Follow-up</dt><dd><?= h(date_pl($offer['followup_at'])) ?></dd></div>
      <?php endif; ?>
    </dl></div>
  </div>

  <?php if (!empty($offer['access_token'])): ?>
  <div class="ov-card">
    <div class="hd"><i class="bi bi-link-45deg"></i> Link dla klienta</div>
    <div class="bd">
      <div class="input-group input-group-sm">
        <input class="form-control" id="pubLink" readonly value="<?= h(crm_offer_public_url($offer)) ?>">
        <button class="btn btn-outline-secondary" type="button" onclick="ovCopyLink()"><i class="bi bi-clipboard"></i></button>
        <a class="btn btn-outline-secondary" href="<?= h(crm_offer_public_url($offer)) ?>" target="_blank"><i class="bi bi-box-arrow-up-right"></i></a>
      </div>
      <div class="form-text" style="font-size:.72rem">Strona bez logowania — klient widzi ofertę, wybiera wariant i akceptuje<?= $needs_conf ? ' oraz potwierdza warunki' : '' ?>.</div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($comms): ?>
  <div class="ov-card">
    <div class="hd"><i class="bi bi-chat-left-text"></i> Ostatnia komunikacja</div>
    <div class="bd">
      <?php foreach ($comms as $cm): ?>
      <div class="d-flex gap-2 align-items-start" style="font-size:.8rem;padding:.25rem 0;border-bottom:1px solid #F6F7F9">
        <i class="bi bi-<?= $cm['direction'] === 'out' ? 'arrow-up-right text-primary' : 'arrow-down-left text-success' ?>"></i>
        <div style="flex:1;min-width:0">
          <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($cm['subject'] ?: '(bez tematu)') ?></div>
          <div class="text-muted" style="font-size:.7rem"><?= h(date_pl($cm['sent_at'])) ?> · <?= h($cm['status']) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<!-- ══ PRAWA: dokument + decyzje ═══════════════════════════════════════════ -->
<div class="col-lg-8">

  <div class="ov-card">
    <div class="hd"><i class="bi bi-file-text" style="color:#0176D3"></i> Dokument oferty
      <span class="ms-auto" style="text-transform:none;letter-spacing:0;font-weight:400;color:#9CA3AF">
        widok wewnętrzny (z notatkami)
      </span>
    </div>
    <div class="bd"><div class="of-doc"><?= crm_offer_document_html($offer, ['internal' => true]) ?></div></div>
  </div>

  <?php if ($can_write): ?>
  <!-- Decyzja klienta -->
  <div class="ov-card">
    <div class="hd"><i class="bi bi-clipboard-check" style="color:#2E844A"></i> Decyzja klienta</div>
    <div class="bd">
      <?php if (in_array($offer['status'], ['zaakceptowana', 'zrealizowana'], true)): ?>
        <div class="alert alert-success py-2 mb-2" style="font-size:.85rem">
          <i class="bi bi-check-circle-fill me-1"></i>Oferta zaakceptowana
          <?= $offer['decided_at'] ? ' — ' . h(date('d.m.Y H:i', strtotime((string)$offer['decided_at']))) : '' ?>.
          <?php if ($sel_variant): ?> Wariant: <strong><?= h($sel_variant['code'] . ' — ' . $sel_variant['name']) ?></strong>.<?php endif; ?>
        </div>
      <?php elseif ($offer['status'] === 'odrzucona'): ?>
        <div class="alert alert-danger py-2 mb-2" style="font-size:.85rem">
          <i class="bi bi-x-circle-fill me-1"></i>Oferta odrzucona<?= $offer['reject_reason'] ? ': ' . h($offer['reject_reason']) : '' ?>.
        </div>
      <?php else: ?>
      <div class="row g-2">
        <div class="col-md-7">
          <form method="post" action="action.php" class="d-flex gap-2 align-items-end"
                onsubmit="return ovAcceptGuard()">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="a" value="status">
            <input type="hidden" name="status" value="zaakceptowana">
            <div class="flex-grow-1">
              <label class="form-label small fw-semibold mb-1">Wariant wybrany przez klienta</label>
              <select name="variant_id" class="form-select form-select-sm">
                <?php foreach ($offer['variants'] as $v): ?>
                <option value="<?= (int)$v['id'] ?>" <?= (int)$offer['selected_variant_id'] === (int)$v['id'] ? 'selected' : '' ?>>
                  <?= h($v['code'] . ' — ' . $v['name']) ?> (<?= h(crm_offer_money((float)$v['total_gross'], (string)$offer['currency'])) ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn-success btn-sm" <?= ($needs_conf && !$conf) ? 'disabled title="Wymagane potwierdzenie klienta"' : '' ?>>
              <i class="bi bi-check2-circle me-1"></i>Zaakceptowana
            </button>
          </form>
          <?php if ($needs_conf && !$conf): ?>
          <div class="form-text text-danger" style="font-size:.75rem">
            Zablokowane: oferta dla osoby fizycznej wymaga zarejestrowanego potwierdzenia.
          </div>
          <?php endif; ?>
        </div>
        <div class="col-md-5">
          <form method="post" action="action.php">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="a" value="status">
            <input type="hidden" name="status" value="odrzucona">
            <label class="form-label small fw-semibold mb-1">Odrzucenie — powód</label>
            <div class="input-group input-group-sm">
              <input name="reason" class="form-control" placeholder="np. cena, brak budżetu, wybrano konkurencję">
              <button class="btn btn-outline-danger"><i class="bi bi-x-lg"></i></button>
            </div>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Potwierdzenie -->
  <div class="ov-card">
    <div class="hd"><i class="bi bi-patch-check" style="color:<?= $conf ? '#2E844A' : ($needs_conf ? '#EA580C' : '#9CA3AF') ?>"></i>
      Potwierdzenie oferty</div>
    <div class="bd">
      <?php if ($conf): ?>
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div style="font-size:.85rem">
            <div class="fw-semibold text-success"><i class="bi bi-check-circle-fill me-1"></i>Potwierdzone</div>
            <div><?= h(CRM_OFFER_CONFIRM_METHODS[$conf['method']]['label'] ?? $conf['method']) ?>
              · <?= h($conf['confirmed_name'] ?: '—') ?>
              · <?= h(date('d.m.Y H:i', strtotime((string)$conf['confirmed_at']))) ?></div>
            <?php if ($conf['confirmed_email']): ?><div class="text-muted"><?= h($conf['confirmed_email']) ?></div><?php endif; ?>
            <?php if ($conf['note']): ?><div class="text-muted"><?= nl2br(h($conf['note'])) ?></div><?php endif; ?>
            <?php if ($conf['ip']): ?><div class="text-muted" style="font-size:.72rem">IP: <?= h($conf['ip']) ?></div><?php endif; ?>
            <?php if ($conf['file_path']): ?>
            <div class="mt-1"><a href="<?= h(upload_link($conf['file_path'])) ?>" target="_blank"><i class="bi bi-paperclip"></i> załączony dokument</a></div>
            <?php endif; ?>
            <?php $st = $conf['statements'] ? json_decode($conf['statements'], true) : null; if (is_array($st) && $st): ?>
            <ul class="mt-1 mb-0 ps-3 text-muted" style="font-size:.75rem">
              <?php foreach ($st as $sk => $sv): if (!$sv) continue; ?>
              <li><?= h(is_string($sv) ? $sv : (string)$sk) ?></li>
              <?php endforeach; ?>
            </ul>
            <?php endif; ?>
          </div>
          <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#revokeModal">
            <i class="bi bi-x-circle me-1"></i>Wycofaj
          </button>
        </div>
      <?php elseif ($needs_conf): ?>
        <div class="d-flex justify-content-between align-items-center gap-2">
          <div style="font-size:.85rem">
            <span class="fw-semibold" style="color:#B45309"><i class="bi bi-exclamation-triangle-fill me-1"></i>Brak potwierdzenia.</span>
            Klient (osoba fizyczna) potwierdza ofertę przez link, albo pracownik rejestruje potwierdzenie z maila / skanu / protokołu.
          </div>
          <button class="btn btn-warning btn-sm flex-shrink-0" data-bs-toggle="modal" data-bs-target="#confirmModal">
            <i class="bi bi-clipboard-check me-1"></i>Zarejestruj
          </button>
        </div>
      <?php else: ?>
        <div class="text-muted" style="font-size:.85rem">
          Klient instytucjonalny — potwierdzenie nie jest wymagane. Akceptację rejestruje opiekun oferty.
          <button class="btn btn-crm-ghost btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#confirmModal">Zarejestruj mimo to</button>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Realizacja / konwersja -->
  <div class="ov-card">
    <div class="hd"><i class="bi bi-rocket-takeoff" style="color:#0F766E"></i> Uruchomienie realizacji</div>
    <div class="bd">
      <?php if ($offer['converted_at']): ?>
        <div class="alert alert-success py-2 mb-0" style="font-size:.85rem">
          <i class="bi bi-check-circle-fill me-1"></i>
          Zrealizowano jako <strong><?= h(CRM_OFFER_CONVERT_TARGETS[$offer['converted_type']]['label'] ?? $offer['converted_type']) ?></strong>
          <?= $offer['converted_id'] ? ' #' . (int)$offer['converted_id'] : '' ?>
          · <?= h(date('d.m.Y H:i', strtotime((string)$offer['converted_at']))) ?>
          <?php if ($offer['converted_type'] === 'sprawa' && $offer['converted_id']): ?>
          — <a href="<?= APP_URL ?>/crm/cases/view.php?id=<?= (int)$offer['converted_id'] ?>">otwórz sprawę</a>
          <?php elseif ($offer['converted_type'] === 'umowa' && $offer['converted_id']): ?>
          — <a href="<?= APP_URL ?>/contracts/uslugi/view.php?id=<?= (int)$offer['converted_id'] ?>">otwórz umowę</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <p class="text-muted mb-2" style="font-size:.84rem">
          Przekształć ofertę bez przepisywania danych — pozycje, kwoty i warunki przechodzą do celu konwersji.
        </p>
        <form method="post" action="action.php" onsubmit="return ovConvertGuard()">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="a" value="convert">
          <input type="hidden" name="ack" id="convAck" value="">
          <div class="d-flex flex-wrap gap-2 align-items-end">
            <div>
              <label class="form-label small fw-semibold mb-1">Cel</label>
              <select name="target" class="form-select form-select-sm" style="min-width:250px">
                <?php foreach (CRM_OFFER_CONVERT_TARGETS as $tk => $tv): ?>
                <option value="<?= h($tk) ?>"><?= h($tv['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php if (!in_array($offer['status'], ['zaakceptowana'], true)): ?>
            <div class="form-check me-2">
              <input class="form-check-input" type="checkbox" name="force" value="1" id="forceConv">
              <label class="form-check-label small" for="forceConv">Uruchom mimo braku akceptacji</label>
            </div>
            <?php endif; ?>
            <button class="btn btn-sm" style="background:#0F766E;color:#fff"
              <?= ($needs_conf && !$conf) ? 'disabled title="Wymagane potwierdzenie klienta"' : '' ?>>
              <i class="bi bi-play-fill me-1"></i>Uruchom realizację
            </button>
          </div>
          <?php if ($needs_conf && !$conf): ?>
          <div class="form-text text-danger" style="font-size:.75rem">
            Zablokowane do czasu zarejestrowania potwierdzenia klienta (osoba fizyczna).
          </div>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Oś czasu -->
  <div class="ov-card">
    <div class="hd"><i class="bi bi-activity"></i> Historia oferty</div>
    <div class="bd">
      <div class="ov-tl">
        <?php foreach ($events as $ev):
          $cls = in_array($ev['event'], ['accepted', 'confirmed', 'converted'], true) ? 'acc'
               : (in_array($ev['event'], ['rejected', 'expired', 'confirmation_revoked'], true) ? 'rej'
               : (in_array($ev['event'], ['discount_requested', 'sent'], true) ? 'warn' : '')); ?>
        <div class="ov-tl-i <?= $cls ?>">
          <strong><?= h(crm_offer_event_label((string)$ev['event'])) ?></strong>
          <?php if ($ev['from_status'] || $ev['to_status']): ?>
          <span class="text-muted">
            <?= h(CRM_OFFER_STATUSES[$ev['from_status']]['label'] ?? $ev['from_status'] ?? '') ?>
            → <?= h(CRM_OFFER_STATUSES[$ev['to_status']]['label'] ?? $ev['to_status'] ?? '') ?>
          </span>
          <?php endif; ?>
          <div class="text-muted" style="font-size:.75rem">
            <?= h(date('d.m.Y H:i', strtotime((string)$ev['created_at']))) ?>
            <?= $ev['actor_name'] ? ' · ' . h($ev['actor_name']) : '' ?>
          </div>
          <?php if ($ev['detail']): ?><div style="font-size:.78rem"><?= h($ev['detail']) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$events): ?><div class="text-muted small">Brak zdarzeń.</div><?php endif; ?>
      </div>
    </div>
  </div>

</div>
</div>

<?php if ($can_write): ?>
<!-- ══ MODAL: wysyłka ══════════════════════════════════════════════════════ -->
<div class="modal fade" id="sendModal" tabindex="-1" aria-labelledby="sendModalL" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" action="action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="a" value="send">
      <div class="modal-header py-2">
        <h5 class="modal-title" id="sendModalL" style="font-size:1rem"><i class="bi bi-send me-2"></i>Wyślij ofertę do klienta</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <?php if ($warning): ?>
        <div class="ov-warn mb-3">
          <div class="fw-bold" style="color:#9A3412"><i class="bi bi-exclamation-triangle-fill me-1"></i>Uwaga — osoba fizyczna</div>
          <div style="font-size:.85rem"><?= h($warning) ?></div>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="ack" value="1" id="ackSend" required>
            <label class="form-check-label" for="ackSend" style="font-size:.85rem">
              Przyjmuję do wiadomości: <strong>bez potwierdzenia klienta nie wolno uruchomić realizacji</strong>.
            </label>
          </div>
        </div>
        <?php endif; ?>
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1">Adres e-mail</label>
          <input name="to" type="email" class="form-control form-control-sm" required
                 value="<?= h($offer['contact_email']) ?>">
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1">Temat</label>
          <input name="subject" class="form-control form-control-sm"
                 value="<?= h('Oferta ' . $offer['offer_number'] . ' — ' . $offer['title']) ?>">
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1">Wiadomość (opcjonalnie)</label>
          <textarea name="message" class="form-control form-control-sm" rows="4"
                    placeholder="Kilka zdań wprowadzenia — treść oferty klient zobaczy pod linkiem."></textarea>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="attach_pdf" value="1" id="attPdf" checked>
          <label class="form-check-label small" for="attPdf">Dołącz ofertę w PDF</label>
        </div>
        <div class="alert alert-light border mt-2 mb-0" style="font-size:.8rem">
          Po wysłaniu system utworzy zadanie <strong>follow-up</strong> za <?= crm_offer_followup_days() ?> dni
          dla opiekuna oferty (<?= h($offer['owner_name'] ?: '—') ?>).
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button class="btn btn-crm-primary btn-sm"><i class="bi bi-send me-1"></i>Wyślij</button>
      </div>
    </form>
  </div></div>
</div>

<!-- ══ MODAL: rejestracja potwierdzenia ═══════════════════════════════════ -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confModalL" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" action="action.php" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="a" value="confirm">
      <div class="modal-header py-2">
        <h5 class="modal-title" id="confModalL" style="font-size:1rem"><i class="bi bi-clipboard-check me-2"></i>Zarejestruj potwierdzenie klienta</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-light border" style="font-size:.8rem">
          Rejestrujesz potwierdzenie złożone poza systemem. Potwierdzenie jest dowodem, że klient
          zaakceptował warunki — opisz dokładnie jego podstawę.
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1">Sposób potwierdzenia</label>
          <select name="method" class="form-select form-select-sm">
            <?php foreach (CRM_OFFER_CONFIRM_METHODS as $mk => $mv): if ($mk === 'online') continue; ?>
            <option value="<?= h($mk) ?>"><?= h($mv['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1">Wariant potwierdzony</label>
          <select name="variant_id" class="form-select form-select-sm">
            <option value="">— nie wskazano —</option>
            <?php foreach ($offer['variants'] as $v): ?>
            <option value="<?= (int)$v['id'] ?>"><?= h($v['code'] . ' — ' . $v['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label small fw-semibold mb-1">Osoba potwierdzająca <span class="text-danger">*</span></label>
            <input name="confirmed_name" class="form-control form-control-sm" required value="<?= h($offer['contact_name']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold mb-1">E-mail / kontakt</label>
            <input name="confirmed_email" class="form-control form-control-sm" value="<?= h($offer['contact_email']) ?>">
          </div>
        </div>
        <div class="mt-2">
          <label class="form-label small fw-semibold mb-1">Podstawa potwierdzenia <span class="text-danger">*</span></label>
          <textarea name="note" class="form-control form-control-sm" rows="3" required
                    placeholder="np. E-mail klienta z 12.03.2026 godz. 10:14 „akceptuję wariant B" / protokół nr 4/2026"></textarea>
        </div>
        <div class="mt-2">
          <label class="form-label small fw-semibold mb-1">Załącznik (skan, wydruk maila)</label>
          <input type="file" name="conf_file" class="form-control form-control-sm">
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button class="btn btn-warning btn-sm"><i class="bi bi-check2 me-1"></i>Zarejestruj potwierdzenie</button>
      </div>
    </form>
  </div></div>
</div>

<!-- ══ MODAL: wycofanie potwierdzenia ═════════════════════════════════════ -->
<div class="modal fade" id="revokeModal" tabindex="-1" aria-labelledby="revModalL" aria-hidden="true">
  <div class="modal-dialog modal-sm"><div class="modal-content">
    <form method="post" action="action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="a" value="revoke_confirm">
      <div class="modal-header py-2"><h5 class="modal-title" id="revModalL" style="font-size:1rem">Wycofaj potwierdzenie</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
      <div class="modal-body">
        <label class="form-label small fw-semibold mb-1">Powód <span class="text-danger">*</span></label>
        <textarea name="reason" class="form-control form-control-sm" rows="3" required
                  placeholder="np. omyłkowa rejestracja, klient wycofał zgodę"></textarea>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button class="btn btn-danger btn-sm">Wycofaj</button>
      </div>
    </form>
  </div></div>
</div>

<!-- ══ MODAL: nowa wersja ═════════════════════════════════════════════════ -->
<div class="modal fade" id="revisionModal" tabindex="-1" aria-labelledby="revsModalL" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" action="action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="a" value="revision">
      <div class="modal-header py-2"><h5 class="modal-title" id="revsModalL" style="font-size:1rem">Nowa wersja oferty</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
      <div class="modal-body" style="font-size:.86rem">
        Powstanie nowa oferta (wersja <?= (int)$offer['revision'] + 1 ?>) z tymi samymi pozycjami i nowym numerem.
        Bieżąca oferta <?= h($offer['offer_number']) ?> zostaje bez zmian — dokument, na który klient już odpowiedział,
        nie może być podmieniany.
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button class="btn btn-primary btn-sm"><i class="bi bi-files me-1"></i>Utwórz nową wersję</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<script>
function ovCopyLink() {
    var el = document.getElementById('pubLink');
    var v = el ? el.value : <?= json_encode(crm_offer_public_url($offer)) ?>;
    if (navigator.clipboard) { navigator.clipboard.writeText(v); }
    else if (el) { el.select(); document.execCommand('copy'); }
    alert('Link skopiowany:\n' + v);
}
var OV_NEEDS_ACK = <?= $warning ? 'true' : 'false' ?>;
function ovAcceptGuard() {
    if (!OV_NEEDS_ACK) return true;
    alert('Nie można zaakceptować oferty dla osoby fizycznej bez zarejestrowanego potwierdzenia klienta.');
    return false;
}
function ovConvertGuard() {
    if (!OV_NEEDS_ACK) return true;
    alert('UWAGA: oferta dla osoby fizycznej nie ma potwierdzenia klienta.\n\n'
        + 'Zanim uruchomisz realizację, zarejestruj potwierdzenie (link online, e-mail, skan lub protokół).');
    return false;
}
</script>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
