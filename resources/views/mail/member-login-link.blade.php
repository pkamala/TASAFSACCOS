<x-mail::message>
# {{ $setup ? 'Set up your login' : 'Reset your password' }}

The SACCOS administrator requested a {{ $setup ? 'login setup' : 'password reset' }} link for your account.

<x-mail::button :url="$url">
{{ $setup ? 'Set password' : 'Reset password' }}
</x-mail::button>

This link can be used once and expires in {{ $expiresIn }} minutes. If you were expecting no change, contact the SACCOS administrator.

TASAF SACCOS
</x-mail::message>
