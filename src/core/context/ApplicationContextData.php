<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResources;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\stereotype\WinterBootApplication;

final class ApplicationContextData {
    private BeanProviderContext $beanProvider;
    private ClassResources $resources;
    private PropertyContext $propertyContext;
    private WinterBootApplication $bootConfig;
    private ClassResource $bootApp;
    private ClassResourceScanner $scanner;

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

}