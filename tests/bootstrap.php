<?php
declare(strict_types=1);

$findRoot = function (): string {
    $root = dirname(__DIR__);
    if (is_dir($root . '/vendor/cakephp/cakephp')) {
        return $root;
    }

    $root = dirname(__DIR__, 2);
    if (is_dir($root . '/vendor/cakephp/cakephp')) {
        return $root;
    }

    $root = dirname(__DIR__, 3);
    if (is_dir($root . '/vendor/cakephp/cakephp')) {
        return $root;
    }

    throw new RuntimeException('Cannot find CakePHP vendor directory.');
};

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

define('ROOT', $findRoot());
define('APP_DIR', 'TestApp');
define('WEBROOT_DIR', 'webroot');
define('APP', ROOT . '/tests/TestApp/');
define('CONFIG', ROOT . '/tests/TestApp/config/');
define('WWW_ROOT', ROOT . DS . 'webroot' . DS);
define('TESTS', ROOT . DS . 'tests' . DS);
define('TMP', ROOT . DS . 'tmp' . DS);
define('LOGS', TMP . 'logs' . DS);
define('CACHE', TMP . 'cache' . DS);
define('RESOURCES', ROOT . DS . 'resources' . DS);
define('CAKE_CORE_INCLUDE_PATH', ROOT . '/vendor/cakephp/cakephp');
define('CORE_PATH', CAKE_CORE_INCLUDE_PATH . DS);
define('CAKE', CORE_PATH . 'src' . DS);

require ROOT . '/vendor/cakephp/cakephp/src/functions.php';
require ROOT . '/vendor/autoload.php';

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Queue\QueueManager;
use Cake\Queue\QueuePlugin as CakeQueuePlugin;
use Crustum\Queue\QueuePlugin;

Configure::write('debug', true);
Configure::write('App', [
    'namespace' => 'TestApp',
    'encoding' => 'UTF-8',
    'defaultLocale' => 'en_US',
    'defaultTimezone' => 'UTC',
    'base' => false,
    'dir' => 'src',
    'webroot' => 'webroot',
    'wwwRoot' => WWW_ROOT,
    'fullBaseUrl' => 'http://localhost',
    'paths' => [
        'plugins' => [ROOT . DS],
        'templates' => [APP . 'templates' . DS],
        'locales' => [RESOURCES . 'locales' . DS],
    ],
]);
Configure::write('Security', [
    'salt' => 'crustum-queue-test-security-salt',
]);

Cache::setConfig([
    '_cake_translations_' => [
        'className' => 'File',
        'prefix' => 'queue_test_cake_core_',
        'path' => CACHE . 'persistent' . DS,
        'serialize' => true,
        'duration' => '+10 seconds',
    ],
]);

Configure::write('Queue', [
    'default' => [
        'url' => 'null:',
        'queue' => 'default',
    ],
    'corpus_embed' => [
        'url' => 'null:',
        'queue' => 'corpus-embed',
    ],
]);

foreach (Configure::read('Queue') as $name => $config) {
    if (QueueManager::getConfig($name) === null) {
        QueueManager::setConfig($name, $config);
    }
}

if (!Plugin::isLoaded('Cake/Queue')) {
    Plugin::getCollection()->add(new CakeQueuePlugin());
}

if (!Plugin::isLoaded('Crustum/Queue')) {
    Plugin::getCollection()->add(new QueuePlugin());
}

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
