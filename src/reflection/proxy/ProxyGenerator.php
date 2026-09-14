<?php
declare(strict_types=1);

namespace dev\winterframework\reflection\proxy;

use dev\winterframework\exception\InvalidSyntaxException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\MethodResource;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\aop\AopStereoType;
use ReflectionException;

final class ProxyGenerator {
    const PREFIX = 'Pr0xy';

    private static ProxyGenerator $instance;

    public static function getDefault(): ProxyGenerator {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function getProxyClassName(string $cls): string {
        $cls = str_replace('\\', '_', $cls);

        return $cls . '' . self::PREFIX;
    }

    public function generateClass(ClassResource $class): string {
        $code = "\n";

        $code .= 'use dev\\winterframework\\core\\aop\\AopInterceptorRegistry;' . "\n";
        $code .= 'use dev\\winterframework\\util\\log\\Wlf4p;' . "\n";
        $code .= 'use dev\\winterframework\\stereotype\\Autowired;' . "\n";
        $code .= 'use dev\\winterframework\\core\\context\\ApplicationContext;' . "\n";

        $code .= 'use dev\\winterframework\\core\\aop\\AopExecutionContext;' . "\n";
        $code .= 'use dev\\winterframework\\task\\async\\AsyncTaskPoolExecutor;' . "\n";

        //$code .= 'use Throwable;' . "\n";
        $code .= 'use dev\\winterframework\\core\\aop\\ex\\AopStopExecution;' . "\n";
        $code .= 'use dev\\winterframework\\core\\aop\\ex\\AopSkipExecutionForOthers;' . "\n";
        $code .= 'use dev\\winterframework\\core\\aop\\ex\\AopSkipExecutionForMe;' . "\n";

        $code .= "\n";

        $code .= 'class ' . self::getProxyClassName($class->getClass()->getName())
            . ' extends ' . ReflectionUtil::getFqName($class->getClass()) . ' {' . "\n";

        $code .= "    use Wlf4p;\n\n";

        /**
         * 1. Add Autowired
         */
        $code .= "    #[Autowired]\n";
        $code .= "    private static AopInterceptorRegistry \$aopRegistry;\n\n";

        $code .= "    #[Autowired]\n";
        $code .= "    private static ApplicationContext \$appCtx;\n\n";

        foreach ($class->getProxyMethods() as $method) {
            /** @var MethodResource $method */
            $code .= $this->generateMethod($method);

            $code .= "\n\n";
        }

        $code .= "}\n";

        //echo $code;
        return $code;
    }

    // WB-014: render a default value as a valid PHP literal.
    private static function exportDefaultValue(mixed $value): string {
        if ($value instanceof \UnitEnum) {
            return '\\' . $value::class . '::' . $value->name;
        }
        return var_export($value, true);
    }

    public function generateMethod(MethodResource $method): string {
        $code = '    ';
        $m = $method->getMethod();

        /**
         *  STEP - 1.  Build Method Definition
         */
        if ($m->isProtected()
            || $m->isPrivate()
            || $m->isAbstract()
            || $m->isConstructor()
            || $m->isFinal()
        ) {
            throw new InvalidSyntaxException('Could not generate Proxy for Private/Protected, '
                . 'Abstract, Final, Constructor method '
                . ReflectionUtil::getFqName($method));
        }

        $code .= 'public ';
        if ($m->isStatic()) {
            $code .= 'static ';
        }

        $methodNameCode = 'function ' . $m->getShortName();
        $code .= $methodNameCode . '(';
        $params = [];
        foreach ($m->getParameters() as $p) {
            $param = '';
            if ($p->hasType()) {
                $param .= ReflectionUtil::getParamType($p) . ' ';
            }
            // WB-014: preserve by-reference and variadic signatures.
            if ($p->isPassedByReference()) {
                $param .= '&';
            }
            if ($p->isVariadic()) {
                $param .= '...';
            }
            $param .= '$' . $p->getName();

            if ($p->isDefaultValueAvailable() && !$p->isVariadic()) {
                try {
                    $param .= ' = ';
                    if ($p->isDefaultValueConstant()) {
                        $param .= $p->getDefaultValueConstantName();
                    } else {
                        // WB-014: emit defaults as PHP literals, not raw values.
                        $param .= self::exportDefaultValue($p->getDefaultValue());
                    }
                } catch (ReflectionException $e) {
                    throw new WinterException('Could not create Proxy method '
                        . ReflectionUtil::getFqName($m)
                        , 0, $e);
                }

            }

            $params[] = $param;
        }
        $code .= implode(', ', $params) . ')';

        $retType = $method->getReturnNamedType();
        if (!$retType->isNoType()) {
            $code .= ': ' . $retType->getName();
        }
        $hasReturnType = (!$retType->isNoType() && !$retType->isVoidType());

        /**
         *  STEP - 1.  Build Method Body
         */
        $code .= " {\n";
        $methodDefBegin = $code;
        $methodPrefix = 'Original';

        if ($method->isAsyncProxy()) {
            $origMethodName = $m->getShortName() . $methodPrefix;
            $code .= $this->buildAsyncProxyMethodCode($method, $origMethodName, $hasReturnType);
            $code .= "\n    }\n";

            $code .= str_replace($methodNameCode, 'function ' . $origMethodName, $methodDefBegin);
        }

        if ($method->isAopProxy()) {
            $code .= $this->buildAopProxyMethodCode($method);
        } else {
            $code .= $this->buildProxyMethodCode($method, $hasReturnType);
        }

        $code .= "\n    }";

        //echo $code . "\n";
        return $code;
    }

    protected function buildAsyncProxyMethodCode(
        MethodResource $method,
        string $origMethodName,
        bool $hasReturnType
    ): string {
        $className = $method->getMethod()->getDeclaringClass()->getName();
        $className = str_replace('\\', '\\\\', $className);

        $return = $hasReturnType ? 'return null' : 'return';

        return <<<EOQ
        \$args = func_get_args();
        
        \$executor = self::\$appCtx->beanByClass(AsyncTaskPoolExecutor::class);
        \$executor->enqueue("$className", "$origMethodName", \$args);
           
        $return;
EOQ;
    }

    protected function buildProxyMethodCode(
        MethodResource $method,
        bool $hasReturnType
    ): string {
        $methodName = $method->getMethod()->getShortName();

        $return = $hasReturnType ? 'return' : '';

        return <<<EOQ
        \$args = func_get_args();
        $return parent::$methodName(...\$args);
        
EOQ;
    }

    // WB-002: actual class/method/attributes label for failure context.
    private function aopContextLabel(MethodResource $method): string {
        $names = [];
        foreach ($method->getAttributes() as $attribute) {
            if ($attribute instanceof AopStereoType) {
                $names[] = (new \ReflectionClass($attribute))->getShortName();
            }
        }
        $label = $method->getMethod()->getDeclaringClass()->getName()
            . '::' . $method->getMethod()->getShortName()
            . '() [' . implode(',', $names) . ']';
        return addcslashes($label, "\\'");
    }

    protected function buildAopProxyMethodCode(
        MethodResource $method
    ): string {
        $return = 'return;';
        $resultId = '';
        $retType = $method->getReturnNamedType();
        if (!$retType->isNoType() && !$retType->isVoidType()) {
            $return = 'return $result;';
            $resultId = ' $result =';
        }

        $className = $method->getMethod()->getDeclaringClass()->getName();
        $className = str_replace('\\', '\\\\', $className);
        $methodName = $method->getMethod()->getShortName();
        $contextLabel = $this->aopContextLabel($method);

        return <<<EOQ
        \$args = func_get_args();
        \$result = null;
        
        \$interceptor = self::\$aopRegistry->get("$className", "$methodName");
        \$executionCtx = new AopExecutionContext(\$this, \$args); 
        
        // WB-002: execptions already handled by aspectBegin()
        //  - setException() and setBeginFailed() already handled by aspectBegin() method.
        \$interceptor->aspectBegin(\$executionCtx);
        \$executionCtx->setBeginDone();
        
        if (\$executionCtx->isStopExecution()) {
           $resultId \$executionCtx->getResult();
            $return
        }
        
        try {
          $resultId parent::$methodName(...\$args);
           
           \$executionCtx->setSuccess();
           \$executionCtx->setResult(\$result);
           
        } catch (Throwable \$e) {
            \$executionCtx->setException(\$e);
            \$executionCtx->setFailed();

            \$interceptor->aspectFailed(\$executionCtx, \$e);
            // WB-002: log failure with context, rethrow original unchanged.
            self::logException(\$e, 'AOP invocation failed on $contextLabel');
            throw \$e;
        }
        
        try {
            \$interceptor->aspectCommit(\$executionCtx, \$result);
            \$executionCtx->setSuccess();
            
        } catch (Throwable \$e) {
            \$executionCtx->setException(\$e);
            \$executionCtx->setCommitFailed();
            
            self::logException(\$e);
        }
        $return
        
EOQ;
    }
}