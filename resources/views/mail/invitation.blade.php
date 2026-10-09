@extends('mail.layout', ['title' => 'You have been invited to '.$businessName, 'preheader' => $inviterName.' invited you to join '.$businessName.' on FloatBook.'])

@section('content')
<h1 style="margin:0 0 12px;font-size:22px;line-height:28px;font-weight:700;color:#10141B;">Join {{ $businessName }}</h1>
<p style="margin:0 0 8px;font-size:15px;line-height:22px;color:#12161C;">
{{ $inviterName }} has invited you to join <strong>{{ $businessName }}</strong> on FloatBook as <strong>{{ $roleLabel }}</strong>.
</p>
@if ($outletNames !== '')
<p style="margin:0 0 8px;font-size:15px;line-height:22px;color:#12161C;">Outlets: {{ $outletNames }}</p>
@endif
<p style="margin:0 0 24px;font-size:15px;line-height:22px;color:#12161C;">{{ $roleDescription }}</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
<tr>
<td style="background:#10141B;border-radius:12px;">
<a href="{{ $url }}" style="display:inline-block;padding:14px 24px;font-size:16px;font-weight:700;color:#FFFFFF;text-decoration:none;">Accept invitation</a>
</td>
</tr>
</table>
<p style="margin:0 0 8px;font-size:13px;line-height:20px;color:#59616C;">
This invitation expires on {{ $expiresOn }}. If the button doesn't work, copy this link into your browser:
</p>
<p style="margin:0;font-size:13px;line-height:20px;word-break:break-all;"><a href="{{ $url }}" style="color:#1D5FD1;">{{ $url }}</a></p>
@endsection
