<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\web\HttpRequestDispatcher;

interface WebContext {

    public function getDispatcher(): HttpRequestDispatcher;

}