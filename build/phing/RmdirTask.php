<?php
declare(strict_types=1);

use Phing\Exception\BuildException;
use Phing\Io\File;
use Phing\Io\IOException;
use Phing\Project;
use Phing\Task;

class RmdirTask extends Task {

    private File $dir;

    public function main() {
        if (null === $this->dir) {
            throw new BuildException('dir attribute is required', $this->getLocation());
        }
        if ($this->dir->isFile()) {
            throw new BuildException(
                'Unable to delete directory as a file already exists with that name: ' . $this->dir->getAbsolutePath()
            );
        }
        if (!$this->dir->exists()) {
            $this->log(
                'Skipping ' . $this->dir->getAbsolutePath() . ' because it does not exists.',
                Project::MSG_VERBOSE
            );
        } else {
            $this->log('Removed dir: ' . $this->dir->getAbsolutePath());
            try {
                $this->dir->delete(true);
            } catch (IOException $e) {
                throw new BuildException(
                    'Could not delete directory: ' . $this->dir->getAbsolutePath() . ' due to ' . $e->getMessage(), $e
                );
            }
        }
    }

    public function setDir(File $dir) {
        $this->dir = $dir;
    }
}
