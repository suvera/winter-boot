<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\core\aop\AopInterceptorRegistry;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResources;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\stereotype\WinterBootApplication;
use dev\winterframework\type\StringList;

final class ApplicationContextData {
    private BeanProviderContext $beanProvider;
    private ClassResources $resources;
    private PropertyContext $propertyContext;
    private WinterBootApplication $bootConfig;
    private ClassResource $bootApp;
    private ClassResourceScanner $scanner;
    private StringList $attributesToScan;

    private AopInterceptorRegistry $aopRegistry;

    public function getBeanProvider(): BeanProviderContext {
        return $this->beanProvider;
    }

    public function setBeanProvider(BeanProviderContext $beanProvider): void {
        $this->beanProvider = $beanProvider;
    }

    public function getResources(): ClassResources {
        return $this->resources;
    }

    public function setResources(ClassResources $resources): void {
        $this->resources = $resources;
    }

    public function getPropertyContext(): PropertyContext {
        return $this->propertyContext;
    }

    public function setPropertyContext(PropertyContext $propertyContext): void {
        $this->propertyContext = $propertyContext;
    }

    public function getBootConfig(): WinterBootApplication {
        return $this->bootConfig;
    }

    public function setBootConfig(WinterBootApplication $bootConfig): void {
        $this->bootConfig = $bootConfig;
    }

    public function getScanner(): ClassResourceScanner {
        return $this->scanner;
    }

    public function setScanner(ClassResourceScanner $scanner): void {
        $this->scanner = $scanner;
    }

    public function getBootApp(): ClassResource {
        return $this->bootApp;
    }

    public function setBootApp(ClassResource $bootApp): void {
        $this->bootApp = $bootApp;
    }

    public function getAopRegistry(): AopInterceptorRegistry {
        return $this->aopRegistry;
    }

    public function setAopRegistry(AopInterceptorRegistry $aopRegistry): void {
        $this->aopRegistry = $aopRegistry;
    }

    public function getAttributesToScan(): StringList {
        return $this->attributesToScan;
    }

    public function setAttributesToScan(StringList $attributesToScan): void {
        $this->attributesToScan = $attributesToScan;
    }


}