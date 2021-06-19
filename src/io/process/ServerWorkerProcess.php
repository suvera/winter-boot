<?php
declare(strict_types=1);

namespace dev\winterframework\io\process;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\io\timer\IdleCheckRegistry;
use Swoole\Process;

abstract class ServerWorkerProcess extends Process implements AttachableProcess {

    protected WinterServer $wServer;
    protected ApplicationContext $appCtx;
    protected Process $process;

    public function __construct(WinterServer $wServer, ApplicationContext $ctx) {
        $this->wServer = $wServer;
        $this->appCtx = $ctx;
        parent::__construct(
            $this,
        // $redirect_stdin_and_stdout = true/false,
        // $pipe_type = 0/1/2
        );
    }

    public function getWServer(): WinterServer {
        return $this->wServer;
    }

    public function getAppCtx(): ApplicationContext {
        return $this->appCtx;
    }

    public function getProcess(): Process {
        return $this->process;
    }

    public function __invoke(Process $me): void {
        $this->process = $me;

        /**
         * This is needed to run Timer to check idle connections
         */
        \Co\run(function () {
            /** @var IdleCheckRegistry $idleCheck */
            $idleCheck = $this->appCtx->beanByClass(IdleCheckRegistry::class);
            $idleCheck->initialize();

            $this->run();
        });

    }

    abstract protected function run(): void;
}