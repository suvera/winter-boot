<?php
declare(strict_types=1);

namespace dev\winterframework\util;

use Throwable;

class Debug {
    public static function getBacktrace(): string {
        $traces = debug_backtrace();
        // Frame 0 is this helper itself.
        unset($traces[0]);
        return self::buildBacktrace($traces);
    }

    private static function buildBacktrace(array $traces, string $message = ''): string {

        $str = $message . "Call Stack:" . PHP_EOL;
        $i = 1;
        foreach ($traces as $trace) {
            $str .= sprintf("#%02d: ", $i++);
            if (isset($trace["file"])) {
                if (isset($trace["class"])) {
                    $str .= $trace["class"] . $trace["type"];
                }
                $func = $trace["function"];
                $str .= $func . " (in " . basename($trace["file"]) . " line " . $trace["line"] . ")" . PHP_EOL;
            } else {
                $str .= "(unknown)" . PHP_EOL;
            }
        }
        return $str;
    }

    public static function exceptionBacktrace(Throwable $ex): string {
        $message = $ex->getMessage() . ' on File ' . $ex->getFile() . ' at Line '
            . $ex->getLine() . " " . PHP_EOL;
        return self::buildBacktrace($ex->getTrace(), $message);
    }
}