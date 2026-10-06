{{--
    The in-app help page (Help 1). Built for READING while working: a column of about 48rem, the contents as a
    sticky list beside it on a wide screen and as a collapsed block on a phone, anchored headings, and a print
    stylesheet that leaves only the text. The HTML comes from HelpRenderer, which strips raw HTML from the Markdown
    and refuses unsafe links, so it is the one place output is not escaped by Blade; everything else is escaped.
    Plain CSS only (no theme build, no JavaScript).
--}}
<x-filament-panels::page>
    <style>
        .help-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
        .help-toc-side { display: none; }
        .help-toc-mobile { border: 1px solid rgba(128, 128, 128, .35); border-radius: .5rem; padding: .5rem .75rem; }
        .help-toc-mobile summary { cursor: pointer; font-weight: 600; }
        .help-toc ol { list-style: none; margin: .25rem 0 0; padding: 0; }
        .help-toc li { margin: .15rem 0; }
        .help-toc li.help-toc-h3 { padding-left: 1rem; font-size: .875em; }
        .help-toc a, .help-content a { text-decoration: underline; }
        .help-content { max-width: 48rem; min-width: 0; line-height: 1.6; font-size: .95rem; }
        .help-content h2 { font-size: 1.25rem; font-weight: 700; margin: 2rem 0 .5rem; scroll-margin-top: 5rem; }
        .help-content h3 { font-size: 1.05rem; font-weight: 600; margin: 1.25rem 0 .35rem; scroll-margin-top: 5rem; }
        .help-content p, .help-content ul, .help-content ol, .help-content table { margin: .5rem 0; }
        .help-content ul { list-style: disc; padding-left: 1.25rem; }
        .help-content ol { list-style: decimal; padding-left: 1.25rem; }
        .help-content table { border-collapse: collapse; width: 100%; }
        .help-content th, .help-content td { border: 1px solid rgba(128, 128, 128, .35); padding: .25rem .5rem; text-align: left; }
        .help-content .help-top { font-size: .8rem; opacity: .7; margin-top: 1rem; }
        @media (min-width: 1024px) {
            .help-layout { grid-template-columns: 14rem minmax(0, 48rem); align-items: start; }
            .help-toc-side { display: block; position: sticky; top: 5rem; max-height: calc(100vh - 6rem); overflow-y: auto; }
            .help-toc-mobile { display: none; }
        }
        @media print {
            .fi-topbar, .fi-sidebar, .fi-sidebar-close-overlay, .fi-header, .fi-breadcrumbs, .help-toc-side, .help-toc-mobile, .help-top { display: none !important; }
            .help-layout { display: block; }
            .help-content { max-width: none; }
        }
    </style>

    <span id="contents"></span>

    <div class="help-layout">
        <nav class="help-toc help-toc-side" aria-label="{{ __('help.contents') }}">
            <strong>{{ __('help.contents') }}</strong>
            <ol>
                @foreach ($help['toc'] as $entry)
                    <li class="help-toc-h{{ $entry['level'] }}"><a href="#{{ $entry['id'] }}">{{ $entry['title'] }}</a></li>
                @endforeach
            </ol>
        </nav>

        <div>
            <p class="help-intro" style="margin-bottom: .75rem;">{{ __('help.intro') }}</p>

            <details class="help-toc help-toc-mobile">
                <summary>{{ __('help.contents') }}</summary>
                <ol>
                    @foreach ($help['toc'] as $entry)
                        <li class="help-toc-h{{ $entry['level'] }}"><a href="#{{ $entry['id'] }}">{{ $entry['title'] }}</a></li>
                    @endforeach
                </ol>
            </details>

            <article class="help-content">
                {!! $help['html'] !!}
            </article>
        </div>
    </div>
</x-filament-panels::page>
