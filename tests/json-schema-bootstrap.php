<?php

declare(strict_types=1);

// The PHAR job's bootstrap: it loads justinrainbow/json-schema from LOCKROT_JR_VENDOR, the vendor
// directory of build/phar/composer.lock, in place of the dev copy. It exits 1 when the validator
// comes from elsewhere, so a wrong path cannot pass on the dev copy.
require __DIR__.'/../vendor/autoload.php';

$source = realpath(getenv('LOCKROT_JR_VENDOR').'/justinrainbow/json-schema/src/JsonSchema');
spl_autoload_register(static function (string $class) use ($source): void {
    $file = $source.'/'.strtr(substr($class, \strlen('JsonSchema\\')), '\\', '/').'.php';
    if ($source !== false && strpos($class, 'JsonSchema\\') === 0 && is_file($file)) {
        require $file;
    }
}, true, true);

$loaded = (new ReflectionClass(JsonSchema\Validator::class))->getFileName();
if ($source === false || !\is_string($loaded) || strpos($loaded, $source.'/') !== 0) {
    fwrite(\STDERR, "justinrainbow/json-schema does not load from LOCKROT_JR_VENDOR\n");
    exit(1);
}
