<?php
namespace OCA\MigrateToInfiniteScale\Helper;

class SharePermissionMapper {
	public const CONDITION_CONTAINS_FILE = 'Resource.File';
	public const CONDITION_CONTAINS_FOLDER = 'Resource.Folder';

	/**
	 * @var array<string, array>
	 * The key is the role's id, and the value is the whole role info
	 */
	private array $roles = [];

	/**
	 * @param array $roles a list of roles, coming from OCISClient->getShareRoles
	 */
	public function __construct(array $roles) {
		foreach ($roles as $role) {
			$this->roles[$role['id']] = $role;
		}
	}

	/**
	 * Find the share role that will be used for read-only shares
	 * (or shares with only view permissions)
	 *
	 * The chosen share role from the list must have the following
	 * conditions:
	 * - all the resource actions must be for reading
	 * - if there is an action condition, it must contain
	 * the $permissionCondition (contain the string, not exact match)
	 *
	 * From the candidates, the one with the most read actions will
	 * be chosen.
	 * @param string $permissionCondition see CONDITION_CONTAINS_*
	 * @return array|null the role info or null if no matching role
	 * is found.
	 */
	public function findReadRole(string $permissionCondition): ?array {
		$chosenRole = null;
		$maxActionsForChosenRolePermission = 0;
		foreach ($this->roles as $ocisShareRole) {
			foreach ($ocisShareRole['rolePermissions'] as $rolePermission) {
				if (!isset($rolePermission['condition']) || \strpos($rolePermission['condition'], $permissionCondition) !== false) {
					// allowed actions apply to files
					foreach ($rolePermission['allowedResourceActions'] as $action) {
						if (\substr($action, -4) !== 'read') {
							// action is different than "read"
							continue 2; // skip to the next role permission
						}
					}

					if ($chosenRole === null) {
						$chosenRole = $ocisShareRole;
						$maxActionsForChosenRolePermission = \count($rolePermission['allowedResourceActions']);
					} else {
						// prioritize the role with the most actions
						if ($maxActionsForChosenRolePermission < \count($rolePermission['allowedResourceActions'])) {
							$chosenRole = $ocisShareRole;
							$maxActionsForChosenRolePermission = \count($rolePermission['allowedResourceActions']);
						}
					}
				}
			}
		}
		return $chosenRole;
	}

	/**
	 * Find the share role that will be used for read & write shares
	 *
	 * The chosen share role from the list must have the following
	 * conditions:
	 * - resource actions must contain at least "content/read",
	 * "basic/read" and "upload/create"
	 * - if there is an action condition, it must target files
	 *
	 * From the candidates, the one with the most actions targeting
	 * files will be chosen.
	 * @return array|null the role info or null if no matching role
	 * is found.
	 */
	public function findFileReadAndWriteRole(): ?array {
		$chosenRole = null;
		$maxActionsForChosenRolePermission = 0;
		foreach ($this->roles as $ocisShareRole) {
			foreach ($ocisShareRole['rolePermissions'] as $rolePermission) {
				if (!isset($rolePermission['condition']) || \strpos($rolePermission['condition'], 'Resource.File') !== false) {
					// allowed actions apply to files

					// the allowed actions should contain at least the following items:
					// - libre.graph/driveItem/upload/create
					// - libre.graph/driveItem/basic/read
					// - libre.graph/driveItem/content/read
					if (
						!\in_array('libre.graph/driveItem/content/read', $rolePermission['allowedResourceActions'], true) ||
						!\in_array('libre.graph/driveItem/basic/read', $rolePermission['allowedResourceActions'], true) ||
						!\in_array('libre.graph/driveItem/upload/create', $rolePermission['allowedResourceActions'], true)
					) {
						// skip to the next
						continue;
					}

					if ($chosenRole === null) {
						$chosenRole = $ocisShareRole;
						$maxActionsForChosenRolePermission = \count($rolePermission['allowedResourceActions']);
					} else {
						// prioritize the role with the most actions
						if ($maxActionsForChosenRolePermission < \count($rolePermission['allowedResourceActions'])) {
							$chosenRole = $ocisShareRole;
							$maxActionsForChosenRolePermission = \count($rolePermission['allowedResourceActions']);
						}
					}
				}
			}
		}
		return $chosenRole;
	}

