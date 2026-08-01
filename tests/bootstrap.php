<?php

/**
 * @file
 * PHPUnit bootstrap for standalone and Drupal-project test runs.
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;

$module_root = dirname(__DIR__);
$autoload = $module_root . '/vendor/autoload.php';
if (!is_file($autoload)) {
  $autoload = (string) getenv('DRUPAL_AUTOLOAD');
}
if ($autoload === '' || !is_file($autoload)) {
  throw new RuntimeException(
    'Run composer install or set DRUPAL_AUTOLOAD to a Drupal Composer autoload.php file.',
  );
}

$loader = require_once $autoload;
if ($loader instanceof ClassLoader) {
  $loader->addPsr4('Drupal\\grok_ai_provider\\', $module_root . '/src');
  $loader->addPsr4('Drupal\\Tests\\grok_ai_provider\\', __DIR__ . '/src');
  $project_root = dirname($autoload, 2);
  $ai_path = (string) getenv('DRUPAL_AI_PATH');
  if ($ai_path === '' && InstalledVersions::isInstalled('drupal/ai')) {
    $ai_install_path = InstalledVersions::getInstallPath('drupal/ai');
    $ai_path = is_string($ai_install_path) ? $ai_install_path . '/src' : '';
  }
  if ($ai_path === '') {
    $ai_path = $project_root . '/web/modules/contrib/ai/src';
  }
  if (is_dir($ai_path)) {
    $loader->addPsr4('Drupal\\ai\\', $ai_path);
  }
}

if (!function_exists('base_path')) {

  /**
   * Provides the default Drupal base path without bootstrapping common.inc.
   */
  function base_path(): string {
    return '/';
  }

}
