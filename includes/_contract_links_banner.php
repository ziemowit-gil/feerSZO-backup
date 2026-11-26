<?php
/**
 * includes/_contract_links_banner.php — przypomnienie dla administratora: aktywne umowy bez kontaktu/jednostki/obszaru.
 * Dołączane w Rejestrze Umów i Strukturze Organizacyjnej. Prowadzi do contracts/przypisania.php.
 */
if (!function_exists('is_admin') || !is_admin()) return;
require_once dirname(__DIR__) . '/modules/contract_links/lib/ContractLinks.php';
try { $_cl_missing = ContractLinks::missingCount(); } catch (\Throwable $e) { return; }
if ($_cl_missing <= 0) return;
?>
<div class="alert alert-warning d-flex align-items-center gap-2 flex-wrap py-2" role="status">
  <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
  <span><strong><?= $_cl_missing ?></strong> <?= $_cl_missing === 1 ? 'aktywna umowa nie ma' : 'aktywnych umów nie ma' ?> przypisanego kontaktu, jednostki albo obszaru Zadań.</span>
  <a href="<?= APP_URL ?>/contracts/przypisania.php" class="btn btn-sm btn-warning ms-auto">Uzupełnij przypisania</a>
</div>
