<?php
/*
 * AUTH CONTROLLER
 * Chức năng: Xử lý xác thực người dùng, Chọn ca làm việc và Kiểm soát về sớm
 * Kết nối Model: app/models/UserModel.php
 * Kết nối View: app/views/auth/login.php, app/views/auth/select_shift.php, app/views/auth/logout_confirm.php
 */
class AuthController extends Controller {

    public function __construct() {
        // Load Model User để kiểm tra DB
        $this->userModel = $this->model('UserModel');
    }

    /**
     * Chức năng: Xử lý Đăng nhập (Bước 1)
     * - Kiểm tra user/pass.
     * - Nếu đúng: KHÔNG tạo session chính thức ngay mà tạo Session TẠM.
     * - Chuyển hướng sang trang Chọn Ca Làm Việc.
     */
    public function login() {
        $data = [
            'username' => '',
            'password' => '',
            'error' => ''
        ];

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $_POST = filter_input_array(INPUT_POST, FILTER_SANITIZE_STRING);
            
            $data['username'] = trim($_POST['username']);
            $data['password'] = trim($_POST['password']);

            if (empty($data['username']) || empty($data['password'])) {
                $data['error'] = 'Vui lòng nhập đầy đủ thông tin.';
            } else {
                // Gọi hàm login từ Model
                $loggedInUser = $this->userModel->login($data['username'], $data['password']);

                if ($loggedInUser) {
                    // [THAY ĐỔI] Chỉ lưu Session tạm để xác nhận danh tính
                    $_SESSION['temp_user_id'] = $loggedInUser->user_id;
                    $_SESSION['temp_user_role'] = $loggedInUser->role_id;
                    $_SESSION['temp_user_name'] = $loggedInUser->full_name;

                    // Chuyển hướng sang trang chọn ca (Bước 2)
                    header('location: ' . URLROOT . '/auth/select_shift');
                    exit;
                } else {
                    $data['error'] = 'Sai tài khoản hoặc mật khẩu, hoặc tài khoản bị khóa.';
                }
            }
        }

        $this->view('auth/login', $data);
    }

    /**
     * [MỚI] Chức năng: Chọn Ca Làm Việc (Bước 2)
     * - Người dùng chọn 5 tiếng hoặc 6 tiếng.
     * - Hệ thống ghi nhận bắt đầu ca (Start Shift) và tạo Session chính thức.
     */
    public function select_shift() {
        // Bảo mật: Phải đăng nhập bước 1 xong mới được vào đây
        if (!isset($_SESSION['temp_user_id'])) {
            header('location: ' . URLROOT . '/auth/login');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $hours = (int)$_POST['shift_hours']; // 5 hoặc 6
            $userId = $_SESSION['temp_user_id'];

            // 1. Gọi Model để bắt đầu ca làm việc (Lưu giờ dự kiến)
            $this->userModel->startShift($userId, $hours);

            // 2. Chuyển Session tạm thành Session chính thức (Đăng nhập thành công)
            $_SESSION['user_id'] = $userId;
            $_SESSION['role_id'] = $_SESSION['temp_user_role'];
            $_SESSION['full_name'] = $_SESSION['temp_user_name'];
            $_SESSION['username'] = ''; // Có thể lấy lại nếu cần

            // Xóa session tạm
            unset($_SESSION['temp_user_id']);
            unset($_SESSION['temp_user_role']);
            unset($_SESSION['temp_user_name']);

            // 3. Điều hướng vào trang chính
            if ($_SESSION['role_id'] == 1) {
                header('location: ' . URLROOT . '/dashboard');
            } else {
                header('location: ' . URLROOT . '/pos');
            }
        } else {
            // Hiển thị View chọn ca
            $this->view('auth/select_shift');
        }
    }

    /**
     * [SỬA ĐỔI] Chức năng: Đăng xuất & Kiểm tra về sớm
     * - Kiểm tra thời gian làm thực tế so với dự kiến.
     * - Nếu về sớm: Hiện cảnh báo và form nhập lý do.
     * - Nếu đủ giờ hoặc đã xác nhận: Chốt ca và đăng xuất.
     */
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            // 1. Lấy thông tin ca hiện tại
            $currentShift = $this->userModel->getCurrentShift($_SESSION['user_id']);
            
            if ($currentShift) {
                // Tính toán thời gian
                $startTime = strtotime($currentShift->login_time);
                $now = time();
                $minutesWorked = ($now - $startTime) / 60; // Số phút đã làm
                $expectedMinutes = $currentShift->expected_hours * 60; // Số phút dự kiến

                // Kiểm tra về sớm (Cho phép sai số 5 phút du di)
                // Nếu chưa đủ giờ VÀ chưa có xác nhận confirm_early từ form
                if ($minutesWorked < ($expectedMinutes - 5) && !isset($_POST['confirm_early'])) {
                    
                    // Chuẩn bị dữ liệu cảnh báo ra View
                    $data = [
                        'remaining_minutes' => round($expectedMinutes - $minutesWorked),
                        'expected_time' => date('H:i', $startTime + $expectedMinutes * 60)
                    ];
                    
                    // Hiển thị trang cảnh báo về sớm
                    $this->view('auth/logout_confirm', $data);
                    return; // Dừng lại, không logout ngay
                }

                // 2. Chốt ca (Nếu đã đủ giờ hoặc đã nhập lý do)
                $reason = isset($_POST['early_reason']) ? trim($_POST['early_reason']) : null;
                $this->userModel->endShift($_SESSION['user_id'], $reason);
            }
        }

        // 3. Xóa Session và quay về Login
        unset($_SESSION['user_id']);
        unset($_SESSION['username']);
        unset($_SESSION['full_name']);
        unset($_SESSION['role_id']);
        session_destroy();

        header('location: ' . URLROOT . '/auth/login');
    }
}