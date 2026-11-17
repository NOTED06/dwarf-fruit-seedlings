<?php

$currentPage = basename($_SERVER['PHP_SELF']);
$customerName = $_SESSION['username'] ?? 'Customer';
$customerEmail = $_SESSION['email'] ?? '';
$customerProfilePicture = '';

require_once __DIR__ . '/../notifications_helper.php';
require_once __DIR__ . '/../seen_helper.php';

$_custId = $_SESSION['user_id'] ?? 0;
if (isset($conn) && $_custId) {
    $profileStmt = $conn->prepare('SELECT profile_picture FROM customer WHERE customer_id = ? LIMIT 1');
    $profileStmt->bind_param('i', $_custId);
    $profileStmt->execute();
    $customerProfilePicture = (string)($profileStmt->get_result()->fetch_assoc()['profile_picture'] ?? '');
}

$_custSectionMap = [
    'customer_dashboard.php' => 'products',
    'customer_orders.php'    => 'orders',
    'customer_messages.php'  => 'messages'
];
if (isset($conn) && $_custId && isset($_custSectionMap[$currentPage])) {
    markSectionSeen($conn, 'customer', $_custId, $_custSectionMap[$currentPage]);
}

$_custBadges = ['products' => 0, 'orders' => 0, 'messages' => 0];
if (isset($conn) && $_custId) {
    $_custBadges = getCustomerBadgeCounts($conn, $_custId);
}
$productsBadge = $_custBadges['products'];
$ordersBadge   = $_custBadges['orders'];
$msgCount      = $_custBadges['messages'];

$custNotifCount = 0;
if (isset($conn) && $_custId) {
    $custNotifCount = getUnreadNotifCount($conn, 'customer', $_custId);
}

$cartCount = 0;
if (isset($conn) && $_custId) {
    $cartCount = (int)$conn->query("SELECT COUNT(*) as c FROM customer_orders WHERE customer_id = $_custId AND status = 'In Cart'")->fetch_assoc()['c'];
}
?>

