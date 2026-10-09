<x-filament-panels::page>
    <x-filament::empty-state
        :heading="'Module ' . $label . ' đang được phát triển'"
        :description="'Dự kiến hoàn thành ở Phase ' . $phase . '. Hệ thống được triển khai lần lượt theo từng phase; module này sẽ xuất hiện tại đây khi sẵn sàng.'"
        icon="heroicon-o-wrench-screwdriver"
        icon-color="gray"
    />
</x-filament-panels::page>
