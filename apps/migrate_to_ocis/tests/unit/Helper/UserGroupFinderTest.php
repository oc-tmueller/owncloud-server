<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\Helper;

use OCP\IUser;
use OCP\IGroup;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\ITempManager;
use OCP\Http\Client\IResponse;
use OCA\MigrateToInfiniteScale\OCIS\Client;
use OCA\MigrateToInfiniteScale\OCIS\ClientException;
use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;

class UserGroupFinderTest extends \Test\TestCase {
	private UserGroupFinder $userGroupFinder;
	private Client $ocisClient;
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private ITempManager $tempManager;

	protected function setUp(): void {
		$this->ocisClient = $this->createMock(Client::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->tempManager = $this->createMock(ITempManager::class);

		$this->userGroupFinder = new UserGroupFinder($this->ocisClient, $this->userManager, $this->groupManager, $this->tempManager);
	}

	public function testAddAndGetUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUserName')->willReturn('username_001');

		$this->ocisClient->expects($this->never())->method('checkUser');

		$this->userGroupFinder->addUserToCache($user, 'ocis_userid_001');
		self::assertSame('ocis_userid_001', $this->userGroupFinder->getUser('admin', 'token', $user));
	}

	public function testAddAndGetGroup(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('groupDisplay_01');

		$this->ocisClient->expects($this->never())->method('checkGroup');

		$this->userGroupFinder->addGroupToCache($group, 'ocis_groupid_01');
		self::assertSame('ocis_groupid_01', $this->userGroupFinder->getGroup('admin', 'token', $group));
	}

	public function testGetUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUserName')->willReturn('username_001');

		$this->ocisClient->expects($this->once())
			->method('checkUser')
			->willReturn(['id' => 'ocis_userid_001']);

