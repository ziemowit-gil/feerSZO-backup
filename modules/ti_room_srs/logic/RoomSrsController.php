<?php
/**
 * modules/ti_room_srs/logic/RoomSrsController.php — Formularze rezerwacji sal TI ↔ SRS.
 *
 * Panel dydaktyka — kierownik albo osoba z uprawnieniem „Może rezerwować” (middleware dyd.book):
 *   index    — grupy z salą w okresie (kierownik: wszystkie, prowadzący: własne) + rezerwacje przestrzeni bez grupy;
 *   form     — formularz grupy wypełniony danymi z systemu; PESEL i adres zamieszkania wpisywane ręcznie;
 *   generate — zapis (bez PESEL/adresu), rezerwacja w SRS, pobranie pliku ze wzoru operatora sali (DOCX/PDF);
 *   adhoc / adhocSave — „Rezerwuj przestrzeń”: sala, dzień i godziny bez grupy TI (np. konsultacje, spotkanie).
 * Tylko kierownik (dyd.staff): rooms — przypisanie sal TI do wzorów operatorów i zasobów SRS.
 * To samo w SZO (sesja SZO; kierownik = administrator albo zapis w SRS) — formularz/rezerwacja zapamiętuje źródło (DYD/SZO).
 * file — formularz do wglądu z karty rezerwacji SRS (bez PESEL/adresu, nigdy niezapisane).
 */
require_once __DIR__ . '/roomSrs.php';

final class RoomSrsController
{
    private const VIEW = __DIR__ . '/../view/';

    /** 'dyd' — wejście panelu dydaktyka (saleFormularz.php), 'szo' — wejście SZO (router.php). */
    public static function source(): string
    {
        return defined('TI_ROOM_SRS_PANEL') ? 'dyd' : 'szo';
    }

    /** Zalogowany: w panelu — sesja dydaktyka (staff = kierownik), w SZO — sesja SZO (staff = administrator / zapis w SRS). */
    public static function me(): array
    {
        if (self::source() === 'dyd') {
            $me = dyd_require();
            return ['id' => (int)$me['user_id'], 'name' => (string)($me['name'] ?? ''), 'staff' => dyd_is_staff()];
        }
        require_login();
        $u = current_user();
        return ['id' => (int)$u['id'], 'name' => (string)($u['name'] ?? ''), 'staff' => is_admin() || can_write('srs')];
    }

    /** Okres z parametrów (jak w „Wykazie sal do rezerwacji”). */
    private function range(): array
    {
        $range = in_array($_GET['range'] ?? '', ['week', 'month', 'quarter'], true) ? $_GET['range'] : 'month';
        return ['range' => $range] + ti_room_reservation_range((string)($_GET['w'] ?? ''), $range);
    }

    public function index(): string
    {
        $me = self::me();
        $rr = $this->range();
        $items = tiRoomSrsCandidates($rr['from'], $rr['to'], $me['staff'] ? 0 : $me['id']);
        $resIds = array_filter(array_map(fn($i) => (int)($i['form']['srs_reservation_id'] ?? 0), $items));
        $res = [];
        if ($resIds) {
            foreach (db_all("SELECT id, status FROM resource_reservations WHERE id IN (" . implode(',', $resIds) . ")") as $r) $res[(int)$r['id']] = $r['status'];
        }
        $rooms = [];
        foreach (tiRoomSrsRooms() as $r) $rooms[(int)$r['id']] = $r;
        return Router::view(self::VIEW . 'index.php', [
            'me' => $me, 'rr' => $rr, 'items' => $items, 'res' => $res, 'rooms' => $rooms,
            'adhoc' => tiRoomSrsAdhocList($me['id'], $me['staff'], date('Y-m-d', strtotime('-7 days'))),
            'pending' => $me['staff'] ? tiRoomSrsPendingList() : [],
        ]);
    }

    public function form(): string
    {
        $me = self::me();
        [$courseId, $roomId, $from, $to] = $this->key($_GET);
        $this->ownCourse($me, $courseId);
        try {
            $d = tiRoomSrsDefaults($courseId, $roomId, $from, $to);
        } catch (RuntimeException $e) {
            flash_set('danger', $e->getMessage());
            header('Location: ' . route('trs.index')); exit;
        }
        return Router::view(self::VIEW . 'form.php', [
            'me' => $me, 'trData' => $d['data'], 'trMeta' => $d['meta'], 'trMode' => 'course',
            'trKey' => compact('courseId', 'roomId', 'from', 'to'), 'trAction' => route('trs.generate'),
            'trResStatus' => $this->resStatus($d['meta']['form']), 'back' => (string)($_GET['back'] ?? ''),
        ]);
    }

