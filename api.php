<?php
header("Content-Type: application/json; charset=UTF-8");
$conn = new mysqli("localhost", "root", "", "suz");

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$conn->set_charset("utf8mb4");

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? $_GET['action'] ?? '';

function sendResponse($success, $data = null, $error = null, $statusCode = 200) {
    http_response_code($statusCode);
    $response = ['success' => $success];
    if ($data !== null) {
        $response['data'] = $data;
    }
    if ($error !== null) {
        $response['error'] = $error;
    }
    echo json_encode($response);
    exit;
}

switch ($action) {
    case 'login':
        handleLogin($conn, $data);
        break;
    case 'register':
        handleRegister($conn, $data);
        break;
    case 'create_request':
        handleCreateRequest($conn, $data);
        break;
    case 'get_requests_citizen':
        handleGetRequestsCitizen($conn, $data);
        break;
    case 'get_request_details':
        handleGetRequestDetails($conn, $data);
        break;
    case 'send_message':
        handleSendMessage($conn, $data);
        break;
    case 'update_request_status':
        handleUpdateRequestStatus($conn, $data);
        break;
    case 'get_employees':
        handleGetEmployees($conn);
        break;
    case 'add_employee':
        handleAddEmployee($conn, $data);
        break;
    case 'edit_employee':
        handleEditEmployee($conn, $data);
        break;
    case 'delete_employee':
        handleDeleteEmployee($conn, $data);
        break;
    case 'toggle_ban_citizen':
        handleToggleBanCitizen($conn, $data);
        break;
    case 'get_categories':
        handleGetCategories($conn);
        break;
    case 'get_citizens':
        handleGetCitizens($conn);
        break;
    default:
        sendResponse(false, null, 'Unknown action', 400);
}

$conn->close();

// ========== Functions ==========

function handleLogin($conn, $data) {
    if (!isset($data['username'], $data['password'])) {
        sendResponse(false, null, 'Missing username or password', 400);
    }

    $username = $data['username'];
    $password = $data['password'];

    $stmt = $conn->prepare("SELECT id, username, name, password, role FROM users WHERE username = ? AND banned = 0");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        if (password_verify($password, $user['password'])) {
            $response = [
                'id' => $user['id'],
                'username' => $user['username'],
                'name' => $user['name'],
                'role' => $user['role']
            ];

            if ($user['role'] === 'employee') {
                $stmt = $conn->prepare("SELECT d.name FROM departments d JOIN user_departments ud ON d.id = ud.department_id WHERE ud.user_id = ?");
                $stmt->bind_param("i", $user['id']);
                $stmt->execute();
                $result = $stmt->get_result();
                $response['department'] = array_column($result->fetch_all(MYSQLI_ASSOC), 'name');
            }

            sendResponse(true, $response);
        } else {
            sendResponse(false, null, 'Invalid password', 401);
        }
    } else {
        sendResponse(false, null, 'User not found or banned', 401);
    }
}

function handleRegister($conn, $data) {
    if (!isset($data['fullname'], $data['district'], $data['phone'], $data['reg-password'])) {
        sendResponse(false, null, 'Missing required fields', 400);
    }

    $name = trim($data['fullname']);
    $district = trim($data['district']);
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone']);
    $password = password_hash($data['reg-password'], PASSWORD_DEFAULT);
    $username = strtolower(str_replace(' ', '', $name));

    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        sendResponse(false, null, 'Username already taken', 409);
        return;
    }

    $stmt = $conn->prepare("INSERT INTO users (name, username, password, role, district, email, phone) VALUES (?, ?, ?, 'citizen', ?, ?, ?)");
    $stmt->bind_param("ssssss", $name, $username, $password, $district, $email, $phone);
    if ($stmt->execute()) {
        sendResponse(true, ['message' => 'Registration successful']);
    } else {
        sendResponse(false, null, 'Registration failed', 500);
    }
}

