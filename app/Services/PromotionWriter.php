<?php

namespace App\Services;

use App\Services\Exceptions\PromotionInvalidException;
use DateTimeImmutable;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\Exceptions\PromotionCodeAlreadyExistsException;
use EasyCo\Promotions\Promotion;
use EasyCo\Promotions\PromotionScope;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * P5b: the ONE write path of the admin promotion form. A Filament page never writes a promotion row: it hands the form's data to create() / update() here, which parses the typed text (the SAME MoneyInput the other admin
 * forms use; the percent into basis points by string arithmetic), builds or changes the DOMAIN entity (Promotion::create() / Promotion::update(), whose invariants are the last word), saves it through the repositories and
 * reconciles its scopes — all in one transaction. A wrong field is a PromotionInvalidException whose keys are the form's field names, so the page shows it under the field.
 *
 * WHAT IS EDITABLE: only what Promotion::update() accepts. The code, the discount type and the discount value are set at creation and never changed: update() ignores any such key in the data it is given.
 *
 * SCOPES: the form manages the five closed types (brand, category, tag, product, attribute value), each include / exclude. An ACCOUNT scope (made through the API) is never offered and never touched: update()
 * reconciles only the types the form shows, so a save leaves an ACCOUNT scope exactly as it was.
 */
final class PromotionWriter
{
    /** The activity log's entity name (the journal shows it as «Промокод» / «Promo code»). */
    public const ENTITY = 'promotion';

    /** The scope types the form offers. */
    public const SCOPE_TYPES = [
        PromotionScopeType::BRAND,
        PromotionScopeType::CATEGORY,
        PromotionScopeType::TAG,
        PromotionScopeType::PRODUCT,
        PromotionScopeType::ATTRIBUTE_VALUE,
    ];

