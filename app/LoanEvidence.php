<?php

namespace App;

use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class LoanEvidence
{
    public function record(UploadedFile $file): int
    {
        $contents = $file->get();

        return DB::table('loan_evidence')->insertGetId([
            'pdf_base64' => base64_encode($contents),
            'sha256' => hash('sha256', $contents),
        ]);
    }

    public function download(int $id, string $filename): Response
    {
        $file = DB::table('loan_evidence')->find($id);
        abort_if($file === null, 404);

        return response(base64_decode($file->pdf_base64, true), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
