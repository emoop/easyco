@extends('sandbox.layout')

@section('title', 'Not available')

@section('content')
    <h1>Not available</h1>

    <div class="not-found">
        <p>
            No product with id <code>{{ $productId }}</code> is available in the sandbox.
        </p>
        <p class="muted">
            A product is shown only when its status is active and its catalog visibility is visible. A draft,
            archived or hidden product — and an id that does not exist at all — look exactly the same from here.
        </p>
    </div>

    <p><a href="{{ route('sandbox.index') }}">← Back to the product list</a></p>
@endsection
