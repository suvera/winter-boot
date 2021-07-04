<?php
declare(strict_types=1);

namespace dev\winterframework\io\disk;

class Journal {

    /**
     * @var JournalItem[]
     */
    protected array $items = [];

    public function addEntry(JournalItem $item): void {
        $item->setId(count($this->items));
        $this->items[$item->getId()] = $item;
    }

    public function getItems(): array {
        return $this->items;
    }

    public function setItems(array $items): void {
        $this->items = $items;
    }

    public function getItem(int $id): ?JournalItem {
        return $this->items[$id] ?? null;
    }

}