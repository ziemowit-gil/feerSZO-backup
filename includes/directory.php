<?php
/**
 * Książka telefoniczna / Katalog współpracowników — helper functions
 */

function directory_migrate(): void {
    $db = db();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS user_profiles (
            user_id INTEGER PRIMARY KEY,
            bio TEXT DEFAULT '',
            phone_public INTEGER DEFAULT 0,
            skills TEXT DEFAULT '',
            avatar_file TEXT DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS profile_field_defs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL,
            field_key TEXT NOT NULL UNIQUE,
            field_type TEXT DEFAULT 'text',
            options TEXT DEFAULT '',
            is_active INTEGER DEFAULT 1,
            sort_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS profile_field_values (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            field_id INTEGER NOT NULL,
            value TEXT DEFAULT '',
            UNIQUE(user_id, field_id)
        )");
    } catch (Exception $e) {
        // migration errors non-fatal on already-existing db
    }
}

function directory_get_profile(int $user_id): ?array {
    $user = db_one("
        SELECT u.id, u.name, u.email, u.first_name, u.last_name, u.phone_number, u.role, u.is_active,
               COALESCE(up.bio, '') AS bio,
               COALESCE(up.phone_public, 0) AS phone_public,
               COALESCE(up.skills, '') AS skills,
               COALESCE(up.avatar_file, '') AS avatar_file
        FROM users u
        LEFT JOIN user_profiles up ON up.user_id = u.id
        WHERE u.id = ?
    ", [$user_id]);

    if (!$user) return null;

    // Primary org membership
    $member = db_one("
        SELECT om.position_name, om.is_head, om.phone_direct, om.phone_mobile,
               ou.name AS unit_name, ou.id AS unit_id,
               op.name AS position_def_name
        FROM org_members om
        LEFT JOIN org_units ou ON ou.id = om.unit_id
        LEFT JOIN org_positions op ON op.id = om.position_id
        WHERE om.user_id = ? AND om.is_primary = 1 AND om.status = 'active'
        ORDER BY om.id DESC LIMIT 1
    ", [$user_id]);

    if (!$member) {
        $member = db_one("
            SELECT om.position_name, om.is_head, om.phone_direct, om.phone_mobile,
                   ou.name AS unit_name, ou.id AS unit_id,
                   op.name AS position_def_name
            FROM org_members om
            LEFT JOIN org_units ou ON ou.id = om.unit_id
            LEFT JOIN org_positions op ON op.id = om.position_id
            WHERE om.user_id = ? AND om.status = 'active'
            ORDER BY om.id DESC LIMIT 1
        ", [$user_id]);
    }

    $user['unit_name']       = $member['unit_name'] ?? '';
    $user['unit_id']         = $member['unit_id'] ?? null;
    $user['position_name']   = $member['position_name'] ?: ($member['position_def_name'] ?? '');
    $user['is_head']         = $member['is_head'] ?? 0;
    $user['phone_direct']    = $member['phone_direct'] ?? '';
    $user['phone_mobile']    = $member['phone_mobile'] ?? '';

    // Resolve public phone
    $user['phone_display'] = '';
    if ($user['phone_public']) {
        $user['phone_display'] = $user['phone_direct'] ?: $user['phone_mobile'] ?: $user['phone_number'];
    }

    // Custom field values
    $fv_rows = db_all("
        SELECT field_id, value FROM profile_field_values WHERE user_id = ?
    ", [$user_id]);
    $user['field_values'] = [];
    foreach ($fv_rows as $fv) {
        $user['field_values'][(int)$fv['field_id']] = $fv['value'];
    }

    // Skills JSON decode
    $user['skills_array'] = [];
    if ($user['skills']) {
        $decoded = json_decode($user['skills'], true);
        if (is_array($decoded)) $user['skills_array'] = $decoded;
    }

    return $user;
}

function directory_get_field_defs(): array {
    return db_all("
        SELECT * FROM profile_field_defs WHERE is_active = 1 ORDER BY sort_order ASC, id ASC
    ");
}

function directory_save_profile(int $uid, array $data): void {
    $db = db();

    // Skills: convert comma-separated or array to JSON
    $skills_raw = $data['skills'] ?? '';
    if (is_array($skills_raw)) {
        $skills_arr = array_values(array_filter(array_map('trim', $skills_raw)));
    } else {
        $skills_arr = array_values(array_filter(array_map('trim', explode(',', $skills_raw))));
    }
    $skills_json = json_encode($skills_arr, JSON_UNESCAPED_UNICODE);

    $bio          = substr(trim($data['bio'] ?? ''), 0, 1000);
    $phone_public = empty($data['phone_public']) ? 0 : 1;
    $avatar_file  = $data['avatar_file'] ?? '';

    $exists = db_one("SELECT user_id FROM user_profiles WHERE user_id = ?", [$uid]);
    if ($exists) {
        $db->prepare("UPDATE user_profiles SET bio=?, phone_public=?, skills=?, avatar_file=?, updated_at=CURRENT_TIMESTAMP WHERE user_id=?")
           ->execute([$bio, $phone_public, $skills_json, $avatar_file, $uid]);
    } else {
        $db->prepare("INSERT INTO user_profiles (user_id, bio, phone_public, skills, avatar_file) VALUES (?,?,?,?,?)")
           ->execute([$uid, $bio, $phone_public, $skills_json, $avatar_file]);
    }

    // Custom field values
    $custom = $data['custom_fields'] ?? [];
    if (is_array($custom)) {
        $upsert = $db->prepare("INSERT INTO profile_field_values (user_id, field_id, value)
            VALUES (?, ?, ?)
            ON CONFLICT(user_id, field_id) DO UPDATE SET value=excluded.value");
        foreach ($custom as $field_id => $value) {
            $upsert->execute([$uid, (int)$field_id, trim((string)$value)]);
        }
    }
}

/**
 * Generate avatar color class index from user id (0-7)
 */
function directory_avatar_color(int $user_id): string {
    $colors = [
        '#3b82f6', // blue
        '#8b5cf6', // violet
        '#10b981', // green
        '#f59e0b', // orange
        '#ec4899', // pink
        '#ef4444', // red
        '#14b8a6', // teal
        '#6366f1', // indigo
    ];
    return $colors[$user_id % 8];
}

/**
 * Render avatar HTML (circle with initials or image)
 */
function directory_avatar_html(array $user, int $size = 48, string $extra_class = ''): string {
    $initials = '';
    if (!empty($user['first_name'])) $initials .= mb_strtoupper(mb_substr($user['first_name'], 0, 1));
    if (!empty($user['last_name']))  $initials .= mb_strtoupper(mb_substr($user['last_name'], 0, 1));
    if (!$initials && !empty($user['name'])) {
        $parts = explode(' ', trim($user['name']));
        foreach (array_slice($parts, 0, 2) as $p) $initials .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    if (!$initials) $initials = '?';

    $color = directory_avatar_color((int)($user['id'] ?? 0));
    $fs    = max(12, (int)($size * 0.4));
    $style = "width:{$size}px;height:{$size}px;background:{$color};color:#fff;font-size:{$fs}px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;flex-shrink:0;";

    $avatar_file = $user['avatar_file'] ?? '';
    if ($avatar_file && file_exists(dirname(__DIR__) . '/uploads/avatars/' . $avatar_file)) {
        $url = APP_URL . '/uploads/avatars/' . h($avatar_file);
        return "<img src=\"{$url}\" alt=\"" . h($initials) . "\" class=\"rounded-circle {$extra_class}\" style=\"width:{$size}px;height:{$size}px;object-fit:cover;\">";
    }

    return "<span class=\"dir-avatar {$extra_class}\" style=\"{$style}\">" . h($initials) . "</span>";
}

/**
 * Display name helper
 */
function directory_display_name(array $user): string {
    $fn = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    return $fn ?: ($user['name'] ?? '');
}