    public function generate(): ?string
    {
        $me = self::me();
        [$courseId, $roomId, $from, $to] = $this->key($_POST, 'k_');
        $this->ownCourse($me, $courseId);
        $room = tiRoomSrsRoom($roomId);
        $data = tiRoomSrsDataFromPost($_POST, (string)($room['template'] ?? ''));
        $back = route('trs.form', ['course' => $courseId, 'room' => $roomId, 'from' => $from, 'to' => $to]);
        if (!$this->peselOk($data)) return Router::redirect($back);
        try {
            $formId = tiRoomSrsSave($courseId, $roomId, $from, $to, $data, $me['id'], !empty($_POST['srs']), self::source(), $me['staff']);
        } catch (RuntimeException $e) {
            flash_set('danger', $e->getMessage());
            return Router::redirect($back);
        }
        return $this->afterSave($formId, $data, (string)($room['template'] ?? ''), $back, 'Formularz rezerwacji sali — grupa #' . $courseId . ', ' . $from . ' – ' . $to, $courseId);
    }

    /** „Rezerwuj przestrzeń”: wybór sali, dnia i godzin, potem pełny formularz operatora. */
    public function adhoc(): string
    {
        $me = self::me();
        $formId = (int)($_GET['id'] ?? 0);
        if ($formId) $this->ownAdhoc($me, $formId);
        $time = fn($v) => preg_match('/^\d{2}:\d{2}$/', (string)$v) ? (string)$v : '';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? (string)$_GET['date'] : '';
        $d = tiRoomSrsAdhocDefaults((int)($_GET['room'] ?? 0), $date, $time($_GET['tf'] ?? ''), $time($_GET['tt'] ?? ''), $me['id'], $formId);
        return Router::view(self::VIEW . 'form.php', [
            'me' => $me, 'trData' => $d['data'], 'trMeta' => $d['meta'], 'trMode' => 'adhoc',
            'trKey' => ['formId' => $formId], 'trAction' => route('trs.adhoc.save'), 'trRooms' => tiRoomSrsBookableRooms(),
            'trResStatus' => $this->resStatus($d['meta']['form']), 'back' => '',
        ]);
    }

    public function adhocSave(): ?string
    {
        $me = self::me();
        $formId = (int)($_POST['k_form'] ?? 0);
        if ($formId) $this->ownAdhoc($me, $formId);
        $roomId = (int)($_POST['k_room'] ?? 0);
        $date = (string)($_POST['k_date'] ?? '');
        $tf = (string)($_POST['k_tf'] ?? '');
        $tt = (string)($_POST['k_tt'] ?? '');
        $room = tiRoomSrsRoom($roomId);
        $data = tiRoomSrsDataFromPost($_POST, (string)($room['template'] ?? ''));
        $back = route('trs.adhoc', array_filter(['id' => $formId ?: null, 'room' => $roomId ?: null, 'date' => $date, 'tf' => $tf, 'tt' => $tt]));
        if (!$this->peselOk($data)) return Router::redirect($back);
        if (!$formId && !empty($_POST['rep'])) return $this->seriesSave($me, $roomId, $date, $tf, $tt, $data, $room, $back);
        try {
            $formId = tiRoomSrsSaveAdhoc($formId, $roomId, $date, $tf, $tt, $data, $me['id'], !empty($_POST['srs']), self::source(), $me['staff']);
        } catch (RuntimeException $e) {
            flash_set('danger', $e->getMessage());
            return Router::redirect($back);
        }
        $clash = tiRoomSrsConflicts($roomId, $date, $tf, $tt, $formId);
        if ($clash) flash_set('warning', 'Uwaga — w terminie ' . date('d.m.Y', strtotime($date)) . ' ' . $tf . '–' . $tt . ' sala jest zajęta: ' . implode('; ', $clash) . '. Rezerwacja zapisana mimo to — sprawdź termin.');
        return $this->afterSave($formId, $data, (string)($room['template'] ?? ''), route('trs.adhoc', ['id' => $formId]),
            'Rezerwacja przestrzeni — ' . $date . ' ' . $tf . '–' . $tt, 0);
    }

