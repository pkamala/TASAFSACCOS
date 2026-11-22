@if ($page->hasPages())
    <nav class="pagination" aria-label="Pagination">
        @if ($page->previousPageUrl()) <a rel="prev" href="{{ $page->previousPageUrl() }}">Previous</a> @endif
        @if ($page->nextPageUrl()) <a rel="next" href="{{ $page->nextPageUrl() }}">Next</a> @endif
    </nav>
@endif
