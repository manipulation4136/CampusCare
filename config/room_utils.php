<?php
/**
 * Optimized room utility functions
 */

/**
 * Check if a room is exam ready based on open reports
 * Only applies to classrooms and laboratories
 */
function isRoomExamReady($conn, $room_id, $room_type) {
    if (!in_array(strtolower($room_type), ['classroom', 'lab', 'laboratory'])) {
        return null;
    }

    // Optimized query to check both conditions at once
    $stmt = $conn->prepare("
        SELECT 
            (SELECT COUNT(*) FROM damage_reports dr 
             JOIN assets a ON a.id = dr.asset_id 
             WHERE a.room_id = ? AND dr.status IN ('pending', 'assigned', 'in_progress')) as open_reports,
            (SELECT COUNT(*) FROM assets a 
             WHERE a.room_id = ? AND a.status = 'Needs Repair') as needs_repair
    ");
    $stmt->bind_param("ii", $room_id, $room_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return ($result['open_reports'] == 0 && $result['needs_repair'] == 0) ? 'Yes' : 'No';
}

/**
 * Get exam ready status with badge class
 */
function getExamReadyBadge($conn, $room_id, $room_type) {
    $status = isRoomExamReady($conn, $room_id, $room_type);
    if ($status === null) {
        return '<span class="badge neutral">N/A</span>';
    }
    $class = $status === 'Yes' ? 'good' : 'bad';
    return '<span class="badge ' . $class . '">' . htmlspecialchars($status) . '</span>';
}

/**
 * Get badge from already calculated status
 */
function getExamReadyBadgeFromStatus($status) {
    if ($status === 'N/A') {
        return '<span class="badge neutral">N/A</span>';
    }
    $class = $status === 'Yes' ? 'good' : 'bad';
    return '<span class="badge ' . $class . '">' . htmlspecialchars($status) . '</span>';
}

/**
 * Get current exam ready status from exam_rooms table
 */
function getStoredExamReadyStatus($conn, $room_id) {
    $stmt = $conn->prepare("SELECT status_exam_ready FROM exam_rooms WHERE room_id = ?");
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        return $row['status_exam_ready'];
    }
    return null;
}

/**
 * Update exam ready status in exam_rooms table
 */
function updateExamReadyStatus($conn, $room_id, $status) {
    $stmt = $conn->prepare("UPDATE exam_rooms SET status_exam_ready = ?, updated_at = CURRENT_TIMESTAMP WHERE room_id = ?");
    $stmt->bind_param("si", $status, $room_id);
    return $stmt->execute();
}

/**
 * Get all exam-ready rooms using a single optimized query
 */
function getExamReadyRooms($conn) {
    $query = "
        SELECT 
            r.*, 
            er.status_exam_ready,
            CASE 
                WHEN SUM(CASE WHEN dr.status IN ('pending', 'assigned', 'in_progress') THEN 1 ELSE 0 END) = 0 
                     AND SUM(CASE WHEN a.status = 'Needs Repair' THEN 1 ELSE 0 END) = 0 
                THEN 'Yes' 
                ELSE 'No' 
            END as dynamic_exam_ready_status
        FROM rooms r
        JOIN exam_rooms er ON er.room_id = r.id
        LEFT JOIN assets a ON a.room_id = r.id
        LEFT JOIN damage_reports dr ON dr.asset_id = a.id
        WHERE r.room_type IN ('classroom', 'lab', 'laboratory')
        GROUP BY r.id
        HAVING dynamic_exam_ready_status = 'Yes'
        ORDER BY r.building, r.floor, r.room_no
    ";
    return $conn->query($query);
}

/**
 * Get all exam rooms with their current status
 */
function getAllExamRoomsWithStatus($conn) {
    $query = "
        SELECT 
            r.*, 
            er.status_exam_ready, 
            er.updated_at as status_updated_at,
            COUNT(CASE WHEN dr.status IN ('pending', 'assigned', 'in_progress') THEN 1 END) as open_reports_count,
            CASE 
                WHEN SUM(CASE WHEN dr.status IN ('pending', 'assigned', 'in_progress') THEN 1 ELSE 0 END) = 0 
                     AND SUM(CASE WHEN a.status = 'Needs Repair' THEN 1 ELSE 0 END) = 0 
                THEN 'Yes' 
                ELSE 'No' 
            END as calculated_exam_ready
        FROM rooms r
        JOIN exam_rooms er ON er.room_id = r.id
        LEFT JOIN assets a ON a.room_id = r.id
        LEFT JOIN damage_reports dr ON dr.asset_id = a.id
        WHERE r.room_type IN ('classroom', 'lab', 'laboratory')
        GROUP BY r.id
        ORDER BY r.building, r.floor, r.room_no
    ";
    return $conn->query($query);
}

/**
 * Sync exam ready status for a specific room
 */
function syncExamReadyStatus($conn, $room_id) {
    $roomStmt = $conn->prepare("SELECT room_type FROM rooms WHERE id = ?");
    $roomStmt->bind_param("i", $room_id);
    $roomStmt->execute();
    $roomResult = $roomStmt->get_result();
    
    if (!$roomRow = $roomResult->fetch_assoc()) {
        return false;
    }
    
    if (!in_array(strtolower($roomRow['room_type']), ['classroom', 'lab', 'laboratory'])) {
        return true;
    }
    
    $calculatedStatus = isRoomExamReady($conn, $room_id, $roomRow['room_type']);
    $storedStatus = getStoredExamReadyStatus($conn, $room_id);
    
    if ($storedStatus !== null && $calculatedStatus !== $storedStatus) {
        return updateExamReadyStatus($conn, $room_id, $calculatedStatus);
    }
    
    return true;
}

/**
 * Bulk sync all exam room statuses
 */
function syncAllExamReadyStatuses($conn) {
    $rooms = $conn->query("SELECT r.id FROM rooms r JOIN exam_rooms er ON er.room_id = r.id");
    $updated = 0;
    while ($room = $rooms->fetch_assoc()) {
        if (syncExamReadyStatus($conn, $room['id'])) {
            $updated++;
        }
    }
    return $updated;
}

/**
 * Get room status details
 */
function getRoomStatusDetails($conn, $room_id) {
    $details = [];
    
    $stmt = $conn->prepare("
        SELECT dr.id, dr.description, dr.status, dr.created_at, a.asset_code, a.name as asset_name
        FROM damage_reports dr
        JOIN assets a ON a.id = dr.asset_id
        WHERE a.room_id = ? AND dr.status IN ('pending', 'assigned', 'in_progress')
        ORDER BY dr.created_at DESC
    ");
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $details['open_reports'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt2 = $conn->prepare("
        SELECT id, asset_code, name, status, created_at
        FROM assets
        WHERE room_id = ? AND status = 'Needs Repair'
        ORDER BY created_at DESC
    ");
    $stmt2->bind_param("i", $room_id);
    $stmt2->execute();
    $details['needs_repair_assets'] = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $details['stored_exam_status'] = getStoredExamReadyStatus($conn, $room_id);
    
    return $details;
}

function addExamRoom($conn, $room_id, $initial_status = 'Yes') {
    $stmt = $conn->prepare("INSERT INTO exam_rooms (room_id, status_exam_ready) VALUES (?, ?) ON DUPLICATE KEY UPDATE status_exam_ready = status_exam_ready");
    $stmt->bind_param("is", $room_id, $initial_status);
    return $stmt->execute();
}

function removeExamRoom($conn, $room_id) {
    $stmt = $conn->prepare("DELETE FROM exam_rooms WHERE room_id = ?");
    $stmt->bind_param("i", $room_id);
    return $stmt->execute();
}
?>