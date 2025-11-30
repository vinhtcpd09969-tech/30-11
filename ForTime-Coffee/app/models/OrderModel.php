<?php
/*
 * ORDER MODEL
 * -------------------------------------------------------------------------
 * Vai trò: Quản lý Đơn hàng (Orders) và Chi tiết đơn hàng (Order Details)
 * Chức năng chính:
 * 1. Tạo đơn, thêm/bớt/sửa món.
 * 2. Thanh toán, áp dụng/hủy mã giảm giá (Tự động kiểm tra điều kiện).
 * 3. Chuyển bàn, gộp bàn.
 * 4. Truy xuất lịch sử đơn hàng.
 * -------------------------------------------------------------------------
 */
class OrderModel {
    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    // =========================================================================
    // 1. QUẢN LÝ ĐƠN HÀNG TRỰC TIẾP (POS)
    // =========================================================================

    public function getUnpaidOrder($tableId) {
        $sql = "SELECT o.*, 
                       d.code as discount_code, 
                       d.type as discount_type, 
                       d.value as discount_value 
                FROM orders o 
                LEFT JOIN discounts d ON o.discount_id = d.discount_id
                WHERE o.table_id = :tid AND o.status = 'pending'";
                
        $this->db->query($sql);
        $this->db->bind(':tid', $tableId);
        return $this->db->single();
    }