		self::assertSame('ocis_userid_001', $this->userGroupFinder->getUser('admin', 'token', $user));
	}

	public function testGetUserNotFound(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUserName')->willReturn('username_001');

		$this->ocisClient->expects($this->once())
			->method('checkUser')
			->willReturn(null);

		self::assertNull($this->userGroupFinder->getUser('admin', 'token', $user));
	}

	public function testGetUserException(): void {
		$this->expectException(ClientException::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUserName')->willReturn('username_001');

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(500);
		$response->method('getBody')->willReturn('error body response');

		$this->ocisClient->expects($this->once())
			->method('checkUser')
			->willThrowException(new ClientException('something blew up', 'checkUser', $response));

		$this->userGroupFinder->getUser('admin', 'token', $user);
	}

	public function testGetGroup(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('groupDisplay_01');

		$this->ocisClient->expects($this->once())
			->method('checkGroup')
			->willReturn(['id' => 'ocis_groupid_001']);

		self::assertSame('ocis_groupid_001', $this->userGroupFinder->getGroup('admin', 'token', $group));
	}

	public function testGetGroupNotFound(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('groupDisplay_01');

		$this->ocisClient->expects($this->once())
			->method('checkGroup')
			->willReturn(null);

		self::assertNull($this->userGroupFinder->getGroup('admin', 'token', $group));
	}

	public function testGetGroupException(): void {
		$this->expectException(ClientException::class);

		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('groupDisplay_01');

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(500);
		$response->method('getBody')->willReturn('error body response');

		$this->ocisClient->expects($this->once())
			->method('checkGroup')
			->willThrowException(new ClientException('something broke', 'checkGroup', $response));

		$this->userGroupFinder->getGroup('admin', 'token', $group);
	}

	public function testGetUserById(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUserName')->willReturn('username_001');
		$this->userManager->method('get')
			->with('myUserId')
			->willReturn($user);

		$this->ocisClient->expects($this->once())
			->method('checkUser')
			->willReturn(['id' => 'ocis_userid_001']);

		self::assertSame('ocis_userid_001', $this->userGroupFinder->getUserById('admin', 'token', 'myUserId'));
	}

	public function testGetUserByIdNotFound(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUserName')->willReturn('username_001');
		$this->userManager->method('get')
			->with('myUserId')
			->willReturn($user);

		$this->ocisClient->expects($this->once())
			->method('checkUser')
			->willReturn(null);

		self::assertNull($this->userGroupFinder->getUserById('admin', 'token', 'myUserId'));
	}

	public function testGetUserByIdException(): void {
		$this->expectException(ClientException::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUserName')->willReturn('username_001');
		$this->userManager->method('get')
			->with('myUserId')
			->willReturn($user);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(500);
		$response->method('getBody')->willReturn('error body response');

		$this->ocisClient->expects($this->once())
			->method('checkUser')
			->willThrowException(new ClientException('something blew up', 'checkUser', $response));

		$this->userGroupFinder->getUserById('admin', 'token', 'myUserId');
	}

	public function testGetUserByIdNoId(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUserName')->willReturn('username_001');
		$this->userManager->method('get')
			->with('myUserId')
			->willReturn(null);

		$this->ocisClient->expects($this->never())->method('checkUser');

		self::assertNull($this->userGroupFinder->getUserById('admin', 'token', 'myUserId'));
	}

	public function testGetGroupById(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('groupDisplay_01');
		$this->groupManager->method('get')
			->with('myGroupId')
			->willReturn($group);

		$this->ocisClient->expects($this->once())
			->method('checkGroup')
			->willReturn(['id' => 'ocis_groupid_001']);

		self::assertSame('ocis_groupid_001', $this->userGroupFinder->getGroupById('admin', 'token', 'myGroupId'));
	}

	public function testGetGroupByIdNotFound(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('groupDisplay_01');
		$this->groupManager->method('get')
			->with('myGroupId')
			->willReturn($group);

		$this->ocisClient->expects($this->once())
			->method('checkGroup')
			->willReturn(null);

		self::assertNull($this->userGroupFinder->getGroupById('admin', 'token', 'myGroupId'));
	}

	public function testGetGroupByIdException(): void {
		$this->expectException(ClientException::class);

		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('groupDisplay_01');
		$this->groupManager->method('get')
			->with('myGroupId')
			->willReturn($group);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(500);
		$response->method('getBody')->willReturn('error body response');

		$this->ocisClient->expects($this->once())
			->method('checkGroup')
			->willThrowException(new ClientException('something broke', 'checkGroup', $response));

		$this->userGroupFinder->getGroupById('admin', 'token', 'myGroupId');
	}

	public function testGetGroupByIdNoId(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('groupDisplay_01');
		$this->groupManager->method('get')
			->with('myGroupId')
			->willReturn(null);

		$this->ocisClient->expects($this->never())->method('checkGroup');

		self::assertNull($this->userGroupFinder->getGroupById('admin', 'token', 'myGroupId'));
	}

	public function testSaveAndLoadCache(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUserName')->willReturn('user001');

		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('group01');

		$this->tempManager->method('getTempBaseDir')->willReturn('/tmp');

		$this->userGroupFinder->addUserToCache($user, 'ocis_user_001');
		$this->userGroupFinder->addGroupToCache($group, 'ocis_group_01');
		$this->userGroupFinder->saveCache();

		$newUserGroupFinder = new UserGroupFinder($this->ocisClient, $this->userManager, $this->groupManager, $this->tempManager);
		self::assertTrue($newUserGroupFinder->loadCache());
		self::assertSame('ocis_user_001', $newUserGroupFinder->getUser('admin', 'token', $user));
		self::assertSame('ocis_group_01', $newUserGroupFinder->getGroup('admin', 'token', $group));
	}

	public function testSaveAndLoadCacheMissingUserFetched(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUserName')->willReturn('user001');

		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('group01');

		$this->tempManager->method('getTempBaseDir')->willReturn('/tmp');

		$this->userGroupFinder->addUserToCache($user, 'ocis_user_001');
		$this->userGroupFinder->saveCache();

		$this->ocisClient->expects($this->never())
			->method('checkUser')
			->with($this->anything(), $this->anything(), $user)
			->willReturn(['id' => 'ocis_user_001']);
		$this->ocisClient->expects($this->once())
			->method('checkGroup')
			->with($this->anything(), $this->anything(), $group)
			->willReturn(['id' => 'ocis_group_01']);

		$newUserGroupFinder = new UserGroupFinder($this->ocisClient, $this->userManager, $this->groupManager, $this->tempManager);
		self::assertTrue($newUserGroupFinder->loadCache());
		self::assertSame('ocis_user_001', $newUserGroupFinder->getUser('admin', 'token', $user));
		self::assertSame('ocis_group_01', $newUserGroupFinder->getGroup('admin', 'token', $group));
	}

	public function testSaveAndLoadCacheMissingGroupFetched(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUserName')->willReturn('user001');

		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('group01');

		$this->tempManager->method('getTempBaseDir')->willReturn('/tmp');

		$this->userGroupFinder->addGroupToCache($group, 'ocis_group_01');
		$this->userGroupFinder->saveCache();

		$this->ocisClient->expects($this->once())
			->method('checkUser')
			->with($this->anything(), $this->anything(), $user)
			->willReturn(['id' => 'ocis_user_001']);
		$this->ocisClient->expects($this->never())
			->method('checkGroup')
			->with($this->anything(), $this->anything(), $group)
			->willReturn(['id' => 'ocis_group_01']);

		$newUserGroupFinder = new UserGroupFinder($this->ocisClient, $this->userManager, $this->groupManager, $this->tempManager);
		self::assertTrue($newUserGroupFinder->loadCache());
		self::assertSame('ocis_user_001', $newUserGroupFinder->getUser('admin', 'token', $user));
		self::assertSame('ocis_group_01', $newUserGroupFinder->getGroup('admin', 'token', $group));
	}

	public function testSaveAndCleanAndLoadCache(): void {
		$this->expectException(\UnexpectedValueException::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUserName')->willReturn('user001');

		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('group01');

		$this->tempManager->method('getTempBaseDir')->willReturn('/tmp');

		$this->userGroupFinder->addUserToCache($user, 'ocis_user_001');
		$this->userGroupFinder->addGroupToCache($group, 'ocis_group_01');
		$this->userGroupFinder->saveCache();
		$this->userGroupFinder->cleanCache();

		$newUserGroupFinder = new UserGroupFinder($this->ocisClient, $this->userManager, $this->groupManager, $this->tempManager);
		self::assertTrue($newUserGroupFinder->loadCache());
	}

	public function testSaveAndDoubleLoadCache(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUserName')->willReturn('user001');
		$user2 = $this->createMock(IUser::class);
		$user2->method('getUserName')->willReturn('user002');
		$user3 = $this->createMock(IUser::class);
		$user3->method('getUserName')->willReturn('user003');

		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('group01');

		$this->tempManager->method('getTempBaseDir')->willReturn('/tmp');

		$this->ocisClient->expects($this->exactly(2))
			->method('checkUser')
			->willReturn(null);

		$this->userGroupFinder->addUserToCache($user, 'ocis_user_001');
		$this->userGroupFinder->addGroupToCache($group, 'ocis_group_01');
		$this->userGroupFinder->saveCache();

		$newUserGroupFinder = new UserGroupFinder($this->ocisClient, $this->userManager, $this->groupManager, $this->tempManager);
		$newUserGroupFinder->addUserToCache($user2, 'ocis_user_002');

		self::assertSame('ocis_user_002', $newUserGroupFinder->getUser('admin', 'token', $user2));
		self::assertTrue($newUserGroupFinder->loadCache());

		$newUserGroupFinder->addUserToCache($user3, 'ocis_user_003');
		self::assertNull($newUserGroupFinder->getUser('admin', 'token', $user2));  // overwritten and not found
		self::assertSame('ocis_user_001', $newUserGroupFinder->getUser('admin', 'token', $user));
		self::assertSame('ocis_user_003', $newUserGroupFinder->getUser('admin', 'token', $user3));
		self::assertSame('ocis_group_01', $newUserGroupFinder->getGroup('admin', 'token', $group));
		self::assertFalse($newUserGroupFinder->loadCache());
		self::assertSame('ocis_user_001', $newUserGroupFinder->getUser('admin', 'token', $user));
		self::assertSame('ocis_user_003', $newUserGroupFinder->getUser('admin', 'token', $user3));
		self::assertSame('ocis_group_01', $newUserGroupFinder->getGroup('admin', 'token', $group));
		self::assertTrue($newUserGroupFinder->loadCache(true));
		self::assertSame('ocis_user_001', $newUserGroupFinder->getUser('admin', 'token', $user));
		self::assertNull($newUserGroupFinder->getUser('admin', 'token', $user3));  // user3 not saved in cache
		self::assertSame('ocis_group_01', $newUserGroupFinder->getGroup('admin', 'token', $group));
	}
}
