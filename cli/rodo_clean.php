#!/usr/bin/env php
<?php
/**
 * cli/rodo_clean.php — Czyszczenie rejestrów RODO i Zaświadczeń
 *
 * Użycie:
 *   php cli/rodo_clean.php                    — statystyki + interaktywne czyszczenie
 *   php cli/rodo_clean.php --dry-run          — pokaż co zostanie usunięte, nic nie rób
 *   php cli/rodo_clean.php --yes              — pomiń potwierdzenie
 *   php cli/rodo_clean.php --days=730         — starsze niż N dni (domyślnie 365)
 *   php cli/rodo_clean.php --rodo-only        — tylko rejestr RODO
 *   php cli/rodo_clean.php --certs-only       — tylko zaświadczenia
 *   php cli/rodo_clean.php --stats            — tylko statystyki, bez czyszczenia
 *   php cli/rodo_clean.php --tenant=SLUG      — baza wybranego tenanta
 *
 * Co jest czyszczone:
 *   RODO:          upoważnienia o statusie wygasłe/odwołane starsze niż --days
 *   Zaświadczenia: wydane/odrzucone wnioski starsze niż --days
 *
 * Co NIE jest czyszczone:
 *   - Aktywne upoważnienia RODO
 *   - Oczekujące wnioski o zaświadczenia
 *   - Log usunięć (rodo_deletion_log) — wymagany art. 5 ust. 2 RODO
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Ten skrypt działa tylko z linii poleceń.\n");
}

$opts     = getopt('', ['dry-run', 'yes', 'days:', 'rodo-only', 'certs-only', 'stats', 'tenant:']);
$dry      = isset($opts['dry-run']);
$noask    = isset($opts['yes']);
$stats_only = isset($opts['stats']);
$days     = max(1, (int)($opts['days'] ?? 365));
$rodo_only  = isset($opts['rodo-only']);
$certs_only = isset($opts['certs-only']);
$do_rodo    = !$certs_only;
$do_certs   = !$rodo_only;
$tenant     = $opts['tenant'] ?? null;

// ── Środowisko CLI ────────────────────────────────────────────────────────────
if (!isset($_SERVER['HTTP_HOST']))     $_SERVER['HTTP_HOST']     = 'localhost';
if (!isset($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
if (!isset($_SERVER['HTTPS']))         $_SERVER['HTTPS']         = 'off';
if (!isset($_SERVER['REQUEST_URI']))   $_SERVER['REQUEST_URI']   = '/';

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';

// Tryb tenanta
if ($tenant !== null) {
    $slug    = preg_replace('/[^a-z0-9]/', '', strtolower($tenant));
    $db_path = $root . '/tenants/' . $slug . '/umowy.db';
    if (!is_file($db_path)) {
        err("Nie znaleziono bazy tenanta '$slug' ($db_path)");
        exit(1);
    }
    // Nadpisz funkcję db() przez closure w globalnym zasięgu — uproszczone
    // Użyj bezpośrednio PDO
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;");
    $label_db = "tenant '$slug'";
} else {
    $pdo = db();
    $label_db = 'główna baza';
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function out(string $s): void  { echo $s . "\n"; }
function err(string $s): void  { fwrite(STDERR, "\033[31m[ERR] {$s}\033[0m\n"); }
function ok(string $s): void   { echo "\033[32m✔ {$s}\033[0m\n"; }
function warn(string $s): void { echo "\033[33m⚠ {$s}\033[0m\n"; }
function head(string $s): void { echo "\n\033[1;34m══ {$s} ══\033[0m\n"; }
function row(string $k, $v, string $color=''): void {
    $c = $color ? "\033[{$color}m" : '';
    $r = $color ? "\033[0m" : '';
    printf("  %-40s %s%s%s\n", $k, $c, $v, $r);
}
function pdo_one(PDO $pdo, string $sql, array $p=[]): ?array {
    $st = $pdo->prepare($sql); $st->execute($p);
    $r  = $st->fetch(PDO::FETCH_ASSOC); return $r ?: null;
}
function pdo_all(PDO $pdo, string $sql, array $p=[]): array {
    $st = $pdo->prepare($sql); $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function confirm(string $q): bool {
    echo "\033[33m{$q} [t/N]: \033[0m";
    $ans = trim(fgets(STDIN));
    return strtolower($ans) === 't';
}

// ── Nagłówek ──────────────────────────────────────────────────────────────────
out("\033[1mCLI: Czyszczenie rejestrów RODO i Zaświadczeń\033[0m");
out("Baza: {$label_db}");
out("Próg: wpisy starsze niż \033[1m{$days} dni\033[0m");
if ($dry)       warn("Tryb --dry-run: żadne dane nie zostaną usunięte");
if ($stats_only) warn("Tryb --stats: tylko statystyki");

$cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
$del_rodo = 0; $del_certs = 0;
$err_count = 0;

// ════════════════════════════════════════════════════════════════════════════
// 1. REJESTR RODO
// ════════════════════════════════════════════════════════════════════════════
if ($do_rodo) {
    head("Rejestr upoważnień RODO");

    // Sprawdź czy tabela istnieje
    $has_rodo = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='rodo_authorizations'")->fetchColumn();
    if (!$has_rodo) {
        warn("Tabela rodo_authorizations nie istnieje — pominięto");
    } else {
        // Statystyki
        $stats_rodo = pdo_all($pdo, "SELECT status, COUNT(*) AS cnt FROM rodo_authorizations GROUP BY status");
        foreach ($stats_rodo as $s) {
            $color = match($s['status']) { 'aktywne' => '32', 'cofnięte' => '31', 'wygasłe' => '33', default => '' };
            row("  " . ucfirst($s['status']), $s['cnt'], $color);
        }
        $log_cnt = (int)($pdo->query("SELECT COUNT(*) FROM rodo_deletion_log")->fetchColumn() ?? 0);
        row("  Log usunięć (zachowany)", $log_cnt, '');

        // Kandydaci do usunięcia
        $to_del = pdo_all($pdo,
            "SELECT id, number, person_name, person_pesel, contract_number, status, updated_at
             FROM rodo_authorizations
             WHERE status IN ('cofnięte','wygasłe') AND updated_at < ?
             ORDER BY updated_at ASC",
            [$cutoff]
        );

        out("\n  Kandydaci do usunięcia (status: odwołane/wygasłe, starsze niż {$days} dni): \033[1m" . count($to_del) . "\033[0m");

        if ($to_del && !$stats_only) {
            out("  Podgląd pierwszych 10:");
            foreach (array_slice($to_del, 0, 10) as $r) {
                printf("    %-22s  %-28s  %s  (%s)\n",
                    $r['number'],
                    mb_substr($r['person_name'], 0, 28),
                    substr($r['updated_at'], 0, 10),
                    $r['status']
                );
            }
            if (count($to_del) > 10) out("    … i " . (count($to_del) - 10) . " więcej");

            $proceed = $dry || $noask || $stats_only || confirm("\n  Usunąć " . count($to_del) . " rekordów RODO z bazy?");

            if ($proceed && !$dry && !$stats_only) {
                foreach ($to_del as $r) {
                    try {
                        // Pobierz pełny rekord (pliki)
                        $full = pdo_one($pdo, "SELECT * FROM rodo_authorizations WHERE id=?", [(int)$r['id']]);
                        // Zapisz do logu
                        $has_log_tbl = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='rodo_deletion_log'")->fetchColumn();
                        if ($has_log_tbl && $full) {
                            $pdo->prepare("INSERT INTO rodo_deletion_log
                                (auth_number, auth_person, auth_pesel, auth_contract, reason, deleted_by_id, deleted_by_name, deleted_at)
                                VALUES (?,?,?,?,?,NULL,'CLI-rodo_clean',datetime('now','localtime'))")
                                ->execute([
                                    $full['number'],
                                    $full['person_name'],
                                    $full['person_pesel'] ?? null,
                                    $full['contract_number'] ?? null,
                                    "Automatyczne czyszczenie CLI — status: {$full['status']}, starsze niż {$days} dni",
                                ]);
                        }
                        // Usuń pliki z dysku
                        foreach (['signed_doc_path','vol_signed_doc_path','revoke_doc_path'] as $col) {
                            if (!empty($full[$col])) {
                                $p = $root . '/uploads/' . ltrim($full[$col], '/');
                                if (file_exists($p)) @unlink($p);
                            }
                        }
                        $pdo->prepare("DELETE FROM rodo_authorizations WHERE id=?")->execute([(int)$r['id']]);
                        $del_rodo++;
                    } catch (\Throwable $e) {
                        err("Błąd przy usuwaniu #{$r['id']}: " . $e->getMessage());
                        $err_count++;
                    }
                }
                ok("Usunięto {$del_rodo} upoważnień RODO · log zachowany");
            } elseif ($dry) {
                warn("--dry-run: pominięto usuwanie " . count($to_del) . " rekordów RODO");
            }
        } elseif (!$to_del) {
            ok("Brak rekordów do usunięcia w rejestrze RODO");
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════
// 2. REJESTR ZAŚWIADCZEŃ
// ════════════════════════════════════════════════════════════════════════════
if ($do_certs) {
    head("Rejestr zaświadczeń");

    $has_certs = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='certificate_requests'")->fetchColumn();
    if (!$has_certs) {
        warn("Tabela certificate_requests nie istnieje — pominięto");
    } else {
        $stats_certs = pdo_all($pdo, "SELECT status, COUNT(*) AS cnt FROM certificate_requests GROUP BY status");
        foreach ($stats_certs as $s) {
            $color = match($s['status']) { 'wydane' => '32', 'odrzucone' => '31', 'oczekuje' => '33', default => '' };
            row("  " . ucfirst($s['status']), $s['cnt'], $color);
        }

        $to_del_c = pdo_all($pdo,
            "SELECT id, cert_number, requester_name, status, issued_at, created_at
             FROM certificate_requests
             WHERE status IN ('wydane','odrzucone')
               AND COALESCE(issued_at, created_at) < ?
             ORDER BY COALESCE(issued_at, created_at) ASC",
            [$cutoff]
        );

        out("\n  Kandydaci do usunięcia (wydane/odrzucone, starsze niż {$days} dni): \033[1m" . count($to_del_c) . "\033[0m");

        if ($to_del_c && !$stats_only) {
            out("  Podgląd pierwszych 10:");
            foreach (array_slice($to_del_c, 0, 10) as $r) {
                $dt = $r['issued_at'] ?? $r['created_at'];
                printf("    %-20s  %-28s  %s  (%s)\n",
                    $r['cert_number'] ?: "req#{$r['id']}",
                    mb_substr($r['requester_name'], 0, 28),
                    substr($dt, 0, 10),
                    $r['status']
                );
            }
            if (count($to_del_c) > 10) out("    … i " . (count($to_del_c) - 10) . " więcej");

            $proceed = $dry || $noask || $stats_only || confirm("\n  Usunąć " . count($to_del_c) . " wniosków o zaświadczenia?");

            if ($proceed && !$dry && !$stats_only) {
                foreach ($to_del_c as $r) {
                    try {
                        $full = pdo_one($pdo, "SELECT * FROM certificate_requests WHERE id=?", [(int)$r['id']]);
                        // Usuń plik
                        if (!empty($full['certificate_file'])) {
                            $p = $root . '/uploads/' . ltrim($full['certificate_file'], '/');
                            if (file_exists($p)) @unlink($p);
                        }
                        $pdo->prepare("DELETE FROM certificate_requests WHERE id=?")->execute([(int)$r['id']]);
                        $del_certs++;
                    } catch (\Throwable $e) {
                        err("Błąd przy usuwaniu zaświadczenia #{$r['id']}: " . $e->getMessage());
                        $err_count++;
                    }
                }
                ok("Usunięto {$del_certs} wniosków o zaświadczenia");
            } elseif ($dry) {
                warn("--dry-run: pominięto usuwanie " . count($to_del_c) . " zaświadczeń");
            }
        } elseif (!$to_del_c) {
            ok("Brak wniosków do usunięcia w rejestrze zaświadczeń");
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════
// Podsumowanie
// ════════════════════════════════════════════════════════════════════════════
head("Podsumowanie");
if (!$dry && !$stats_only) {
    row("Usunięto upoważnień RODO",    $del_rodo,  $del_rodo  ? '32' : '');
    row("Usunięto zaświadczeń",         $del_certs, $del_certs ? '32' : '');
    row("Błędy",                        $err_count, $err_count ? '31' : '32');
} elseif ($dry) {
    warn("Tryb --dry-run — żadnych zmian w bazie");
} else {
    warn("Tryb --stats — żadnych zmian w bazie");
}
out("");
exit($err_count > 0 ? 1 : 0);
