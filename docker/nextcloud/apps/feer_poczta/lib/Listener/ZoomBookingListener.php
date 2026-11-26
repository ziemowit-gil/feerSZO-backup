<?php

declare(strict_types=1);

namespace OCA\FeerPoczta\Listener;

use OCA\FeerPoczta\Service\SzoClient;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Rezerwacja w Kalendarzu (harmonogram spotkań) z lokalizacją zawierającą „Zoom” → spotkanie Zoom z SZO.
 *
 * Słucha OCA\Calendar\Events\BeforeAppointmentBookedEvent (przed zapisem wydarzenia i wysłaniem e-maili).
 * Link ustawiany jako $booking->setTalkUrl(): Kalendarz wpisuje go wtedy jako LOCATION wydarzenia i do e-maila
 * potwierdzającego („Gdzie:”) — bez osobnych wiadomości. Spotkanie tworzy SZO (api/internal/nc_zoom_meeting.php)
 * na koncie hosta Zoom, z kontrolą kolizji z lekcjami TI. Gdy nie wyjdzie (kolizja, awaria) — rezerwacja przechodzi
 * bez linku, a organizator dostaje e-mail z powodem.
 *
 * @template-implements IEventListener<Event>
 */
class ZoomBookingListener implements IEventListener {
	public function __construct(
		private SzoClient $szo,
		private IClientService $http,
		private IAppConfig $config,
		private IUserManager $users,
		private IMailer $mailer,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getBooking') || !method_exists($event, 'getConfig')) {
			return;
		}
		$booking = $event->getBooking();
		$config = $event->getConfig();
		$location = (string)($config->getLocation() ?? '');
		if (stripos($location, 'zoom') === false) {
			return;
		}
		$key = $this->config->getValueString('feer_poczta', 'szo_key', '');
		if ($key === '') {
			$this->notifyOrganizer($config, $booking, 'Brak klucza SZO w chmurze (feer_poczta szo_key).');
			return;
		}
		$payload = [
			'key' => 'nc-' . $config->getToken() . '-' . $booking->getStart() . '-' . strtolower((string)$booking->getEmail()),
			'topic' => trim($config->getName() . ' — ' . $booking->getDisplayName()),
			'start_ts' => (int)$booking->getStart(),
			'end_ts' => (int)$booking->getEnd(),
			'attendee_name' => (string)$booking->getDisplayName(),
			'attendee_email' => (string)$booking->getEmail(),
			'organizer' => (string)$config->getUserId(),
			'description' => (string)($booking->getDescription() ?? ''),
		];
		try {
			$res = $this->http->newClient()->post($this->szo->szoUrl() . '/api/internal/nc_zoom_meeting.php', [
				'headers' => ['X-Internal-Key' => $key, 'Content-Type' => 'application/json'],
				'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
				'timeout' => 20,
			]);
			$data = json_decode((string)$res->getBody(), true);
		} catch (\Throwable $e) {
			$this->logger->warning('feer_poczta: Zoom dla rezerwacji niedostępny: ' . $e->getMessage());
			$this->notifyOrganizer($config, $booking, 'SZO nie odpowiedziało (' . $e->getMessage() . ').');
			return;
		}
		if (is_array($data) && !empty($data['ok']) && !empty($data['join_url'])) {
			$booking->setTalkUrl((string)$data['join_url']);
			return;
		}
		$reason = is_array($data) ? (string)($data['reason'] ?? 'nieznany błąd') : 'nieprawidłowa odpowiedź SZO';
		$this->notifyOrganizer($config, $booking, $reason);
	}

	private function notifyOrganizer($config, $booking, string $reason): void {
		try {
			$user = $this->users->get((string)$config->getUserId());
			$to = $user?->getEMailAddress();
			if (!$to) {
				return;
			}
			$when = (new \DateTimeImmutable('@' . (int)$booking->getStart()))->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('d.m.Y H:i');
			$msg = $this->mailer->createMessage();
			$msg->setTo([$to]);
			$msg->setSubject('Rezerwacja bez linku Zoom: ' . $config->getName() . ', ' . $when);
			$msg->setPlainBody(
				"Ktoś zarezerwował spotkanie „" . $config->getName() . "” na " . $when . ".\n"
				. 'Rezerwujący: ' . $booking->getDisplayName() . ' <' . $booking->getEmail() . ">\n\n"
				. "Nie udało się utworzyć spotkania Zoom, więc rezerwacja nie ma linku.\n"
				. 'Powód: ' . $reason . "\n\n"
				. "Utwórz spotkanie ręcznie i wyślij link rezerwującemu albo zaproponuj inny termin.\n"
			);
			$this->mailer->send($msg);
		} catch (\Throwable $e) {
			$this->logger->warning('feer_poczta: nie wysłano informacji o braku Zooma: ' . $e->getMessage());
		}
	}
}
