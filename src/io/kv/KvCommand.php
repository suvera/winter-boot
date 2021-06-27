<?php
declare(strict_types=1);

namespace dev\winterframework\io\kv;

class KvCommand {
    const PUT = 1;
    const GET = 2;
    const DEL = 3;
    const DEL_ALL = 4;
    const HAS_KEY = 5;

    const PING = 99;
}