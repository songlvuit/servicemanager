# Service Manager — Hệ thống quản lý công ty dịch vụ

Quản lý toàn bộ vòng đời: khách hàng → báo giá → phê duyệt → hợp đồng → công việc (ngân sách, lịch) → chi phí → mua hàng → nghiệm thu → hóa đơn → thanh toán → lợi nhuận.

> **Không còn module Dự án.** Phần mềm quản lý các **công việc độc lập**; mỗi công việc gắn được với một hợp đồng và một báo giá. Nội dung hợp đồng, báo giá chỉ **Giám đốc và Kế toán** xem được — xem [Công việc, hợp đồng và báo giá](#nghiệp-vụ-phase-3-hợp-đồng--công-việc).

Hệ thống được xây theo từng phase. **Hiện tại: đã xong Phase 1–6.** Còn lại Phase 7 (rà soát UI, responsive, hiệu năng, bảo mật).

| Phase | Nội dung | Trạng thái |
|---|---|---|
| 1 | Hạ tầng Docker, đăng nhập, người dùng, vai trò & quyền, layout | Xong |
| 2 | Khách hàng, liên hệ, dịch vụ, cơ hội, báo giá, duyệt báo giá | Xong |
| 3 | Hợp đồng, công việc (danh sách, Kanban, ngân sách), công việc ↔ hợp đồng / báo giá | Xong |
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

Các ngưỡng nghiệp vụ (thuế mặc định, ngưỡng cảnh báo ngân sách, biên lợi nhuận, số ngày nhắc hạn…) có giá trị mặc định trong `config/quotation.php`, `config/job.php`, `config/billing.php` (đổi bằng biến `QUOTATION_*`, `JOB_*`, `INVOICE_*` trong `.env`; biến `PROJECT_*` của bản cũ vẫn được đọc) và **sửa được ngay trên giao diện ở Hệ thống → Cấu hình** — giá trị lưu trên giao diện được ưu tiên.

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
| `DemoContractsSeeder` | 5 hợp đồng, 8 công việc lớn gắn hợp đồng (có ngân sách) và 20 công việc con | Chỉ môi trường phát triển. Chỉ chạy khi chưa có hợp đồng nào. |
| `DemoOperationsSeeder` | 5 nhà cung cấp, đề nghị chi ở đủ các bước duyệt, yêu cầu mua hàng, đơn mua hàng, công nhập tay cho các việc dài ngày | Chỉ môi trường phát triển. Chỉ chạy khi chưa có đề nghị chi nào. |
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
| `director@example.com` | Director — xem toàn bộ, duyệt, xem tài chính; toàn quyền với báo giá, hợp đồng, công việc |
| `sales@example.com` | Sales — khách hàng, cơ hội (không xem được báo giá, hợp đồng) |
| `pm@example.com` | Project Manager ("Quản lý công việc") — công việc, ngân sách, lịch, đề nghị chi (không xem được hợp đồng, báo giá) |
| `accounting@example.com` | Accountant — lập báo giá, hợp đồng, nghiệm thu; chi phí, hóa đơn, thanh toán, công nợ |
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
    Quotations/ Contracts/ Tasks/ Expenses/ Purchasing/ Billing/
                            Quy trình và phép tính của từng nghiệp vụ
    Approvals/              Bộ máy duyệt theo bảng approval_rules
    Reports/                Doanh thu – chi phí – lợi nhuận theo tháng, doanh thu theo dịch vụ
    Notifications/          Nhắc việc hằng ngày
    Scheduling/             Lịch làm việc: lưu lịch, trùng lịch, thông báo, các cách xem, iCalendar
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
    Widgets/                Thẻ KPI, biểu đồ, "Công việc cần chú ý", "Cần xử lý", "Hoạt động gần đây"
    Resources/              Mỗi module một thư mục (form, bảng, trang, tab liên quan)
    RelationManagers/       Tab dùng chung: Tài liệu, Hóa đơn, Hoạt động
    Support/                Cột/bộ lọc dùng chung, nút quy trình, xuất dữ liệu, bảng màu biểu đồ
config/access.php           Khai báo module, quyền, quyền mặc định của vai trò
config/quotation.php, job.php, billing.php, uploads.php, company.php
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
| 07:30 và 17:00 | `reminders:send` | Thông báo: hợp đồng sắp hết hạn, hóa đơn sắp tới hạn / quá hạn, lịch làm việc ngày mai (mỗi sự kiện chỉ nhắc một lần) |

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

**Giá vốn và lợi nhuận là thông tin nội bộ**: chỉ người có quyền "Chi phí nội bộ & giá vốn" (`internal_costs.view`) mới thấy. Người lập báo giá mà không có quyền này thì hệ thống tự lấy giá vốn mặc định của dịch vụ.

**Ai làm việc với báo giá, hợp đồng:** mặc định chỉ **Kế toán** (lập, sửa, gửi duyệt) và **Giám đốc** (toàn quyền, duyệt). Kinh doanh quản lý khách hàng và cơ hội, không xem được báo giá, hợp đồng, hóa đơn, công nợ. Muốn cho thêm người xem: cấp quyền *Báo giá — Xem* / *Hợp đồng — Xem* ở **Hệ thống → Vai trò & quyền**.

Tiền được lưu bằng `DECIMAL(15,2)` và tính bằng số thập phân chính xác (`app/Support/Decimal.php`), không dùng float.

## Nghiệp vụ Phase 3: hợp đồng → công việc

- **Hợp đồng**: Nháp → Chờ ký → Đang hiệu lực → Đã hoàn thành (hoặc Đã huỷ). Lập trực tiếp hoặc từ báo giá đã được khách chấp nhận. Chỉ hợp đồng nháp mới sửa / xoá được. Hợp đồng còn công việc đang mở thì chưa hoàn thành / huỷ được.
- **Công việc** là đơn vị quản lý duy nhất (không có "dự án"): danh sách, **bảng Kanban** kéo-thả (Công việc → Bảng Kanban; lọc "Việc tôi phụ trách"), trang chi tiết với tiền và lịch. Mỗi công việc có **ngân sách** riêng; chi phí đã cam kết / thực tế do hệ thống tính từ chứng từ. Công việc có thể có **công việc con** — chi phí của việc con được tính vào việc cha (ngân sách của việc lớn bao trùm các việc con).
- Người không có quyền "Xem tất cả" chỉ thấy công việc mình phụ trách hoặc có trong ê-kíp.

### Công việc, hợp đồng và báo giá

- Mỗi công việc gắn được với **một hợp đồng** và **một báo giá**. Gắn hợp đồng thì báo giá và khách hàng tự lấy theo hợp đồng; công việc con luôn theo hợp đồng, báo giá của công việc cha.
- Gắn ở ba nơi: form công việc (phần *Hợp đồng & báo giá*), nút **Hợp đồng & báo giá** trên trang công việc, hoặc tab **Công việc** của trang hợp đồng (thêm công việc mới, *Gắn công việc có sẵn*, *Gỡ khỏi hợp đồng*).
- **Chỉ người có quyền xem Hợp đồng / Báo giá mới thấy** — mặc định Giám đốc và Kế toán. Với mọi người khác (quản lý công việc, nhân viên, ê-kíp), cột, ô nhập, bộ lọc và phần *Hợp đồng & báo giá* **không có trong trang** (không chỉ ẩn đi): mã hợp đồng, tên, giá trị không nằm trong dữ liệu gửi xuống trình duyệt, không có trong file xuất, lịch, bảng Kanban, thông báo hay "Hoạt động gần đây". Họ sửa công việc cũng không làm đổi hay mất liên kết.
- Kế toán không sửa được công việc nhưng gắn / gỡ được hợp đồng cho công việc (đó là thông tin của phía hợp đồng).
- **Chi phí và lợi nhuận của hợp đồng** (trang hợp đồng, phần *Chi phí & lợi nhuận*, và Báo cáo lợi nhuận): ngân sách, chi phí đã cam kết, chi phí thực tế = tổng của các công việc gắn với hợp đồng; lợi nhuận = giá trị hợp đồng − chi phí thực tế. Chi phí của công việc không gắn hợp đồng, và chứng từ không gắn công việc nào, là chi phí chung của công ty.
- **Nghiệm thu và hóa đơn** lập theo hợp đồng.

### Chuyển đổi từ bản có module Dự án

Migration `2026_07_05_000000_remove_projects` tự chuyển dữ liệu, **không mất gì**:

| Trước | Sau |
|---|---|
| Dự án | Một công việc (tên, mô tả, người quản lý → người phụ trách, ngày bắt đầu / kết thúc, ngân sách, hợp đồng, báo giá, khách hàng). Dự án đã huỷ / đã xoá → công việc *Đã huỷ* |
| Công việc của dự án | Công việc con của công việc đó, cùng hợp đồng |
| Đề nghị chi, mua hàng, công gắn dự án (không gắn công việc) | Gắn vào công việc đó — chi phí của công việc bằng đúng chi phí của dự án trước đây |
| Nghiệm thu, hóa đơn, tài liệu của dự án | Gắn vào hợp đồng của dự án |
| Ngân sách theo nhóm, thành viên dự án | Bỏ (ngân sách còn một số tổng trên công việc) |
| Quyền Dự án, Ngân sách | Bỏ. Kinh doanh và Quản lý công việc mất quyền Báo giá, Hợp đồng, Nghiệm thu, Hóa đơn, Thanh toán, Công nợ; Kế toán được lập báo giá, hợp đồng, nghiệm thu; Giám đốc toàn quyền với ba mục này và với Công việc. Vai trò tự tạo cũng bị gỡ các quyền trên — cấp lại ở Vai trò & quyền nếu cần |
| Thông báo cũ về dự án, và về báo giá / hợp đồng / hóa đơn của người không còn quyền xem | Xoá |

Thao tác này **không đảo ngược được** (`migrate:rollback` sẽ báo lỗi): hãy sao lưu database trước khi cập nhật — xem mục 12.

## Nghiệp vụ Phase 4: chi phí, mua hàng, chấm công, duyệt

**Mọi số chi phí và doanh thu đều là số trước thuế.**

| Nguồn | Tính là "đã cam kết" khi | Tính là "thực tế" khi |
|---|---|---|
| Đề nghị chi | Đã duyệt | Đã thanh toán |
| Yêu cầu mua hàng | Đã duyệt, chưa lập đơn | — |
| Đơn mua hàng | Đã đặt hàng | Đã nhận hàng |
| Công của nhân sự | Ngay khi được phân công | Ngay khi được phân công (số buổi × đơn giá buổi của người đó) |

**Công việc là tuỳ chọn trên chứng từ.** Đề nghị chi, yêu cầu mua hàng, đơn mua hàng và chấm công có ô **Công việc**; để trống thì đó là *chi phí chung* của công ty. Quy trình duyệt, thanh toán, nhận hàng giữ nguyên. Chi phí chung vẫn nằm trong **chi phí / lợi nhuận theo tháng** của công ty và trong Báo cáo chi phí (nhóm "Chi phí chung"), nhưng không tính vào công việc hay hợp đồng nào. Các danh sách có bộ lọc **Chi phí chung (không gắn công việc)**. Công việc đã huỷ không nhận thêm chứng từ.

### Mọi chi phí của một công việc trên một danh sách

- **Tab "Chi phí" của công việc** liệt kê tất cả chi phí liên quan tới công việc đó: công của nhân sự (ai, mấy buổi × đơn giá), thuê thiết bị / nhà cung cấp (yêu cầu và đơn mua hàng), đi lại và các đề nghị chi khác — gom theo **nhóm chi phí** (Nhân sự, Thiết bị, Nhà cung cấp, Đi lại, Sản xuất…), có tổng từng nhóm. Gồm cả chi phí của các công việc con. Dòng "Tổng" là chi phí đã cam kết, "Trong đó thực tế" là phần đã chi — khớp với hai số ở phần *Tiền & ngân sách*. Lọc theo nhóm, nguồn, tình trạng, ngày; xuất CSV / Excel; bấm một dòng để mở chứng từ gốc.
- Tab **Đề nghị chi** (tên cũ: Chi phí) vẫn liệt kê các đề nghị chi của công việc ở mọi trạng thái, kể cả nháp và đang duyệt.
- **Vận hành → Chi phí theo công việc** là cùng danh sách đó cho mọi công việc, gom theo công việc (khoản không gắn công việc nằm ở "Chi phí chung"); cũng mở được từ nút *Chi phí theo công việc* ở màn hình Chi phí.
- Đây là số liệu nội bộ: chỉ người có quyền "Chi phí nội bộ & giá vốn" mới thấy tab và trang này; người không xem được mọi công việc chỉ thấy chi phí của công việc mình được xem.

### Chấm công theo buổi (công) — tự tính theo phân công

Đơn vị chấm công là **buổi (công)**, không phải giờ. Mỗi người có **đơn giá / buổi** (Hệ thống → Người dùng).

- **Không phải chấm công tay.** Phân công một người vào công việc (người phụ trách hoặc ê-kíp) là người đó tự có một dòng công cho từng ngày của công việc, nên chi phí nhân sự — và chi phí của công việc, của hợp đồng — có ngay.
- **Số buổi mỗi ngày**: việc có giờ = thời lượng ÷ 4 giờ, làm tròn, ít nhất 1 buổi (08:00–12:00 là 1 buổi, 08:00–17:00 là 2 buổi, 07:00–19:00 là 3 buổi); việc cả ngày = 2 buổi. Hai con số này sửa ở **Hệ thống → Cấu hình → Chấm công theo buổi (công)**.
- **Công đi theo công việc**: đổi người, ngày, giờ thì công đổi theo; huỷ hoặc xoá công việc thì công mất; mở lại thì có lại.
- **Không tự tính** cho: công việc chưa có ngày bắt đầu, công việc đã huỷ, và *việc dài ngày* (cả ngày, kéo dài hơn 3 ngày — người làm vẫn nhận việc khác trong lúc đó). Với các việc này, nhập công ở tab **Chấm công** của công việc.
- **Chỉnh tay** ở tab **Chấm công** của công việc (bảng gom theo từng người, có tổng buổi và tổng tiền): sửa số buổi, hoặc nhập **đơn giá riêng** cho việc trả theo giá khác; nhập **0 buổi** nếu người đó không tính công ngày ấy; **Thêm công** cho người ngoài ê-kíp. Dòng đã chỉnh tay (nhãn *Nhập tay*) không bao giờ bị hệ thống ghi đè; nút **Tính lại theo phân công** chỉ sinh lại các dòng *Tự động*.
- **Đơn giá** được chụp lại lúc phân công. Đổi đơn giá của một người chỉ áp dụng cho công tự tính từ hôm nay trở đi; công của những ngày đã qua giữ nguyên.
- **Quyền**: nhân viên thấy bảng công của chính mình (số buổi, không có đơn giá và tiền) và tự chấm thêm được; dòng tự tính chỉ người có quyền *Chấm công — Xem tất cả* sửa. Đơn giá và chi phí chỉ hiện với người có quyền "Chi phí nội bộ & giá vốn".
- Công không gắn công việc (nhập ở mục **Chấm công**) là chi phí chung.
- **Tổng hợp theo tháng** ở mục **Chấm công**: bảng mở ra ở tháng hiện tại và gom theo nhân viên (tổng buổi, tổng tiền từng người). Bộ lọc: **Nhân viên**, **Tháng**, khoảng ngày, **Công việc**, nguồn (tự động / nhập tay); gom được theo nhân viên, công việc hoặc ngày.
- **Xuất bảng công (Excel)**: một file .xlsx theo đúng bộ lọc đang chọn, gồm ba trang — *Theo nhân viên* (từng người, từng công việc: số ngày, số buổi, đơn giá, thành tiền, có dòng cộng), *Theo công việc* (từng công việc, từng người) và *Chi tiết* (từng dòng công). Cần quyền *Chấm công — Xuất dữ liệu* (mặc định Giám đốc, Quản lý công việc, Kế toán); cột tiền chỉ có khi người xuất được xem chi phí nội bộ.

Nâng cấp từ bản chấm công theo giờ: số liệu được quy đổi 4 giờ = 1 buổi (số buổi = số giờ ÷ 4, đơn giá buổi = đơn giá giờ × 4), chi phí đã ghi nhận giữ nguyên; công việc đã phân công từ trước được sinh công (người / ngày đã chấm rồi thì giữ dòng cũ).

- **Rule 6 / Rule 7**: chi phí thực tế ≥ 80% ngân sách → *Cảnh báo*; > ngân sách → *Vượt ngân sách* (báo cho người vừa xem được mọi công việc vừa xem được chi phí nội bộ — mặc định Giám đốc và Quản lý công việc). Tính trên ngân sách của từng công việc. Ngưỡng 80% sửa ở Hệ thống → Cấu hình.
- **Đề nghị chi (Rule 9)**: Nháp → các bước duyệt → Đã duyệt → Đã thanh toán → Hoàn tất. **Rule 2**: chỉ thanh toán được khoản đã duyệt xong.
- **Quy trình duyệt cấu hình được** ở **Hệ thống → Quy trình duyệt** (bảng `approval_rules`: loại chứng từ, khoảng số tiền, vai trò duyệt, thứ tự). Mặc định:

  | Số tiền | Đề nghị chi | Yêu cầu mua hàng |
  |---|---|---|
  | Dưới 5 triệu | Quản lý công việc → Kế toán | Quản lý công việc → Kế toán |
  | 5 – 20 triệu | Quản lý công việc → Kế toán → **Quản lý** | Quản lý công việc → Kế toán |
  | Trên 20 triệu | Quản lý công việc → Kế toán → Giám đốc | Quản lý công việc → Kế toán → Giám đốc |

  > Hệ thống có 7 vai trò và không có vai trò "Quản lý" riêng, nên bước **Quản lý** (5 – 20 triệu) mặc định giao cho vai trò **Director**. Nếu công ty có cấp quản lý riêng: tạo vai trò mới ở Vai trò & quyền rồi đổi vai trò duyệt của bước này.

- Người đề nghị không tự duyệt chứng từ của mình; bước thuộc vai trò của chính người đề nghị được bỏ qua nhưng luôn còn ít nhất một bước do người khác duyệt.
- **Mua hàng**: Yêu cầu mua hàng (duyệt như trên) → Đơn mua hàng: Nháp → Đã đặt hàng → Đã nhận hàng → Hoàn tất. Đơn lập trực tiếp không qua yêu cầu cần người có quyền "Mua hàng — Duyệt" đặt hàng.
- **Tài liệu đính kèm** (hóa đơn, chứng từ, hợp đồng): PDF, JPG, PNG, DOC(X), XLS(X), tối đa 20MB; tải về qua đường dẫn có kiểm tra quyền, không truy cập trực tiếp được.

## Nghiệp vụ Phase 5: nghiệm thu → hóa đơn → thanh toán → công nợ

- **Nghiệm thu** (lập theo hợp đồng, mặc định do Kế toán lập, Giám đốc duyệt): Nháp → Chờ duyệt → Đã nghiệm thu / Bị từ chối. Biên bản đã nghiệm thu là căn cứ lập hóa đơn.
- **Hóa đơn**: Nháp → Đã phát hành → Thu một phần → Đã thu đủ; quá ngày đến hạn mà chưa thu đủ → Quá hạn. Sau khi phát hành, trạng thái luôn được **suy ra** từ số đã thu và ngày đến hạn — không ai tự đặt "Đã thu đủ" được (**Rule 3**).
- **Rule 4**: hóa đơn đã phát hành không xoá được (chỉ huỷ, có lý do, khi chưa thu đồng nào).
- **Rule 5**: thanh toán đã ghi nhận không sửa, không xoá — kể cả Super Admin. Ghi nhầm thì phải xử lý bằng nghiệp vụ, không sửa lịch sử.
- **Công nợ**: các hóa đơn còn phải thu, chia nhóm tuổi nợ: chưa tới hạn, quá hạn 1–30, 31–60, 61–90, trên 90 ngày.

## Nghiệp vụ Phase 6: dashboard, báo cáo, thông báo, audit log, cấu hình

**Công thức tài chính toàn công ty** (`app/Services/Reports/FinancialReport.php`), đều trước thuế:

| | |
|---|---|
| Doanh thu tháng | Σ giá trị trước thuế của hóa đơn **đã phát hành** có ngày hóa đơn trong tháng (không tính nháp, đã huỷ) |
| Chi phí tháng | Σ chi phí thực tế phát sinh trong tháng: đề nghị chi đã thanh toán (theo ngày chi), đơn mua hàng đã nhận (theo ngày nhận), công của nhân sự (theo ngày làm) |
| Lợi nhuận | Doanh thu − Chi phí |
| Biên lợi nhuận | Lợi nhuận ÷ Doanh thu × 100 |

Lợi nhuận **từng hợp đồng**: Giá trị hợp đồng − Chi phí thực tế của các công việc gắn với hợp đồng (không gồm chi phí chung; phần này chỉ trừ vào lợi nhuận theo tháng của công ty). Đánh giá biên lợi nhuận: trên 30% *Tốt*, 20–30% *Bình thường*, dưới 20% *Cảnh báo*, dưới 10% *Nguy hiểm* (ngưỡng sửa ở Cấu hình).

**Doanh thu theo dịch vụ**: hóa đơn không ghi từng dịch vụ, nên doanh thu của mỗi hóa đơn được chia theo tỷ trọng các hạng mục trong báo giá của hợp đồng. Hợp đồng không lập từ báo giá được xếp vào "Chưa phân loại".

**Dashboard** hiển thị theo quyền của người đăng nhập:

| Khối | Ai thấy |
|---|---|
| Doanh thu tháng, doanh thu theo tháng / theo dịch vụ | Có quyền xem Hóa đơn hoặc Báo cáo tài chính |
| Chi phí, lợi nhuận, biên lợi nhuận (thẻ và biểu đồ theo tháng) | Có quyền Báo cáo tài chính |
| Công nợ, tuổi nợ | Có quyền xem Công nợ |
| Lợi nhuận theo hợp đồng | Có quyền xem Hợp đồng và "Chi phí nội bộ & giá vốn" |
| Công việc theo trạng thái | Có quyền xem Công việc |
| Công việc cần chú ý | Có quyền xem Công việc (không có quyền chi phí nội bộ thì chỉ thấy công việc quá hạn) |
| Cần xử lý | Mọi người — mỗi dòng đếm đúng số chứng từ người đó được xem và bấm vào mở danh sách đã lọc sẵn |
| Hoạt động gần đây | Mọi người thấy thao tác của mình (trừ thao tác trên báo giá, hợp đồng, nghiệm thu, hóa đơn, thanh toán nếu nay không còn quyền xem các mục đó); có quyền Audit Log thì thấy của tất cả |

Người không xem được số liệu tài chính (nhân viên, quản lý công việc…) thấy thẻ về công việc và đề nghị chi của chính mình.

**Báo cáo** (nhóm Tài chính, quyền "Báo cáo tài chính"): Báo cáo lợi nhuận (theo hợp đồng), Báo cáo doanh thu (lọc theo ngày, khách hàng, hợp đồng, dịch vụ), Báo cáo chi phí (lọc theo ngày, công việc, hợp đồng, nhóm chi phí, nhà cung cấp, nguồn). Báo cáo công nợ là màn hình **Công nợ**; ngân sách – thực tế của từng công việc xem ở mục **Công việc** (tab *Vượt ngân sách*, cột *Chi phí / ngân sách*).

**Xuất dữ liệu** (CSV và Excel): Khách hàng, Báo giá, Công việc, Chi phí, Hóa đơn, Thanh toán, Công nợ và ba báo cáo. File chứa đúng những dòng đang hiển thị theo tab, bộ lọc và ô tìm kiếm (mọi trang, không chỉ trang đang xem). Cần quyền "Xuất dữ liệu" của module; cột giá vốn / chi phí chỉ có khi người xuất được xem chi phí nội bộ, cột hợp đồng / báo giá chỉ có khi họ được xem hợp đồng / báo giá. Mỗi lần xuất được ghi vào Audit Log.

**Thông báo** hiện ở chuông trên thanh trên cùng và ở **Hệ thống → Thông báo** (giữ cả thông báo đã đọc): báo giá / đề nghị chi / yêu cầu mua hàng cần duyệt và kết quả duyệt, công việc vượt ngân sách, hợp đồng sắp hết hạn, hóa đơn sắp tới hạn, hóa đơn quá hạn (ba loại sau chỉ gửi cho người còn quyền xem hợp đồng / hóa đơn).

**Audit Log** (Hệ thống → Audit Log, quyền `audit_logs.view`): ghi ai, làm gì, trên bản ghi nào, giá trị trước / sau, địa chỉ IP, trình duyệt, thời điểm — cho các thao tác tạo, sửa, đổi trạng thái (duyệt, thanh toán, phát hành…), xoá, khôi phục, đăng nhập, xuất dữ liệu, đổi cấu hình, đổi phân quyền. Nhật ký chỉ để xem: không sửa, không xoá được, kể cả Super Admin. Mật khẩu không bao giờ được ghi. Các trang Khách hàng, Báo giá, Hợp đồng, Chi phí, Hóa đơn có tab **Hoạt động** cho riêng bản ghi đó.

**Cấu hình** (Hệ thống → Cấu hình; xem: `settings.view`, sửa: `settings.update` — mặc định chỉ Super Admin): thông tin công ty và các ngưỡng nghiệp vụ. Thay đổi có hiệu lực ngay và được ghi vào Audit Log.

**Trang lỗi** (403, 404, 419, 429, 500, 503) bằng tiếng Việt, không lộ chi tiết kỹ thuật khi `APP_DEBUG=false`; chi tiết lỗi nằm trong `storage/logs/laravel.log`.

## Lịch làm việc

Lịch chung của công ty tại **Tổng quan → Lịch làm việc**: ai làm việc gì, lúc nào, ở đâu, cho khách hàng nào.

**Lịch tự lấy từ Công việc — trang Lịch chỉ để xem.** Không có nút thêm, sửa hay xoá lịch công việc ở trang Lịch; muốn thêm hoặc đổi lịch thì tạo / sửa **công việc** (mục Công việc, bảng Kanban, tab Công việc của hợp đồng):

- Công việc **có ngày bắt đầu** tự hiện trên lịch, từ ngày bắt đầu tới hạn hoàn thành (không có hạn: một ngày). Công việc chưa có ngày bắt đầu, hoặc kéo dài quá 2 tháng, không lên lịch.
- Phần **Lịch làm việc** trong form công việc quyết định lịch trông thế nào: loại lịch, cả ngày hay từ giờ – đến giờ, ê-kíp (người phụ trách luôn có trong ê-kíp, thêm được cộng tác viên ngoài), địa điểm, thiết bị, khách hàng (việc gắn hợp đồng / báo giá thì theo khách hàng của hợp đồng / báo giá), "lịch dự kiến (chưa chốt)".
- Sửa công việc → lịch đổi theo ngay. Công việc *Hoàn thành* → lịch *Đã xong*; *Đã huỷ* → *Đã huỷ*; còn lại là *Đã chốt* (hoặc *Dự kiến*). Bỏ ngày bắt đầu hoặc xoá công việc → lịch mất (lịch đã được "ghi giờ công" ở bản trước thì giữ lại để tra cứu).
- Bấm một lịch để xem chi tiết; nút **Mở công việc** đưa tới trang công việc để sửa lịch, xem chi phí và ngân sách.
- **Việc dài ngày** — công việc cả ngày kéo dài hơn 3 ngày, ví dụ hậu kỳ hai tuần — hiện gọn thành một dải ở đầu mỗi ngày (quá 3 việc thì gom lại, bấm để mở) và **không tính là trùng lịch**, vì người làm việc dài ngày vẫn nhận được buổi quay khác. Chuyến đi quay cả ngày tới 3 ngày, hoặc việc có nhập giờ, vẫn giữ chỗ như thường.
- Lịch nghỉ là ngoại lệ duy nhất nhập ở trang Lịch (nút **Đăng ký nghỉ**).

**Dữ liệu nhập bằng mã** (seeder, tích hợp gọi `ScheduleManager::save()`): lịch tạo theo cách này tự có công việc của nó và hai bên vẫn luôn khớp nhau; một lịch cũng có thể gắn thêm vào công việc có sẵn (`task_id`). Dữ liệu của bản trước được nối lại khi chạy migration: lịch cũ có công việc, công việc cũ có ngày bắt đầu có lịch.

**Bốn cách xem:** *Tuần* (mỗi ngày chia Sáng / Chiều – tối, trước và sau 12:00), *Tháng*, *Theo người* (mỗi người một hàng — thấy ngay ai còn trống), *Lịch của tôi* (30 ngày tới của người đang đăng nhập). Màn hình hẹp tự xếp dọc từng ngày. Dashboard có khối "Lịch của tôi" cho 7 ngày tới, trang công việc có tab **Lịch**.

**Quyền:**

| Việc | Ai làm được |
|---|---|
| Xem toàn bộ lịch | Mọi người dùng (module này không có quyền "Xem") |
| Thêm / đổi lịch công việc | Người được tạo công việc (`tasks.create`) — nhập ở form công việc. Người chỉ được cập nhật công việc của mình đổi được trạng thái, tiến độ; lịch đi theo |
| Đăng ký nghỉ, sửa / xoá lịch nghỉ của chính mình | Mọi người dùng (nút **Đăng ký nghỉ**) |
| Đăng ký nghỉ hộ người khác, sửa / xoá lịch nghỉ của người khác | Quyền `schedules.create` / `update` / `delete` — mặc định Giám đốc và Quản lý công việc |
| Sửa danh mục loại lịch (Hệ thống → Loại lịch) | Quyền `schedule_types.*` — mặc định Giám đốc và Quản lý công việc |
| Bật / đổi / tắt đường dẫn chỉ-xem | Quyền `schedules.update` |

**Trùng lịch:** khi một người có hai lịch chồng thời gian, hoặc bị xếp việc vào lúc đang nghỉ, form công việc hiện cảnh báo ngay lúc nhập, thẻ lịch có viền đỏ kèm tên người bị trùng. Đây là **cảnh báo, không chặn** — đôi khi người xếp lịch cố ý. Lịch đã huỷ và việc dài ngày không tính; hai việc nối tiếp nhau (việc này kết thúc đúng lúc việc kia bắt đầu) không tính là trùng.

**Thông báo:** người phụ trách và ê-kíp của công việc được báo khi công việc lên lịch, lịch đổi giờ / đổi địa điểm, bị rút khỏi ê-kíp, công việc bị huỷ, và được nhắc về lịch ngày mai (lệnh `reminders:send`, chạy 07:30 và 17:00). Người tự thao tác không nhận thông báo về chính thao tác đó; lịch đã qua (nhập bù) không tạo thông báo. Khi có người đăng ký nghỉ, những người có quyền của module Lịch làm việc được báo.

**Trang công việc** (bấm vào một dòng ở mục Công việc): Ngân sách · Đã cam kết · Chi phí thực tế · Còn lại, cảnh báo *Vượt ngân sách*, dòng *Trong đó: nhân sự* (tổng buổi, số người), và các tab Chi phí (đề nghị chi), Mua hàng, Chấm công (công của từng người), Lịch. Đề nghị chi, yêu cầu / đơn mua hàng có ô **Công việc**; chi phí của công việc tính theo bảng "cam kết / thực tế" ở Phase 4, số trước thuế, gồm cả chứng từ của các công việc con. Việc đã xong vẫn nhận thêm chi phí; việc đã huỷ thì không. Trang công việc cũng cho biết công việc đang nằm ở đâu trên lịch (thời gian, loại lịch, ê-kíp, địa điểm). Người trong ê-kíp thấy công việc và gắn được đề nghị chi của mình vào đó, nhưng không thấy ngân sách và chi phí.

**Công của ê-kíp:** không còn nút "Ghi giờ công" trên lịch — công của từng người tự tính theo phân công của công việc (xem *Chấm công theo buổi (công)* ở Phase 4).

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
- Phase 2: sinh mã chứng từ, tạo khách hàng, liên hệ chính, danh mục dịch vụ, doanh thu kỳ vọng của cơ hội, tính toán báo giá, quy trình duyệt báo giá (kể cả quy tắc Giám đốc duyệt), khoá hạng mục sau khi gửi duyệt, hết hạn báo giá, ẩn giá vốn với người không có quyền chi phí nội bộ.

- Phase 3: hợp đồng, công việc và bảng Kanban, tài liệu đính kèm.
- Công việc ↔ hợp đồng / báo giá: gắn – gỡ, công việc con theo công việc cha, chi phí và lợi nhuận của hợp đồng, tab Công việc của hợp đồng, và **quyền xem**: người không có quyền Hợp đồng / Báo giá không thấy chúng ở bất kỳ trang, form, file xuất, thông báo hay nhật ký nào, và không làm đổi được liên kết.
- Danh sách mọi chi phí của công việc (khớp từng đồng với đã cam kết / thực tế, gồm việc con, quyền xem, trang Chi phí theo công việc), lọc công theo nhân viên – tháng và file Excel bảng công ba trang.
- Chấm công theo buổi và công tự tính theo phân công: số buổi theo thời lượng, công đổi theo mọi thay đổi của công việc, cộng dồn lên công việc cha và hợp đồng, chỉnh tay không bị ghi đè, đổi đơn giá áp dụng từ hôm nay, quyền xem / sửa, sinh công cho dữ liệu cũ.
- Phase 4: bộ máy duyệt theo số tiền, quy trình đề nghị chi (Rule 2, Rule 9), mua hàng, chấm công, chi phí cam kết / thực tế của công việc (cộng dồn từ công việc con) và cảnh báo ngân sách (Rule 6, Rule 7).
- Phase 5: nghiệm thu, hóa đơn (Rule 3, Rule 4), thanh toán (Rule 5, không thu quá số còn lại), tuổi nợ.
- Phase 6: doanh thu – chi phí – lợi nhuận theo tháng, tổng dòng chi phí khớp chi phí thực tế của công việc và hợp đồng, chia doanh thu theo dịch vụ, dashboard theo từng vai trò, "Cần xử lý" khớp danh sách, báo cáo và bộ lọc, xuất CSV / Excel và quyền xuất, nhắc việc không trùng, màn hình thông báo, audit log (giá trị trước / sau, người thực hiện, che mật khẩu, không sửa xoá được), cấu hình.

- Công việc → lịch: công việc có ngày bắt đầu tự lên lịch, lịch đổi theo mọi thay đổi của công việc, giờ / nhiều ngày / quá 2 tháng, thông báo cho ê-kíp, trang Lịch không thêm – sửa – xoá được công việc, việc dài ngày không tính trùng lịch, nối dữ liệu cũ.
- Lịch → công việc (dữ liệu nhập bằng mã) và tiền của công việc: lịch tự có công việc và ngân sách, gắn vào công việc có sẵn, sửa / huỷ / xoá lịch đồng bộ sang công việc, chi phí cam kết / thực tế của công việc từ đề nghị chi – mua hàng – công của nhân sự, quyền xem của ê-kíp, trang công việc, tạo bù công việc cho lịch cũ.
- Chi phí chung: đề nghị chi / mua hàng / chấm công không gắn công việc đi đủ quy trình, tính vào chi phí tháng mà không vào công việc hay hợp đồng nào, bộ lọc "Chi phí chung", nhóm "Chi phí chung" trong báo cáo chi phí.
- Lịch làm việc: nhãn thời gian và lịch cả ngày / nhiều ngày, phát hiện trùng lịch và trùng ngày nghỉ, quyền xếp lịch và tự quản lịch nghỉ, thông báo cho ê-kíp, sắp lịch theo tuần / tháng / người, sửa – xoá lịch nghỉ từ trang lịch, nhắc lịch ngày mai, đường dẫn chỉ-xem (bật / đổi / tắt, 404 với mã sai), nguồn iCalendar.

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

Nếu cập nhật bằng cách **chép đè** (rsync, giải nén) thay vì `git pull`: chép đè không xoá file của những module đã bỏ. Container `app` tự dọn các file đó mỗi lần khởi động (`scripts/remove-retired-files.sh` — chỉ xoá đúng danh sách file mã nguồn ghi trong script); cũng có thể chạy tay `./scripts/remove-retired-files.sh` trước khi restart.

Bản cập nhật có migration **không đảo ngược được** (ví dụ bản bỏ module Dự án) thì sao lưu trước: `./scripts/backup-db.sh`.

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
