<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Helper;

use Egulias\EmailValidator\EmailLexer;
use Egulias\EmailValidator\EmailParser;

class EMailAddress {
	public static function validateMailAddress(string $email): bool {
		$lexer = new EmailLexer();
		$parser = new EmailParser($lexer);
		try {
			$result = $parser->parse($email);
			if ($result->isInvalid()) {
				return false;
			}
			$domain = $parser->getDomainPart();
			$parts = explode('.', $domain);

			return \count($parts) > 1;
		} catch (\Exception $invalid) {
			return false;
		}
	}
}
