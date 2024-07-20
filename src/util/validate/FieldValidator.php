<?php

declare(strict_types=1);

namespace dev\winterframework\util\validate;

use dev\winterframework\reflection\support\ParameterType;
use dev\winterframework\util\DateUtil;
use dev\winterframework\util\log\Wlf4p;

class FieldValidator {
    use Wlf4p;
    private static ?FieldValidator $instance = null;

    public static function getInstance(): FieldValidator {
        if (self::$instance === null) {
            self::$instance = new FieldValidator();
        }

        return self::$instance;
    }

    public function validate(string $checkName, string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        if (empty($checkName)) {
            return null;
        }
        $methodName = 'validate' . ucfirst($checkName);

        if (method_exists($this, $methodName)) {

            return $this->$methodName($paramName, $paramType, $value, $xtraArgs);
        }

        return 'Invalid validator: ' . $checkName;
    }

    protected function validateRequired(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        if ($value === null || $value === '' || $value === []) {
            return 'Property ' . $paramName . ' is required';
        }
        return null;
    }

    protected function validateNumeric(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        $msg = 'Property ' . $paramName . ' must be numeric';

        if ($paramType->isStringType() || $paramType->isIntegerType() || $paramType->isFloatType()) {
            if (!is_numeric($value)) {
                return $msg;
            }
            return null;
        } else if ($paramType->isArrayType()) {
            if (!is_array($value)) {
                return $msg;
            }

            foreach ($value as $val) {
                if (!is_numeric($val)) {
                    return $msg;
                }
            }
            return null;
        }
        return $msg;
    }

    protected function validateInt(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        return $this->validateNumeric($paramName, $paramType, $value, $xtraArgs);
    }

    protected function validateInteger(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        $msg = 'Property ' . $paramName . ' must be an integer';

        if (isset($xtraArgs['min']) && !isset($xtraArgs['min_range'])) {
            $xtraArgs['min_range'] = $xtraArgs['min'];
        }
        if (isset($xtraArgs['max']) && !isset($xtraArgs['max_range'])) {
            $xtraArgs['max_range'] = $xtraArgs['max'];
        }

        if ($paramType->isStringType() || $paramType->isIntegerType() || $paramType->isFloatType()) {
            if (!filter_var($value, FILTER_VALIDATE_INT, FILTER_REQUIRE_SCALAR, $xtraArgs)) {
                return $msg;
            }
            return null;
        } else if ($paramType->isArrayType()) {
            if (!filter_var($value, FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY, $xtraArgs)) {
                return $msg;
            }
            return null;
        }

        return $msg;
    }

