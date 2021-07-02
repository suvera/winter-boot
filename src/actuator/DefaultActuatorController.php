<?php
declare(strict_types=1);

namespace dev\winterframework\actuator;

use dev\winterframework\actuator\stereotype\HealthInformer;
use dev\winterframework\actuator\stereotype\InfoInformer;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\core\System;
use dev\winterframework\core\web\DispatcherServlet;
use dev\winterframework\core\web\route\RequestMappingRegistry;
use dev\winterframework\io\metrics\prometheus\PrometheusMetricRegistry;
use dev\winterframework\stereotype\RestController;
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

        $resp->setBody($result);
        return $resp;
    }

    public function getPrometheus(): ResponseEntity {
        $resp = ResponseEntity::ok()->withContentType(RenderTextFormat::MIME_TYPE);

        /** @var PrometheusMetricRegistry $reg */
        $reg = $this->appCtx->beanByClass(PrometheusMetricRegistry::class);

        $resp->setBody($reg->getFormatted());
        return $resp;
    }

}