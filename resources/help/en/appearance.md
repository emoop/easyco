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

**Phone.** The menu button opens a drawer from the side you choose. In it you can put the category menu (expandable), account, text, a button and social networks (up to 8 elements). It closes with "Esc", by tapping beside it or with the close button.

**Preview and publishing.** "Save draft" keeps your changes without showing them to customers. "Preview" opens the home page with the draft: the link is temporary (30 minutes), is not indexed and nobody else can see it. "Publish" checks everything and shows it to everyone. "Restore the published one" throws the draft away.

**Elements that appear later.** Search, account and wishlist are kept in the arrangement but are not shown until that feature is released in the shop. So you can arrange the final header now. Nothing is shown empty.

## Menus {#action-appearance-menus}

"Menus" (the "Content" menu, next to "Pages") is where you build the shop's navigation. The menu is a tool of its own: **you** choose which categories appear and in which order. A category is never added just because it exists, and a category you leave out never shows.

**Which categories.** Open the "Main menu" and add items: a category, a page or your own link. "Add the main categories" adds the top-level categories that are not in the menu yet. Use the up and down buttons to order them. A category with no products to show is skipped automatically.

**Dropdown or mega menu.** For each top-level item choose what opens under it: nothing, a **dropdown** or a **mega menu**. The item itself stays a link; a small arrow beside it opens the panel. A dropdown lists items (you can tick "List its sub-categories automatically", up to the number you set, then a "See all" link, or use "Add the sub-categories" and remove the ones you do not want). A mega menu is a wide panel built from **columns**: each column has an optional heading and a list of links; you can also put up to **two pictures** in it, each with an optional link and text. Choose a picture that is wide enough; it appears once it has been processed.

**"More".** If the main menu has more items than fit on one row, the ones that do not fit move into "More" automatically. The logo is never pushed aside. The page warns you when the main menu has more than 7 top-level items or a long name.

**The footer.** The "Footer" menu is the list of links at the bottom of every page. Add the pages you want (for example terms, privacy) in the order you want. A page that is not published is not shown.

**In the header.** The "Header" page, element "Category menu", has a "Source" field: choose "Main menu" (or another menu you made). A menu that the header uses cannot be deleted. When you rename a category or publish a page, the menus follow by themselves.
