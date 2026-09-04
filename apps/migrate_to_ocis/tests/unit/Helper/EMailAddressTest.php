<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\Helper;

use OCA\MigrateToInfiniteScale\Helper\EMailAddress;

class EMailAddressTest extends \Test\TestCase {
	public function validateMailAddressProvider(): array {
		return [
			['mailme@example.prv', true],
			['user001@very.big.company.prv', true],
			['user001+info@very.big.company.prv', true],
			['mailme', false],
			['user001@example@second.org', false],
			['', false],
		];
	}

	/**
	 * @dataProvider validateMailAddressProvider
	 */
	public function testValidateMailAddress($mail, $expected): void {
		self::assertSame($expected, EMailAddress::validateMailAddress($mail));
	}
}
