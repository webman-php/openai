<?php

declare(strict_types=1);

use Workerman\Events\Fiber;
use Workerman\Events\Swoole;
use Workerman\Events\Swow;
use Workerman\Timer;
use Workerman\Worker;
use Webman\Openai\Tests\MockOpenaiHttpWorker;

require_once __DIR__ . '/bootstrap.php';

$mockPort = (int)(getenv('MOCK_OPENAI_HTTP_PORT') ?: '17171');
$mockListen = getenv('MOCK_OPENAI_HTTP_LISTEN') ?: '127.0.0.1';
putenv('MOCK_OPENAI_HTTP_PORT=' . $mockPort);
putenv('MOCK_OPENAI_HTTP_LISTEN=' . $mockListen);
$_ENV['MOCK_OPENAI_HTTP_PORT'] = (string) $mockPort;
$_ENV['MOCK_OPENAI_HTTP_LISTEN'] = $mockListen;

$eventLoopClass = null;
if (class_exists(Revolt\EventLoop::class) && (DIRECTORY_SEPARATOR === '/' || !extension_loaded('swow'))) {
    $eventLoopClass = Fiber::class;
} elseif (extension_loaded('Swoole')) {
    $eventLoopClass = Swoole::class;
} elseif (extension_loaded('Swow')) {
    $eventLoopClass = Swow::class;
}

if ($eventLoopClass === null) {
    fwrite(STDERR, "No supported Workerman event loop (need revolt+Fiber, Swoole, or Swow).\n");
    exit(1);
}

create_openai_test_worker(function () {
    $app = new PHPUnit\TextUI\Application();
    $base = [
        dirname(__DIR__) . '/vendor/bin/phpunit',
        '--colors=always',
        '-c',
        dirname(__DIR__) . '/phpunit.xml.dist',
    ];
    // Workerman CLI tokens (see Workerman\Worker::parseCommand); must not reach PHPUnit.
    $workermanOnlyArgs = [
        'start',
        'stop',
        'restart',
        'reload',
        'status',
        'connections',
        '-d',
        '-g',
    ];
    $rawArgv = array_slice($_SERVER['argv'] ?? [], 1);
    $extra = array_values(array_filter(
        $rawArgv,
        static fn (string $arg): bool => !in_array($arg, $workermanOnlyArgs, true)
    ));
    $app->run(array_merge($base, $extra));
}, $eventLoopClass);

/**
 * @param Closure():void $callable
 */
function create_openai_test_worker(Closure $callable, string $eventLoopClass): void
{
    $worker = new Worker();
    $worker->eventLoop = $eventLoopClass;
    $worker->onWorkerStart = function () use ($callable, $eventLoopClass) {
        $fp = fopen(__FILE__, 'r+');
        flock($fp, LOCK_EX);
        echo PHP_EOL . PHP_EOL . PHP_EOL . '[webman/openai tests — event loop: ' . basename(str_replace('\\', '/', $eventLoopClass)) . ']' . PHP_EOL;
        try {
            $callable();
        } catch (Throwable $e) {
            echo $e;
        } finally {
            flock($fp, LOCK_UN);
        }
        Timer::repeat(1, function () use ($fp) {
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                if (function_exists('posix_kill')) {
                    posix_kill(posix_getppid(), SIGINT);
                } else {
                    Worker::stopAll();
                }
            }
        });
    };
}

$http = new Worker("http://{$mockListen}:{$mockPort}");
$http->name = 'OpenaiMockHttp';
$http->onMessage = [MockOpenaiHttpWorker::class, 'handle'];

Worker::runAll();
