# EasyCo — Представяне на продукта (продуктова карта и продуктова страница)

**Статус:** проект за обсъждане
**Свързани документи:** дизайн настройки на страниците (page design editor), Catalog, Pricing, Promotions, Orders, Media

---

## 1. Цел

Документът описва как се представя продуктът в storefront-а:

- продуктовата карта в каталога (категория, търсене, секции на началната страница);
- продуктовата страница;
- кое е стойност по подразбиране на темата, кое се настройва в дизайн редактора и кое е настройка на самия атрибут.

Целта е мърчантът да сглобява страницата без код, а клиентът с един поглед да разбере дали продуктът е подходящ и изгоден за него.

EasyCo не е само за мода. Всяко решение трябва да работи и за техника (печки, хладилници) — там разликите са описани в раздел 6.

---

## 2. Принципи

1. **Йерархия на погледа.** Първо: снимка, марка, име, цена. Всичко второстепенно (SKU, категории, етикети) е с по-малък и по-блед шрифт и стои най-отдолу.
2. **Цената разказва история.** Не само крайната цена, а и старата цена и спестената сума в пари.
3. **Честна комуникация.** Брояч на промоция се показва само ако промоцията има реална крайна дата. Никога фалшива спешност.
4. **Гъвкавост без сложност.** Страницата е шаблон от зони и компоненти. Мърчантът мести, скрива и настройва компоненти, но не пише код.
5. **Един източник на истина.** Компонентите не изчисляват сами цени, наличности и отстъпки — четат ги от Pricing, Inventory и Promotions.
6. **Mobile-first.** Всяка настройка има отделна стойност за десктоп и мобилен, където това има смисъл.

---

## 3. Продуктова карта (каталог)

### 3.1. Снимка — промяна спрямо текущата имплементация

Текущото състояние: снимката е със свободно пространство отстрани и не запълва картата.

Новото изискване:

- снимката е **портретна (по-висока отколкото широка)**, пропорция **3:4** по подразбиране;
- заема **цялата ширина на картата**, без вътрешно разстояние отстрани;
- изображението запълва рамката (`object-fit: cover`), с фокус в горната-централна част, за да не се реже главата/горната част на дрехата;
- пропорцията е настройка на темата (3:4 по подразбиране, възможни 4:5 и 1:1 — например за техника, обувки, аксесоари);
- използва се media conversion със съответния размер, не оригиналът.

### 3.2. Елементи върху снимката

- **Badges** — максимум 2 едновременно, по приоритет:
  1. отстъпка (`-20%`);
  2. ново;
  3. последни бройки.
- **Любими (сърце)** — горе вдясно.
- **Размери** — на десктоп се показват при hover в долната част на снимката (наличните — нормално, изчерпаните — зачеркнати).
- **Втора снимка при hover** (само десктоп, включва се от темата, по подразбиране — да):
  - при hover главната снимка плавно се сменя с втората снимка на продукта;
  - втората снимка **не се зарежда предварително** — URL-ът стои в `data-` атрибут и браузърът я изтегля едва при първия hover върху картата;
  - използва се същият малък media conversion като за главната снимка;
  - ако продуктът има само една снимка — няма ефект;
  - на мобилен не се прилага (там няма hover и няма излишен трафик).

  Цена на ресурс: на сървъра — нулева (няма допълнителни заявки при зареждане на страницата); в браузъра — по една малка снимка само за картите, върху които клиентът реално е минал с мишката.

### 3.3. Под снимката

1. марка (малък, блед шрифт);
2. име на продукта (до 2 реда);
3. цена: стара цена зачеркната + нова цена в акцентен цвят;
4. цветове като кръгчета (ако продуктът има атрибут цвят);
5. звезди и брой отзиви (когато Reviews е включен).

---

## 4. Продуктова страница

### 4.1. Модел на страницата

```
Продуктова страница
├── Основна зона (две колони)
│   ├── Лява секция: Галерия            (ширина в % — настройка)
│   └── Дясна секция: Инфо колона       (плуваща: да/не)
│        └── компоненти, подредени в редове
└── Долна зона (на цяла ширина, по избор)
     └── табове | подобни | съчетай с | още от марката | отзиви | нищо → футър
```

Мърчантът в дизайн редактора:

- поставя компоненти в дясната секция и в долната зона;
- определя реда им;
- скрива компоненти, които не иска;
- избира дали долната зона изобщо да съществува.

### 4.2. Галерия

Режимите се избират **отделно за десктоп и мобилен**.

**Десктоп:**

| Режим | Описание |
|---|---|
| Тъмбнейли вляво | Голяма главна снимка, малките снимки в колона вляво от нея |
| Тъмбнейли отдолу | Голяма главна снимка, малките снимки в ред под нея |
| Решетка в 2 колони | Снимките в две равни колони, заемат ~2/3 от ширината |

Настройки на решетката:

- ширина на галерията като % от страницата;
- при нечетен брой снимки последната е: **със същия размер** или **на цяла ширина**;
- при една-единствена снимка — тя е на цяла ширина на галерията.

**Мобилен:**

| Режим | Описание |
|---|---|
| Карусел | Една голяма снимка, следващата е леко загатната отстрани, стрелка за напред |
| Голяма + тъмбнейли | Една голяма снимка, малките снимки в ред отдолу |

Обичайна комбинация: решетка в 2 колони на десктоп → карусел на мобилен.

**Уголемяване на снимката:**

- по подразбиране — **модал (lightbox)** и на десктоп, и на мобилен;
- десктоп: клик → модал, увеличение с колелцето на мишката, навигация със стрелки;
- мобилен: докосване → цял екран, увеличение с два пръста, плъзгане между снимките;
- в модала се зарежда големият media conversion (zoom), не оригиналът.

**Hover ефект върху главната снимка (само десктоп):**

- при мишка върху снимката тя се уголемява леко **вътре в рамката си** (рамката не променя размера си, излишното се скрива);
- реализация: само CSS — `overflow: hidden` на рамката и `transform: scale(1.05–1.1)` с плавен `transition` на снимката;
- силата на увеличението е настройка на темата (изключено / леко / средно);
- без JavaScript, без допълнителни снимки, без натоварване;
- истинската детайлна лупа остава в модала (lightbox) — отделна лупа, която следва курсора, **няма да има** във версия 1.

