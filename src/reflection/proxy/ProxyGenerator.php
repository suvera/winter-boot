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
    const PREFIX = 'Wx61BIkqR5Cl10fY';

    private static ProxyGenerator $instance;

    public static function getDefault(): ProxyGenerator {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function isProxyNeeded(ClassResource $class): bool {
        foreach ($class->getMethods() as $method) {
            foreach ($method->getAttributes() as $attribute) {
                $name = $attribute::class;
                if (is_a($name, AopStereoType::class, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function getProxyClassName(string $cls): string {
        $cls = str_replace('\\', '_', $cls);

        return $cls . '_' . self::PREFIX;
    }

    public function generateClass(ClassResource $class): string {
        $code = "\n";

        $code .= 'use dev\\winterframework\\core\\aop\\AopInterceptorRegistry;' . "\n";
        $code .= 'use dev\\winterframework\\util\\log\\Wlf4p;' . "\n";
        $code .= 'use dev\\winterframework\\stereotype\\Autowired;' . "\n";

        $code .= 'use dev\\winterframework\\core\\aop\\AopExecutionContext;' . "\n";

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

        foreach ($class->getProxyMethods() as $method) {
            /** @var MethodResource $method */
            $code .= $this->generateMethod($method);

            $code .= "\n\n";
        }

        $code .= "}\n";

        //echo $code;
        return $code;
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

        $code .= 'function ' . $m->getName() . '(';
        $params = [];
        foreach ($m->getParameters() as $p) {
            $param = '';
            if ($p->hasType()) {
                $param .= ReflectionUtil::getParamType($p) . ' ';
            }
            $param .= '$' . $p->getName();

            if ($p->isDefaultValueAvailable()) {
                try {
                    $param .= ' = ';
                    if ($p->isDefaultValueConstant()) {
                        $param .= $p->getDefaultValueConstantName();
                    } else {
                        $param .= $p->getDefaultValue();
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

        $return = 'return;';
        $resultId = '';
        $retType = $method->getReturnNamedType();
        if (!$retType->isNoType()) {
            $code .= ': ' . $retType->getName();

            if (!$retType->isVoidType()) {
                $return = 'return $result;';
                $resultId = ' $result =';
            }
        }

        $className = $method->getMethod()->getDeclaringClass()->getName();
        $className = str_replace('\\', '\\\\', $className);
        $methodName = $method->getMethod()->getShortName();

        /**
         *  STEP - 1.  Build Method Body
         */
        $code .= " {\n";

        $code .= <<<EOQ
        \$args = func_get_args();
        \$result = null;
        
        \$interceptor = self::\$aopRegistry->get("$className", "$methodName");
        \$executionCtx = new AopExecutionContext(\$this, \$args); 
        
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
            $return
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
        $code .= "\n    }";

        return $code;
    }
}