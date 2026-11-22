@extends('layout')
@section('title', 'Branding')
@section('content')
    <div class="page-intro">
        <h1>Branding</h1>
        <p>Customize the site header, browser title, icon, and color palette.</p>
    </div>

    @if (session('status')) <p class="status" role="status">{{ session('status') }}</p> @endif
    @include('form-errors')

    <div class="profile-grid">
        <section class="panel">
            <h2>Site identity</h2>
            <form method="POST" action="{{ route('staff.branding.update') }}" enctype="multipart/form-data" class="fields">
                @csrf
                @method('PATCH')
                <label for="name">Display name</label>
                <input id="name" name="name" value="{{ is_string(old('name')) ? old('name') : $settings->name }}" maxlength="80" required>
                <label for="mark">Fallback initials</label>
                <input id="mark" name="mark" value="{{ is_string(old('mark')) ? old('mark') : $settings->mark }}" maxlength="3" pattern="[A-Za-z0-9]{1,3}" required>
                <label for="theme">Color palette</label>
                <select id="theme" name="theme">
                    @foreach ($themes as $value => $label)
                        <option value="{{ $value }}" @selected(old('theme', $settings->theme) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <label for="logo">Upload logo</label>
                <input id="logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp" aria-describedby="logo-guidance">
                <p class="note" id="logo-guidance">PNG, JPEG, or WebP. Maximum 1 MB and 1600 × 1600 pixels.</p>
                @if ($settings->logo_type)
                    <label class="check-field"><input type="checkbox" name="remove_logo" value="1"> Remove the current logo</label>
                @endif
                <button class="primary">Save branding</button>
            </form>
        </section>
        <section class="panel preview-panel">
            <h2>Current branding</h2>
            <div class="brand">
                @if ($settings->logo_type)
                    <img class="brand-logo" src="{{ route('branding.logo', $settings->id) }}" alt="">
                @else
                    <span class="brand-mark" aria-hidden="true">{{ $settings->mark }}</span>
                @endif
                <span>{{ $settings->name }}</span>
            </div>
            <p>{{ $themes[$settings->theme] }}</p>
        </section>
    </div>

    <section class="panel stacked-panel">
        <h2>Branding history</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Branding history">
            <table>
                <thead><tr><th scope="col">Changed on</th><th scope="col">Changed by</th><th scope="col">Display name</th><th scope="col">Initials</th><th scope="col">Palette</th><th scope="col">Logo</th></tr></thead>
                <tbody>
                    @forelse ($history as $row)
                        <tr>
                            <td>{{ \Carbon\CarbonImmutable::parse($row->changed_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</td>
                            <td>{{ $row->actor }}</td><td>{{ $row->name }}</td><td>{{ $row->mark }}</td><td>{{ $themes[$row->theme] }}</td><td>{{ $row->logo_type ? 'Uploaded' : 'Initials' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No branding changes recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $history])
    </section>
@endsection
