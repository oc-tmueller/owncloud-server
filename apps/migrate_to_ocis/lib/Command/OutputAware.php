<?php

namespace OCA\MigrateToInfiniteScale\Command;

use Symfony\Component\Console\Output\OutputInterface;

trait OutputAware {
	protected OutputInterface $output;
	protected function writeln(string $message = "", bool $verbose = false): void {
		$this->output->writeln($message, $verbose ? OutputInterface::VERBOSITY_VERBOSE : 0);
	}
}
