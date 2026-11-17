# CI: тестовете в GitHub Actions · Tests on GitHub Actions (CI-1)

Целият PHPUnit пакет се пуска автоматично в GitHub, разделен на 4 паралелни части. Не е нужен `--full` на собствения компютър.
The whole PHPUnit suite runs automatically on GitHub, split into 4 parallel shards. A local `--full` is no longer needed.

## 1. Какво се пуска · What runs

* Преди тестовете CI строи фронтенда · Before the tests CI builds the front-end: `npm ci && npm run build` (`public/build` не е в git · is not in git; `@vite` иска манифеста · needs the manifest).
* Тестовете фиксират `APP_URL=http://localhost:8000` във `phpunit.xml`, затова не зависят от локалния `.env`. · The tests pin `APP_URL=http://localhost:8000` in `phpunit.xml`, so they do not depend on the local `.env`.
* В CI настройките на базата са променливи на средата на процеса (там няма `.env.testing`). · In CI the DB settings are process environment variables (there is no `.env.testing` in CI).
* Кога · When: всеки `git push` към `main`, всеки pull request, и ръчно (**Actions → Tests → Run workflow**). По-нов push към същия клон спира по-старото пускане. · every push to `main`, every pull request, and manually; a newer push to the same ref cancels the older run.
* Файл · File: `.github/workflows/tests.yml`. 4 задачи (`shard 1…4`), всяка на `ubuntu-24.04` с MySQL 8.4 контейнер (базата `easyco_testing`, само за тази задача), PHP 8.3, Node 24 (няколко теста пускат скриптове под node). · 4 jobs, each on `ubuntu-24.04` with a MySQL 8.4 service container, PHP 8.3 and Node 24.
* Разделянето е по **тестов файл** · Sharding is by test **file**: `scripts/ci/shard.php` взима всички `*Test.php` от тестовите пакети на `phpunit.xml`, сортира ги и шард *k* получава файловете с `i % 4 == k-1`. `--verify` проверява, че 4-те шарда са точно целият списък, всеки файл веднъж (пуска се първо във всеки шард). · takes every `*Test.php` of the `phpunit.xml` testsuites, sorts, shard *k* gets files with `i % 4 == k-1`; `--verify` proves the shards are exactly the full list.
* Не се пуска · Not in CI: браузърните скриптове `tests/Browser/*.mjs`, всичко, което иска Laragon или снимките на собственика. · the Playwright scripts and anything needing Laragon or the owner's photos.
* Без тайни · No secrets: паролата на базата и `APP_KEY` са еднократни за задачата; логовете са публични. · the DB password and `APP_KEY` are throw-away values of the job; logs are public.

## 2. Как се чете червено пускане · How to read a red run

1. На коммита / в **Actions** отворете червения шард. · Open the red shard on the commit / in **Actions**.
2. Горе в **Summary** има таблица (tests / passed / failed / skipped) и имената на падналите тестове. · The job summary lists the counts and the failing test names.
3. Пълният изход е в стъпка **PHPUnit**; JUnit XML е в *Artifacts* (`junit-shard-N`, 7 дни). · Full output is in the **PHPUnit** step; the JUnit XML is an artifact for 7 days.
4. Един червен шард не спира останалите (`fail-fast: false`). GitHub праща писмо при червено. · One red shard does not stop the others; GitHub mails on a failure.

## 3. Как се пуска само един шард · Re-running one shard

* В GitHub: пускането → **Re-run jobs → Re-run failed jobs** (или само избрания шард). · In GitHub: the run → **Re-run failed jobs**.
* Локално · Locally (един шард, примерно първите 5 файла · one shard, e.g. the first 5 files):

```
php scripts/ci/shard.php --shard=1 --limit=5      # пише phpunit.shard.xml · writes phpunit.shard.xml
DB_DATABASE=<частната тестова база> APP_ENV=testing vendor/bin/phpunit --configuration phpunit.shard.xml
rm phpunit.shard.xml
```

Без `--limit` е целият шард. `rt.sh` остава непроменен за целеви пускания. · Without `--limit` it is the whole shard. `rt.sh` stays as is for targeted runs.

## 4. `--full` локално · local `--full`

За големи задачи вече не се пуска `--full` на компютъра на собственика: пуска се целевото (`./rt.sh <път>`), а целият пакет го пуска CI след push. · For big tasks run only the targeted tests; CI runs the whole suite after the push.
