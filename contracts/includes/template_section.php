<?php
/**
 * contracts/includes/template_section.php
 * Sekcja „Wzory dokumentów" do wstawienia w zakładce docs każdego widoku umowy.
 *
 * Wymagane zmienne z rodzica:
 *   $TYPE        — string: 'wolontariat' | 'zlecenie' | 'dzielo' | 'praca' | 'uslugi' | 'inne'
 *   $id          — int: ID umowy
 *   $row         — array: wiersz z tabeli umowy (potrzebny email, pesel)
 */

require_once dirname(dirname(__DIR__)) . '/includes/contract_template_engine.php';
cte_migrate();

$_tpl_sect_list = cte_list($TYPE);
$_tpl_email     = $row['email'] ?? '';
$_tpl_pesel     = preg_replace('/\D/', '', $row['pesel'] ?? '');
$_tpl_can_email = $_tpl_email && strlen($_tpl_pesel) >= 5;
?>

<div class="cv-section">
  <div class="cv-section-head">
    <div class="cv-section-icon" style="background:#F0F4FF;color:#4F46E5">
      <i class="bi bi-file-earmark-text"></i>
    </div>
    <span class="cv-section-title">Wzory dokumentów</span>
    <div class="cv-section-action">
      <?php if (is_admin()): ?>
      <a href="<?= APP_URL ?>/admin/template_editor.php?new=1&type=<?= $TYPE ?>"
         class="btn btn-sm btn-outline-primary py-0 px-2"
         title="Utwórz nowy wzór dla tego typu umowy">
        <i class="bi bi-plus-lg"></i>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$_tpl_sect_list): ?>
  <div class="text-muted small d-flex align-items-center gap-2 py-1">
    <i class="bi bi-info-circle"></i>
    Brak wzorów dla tego typu umowy.
    <?php if (is_admin()): ?>
    <a href="<?= APP_URL ?>/admin/template_editor.php?new=1&type=<?= $TYPE ?>">
      Utwórz wzór →
    </a>
    <?php endif; ?>
  </div>

  <?php else: ?>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($_tpl_sect_list as $_ts): ?>
    <div class="d-flex gap-1 align-items-center">
      <!-- PDF / podgląd -->
      <a href="<?= APP_URL ?>/contracts/print_template.php?template_id=<?= $_ts['id'] ?>&contract_id=<?= $id ?>&type=<?= $TYPE ?>&preview=1"
         target="_blank"
         class="btn btn-sm btn-outline-secondary"
         title="Podgląd i druk PDF">
        <i class="bi bi-file-earmark-text me-1"></i><?= h($_ts['name']) ?>
      </a>
      <!-- DOCX -->
      <a href="<?= APP_URL ?>/contracts/download_template_docx.php?template_id=<?= $_ts['id'] ?>&contract_id=<?= $id ?>&type=<?= $TYPE ?>"
         class="btn btn-sm btn-outline-secondary py-0 px-2"
         title="Pobierz DOCX">
        <i class="bi bi-file-earmark-word"></i>
      </a>
      <!-- E-mail (zaszyfrowany ZIP) -->
      <?php if ($_tpl_can_email): ?>
      <form method="post"
            action="<?= APP_URL ?>/contracts/email_template_doc.php"
            class="d-inline"
            onsubmit="return confirm('Wysłać dokument na <?= h(addslashes($_tpl_email)) ?>?\nPlik ZIP będzie zaszyfrowany — hasło: 5 ostatnich cyfr PESEL.')">
        <input type="hidden" name="_csrf"        value="<?= csrf_token() ?>">
        <input type="hidden" name="template_id"  value="<?= $_ts['id'] ?>">
        <input type="hidden" name="contract_id"  value="<?= $id ?>">
        <input type="hidden" name="type"         value="<?= $TYPE ?>">
        <input type="hidden" name="return_url"   value="<?= h(APP_URL . '/contracts/' . $TYPE . '/view.php?id=' . $id . '&tab=docs') ?>">
        <button type="submit"
                class="btn btn-sm btn-outline-primary py-0 px-2"
                title="Wyślij na e-mail <?= h($_tpl_email) ?> (ZIP AES-256, hasło: 5 ost. cyfr PESEL)">
          <i class="bi bi-envelope-arrow-up"></i>
        </button>
      </form>
      <?php else: ?>
      <span class="text-muted" style="font-size:.8rem"
            title="<?= !$_tpl_email ? 'Brak e-mail w umowie' : 'Brak PESEL — nie można zaszyfrować' ?>">
        <i class="bi bi-envelope-slash"></i>
      </span>
      <?php endif; ?>

      <?php if (is_admin()): ?>
      <a href="<?= APP_URL ?>/admin/template_editor.php?id=<?= $_ts['id'] ?>"
         class="btn btn-sm btn-link text-muted py-0 px-1"
         title="Edytuj wzór">
        <i class="bi bi-pencil" style="font-size:.75rem"></i>
      </a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
