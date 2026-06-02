<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');

// ── JSON endpoint ─────────────────────────────────────────────────────────────
if (isset($_GET['_json'])) {
    header('Content-Type: application/json; charset=utf-8');

    $range_start = $_GET['start'] ?? date('Y-m-01');
    $range_end   = $_GET['end']   ?? date('Y-m-t');

    // sanitise
    $range_start = preg_replace('/[^0-9\-]/', '', $range_start);
    $range_end   = preg_replace('/[^0-9\-]/', '', $range_end);

    $today = date('Y-m-d');
    $events = [];

    // helper: colour for end-date
    $end_color = function(string $date) use ($today): string {
        if ($date < $today)  return '#94a3b8'; // przeszłość – szary
        $days = (int) round((strtotime($date) - strtotime($today)) / 86400);
        if ($days <=  7) return '#ef4444'; // czerwony
        if ($days <= 30) return '#f97316'; // pomarańczowy
        return '#f59e0b';                   // żółty
    };

    // ── Tabele umów ──────────────────────────────────────────────────────────
    $contract_tables = [
        // [table, person_col, end_col, type_slug]
        ['umowy_wolontariat', 'imie_nazwisko',  'data_zakonczenia', 'wolontariat'],
        ['umowy_zlecenie',    'imie_nazwisko',  'data_zakonczenia', 'zlecenie'],
        ['umowy_dzielo',      'imie_nazwisko',  'termin_oddania',   'dzielo'],
        ['umowy_uslugi',      'nazwa_wykonawcy','data_zakonczenia', 'uslugi'],
        ['umowy_inne',        'strona_umowy',   'data_zakonczenia', 'inne'],
    ];

    foreach ($contract_tables as [$table, $person_col, $end_col, $type_slug]) {
        // Sprawdź czy tabela istnieje
        $exists = db()->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name=" . db()->quote($table)
        )->fetchColumn();
        if (!$exists) continue;

        // Daty startu (zielone)
        try {
            $stmt = db()->prepare(
                "SELECT id, numer_umowy, {$person_col} AS osoba, data_zawarcia, status
                   FROM {$table}
                  WHERE data_zawarcia BETWEEN :s AND :e
                    AND data_zawarcia IS NOT NULL AND data_zawarcia != ''
                  ORDER BY data_zawarcia"
            );
            $stmt->execute([':s' => $range_start, ':e' => $range_end]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $events[] = [
                    'id'            => "start_{$type_slug}_{$r['id']}",
                    'title'         => "\u{1F7E2} " . ($r['osoba'] ?: $r['numer_umowy']),
                    'start'         => $r['data_zawarcia'],
                    'end'           => null,
                    'color'         => '#22c55e',
                    'url'           => "/contracts/{$type_slug}/view.php?id={$r['id']}",
                    'extendedProps' => [
                        'type'        => 'start',
                        'contract_type' => $type_slug,
                        'numer_umowy' => $r['numer_umowy'],
                        'osoba'       => $r['osoba'],
                        'status'      => $r['status'],
                        'description' => 'Data zawarcia: ' . $r['data_zawarcia'],
                    ],
                ];
            }
        } catch (\Throwable $e) { /* brak kolumny – pomiń */ }

        // Daty zakończenia (kolorowe)
        try {
            $stmt = db()->prepare(
                "SELECT id, numer_umowy, {$person_col} AS osoba, {$end_col} AS data_konca, status
                   FROM {$table}
                  WHERE {$end_col} BETWEEN :s AND :e
                    AND {$end_col} IS NOT NULL AND {$end_col} != ''
                  ORDER BY {$end_col}"
            );
            $stmt->execute([':s' => $range_start, ':e' => $range_end]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $color = $end_color($r['data_konca']);
                $events[] = [
                    'id'            => "end_{$type_slug}_{$r['id']}",
                    'title'         => "\u{1F534} " . ($r['osoba'] ?: $r['numer_umowy']),
                    'start'         => $r['data_konca'],
                    'end'           => null,
                    'color'         => $color,
                    'url'           => "/contracts/{$type_slug}/view.php?id={$r['id']}",
                    'extendedProps' => [
                        'type'        => 'end',
                        'contract_type' => $type_slug,
                        'numer_umowy' => $r['numer_umowy'],
                        'osoba'       => $r['osoba'],
                        'status'      => $r['status'],
                        'description' => 'Data zakończenia: ' . $r['data_konca'],
                    ],
                ];
            }
        } catch (\Throwable $e) { /* brak kolumny – pomiń */ }
    }

    // ── Tabela ev_events ─────────────────────────────────────────────────────
    $ev_exists = db()->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='ev_events'"
    )->fetchColumn();

    if ($ev_exists) {
        try {
            $stmt = db()->prepare(
                "SELECT id, name, start_date, end_date, location
                   FROM ev_events
                  WHERE start_date <= :e AND (end_date >= :s OR (end_date IS NULL AND start_date >= :s2))
                  ORDER BY start_date"
            );
            $stmt->execute([':s' => $range_start, ':e' => $range_end, ':s2' => $range_start]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                // FullCalendar: end is exclusive, add 1 day for all-day events
                $fc_end = null;
                if (!empty($r['end_date']) && $r['end_date'] !== $r['start_date']) {
                    $fc_end = date('Y-m-d', strtotime($r['end_date'] . ' +1 day'));
                }
                $events[] = [
                    'id'            => "ev_{$r['id']}",
                    'title'         => $r['name'],
                    'start'         => $r['start_date'],
                    'end'           => $fc_end,
                    'color'         => '#3b82f6',
                    'url'           => '',
                    'extendedProps' => [
                        'type'        => 'event',
                        'description' => ($r['location'] ? 'Miejsce: ' . $r['location'] . "\n" : '') .
                                         'Od: ' . $r['start_date'] .
                                         ($r['end_date'] ? ' – Do: ' . $r['end_date'] : ''),
                    ],
                ];
            }
        } catch (\Throwable $e) { /* brak kolumny – pomiń */ }
    }

    echo json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Widok HTML ────────────────────────────────────────────────────────────────
