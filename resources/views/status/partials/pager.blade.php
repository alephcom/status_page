@if ($paginator->hasPages())
    <nav class="pager">
        @if ($paginator->onFirstPage())<span class="muted">← {{ __('Newer') }}</span>@else<a href="{{ $paginator->previousPageUrl() }}">← {{ __('Newer') }}</a>@endif
        <span class="muted">{{ __('Page :page of :last', ['page' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>
        @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}">{{ __('Older') }} →</a>@else<span class="muted">{{ __('Older') }} →</span>@endif
    </nav>
@endif
