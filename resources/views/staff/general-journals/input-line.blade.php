<tr>
    <td><select name="lines[{{ $index }}][account]" aria-label="Ledger account" required>
        <option value="">Select account</option>
        @foreach ($accounts as $account)
            <option value="{{ $account->id }}" @selected((string) $account->id === (string) ($input['account'] ?? ''))>{{ $account->code }} · {{ $account->name }} (ID {{ $account->id }}){{ $cashAccountIds->has($account->id) ? ' · Cash book' : '' }}</option>
        @endforeach
    </select></td>
    <td><select name="lines[{{ $index }}][side]" aria-label="Debit or credit" required>
        <option value="debit" @selected(($input['side'] ?? '') === 'debit')>Debit</option>
        <option value="credit" @selected(($input['side'] ?? ($index === 1 ? 'credit' : 'debit')) === 'credit')>Credit</option>
    </select></td>
    <td><input name="lines[{{ $index }}][amount]" aria-label="Amount in TZS" inputmode="decimal" maxlength="15" value="{{ $input['amount'] ?? '' }}" required></td>
    <td><button type="button" data-remove aria-label="Remove journal line">Remove</button></td>
</tr>