$PAGE_TITLE = 'Kalendarz';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-calendar3 me-2 text-primary"></i>Kalendarz</h4>

    <div class="btn-group btn-group-sm" role="group" aria-label="Filtruj widoczność">
      <input type="checkbox" class="btn-check" id="togStarts"  autocomplete="off" checked>
      <label class="btn btn-outline-success" for="togStarts">
        <i class="bi bi-circle-fill me-1" style="color:#22c55e"></i>Starty
      </label>

      <input type="checkbox" class="btn-check" id="togEnds"    autocomplete="off" checked>
      <label class="btn btn-outline-warning" for="togEnds">
        <i class="bi bi-circle-fill me-1" style="color:#f97316"></i>Zakończenia
      </label>

      <input type="checkbox" class="btn-check" id="togEvents"  autocomplete="off" checked>
      <label class="btn btn-outline-primary" for="togEvents">
        <i class="bi bi-circle-fill me-1" style="color:#3b82f6"></i>Wydarzenia
      </label>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body p-2 p-md-3">
      <div id="calendar"></div>
    </div>
  </div>
</div>

<!-- Modal szczegółów -->
<div class="modal fade" id="eventModal" tabindex="-1" aria-labelledby="eventModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2" id="eventModalHeader">
        <h6 class="modal-title fw-bold" id="eventModalLabel"></h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="eventModalBody"></div>
      <div class="modal-footer py-2">
        <a href="#" class="btn btn-sm btn-primary d-none" id="eventModalLink" target="_blank">
          <i class="bi bi-eye me-1"></i>Otwórz umowę
        </a>
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Zamknij</button>
      </div>
    </div>
  </div>
</div>

<!-- FullCalendar v6 -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

