@php
    $page->withQueryString();
    $sizeKey = \App\TablePage::key($page->getPageName());
    $filters = request()->except([$page->getPageName(), $sizeKey]);
@endphp
<div class="table-pagination print-hide">
    <form method="GET" action="{{ request()->url() }}" class="page-size">
        @foreach ($filters as $key => $value)
            @if (is_scalar($value))
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
        <label>Rows per page
            <select name="{{ $sizeKey }}">
                @foreach (\App\TablePage::Sizes as $size)
                    <option value="{{ $size }}" @selected($page->perPage() === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit">Apply</button>
    </form>
    @if ($page->hasPages())
        <nav class="pagination" aria-label="Pages">
            @if ($page->previousPageUrl()) <a rel="prev" href="{{ $page->previousPageUrl() }}">Previous</a> @endif
            <span>Page {{ $page->currentPage() }}</span>
            @if ($page->nextPageUrl()) <a rel="next" href="{{ $page->nextPageUrl() }}">Next</a> @endif
        </nav>
    @endif
</div>
