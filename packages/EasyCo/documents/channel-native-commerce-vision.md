# Channel-Native Commerce Vision — Foundational Discussion

*Raw conversation transcript, not a formal design doc. Captures the
architectural shift from "e-commerce platform + modules" to "Modular
Commerce Platform with channel-native distribution" (Web/POS/Social/AI
as first-class channels, not bolt-on integrations) — referenced by
CLAUDE.md and later domain design docs. Kept close to its original
form rather than rewritten, since the reasoning trail (why each
conclusion was reached) is itself part of the value.*

---

Да. И това, което току-що описа, **променя една от основните ми постановки за EasyCo**.

До момента мислехме:

> **E-commerce platform + modules**

Аз вече бих го формулирал като:

> **Commerce Operating System за малък и среден физически/онлайн търговец, в който storefront-ът е само един от каналите.**

Това е много по-силна концепция.

И примерът ти с днешната поръчка от ChatGPT е изключително показателен. Това вече не е бъдеща теория: ChatGPT в момента реално показва продуктови резултати с изображения, цени, наличност и merchant links, а за някои merchants има и Instant Checkout. OpenAI изрично развива Agentic Commerce Protocol (ACP) като слой за свързване на merchant product feeds и promotions с AI shopping. ([OpenAI][1])

---

# 1. Ще променя фундаментално начина, по който мислим за EasyCo

Представи си реалния ден на твоя fashion merchant:

```text
                         НОВА СТОКА
                             │
                    ┌────────┴────────┐
                    │                 │
                 Физически         EasyCo
                  магазин             │
                    │                 │
                    │          Product / Inventory
                    │                 │
                    │        ┌────────┴─────────┐
                    │        │                  │
                    │     Website          Social / AI
                    │        │                  │
                    │      Storefront      Instagram
                    │                         Meta
                    │                         Google
                    │                         ChatGPT
                    │                         AI Agents
                    │
                    └──────────┬──────────────┘
                               │
                          CUSTOMER
                               │
                    ┌──────────┼──────────┐
                    │          │          │
                  Store      Website    Messenger
                    │          │          │
                    └──────────┼──────────┘
                               │
                             ORDER
```

**Това е commerce.**

Website-ът е само една от точките.

---

# 2. И най-важното: съдържанието вече е част от commerce

Твоят пример е великолепен:

> „Идва нова стока → снимам я в магазина → правя видео → качвам Story → клиентка го вижда → пита в Messenger → друга го вижда → follow → след време купува.“

Традиционната e-commerce архитектура мисли така:

```text
Product
   ↓
Website
   ↓
Cart
   ↓
Checkout
   ↓
Order
```

А реалният merchant workflow е:

```text
Product arrives
      ↓
Content created
      ↓
Social post
      ↓
Discovery
      ↓
Conversation
      ↓
Interest
      ↓
Product
      ↓
Purchase
```

Това е **social commerce funnel**, а не стандартен e-commerce funnel.

И EasyCo трябва да го разбира.

---

# 3. Затова предлагам нов фундаментален принцип

## **Product is the source of commerce truth.**

Не:

> Website is the source of truth.

А:

> **EasyCo owns the canonical commerce data and exposes it to every channel.**

Например един продукт:

```text
Product
├── Identity
├── Variants
├── Attributes
├── Media
├── Pricing
├── Inventory
├── Availability
├── Content
├── SEO
├── Social metadata
├── AI metadata
└── Channel availability
```

После:

```text
                    PRODUCT
                       │
        ┌──────────────┼──────────────┐
        │              │              │
      Web            Social          AI
        │              │              │
   Storefront      Instagram       ChatGPT
   Google          Facebook        Gemini
   SEO             Messenger       AI Agents
```

И тук вече виждам **много сериозен архитектурен диференциатор**.

---

# 4. EasyCo трябва да бъде „channel-native“

Не бих искал да имаме:

```text
E-commerce
   +
Social integrations
   +
AI integrations
```

Това е старото мислене.

Бихме имали:

```text
                    EasyCo Commerce Core
                           │
              ┌────────────┼────────────┐
              │            │            │
           Web          Social          AI
              │            │            │
          Storefront   Meta/IG       AI Agents
          API          Messenger     AI Search
          SEO          Content       Product feeds
              │            │            │
              └────────────┼────────────┘
                           │
                        Orders
```

**Channels са first-class citizens.**

---

# 5. Това има още една много важна последица

Не трябва да мислим само за:

> Product API

Трябва да имаме:

> **Commerce Discovery API / Channel APIs**

Защото AI агентът няма нужда просто от:

