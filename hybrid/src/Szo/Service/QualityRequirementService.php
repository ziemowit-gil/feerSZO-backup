<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Szo\Service;

use FeerSzo\Hybrid\Dto\AccessibilityRequirementDto;

/**
 * Rejestr wymagań dostępności publikowanych przez SZO. Zestaw domyślny
 * zasiewany raz (idempotentnie) — to standardy, nie dane operacyjne,
 * więc nie ma tu potrzeby ekranu administracyjnego w tym etapie.
 */
final class QualityRequirementService
{
    private static bool $migrated = false;

    private const DEFAULTS = [
        ['code' => 'MAT-01', 'title' => 'Materiał dostępny w formacie audio',        'description' => 'Nagranie lektorskie treści materiału.',              'wcag_level' => 'AA'],
        ['code' => 'MAT-02', 'title' => 'Materiał dostępny w brajlu',                'description' => 'Transkrypcja brajlowska materiału drukowanego.',      'wcag_level' => 'AAA'],
        ['code' => 'MAT-03', 'title' => 'Materiał w formacie z powiększoną czcionką', 'description' => 'Wersja z czcionką min. 18pt i wysokim kontrastem.',   'wcag_level' => 'AA'],
        ['code' => 'MAT-04', 'title' => 'Materiał w formacie tekstowym (plain text)', 'description' => 'Wersja bez elementów graficznych, zgodna z czytnikami ekranu.', 'wcag_level' => 'A'],
    ];

    public static function migrate(): void
    {
        if (self::$migrated) {
            return;
        }
        self::$migrated = true;
        \db()->exec("CREATE TABLE IF NOT EXISTS k30_szo_accessibility_requirements (
            code        TEXT PRIMARY KEY,
            title       TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            wcag_level  TEXT NOT NULL DEFAULT ''
        )");
        foreach (self::DEFAULTS as $d) {
            try {
                \db()->prepare(
                    "INSERT OR IGNORE INTO k30_szo_accessibility_requirements (code, title, description, wcag_level) VALUES (?,?,?,?)"
                )->execute([$d['code'], $d['title'], $d['description'], $d['wcag_level']]);
            } catch (\Throwable $e) {
            }
        }
    }

    /** @return AccessibilityRequirementDto[] */
    public function list(): array
    {
        self::migrate();
        $rows = \db_all("SELECT * FROM k30_szo_accessibility_requirements ORDER BY code");
        return array_map(static fn(array $r) => AccessibilityRequirementDto::fromArray($r), $rows);
    }
}
