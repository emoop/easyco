<?php

namespace App\Console\Commands;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Media\MediaUploader;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * `php artisan media:import-html-block-files` — ML-2 (media-library-design.md §7): the pictures the HTML block editor used to save as LOOSE files in `public/html-blocks/` (referenced as
 * `<img src="/storage/html-blocks/…">` in a block's text) become Media assets, so they are listed in «Медия», processed into sizes, and protected from deletion while a text uses them.
 *
 * DRY-RUN BY DEFAULT: counts only. `--execute` takes every file a block's text references and makes it an asset through MediaUploader (the metadata strip of ML-0, the processing job); the
 * file → asset pairs are remembered in the setting `media.html_block_import`, so a second run does not import twice. The `src` in the texts is rewritten to the asset's `large` size ONLY when
 * the asset is READY: a file that is still being processed is left as it is and the next `--execute` does the rewrite. Files nothing references are listed, never imported.
 * `--delete-old` (a SEPARATE run, nothing is imported) removes the IMPORTED old files that no text references any more. Counts only: no text is printed.
 */
class ImportHtmlBlockFiles extends Command
{
    protected $signature = 'media:import-html-block-files {--execute : Import and rewrite (the default is a dry-run that changes nothing)} {--delete-old : Remove the old files no text references any more (a separate run)}';

    protected $description = 'Make the loose pictures of HTML block texts (public/html-blocks/) Media assets and point the texts at them (dry-run unless --execute)';

    private const SETTING = 'media.html_block_import';

    private const FOLDER = 'html-blocks';

    public function handle(): int
    {
        $disk = (string) config('services.media.default_disk', 'public');
        $storage = Storage::disk($disk);
        $files = array_map('basename', $storage->files(self::FOLDER));
        $referenced = $this->referenced();

        if ($this->option('delete-old')) {
            return $this->deleteOld($storage, $files, $referenced, $this->map(app(SiteSettingsRepository::class)));
        }

        $execute = (bool) $this->option('execute');
        $settings = app(SiteSettingsRepository::class);
        $map = $this->map($settings);
        $report = ['referenced' => 0, 'missing' => 0, 'imported' => 0, 'already' => 0, 'refused' => 0, 'rewritten' => 0, 'processing' => 0, 'unreferenced' => 0];

        foreach ($files as $file) {
            if (! isset($referenced[$file])) {
                $report['unreferenced']++;
            }
        }

        foreach (array_keys($referenced) as $file) {
            $report['referenced']++;

            if (! in_array($file, $files, true) && ! isset($map[$file])) {
                $report['missing']++;

                continue;
            }

            if (isset($map[$file])) {
                $report['already']++;

                continue;
            }

            if (! $execute) {
                $report['imported']++;

                continue;
            }

            $result = app(MediaUploader::class)->upload([['name' => $file, 'content' => (string) $storage->get(self::FOLDER.'/'.$file)]], imagesOnly: true);

            if ($result['stored'] === []) {
                $report['refused']++;

                continue;
            }

            $map[$file] = $result['stored'][0];
            $report['imported']++;
        }

        if ($execute) {
            $settings->set(self::SETTING, (string) json_encode($map, JSON_THROW_ON_ERROR));

            foreach ($map as $file => $assetId) {
                $large = $this->largeSource((int) $assetId);

                if ($large === null) {
                    isset($referenced[$file]) && $report['processing']++;

                    continue;
                }

                $report['rewritten'] += $this->rewrite($file, $large);
            }
        }

        $this->line($execute ? 'Изпълнение:' : 'Проба (нищо не се променя):');
        $this->line(sprintf('  файлове в %s/: %d, от тях на които текст се позовава: %d, без позоваване (само в списъка): %d', self::FOLDER, count($files), $report['referenced'], $report['unreferenced']));
        $this->line(sprintf('  липсващи (текст сочи файл, който го няма): %d', $report['missing']));
        $this->line(sprintf('  вече внесени: %d', $report['already']));
        $this->line(sprintf('  %s: %d, отказани: %d', $execute ? 'внесени сега' : 'ще бъдат внесени', $report['imported'], $report['refused']));
        $execute && $this->line(sprintf('  пренаписани адреса в текстове: %d, още се обработват (следва втори пуск): %d', $report['rewritten'], $report['processing']));
        Log::info('media.import_html_block_files', ['execute' => $execute] + $report);

        return self::SUCCESS;
    }

    /** @return array<string, true> the file names under html-blocks/ that some text references */
    private function referenced(): array
    {
        $found = [];

        foreach (HtmlBlockModel::query()->pluck('body') as $body) {
            preg_match_all('#/storage/'.self::FOLDER.'/([A-Za-z0-9._-]+)#', (string) $body, $m);

            foreach ($m[1] as $file) {
                $found[$file] = true;
            }
        }

        return $found;
    }

    /** @return array<string, int> file => asset id, remembered between runs */
    private function map(SiteSettingsRepository $settings): array
    {
        $data = json_decode((string) $settings->get(self::SETTING), true);

        return is_array($data) ? array_map('intval', array_filter($data, 'is_numeric')) : [];
    }

    /** The `large` size of a READY asset as the path it is served at, or null while it is still being processed (or failed). */
    private function largeSource(int $assetId): ?string
    {
        $asset = MediaAssetModel::query()->find($assetId);

        if ($asset === null || $asset->processing_status !== 'ready') {
            return null;
        }

        $variant = collect((array) $asset->variants)->first(fn ($v): bool => is_array($v) && ($v['tier'] ?? null) === 'large' && is_string($v['path'] ?? null));

        if ($variant === null) {
            return null;
        }

        $url = Storage::disk((string) $asset->disk)->url((string) $variant['path']);

        return preg_match('#^https?://[^/]+(/storage/.*)$#i', $url, $m) === 1 ? $m[1] : $url;
    }

    /** Points every text at the asset instead of the loose file; returns how many texts changed. */
    private function rewrite(string $file, string $large): int
    {
        $changed = 0;
        $pattern = '#(?:https?://[^/"\']+)?/storage/'.self::FOLDER.'/'.preg_quote($file, '#').'(?![A-Za-z0-9._-])#';

        foreach (HtmlBlockModel::query()->where('body', 'like', '%'.self::FOLDER.'/'.$file.'%')->get() as $block) {
            $new = preg_replace($pattern, $large, (string) $block->body, -1, $count);

            if ($count > 0 && $new !== null && $new !== $block->body) {
                $block->body = $new;
                $block->save();
                $changed++;
            }
        }

        return $changed;
    }

    /** Only files this command imported (remembered in the setting) and no text references any more; a file that was never imported is not ours to remove. @param  list<string>  $files @param  array<string, true>  $referenced @param  array<string, int>  $map */
    private function deleteOld(\Illuminate\Contracts\Filesystem\Filesystem $storage, array $files, array $referenced, array $map): int
    {
        $removed = 0;
        $kept = 0;

        foreach ($files as $file) {
            if (isset($referenced[$file]) || ! isset($map[$file])) {
                $kept++;

                continue;
            }

            $storage->delete(self::FOLDER.'/'.$file) && $removed++;
        }

        $this->line('Изтриване на старите файлове:');
        $this->line(sprintf('  изтрити (внесени и никой текст не ги ползва): %d, оставени (текст още ги ползва или не са внасяни): %d', $removed, $kept));
        Log::info('media.import_html_block_files_delete_old', ['removed' => $removed, 'kept' => $kept]);

        return self::SUCCESS;
    }
}
