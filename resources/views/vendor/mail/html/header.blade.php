{{-- Laravel's own header, with one addition: a school can put its logo here.

     Published rather than edited in place, and published ALONE — the mail
     component namespace is registered with the app's `views/vendor/mail`
     directory first and the framework's second, so every other component still
     resolves to the version Laravel ships and stays on its upgrade path.

     Two departures from the original. The `Laravel` slot special case is gone,
     because nothing in this app is ever going to send under that name. And the
     size is set here rather than taken from the theme's `.logo` class, which
     is a hard 75x75 square: a school crest is square, a wordmark is not, and
     one squashed into the other reads as a broken image. Height fixed, width
     automatic — the same rule the payslip and invoice PDFs size logos by.

     The slot stays plain text (the school's name) and doubles as the alt
     attribute. That matters: the plain-text half of this email renders the
     same slot through `text/header.blade.php`, and HTML put in here would
     arrive as literal markup in the inbox of anyone reading text-only. --}}
@props(['url', 'logo' => null])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($logo)
<img src="{{ $logo }}" alt="{{ trim(strip_tags($slot)) }}" style="height: 56px; width: auto; max-width: 220px; margin-top: 15px; margin-bottom: 10px;">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
