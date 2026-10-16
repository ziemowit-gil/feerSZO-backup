<?php
/**
 * modules/ti_lesson_control/logic/lessonControlCli.php — wspólne wejście CLI i agenta CRON Kontroli Lekcji i Wpisów.
 *
 *   php cli/tiLessonControl.php --dry-run                 # co by oznaczono (nic nie zapisuje, nic nie wysyła)
 *   php cli/tiLessonControl.php                           # oznacz statusy (zakres: ostatnie ti_lc_lookback_days dni)
 *   php cli/tiLessonControl.php --from=2026-10-01 --to=2026-10-15 [--course=ID] [--session=ID] [--list]
 *   php cli/tiLessonControl.php --notify [--dry-run]      # + przypomnienia i raport wg ustawień (domyślnie wyłączone)
 *   php cli/tiLessonControl.php --notify --force          # z pominięciem włącznika/godziny/okresu (testy, ręczne wywołanie)
 *   php cli/tiLessonControl.php --preview=reminder|report [--format=html|text] [--instructor=ID]
 * Agent CRON (cron/ti_lesson_control.php) = --notify --cron.
 * Kody wyjścia: 0 OK, 1 błąd wykonania, 2 złe argumenty.
 */

require_once __DIR__ . '/lessonControl.php';

function tiLcCliMain(array $args): int {
    $o = ['dry' => false, 'notify' => false, 'force' => false, 'cron' => false, 'list' => false, 'from' => '', 'to' => '',
          'course' => 0, 'session' => 0, 'preview' => '', 'format' => 'text', 'instructor' => 0];
    foreach ($args as $a) {
        if ($a === '--dry-run') $o['dry'] = true;
        elseif ($a === '--notify') $o['notify'] = true;
        elseif ($a === '--force') $o['force'] = true;
        elseif ($a === '--cron') $o['cron'] = true;
        elseif ($a === '--list') $o['list'] = true;
        elseif ($a === '--help' || $a === '-h') { echo tiLcCliHelp(); return 0; }
        elseif (preg_match('/^--(from|to)=(\d{4}-\d{2}-\d{2})$/', $a, $m)) $o[$m[1]] = $m[2];
        elseif (preg_match('/^--(course|session|instructor)=(\d+)$/', $a, $m)) $o[$m[1]] = (int)$m[2];
        elseif (preg_match('/^--preview=(reminder|report)$/', $a, $m)) $o['preview'] = $m[1];
        elseif (preg_match('/^--format=(html|text)$/', $a, $m)) $o['format'] = $m[1];
        else { fwrite(STDERR, "Nieznana opcja: {$a}\n" . tiLcCliHelp()); return 2; }
    }
    if ($o['force'] && !$o['notify']) { fwrite(STDERR, "--force działa tylko z --notify.\n"); return 2; }

    $now = new \DateTimeImmutable();
    $ts  = fn() => '[' . date('Y-m-d H:i:s') . ']';
    try {
        if ($o['preview'] !== '') return tiLcCliPreview($o, $now);

        if ($o['cron'] && !LessonControlSettings::on('ti_lc_enabled')) { echo $ts() . " ti_lesson_control: kontrola wyłączona (ti_lc_enabled=0)\n"; return 0; }
        [$from, $to] = LessonControlService::defaultRange($now);
        if ($o['from'] !== '') $from = $o['from'];
        if ($o['to'] !== '')   $to   = $o['to'];
        echo $ts() . " Kontrola wpisów {$from} – {$to}" . ($o['dry'] ? ' (PODGLĄD: bez zapisu i wysyłki)' : '') . "\n";

        $svc = new LessonControlService();
        $sum = $o['session'] ? tiLcCliOne($svc, $o['session'], $o['dry'], $now) : $svc->run($from, $to, $o['dry'], $now, $o['course'] ?: null);
        echo "  Sprawdzono lekcji: {$sum->checked}" . ($o['dry'] ? '' : ", zapisano: {$sum->stored}") . "\n";
        foreach (TiLcStatus::AUTO as $st => [$label]) {
            if (!empty($sum->byStatus[$st])) echo sprintf("  %-14s %4d\n", $label . ':', $sum->byStatus[$st]);
        }
        echo "  Zmian statusu: " . count($sum->changes) . "\n";
        if ($o['list'] || $o['session'] || ($o['dry'] && count($sum->items) <= 50)) {
            foreach ($sum->items as $sid => $it) {
                /** @var ValidationResult $r */ $r = $it['result']; $s = $it['snapshot'];
                if ($r->autoStatus === TiLcStatus::NIE_DOTYCZY) continue;
                echo sprintf("  #%-6d %s %-5s %-28s %-14s %s%s\n", $sid, $s->lessonDate, substr($s->timeFrom, 0, 5), mb_strimwidth($s->courseName, 0, 28, '…'),
                    TiLcStatus::label($r->autoStatus), implode(', ', array_map(fn($v) => $v->label, $r->missing)),
                    $r->delayMin ? ' (opóźnienie ' . round($r->delayMin / 60, 1) . ' h)' : '');
            }
        }

        if ($o['notify']) {
            $n = new LessonControlNotifier();
            $rem = $n->remindInstructors($now, $o['dry'], $o['force']);
            echo "  Przypomnienia: " . ($o['dry'] ? 'wyszłoby ' : 'wysłano ') . $rem['sent'] . "\n";
            foreach ($rem['items'] as $i) echo "    → {$i['name']} <{$i['email']}>: {$i['count']} lekcji" . ($i['mail_id'] ? " (mail #{$i['mail_id']})" : '') . "\n";
            foreach ($rem['skipped'] as $s) echo "    · pominięto: {$s}\n";
            $rep = $n->sendSupervisorReport($now, $o['dry'], $o['force']);
            echo "  Raport ({$rep['period']['label']}): " . ($o['dry'] ? 'wyszedłby do ' . count($rep['recipients']) : 'wysłano ' . $rep['sent']) . "\n";
            foreach ($rep['skipped'] as $s) echo "    · pominięto: {$s}\n";
        }
        echo $ts() . " Koniec\n";
        return 0;
    } catch (\Throwable $e) {
        fwrite(STDERR, $ts() . ' BŁĄD: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
        return 1;
    }
}

function tiLcCliOne(LessonControlService $svc, int $sid, bool $dry, \DateTimeImmutable $now): ControlRunSummary {
    $row = db_one("SELECT lesson_date FROM k30_ti_sessions WHERE id = ?", [$sid]);
    if (!$row) throw new \RuntimeException("Brak lekcji #{$sid}.");
    $sum = $svc->run((string)$row['lesson_date'], (string)$row['lesson_date'], $dry, $now);
    $sum->items = array_intersect_key($sum->items, [$sid => 1]);
    return $sum;
}

function tiLcCliPreview(array $o, \DateTimeImmutable $now): int {
    if ($o['preview'] === 'report') {
        $p = LessonControlNotifier::reportPeriod($now);
        $mail = LessonControlMailTemplates::supervisorReport((new LessonControlNotifier())->reportData($p['from'], $p['to'], $p['label']));
    } else {
        $by = (new LessonControlNotifier())->pendingReminders($now);
        $iid = $o['instructor'] ?: (int)(array_key_first($by) ?? 0);
        $items = $by[$iid] ?? [];
        if (!$items) {   // podgląd szablonu bez danych — przykładowe wiersze
            $items = [
                ['lesson_date' => date('Y-m-d', strtotime('-2 days')), 'time_from' => '10:00', 'time_to' => '11:30', 'course_name' => 'Przykładowa grupa A',
                 'auto_status' => TiLcStatus::BRAK_WPISU, 'review_status' => TiLcStatus::NIEZWERYFIKOWANY, 'review_comment' => '',
                 'missing_json' => json_encode([['code' => 'topic', 'label' => 'temat zajęć'], ['code' => 'attendance', 'label' => 'obecność']]),
                 'due_at' => date('Y-m-d 23:59:59', strtotime('-2 days')), 'reminder_count' => 0],
                ['lesson_date' => date('Y-m-d', strtotime('-1 day')), 'time_from' => '12:00', 'time_to' => '13:00', 'course_name' => 'Przykładowa grupa B',
                 'auto_status' => TiLcStatus::PRAWIDLOWY, 'review_status' => TiLcStatus::DO_POPRAWY, 'review_comment' => 'Temat nie zgadza się z sylabusem.',
                 'missing_json' => '[]', 'due_at' => date('Y-m-d 23:59:59', strtotime('-1 day')), 'reminder_count' => 0],
            ];
            $name = 'Jan Przykładowy';
        } else {
            $name = (string)(db_one("SELECT name FROM users WHERE id = ?", [$iid])['name'] ?? '');
        }
        $mail = LessonControlMailTemplates::instructorReminder($name, $items,
            ['reminder_no' => 1, 'escalate_after' => LessonControlSettings::int('ti_lc_escalate_after'), 'deadline_label' => LessonControlPolicy::forCourse(0)->deadlineLabel()]);
    }
    echo 'Temat: ' . $mail['subject'] . "\n\n" . ($o['format'] === 'html' ? $mail['html'] : $mail['text']) . "\n";
    return 0;
}

function tiLcCliHelp(): string {
    return <<<TXT
Kontrola Lekcji i Wpisów (TI)
  --dry-run              tylko podgląd: bez zapisu statusów i bez wysyłek
  --from=RRRR-MM-DD      początek zakresu (domyślnie: ti_lc_lookback_days dni wstecz)
  --to=RRRR-MM-DD        koniec zakresu (domyślnie: dziś)
  --course=ID            tylko jedna grupa
  --session=ID           tylko jedna lekcja (ze szczegółami)
  --list                 wypisz wynik każdej lekcji
  --notify               po kontroli: przypomnienia dla prowadzących i raport (wg ustawień)
  --force                z --notify: pomiń włącznik, godzinę i znacznik okresu
  --preview=reminder|report [--format=html|text] [--instructor=ID]   pokaż treść e-maila

TXT;
}
