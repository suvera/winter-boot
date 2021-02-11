<?php
declare(strict_types=1);

namespace examples\MyApp\service;

interface CalculatorService {

    public function add(int $a, int $b): int;

    public function subtract(int $a, int $b): int;

    public function multiply(int $a, int $b): int;

    public function divide(int $a, int $b): float;

}