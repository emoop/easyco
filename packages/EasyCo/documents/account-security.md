# Акаунти на клиентите: сигурност и имейли · Customer accounts: security and mails (ACC-1)

Само API; страниците са на ACC-3 (`/account/verify`, `/account/reset`, `/account/email/confirm`, `/account/login`, `/account/forgot`). · API only; the pages belong to ACC-3.

## 1. Правила · Rules

* **Регистрация · Registration** `POST /api/account/register`: винаги `202` със същото тяло и в `Timebox`, за нов, потвърден и непотвърден имейл. Не влиза в акаунта. Нов → непотвърден акаунт + писмо за потвърждение; потвърден → писмо «вече имате акаунт»; непотвърден → паролата се заменя + ново писмо за потвърждение. До 3 писма/час на адрес. · Always `202`, same body, inside a `Timebox`; never logs in.
* **Потвърждение · Verification** `POST /api/account/verify {id, expires, signature}` (24 ч): потвърждава и вдига hook `account.verified` (само първия път), отговор `200` с текст — **НЕ влиза в акаунта** (връзката доказва пощата, не е вход: регистрацията сменя паролата на непотвърден акаунт, а който кликне първи не бива да попада в чужда парола); вече потвърден → същото `200`, без промяна; иначе общо `422`. `POST /api/account/verify/resend` → винаги `202`, до 3/час на адрес. · Signed 24 h link.
* **Вход · Login**: грешен имейл или парола → едно и също `401` в `Timebox`; вярна парола, непотвърден акаунт → `403 email_unverified`; `remember` = 30 дни (бисквитката на guard-а). Заключване `LoginLockout` по **scope** (`admin`/`customer`) + имейл + IP, стъпки 5 мин / 30 мин / 24 ч, същото правило `SECURITY_MODE`; писмото-аларма е само за админите. · Same steps as the admin lockout.
* **Сесии · Sessions**: `accounts.session_version` се записва в сесията при вход; `EnsureAccountSessionIsCurrent` (след `auth:customer`) отписва при разлика. Повишава се при нова парола (reset/промяна) и смяна на имейл; `remember_token` се сменя. Сесия без стойност: приема се само при remember-вход или при акаунт с версия 0. · Bumped on password reset/change and email change.
* **Парола · Password** (`PasswordPolicy`): 8–72 **байта**, не е имейлът, не е в `resources/security/common-passwords.txt` (до 10 000 реда). · 8–72 bytes.
* **Забравена парола · Forgot**: `POST /api/account/password/forgot` → винаги `202`; за всеки съществуващ акаунт, и непотвърден (ресетът доказва пощата и я потвърждава, а версията на сесията убива всяка по-ранна сесия); токен в `account_password_reset_tokens` (хеш, 60 мин, един на имейл). `POST /api/account/password/reset` → нова парола, токенът се изтрива, версията расте, писмо «паролата е сменена», потвърждава имейла. `PUT /api/account/password` (влязъл): текущата парола; версията расте, тази сесия остава.
* **Смяна на имейл · Change email**: `POST /api/account/email {current_password, email}` → `202`; връзка към НОВИЯ адрес, известие към СТАРИЯ; имейлът се сменя само при `POST /api/account/email/confirm` (`account_email_changes`, хеш на токена, един на акаунт). Зает адрес → общо `422`.
* **Връзките · Links** (`SignedLink`): HMAC-SHA256 върху `purpose|id|expires|token` с ключ от `APP_KEY` (не са «Laravel signed URL»: подписът не е вързан за един URL, а страницата препраща параметрите към API). · HMAC, not a Laravel signed URL: the storefront page forwards the parameters.
* **Писма · Mails**: `AccountMails` → `SendAccountMailJob` (опашка `mail-transactional`, **криптирана** полезна нагрузка, връзката не влиза в `mail_log` и логовете), шаблони в `resources/mail/{bg,en}/account.*.md`.
* **Чистене · Prune**: `accounts:prune-unverified` (дневно): непотвърдени над 7 дни без поръчка.

## 2. Hooks (само id на акаунта · account id only)

`account.registered`, `account.verified`, `account.password_changed`, `account.email_changed` — след запис, никога парола или токен. · Fired after the write; never a password or token.

Редовете са в таблицата «Hook Reference» на `extensibility-design-and-hooks.md`; `account.registered` вече носи id (беше обектът `Account`). · The four rows are in that Hook Reference table; `account.registered` now carries the id.

## 3. Лимити · Limiters

`password-forgot`, `verify-resend`, `email-change` (5/мин на IP), `account-token` (10/мин на IP: verify, reset, confirm, смяна на парола); плюс бюджет 3/час на имейл в контролерите. Всички отговори на лимит остават общи. · Plus 3/hour per address in the controllers.

`ACCOUNT_TIMEBOX_US` (по подразбиране 500000 µs) трябва да е над един хеш на парола + пускане в опашката; вдигнете го, ако се вдигне `BCRYPT_ROUNDS`. · The timebox must stay above one password hash + the queue push; raise it if `BCRYPT_ROUNDS` goes up.

## 4. След деплой · After deploy

`php artisan migrate`, `php artisan queue:restart` (нов job), `php artisan optimize:clear`; `npm run build` (махнати `.sf-404` правила).
