{{-- The payment marks of the footer (FT-1): a CLOSED set of small inline SVGs, decorative (the name is in a screen-reader text next to them), drawn here, no file, no CDN.
     DRAWN by hand from plain geometry: the two overlapping circles of Mastercard and of Maestro (their well-known colours), the "VISA" word, a bank-building glyph.
     NEUTRAL badges (a rounded rectangle with the name as text, no logo): Apple Pay, Google Pay, PayPal, cash on delivery. Only plain shapes and text; no style, no link, no script. --}}
<svg class="sf-pay-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 30" width="48" height="30" aria-hidden="true" focusable="false">
    @switch($method)
        @case('visa')
            <rect x="0.5" y="0.5" width="47" height="29" rx="4" fill="#ffffff" stroke="#d4d4d4"/>
            <text x="24" y="20" text-anchor="middle" font-size="13" font-weight="700" font-style="italic" fill="#1a1f71">VISA</text>
            @break
        @case('mastercard')
            <rect x="0.5" y="0.5" width="47" height="29" rx="4" fill="#ffffff" stroke="#d4d4d4"/>
            <circle cx="19" cy="15" r="8" fill="#eb001b"/>
            <circle cx="29" cy="15" r="8" fill="#f79e1b" fill-opacity="0.9"/>
            @break
        @case('maestro')
            <rect x="0.5" y="0.5" width="47" height="29" rx="4" fill="#ffffff" stroke="#d4d4d4"/>
            <circle cx="19" cy="15" r="8" fill="#0099df"/>
            <circle cx="29" cy="15" r="8" fill="#eb001b" fill-opacity="0.9"/>
            @break
        @case('apple_pay')
            <rect x="0.5" y="0.5" width="47" height="29" rx="4" fill="#000000"/>
            <text x="24" y="19" text-anchor="middle" font-size="9" font-weight="700" fill="#ffffff">Apple Pay</text>
            @break
        @case('google_pay')
            <rect x="0.5" y="0.5" width="47" height="29" rx="4" fill="#ffffff" stroke="#d4d4d4"/>
            <text x="24" y="19" text-anchor="middle" font-size="10" font-weight="700" fill="#3c4043">G Pay</text>
            @break
        @case('paypal')
            <rect x="0.5" y="0.5" width="47" height="29" rx="4" fill="#003087"/>
            <text x="24" y="19" text-anchor="middle" font-size="10" font-weight="700" font-style="italic" fill="#ffffff">PayPal</text>
            @break
        @case('bank_transfer')
            <rect x="0.5" y="0.5" width="47" height="29" rx="4" fill="#ffffff" stroke="#d4d4d4"/>
            <path d="M24 6l11 5H13z" fill="#404040"/>
            <path d="M15 13h3v8h-3zM22.5 13h3v8h-3zM30 13h3v8h-3z" fill="#404040"/>
            <path d="M13 23h22v2H13z" fill="#404040"/>
            @break
        @case('cash_on_delivery')
            <rect x="0.5" y="0.5" width="47" height="29" rx="4" fill="#ffffff" stroke="#d4d4d4"/>
            <text x="24" y="19" text-anchor="middle" font-size="10" font-weight="700" fill="#404040">COD</text>
            @break
    @endswitch
</svg>
