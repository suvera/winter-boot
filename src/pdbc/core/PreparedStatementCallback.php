<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\core;

use dev\winterframework\pdbc\PreparedStatement;

interface PreparedStatementCallback {

    public function doInPreparedStatement(PreparedStatement $ps): mixed;
    
}