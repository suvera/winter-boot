<?php
declare(strict_types=1);

namespace dev\winterframework\ppa;

interface GenerationType {
    const NONE = 0;
    const AUTO_INCREMENT = 1;
    const SEQUENCE = 2;
    const TABLE = 3;
}