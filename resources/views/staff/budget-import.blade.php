<dialog class="budget-import-dialog panel print-hide" id="budget-import" aria-labelledby="budget-import-title" @if($importOpen) open @endif>
    <h2 id="budget-import-title">Import {{ $year }} budget</h2>
    @if ($importOpen)
        @include('form-errors')
    @endif
    <p>Upload a CSV or single-sheet Excel file with an account code and annual amount in TZS for each income or expense account.</p>
    <a href="{{ route('staff.budgets.import.template') }}">Download CSV template</a>
    <form class="fields search" method="POST" enctype="multipart/form-data" action="{{ route('staff.budgets.import.preview') }}">
        @csrf
        <input type="hidden" name="year" value="{{ $year }}">
        <label for="budget-file">Budget file</label>
        <input id="budget-file" name="budget_file" type="file" accept=".csv,.xlsx" required>
        <button class="primary">Preview import</button>
    </form>
    @if ($preview)
        <h3>Review {{ count($preview['plan']) }} budget lines</h3>
        <div class="table-wrap budget-import-preview" tabindex="0" role="region" aria-label="Budget import preview">
            <table>
                <thead><tr><th scope="col">Account</th><th scope="col" class="money">Current (TZS)</th><th scope="col" class="money">Imported (TZS)</th></tr></thead>
                <tbody>
                    @foreach ($preview['plan'] as $line)
                        <tr>
                            <th scope="row">{{ $line['code'] }} · {{ $line['name'] }}</th>
                            <td class="money">{{ $line['previous_minor'] === null ? '—' : number_format($line['previous_minor'] / 100, 2) }}</td>
                            <td class="money">{{ number_format($line['amount_minor'] / 100, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <form class="search" method="POST" action="{{ route('staff.budgets.import.apply') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $preview['token'] }}">
            <button class="primary">Save imported budget</button>
        </form>
    @endif
    <p><a href="{{ route('staff.budgets.index', ['year' => $year]) }}" data-close-budget-import>Close import</a></p>
</dialog>
<script src="{{ asset('saccos-budget-import.js') }}" defer></script>
