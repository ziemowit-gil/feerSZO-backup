<?php
/**
 * NotificationService — serwis powiadomień e-mail dla akcji EZD i Org.
 *
 * Zależności: includes/org.php, includes/mail_queue.php, includes/ezd.php
 *
 * Użycie:
 *   NotificationService::onDekretacja(int $dekr_id): void
 *   NotificationService::onUnitAssignment(int $dekr_id, int $unit_id): void
 *   NotificationService::toUser(int $user_id, string $subject, string $tpl_key, array $vars): void
 *   NotificationService::toUnit(int $unit_id, string $subject, string $tpl_key, array $vars): void
 */

class NotificationService
{
    // ── Główne punkty wejścia (EZD) ──────────────────────────────────────────

    /**
     * Wywołaj po ezd_dekretacja_create() — adresuje i kolejkuje e-mail.
     */
    public static function onDekretacja(int $dekr_id): void
    {
        $dekr = self::loadDekretacjaContext($dekr_id);
        if (!$dekr) return;

        [$sygnatura, $doc_title, $doc_url] = self::resolveDocContext($dekr);

        $unit_id = $dekr['unit_id'] ?? null;
        $dysp    = EZD_DYSPOZYCJE[$dekr['dyspozycja']] ?? $dekr['dyspozycja'];

        // Odbiorca = wykonawca (z uwzględnieniem zastępstwa)
        $recipient = org_resolve_email((int)$dekr['wykonawca_id']);
        if (!$recipient['email']) return;

        $subject = "[EZD] {$dysp}: {$sygnatura}";
        $html    = self::buildEzdDekretacjaHtml([
            'sygnatura'      => $sygnatura,
            'doc_title'      => $doc_title,
            'doc_url'        => $doc_url,
            'zlecajacy'      => $dekr['zlecajacy_name'],
            'dyspozycja'     => $dysp,
            'tresc'          => $dekr['tresc'],
            'recipient_name' => $recipient['name'],
            'via_substitute' => $recipient['via_substitute'],
            'original_name'  => $recipient['original_name'],
            'deadline'       => $dekr['deadline'] ?? '',
        ]);

        self::enqueue($recipient['email'], $recipient['name'], $subject, $html, 'ezd_dekretacja', $dekr_id);

        // Dodatkowy mail do ogólnego adresu jednostki (jeśli dekretacja na jednostkę)
        if ($unit_id) {
            $unit = org_unit_get((int)$unit_id);
            if ($unit && $unit['email'] && $unit['email'] !== $recipient['email']) {
                self::enqueue($unit['email'], $unit['name'], $subject, $html, 'ezd_dekretacja', $dekr_id);
            }
        }
    }

    /**
     * Dekretacja na całą jednostkę:
     * - znajduje kierownika tej jednostki (lub zastępcę),
     * - ustawia wykonawca_id na kierownika,
     * - wysyła powiadomienie.
     */
    public static function onUnitAssignment(int $dekr_id, int $unit_id): void
    {
        $head = org_unit_head($unit_id);
        if (!$head) {
            // Brak kierownika — zaloguj ale nie rzucaj wyjątku
            error_log("NotificationService: jednostka #$unit_id nie ma kierownika — dekretacja #$dekr_id bez notyfikacji.");
            return;
        }
        // Zaktualizuj wykonawca_id na kierownika
        db()->prepare("UPDATE ezd_dekretacje SET wykonawca_id=? WHERE id=?")->execute([(int)$head['user_id'], $dekr_id]);
        self::onDekretacja($dekr_id);
    }

    // ── Generyczne powiadomienia ──────────────────────────────────────────────

    public static function toUser(int $user_id, string $subject, string $tpl_key, array $vars, string $ctx_type = '', ?int $ctx_id = null): void
    {
        $recipient = org_resolve_email($user_id);
        if (!$recipient['email']) return;
        $html = self::buildGenericHtml($tpl_key, array_merge($vars, ['recipient_name' => $recipient['name']]));
        self::enqueue($recipient['email'], $recipient['name'], $subject, $html, $ctx_type, $ctx_id);
    }

