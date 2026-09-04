<?php
/**
 * Copyright (c) 2012 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

function loadDirectory($path) {
	if (\stripos(\basename($path), 'acceptance') !== false) {
		return;
	}
	if (\strcasecmp(\basename($path), 'ui') === 0) {
		return;
	}
	$dirEntries = \scandir($path);
	foreach ($dirEntries as $name) {
		if ($name[0] !== '.') {
			$file = $path . '/' . $name;
			if (\is_dir($file)) {
				loadDirectory($file);
			} elseif (\substr($name, -4, 4) === '.php') {
				require_once $file;
			}
		}
	}
}

function getSubclasses($parentClassName) {
	$classes = [];
	foreach (\get_declared_classes() as $className) {
		if (\is_subclass_of($className, $parentClassName)) {
			$classes[] = $className;
		}
	}

	return $classes;
}

/**
 * The apps bundled with core, from the single list the Makefile also reads.
 *
 * "Lives in the apps directory" used to be a sufficient test for "bundled with
 * core", which is what the check below used to be. It stopped being one when the
 * 29 externally released apps moved into the same directory: this suite would
 * then load all of their test trees into one process, which is not what it is
 * for and does not work anyway -- with activity enabled it dies on a duplicate
 * OCA\Activity\Tests\Unit\TestCase, declared once by loadDirectory() and once by
 * the autoloader that a test file's use of it triggers.
 *
 * Each of those apps runs its own suite via `make test-php-unit` in its own
 * directory, which is how it was run when it had its own repository.
 */
function bundledApps() {
	$lines = \file(__DIR__ . '/../build/core-bundled-apps.txt', FILE_IGNORE_NEW_LINES);
	$apps = [];
	foreach ($lines as $line) {
		$line = \trim(\preg_replace('/#.*/', '', $line));
		if ($line !== '') {
			$apps[] = $line;
		}
	}
	return $apps;
}

$apps = OC_App::getEnabledApps();
$bundled = bundledApps();

foreach ($apps as $app) {
	// skip files_external, it has its own test suite
	if ($app === 'files_external') {
		continue;
	}
	if (!\in_array($app, $bundled, true)) {
		continue;
	}
	$dir = OC_App::getAppPath($app);

	// we do not want to automatically run unit tests for extra apps
	// that might be in a secondary apps dir like apps-external
	if (\basename(\dirname($dir)) === "apps") {
		if (\is_dir($dir . '/tests')) {
			loadDirectory($dir . '/tests');
		}
	}
}
