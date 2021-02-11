<?php
declare(strict_types=1);

namespace dev\winterframework\core\data;

use dev\winterframework\bombok\Data;

/**
 * @method setId(string|int $val): void
 * @method string|int getId()
 *
 * @method setTitle(string $val): void
 * @method getTitle(): string
 */
class Option {
    use Data;

    private string|int $id;
    private string $title;

    public function __construct(int|string $id = '', string $title = '') {
        $this->id = $id;
        $this->title = $title;
    }


}