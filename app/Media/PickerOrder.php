<?php

namespace App\Media;

/** The order of a list of pictures in a picker field (ML-3): one picture moved one place left or right. */
final class PickerOrder
{
    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    public static function move(array $ids, int $position, int $by): array
    {
        $ids = array_values(array_map('intval', $ids));
        $target = $position + $by;

        if (! isset($ids[$position], $ids[$target])) {
            return $ids;
        }

        [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];

        return $ids;
    }
}
