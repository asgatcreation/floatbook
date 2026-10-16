@php
    use App\Support\Money\Money;
    $naira = fn (int $kobo) => Money::kobo($kobo)->format(true);
    $change = $total['profit'] - $yesterday['profit'];
@endphp
{{ $business }} — {{ $dateLabel }}

@if (! $hasActivity)
Nothing was recorded today. If the shop was open, check that your staff are saving their entries.
@else
PROFIT TODAY: {{ $naira($total['profit']) }} ({{ $naira(abs($change)) }} {{ $change >= 0 ? 'more' : 'less' }} than yesterday)

Volume: {{ $naira($total['volume']) }} from {{ $total['count'] }} {{ $total['count'] === 1 ? 'entry' : 'entries' }}
Charges and commissions: {{ $naira($total['charges'] + $total['commissions']) }}
Provider fees: -{{ $naira($total['fees']) }}
Expenses: -{{ $naira($total['expenses']) }}
@endif

OUTLETS
@foreach ($outlets as $outlet)
- {{ $outlet['name'] }}: {{ $naira($outlet['profit']) }} profit, {{ $outlet['count'] }} {{ $outlet['count'] === 1 ? 'entry' : 'entries' }}, {{ $naira($outlet['volume']) }}
  Drawer {{ $naira($outlet['cash']) }}, wallets {{ $naira($outlet['wallets']) }}
@if ($outlet['counted'] === null)
  {{ $outlet['recount'] ? 'Needs counting again' : 'Not counted today' }}
@elseif ($outlet['variance'])
  {{ $outlet['counted'] }} — drawer {{ $outlet['variance'] < 0 ? 'short' : 'over' }} by {{ $naira(abs($outlet['variance'])) }}
@else
  {{ $outlet['counted'] }} — balanced
@endif
@endforeach

@if (count($attention) > 0)
NEEDS ATTENTION
@foreach ($attention as $line)
- {{ $line }}
@endforeach
@endif

Open FloatBook: {{ route('dashboard') }}

You get this once a day because you own this business. Turn it off under Settings -> Business.
