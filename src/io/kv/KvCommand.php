<?php
declare(strict_types=1);

namespace dev\winterframework\io\kv;

class KvCommand {
    const PUT = 1;
    const GET = 2;
    const DEL = 3;
    const DEL_ALL = 4;
    const HAS_KEY = 5;

    const INCR = 6;
    const DECR = 7;

    const APPEND = 8;
    const GETSET = 9;

    const STRLEN = 12;

    const KEYS = 13;

    const GET_ALL = 14;

    const PUT_IF_NOT = 15;

    const GETSET_IF_NOT = 16;

    const STATS = 98;
    const PING = 99;
}