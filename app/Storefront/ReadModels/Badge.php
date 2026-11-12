<?php

namespace App\Storefront\ReadModels;

/**
 * A card badge. P7-1 (badges-research.md): `type` is the kind (`sale`, `new`, `low_stock`), `placement` is `top` | `bottom`, `style` a colour of the closed palette, `shape` square | rounded | pill; `label` is RAW text
 * (escaped by the view). Every card carries `badges: []` until the merchant switches a badge on in «Дизайн» → «Значки».
 */
final readonly class Badge
{
    public function __construct(
        public string $type,
        public string $label,
        public string $placement,
        public string $style,
        public int $priority,
        public string $shape = 'square',
    ) {
    }

    /** @return array{type: string, label: string, placement: string, style: string, priority: int, shape: string} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'placement' => $this->placement,
            'style' => $this->style,
            'priority' => $this->priority,
            'shape' => $this->shape,
        ];
    }
}
