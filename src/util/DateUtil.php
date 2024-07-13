<?php

declare(strict_types=1);

namespace dev\winterframework\util;

use DateTime;
use DateTimeZone;
use dev\winterframework\exception\InvalidSyntaxException;

class DateUtil {
    const DEFATULT_DATE_FORMAT = 'Y-m-d';

    public static function getCurrentDateTime(): DateTime {
        return new DateTime('now', new DateTimeZone('UTC'));
    }

    /**
     * Doc: https://www.php.net/manual/en/datetimeimmutable.createfromformat.php
     * 
     * @throws InvalidSyntaxException
     */
    public static function createFromFormat(string $format, string $datetime): DateTime {
        try {
            $dt = DateTime::createFromFormat($format, $datetime);
        } catch (\Throwable $e) {
            throw new InvalidSyntaxException('Error parsing date: ' . $datetime . ' using format: ' . $format . '', 0, $e);
        }

        if ($dt === false) {
            throw new InvalidSyntaxException('Error parsing date: ' . $datetime . ' using format: ' . $format . '');
        }

        return $dt;
    }

    public static function isValidDate(string $format, string $datetime): bool {
        try {
            self::createFromFormat($format, $datetime);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
