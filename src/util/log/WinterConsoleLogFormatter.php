<?php
declare(strict_types=1);

namespace dev\winterframework\util\log;

use Monolog\Formatter\LineFormatter;

class WinterConsoleLogFormatter extends LineFormatter {
    public function __construct(
        ?string $format = null,
        ?string $dateFormat = null,
        bool $allowInlineLineBreaks = false,
        bool $ignoreEmptyContextAndExtra = false
    ) {
        if (!isset($dateFormat)) {
            $dateFormat = 'Y-m-d H:i:s,u';
        }
        parent::__construct($format, $dateFormat, $allowInlineLineBreaks, $ignoreEmptyContextAndExtra);
    }

    protected function replaceNewlines(string $str): string {
        return $str;
    }
}