<a href="{{ route($destination) }}" @if (request()->routeIs($destination)) aria-current="page" @endif>{{ $label }}</a>
