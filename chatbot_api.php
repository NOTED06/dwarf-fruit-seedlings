<?php

ini_set('session.gc_maxlifetime', 86400 * 30);
session_set_cookie_params(86400 * 30);
session_start();
require_once 'db_conn.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$history = $input['history'] ?? [];

if (empty($message)) {
    echo json_encode(['reply' => "Please type a message so I can help you!", 'quickReplies' => []]);
    exit;
}

$geminiKey = getenv('GEMINI_API_KEY') ?: 'YOUR_GEMINI_API_KEY';
$dbKeyRes = $conn->query("SELECT meta_value FROM system_info WHERE meta_field = 'gemini_api_key'");
if ($dbKeyRes && $dbKeyRes->num_rows > 0) {
    $row = $dbKeyRes->fetch_assoc();
    if (!empty($row['meta_value'])) {
        $geminiKey = $row['meta_value'];
    }
}

$geminiModel = 'gemini-3.6-flash';
$dbModelRes = $conn->query("SELECT meta_value FROM system_info WHERE meta_field = 'gemini_model'");
if ($dbModelRes && $dbModelRes->num_rows > 0) {
    $row = $dbModelRes->fetch_assoc();
    if (!empty($row['meta_value'])) {
        $geminiModel = $row['meta_value'];
    }
}

$productInfo = [];
$res = $conn->query("SELECT p.product_name, p.description, p.unit FROM product p WHERE p.is_active = 1 ORDER BY p.product_name ASC");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $entry = $r['product_name'];
        if (!empty($r['description'])) $entry .= ' - ' . $r['description'];
        if (!empty($r['unit'])) $entry .= ' (Unit: ' . $r['unit'] . ')';
        $productInfo[] = $entry;
    }
}

