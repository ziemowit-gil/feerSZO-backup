<?php

declare(strict_types=1);

namespace OCA\FeerPoczta\BackgroundJob;

use OCA\FeerPoczta\Service\FeerAddressBook;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Co godzinę: książka adresowa „FEER” z SZO (Service\FeerAddressBook). */
class FeerAddressBookJob extends TimedJob {
	public function __construct(ITimeFactory $time, private FeerAddressBook $book, private LoggerInterface $logger) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void {
		$r = $this->book->sync();
		if (empty($r['ok'])) {
			$this->logger->warning('feer_poczta: książka FEER: ' . ($r['error'] ?? 'błąd'));
		}
	}
}