    /** Rezerwacja cykliczna: reguła z formularza → seria terminów (kolizje i dni wolne pomijane z komunikatem). */
    private function seriesSave(array $me, int $roomId, string $date, string $tf, string $tt, array $data, ?array $room, string $back): ?string
    {
        try {
            $rule = tiRoomSrsSeriesRuleFromPost($_POST, $date, $tf, $tt);
            $r = tiRoomSrsSaveSeries($roomId, $rule, $data, $me['id'], !empty($_POST['srs']), self::source(), $me['staff']);
        } catch (RuntimeException $e) {
            flash_set('danger', $e->getMessage());
            return Router::redirect($back);
        }
        $seriesUrl = route('trs.series', ['id' => $r['series_id']]);
        $msg = 'Utworzono serię: ' . count($r['dates']) . ' ' . tiRoomSrsTermWord(count($r['dates'])) . '.';
        if ($r['skipped']) {
            $msg .= ' Pominięto (sala niedostępna): ' . implode('; ', array_map(fn($x) => date('d.m.Y', strtotime($x[0])) . ' — ' . $x[1], $r['skipped'])) . '.';
        }
        if (!$me['staff']) {
            flash_set('info', $msg . ' Seria czeka na akceptację kierownika (dostał e-mail) — wydruk po zatwierdzeniu.');
            return Router::redirect($seriesUrl);
        }
        flash_set($r['skipped'] ? 'warning' : 'success', $msg);
        if (($_POST['_op'] ?? '') === 'save') return Router::redirect($seriesUrl);
        $data['terms'] = tiRoomSrsSeriesTermLines($r['dates'], $tf, $tt);
        ti_print_log_add('sale_formularz', 'Rezerwacja cykliczna przestrzeni — seria #' . $r['series_id'] . ' [' . strtoupper(self::source()) . ']', 0, 0,
            ['series_id' => $r['series_id'], 'source' => self::source()], ['user_id' => $me['id'], 'name' => $me['name']]);
        try {
            return $this->send((string)($room['template'] ?? ''), $data, 0, $r['dates'][0]);
        } catch (RuntimeException $e) {
            flash_set('warning', $msg . ' Pliku nie da się wygenerować: ' . $e->getMessage());
            return Router::redirect($seriesUrl);
        }
    }

    /** Zarządzanie serią: lista terminów, odwołanie terminu / serii, zatwierdzenie (kierownik), plik dla całej serii. */
    public function series(int $id): string
    {
        $me = self::me();
        $s = $this->ownSeries($me, $id);
        return Router::view(self::VIEW . 'series.php', ['me' => $me, 's' => $s, 'forms' => tiRoomSrsSeriesForms($id)]);
    }

    public function seriesCancel(int $id): ?string
    {
        $me = self::me();
        $this->ownSeries($me, $id);
        $n = tiRoomSrsCancelSeries($id, $me['id'], ($_POST['scope'] ?? '') === 'future' ? date('Y-m-d') : '');
        flash_set('success', 'Odwołano terminów: ' . $n . '. Rezerwacje SRS tych terminów zostały anulowane.');
        return Router::redirect(route('trs.series', ['id' => $id]));
    }

    public function termCancel(int $id): ?string
    {
        $me = self::me();
        $this->ownAdhoc($me, $id);
        $f = tiRoomSrsFormGet($id);
        tiRoomSrsCancelForm($id, $me['id']);
        flash_set('success', 'Odwołano termin ' . date('d.m.Y', strtotime((string)$f['date_from'])) . '.');
        return Router::redirect(!empty($f['series_id']) ? route('trs.series', ['id' => (int)$f['series_id']]) : route('trs.index'));
    }

    /** Kierownik: ręczne „Potwierdzone” / „Do rezerwacji” dla całej serii (sala ustalona z operatorem poza SRS). */
    public function seriesConfirm(int $id): ?string
    {
        $on = ($_POST['status'] ?? '') === 'potwierdzone';
        $n = tiRoomSrsSetManualStatus([], $on, $id);
        flash_set('success', ($on ? 'Oznaczono jako potwierdzone: ' : 'Cofnięto potwierdzenie: ') . $n . ' ' . tiRoomSrsTermWord($n) . '.');
        return Router::redirect(route('trs.series', ['id' => $id]));
    }

