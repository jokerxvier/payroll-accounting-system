{{-- The message a parent actually receives. Written to be read on a phone in
     ten seconds: what it is, how much, by when, and one thing to press.

     The invoice PDF is attached, and the link is what pays it. The body itself
     still repeats neither the payer's address nor their TIN — those are on the
     attachment, where a document belongs, rather than in text a preview pane
     shows over someone's shoulder.

     Built on `x-mail::layout` rather than `x-mail::message` because that
     component hardcodes config('app.name') into its header and footer, which
     would tell a parent the invoice came from "Payroll and Accounting" — a
     vendor they have never heard of — instead of from their school.

     The header carries the school's logo when it has one, and falls back to
     its name in type when it does not — an email whose masthead is a broken
     image is worse than one with no image at all. See
     `resources/views/vendor/mail/html/header.blade.php`. --}}
<x-mail::layout>

<x-slot:header>
<x-mail::header :url="config('app.url')" :logo="$logoUrl">
{{ $schoolName }}
</x-mail::header>
</x-slot:header>

# {{ $invoiceNumber }}

Hello {{ $payerName }},

@if ($studentName)
{{ $schoolName }} has issued an invoice for **{{ $studentName }}**.
@else
{{ $schoolName }} has issued you an invoice.
@endif

<x-mail::panel>
**{{ $amount }}**@if ($dueDate) due by {{ $dueDate }}@endif
</x-mail::panel>

<x-mail::button :url="$payUrl">
View and pay
</x-mail::button>

The full invoice is attached, and it is on that page too, so you can pay later
if you would rather. The link is yours alone — please do not forward it.

If you have already paid, or something here looks wrong, reply to this message
@if ($schoolEmail)
and it will reach {{ $schoolName }} at {{ $schoolEmail }}.
@else
and the school office will sort it out.
@endif

Thanks,<br>
{{ $schoolName }}

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $schoolName }}
</x-mail::footer>
</x-slot:footer>

</x-mail::layout>
