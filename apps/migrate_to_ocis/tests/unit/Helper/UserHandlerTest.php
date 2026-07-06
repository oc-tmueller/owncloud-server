<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\Helper;

use OCP\IUser;
use OCP\Http\Client\IResponse;
use OCA\MigrateToInfiniteScale\Helper\UserHandler;
use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;
use OCA\MigrateToInfiniteScale\OCIS\ClientException;

class UserHandlerTest extends \Test\TestCase {
	/** @var UserHandler */
	private UserHandler $userHandler;
	/** @var UserGroupFinder */
	private UserGroupFinder $userGroupFinder;

	protected function setUp(): void {
		$this->userGroupFinder = $this->createMock(UserGroupFinder::class);

		$this->userHandler = new UserHandler($this->userGroupFinder);
	}

	public function canBeMigratedProvider(): array {
		// good user
		$user1 = $this->createMock(IUser::class);
		$user1->method('getEMailAddress')->willReturn('user001@example.prv');
		$user1->method('isEnabled')->willReturn(true);
		$user1->method('getBackendClassName')->willReturn('Database');

		// no email
		$user2 = $this->createMock(IUser::class);
		$user2->method('getEMailAddress')->willReturn(null);
		$user2->method('isEnabled')->willReturn(true);
		$user2->method('getBackendClassName')->willReturn('Database');

		// disabled user
		$user3 = $this->createMock(IUser::class);
		$user3->method('getEMailAddress')->willReturn('user001@example.prv');
		$user3->method('isEnabled')->willReturn(false);
		$user3->method('getBackendClassName')->willReturn('Database');

		// LDAP user
		$user4 = $this->createMock(IUser::class);
		$user4->method('getEMailAddress')->willReturn('user001@example.prv');
		$user4->method('isEnabled')->willReturn(true);
		$user4->method('getBackendClassName')->willReturn('LDAP');

		$user5 = $this->createMock(IUser::class);
		$user5->method('getEMailAddress')->willReturn('user001@example.prv');
		$user5->method('isEnabled')->willReturn(true);
		$user5->method('getBackendClassName')->willReturn('OCA\User_LDAP\User_Proxy');

		return [
			[$user1, true],
			[$user2, false],
			[$user3, false],
			[$user4, false],
			[$user5, false],
		];
	}

	/**
	 * @dataProvider canBeMigratedProvider
	 */
	public function testCanBeMigrated(IUser $user, $expectedResult): void {
		self::assertSame($expectedResult, $this->userHandler->canBeMigrated($user));
	}

	public function testHasBeenMigrated(): void {
		$this->userGroupFinder->method('getUser')->willReturn('ocisUser_001');
		self::assertTrue($this->userHandler->hasBeenMigrated('admin', 'token', $this->createMock(IUser::class)));
	}

	public function testHasBeenMigratedNullUser(): void {
		$this->userGroupFinder->method('getUser')->willReturn(null);
		self::assertFalse($this->userHandler->hasBeenMigrated('admin', 'token', $this->createMock(IUser::class)));
	}

	public function testHasBeenMigratedException(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(500);
		$response->method('getBody')->willReturn('error body response');

		$ex = new ClientException('something broke', 'getUser', $response);
		$this->userGroupFinder->method('getUser')->willThrowException($ex);
		self::assertFalse($this->userHandler->hasBeenMigrated('admin', 'token', $this->createMock(IUser::class)));
	}
}
