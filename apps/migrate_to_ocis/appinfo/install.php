<?php
// SPDX-License-Identifier: Apache-2.0

$rclonePath = __DIR__ . '/../bin/rclone_linux_amd64';

if (file_exists($rclonePath)) {
	chmod($rclonePath, 0755);
	\OC::$server->getLogger()->info("rclone executable permissions set to 0755", ['app' => 'migrate_to_ocis']);
} else {
	\OC::$server->getLogger()->error("rclone executable not found in $rclonePath", ['app' => 'migrate_to_ocis']);
}
