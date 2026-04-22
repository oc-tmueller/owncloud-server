<?php
// SPDX-License-Identifier: Apache-2.0

namespace unit;

use OCA\MigrateToInfiniteScale\Command\CommandTrait;
use Test\TestCase;

class RCloneTest extends TestCase {
	public function test(): void {
		$obscured = CommandTrait::RCloneObscure('foo');
		self::assertIsString($obscured);
		self::assertEquals(26, \strlen($obscured));
	}
}
