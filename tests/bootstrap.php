<?php
/**
 * Boots the Craft project Speedy Cache is installed in, so the tests run against real Craft.
 *
 *   php vendor/bin/phpunit -c plugins/speedy/phpunit.xml.dist          (from the project root)
 *
 * CRAFT_PROJECT_ROOT points at another project; by default it’s three folders up (project/plugins/speedy/tests).
 * The http suite needs SPEEDY_TEST_URL, the site’s base URL, and no other page cache plugin enabled.
 */
$root = getenv('CRAFT_PROJECT_ROOT') ?: dirname(__DIR__, 3);
require $root . '/bootstrap.php';
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

spl_autoload_register(function(string $class) {
    $prefix = 'suprim\\speedy\\tests\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

if (!\suprim\speedy\Plugin::getInstance()) {
    fwrite(STDERR, "Speedy Cache isn’t installed in the project at $root.\n");
    exit(1);
}
