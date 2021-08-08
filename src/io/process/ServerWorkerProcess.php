<?php
declare(strict_types=1);

namespace dev\winterframework\io\process;

use Co;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\io\timer\IdleCheckRegistry;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\util\log\Wlf4p;
use Swoole\Process;
use Throwable;
use function Co\run;

abstract class ServerWorkerProcess extends Process implements AttachableProcess {
    use Wlf4p;

    protected WinterServer $wServer;
    protected ApplicationContext $appCtx;
    protected Process $process;
    protected int $threadId;

    public function __construct(
        WinterServer $wServer,
        ApplicationContext $ctx,
        int $threadId = 0
    ) {
        $this->wServer = $wServer;
        $this->appCtx = $ctx;
        $this->threadId = $threadId;
        parent::__construct(
            $this,
        // $redirect_stdin_and_stdout = true/false,
        // $pipe_type = 0/1/2
        );
    }

    public final function getWServer(): WinterServer {
        return $this->wServer;
    }

    public final function getAppCtx(): ApplicationContext {
        return $this->appCtx;
    }

    public final function getProcess(): Process {
        return $this->process;
    }

    public function getThreadId(): int {
        return $this->threadId;
    }

    private function doAutoWired(): void {
        $cls = new RefKlass($this);

        $properties = $cls->getProperties();
        foreach ($properties as $property) {
            $attributes = $property->getAttributes(Autowired::class);
            if (!$attributes) {
                continue;
            }
            foreach ($attributes as $attribute) {
                /** @var Autowired $autoWired */
                $autoWired = $attribute->newInstance();
                $autoWired->init(RefProperty::getInstance($property));
                ReflectionUtil::performAutoWired($this->appCtx, $autoWired, $this);
            }
        }
    }

    public final function __invoke(Process $me): void {
        $this->process = $me;
        $myPid = getmypid();
        posix_setpgid($myPid, $this->wServer->getServer()->master_pid);
        $this->wServer->addPid($this->getProcessId(), $myPid, $this->getProcessType());

        Co::set([
            'hook_flags' => SWOOLE_HOOK_FILE | SWOOLE_HOOK_SLEEP | SWOOLE_HOOK_TCP
                | SWOOLE_HOOK_SSL | SWOOLE_HOOK_STREAM_FUNCTION | SWOOLE_HOOK_TLS | SWOOLE_HOOK_SOCKETS
                | SWOOLE_HOOK_UDP | SWOOLE_HOOK_UNIX | SWOOLE_HOOK_UDG | SWOOLE_HOOK_PROC
        ]);

        $this->onProcessInvoke();

        /**
         * This is needed to run Timer to check idle connections
         */
        run(function () {

            /** @var IdleCheckRegistry $idleCheck */
            $idleCheck = $this->appCtx->beanByClass(IdleCheckRegistry::class);
            $idleCheck->initialize();

            try {
                $this->doAutoWired();
            } catch (Throwable $e) {
                $this->wServer->shutdown('', $e);
            }

            $this->run();
        });

        // This is a daemon thread, cannot be killed, will be restarted again.
        while (1) {
            sleep(10);
        }
    }

    protected function onProcessInvoke(): void {
        // template
    }

    abstract public function getProcessType(): int;

    abstract public function getProcessId(): string;

    abstract protected function run(): void;
}