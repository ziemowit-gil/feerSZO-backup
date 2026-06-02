<?php
/**
 * Webhook dispatcher — outgoing webhooks for NGO management system
 * Available events: contract.created, contract.updated, contract.expired,
 *                   volunteer.added, task.created, user.registered, webhook.test
 */

function webhooks_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webhook_endpoints (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            secret TEXT,
            events TEXT NOT NULL DEFAULT '[]',
            is_active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            last_triggered_at TEXT NULL,
            last_status INTEGER NULL
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webhook_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            endpoint_id INTEGER,
            event TEXT,
            payload TEXT,
            response_code INTEGER,
            response_body TEXT,
            triggered_at TEXT DEFAULT (datetime('now','localtime'))
        )
    ");
}

function webhook_fire(string $event, array $payload): void {
    try {
        webhooks_migrate();
        $endpoints = db_all(
            "SELECT * FROM webhook_endpoints WHERE is_active = 1"
        );
        foreach ($endpoints as $ep) {
            try {
                $events = json_decode($ep['events'] ?? '[]', true);
                if (!is_array($events) || !in_array($event, $events, true)) {
                    continue;
                }

                $body = json_encode([
                    'event'     => $event,
                    'data'      => $payload,
                    'timestamp' => time(),
                ], JSON_UNESCAPED_UNICODE);

                $headers = [
                    'Content-Type: application/json',
                    'X-Event: ' . $event,
                ];
                if (!empty($ep['secret'])) {
                    $headers[] = 'X-Webhook-Secret: ' . $ep['secret'];
                }

                $ctx = stream_context_create([
                    'http' => [
                        'method'        => 'POST',
                        'header'        => implode("\r\n", $headers),
                        'content'       => $body,
                        'timeout'       => 5,
                        'ignore_errors' => true,
                    ],
                ]);

                $response_body = @file_get_contents($ep['url'], false, $ctx);
                $response_code = 0;
                $_resp_headers = function_exists('http_get_last_response_headers')
                    ? (http_get_last_response_headers() ?? [])
                    : ($http_response_header ?? []);
                foreach ($_resp_headers as $_rh) {
                    if (preg_match('/HTTP\/\S+\s+(\d+)/', $_rh, $m)) {
                        $response_code = (int)$m[1];
                        break;
                    }
                }

                $truncated = $response_body === false
                    ? '[connection failed]'
                    : substr((string)$response_body, 0, 500);

                db_insert('webhook_log', [
                    'endpoint_id'   => (int)$ep['id'],
                    'event'         => $event,
                    'payload'       => $body,
                    'response_code' => $response_code,
                    'response_body' => $truncated,
                ]);

                db()->prepare(
                    "UPDATE webhook_endpoints
                        SET last_triggered_at = datetime('now','localtime'),
                            last_status = ?
                      WHERE id = ?"
                )->execute([$response_code, (int)$ep['id']]);

            } catch (\Throwable $e) {
                // never throw — log silently
                error_log('[webhook_fire] endpoint ' . ($ep['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        error_log('[webhook_fire] ' . $e->getMessage());
    }
}