    protected function validateFloat(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        $msg = 'Property ' . $paramName . ' must be a float';
        if (isset($xtraArgs['min']) && !isset($xtraArgs['min_range'])) {
            $xtraArgs['min_range'] = $xtraArgs['min'];
        }
        if (isset($xtraArgs['max']) && !isset($xtraArgs['max_range'])) {
            $xtraArgs['max_range'] = $xtraArgs['max'];
        }

        if ($paramType->isStringType() || $paramType->isIntegerType() || $paramType->isFloatType()) {
            if (!filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_REQUIRE_SCALAR)) {
                return $msg;
            }
            return null;
        } else if ($paramType->isArrayType()) {
            if (!filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_REQUIRE_ARRAY)) {
                return $msg;
            }
            return null;
        }

        return $msg;
    }

    protected function validateBool(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        return $this->validateBoolean($paramName, $paramType, $value, $xtraArgs);
    }

    protected function validateBoolean(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        $msg = 'Property ' . $paramName . ' must be a boolean';
        if ($paramType->isBooleanType()) {
            if (!filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE | FILTER_REQUIRE_SCALAR)) {
                return $msg;
            }
            return null;
        } else if ($paramType->isArrayType()) {
            if (!filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE | FILTER_REQUIRE_ARRAY)) {
                return $msg;
            }
            return null;
        }

        return $msg;
    }

    protected function validateEmail(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        $msg = 'Property ' . $paramName . ' must be an email';
        if ($paramType->isStringType()) {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL, FILTER_REQUIRE_SCALAR)) {
                return $msg;
            }
            return null;
        } else if ($paramType->isArrayType()) {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL, FILTER_REQUIRE_ARRAY)) {
                return $msg;
            }
            return null;
        }

        return $msg;
    }

    protected function validateUrl(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        $msg = 'Property ' . $paramName . ' must be an url';
        if ($paramType->isStringType()) {
            if (!filter_var($value, FILTER_VALIDATE_URL, FILTER_REQUIRE_SCALAR)) {
                return $msg;
            }
            return null;
        } else if ($paramType->isArrayType()) {
            if (!filter_var($value, FILTER_VALIDATE_URL, FILTER_REQUIRE_ARRAY)) {
                return $msg;
            }
            return null;
        }

        return $msg;
    }

    protected function validateIp(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        $msg = 'Property ' . $paramName . ' must be valid IP address';
        if ($paramType->isStringType()) {
            if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_REQUIRE_SCALAR)) {
                return $msg;
            }
            return null;
        } else if ($paramType->isArrayType()) {
            if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_REQUIRE_ARRAY)) {
                return $msg;
            }
            return null;
        }

        return $msg;
    }

    // validate UUID
    protected function validateUuid(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        $msg = 'Property ' . $paramName . ' must be valid UUID';
        if ($paramType->isStringType()) {
            if (!filter_var($value, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i']])) {
                return $msg;
            }
            return null;
        } else if ($paramType->isArrayType()) {
            if (!filter_var($value, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i']])) {
                return $msg;
            }
            return null;
        }

        return $msg;
    }

    // validate Date
    protected function validateDate(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        $msg = 'Property ' . $paramName . ' must be valid Date';
        if (!isset($xtraArgs['format']) || empty($xtraArgs['format'])) {
            $xtraArgs['format'] = DateUtil::DEFATULT_DATE_FORMAT;
        }

        $minDate = null;
        if (isset($xtraArgs['min']) && !empty($xtraArgs['min'])) {
            $minDate = DateUtil::createFromFormat($xtraArgs['min'], $xtraArgs['format']);
        }

        $maxDate = null;
        if (isset($xtraArgs['max']) && !empty($xtraArgs['max'])) {
            $maxDate = DateUtil::createFromFormat($xtraArgs['max'], $xtraArgs['format']);
        }

        if ($paramType->isStringType()) {
            if (!DateUtil::isValidDate($xtraArgs['format'], $value)) {
                return $msg;
            }

            if ($minDate !== null && $value < $minDate) {
                return $msg;
            }

            if ($maxDate !== null && $value > $maxDate) {
                return $msg;
            }
            return null;
        } else if ($paramType->isArrayType()) {
            if (!is_array($value)) {
                return $msg;
            }

            foreach ($value as $val) {
                if (!is_string($val)) {
                    return $msg;
                }
                if (!DateUtil::isValidDate($xtraArgs['format'], $val)) {
                    return $msg;
                }

                if ($minDate !== null && $val < $minDate) {
                    return $msg;
                }

                if ($maxDate !== null && $val > $maxDate) {
                    return $msg;
                }
            }
            return null;
        }
        return $msg;
    }

    protected function validateOneOf(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        if (!isset($xtraArgs['values']) || !is_array($xtraArgs['values'])) {
            return 'Property ' . $paramName . ' must be one of []';
        }
        $msg = 'Property ' . $paramName . ' must be one of ' . implode(',', $xtraArgs['values']);
        if ($paramType->isStringType()) {
            if (!in_array($value, $xtraArgs['values'])) {
                return $msg;
            }
            return null;
        } else if ($paramType->isArrayType()) {
            if (!is_array($value)) {
                return $msg;
            }
            foreach ($value as $val) {
                if (!in_array($val, $xtraArgs['values'])) {
                    return $msg;
                }
            }
            return null;
        }

        return $msg;
    }

    protected function validateRegex(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        if (!isset($xtraArgs['regex']) || empty($xtraArgs['regex'])) {
            return 'Property ' . $paramName . ' must match: No regex provided';
        }
        $msg = 'Property ' . $paramName . ' must match ' . $xtraArgs['regex'];
        if ($paramType->isStringType()) {
            if (!preg_match($xtraArgs['regex'], $value)) {
                return $msg;
            }
            return null;
        } else if ($paramType->isArrayType()) {
            if (!is_array($value)) {
                return $msg;
            }
            foreach ($value as $val) {
                if (!preg_match($xtraArgs['regex'], $val)) {
                    return $msg;
                }
            }
            return null;
        }
    }

    protected function validateAlphanumeric(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        if (!isset($xtraArgs['regex']) || empty($xtraArgs['regex'])) {
            $xtraArgs['regex'] = '/^[a-zA-Z0-9]*$/';
        }
        $msg = $this->validateRegex($paramName, $paramType, $value, $xtraArgs);
        if ($msg !== null) {
            return 'Property ' . $paramName . ' must be alphanumeric';
        }
        return null;
    }

    protected function validateSuffix(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        if (!isset($xtraArgs['suffix']) || empty($xtraArgs['suffix'])) {
            return 'Property ' . $paramName . ' must end with: No suffix provided';
        }
        $msg = 'Property ' . $paramName . ' must end with ' . $xtraArgs['suffix'];
        if ($paramType->isStringType()) {
            if (!str_ends_with($value, $xtraArgs['suffix'])) {
                return $msg;
            }
            return null;
        } else if ($paramType->isArrayType()) {
            if (!is_array($value)) {
                return $msg;
            }
            foreach ($value as $val) {
                if (!str_ends_with($val, $xtraArgs['suffix'])) {
                    return $msg;
                }
            }
            return null;
        }

        return $msg;
    }

    protected function validatePrefix(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        if (!isset($xtraArgs['prefix']) || empty($xtraArgs['prefix'])) {
            return 'Property ' . $paramName . ' must start with: No prefix provided';
        }
        $msg = 'Property ' . $paramName . ' must start with ' . $xtraArgs['prefix'];
        if ($paramType->isStringType()) {
            if (!str_starts_with($value, $xtraArgs['prefix'])) {
                return $msg;
            }
            return null;
        } else if ($paramType->isArrayType()) {
            if (!is_array($value)) {
                return $msg;
            }
            foreach ($value as $val) {
                if (!str_starts_with($val, $xtraArgs['prefix'])) {
                    return $msg;
                }
            }
            return null;
        }
        return $msg;
    }

    protected function validateHttpUrl(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        if (!isset($xtraArgs['prefix']) || empty($xtraArgs['prefix'])) {
            $xtraArgs['prefix'] = 'https://';
        }

        $msg = $this->validatePrefix($paramName, $paramType, $value, $xtraArgs);
        if ($msg !== null) {
            return 'Property ' . $paramName . ' must be a valid http url';
        }

        return $this->validateUrl($paramName, $paramType, $value, $xtraArgs);
    }

    protected function validateLen(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        return $this->validateLength($paramName, $paramType, $value, $xtraArgs);
    }

    protected function validateLength(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        $hasMin = isset($xtraArgs['min']) && is_numeric($xtraArgs['min']);
        $hasMax = isset($xtraArgs['max']) && is_numeric($xtraArgs['max']);
        if (!$hasMin && !$hasMax) {
            return 'Property ' . $paramName . ' must be between min and max length: None configured';
        }

        if ($hasMin && $hasMax) {
            $msg = 'Property ' . $paramName . ' must be between ' . $xtraArgs['min'] . ' and ' . $xtraArgs['max'] . ' characters';
        } else if ($hasMin) {
            $msg = 'Property ' . $paramName . ' must be at least ' . $xtraArgs['min'] . ' characters';
            $xtraArgs['max'] = PHP_INT_MAX;
        } else {
            $msg = 'Property ' . $paramName . ' must be at most ' . $xtraArgs['max'] . ' characters';
            $xtraArgs['min'] = PHP_INT_MIN;
        }

        if ($paramType->isStringType()) {
            if (strlen($value) < $xtraArgs['min'] || strlen($value) > $xtraArgs['max']) {
                return $msg;
            }
            return null;
        } else if ($paramType->isArrayType()) {
            if (!is_array($value)) {
                return $msg;
            }
            foreach ($value as $val) {
                if (strlen($val) < $xtraArgs['min'] || strlen($val) > $xtraArgs['max']) {
                    return $msg;
                }
            }
            return null;
        }

        return $msg;
    }

    protected function validatePassword(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        if (!isset($xtraArgs['regex']) || empty($xtraArgs['regex'])) {
            $xtraArgs['regex'] = '/^[a-zA-Z0-9!@#$%^&*()\-_=+{};:,<.>~`]+$/';
        }
        $msg = $this->validateRegex($paramName, $paramType, $value, $xtraArgs);
        if ($msg !== null) {
            return 'Property ' . $paramName . ' must satisfy password requirements';
        }
        return $msg;
    }

    protected function validateUsername(string $paramName, ParameterType $paramType, mixed $value, array $xtraArgs): ?string {
        if (!isset($xtraArgs['regex']) || empty($xtraArgs['regex'])) {
            $xtraArgs['regex'] = '/^\w+([\-\.]\w+)*$/';
        }
        $msg = $this->validateRegex($paramName, $paramType, $value, $xtraArgs);
        if ($msg !== null) {
            return 'Property ' . $paramName . ' must satisfy Username requirements';
        }
        return null;
    }
}
