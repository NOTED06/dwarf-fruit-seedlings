<?php
ini_set('session.gc_maxlifetime', 86400 * 30);
session_set_cookie_params(86400 * 30);
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'customer_login') {
    require_once 'db_conn.php';
    header('Content-Type: application/json');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Username and password are required']);
        exit;
    }
    $stmt = $conn->prepare('SELECT customer_id, name, email, password FROM customer WHERE name = ? OR email = ? LIMIT 1');
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Query error: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows === 1) {
        $row = $result->fetch_assoc();
        if (!empty($row['password']) && password_verify($password, $row['password'])) {
            $_SESSION['user_id'] = $row['customer_id'];
            $_SESSION['type'] = 'customer';
            $_SESSION['username'] = $row['name'];
            $_SESSION['email'] = $row['email'];
            $conn->query("UPDATE customer SET last_active = NOW() WHERE customer_id = " . (int)$row['customer_id']);
            echo json_encode(['success' => true, 'redirect' => 'customer/customer_dashboard.php']);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'customer_register') {
    require_once 'db_conn.php';
    header('Content-Type: application/json');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (empty($name) || empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Name, email and password are required']);
        exit;
    }
    if ($password !== $confirm) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
        exit;
    }
    
    $check = $conn->prepare('SELECT customer_id FROM customer WHERE email = ? LIMIT 1');
    $check->bind_param('s', $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Email already registered']);
        exit;
    }
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('INSERT INTO customer (name, email, password, phone, address) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('sssss', $name, $email, $hashed, $phone, $address);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Registration successful! You can now log in.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Registration failed. Please try again.']);
    }
    exit;
}

