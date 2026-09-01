<?php
declare(strict_types=1);

namespace dev\winterframework\io\timer;

use dev\winterframework\util\log\Wlf4p;
use Swoole\Timer;
use Throwable;

class IdleCheckRegistry {
    use Wlf4p;

    private array $callbacks = [];
    private array $initialized = [];
    private bool $timerEnabled = false;
    private int $timerId;

    public function __construct() {
        $this->timerEnabled = extension_loaded('swoole');
    }

    public function register(callable $callback): void {
        if (!$this->timerEnabled) {
            self::logWarning('Timer functionality is not available, IdleCheckRegistry does not work!');
            return;
        }
        $this->initialize();

        $this->callbacks[] = $callback;
    }

    public function initialize(): void {
        if (!$this->timerEnabled) {
            self::logWarning('Timer functionality is not available, IdleCheckRegistry does not work!');
            return;
        }

        if (isset($this->initialized[getmypid()])) {
            return;
        }

        /**
         * Non blocking code is recommended, do not use regular sleep() inside callbacks
         */
        $timerId = Timer::tick(mt_rand(20000, 30000), [$this, 'checkIdleIo']);
        if ($timerId === false) {
            self::logError('Failed to start IdleCheckRegistry timer');
            return;
        }

        $this->timerId = $timerId;
        $this->initialized[getmypid()] = true;
    }

    public function checkIdleIo(): void {
        //self::logInfo('checking idle connections ... callbacks: ' . count($this->callbacks));
        foreach ($this->callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                self::logException($e);
            }
        }
    }

    public function clear(): void {
        if (!$this->timerEnabled) {
            return;
        }
        if ($this->timerId) {
            Timer::clear($this->timerId);
            unset($this->initialized[getmypid()]);
        }
    }

    public static function clearAll(): void {
        if (!extension_loaded('swoole')) {
            return;
        }
        Timer::clearAll();
    }

}