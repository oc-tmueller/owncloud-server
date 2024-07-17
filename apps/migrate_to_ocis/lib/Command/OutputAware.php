<?php

namespace OCA\MigrateToInfiniteScale\Command;

use Symfony\Component\Console\Output\OutputInterface;

trait OutputAware {
	private OutputInterface $output;
	private function writeln(string $message = "", bool $verbose = false): void {
		$this->output->writeln($message, $verbose ? OutputInterface::VERBOSITY_VERBOSE : 0);
	}
}