    public function __construct(
        private readonly PromotionRepository $promotions,
        private readonly PromotionScopeRepository $scopes,
        private readonly PromotionAdminReader $reader,
        private readonly ActivityLogger $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  the form's state (see PromotionResource::form())
     *
     * @throws PromotionInvalidException
     */
    public function create(array $data): Promotion
    {
        $errors = [];
        $currency = DefaultCurrency::get();

        $type = PromotionDiscountType::tryFrom((string) ($data['discount_type'] ?? ''));
        $code = trim((string) ($data['code'] ?? ''));

        if ($code === '') {
            $errors['code'][] = __('promotions.errors.code_required');
        } elseif (mb_strlen($code) > 255) {
            $errors['code'][] = __('promotions.errors.code_too_long');
        }

        $basisPoints = null;
        $amount = null;

        if ($type === null) {
            $errors['discount_type'][] = __('promotions.errors.type_required');
        } elseif ($type === PromotionDiscountType::PERCENTAGE) {
            $basisPoints = self::parsePercent($data['percentage'] ?? null);

            if ($basisPoints === null) {
                $errors['percentage'][] = __('promotions.errors.percentage_invalid');
            }
        } else {
            $amount = MoneyInput::parse(self::text($data['discount_amount'] ?? null), $currency);

            if ($amount === null || $amount->isZero()) {
                $errors['discount_amount'][] = __('validation.money_format', ['attribute' => __('promotions.fields.discount_amount')]);
            }
        }

        $editable = $this->editable($data, $errors);
        $scopes = $this->parseScopes($data['scopes'] ?? [], $errors);

        if ($errors !== []) {
            throw new PromotionInvalidException($errors);
        }

        try {
            $promotion = Promotion::create(
                code: $code,
                discountType: $type,
                percentageBasisPoints: $basisPoints,
                discountAmount: $amount,
                individualUseOnly: $editable['individualUseOnly'],
                excludeSaleItems: $editable['excludeSaleItems'],
                minimumSpend: $editable['minimumSpend'],
                maximumSpend: $editable['maximumSpend'],
                newCustomersOnly: $editable['newCustomersOnly'],
                usageLimitTotal: $editable['usageLimitTotal'],
                usageLimitPerCustomer: $editable['usageLimitPerCustomer'],
                usageLimitItems: $editable['usageLimitItems'],
                validFrom: $editable['validFrom'],
                validUntil: $editable['validUntil'],
                isPublic: $editable['isPublic'],
                publicDescription: $editable['publicDescription'],
            );
        } catch (InvalidArgumentException $exception) {
            throw new PromotionInvalidException(['code' => [$exception->getMessage()]]);
        }

        if (($data['is_active'] ?? true) === false) {
            $promotion->deactivate();
        }

        try {
            DB::transaction(function () use ($promotion, $scopes): void {
                $this->promotions->save($promotion);

                foreach ($scopes as $scope) {
                    $this->scopes->attach(new PromotionScope(null, (string) $promotion->id(), $scope['type'], $scope['reference'], $scope['mode']));
                }

                $this->audit->logCreated(self::ENTITY, (string) $promotion->id());
            });
        } catch (PromotionCodeAlreadyExistsException) {
            throw new PromotionInvalidException(['code' => [__('promotions.errors.code_taken')]]);
        }

        return $promotion;
    }

    /**
     * @param  array<string, mixed>  $data  the form's state; the code / type / value keys, if any, are ignored
     *
     * @throws PromotionInvalidException
     */
    public function update(string $id, array $data): Promotion
    {
        $promotion = $this->promotions->findById($id);

        if ($promotion === null) {
            throw new PromotionInvalidException(['code' => [__('promotions.errors.not_found')]]);
        }

        $errors = [];
        $editable = $this->editable($data, $errors);
        $scopes = $this->parseScopes($data['scopes'] ?? [], $errors);

        if ($errors !== []) {
            throw new PromotionInvalidException($errors);
        }

        $before = self::snapshot($promotion, $this->managedScopeKeys($promotion));

        try {
            $promotion->update(
                individualUseOnly: $editable['individualUseOnly'],
                excludeSaleItems: $editable['excludeSaleItems'],
                minimumSpend: $editable['minimumSpend'],
                maximumSpend: $editable['maximumSpend'],
                newCustomersOnly: $editable['newCustomersOnly'],
                usageLimitTotal: $editable['usageLimitTotal'],
                usageLimitPerCustomer: $editable['usageLimitPerCustomer'],
                usageLimitItems: $editable['usageLimitItems'],
                validFrom: $editable['validFrom'],
                validUntil: $editable['validUntil'],
                isPublic: $editable['isPublic'],
                publicDescription: $editable['publicDescription'],
            );
        } catch (InvalidArgumentException $exception) {
            throw new PromotionInvalidException(['code' => [$exception->getMessage()]]);
        }

        ($data['is_active'] ?? $promotion->isActive()) ? $promotion->activate() : $promotion->deactivate();

        $after = self::snapshot($promotion, self::scopeKeysOf($scopes));

        // an update that changes nothing writes nothing: no save, no audit entry
        if ($after === $before) {
            return $promotion;
        }

        DB::transaction(function () use ($promotion, $scopes, $before, $after): void {
            $this->promotions->save($promotion);
            $this->reconcileScopes($promotion, $scopes);
            $this->auditChanges((string) $promotion->id(), $before, $after);
        });

        return $promotion;
    }

    /**
     * Switches a promotion on or off (the domain's activate() / deactivate()); nothing else about it changes.
     *
     * @throws PromotionInvalidException
     */
    public function setActive(string $id, bool $active): Promotion
    {
        $promotion = $this->promotions->findById($id);

        if ($promotion === null) {
            throw new PromotionInvalidException(['code' => [__('promotions.errors.not_found')]]);
        }

        // switching to the state it already has writes nothing
        if ($promotion->isActive() === $active) {
            return $promotion;
        }

        DB::transaction(function () use ($promotion, $active): void {
            $active ? $promotion->activate() : $promotion->deactivate();
            $this->promotions->save($promotion);
            $this->audit->logFieldChanged(self::ENTITY, (string) $promotion->id(), 'status', $active ? 'inactive' : 'active', $active ? 'active' : 'inactive');
        });

        return $promotion;
    }

    /**
     * Parses and checks the EDITABLE fields, collecting every wrong one (the same checks the domain makes, so each is a message under ITS field).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>
     */
    private function editable(array $data, array &$errors): array
    {
        $currency = DefaultCurrency::get();

        $min = $this->optionalMoney($data['minimum_spend'] ?? null, 'minimum_spend', $currency, $errors);
        $max = $this->optionalMoney($data['maximum_spend'] ?? null, 'maximum_spend', $currency, $errors);

        if ($min !== null && $max !== null && ! $max->subtract($min)->isPositive()) {
            $errors['maximum_spend'][] = __('promotions.errors.maximum_not_above_minimum');
        }

        $limits = [];

        foreach (['usage_limit_total', 'usage_limit_per_customer', 'usage_limit_items'] as $field) {
            $raw = $data[$field] ?? null;
            $limits[$field] = null;

            if ($raw === null || trim((string) $raw) === '') {
                continue;
            }

            if (! preg_match('/^\d{1,10}$/', trim((string) $raw)) || (int) $raw < 1 || (int) $raw > 4294967295) {
                $errors[$field][] = __('promotions.errors.limit_invalid');

                continue;
            }

            $limits[$field] = (int) $raw;
        }

        $from = $this->optionalDate($data['valid_from'] ?? null, 'valid_from', $errors);
        $until = $this->optionalDate($data['valid_until'] ?? null, 'valid_until', $errors);

        if ($from !== null && $until !== null && $until <= $from) {
            $errors['valid_until'][] = __('promotions.errors.until_not_after_from');
        }

        $isPublic = (bool) ($data['is_public'] ?? false);
        $description = $isPublic ? trim((string) ($data['public_description'] ?? '')) : null;

        if ($isPublic) {
            if ($description === '') {
                $errors['public_description'][] = __('promotions.errors.description_required');
            } elseif (mb_strlen($description) > Promotion::PUBLIC_DESCRIPTION_MAX_LENGTH) {
                $errors['public_description'][] = __('promotions.errors.description_too_long', ['max' => Promotion::PUBLIC_DESCRIPTION_MAX_LENGTH]);
            } elseif (preg_match('/[\x00-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $description) === 1) {
                $errors['public_description'][] = __('validation.plain_text', ['attribute' => __('promotions.fields.public_description')]);
            }
        }

        return [
            'individualUseOnly' => (bool) ($data['individual_use_only'] ?? false),
            'excludeSaleItems' => (bool) ($data['exclude_sale_items'] ?? false),
            'minimumSpend' => $min,
            'maximumSpend' => $max,
            'newCustomersOnly' => (bool) ($data['new_customers_only'] ?? false),
            'usageLimitTotal' => $limits['usage_limit_total'],
            'usageLimitPerCustomer' => $limits['usage_limit_per_customer'],
            'usageLimitItems' => $limits['usage_limit_items'],
            'validFrom' => $from,
            'validUntil' => $until,
            'isPublic' => $isPublic,
            'publicDescription' => $description,
        ];
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function optionalMoney(mixed $raw, string $field, mixed $currency, array &$errors): ?Money
    {
        $text = self::text($raw);

        if (trim($text) === '') {
            return null;
        }

        $money = MoneyInput::parse($text, $currency);

        if ($money === null) {
            $errors[$field][] = __('validation.money_format', ['attribute' => __('promotions.fields.'.$field)]);
        }

        return $money;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function optionalDate(mixed $raw, string $field, array &$errors): ?DateTimeImmutable
    {
        $text = self::text($raw);

        if (trim($text) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($text);
        } catch (Throwable) {
            $errors[$field][] = __('promotions.errors.date_invalid');

            return null;
        }
    }

    /**
     * 12.5 / 12,5 / 12.50 => 1250 basis points: by string arithmetic (no float), at most two decimals, 0.01 to 100.
     */
    public static function parsePercent(mixed $raw): ?int
    {
        $text = str_replace(',', '.', trim(self::text($raw)));

        if (! preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $text, $match)) {
            return null;
        }

        $basisPoints = ((int) $match[1]) * 100 + (int) str_pad($match[2] ?? '', 2, '0');

        return $basisPoints >= 1 && $basisPoints <= 10000 ? $basisPoints : null;
    }

    /** The basis points as the text a merchant types: 1250 => "12.5", 1000 => "10". */
    public static function percentText(int $basisPoints): string
    {
        $text = number_format($basisPoints / 100, 2, '.', '');

        return rtrim(rtrim($text, '0'), '.');
    }

    private static function text(mixed $raw): string
    {
        return is_scalar($raw) ? (string) $raw : '';
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<array{type: PromotionScopeType, mode: PromotionScopeMode, reference: string}>
     */
    private function parseScopes(mixed $rows, array &$errors): array
    {
        $out = [];
        $seen = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $type = PromotionScopeType::tryFrom((string) ($row['type'] ?? ''));
            $mode = PromotionScopeMode::tryFrom((string) ($row['mode'] ?? ''));
            $reference = trim(self::text($row['reference'] ?? null));

            if ($type === null || ! in_array($type, self::SCOPE_TYPES, true) || $mode === null || $reference === '') {
                $errors['scopes'][] = __('promotions.errors.scope_invalid');

                continue;
            }

            $key = $type->value.'|'.$mode->value.'|'.$reference;

            if (isset($seen[$key])) {
                continue;
            }

            // a chosen record must exist (a tampered id is refused, never saved)
            if (! $this->reader->exists($type, $reference)) {
                $errors['scopes'][] = __('promotions.errors.scope_invalid');

                continue;
            }

            $seen[$key] = true;
            $out[] = ['type' => $type, 'mode' => $mode, 'reference' => $reference];
        }

        return $out;
    }

    /**
     * The values the audit compares, by the FORM's own field names, as short readable strings: money as 79.99, dates as Y-m-d H:i, booleans as 1 / 0, a missing value as an empty string, the scopes as one compact
     * sorted list (`brand:include:12, tag:exclude:7`). Only the scope types the form manages; nothing about a customer ever goes in.
     *
     * @param  list<string>  $scopeKeys  `type:mode:reference` of every managed scope
     * @return array<string, string>
     */
    private static function snapshot(Promotion $promotion, array $scopeKeys): array
    {
        $flag = static fn (bool $value): string => $value ? '1' : '0';
        $int = static fn (?int $value): string => $value === null ? '' : (string) $value;
        $money = static fn (?Money $value): string => $value === null ? '' : $value->decimalValue();
        $date = static fn (?DateTimeImmutable $value): string => $value === null ? '' : $value->format('Y-m-d H:i');

        $keys = array_values(array_unique($scopeKeys));
        sort($keys);

        return [
            'minimum_spend' => $money($promotion->minimumSpend()),
            'maximum_spend' => $money($promotion->maximumSpend()),
            'new_customers_only' => $flag($promotion->newCustomersOnly()),
            'exclude_sale_items' => $flag($promotion->excludeSaleItems()),
            'individual_use_only' => $flag($promotion->individualUseOnly()),
            'usage_limit_total' => $int($promotion->usageLimitTotal()),
            'usage_limit_per_customer' => $int($promotion->usageLimitPerCustomer()),
            'usage_limit_items' => $int($promotion->usageLimitItems()),
            'valid_from' => $date($promotion->validFrom()),
            'valid_until' => $date($promotion->validUntil()),
            'is_public' => $flag($promotion->isPublic()),
            'public_description' => (string) $promotion->publicDescription(),
            'status' => $promotion->isActive() ? 'active' : 'inactive',
            'scopes' => implode(', ', $keys),
        ];
    }

    /** @return list<string> the `type:mode:reference` keys of the promotion's stored scopes of the types the form manages */
    private function managedScopeKeys(Promotion $promotion): array
    {
        $keys = [];

        foreach ($this->scopes->findByPromotionId((string) $promotion->id()) as $scope) {
            if (in_array($scope->scopeType(), self::SCOPE_TYPES, true)) {
                $keys[] = $scope->scopeType()->value.':'.$scope->mode()->value.':'.$scope->scopeReferenceId();
            }
        }

        return $keys;
    }

    /**
     * @param  list<array{type: PromotionScopeType, mode: PromotionScopeMode, reference: string}>  $scopes
     * @return list<string>
     */
    private static function scopeKeysOf(array $scopes): array
    {
        return array_map(static fn (array $row): string => $row['type']->value.':'.$row['mode']->value.':'.$row['reference'], $scopes);
    }

    /**
     * ONE activity-log entry per field that really changed (the same transaction as the save).
     *
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     */
    private function auditChanges(string $id, array $before, array $after): void
    {
        foreach ($after as $field => $new) {
            if ($before[$field] !== $new) {
                $this->audit->logFieldChanged(self::ENTITY, $id, $field, $before[$field], $new);
            }
        }
    }

    /**
     * Brings the promotion's scopes of the five offered types to exactly `$wanted`: the missing ones are attached, the extra ones detached. Any other type (ACCOUNT) is left alone.
     *
     * @param  list<array{type: PromotionScopeType, mode: PromotionScopeMode, reference: string}>  $wanted
     */
    private function reconcileScopes(Promotion $promotion, array $wanted): void
    {
        $existing = [];

        foreach ($this->scopes->findByPromotionId((string) $promotion->id()) as $scope) {
            if (in_array($scope->scopeType(), self::SCOPE_TYPES, true)) {
                $existing[$scope->scopeType()->value.'|'.$scope->mode()->value.'|'.$scope->scopeReferenceId()] = $scope;
            }
        }

        $wantedKeys = [];

        foreach ($wanted as $row) {
            $key = $row['type']->value.'|'.$row['mode']->value.'|'.$row['reference'];
            $wantedKeys[$key] = true;

            if (! isset($existing[$key])) {
                $this->scopes->attach(new PromotionScope(null, (string) $promotion->id(), $row['type'], $row['reference'], $row['mode']));
            }
        }

        foreach ($existing as $key => $scope) {
            if (! isset($wantedKeys[$key])) {
                $this->scopes->detach((string) $scope->id());
            }
        }
    }
}
