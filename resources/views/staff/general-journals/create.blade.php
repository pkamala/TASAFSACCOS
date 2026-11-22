@extends('layout')
@section('title', 'Prepare general journal')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.general-journals.index') }}">General journals</a>
        <h1>Prepare general journal</h1>
        <p>Use the dedicated loan and member fund workflows for transactions that change member balances.</p>
    </div>
    @include('form-errors')
    <section class="panel">
        <form method="POST" action="{{ route('staff.general-journals.preview') }}" class="fields">
            @csrf
            <label for="transaction-date">Ledger date</label>
            <input id="transaction-date" type="date" name="transaction_date" max="{{ $today }}" value="{{ is_string(old('transaction_date')) ? old('transaction_date') : ($preview['journal']['transaction_date'] ?? $today) }}" required>
            <label for="category">Category</label>
            <select id="category" name="category" required>
                @foreach (['expense' => 'Expense', 'fee' => 'Fee', 'general' => 'General'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('category', $preview['journal']['category'] ?? 'general') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <label for="source-reference">Source reference</label>
            <input id="source-reference" name="source_reference" maxlength="100" value="{{ is_string(old('source_reference')) ? old('source_reference') : ($preview['journal']['source_reference'] ?? '') }}" required>
            <label for="description">Description</label>
            <textarea id="description" name="description" maxlength="500" required>{{ is_string(old('description')) ? old('description') : ($preview['journal']['description'] ?? '') }}</textarea>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Journal lines">
                <table>
                    <thead><tr><th scope="col">Ledger account</th><th scope="col">Side</th><th scope="col">Amount (TZS)</th><th scope="col">Actions</th></tr></thead>
                    <tbody id="journal-lines">
                        @foreach ($inputs as $index => $input)
                            @include('staff.general-journals.input-line')
                        @endforeach
                    </tbody>
                </table>
            </div>
            <button type="button" id="add-journal-line">Add line</button>
            <button class="primary">Preview journal</button>
        </form>
        <template id="journal-line-template">
            <table><tbody>@include('staff.general-journals.input-line', ['index' => 0, 'input' => []])</tbody></table>
        </template>
    </section>
    @if ($preview)
        <section class="panel" id="journal-preview">
            <h2>Journal preview</h2>
            <p>{{ $preview['journal']['transaction_date'] }} · {{ ucfirst($preview['journal']['category']) }} · {{ $preview['journal']['source_reference'] }}</p>
            <p>{{ $preview['journal']['description'] }}</p>
            @include('staff.general-journals.lines', ['lines' => $previewLines])
            <form method="POST" action="{{ route('staff.general-journals.post') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $preview['token'] }}">
                <button class="primary">Post previewed journal</button>
            </form>
        </section>
    @endif
    <script>
        (() => {
            const lines = document.getElementById('journal-lines');
            const template = document.getElementById('journal-line-template');
            const add = document.getElementById('add-journal-line');

            function updateLines() {
                add.disabled = lines.rows.length >= 20;
                Array.from(lines.rows).forEach((row, index) => {
                    row.querySelectorAll('[name]').forEach(field => {
                        field.name = field.name.replace(/\[\d+\]/, '[' + index + ']');
                    });
                    row.querySelector('[data-remove]').disabled = lines.rows.length <= 2;
                });
            }

            add.addEventListener('click', () => {
                const row = template.content.querySelector('tr').cloneNode(true);
                lines.append(row);
                updateLines();
                row.querySelector('select').focus();
            });

            lines.addEventListener('click', event => {
                const remove = event.target.closest('[data-remove]');
                if (remove && lines.rows.length > 2) {
                    remove.closest('tr').remove();
                    updateLines();
                    add.focus();
                }
            });

            updateLines();
        })();
    </script>
@endsection
