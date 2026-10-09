<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light only">
<title>{{ $title ?? config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#F4F5F2;font-family:'Instrument Sans',Segoe UI,Helvetica,Arial,sans-serif;color:#12161C;">
@isset($preheader)
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preheader }}</div>
@endisset
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F5F2;">
<tr>
<td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">
<tr>
<td style="padding:0 4px 20px;">
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="width:36px;height:36px;background:#E8A317;border-radius:10px;text-align:center;vertical-align:middle;font-weight:700;font-size:18px;color:#10141B;">F</td>
<td style="padding-left:10px;font-size:20px;font-weight:700;letter-spacing:-0.02em;color:#10141B;">FloatBook</td>
</tr>
</table>
</td>
</tr>
<tr>
<td style="background:#FFFFFF;border:1px solid #E2E5DF;border-radius:16px;padding:32px 28px;">
@yield('content')
</td>
</tr>
<tr>
<td style="padding:20px 4px 0;font-size:12px;line-height:18px;color:#59616C;">
You are receiving this email because of an action on {{ config('app.name') }}.
If this wasn't you, you can safely ignore it.<br>
{{ config('app.name') }} · Back office for POS businesses
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
