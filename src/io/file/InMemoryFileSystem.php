<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\io\file;

use RuntimeException;

class InMemoryFileSystem {
    const WRAPPER_NAME = 'imfs';
    const TYPE_DIR = 1;
    const TYPE_FILE = 2;

    protected string $filePath;
    protected string $fileMode;
    protected int $position = 0;
    protected bool $eof = false;

    private static bool $registered = false;
    private static int $counter = 10000;
    private static array $REPO = array(
        '/winter' => array(
            'type' => self::TYPE_DIR,
            'mode' => '0777'
        )
    );

    public static function register(): void {
        if (self::$registered) {
            return;
        }
        $existed = in_array(self::WRAPPER_NAME, stream_get_wrappers());

        if ($existed) {
            stream_wrapper_unregister(self::WRAPPER_NAME);
        }

        stream_wrapper_register(self::WRAPPER_NAME, __CLASS__);
        self::$registered = true;
    }

    public static function createFile($data): InMemoryFile {
        self::register();

        self::$counter++;

        $name = self::WRAPPER_NAME . '://winter/' . self::$counter;
        file_put_contents($name, $data);

        return new InMemoryFile($name, strval(self::$counter));
    }

    private function getCleanedPath(string $path): string {
        $url = parse_url($path);
        $p = '/' . $url['host'];

        if ($url['path']) {
            $p .= rtrim($url['path'], '/');
        }

        return $p;
    }

