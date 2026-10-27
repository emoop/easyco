<?php print("\x01"); ?>{{-- SC-1: the marker keeps Laravel's ltrim of a view's output from eating the first line's indentation; product.blade.php removes it. --}}{{-- SC-1, right column component `html_block`: the sanitised HTML of an HTML block (cleaned again by ProductSummaryView, as the footer's block is). --}}
                <div class="sf-summary-html">{!! $component['html'] !!}</div>
