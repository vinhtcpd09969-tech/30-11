<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Cảnh báo về sớm</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
    <div class="card shadow border-0" style="width: 450px;">
        <div class="card-header bg-warning text-dark fw-bold text-center py-3">
            <i class="fas fa-exclamation-triangle me-2"></i> CẢNH BÁO VỀ SỚM
        </div>
        <div class="card-body p-4">
            <p class="text-center mb-4">
                Bạn đang đăng xuất sớm hơn dự kiến.<br>
                Thời gian còn lại: <strong class="text-danger fs-5"><?php echo $data['remaining_minutes']; ?> phút</strong>.<br>
                Giờ kết thúc ca chuẩn: <strong><?php echo $data['expected_time']; ?></strong>
            </p>

            <form action="<?php echo URLROOT; ?>/auth/logout" method="post">
                <input type="hidden" name="confirm_early" value="1">
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Lý do về sớm <span class="text-danger">*</span>:</label>
                    <textarea name="early_reason" class="form-control" rows="3" required placeholder="Vui lòng nhập lý do..."></textarea>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-danger fw-bold">Xác nhận & Đăng xuất</button>
                    <a href="javascript:history.back()" class="btn btn-secondary">Quay lại làm việc</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>