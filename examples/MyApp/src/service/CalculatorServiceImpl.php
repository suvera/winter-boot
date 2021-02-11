<?php
declare(strict_types=1);

namespace examples\MyApp\service;

use dev\winterframework\stereotype\Service;

#[Service]
class CalculatorServiceImpl implements CalculatorService {
    public function add(int $a, int $b): int {
        return $a + $b;
    }

    public function subtract(int $a, int $b): int {
        return $a - $b;
    }

    public function multiply(int $a, int $b): int {
        return $a * $b;
    }

    public function divide(int $a, int $b): float {
        // Division by Zero error
        return $a / $b;
    }

}