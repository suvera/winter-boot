<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\core;

class BindType {
    const INTEGER = 1;
    const FLOAT = 2;
    const STRING = 3;
    const BOOL = 4;
    const DATE = 5;
    const BLOB = 6;
    const CLOB = 7;
    const NULL = 8;
}