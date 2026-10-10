@php
    /** @var \App\Filament\Resources\Tasks\Pages\TaskBoard $this */
    $columns = $this->getColumns();
@endphp

<x-filament-panels::page>
    {{-- Giao diện Kanban dùng CSS riêng (không cần build theme): màu lấy từ biến màu của Filament nên chạy được cả dark mode. --}}
    <style>
        .sm-board-scope { display: inline-flex; gap: .25rem; padding: .25rem; border-radius: .625rem; background: color-mix(in oklab, var(--gray-500) 10%, transparent); width: fit-content; }
        .sm-board-scope button { padding: .3125rem .75rem; border-radius: .4375rem; font-size: .8125rem; font-weight: 500; opacity: .75; }
        .sm-board-scope button.is-active { background: #fff; opacity: 1; color: var(--primary-600); box-shadow: 0 1px 2px rgb(0 0 0 / .08); }
        .dark .sm-board-scope button.is-active { background: var(--gray-800); color: var(--primary-400); }
        .sm-board { display: grid; grid-template-columns: repeat(4, minmax(15rem, 1fr)); gap: 1rem; overflow-x: auto; padding-bottom: .5rem; }
        .sm-board-col { display: flex; flex-direction: column; min-height: 22rem; border-radius: .75rem; padding: .75rem; background: color-mix(in oklab, var(--gray-500) 8%, transparent); border: 1px solid color-mix(in oklab, var(--gray-500) 18%, transparent); }
        .sm-board-col.is-over { outline: 2px dashed var(--primary-500); outline-offset: -2px; }
        .sm-board-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .25rem .25rem .75rem; font-weight: 600; font-size: .875rem; }
        .sm-board-count { font-weight: 500; font-size: .75rem; opacity: .65; }
        .sm-board-list { display: flex; flex-direction: column; gap: .5rem; flex: 1; }
        .sm-card { cursor: grab; text-align: left; border-radius: .625rem; padding: .75rem; background: #fff; border: 1px solid color-mix(in oklab, var(--gray-500) 22%, transparent); box-shadow: 0 1px 2px rgb(0 0 0 / .05); transition: box-shadow .15s, transform .15s; }
        .dark .sm-card { background: var(--gray-900); }
        .sm-card:hover { box-shadow: 0 4px 12px rgb(0 0 0 / .1); transform: translateY(-1px); }
        .sm-card.is-dragging { opacity: .4; }
        .sm-card-title { font-size: .875rem; font-weight: 500; line-height: 1.35; }
        .sm-card-sub { margin-top: .125rem; font-size: .75rem; opacity: .7; }
        .sm-card-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem .5rem; margin-top: .5rem; font-size: .75rem; opacity: .8; }
        .sm-card-late { color: var(--danger-600); font-weight: 600; opacity: 1; }
        .dark .sm-card-late { color: var(--danger-400); }
        .sm-progress { height: .25rem; border-radius: 999px; margin-top: .625rem; background: color-mix(in oklab, var(--gray-500) 20%, transparent); overflow: hidden; }
        .sm-progress > span { display: block; height: 100%; border-radius: 999px; background: var(--primary-500); }
        .sm-board-empty { flex: 1; display: flex; align-items: center; justify-content: center; font-size: .8125rem; opacity: .55; border: 1px dashed color-mix(in oklab, var(--gray-500) 35%, transparent); border-radius: .625rem; padding: 1rem; text-align: center; }
        @media (max-width: 1024px) { .sm-board { grid-template-columns: repeat(4, 17rem); } }
    </style>

    <div class="sm-board-scope" role="tablist" aria-label="Phạm vi công việc">
        <button type="button" role="tab" @class(['is-active' => $this->scope === 'all']) aria-selected="{{ $this->scope === 'all' ? 'true' : 'false' }}" wire:click="setScope('all')">Tất cả</button>
        <button type="button" role="tab" @class(['is-active' => $this->scope === 'mine']) aria-selected="{{ $this->scope === 'mine' ? 'true' : 'false' }}" wire:click="setScope('mine')">Việc tôi phụ trách</button>
    </div>

    <div
        class="sm-board"
        x-data="{
            dragging: null,
            over: null,
            drop(status, list) {
                if (this.dragging === null) { return; }
                const card = this.$root.querySelector(`[data-task='${this.dragging}']`);
                const after = [...list.querySelectorAll('[data-task]')].find((el) => el !== card && this.pointerY < el.getBoundingClientRect().top + el.offsetHeight / 2);
                after ? list.insertBefore(card, after) : list.appendChild(card);
                const ids = [...list.querySelectorAll('[data-task]')].map((el) => Number(el.dataset.task));
                this.$wire.moveTask(Number(this.dragging), status, ids);
                this.dragging = null;
                this.over = null;
            },
            pointerY: 0,
        }"
    >
        @foreach ($columns as $key => $column)
            <div
                class="sm-board-col"
                wire:key="col-{{ $key }}"
                x-bind:class="{ 'is-over': over === '{{ $key }}' }"
                x-on:dragover.prevent="over = '{{ $key }}'; pointerY = $event.clientY"
                x-on:dragleave.self="over = null"
                x-on:drop.prevent="drop('{{ $key }}', $el.querySelector('[data-list]'))"
            >
                <div class="sm-board-head">
                    <x-filament::badge :color="$column['status']->getColor()">
                        {{ $column['status']->getLabel() }}
                    </x-filament::badge>
                    <span class="sm-board-count">{{ $column['tasks']->count() }}</span>
                </div>

                <div class="sm-board-list" data-list>
                    @forelse ($column['tasks'] as $task)
                        <div
                            role="button"
                            tabindex="0"
                            class="sm-card"
                            draggable="true"
                            data-task="{{ $task->getKey() }}"
                            wire:key="task-{{ $task->getKey() }}"
                            wire:click="mountAction('editTask', { task: {{ $task->getKey() }} })"
                            wire:keydown.enter="mountAction('editTask', { task: {{ $task->getKey() }} })"
                            x-on:dragstart="dragging = {{ $task->getKey() }}; $el.classList.add('is-dragging'); $event.dataTransfer.effectAllowed = 'move'"
                            x-on:dragend="$el.classList.remove('is-dragging'); over = null"
                        >
                            <div class="sm-card-title">{{ $task->name }}</div>

                            @if ($task->customer)
                                <div class="sm-card-sub">{{ $task->customer->name }}</div>
                            @endif

                            <div class="sm-card-meta">
                                <x-filament::badge size="sm" :color="$task->priority->getColor()">
                                    {{ $task->priority->getLabel() }}
                                </x-filament::badge>
                                <span>{{ $task->assignee?->name ?? 'Chưa giao' }}</span>
                                @if ($task->due_date)
                                    <span @class(['sm-card-late' => $task->isOverdue()])>
                                        Hạn {{ $task->due_date->format('d/m/Y') }}
                                    </span>
                                @endif
                            </div>

                            @if ($task->progress > 0 && $task->progress < 100)
                                <div class="sm-progress" title="Tiến độ {{ $task->progress }}%">
                                    <span style="width: {{ $task->progress }}%"></span>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="sm-board-empty">Kéo công việc vào đây</div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    @if (collect($columns)->sum(fn (array $column): int => $column['tasks']->count()) === 0)
        <x-filament::empty-state
            heading="Chưa có công việc nào."
            description="Thêm công việc đầu tiên, sau đó kéo thả thẻ giữa các cột để cập nhật trạng thái."
            icon="heroicon-o-rectangle-stack"
            icon-color="gray"
        />
    @endif
</x-filament-panels::page>
