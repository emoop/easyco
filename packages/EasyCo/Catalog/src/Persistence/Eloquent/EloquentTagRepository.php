<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Tag;

/**
 * Maps Tag onto catalog_tags via TagModel. Mirrors
 * EloquentAttributeDefinitionRepository's id-or-new + assignId() pattern
 * exactly. A duplicate `slug` is a plain unique-constraint violation
 * that propagates as a raw QueryException — no dedicated exception
 * wrapping, same precedent as EloquentBrandRepository/
 * EloquentAttributeDefinitionRepository.
 */
final class EloquentTagRepository implements TagRepository
{
    public function save(Tag $tag): void
    {
        $model = $tag->id() !== null
            ? TagModel::findOrFail($tag->id())
            : new TagModel();

        $model->name = $tag->name();
        $model->slug = $tag->slug();
        $model->save();

        if ($tag->id() === null) {
            $tag->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?Tag
    {
        $model = TagModel::find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    /** @return Tag[] */
    public function all(): array
    {
        return TagModel::all()
            ->map(fn (TagModel $model) => $this->toDomain($model))
            ->all();
    }

    private function toDomain(TagModel $model): Tag
    {
        return new Tag(
            id: (string) $model->id,
            name: $model->name,
            slug: $model->slug,
        );
    }
}
