{{--
    One money flow: what came in, what went out, or what was paid on the
    employee's behalf.

    The three flows are deliberately NOT rendered identically. Presenting them
    as three matching lists is why staff read employer contributions as money
    taken from them. Each carries a direction mark and, where it is not
    self-evident, a sentence saying what the flow actually means.

    Expects: $mark, $title, $lines, $totalLabel, $totalCentavos, and optional
    $note and $accent.
--}}
<p class="flow"><span class="flow-mark {{ $accent ?? '' }}">{{ $mark }}</span>{{ $title }}</p>

@if (!empty($note))
    <p class="flow-note">{{ $note }}</p>
@endif

@if (empty($lines))
    <p class="flow-note">Nothing this period.</p>
@else
    <table class="lines">
        @foreach ($lines as $line)
            <tr class="{{ $loop->index % 2 === 1 ? 'banded' : '' }}">
                <td class="line-label">{{ $payslipLabel($line['label']) }}</td>
                <td class="line-amount">{{ $peso($line['amount']) }}</td>
            </tr>
        @endforeach
        <tr class="line-total">
            <td>{{ $totalLabel }}</td>
            <td class="line-amount">{{ $peso($totalCentavos) }}</td>
        </tr>
    </table>
@endif
