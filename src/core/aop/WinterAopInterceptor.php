<?php
/** @noinspection PhpStatementHasEmptyBodyInspection */
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace dev\winterframework\core\aop;

use dev\winterframework\core\aop\ex\AopStopExecution;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\MethodResource;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\AopStereoType;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class WinterAopInterceptor implements AopInterceptor {
    use Wlf4p;

    const INIT = 1;
    const BEGIN = 2;
    const BEGIN_FAILED = 3;
    const COMMIT = 4;
    const FAILED = 6;

    /**
     * @var AopContext[]
     */
    private array $aopContexts = [];

    /**
     * @var WinterAspect[]
     */
    private array $aspects = [];

    private int $curState = 0;

    public function __construct(
        protected ClassResource $class,
        protected MethodResource $method,
        protected ApplicationContextData $ctxData,
        protected ApplicationContext $appCtx
    ) {
        $this->init();
    }

    private function init(): void {
        foreach ($this->method->getAttributes() as $attribute) {
            $name = $attribute::class;
            if (!is_a($name, AopStereoType::class, true)) {
                continue;
            }
            /** @var AopStereoType $attribute */

            $this->aopContexts[] = new AopContext(
                $attribute,
                $this->method->getMethod(),
                $this->appCtx
            );
            $this->aspects[] = $attribute->getAspect();
        }
    }

    public function getClass(): ClassResource {
        return $this->class;
    }

    public function getMethod(): MethodResource {
        return $this->method;
    }

    public function aspectBegin(AopExecutionContext $exCtx): void {
        $skipAll = false;
        foreach ($this->aopContexts as $idx => $aopCtx) {
            if ($skipAll) {
                $exCtx->addSkippedAspect($idx);
                continue;
            }
            $hasException = false;

            try {
                $this->aspects[$idx]->begin($aopCtx, $exCtx);
            } catch (Throwable $e) {
                $exCtx->setBeginFailed();
                $exCtx->setException($e);

                $hasException = true;
                $this->aspectBeginFailed($idx, $exCtx, $e);
                throw $e;
            }

            if ($exCtx->isStopExecution()) {
                if (!$hasException) {
                    $this->aspectBeginFailed($idx, $exCtx, new AopStopExecution('execution stopped explicitly'));
                }
                break;
            } else if ($exCtx->isSkipExecutionMe()) {

                $exCtx->addSkippedAspect($idx);
                $exCtx->setExecutionOk();

            } else if ($exCtx->isSkipExecutionOthers()) {
                $skipAll = true;
                $exCtx->setExecutionOk();
            }
        }
        $this->curState = self::BEGIN;
    }

    private function aspectBeginFailed(int $idx, AopExecutionContext $exCtx, Throwable $e): void {
        for ($i = $idx; $i >= 0; $i--) {
            try {
                $this->aspects[$idx]->beginFailed($this->aopContexts[$i], $exCtx, $e);
            } catch (Throwable $e) {
                self::logException($e, 'Aspect beginFailed handler call failed, for Type "'
                    . get_class($this->aopContexts[$idx]->getStereoType()) . '" on Method '
                    . ReflectionUtil::getFqName($this->aopContexts[$idx]->getMethod())
                    . '. ');
            }
        }

        $this->curState = self::BEGIN_FAILED;
    }

    public function aspectFailed(AopExecutionContext $exCtx, Throwable $e): void {
        foreach ($this->aopContexts as $idx => $aopCtx) {

            if ($exCtx->isSkippedAspect($idx)) {
                continue;
            }

            try {
                $this->aspects[$idx]->failed($aopCtx, $exCtx, $e);
            } catch (Throwable $e) {
                self::logException($e, 'Aspect fail handler call failed, for Type "'
                    . get_class($aopCtx->getStereoType()) . '" on Method '
                    . ReflectionUtil::getFqName($aopCtx->getMethod())
                    . '. ');
            }
        }
        $this->curState = self::FAILED;
    }

    public function aspectCommit(AopExecutionContext $exCtx, mixed $result): void {
        foreach ($this->aspects as $i => $aspect) {

            if ($exCtx->isSkippedAspect($i)) {
                continue;
            }

            try {
                $aspect->commit($this->aopContexts[$i], $exCtx, $result);
            } catch (Throwable $e) {
                $exCtx->setCommitFailed();
                $exCtx->setException($e);

                $this->aspectCommitFailed(
                    $i,
                    $exCtx,
                    $result,
                    $e
                );
            }
        }
        $this->curState = self::COMMIT;
    }

    private function aspectCommitFailed(
        int $idx,
        AopExecutionContext $exCtx,
        mixed $result,
        Throwable $e
    ): void {
        $this->aspects[$idx]->commitFailed($this->aopContexts[$idx], $exCtx, $result, $e);
    }

}