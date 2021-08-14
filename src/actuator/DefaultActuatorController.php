<?php
declare(strict_types=1);

namespace dev\winterframework\actuator;

use dev\winterframework\actuator\stereotype\HealthInformer;
use dev\winterframework\actuator\stereotype\InfoInformer;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\core\System;
use dev\winterframework\core\web\DispatcherServlet;
use dev\winterframework\core\web\route\RequestMappingRegistry;
use dev\winterframework\io\kv\KvTemplate;
use dev\winterframework\io\metrics\prometheus\PrometheusMetricRegistry;
use dev\winterframework\io\process\ProcessUtil;
use dev\winterframework\io\queue\QueueSharedTemplate;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\reflection\support\ParameterType;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\stereotype\web\RequestParam;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\MediaType;
use Prometheus\RenderTextFormat;

#[RestController]
class DefaultActuatorController implements ActuatorController {

    /** @noinspection PhpPropertyOnlyWrittenInspection */
    public function __construct(
        private ApplicationContextData $ctxData,
        private ApplicationContext $appCtx,
        private RequestMappingRegistry $requestMapping,
        private DispatcherServlet $dispatcherServlet,
    ) {
    }

    public function getBeans(): ResponseEntity {
        $resp = ResponseEntity::ok()->withContentType(MediaType::APPLICATION_JSON);

        /** @var WinterBeanProviderContext $beanProvider */
        $beanProvider = $this->ctxData->getBeanProvider();

        $result = [];
        if (method_exists($beanProvider, 'getBeanClassFactory')) {
            $result = array_keys($beanProvider->getBeanClassFactory());
        }

        $resp->setBody($result);
        return $resp;
    }

    public function getConfigProps(): ResponseEntity {
        $resp = ResponseEntity::ok()->withContentType(MediaType::APPLICATION_JSON);

        $result = $this->ctxData->getPropertyContext()->getAll();

        $resp->setBody($result);
        return $resp;
    }

    public function getEnv(): ResponseEntity {
        $resp = ResponseEntity::ok()->withContentType(MediaType::APPLICATION_JSON);

        $result = System::getEnv();

        $resp->setBody($result);
        return $resp;
    }

    public function getHealth(): ResponseEntity {
        $resp = ResponseEntity::ok()->withContentType(MediaType::APPLICATION_JSON);
        $health = Health::up();

        $array = $this->ctxData->getResources()->getClassesByAttribute(HealthInformer::class);
        foreach ($array as $classResource) {
            /** @var HealthIndicator $bean */
            $bean = $this->appCtx->beanByClass($classResource->getClass()->getName());

            $health = $bean->health();
            if ($health->getStatus() !== Status::UP) {
                break;
            }
        }

        $resp->setBody($health);
        return $resp;
    }

    public function getInfo(): ResponseEntity {
        $resp = ResponseEntity::ok()->withContentType(MediaType::APPLICATION_JSON);

        $info = new InfoBuilder();
        $array = $this->ctxData->getResources()->getClassesByAttribute(InfoInformer::class);
        foreach ($array as $classResource) {
            /** @var InfoContributor $bean */
            $bean = $this->appCtx->beanByClass($classResource->getClass()->getName());

            $bean->contribute($info);
        }

        $resp->setBody($info);
        return $resp;
    }

