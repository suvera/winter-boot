<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

final class WinterApplicationContext extends WinterApplicationContextBuilder {

    public function getId(): string {
        $val = $this->propertyContext->get('winter.application.id', '');
        return !$val ? $this->contextData->getBootApp()->getClass()->getShortName() : $val;
    }

    public function getApplicationName(): string {
        $val = $this->propertyContext->get('winter.application.name', '');
        return !$val ? $this->contextData->getBootApp()->getClass()->getShortName() : $val;
    }

    public function getStartupDate(): int {
        return $this->startTime;
    }


}