$variantInfo = [];
$vRes = $conn->query("SELECT pv.variant_name, pv.size, pv.price, p.product_name 
    FROM product_variants pv 
    JOIN product p ON pv.product_id = p.product_id 
    WHERE pv.is_active = 1 ORDER BY p.product_name, pv.variant_name");
if ($vRes) {
    while ($v = $vRes->fetch_assoc()) {
        $line = $v['product_name'] . ' > ' . $v['variant_name'];
        if (!empty($v['size'])) $line .= ' (' . $v['size'] . ')';
        if (!empty($v['price'])) $line .= ' - ₱' . number_format($v['price'], 2);
        $variantInfo[] = $line;
    }
}

$orderInfo = '';
$orderCards = [];
$customerId = $_SESSION['user_id'] ?? null;
$customerName = $_SESSION['username'] ?? 'Guest';
if ($customerId) {
    $oRes = $conn->prepare("SELECT product_name, variant_name, size, quantity, price, status, payment_mode, down_payment_amount, created_at, batch_id
        FROM customer_orders WHERE customer_id = ? AND status != 'In Cart' ORDER BY created_at DESC LIMIT 10");
    $oRes->bind_param('i', $customerId);
    $oRes->execute();
    $oResult = $oRes->get_result();
    $orders = [];
    while ($o = $oResult->fetch_assoc()) {
        $orders[] = '- ' . $o['product_name'] 
            . (!empty($o['variant_name']) ? ' (' . $o['variant_name'] . ')' : '')
            . ' x' . $o['quantity'] 
            . ' | ₱' . number_format($o['price'], 2) 
            . ' | Status: ' . $o['status'] 
            . ' | Ordered: ' . date('M j, Y', strtotime($o['created_at']));
        
        $orderCards[] = [
            'product' => $o['product_name'],
            'variant' => $o['variant_name'] ?? '',
            'size' => $o['size'] ?? '',
            'qty' => (int)$o['quantity'],
            'price' => number_format($o['price'], 2),
            'status' => $o['status'],
            'payment' => $o['payment_mode'] ?? '',
            'down' => $o['down_payment_amount'] ? number_format($o['down_payment_amount'], 2) : '',
            'date' => date('M j, Y', strtotime($o['created_at'])),
            'batch' => $o['batch_id'] ?? ''
        ];
    }
    if (!empty($orders)) {
        $orderInfo = "\n\nCURRENT CUSTOMER ORDERS for {$customerName}:\n" . implode("\n", $orders);
    } else {
        $orderInfo = "\n\nThis customer ({$customerName}) has no active orders yet.";
    }
}

$productsList = !empty($productInfo) ? implode("\n", $productInfo) : 'No products loaded';
$variantsList = !empty($variantInfo) ? implode("\n", $variantInfo) : 'No variants loaded';

$systemPrompt = "You are Dwarf Fruit Assistant, a friendly and helpful AI chatbot for Dwarf Fruit Shop located in Toril, Davao City.

ABOUT THE SHOP:
- Dwarf Fruit Shop offers healthy dwarf fruit seedlings and grafted fruit trees in Toril, Davao City
- Available categories are Exotic, Citrus, and Other fruit varieties
- Help customers choose seedlings based on their growing space, sunlight, and fruit preferences
- Business Hours: Monday-Saturday 8:00 AM - 6:00 PM, Sunday Closed
- Contact: 09956143096 | dwarffruit@email.com
- Online orders accepted 24/7 through the website

AVAILABLE PRODUCTS:
{$productsList}

PRODUCT VARIANTS & PRICES:
{$variantsList}

HOW TO ORDER:
1. Create an Account - Register on the website with name, email, and password
2. Browse Products - View available products in the Products/Dashboard page
3. Choose a Seedling - Select an exotic, citrus, or other fruit variety
4. Add to Cart - Select quantity and add to cart
5. Submit Order - Go to My Orders, review cart, and submit
6. Message the Admin - Chat with admin through the Messages page for questions or special requests
7. Payment - Admin will send a payment request via chat. Customer can pay via GCash (down payment or full payment)
8. Order Preparation - Admin prepares the selected seedling for pickup
9. Pickup/Completion - Customer picks up the order, admin marks as Completed

ORDER STATUS FLOW:
In Cart → Processing → Production → Ready for Pickup → Completed
(Orders can also be Cancelled)

PAYMENT OPTIONS:
- GCash payment (down payment or full payment)
- Payment is coordinated through the messaging system
- Down payment: partial payment first, remaining balance due at pickup
- Full payment: pay the complete amount upfront via GCash
{$orderInfo}

RULES:
- Be friendly, concise, and helpful
- Use emojis sparingly to keep it engaging
- If asked about specific pricing or availability, refer to the product and variant information listed above
- Give practical, general plant-care guidance, including sunlight, watering, soil, and spacing
- If a product is not in the list, say we don't currently offer it but they can message the admin
- For order status questions, refer to their order data above
- If the customer is not logged in, encourage them to log in or create an account
- You can understand and respond in both English and Filipino/Bisaya
- Keep responses concise (2-4 short paragraphs max)
- Always suggest relevant quick reply options at the end
- Format quick replies as a JSON array in the last line like: [QUICK_REPLIES: option1, option2, option3]";

$contents = [];

$historySlice = array_slice($history, -10);
foreach ($historySlice as $h) {
    $role = ($h['role'] === 'user') ? 'user' : 'model';
    $contents[] = ['role' => $role, 'parts' => [['text' => $h['text']]]];
}

$contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

$geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/' . $geminiModel . ':generateContent?key=' . urlencode($geminiKey);

$payload = [
    'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
    'contents' => $contents,
    'generationConfig' => [
        'temperature' => 0.7,
        'maxOutputTokens' => 1024,
    ]
];

$ch = curl_init($geminiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

$reply = '';
$quickReplies = ['Services', 'How to Order', 'My Orders', 'Contact'];

if ($curlErr || $httpCode !== 200) {
    if ($httpCode === 403) {
        $reply = "The Gemini AI API key needs to be configured or renewed in Admin > API Settings (Google AI Studio). 🌱";
    } elseif ($httpCode === 404) {
        $reply = "The selected Gemini model ($geminiModel) was not found. Please choose 'gemini-3.6-flash' or 'gemini-3.5-flash' in Admin > API Settings.";
    } else {
        $reply = "I'm having trouble connecting right now. Please try again in a moment! 🔄";
    }
} else {
    $data = json_decode($response, true);
    $text = '';
    if (!empty($data['candidates'][0]['content']['parts'])) {
        foreach ($data['candidates'][0]['content']['parts'] as $part) {
            if (!empty($part['text'])) {
                $text .= $part['text'];
            }
        }
    }
    
    if (empty(trim($text))) {
        $reply = "I'm sorry, I couldn't generate a response. Please try rephrasing your question! 🤔";
    } else {
        
        if (preg_match('/\[QUICK_REPLIES:\s*(.+?)\]\s*$/', $text, $m)) {
            $text = preg_replace('/\[QUICK_REPLIES:\s*.+?\]\s*$/', '', $text);
            $quickReplies = array_map('trim', explode(',', $m[1]));
        }
        $reply = trim($text);
    }
}

$msgLower = strtolower($message);
$isOrderQuery = preg_match('/(track|order|status|progress|my order|orders|aking order|update|where.*order|ano.*status)/i', $msgLower);
$responseData = [
    'reply' => $reply,
    'quickReplies' => $quickReplies
];

if ($isOrderQuery && !empty($orderCards) && $customerId) {
    $responseData['orderCards'] = $orderCards;
}

echo json_encode($responseData);