    public function getMappings(): ResponseEntity {
        $resp = ResponseEntity::ok()->withContentType(MediaType::APPLICATION_JSON);

        $result = $this->requestMapping->getAll();

        $arr = [];
        foreach ($result as $mapping) {
            /** @var RequestMapping $mapping */
            if ($mapping->isRestController()) {
                continue;
            }

            $reqBody = $mapping->getRequestBody();
            $bodyType = null;
            if ($reqBody) {
                $bodyType = $reqBody->getVariableType();
                if ($bodyType !== 'string') {
                    $bodyType = ReflectionUtil::classToPropertiesTemplate($bodyType);
                }
            }
            $reqParams = $mapping->getRequestParams();
            $params = [];
            foreach ($reqParams as $reqParam) {
                /** @var RequestParam $reqParam */
                $params[$reqParam->name] = implode('|', $reqParam->getVariableType()->getNames());
            }

            $retType = ParameterType::fromType($mapping->getRefOwner()->getReturnType());

            foreach ($mapping->getUriPaths() as $uriPath) {
                $normalized = $uriPath->getRaw();

                $arr[] = [
                    'path' => $normalized,
                    'method' => array_values($mapping->method),
                    'name' => $mapping->name,
                    'consumes' => $mapping->consumes,
                    'produces' => $mapping->produces,
                    'request' => [
                        'body' => $bodyType,
                        'params' => $params
                    ],
                    'response' => implode('|', $retType->getNames())
                ];
            }
        }

        $resp->setBody([
            'total' => count($arr),
            'mappings' => $arr
        ]);
        return $resp;
    }

    public function getPrometheus(): ResponseEntity {
        $resp = ResponseEntity::ok()->withContentType(RenderTextFormat::MIME_TYPE);

        /** @var PrometheusMetricRegistry $reg */
        $reg = $this->appCtx->beanByClass(PrometheusMetricRegistry::class);

        $resp->setBody($reg->getFormatted());
        return $resp;
    }

    public function getScheduledTasks(): ResponseEntity {
        $resp = ResponseEntity::ok()->withContentType(MediaType::APPLICATION_JSON);
        /** @var WinterServer $wServer */
        $wServer = $this->appCtx->beanByClass(WinterServer::class);

        $tables = $wServer->getScheduledTables();
        $arr = [];

        foreach ($tables as $workerId => $table) {

            foreach ($table as $id => $row) {
                $bucket = 'custom';
                $interval = 0;
                if ($row['fixedDelay'] > 0) {
                    $bucket = 'fixedDelay';
                    $interval = $row['fixedDelay'];
                } else if ($row['fixedRate'] > 0) {
                    $bucket = 'fixedRate';
                    $interval = $row['fixedRate'];
                }
                if (!isset($arr[$bucket])) {
                    $arr[$bucket] = [];
                }

                $arr[$bucket][] = [
                    'runnable' => [
                        'target' => $row['className'],
                        'method' => $row['methodName']
                    ],
                    'initialDelay' => [],
                    'interval' => $interval,
                    'lastRun' => $row['lastRun'],
                    'nextRun' => $row['nextRun'],
                    'inProgress' => $row['inProgress'],
                    'id' => $id,
                    'workerId' => $workerId
                ];
            }
        }

        $resp->setBody($arr ?: '{}');
        return $resp;
    }

    public function getHeapDump(): ResponseEntity {
        $resp = ResponseEntity::ok()->withContentType(MediaType::APPLICATION_JSON);
        /** @var WinterServer $wServer */
        $wServer = $this->appCtx->beanByClass(WinterServer::class);

        $table = $wServer->getPidManager()->getPidTable();
        $arr = [];

        foreach ($table as $id => $row) {

            $bucket = ProcessUtil::getProcessTypeName($row['type']);
            $pid = intval($row['pid']);

            if (!isset($arr[$bucket])) {
                $arr[$bucket] = [];
            }

            $info = [
                'pid' => $pid,
                'winterId' => $id
            ];

            $pi = ProcessUtil::getPidInfo($pid);
            if ($pi) {
                $info = array_merge($info, $pi->getArray());
            }

            $arr[$bucket][] = $info;
        }

        if ($this->appCtx->getPropertyInt('winter.kv.port', 0) > 0) {
            /** @var KvTemplate $tpl */
            $tpl = $this->appCtx->beanByClass(KvTemplate::class);
            $arr['kv-server-stats'] = $tpl->stats();
        }

        if ($this->appCtx->getPropertyInt('winter.queue.port', 0) > 0) {
            /** @var QueueSharedTemplate $tpl */
            $tpl = $this->appCtx->beanByClass(QueueSharedTemplate::class);
            $arr['queue-server-stats'] = $tpl->stats();
        }

        $resp->setBody($arr ?: '{}');
        return $resp;
    }


}