```json
{
  "name": "Dress",
  "price": 80
}
```

Той трябва да разбере:

```json
{
  "product": "...",
  "title": "...",
  "description": "...",
  "brand": "...",
  "category": "...",
  "attributes": {},
  "variants": [],
  "availability": {},
  "price": {},
  "media": [],
  "shipping": {},
  "returns": {},
  "merchant": {},
  "location": {},
  "related_products": [],
  "questions_and_answers": []
}
```

И тук вече има много интересен външен сигнал.

Google например вече въвежда **conversational attributes** за Merchant Center — включително Q&A, related products и document links — именно за да помогне на AI системите да разбират по-добре продуктите. ([Google Help][2])

Тоест нашата посока не е измислена във вакуум.

---

# 6. Ще добавя нов домейн към архитектурата

Досега мислехме:

```text
Catalog
Pricing
Inventory
Orders
Customers
...
```

Аз бих добавил:

# **Commerce Channels**

или по-точно:

# **Channel & Distribution**

Неговата работа няма да бъде да съдържа Instagram API integration.

Това е много важно.

Ще има:

```text
Channel
├── Channel definition
├── Product eligibility
├── Content transformation
├── Feed generation
├── Availability
├── Pricing presentation
├── URL / deep linking
├── Attribution
└── Publishing state
```

А конкретните интеграции ще бъдат adapters:

```text
Channel
   │
   ├── Web Adapter
   ├── Google Adapter
   ├── Meta Adapter
   ├── Instagram Adapter
   ├── ChatGPT / ACP Adapter
   ├── Marketplace Adapter
   └── Future AI Agent Adapter
```

Това е огромна разлика.

---

# 7. Social Media не трябва да бъде просто „plugin“

Тук бих коригирал и предишното ни мислене.

Ако Instagram е просто:

```text
Plugins
└── Instagram
```

ще изпуснем същината.

За merchant-а Instagram е **sales/discovery channel**.

Следователно EasyCo трябва да може да знае:

```text
Product X
   ↓
Instagram Reel
   ↓
Instagram Story
   ↓
Messenger conversation
   ↓
Product page
   ↓
Order #1234
```

Това ни позволява един ден да кажем:

> Тази рокля е донесла 17 разговора, 8 посещения и 3 продажби от organic Instagram.

Това е **commerce intelligence**, а не просто social integration.

---

# 8. И тук идва нещо още по-интересно: Content

Аз бих създал отделен домейн:

# **Content**

Не CMS в класическия смисъл.

А:

> **Commerce Content**

Той може да управлява:

```text
Product
   │
   ├── Product description
   ├── Lifestyle images
   ├── Videos
   ├── Reels
   ├── Stories
   ├── Posts
   ├── AI-generated descriptions
   ├── Social captions
   └── Channel-specific content
```

Тогава merchant workflow-ът става:

```text
Receive products
       ↓
Take photos/video
       ↓
Upload once to EasyCo
       ↓
EasyCo understands product
       ↓
Generate / prepare content
       ↓
Publish to channels
```

**Един input → много commerce outputs.**

Това вече е интересна продуктова идея.

---

# 9. А AI не трябва да бъде „AI feature“

Това е друга промяна, която бих направил.

Не:

```text
EasyCo
└── AI
```

защото това много бързо ще стане:

> „Имаме AI copywriter.“

Не това ни трябва.

AI трябва да бъде **external participant in the commerce ecosystem**.

```text
                    EasyCo
                      │
          ┌───────────┼────────────┐
          │           │            │
       Human       Channels       AI
          │           │            │
       Admin       Web/Social    Agents
```

И EasyCo трябва да може да бъде разбираем за AI agents.

---

# 10. И тук днешната ти поръчка е супер важен сигнал

Това:

> „Поръчка дошла от ChatGPT.com.“

за мен не е просто забавна случка.

Това трябва да стане **architectural requirement**.

Трябва да можем да кажем:

```text
Order #1234

Source:
    AI

Agent:
    ChatGPT

Discovery:
    Product recommendation

Channel:
    AI

Attribution:
    chatgpt.com
```

И после analytics:

```text
Orders by discovery channel

Website       72%
Instagram     14%
Google         8%
AI             4%
Direct         2%
```

Това ще става все по-интересно.

OpenAI вече описва merchant/product discovery през ChatGPT като отделен commerce layer, а Google също развива измерване на product discovery през AI Mode, AI Overviews и Gemini. ([OpenAI][1])

---

# 11. Има още нещо: AI агентът не е човек

Това трябва да залегне в архитектурата.

