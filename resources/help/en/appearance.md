## Colours {#action-appearance-colors}

The "Appearance" page (the "Admin" menu) changes how the shop looks: colours, fonts, corners and a top banner. Changes apply at once to every page, the cart and the checkout included. The shop does not change until you change something: the standard look stays exactly as it is.

You pick each colour from the colour palette. Only a colour you changed is stored. The primary colour is the colour of the buttons; "Text on the primary colour" is the colour of the words in a button. The accent colours the price of an item on sale.

Under the important pairs of colours (text and background, button, header, footer, banner) you may see a yellow "Low contrast" warning. It means the text may be hard to read, especially on a phone. At least **4.5:1** is recommended. The warning does not stop you from saving: you decide.

## Fonts and shapes {#action-appearance-fonts}

You can choose "System" (the font of the customer's own device), "Inter", "Manrope" and "Lora" (with serifs, nice for headings). The heading font can be the same as the body font or another one. The fonts come with the shop: nothing is loaded from another site, so nothing is sent to third parties and the page is not slowed down.

"Corner radius" changes the corners of buttons, fields and cards (from no rounding to large). "Pill-shaped buttons" rounds only the buttons completely. "Button style": solid (filled) or outline only.

## Top banner {#action-appearance-banner}

The banner is one line above the header, for example "Free delivery over 100 €". Write plain text (up to 160 characters). The link is optional: a path in the shop (starts with "/") or an address with https://. When there is a link but no "Link text", the whole banner is the link; otherwise the text is plain and the link is a separate word.

The banner shows only while it is on and between the "From" and "Until" dates (in the shop's time). On a phone a long text is cut with "…". There is no close button.

## Themes {#action-appearance-themes}

The shop uses the theme "easyco". A theme is a folder of files on the server (it is not uploaded from the admin): so nobody who gets into the admin can run code on the server. A new theme is installed by a developer, the way an extension is installed. Switching themes from the admin is not available yet.

## Header {#action-appearance-header}

The "Header" page (the "Admin" menu) arranges the top of the shop. There are two arrangements: **Desktop** and **Phone**. Each has rows: top, main and bottom. Each row has three places: left, centre and right, and in each place you order elements: logo, category menu, menu button (hamburger), search, account, wishlist, cart, text, HTML block, button, social networks and spacer. A place holds up to 6 elements; the logo and the menu button are one each.

**Height.** Every row has its own height in pixels (for example the main row: 44 to 140 px on desktop, 44 to 96 on phone). The content is centred in that height. The logo must be at least 8 px lower than the row, otherwise the page tells you it is taller than the row.

**Phone.** The menu button opens a drawer from the side you choose. In it you can put the category menu (expandable), text, a button and social networks (up to 8 elements). It closes with "Esc", by tapping beside it or with the close button. The order inside the drawer is always the same: search at the top, the menu, the "Wishlist" and "My profile" rows (when those pages exist), then text, button and social networks. The setting **"Main items in capitals"** (on the "Header" page, in the "Phone" tab, next to the drawer side; on by default) shows the first-level items in capitals; off, in normal case.

**Preview and publishing.** The buttons are the same in every zone of the Design screen (see "The buttons of the Design screen" below): "Apply" keeps your changes in the working copy and refreshes the preview without showing them to customers. The preview is a temporary link (30 minutes), is not indexed and nobody else can see it. "Publish" checks everything and shows it to everyone. "Revert to published" throws the working copy away.

**Elements that appear later.** Search, account and wishlist are kept in the arrangement but are not shown until that feature is released in the shop. So you can arrange the final header now. Nothing is shown empty.

## Footer {#action-appearance-footer}

The "Footer" page (menu "Design", after "Header") arranges the bottom of the shop in **two levels**. The buttons are as in every zone: **Apply** (under the panel), **Revert to published**, **Initial settings** and **Publish**. Until you publish a footer the shop shows its old footer (the shop name and the menu of the "Footer" place).

**Upper level.** Switched on with "Enabled". It has padding, a background colour, a text colour, and a colour and thickness (0 to 4 px) of the border on top. An empty colour = the colour from "Appearance". The level has 1 or 2 **rows**, each row has 1 to 5 **columns**. Every column has a width (1 or 2 shares), an alignment (start, centre, end) and an ordered list of **blocks** (up to 6 per column, 40 in all): menu (the links of its first level, with an optional heading; with no menu chosen it shows the menu of the "Footer" place), HTML block (for example a banner with a picture), text, newsletter, social networks, payment methods, contacts (phone, email, address, opening hours) and space. A column in which nothing is shown, and an empty row, are not printed. On a phone the columns stack one under another.

**Newsletter.** The "Newsletter" block is saved, but it is shown on the site only once newsletter subscription exists (a note under the block says so).

**Lower level.** One line of text, aligned as you choose, with its own colours. In the text **[year]** is replaced by the current year every time the page is shown (so on 1 January you do nothing). Anything else in square brackets stays as written.

**Example.** Upper level, row 1: 4 columns with one menu each (each with a heading) and a "Newsletter" column; row 2: a column with "Social networks" at the start and a column (2 shares) with "Payment methods" at the end. Lower level: "Fetish Harmanli © [year] – run by Mari Ned Ltd".

## Menus {#action-appearance-menus}

"Menus" (the "Design" group) is one screen laid out like the WordPress menu screen. At the top you choose a menu or create a new one. On the left you add items, on the right you see the structure and arrange it. "Save menu" is one button for everything: the order, the names, the labels and the locations. If something is wrong nothing is saved, and the first faulty item opens with an explanation.

**Adding.** The left side has four panels: **Categories** (with search; "with their sub-categories" adds the sub-categories under the category too), **Pages** ("Most recent", "View all", "Search"; published ones only), **Products** (search by name or SKU, up to 20 results) and **Custom link** (address and text). Column headings are typed in the columns themselves. Tick and press "Add to menu": the new items go to the end and light up for a moment.

**Arranging.** Drag an item by its handle. Up and down move it; **to the right** (every 30 pixels is one level) makes it a sub-item of the one above, to the left brings it out. Menus have at most three levels. An item moves together with its sub-items. The same moves are buttons in the opened item ("Up", "Down", "Under the previous", "Take out of the submenu"), handy from the keyboard.

**Dropdown or mega menu.** For a top-level item choose **Submenu**: "None", "Dropdown" or "Mega". A dropdown lists the sub-items (the third level opens to the side). **Mega** is a wide panel of **columns**: next to the item choose **Columns: 1, 2, 3 or 4** (at most 4) and that many **boxes** ("Column 1 ... Column N") appear under it, indented like sub-items. Drag items into the boxes (or choose in "New items go to" which column gets them). The boxes can be reordered among themselves (drag by their header, or the buttons ← and →) but never leave their mega menu.

Every column has an **optional heading**. Without one the column is just links; with one the heading sits on top and the links under it. So one model serves a **2-level** and a **3-level** menu. The **"Split evenly"** button takes all the links of the mega menu in their order and spreads them over the columns as evenly as possible (12 over 4 columns = 3 each; 10 over 4 = 3, 3, 2, 2); then you can still drag. If you lower the number of columns (for example from 4 to 2), the links of the removed columns go to the end of the last column, with a visible warning: nothing is lost. Raising the number adds empty boxes. If you change "Mega" to "Dropdown" the columns are dissolved and their links become items of the dropdown in order (you are asked first). At most 15 links per column. To the right of the columns an **HTML block** can be shown (for example a picture): choose it in "HTML block on the right"; the block is a column of its own and does not depend on the number of columns. Blocks are made under "HTML blocks".

Two examples. **Boutique:** "Dresses" is Mega with 4 columns without headings; add 12 categories to the first column and press "Split evenly". **Children's shop:** "Boys" is Mega with 3 columns with the headings "Clothes", "Clothes" and "Shoes", each with its list of categories underneath; on the right an HTML block with a picture.

**Label.** A small text after the item (for example "Sale", up to 16 characters) with a colour: one of six ready ones or your own.

**Hidden or deleted target.** When the category, page or product is no longer shown in the shop, the item says "(hidden)" or "(deleted)" in red. That is why it is missing in the shop.

**On a phone** there is no mega menu: an item with a submenu opens in place, and the columns of a mega item are shown as sections one under another (the heading, when there is one, as a small title, the links under it; an empty column is left out). The HTML block is not shown.

**Location.** Tick where the menu is shown: **Header** (the bar at the top), **Phone** (the menu in the drawer; when none is ticked the drawer shows the header's menu) and **Footer**. A place has exactly one menu: ticking it here moves it from the menu that had it (a note under the tick says from which). A place with no menu shows nothing. A menu that has a place cannot be deleted.

**"More".** When the top-level items do not all fit in the bar, the extra ones go under "More". The screen warns you when there are more than 7 or one has a long name.

**Unsaved changes.** The dot next to "Save menu" shows there are unsaved changes; if you leave you will be asked first.

## HTML blocks {#action-appearance-html-blocks}

"HTML blocks" (the "Design" group) are small pieces of content you can reuse. For now they are shown to the right of the columns of a mega menu (for example a picture of boys' clothes next to "Clothes" and "Shoes"). Give it a title (for you only) and write the content: headings, bold and italic, lists, a link and a **picture** ("attach file"; JPG, PNG, WebP or GIF up to 5 MB). Scripts, styles, embedded frames and video are not kept: they are removed when you save and again when the block is shown. A block that a menu shows cannot be deleted; the message says which menu uses it.

**A picture with a link.** The link on **text** is set with the "link" button in the editor. So that it is clear which picture leads where, pictures with a link are added separately, in the **"Pictures with links"** field above the content: "Add a picture", choose the file (JPG, PNG, WebP or GIF up to 5 MB), optionally write a description and the **"Link when clicked"**. The link is an https:// address or a path in the shop (for example /product-category/boys); javascript:, http: and the like are not allowed. Up to 4 pictures, ordered with the buttons; they are shown first, one under another, and the content comes after them. A block of pictures only (no text) is allowed. The picture is processed like every picture in the shop (a lighter WebP file) and until it is ready the original is shown. **Example:** a banner in the mega menu leads to the category: a block with a picture of boys and the link /product-category/boys, chosen on "Boys" in "Menus". On a phone the picture is not shown.

## Page and cards {#action-appearance-page}

The «Page and cards» section of the Design screen sets the side padding of the whole shop and the border and corners of the product cards. **The side padding** is one number that grows with the window width: a minimum (px), a fluid part (vw, 1 vw is 1 % of the width) and a maximum (px); the minimum cannot be larger than the maximum. **The cards** have a border and rounded corners, separately for desktop and phone; 0 means no border or square corners. Lengths are in pixels and the decimal mark is a dot (4.5). Until you change something the shop looks as before: only what differs from the default is stored. «Apply» keeps the change in the working copy; it reaches the shop after «Publish». «Initial settings» puts the default values into the working copy (they take effect after «Publish» too).

## Product page {#action-appearance-product-page}

The «Product page» section of the Design screen lays out the gallery and the right column. **The gallery** has its own mode for a computer (2-column grid, small photos on the left or below) and for a phone (carousel, or a big photo with small ones below), a width (50 to 80 %), the behaviour of an odd last photo and the zoom under the mouse. Next to it are **the gap between photos**, **the photo corner radius** and **the gap between the gallery and the description** (px). The switch «The right column stays in view while scrolling» keeps the price and the «Add to cart» button on screen while the photos scroll (desktop only). The product's video appears in the same gallery. Only what differs from the default is stored; «Apply» keeps the changes in the working copy, «Initial settings» puts the default values into the working copy, and only what you publish with «Publish» reaches the shop.

## The buttons of the Design screen {#action-appearance-design-buttons}

In every zone of the Design screen (Top banner, Header, Footer, Cart and checkout, Page and cards, Product page) the buttons are the same and in the same places. Each zone has a **working copy** (a draft) and a **published version** (what customers see). **"Apply"** (under the panel) takes what is in the panel into the working copy and refreshes the preview; it publishes nothing. Every change of the layout (adding, moving, deleting) is stored in the working copy at once, so leaving the screen never loses what you applied. At the top: **"Revert to published"** throws the working copy away; **"Initial settings"** (only in zones that have initial settings) puts the initial values into the working copy, after a confirmation; **"Publish"** (the last, red button) shows the working copy in the shop. The small text beside it says whether it is "Published", "Draft — not published" or "The panel has changes that are not applied". "Publish" is unavailable while there is nothing to publish and while the panel has changes that are not applied: press "Apply" first.
