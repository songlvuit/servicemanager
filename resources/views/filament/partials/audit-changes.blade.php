@php
    /** @var list<array{field: string, old: string, new: string}> $changes */
    $changes = $getState() ?? [];
@endphp

<div>
    <style>
        .sm-changes { width: 100%; border-collapse: collapse; font-size: .8125rem; table-layout: fixed; }
        .sm-changes th { text-align: left; font-weight: 600; padding: .5rem .625rem; border-bottom: 1px solid color-mix(in oklab, var(--gray-500) 25%, transparent); opacity: .75; }
        .sm-changes td { padding: .5rem .625rem; border-bottom: 1px solid color-mix(in oklab, var(--gray-500) 12%, transparent); vertical-align: top; overflow-wrap: anywhere; }
        .sm-changes tr:last-child td { border-bottom: 0; }
        .sm-changes td:first-child { font-weight: 500; }
        .sm-changes-old { opacity: .65; }
        .sm-changes-wrap { border: 1px solid color-mix(in oklab, var(--gray-500) 18%, transparent); border-radius: .625rem; overflow: hidden; }
    </style>

    <div class="sm-changes-wrap">
        <table class="sm-changes">
            <thead>
                <tr>
                    <th style="width: 30%">Trường</th>
                    <th style="width: 35%">Trước</th>
                    <th style="width: 35%">Sau</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($changes as $change)
                    <tr>
                        <td>{{ $change['field'] }}</td>
                        <td class="sm-changes-old">{{ $change['old'] === '' ? '—' : $change['old'] }}</td>
                        <td>{{ $change['new'] === '' ? '—' : $change['new'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
