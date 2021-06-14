<?php
declare(strict_types=1);

namespace dev\winterframework\util;

use DateTime;
use DateTimeZone;

class DateUtil {

    public static function getCurrentDateTime(): DateTime {
        return new DateTime('now', new DateTimeZone('UTC'));
    }

}