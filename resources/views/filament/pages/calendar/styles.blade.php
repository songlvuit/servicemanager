{{--
    CSS của lịch làm việc, dùng chung cho trang trong panel và trang chỉ-xem
    công khai. Không cần build theme: màu lấy từ các biến màu của Filament
    (trang công khai tự khai báo các biến cùng tên), nên chạy được cả dark mode.
    Màu của loại lịch truyền qua biến --c trên từng thẻ và luôn đi kèm tên loại.
--}}
<style>
    .sm-cal { --line: color-mix(in oklab, var(--gray-500) 22%, transparent); --soft: color-mix(in oklab, var(--gray-500) 7%, transparent); --card: #fff; --ink-2: var(--gray-600); --ink-3: var(--gray-500); font-size: .8125rem; }
    .dark .sm-cal { --card: var(--gray-900); --ink-2: var(--gray-300); --ink-3: var(--gray-400); }

    /* Thanh công cụ */
    .sm-cal-bar { display: flex; flex-wrap: wrap; align-items: center; gap: .625rem 1rem; margin-bottom: 1rem; }
    .sm-cal-tabs { display: inline-flex; padding: .1875rem; border-radius: .625rem; background: var(--soft); border: 1px solid var(--line); }
    .sm-cal-tab { padding: .375rem .75rem; border-radius: .4375rem; font-weight: 500; color: var(--ink-2); white-space: nowrap; }
    .sm-cal-tab:hover { color: inherit; }
    .sm-cal-tab.is-active { background: var(--card); color: var(--primary-600); box-shadow: 0 1px 2px rgb(0 0 0 / .08); }
    .dark .sm-cal-tab.is-active { color: var(--primary-400); background: var(--gray-800); }
    .sm-cal-nav { display: inline-flex; align-items: center; gap: .25rem; }
    .sm-cal-btn { display: inline-flex; align-items: center; justify-content: center; gap: .25rem; min-width: 2rem; height: 2rem; padding: 0 .625rem; border-radius: .5rem; border: 1px solid var(--line); background: var(--card); font-weight: 500; }
    .sm-cal-btn:hover { background: var(--soft); }
    .sm-cal-btn svg { width: 1rem; height: 1rem; }
    .sm-cal-label { min-width: 10.5rem; text-align: center; font-size: .9375rem; font-weight: 600; font-variant-numeric: tabular-nums; }
    .sm-cal-filters { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-left: auto; }
    .sm-cal-select { height: 2rem; max-width: 11.5rem; padding: 0 1.75rem 0 .625rem; border-radius: .5rem; border: 1px solid var(--line); background-color: var(--card); color: inherit; font-size: .8125rem; }
    .sm-cal-check { display: inline-flex; align-items: center; gap: .375rem; color: var(--ink-2); white-space: nowrap; }
    .sm-cal-link { color: var(--primary-600); font-weight: 500; }
    .dark .sm-cal-link { color: var(--primary-400); }
    .sm-cal-link:hover { text-decoration: underline; }

    /* Lưới tuần: 7 ngày, các hàng (đầu ngày, ghi chú, sáng, chiều) thẳng hàng nhau nhờ subgrid */
    .sm-week-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: .75rem; background: var(--card); }
    .sm-week { display: grid; grid-template-columns: repeat(7, minmax(9.25rem, 1fr)); grid-template-rows: auto auto auto 1fr; min-width: 66rem; }
    .sm-day { display: grid; grid-template-rows: subgrid; grid-template-columns: minmax(0, 1fr); grid-row: span 4; border-left: 1px solid var(--line); min-width: 0; }
    .sm-day:first-child { border-left: 0; }
    .sm-day.is-weekend { background: color-mix(in oklab, var(--gray-500) 4%, transparent); }
    .sm-day-head { display: flex; align-items: center; justify-content: space-between; gap: .25rem; padding: .5rem .625rem; border-bottom: 1px solid var(--line); }
    .sm-day-name { font-weight: 600; }
    .sm-day-date { color: var(--ink-3); font-variant-numeric: tabular-nums; }
    .sm-day.is-today .sm-day-head { background: color-mix(in oklab, var(--primary-500) 12%, transparent); }
    .sm-day.is-today .sm-day-name { color: var(--primary-600); }
    .dark .sm-day.is-today .sm-day-name { color: var(--primary-400); }
    .sm-day-meta { display: flex; flex-direction: column; gap: .25rem; padding: .375rem .5rem 0; min-height: .375rem; min-width: 0; }
    .sm-more { min-width: 0; }
    .sm-more summary { cursor: pointer; font-size: .6875rem; line-height: 1.4; color: var(--ink-2); padding: 0 .125rem; }
    .sm-more summary:hover { color: var(--primary-600); }
    .sm-more[open] { display: flex; flex-direction: column; gap: .25rem; }
    .sm-band { display: flex; flex-direction: column; gap: .375rem; padding: .375rem .5rem .625rem; min-height: 5.5rem; }
    .sm-band + .sm-band { border-top: 1px dashed var(--line); }
    .sm-band-name { display: flex; align-items: center; justify-content: space-between; font-size: .6875rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--ink-3); }
    .sm-band-empty { color: var(--ink-3); opacity: .6; font-size: .75rem; padding: .25rem 0; }

    /* Thẻ lịch */
    .sm-entry { display: block; width: 100%; text-align: left; padding: .4375rem .5rem .5rem .5625rem; border-radius: .5rem; background: var(--card); border: 1px solid var(--line); border-left: 3px solid var(--c, var(--gray-400)); box-shadow: 0 1px 1px rgb(0 0 0 / .03); min-width: 0; }
    button.sm-entry { cursor: pointer; transition: box-shadow .15s, transform .15s; }
    button.sm-entry:hover, button.sm-entry:focus-visible { box-shadow: 0 3px 10px rgb(0 0 0 / .1); transform: translateY(-1px); }
    .sm-entry-top { display: flex; align-items: center; justify-content: space-between; gap: .375rem; font-size: .6875rem; color: var(--ink-2); }
    .sm-entry-type { display: inline-flex; align-items: center; gap: .3125rem; min-width: 0; font-weight: 500; }
    .sm-entry-type span:last-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .sm-dot { flex: none; width: .5rem; height: .5rem; border-radius: 999px; background: var(--c, var(--gray-400)); }
    .sm-entry-title { margin-top: .1875rem; font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }
    .sm-entry-line { display: flex; gap: .3125rem; margin-top: .1875rem; color: var(--ink-2); line-height: 1.35; overflow-wrap: anywhere; }
    .sm-entry-line svg { flex: none; width: .875rem; height: .875rem; margin-top: .0625rem; opacity: .7; }
    .sm-entry-line.is-time { font-weight: 500; font-variant-numeric: tabular-nums; }
    .sm-entry-line.is-note { color: var(--ink-3); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .sm-entry.is-cancelled { opacity: .55; }
    .sm-entry.is-cancelled .sm-entry-title { text-decoration: line-through; }
    .sm-entry.is-tentative { border-style: dashed; border-left-style: solid; }
    .sm-entry.is-conflict { box-shadow: 0 0 0 1.5px var(--danger-500); }
    .sm-flag { display: inline-flex; align-items: center; gap: .1875rem; flex: none; font-size: .6875rem; font-weight: 600; }
    .sm-flag svg { width: .875rem; height: .875rem; }
    .sm-flag.is-conflict { color: var(--danger-600); }
    .dark .sm-flag.is-conflict { color: var(--danger-400); }
    .sm-flag.is-muted { color: var(--ink-3); font-weight: 500; }
    .sm-entry-line.is-conflict { color: var(--danger-600); font-weight: 600; }
    .dark .sm-entry-line.is-conflict { color: var(--danger-400); }
    .sm-entry-line.is-conflict svg { opacity: 1; }

    /* Dải nhỏ: nghỉ phép, mốc dự án, lịch trên lưới tháng / theo người */
    .sm-chip { display: flex; align-items: center; gap: .3125rem; width: 100%; min-width: 0; padding: .1875rem .4375rem; border-radius: .375rem; font-size: .75rem; line-height: 1.35; text-align: left; background: color-mix(in oklab, var(--c, var(--gray-500)) 13%, transparent); border-left: 3px solid var(--c, var(--gray-400)); }
    button.sm-chip { cursor: pointer; }
    button.sm-chip:hover, button.sm-chip:focus-visible { background: color-mix(in oklab, var(--c, var(--gray-500)) 24%, transparent); }
    .sm-chip-time { flex: none; font-variant-numeric: tabular-nums; color: var(--ink-2); }
    .sm-chip-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; }
    .sm-chip svg { flex: none; width: .8125rem; height: .8125rem; opacity: .75; }
    .sm-chip.is-leave { --c: var(--gray-400); background-image: repeating-linear-gradient(135deg, transparent 0 5px, color-mix(in oklab, var(--gray-500) 12%, transparent) 5px 6px); }
    .sm-chip.is-milestone { border-left-color: transparent; background: transparent; color: var(--ink-2); padding-left: .125rem; }
    .sm-chip.is-cancelled { opacity: .55; text-decoration: line-through; }
    .sm-chip.is-conflict { box-shadow: 0 0 0 1.5px var(--danger-500); }

    /* Lưới tháng */
    .sm-month-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: .75rem; background: var(--card); }
    .sm-month { display: grid; grid-template-columns: repeat(7, minmax(7.5rem, 1fr)); min-width: 54rem; }
    .sm-month-head { padding: .5rem .625rem; font-weight: 600; color: var(--ink-2); border-bottom: 1px solid var(--line); }
    .sm-month-cell { display: flex; flex-direction: column; gap: .1875rem; min-height: 7.25rem; padding: .375rem; border-left: 1px solid var(--line); border-top: 1px solid var(--line); min-width: 0; }
    .sm-month-cell:nth-child(7n + 1) { border-left: 0; }
    .sm-month-cell.is-out { background: var(--soft); }
    .sm-month-cell.is-out .sm-month-num { opacity: .45; }
    .sm-month-num { align-self: flex-start; min-width: 1.625rem; height: 1.625rem; padding: 0 .375rem; border-radius: 999px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .sm-month-num:hover { background: var(--soft); }
    .sm-month-cell.is-today .sm-month-num { background: var(--primary-600); color: #fff; }
    .sm-month-more { padding: 0 .4375rem; font-size: .75rem; color: var(--ink-3); text-align: left; }
    .sm-month-more:hover { color: var(--primary-600); text-decoration: underline; }

    /* Theo người */
    .sm-people-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: .75rem; background: var(--card); }
    .sm-people { width: 100%; min-width: 62rem; border-collapse: collapse; table-layout: fixed; }
    .sm-people th, .sm-people td { border-left: 1px solid var(--line); border-top: 1px solid var(--line); padding: .375rem; vertical-align: top; }
    .sm-people tr:first-child th { border-top: 0; }
    .sm-people th:first-child, .sm-people td:first-child { border-left: 0; width: 11rem; position: sticky; left: 0; background: var(--card); z-index: 1; }
    .sm-people thead th { padding: .5rem .625rem; text-align: left; font-weight: 600; }
    .sm-people thead th.is-today { background: color-mix(in oklab, var(--primary-500) 12%, transparent); color: var(--primary-600); }
    .dark .sm-people thead th.is-today { color: var(--primary-400); }
    .sm-people thead th span { display: block; font-weight: 400; color: var(--ink-3); font-variant-numeric: tabular-nums; }
    .sm-person { font-weight: 600; overflow-wrap: anywhere; }
    .sm-person-sub { color: var(--ink-3); font-weight: 400; font-size: .75rem; }
    .sm-people .sm-chip { align-items: flex-start; }
    .sm-people .sm-chip-text { white-space: normal; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .sm-people .sm-chip svg { margin-top: .125rem; }
    .sm-cell { display: flex; flex-direction: column; gap: .1875rem; min-height: 2.75rem; }
    .sm-cell-free { color: var(--ink-3); opacity: .5; font-size: .75rem; padding: .25rem; }

    /* Lịch của tôi */
    .sm-agenda { display: flex; flex-direction: column; gap: 1rem; max-width: 46rem; }
    .sm-agenda-day { display: grid; grid-template-columns: 6.5rem minmax(0, 1fr); gap: .75rem; }
    .sm-agenda-date { padding-top: .375rem; }
    .sm-agenda-date strong { display: block; font-size: .9375rem; }
    .sm-agenda-date span { color: var(--ink-3); font-variant-numeric: tabular-nums; }
    .sm-agenda-day.is-today .sm-agenda-date strong { color: var(--primary-600); }
    .dark .sm-agenda-day.is-today .sm-agenda-date strong { color: var(--primary-400); }
    .sm-agenda-list { display: flex; flex-direction: column; gap: .375rem; }
    .sm-empty { padding: 2.5rem 1rem; text-align: center; border: 1px dashed var(--line); border-radius: .75rem; color: var(--ink-2); }
    .sm-empty strong { display: block; margin-bottom: .25rem; font-size: .9375rem; color: inherit; }

    /* Chú giải loại lịch */
    .sm-legend { display: flex; flex-wrap: wrap; gap: .375rem 1rem; margin-top: .875rem; color: var(--ink-2); font-size: .75rem; }
    .sm-legend-item { display: inline-flex; align-items: center; gap: .375rem; }
    .sm-legend-item.is-conflict svg { width: .875rem; height: .875rem; color: var(--danger-600); }

    /* Màn hình hẹp: lịch tuần xếp dọc từng ngày */
    @media (max-width: 860px) {
        .sm-week-wrap { overflow: visible; border: 0; background: transparent; }
        .sm-week { display: flex; flex-direction: column; gap: .75rem; min-width: 0; }
        .sm-day { display: block; border: 1px solid var(--line); border-radius: .75rem; background: var(--card); overflow: hidden; }
        .sm-day:first-child { border-left: 1px solid var(--line); }
        .sm-band { min-height: 0; }
        .sm-cal-filters { margin-left: 0; width: 100%; }
        .sm-cal-select { max-width: none; flex: 1 1 8rem; }
        .sm-cal-label { min-width: 0; }
        .sm-agenda-day { grid-template-columns: 1fr; gap: .25rem; }
    }
</style>
