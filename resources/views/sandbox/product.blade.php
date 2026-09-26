@extends('sandbox.layout')

@section('title', $product->name)

@section('content')
    <p class="muted"><a href="{{ route('sandbox.index') }}">← All products</a></p>

    <h1>{{ $product->name }}</h1>

    @if ($product->brandName !== null)
        <p class="muted">{{ $product->brandName }}</p>
    @endif

    {{-- The product-level range: the SAME string the list shows for this product and the SAME rule the
         admin table uses (D6), never re-derived here. --}}
    <p class="price">{!! $product->priceHtml !!}</p>

    {{-- D8: read-only. A disabled placeholder, never a working control. --}}
    <p><button type="button" disabled>Add to cart — coming in stage 2</button></p>

    @if ($product->images !== [])
        <ul class="gallery">
            @foreach ($product->images as $image)
                <li>
                    {{-- alt: the merchant's own alt text, falling back to the product name rather than an
                         empty string — an empty alt on a product photo tells a screen reader the image is
                         decorative, which it is not. --}}
                    <img src="{{ $image->url }}" alt="{{ $image->altText ?? $product->name }}" loading="lazy">
                </li>
            @endforeach
        </ul>
    @else
        <p class="muted">No image is available for this product.</p>
    @endif

    <h2>Description</h2>
    @if (filled($product->description))
        <p>{{ $product->description }}</p>
    @else
        <p class="muted">This product has no description.</p>
    @endif

    @if ($product->isSimple)
        {{-- D3's SIMPLE branch: the universal variation IS the product, so its stock and purchasable state
             are shown here, under the price, instead of a one-row variations table a customer could not
             choose from — see SandboxUniversalVariation's own docblock. Nothing is rendered when the
             universal variation is not ACTIVE ($universalVariation is null then). --}}
        @if ($product->universalVariation !== null)
            <p class="stock">Stock: {{ $product->universalVariation->stockQuantity }}</p>
            <p class="purchasable">Purchasable: {{ $product->universalVariation->purchasable ? 'Yes' : 'No' }}</p>

            {{-- D3's add-to-cart control, SIMPLE branch: one control, because a SIMPLE product has exactly one
                 thing to buy. No control at all when the domain says it is not purchasable — this page never
                 offers an action the API would refuse. --}}
            @if ($product->universalVariation->purchasable)
                <p class="add-to-cart" data-variation-id="{{ $product->universalVariation->id }}">
                    <label for="qty-universal">Quantity</label>
                    <input id="qty-universal" class="qty-input" type="number" min="1" value="1">
                    <button class="primary add" type="button">Add to cart</button>
                </p>
            @else
                <p class="muted">Not purchasable right now — no add-to-cart control.</p>
            @endif
        @endif
    @else
        <h2>Variations</h2>

        @if ($product->variations === [])
            <p class="muted">
                This product has no variation a customer could currently see — a variation is listed only when its
                status is active and it is visible.
            </p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Attributes</th>
                        <th>SKU</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Purchasable</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($product->variations as $variation)
                        <tr>
                            <td>
                                @forelse ($variation->axisLabels as $axis)
                                    <span class="axis">{{ $axis['name'] }}: {{ $axis['value'] }}</span>
                                @empty
                                    —
                                @endforelse
                            </td>
                            <td>{{ $variation->sku }}</td>
                            {{-- '—' for a variation with no resolvable price (D5) — already rendered by the
                                 shared display rule, escaped inside it. --}}
                            <td>{!! $variation->priceHtml !!}</td>
                            <td>{{ $variation->stockQuantity }}</td>
                            <td>{{ $variation->purchasable ? 'Yes' : 'No' }}</td>
                            {{-- D3's add-to-cart control, VARIABLE branch: one per PURCHASABLE row (the row the
                                 customer is actually choosing), none on a row the API would refuse — never a
                                 disabled form a customer can still submit. --}}
                            <td>
                                @if ($variation->purchasable)
                                    <span class="add-to-cart" data-variation-id="{{ $variation->id }}">
                                        <input class="qty-input" type="number" min="1" value="1" aria-label="Quantity">
                                        <button class="primary add" type="button">Add</button>
                                    </span>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif
@endsection