### 4.3. Плуващата дясна колона

- Настройка: включено/изключено.
- Реализация: само CSS (`position: sticky`), без JavaScript.
- Активна само на десктоп.
- Има ефект само когато галерията е по-висока от колоната; иначе колоната стои нормално.
- Докато клиентът превърта снимките, колоната с цена, атрибути и бутон остава видима.

### 4.4. Компоненти на дясната колона

Подредба по подразбиране (мърчантът може да я променя):

1. марка;
2. име (H1);
3. звезди и брой отзиви;
4. цена — нова цена, стара цена зачеркната, „спестяваш X";
5. промо блок (виж 4.5);
6. атрибути (цвят, размер и др. — виж 4.6);
7. наличност („Последни 2 бр.", „Няма наличност");
8. брояч за количество + бутон „Добави в количката" + любими;
9. блок за увереност (виж 4.7);
10. акордеон (виж 4.8);
11. SKU, категории, етикети — малък, блед шрифт. Всяко от тях може да се скрие.

### 4.5. Промо блок

- Показва активна промоция или промокод, например: „Код ЕСЕН = още -10%".
- Визуално прилича на купон (прекъсната рамка).
- Цветовете (фон, рамка, текст) се избират от мърчанта в редактора, с ограничение за четим контраст.
- **Брояч** („още 2д 14ч 05м") — показва се само ако промоцията има крайна дата.
- Данните идват от Promotions; компонентът само ги показва.
- Версия 1: показва се **едно съобщение** — най-изгодната активна промоция за продукта. Повече съобщения едновременно — бъдещо разширение.

### 4.6. Атрибути

Начинът на показване е **настройка на самия атрибут**, не на темата:

| Тип показване | Пример |
|---|---|
| Swatch (кръгчета) | цвят |
| Бутони | размер |
| Падащо меню | атрибути с много стойности |
| Само в спецификации | технически характеристики |

Правила:

- изчерпаните стойности се показват **зачеркнати, но видими**;
- до размера може да има линк „таблица с размери" (съдържание — виж 4.8);
- избраната стойност се изписва до името на атрибута („Цвят: Син").

**CT-3 as built (настройката, без показване).** `catalog_attribute_definitions.display_type` (`swatch` | `buttons` | `dropdown` | `specs` — затворен набор в enum `AttributeDisplayType`, по подразбиране `dropdown`) се въвежда и редактира във формата на атрибута; всеки вид е разрешен за всеки тип атрибут. Страницата на продукта и картата още не го четат.

### 4.6.1. Два вида информация за размерите

| | Таблица с размери (шаблон) | Мерки на продукта |
|---|---|---|
| Какво е | Обща таблица със свободна структура — тяло, обувки, глава… (напр. обиколка гърди 96–100 см = M) | Реалните мерки на конкретния продукт (S: дължина 68 см, ширина 50 см…) |
| Къде се въвежда | Веднъж, като шаблон | В самия продукт |
| Къде се показва | Модал от линка „таблица с размери" до атрибута | Акордеон/таб „Размери" |
| Описание | 4.6.2 | 4.6.3 |

Двете могат да съществуват едновременно на един продукт.

### 4.6.2. Таблици с размери (шаблони)

Таблицата с размери е **шаблон**, а не текст, писан във всеки продукт.

**Свободна структура.** Шаблонът не е вързан за тяло или за дрехи. Мърчантът сам определя колоните и редовете, така че един и същ механизъм работи за:

| Вид | Примерни колони | Примерни редове |
|---|---|---|
| Тяло | Обиколка гърди, Талия, Ханш, Ръст (см) | XS, S, M, L, XL |
| Обувки | EU, UK, US, Дължина стелка (см) | 36, 37, 38… |
| Глава (шапки) | Обиколка глава (см) | S, M, L или 54, 56, 58 |
| Друго | каквото е нужно | каквото е нужно |

Всеки шаблон има:

- **име** (напр. „Мъжки тениски Nike", „Шапки — обиколка", „Хладилници — габарити");
- **вид** — свободен етикет за подреждане и филтриране в админа (тяло, обувки, глава…), без да влияе на логиката;
- **колони** — свободен списък: име + мерна единица (по избор: см, кг, мм, EU… или без единица), ред с плъзгане;
- **редове** — свободни етикети (не е задължително да съвпадат с атрибута Размер);
- **клетки** — текст, за да се допускат диапазони („96–100") и стойности като „42 ½";
- **текст преди и след таблицата** (по избор) — напр. „Как да се измерите";
- **снимка/схема** (по избор, от библиотеката Медия) — напр. къде се мери обиколката на главата.

Шаблон може да се **дублира**, за да се направи бързо подобен (напр. „Дамски тениски" от „Мъжки тениски").

**Техника.** Габаритите (височина, ширина, дълбочина, тегло) са индивидуални за всеки модел, затова не са шаблон. Въвеждат се като мерки на продукта (4.6.3) или като атрибути в спецификациите (4.6, тип „само в спецификации"). Шаблон има смисъл само когато една таблица важи за много продукти.

**Прикачване.**

- Мърчантът създава няколко шаблона.
- Шаблон се прикача по един от начините:
  1. **ръчно в продукта** — избор от списък;
  2. **по правило** — етикет и/или марка, напр. етикет „мъжки тениски" + марка „Nike";
  3. **шаблон по подразбиране** — когато нищо друго не съвпада.
- Ред на избор (първото съвпадение печели):
  1. ръчно прикачен в продукта;
  2. правило етикет + марка;
  3. правило само по етикет;
  4. правило само по марка;
  5. шаблон по подразбиране.
- Ако няма намерен шаблон — линкът „таблица с размери" не се показва.
- Показва се в модал от линка до атрибута размер.

### 4.6.3. Мерки на продукта

Наследник на WooCommerce плъгина „Product Sizes" на мърчанта, с няколко промени: мерките се дефинират в атрибута, стойностите на размера се вземат от продукта, а въвеждането става в таблица.

**1. Дефиниране на мерките — в настройките на атрибута**

- Атрибут от тип размер (напр. „Размер", „Размер обувки") има настройка **„Мерки"** — списък от показатели.
- Пример за „Размер": Ширина под мишници, Дължина от рамо, Дължина гръб, Дължина, Талия, Ханш, Ръкав от врата.
- Пример за „Размер обувки": Дължина на стелката.
- Всеки показател има: име, мерна единица (по подразбиране „см"), ред на показване.
- Списъкът се поддържа от мърчанта — без хардкоднати стойности в кода.

**2. Въвеждане в продукта — страничен модал (slide-over)**

- В редакцията на продукта има бутон **„Въведи мерки"**. Той отваря модал, който излиза отстрани на екрана.
- Стойностите на размера (S, M, L…) се вземат **наготово от атрибута на самия продукт**. Затова мерките винаги съответстват точно на размерите, които продуктът реално има.
- Ако продуктът още няма избрани стойности на размера, модалът не показва таблица, а подкана: „Първо изберете размерите на продукта".
- Мърчантът маркира с **чекбоксове** кои мерки важат за този продукт — по същия начин, по който се избират стойностите на атрибутите. Пример за тениска: Дължина от рамо, Ширина под мишници, Дължина, Ръкав от врата.
- Щом се маркират, се появява таблица за попълване:

```
           Дължина от рамо  Ширина под мишници  Дължина  Ръкав от врата
S          [ 68 ]           [ 50 ]              [ 70 ]   [ 20 ]
M          [ 70 ]           [ 53 ]              [ 72 ]   [ 21 ]
L          [ 72 ]           [ 56 ]              [ 74 ]   [ 22 ]
```

- Редовете са стойностите на размера на продукта (в реда на атрибута). Колоните са маркираните мерки (в реда от атрибута).
- Стойността е число (десетична запетая/точка). Мерната единица идва от показателя.
- Навигация с Tab между клетките; празна клетка = мярката не се показва за този размер.

**3. Синхронизация**

- Добавена стойност на размера в продукта → нов празен ред в таблицата.
- Премахната стойност → редът не се показва. Стойностите се пазят и се връщат, ако размерът бъде добавен отново.
- Размаркиран чекбокс на мярка → колоната се скрива. Стойностите се пазят по същия начин.

**4. Модел на данните (ориентировъчно)**

```
attribute_measures           — id, attribute_id, name, unit, sort
product_measures             — product_id, attribute_measure_id          (маркираните чекбоксове)
product_measure_values       — product_id, attribute_value_id,
                               attribute_measure_id, value (decimal)
```

Ключът на стойността е id на стойността на атрибута (не текстът „S"). Така преименуване на стойност не чупи данните.

**5. Показване на фронтенда**

- Акордеон или таб „Размери" — източник „от продукта" (4.8). Ако продуктът няма въведени мерки, елементът не се показва.
- Таблица: редове — размери, колони — мерки, единицата в заглавието на колоната („Дължина, см").
- Мобилен: хоризонтален скрол с фиксирана първа колона (размерите).
- Избраният от клиента размер се маркира в таблицата (по избор, настройка на темата).

**Поуки от текущия плъгин**

- При запис формата изпраща `measure[]` / `value[]`, а кодът чете `measures` — двете трябва да съвпадат. Таблицата с фиксирани редове и колони премахва този проблем.
- Новите редове използват един и същ ключ `new` и се презаписват. В новия модел няма свободно добавяне на редове.
- Стойностите на фронтенда трябва да се escape-ват (размерът в момента се извежда без `esc_html`).

### 4.7. Блок за увереност

Кратки редове с иконка:

- доставка — включително „още €X до безплатна доставка", изчислено спрямо количката;
- връщане;
- гаранция (особено за техника);
- монтаж (по избор, за техника).

Текстовете са глобални и се пишат веднъж (виж 4.8).

### 4.8. Акордеон и табове

**Акордеон** и **табове** са два отделни компонента. Могат да се ползват поотделно или заедно — например кратък акордеон в дясната колона и подробни табове в долната зона.

Всеки елемент има:

- заглавие;
- иконка (по избор);
- съдържание.

Съдържанието идва от един от три източника:

| Източник | Примери | Къде се редактира |
|---|---|---|
| От продукта | описание, състав, таблица с размери в см, спецификации | в продукта |
| Глобален текст | доставка, връщане, гаранция | веднъж, за всички продукти |
| Свободен текст | специален текст само за този шаблон | в дизайн редактора |

Глобалният текст е задължителен като възможност — иначе мърчантът би трябвало да пише „Доставка до 2 дни" във всеки продукт и да го редактира навсякъде при промяна.

### 4.9. Долна зона

Възможни секции, в избран от мърчанта ред:

- табове;
- „Съчетай с";
- „Още от марката";
- „Подобни продукти";
- „Препоръчани";
- отзиви;
- нищо — директно футър.

---

## 5. Отзиви (Reviews)

Reviews е **отделен малък домейн**. Зависи от Catalog и Orders, но нито Catalog, нито Orders зависят от него. Може да бъде добавен по-късно, без промени в съществуващите домейни.

Функционалност:

- **потвърдена покупка** — отзивът се маркира, ако клиентът има завършена поръчка с този продукт (проверка през Orders);
- **модерация** — нов отзив е „чака одобрение"; мърчантът одобрява или отхвърля;
- **оценка** със звезди + текст;
- **размер** (за мода) — „малък / точен / голям", с обобщение до избора на размер („повечето казват: точен размер"). Включва се по тип продукт;
- **снимки от клиенти** — по избор, минават през модерация и библиотеката Медия;
- защита от спам и ботове — същите принципи като при формите в checkout.

Компоненти за дизайн редактора:

- звезди + брой в продуктовата карта;
- обобщение до цената на продуктовата страница;
- пълен списък като таб или като секция в долната зона.

---

## 6. Стоки различни от мода

| Елемент | Мода | Техника |
|---|---|---|
| Пропорция на снимката | 3:4 | 4:5 или 1:1 |
| Ключови атрибути | цвят, размер | 3–4 ключови характеристики (обем, енергиен клас, ширина) |
| Останали атрибути | — | таблица „Спецификации" в таб/акордеон |
| Блок за увереност | доставка, връщане | доставка, гаранция, монтаж |
| Отзиви — размер | да | не |

Тези разлики се постигат чрез настройки (тип продукт, атрибути, шаблон), а не чрез отделен код.

---

## 7. Къде живее всяка настройка

| Настройка | По подразбиране (тема) | Дизайн редактор | Ниво атрибут/продукт |
|---|---|---|---|
| Пропорция на снимката в картата | 3:4 | ✓ | |
| Badges — приоритет и максимум | 2 | ✓ | |
| Режим на галерията (десктоп/мобилен) | решетка / карусел | ✓ | |
| Ширина на галерията | ~2/3 | ✓ | |
| Нечетна последна снимка | същия размер | ✓ | |
| Плуваща дясна колона | да | ✓ | |
| Втора снимка при hover в картата | да | ✓ | |
| Hover уголемяване в галерията | леко | ✓ | |
| Шаблон за таблица с размери | шаблон по подразбиране | ✓ (правила етикет/марка) | ✓ (продукт) |
| Списък с мерки (показатели) | — | | ✓ (атрибут) |
| Кои мерки важат + стойностите им | — | | ✓ (продукт) |
| Ред и видимост на компонентите | по 4.4 | ✓ | |
| Цветове на промо блока | от темата | ✓ | |
| Показване на SKU / категории / етикети | да | ✓ | |
| Тип показване на атрибут | — | | ✓ (атрибут) |
| Съдържание „от продукта" | — | | ✓ (продукт) |
| Глобални текстове | — | ✓ (глобални настройки) | |
| Размер в отзивите | — | | ✓ (тип продукт) |

---

## 8. Решения по отворените въпроси

| Въпрос | Решение |
|---|---|
| Втора снимка при hover в картата | Да, само десктоп, с отложено зареждане при първия hover (3.2) |
| Брой промоции в промо блока | Версия 1 — едно съобщение, най-изгодното (4.5) |
| Таблица с размери | Шаблони със свободни колони и редове (тяло, обувки, глава…); габаритите на техника са мерки на продукта — ръчно в продукта, по правило етикет/марка, или по подразбиране (4.6.2) |
| Мерки на конкретния продукт | Мерките се дефинират в атрибута, избират се с чекбоксове в продукта, въвеждат се в таблица в страничен модал (4.6.3) |
| Hover-zoom на десктоп | Леко CSS уголемяване вътре в рамката; без лупа, следваща курсора (4.2) |

---

## 9. Предложен ред на имплементация

Малки, самостоятелни стъпки:

1. Продуктова карта — новата снимка 3:4, на цялата ширина (поправка на текущото състояние) + втора снимка при hover.
2. Галерия — режими за десктоп и мобилен + lightbox + леко hover уголемяване.
3. Плуваща дясна колона.
4. Компонентите на дясната колона като отделни компоненти в дизайн редактора.
5. Акордеон и табове с трите източника на съдържание + глобални текстове.
6. Тип показване на атрибутите (swatch / бутони / меню / спецификации) + шаблони за таблици с размери.
6a. Мерки на продукта — мерки в атрибута, страничен модал в продукта, таблица в акордеона.
7. Промо блок с цветове и брояч.
8. Долна зона и секциите в нея.
9. Reviews домейн и неговите компоненти.

**PC-1 as built (step 1).** The card's frame is `aspect-ratio: var(--sf-card-ratio, 3 / 4)` (portrait, one variable) with `object-position: 50% 0`; the second photo (the second usable one, same rule as the first: READY, IMAGE, not `missing_at`) comes from the same listing query; its URLs sit in data- attributes and `storefront.js` requests it on the first mouse hover or keyboard focus of the card (focus only with a fine pointer, so a touch tap does not fetch it). Differs from §3.2 only by that keyboard focus.

**PC-2 as built (step 1, sizes on the card).** The sizes of a card are the values of the product's first `buttons` axis (CT-3), in the value order, at most 8; a sold-out size is crossed out. They come in ONE cacheable GET `/storefront/card-sizes?ids=…` (60 s, no cookie) for the page's cards: on the first mouse hover on desktop, and once when idle on touch devices (the strip slides up under the photo). This replaces §3.2's "на мобилен не се прилага" (line 60) by the owner's decision of 2026-10-25; with data saver on nothing is asked.

**GL-1 as built (step 1, the gallery).** ONE markup (`storefront/partials/gallery`), the modes are CSS under the storefront breakpoint (64rem); settings are the closed sets of `App\Settings\GallerySettings` (`storefront.gallery.*`, defaults grid / carousel / 65 % / `same` / light, no admin UI until GL-2). The usable rule (READY, not `missing_at`) is `CatalogReader::usableMedia`, shared with the card; the product's video joins the gallery in media order (no poster exists, so it shows its first frame, `preload="metadata"`) and is not in the lightbox. Chromium fetches a lazy image inside a `display:none` figure, so every big image after the first waits in `data-src` and `storefront.js` fills it when its figure can be seen (a `<noscript>` copy serves no-JavaScript). The lightbox opens the widest rendition (`large`), wheel zoom is anchored at the cursor by translating the image (not by changing `transform-origin`). Differs from §4.2 only by the video and the alt text "name — снимка N".

**GL-1.1 as built (spacing).** One variable per measure on `.sf-gallery`: `--sf-gal-gap: 0.5rem` (grid, thumbnails, stage-to-thumbnails, carousel), `--sf-gal-radius: 0.25rem` (tiles and thumbnails); `--sf-product-col-gap: 2rem` is the desktop column gap of `.sf-product` (was `gap-6` = 24px). Outer container untouched (`.sf-main`: max-width 1152px, 16px side padding).

**GL-3 as built (sticky column, §9 step 3).** At ≥ 64rem `.sf-summary` is `position: sticky` with `top: var(--sf-sticky-top)`, `align-self: start` and `max-height: calc(100vh - var(--sf-sticky-top) - 1rem)` (it scrolls inside itself on a short window). `--sf-sticky-top` is 1rem without a sticky header, and with one (`.sf-hdr-d-sticky`, the default) the height of the rows the server renders (`body:has(…)`); nothing sticks below 64rem. Spec step 3 is done.

**GL-3.3 as built.** The gallery and the summary are the two children of `div.sf-product-main` (its own two-column grid from 64rem, the same 65fr/35fr rules); the description stays a direct child of `article.sf-product`, so the sticky summary stops at the gallery bottom and never runs over it.

**GL-2 as built (the Design screen holds the values).** Two plain sections on the «Дизайн» screen, «Страница и карти» and «Продуктова страница» (global scope, no draft: «Запази» is live), described by ONE list of fields, `App\Storefront\PageDesign\PageDesign` (new field kind `number`, a decimal in a range). Their variables (`--sf-page-pad` as one `clamp()`, `--sf-card-*`, `--sf-gal-gap`, `--sf-gal-radius`, `--sf-product-col-gap`, and `--sf-summary-position: static; --sf-summary-max-height: none` when the sticky column is off) are stored as one JSON setting per section (`design.page.global`, `design.product.global`) and compiled into the token stylesheet ONLY when they differ from the defaults, so an untouched shop is byte-identical; lengths are entered in px and written in rem (px / 16, 4 decimals). The five gallery choices stay the `storefront.gallery.*` keys of `GallerySettings`. Saving recompiles and replaces the reader's cache entry; «Върни по подразбиране» fills the panel and «Запази» stores it.

**GL-2.1 as built (toolbar).** The two plain sections of the Design screen show the top banner's toolbar («Запази», no draft, so no «Приложи» and no «Публикувай»); the one extra button is «Върни по подразбиране», which GL-2 gives them on purpose.

**DS-5 as built (one button model).** Every zone of the Design screen, the two plain sections included, has a working copy (a draft) beside its published value; «Приложи» stores the draft, «Публикувай» publishes it (and writes `storefront.gallery.*` for the product section), «Начални настройки» fills the working copy. See `design-screen-design.md`, «DS-5 as built».


**SC-1 as built (the right column is a list of components).** The product page's right column prints the published layout of the zone «Дясна колона» (`design.product_summary.global.*`, compiled into `design.compiled`): brand, name, SKU, short description, price, stock, purchase (the variation select and the add-to-cart box, unchanged) and HTML blocks, in the order the merchant chose; the default layout is today's page byte for byte. See `design-screen-design.md`, «SC-1 as built».

**SC-1.1 as built (loose ends).** Editing an HTML block that the published right column shows refreshes the column's compiled copy in the same rebuild that refreshes the footer (`MenuCompiler::compileAndStore`, both branches); a block the published right column holds is a user of the block (`HtmlBlockUsage::summary`, published and draft) and its delete is refused, the message naming «Дясната колона» with the other users.

**HB-1 as built («Стил на блока»).** An HTML block can be a styled box. `html_blocks.style` (nullable JSON, migration `2026_10_29_000001`) holds only what differs from the defaults (`bg`, `color`, `pad_v`, `pad_h`, `border_w`, `border_color`, `radius`, `width_mode` auto|full|fixed, `width_px`, `align`, `size`); all defaults = null. `App\Content\HtmlBlocks\HtmlBlockStyle` validates it and writes ONE rule per block from a closed template (`.sf-hb-{id}{…}`, no inline style on the shop); `AppearanceCompiler` adds the rules of the styled blocks (by id, at most 100) to the token stylesheet and re-checks each with `HtmlBlockStyle::isValidRule` (a regex over the whole rule); with no styled block the stylesheet is what it was. `HtmlBlockModel::renderMany` is the one place a styled block gets its wrapper `div.sf-hb.sf-hb-{id}` (the mega menu, the footer and the right column print what it returns; the compiled copies carry it; `HtmlBlockSanitizer::render` keeps exactly that wrapper through its second cleaning); a block with no style is not wrapped. Saving, restyling or deleting a styled block rebuilds the token stylesheet at once (and the compiled copies follow through the SC-1.1 path). The form's preview is the only inline style of the feature (an admin page).

**HB-2 as built («Показвай, когато…»).** An HTML block can be shown only for some products. `html_blocks.conditions` (nullable JSON, migration `2026_10_29_000002`; null = shown everywhere) holds only the keys that are set: `on_sale` (true / false: a `false` IS a condition), `categories` (≤ 50, descendants count), `tags` (≤ 50), `seasons` (≤ 20), `attributes` (≤ 5 rules `{attribute, values ≤ 20}`, an attribute once). ALL set conditions must hold (AND); inside a list any value is enough (OR). There is no «new» condition: the shop has no concept of a new product (no flag, no time window). `App\Storefront\Conditions\ProductConditions` is the one place: it validates (closed keys, ids that exist, caps, duplicates), keeps the named predicates and evaluates a block's conditions through them. `ProductContext` is built once per request from the product page's own read model; the sale state is the price block's regular price (so a sale that ends by date or is switched off stops matching with no page cache in between: the storefront has none); categories (with their ancestors), tags, season and attribute values are read lazily, one small query each, and only when a block or a template asks. The right column's compiled copy carries the conditions of the blocks that have some (the key is absent when none has); `ProductController` filters the list per product, and a block that is not for the product prints nothing at all. The footer and the menus ignore the conditions (there is no product there). A conditions-only edit refreshes the compiled copy through the SC-1.1 path. The form puts «Стил на блока» and «Показвай, когато…» side by side (two columns, stacked on a phone), compact, each closed while empty and open when set; the ids are checked again on the server.

**Theme hooks.** The same predicates are open to a theme: `@productIs('on_sale') … @elseproductIs('in_category', 12) … @else … @endproductIs` (Blade::if, `ProductConditionsServiceProvider`), for the product of the page being rendered (`CurrentProduct`, filled by `ProductController`; outside a product page every predicate is false). Predicates (`ProductConditions::predicates()`): `on_sale` (`true` default, `false` = not on sale), `in_category` (an id or a list; descendants count), `has_tag`, `in_season`, `has_attribute` (attribute id, then a value id or a list; no values = any value of that attribute). Examples: `@productIs('on_sale', false)`, `@productIs('has_attribute', 5, [5001, 5002])`. A name that does not exist throws outside production and is false in production. To add a predicate, add an entry (`name => fn (ProductContext $c, array $params): bool`) to `ProductConditions::predicates()`; the admin conditions and the directive both read that one list.

**AT-1 as built (цвят на стойност, данни без показване).** The attribute's display type already existed since CT-3 (`catalog_attribute_definitions.display_type`: `dropdown` | `buttons` | `swatch` | `specs`); AT-1 reuses it: «Падащ списък» = `dropdown`, «Бутони» = `buttons` (hint «подходящо за размери»), «Цвят (кръгчета)» = `swatch` (the «colour» type of the brief); `specs` stays as a fourth option. New: `catalog_attribute_values.colour` (nullable `varchar(7)`, `#rrggbb` lower-case; migration `2026_10_29_000001`, idempotent). `AttributeValue::normaliseColour()` is the one validator (empty/null = no colour; `#fff`, `red`, anything else refused); the admin value form, the API (`POST /api/attribute-values`, field `colour`) and the constructor all go through it. The picker (with «Без цвят» and the muted «няма цвят — ще се покаже като текст») is visible only while the value's attribute is `swatch`; a hidden picker is not submitted, so the stored colour is kept when the type is changed to «Бутони» and back. The list has a colour column (dot) for values of a `swatch` attribute only. Read model: `ProductPage::optionDisplay` runs parallel to `options` — per attribute `displayType` and `colours` (value => `#rrggbb`|null, filled only for `swatch`, `[]` for every other type); it rides on the query that already loads the values (no extra query), is NOT in `toArray()` (the JSON contract and the golden fixtures are unchanged) and nothing prints it yet (PB-1 will). A colour and a type are presentation only: they are never part of a variation's signature, SKU, slug, or any order line (a test compares the variation rows before and after).

**PB-1 as built («Покупка»).** The right column's locked `purchase` component is ONE block (`partials/summary/purchase.blade.php`, logic in `storefront-cart.js`): attribute pickers by the attribute's display type (AT-1: `swatch` → round swatches coloured by `data-colour` that the script applies through the CSSOM, never an inline style in the HTML; `buttons` → rectangular buttons; `dropdown`/`specs` → the dropdown; a value of a swatch attribute with no colour is a text button), a quantity counter and the add-to-cart button. The page's own `<select name="variation">` stays the source of truth: with the script (`html.js`) it is only visually hidden, a click on a choice sets it and fires the same `change`; without the script it is shown as before (and, as before, nothing can be added to the cart without the script: the cart API is JSON + CSRF). `specs` is not a picker (it is for the specification table), so an axis of that type stays a dropdown. A product mixing picker and dropdown axes gets a small select per dropdown axis. Pickers are used only when every variation can be placed in the groups (unique attribute names, no missing attribute); otherwise today's select stays alone. Availability is computed in the script from ONE escaped `data-sf-purchase` attribute (`{pickers, groups, variations: [{id, v: value index per group, o: can be bought, q: max quantity}]}`, ids and flags only): a value is unavailable when no orderable variation has it together with what is chosen in the other groups; it is greyed and `aria-disabled`, never hidden. `q` is the stock capped at 99 (`VariationView::maxQuantity`, from the stock read the page already makes; it is not in `toArray()`). The button has four states: normal, before a choice («Избери размер», aria-disabled, a click outlines the first missing group), sold out («Изчерпан») and busy. Settings of the component (validated by `ProductSummaryValidator` from the catalog schema): `label`, `label_choose`, `label_sold_out` (plain text ≤ 30, empty = the shop's own wording), `bg`, `color`, `border_color` (`#rrggbb` or none; the 3-digit form is refused), `border_w` (0–4), `rounded`. `PurchaseStyle` turns them into the CLOSED list `--sf-buy-bg`, `-bg-hover` (computed: a fixed step darker, lighter on a dark background), `-color`, `-border` (`#rrggbb` | `currentcolor`), `-bw` (`1px`..`4px`), `-radius` (`0`), `-off` (`0.5`, the disabled dimming), only when they differ from the defaults; `AppearanceCompiler::css(... $buyVars)` re-checks each with `PurchaseStyle::isValidVar` and publishing the layout rebuilds the token stylesheet. `storefront.css` reads them with the shop's own button as the fallback, so with no setting the stylesheet is what it was. The Design editor has its own compact form (two fields per row), a preview of the button in three states (an inline style in that admin preview only) and a non-blocking contrast warning (`AppearanceSettings::MIN_CONTRAST`). The draft preview shows the new pickers and texts; the button's colours come from the token stylesheet, so they show after «Публикувай».

**PB-1.1 as built (polish of «Покупка»).** The counter has no visible label (an `aria-label` on the input, the old translation) and sits with the add-to-cart button in ONE row (`.sf-buy-row`, `.sf-qty` 6.5rem fixed, the button the rest; both min 2.75rem high, today's button was 3rem); the status line stays under the row. Two new settings of the `purchase` component: `show_quantity` (bool, default true; false = no counter markup at all, the script finds no counter elements and posts quantity 1) and `axes` (a list of at most 10 whole positive attribute-definition ids, default empty; the validator refuses a map, a non-int, a negative or an over-long list, and drops duplicates). `PurchaseView::build($product, $settings)` reorders `options` and `optionDisplay` by `axes` BEFORE any group index is built (listed axes the product has first, the others after in the page's own order, foreign ids ignored; an axis is never hidden), so the variation table and the script stay consistent. `ProductPage::optionDisplay[n]['id']` is the attribute definition id (from the `def.id` the reader already selects; not in `toArray()`, no new query). CSS: a larger gap between `.sf-picker` groups (1.25rem), `.sf-summary` gets `padding-inline: 6px` where it is sticky/`overflow-y: auto` (≥ 64rem) so the focus ring of the first choice is not clipped, `.sf-summary .sf-price-current` is 1.15rem (weight unchanged). The stock line is `text-xs` (0.75rem) already, so it was NOT enlarged to 0.8125rem (see the report).

**FN-1 as built (two self-hosted fonts).** `AppearanceSettings::FONTS` (the closed list of the body and the heading font in «Облик») gained `montserrat` and `cormorant` (Cormorant Garamond). They are STATIC families, unlike the three variable ones: `scripts/copy-storefront-fonts.mjs` (run by `npm run build`) copies from `@fontsource/montserrat` (300, 400, 500, 600) and `@fontsource/cormorant-garamond` (400, 500, 600 and italic 500) the `woff2` of three subsets (latin, latin-ext, cyrillic) into `public/fonts/storefront/{key}/` with the OFL licence, and writes each face into `fonts.json` with `weight`, `style` and the subset's own `unicode-range`. The file is committed. `AppearanceCompiler::fontFaces` prints a face that has a `weight` as one weight/style rule (`format('woff2')`, `font-display: swap`) and re-checks weight (100..900), style (`normal|italic`), file name, range and family name; a face without a weight is a variable one and is printed byte for byte as before. `serif: true` in the manifest gives the heading stack its serif fallback. The preload of a static BODY family is its regular latin file, stored as `preload` in `appearance.compiled` (a variable family stores nothing new and keeps the old file name). The heading font setting already existed (`appearance.font.heading`, default «same» = nothing emitted); `h1..h4` and the product title (`h1.sf-title`) read `--sf-font-heading`. Nothing else changed: no default, no new setting, a shop that chose none of the two gets the same stylesheet, preloads and pages. Known, NOT changed here: the `latin` and `cyrillic` faces of the three older variable families carry the unicode-range of `latin-ext` and `cyrillic-ext` (the copy script read the comment that belongs to the NEXT rule); see the FN-1 report.

**LK-1 as built (the default look of the right column).** CSS only (`storefront.css`), scoped to `.sf-summary` (cards, header, footer, cart and checkout are untouched). Colours are the shop's tokens with the style's value as the fallback, defined once on `.sf-summary`: `--sf-pdp-text: var(--sf-text, #1a1a1a)`, `--sf-pdp-muted: var(--sf-muted, #6b6b6b)`, `--sf-pdp-line: var(--sf-border, #d9d9d9)` (and `--sf-pdp-line-hover`, the line a step darker via `color-mix`). The token compiler writes a token only when the merchant changed it, so a shop that chose nothing gets the style's values and a shop that chose a colour in «Облик» keeps its choice. Values as built: brand 12px/400/uppercase/0.12em/muted; title 26px (30px from 48rem), weight 500, line-height 1.15, no tracking; price 18px (20px from 48rem), weight 400, struck regular price 14px muted, the sale price keeps the accent rule; picker labels 12px/500/uppercase/0.08em; short description 14px/400/1.65; SKU and stock 13px/400, normal case, muted (the stock is no longer green); «Изчистване» 12px muted underlined (hover: text colour); size buttons min 48x44, 1px line, square, selected = 1px text-colour border (not filled, not bold), unavailable = muted, struck through, half opacity; swatches 28px, selected = a 1px ring 3px outside the circle (`box-shadow`), the focus ring 4px outside (inside the column's 6px padding); counter and button 48px high, counter a 1px line box with a light «−»/«+»; button 13px/500/uppercase/0.14em, `--sf-buy-bg` > `--sf-btn-bg` > `--sf-primary` > `#1a1a1a`, hover `--sf-buy-bg-hover` > `--sf-primary-hover` > `#3a3a3a`. Rhythm (px): brand→title 8, title→price 16, price→first group 24, between groups 24, groups→button 24, button row→next ~32 (the status line is 20px high). No separators between sections (they come with the accordion). The purchase setting `rounded` now defaults to FALSE (square): `true` emits `--sf-buy-radius: var(--sf-r-btn, var(--radius-md))` (the one value `PurchaseStyle::isValidVar` accepts), `false` emits nothing, and the CSS fallback of `.sf-buy` is square. A layout published before LK-1 stores `rounded: true` (it was the default then), so such a shop keeps its rounded button until the merchant unticks it. Headings: a font may carry `heading_weight` in `fonts.json` (`cormorant`: 600, it has no bold); the compiler then writes `--sf-heading-weight` (for the effective heading font, «as the body» included, closed list: an integer 100..900) and the two classes that were bold (`.sf-title`, `.sf-404 h1`) read it with 700 as the fallback, so nothing is faked and a shop without such a font gets the same stylesheet. Differences from the spec file: `lk-1-style-spec.md` was not present in the repository, so the values of the task text were used.

**LK-2 as built (product cards in the lists).** CSS only (`storefront.css`), no markup, no setting; the card is `partials/card.blade.php` (printed by `category.blade.php`, which also serves search, and `home.blade.php`), the border and radius of GL-2 are untouched. On `.sf-card` the two LK-1 colours are defined again with the same fallbacks (`--sf-pdp-text: var(--sf-text, #1a1a1a)`, `--sf-pdp-muted: var(--sf-muted, #6b6b6b)`) because the cards are not inside `.sf-summary`; a merchant's colour in «Облик» wins. Values as built (desktop / phone): name 15px / 14px, weight 400, normal case, line-height 1.4, in `var(--font-sans)` (the BODY font; the card name is an `h3`, which `h1..h4` would otherwise give the heading font), two lines with an ellipsis and `min-height: 2.8em` so a one-line name still reserves them and the prices of a row line up; brand 11px/400/uppercase/0.12em/muted, one line with an ellipsis; price 14px / 13px, weight 400, the struck old price 13px muted, the sale price keeps the accent rule (the shop's accent token, else the text colour); image → name 12px, brand → name 4px, name → price 8px (the price block keeps `margin-top: auto`, so rows align). The out-of-stock line, the badges, the sizes strip and the hover image are untouched (the «Изчерпан» line keeps its uppercase 12px/500; it now looks heavier than the new prices: left as it was on purpose, see the report). The product page and everything else keep the heading font and weights of LK-1.

**HB-3 as built (plain paragraph text in the HTML block editor).** The sanitiser already kept `<p>` and `<br>` (attributes stripped), so `HtmlBlockSanitizer` is unchanged. The editor had no way back to plain text: it gets Filament's own `paragraph` tool (TipTap `setParagraph`), placed after the heading buttons (`['h2', 'h3', 'h4', 'paragraph']`). The tool is re-declared in `HtmlBlockResource` only for its Bulgarian label («Параграф», lang key `html_blocks.tools.paragraph`; the vendor's Bulgarian file has no such key, so it would show English). The button's label is also its tooltip and `aria-label`, so the tooltip reads «Параграф», not «Обикновен текст» as the task text suggested. CSS (`storefront.css`, next to `.sf-summary-html img`): `p` inside `.sf-summary-html`, `.sf-mega-html` and `.sf-ft-html` gets `margin: 0 0 0.5em`, and `:last-child` gets `margin-bottom: 0`; the reset of the storefront gives `p` no margin at all, so without the rule paragraphs stacked with no gap. Nothing else changed: a block saved before this task prints byte-identically (existing HB-1/HB-2/SC-1 tests and fixtures pass).

**HB-3.1 as built (paragraphs in blocks).** The text of a centred block stays centred: measured with and without the HB-3 rule (right column, desktop and phone), the horizontal gaps of every line are identical, so the HB-3 margins never moved the text sideways. The rule is now `p { margin: 0 }` and `p + p { margin-top: .5em }` in the three places (right column, mega menu, footer): a gap between paragraphs only, no bottom margin. Open, not changed here: a centred block with «fit-content» width is only as wide as its widest line and sits at the column's left edge (its text is centred inside that box, so it reads as left-aligned); the cause is the compiled style of HB-1 (`HtmlBlockStyle::css`), whose tests pin the exact CSS.

**HB-3.2 as built (alignment of a block).** `HtmlBlockStyle::css` gives a fit-content box (`width_mode` auto) two margins when it is aligned: centre `margin-left:auto;margin-right:auto`, right `margin-left:auto`; left, full-width and fixed-width boxes get nothing new (a fit-content box stayed at the place's left edge, so a centred block read as left-aligned). `isValidRule` accepts exactly these two margin declarations. Places: the right column and the mega menu's media cell are block containers, so the margins act there; the footer column is a flex column whose `align-items` is the footer's own alignment, so inside it a fit-content box is placed by that alignment, not by these margins. Expectations changed by hand: `HtmlBlockStyleTest` (the centre and right rows, the brief's example) and `HtmlBlockStylePrintingTest` (the stored rule).


## HB-4 as built (a background picture in «Стил на блока»: banners)

- **Keys** (closed validation in `HtmlBlockStyle::validate`, only non-defaults stored): `bg_image` (a MEDIA ID, a picture of the library; nothing else), `bg_size` cover (default) | contain, `bg_position` center (default) | top | bottom | left | right, `overlay` 0 (default) | 20 | 40 | 60 (percent black), `min_height` 0 (default) | 160 | 240 | 320 | 400 (px), `valign` top (default) | center | bottom. Without `bg_image` the other five are ignored (even a bad value) and never stored. Whether the id is a picture of the library is checked by the form (`HtmlBlockBackground::isPicture`) and again, as «can I resolve it», when the stylesheet is built.
- **Resolving the address (`HtmlBlockBackground`):** at compile time, from the library's own record: the LARGEST customer rendition (the WebP variants, once the asset is ready), else the original file, as a path of the shop (`/storage/…`). Only a path that matches `HtmlBlockBackground::URL_PATH` ever leaves it (letters, digits, `. _ / -`, no `..` or `//`, a picture extension); `HtmlBlockStyle::isValidRule()` re-checks the very same pattern (and uses `~` as its delimiter), so nothing else can be inside `url(…)`. `css($id, $style, $imageUrl)` takes the resolved address; a picture that is gone or unusable resolves to nothing and the block compiles WITHOUT its picture keys (as if it had none; with nothing else set there is no rule at all). `storedRules()` resolves all the pictures with ONE query. The admin's live preview resolves the address too (its inline style stays on an admin page only).
- **Compiled shape** (appended to the block's rule, in this order): `background-image:linear-gradient(rgba(0,0,0,.4),rgba(0,0,0,.4)),url(/storage/…)` (the veil only with overlay > 0; .2 / .4 / .6), `background-size`, `background-position`, `background-repeat:no-repeat`, `min-height:Npx` (> 0), and `display:flex;flex-direction:column;justify-content:center|flex-end` only with a minimum height and a valign other than top. Example: `.sf-hb-9{color:#ffffff;padding:20px 24px;width:fit-content;max-width:100%;margin-left:auto;margin-right:auto;text-align:center;background-image:…;min-height:240px;display:flex;flex-direction:column;justify-content:center}`.
- **Flex and HB-3.2:** the wrapper becomes a flex COLUMN only to place its content vertically (the children keep their own margins; they stretch to the box, the flex default, so `text-align` and the padding behave as before). A `fit-content` box is not stretched by it (its width is still `fit-content`) and keeps the margin-based centring / right alignment of HB-3.2; a full-width box fills its place as before. Checked in a real browser (`tests/Browser/hb-4.mjs`).
- **The phone:** the compiler emits one plain rule per block (no `@media`), so `min-height` is the same on a phone as on a computer (no « at most 240px on a phone » rule): the owner's second rule needs a media block in the stylesheet, which the compiler does not emit (open question).
- **Media library:** `App\Media\Usage\HtmlBlockBackgrounds` (registered in `MediaUsageRegistry`) reads `bg_image` from the styles of the blocks (it shares the ONE read of `html_blocks` with the two other block providers: `HtmlBlockRows` now reads every column), so the picture shows in the library's «used by» list, is not offered as «unused» and `MediaDeleter` refuses to delete it.
- **Form:** «Фонова снимка» (the media picker, full row) and, only with a picture, «Размер на снимката», «Позиция», «Затъмняване» (hint: a light text colour on a dark picture), «Минимална височина», «Текст по вертикала», in the existing compact two-column section. No migration (the style is a JSON column); no CSS file changed.
- **Not changed:** the 100-rule cap, the HB-1/2/3.x output of a block with no picture (byte-identical), the sanitiser.
