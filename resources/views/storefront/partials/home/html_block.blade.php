{{-- HP-1, home section `html_block`: the sanitised HTML of ONE HTML block, compiled at publish through the one renderer (HtmlBlockModel::renderMany) and cleaned again when the layout is read; it is printed as it is. --}}
<div class="sf-hp-html">{!! $item['html'] !!}</div>