if (isset($_SESSION['user_id']) && $_SESSION['type'] === 'customer') {
    header('Location: customer/customer_dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dwarf Fruit Shop - Quality Seedlings in Toril</title>
    <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body>

<nav class="navbar-zian" id="navbar">
    <div class="navbar-inner">
        <a href="#" class="navbar-brand" aria-label="Dwarf Fruit home">
            <img class="logo" src="img/logo.png" alt="Dwarf Fruit logo">
            <div class="brand-text">Dwarf <span>Fruit Seedlings</span></div>
        </a>
        <ul class="navbar-nav" id="navMenu">
            <li><a href="#home" class="active">Home</a></li>
            <li><a href="#services">Services</a></li>
            <li><a href="#contact">Contact</a></li>
            <li><button type="button" class="btn-about-nav" onclick="openAboutModal()">About</button></li>
            <li><button class="btn-login-nav" onclick="openLoginModal()">Login</button></li>
        </ul>
        <button class="navbar-toggler" onclick="toggleMobileMenu()">
            <span></span><span></span><span></span>
        </button>
    </div>
</nav>

<section class="hero-section" id="home">
    <div class="hero-bg"></div>
    <div class="hero-content">
        <div class="hero-badge">
            <span class="dot"></span>
            Pick-up or delivery
        </div>

        <h1 class="hero-title">
            Grow your own
            <span class="highlight">orchard —</span>
            even on a balcony.
        </h1>

        <p class="hero-subtitle">
            Grafted dwarf fruit trees, ready to bear fruit fast. Jackfruit, durian,
            mangosteen, rambutan and 18 more varieties — delivered around Mindanao.
        </p>

        <div class="hero-buttons">
            <button class="btn-primary" onclick="openLoginModal()">
                Browse all 22 trees
            </button>
            <button class="btn-outline" onclick="openLoginModal('register')">
                Meet the seller
            </button>
        </div>

        <div class="hero-stats">
            <div class="hero-stat">
                <div class="hero-stat-value">22<span class="suffix">+</span></div>
                <span class="hero-stat-label">Species</span>
            </div>
            <div class="hero-stat">
                <div class="hero-stat-value">60<span class="suffix">+</span></div>
                <span class="hero-stat-label">Varieties</span>
            </div>
            <div class="hero-stat">
                <div class="hero-stat-value">Mindanao</div>
                <span class="hero-stat-label">delivery</span>
            </div>
            <div class="hero-stat">
                </div>
                <div class="hero-stat-value">100<span class="suffix">%</span></div>
                </div>
                <span class="hero-stat-label">Grafted</span>
            </div>
        </div>
    </div>
</section>

<section class="section" id="services">
    <div class="section-header">
        <p class="section-label">Our Services</p>
        <h2 class="section-title">Everything You Need to Grow</h2>
        <p class="section-desc">Choose healthy dwarf fruit seedlings and get the guidance you need to grow them successfully at home.</p>
    </div>
    <div class="services-grid">
        <div class="service-card">
            <div class="service-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </div>
            <h3>Healthy Seedlings</h3>
            <p>Strong, carefully selected dwarf fruit seedlings ready for home gardens, patios, and small growing spaces.</p>
        </div>
        <div class="service-card">
            <div class="service-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            </div>
            <h3>Grafted Fruit Trees</h3>
            <p>Grafted varieties that establish quickly and can produce quality fruit sooner with proper care.</p>
        </div>
        <div class="service-card">
            <div class="service-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M12 4v16"/><circle cx="8" cy="10" r="2"/><path d="M6 16l2.5-2.5"/></svg>
            </div>
            <h3>Exotic Varieties</h3>
            <p>Discover distinctive exotic fruit varieties, including dragon fruit and other exciting additions to your collection.</p>
        </div>
        <div class="service-card">
            <div class="service-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
            </div>
            <h3>Citrus Varieties</h3>
            <p>Shop flavorful citrus seedlings such as calamansi and lemon, selected for productive home growing.</p>
        </div>
        <div class="service-card">
            <div class="service-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 12l2 2 4-4"/></svg>
            </div>
            <h3>Other Fruit Varieties</h3>
            <p>Browse additional fruit seedlings and find the right variety for your space, climate, and growing goals.</p>
        </div>
        <div class="service-card">
            <div class="service-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
            </div>
            <h3>Growing Support</h3>
            <p>Get practical guidance on planting, sunlight, watering, and care so your dwarf fruit tree can thrive.</p>
        </div>
    </div>
</section>

<section class="section">
    <div class="section-header">
        <p class="section-label">Why Choose Us</p>
        <h2 class="section-title">Trusted by Toril Since Day One</h2>
    </div>
    <div class="features-grid">
        <div class="feature-item">
            <div class="feature-number">500+</div>
            <h4>Happy Customers</h4>
            <p>Serving students, businesses, and the community.</p>
        <div class="feature-item">
            </div>
        <div class="feature-item">
            <div class="feature-number">100%</div>
            <h4>Quality Guaranteed</h4>
            <p>Premium materials and professional equipment.</p>
        </div>
        <div class="feature-item">
            <div class="feature-number">Best</div>
            <h4>Affordable Rates</h4>
            <p>Fair pricing for healthy seedlings and grafted fruit trees.</p>
        </div>
    </div>
</section>

<section class="cta-section" id="contact">
    <h2>Ready to Place an Order?</h2>
    <p>Sign up or log in to start ordering online. No more long lines!</p>
    <div class="hero-buttons">
        <button class="btn-primary" onclick="openLoginModal('register')">
            Create Account
        </button>
        <button class="btn-outline" onclick="openLoginModal('login')">
            Log In
        </button>
    </div>
    <div style="margin-top: 3rem; color: var(--text-muted); font-size: 0.95rem;">
        <p>📍 Purok 3, Talomo Balite Looban, Davao City</p>
        <p>📞 Contact: 09956143096</p>
        <p>📧  dwarffruit@email.com</p>
    </div>
</section>

<footer class="footer">
    <div class="footer-inner">
        <p>&copy; 2026  Dwarf Fruit Shop. All rights reserved.</p>
        <div class="footer-links">
            <a href="#home">Home</a>
            <a href="#services">Services</a>
            <a href="#contact">Contact</a>
        </div>
    </div>
</footer>

<div class="modal-overlay" id="authModal">
    <div class="modal-card">
        <button class="modal-close" onclick="closeLoginModal()">&times;</button>
        <div class="modal-header">
            <img class="modal-logo" src="img/logo.png" alt="Dwarf Fruit logo">
            <h2 id="modalTitle">Welcome Back</h2>
            <p id="modalSubtitle">Log in to your account</p>
        </div>

        
        <div class="modal-tabs">
            <button class="modal-tab active" id="tabLogin" onclick="switchTab('login')">Login</button>
            <button class="modal-tab" id="tabRegister" onclick="switchTab('register')">Register</button>
        </div>

        
        <div class="alert-msg" id="authAlert"></div>

        
        <form class="modal-form" id="loginForm" onsubmit="handleLogin(event)">
            <div class="form-group">
                <label class="form-label">Username or Email</label>
                <input type="text" name="username" class="form-input" placeholder="Enter your username or email" required>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" class="form-input" placeholder="••••••••" required>
                    <button type="button" class="password-toggle" onclick="togglePassword(this)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-submit">Log In</button>
            <div class="form-footer">
                Don't have an account? <a href="#" onclick="switchTab('register'); return false;">Register here</a>
            </div>
        </form>

        
        <form class="modal-form" id="registerForm" style="display:none;" onsubmit="handleRegister(event)">
            <div class="form-group">
                <label class="form-label">Full Name</label>
                <input type="text" name="name" class="form-input" placeholder="Juan Dela Cruz" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-input" placeholder="email@example.com" required>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-input" placeholder="09XX-XXX-XXXX">
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-input" placeholder="Toril, Davao City">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" class="form-input" placeholder="Min. 6 characters" required minlength="6">
                    <button type="button" class="password-toggle" onclick="togglePassword(this)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password</label>
                <div class="password-wrapper">
                    <input type="password" name="confirm_password" class="form-input" placeholder="Re-enter password" required>
                    <button type="button" class="password-toggle" onclick="togglePassword(this)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-submit">Create Account</button>
            <div class="form-footer">
                Already have an account? <a href="#" onclick="switchTab('login'); return false;">Log in here</a>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="aboutModal" role="dialog" aria-modal="true" aria-labelledby="aboutModalTitle">
    <div class="modal-card about-modal-card">
        <button class="modal-close" onclick="closeAboutModal()" aria-label="Close About">&times;</button>
        <div class="about-modal-heading">
            <h2 id="aboutModalTitle">Dayot Nursery</h2>
            <p class="about-modal-subtitle">Founder &amp; head grafter, Dwarf Fruit Seedlings</p>
        </div>
        <div class="about-story">
            <p>Kamusta! I've been grafting seedlings fruit for 25 years since 2000. We provide high-quality grafted fruit tree seedlings that are carefully handcrafted for healthy growth and early fruit production. From our humble beginnings selling door-to-door, we have grown into an online business serving customers near and far. Our mission is to offer reliable, affordable seedlings that help homes, farms, and gardens thrive.</p>
        </div>
    </div>
</div>

<div id="chatbot">
    
    <button id="chatbot-toggle" onclick="toggleChatbot()" title="Chat with us">
        <svg id="chatbot-icon-open" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            <path d="M8 10h.01M12 10h.01M16 10h.01" stroke-width="2.5"/>
        </svg>
        <svg id="chatbot-icon-close" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
        <span id="chatbot-badge" style="display:none;">1</span>
    </button>

    
    <div id="chatbot-window">
        <div id="chatbot-header">
            <div class="chatbot-header-info">
                <div class="chatbot-avatar" aria-label="AI Assistant">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                        <path d="M8 10h.01M12 10h.01M16 10h.01" stroke-width="2.5"/>
                    </svg>
                </div>
                <div>
                    <div class="chatbot-name">Dwarf Fruit Assistant</div>
                    <div class="chatbot-status"><span class="status-dot"></span> Online</div>
                </div>
            </div>
            <button class="chatbot-close-btn" onclick="toggleChatbot()" title="Close chat">&times;</button>
        </div>
        <div id="chatbot-messages">
            
        </div>
        <div id="chatbot-quick-replies">
            
        </div>
        <form id="chatbot-input-form" onsubmit="sendChatMessage(event)">
            <input type="text" id="chatbot-input" placeholder="Type your message..." autocomplete="off">
            <button type="submit" id="chatbot-send" title="Send">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
            </button>
        </form>
    </div>
</div>

<style>

#chatbot { position: fixed; bottom: 24px; right: 24px; z-index: 9999; font-family: 'Inter', system-ui, -apple-system, sans-serif; }

#chatbot-toggle {
    width: 60px; height: 60px; border-radius: 50%; border: none;
    background: linear-gradient(135deg, #22a06b, #18794e);
    color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 20px rgba(34,160,107,0.4);
    transition: all 0.3s ease; position: relative;
}
#chatbot-toggle:hover { transform: scale(1.08); box-shadow: 0 6px 28px rgba(34,160,107,0.55); }
#chatbot-badge {
    position: absolute; top: -2px; right: -2px; background: #ef4444; color: #fff;
    font-size: 11px; font-weight: 700; width: 20px; height: 20px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; border: 2px solid #0a0a0a;
}

#chatbot-window {
    display: none; position: absolute; bottom: 72px; right: 0;
    width: 380px; max-height: 520px; border-radius: 16px; overflow: hidden;
    background: #ffffff; border: 1px solid #e2e8f0;
    box-shadow: 0 12px 48px rgba(0,0,0,0.12);
    flex-direction: column; animation: chatSlideUp 0.3s ease;
}
#chatbot-window.open { display: flex; }