    public static function toUnit(int $unit_id, string $subject, string $tpl_key, array $vars, string $ctx_type = '', ?int $ctx_id = null): void
    {
        $head = org_unit_head($unit_id);
        if ($head) {
            self::toUser((int)$head['user_id'], $subject, $tpl_key, $vars, $ctx_type, $ctx_id);
        }
        $unit = org_unit_get($unit_id);
        if ($unit && $unit['email']) {
            $html = self::buildGenericHtml($tpl_key, array_merge($vars, ['recipient_name' => $unit['name']]));
            self::enqueue($unit['email'], $unit['name'], $subject, $html, $ctx_type, $ctx_id);
        }
    }

    // ── Kolejkowanie ──────────────────────────────────────────────────────────

    public static function enqueue(string $email, string $name, string $subject, string $html, string $ctx_type = '', ?int $ctx_id = null): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return;
        mail_queue_add($email, $name, $subject, $html, '', $ctx_type, $ctx_id);
    }

    // ── Szablony HTML ─────────────────────────────────────────────────────────

    private static function loadDekretacjaContext(int $dekr_id): ?array
    {
        return db_one(
            "SELECT d.*,
                    s.znak_sprawy, s.title AS sprawa_title,
                    p.sygnatura AS pismo_syg, p.title AS pismo_title,
                    u.sygnatura AS umowa_syg, u.title AS umowa_title,
                    zl.name  AS zlecajacy_name,
                    wyk.name AS wykonawca_name, wyk.email AS wykonawca_email
             FROM ezd_dekretacje d
             LEFT JOIN ezd_sprawy  s  ON s.id=d.sprawa_id
             LEFT JOIN ezd_pisma   p  ON p.id=d.pismo_id
             LEFT JOIN ezd_umowy   u  ON u.id=d.umowa_id
             LEFT JOIN users zl  ON zl.id=d.zlecajacy_id
             LEFT JOIN users wyk ON wyk.id=d.wykonawca_id
             WHERE d.id=?",
            [$dekr_id]
        );
    }

    private static function resolveDocContext(array $dekr): array
    {
        if ($dekr['pismo_id']) {
            return [$dekr['pismo_syg'], $dekr['pismo_title'], APP_URL . '/ezd/pisma/view.php?id=' . $dekr['pismo_id']];
        }
        if ($dekr['umowa_id']) {
            return [$dekr['umowa_syg'], $dekr['umowa_title'], APP_URL . '/ezd/umowy/view.php?id=' . $dekr['umowa_id']];
        }
        return [$dekr['znak_sprawy'], $dekr['sprawa_title'], APP_URL . '/ezd/sprawy/view.php?id=' . $dekr['sprawa_id']];
    }

    // ── Builder szablonów e-mail ──────────────────────────────────────────────

    private static function buildEzdDekretacjaHtml(array $v): string
    {
        $sign  = htmlspecialchars($v['sygnatura']      ?? '—',  ENT_QUOTES);
        $title = htmlspecialchars($v['doc_title']      ?? '—',  ENT_QUOTES);
        $url   = htmlspecialchars($v['doc_url']        ?? '#',  ENT_QUOTES);
        $from  = htmlspecialchars($v['zlecajacy']      ?? '—',  ENT_QUOTES);
        $disp  = htmlspecialchars($v['dyspozycja']     ?? '—',  ENT_QUOTES);
        $note  = nl2br(htmlspecialchars($v['tresc']    ?? '',   ENT_QUOTES));
        $rname = htmlspecialchars($v['recipient_name'] ?? '',   ENT_QUOTES);
        $dead  = $v['deadline'] ? '<tr><td style="padding:8px 12px;background:#f8fafc;font-weight:600;color:#475569;width:40%">Termin:</td><td style="padding:8px 12px">'.htmlspecialchars(date_pl($v['deadline']), ENT_QUOTES).'</td></tr>' : '';
        $note_row = $v['tresc'] ? '<tr><td style="padding:8px 12px;background:#f8fafc;font-weight:600;color:#475569;vertical-align:top">Treść / uwagi:</td><td style="padding:8px 12px">'.$note.'</td></tr>' : '';

        $sub_notice = '';
        if (!empty($v['via_substitute'])) {
            $orig = htmlspecialchars($v['original_name'] ?? '', ENT_QUOTES);
            $sub_notice = '<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;margin-bottom:20px;border-radius:0 6px 6px 0;font-size:13px">'
                        . '<strong>⚠️ Zastępstwo</strong><br>Ta wiadomość jest kierowana do Ciebie jako do zastępcy osoby <strong>' . $orig . '</strong>, która jest aktualnie niedostępna.'
                        . '</div>';
        }

        $body = '<div style="background:#2563eb;padding:10px 32px;margin:-28px -32px 24px;border-bottom:1px solid #1d4ed8">'
              . '<span style="color:#bfdbfe;font-size:12px">Nowe zadanie do wykonania:</span>'
              . '<span style="color:#ffffff;font-size:14px;font-weight:700;margin-left:8px">' . $disp . '</span>'
              . '</div>'
              . '<p style="margin:0 0 6px;color:#475569;font-size:13px">Cześć <strong>' . $rname . '</strong>,</p>'
              . '<p style="margin:0 0 20px;color:#64748b;font-size:13px">Przydzielono Ci nowe zadanie w systemie EZD. Szczegóły poniżej.</p>'
              . $sub_notice
              . '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;font-size:13px;margin-bottom:24px">'
              . '<tr><td style="padding:8px 12px;background:#f8fafc;font-weight:600;color:#475569;width:40%">Sygnatura:</td>'
              . '<td style="padding:8px 12px;font-weight:700;font-family:monospace;color:#1e40af">' . $sign . '</td></tr>'
              . '<tr><td style="padding:8px 12px;background:#f1f5f9;font-weight:600;color:#475569">Tytuł / sprawa:</td>'
              . '<td style="padding:8px 12px;color:#1e293b">' . $title . '</td></tr>'
              . '<tr><td style="padding:8px 12px;background:#f8fafc;font-weight:600;color:#475569">Przekazał/a:</td>'
              . '<td style="padding:8px 12px;color:#374151">' . $from . '</td></tr>'
              . '<tr><td style="padding:8px 12px;background:#f1f5f9;font-weight:600;color:#475569">Dyspozycja:</td>'
              . '<td style="padding:8px 12px"><span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:4px;font-weight:600;font-size:12px">' . $disp . '</span></td></tr>'
              . $dead . $note_row
              . '</table>'
              . '<a href="' . $url . '" style="display:inline-block;background:#2563eb;color:#fff;padding:11px 22px;border-radius:6px;text-decoration:none;font-weight:700;font-size:14px">Otwórz dokument w systemie →</a>'
              . '<p style="margin:16px 0 0;font-size:11px;color:#94a3b8">Jeśli link nie działa, skopiuj adres: <a href="' . $url . '" style="color:#2563eb">' . $url . '</a></p>';

        return _feer_email_tpl($body, 'Nowe zadanie EZD — ' . htmlspecialchars($v['sygnatura'] ?? '', ENT_QUOTES));
    }

    private static function buildGenericHtml(string $tpl_key, array $v): string
    {
        $rname = htmlspecialchars($v['recipient_name'] ?? '', ENT_QUOTES);
        $body  = htmlspecialchars($v['body'] ?? '', ENT_QUOTES);
        $url   = htmlspecialchars($v['url'] ?? '', ENT_QUOTES);
        $btn   = $url ? '<p style="margin:20px 0 0"><a href="' . $url . '" style="display:inline-block;background:#2563eb;color:#fff;padding:11px 22px;border-radius:6px;text-decoration:none;font-weight:700">Przejdź do systemu →</a></p>' : '';

        $inner = '<p style="margin:0 0 14px">Cześć <strong>' . $rname . '</strong>,</p>'
               . '<div style="background:#f8fafc;border-left:4px solid #2563eb;padding:14px 16px;border-radius:0 6px 6px 0;margin:0 0 4px">' . $body . '</div>'
               . $btn;

        return _feer_email_tpl($inner);
    }
}
