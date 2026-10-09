<?php

namespace Database\Seeders;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\OpportunityStatus;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\Quotation;
use App\Models\Service;
use App\Models\User;
use App\Services\Quotations\QuotationWorkflow;
use Illuminate\Database\Seeder;

/**
 * Dữ liệu mẫu Phase 2 cho môi trường phát triển: dịch vụ, khách hàng, liên hệ,
 * cơ hội và 10 báo giá ở đủ các trạng thái. Báo giá được đưa qua đúng quy
 * trình (QuotationWorkflow) nên số liệu và trạng thái luôn nhất quán.
 *
 * Chỉ chạy một lần: bỏ qua nếu đã có khách hàng, và không chạy trên production.
 */
class DemoDataSeeder extends Seeder
{
    public function run(QuotationWorkflow $workflow): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Bỏ qua DemoDataSeeder: không tạo dữ liệu mẫu trên production.');

            return;
        }

        if (Customer::query()->withTrashed()->exists()) {
            $this->command?->info('Bỏ qua DemoDataSeeder: đã có dữ liệu khách hàng.');

            return;
        }

        $sales = User::query()->where('email', 'sales@example.com')->first();
        $director = User::query()->where('email', 'director@example.com')->first();

        if ($sales === null || $director === null) {
            $this->command?->warn('Bỏ qua DemoDataSeeder: chưa có tài khoản mẫu (chạy DevUserSeeder trước).');

            return;
        }

        $services = $this->seedServices();
        $customers = $this->seedCustomers($sales);
        $opportunities = $this->seedOpportunities($customers, $sales);

        $this->seedQuotations($workflow, $customers, $opportunities, $services, $sales, $director);
    }

    /**
     * @return array<string, Service>
     */
    private function seedServices(): array
    {
        // [khoá, tên, nhóm, đơn vị, đơn giá bán, giá vốn]
        $rows = [
            ['video', 'Quay phim', 'Sản xuất nội dung', 'ngày', 15000000, 9000000],
            ['photo', 'Chụp ảnh', 'Sản xuất nội dung', 'buổi', 8000000, 4500000],
            ['edit', 'Dựng phim', 'Sản xuất nội dung', 'sản phẩm', 12000000, 7000000],
            ['design', 'Thiết kế', 'Thiết kế', 'gói', 10000000, 5500000],
            ['dev', 'Lập trình', 'Công nghệ', 'ngày', 6000000, 4000000],
            ['consult', 'Tư vấn', 'Tư vấn', 'giờ', 2000000, 800000],
            ['event', 'Tổ chức sự kiện', 'Sự kiện', 'gói', 120000000, 95000000],
            ['live', 'Livestream', 'Sự kiện', 'buổi', 25000000, 16000000],
            ['gear', 'Thuê thiết bị', 'Thiết bị', 'ngày', 5000000, 3500000],
            ['crew', 'Nhân sự', 'Nhân sự', 'người', 1500000, 1000000],
            ['content', 'Sản xuất nội dung', 'Sản xuất nội dung', 'tháng', 30000000, 18000000],
        ];

        $services = [];

        foreach ($rows as [$key, $name, $category, $unit, $price, $cost]) {
            $services[$key] = Service::create([
                'name' => $name,
                'category' => $category,
                'default_unit' => $unit,
                'default_price' => $price,
                'default_cost' => $cost,
            ]);
        }

        return $services;
    }

    /**
     * @return list<Customer>
     */
    private function seedCustomers(User $sales): array
    {
        $rows = [
            [
                'name' => 'Công ty TNHH Thực phẩm An Phát', 'tax_code' => '0312345678', 'industry' => 'Thực phẩm & đồ uống',
                'address' => '25 Nguyễn Thị Minh Khai, Quận 1', 'province' => 'TP. Hồ Chí Minh', 'website' => 'https://anphat.example.com',
                'email' => 'lienhe@anphat.example.com', 'phone' => '02838221234', 'status' => CustomerStatus::Active,
                'contacts' => [['Nguyễn Thị Hoa', 'Trưởng phòng Marketing', 'Marketing'], ['Trần Văn Bình', 'Kế toán trưởng', 'Kế toán']],
            ],
            [
                'name' => 'Công ty Cổ phần Giáo dục Minh Trí', 'tax_code' => '0109876543', 'industry' => 'Giáo dục',
                'address' => '88 Láng Hạ, Đống Đa', 'province' => 'Hà Nội', 'website' => 'https://minhtri.example.com',
                'email' => 'info@minhtri.example.com', 'phone' => '02437761234', 'status' => CustomerStatus::Active,
                'contacts' => [['Lê Minh Tuấn', 'Giám đốc', 'Ban giám đốc'], ['Phạm Thu Hà', 'Chuyên viên truyền thông', 'Truyền thông']],
            ],
            [
                'name' => 'Công ty TNHH Bất động sản Sông Hàn', 'tax_code' => '0401122334', 'industry' => 'Bất động sản',
                'address' => '12 Bạch Đằng, Hải Châu', 'province' => 'Đà Nẵng', 'website' => null,
                'email' => 'contact@songhan.example.com', 'phone' => '02363881234', 'status' => CustomerStatus::Lead,
                'contacts' => [['Võ Quốc Khánh', 'Phó giám đốc kinh doanh', 'Kinh doanh'], ['Đặng Ngọc Lan', 'Trợ lý', 'Kinh doanh']],
            ],
            [
                'name' => 'Công ty Cổ phần Thời trang Lụa Việt', 'tax_code' => '0305566778', 'industry' => 'Bán lẻ',
                'address' => '140 Lê Lợi, Quận 1', 'province' => 'TP. Hồ Chí Minh', 'website' => 'https://luaviet.example.com',
                'email' => 'hello@luaviet.example.com', 'phone' => '02839151234', 'status' => CustomerStatus::Lead,
                'contacts' => [['Hoàng Mai Anh', 'Giám đốc thương hiệu', 'Thương hiệu'], ['Bùi Đức Long', 'Trưởng nhóm nội dung', 'Marketing']],
            ],
            [
                'name' => 'Trung tâm Xúc tiến Du lịch Miền Trung', 'tax_code' => null, 'industry' => 'Du lịch',
                'address' => '5 Trần Phú', 'province' => 'Khánh Hòa', 'website' => null,
                'email' => 'vanphong@dulichmientrung.example.com', 'phone' => '02583521234', 'status' => CustomerStatus::Inactive,
                'company_type' => CustomerType::Government,
                'contacts' => [['Ngô Thanh Sơn', 'Trưởng phòng', 'Xúc tiến'], ['Đỗ Thị Yến', 'Chuyên viên', 'Hành chính']],
            ],
        ];

        $customers = [];

        foreach ($rows as $row) {
            $contacts = $row['contacts'];
            unset($row['contacts']);

            $customer = new Customer($row);
            $customer->created_by = $sales->getKey();
            $customer->save();

            foreach ($contacts as $index => [$name, $position, $department]) {
                $customer->contacts()->create([
                    'name' => $name,
                    'position' => $position,
                    'department' => $department,
                    'email' => 'lienhe'.($index + 1).'.kh'.$customer->getKey().'@example.com',
                    'phone' => '09'.str_pad((string) ($customer->getKey() * 1000 + $index), 8, '0', STR_PAD_LEFT),
                    'is_primary' => $index === 0,
                ]);
            }

            $customers[] = $customer;
        }

        return $customers;
    }

    /**
     * @param  list<Customer>  $customers
     * @return list<Opportunity>
     */
    private function seedOpportunities(array $customers, User $sales): array
    {
        // [chỉ số khách hàng, tên, giá trị, xác suất, số ngày tới hạn chốt, giai đoạn]
        $rows = [
            [0, 'TVC ra mắt sản phẩm mới', 250000000, 70, 20, OpportunityStatus::Negotiation],
            [1, 'Bộ video giới thiệu khoá học', 120000000, 50, 35, OpportunityStatus::Contacted],
            [2, 'Sự kiện mở bán dự án', 400000000, 40, 45, OpportunityStatus::New],
            [3, 'Bộ ảnh lookbook Thu Đông', 60000000, 60, 15, OpportunityStatus::Contacted],
            [0, 'Gói nội dung mạng xã hội 6 tháng', 180000000, 30, 60, OpportunityStatus::New],
            [4, 'Phim quảng bá du lịch', 300000000, 10, 90, OpportunityStatus::Lost],
        ];

        $opportunities = [];

        foreach ($rows as [$customerIndex, $title, $value, $probability, $days, $status]) {
            $opportunities[] = Opportunity::create([
                'customer_id' => $customers[$customerIndex]->getKey(),
                'title' => $title,
                'estimated_value' => $value,
                'probability' => $probability,
                'expected_close_date' => today()->addDays($days),
                'sales_owner_id' => $sales->getKey(),
                'status' => $status,
            ]);
        }

        return $opportunities;
    }

    /**
     * @param  list<Customer>  $customers
     * @param  list<Opportunity>  $opportunities
     * @param  array<string, Service>  $services
     */
    private function seedQuotations(
        QuotationWorkflow $workflow,
        array $customers,
        array $opportunities,
        array $services,
        User $sales,
        User $director,
    ): void {
        /*
         * Mỗi dòng: [khách hàng, cơ hội|null, tiêu đề, số ngày trước, hạng mục, trạng thái cuối]
         * Hạng mục: [dịch vụ, số lượng, chiết khấu]
         */
        $rows = [
            [0, 0, 'TVC 30 giây ra mắt sản phẩm', 12, [['video', 2, 0], ['edit', 1, 0], ['gear', 2, 0]], 'accepted'],
            [1, 1, 'Bộ 5 video giới thiệu khoá học', 9, [['video', 3, 0], ['edit', 5, 5000000]], 'sent'],
            [3, 3, 'Chụp lookbook Thu Đông', 6, [['photo', 2, 0], ['design', 1, 0]], 'approved'],
            [2, 2, 'Sự kiện mở bán dự án Sông Hàn', 4, [['event', 1, 0], ['live', 1, 0], ['crew', 10, 0]], 'pending'],
            // Chiết khấu 25% → bắt buộc Giám đốc duyệt.
            [0, 4, 'Gói nội dung mạng xã hội 6 tháng', 3, [['content', 6, 45000000]], 'pending'],
            // Biên lợi nhuận thấp (sự kiện có giá vốn cao + chiết khấu).
            [2, null, 'Lễ ký kết hợp tác chiến lược', 2, [['event', 1, 10000000], ['gear', 1, 0]], 'draft'],
            [1, null, 'Tư vấn chiến lược nội dung', 1, [['consult', 10, 0]], 'draft'],
            [3, null, 'Thiết kế bộ nhận diện thương hiệu', 15, [['design', 2, 0], ['consult', 4, 0]], 'rejected'],
            [4, 5, 'Phim quảng bá du lịch 3 phút', 50, [['video', 4, 0], ['edit', 1, 0], ['gear', 4, 0]], 'expired'],
            [1, null, 'Livestream lễ khai giảng', 8, [['live', 1, 0], ['crew', 4, 0]], 'cancelled'],
        ];

        foreach ($rows as [$customerIndex, $opportunityIndex, $title, $daysAgo, $items, $finalStatus]) {
            $date = today()->subDays($daysAgo);

            $quotation = new Quotation([
                'customer_id' => $customers[$customerIndex]->getKey(),
                'opportunity_id' => $opportunityIndex === null ? null : $opportunities[$opportunityIndex]->getKey(),
                'title' => $title,
                'quotation_date' => $date,
                'valid_until' => $date->copy()->addDays((int) config('quotation.validity_days')),
            ]);
            $quotation->created_by = $sales->getKey();
            $quotation->save();

            foreach ($items as $order => [$serviceKey, $quantity, $discount]) {
                $service = $services[$serviceKey];

                $quotation->items()->create([
                    'service_id' => $service->getKey(),
                    'description' => $service->name,
                    'quantity' => $quantity,
                    'unit' => $service->default_unit,
                    'unit_price' => $service->default_price,
                    'discount' => $discount,
                    'tax_rate' => config('quotation.default_tax_rate'),
                    'estimated_cost' => $service->default_cost,
                    'sort_order' => $order + 1,
                ]);
            }

            $workflow->recalculate($quotation);

            $this->advance($workflow, $quotation, $finalStatus, $sales, $director);
        }

        // Báo giá "expired" có ngày hiệu lực đã qua: chạy đúng tác vụ hằng ngày.
        $workflow->expireOverdue();
    }

    private function advance(QuotationWorkflow $workflow, Quotation $quotation, string $finalStatus, User $sales, User $director): void
    {
        if ($finalStatus === 'draft') {
            return;
        }

        if ($finalStatus === 'cancelled') {
            $workflow->cancel($quotation, $sales, 'Khách hàng thay đổi kế hoạch.');

            return;
        }

        $workflow->submit($quotation, $sales);

        if ($finalStatus === 'pending') {
            return;
        }

        if ($finalStatus === 'rejected') {
            $workflow->reject($quotation, $director, 'Đơn giá tư vấn thấp hơn bảng giá hiện hành, cần điều chỉnh.');

            return;
        }

        $workflow->approve($quotation, $director, 'Đồng ý.');

        if ($finalStatus === 'approved') {
            return;
        }

        // sent, accepted, expired đều đã gửi khách.
        $workflow->markSent($quotation, $sales);

        if ($finalStatus === 'accepted') {
            $workflow->markAccepted($quotation, $sales);
        }
    }
}
