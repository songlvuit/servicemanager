<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thao tác vi phạm quy tắc nghiệp vụ (sai trạng thái, thiếu dữ liệu, vượt hạn
 * mức...). Thông điệp bằng tiếng Việt, hiển thị được cho người dùng.
 */
class WorkflowException extends DomainException
{
    //
}
