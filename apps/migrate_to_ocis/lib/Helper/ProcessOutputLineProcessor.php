<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Helper;

class ProcessOutputLineProcessor {
	/**
	 * @var callable
	 */
	private $callback;

	public function __construct(callable $callback) {
		$this->callback = $callback;
	}

	private array $buffers = [];
	public function __invoke($type, $buffer) {
		$b = $this->buffers[$type] ?? '';
		$b .= $buffer;
		$this->buffers[$type] = $this->procBuffer($type, $b);
	}

	private function procBuffer(string $type, string $b): string {
		$lines = explode(PHP_EOL, $b);
		$last = array_pop($lines);
		$this->call($type, $lines);
		return $last;
	}

	private function call(string $type, array $lines): void {
		$callback = $this->callback;
		foreach ($lines as $line) {
			$callback($type, $line);
		}
	}

	public function close(): void {
		foreach ($this->buffers as $type => $buffer) {
			$this->procBuffer($type, $buffer . PHP_EOL);
		}
	}
}