<nav class="customer-navbar">
    <a href="customer_dashboard.php" class="navbar-brand">
        <img class="customer-brand-logo" src="../img/logo.png" alt="Dwarf Fruit logo">
        <div class="brand-text">Dwarf <span class="brand-sub">Fruit Seedlings</span></div>
    </a>

    
    <div class="nav-links desktop-nav-links">
        <a href="customer_dashboard.php" class="nav-item-link <?= $currentPage === 'customer_dashboard.php' ? 'active' : '' ?>">Home</a>
        <a href="customer_shop.php" class="nav-item-link <?= $currentPage === 'customer_shop.php' ? 'active' : '' ?>">Browse Products</a>
        <div class="nav-dropdown-wrap">
            <a href="customer_orders.php" class="nav-item-link <?= ($currentPage === 'customer_orders.php' || $currentPage === 'customer_messages.php') ? 'active' : '' ?>" style="display:flex; align-items:center; gap:4px;">
                <?= $currentPage === 'customer_orders.php' ? 'My Orders' : ($currentPage === 'customer_messages.php' ? 'Chat Support' : 'Pages') ?>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </a>
            <div class="nav-dropdown-menu">
                <a href="customer_orders.php" class="nav-dropdown-item">My Orders</a>
                <a href="customer_messages.php" class="nav-dropdown-item">Chat Support</a>
            </div>
        </div>
    </div>

    <div class="nav-right">
        
        <div class="search-box">
            <svg width="14" height="14" fill="none" stroke="#666" stroke-width="2.5" viewBox="0 0 24 24">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" id="navbarSearchInput" placeholder="Search..." oninput="handleNavbarSearch(this.value)">
        </div>

        
        <div id="custNotifBellWrap" style="position:relative;">
            <button onclick="toggleCustNotif(event)" class="nav-icon-btn" title="Notifications">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/>
                </svg>
                <span id="custNotifBadge" style="display:<?= $custNotifCount > 0 ? 'flex' : 'none' ?>;"><?= $custNotifCount ?></span>
            </button>
            <div id="custNotifDropdown" class="notif-dropdown">
                <div class="notif-dropdown-header">
                    <span>Notifications</span>
                    <button onclick="markAllCustNotifRead()">Mark all read</button>
                </div>
                <div id="custNotifList" class="notif-dropdown-list">
                    <div class="notif-loading">Loading...</div>
                </div>
            </div>
        </div>

        
        <div class="desktop-only-action" style="position:relative;">
            <button onclick="toggleProfileDropdown(event)" class="nav-icon-btn profile-avatar" title="Profile">
                <?php if ($customerProfilePicture): ?><img src="../<?= htmlspecialchars($customerProfilePicture) ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;"><?php else: ?><?= strtoupper(substr($customerName, 0, 1)) ?><?php endif; ?>
            </button>
            <div id="navbarProfileDropdown" class="profile-dropdown">
                <div class="profile-dropdown-user">
                    <div class="profile-avatar large" style="overflow:hidden;"><?php if ($customerProfilePicture): ?><img src="../<?= htmlspecialchars($customerProfilePicture) ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;"><?php else: ?><?= strtoupper(substr($customerName, 0, 1)) ?><?php endif; ?></div>
                    <div class="profile-user-details">
                        <div class="profile-name"><?= htmlspecialchars($customerName) ?></div>
                        <div class="profile-email"><?= htmlspecialchars($customerEmail) ?></div>
                    </div>
                </div>
                <div class="profile-dropdown-divider"></div>
                <ul class="profile-dropdown-links">
                    <li><a href="customer_shop.php">Browse Products</a></li>
                    <li><a href="customer_orders.php">My Orders</a></li>
                    <li><a href="customer_messages.php">Chat Support</a></li>
                    <li><a href="settings.php">Settings</a></li>
                </ul>
                <div class="profile-dropdown-divider"></div>
                <a href="../logout.php" onclick="event.preventDefault(); showConfirm({ title: 'Sign Out?', message: 'Are you sure you want to sign out of your account?', type: 'warning', confirmText: 'Sign Out', onConfirm: () => { window.location.href = this.href; } });" class="profile-logout-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16,17 21,12 16,7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    Sign Out
                </a>
            </div>
        </div>

        
        <button class="navbar-mobile-toggle" onclick="toggleMobileNavbarMenu(event)" title="Toggle Navigation Menu" aria-label="Toggle Navigation">
            <span></span><span></span><span></span>
        </button>
    </div>
</nav>

<div class="mobile-nav-backdrop" id="custMobileBackdrop" onclick="toggleMobileNavbarMenu(event)"></div>

<div class="customer-mobile-drawer" id="custMobileDrawer">
    <div class="mobile-drawer-header">
        <div class="mobile-drawer-user">
            <div class="profile-avatar" style="width:38px; height:38px; font-size:15px; overflow:hidden;">
                <?php if ($customerProfilePicture): ?><img src="../<?= htmlspecialchars($customerProfilePicture) ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;"><?php else: ?><?= strtoupper(substr($customerName, 0, 1)) ?><?php endif; ?>
            </div>
            <div class="mobile-user-details">
                <div class="mobile-user-name"><?= htmlspecialchars($customerName) ?></div>
                <div class="mobile-user-email"><?= htmlspecialchars($customerEmail) ?></div>
            </div>
        </div>
        <button class="mobile-drawer-close" onclick="toggleMobileNavbarMenu(event)" aria-label="Close menu">&times;</button>
    </div>
    <div class="mobile-drawer-links">
        <a href="customer_dashboard.php" class="mobile-nav-link <?= $currentPage === 'customer_dashboard.php' ? 'active' : '' ?>">
            <span class="mobile-nav-icon">🏡</span>
            <span>Home</span>
        </a>
        <a href="customer_shop.php" class="mobile-nav-link <?= $currentPage === 'customer_shop.php' ? 'active' : '' ?>">
            <span class="mobile-nav-icon">🌱</span>
            <span>Browse Products</span>
        </a>
        <a href="customer_orders.php" class="mobile-nav-link <?= $currentPage === 'customer_orders.php' ? 'active' : '' ?>">
            <span class="mobile-nav-icon">📦</span>
            <span>My Orders</span>
            <?php if ($ordersBadge > 0): ?><span class="mobile-badge"><?= $ordersBadge ?></span><?php endif; ?>
        </a>
        <a href="customer_messages.php" class="mobile-nav-link <?= $currentPage === 'customer_messages.php' ? 'active' : '' ?>">
            <span class="mobile-nav-icon">💬</span>
            <span>Chat Support</span>
            <?php if ($msgCount > 0): ?><span class="mobile-badge" style="background:#22c55e;"><?= $msgCount ?></span><?php endif; ?>
        </a>
        <a href="settings.php" class="mobile-nav-link <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
            <span class="mobile-nav-icon">⚙️</span>
            <span>Account Settings</span>
        </a>
    </div>
    <div class="mobile-drawer-footer">
        <a href="../logout.php" onclick="event.preventDefault(); showConfirm({ title: 'Sign Out?', message: 'Are you sure you want to sign out?', type: 'warning', confirmText: 'Sign Out', onConfirm: () => { window.location.href = this.href; } });" class="mobile-logout-link">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16,17 21,12 16,7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign Out
        </a>
    </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>

