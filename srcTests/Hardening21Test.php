<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\web\DispatcherServlet;
use dev\winterframework\core\web\format\DefaultResponseRenderer;
use dev\winterframework\paxb\XmlObjectMapper;
use dev\winterframework\reflection\support\ParameterType;
use dev\winterframework\web\http\HttpHeaders;
use dev\winterframework\web\http\HttpUploadedFile;
use winterBootTests\Support\TestCase;

/**
 * 2.1.0 hardening: web + data input surface.
 *
 * Each test exercises a real input path with attacker-controlled data and
 * asserts the framework neutralises it. All fixes are backward-compatible:
 * legitimate values behave exactly as before.
 */
final class Hardening21Test extends TestCase {

    // WB-2.1-01: response-splitting via CRLF in header values must fail fast.
    public function testHeaderValueWithCrlfRejected(): void {
        $headers = new HttpHeaders();
        $this->assertThrows(\InvalidArgumentException::class, function () use ($headers) {
            $headers->add('X-Next', "ok\r\nInjected: evil");
        });
        $this->assertThrows(\InvalidArgumentException::class, function () use ($headers) {
            $headers->set('X-Next', "ok\nInjected: evil");
        });
    }

    // WB-2.1-01: CRLF in header names must fail fast as well.
    public function testHeaderNameWithCrlfRejected(): void {
        $headers = new HttpHeaders();
        $this->assertThrows(\InvalidArgumentException::class, function () use ($headers) {
            $headers->add("X-Bad\r\nName", 'value');
        });
    }

    // WB-2.1-01: clean header values keep working.
    public function testCleanHeaderValueAccepted(): void {
        $headers = new HttpHeaders();
        $headers->add('X-Next', '/users/42?tab=info');
        $this->assertSame(['/users/42?tab=info'], $headers->get('X-Next'));
    }

    // WB-2.1-02: consumes matching must be exact, not a substring match.
    public function testConsumesMatchingIsExact(): void {
        $method = new \ReflectionMethod(DispatcherServlet::class, 'isContentTypeSupported');
        $method->setAccessible(true);
        $servlet = (new \ReflectionClass(DispatcherServlet::class))->newInstanceWithoutConstructor();

        $this->assertTrue($method->invoke($servlet, 'application/json', 'application/json'));
        $this->assertTrue($method->invoke($servlet, 'application/json; charset=utf-8', 'application/json'));
        $this->assertTrue($method->invoke($servlet, 'Application/JSON', 'application/json'));
        $this->assertFalse($method->invoke($servlet, 'application/json-malicious', 'application/json'));
        $this->assertFalse($method->invoke($servlet, 'text/plain', 'application/json'));
        $this->assertFalse($method->invoke($servlet, '', 'application/json'));
    }

    // WB-2.1-03: client-controlled MAX_FILE_SIZE must not be reflected verbatim.
    public function testUploadMaxFileSizeNotReflected(): void {
        $_POST['MAX_FILE_SIZE'] = '<script>alert(1)</script>';
        try {
            $file = HttpUploadedFile::fromArray(['error' => UPLOAD_ERR_FORM_SIZE]);
            $text = $file->getErrorText();
            $this->assertFalse(str_contains($text, '<script>'), 'raw form value leaked into error text');
        } finally {
            unset($_POST['MAX_FILE_SIZE']);
        }
    }

    // WB-2.1-03: numeric MAX_FILE_SIZE still reported.
    public function testUploadMaxFileSizeNumericStillReported(): void {
        $_POST['MAX_FILE_SIZE'] = '2097152';
        try {
            $file = HttpUploadedFile::fromArray(['error' => UPLOAD_ERR_FORM_SIZE]);
            $this->assertTrue(str_contains($file->getErrorText(), '2097152'));
        } finally {
            unset($_POST['MAX_FILE_SIZE']);
        }
    }

    // WB-2.1-04: request URIs echoed into 404 messages/logs must carry no controls.
    public function testErrorUriSanitized(): void {
        $method = new \ReflectionMethod(DispatcherServlet::class, 'sanitizeUriForError');
        $method->setAccessible(true);
        $servlet = (new \ReflectionClass(DispatcherServlet::class))->newInstanceWithoutConstructor();

        $out = $method->invoke($servlet, "users/1\r\nX-Injected: evil\x00tail");
        $this->assertFalse(str_contains($out, "\r"));
        $this->assertFalse(str_contains($out, "\n"));
        $this->assertFalse(str_contains($out, "\x00"));

        $long = str_repeat('a', 2000);
        $this->assertTrue(strlen($method->invoke($servlet, $long)) <= 512);
    }

    // WB-2.1-05: the XML parser must always forbid network access (defence in depth).
    public function testXmlParserForcesNoNet(): void {
        $mapper = new XmlObjectMapper();
        $method = new \ReflectionMethod(XmlObjectMapper::class, 'getLibxmlFlagValue');
        $method->setAccessible(true);
        $flags = $method->invoke($mapper);
        $this->assertTrue(($flags & LIBXML_NONET) !== 0, 'LIBXML_NONET must always be set');
    }

    // WB-2.1-06: download filenames must not break out of Content-Disposition.
    public function testDownloadFileNameSanitized(): void {
        $method = new \ReflectionMethod(DefaultResponseRenderer::class, 'sanitizeDownloadFileName');
        $method->setAccessible(true);

        $out = $method->invoke(null, "a\r\nb\"c.txt");
        $this->assertFalse(str_contains($out, "\r"));
        $this->assertFalse(str_contains($out, "\n"));
        $this->assertFalse(str_contains($out, '"'));

        $this->assertSame('report.pdf', $method->invoke(null, 'report.pdf'));
        $this->assertSame('report.pdf', $method->invoke(null, '/var/data/report.pdf'));
    }

    // WB-2.1-07: an int-only parameter must reject float-valued input.
    public function testIntParamRejectsFloatString(): void {
        $intOnly = new ParameterType('int', false, true);
        $this->assertThrows(\TypeError::class, function () use ($intOnly) {
            $intOnly->castValue('3.14');
        });
    }

    // WB-2.1-07: legitimate int/float coercion keeps working.
    public function testIntFloatCoercionStillWorks(): void {
        $intOnly = new ParameterType('int', false, true);
        $this->assertSame(42, $intOnly->castValue('42'));

        $floatOnly = new ParameterType('float', false, true);
        $this->assertSame(3.14, $floatOnly->castValue('3.14'));
    }
}