Човекът може да каже:

> „Търся хубава рокля за сватба.“

AI agent може да поиска:

```text
category = dress
occasion = wedding
size = M
color = black
price <= €150
availability = immediate
shipping = Bulgaria
return_policy = available
```

И следователно Catalog трябва да има **богата структурирана семантика**, не само title/description.

Това означава:

```text
Product Attributes
+
Structured Metadata
+
Relationships
+
Availability
+
Pricing
+
Content
```

Google вече препоръчва богати структурирани product attributes, а Merchant Center дори добавя conversational attributes за AI discovery. ([Google Help][3])

---

# 12. Има една още по-важна идея

## **EasyCo трябва да бъде AI-readable by default.**

Не:

> „След 3 години ще направим AI integration.“

А още при създаването на Product:

```text
Product
├── Human-readable data
└── Machine-readable commerce data
```

Това включва:

* структурирани атрибути;
* variants;
* availability;
* prices;
* policies;
* shipping;
* returns;
* related products;
* Q&A;
* media;
* semantic descriptions.

Така AI агентът няма нужда да „гадае“ какво продава merchant-ът.

---

# 13. Но трябва да внимаваме с едно нещо

Не бих направил EasyCo зависим от ChatGPT.

Нито от Meta.

Нито от Google.

Нито от един AI provider.

Правилната архитектура е:

```text
               EasyCo Commerce Data
                       │
          ┌────────────┼────────────┐
          │            │            │
        Google        Meta        OpenAI
          │            │            │
       Adapter       Adapter      Adapter
```

а не:

```text
EasyCo
   ↓
OpenAI
```

Защото след пет години може да има:

```text
OpenAI
Google
Anthropic
Meta
Amazon
Apple
New Agent X
```

И EasyCo трябва да е готов.

---

# 14. Това всъщност решава и един от големите ни рискове

По-рано говорихме за ecosystem.

Ако EasyCo направи:

> **най-добрия channel/commerce data layer за SMB merchants**

това вече е много по-интересно.

Защото merchant-ът не трябва да мисли:

> „Как да направя магазина си съвместим с ChatGPT?“

или:

> „Как да го кача в Google?“

или:

> „Как да направя Meta catalog?“

Той просто казва:

> **„Добавих новия продукт в EasyCo.“**

И EasyCo се грижи продуктът да стане достъпен за:

```text
Website
Google
Meta
Instagram
AI
Marketplaces
Future channels
```

---

# 15. И това води до нова архитектурна схема

Аз бих започнал да мисля за EasyCo така:

```text
                         EASYCO
                           │
          ┌────────────────┼────────────────┐
          │                │                │
      Commerce Core     Content          Channels
          │                │                │
    ┌─────┼─────┐          │       ┌────────┼─────────┐
    │     │     │          │       │        │         │
 Catalog Pricing Inventory  │     Web      Social     AI
    │     │     │            │      │        │         │
    └─────┼─────┘            │    Store    Meta      Agents
          │                  │            Google
     Orders/Checkout         │            ChatGPT
          │                  │
          └──────────────────┘
```

И отдолу:

```text
Infrastructure
Database
Cache
Queue
Search
Storage
Events
Observability
```

---

# 16. Има и една много силна възможност за EasyCo

### **Physical store ↔ Digital store**

Твоят пример започва физически:

> „Идва нова стока във физическия магазин.“

Но EasyCo може да направи физическия магазин **първокласен source of commerce events**.

Например:

```text
New stock received
        ↓
Inventory event
        ↓
Product becomes available
        ↓
Content created
        ↓
Social publishing
        ↓
AI/Google feeds updated
        ↓
Customers discover
        ↓
Order
        ↓
POS / Web / AI
```

Това е **omnichannel**, но без да го превръщаме в enterprise buzzword.

---

# 17. А POS вече изглежда различно

По-рано мислехме:

> POS = internal module.

Сега бих казал:

> **POS е още един commerce channel, но с privileged capabilities.**

Например:

```text
Customer
    │
    ├── Website
    ├── Instagram
    ├── AI Agent
    └── POS
```

Всички използват:

```text
Catalog
Pricing
Inventory
Customers
Orders
```

Но POS има достъп до:

```text
Cost
Margin
Internal notes
Staff operations
Cash drawer
```

Това идеално пасва на разговора ни за `CostPriceProvider`.

---

# 18. И ще добавя още една архитектурна концепция

## Commerce Event Stream

Не всичко трябва да бъде request/response.

Например:

