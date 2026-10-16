@php
    use App\Support\Money\Money;
    $naira = fn (int $kobo) => Money::kobo($kobo)->format(true);
    $change = $total['profit'] - $yesterday['profit'];
@endphp

@extends('mail.layout', ['title' => $business.' · '.$dateLabel, 'preheader' => $naira($total['profit']).' profit from '.$total['count'].' '.($total['count'] === 1 ? 'entry' : 'entries')])

@section('content')
    <p style="margin:0 0 4px;font-size:14px;color:#59616C;">{{ $dateLabel }}</p>
    <h1 style="margin:0 0 24px;font-size:22px;line-height:28px;letter-spacing:-0.02em;">{{ $business }}</h1>

    @if (! $hasActivity)
        <p style="margin:0 0 20px;font-size:15px;line-height:24px;">
            Nothing was recorded today. If the shop was open, check that your staff are saving their entries.
        </p>
    @else
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
            <tr>
                <td style="background:#F6F7F4;border-radius:12px;padding:16px 18px;">
                    <p style="margin:0 0 2px;font-size:13px;color:#59616C;">Profit today</p>
                    <p style="margin:0;font-size:28px;font-weight:700;letter-spacing:-0.02em;">{{ $naira($total['profit']) }}</p>
                    <p style="margin:6px 0 0;font-size:13px;color:#59616C;">
                        {{ $naira(abs($change)) }} {{ $change >= 0 ? 'more' : 'less' }} than yesterday
                    </p>
                </td>
            </tr>
        </table>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;font-size:14px;">
            <tr>
                <td style="padding:6px 0;color:#59616C;">Volume</td>
                <td style="padding:6px 0;text-align:right;font-weight:600;">{{ $naira($total['volume']) }}</td>
            </tr>
            <tr>
                <td style="padding:6px 0;color:#59616C;">Entries</td>
                <td style="padding:6px 0;text-align:right;font-weight:600;">{{ $total['count'] }}</td>
            </tr>
            <tr>
                <td style="padding:6px 0;color:#59616C;">Charges and commissions</td>
                <td style="padding:6px 0;text-align:right;font-weight:600;">{{ $naira($total['charges'] + $total['commissions']) }}</td>
            </tr>
            <tr>
                <td style="padding:6px 0;color:#59616C;">Provider fees</td>
                <td style="padding:6px 0;text-align:right;">−{{ $naira($total['fees']) }}</td>
            </tr>
            <tr>
                <td style="padding:6px 0;color:#59616C;">Expenses</td>
                <td style="padding:6px 0;text-align:right;">−{{ $naira($total['expenses']) }}</td>
            </tr>
        </table>
    @endif

    <h2 style="margin:0 0 12px;font-size:15px;font-weight:600;">Outlets</h2>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border-collapse:collapse;font-size:14px;">
        @foreach ($outlets as $outlet)
            <tr>
                <td style="padding:12px 0;border-top:1px solid #E2E5DF;">
                    <p style="margin:0 0 2px;font-weight:600;">{{ $outlet['name'] }}</p>
                    <p style="margin:0;font-size:13px;color:#59616C;">
                        {{ $outlet['count'] }} {{ $outlet['count'] === 1 ? 'entry' : 'entries' }} ·
                        {{ $naira($outlet['volume']) }} ·
                        drawer {{ $naira($outlet['cash']) }} · wallets {{ $naira($outlet['wallets']) }}
                    </p>
                    @if ($outlet['counted'] === null)
                        <p style="margin:4px 0 0;font-size:13px;color:#8A5A00;">{{ $outlet['recount'] ? 'Needs counting again' : 'Not counted today' }}</p>
                    @elseif ($outlet['variance'])
                        <p style="margin:4px 0 0;font-size:13px;color:#8A5A00;">
                            {{ $outlet['counted'] }} · drawer {{ $outlet['variance'] < 0 ? 'short' : 'over' }} by {{ $naira(abs($outlet['variance'])) }}
                        </p>
                    @else
                        <p style="margin:4px 0 0;font-size:13px;color:#12723A;">{{ $outlet['counted'] }} · balanced</p>
                    @endif
                </td>
                <td style="padding:12px 0;border-top:1px solid #E2E5DF;text-align:right;vertical-align:top;font-weight:600;">
                    {{ $naira($outlet['profit']) }}
                </td>
            </tr>
        @endforeach
    </table>

    @if (count($attention) > 0)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
            <tr>
                <td style="background:#FCF1D6;border-radius:12px;padding:16px 18px;">
                    <p style="margin:0 0 8px;font-size:14px;font-weight:600;color:#8A5A00;">Needs attention</p>
                    <ul style="margin:0;padding-left:18px;font-size:14px;line-height:22px;color:#59616C;">
                        @foreach ($attention as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </td>
            </tr>
        </table>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0">
        <tr>
            <td style="background:#E8A317;border-radius:12px;">
                <a href="{{ route('dashboard') }}" style="display:inline-block;padding:13px 24px;font-size:15px;font-weight:700;color:#10141B;text-decoration:none;">Open FloatBook</a>
            </td>
        </tr>
    </table>

    <p style="margin:20px 0 0;font-size:13px;line-height:20px;color:#59616C;">
        You get this once a day because you own this business. Turn it off under Settings → Business.
    </p>
@endsection
