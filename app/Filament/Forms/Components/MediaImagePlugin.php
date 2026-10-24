<?php

namespace App\Filament\Forms\Components;

use App\Media\MediaPickerAccess;
use App\Rules\MediaAssetId;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Validator;

/**
 * The picture button of the HTML block text editor (ML-2, media-library-design.md §7), through Filament v5's own extension point: a RichContentPlugin that adds a RichEditorTool («Снимка») and
 * the Action the tool mounts. The action's modal is the media picker (MediaPickerBrowser in `withSize` mode: pick or upload, the alt text, «Среден / Голям»); the modal wrapper puts the chosen id and
 * size into the action's hidden fields and submits it, and the action inserts `<img src="{variant address}" alt width height>` at the cursor (`insertContent`, an `image` node WITHOUT the
 * editor's own `data-id`). That is exactly what HtmlBlockSanitizer keeps; nothing new is allowed. It replaces Filament's `attachFiles` (loose files in `public/html-blocks/`).
 */
final class MediaImagePlugin implements RichContentPlugin
{
    public const TOOL = 'mediaImage';

    public static function make(): self
    {
        return new self;
    }

    public function getTipTapPhpExtensions(): array
    {
        return [];
    }

    public function getTipTapJsExtensions(): array
    {
        return [];
    }

    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make(self::TOOL)
                ->label(__('media.picker.editor_tool'))
                ->icon(Heroicon::Photo)
                ->action(),
        ];
    }

    public function getEditorActions(): array
    {
        return [
            Action::make(self::TOOL)
                ->label(__('media.picker.editor_tool'))
                ->authorize(fn (): bool => MediaPickerAccess::allowed())
                ->modalHeading(__('media.picker.title'))
                ->modalWidth(Width::FiveExtraLarge)
                ->modalSubmitAction(false)
                ->modalCancelAction(false)
                ->closeModalByClickingAway(false)
                ->schema([Hidden::make('media_id'), Hidden::make('size')])
                ->modalContent(fn (): \Illuminate\Contracts\View\View => view('filament.forms.components.media-picker-editor-modal'))
                ->action(function (array $arguments, array $data, RichEditor $component): void {
                    self::insert($arguments, $data, $component);
                }),
        ];
    }

    /** @param  array<string, mixed>  $arguments @param  array<string, mixed>  $data */
    private static function insert(array $arguments, array $data, RichEditor $component): void
    {
        abort_unless(MediaPickerAccess::allowed(), 403);

        $check = Validator::make(['id' => $data['media_id'] ?? null], ['id' => ['required', new MediaAssetId]]);

        if ($check->fails()) {
            Notification::make()->title($check->errors()->first('id'))->danger()->send();

            return;
        }

        $asset = MediaAssetModel::query()->find((int) $data['media_id']);
        $tier = ($data['size'] ?? 'large') === 'medium' ? 'medium' : 'large';
        $variant = $asset === null ? null : collect((array) $asset->variants)->first(fn ($v): bool => is_array($v) && ($v['tier'] ?? null) === $tier && is_string($v['path'] ?? null));

        // a picture that is still being processed has no sizes yet: nothing is put into the text
        if ($asset === null || $variant === null) {
            Notification::make()->title(__('media.picker.not_ready'))->warning()->send();

            return;
        }

        $url = app(MediaStorageAdapter::class)->url((string) $asset->disk, (string) $variant['path']);
        // our own storage, as the path it is served at: that is the form the text sanitizer accepts
        $src = preg_match('#^https?://[^/]+(/storage/.*)$#i', $url, $m) === 1 ? $m[1] : $url;

        $component->runCommands([
            EditorCommand::make('insertContent', arguments: [[
                'type' => 'image',
                'attrs' => [
                    'src' => $src,
                    'alt' => (string) $asset->alt_text,
                    'width' => (int) ($variant['width'] ?? 0) ?: null,
                    'height' => (int) ($variant['height'] ?? 0) ?: null,
                ],
            ]]),
        ], editorSelection: $arguments['editorSelection'] ?? null);
    }
}
