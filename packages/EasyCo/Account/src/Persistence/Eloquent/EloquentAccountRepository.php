<?php

namespace EasyCo\Account\Persistence\Eloquent;

use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Exceptions\EmailAlreadyRegisteredException;
use Illuminate\Database\QueryException;

/** Maps the Account entity onto the accounts table. */
final class EloquentAccountRepository implements AccountRepository
{
    public function save(Account $account): void
    {
        $model = $account->id() !== null
            ? AccountModel::findOrFail($account->id())
            : new AccountModel();

        $model->email = $account->email();
        $model->password = $account->passwordHash();

        try {
            $model->save();
        } catch (QueryException $e) {
            if ($this->isEmailUniqueViolation($e)) {
                throw EmailAlreadyRegisteredException::forEmail($account->email());
            }

            throw $e;
        }

        if ($account->id() === null) {
            $account->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?Account
    {
        $model = AccountModel::find($id);

        return $model !== null ? $this->toDomainAccount($model) : null;
    }

    public function findByEmail(string $email): ?Account
    {
        $model = AccountModel::where('email', strtolower($email))->first();

        return $model !== null ? $this->toDomainAccount($model) : null;
    }

    /**
     * Detects a violation of accounts_email_unique — confirmed via a
     * real SHOW CREATE TABLE against the dev database, not assumed
     * from Laravel's naming convention. SQLSTATE 23000 + driver error
     * code (MySQL 1062 / SQLite 19) is the primary check, then
     * errorInfo[2] narrows to this specific constraint — never
     * $e->getMessage() string matching (CLAUDE.md rule 3, mirrors
     * EloquentProductRepository::isProductSlugUniqueViolation()).
     */
    private function isEmailUniqueViolation(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $sqlState = $errorInfo[0] ?? null;
        $driverErrorCode = (int) ($errorInfo[1] ?? 0);

        if ($sqlState !== '23000' || ! in_array($driverErrorCode, [1062, 19], true)) {
            return false;
        }

        $driverErrorMessage = (string) ($errorInfo[2] ?? '');

        return str_contains($driverErrorMessage, 'accounts_email_unique')
            || str_contains($driverErrorMessage, 'accounts.email');
    }

    private function toDomainAccount(AccountModel $model): Account
    {
        return Account::reconstituteFromStorage(
            id: (string) $model->id,
            email: $model->email,
            passwordHash: $model->password,
        );
    }
}