    public function createOrder($userId, $tableId) {
        $this->db->query("INSERT INTO orders (user_id, table_id, order_time, status) 
                          VALUES (:uid, :tid, NOW(), 'pending')");
        $this->db->bind(':uid', $userId);
        $this->db->bind(':tid', $tableId);
        
        if ($this->db->execute()) {
            $this->db->query("UPDATE tables SET status = 'occupied' WHERE table_id = :tid");
            $this->db->bind(':tid', $tableId);
            $this->db->execute();
            
            $this->db->query("SELECT LAST_INSERT_ID() as id");
            return $this->db->single()->id;
        }
        return false;
    }

    public function addNumItem($orderId, $productId, $price, $note = '') {
        $sql = "SELECT * FROM order_details 
                WHERE order_id = :oid AND product_id = :pid AND note = :note";
        
        $this->db->query($sql);
        $this->db->bind(':oid', $orderId);
        $this->db->bind(':pid', $productId);
        $this->db->bind(':note', $note);
        
        $existingItem = $this->db->single();

        if ($existingItem) {
            $this->db->query("UPDATE order_details 
                              SET quantity = quantity + 1 
                              WHERE order_detail_id = :did");
            $this->db->bind(':did', $existingItem->order_detail_id);
        } else {
            $this->db->query("INSERT INTO order_details (order_id, product_id, quantity, unit_price, note) 
                              VALUES (:oid, :pid, 1, :price, :note)");
            $this->db->bind(':oid', $orderId);
            $this->db->bind(':pid', $productId);
            $this->db->bind(':price', $price);
            $this->db->bind(':note', $note);
        }
        $this->db->execute();
        
        $this->updateOrderTotal($orderId);
    }

    public function updateOrderDetail($detailId, $orderId, $newPrice, $newNote) {
        $sql = "UPDATE order_details 
                SET unit_price = :price, note = :note 
                WHERE order_detail_id = :did";
        
        $this->db->query($sql);
        $this->db->bind(':price', $newPrice);
        $this->db->bind(':note', $newNote);
        $this->db->bind(':did', $detailId);
        
        if ($this->db->execute()) {
            $this->updateOrderTotal($orderId);
            return true;
        }
        return false;
    }

    public function updateNote($detailId, $note) {
        $this->db->query("UPDATE order_details SET note = :note WHERE order_detail_id = :did");
        $this->db->bind(':note', $note);
        $this->db->bind(':did', $detailId);
        return $this->db->execute();
    }

    public function deleteOrderDetail($detailId, $orderId) {
        $this->db->query("DELETE FROM order_details WHERE order_detail_id = :did");
        $this->db->bind(':did', $detailId);
        
        if ($this->db->execute()) {
            $this->updateOrderTotal($orderId);

            $this->db->query("SELECT COUNT(*) as count FROM order_details WHERE order_id = :oid");
            $this->db->bind(':oid', $orderId);
            $row = $this->db->single();

            if ($row->count == 0) {
                $this->cancelEmptyOrder($orderId);
            }
            return true;
        }
        return false;
    }

    public function updateItemQuantity($detailId, $action) {
        $this->db->query("SELECT order_id, quantity FROM order_details WHERE order_detail_id = :did");
        $this->db->bind(':did', $detailId);
        $item = $this->db->single();
        
        if (!$item) return false;
        $orderId = $item->order_id;

        if ($action == 'inc') {
             $this->db->query("UPDATE order_details SET quantity = quantity + 1 WHERE order_detail_id = :did");
             $this->db->bind(':did', $detailId);
             $this->db->execute();
        } elseif ($action == 'dec') {
             if ($item->quantity > 1) {
                 $this->db->query("UPDATE order_details SET quantity = quantity - 1 WHERE order_detail_id = :did");
                 $this->db->bind(':did', $detailId);
                 $this->db->execute();
             }
        }
        
        $this->updateOrderTotal($orderId);
        return true;
    }

    /**
     * [FIX QUAN TRỌNG] Cập nhật tổng tiền & Tự động kiểm tra lại mã giảm giá
     */
    public function updateOrderTotal($orderId) {
        // 1. Tính lại tổng tiền mới
        $sql = "UPDATE orders 
                SET total_amount = (
                    SELECT COALESCE(SUM(quantity * unit_price), 0) 
                    FROM order_details 
                    WHERE order_id = :oid
                ) 
                WHERE order_id = :oid";
        
        $this->db->query($sql);
        $this->db->bind(':oid', $orderId);
        $this->db->execute();

        // 2. Kiểm tra lại điều kiện mã giảm giá ngay lập tức
        $this->revalidateDiscount($orderId);
    }

    /**
     * [MỚI] Hàm kiểm tra và hủy mã nếu không còn đủ điều kiện
     */
    private function revalidateDiscount($orderId) {
        // Lấy thông tin đơn hàng và mã giảm giá đang áp dụng
        $sql = "SELECT o.total_amount, d.discount_id, d.min_order_value 
                FROM orders o
                JOIN discounts d ON o.discount_id = d.discount_id
                WHERE o.order_id = :oid";
        
        $this->db->query($sql);
        $this->db->bind(':oid', $orderId);
        $data = $this->db->single();

        if ($data) {
            // Nếu có mã giảm giá và có điều kiện tối thiểu
            // Mà tổng tiền hiện tại < điều kiện tối thiểu
            if ($data->min_order_value > 0 && $data->total_amount < $data->min_order_value) {
                // Hủy mã giảm giá tự động
                $this->removeDiscount($orderId);
            }
        }
    }

    private function cancelEmptyOrder($orderId) {
        $this->db->query("SELECT table_id FROM orders WHERE order_id = :oid");
        $this->db->bind(':oid', $orderId);
        $orderInfo = $this->db->single();

        if ($orderInfo) {
            $this->db->query("UPDATE tables SET status = 'empty' WHERE table_id = :tid");
            $this->db->bind(':tid', $orderInfo->table_id);
            $this->db->execute();
        }
        
        $this->db->query("UPDATE orders SET status = 'canceled' WHERE order_id = :oid");
        $this->db->bind(':oid', $orderId);
        $this->db->execute();
    }

    // =========================================================================
    // 2. THANH TOÁN & GIẢM GIÁ
    // =========================================================================

    public function getOrderDetails($orderId) {
        $sql = "SELECT od.*, p.product_name, p.image, p.price 
                FROM order_details od 
                JOIN products p ON od.product_id = p.product_id 
                WHERE od.order_id = :oid";
        
        $this->db->query($sql);
        $this->db->bind(':oid', $orderId);
        return $this->db->resultSet();
    }

    public function applyDiscount($orderId, $discountId) {
        $this->db->query("UPDATE orders SET discount_id = :did WHERE order_id = :oid");
        $this->db->bind(':did', $discountId);
        $this->db->bind(':oid', $orderId);
        return $this->db->execute();
    }
    
    public function removeDiscount($orderId) {
        $this->db->query("UPDATE orders SET discount_id = NULL WHERE order_id = :oid");
        $this->db->bind(':oid', $orderId);
        return $this->db->execute();
    }

    public function checkoutOrder($orderId, $finalAmount) {
        $this->db->query("UPDATE orders SET status = 'paid', final_amount = :amount WHERE order_id = :oid");
        $this->db->bind(':amount', $finalAmount);
        $this->db->bind(':oid', $orderId);
        
        if ($this->db->execute()) {
            $this->db->query("SELECT table_id FROM orders WHERE order_id = :oid");
            $this->db->bind(':oid', $orderId);
            $row = $this->db->single();
            
            if ($row) {
                $this->db->query("UPDATE tables SET status = 'empty' WHERE table_id = :tid");
                $this->db->bind(':tid', $row->table_id);
                $this->db->execute();
            }
            return true;
        }
        return false;
    }

    // =========================================================================
    // 3. CHUYỂN BÀN / GỘP BÀN
    // =========================================================================

    public function moveTable($fromTableId, $toTableId) {
        $orderA = $this->getUnpaidOrder($fromTableId);
        if (!$orderA) return false;

        $orderB = $this->getUnpaidOrder($toTableId);

        if (!$orderB) {
            $this->db->query("UPDATE orders SET table_id = :toTid WHERE order_id = :oid");
            $this->db->bind(':toTid', $toTableId);
            $this->db->bind(':oid', $orderA->order_id);
            $this->db->execute();

            $this->db->query("UPDATE tables SET status = 'empty' WHERE table_id = :idA");
            $this->db->bind(':idA', $fromTableId);
            $this->db->execute();

            $this->db->query("UPDATE tables SET status = 'occupied' WHERE table_id = :idB");
            $this->db->bind(':idB', $toTableId);
            $this->db->execute();

        } else {
            $this->db->query("UPDATE order_details SET order_id = :oidB WHERE order_id = :oidA");
            $this->db->bind(':oidB', $orderB->order_id);
            $this->db->bind(':oidA', $orderA->order_id);
            $this->db->execute();

            $this->db->query("DELETE FROM orders WHERE order_id = :oidA");
            $this->db->bind(':oidA', $orderA->order_id);
            $this->db->execute();

            $this->db->query("UPDATE tables SET status = 'empty' WHERE table_id = :idA");
            $this->db->bind(':idA', $fromTableId);
            $this->db->execute();

            $this->updateOrderTotal($orderB->order_id);
        }
        return true;
    }

    // =========================================================================
    // 4. BÁO CÁO & LỊCH SỬ
    // =========================================================================

    public function countOrders($fromDate = null, $toDate = null, $search = '') {
        $sql = "SELECT COUNT(*) as count FROM orders o LEFT JOIN users u ON o.user_id = u.user_id WHERE o.status = 'paid'";
        if ($fromDate && $toDate) $sql .= " AND DATE(o.order_time) BETWEEN :from AND :to";
        if (!empty($search)) $sql .= " AND (o.order_id LIKE :search OR u.full_name LIKE :search OR u.username LIKE :search)";

        $this->db->query($sql);
        if ($fromDate && $toDate) {
            $this->db->bind(':from', $fromDate);
            $this->db->bind(':to', $toDate);
        }
        if (!empty($search)) $this->db->bind(':search', "%$search%");

        $row = $this->db->single();
        return $row->count;
    }

    public function getAllOrders($fromDate = null, $toDate = null, $limit = 10, $offset = 0, $search = '') {
        $sql = "SELECT o.*, u.full_name as staff_name FROM orders o LEFT JOIN users u ON o.user_id = u.user_id WHERE o.status = 'paid'";
        
        if ($fromDate && $toDate) $sql .= " AND DATE(o.order_time) BETWEEN :from AND :to";
        if (!empty($search)) $sql .= " AND (o.order_id LIKE :search OR u.full_name LIKE :search OR u.username LIKE :search)";
        
        $sql .= " ORDER BY o.order_time DESC LIMIT :limit OFFSET :offset";
        
        $this->db->query($sql);
        if ($fromDate && $toDate) {
            $this->db->bind(':from', $fromDate);
            $this->db->bind(':to', $toDate);
        }
        if (!empty($search)) $this->db->bind(':search', "%$search%");
        $this->db->bind(':limit', $limit, PDO::PARAM_INT);
        $this->db->bind(':offset', $offset, PDO::PARAM_INT);

        return $this->db->resultSet();
    }

    public function getOrderDetail($order_id) {
        $sqlOrder = "SELECT o.*, u.full_name as staff_name FROM orders o LEFT JOIN users u ON o.user_id = u.user_id WHERE o.order_id = :id";
        $this->db->query($sqlOrder);
        $this->db->bind(':id', $order_id);
        $order = $this->db->single();

        $sqlItems = "SELECT od.*, p.product_name, p.image, p.price 
                     FROM order_details od
                     JOIN products p ON od.product_id = p.product_id
                     WHERE od.order_id = :id";
        
        $this->db->query($sqlItems);
        $this->db->bind(':id', $order_id);
        $items = $this->db->resultSet();

        return ['info' => $order, 'items' => $items];
    }
}