@keyframes chatSlideUp {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: translateY(0); }
}

#chatbot-header {
    background: linear-gradient(135deg, #22a06b, #18794e);
    padding: 14px 16px; display: flex; align-items: center; justify-content: space-between;
}
.chatbot-header-info { display: flex; align-items: center; gap: 10px; }
.chatbot-avatar {
    width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.2);
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 18px; color: #fff;
}
.chatbot-name { font-weight: 700; font-size: 15px; color: #fff; }
.chatbot-status { font-size: 12px; color: rgba(255,255,255,0.85); display: flex; align-items: center; gap: 5px; }
.status-dot { width: 7px; height: 7px; border-radius: 50%; background: #4ade80; display: inline-block; }
.chatbot-close-btn {
    background: rgba(255,255,255,0.15); border: none; color: #fff; width: 30px; height: 30px;
    border-radius: 50%; font-size: 18px; cursor: pointer; display: flex; align-items: center;
    justify-content: center; transition: background 0.2s;
}
.chatbot-close-btn:hover { background: rgba(255,255,255,0.3); }

#chatbot-messages {
    flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 10px;
    min-height: 260px; max-height: 320px; scroll-behavior: smooth;
    background: #ffffff;
}
#chatbot-messages::-webkit-scrollbar { width: 5px; }
#chatbot-messages::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

.chat-msg { max-width: 85%; padding: 10px 14px; border-radius: 14px; font-size: 13.5px; line-height: 1.55; word-wrap: break-word; }
.chat-msg.bot {
    align-self: flex-start; background: #f1f5f9; color: #1e293b;
    border-bottom-left-radius: 4px;
}
.chat-msg.user {
    align-self: flex-end; background: linear-gradient(135deg, #22a06b, #18794e); color: #fff;
    border-bottom-right-radius: 4px;
}
.chat-msg.bot strong, .chat-msg.bot b { color: #22a06b; }

.chat-typing { align-self: flex-start; display: flex; gap: 4px; padding: 12px 16px; background: #f1f5f9; border-radius: 14px; border-bottom-left-radius: 4px; }
.chat-typing span {
    width: 7px; height: 7px; background: #94a3b8; border-radius: 50%;
    animation: typingBounce 1.2s infinite;
}
.chat-typing span:nth-child(2) { animation-delay: 0.2s; }
.chat-typing span:nth-child(3) { animation-delay: 0.4s; }
@keyframes typingBounce {
    0%, 80%, 100% { transform: translateY(0); opacity: 0.4; }
    40% { transform: translateY(-6px); opacity: 1; }
}

#chatbot-quick-replies {
    padding: 0 16px 8px; display: flex; flex-wrap: wrap; gap: 6px;
    background: #ffffff;
}
.quick-reply-btn {
    background: #ffffff; border: 1.5px solid #22a06b; color: #22a06b; padding: 6px 14px;
    border-radius: 20px; font-size: 12px; cursor: pointer; transition: all 0.2s; font-family: inherit;
    font-weight: 500;
}
.quick-reply-btn:hover { background: #22a06b; color: #fff; }

#chatbot-input-form {
    display: flex; align-items: center; gap: 8px; padding: 10px 12px;
    border-top: 1px solid #f1f5f9; background: #ffffff;
}
#chatbot-input {
    flex: 1; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 20px;
    padding: 9px 16px; color: #1e293b; font-size: 13.5px; outline: none;
    font-family: inherit; transition: border-color 0.2s;
}
#chatbot-input::placeholder { color: #94a3b8; }
#chatbot-input:focus { border-color: #22a06b; background: #ffffff; }
#chatbot-send {
    width: 38px; height: 38px; border-radius: 50%; border: none;
    background: #22a06b; color: #fff; cursor: pointer; display: flex;
    align-items: center; justify-content: center; transition: background 0.2s; flex-shrink: 0;
}
#chatbot-send:hover { background: #18794e; }

@media (max-width: 480px) {
    #chatbot-window { width: calc(100vw - 32px); right: -8px; bottom: 68px; max-height: 70vh; }
    #chatbot-toggle { width: 52px; height: 52px; }
}
</style>

<script>

let chatbotOpen = false;
let chatbotReady = false;
let chatHistory = [];

function toggleChatbot() {
    const win = document.getElementById('chatbot-window');
    const iconOpen = document.getElementById('chatbot-icon-open');
    const iconClose = document.getElementById('chatbot-icon-close');
    const badge = document.getElementById('chatbot-badge');

    chatbotOpen = !chatbotOpen;
    if (chatbotOpen) {
        win.classList.add('open');
        iconOpen.style.display = 'none';
        iconClose.style.display = 'block';
        badge.style.display = 'none';
        if (!chatbotReady) {
            chatbotReady = true;
            setTimeout(() => {
                appendBotMessage("Hello! 👋 Welcome to **Dwarf Fruit Shop**! I'm your AI assistant powered by Gemini.\n\nI can help you with:\n• 🌱 Seedling varieties\n• 🍊 Prices and availability\n• 🪴 Plant care guidance\n• 📦 How to order\n\nWhat would you like to know?",
                    ['Seedling Varieties', 'Prices', 'Plant Care', 'How to Order']);
            }, 400);
        }
        document.getElementById('chatbot-input').focus();
    } else {
        win.classList.remove('open');
        iconOpen.style.display = 'block';
        iconClose.style.display = 'none';
    }
}

function sendChatMessage(e) {
    e.preventDefault();
    const input = document.getElementById('chatbot-input');
    const msg = input.value.trim();
    if (!msg) return;

    appendUserMessage(msg);
    chatHistory.push({ role: 'user', text: msg });
    input.value = '';
    clearQuickReplies();
    showTyping();

    fetch('chatbot_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ message: msg, history: chatHistory.slice(-10) })
    })
    .then(r => r.json())
    .then(data => {
        removeTyping();
        chatHistory.push({ role: 'assistant', text: data.reply });
        appendBotMessage(data.reply, data.quickReplies || []);
    })
    .catch(() => {
        removeTyping();
        appendBotMessage("Sorry, something went wrong. Please try again!", ['Seedling Varieties', 'Prices', 'How to Order']);
    });
}

