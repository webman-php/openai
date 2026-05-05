<?php

declare(strict_types=1);

namespace Webman\Openai\Tests\Support;

use ReflectionClass;
use Workerman\Http\Client;

/**
 * Closes pooled HTTP connections held by a {@see Client} (for simulating abrupt disconnect).
 *
 * In-flight requests normally sit in {@see ConnectionPool::$using}; under some timings the
 * client may already recycle a connection to {@see ConnectionPool::$idle} while the stream
 * is still logically open — tests that force-close should scan both buckets.
 */
final class ConnectionPoolHelper
{
    /**
     * @return int Number of connections on which {@see \Workerman\Connection\TcpConnection::close} was invoked
     */
    public static function closeAllPooledConnections(Client $client): int
    {
        $pool = self::readProtectedProperty($client, '_connectionPool');
        $closed = 0;
        foreach (['using', 'idle'] as $bucket) {
            $map = self::readProtectedProperty($pool, $bucket);
            if (!is_array($map)) {
                continue;
            }
            foreach ($map as $connections) {
                if (!is_array($connections)) {
                    continue;
                }
                foreach ($connections as $connection) {
                    if (is_object($connection) && method_exists($connection, 'close')) {
                        $connection->close();
                        $closed++;
                    }
                }
            }
        }
        return $closed;
    }

    /**
     * @deprecated Use {@see closeAllPooledConnections} which also covers {@code idle}.
     */
    public static function closeAllUsingConnections(Client $client): int
    {
        return self::closeAllPooledConnections($client);
    }

    private static function readProtectedProperty(object $object, string $property): mixed
    {
        $ref = new ReflectionClass($object);
        $prop = $ref->getProperty($property);
        // PHP 8.1+ allows reading non-public properties via Reflection without setAccessible();
        // PHP 8.5+ deprecates ReflectionProperty::setAccessible() (no-op since 8.1).
        return $prop->getValue($object);
    }
}
