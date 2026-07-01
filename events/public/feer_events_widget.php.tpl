<?php
/**
 * feer_events_widget.php
 * Widget PHP do osadzenia listy wydarzeń FEER na zewnętrznej stronie.
 *
 * Instalacja: skopiuj ten plik na swój serwer, a następnie w miejscu,
 * gdzie ma się wyświetlić lista wydarzeń:
 *
 *   require __DIR__ . '/feer_events_widget.php';
 *   echo feer_events_widget_html(['limit' => 6]);
 *
 * Opcje: limit (int, 1-20), ids ("slug1,slug2" — konkretne wydarzenia),
 *        type ("webinar" | "stationary").
 * Dane pochodzą z publicznego feedu — tylko wydarzenia opublikowane i oznaczone
 * jako publiczne; nigdy dane osobowe uczestników.
 */

if (!function_exists('feer_events_widget_html')) {
    function feer_events_widget_html(array $opts = []): string {
        $api_base = $opts['api_base'] ?? '__API_BASE__';
        $params   = array_filter([
            'limit' => $opts['limit'] ?? 6,
            'ids'   => $opts['ids']   ?? '',
            'type'  => $opts['type']  ?? '',
        ], fn($v) => $v !== '' && $v !== null);
        $url = $api_base . '?' . http_build_query($params);

        $json = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $json = curl_exec($ch);
        } else {
            $ctx  = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
            $json = @file_get_contents($url, false, $ctx);
        }

        $data   = json_decode((string)$json, true);
        $events = is_array($data['data'] ?? null) ? $data['data'] : [];
        if (!$events) {
            return '<div style="color:#94a3b8;font-family:sans-serif;font-size:.9rem">Brak nadchodzących wydarzeń.</div>';
        }

        $esc = fn($s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES);
        $fmt = function ($iso) {
            if (!$iso) return '';
            $ts = strtotime($iso);
            return $ts ? date('d.m.Y H:i', $ts) : $iso;
        };

        $html = '<div style="font-family:system-ui,-apple-system,\'Segoe UI\',sans-serif;display:grid;gap:14px">';
        foreach ($events as $ev) {
            $html .= '<div style="border:1px solid #e5e0f5;border-radius:12px;padding:14px 16px;background:#fff;box-shadow:0 1px 3px rgba(76,29,149,.06)">'
                . '<a href="' . $esc($ev['register_url'] ?? '#') . '" target="_blank" rel="noopener" style="color:#4c1d95;font-weight:700;text-decoration:none;font-size:1rem">' . $esc($ev['title'] ?? '') . '</a>'
                . '<div style="font-size:.85rem;color:#64748b;margin-top:4px">'
                . ($ev['start_at'] ? '🗓 ' . $esc($fmt($ev['start_at'])) . '&nbsp;&nbsp;' : '')
                . (!empty($ev['venue']) ? '📍 ' . $esc($ev['venue']) : $esc($ev['type_label'] ?? ''))
                . '</div>'
                . (!empty($ev['description']) ? '<div style="font-size:.85rem;color:#334155;margin-top:6px">' . $esc($ev['description']) . '</div>' : '')
                . '<a href="' . $esc($ev['register_url'] ?? '#') . '" target="_blank" rel="noopener" style="display:inline-block;margin-top:8px;background:#7c3aed;color:#fff;text-decoration:none;font-size:.82rem;font-weight:600;padding:6px 14px;border-radius:8px">Zarejestruj się</a>'
                . '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}