function handleNavbarSearch(query) {
    const q = query.toLowerCase().trim();
    const cards = document.querySelectorAll('.product-card');
    if (!cards || cards.length === 0) return;
    
    cards.forEach(card => {
        const productName = card.querySelector('h3').textContent.toLowerCase();
        const productDesc = card.querySelector('.product-service-type').textContent.toLowerCase();
        const categoryTag = card.querySelector('.product-category-tag')?.textContent.toLowerCase() || '';
        
        if (productName.includes(q) || productDesc.includes(q) || categoryTag.includes(q)) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('navbarSearchInput');
    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const q = this.value.trim();
                const isDashboard = <?= $currentPage === 'customer_dashboard.php' ? 'true' : 'false' ?>;
                if (!isDashboard && q) {
                    window.location.href = 'customer_dashboard.php?search=' + encodeURIComponent(q);
                }
            }
        });
        
        
        const urlParams = new URLSearchParams(window.location.search);
        const searchVal = urlParams.get('search');
        if (searchVal) {
            searchInput.value = searchVal;
            
            setTimeout(() => {
                handleNavbarSearch(searchVal);
            }, 100);
            searchInput.focus();
        }
    }
});

let profileDropdownOpen = false;
function toggleProfileDropdown(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('navbarProfileDropdown');
    profileDropdownOpen = !profileDropdownOpen;
    dropdown.classList.toggle('show', profileDropdownOpen);
    
    
    if (custNotifOpen) {
        document.getElementById('custNotifDropdown').style.display = 'none';
        custNotifOpen = false;
    }
}

let mobileMenuOpen = false;
function toggleMobileNavbarMenu(event) {
    if (event) event.stopPropagation();
    const drawer = document.getElementById('custMobileDrawer');
    const backdrop = document.getElementById('custMobileBackdrop');
    const toggle = document.querySelector('.navbar-mobile-toggle');
    if (!drawer) return;
    mobileMenuOpen = !mobileMenuOpen;
    drawer.classList.toggle('open', mobileMenuOpen);
    if (backdrop) backdrop.classList.toggle('active', mobileMenuOpen);
    if (toggle) toggle.classList.toggle('active', mobileMenuOpen);
    document.body.style.overflow = mobileMenuOpen ? 'hidden' : '';
}

document.addEventListener('click', function(e) {
    
    const profileDropdown = document.getElementById('navbarProfileDropdown');
    const profileBtn = document.querySelector('.profile-avatar');
    if (profileDropdown && profileBtn && !profileDropdown.contains(e.target) && !profileBtn.contains(e.target) && profileDropdownOpen) {
        profileDropdown.classList.remove('show');
        profileDropdownOpen = false;
    }

    
    const notifDropdown = document.getElementById('custNotifDropdown');
    const notifWrap = document.getElementById('custNotifBellWrap');
    if (notifDropdown && notifWrap && !notifWrap.contains(e.target) && custNotifOpen) {
        notifDropdown.style.display = 'none';
        custNotifOpen = false;
    }
    
    
    const drawer = document.getElementById('custMobileDrawer');
    const mobileToggle = document.querySelector('.navbar-mobile-toggle');
    if (drawer && mobileToggle && !drawer.contains(e.target) && !mobileToggle.contains(e.target) && mobileMenuOpen) {
        toggleMobileNavbarMenu(null);
    }
});

