<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Chọn Ca Làm Việc</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .shift-card { cursor: pointer; transition: 0.3s; border: 2px solid transparent; }
        .shift-card:hover, .shift-radio:checked + .shift-card { border-color: #4e73df; background-color: #f8f9fc; transform: translateY(-5px); }
        .shift-radio { display: none; }
    </style>
</head>
<body>
    <div class="card shadow-lg p-4 text-center" style="width: 400px;">
        <h4 class="mb-4 text-primary fw-bold">Chào <?php echo $_SESSION['temp_user_name']; ?>! 👋</h4>
        <p class="text-muted mb-4">Vui lòng chọn ca làm việc hôm nay:</p>
        
        <form action="<?php echo URLROOT; ?>/auth/select_shift" method="post">
            <div class="row g-3 mb-4">
                <div class="col-6">
                    <input type="radio" name="shift_hours" id="shift5" value="5" class="shift-radio" required>
                    <label for="shift5" class="card p-3 shift-card h-100 shadow-sm">
                        <h2 class="fw-bold text-info">5h</h2>
                        <small class="text-muted">Ca 5 Tiếng</small>
                    </label>
                </div>
                <div class="col-6">
                    <input type="radio" name="shift_hours" id="shift6" value="6" class="shift-radio" required>
                    <label for="shift6" class="card p-3 shift-card h-100 shadow-sm">
                        <h2 class="fw-bold text-success">6h</h2>
                        <small class="text-muted">Ca 6 Tiếng</small>
                    </label>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">BẮT ĐẦU LÀM VIỆC</button>
        </form>
    </div>
</body>
</html>