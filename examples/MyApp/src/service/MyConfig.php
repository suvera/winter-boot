<?php
declare(strict_types=1);

namespace examples\MyApp\service;

use dev\winterframework\stereotype\Configuration;
use dev\winterframework\stereotype\Value;

#[Configuration]
class MyConfig {

    #[Value('${myApp.value1}')]
    private string $textValue;

    #[Value('${myApp.value2}')]
    private int $intValue;

    #[Value('${myApp.value3}')]
    private bool $boolValue;

    #[Value('${myApp.value4}')]
    private float $floatValue;



    public function getTextValue(): string {
        return $this->textValue;
    }
    public function getIntValue(): int {
        return $this->intValue;
    }
    public function isBoolValue(): bool {
        return $this->boolValue;
    }
    public function getFloatValue(): float {
        return $this->floatValue;
    }

}