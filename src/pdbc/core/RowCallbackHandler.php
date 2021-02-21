<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\core;

use dev\winterframework\pdbc\ResultSet;

interface RowCallbackHandler {

    /**
     * Process Single Row
     *
     * @param ResultSet $rs
     */
    public function processRow(ResultSet $rs): void;
}