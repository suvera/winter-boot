<?php

declare(strict_types=1);

namespace dev\winterframework\web\http;

use dev\winterframework\io\file\File;
use dev\winterframework\io\file\FileTrait;
use dev\winterframework\stereotype\JsonProperty;

class HttpUploadedFile implements File {
    use FileTrait;

    #[JsonProperty(name: 'name')]
    public string $name = '';

    #[JsonProperty(name: 'type')]
    protected string $type = '';

    public string $typeDerived = '';

    #[JsonProperty(name: 'size')]
    protected int $size = 0;

    #[JsonProperty(name: 'tmp_name')]
    protected string $filePath = '';

    #[JsonProperty(name: 'error')]
    protected int $error = UPLOAD_ERR_NO_FILE;

    /*
    * The full path as submitted by the browser. This value does not always contain a real directory structure, and cannot be trusted. 
    * Available as of PHP 8.1.0.
    * @since 8.1
    */
    #[JsonProperty(name: 'full_path')]
    protected string $fullPath = '';

    public function __construct() {
    }

    public static function fromArray(array $values): HttpUploadedFile {
        $ret = new HttpUploadedFile();

        $ret->name = $values['name'] ?? '';
        $ret->type = $values['type'] ?? '';
        $ret->size = $values['size'] ?? 0;
        $ret->filePath = $values['tmp_name'] ?? '';
        $ret->error = $values['error'] ?? UPLOAD_ERR_NO_FILE;
        $ret->fullPath = $values['full_path'] ?? '';

        return $ret;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getType(): string {
        return $this->type;
    }

    public function getSize(): int {
        return $this->size;
    }

    public function getFilePath(): string {
        return $this->filePath;
    }

    public function getError(): int {
        return $this->error;
    }

    public function getFullPath(): string {
        return $this->fullPath;
    }

    public function getTypeDerived(): string {
        return $this->typeDerived;
    }

    public function getErrorText(): string {
        switch ($this->error) {
            case UPLOAD_ERR_OK:
            case UPLOAD_ERR_NO_FILE:
                return '';
            case UPLOAD_ERR_INI_SIZE:
                $max = ini_get('upload_max_filesize');
                return 'The uploaded file exceeds allowed limit ' . $max . ' bytes';
            case UPLOAD_ERR_FORM_SIZE:
                $max = $_POST['MAX_FILE_SIZE'] ?? 'unknown';
                return 'The uploaded file exceeds allowed limit ' . $max . ' bytes specified in the form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Internal server error: Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Internal server error: Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'Internal server error: Application extension stopped the file upload';
            default:
                return 'Unknown file upload error';
        }
    }
}
