@php
    $page->withQueryString();
    $pageName = $page->getPageName();
    $sizeKey = \App\TablePage::key($pageName);
    $searchKey = \App\TablePage::searchKey($pageName);
    $filters = request()->except([$pageName, $sizeKey]);
@endphp
<div class="table-pagination print-hide" data-server-table-pagination>
    @if ($searchable ?? true)
        <form class="fields search print-hide" method="GET" action="{{ request()->url() }}" data-table-search-form>
            @foreach (request()->except([$pageName, $searchKey]) as $key => $value)
                @if (is_scalar($value))
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach
            <label>Search table
                <input name="{{ $searchKey }}" type="search" maxlength="100" value="{{ \App\TablePage::searchTerm($pageName) }}">
            </label>
            <button type="submit">Search</button>
        </form>
    @endif
    <span class="table-record-count">
        @if ($page->count())
            Showing {{ number_format($page->firstItem()) }}–{{ number_format($page->lastItem()) }} of {{ number_format($page->total()) }} {{ Str::plural('record', $page->total()) }}
        @else
            {{ number_format($page->total()) }} {{ Str::plural('record', $page->total()) }}
        @endif
    </span>
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