<script>
(function () {
  'use strict';

  // Stan filtrów
  const filters = { start: true, end: true, event: true };

  // Zbuduj URL do pobierania danych
  function buildFeedUrl(fetchInfo) {
    const s = fetchInfo.startStr.slice(0, 10);
    const e = fetchInfo.endStr.slice(0, 10);
    return '?_json=1&start=' + s + '&end=' + e;
  }

  const calendarEl = document.getElementById('calendar');

  const calendar = new FullCalendar.Calendar(calendarEl, {
    locale: 'pl',
    initialView: 'dayGridMonth',
    height: 'auto',
    firstDay: 1, // poniedziałek
    buttonText: {
      today: 'Dziś',
      month: 'Miesiąc',
      week:  'Tydzień',
      day:   'Dzień',
    },
    headerToolbar: {
      left:   'prev,next today',
      center: 'title',
      right:  'dayGridMonth,dayGridWeek',
    },

    events: function (fetchInfo, successCallback, failureCallback) {
      fetch(buildFeedUrl(fetchInfo))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          // Filtrowanie według aktywnych toggles
          const filtered = data.filter(function (ev) {
            const t = (ev.extendedProps && ev.extendedProps.type) || '';
            if (t === 'start' && !filters.start)  return false;
            if (t === 'end'   && !filters.end)    return false;
            if (t === 'event' && !filters.event)  return false;
            return true;
          });
          successCallback(filtered);
        })
        .catch(failureCallback);
    },

    eventClick: function (info) {
      info.jsEvent.preventDefault();
      const ev   = info.event;
      const props = ev.extendedProps || {};

      // Nagłówek – kolor jak zdarzenie
      const header = document.getElementById('eventModalHeader');
      header.style.backgroundColor = ev.backgroundColor || '#3b82f6';
      header.style.color = '#fff';

      document.getElementById('eventModalLabel').textContent = ev.title;

      // Treść
      let body = '';
      if (props.numer_umowy) {
        body += '<p class="mb-1"><strong>Nr umowy:</strong> ' + escHtml(props.numer_umowy) + '</p>';
      }
      if (props.osoba) {
        body += '<p class="mb-1"><strong>Osoba / firma:</strong> ' + escHtml(props.osoba) + '</p>';
      }
      if (props.status) {
        const statusLabel = {
          'aktywna': 'Aktywna', 'projekt': 'Projekt', 'zakonczona': 'Zakończona',
          'anulowana': 'Anulowana', 'archiwalna': 'Archiwalna',
        };
        body += '<p class="mb-1"><strong>Status:</strong> ' + escHtml(statusLabel[props.status] || props.status) + '</p>';
      }
      if (props.contract_type) {
        const typeLabel = {
          'wolontariat': 'Wolontariat', 'zlecenie': 'Zlecenie', 'dzielo': 'Dzieło',
          'uslugi': 'Usługi', 'inne': 'Inne',
        };
        body += '<p class="mb-1"><strong>Typ:</strong> ' + escHtml(typeLabel[props.contract_type] || props.contract_type) + '</p>';
      }
      if (props.description) {
        body += '<p class="mb-1 text-muted small">' + escHtml(props.description).replace(/\n/g, '<br>') + '</p>';
      }
      if (!body) {
        body = '<p class="text-muted">Brak szczegółów.</p>';
      }

      document.getElementById('eventModalBody').innerHTML = body;

      // Link do umowy
      const link = document.getElementById('eventModalLink');
      if (ev.url) {
        link.href = ev.url;
        link.classList.remove('d-none');
      } else {
        link.classList.add('d-none');
      }

      const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('eventModal'));
      modal.show();
    },

    // Podpowiedź przy hover (tooltip BS5)
    eventDidMount: function (info) {
      const props = info.event.extendedProps || {};
      const tip   = [info.event.title, props.description].filter(Boolean).join('\n');
      info.el.setAttribute('title', tip);
      new bootstrap.Tooltip(info.el, { placement: 'top', trigger: 'hover', boundary: 'window' });
    },
  });

  calendar.render();

  // ── Przełączniki ──────────────────────────────────────────────────────────
  document.getElementById('togStarts').addEventListener('change', function () {
    filters.start = this.checked;
    calendar.refetchEvents();
  });
  document.getElementById('togEnds').addEventListener('change', function () {
    filters.end = this.checked;
    calendar.refetchEvents();
  });
  document.getElementById('togEvents').addEventListener('change', function () {
    filters.event = this.checked;
    calendar.refetchEvents();
  });

  // helper
  function escHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
})();
</script>

<style>
#calendar .fc-event { cursor: pointer; }
#calendar .fc-daygrid-event { border-radius: 4px; font-size: .8rem; padding: 1px 4px; }
#calendar .fc-toolbar-title { font-size: 1.1rem; font-weight: 600; }
</style>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
