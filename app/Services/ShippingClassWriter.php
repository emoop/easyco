<?php

namespace App\Services;

use App\Services\Exceptions\ShippingClassDefaultException;
use App\Services\Exceptions\ShippingClassInUseException;
use App\Services\Exceptions\ShippingClassInvalidException;
use App\Services\Exceptions\ShippingClassNotFoundException;
use EasyCo\Extensibility\Hook;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Exceptions\InvalidShippingClassException;
use EasyCo\Shipping\Exceptions\ShippingClassCodeAlreadyExistsException;
use EasyCo\Shipping\ShippingClass;
use EasyCo\Shipping\ShippingCode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Create / update / delete a shipping CLASS (shipping-domain-design.md §3, §12.3.1, §12.6) — the only way the admin
 * writes one; no Filament form writes a shipping row itself. Each call:
 *
 *  1. VALIDATES — the bounds the domain does not know (plain single-line text, the description's cap, the code's
 *     charset and a duplicate), then THROUGH THE ENTITY (ShippingClass::create / rename / describe), whose
 *     Invalid… exception is the last word. Every refusal is a ShippingClassInvalidException of TRANSLATED messages
 *     keyed by the form's field names; a raw message or a SQL error never reaches a screen. Nothing is written.
 *  2. SAVES through the repository and writes EXACTLY ONE ActivityLogger entry (`shipping_class`) in the same
 *     transaction: create -> logCreated; delete -> logDeleted with a JSON snapshot; update -> ONE logFieldChanged
 *     with the fixed field `class` and compact JSON before/after. Field changes are written only while
 *     `admin.activity_log_enabled` is on; a delete is always written.
 *  3. FIRES ONE HOOK after the commit, never inside it: `shipping.class.created (ShippingClass)`, `.updated
 *     (ShippingClass, array $before)`, `.deleted (array $snapshot)`. A refusal or a failed write fires nothing.
 *
 * THE CODE NEVER CHANGES. A class is referenced BY CODE (a rate row's foreign key, a variation's text column), the
 * entity has no code setter and the rate table's foreign key restricts an update of it — so the rule is not "not
 * while in use" but "not at all". An update that names another code is refused with a translated message.
 *
 * A class that is used is not deleted: the refusal names the methods and the variations (ShippingClassUsageReader),
 * and a database refusal (the rate table's restrict key) is translated to the same exception. Nothing is cascaded.
 * A variation's assignment is plain text without a foreign key, so that half of the check is the application's;
 * a variation assigned in the instant between the check and the delete is the one race no constraint can close
 * (the quote then falls back to the method's base amount, as it does for any unknown class).
 *
 * NO CACHE IS FLUSHED (§12.6). No permission check here, like every app service: the screen is what is authorized.
 */
final class ShippingClassWriter
{
    public const ENTITY = 'shipping_class';

    /** A description is a one-line note to the merchant; the column is text, so the writer bounds it. */
    public const DESCRIPTION_MAX_LENGTH = 500;

    public function __construct(
        private readonly ShippingClassRepository $classes,
        private readonly ShippingClassUsageReader $usage,
        private readonly ActivityLogger $audit,
    ) {
    }

    /** @throws ShippingClassInvalidException */
    public function create(ShippingClassInput $input): ShippingClass
    {
        $clean = $this->clean($input);

        $class = DB::transaction(function () use ($clean): ShippingClass {
            if ($this->classes->findByCode($clean['code']) !== null) {
                throw $this->duplicate();
            }

            $class = $this->guarded(fn (): ShippingClass => ShippingClass::create($clean['name'], $clean['code'], $clean['description']));

            try {
                $this->classes->save($class);
            } catch (ShippingClassCodeAlreadyExistsException) {
                // lost the race to a concurrent create: the unique index (SQLSTATE 23000) is the last word
                throw $this->duplicate();
            }

            $this->audit->logCreated(self::ENTITY, (string) $class->id());

            return $class;
        });

        Hook::fire('shipping.class.created', $class);

        return $class;
    }

    /**
     * The code can never change. An update that changes nothing writes nothing: no save, no audit entry, no hook.
     *
     * @throws ShippingClassInvalidException
     * @throws ShippingClassNotFoundException
     */
    public function update(string $id, ShippingClassInput $input): ShippingClass
    {
        $clean = $this->clean($input);

        $result = DB::transaction(function () use ($id, $clean): array {
            $class = $this->classes->findById($id) ?? throw new ShippingClassNotFoundException();

            if ($clean['code'] !== $class->code()) {
                throw new ShippingClassInvalidException(['code' => [__('shipping.classes.errors.code_immutable')]]);
            }

            $before = self::snapshot($class);

            $this->guarded(function () use ($class, $clean): void {
                $class->rename($clean['name']);
                $class->describe($clean['description']);
            });

            if (self::snapshot($class) === $before) {
                return ['class' => $class, 'before' => null];
            }

            $this->classes->save($class);
            $this->audit->logFieldChanged(self::ENTITY, (string) $class->id(), 'class', self::json($before), self::json(self::snapshot($class)));

            return ['class' => $class, 'before' => $before];
        });

        if ($result['before'] !== null) {
            Hook::fire('shipping.class.updated', $result['class'], $result['before']);
        }

        return $result['class'];
    }

    /**
     * @throws ShippingClassNotFoundException
     * @throws ShippingClassInUseException the class is still used by a method or a variation
     * @throws ShippingClassDefaultException the class is the store's default (make another the default first)
     */
    public function delete(string $id): void
    {
        $snapshot = DB::transaction(function () use ($id): array {
            $class = $this->classes->findById($id) ?? throw new ShippingClassNotFoundException();
            $snapshot = self::snapshot($class);

            if ($this->classes->findDefault()?->id() === $class->id()) {
                throw new ShippingClassDefaultException($class->name());
            }

            $this->refuseWhenUsed($class);

            try {
                $this->classes->delete($id);
            } catch (QueryException $exception) {
                if (($exception->errorInfo[0] ?? null) === '23000') {
                    // a rate was added in the instant after the check: the restrict key refused it
                    $this->refuseWhenUsed($class, force: true);
                }

                throw $exception;
            }

            $this->audit->logDeleted(self::ENTITY, $id, $snapshot);

            return $snapshot;
        });

        Hook::fire('shipping.class.deleted', $snapshot);
    }

    /**
     * Makes the class the store's DEFAULT (shipping stage 5e) — the one used for new products and by the
     * assign-missing command — and clears the previous default, atomically (one transaction, the current default row
     * locked; the database's unique marker is the backstop). ONE audit entry on the new default (field `is_default`,
     * old = the previous default's code or null, new = this code) and ONE hook after the commit:
     * `shipping.class.default_changed (?string $oldCode, ?string $newCode)`. Already the default: nothing is written.
     *
     * @return bool whether anything changed
     *
     * @throws ShippingClassNotFoundException
     */
    public function setDefault(string $id): bool
    {
        $change = DB::transaction(function () use ($id): ?array {
            $class = $this->classes->findById($id) ?? throw new ShippingClassNotFoundException();
            $previous = $this->classes->findDefault();

            if ($previous?->id() === $class->id()) {
                return null;
            }

            $this->classes->markDefault($id);
            $this->audit->logFieldChanged(self::ENTITY, (string) $class->id(), 'is_default', $previous?->code(), $class->code());

            return ['old' => $previous?->code(), 'new' => $class->code()];
        });

        if ($change === null) {
            return false;
        }

        Hook::fire('shipping.class.default_changed', $change['old'], $change['new']);

        return true;
    }

    /**
     * The store has no default class any more. ONE audit entry on the class that was the default, ONE hook after the
     * commit. No default: nothing is written.
     *
     * @return bool whether anything changed
     */
    public function clearDefault(): bool
    {
        $old = DB::transaction(function (): ?ShippingClass {
            $previous = $this->classes->findDefault();

            if ($previous === null) {
                return null;
            }

            $this->classes->markDefault(null);
            $this->audit->logFieldChanged(self::ENTITY, (string) $previous->id(), 'is_default', $previous->code(), null);

            return $previous;
        });

        if ($old === null) {
            return false;
        }

        Hook::fire('shipping.class.default_changed', $old->code(), null);

        return true;
    }

    /**
     * Creates the store's first class, "Standard" / "Стандартен" (code `standard`, named in the store's locale), and
     * makes it the default — ONLY when the store has no class at all, never when classes exist. Two writes, each with
     * its own audit entry and hook (created, default_changed).
     *
     * @throws ShippingClassInvalidException a class already exists
     */
    public function createDefault(?string $name = null): ShippingClass
    {
        if ($this->classes->all() !== []) {
            throw new ShippingClassInvalidException(['code' => [__('shipping.classes.errors.default_only_when_empty')]]);
        }

        $name ??= (string) \Illuminate\Support\Facades\Lang::get('shipping.classes.default_name', [], app(\App\Settings\StoreLocale::class)->current());
        $class = $this->create(new ShippingClassInput($name, 'standard', null));
        $this->setDefault((string) $class->id());

        return $class;
    }

    /**
     * The compact, stable picture of a class that the audit entries and the hooks carry.
     *
     * @return array{id: ?string, code: string, name: string, description: ?string}
     */
    public static function snapshot(ShippingClass $class): array
    {
        return [
            'id' => $class->id(),
            'code' => $class->code(),
            'name' => $class->name(),
            'description' => $class->description(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    // ---- validation ----------------------------------------------------------------------------------------

    /** @return array{name: string, code: string, description: ?string} */
    private function clean(ShippingClassInput $input): array
    {
        $errors = [];

        $name = trim($input->name);

        if ($name === '') {
            $errors['name'][] = __('shipping.classes.errors.name_required');
        } elseif (mb_strlen($name) > ShippingClass::NAME_MAX_LENGTH) {
            $errors['name'][] = __('shipping.classes.errors.too_long', ['max' => ShippingClass::NAME_MAX_LENGTH]);
        } elseif (! self::isPlain($name)) {
            $errors['name'][] = __('validation.plain_text', ['attribute' => __('shipping.classes.fields.name')]);
        }

        $code = trim($input->code);

        if ($code === '') {
            $errors['code'][] = __('shipping.classes.errors.code_required');
        } elseif (! ShippingCode::isValid($code)) {
            $errors['code'][] = __('shipping.classes.errors.code_invalid');
        }

        $description = $input->description === null ? null : trim($input->description);

        if ($description !== null && $description !== '') {
            if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
                $errors['description'][] = __('shipping.classes.errors.too_long', ['max' => self::DESCRIPTION_MAX_LENGTH]);
            } elseif (! self::isPlain($description)) {
                $errors['description'][] = __('validation.plain_text', ['attribute' => __('shipping.classes.fields.description')]);
            }
        }

        if ($errors !== []) {
            throw new ShippingClassInvalidException($errors);
        }

        return ['name' => $name, 'code' => $code, 'description' => $description === '' ? null : $description];
    }

    private function refuseWhenUsed(ShippingClass $class, bool $force = false): void
    {
        $counts = $this->usage->usage([$class->code()])[$class->code()] ?? ['methods' => 0, 'variations' => 0];

        if ($force || $counts['methods'] > 0 || $counts['variations'] > 0) {
            throw new ShippingClassInUseException($class->name(), $counts['methods'], $counts['variations']);
        }
    }

    private function duplicate(): ShippingClassInvalidException
    {
        return new ShippingClassInvalidException(['code' => [__('shipping.classes.errors.code_taken')]]);
    }

    /**
     * The entity's Invalid… exception becomes a translated field error (its English message is never shown). The
     * pre-checks above make this a backstop.
     *
     * @template T
     *
     * @param  \Closure(): T  $call
     * @return T
     */
    private function guarded(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (InvalidShippingClassException $exception) {
            $field = str_contains($exception->getMessage(), 'code') ? 'code' : 'name';

            throw new ShippingClassInvalidException([$field => [__('shipping.classes.errors.invalid')]]);
        }
    }

    /** A single line of plain text: no control characters, no bidirectional override (the project's PlainText rule). */
    private static function isPlain(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value) === 0;
    }
}
