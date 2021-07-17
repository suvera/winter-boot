<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

final class WinterApplicationContext extends WinterApplicationContextBuilder {

    public function getId(): string {
        if (isset($GLOBALS['winter.application.id'])) {
            return $GLOBALS['winter.application.id'];
        }
        $val = $this->propertyContext->get('winter.application.id', '');
        return !$val ? $this->contextData->getBootApp()->getClass()->getShortName() : $val;
    }

    public function getApplicationName(): string {
        if (isset($GLOBALS['winter.application.name'])) {
            return $GLOBALS['winter.application.name'];
        }
        $val = $this->propertyContext->get('winter.application.name', '');
        return !$val ? $this->contextData->getBootApp()->getClass()->getShortName() : $val;
    }

    public function getApplicationVersion(): string {
        if (isset($GLOBALS['winter.application.version'])) {
            return $GLOBALS['winter.application.version'];
        }
        $val = $this->propertyContext->get('winter.application.version', '');
        return !$val ? '' : $val;
    }

    public function getStartupDate(): int {
        return $this->startTime;
    }


}