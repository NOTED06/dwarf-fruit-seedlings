<?php
ini_set('session.gc_maxlifetime', 86400 * 30);
session_set_cookie_params(86400 * 30);
session_start();
require_once '../db_conn.php';
require_once 'check_admin.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_product') {
    header('Content-Type: application/json');
    $id = (int)$_POST['product_id'];
    $name = trim($_POST['product_name']);
    $desc = trim($_POST['description']);
    $unit = trim($_POST['unit'] ?? '');
    $quantity = max(0, (int)($_POST['quantity'] ?? 0));
    $isActive = (int)$_POST['is_active'];
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);

    if (isset($_FILES['img']) && $_FILES['img']['error'] === 0) {
        $uploadDir = '../img/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['img']['name'], PATHINFO_EXTENSION));
        $filename = 'product_' . $id . '_' . time() . '.' . $ext;
        move_uploaded_file($_FILES['img']['tmp_name'], $uploadDir . $filename);
        $img = 'img/uploads/' . $filename;
        $stmt = $conn->prepare("UPDATE product SET category_id=?, product_name=?, description=?, unit=?, quantity=?, is_active=?, price=?, img=? WHERE product_id=?");
        $stmt->bind_param('isssiidsi', $categoryId, $name, $desc, $unit, $quantity, $isActive, $price, $img, $id);
    } else {
        $stmt = $conn->prepare("UPDATE product SET category_id=?, product_name=?, description=?, unit=?, quantity=?, is_active=?, price=? WHERE product_id=?");
        $stmt->bind_param('isssiidi', $categoryId, $name, $desc, $unit, $quantity, $isActive, $price, $id);
    }
    if (!$stmt || !$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Unable to update product: ' . $conn->error]);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_product') {
    $name = trim($_POST['product_name']);
    $desc = trim($_POST['description']);
    $unit = trim($_POST['unit'] ?? '');
    $quantity = max(0, (int)($_POST['quantity'] ?? 0));
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $img = '';

    if (isset($_FILES['img']) && $_FILES['img']['error'] === 0) {
        $uploadDir = '../img/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $ext = pathinfo($_FILES['img']['name'], PATHINFO_EXTENSION);
        $filename = 'product_' . time() . '.' . $ext;
        move_uploaded_file($_FILES['img']['tmp_name'], $uploadDir . $filename);
        $img = 'img/uploads/' . $filename;
    }

    $stmt = $conn->prepare("INSERT INTO product (category_id, product_name, description, unit, quantity, price, img, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt || !$stmt->bind_param('isssids', $categoryId, $name, $desc, $unit, $quantity, $price, $img) || !$stmt->execute()) {
        http_response_code(500);
        exit('Unable to save product: ' . $conn->error);
    }
    header('Location: products.php?msg=added');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_variant') {
    header('Content-Type: application/json');
    $productId = (int)$_POST['product_id'];
    $variantName = trim($_POST['variant_name']);
    $size = trim($_POST['size']);
    $price = (float)$_POST['price'];
    $quantity = max(0, (int)($_POST['quantity'] ?? 0));
    $img = '';

    if (isset($_FILES['variant_img']) && $_FILES['variant_img']['error'] === 0) {
        $uploadDir = '../img/uploads/variants/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['variant_img']['name'], PATHINFO_EXTENSION));
        $filename = 'variant_' . $productId . '_' . time() . '_' . mt_rand(100,999) . '.' . $ext;
        move_uploaded_file($_FILES['variant_img']['tmp_name'], $uploadDir . $filename);
        $img = 'img/uploads/variants/' . $filename;
    }

    $stmt = $conn->prepare("INSERT INTO product_variants (product_id, variant_name, size, price, quantity, image) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt || !$stmt->bind_param('issdis', $productId, $variantName, $size, $price, $quantity, $img) || !$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Unable to add variant: ' . $conn->error]);
        exit;
    }
    echo json_encode(['success' => true, 'variant_id' => $conn->insert_id]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_variant') {
    header('Content-Type: application/json');
    $variantId = (int)($_POST['variant_id'] ?? 0);
    $productId = (int)($_POST['product_id'] ?? 0);
    $variantName = trim($_POST['variant_name'] ?? '');
    $size = trim($_POST['size'] ?? '');
    $price = max(0, (float)($_POST['price'] ?? 0));
    $quantity = max(0, (int)($_POST['quantity'] ?? 0));

    if ($variantId <= 0 || $productId <= 0 || $variantName === '') {
        echo json_encode(['success' => false, 'message' => 'Variant name is required.']);
        exit;
    }

    $img = null;
    if (isset($_FILES['variant_img']) && $_FILES['variant_img']['error'] === 0) {
        $uploadDir = '../img/uploads/variants/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['variant_img']['name'], PATHINFO_EXTENSION));
        $filename = 'variant_' . $productId . '_' . time() . '_' . mt_rand(100,999) . '.' . $ext;
        if (move_uploaded_file($_FILES['variant_img']['tmp_name'], $uploadDir . $filename)) {
            $img = 'img/uploads/variants/' . $filename;
        }
    }

    if ($img !== null) {
        $stmt = $conn->prepare("UPDATE product_variants SET variant_name=?, size=?, price=?, quantity=?, image=? WHERE variant_id=? AND product_id=?");
        if ($stmt) $stmt->bind_param('ssdsiii', $variantName, $size, $price, $quantity, $img, $variantId, $productId);
    } else {
        $stmt = $conn->prepare("UPDATE product_variants SET variant_name=?, size=?, price=?, quantity=? WHERE variant_id=? AND product_id=?");
        if ($stmt) $stmt->bind_param('ssdiii', $variantName, $size, $price, $quantity, $variantId, $productId);
    }

    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database prepare failed: ' . $conn->error]);
        exit;
    }

    $success = $stmt->execute();
    echo json_encode(['success' => $success, 'message' => $success ? '' : 'Unable to update variant: ' . $stmt->error]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_variant') {
    header('Content-Type: application/json');
    $variantId = (int)$_POST['variant_id'];
    $conn->query("DELETE FROM product_variants WHERE variant_id = $variantId");
    echo json_encode(['success' => true]);
    exit;
}

if (isset($_GET['get_variants'])) {
    header('Content-Type: application/json');
    $pid = (int)$_GET['get_variants'];
    $res = $conn->query("SELECT * FROM product_variants WHERE product_id = $pid ORDER BY variant_id ASC");
    $variants = [];
    while ($r = $res->fetch_assoc()) $variants[] = $r;
    echo json_encode($variants);
    exit;
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $conn->query("DELETE FROM product WHERE product_id = $id");
    header('Location: products.php?msg=deleted');
    exit;
}

$categories = [];
$catRes = $conn->query("SELECT * FROM product_category ORDER BY category_id ASC");
while ($r = $catRes->fetch_assoc()) $categories[] = $r;
$categoryLabels = ['Exoctic', 'Citrus', 'Other'];
$categoryNamesById = [];
foreach ($categories as $index => &$category) {
    if ($index < count($categoryLabels)) {
        $category['category_name'] = $categoryLabels[$index];
        $categoryNamesById[$category['category_id']] = $category['category_name'];
    }
}
unset($category);
$categories = array_slice($categories, 0, count($categoryLabels));

$productsRes = $conn->query("SELECT p.*, pc.category_name, (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.product_id AND pv.is_active = 1 AND (COALESCE(p.img, '') = '' OR COALESCE(pv.image, '') <> COALESCE(p.img, ''))) + CASE WHEN COALESCE(p.img, '') <> '' THEN 1 ELSE 0 END as variant_count FROM product p LEFT JOIN product_category pc ON p.category_id = pc.category_id ORDER BY p.product_id DESC");
$products = [];
while ($r = $productsRes->fetch_assoc()) {
    if (isset($categoryNamesById[$r['category_id']])) {
        $r['category_name'] = $categoryNamesById[$r['category_id']];
    }
    $products[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - Dwarf Fruit Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/admin.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50 admin-page">

<?php include 'sidebar.php'; ?>

<main class="ml-64 p-8 min-h-screen">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Products</h1>
            <p class="text-gray-500 text-sm mt-1">Manage your shop services, products & album variants</p>
        </div>
        <button onclick="document.getElementById('addModal').classList.remove('hidden')" class="px-5 py-2.5 rounded-lg text-white text-sm font-semibold" style="background:#ff6b35; box-shadow:0 4px 14px rgba(255,107,53,0.35);">
            + Add Product
        </button>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <script>document.addEventListener('DOMContentLoaded', () => showToast('<?= $_GET['msg'] === 'added' ? 'Product Added' : 'Product Deleted' ?>', '<?= $_GET['msg'] === 'added' ? 'Product has been added successfully.' : 'Product has been deleted successfully.' ?>', 'success'));</script>
    <?php endif; ?>

    
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        <?php if (empty($products)): ?>
            <div class="col-span-full text-center py-16 text-gray-400">
                <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="20" height="20" rx="2"/><path d="M12 8v8m-4-4h8"/></svg>
                <p class="text-lg font-medium">No products yet</p>
                <p class="text-sm">Add your first product to get started</p>
            </div>
        <?php else: ?>
            <?php foreach ($products as $p): ?>
            <div class="bg-white rounded-xl overflow-hidden stat-card">
                <?php if (!empty($p['img'])): ?>
                    <div class="h-40 bg-gray-100 overflow-hidden relative">
                        <img src="../<?= htmlspecialchars($p['img']) ?>" alt="<?= htmlspecialchars($p['product_name']) ?>" class="w-full h-full object-cover">
                        <?php if ($p['variant_count'] > 0): ?>
                        <div class="absolute top-2 right-2 px-2 py-1 rounded-full text-xs font-bold text-white" style="background:#ff6b35;">
                            <?= $p['variant_count'] ?> variant<?= $p['variant_count'] > 1 ? 's' : '' ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="h-40 bg-gray-100 flex items-center justify-center relative">
                        <svg class="w-12 h-12 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                        <?php if ($p['variant_count'] > 0): ?>
                        <div class="absolute top-2 right-2 px-2 py-1 rounded-full text-xs font-bold text-white" style="background:#ff6b35;">
                            <?= $p['variant_count'] ?> variant<?= $p['variant_count'] > 1 ? 's' : '' ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="p-4">
                    <?php if (!empty($p['category_name'])): ?>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-orange-50 text-orange-600 mb-1 inline-block"><?= htmlspecialchars($p['category_name']) ?></span>
                    <?php endif; ?>
                    <h3 class="font-semibold text-gray-900"><?= htmlspecialchars($p['product_name']) ?></h3>
                    <p class="text-sm text-gray-500 mt-1 line-clamp-2"><?= htmlspecialchars($p['description'] ?? 'No description') ?></p>
                    <?php if (!empty($p['price']) && $p['price'] > 0): ?>
                    <p class="text-sm font-bold mt-1" style="color:#ff6b35;">₱<?= number_format($p['price'], 2) ?></p>
                    <?php endif; ?>
                    <p class="text-xs font-medium mt-2 <?= (int)($p['quantity'] ?? 0) > 0 ? 'text-green-600' : 'text-red-500' ?>">
                        Available stock: <?= (int)($p['quantity'] ?? 0) ?>
                    </p>
                    <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-100">
                        <span class="text-xs px-2 py-1 rounded-full <?= $p['is_active'] ? 'bg-green-50 text-green-600' : 'bg-gray-100 text-gray-500' ?>">
                            <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                        <div class="flex items-center gap-3">
                            <button onclick="openEditModal(<?= $p['product_id'] ?>, <?= htmlspecialchars(json_encode($p['product_name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($p['description'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($p['unit'] ?? ''), ENT_QUOTES) ?>, <?= (int)$p['is_active'] ?>, <?= htmlspecialchars(json_encode($p['img'] ?? ''), ENT_QUOTES) ?>, <?= (int)($p['category_id'] ?? 0) ?>, <?= (float)($p['price'] ?? 0) ?>, <?= (int)($p['quantity'] ?? 0) ?>)" class="text-xs font-semibold text-blue-500 hover:text-blue-700">
                                <svg class="inline w-3.5 h-3.5 mr-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Edit
                            </button>
                            <button onclick="openAlbumModal(<?= $p['product_id'] ?>, '<?= htmlspecialchars($p['product_name'], ENT_QUOTES) ?>')" class="text-xs font-semibold hover:opacity-80" style="color:#ff6b35;">
                                <svg class="inline w-3.5 h-3.5 mr-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 3v18"/></svg>
                                Album
                            </button>
                            <a href="javascript:void(0)" onclick="showConfirm({title:'Delete Product?',message:'This will permanently delete this product and all its variants. This action cannot be undone.',type:'danger',confirmText:'Delete',onConfirm:()=>{window.location='products.php?delete=<?= $p['product_id'] ?>';}})" class="text-xs text-red-400 hover:text-red-600">Delete</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<div id="addModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center">
    <div class="bg-white rounded-2xl w-full max-w-lg p-8 mx-4 shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-gray-900">Add New Product</h2>
            <button onclick="document.getElementById('addModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <form method="POST" action="products.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_product">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                    <select name="category_id" required class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm bg-white">
                        <option value="0">-- Select Category --</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Product Name</label>
                    <input type="text" name="product_name" required placeholder="e.g., Santol, Macopa, Tambis" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" rows="2" placeholder="e.g., Fresh and juicy fruit" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm resize-none"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Price (₱)</label>
                        <input type="number" name="price" step="0.01" min="0" placeholder="0.00" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                        <div style="display:flex;align-items:center;gap:4px;">
                            <button type="button" onclick="adjQty('newQty',-1)" style="width:32px;height:38px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:1.1rem;cursor:pointer;flex-shrink:0;">-</button>
                            <input type="number" name="quantity" id="newQty" value="0" min="0" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm text-center" style="min-width:0;">
                            <button type="button" onclick="adjQty('newQty',1)" style="width:32px;height:38px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:1.1rem;cursor:pointer;flex-shrink:0;">+</button>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reference / Cover Photo</label>
                    <input type="file" name="img" accept="image/*" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-orange-50 file:text-orange-600 hover:file:bg-orange-100">
                    <p class="text-xs text-gray-400 mt-1">Main reference photo. Add more seed photos via Album after saving.</p>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')" class="flex-1 py-2.5 px-4 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="flex-1 py-2.5 px-4 rounded-lg text-sm font-semibold text-white" style="background:#ff6b35;">Add Product</button>
            </div>
        </form>
    </div>
</div>

<div id="editModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center">
    <div class="bg-white rounded-2xl w-full max-w-lg p-8 mx-4 shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-gray-900">Edit Product</h2>
            <button onclick="document.getElementById('editModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <input type="hidden" id="editProductId">
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                <select id="editProductCategory" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm bg-white">
                    <option value="0">-- Select Category --</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Product Name</label>
                <input type="text" id="editProductName" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea id="editProductDesc" rows="2" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm resize-none"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price (₱)</label>
                    <input type="number" id="editProductPrice" step="0.01" min="0" placeholder="0.00" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <button type="button" onclick="adjQty('editProductQty',-1)" style="width:32px;height:38px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:1.1rem;cursor:pointer;flex-shrink:0;">-</button>
                        <input type="number" id="editProductQty" value="0" min="0" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm text-center" style="min-width:0;">
                        <button type="button" onclick="adjQty('editProductQty',1)" style="width:32px;height:38px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:1.1rem;cursor:pointer;flex-shrink:0;">+</button>
                    </div>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Reference / Cover Photo</label>
                <div id="editImgPreview" class="mb-2" style="display:none;">
                    <img id="editImgThumb" class="w-20 h-20 object-cover rounded-lg border border-gray-200">
                </div>
                <input type="file" id="editProductImg" accept="image/*" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-orange-50 file:text-orange-600 hover:file:bg-orange-100">
                <p class="text-xs text-gray-400 mt-1">Leave empty to keep the current image</p>
            </div>
        </div>
        <div class="flex gap-3 mt-6">
            <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="flex-1 py-2.5 px-4 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
            <button type="button" onclick="saveProduct()" id="saveProductBtn" class="flex-1 py-2.5 px-4 rounded-lg text-sm font-semibold text-white" style="background:#ff6b35;">Save Changes</button>
        </div>
    </div>
</div>

<div id="albumModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-5xl p-6 sm:p-8 mx-auto shadow-2xl max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-4 border-b border-gray-100 mb-6">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white shadow-sm" style="background:linear-gradient(135deg,#22a06b,#18794e);">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-gray-900">Seedling Variants & Photo Album</h2>
                    <p class="text-xs text-gray-500 mt-0.5">Product: <span id="albumProductName" class="font-bold text-green-700"></span></p>
                </div>
            </div>
            <button onclick="closeAlbumModal()" class="text-gray-400 hover:text-gray-600 text-2xl w-9 h-9 rounded-full flex items-center justify-center hover:bg-gray-100 transition-colors">&times;</button>
        </div>

        
        <div class="bg-gradient-to-br from-green-50/50 to-emerald-50/30 rounded-2xl p-5 mb-8 border border-green-100/80 shadow-xs">
            <div class="flex items-center gap-2 mb-4">
                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                <h3 class="text-sm font-bold text-gray-800">Add New Variety / Size</h3>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3.5">
                <input type="hidden" id="albumProductId">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Variant Name <span class="text-red-500">*</span></label>
                    <input type="text" id="variantName" placeholder="e.g., Red, Yellow, Grafted" class="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500/20 focus:border-green-500 transition-all">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Size / Growth Stage</label>
                    <input type="text" id="variantSize" placeholder="e.g., Seedling (1-2 ft)" class="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500/20 focus:border-green-500 transition-all">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Price (₱)</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400 text-sm font-semibold">₱</span>
                        <input type="number" id="variantPrice" step="0.01" min="0" placeholder="0.00" class="w-full pl-7 pr-3 py-2 bg-white border border-gray-200 rounded-lg text-sm text-gray-800 font-medium placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500/20 focus:border-green-500 transition-all">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Initial Stock</label>
                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="adjQty('variantQuantity',-1)" class="w-9 h-9 border border-gray-200 rounded-lg bg-white hover:bg-gray-50 text-gray-600 font-bold flex items-center justify-center text-sm transition-colors">−</button>
                        <input type="number" id="variantQuantity" value="10" min="0" class="w-full h-9 px-2 text-center bg-white border border-gray-200 rounded-lg text-sm text-gray-800 font-semibold focus:outline-none focus:border-green-500 transition-all">
                        <button type="button" onclick="adjQty('variantQuantity',1)" class="w-9 h-9 border border-gray-200 rounded-lg bg-white hover:bg-gray-50 text-gray-600 font-bold flex items-center justify-center text-sm transition-colors">+</button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Reference Photo</label>
                    <input type="file" id="variantImg" accept="image/*" class="w-full text-xs text-gray-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-green-100 file:text-green-700 hover:file:bg-green-200 cursor-pointer">
                </div>
            </div>
            <div class="mt-4 flex justify-end">
                <button type="button" onclick="addVariant()" id="addVariantBtn" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-white text-sm font-semibold shadow-xs hover:shadow transition-all" style="background:linear-gradient(135deg,#22a06b,#18794e);">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                    <span>Add Variant</span>
                </button>
            </div>
        </div>

        
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                <span>Existing Varieties</span>
                <span id="variantCountBadge" class="px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600">0 items</span>
            </h3>
        </div>

        
        <div id="variantsGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        </div>
        <div id="noVariants" class="text-center py-16 bg-gray-50/50 rounded-2xl border border-dashed border-gray-200" style="display:none;">
            <div class="w-14 h-14 mx-auto mb-3 rounded-full bg-gray-100 flex items-center justify-center text-gray-400">
                <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
            </div>
            <p class="text-sm font-bold text-gray-700">No varieties yet</p>
            <p class="text-xs text-gray-400 mt-1 max-w-xs mx-auto">Add your seedling varieties, grafted branches, or sizes above to display them for customers.</p>
        </div>
    </div>
</div>

<div id="editVariantModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-[60] flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-lg p-6 sm:p-7 shadow-2xl animate-fade-in relative">
        <div class="flex items-center justify-between pb-3.5 border-b border-gray-100 mb-5">
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl bg-green-50 text-green-600 flex items-center justify-center">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-gray-900">Edit Seedling Variant</h3>
                    <p class="text-xs text-gray-500">Update photo, price, and stock quantity</p>
                </div>
            </div>
            <button type="button" onclick="closeEditVariantModal()" class="text-gray-400 hover:text-gray-600 w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100 transition-colors">&times;</button>
        </div>

        <input type="hidden" id="modalEditVariantId">

        <div class="space-y-4">
            
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Variant Photo</label>
                <div class="flex items-center gap-4 p-3 bg-gray-50 rounded-xl border border-gray-200/80">
                    <div class="w-20 h-20 rounded-xl bg-white border border-gray-200 overflow-hidden flex-shrink-0 flex items-center justify-center shadow-xs relative group">
                        <img id="modalEditVariantThumb" class="w-full h-full object-cover hidden">
                        <div id="modalEditVariantNoThumb" class="text-gray-300 flex flex-col items-center justify-center">
                            <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <label for="modalEditVariantImg" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-semibold bg-white text-gray-700 border border-gray-300 hover:border-green-500 hover:text-green-600 cursor-pointer shadow-2xs transition-colors">
                            <svg class="w-4 h-4 text-green-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                            <span>Choose New Photo</span>
                        </label>
                        <input type="file" id="modalEditVariantImg" accept="image/*" class="hidden" onchange="previewVariantImage(this)">
                        <p id="modalEditVariantFileName" class="text-[11px] text-gray-500 mt-1.5 truncate">Keep existing photo, or pick a replacement</p>
                    </div>
                </div>
            </div>

            
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Variant / Variety Name <span class="text-red-500">*</span></label>
                <input type="text" id="modalEditVariantName" required placeholder="e.g., Yellow Dragon Fruit" class="w-full px-3.5 py-2.5 bg-white border border-gray-300 rounded-xl text-sm text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500/20 focus:border-green-500 transition-all">
            </div>

            
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Size / Growth Stage <span class="text-gray-400 font-normal">(optional)</span></label>
                <input type="text" id="modalEditVariantSize" placeholder="e.g., Seedling (1-2 ft), Grafted (2-3 ft), Pot (1 ft)" class="w-full px-3.5 py-2.5 bg-white border border-gray-300 rounded-xl text-sm text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500/20 focus:border-green-500 transition-all">
            </div>

            
            <div class="grid grid-cols-2 gap-3.5">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Price (₱) <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-500 text-sm font-semibold">₱</span>
                        <input type="number" id="modalEditVariantPrice" step="0.01" min="0" required placeholder="0.00" class="w-full pl-8 pr-3.5 py-2.5 bg-white border border-gray-300 rounded-xl text-sm text-gray-800 font-medium placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500/20 focus:border-green-500 transition-all">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Stock Quantity</label>
                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="adjQty('modalEditVariantQty', -1)" class="w-10 h-10 border border-gray-300 rounded-xl bg-white hover:bg-gray-50 text-gray-600 font-bold flex items-center justify-center text-sm transition-colors">−</button>
                        <input type="number" id="modalEditVariantQty" min="0" step="1" value="0" class="w-full h-10 px-2 text-center bg-white border border-gray-300 rounded-xl text-sm text-gray-800 font-bold focus:outline-none focus:border-green-500 transition-all">
                        <button type="button" onclick="adjQty('modalEditVariantQty', 1)" class="w-10 h-10 border border-gray-300 rounded-xl bg-white hover:bg-gray-50 text-gray-600 font-bold flex items-center justify-center text-sm transition-colors">+</button>
                    </div>
                </div>
            </div>
        </div>

        
        <div class="flex gap-3 pt-5 mt-6 border-t border-gray-100">
            <button type="button" onclick="closeEditVariantModal()" class="flex-1 py-2.5 px-4 border border-gray-200 rounded-xl text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">Cancel</button>
            <button type="button" onclick="submitEditVariantModal()" id="modalEditVariantSaveBtn" class="flex-1 py-2.5 px-4 rounded-xl text-sm font-semibold text-white shadow-sm hover:shadow transition-all" style="background: linear-gradient(135deg, #22a06b, #18794e);">Save Changes</button>
        </div>
    </div>
</div>

<script>
let currentAlbumProductId = null;
let _cachedVariants = {};

function openAlbumModal(productId, productName) {
    currentAlbumProductId = productId;
    document.getElementById('albumProductId').value = productId;
    document.getElementById('albumProductName').textContent = productName;
    document.getElementById('albumModal').classList.remove('hidden');
    loadVariants(productId);
}

function closeAlbumModal() {
    document.getElementById('albumModal').classList.add('hidden');
    location.reload();
}

document.getElementById('albumModal').addEventListener('click', function(e) {
    if (e.target === this) closeAlbumModal();
});

document.getElementById('editVariantModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditVariantModal();
});

function loadVariants(productId) {
    const grid = document.getElementById('variantsGrid');
    const noMsg = document.getElementById('noVariants');
    const countBadge = document.getElementById('variantCountBadge');
    grid.innerHTML = '<div class="col-span-full text-center py-10 text-gray-400 text-sm">Loading variants...</div>';

    fetch('products.php?get_variants=' + productId)
        .then(r => r.json())
        .then(variants => {
            _cachedVariants = {};
            variants.forEach(v => _cachedVariants[v.variant_id] = v);

            if (countBadge) countBadge.textContent = variants.length + (variants.length === 1 ? ' item' : ' items');

            if (variants.length === 0) {
                grid.innerHTML = '';
                noMsg.style.display = 'block';
                return;
            }
            noMsg.style.display = 'none';

            grid.innerHTML = variants.map(v => {
                const priceVal = parseFloat(v.price) || 0;
                const qty = parseInt(v.quantity) || 0;
                const isOutOfStock = qty <= 0;
                const isLowStock = qty > 0 && qty <= 3;
                const stockBadge = isOutOfStock
                    ? `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-red-50 text-red-600 border border-red-200/80 shadow-2xs"><span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>Out of stock</span>`
                    : (isLowStock
                        ? `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200/80 shadow-2xs"><span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>Low stock: ${qty}</span>`
                        : `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-green-50 text-green-700 border border-green-200/80 shadow-2xs"><span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>${qty} in stock</span>`
                    );

                return `
                <div id="variant-${v.variant_id}" class="bg-white rounded-2xl overflow-hidden border border-gray-200/90 shadow-xs hover:shadow-md hover:border-green-400 transition-all flex flex-col group">
                    <div class="relative h-48 bg-gray-100 overflow-hidden flex items-center justify-center">
                        ${v.image 
                            ? `<img src="../${escHtml(v.image)}" alt="${escAttr(v.variant_name)}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">`
                            : `<div class="h-full w-full flex flex-col items-center justify-center text-gray-300 bg-gray-50"><svg class="w-10 h-10 mb-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg><span class="text-[11px] text-gray-400 font-medium">No photo uploaded</span></div>`
                        }
                        <div class="absolute top-2.5 left-2.5">
                            ${stockBadge}
                        </div>
                    </div>
                    <div class="p-4 sm:p-5 flex-1 flex flex-col justify-between">
                        <div>
                            <h4 class="text-base font-bold text-gray-900 leading-snug mb-1 truncate" title="${escAttr(v.variant_name)}">${escHtml(v.variant_name)}</h4>
                            ${v.size ? `<span class="inline-block px-2.5 py-0.5 rounded-md bg-gray-100 text-gray-600 text-xs font-medium mb-3">${escHtml(v.size)}</span>` : '<div class="h-2"></div>'}
                        </div>
                        <div class="pt-3 border-t border-gray-100 flex items-center justify-between mt-2">
                            <div>
                                <span class="text-[10px] uppercase font-bold text-gray-400 tracking-wider block">Price</span>
                                <span class="text-lg font-extrabold text-green-600">₱${priceVal.toFixed(2)}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="openEditVariantModal(${v.variant_id})" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold bg-green-50 text-green-700 hover:bg-green-100 hover:text-green-800 transition-colors" title="Edit price, photo and details">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                    <span>Edit</span>
                                </button>
                                <button type="button" onclick="deleteVariant(${v.variant_id})" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="Delete variant">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>`;
            }).join('');
        })
        .catch(() => {
            grid.innerHTML = '<div class="col-span-full text-center py-8 text-red-500 text-sm">Failed to load variants.</div>';
        });
}

function openEditVariantModal(variantId) {
    const v = _cachedVariants[variantId];
    if (!v) return;

    document.getElementById('modalEditVariantId').value = v.variant_id;
    document.getElementById('modalEditVariantName').value = v.variant_name || '';
    document.getElementById('modalEditVariantSize').value = v.size || '';
    document.getElementById('modalEditVariantPrice').value = parseFloat(v.price || 0).toFixed(2);
    document.getElementById('modalEditVariantQty').value = parseInt(v.quantity) || 0;

    const fileInput = document.getElementById('modalEditVariantImg');
    fileInput.value = '';
    document.getElementById('modalEditVariantFileName').textContent = 'Keep existing photo, or pick a replacement';

    const thumb = document.getElementById('modalEditVariantThumb');
    const noThumb = document.getElementById('modalEditVariantNoThumb');
    if (v.image) {
        thumb.src = '../' + v.image;
        thumb.classList.remove('hidden');
        noThumb.classList.add('hidden');
    } else {
        thumb.src = '';
        thumb.classList.add('hidden');
        noThumb.classList.remove('hidden');
    }

    document.getElementById('editVariantModal').classList.remove('hidden');
}

function closeEditVariantModal() {
    document.getElementById('editVariantModal').classList.add('hidden');
}

function previewVariantImage(input) {
    const fileNameEl = document.getElementById('modalEditVariantFileName');
    const thumb = document.getElementById('modalEditVariantThumb');
    const noThumb = document.getElementById('modalEditVariantNoThumb');

    if (input.files && input.files[0]) {
        const file = input.files[0];
        fileNameEl.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
        const reader = new FileReader();
        reader.onload = function(e) {
            thumb.src = e.target.result;
            thumb.classList.remove('hidden');
            noThumb.classList.add('hidden');
        };
        reader.readAsDataURL(file);
    } else {
        fileNameEl.textContent = 'Keep existing photo, or pick a replacement';
    }
}

function submitEditVariantModal() {
    const variantId = document.getElementById('modalEditVariantId').value;
    const name = document.getElementById('modalEditVariantName').value.trim();
    const size = document.getElementById('modalEditVariantSize').value.trim();
    const price = document.getElementById('modalEditVariantPrice').value;
    const qty = document.getElementById('modalEditVariantQty').value;
    const imgInput = document.getElementById('modalEditVariantImg');
    const btn = document.getElementById('modalEditVariantSaveBtn');

    if (!name) { alert('Enter a variant name'); return; }
    if (price === '' || Number(price) < 0 || isNaN(Number(price))) { alert('Enter a valid price'); return; }
    if (qty === '' || Number(qty) < 0 || !Number.isInteger(Number(qty))) { alert('Enter a valid stock quantity'); return; }

    btn.disabled = true;
    btn.textContent = 'Saving...';

    const fd = new FormData();
    fd.append('action', 'edit_variant');
    fd.append('product_id', currentAlbumProductId);
    fd.append('variant_id', variantId);
    fd.append('variant_name', name);
    fd.append('size', size);
    fd.append('price', price || '0');
    fd.append('quantity', qty || '0');
    if (imgInput.files && imgInput.files.length > 0) {
        fd.append('variant_img', imgInput.files[0]);
    }

    fetch('products.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Failed to update variant.');
            closeEditVariantModal();
            loadVariants(currentAlbumProductId);
            showToast('Variant Updated', 'Price, photo, and details have been saved.', 'success');
        })
        .catch(error => {
            showToast('Error', error.message || 'Something went wrong.', 'error');
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = 'Save Changes';
        });
}

function addVariant() {
    const name = document.getElementById('variantName').value.trim();
    const imgInput = document.getElementById('variantImg');
    const btn = document.getElementById('addVariantBtn');

    if (!name) { alert('Enter a variant name'); return; }

    btn.disabled = true;
    btn.textContent = 'Adding...';

    const fd = new FormData();
    fd.append('action', 'add_variant');
    fd.append('product_id', currentAlbumProductId);
    fd.append('variant_name', name);
    fd.append('size', document.getElementById('variantSize') ? document.getElementById('variantSize').value.trim() : '');
    fd.append('price', document.getElementById('variantPrice').value || '0');
    fd.append('quantity', document.getElementById('variantQuantity').value || '0');
    if (imgInput.files.length > 0) {
        fd.append('variant_img', imgInput.files[0]);
    }

    fetch('products.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('variantName').value = '';
                if (document.getElementById('variantSize')) document.getElementById('variantSize').value = '';
                document.getElementById('variantPrice').value = '';
                document.getElementById('variantQuantity').value = '10';
                imgInput.value = '';
                loadVariants(currentAlbumProductId);
                showToast('Variant Added', 'New variant has been added to the album.', 'success');
            } else {
                showToast('Error', data.message || 'Failed to add variant.', 'error');
            }
            btn.disabled = false;
            btn.textContent = '+ Add Variant';
        })
        .catch(() => {
            showToast('Error', 'Something went wrong.', 'error');
            btn.disabled = false;
            btn.textContent = '+ Add Variant';
        });
}

function deleteVariant(variantId) {
    showConfirm({
        title: 'Delete Variant?',
        message: 'This variant will be permanently removed from the album.',
        type: 'danger',
        confirmText: 'Delete',
        onConfirm: () => { _doDeleteVariant(variantId); }
    });
}
function _doDeleteVariant(variantId) {
    const fd = new FormData();
    fd.append('action', 'delete_variant');
    fd.append('variant_id', variantId);
    fetch('products.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                loadVariants(currentAlbumProductId);
                showToast('Variant Deleted', 'Variant has been removed from the album.', 'success');
            } else {
                showToast('Error', 'Failed to delete variant.', 'error');
            }
        });
}

function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function escAttr(str) {
    return escHtml(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function adjQty(inputId, delta) {
    const el = document.getElementById(inputId);
    if (!el) return;
    el.value = Math.max(0, (parseInt(el.value) || 0) + delta);
}

// ---- Edit Product ----
function openEditModal(id, name, desc, unit, isActive, img, categoryId, price, quantity) {
    document.getElementById('editProductId').value = id;
    document.getElementById('editProductCategory').value = categoryId || 0;
    document.getElementById('editProductName').value = name;
    document.getElementById('editProductDesc').value = desc;
    document.getElementById('editProductPrice').value = price > 0 ? price : '';
    document.getElementById('editProductQty').value = quantity || 0;
    document.getElementById('editProductImg').value = '';

    const preview = document.getElementById('editImgPreview');
    const thumb = document.getElementById('editImgThumb');
    if (img) {
        thumb.src = '../' + img;
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }

    document.getElementById('editModal').classList.remove('hidden');
}

document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.add('hidden');
});

function saveProduct() {
    const btn = document.getElementById('saveProductBtn');
    const name = document.getElementById('editProductName').value.trim();
    if (!name) { alert('Product name is required'); return; }

    btn.disabled = true;
    btn.textContent = 'Saving...';

    const fd = new FormData();
    fd.append('action', 'edit_product');
    fd.append('product_id', document.getElementById('editProductId').value);
    fd.append('category_id', document.getElementById('editProductCategory').value);
    fd.append('product_name', name);
    fd.append('description', document.getElementById('editProductDesc').value.trim());
    fd.append('price', document.getElementById('editProductPrice').value || '0');
    fd.append('quantity', document.getElementById('editProductQty').value || '0');
    fd.append('is_active', '1');

    const imgInput = document.getElementById('editProductImg');
    if (imgInput.files.length > 0) {
        fd.append('img', imgInput.files[0]);
    }

    fetch('products.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('editModal').classList.add('hidden');
                showToast('Product Updated', 'Product has been updated successfully.', 'success');
                setTimeout(() => location.reload(), 600);
            } else {
                showToast('Error', data.message || 'Failed to update product.', 'error');
            }
            btn.disabled = false;
            btn.textContent = 'Save Changes';
        })
        .catch(() => {
            showToast('Error', 'Something went wrong.', 'error');
            btn.disabled = false;
            btn.textContent = 'Save Changes';
        });
}
</script>
</body>
</html>
