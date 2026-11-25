<?php

namespace App\Http\Controllers;

use App\TablePage;
use App\WorkflowTasks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TaskInboxController extends Controller
{
    public function index(Request $request, WorkflowTasks $workflow): View
    {
        $tasks = $workflow->forUser($request->user())->orderByRaw('since NULLS LAST')->orderBy('type')->orderBy('reference')
            ->tap(TablePage::search(['type', 'reference', 'since']))
            ->paginate(TablePage::size())->withQueryString();
        $members = DB::table('members')->whereIntegerInRaw('id', $tasks->getCollection()->pluck('member_id')->filter()->unique()->all())
            ->pluck('registration_no', 'id');
        $tasks->through(fn ($task): array => [
            ...$workflow->action($task), 'reference' => $task->reference, 'since' => $task->since,
            'member' => $members->get($task->member_id),
        ]);

        return view('staff.task-inbox', compact('tasks'));
    }

    public function count(Request $request, WorkflowTasks $workflow): JsonResponse
    {
        return response()->json(['count' => $workflow->forUser($request->user())->count()])
            ->header('Cache-Control', 'private, no-store');
    }
}
