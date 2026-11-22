<section class="panel print-hide">
    @include('form-errors')
    <form class="fields search" method="GET" action="{{ $action }}">
        <label for="from">From</label>
        <input id="from" name="from" type="date" value="{{ $from }}" required>
        <label for="to">To</label>
        <input id="to" name="to" type="date" value="{{ $to }}" required>
        <button class="primary">Run report</button>
    </form>
    <button class="primary" type="button" onclick="window.print()">Print report</button>
</section>
