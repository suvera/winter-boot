<?php
declare(strict_types=1);

namespace dev\winterframework\paxb;

use LibXMLError;

class LibxmlUtil {

    public static function init(): void {
        libxml_use_internal_errors(true);
    }

    public static function hasXmlError(): bool {
        return count(libxml_get_errors()) > 0;
    }

    public static function getXmlError(): string {
        $errors = libxml_get_errors();

        $err = '';
        foreach ($errors as $error) {
            $err = self::errorToString($error);
            $err .= PHP_EOL;
        }

        libxml_clear_errors();
        return $err;
    }

    public static function errorToString(LibXMLError $error): string {
        $return = '';
        $return .= str_repeat('-', $error->column) . "^\n";

        $return .= match ($error->level) {
            LIBXML_ERR_WARNING => "Warning $error->code: ",
            LIBXML_ERR_ERROR => "Error $error->code: ",
            LIBXML_ERR_FATAL => "Fatal Error $error->code: ",
        };

        $return .= trim($error->message) .
            ",  Line: $error->line" .
            ",  Column: $error->column";

//        if ($error->file) {
//            $return .= "\n  File: $error->file";
//        }

        return $return . '. ';
    }
}