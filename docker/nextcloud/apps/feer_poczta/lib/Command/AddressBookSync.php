<?php

declare(strict_types=1);

namespace OCA\FeerPoczta\Command;

use OCA\FeerPoczta\Service\FeerAddressBook;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** occ feer:addressbook-sync — od razu uzgadnia książkę adresową „FEER” z SZO. */
class AddressBookSync extends Command {
	public function __construct(private FeerAddressBook $book) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('feer:addressbook-sync')->setDescription('Książka adresowa „FEER” w Kontaktach z SZO (jednostki, obszary, zespoły)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$r = $this->book->sync();
		$output->writeln(json_encode($r, JSON_UNESCAPED_UNICODE));
		return empty($r['ok']) ? 1 : 0;
	}
}
