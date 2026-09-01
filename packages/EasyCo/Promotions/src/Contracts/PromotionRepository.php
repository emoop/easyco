<?php

namespace EasyCo\Promotions\Contracts;

use EasyCo\Promotions\Promotion;

interface PromotionRepository
{
    /**
     * @throws \EasyCo\Promotions\Exceptions\PromotionCodeAlreadyExistsException
     */
    public function save(Promotion $promotion): void;

    public function findById(string $id): ?Promotion;

    /**
     * Case-insensitive lookup — see Promotion::normalizeAndValidateCode()
     * and EloquentPromotionRepository::findByCode() for how that's
     * actually enforced (application-layer lowercasing on both write
     * and read, same convention as EasyCo\Account\Account/
     * EloquentAccountRepository::findByEmail() — not a DB collation
     * trick).
     */
    public function findByCode(string $code): ?Promotion;
}
