#!/usr/bin/env bash
#
# Xoá các file mã nguồn ĐÃ BỎ khỏi phần mềm nhưng có thể còn sót lại sau khi
# cập nhật bằng cách chép đè (rsync, giải nén): chép đè không xoá file cũ.
#
# Cần thiết vì Filament tự nạp mọi Resource / Widget trong thư mục app/: một
# file của module đã bỏ còn nằm lại sẽ hiện lại trên menu và gây lỗi.
#
#   ./scripts/remove-retired-files.sh
#
# Chạy lại nhiều lần đều an toàn. Chỉ xoá đúng các đường dẫn liệt kê dưới đây
# (mã nguồn), không đụng tới .env, storage/, backups/ hay database. Container
# `app` tự chạy script này mỗi lần khởi động (docker/entrypoint.sh).
set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f artisan ] || [ ! -d app ]; then
    echo "Không tìm thấy mã nguồn Service Manager ở $(pwd) — dừng lại." >&2
    exit 1
fi

RETIRED=(
    # Module Dự án và Ngân sách (đã bỏ: phần mềm chỉ còn quản lý Công việc).
    app/Enums/ProjectStatus.php
    app/Filament/Resources/BudgetItems
    app/Filament/Resources/Projects
    app/Filament/Resources/Contracts/RelationManagers/ProjectsRelationManager.php
    app/Filament/Resources/Customers/RelationManagers/ProjectsRelationManager.php
    app/Filament/Widgets/AttentionProjects.php
    app/Filament/Widgets/ExpenseVsBudget.php
    app/Filament/Widgets/ProjectProfitabilityChart.php
    app/Filament/Widgets/ProjectStatusChart.php
    app/Models/BudgetItem.php
    app/Models/Project.php
    app/Models/ProjectMember.php
    app/Models/Concerns/AffectsProjectCosts.php
    app/Policies/BudgetItemPolicy.php
    app/Policies/ProjectMemberPolicy.php
    app/Policies/ProjectPolicy.php
    app/Services/Projects
    config/project.php
    database/factories/BudgetItemFactory.php
    database/factories/ProjectFactory.php
    database/seeders/DemoProjectsSeeder.php
    resources/views/filament/resources/projects
    tests/Concerns/BuildsProjects.php
    tests/Feature/BudgetTest.php
    tests/Feature/ProjectTest.php
    tests/Feature/WithoutProjectTest.php
    # Nút "Ghi giờ công từ lịch" (đã bỏ: công tự tính theo phân công).
    app/Services/Scheduling/ScheduleTimesheets.php
)

removed=0

for path in "${RETIRED[@]}"; do
    if [ -e "$path" ]; then
        rm -rf -- "$path"
        echo "Đã xoá $path"
        removed=$((removed + 1))
    fi
done

if [ "$removed" -eq 0 ]; then
    echo "Không còn file cũ nào cần xoá."
fi
