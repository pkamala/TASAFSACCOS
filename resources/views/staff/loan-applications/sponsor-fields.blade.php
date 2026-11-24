<div class="fields" @unless($selfService ?? false) data-sponsor-picker data-search-url="{{ route('staff.loan-sponsors.search', $profile->id, false) }}" @endunless>
    <p>Enter the registration numbers of two active member sponsors. Each sponsor confirms through My membership.</p>
    @for ($index = 0; $index < \App\LoanSponsorship::RequiredCount; $index++)
        @php($number = old('sponsors.'.$index, $numbers[$index] ?? ''))
        <label for="sponsor-{{ $index + 1 }}">Sponsor {{ $index + 1 }} registration number</label>
        <div class="sponsor-field">
            <input id="sponsor-{{ $index + 1 }}" name="sponsors[]" maxlength="30" value="{{ is_string($number) ? $number : '' }}" required>
            @unless ($selfService ?? false)
            <button type="button" aria-haspopup="dialog" data-open-sponsors hidden>Choose sponsor {{ $index + 1 }}</button>
            @endunless
        </div>
        <output for="sponsor-{{ $index + 1 }}" data-sponsor-name></output>
    @endfor
    @unless ($selfService ?? false)
    <dialog class="sponsor-dialog panel" aria-labelledby="sponsor-picker-title">
        <div class="fields">
            <h2 id="sponsor-picker-title">Choose a sponsor</h2>
            <label for="sponsor-search">Name or registration number</label>
            <input id="sponsor-search" type="search" maxlength="100" autocomplete="off" autofocus>
            <p role="status" data-sponsor-search-status></p>
            <ul class="sponsor-results" aria-label="Eligible members" data-sponsor-results></ul>
            <button type="button" data-close-sponsors>Cancel</button>
        </div>
    </dialog>
    @endunless
</div>
@unless ($selfService ?? false)
@once
    <script src="{{ asset('saccos-sponsor-picker.js') }}" defer></script>
@endonce
@endunless
