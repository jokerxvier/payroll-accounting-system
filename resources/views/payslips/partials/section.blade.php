{{-- Reusable section: a list of audit lines with a labelled total. --}}
<div class="section">
    <h2>{{ $title }}</h2>
    @if(empty($lines))
        <p class="footnote" style="margin: 4px 0 0 0">No lines.</p>
    @else
        <table>
            <tbody>
            @foreach($lines as $line)
                <tr>
                    <td>
                        {{ $line['label'] }}<br>
                        <span class="code">{{ $line['code'] }}</span>
                    </td>
                    <td class="amount">₱{{ number_format($line['amount'] / 100, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr>
                <th style="text-align:left">{{ $totalLabel }}</th>
                <th class="amount">₱{{ number_format($totalCentavos / 100, 2) }}</th>
            </tr>
            </tfoot>
        </table>
    @endif
</div>
