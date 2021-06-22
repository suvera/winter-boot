<?php
declare(strict_types=1);

namespace dev\winterframework\core\app;

interface ApplicationReadyEvent {

    public function onApplicationReady(): void;

}