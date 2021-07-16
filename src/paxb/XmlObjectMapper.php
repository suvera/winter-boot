<?php
declare(strict_types=1);

namespace dev\winterframework\paxb;

use dev\winterframework\io\file\File;
use dev\winterframework\io\file\FileStream;
use dev\winterframework\io\file\InMemoryFileSystem;
use dev\winterframework\io\ObjectMapper;
use dev\winterframework\paxb\bind\ObjectToXmlWriter;
use dev\winterframework\paxb\bind\XmlReaderObjectCreator;
use dev\winterframework\paxb\bind\XmlSchemaCreator;
use XMLReader;
use XMLWriter;

class XmlObjectMapper implements ObjectMapper {

    protected array $properties = [
        XMLReader::LOADDTD => false,
        XMLReader::DEFAULTATTRS => false,
        XMLReader::VALIDATE => false,
        XMLReader::SUBST_ENTITIES => false
    ];

    protected array $libXmlFlags = [
        LIBXML_SCHEMA_CREATE => false,
        LIBXML_BIGLINES => false,
        LIBXML_COMPACT => false,
        LIBXML_DTDATTR => false,
        LIBXML_DTDLOAD => false,
        LIBXML_DTDVALID => false,
        LIBXML_NOBLANKS => false,
        LIBXML_NOENT => false,
        LIBXML_NOERROR => false,
        LIBXML_NOWARNING => false,
        LIBXML_XINCLUDE => false,
        LIBXML_NSCLEAN => false,
        LIBXML_NOCDATA => false,
        LIBXML_NONET => false,
        LIBXML_PEDANTIC => false,
        LIBXML_PARSEHUGE => false
    ];

    /**
     *  XMLReader::LOADDTD
     *  XMLReader::DEFAULTATTRS
     *  XMLReader::VALIDATE
     *  XMLReader::SUBST_ENTITIES
     *
     * @param int $property
     */
    public function setParserProperty(int $property): void {
        if (isset($this->properties[$property])) {
            $this->properties[$property] = true;
        }
    }

    /**
     * @param int[] $properties
     */
    public function setParserProperties(array $properties): void {
        foreach ($this->properties as $key => $flag) {
            $this->properties[$flag] = false;
        }
        foreach ($properties as $property) {
            if (is_int($property)) {
                $this->setParserProperty($property);
            }
        }
    }

    /**
     *  LIBXML_* constants
     *
     * @param int $property
     */
    public function setLibxmlProperty(int $property): void {
        if (isset($this->libXmlFlags[$property])) {
            $this->libXmlFlags[$property] = true;
        }
    }

    /**
     * @param int[] $properties
     */
    public function setLibxmlProperties(array $properties): void {
        foreach ($this->libXmlFlags as $key => $flag) {
            $this->libXmlFlags[$flag] = false;
        }
        foreach ($properties as $property) {
            if (is_int($property)) {
                $this->setLibxmlProperty($property);
            }
        }
    }

    protected function getLibxmlFlagValue(): int {
        $ret = 0;
        foreach ($this->libXmlFlags as $key => $flag) {
            if ($flag) {
                $ret = $ret | $key;
            }
        }

        return $ret;
    }

    protected function setParserOptions(XMLReader $reader): void {
        foreach ($this->properties as $key => $flag) {
            if ($flag) {
                $reader->setParserProperty($key, $flag);
            }
        }
    }

    public function readValue(string $xml, string $class, bool $validate = false): object {
        /** @var XMLReader $reader */
        $reader = XMLReader::xml($xml, null, $this->getLibxmlFlagValue());
        $this->setParserOptions($reader);

        return $this->parseToObject($reader, $class, $validate);
    }

    public function readValueFromFile(
        FileStream|File $file,
        string $class,
        bool $validate = false
    ): object {
        /** @var XMLReader $reader */
        $path = ($file instanceof FileStream) ? $file->getFile()->getRealPath() : $file->getRealPath();
        $reader = XMLReader::open($path, null, $this->getLibxmlFlagValue());

        $this->setParserOptions($reader);

        return $this->parseToObject($reader, $class, $validate);
    }

    protected function parseToObject(XMLReader $reader, string $class, bool $validate): object {
        $resources = [];
        if ($validate) {
            $creator = new XmlSchemaCreator($class);
            $xsd = $creator->buildXsd();
            //echo "\n$xsd\n";
            $file = InMemoryFileSystem::createFile($xsd);
            $reader->setSchema($file->getRealPath());
            $resources = $creator->getResources();
        }

        $creator = new XmlReaderObjectCreator($reader, $class, $resources);
        return $creator->create($validate);
    }

    public function writeValueToFile(object $object, string $filePath): void {
        $writer = new XMLWriter();
        $writer->openURI($filePath);
        $writer->setIndent(true);
        $writer->startDocument("1.0", 'UTF-8');

        $w = new ObjectToXmlWriter($writer, $object);
        $w->create();

        $writer->endDocument();
        $writer->flush();
    }

    public function writeValue(object $object): string {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->startDocument("1.0", 'UTF-8');

        $w = new ObjectToXmlWriter($writer, $object);
        $w->create();

        $writer->endDocument();
        return $writer->outputMemory();
    }

}