	/**
	 * Find the share role that will be used for read & write shares.
	 * This will be applicable only to folders, not files.
	 *
	 * The chosen share role from the list must have the following
	 * conditions:
	 * - resource actions must contain at least "content/read",
	 * "basic/read", "upload/create", "children/create", "standard/delete",
	 * and "path/update"
	 * - if there is an action condition, it must target folders
	 *
	 * From the candidates, the one with the most actions targeting
	 * folders will be chosen.
	 * @return array|null the role info or null if no matching role
	 * is found.
	 */
	public function findFolderReadAndWriteRole(): ?array {
		$chosenRole = null;
		$maxActionsForChosenRolePermission = 0;
		foreach ($this->roles as $ocisShareRole) {
			foreach ($ocisShareRole['rolePermissions'] as $rolePermission) {
				if (!isset($rolePermission['condition']) || \strpos($rolePermission['condition'], 'Resource.Folder') !== false) {
					// allowed actions apply to files

					// the allowed actions should contain at least the following items:
					// - libre.graph/driveItem/children/create
					// - libre.graph/driveItem/standard/delete
					// - libre.graph/driveItem/upload/create
					// - libre.graph/driveItem/path/update
					// - libre.graph/driveItem/basic/read
					// - libre.graph/driveItem/content/read
					if (
						!\in_array('libre.graph/driveItem/children/create', $rolePermission['allowedResourceActions'], true) ||
						!\in_array('libre.graph/driveItem/standard/delete', $rolePermission['allowedResourceActions'], true) ||
						!\in_array('libre.graph/driveItem/upload/create', $rolePermission['allowedResourceActions'], true) ||
						!\in_array('libre.graph/driveItem/path/update', $rolePermission['allowedResourceActions'], true) ||
						!\in_array('libre.graph/driveItem/content/read', $rolePermission['allowedResourceActions'], true) ||
						!\in_array('libre.graph/driveItem/basic/read', $rolePermission['allowedResourceActions'], true)
					) {
						// skip to the next
						continue;
					}

					if ($chosenRole === null) {
						$chosenRole = $ocisShareRole;
						$maxActionsForChosenRolePermission = \count($rolePermission['allowedResourceActions']);
					} else {
						// prioritize the role with the most actions
						if ($maxActionsForChosenRolePermission < \count($rolePermission['allowedResourceActions'])) {
							$chosenRole = $ocisShareRole;
							$maxActionsForChosenRolePermission = \count($rolePermission['allowedResourceActions']);
						}
					}
				}
			}
		}
		return $chosenRole;
	}

	/**
	 * Find the share role that will be used for write-only shares
	 * (file drop)
	 *
	 * The chosen share role from the list must have the following
	 * conditions:
	 * - resource actions must contain at least "children/create",
	 * "path/update" and "upload/create".
	 * - resource actions must NOT contain "standard/delete"
	 * - if there is an action condition, it must target folders
	 *
	 * From the candidates, the one with the most actions targeting
	 * files will be chosen.
	 *
	 * NOTE: read actions are allowed to appear because there is no real
	 * write-only role in oCIS without read actions in the default setup.
	 * @return array|null the role info or null if no matching role
	 * is found.
	 */
	public function findFolderWriteOnlyRole(): ?array {
		$chosenRole = null;
		$maxActionsForChosenRolePermission = 0;
		foreach ($this->roles as $ocisShareRole) {
			foreach ($ocisShareRole['rolePermissions'] as $rolePermission) {
				if (!isset($rolePermission['condition']) || \strpos($rolePermission['condition'], 'Resource.Folder') !== false) {
					// allowed actions apply to files

					// the allowed actions should contain at least the following items:
					// - libre.graph/driveItem/children/create
					// - libre.graph/driveItem/upload/create
					// - libre.graph/driveItem/path/update
					// the allowed actions must NOT contain:
					// - libre.graph/driveItem/standard/delete
					if (
						!\in_array('libre.graph/driveItem/children/create', $rolePermission['allowedResourceActions'], true) ||
						!\in_array('libre.graph/driveItem/upload/create', $rolePermission['allowedResourceActions'], true) ||
						!\in_array('libre.graph/driveItem/path/update', $rolePermission['allowedResourceActions'], true) ||
						\in_array('libre.graph/driveItem/standard/delete', $rolePermission['allowedResourceActions'], true)
					) {
						// skip to the next
						continue;
					}

					if ($chosenRole === null) {
						$chosenRole = $ocisShareRole;
						$maxActionsForChosenRolePermission = \count($rolePermission['allowedResourceActions']);
					} else {
						// prioritize the role with the most actions
						if ($maxActionsForChosenRolePermission < \count($rolePermission['allowedResourceActions'])) {
							$chosenRole = $ocisShareRole;
							$maxActionsForChosenRolePermission = \count($rolePermission['allowedResourceActions']);
						}
					}
				}
			}
		}
		return $chosenRole;
	}

	/**
	 * Get a permission map to find a suitable role.
	 * The map will contain the matched roles for files and folders
	 * with read-only, read & write and write-only permission.
	 *
	 * [
	 *   'file' => [
	 *     'ro' => $read-only-role,
	 *     'rw' => $read&write-role,
	 *   ],
	 *   'folder' => [
	 *     'ro' => $read-only-role,
	 *     'rw' => $read&write-role,
	 *     'wo' => $write-only-role,
	 *   ]
	 * ]
	 *
	 * Each role will contain the whole stored information about it (id,
	 * display name, allowed actions, etc). Note that files and folder
	 * roles might be different based on conditions
	 */
	public function getPermissionMap(): array {
		return [
			'file' => [
				'ro' => $this->findReadRole(self::CONDITION_CONTAINS_FILE),  // read-only
				'rw' => $this->findFileReadAndWriteRole(),  // read&write
			],
			'folder' => [
				'ro' => $this->findReadRole(self::CONDITION_CONTAINS_FOLDER),  // read-only
				'rw' => $this->findFolderReadAndWriteRole(),  // read&write
				'wo' => $this->findFolderWriteOnlyRole(),  // write-only
			],
		];
	}

	/**
	 * Get the role by id, or null if there isn't such role
	 */
	public function getRoleById(string $id): ?array {
		return $this->roles[$id] ?? null;
	}
}
