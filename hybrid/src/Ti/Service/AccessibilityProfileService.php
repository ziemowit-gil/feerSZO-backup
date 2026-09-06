<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Ti\Service;

use FeerSzo\Hybrid\Dto\AccessibilityProfileDto;

/**
 * Logika domenowa TI: profile dostępności uczestników. Używa tych samych
 * globalnych helperów co reszta aplikacji (db(), db_one(), db_exec() —
 * patrz includes/db.php) — to JEDYNE miejsce w warstwie hybrydowej, które
 * dotyka bazy danych dla tej domeny.
 */
final class AccessibilityProfileService
{
    private static bool $migrated = false;

    public static function migrate(): void
    {
        if (self::$migrated) {
            return;
        }
        self::$migrated = true;
        \db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_accessibility_profiles (
            client_id                   INTEGER PRIMARY KEY,
            assistive_tools             TEXT NOT NULL DEFAULT '[]',
            preferred_material_formats  TEXT NOT NULL DEFAULT '[]',
            tyflo_technologies          TEXT NOT NULL DEFAULT '[]',
            notes                       TEXT NOT NULL DEFAULT '',
            updated_at                  DATETIME
        )");
    }

    public function getProfile(int $clientId): AccessibilityProfileDto
    {
        self::migrate();
        $row = \db_one("SELECT * FROM k30_ti_accessibility_profiles WHERE client_id = ?", [$clientId]);
        if (!$row) {
            return new AccessibilityProfileDto(clientId: $clientId);
        }
        return new AccessibilityProfileDto(
            clientId: $clientId,
            assistiveTools: (array)json_decode((string)$row['assistive_tools'], true),
            preferredMaterialFormats: (array)json_decode((string)$row['preferred_material_formats'], true),
            tyfloTechnologies: (array)json_decode((string)$row['tyflo_technologies'], true),
            notes: (string)$row['notes'],
        );
    }

    public function saveProfile(AccessibilityProfileDto $profile): void
    {
        self::migrate();
        \db()->prepare(
            "INSERT INTO k30_ti_accessibility_profiles
                (client_id, assistive_tools, preferred_material_formats, tyflo_technologies, notes, updated_at)
             VALUES (?,?,?,?,?, datetime('now','localtime'))
             ON CONFLICT(client_id) DO UPDATE SET
                assistive_tools = excluded.assistive_tools,
                preferred_material_formats = excluded.preferred_material_formats,
                tyflo_technologies = excluded.tyflo_technologies,
                notes = excluded.notes,
                updated_at = excluded.updated_at"
        )->execute([
            $profile->clientId,
            json_encode($profile->assistiveTools, JSON_UNESCAPED_UNICODE),
            json_encode($profile->preferredMaterialFormats, JSON_UNESCAPED_UNICODE),
            json_encode($profile->tyfloTechnologies, JSON_UNESCAPED_UNICODE),
            $profile->notes,
        ]);
    }
}
