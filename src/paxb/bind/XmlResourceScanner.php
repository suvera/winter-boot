<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\bind;

use dev\winterframework\io\file\DirectoryScanner;
use dev\winterframework\paxb\attr\XmlAnyAttribute;
use dev\winterframework\paxb\attr\XmlAnyElement;
use dev\winterframework\paxb\attr\XmlValue;
use dev\winterframework\paxb\ex\PaxbException;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\type\StringSet;

trait XmlResourceScanner {
    protected function scanForPaxbClasses(RefKlass $cls): array {
        $stereoTypes = array_keys(
            DirectoryScanner::scanForPhpClasses(
                dirname(__DIR__) . '/attr',
                'dev\\winterframework\\paxb\\attr'
            )
        );

        $classRes = ClassResourceScanner::getDefaultScanner()->scanClassRecursive(
            $cls->getName(),
            StringSet::ofArray($stereoTypes)
        );

        $resources = [];
        foreach ($classRes as $resource) {
            /** @var ClassResource $resource */
            $name = $resource->getClass()->getName();
            if (!isset($resources[$name])) {
                $resources[$name] = new XmlBeanAnnotations($resource);
            }
        }

        return $resources;
    }

    protected function validate(): void {
        foreach ($this->resources as $classRes) {
            $this->validateConstructor($classRes->getClassResource());

            $this->validateUniqueJaxbTypes($classRes->getClassResource());
        }
    }

    protected function validateConstructor(ClassResource $classRes): void {
        $cls = $classRes->getClass();

        $cons = $cls->getConstructor();
        if ($cons && $cons->getNumberOfRequiredParameters() > 0) {
            throw new PaxbException('Object for class "'
                . ReflectionUtil::getFqName($cls)
                . '"  could not be created as it\'s constructor is '
                . 'defined with required arguments, and XMLMapper does not know about them.'
            );
        }
    }

    protected function validateUniqueJaxbTypes(ClassResource $classRes): void {
        $uniqueAttrClasses = [
            XmlValue::class => 'XmlValue',
            XmlAnyElement::class => 'XmlAnyElement',
            XmlAnyAttribute::class => 'XmlAnyAttribute'
        ];

        $name = $classRes->getClass()->getName();
        foreach ($uniqueAttrClasses as $uniqueCls => $uniqName) {
            $uniqueAttrs = $this->resources[$name]->getAttributesBy($uniqueCls);

            if (count($uniqueAttrs) > 1) {
                throw new PaxbException("#[$uniqName] attribute must be defined only once, "
                    . 'but class "'
                    . ReflectionUtil::getFqName($classRes->getClass())
                    . '"  has it multiple times '
                );
            }
        }
    }
}