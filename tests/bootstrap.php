<?php

declare(strict_types=1);

$dir = dirname(__DIR__);
for ($i = 0; $i < 8; $i++) {
    $candidate = $dir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (is_file($candidate)) {
        require $candidate;
        return;
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
        break;
    }
    $dir = $parent;
}

fwrite(STDERR, "Composer autoload not found. Run `composer install` in webman/openai or ensure a parent project has vendor/autoload.php.\n");
exit(1);