function handleCreateRequest($conn, $data) {
    if (!isset($data['title'], $data['description'], $data['address'], $data['category_id'], $data['user_id'], $data['assigned_department_id'])) {
        sendResponse(false, null, 'Missing required fields', 400);
    }

    $title = trim($data['title']);
    $description = trim($data['description']);
    $address = trim($data['address']);
    $category_id = (int)$data['category_id'];
    $subcategory_id = isset($data['subcategory_id']) && $data['subcategory_id'] !== '' ? (int)$data['subcategory_id'] : null;
    $user_id = (int)$data['user_id'];
    $assigned_department_id = (int)$data['assigned_department_id'];

    $stmt = $conn->prepare("INSERT INTO requests (title, description, address, category_id, subcategory_id, user_id, assigned_department_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'new')");
    $stmt->bind_param("sssiiii", $title, $description, $address, $category_id, $subcategory_id, $user_id, $assigned_department_id);

    if ($stmt->execute()) {
        sendResponse(true, ['request_id' => $conn->insert_id]);
    } else {
        sendResponse(false, null, 'Failed to create request', 500);
    }
}

function handleGetRequestsCitizen($conn, $data) {
    if (!isset($data['user_id'])) {
        sendResponse(false, null, 'Missing user_id', 400);
    }

    $user_id = (int)$data['user_id'];
    $filter = $data['filter'] ?? 'all';

    $sql = "SELECT r.id, r.title, r.description, r.address, r.date_created AS date, r.status, 
                   c.name AS category_name, sc.name AS subcategory_name
            FROM requests r
            JOIN categories c ON r.category_id = c.id
            LEFT JOIN subcategories sc ON r.subcategory_id = sc.id
            WHERE r.user_id = ?";
    
    if ($filter !== 'all') {
        $sql .= " AND r.status = ?";
    }

    $stmt = $conn->prepare($sql);
    if ($filter !== 'all') {
        $stmt->bind_param("is", $user_id, $filter);
    } else {
        $stmt->bind_param("i", $user_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $requests = $result->fetch_all(MYSQLI_ASSOC);

    sendResponse(true, $requests);
}

function handleGetRequestDetails($conn, $data) {
    if (!isset($data['request_id'])) {
        sendResponse(false, null, 'Missing request_id', 400);
    }

    $request_id = (int)$data['request_id'];
    $stmt = $conn->prepare("SELECT r.id, r.title, r.description, r.address, r.date_created AS date, r.status,
                                   c.name AS category_name, sc.name AS subcategory_name,
                                   u.name AS citizen_name
                            FROM requests r
                            JOIN categories c ON r.category_id = c.id
                            LEFT JOIN subcategories sc ON r.subcategory_id = sc.id
                            JOIN users u ON r.user_id = u.id
                            WHERE r.id = ?");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($request = $result->fetch_assoc()) {
        $stmt = $conn->prepare("SELECT m.message_text, m.timestamp, m.is_read, u.role, u.id AS user_id
                                FROM messages m
                                JOIN users u ON m.sender_id = u.id
                                WHERE m.request_id = ?
                                ORDER BY m.timestamp");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $request['messages'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        sendResponse(true, $request);
    } else {
        sendResponse(false, null, 'Request not found', 404);
    }
}

function handleSendMessage($conn, $data) {
    if (!isset($data['request_id'], $data['sender_id'], $data['text'])) {
        sendResponse(false, null, 'Missing required fields', 400);
    }

    $request_id = (int)$data['request_id'];
    $sender_id = (int)$data['sender_id'];
    $message_text = trim($data['text']);

    $stmt = $conn->prepare("INSERT INTO messages (request_id, sender_id, message_text) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $request_id, $sender_id, $message_text);

    if ($stmt->execute()) {
        sendResponse(true);
    } else {
        sendResponse(false, null, 'Failed to send message', 500);
    }
}

function handleUpdateRequestStatus($conn, $data) {
    if (!isset($data['request_id'], $data['status'])) {
        sendResponse(false, null, 'Missing required fields', 400);
    }

    $request_id = (int)$data['request_id'];
    $status = $data['status'];

    if (!in_array($status, ['new', 'in_progress', 'completed'])) {
        sendResponse(false, null, 'Invalid status', 400);
    }

    $stmt = $conn->prepare("UPDATE requests SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $request_id);

    if ($stmt->execute()) {
        sendResponse(true);
    } else {
        sendResponse(false, null, 'Failed to update status', 500);
    }
}

function handleGetEmployees($conn) {
    $sql = "SELECT u.id, u.name, GROUP_CONCAT(d.name SEPARATOR ', ') AS department
            FROM users u
            JOIN user_departments ud ON u.id = ud.user_id
            JOIN departments d ON ud.department_id = d.id
            WHERE u.role = 'employee'
            GROUP BY u.id";
    $result = $conn->query($sql);
    $employees = $result->fetch_all(MYSQLI_ASSOC);
    sendResponse(true, $employees);
}

function handleAddEmployee($conn, $data) {
    if (!isset($data['name'], $data['username'], $data['password'], $data['departments'])) {
        sendResponse(false, null, 'Missing required fields', 400);
    }

    $name = trim($data['name']);
    $username = trim($data['username']);
    $password = password_hash($data['password'], PASSWORD_DEFAULT);
    $departments = $data['departments'];

    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        sendResponse(false, null, 'Username already taken', 409);
        return;
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO users (name, username, password, role) VALUES (?, ?, ?, 'employee')");
        $stmt->bind_param("sss", $name, $username, $password);
        $stmt->execute();
        $user_id = $conn->insert_id;

        foreach ($departments as $dept) {
            $stmt = $conn->prepare("SELECT id FROM departments WHERE name = ?");
            $stmt->bind_param("s", $dept);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $dept_id = $row['id'];
                $stmt = $conn->prepare("INSERT INTO user_departments (user_id, department_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $user_id, $dept_id);
                $stmt->execute();
            }
        }

        $conn->commit();
        sendResponse(true);
    } catch (Exception $e) {
        $conn->rollback();
        sendResponse(false, null, 'Failed to add employee: ' . $e->getMessage(), 500);
    }
}

function handleEditEmployee($conn, $data) {
    if (!isset($data['id'], $data['name'], $data['username'], $data['departments'])) {
        sendResponse(false, null, 'Missing required fields', 400);
    }

    $user_id = (int)$data['id'];
    $name = trim($data['name']);
    $username = trim($data['username']);
    $password = !empty($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null;
    $departments = $data['departments'];

    $conn->begin_transaction();
    try {
        if ($password) {
            $stmt = $conn->prepare("UPDATE users SET name = ?, username = ?, password = ? WHERE id = ?");
            $stmt->bind_param("sssi", $name, $username, $password, $user_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name = ?, username = ? WHERE id = ?");
            $stmt->bind_param("ssi", $name, $username, $user_id);
        }
        $stmt->execute();

        $stmt = $conn->prepare("DELETE FROM user_departments WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        foreach ($departments as $dept) {
            $stmt = $conn->prepare("SELECT id FROM departments WHERE name = ?");
            $stmt->bind_param("s", $dept);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $dept_id = $row['id'];
                $stmt = $conn->prepare("INSERT INTO user_departments (user_id, department_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $user_id, $dept_id);
                $stmt->execute();
            }
        }

        $conn->commit();
        sendResponse(true);
    } catch (Exception $e) {
        $conn->rollback();
        sendResponse(false, null, 'Failed to edit employee: ' . $e->getMessage(), 500);
    }
}

function handleDeleteEmployee($conn, $data) {
    if (!isset($data['id'])) {
        sendResponse(false, null, 'Missing user_id', 400);
    }

    $user_id = (int)$data['id'];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM user_departments WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'employee'");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $conn->commit();
            sendResponse(true);
        } else {
            $conn->rollback();
            sendResponse(false, null, 'Employee not found', 404);
        }
    } catch (Exception $e) {
        $conn->rollback();
        sendResponse(false, null, 'Failed to delete employee: ' . $e->getMessage(), 500);
    }
}

function handleToggleBanCitizen($conn, $data) {
    if (!isset($data['id'])) {
        sendResponse(false, null, 'Missing user_id', 400);
    }

    $user_id = (int)$data['id'];
    $stmt = $conn->prepare("UPDATE users SET banned = NOT banned WHERE id = ? AND role = 'citizen'");
    $stmt->bind_param("i", $user_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        sendResponse(true);
    } else {
        sendResponse(false, null, 'Citizen not found', 404);
    }
}

function handleGetCategories($conn) {
    $stmt = $conn->prepare("SELECT c.id, c.name AS name, COALESCE(JSON_ARRAYAGG(JSON_OBJECT('id', sc.id, 'name', sc.name)), '[]') AS subcategories
                            FROM categories c
                            LEFT JOIN subcategories sc ON c.id = sc.category_id
                            GROUP BY c.id, c.name");
    $stmt->execute();
    $categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    sendResponse(true, ['categories' => $categories]);
}

function handleGetCitizens($conn) {
    $stmt = $conn->prepare("SELECT id, name, username, role, banned FROM users WHERE role = 'citizen'");
    $stmt->execute();
    $citizens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    sendResponse(true, $citizens);
}
?>