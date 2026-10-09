{{ $inviterName }} has invited you to join {{ $businessName }} on FloatBook as {{ $roleLabel }}.
@if ($outletNames !== '')
Outlets: {{ $outletNames }}
@endif

{{ $roleDescription }}

Accept the invitation here:
{{ $url }}

This invitation expires on {{ $expiresOn }}. If you weren't expecting it, you can ignore this email.