    public function seriesApprove(int $id): ?string
    {
        $me = self::me();
        $n = tiRoomSrsApproveSeries($id, $me['id']);
        flash_set('success', 'Zatwierdzono terminów: ' . $n . '.');
        return Router::redirect(route('trs.series', ['id' => $id]));
    }

    /** Wniosek operatora dla całej serii (wszystkie nieodwołane terminy w jednym dokumencie). */
    public function seriesFile(int $id): ?string
    {
        $me = self::me();
        $s = $this->ownSeries($me, $id);
        $live = array_values(array_filter(tiRoomSrsSeriesForms($id), fn($f) => empty($f['cancelled_at'])));
        if (!$live || !tiRoomSrsIsApproved($live[0])) { flash_set('warning', 'Brak zatwierdzonych terminów serii do wydruku.'); return Router::redirect(route('trs.series', ['id' => $id])); }
        $data = tiRoomSrsAdhocDefaults(0, '', '', '', (int)$live[0]['created_by'], (int)$live[0]['id'])['data'];
        $data['terms'] = tiRoomSrsSeriesTermLines(array_column($live, 'date_from'), (string)$s['time_from'], (string)$s['time_to']);
        $room = tiRoomSrsRoom((int)$s['room_id']);
        try { return $this->send((string)($room['template'] ?? ''), $data, 0, (string)$live[0]['date_from']); }
        catch (RuntimeException $e) { flash_set('warning', 'Pliku nie da się wygenerować: ' . $e->getMessage()); return Router::redirect(route('trs.series', ['id' => $id])); }
    }

    private function ownSeries(array $me, int $id): array
    {
        $s = tiRoomSrsSeriesGet($id);
        if ($s && ($me['staff'] || (int)$s['created_by'] === $me['id'])) return $s;
        http_response_code(403); exit('Brak dostępu do tej serii.');
    }

    /** Kierownik: „Zatwierdź” — formularz można wydrukować, rezerwacja idzie do SRS, autor dostaje e-mail. */
    public function approve(int $id): ?string
    {
        $me = self::me();
        try {
            tiRoomSrsApprove($id, $me['id']);
            flash_set('success', 'Zatwierdzono formularz rezerwacji sali — autor dostał e-mail i może go wydrukować.');
        } catch (RuntimeException $e) {
            flash_set('danger', $e->getMessage());
        }
        $back = (string)($_POST['back'] ?? '');
        return Router::redirect(str_starts_with($back, rtrim(APP_URL, '/') . '/') ? $back : route('trs.index'));
    }

    public function rooms(): string
    {
        return Router::view(self::VIEW . 'rooms.php', ['me' => self::me(), 'rooms' => tiRoomSrsRooms(), 'resources' => tiRoomSrsResources()]);
    }

