<?php
/**
 * Migracja: ePodpis kwalifikowany + pole email w umowach
 * Uruchom raz z przeglądarki lub CLI, następnie usuń plik.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$db = db();
$results = [];

function migrate_add_column(PDO $db, string $table, string $col, string $def): void {
    global $results;
    try {
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        $results[] = "✅ {$table}.{$col} — dodano";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'duplicate') || str_contains($msg, 'already exists')) {
            $results[] = "⏭ {$table}.{$col} — już istnieje";
        } else {
            $results[] = "❌ {$table}.{$col} — błąd: {$msg}";
        }
    }
}

// ── Pole email (dla typów bez niego) ─────────────────────────────────────────
// umowy_wolontariat ma już email — pomijamy
foreach (['umowy_zlecenie', 'umowy_uslugi', 'umowy_dzielo', 'umowy_praca', 'umowy_inne'] as $t) {
    migrate_add_column($db, $t, 'email', 'VARCHAR(255)');
}

// ── Pola ePodpisu kwalifikowanego ─────────────────────────────────────────────
foreach (['umowy_zlecenie','umowy_uslugi','umowy_wolontariat','umowy_dzielo','umowy_praca','umowy_inne'] as $t) {
    migrate_add_column($db, $t, 'epodpis_dostawca',        'VARCHAR(100)');
    migrate_add_column($db, $t, 'epodpis_nr_certyfikatu',  'VARCHAR(255)');
    migrate_add_column($db, $t, 'epodpis_data_waznosci',   'DATE');
}

// ── Dane organizacji w settings ───────────────────────────────────────────────
foreach (['org_krs','org_miejscowosc','org_nip','org_regon','org_adres'] as $key) {
    $exists = $db->prepare("SELECT 1 FROM settings WHERE key_=?")->execute([$key]);
    $row    = $db->query("SELECT 1 FROM settings WHERE key_='{$key}'")->fetch();
    if (!$row) {
        $db->prepare("INSERT INTO settings (key_, value) VALUES (?,'')")->execute([$key]);
        $results[] = "✅ settings.{$key} — dodano";
    } else {
        $results[] = "⏭ settings.{$key} — już istnieje";
    }
}

?><!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8">
<title>Migracja ePodpis + email</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="p-4">
<h4>Migracja: ePodpis kwalifikowany + email</h4>
<ul class="list-unstyled">
<?php foreach ($results as $r): ?>
<li><?= htmlspecialchars($r) ?></li>
<?php endforeach; ?>
</ul>
<div class="alert alert-warning mt-3">
  <strong>Gotowe.</strong> Usuń ten plik z serwera po zakończeniu migracji.
</div>
</body></html>