```text
ProductCreated
ProductUpdated
InventoryChanged
PriceChanged
PromotionActivated
ContentPublished
OrderCreated
OrderPaid
OrderFulfilled
CustomerCreated
```

Тези events могат да се използват от:

```text
Google feed
Meta feed
AI feed
Search index
Analytics
Notifications
CRM
Content
```

Например:

```text
InventoryChanged
       │
       ├── Storefront cache
       ├── Google feed
       ├── AI feed
       ├── Search index
       └── Social availability
```

Това е много по-добре от:

```text
InventoryService
   ↓
call Google
   ↓
call Meta
   ↓
call OpenAI
   ↓
call Search
```

---

# 19. И това е мястото, където трябва да сме много внимателни

Не искам да превърнем EasyCo в:

> **„Всичко за всички.“**

Това ще бъде смъртта му.

Нашата първа версия може да бъде:

### Core

```text
Catalog
Pricing
Inventory
Customers
Cart
Checkout
Orders
```

### First-class channels

```text
Web
POS
```

### Distribution

```text
Google
Meta
AI
```

Но самите adapters могат да се развиват постепенно.

---

# 20. И най-същественото ми решение като Chief Architect

От този момент нататък бих променил **архитектурната цел на EasyCo** от:

> „Модулна e-commerce платформа“

на:

# **„Modular Commerce Platform with channel-native distribution.“**

И това трябва да се появи още в Foundation документа.

Защото според мен това е потенциалната ни **истинска идентичност**.

Не се опитваме да победим Shopify, WooCommerce, Medusa и т.н. като копираме техния feature list.

Опитваме се да построим нещо, което отговаря на начина, по който **реалният merchant продава през 2026+**:

```text
                PRODUCT
                   │
          ┌────────┼─────────┐
          │        │         │
        STORE    SOCIAL      AI
          │        │         │
       Website   Instagram  Agents
       POS       Messenger  AI Search
          │        │         │
          └────────┼─────────┘
                   │
                 ORDER
                   │
              CUSTOMER
```

И най-хубавото е, че **това не противоречи на domain architecture-а, който вече изграждаме.**

Напротив — прави го по-смислен.

И още нещо: фактът, че днес реално си получил поръчка, дошла през ChatGPT, бих го записал в проекта като **real-world validation signal**, не като анекдот. Не доказва бъдещия успех на AI commerce, но е достатъчно силен сигнал, че архитектурата ни трябва да го третира като реален канал, а не като експеримент.

Следващото нещо, което бих направил, е да **преработя Foundation документа според тази нова постановка**, преди да продължим с останалите domains. Това е фундаментална промяна и е по-добре да я фиксираме сега, отколкото след 15 модула.

[1]: https://openai.com/index/powering-product-discovery-in-chatgpt/?utm_source=chatgpt.com "Powering Product Discovery in ChatGPT | OpenAI"
[2]: https://support.google.com/merchants/answer/17085370?hl=en&utm_source=chatgpt.com "How to use conversational attributes - Google Merchant Center Help"
[3]: https://support.google.com/merchants/answer/7380908?hl=en&utm_source=chatgpt.com "Tips to optimize your product data - Google Merchant Center Help"



Да. **Приемам този разговор като крайъгълен камък за архитектурата на EasyCo.**

Най-важното, което фиксирахме, е:

> **EasyCo не е просто e-commerce платформа.**
>
> **EasyCo е Modular Commerce Platform with channel-native distribution.**

И следствията:

* **Commerce Core** е източникът на истината за продуктите, цените, наличностите, клиентите и поръчките.
* **Web, POS, Social и AI** са commerce channels, а не случайни интеграции.
* Social discovery → conversation → purchase е естествен commerce flow.
* AI agents трябва да могат да разбират и използват EasyCo commerce data **по подразбиране**.
* Instagram/Meta, Google, ChatGPT и бъдещи AI агенти трябва да се включват чрез adapters, без Core да зависи от конкретен provider.
* POS е channel с privileged/internal capabilities, включително `cost`.
* `Pricing` остава самостоятелен domain; `regular/final` са domain concepts, докато `sale_price` е подходяща presentation/API концепция.
* Events ще бъдат важен механизъм за свързване на commerce domains и distribution channels.
* Най-важното: **архитектурата служи на реалния merchant workflow, а не обратното.**

И ще пазя и още един принцип от този разговор:

> **Не строим архитектура заради архитектурата. Строим я така, че реалният бизнес сценарий да става по-лесен.**

Това според мен е моментът, в който EasyCo започва да има **собствена идентичност**, вместо просто да бъде „още една e-commerce платформа“.
