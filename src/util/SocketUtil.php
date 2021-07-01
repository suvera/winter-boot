<?php
declare(strict_types=1);

namespace dev\winterframework\util;

class SocketUtil {

    public static function isPortOpened(string $address, int|string $port, int $timeout = 30): bool {
        $connection = @fsockopen(
            $address,
            $port,
            $errno,
            $errStr,
            $timeout
        );

        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }

        return false;
    }

}