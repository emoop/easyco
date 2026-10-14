<?php

/*
 * Pages (pages-design.md): the admin "Pages" screen, the PageWriter errors and the reasons a page cannot be deleted.
 * bg and en are kept together (tests/Feature/PageLangParityTest.php).
 */
return [
    'model' => 'Page',
    'plural' => 'Pages',
    'navigation_label' => 'Pages',
    'title' => 'Pages',
    'empty' => 'No pages yet',

    'fields' => [
        'title' => 'Title',
        'slug' => 'Address (slug)',
        'body' => 'Text',
        'status' => 'Status',
        'meta_title' => 'SEO title',
        'meta_description' => 'SEO description',
        'noindex' => 'Do not index (noindex)',
        'in_footer' => 'Show in the footer',
        'footer_order' => 'Footer order',
        'revision' => 'Revision',
        'updated_at' => 'Changed',
    ],

    'help' => [
        'slug' => 'The page lives at /info/{address}. Lower-case letters of any script, digits and single hyphens; 1-120 characters; unique.',
        'slug_changed' => 'The page is published: old links to it will stop working if you change the address.',
        'body' => 'The editor offers only what the shop keeps: headings, bold and italic, lists, a link, a quote and a table. Anything else is removed when you save.',
        'noindex' => 'The page stays reachable, but search engines are asked not to index it.',
    ],

    'sections' => [
        'seo' => 'SEO',
        'footer' => 'Footer',
    ],

    'status' => [
        'draft' => 'Draft',
        'published' => 'Published',
    ],

    'actions' => [
        'create' => 'New page',
        'edit' => 'Edit',
        'preview' => 'Preview',
        'archive' => 'Archive',
    ],

    'archive' => [
        'heading' => 'Archive the page',
        'description' => 'The page becomes a draft and leaves the footer. Its text and revisions are kept.',
        'submit' => 'Archive',
    ],

    'notice' => [
        'created' => 'The page was created',
        'saved' => 'The page was saved',
        'archived' => 'The page was archived',
    ],

    'errors' => [
        'body_too_large' => 'The text is too long (at most :max bytes).',
        'body_required_to_publish' => 'A published page needs a text.',
        'slug_invalid' => 'The address is lower-case letters (of any script), digits and single hyphens, with no hyphen at the start or the end.',
        'slug_taken' => 'Another page already uses this address.',
    ],

    'in_use' => [
        'terms_page' => 'The page cannot be deleted: it is chosen as the terms and conditions at checkout.',
        'privacy_page' => 'The page cannot be deleted: it is chosen as the privacy policy at checkout.',
        'accepted_by_order' => 'The page cannot be deleted: an order accepted one of its revisions as the terms and conditions.',
    ],

    'revisions' => [
        'title' => 'Revisions',
        'number' => 'Revision',
        'date' => 'Date',
        'who' => 'Who',
        'unknown_staff' => '—',
        'view' => 'View',
        'heading' => ':title — revision :number',
        'back' => 'Back to the page',
        'saved_at' => 'Saved on :date',
    ],

    'drafts' => [
        'terms_title' => 'Terms and conditions',
        'privacy_title' => 'Privacy policy',
    ],
];
