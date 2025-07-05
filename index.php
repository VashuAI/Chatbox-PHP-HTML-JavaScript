<?php
session_start();


// Database configuration
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "chat";

// Create database connection
try {
    $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($mysqli->connect_error) {
        throw new Exception("Database connection failed: " . $mysqli->connect_error);
    }
    $mysqli->set_charset("utf8mb4");
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}



$createTables = [
    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen TIMESTAMP NULL DEFAULT NULL,
        status ENUM('online', 'offline') DEFAULT 'offline'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    "CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX (sender_id, receiver_id),
        INDEX (receiver_id, sender_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

foreach ($createTables as $query) {
    if (!$mysqli->query($query)) {
        die("Error creating table: " . $mysqli->error);
    }
}

// Update user status to online
if (isset($_SESSION['uid'])) {
    $stmt = $mysqli->prepare("UPDATE users SET status='online', last_seen=NOW() WHERE id=?");
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['uid']);
        $stmt->execute();
        $stmt->close();
    }
}

// Handle registration
if (isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    $stmt = $mysqli->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("ss", $username, $password);
        if ($stmt->execute()) {
            $_SESSION['uid'] = $stmt->insert_id;
            $_SESSION['username'] = $username;
            header("Location: ".$_SERVER['PHP_SELF']);
            exit;
        } else {
            $error = "Username already exists!";
        }
        $stmt->close();
    } else {
        $error = "Database error: " . $mysqli->error;
    }
}

// Handle login
if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    $stmt = $mysqli->prepare("SELECT id, password FROM users WHERE username = ?");
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $stmt->bind_result($uid, $hashed);
            $stmt->fetch();
            
            if (password_verify($password, $hashed)) {
                $_SESSION['uid'] = $uid;
                $_SESSION['username'] = $username;
                $mysqli->query("UPDATE users SET status='online', last_seen=NOW() WHERE id=$uid");
                header("Location: ".$_SERVER['PHP_SELF']);
                exit;
            }
        }
        $stmt->close();
    }
    
    $error = "Invalid username or password!";
}

