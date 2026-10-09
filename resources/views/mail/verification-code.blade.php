@extends('mail.layout', ['title' => 'Your verification code', 'preheader' => 'Use this code to confirm your email address on FloatBook.'])

@section('content')
<h1 style="margin:0 0 12px;font-size:22px;line-height:28px;font-weight:700;color:#10141B;">Confirm your email</h1>
<p style="margin:0 0 20px;font-size:15px;line-height:22px;color:#12161C;">
Hi {{ $name }}, enter this code on FloatBook to confirm your email address:
</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
<tr>
<td style="background:#10141B;border-radius:12px;padding:16px 24px;font-size:32px;line-height:36px;font-weight:700;letter-spacing:10px;color:#E8A317;font-family:Consolas,Menlo,monospace;">{{ $code }}</td>
</tr>
</table>
<p style="margin:0;font-size:14px;line-height:21px;color:#59616C;">
The code expires in {{ $minutes }} minutes. FloatBook staff will never ask you for this code.
</p>
@endsection
