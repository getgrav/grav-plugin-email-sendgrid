<?php

declare(strict_types=1);

/**
 * The plugin ships its own vendor directory, and that directory holds the
 * Symfony SendGrid bridge and Composer's autoloader and nothing else - Symfony
 * Mailer itself comes from Grav at runtime, which is why the plugin's
 * composer.json replaces it rather than requiring it.
 *
 * Installing PHPUnit into that same directory would put development packages
 * into the released plugin, so the suite keeps its own composer.json and its
 * own vendor directory here under tests/ instead. Run `composer install` in
 * this directory once, then `phpunit` from the repository root.
 */
$autoload = __DIR__ . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "The test dependencies are not installed. Run: composer install -d tests\n");
    exit(1);
}

require $autoload;

/**
 * The provider contract lives in the Email plugin, which is a sibling checkout
 * rather than a Composer package - Grav plugins are installed by GPM, not by
 * Composer, so there is no package to require here.
 *
 * `EMAIL_PLUGIN_ROOT` points at it for anybody whose checkout is somewhere
 * else; the default is the sibling folder, which is where it is on a normal
 * Grav site and in the workspace this was written in.
 */
$emailPluginRoot = getenv('EMAIL_PLUGIN_ROOT');
if (!is_string($emailPluginRoot) || trim($emailPluginRoot) === '') {
    $emailPluginRoot = dirname(__DIR__, 2) . '/grav-plugin-email';
}

$contract = rtrim($emailPluginRoot, '/') . '/classes/Providers';

if (!is_dir($contract)) {
    fwrite(STDERR, sprintf(
        "The Email plugin's provider contract was not found at %s.\n"
        . "Point EMAIL_PLUGIN_ROOT at a checkout of grav-plugin-email that has it.\n",
        $contract
    ));
    exit(1);
}

spl_autoload_register(static function (string $class) use ($contract): void {
    $prefix = 'Grav\\Plugin\\Email\\Providers\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = $contract . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
