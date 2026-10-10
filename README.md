# Service Manager — Hệ thống quản lý công ty dịch vụ

Quản lý toàn bộ vòng đời: khách hàng → báo giá → phê duyệt → hợp đồng → dự án → ngân sách → công việc → chi phí → mua hàng → nghiệm thu → hóa đơn → thanh toán → lợi nhuận.

Hệ thống được xây theo từng phase. **Hiện tại: đã xong Phase 1–6.** Còn lại Phase 7 (rà soát UI, responsive, hiệu năng, bảo mật).

| Phase | Nội dung | Trạng thái |
|---|---|---|
| 1 | Hạ tầng Docker, đăng nhập, người dùng, vai trò & quyền, layout | Xong |
| 2 | Khách hàng, liên hệ, dịch vụ, cơ hội, báo giá, duyệt báo giá | Xong |
| 3 | Hợp đồng, dự án, dashboard dự án, công việc (Kanban), ngân sách | Xong |
| 4 | Chi phí, nhà cung cấp, mua hàng, chấm công, approval engine, kiểm soát chi phí | Xong |
| 5 | Nghiệm thu, hóa đơn, thanh toán, công nợ | Xong |
| 6 | Dashboard, lợi nhuận, báo cáo, xuất dữ liệu, thông báo, audit log, cấu hình | Xong |
| + | Lịch làm việc (lịch chung của công ty, ngoài 7 phase ban đầu) | Xong |
| 7 | Hoàn thiện UI, responsive, hiệu năng, bảo mật, test | Chưa làm |

---

## 1. Requirements

Chỉ cần cài trên máy:

- Docker Engine 24+ và Docker Compose v2 (Docker Desktop trên macOS/Windows đã gồm cả hai)
- Git (tuỳ chọn)

Không cần cài PHP, Composer, PostgreSQL hay Redis trên máy: tất cả chạy trong container.

Công nghệ bên trong: PHP 8.3, Laravel 12, Filament 5 (Livewire 4, Tailwind 4), PostgreSQL 16, Redis 7, Nginx, spatie/laravel-permission.

## 2. Installation

```bash
cp .env.example .env
docker compose up -d --build
```

Lần đầu mất vài phút vì container `app` phải tải thư viện bằng Composer. Lệnh `up` chỉ trả về khi ứng dụng đã sẵn sàng; muốn xem tiến độ, mở một cửa sổ khác:

```bash
docker compose logs -f app
```

Khi khởi động, container `app` tự làm các việc sau (xem `docker/entrypoint.sh`):

1. Cài thư viện Composer nếu chưa có thư mục `vendor/` (đồng thời sinh ra `composer.lock` — hãy commit file này).
2. Sinh `APP_KEY` nếu `.env` chưa có.
3. Chạy migration và đồng bộ danh mục vai trò & quyền.

Sau đó tạo tài khoản mẫu (chỉ dùng khi phát triển):

```bash
./scripts/artisan.sh db:seed
```

Mở <http://localhost> và đăng nhập bằng một trong các [tài khoản mẫu](#7-login-accounts).

## 3. Docker setup

| Service | Image | Vai trò |
|---|---|---|
| `app` | `php:8.3-fpm` + extension (xem `Dockerfile`) | Ứng dụng Laravel (PHP-FPM) |
| `nginx` | `nginx:1.27-alpine` | Web server, cổng `APP_PORT` (mặc định 80) |
| `queue` | cùng image với `app` | `php artisan queue:work` |
| `scheduler` | cùng image với `app` | `php artisan schedule:work` |
| `postgres` | `postgres:16-alpine` | Database, dữ liệu nằm trong volume `postgres_data` |
| `redis` | `redis:7-alpine` | Cache, session, queue |

- Mã nguồn được mount từ thư mục dự án vào container, nên sửa code là có hiệu lực ngay (riêng `queue` cần `docker compose restart queue`).
- PostgreSQL và Redis **không mở cổng ra ngoài**; chỉ các container trong cùng mạng truy cập được.
- `queue` và `scheduler` chỉ khởi động sau khi `app` chuẩn bị xong.

Lệnh thường dùng:

```bash
docker compose up -d            # chạy
docker compose down             # dừng (dữ liệu vẫn giữ trong volume)
docker compose ps               # trạng thái
docker compose logs -f app      # log ứng dụng
docker compose restart app      # khởi động lại app (tự chạy migration)
./scripts/artisan.sh <lệnh>     # chạy artisan trong container bằng user www-data
```

## 4. Environment

Toàn bộ cấu hình nằm trong `.env` (mẫu: `.env.example`).

| Biến | Ý nghĩa |
|---|---|
| `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL` | Thông tin ứng dụng. `APP_KEY` được sinh tự động. |
| `APP_PORT` | Cổng Nginx mở trên máy chủ (mặc định 80). |
| `APP_TIMEZONE`, `APP_LOCALE` | Mặc định `Asia/Ho_Chi_Minh`, `vi`. |
| `AUTO_MIGRATE` | `true`: tự chạy migration + đồng bộ quyền khi `app` khởi động. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Kết nối PostgreSQL. Container `postgres` cũng đọc các biến này khi khởi tạo lần đầu. |
| `REDIS_HOST`, `REDIS_PORT` | Kết nối Redis. |
| `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` | Mặc định đều dùng `redis`. |
| `FILESYSTEM_DISK` | `local` khi phát triển; đổi sang `s3` (S3 hoặc dịch vụ tương thích) khi cần. |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | Gửi email. Mặc định `log` (ghi vào log, không gửi thật). |

