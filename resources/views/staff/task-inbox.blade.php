@extends('layout')
@section('title', 'Tasks')
@section('content')
    <div class="page-intro">
        <h1>Tasks</h1>
        <p>Work waiting for you or someone with your role. Completed items leave this list automatically.</p>
    </div>

    <section class="panel stacked-panel">
        <div class="table-wrap" tabindex="0" role="region" aria-label="Tasks awaiting action">
            <table>
                <thead><tr><th scope="col">Action</th><th scope="col">Reference</th><th scope="col">Member</th><th scope="col">Waiting since</th></tr></thead>
                <tbody>
                    @forelse ($tasks as $task)
                        <tr>
                            <th scope="row"><a href="{{ $task['url'] }}">{{ $task['title'] }}</a></th>
                            <td>{{ $task['reference'] }}</td>
                            <td>{{ $task['member'] ?? '—' }}</td>
                            <td>{{ $task['since'] ? \Carbon\CarbonImmutable::parse($task['since'])->setTimezone('Africa/Dar_es_Salaam')->format('d M Y') : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No tasks are waiting for your role.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $tasks, 'memberStatusFilter' => true])
    </section>
@endsection