function showToast(title, message, type = 'success') {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        container.id = 'toastContainer';
        document.body.appendChild(container);
    }
    const icons = {
        success: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>',
        error: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        info: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
    };
    const titles = { success: 'Success!', error: 'Error!', info: 'Info' };
    const toast = document.createElement('div');
    toast.className = 'toast-item';
    toast.innerHTML = `
        <div class="toast-icon ${type}">${icons[type] || icons.info}</div>
        <div class="toast-body">
            <div class="toast-title ${type}">${title || titles[type]}</div>
            <div class="toast-msg">${message || ''}</div>
        </div>
        <button class="toast-close" onclick="dismissToast(this)">&times;</button>
    `;
    container.appendChild(toast);
    setTimeout(() => dismissToast(toast.querySelector('.toast-close')), 4000);
}

function dismissToast(btn) {
    const toast = btn.closest ? btn.closest('.toast-item') : btn.parentElement;
    if (!toast || toast.classList.contains('toast-out')) return;
    toast.classList.add('toast-out');
    setTimeout(() => toast.remove(), 300);
}

function showConfirm(options) {
    const { title, message, type, confirmText, cancelText, onConfirm } = Object.assign(
        { title:'Are you sure?', message:'', type:'danger', confirmText:'Confirm', cancelText:'Cancel', onConfirm:()=>{} }, options
    );
    const icons = {
        danger: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        warning: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        info: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
        success: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ff6b35" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg>'
    };
    const iconBgs = { danger:'danger', warning:'warning', info:'info', success:'warning' };
    const overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.innerHTML = `
        <div class="confirm-box">
            <div class="confirm-icon ${iconBgs[type] || 'danger'}">${icons[type] || icons.danger}</div>
            <div class="confirm-heading">${title}</div>
            <div class="confirm-text">${message}</div>
            <div class="confirm-btns">
                <button class="confirm-btn confirm-btn-cancel" id="confirmCancelBtn">${cancelText}</button>
                <button class="confirm-btn confirm-btn-ok ${type}" id="confirmOkBtn">${confirmText}</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    const close = () => { overlay.classList.add('confirm-closing'); setTimeout(() => overlay.remove(), 200); };
    overlay.querySelector('#confirmCancelBtn').onclick = close;
    overlay.querySelector('#confirmOkBtn').onclick = () => { close(); onConfirm(); };
    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
}

let custNotifOpen = false;
function toggleCustNotif(event) {
    if (event) event.stopPropagation();
    const dd = document.getElementById('custNotifDropdown');
    custNotifOpen = !custNotifOpen;
    dd.style.display = custNotifOpen ? 'block' : 'none';
    if (custNotifOpen) loadCustNotifications();
    
    
    if (profileDropdownOpen) {
        document.getElementById('navbarProfileDropdown').classList.remove('show');
        profileDropdownOpen = false;
    }
}

function loadCustNotifications() {
    fetch('notifications_api.php?action=list')
        .then(r => r.json())
        .then(notifs => {
            const list = document.getElementById('custNotifList');
            if (notifs.length === 0) {
                list.innerHTML = '<div style="padding:2rem; text-align:center; color:hsl(215 16% 47%); font-size:0.8rem;">No notifications yet</div>';
                return;
            }
            list.innerHTML = notifs.map(n => {
                const iconColor = n.type === 'status' ? '#22c55e' : n.type === 'message' ? '#3b82f6' : '#ff6b35';
                const icon = n.type === 'status'
                    ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>'
                    : n.type === 'message'
                    ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>'
                    : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>';
                const ago = ntTimeAgo(n.created_at);
                const unreadBg = n.is_read == 0 ? `background:hsl(210 20% 97%);` : '';
                return `<a href="${n.link || '#'}" style="display:flex; align-items:flex-start; gap:10px; padding:10px 16px; border-bottom:1px solid hsl(214 32% 93%); text-decoration:none; transition:background 0.2s; ${unreadBg}" onmouseover="this.style.background='hsl(210 20% 96%)'" onmouseout="this.style.background='${n.is_read == 0 ? 'hsl(210 20% 97%)' : 'transparent'}'">
                    <div style="flex-shrink:0; width:28px; height:28px; border-radius:50%; background:${iconColor}; display:flex; align-items:center; justify-content:center;">${icon}</div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:0.75rem; font-weight:700; color:hsl(222 47% 11%); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${ntEsc(n.title)}</div>
                        <div style="font-size:0.7rem; color:hsl(215 16% 47%); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${ntEsc(n.message)}</div>
                        <div style="font-size:0.6rem; color:hsl(215 16% 60%); margin-top:2px;">${ago}</div>
                    </div>
                    ${n.is_read == 0 ? '<div style="width:7px; height:7px; border-radius:50%; background:#ff6b35; flex-shrink:0; margin-top:6px;"></div>' : ''}
                </a>`;
            }).join('');
        });
}

function markAllCustNotifRead() {
    fetch('notifications_api.php?action=mark_read')
        .then(r => r.json())
        .then(() => {
            document.getElementById('custNotifBadge').style.display = 'none';
            loadCustNotifications();
        });
}

function pollCustNotifCount() {
    if (document.hidden) return;
    fetch('notifications_api.php?action=count')
        .then(r => r.json())
        .then(data => {
            const badge = document.getElementById('custNotifBadge');
            if (data.count > 0) {
                badge.textContent = data.count > 99 ? '99+' : data.count;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }).catch(() => {});
}
setInterval(pollCustNotifCount, 20000);

function ntTimeAgo(dateStr) {
    const now = new Date();
    const d = new Date(dateStr);
    const diff = Math.floor((now - d) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
}
function ntEsc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function pollCustSidebarBadges() {
    if (document.hidden) return;
    fetch('poll_api.php?action=sidebar_badges')
        .then(r => r.json())
        .then(data => {
            updateCustBadge('custBadgeProducts', data.products, '#8b5cf6');
            updateCustBadge('custBadgeOrders', data.orders, '#ff6b35');
            updateCustBadge('custBadgeMessages', data.messages, '#22c55e');
            
            const cartEl = document.getElementById('navCartCount');
            if (cartEl && typeof data.cart_count !== 'undefined') {
                cartEl.textContent = data.cart_count;
            }
        }).catch(() => {});
}
function updateCustBadge(id, count, color) {
    let el = document.getElementById(id);
    if (count > 0) {
        if (!el) {
            const map = { custBadgeProducts: 'Products', custBadgeOrders: 'My Orders', custBadgeMessages: 'Messages' };
            const links = document.querySelectorAll('.nav-links a');
            links.forEach(a => {
                if (a.textContent.trim().includes(map[id])) {
                    el = document.createElement('span');
                    el.id = id;
                    el.className = 'nav-badge';
                    if (color) el.style.background = color;
                    a.appendChild(el);
                }
            });
        }
        if (el) { el.textContent = count > 99 ? '99+' : count; el.style.display = 'inline-flex'; }
    } else if (el) {
        el.style.display = 'none';
    }
}
setInterval(pollCustSidebarBadges, 20000);
</script>

<style>

.search-box {
    display: flex;
    align-items: center;
    border: 1.5px solid #eaeaea;
    border-radius: 50px;
    background: #FAF9F6;
    height: 36px;
    box-sizing: border-box;
    width: 36px;
    padding: 0 10px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    cursor: pointer;
    overflow: hidden;
}
.search-box svg {
    flex-shrink: 0;
    cursor: pointer;
    transition: all 0.25s;
    stroke: #666666;
}
.search-box input {
    border: none;
    background: transparent;
    outline: none;
    font-size: 12px;
    width: 0;
    opacity: 0;
    font-family: inherit;
    color: #111111;
    padding: 0;
    transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.2s;
}
.search-box:hover,
.search-box:focus-within {
    width: 190px;
    padding: 0 12px;
    border-color: #22a06b;
    background: #ffffff;
    box-shadow: 0 3px 10px rgba(34,160,107,0.12);
}
.search-box:hover input,
.search-box:focus-within input {
    width: 100%;
    opacity: 1;
    margin-left: 8px;
}
.search-box:hover svg,
.search-box:focus-within svg {
    stroke: #22a06b;
}

@keyframes bell-shake {
    0%, 100% { transform: rotate(0); }
    15% { transform: rotate(-14deg); }
    30% { transform: rotate(12deg); }
    45% { transform: rotate(-10deg); }
    60% { transform: rotate(8deg); }
    75% { transform: rotate(-4deg); }
    90% { transform: rotate(2deg); }
}

.nav-icon-btn {
    width: 36px;
    height: 36px;
    min-width: 36px;
    border-radius: 50% !important;
    border: 1.5px solid #eaeaea !important;
    background: #FAF9F6 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer;
    position: relative;
    padding: 0 !important;
    margin: 0;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    color: #555555;
    outline: none !important;
    box-sizing: border-box;
    flex-shrink: 0;
}
.nav-icon-btn:hover {
    border-color: #22a06b !important;
    background: #ffffff !important;
    color: #22a06b !important;
    box-shadow: 0 3px 10px rgba(34, 160, 107, 0.15);
    transform: translateY(-1px);
}
.nav-icon-btn:active {
    transform: translateY(0);
}
.nav-icon-btn svg {
    color: #555555;
    transition: color 0.2s;
    display: block;
}
.nav-icon-btn:hover svg {
    color: #22a06b;
    animation: bell-shake 0.6s ease-in-out;
}

.profile-avatar.nav-icon-btn {
    width: 36px !important;
    height: 36px !important;
    min-width: 36px !important;
    border-radius: 50% !important;
    border: 1.5px solid transparent !important;
    background: #22a06b !important;
    color: #ffffff !important;
    font-weight: 800;
    font-size: 14px;
    overflow: hidden;
    padding: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    box-shadow: 0 2px 6px rgba(34, 160, 107, 0.2);
}
.profile-avatar.nav-icon-btn:hover {
    background: #18794e !important;
    border-color: #18794e !important;
    box-shadow: 0 4px 12px rgba(34, 160, 107, 0.35);
    transform: translateY(-1px);
}
.profile-avatar.nav-icon-btn img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
    display: block;
}

#custNotifBadge {
    position: absolute;
    top: -3px;
    right: -3px;
    background: #ef4444;
    color: #ffffff;
    font-size: 10px;
    font-weight: 800;
    min-width: 17px;
    height: 17px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    box-shadow: 0 2px 6px rgba(239, 68, 68, 0.4);
    border: 2px solid #ffffff;
    line-height: 1;
    box-sizing: border-box;
    z-index: 2;
}

.notif-dropdown,
.profile-dropdown {
    border-radius: 16px !important;
    border: 1px solid #eaeaea !important;
    box-shadow: 0 14px 40px rgba(0, 0, 0, 0.12) !important;
    margin-top: 10px !important;
    background: #ffffff !important;
}

.customer-navbar {
    position: sticky;
    top: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 36px;
    background: #ffffff;
    border-bottom: 1px solid #eaeaea;
    width: 100%;
    box-sizing: border-box;
}
.customer-brand-logo {
    height: 38px;
    width: auto;
    object-fit: contain;
}
.navbar-brand {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    flex-shrink: 0;
}
.brand-text {
    font-size: 1.15rem;
    font-weight: 800;
    color: #111;
    letter-spacing: -0.02em;
    white-space: nowrap;
}
.brand-text span {
    font-weight: 500;
    color: #ff6b35;
}
.desktop-nav-links {
    display: flex;
    gap: 32px;
    align-items: center;
}
.nav-item-link {
    font-size: 12px;
    font-weight: 700;
    color: #000;
    letter-spacing: 0.06em;
    text-decoration: none;
    padding-bottom: 4px;
    border-bottom: 2px solid transparent;
    text-transform: uppercase;
    transition: color 0.2s;
}
.nav-item-link:hover,
.nav-item-link.active {
    color: #ff6b35;
    border-bottom-color: #ff6b35;
}
.nav-right {
    display: flex;
    align-items: center;
    gap: 10px;
}
.navbar-mobile-toggle {
    display: none;
    flex-direction: column;
    justify-content: space-between;
    width: 22px;
    height: 16px;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    margin-left: 6px;
    z-index: 1010;
}
.navbar-mobile-toggle span {
    width: 100%;
    height: 2px;
    background: #111;
    border-radius: 2px;
    transition: all 0.25s ease;
}
.navbar-mobile-toggle.active span:nth-child(1) {
    transform: translateY(7px) rotate(45deg);
}
.navbar-mobile-toggle.active span:nth-child(2) {
    opacity: 0;
}
.navbar-mobile-toggle.active span:nth-child(3) {
    transform: translateY(-7px) rotate(-45deg);
}

.mobile-nav-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(2px);
    z-index: 9990;
    opacity: 0;
    transition: opacity 0.25s ease;
}
.mobile-nav-backdrop.active {
    display: block;
    opacity: 1;
}
.customer-mobile-drawer {
    position: fixed;
    top: 0;
    right: 0;
    width: 280px;
    max-width: 84vw;
    height: 100%;
    background: #ffffff;
    z-index: 9999;
    box-shadow: -8px 0 30px rgba(0,0,0,0.15);
    display: flex;
    flex-direction: column;
    transform: translateX(100%);
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-sizing: border-box;
}
.customer-mobile-drawer.open {
    transform: translateX(0);
}
.mobile-drawer-header {
    padding: 18px 16px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #fafafa;
}
.mobile-drawer-user {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.mobile-user-details {
    min-width: 0;
}
.mobile-user-name {
    font-size: 0.9rem;
    font-weight: 700;
    color: #111;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.mobile-user-email {
    font-size: 0.72rem;
    color: #888;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.mobile-drawer-close {
    background: none;
    border: none;
    font-size: 1.6rem;
    color: #888;
    cursor: pointer;
    line-height: 1;
    padding: 2px 6px;
    border-radius: 6px;
}
.mobile-drawer-links {
    padding: 14px 10px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
    overflow-y: auto;
}
.mobile-nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    text-decoration: none;
    color: #222;
    font-size: 0.88rem;
    font-weight: 600;
    border-radius: 10px;
    transition: background 0.15s, color 0.15s;
    position: relative;
}
.mobile-nav-link:hover,
.mobile-nav-link.active {
    background: #fff5f0;
    color: #ff6b35;
}
.mobile-nav-icon {
    font-size: 1.1rem;
}
.mobile-badge {
    margin-left: auto;
    background: #ff6b35;
    color: #fff;
    font-size: 0.65rem;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 10px;
}
.mobile-drawer-footer {
    padding: 16px 18px;
    border-top: 1px solid #f0f0f0;
}
.mobile-logout-link {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #ef4444;
    text-decoration: none;
    font-size: 0.88rem;
    font-weight: 600;
    padding: 8px 0;
}

@media (max-width: 768px) {
    .customer-navbar {
        position: -webkit-sticky !important;
        position: sticky !important;
        top: 0 !important;
        left: 0 !important;
        width: 100% !important;
        z-index: 1000 !important;
        background: #ffffff !important;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05) !important;
        padding: 10px 14px;
    }
    .desktop-nav-links {
        display: none !important;
    }
    .desktop-only-action {
        display: none !important; 
    }
    .navbar-mobile-toggle {
        display: flex !important;
    }
    .brand-text {
        font-size: 1.05rem;
    }
    .search-box {
        height: 32px;
        width: 32px;
        padding: 0 8px;
    }
    .search-box:hover,
    .search-box:focus-within {
        width: 130px;
    }
    .nav-icon-btn {
        width: 32px !important;
        height: 32px !important;
        min-width: 32px !important;
    }
    .nav-right {
        gap: 8px !important;
    }
}
@media (max-width: 480px) {
    .brand-sub {
        display: none;
    }
    .customer-navbar {
        padding: 8px 10px;
    }
    .nav-right {
        gap: 6px !important;
    }
}
</style>