function appendUserMessage(text) {
    const container = document.getElementById('chatbot-messages');
    const div = document.createElement('div');
    div.className = 'chat-msg user';
    div.textContent = text;
    container.appendChild(div);
    scrollChat();
}

function appendBotMessage(text, quickReplies) {
    const container = document.getElementById('chatbot-messages');
    const div = document.createElement('div');
    div.className = 'chat-msg bot';
    div.innerHTML = formatBotText(text);
    container.appendChild(div);
    scrollChat();

    if (quickReplies && quickReplies.length > 0) {
        showQuickReplies(quickReplies);
    }

    
    if (!chatbotOpen) {
        document.getElementById('chatbot-badge').style.display = 'flex';
    }
}

function formatBotText(text) {
    
    text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    
    text = text.replace(/\n/g, '<br>');
    return text;
}

function showQuickReplies(replies) {
    const container = document.getElementById('chatbot-quick-replies');
    container.innerHTML = '';
    replies.forEach(r => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'quick-reply-btn';
        btn.textContent = r;
        btn.onclick = () => {
            document.getElementById('chatbot-input').value = r;
            sendChatMessage(new Event('submit'));
        };
        container.appendChild(btn);
    });
}

function clearQuickReplies() {
    document.getElementById('chatbot-quick-replies').innerHTML = '';
}

function showTyping() {
    const container = document.getElementById('chatbot-messages');
    const typing = document.createElement('div');
    typing.className = 'chat-typing';
    typing.id = 'typing-indicator';
    typing.innerHTML = '<span></span><span></span><span></span>';
    container.appendChild(typing);
    scrollChat();
}

function removeTyping() {
    const el = document.getElementById('typing-indicator');
    if (el) el.remove();
}

function scrollChat() {
    const container = document.getElementById('chatbot-messages');
    setTimeout(() => container.scrollTop = container.scrollHeight, 50);
}

setTimeout(() => {
    if (!chatbotOpen) {
        document.getElementById('chatbot-badge').style.display = 'flex';
    }
}, 3000);
</script>

<script src="js/main.js?v=<?= time() ?>"></script>
</body>
</html>
