{{--
    Embedded Thaana webfont for every council PDF.

    The register holds Dhivehi (Thaana) property and tenant names. Without an
    embedded font those names render as tofu boxes on any host whose Chrome has
    no Thaana coverage — which is every slim Linux container and every hosted
    renderer (Gotenberg, Cloudflare Browser Rendering). macOS happens to ship a
    Thaana font, which is why this only ever looked right in local development.

    Embedding as base64 keeps the document self-contained: no network fetch at
    render time, so it works identically on every driver in config/laravel-pdf.php.

    The `unicode-range` is what keeps the Latin text untouched — Chrome uses this
    face ONLY for Thaana codepoints and falls through to the system stack for
    everything else. Keep it, and keep this family FIRST in `font-family`.

    Font: Noto Sans Thaana (Thaana subset), SIL Open Font License 1.1 —
    resources/fonts/noto-sans-thaana.woff2
--}}
@php
    $thaanaFont = base64_encode(file_get_contents(resource_path('fonts/noto-sans-thaana.woff2')));
@endphp
<style>
    @font-face {
        font-family: 'Noto Sans Thaana';
        font-style: normal;
        font-weight: 400 700;
        src: url(data:font/woff2;base64,{{ $thaanaFont }}) format('woff2');
        unicode-range: U+060C, U+061B-061C, U+061F, U+0660-066C, U+0780-07B1, U+200C-200F, U+25CC, U+FDF2, U+FDFD;
    }
</style>
