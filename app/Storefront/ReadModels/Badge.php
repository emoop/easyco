<?php

namespace App\Storefront\ReadModels;

/**
 * A card badge (storefront-design.md §2.4, §8.3). The class exists so the shape is fixed; NO resolver exists in S1:
 * every card carries `badges: []` until S10.
 */
final readonly class Badge
{
    public function __construct(
        public string $type,
        public string $label,
        public string $placement,
        public string $style,
        public int $priority,
    ) {
    }

    /** @return array{type: string, label: string, placement: string, style: string, priority: int} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'placement' => $this->placement,
            'style' => $this->style,
            'priority' => $this->priority,
        ];
    }
}
