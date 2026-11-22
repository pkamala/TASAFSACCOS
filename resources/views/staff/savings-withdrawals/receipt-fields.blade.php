<label for="reference">{{ $referenceLabel }}</label>
<input id="reference" name="reference" maxlength="100" value="{{ is_string(old('reference')) ? old('reference') : '' }}" required>
<label for="effective_on">Transaction date</label>
<input id="effective_on" name="effective_on" type="date" value="{{ is_string(old('effective_on')) ? old('effective_on') : '' }}" required>
<label for="note">Note <small>Optional</small></label>
<textarea id="note" name="note" maxlength="500">{{ is_string(old('note')) ? old('note') : '' }}</textarea>
