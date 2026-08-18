<?php
// SPDX-License-Identifier: Apache-2.0

$rclonePath = __DIR__ . '/../bin/rclone_linux_amd64';

// The binary is committed 0755 and released 0755, so this chmod is only a
// safety net for installs whose packaging dropped the bit: the complete tarball
// used to normalize every file to 0644 (fixed in owncloud/server-release#52).
// Keep it - without an exec bit the migration cannot run at all, and Trivy's
// gobinary analyzer silently skips the binary because it gates on that bit.
if (file_exists($rclonePath)) {
	chmod($rclonePath, 0755);
	\OC::$server->getLogger()->info("rclone executable permissions set to 0755", ['app' => 'migrate_to_ocis']);
} else {
	\OC::$server->getLogger()->error("rclone executable not found in $rclonePath", ['app' => 'migrate_to_ocis']);
}