// Handle logout
if (isset($_GET['logout'])) {
    if (isset($_SESSION['uid'])) {
        $mysqli->query("UPDATE users SET status='offline', last_seen=NOW() WHERE id=".$_SESSION['uid']);
    }
    session_destroy();
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

// Handle sending messages
if (isset($_POST['sendMsg']) && isset($_SESSION['uid'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        $msg = trim($_POST['message']);
        $to = intval($_POST['to']);
        $from = $_SESSION['uid'];
        
        if (empty($msg)) {
            throw new Exception("Message cannot be empty");
        }
        
        if ($to <= 0) {
            throw new Exception("Invalid recipient");
        }
        
        $stmt = $mysqli->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Database error: " . $mysqli->error);
        }
        
        $stmt->bind_param("iis", $from, $to, $msg);
        if (!$stmt->execute()) {
            throw new Exception("Failed to send message: " . $stmt->error);
        }
        
        $readStmt = $mysqli->prepare("UPDATE messages SET is_read=TRUE WHERE sender_id=? AND receiver_id=?");
        if ($readStmt) {
            $readStmt->bind_param("ii", $to, $from);
            $readStmt->execute();
            $readStmt->close();
        }
        
        $response['success'] = true;
        $response['message'] = "Message sent successfully";
        
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

// Fetch messages
if (isset($_GET['fetch']) && isset($_SESSION['uid'])) {
    $with = intval($_GET['with']);
    $me = $_SESSION['uid'];
    
    $stmt = $mysqli->prepare("SELECT 
        m.id, m.message, m.created_at, m.is_read,
        u1.username as sender_name, 
        u2.username as receiver_name 
        FROM messages m
        JOIN users u1 ON m.sender_id = u1.id
        JOIN users u2 ON m.receiver_id = u2.id
        WHERE (m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?) 
        ORDER BY m.created_at");
    
    if ($stmt) {
        $stmt->bind_param("iiii", $me, $with, $with, $me);
        $stmt->execute();
        $res = $stmt->get_result();
        
        while ($row = $res->fetch_assoc()) {
            $who = ($row['sender_name'] == $_SESSION['username']) ? 'me' : 'them';
            $msg = htmlspecialchars($row['message']);
            $time = date("H:i", strtotime($row['created_at']));
            $isRead = $row['is_read'] ? 'read' : '';
            
            echo "<div class='message $who'>
                    <div class='bubble'>$msg</div>
                    <div class='meta'>
                        <span class='time'>$time</span>
                        ".($who == 'me' ? "<span class='read-status $isRead'><i class='fas fa-check-double'></i></span>" : "")."
                    </div>
                  </div>";
        }
        $stmt->close();
    }
    exit;
}

// Fetch user list
if (isset($_GET['getUsers']) && isset($_SESSION['uid'])) {
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $me = $_SESSION['uid'];
    
    try {
        if (!empty($search)) {
            $searchTerm = "%" . $search . "%";
            $query = "SELECT id, username, status, last_seen FROM users WHERE username LIKE ? AND id != ? ORDER BY status DESC, username";
            $stmt = $mysqli->prepare($query);
            if ($stmt) {
                $stmt->bind_param("si", $searchTerm, $me);
            }
        } else {
            $query = "SELECT id, username, status, last_seen FROM users WHERE id != ? ORDER BY status DESC, username";
            $stmt = $mysqli->prepare($query);
            if ($stmt) {
                $stmt->bind_param("i", $me);
            }
        }
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $res = $stmt->get_result();
        
        if ($res->num_rows === 0) {
            echo "<div class='no-users'>No users found</div>";
        } else {
            while ($row = $res->fetch_assoc()) {
                $active = (isset($_GET['chat_with']) && $_GET['chat_with'] == $row['id']) ? 'active' : '';
                $status = $row['status'];
                $lastSeen = $status == 'online' ? 'Online' : 'Last seen '.time_elapsed_string($row['last_seen']);
                
                echo "<a href='?chat_with={$row['id']}' class='user $active'>
                        <div class='avatar' data-status='$status'>".strtoupper(substr($row['username'], 0, 1))."</div>
                        <div class='user-info'>
                            <div class='username'>{$row['username']}</div>
                            <div class='status'>$lastSeen</div>
                        </div>
                        <div class='unread-count'></div>
                      </a>";
            }
        }
        $stmt->close();
    } catch (Exception $e) {
        echo "<div class='error'>Error loading users: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    exit;
}

function time_elapsed_string($datetime, $full = false) {
    if (empty($datetime)) return "never";
    
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Application</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    
</head>
<body>
    <?php if (!isset($_SESSION['uid'])): ?>
        <div class="login-container">
            <div class="login-card glass">
                <div class="login-logo">
                    <div class="login-logo-icon">
                        <i class="fas fa-comments"></i>
                    </div>
                    <h1 class="login-title">Chat Application</h1>
                    <p class="login-subtitle">Connect with anyone, anywhere</p>
                </div>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <div class="auth-tabs">
                    <div class="auth-tab active" data-tab="login">Login</div>
                    <div class="auth-tab" data-tab="register">Register</div>
                </div>
                
                <div class="auth-forms">
                    <form method="post" class="auth-form active" id="loginForm">
                        <div class="form-group">
                            <label for="login-username" class="form-label">Username</label>
                            <input type="text" id="login-username" name="username" class="form-control" placeholder="Enter your username" required>
                        </div>
                        <div class="form-group">
                            <label for="login-password" class="form-label">Password</label>
                            <input type="password" id="login-password" name="password" class="form-control" placeholder="Enter your password" required>
                        </div>
                        <button type="submit" name="login" class="btn btn-primary">Login</button>
                    </form>
                    
                    <form method="post" class="auth-form" id="registerForm">
                        <div class="form-group">
                            <label for="register-username" class="form-label">Username</label>
                            <input type="text" id="register-username" name="username" class="form-control" placeholder="Choose a username" required>
                        </div>
                        <div class="form-group">
                            <label for="register-password" class="form-label">Password</label>
                            <input type="password" id="register-password" name="password" class="form-control" placeholder="Choose a password" required>
                        </div>
                        <button type="submit" name="register" class="btn btn-success">Register</button>
                    </form>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="chat-app">
            <!-- Sidebar -->
            <div class="sidebar">
                <div class="user-header">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                    </div>
                    <div class="user-info">
                        <div class="user-name"><?php echo $_SESSION['username']; ?></div>
                        <div class="user-status">Online</div>
                    </div>
                    <button class="logout-btn" onclick="location.href='?logout=1'">
                        <i class="fas fa-sign-out-alt"></i>
                    </button>
                </div>
                
                <div class="search-box">
                    <input type="text" id="userSearch" class="search-input" placeholder="Search users...">
                </div>
                
                <div class="user-list" id="userList">
                    <!-- Users will be loaded here via AJAX -->
                </div>
            </div>
            
            <!-- Chat Area -->
            <div class="chat-area" id="chatArea">
                <?php if (isset($_GET['chat_with'])): ?>
                    <?php 
                        $chatWith = intval($_GET['chat_with']);
                        $stmt = $mysqli->prepare("SELECT username, status FROM users WHERE id = ?");
                        $stmt->bind_param("i", $chatWith);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $user = $res->fetch_assoc();
                        $status = $user['status'] == 'online' ? 'Online' : 'Offline';
                    ?>
                    <div class="chat-header">
                        <div class="chat-avatar" data-status="<?php echo $user['status']; ?>">
                            <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                        </div>
                        <div class="chat-info">
                            <div class="chat-name"><?php echo $user['username']; ?></div>
                            <div class="chat-status"><?php echo $status; ?></div>
                        </div>
                        <div class="chat-actions">
                            <button class="chat-action-btn">
                                <i class="fas fa-phone"></i>
                            </button>
                            <button class="chat-action-btn">
                                <i class="fas fa-video"></i>
                            </button>
                            <button class="chat-action-btn">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="chat-messages" id="chatMessages">
                        <!-- Messages will be loaded here via AJAX -->
                    </div>
                    
                    <div class="chat-input-area">
                        <input type="text" id="messageInput" class="message-input" placeholder="Type a message..." autocomplete="off">
                        <input type="hidden" id="receiverId" value="<?php echo $chatWith; ?>">
                        <button type="submit" id="sendBtn" class="send-btn">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                <?php else: ?>
                    <div class="no-chat">
                        <div class="no-chat-icon">
                            <i class="fas fa-comment-dots"></i>
                        </div>
                        <h2 class="no-chat-title">No chat selected</h2>
                        <p class="no-chat-description">Choose a conversation from the sidebar to start chatting</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        $(document).ready(function() {
            console.log("Document ready. Initializing chat application.");
            
            // Tab switching for login/register
            $('.auth-tab').click(function() {
                $('.auth-tab').removeClass('active');
                $(this).addClass('active');
                $('.auth-form').removeClass('active');
                $('#' + $(this).data('tab') + 'Form').addClass('active');
            });
            
            // Load users
            function loadUsers(search = '') {
                $.get('?getUsers=1&search=' + encodeURIComponent(search), function(data) {
                    $('#userList').html(data);
                    
                    // Highlight active user
                    const urlParams = new URLSearchParams(window.location.search);
                    const chatWith = urlParams.get('chat_with');
                    if (chatWith) {
                        $('.user').removeClass('active');
                        $(`.user[href*="chat_with=${chatWith}"]`).addClass('active');
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                    console.error("Error loading users:", textStatus, errorThrown);
                    $('#userList').html("<div class='error'>Error loading users. Please try again.</div>");
                });
            }
            
            // Initial load
            loadUsers();
            
            // Search users with debounce
            let searchTimer;
            $('#userSearch').on('input', function() {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => {
                    loadUsers($(this).val());
                }, 300);
            });
            
            // Load messages
            // Load messages without blinking
function loadMessages() {
    const chatWith = $('#receiverId').val();
    if (chatWith) {
        $.get('?fetch=1&with=' + chatWith)
            .done(function(data) {
                // Only update if messages have changed
                if ($('#chatMessages').html() !== data) {
                    $('#chatMessages').html(data);
                    $('#chatMessages').scrollTop($('#chatMessages')[0].scrollHeight);
                }
            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                console.error("Error loading messages:", textStatus, errorThrown);
            });
    }
}

// Update the auto-refresh to use a smoother approach
if ($('#receiverId').val()) {
    // Initial load
    loadMessages();
    
    // Set up refresh with visibility check
    let refreshInterval = setInterval(function() {
        if (!document.hidden) { // Only refresh if tab is active
            loadMessages();
        }
    }, 2000);
    
    // Handle tab visibility changes
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            loadMessages(); // Load immediately when tab becomes visible
        }
    });
}
            
            // Send message
            $('#sendBtn').click(function(e) {
                e.preventDefault();
                sendMessage();
            });
            
            $('#messageInput').keypress(function(e) {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
            
            function sendMessage() {
                const message = $('#messageInput').val().trim();
                const receiver = $('#receiverId').val();
                
                if (message && receiver) {
                    $.ajax({
                        url: '',
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            sendMsg: 1,
                            message: message,
                            to: receiver
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#messageInput').val('');
                                loadMessages();
                            } else {
                                alert('Error: ' + response.message);
                                console.error('Send message failed:', response.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            const errorMessage = xhr.responseJSON && xhr.responseJSON.message 
                                ? xhr.responseJSON.message 
                                : 'Failed to send message. Please try again.';
                            alert('Error: ' + errorMessage);
                            console.error('AJAX error:', status, error);
                        }
                    });
                }
            }
            
            // Auto-refresh messages if chat is open
            if ($('#receiverId').val()) {
                loadMessages();
                setInterval(loadMessages, 2000);
            }
            
            // Auto-focus message input
            $('#messageInput').focus();
        });
    </script>
</body>
</html>