    public function dir_closedir(): bool {
        return true;
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function dir_opendir(string $path, int $options): bool {
        return true;
    }

    public function dir_readdir(): ?string {
        return null;
    }

    public function dir_rewinddir(): bool {
        return false;
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function mkdir(string $path, int $mode, int $options): bool {
        $path = $this->getCleanedPath($path);

        if (isset(self::$REPO[$path])) {
            throw new RuntimeException('Dir/File already exists ' . $path);
        }

        self::$REPO[$path] = array(
            'type' => self::TYPE_DIR,
            'mode' => '0777'
        );

        return true;
    }

    public function rename(string $path_from, string $path_to): bool {
        $path_from = $this->getCleanedPath($path_from);
        $path_to = $this->getCleanedPath($path_to);

        if (!isset(self::$REPO[$path_from])) {
            throw new RuntimeException('Dir/File does not exists ' . $path_from);
        }

        if (isset(self::$REPO[$path_to])) {
            throw new RuntimeException('Dir/File already exists ' . $path_to);
        }

        self::$REPO[$path_to] = self::$REPO[$path_from];

        if (self::$REPO[$path_from]['type'] === self::TYPE_DIR) {
            foreach (array_keys(self::$REPO) as $repoPath) {
                if (str_starts_with($repoPath, $path_from . '/')) {
                    self::$REPO[$path_to . substr($repoPath, strlen($path_from))] = self::$REPO[$repoPath];
                    unset(self::$REPO[$repoPath]);
                }
            }
        }

        unset(self::$REPO[$path_from]);
        return true;
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function rmdir(string $path, int $options): bool {
        $path = $this->getCleanedPath($path);

        if (isset(self::$REPO[$path])) {
            throw new RuntimeException('Dir  does not exist ' . $path);
        }

        if (self::$REPO[$path]['type'] != self::TYPE_DIR) {
            throw new RuntimeException($path . ' is not a directory');
        }

        unset(self::$REPO[$path]);

        foreach (array_keys(self::$REPO) as $repoPath) {
            if (str_starts_with($repoPath, $path . '/')) {
                unset(self::$REPO[$repoPath]);
            }
        }

        return true;
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function stream_cast(int $cast_as): mixed {
        return null;
    }

    public function stream_close(): mixed {
        return null;
    }

    public function stream_eof(): bool {
        return $this->eof;
    }

    public function stream_flush(): mixed {
        return null;
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function stream_lock(int $operation): mixed {
        return null;
    }


    public function stream_metadata($path, $option, $value) {
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool {
        $path = $this->getCleanedPath($path);

        $dirName = dirname($path);

        if (!isset(self::$REPO[$dirName])) {
            throw new RuntimeException('Dir does not exists ' . $dirName);
        }

        if (self::$REPO[$dirName]['type'] !== self::TYPE_DIR) {
            throw new RuntimeException($dirName . ' is not a Dir');
        }


        if (!isset(self::$REPO[$path])) {
            if ($mode[0] === 'r') {
                throw new RuntimeException('File does not exist ' . $path);
            }

            self::$REPO[$path] = array(
                'type' => self::TYPE_FILE,
                'mode' => '0755',
                'content' => ''
            );
            $this->eof = true;
        }

        $this->filePath = $path;
        $this->fileMode = $mode;

        if ($mode[0] === 'a') {
            $this->position = strlen(self::$REPO[$path]['content']);
            $this->eof = true;
        }

        return true;
    }

    public function stream_read(int $count): bool|string {

        if (!$this->filePath) {
            $this->eof = true;
            return false;
        }

        if ($this->eof) {
            return false;
        }

        $ret = substr(self::$REPO[$this->filePath]['content'], $this->position, $count);

        if (strlen(self::$REPO[$this->filePath]['content']) <= $this->position + $count) {
            $this->eof = true;
            $this->position = strlen(self::$REPO[$this->filePath]['content']);
        } else {
            $this->position += $count;
        }

        return $ret;
    }

    public function stream_write(string $data): int {

        if (!$this->filePath) {
            return 0;
        }

        $l = strlen($data);
        $p = substr(self::$REPO[$this->filePath]['content'], 0, $this->position);

        $this->position += $l;
        $e = substr(self::$REPO[$this->filePath]['content'], $this->position);

        self::$REPO[$this->filePath]['content'] = $p . $data . $e;

        return $l;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool {
        if (!$this->filePath) {
            return false;
        }

        $l = strlen(self::$REPO[$this->filePath]['content']);

        switch ($whence) {
            case SEEK_SET:
                $newPos = $offset;
                break;
            case SEEK_CUR:
                $newPos = $this->position + $offset;
                break;

            case SEEK_END:
                $newPos = $l + $offset;
                break;

            default:
                return false;
        }

        $ret = ($newPos >= 0 && $newPos <= $l);

        if ($ret) {
            $this->position = $newPos;

            if ($this->position == $l) {
                $this->eof = true;
            } else {
                $this->eof = false;
            }
        }

        return $ret;
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function stream_set_option(int $option, int $arg1, int $arg2): mixed {
        return null;
    }

    public function stream_stat(): mixed {
        return null;
    }

    public function stream_tell(): int {
        return $this->position;
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function stream_truncate(int $new_size): mixed {
        return null;
    }

    public function unlink(string $path): bool {
        $path = rtrim($path, '/');

        if (isset(self::$REPO[$path]) && self::$REPO[$path]['type'] === self::TYPE_FILE) {
            unset(self::$REPO[$path]);

            return true;
        }

        return false;
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function url_stat(string $path, int $flags): ?array {
        $path = str_replace(self::WRAPPER_NAME . ':/', '', $path);

        if (!isset(self::$REPO[$path])) {
            return null;
        }
        $isFile = (self::$REPO[$path]['type'] === self::TYPE_FILE);

        return [
            0 => 64770,
            1 => 1054649,
            2 => 33279,
            3 => 1,
            4 => 3003,
            5 => 3003,
            6 => 0,
            7 => $isFile ? strlen(self::$REPO[$path]['content']) : 0, // size
            8 => 1568955864, // atime
            9 => 1568955864, // mtime
            10 => 1578033107, // ctime
            11 => 4096,
            12 => 104544,
            'dev' => 64770,
            'ino' => 1054649,
            'mode' => 33279,
            'nlink' => 1,
            'uid' => 3003,
            'gid' => 3003,
            'rdev' => 0,
            'size' => $isFile ? strlen(self::$REPO[$path]['content']) : 0,
            'atime' => 1568955864,
            'mtime' => 1568955864,
            'ctime' => 1578033107,
            'blksize' => 4096,
            'blocks' => 104544,

        ];
    }
}

InMemoryFileSystem::register();