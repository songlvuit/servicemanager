@php
    /**
     * Theo người: mỗi người một hàng, mỗi ngày một cột.
     *
     * @var array $data Kết quả của CalendarQuery::people()
     */
@endphp

<div class="sm-people-wrap">
    <table class="sm-people">
        <thead>
            <tr>
                <th scope="col">Nhân sự</th>
                @foreach ($data['days'] as $day)
                    <th scope="col" class="{{ $day['isToday'] ? 'is-today' : '' }}">
                        {{ $day['weekday'] }}
                        <span>{{ $day['date']->format('d/m') }}</span>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($data['rows'] as $row)
                <tr wire:key="person-{{ $row['user']->getKey() }}">
                    <td>
                        <div class="sm-person">{{ $row['user']->name }}</div>
                        <div class="sm-person-sub">
                            {{ $row['total'] > 0 ? $row['total'].' lịch trong tuần' : 'Chưa có lịch' }}
                        </div>
                    </td>

                    @foreach ($data['days'] as $day)
                        @php($entries = $row['cells'][$day['date']->toDateString()] ?? [])
                        <td>
                            <div class="sm-cell">
                                @foreach ($entries as $entry)
                                    @include('filament.pages.calendar.chip', [
                                        'entry' => $entry,
                                        'day' => $day['date'],
                                        'clickable' => true,
                                        // Chỉ đánh dấu trùng ở hàng của đúng người bị trùng.
                                        'conflictNames' => in_array($row['user']->name, $data['conflicts'][$entry->getKey()] ?? [], true) ? [$row['user']->name] : [],
                                    ])
                                @endforeach

                                @if ($entries === [])
                                    <div class="sm-cell-free">Trống</div>
                                @endif
                            </div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