    public function roomsSave(): ?string
    {
        $me = self::me();
        try {
            if (($_POST['_op'] ?? '') === 'create') {
                $id = tiRoomSrsCreateResource((int)($_POST['room_id'] ?? 0), $me['id']);
                flash_set('success', 'Utworzono zasób SRS #' . $id . ' i przypisano go do sali.');
            } else {
                foreach ((array)($_POST['rooms'] ?? []) as $roomId => $r) {
                    [$tpl, $opt] = array_pad(explode(':', (string)($r['form'] ?? ''), 2), 2, '');
                    tiRoomSrsSaveRoom((int)$roomId, (int)($r['resource_id'] ?? 0) ?: null, $tpl, $opt);
                }
                flash_set('success', 'Zapisano przypisania sal.');
            }
        } catch (RuntimeException $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(route('trs.rooms'));
    }

    /** SZO: formularz z karty rezerwacji SRS (wnioskodawca, administrator SRS, dysponent zasobu). */
    public function file(int $id): ?string
    {
        $form = tiRoomSrsFormGet($id);
        $res = $form && (int)$form['srs_reservation_id'] ? res_reservation_get((int)$form['srs_reservation_id']) : null;
        $u = current_user();
        $ok = $res && (is_admin() || can_write('srs') || (int)$res['user_id'] === (int)$u['id'] || (int)($res['dysponent_user_id'] ?? 0) === (int)$u['id']);
        if (!$ok) { http_response_code(403); return 'Brak dostępu do formularza.'; }
        if ((int)$form['course_id'] > 0) {
            $data = tiRoomSrsDefaults((int)$form['course_id'], (int)$form['room_id'], (string)$form['date_from'], (string)$form['date_to'])['data'];
        } else {
            $data = tiRoomSrsAdhocDefaults(0, '', '', '', (int)$form['created_by'], $id)['data'];
        }
        $room = tiRoomSrsRoom((int)$form['room_id']);
        return $this->send((string)($room['template'] ?? ''), $data, (int)$form['course_id'], (string)$form['date_from']);
    }

    /* ── pomocnicze ── */

    private function afterSave(int $formId, array $data, string $template, string $back, string $logLabel, int $courseId): ?string
    {
        if (!tiRoomSrsIsApproved(tiRoomSrsFormGet($formId))) {
            flash_set('info', 'Zapisano — formularz czeka na akceptację kierownika (dostał e-mail). Po zatwierdzeniu dostaniesz e-mail i pobierzesz plik do wydruku.');
            return Router::redirect($back);
        }
        if (($_POST['_op'] ?? '') === 'save') {
            flash_set('success', !empty($_POST['srs']) ? 'Zapisano, rezerwacja w SRS złożona/zaktualizowana.' : 'Zapisano.');
            return Router::redirect($back);
        }
        if (function_exists('ti_print_log_add')) {
            $me = self::me();
            ti_print_log_add('sale_formularz', $logLabel . ' [' . strtoupper(self::source()) . ']', $courseId, 0, ['form_id' => $formId, 'source' => self::source()],
                ['user_id' => $me['id'], 'name' => $me['name']]);
        }
        try {
            return $this->send($template, $data, $courseId, (string)(tiRoomSrsFormGet($formId)['date_from'] ?? date('Y-m-d')));
        } catch (RuntimeException $e) {
            flash_set('warning', 'Zapisano, ale pliku nie da się wygenerować: ' . $e->getMessage());
            return Router::redirect($back);
        }
    }

    private function send(string $template, array $data, int $courseId, string $from): ?string
    {
        $f = tiRoomSrsRender($template, $data);
        $course = $courseId ? db_one("SELECT group_code, name FROM k30_ti_courses WHERE id = ?", [$courseId]) : null;
        $base = $course ? (string)($course['group_code'] ?: $course['name']) : 'rezerwacja';
        $slug = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base)), '_') ?: 'sala';
        header('Content-Type: ' . $f['mime']);
        header('Content-Disposition: attachment; filename="Rezerwacja_sali_' . $slug . '_' . $from . '.' . $f['ext'] . '"');
        header('Content-Length: ' . strlen($f['bin']));
        header('Cache-Control: private, no-store');
        echo $f['bin'];
        return null;
    }

    private function peselOk(array $data): bool
    {
        if ($data['pesel'] === '' || tiRoomSrsPeselValid($data['pesel'])) return true;
        flash_set('danger', 'Numer PESEL jest niepoprawny (suma kontrolna) — popraw go albo zostaw puste pole.');
        return false;
    }

    private function resStatus(?array $form): ?string
    {
        $id = (int)($form['srs_reservation_id'] ?? 0);
        return $id ? (db_one("SELECT status FROM resource_reservations WHERE id = ?", [$id])['status'] ?? null) : null;
    }

    /** Prowadzący z uprawnieniem rezerwuje tylko dla własnych grup. */
    private function ownCourse(array $me, int $courseId): void
    {
        if ($me['staff'] || k30_ti_instructor_owns_course($me['id'], $courseId)) return;
        http_response_code(403); exit('Formularze rezerwacji sal — tylko dla własnych grup.');
    }

    private function ownAdhoc(array $me, int $formId): void
    {
        $f = tiRoomSrsFormGet($formId);
        if ($f && (int)$f['course_id'] === 0 && ($me['staff'] || (int)$f['created_by'] === $me['id'])) return;
        http_response_code(403); exit('Brak dostępu do tej rezerwacji.');
    }

    /** course/room/from/to z żądania (daty Y-m-d); w POST z prefiksem k_ — „room” to tam opcja sali we wzorze. */
    private function key(array $src, string $p = ''): array
    {
        $d = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : date('Y-m-d');
        return [(int)($src[$p . 'course'] ?? 0), (int)($src[$p . 'room'] ?? 0), $d($src[$p . 'from'] ?? ''), $d($src[$p . 'to'] ?? '')];
    }
}
