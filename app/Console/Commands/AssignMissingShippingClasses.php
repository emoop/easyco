<?php

namespace App\Console\Commands;

use App\Services\ActivityLogger;
use App\Services\Exceptions\ShippingClassInvalidException;
use App\Services\ShippingClassAssigner;
use App\Services\ShippingClassMissingReader;
use App\Services\ShippingClassWriter;
use EasyCo\Extensibility\Hook;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan shipping-classes:assign-missing` — gives a shipping class to the variations that have none (shipping
 * stage 5e). The class is REQUIRED in the admin forms from this stage on; this command is how the EXISTING data
 * catches up. Nothing runs it automatically, and DRY RUN IS THE DEFAULT: without --force nothing is written.
 *
 * "Missing" is ShippingClassMissingReader's one definition: the stored text is NULL, blank, or a code that names no
 * existing class (legacy free text). Archived variations are included (only the class text changes). A variation that
 * already has a VALID class is never touched, not even by a concurrent run: every chunk locks its rows, re-reads them
 * and skips what is no longer missing, so the command is idempotent, resumable and safe to run twice at once.
 *
 *  --class=<code>        the class to assign; default: the store's default class. Neither exists: the command stops
 *                        (exit 1) — unless --create-default.
 *  --create-default[=N]  only when the store has NO class at all: creates "Standard" (or N), code `standard`, marked
 *                        default. Ignored (with a warning) when any class exists.
 *  --force               write. --dry-run is accepted for clarity and wins over --force.
 *  --limit=<n>           cap on the variations processed in this run (default: all); run again for the rest.
 *  --chunk=<n>           variations per transaction (default 200, 1..1000). Rows are streamed in id order by chunk;
 *                        never all loaded at once.
 *
 * THE AUDIT RULE (one, consistent): a run that changes anything writes ONE summary entry — entity `shipping_class`,
 * field `assign_missing`, JSON {class, count, skipped, groups:{null,blank,unknown}} — and, ONLY when the run was
 * planned to change at most 200 variations (the lesser of the missing total and --limit), also the usual per-variation
 * entry (entity `product`, field `variation[<id>].shipping_class`, old text, new code). Like every field change these
 * entries exist only while `admin.activity_log_enabled` is on. ONE hook, `shipping.class.assigned_bulk (string
 * $classCode, int $count)`, fires after the run; no per-variation `assigned` hook.
 */
class AssignMissingShippingClasses extends Command
{
    /** Up to this many changed variations a run also writes one audit entry per variation. */
    public const PER_VARIATION_AUDIT_MAX = 200;

    private const SAMPLE_SIZE = 20;

    protected $signature = 'shipping-classes:assign-missing
                            {--class= : Code of the class to assign (default: the store\'s default class)}
                            {--create-default= : If the store has NO class at all, create "Standard" (or this name) as the default class}
                            {--dry-run : Report only (this is the default)}
                            {--force : Write the changes (without it nothing is written)}
                            {--limit= : At most this many variations in this run}
                            {--chunk=200 : Variations per transaction}';

    protected $description = 'Give a shipping class to the variations that have none (NULL, blank or a code naming no class). Dry run unless --force.';

    public function handle(
        ShippingClassRepository $classes,
        ShippingClassMissingReader $missing,
        ShippingClassAssigner $assigner,
        ShippingClassWriter $writer,
        ActivityLogger $audit,
    ): int {
        $limit = $this->option('limit');
        $chunk = $this->option('chunk');

        if ($limit !== null && (! ctype_digit((string) $limit) || (int) $limit < 1)) {
            $this->error('--limit must be a whole number of 1 or more. Nothing was changed.');

            return self::FAILURE;
        }

        if (! ctype_digit((string) $chunk) || (int) $chunk < 1 || (int) $chunk > 1000) {
            $this->error('--chunk must be a whole number from 1 to 1000. Nothing was changed.');

            return self::FAILURE;
        }

        $limit = $limit === null ? null : (int) $limit;
        $chunk = (int) $chunk;
        $write = (bool) $this->option('force') && ! $this->option('dry-run');

        if ($this->option('force') && $this->option('dry-run')) {
            $this->warn('Both --force and --dry-run were given: this is a dry run, nothing will be written.');
        }

        // ---- which class -------------------------------------------------------------------------------------
        $existing = $classes->all();
        $wanted = $this->option('class');
        $class = null;

        if ($wanted !== null && trim((string) $wanted) !== '') {
            $class = $classes->findByCode(trim((string) $wanted));

            if ($class === null) {
                $this->error('There is no shipping class with the code "'.$this->plain((string) $wanted, 64).'". Nothing was changed.');

                return self::FAILURE;
            }
        } else {
            $class = $classes->findDefault();
        }

        $creating = false;

        if ($this->input->hasParameterOption('--create-default')) {
            if ($existing !== []) {
                $this->warn('--create-default is ignored: the store already has shipping classes.');
            } else {
                $creating = true;
            }
        }

        if ($class === null && ! $creating) {
            $this->error($existing === []
                ? 'The store has no shipping class. Create one in the admin, or run again with --create-default. Nothing was changed.'
                : 'No class was named and the store has no default class. Pass --class=<code> or mark a class as the default. Nothing was changed.');

            return self::FAILURE;
        }

        // ---- the report --------------------------------------------------------------------------------------
        $counts = $missing->counts();

        $this->line($write ? 'ASSIGN MISSING SHIPPING CLASSES (writing)' : 'DRY RUN — nothing is written (add --force to write)');
        $this->line($creating
            ? 'Class: a new default class "'.$this->plain((string) ($this->option('create-default') ?: 'Standard'), 100).'" (code standard) would be created.'
            : 'Class: '.$this->plain($class->name(), 100).' ('.$class->code().')');
        $this->line(sprintf(
            'Variations without a usable class: %d  [NULL: %d, blank: %d, a code naming no class: %d]',
            $counts['total'], $counts['null'], $counts['blank'], $counts['unknown'],
        ));

        if ($counts['total'] > 0) {
            $this->sample($missing);
        }

        $planned = $limit === null ? $counts['total'] : min($counts['total'], $limit);

        if (! $write) {
            $this->line(sprintf('Would give the class to %d variation(s)%s.', $planned, $limit !== null && $counts['total'] > $limit ? ' (capped by --limit)' : ''));

            return self::SUCCESS;
        }

        // ---- the class (created first when asked) ----------------------------------------------------------------
        if ($creating) {
            try {
                $class = $writer->createDefault($this->option('create-default') ?: null);
            } catch (ShippingClassInvalidException $exception) {
                $this->error($exception->getMessage().' Nothing was changed.');

                return self::FAILURE;
            }

            $this->info('Created the default class "'.$this->plain($class->name(), 100).'" (code '.$class->code().').');
        }

        if ($counts['total'] === 0) {
            $this->info('Nothing to assign.');

            return self::SUCCESS;
        }

        // ---- the chunks ----------------------------------------------------------------------------------------------
        $auditEach = $planned <= self::PER_VARIATION_AUDIT_MAX;
        $lastId = 0;
        $processed = 0;
        $assigned = 0;
        $skipped = 0;
        $peak = 0;

        while ($limit === null || $processed < $limit) {
            $take = $limit === null ? $chunk : min($chunk, $limit - $processed);
            $ids = $missing->query()->where('v.id', '>', $lastId)->orderBy('v.id')->limit($take)->pluck('v.id')->map(static fn ($id): string => (string) $id)->all();

            if ($ids === []) {
                break;
            }

            $result = $assigner->assignMissing($ids, (string) $class->id(), $auditEach);

            $lastId = (int) max($ids);
            $processed += count($ids);
            $assigned += $result['assigned'];
            $skipped += $result['skipped'];
            $peak = max($peak, count($ids));

            $this->line(sprintf('  chunk of %d: %d assigned, %d skipped (total assigned %d)', count($ids), $result['assigned'], $result['skipped'], $assigned));
        }

        if ($assigned > 0) {
            $audit->logFieldChanged('shipping_class', (string) $class->id(), 'assign_missing', null, ShippingClassWriter::json([
                'class' => $class->code(),
                'count' => $assigned,
                'skipped' => $skipped,
                'groups' => ['null' => $counts['null'], 'blank' => $counts['blank'], 'unknown' => $counts['unknown']],
            ]));

            Hook::fire('shipping.class.assigned_bulk', $class->code(), $assigned);
        }

        $this->info(sprintf(
            'Done: %d variation(s) processed, %d assigned, %d skipped (already fixed by someone else); largest chunk %d; %s.',
            $processed, $assigned, $skipped, $peak,
            $auditEach ? 'one audit entry per variation plus the summary' : 'the summary audit entry only (more than '.self::PER_VARIATION_AUDIT_MAX.' planned)',
        ));

        return self::SUCCESS;
    }

    /** The first 20 missing variations: product SKU/name and the stored text, neutralised for the console and truncated. */
    private function sample(ShippingClassMissingReader $missing): void
    {
        $rows = $missing->query()
            ->leftJoin('catalog_products as p', 'p.id', '=', 'v.product_id')
            ->orderBy('v.id')
            ->limit(self::SAMPLE_SIZE)
            ->get(['v.id', 'v.sku', 'v.shipping_class', 'p.name']);

        $this->line('First '.$rows->count().':');

        foreach ($rows as $row) {
            $stored = $row->shipping_class === null ? 'NULL' : '"'.$this->plain((string) $row->shipping_class, 40).'"'.(mb_strlen((string) $row->shipping_class) > 40 ? ' ('.mb_strlen((string) $row->shipping_class).' characters)' : '');

            $this->line(sprintf('  #%s  %s  %s  stored: %s', $row->id, $this->plain((string) $row->sku, 40), $this->plain((string) $row->name, 50), $stored));
        }
    }

    /** Text for the console: control and escape characters become "?", long text is cut with an ellipsis. Nothing is interpreted. */
    private function plain(string $text, int $max): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '?', $text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }
}