> Đặt `DB_PASSWORD` **trước lần chạy đầu tiên**. PostgreSQL chỉ nhận mật khẩu này khi tạo volume; đổi về sau phải đổi cả trong database (xem [Troubleshooting](#14-troubleshooting)).

Các ngưỡng nghiệp vụ (thuế mặc định, ngưỡng cảnh báo ngân sách, biên lợi nhuận, số ngày nhắc hạn…) có giá trị mặc định trong `config/quotation.php`, `config/project.php`, `config/billing.php` (đổi bằng biến `QUOTATION_*`, `PROJECT_*`, `INVOICE_*` trong `.env`) và **sửa được ngay trên giao diện ở Hệ thống → Cấu hình** — giá trị lưu trên giao diện được ưu tiên.

Quy ước hiển thị: tiền `45.000.000 ₫`, ngày `DD/MM/YYYY`, ngày giờ `DD/MM/YYYY HH:mm`.

## 5. Database migration

```bash
./scripts/artisan.sh migrate               # chạy migration còn thiếu
./scripts/artisan.sh migrate:status        # xem trạng thái
./scripts/artisan.sh migrate:fresh --seed  # XOÁ toàn bộ dữ liệu, tạo lại và seed (chỉ dùng khi phát triển)
```

Với `AUTO_MIGRATE=true`, migration tự chạy mỗi lần container `app` khởi động.

## 6. Seeder

| Seeder | Nội dung | Dùng ở đâu |
|---|---|---|
| `RolesAndPermissionsSeeder` | Danh mục quyền và 7 vai trò hệ thống theo `config/access.php` | Mọi môi trường. Chạy lại nhiều lần đều an toàn; không ghi đè quyền đã chỉnh trên giao diện. |
| `DevUserSeeder` | Tài khoản mẫu | Chỉ môi trường phát triển (tự bỏ qua khi `APP_ENV=production`). |
| `DemoDataSeeder` | 11 dịch vụ, 5 khách hàng, 10 liên hệ, 6 cơ hội, 10 báo giá ở đủ các trạng thái | Chỉ môi trường phát triển. Chỉ chạy khi chưa có khách hàng nào. |
| `DemoProjectsSeeder` | 5 hợp đồng, 8 dự án kèm ngân sách, thành viên và công việc | Chỉ môi trường phát triển. Chỉ chạy khi chưa có hợp đồng nào. |
| `DemoOperationsSeeder` | 5 nhà cung cấp, đề nghị chi ở đủ các bước duyệt, yêu cầu mua hàng, đơn mua hàng, giờ công | Chỉ môi trường phát triển. Chỉ chạy khi chưa có đề nghị chi nào. |
| `DemoBillingSeeder` | 4 biên bản nghiệm thu, 10 hóa đơn (nháp, đã phát hành, thu một phần, đã thu đủ, quá hạn), 10 lần thanh toán | Chỉ môi trường phát triển. Chỉ chạy khi chưa có hóa đơn nào. |
| `DemoScheduleSeeder` | 4 tài khoản ê-kíp và 20 lịch quanh tuần hiện tại (có lịch nhiều ngày, lịch dự kiến, lịch đã huỷ, một ngày nghỉ, một trường hợp trùng lịch) | Chỉ môi trường phát triển. Chỉ chạy khi chưa có lịch nào. |

```bash
./scripts/artisan.sh db:seed                                      # tất cả
./scripts/artisan.sh db:seed --class=RolesAndPermissionsSeeder    # chỉ vai trò & quyền
```

Dữ liệu demo đi qua đúng các quy trình nghiệp vụ (gửi duyệt, duyệt, thanh toán…), nên số liệu trên dashboard và báo cáo khớp với chứng từ, và Audit Log ghi lại từng bước dưới tên tài khoản mẫu thực hiện.

Khi một phase thêm module mới, `RolesAndPermissionsSeeder` tự cấp các quyền mới cho những vai trò hệ thống theo `config/access.php` (container `app` chạy seeder này mỗi lần khởi động).

## 7. Login accounts

**Chỉ dành cho môi trường phát triển.** Mật khẩu chung: `password`.

| Email | Vai trò |
|---|---|
| `admin@example.com` | Super Admin — toàn quyền |
| `director@example.com` | Director — xem toàn bộ, duyệt, xem tài chính |
| `sales@example.com` | Sales — khách hàng, cơ hội, báo giá, hợp đồng |
| `pm@example.com` | Project Manager — dự án, công việc, ngân sách, đề nghị chi |
| `accounting@example.com` | Accountant — chi phí, hóa đơn, thanh toán, công nợ |
| `procurement@example.com` | Procurement — nhà cung cấp, mua hàng |
| `employee@example.com` | Employee — công việc được giao, đề nghị chi |
| `quay@`, `dung@`, `sang@`, `thanh@example.com` | Employee — ê-kíp mẫu của Lịch làm việc (tạo bởi `DemoScheduleSeeder`) |

Trên production **không** chạy `DevUserSeeder`; tạo quản trị viên bằng:

```bash
./scripts/artisan.sh app:create-admin
```

## 8. Development

Cấu trúc chính:

```
app/
  Enums/                    Vai trò hệ thống, các trạng thái, nhóm ngân sách, đánh giá biên lợi nhuận
  Models/                   Mỗi bảng một model; Concerns/ chứa các trait dùng chung (Auditable, HasApprovals, HasAttachments…)
  Services/
    Workflow/               Khung đổi trạng thái dùng chung (transaction, khoá dòng, kiểm tra quyền)
    Quotations/ Contracts/ Projects/ Expenses/ Purchasing/ Billing/
                            Quy trình và phép tính của từng nghiệp vụ
    Approvals/              Bộ máy duyệt theo bảng approval_rules
    Reports/                Doanh thu – chi phí – lợi nhuận theo tháng, doanh thu theo dịch vụ
    Notifications/          Nhắc việc hằng ngày
    Scheduling/             Lịch làm việc: lưu lịch, trùng lịch, thông báo, các cách xem, iCalendar, ghi giờ công
    Sequences/              Sinh mã chứng từ (CUS-, QT-, HD-, PRJ-, EXP-, PR-, PO-, NT-, INV-…)
  Support/
    Money.php, Decimal.php  Định dạng VND và phép tính tiền chính xác (không dùng float)
    Settings.php            Cấu hình sửa trên giao diện (ghi đè config)
    Audit/                  Ghi nhật ký thao tác
    Access/                 Đọc danh mục module & quyền
  Policies/                 Phân quyền theo model
  Console/Commands/         quotations:expire, invoices:mark-overdue, reminders:send, app:create-admin
  Filament/
    Pages/                  Dashboard, Lịch làm việc, Thông báo, Cấu hình, Reports/ (lợi nhuận, doanh thu, chi phí)
    Widgets/                Thẻ KPI, biểu đồ, "Dự án cần chú ý", "Cần xử lý", "Hoạt động gần đây"
    Resources/              Mỗi module một thư mục (form, bảng, trang, tab liên quan)
    RelationManagers/       Tab dùng chung: Tài liệu, Hóa đơn, Hoạt động
    Support/                Cột/bộ lọc dùng chung, nút quy trình, xuất dữ liệu, bảng màu biểu đồ
config/access.php           Khai báo module, quyền, quyền mặc định của vai trò
config/quotation.php, project.php, billing.php, uploads.php, company.php
                            Giá trị mặc định của các quy tắc nghiệp vụ
database/migrations, seeders, factories
docker/                     Nginx, PHP, entrypoint
scripts/                    backup-db.sh, restore-db.sh, artisan.sh
tests/
```

Nguyên tắc khi sửa code:

- Giao diện không tự đổi cột `status`: mọi bước chuyển trạng thái đi qua một lớp trong `app/Services` (chạy trong transaction, khoá dòng, kiểm tra quyền và ghi nhật ký).
- Quy tắc nghiệp vụ đọc từ `config(...)` hoặc bảng `approval_rules`, không viết số cứng trong code.
- Model nào cần ghi Audit Log thì thêm `use Auditable;` và hằng `AUDIT_NAME`.

Lịch chạy tự động (container `scheduler`, giờ Việt Nam — xem `routes/console.php`):

| Giờ | Lệnh | Việc |
|---|---|---|
| 00:10 | `quotations:expire` | Báo giá quá ngày hiệu lực → Hết hạn |
| 00:15 | `invoices:mark-overdue` | Hóa đơn chưa thu đủ đã quá hạn → Quá hạn |
| 07:30 và 17:00 | `reminders:send` | Thông báo: hợp đồng sắp hết hạn, hóa đơn sắp tới hạn / quá hạn, dự án sắp tới hạn, lịch làm việc ngày mai (mỗi sự kiện chỉ nhắc một lần) |

Khi `APP_ENV=local`, scheduler còn chạy `dev:runner` mỗi phút: lệnh này thực hiện các tác vụ phát triển (migrate, seed, test…) được yêu cầu qua file `storage/app/dev-runner/request.json` và ghi kết quả vào `storage/logs/dev-runner/`. Lệnh không được lên lịch ở production.

Phân quyền:

- Tên quyền có dạng `{module}.{ability}`, ví dụ `customers.view`, `expenses.approve`. Danh mục khai báo trong `config/access.php`.
- Super Admin luôn có toàn quyền. Các vai trò khác chỉnh quyền trong màn hình **Hệ thống → Vai trò & quyền**.
- Thêm module mới: khai báo trong `config/access.php`, tạo Resource trong `app/Filament/Resources`, viết Policy kiểm tra `$user->can('<module>.<ability>')`, rồi chạy lại `RolesAndPermissionsSeeder`.
- Module khai báo `phase` lớn hơn `completed_phase` trong `config/access.php` sẽ hiện trên sidebar dưới dạng trang "đang phát triển" (hiện không còn module nào như vậy).

Thêm thư viện PHP:

```bash
docker compose exec app composer require <package>
```

## Nghiệp vụ Phase 2: khách hàng → cơ hội → báo giá

**Mã chứng từ** được cấp tự động, tăng dần và không dùng lại: `CUS-2026-0001` (khách hàng), `OPP-2026-0001` (cơ hội), `QT-2026-0001` (báo giá), `SV-0001` (dịch vụ).

**Quy trình báo giá** (toàn bộ nằm trong `app/Services/Quotations/QuotationWorkflow.php`):

```
Nháp ──gửi duyệt──▶ Chờ duyệt ──duyệt──▶ Đã duyệt ──▶ Đã gửi khách ──▶ Khách chấp nhận
                        │                                   │
                        └──từ chối──▶ Bị từ chối ◀──khách từ chối
                                          │
                                          └──soạn lại──▶ Nháp
```

- Chỉ báo giá **Nháp** mới sửa được nội dung và hạng mục; sau khi gửi duyệt, số liệu bị khoá (kể cả với Super Admin).
- Người có quyền `quotations.approve` duyệt hoặc từ chối (từ chối phải có lý do). Người lập và người duyệt nhận thông báo ở chuông trên thanh trên cùng.
- **Chiết khấu vượt 20%** giá trị báo giá: bắt buộc **Giám đốc** duyệt.
- **Biên lợi nhuận dưới 20%**: hiện cảnh báo "Biên lợi nhuận thấp." trên form, trang chi tiết và hộp xác nhận duyệt.
- Khi khách chấp nhận: cơ hội liên quan chuyển sang "Thắng", khách hàng tiềm năng thành "Đang hoạt động".
- Báo giá Đã duyệt / Đã gửi khách quá ngày hiệu lực tự chuyển sang "Hết hạn" (lệnh `quotations:expire`, scheduler chạy hằng ngày lúc 00:10).

**Công thức** (`app/Services/Quotations/QuotationCalculator.php`):

| | |
|---|---|
| Thành tiền hạng mục | Số lượng × Đơn giá − Chiết khấu |
| Thuế | Thành tiền hạng mục × Thuế suất (tính theo từng hạng mục) |
| Tổng cộng | Σ Thành tiền + Σ Thuế |
| Giá vốn ước tính | Σ Số lượng × Giá vốn một đơn vị |
| Lợi nhuận ước tính | Doanh thu − Giá vốn ước tính |
| Biên lợi nhuận | Lợi nhuận ước tính ÷ Doanh thu × 100 |

"Doanh thu" mặc định là giá trị **trước thuế**, vì VAT là khoản thu hộ; tính trên giá sau thuế sẽ làm biên lợi nhuận trông cao hơn thực tế. Nếu muốn đúng nguyên văn công thức `total − estimated_cost`, đặt `QUOTATION_PROFIT_BASE=total` trong `.env`.

Các ngưỡng (20% biên lợi nhuận, 20% chiết khấu, số ngày hiệu lực, thuế suất mặc định) nằm trong `config/quotation.php`, đổi bằng biến `QUOTATION_*` trong `.env`.

**Giá vốn và lợi nhuận là thông tin nội bộ**: chỉ người có quyền "Chi phí nội bộ & giá vốn" (`internal_costs.view`) mới thấy. Mặc định Sales không có quyền này — khi Sales lập báo giá, hệ thống tự lấy giá vốn mặc định của dịch vụ.

Tiền được lưu bằng `DECIMAL(15,2)` và tính bằng số thập phân chính xác (`app/Support/Decimal.php`), không dùng float.

## Nghiệp vụ Phase 3: hợp đồng → dự án → ngân sách → công việc

- **Hợp đồng**: Nháp → Chờ ký → Đang hiệu lực → Đã hoàn thành (hoặc Đã huỷ). Lập trực tiếp hoặc từ báo giá đã được khách chấp nhận. Chỉ hợp đồng nháp mới sửa / xoá được.
- **Rule 1**: chỉ tạo được dự án từ hợp đồng **Đang hiệu lực**.
- **Dự án**: Lập kế hoạch → Đang thực hiện ⇄ Tạm dừng → Hoàn thành (hoặc Đã huỷ). Người không có quyền "Xem tất cả" chỉ thấy dự án mình quản lý, là thành viên hoặc có việc được giao.
- **Ngân sách** lập theo nhóm (Nhân sự, Thiết bị, Nhà cung cấp, Đi lại, Sản xuất, Phần mềm, Marketing, Khác). Người dùng chỉ nhập số kế hoạch; số đã cam kết / thực tế / còn lại do hệ thống tính.
- **Công việc**: danh sách và bảng Kanban kéo-thả; tiến độ dự án là trung bình tiến độ các công việc chưa huỷ. Công việc không bắt buộc thuộc dự án — xem "Dự án là tuỳ chọn" ở Phase 4.
- **Trang dự án** là dashboard của dự án: doanh thu, ngân sách, chi phí cam kết / thực tế, lợi nhuận, biên lợi nhuận, đã thu / còn phải thu, tiến độ, bảng lãi lỗ theo nhóm chi phí.

## Nghiệp vụ Phase 4: chi phí, mua hàng, chấm công, duyệt

**Mọi số chi phí và doanh thu của dự án đều là số trước thuế.**

| Nguồn | Tính là "đã cam kết" khi | Tính là "thực tế" khi |
|---|---|---|
| Đề nghị chi | Đã duyệt | Đã thanh toán |
| Yêu cầu mua hàng | Đã duyệt, chưa lập đơn | — |
| Đơn mua hàng | Đã đặt hàng | Đã nhận hàng |
| Chấm công | — | Ngay khi ghi (giờ × đơn giá giờ của người đó tại thời điểm ghi) |

**Dự án là tuỳ chọn.** Công việc, đề nghị chi, yêu cầu mua hàng, đơn mua hàng và chấm công đều tạo được mà không gắn dự án (để trống ô "Dự án"): đó là *việc độc lập* và *chi phí chung* của công ty. Quy trình duyệt, thanh toán, nhận hàng giữ nguyên. Chi phí chung vẫn nằm trong **chi phí / lợi nhuận theo tháng** của công ty và trong Báo cáo chi phí (nhóm "Chi phí chung"), nhưng không tính vào ngân sách hay lợi nhuận của dự án nào. Các danh sách có bộ lọc **Không gắn dự án**.

- **Rule 6 / Rule 7**: chi phí thực tế ≥ 80% ngân sách → *Cảnh báo*; > ngân sách → *Vượt ngân sách* (báo cho quản lý dự án và Giám đốc). Ngưỡng 80% sửa ở Hệ thống → Cấu hình.
- **Đề nghị chi (Rule 9)**: Nháp → các bước duyệt → Đã duyệt → Đã thanh toán → Hoàn tất. **Rule 2**: chỉ thanh toán được khoản đã duyệt xong.
- **Quy trình duyệt cấu hình được** ở **Hệ thống → Quy trình duyệt** (bảng `approval_rules`: loại chứng từ, khoảng số tiền, vai trò duyệt, thứ tự). Mặc định:

  | Số tiền | Đề nghị chi | Yêu cầu mua hàng |
  |---|---|---|
  | Dưới 5 triệu | Quản lý dự án → Kế toán | Quản lý dự án → Kế toán |
  | 5 – 20 triệu | Quản lý dự án → Kế toán → **Quản lý** | Quản lý dự án → Kế toán |
  | Trên 20 triệu | Quản lý dự án → Kế toán → Giám đốc | Quản lý dự án → Kế toán → Giám đốc |

  > Hệ thống có 7 vai trò và không có vai trò "Quản lý" riêng, nên bước **Quản lý** (5 – 20 triệu) mặc định giao cho vai trò **Director**. Nếu công ty có cấp quản lý riêng: tạo vai trò mới ở Vai trò & quyền rồi đổi vai trò duyệt của bước này.

- Người đề nghị không tự duyệt chứng từ của mình; bước thuộc vai trò của chính người đề nghị được bỏ qua nhưng luôn còn ít nhất một bước do người khác duyệt.
- **Mua hàng**: Yêu cầu mua hàng (duyệt như trên) → Đơn mua hàng: Nháp → Đã đặt hàng → Đã nhận hàng → Hoàn tất. Đơn lập trực tiếp không qua yêu cầu cần người có quyền "Mua hàng — Duyệt" đặt hàng.
- **Tài liệu đính kèm** (hóa đơn, chứng từ, hợp đồng): PDF, JPG, PNG, DOC(X), XLS(X), tối đa 20MB; tải về qua đường dẫn có kiểm tra quyền, không truy cập trực tiếp được.

## Nghiệp vụ Phase 5: nghiệm thu → hóa đơn → thanh toán → công nợ

- **Nghiệm thu**: Nháp → Chờ duyệt → Đã nghiệm thu / Bị từ chối. Biên bản đã nghiệm thu là căn cứ lập hóa đơn.
- **Hóa đơn**: Nháp → Đã phát hành → Thu một phần → Đã thu đủ; quá ngày đến hạn mà chưa thu đủ → Quá hạn. Sau khi phát hành, trạng thái luôn được **suy ra** từ số đã thu và ngày đến hạn — không ai tự đặt "Đã thu đủ" được (**Rule 3**).
- **Rule 4**: hóa đơn đã phát hành không xoá được (chỉ huỷ, có lý do, khi chưa thu đồng nào).
- **Rule 5**: thanh toán đã ghi nhận không sửa, không xoá — kể cả Super Admin. Ghi nhầm thì phải xử lý bằng nghiệp vụ, không sửa lịch sử.
- **Công nợ**: các hóa đơn còn phải thu, chia nhóm tuổi nợ: chưa tới hạn, quá hạn 1–30, 31–60, 61–90, trên 90 ngày.

## Nghiệp vụ Phase 6: dashboard, báo cáo, thông báo, audit log, cấu hình

**Công thức tài chính toàn công ty** (`app/Services/Reports/FinancialReport.php`), đều trước thuế:

| | |
|---|---|
| Doanh thu tháng | Σ giá trị trước thuế của hóa đơn **đã phát hành** có ngày hóa đơn trong tháng (không tính nháp, đã huỷ) |
| Chi phí tháng | Σ chi phí thực tế phát sinh trong tháng: đề nghị chi đã thanh toán (theo ngày chi), đơn mua hàng đã nhận (theo ngày nhận), giờ công (theo ngày làm) |
| Lợi nhuận | Doanh thu − Chi phí |
| Biên lợi nhuận | Lợi nhuận ÷ Doanh thu × 100 |

Lợi nhuận **từng dự án** tính trên giá trị hợp đồng của dự án: Giá trị hợp đồng − Chi phí thực tế (không gồm chi phí chung không gắn dự án; phần này chỉ trừ vào lợi nhuận theo tháng của công ty). Đánh giá biên lợi nhuận: trên 30% *Tốt*, 20–30% *Bình thường*, dưới 20% *Cảnh báo*, dưới 10% *Nguy hiểm* (ngưỡng sửa ở Cấu hình).

**Doanh thu theo dịch vụ**: hóa đơn không ghi từng dịch vụ, nên doanh thu của mỗi hóa đơn được chia theo tỷ trọng các hạng mục trong báo giá của hợp đồng. Hợp đồng không lập từ báo giá được xếp vào "Chưa phân loại".

**Dashboard** hiển thị theo quyền của người đăng nhập:

| Khối | Ai thấy |
|---|---|
| Doanh thu tháng, doanh thu theo tháng / theo dịch vụ | Có quyền xem Hóa đơn hoặc Báo cáo tài chính |
| Chi phí, lợi nhuận, biên lợi nhuận (thẻ và biểu đồ theo tháng) | Có quyền Báo cáo tài chính |
| Công nợ, tuổi nợ | Có quyền xem Công nợ |
| Chi phí so với ngân sách, lợi nhuận theo dự án | Có quyền xem Dự án và "Chi phí nội bộ & giá vốn" |
| Dự án cần chú ý | Có quyền xem Dự án (không có quyền chi phí nội bộ thì chỉ thấy dự án trễ hạn) |
| Cần xử lý | Mọi người — mỗi dòng đếm đúng số chứng từ người đó được xem và bấm vào mở danh sách đã lọc sẵn |
| Hoạt động gần đây | Mọi người thấy thao tác của mình; có quyền Audit Log thì thấy của tất cả |

Người không xem được số liệu tài chính (nhân viên, quản lý dự án…) thấy thẻ về dự án, công việc và đề nghị chi của chính mình.

**Báo cáo** (nhóm Tài chính, quyền "Báo cáo tài chính"): Báo cáo lợi nhuận (theo dự án), Báo cáo doanh thu (lọc theo ngày, khách hàng, dự án, dịch vụ), Báo cáo chi phí (lọc theo ngày, dự án, nhóm chi phí, nhà cung cấp, nguồn). Báo cáo công nợ là màn hình **Công nợ**; báo cáo ngân sách – thực tế là màn hình **Ngân sách**.

**Xuất dữ liệu** (CSV và Excel): Khách hàng, Báo giá, Dự án, Ngân sách, Chi phí, Hóa đơn, Thanh toán, Công nợ và ba báo cáo. File chứa đúng những dòng đang hiển thị theo tab, bộ lọc và ô tìm kiếm (mọi trang, không chỉ trang đang xem). Cần quyền "Xuất dữ liệu" của module; cột giá vốn / chi phí chỉ có khi người xuất được xem chi phí nội bộ. Mỗi lần xuất được ghi vào Audit Log.

**Thông báo** hiện ở chuông trên thanh trên cùng và ở **Hệ thống → Thông báo** (giữ cả thông báo đã đọc): báo giá / đề nghị chi / yêu cầu mua hàng cần duyệt và kết quả duyệt, dự án vượt ngân sách, dự án sắp tới hạn, hợp đồng sắp hết hạn, hóa đơn sắp tới hạn, hóa đơn quá hạn.

**Audit Log** (Hệ thống → Audit Log, quyền `audit_logs.view`): ghi ai, làm gì, trên bản ghi nào, giá trị trước / sau, địa chỉ IP, trình duyệt, thời điểm — cho các thao tác tạo, sửa, đổi trạng thái (duyệt, thanh toán, phát hành…), xoá, khôi phục, đăng nhập, xuất dữ liệu, đổi cấu hình, đổi phân quyền. Nhật ký chỉ để xem: không sửa, không xoá được, kể cả Super Admin. Mật khẩu không bao giờ được ghi. Các trang Khách hàng, Báo giá, Hợp đồng, Dự án, Chi phí, Hóa đơn có tab **Hoạt động** cho riêng bản ghi đó.

**Cấu hình** (Hệ thống → Cấu hình; xem: `settings.view`, sửa: `settings.update` — mặc định chỉ Super Admin): thông tin công ty và các ngưỡng nghiệp vụ. Thay đổi có hiệu lực ngay và được ghi vào Audit Log.

**Trang lỗi** (403, 404, 419, 429, 500, 503) bằng tiếng Việt, không lộ chi tiết kỹ thuật khi `APP_DEBUG=false`; chi tiết lỗi nằm trong `storage/logs/laravel.log`.

## Lịch làm việc

Lịch chung của công ty tại **Tổng quan → Lịch làm việc**: ai làm việc gì, lúc nào, ở đâu, cho dự án nào.

**Lịch tự lấy từ Công việc và Dự án — trang Lịch chỉ để xem.** Không có nút thêm, sửa hay xoá lịch công việc ở trang Lịch; muốn thêm hoặc đổi lịch thì tạo / sửa **công việc** (mục Công việc, tab Công việc của dự án, bảng Kanban):

- Công việc **có ngày bắt đầu** tự hiện trên lịch, từ ngày bắt đầu tới hạn hoàn thành (không có hạn: một ngày). Công việc chưa có ngày bắt đầu, hoặc kéo dài quá 2 tháng, không lên lịch.
- Phần **Lịch làm việc** trong form công việc quyết định lịch trông thế nào: loại lịch, cả ngày hay từ giờ – đến giờ, ê-kíp (người phụ trách luôn có trong ê-kíp, thêm được cộng tác viên ngoài), địa điểm, thiết bị, khách hàng (với việc độc lập; việc thuộc dự án theo khách hàng của dự án), "lịch dự kiến (chưa chốt)".
- Sửa công việc → lịch đổi theo ngay. Công việc *Hoàn thành* → lịch *Đã xong*; *Đã huỷ* → *Đã huỷ*; còn lại là *Đã chốt* (hoặc *Dự kiến*). Bỏ ngày bắt đầu hoặc xoá công việc → lịch mất (lịch đã ghi giờ công thì giữ lại để tra cứu).
- Bấm một lịch để xem chi tiết; nút **Mở công việc** đưa tới trang công việc để sửa lịch, xem chi phí và ngân sách.
- Từ **dự án**, lịch có thêm mốc hạn hoàn thành của các dự án đang mở (hiện ở đầu ngày), và trang dự án có tab **Lịch** liệt kê lịch của các công việc thuộc dự án.
- **Việc dài ngày** — công việc cả ngày kéo dài hơn 3 ngày, ví dụ hậu kỳ hai tuần — hiện gọn thành một dải ở đầu mỗi ngày (quá 3 việc thì gom lại, bấm để mở) và **không tính là trùng lịch**, vì người làm việc dài ngày vẫn nhận được buổi quay khác. Chuyến đi quay cả ngày tới 3 ngày, hoặc việc có nhập giờ, vẫn giữ chỗ như thường.
- Lịch nghỉ là ngoại lệ duy nhất nhập ở trang Lịch (nút **Đăng ký nghỉ**).

**Dữ liệu nhập bằng mã** (seeder, tích hợp gọi `ScheduleManager::save()`): lịch tạo theo cách này tự có công việc của nó và hai bên vẫn luôn khớp nhau; một lịch cũng có thể gắn thêm vào công việc có sẵn (`task_id`). Dữ liệu của bản trước được nối lại khi chạy migration: lịch cũ có công việc, công việc cũ có ngày bắt đầu có lịch.

**Bốn cách xem:** *Tuần* (mỗi ngày chia Sáng / Chiều – tối, trước và sau 12:00), *Tháng*, *Theo người* (mỗi người một hàng — thấy ngay ai còn trống), *Lịch của tôi* (30 ngày tới của người đang đăng nhập). Màn hình hẹp tự xếp dọc từng ngày. Dashboard có khối "Lịch của tôi" cho 7 ngày tới, trang dự án có tab **Lịch**.

**Quyền:**

| Việc | Ai làm được |
|---|---|
| Xem toàn bộ lịch | Mọi người dùng (module này không có quyền "Xem") |
| Thêm / đổi lịch công việc | Người được tạo công việc (`tasks.create`) — nhập ở form công việc. Người chỉ được cập nhật công việc của mình đổi được trạng thái, tiến độ; lịch đi theo |
| Đăng ký nghỉ, sửa / xoá lịch nghỉ của chính mình | Mọi người dùng (nút **Đăng ký nghỉ**) |
| Đăng ký nghỉ hộ người khác, sửa / xoá lịch nghỉ của người khác | Quyền `schedules.create` / `update` / `delete` — mặc định Giám đốc và Quản lý dự án |
| Sửa danh mục loại lịch (Hệ thống → Loại lịch) | Quyền `schedule_types.*` — mặc định Giám đốc và Quản lý dự án |
| Bật / đổi / tắt đường dẫn chỉ-xem | Quyền `schedules.update` |
| Ghi giờ công từ lịch | Quyền sửa lịch + quyền ghi giờ công cho người khác (`timesheets.create`, `timesheets.view_all`) |

**Trùng lịch:** khi một người có hai lịch chồng thời gian, hoặc bị xếp việc vào lúc đang nghỉ, form công việc hiện cảnh báo ngay lúc nhập, thẻ lịch có viền đỏ kèm tên người bị trùng. Đây là **cảnh báo, không chặn** — đôi khi người xếp lịch cố ý. Lịch đã huỷ và việc dài ngày không tính; hai việc nối tiếp nhau (việc này kết thúc đúng lúc việc kia bắt đầu) không tính là trùng.

**Thông báo:** người phụ trách và ê-kíp của công việc được báo khi công việc lên lịch, lịch đổi giờ / đổi địa điểm, bị rút khỏi ê-kíp, công việc bị huỷ, và được nhắc về lịch ngày mai (lệnh `reminders:send`, chạy 07:30 và 17:00). Người tự thao tác không nhận thông báo về chính thao tác đó; lịch đã qua (nhập bù) không tạo thông báo. Khi có người đăng ký nghỉ, những người có quyền của module Lịch làm việc được báo.

**Trang công việc** (bấm vào một dòng ở mục Công việc): Ngân sách · Đã cam kết · Chi phí thực tế · Còn lại, cảnh báo *Vượt ngân sách*, và các tab Chi phí (đề nghị chi), Mua hàng, Chấm công, Lịch. Đề nghị chi, yêu cầu / đơn mua hàng có ô **Công việc**; chứng từ gắn công việc luôn thuộc dự án của công việc đó. Chi phí của công việc tính theo đúng quy tắc của dự án (bảng "cam kết / thực tế" ở Phase 4), số trước thuế. Việc đã xong vẫn nhận thêm chi phí; việc đã huỷ thì không. Trang công việc cũng cho biết công việc đang nằm ở đâu trên lịch (thời gian, loại lịch, ê-kíp, địa điểm). Người trong ê-kíp thấy công việc và gắn được đề nghị chi của mình vào đó, nhưng không thấy ngân sách và chi phí.

**Ghi giờ công từ lịch:** với lịch đã diễn ra (trừ lịch nghỉ), nút **Ghi giờ công** tạo cho mỗi người trong ê-kíp một dòng giờ công cho từng ngày của lịch (số giờ gợi ý = thời lượng lịch, hoặc 8 giờ/ngày với lịch cả ngày, nhiều ngày). Giờ công vào công việc của lịch và tự thành chi phí nhân sự của dự án; lịch không gắn dự án thì thành chi phí chung. Ghi giờ công xong thì lịch và công việc cùng chuyển sang đã xong. Bấm lại không ghi trùng.

**Chia sẻ ra ngoài hệ thống** (nút **Chia sẻ** trên trang lịch):

- *Đường dẫn chỉ-xem* `/lich/<mã>`: lịch tuần của cả công ty, mở được **không cần đăng nhập** — để dán vào nhóm chat hoặc treo màn hình văn phòng (tự tải lại mỗi 5 phút). **Mặc định tắt.** Ai có đường dẫn đều xem được tên việc, ê-kíp, khách hàng, địa điểm, thiết bị (không có số tiền nào), nên chỉ gửi cho người trong công ty; lộ thì bấm **Đổi đường dẫn** (đường dẫn cũ chết ngay) hoặc **Tắt**. Mỗi lần bật / đổi / tắt được ghi vào Audit Log.
- *Đưa lịch của tôi vào điện thoại*: mỗi người có một đường dẫn iCalendar riêng (`/lich-cua-toi/<mã>.ics`) để đăng ký vào Google Calendar, lịch iPhone, Outlook. Ứng dụng lịch tự cập nhật, thường chậm vài giờ. Tài khoản bị khoá thì đường dẫn cũng đóng.

Các trang công khai này chỉ đọc dữ liệu, trả về 404 với mã sai, có giới hạn 60 lượt/phút và yêu cầu công cụ tìm kiếm không lập chỉ mục. Khi triển khai thật nên chạy sau HTTPS (Cloudflare) để mã trong đường dẫn không đi qua mạng ở dạng rõ.

## 9. Testing

Test chạy bằng SQLite trong bộ nhớ (cấu hình trong `phpunit.xml`), không đụng tới PostgreSQL.

```bash
./scripts/artisan.sh test
./scripts/artisan.sh test --filter=RoleManagementTest
```

- Phase 1: đăng nhập, tài khoản bị khoá, phân quyền theo vai trò, quản lý người dùng, quản lý vai trò & quyền, điều hướng theo quyền, định dạng tiền tệ.
- Phase 2: sinh mã chứng từ, tạo khách hàng, liên hệ chính, danh mục dịch vụ, doanh thu kỳ vọng của cơ hội, tính toán báo giá, quy trình duyệt báo giá (kể cả quy tắc Giám đốc duyệt), khoá hạng mục sau khi gửi duyệt, hết hạn báo giá, ẩn giá vốn với Sales.

- Phase 3: hợp đồng và Rule 1, dự án và phạm vi xem theo thành viên, ngân sách, công việc và Kanban, tài liệu đính kèm.
- Phase 4: bộ máy duyệt theo số tiền, quy trình đề nghị chi (Rule 2, Rule 9), mua hàng, chấm công, chi phí cam kết / thực tế và cảnh báo ngân sách (Rule 6, Rule 7).
- Phase 5: nghiệm thu, hóa đơn (Rule 3, Rule 4), thanh toán (Rule 5, không thu quá số còn lại), tuổi nợ.
- Phase 6: doanh thu – chi phí – lợi nhuận theo tháng, tổng dòng chi phí khớp chi phí thực tế của dự án, chia doanh thu theo dịch vụ, dashboard theo từng vai trò, "Cần xử lý" khớp danh sách, báo cáo và bộ lọc, xuất CSV / Excel và quyền xuất, nhắc việc không trùng, màn hình thông báo, audit log (giá trị trước / sau, người thực hiện, che mật khẩu, không sửa xoá được), cấu hình.

- Công việc → lịch: công việc có ngày bắt đầu tự lên lịch, lịch đổi theo mọi thay đổi của công việc, giờ / nhiều ngày / quá 2 tháng, thông báo cho ê-kíp, trang Lịch không thêm – sửa – xoá được công việc, việc dài ngày không tính trùng lịch, nối dữ liệu cũ.
- Lịch → công việc (dữ liệu nhập bằng mã) và tiền của công việc: lịch tự có công việc và ngân sách, gắn vào công việc có sẵn, sửa / huỷ / xoá lịch đồng bộ sang công việc, chi phí cam kết / thực tế của công việc từ đề nghị chi – mua hàng – giờ công, chuyển dự án kéo theo chứng từ, quyền xem của ê-kíp, trang công việc, tạo bù công việc cho lịch cũ.
- Dự án là tuỳ chọn: việc độc lập, đề nghị chi / mua hàng / chấm công không gắn dự án đi đủ quy trình, tính vào chi phí tháng mà không vào dự án nào, bộ lọc "Không gắn dự án", nhóm "Chi phí chung" trong báo cáo chi phí.
- Lịch làm việc: nhãn thời gian và lịch cả ngày / nhiều ngày, phát hiện trùng lịch và trùng ngày nghỉ, quyền xếp lịch và tự quản lịch nghỉ, thông báo cho ê-kíp, sắp lịch theo tuần / tháng / người, mốc dự án, sửa – xoá lịch nghỉ từ trang lịch, ghi giờ công không trùng, nhắc lịch ngày mai, đường dẫn chỉ-xem (bật / đổi / tắt, 404 với mã sai), nguồn iCalendar.

> Chỉ chạy test khi `APP_ENV=local`. Test dùng `RefreshDatabase`; nếu cấu hình bị cache (production), bộ test sẽ tự từ chối chạy để không xoá dữ liệu thật.

## 10. Production deployment

Kiến trúc: Internet → Cloudflare → Nginx (container) → Laravel (PHP-FPM) → PostgreSQL / Redis.

1. Cài Docker trên VPS, đưa mã nguồn lên (git clone hoặc copy).
2. Tạo `.env` từ `.env.example` và sửa:

   ```dotenv
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://ten-mien-cua-ban
   LOG_LEVEL=warning
   DB_PASSWORD=<mật khẩu mạnh>
   ```

3. Chạy:

   ```bash
   docker compose up -d --build
   ./scripts/artisan.sh app:create-admin
   ```

   Ở chế độ production, `app` cài Composer với `--no-dev` và cache cấu hình, route, view.

4. Trỏ tên miền qua Cloudflare về VPS. Nginx trong container chỉ nghe cổng HTTP (`APP_PORT`); HTTPS do Cloudflare đảm nhận. Nên giới hạn tường lửa của VPS để cổng này chỉ nhận kết nối từ dải IP của Cloudflare.

Cập nhật phiên bản mới:

```bash
git pull
docker compose exec app composer install --no-dev --optimize-autoloader
docker compose restart app queue scheduler   # app tự chạy migration và cache lại cấu hình
```

Lưu ý khi chạy production:

- Sau khi sửa `.env` phải `docker compose restart app queue scheduler` vì cấu hình đã được cache.
- Không chạy `migrate:fresh`, `db:seed` (không tham số) hay `test` trên production.
- Đặt lịch sao lưu hằng ngày (mục 12).

## 11. File lưu trữ

File upload nằm trong `storage/app` (disk `local`). Ứng dụng luôn truy cập file qua lớp Storage của Laravel, nên khi cần chuyển sang S3 hoặc dịch vụ tương thích chỉ cần đổi `FILESYSTEM_DISK` và điền các biến `AWS_*`.

Giới hạn mặc định 20MB/file. Khi đổi, sửa cả `docker/php/php.ini` (`upload_max_filesize`, `post_max_size`) và `docker/nginx/default.conf` (`client_max_body_size`).

## 12. Backup

```bash
./scripts/backup-db.sh
```

Tạo file `backups/db-YYYYmmdd-HHMMSS.dump`. Tự xoá bản cũ hơn N ngày:

```bash
BACKUP_RETENTION_DAYS=14 ./scripts/backup-db.sh
```

Sao lưu tự động hằng ngày lúc 02:00 bằng cron trên VPS:

```cron
0 2 * * * cd /duong-dan/toi/du-an && BACKUP_RETENTION_DAYS=14 ./scripts/backup-db.sh >> backups/backup.log 2>&1
```

Ngoài database, cần sao lưu thêm file `.env` và thư mục `storage/app` (file upload). Audit Log và cấu hình nằm trong database nên đã có trong bản sao lưu. Nên chép các bản sao lưu ra khỏi VPS.

## 13. Restore

```bash
./scripts/restore-db.sh backups/db-20260101-020000.dump
```

Script hỏi xác nhận trước khi ghi đè database hiện tại (thêm `--yes` để bỏ qua bước hỏi).

Chuyển hệ thống sang VPS khác:

1. VPS cũ: `./scripts/backup-db.sh`, rồi chép sang VPS mới file `.dump`, file `.env` và thư mục `storage/app`.
2. VPS mới: đưa mã nguồn lên, đặt `.env` vào, chạy `docker compose up -d --build`.
3. VPS mới: `./scripts/restore-db.sh backups/<file>.dump`.

## 14. Troubleshooting

| Hiện tượng | Cách xử lý |
|---|---|
| `docker compose up` đứng lâu ở lần đầu | Bình thường: `app` đang cài thư viện. Theo dõi bằng `docker compose logs -f app`. |
| `Bind for 0.0.0.0:80 failed: port is already allocated` | Cổng 80 đang có ứng dụng khác dùng. Đặt `APP_PORT=8080` và `APP_URL=http://localhost:8080` trong `.env` rồi `docker compose up -d`, truy cập <http://localhost:8080>. |
| Container `app` khởi động lại liên tục | Xem `docker compose logs app`: thường do migration lỗi hoặc không kết nối được database. |
| `502 Bad Gateway` | `app` chưa chuẩn bị xong hoặc đã dừng. Kiểm tra `docker compose ps` và log của `app`. |
| Lỗi quyền ghi vào `storage/` (thường gặp trên Linux) | `docker compose exec app chown -R www-data:www-data storage bootstrap/cache` |
| Sửa `.env` nhưng không có tác dụng | `docker compose restart app queue scheduler` |
| Đã đổi `DB_PASSWORD` sau lần chạy đầu và không kết nối được | Đổi cả trong PostgreSQL: `docker compose exec postgres psql -U <DB_USERNAME> -d <DB_DATABASE> -c "ALTER USER <DB_USERNAME> PASSWORD '<mật khẩu mới>'"` |
| Trang không có CSS / JS | `./scripts/artisan.sh filament:assets` |
| "419 Page Expired" khi đăng nhập | `APP_URL` chưa khớp với địa chỉ đang truy cập (http/https, tên miền). Sửa `.env`, khởi động lại `app`, xoá cookie của trang. |
| Test báo "Từ chối chạy test" | Cấu hình đang bị cache: `./scripts/artisan.sh optimize:clear` (chỉ chạy test ở môi trường phát triển). |
| Biểu đồ dashboard trống dù đã có chứng từ | Doanh thu chỉ tính hóa đơn **đã phát hành**; chi phí chỉ tính khoản **đã thanh toán / đã nhận hàng**. Kiểm tra trạng thái chứng từ và ngày của chúng. |
| Không nhận được thông báo nhắc hạn | Container `scheduler` phải đang chạy (`docker compose ps`). Chạy thử: `./scripts/artisan.sh reminders:send`. |
| Số trên Cấu hình không đổi sau khi sửa `.env` | Giá trị đã lưu trên giao diện được ưu tiên hơn `.env`. Sửa ngay tại Hệ thống → Cấu hình. |
| Đường dẫn chỉ-xem của lịch báo 404 | Đường dẫn đang tắt hoặc mã đã được đổi. Mở Lịch làm việc → Chia sẻ để lấy đường dẫn hiện hành. |
| Lịch trên điện thoại không cập nhật ngay | Google Calendar / iPhone tự tải lại nguồn iCalendar theo lịch riêng của chúng (thường vài giờ một lần); đây là giới hạn của ứng dụng lịch. |
| Muốn làm lại từ đầu (xoá sạch dữ liệu) | `docker compose down -v` rồi `docker compose up -d --build` |
