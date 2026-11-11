@if ($paginator->hasPages())
    <nav class="pager">
        @if ($paginator->onFirstPage())<span class="muted">← Newer</span>@else<a href="{{ $paginator->previousPageUrl() }}">← Newer</a>@endif
        <span class="muted">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}">Older →</a>@else<span class="muted">Older →</span>@endif
    </nav>
@endif
