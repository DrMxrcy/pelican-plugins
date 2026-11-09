<x-filament-widgets::widget class="pp-activity-heatmap">
    <x-filament::section :heading="$heading" :description="$description">
        <div style="overflow-x: auto;">
            <table
                aria-label="{{ $heading }}"
                style="width: 100%; min-width: 36rem; border-collapse: separate; border-spacing: 3px; table-layout: fixed; font-size: 0.75rem;"
            >
                <thead>
                    <tr>
                        <th scope="col" style="width: 3rem;"></th>
                        @foreach (range(0, 23) as $hour)
                            <th scope="col" style="font-weight: 400; opacity: 0.7; text-align: center;">
                                @if ($hour % 3 === 0)
                                    {{ sprintf('%02d', $hour) }}
                                @else
                                    <span class="fi-sr-only">{{ sprintf('%02d', $hour) }}</span>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <th scope="row" style="text-align: start; font-weight: 500; padding-inline-end: 0.5rem;">{{ $row['label'] }}</th>
                            @foreach ($row['cells'] as $cell)
                                @if ($cell['value'] === null)
                                    <td
                                        data-pp-cell="empty"
                                        title="{{ $cell['title'] }}"
                                        style="height: 1.5rem; border-radius: 4px; border: 1px dashed color-mix(in oklab, var(--gray-400) 45%, transparent);"
                                    ><span class="fi-sr-only">{{ $cell['title'] }}</span></td>
                                @else
                                    <td
                                        data-pp-cell="filled"
                                        title="{{ $cell['title'] }}"
                                        style="height: 1.5rem; border-radius: 4px; background-color: color-mix(in oklab, var(--primary-500) {{ $cell['strength'] }}%, transparent);"
                                    ><span class="fi-sr-only">{{ $cell['title'] }}</span></td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
