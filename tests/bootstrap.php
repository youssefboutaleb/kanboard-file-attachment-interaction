<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Kanboard is not a Composer dependency of this plugin — it is the application the
 * plugin is dropped into — so `Kanboard\Controller\BaseController` does not exist
 * when the suite runs standalone. The controllers used to paper over that by
 * `require_once`-ing a test stub from inside src/, which meant production classes
 * referenced tests/ (a directory the release archive deliberately excludes).
 *
 * Loading the stub here keeps that scaffolding entirely on the test side: inside
 * Kanboard the real BaseController is autoloaded and this file never runs.
 */
require_once __DIR__ . '/../vendor/autoload.php';

if (!class_exists('Kanboard\\Controller\\BaseController')) {
    require_once __DIR__ . '/stubs/BaseController.php';
}
