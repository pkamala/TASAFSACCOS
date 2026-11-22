<?php

namespace App;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordLoanDisbursement
{
    public function __construct(private LedgerPeriods $periods) {}

    public function record(array $input, int $actor, int $member, int $application, int $batch): void
    {
        $data = Validator::make($input, [
            'transfer_reference' => ['required', 'string', 'max:100'],
            'transferred_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'transfer_note' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $reference = Str::upper($data['transfer_reference']);

        try {
            DB::transaction(function () use ($data, $reference, $actor, $member, $application, $batch): void {
                $record = DB::table('loan_applications')->where('member_id', $member)->lockForUpdate()->find($application);
                abort_if($record === null, 404);

                $existing = DB::table('loan_disbursements')->where('loan_application_id', $application)->first();
                if ($existing !== null && $existing->transfer_reference === $reference
                    && $existing->transferred_on === $data['transferred_on']
                    && $existing->note === ($data['transfer_note'] ?? null)) {
                    return;
                }
                if ($record->status !== 'approved' || $existing !== null) {
                    throw ValidationException::withMessages(['transfer_reference' => 'Only an approved loan without a recorded transfer can be disbursed.']);
                }

                if (DB::table('members')->where('id', $member)->value('status') !== 'active'
                    || DB::table('member_exits')->where('member_id', $member)->whereIn('status', ['requested', 'approved'])->exists()) {
                    throw ValidationException::withMessages(['transfer_reference' => 'The member is inactive or has an open exit request.']);
                }

                $approval = DB::table('loan_application_decisions')->where('loan_application_id', $application)
                    ->where('decision', 'approved')->value('reviewed_at');
                if ($approval === null) {
                    throw ValidationException::withMessages(['transfer_reference' => 'This loan does not have a recorded approval decision.']);
                }
                $approvedOn = CarbonImmutable::parse($approval)->setTimezone('Africa/Dar_es_Salaam')->toDateString();
                if ($data['transferred_on'] < $approvedOn) {
                    throw ValidationException::withMessages(['transferred_on' => 'The transfer date must be on or after the approval date.']);
                }

                $this->periods->requireOpenDates([$data['transferred_on']], 'transferred_on');

                DB::table('loan_disbursements')->insert([
                    'loan_application_id' => $application,
                    'transfer_reference' => $reference,
                    'cash_amount_minor' => $record->principal_minor,
                    'transferred_on' => $data['transferred_on'],
                    'note' => $data['transfer_note'] ?? null,
                    'loan_batch_id' => $batch,
                    'recorded_by' => $actor,
                    'recorded_at' => now(),
                ]);

                DB::table('loan_applications')->where('id', $application)->update(['status' => 'disbursed']);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['transfer_reference' => 'This transfer reference is already recorded.']);
        }

    }
}
