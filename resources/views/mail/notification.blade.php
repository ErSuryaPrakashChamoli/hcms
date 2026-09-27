<x-mail::message>
{!! nl2br(e($bodyText)) !!}

Thanks,<br>
{{ config('peopleos.name') }}
</x-mail::message>
