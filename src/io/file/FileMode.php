<?php
declare(strict_types=1);

namespace dev\winterframework\io\file;

use Stringable;

class FileMode implements Stringable {
    public static FileMode $READ_ONLY;
    public static FileMode $READ_PLUS;

    public static FileMode $WRITE_ONLY;
    public static FileMode $WRITE_PLUS;

    public static FileMode $APPEND_ONLY;
    public static FileMode $APPEND_PLUS;

    public static FileMode $X_ONLY;
    public static FileMode $X_PLUS;

    public static FileMode $C_ONLY;
    public static FileMode $C_PLUS;

    public static FileMode $E_ONLY;

    private function __construct(
        private string $mode
    ) {
    }

    public static function init(): void {
        self::$READ_ONLY = new self('r');
        self::$READ_PLUS = new self('r+');

        self::$WRITE_ONLY = new self('w');
        self::$WRITE_PLUS = new self('w+');

        self::$APPEND_ONLY = new self('a');
        self::$APPEND_PLUS = new self('a+');

        self::$X_ONLY = new self('x');
        self::$X_PLUS = new self('x+');

        self::$C_ONLY = new self('c');
        self::$C_PLUS = new self('c+');

        self::$E_ONLY = new self('e');
    }

    public function __toString(): string {
        return $this->mode;
    }

    public function getModeValue(): string {
        return $this->mode;
    }

}

FileMode::init();