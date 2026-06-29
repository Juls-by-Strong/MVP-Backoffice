<?php
// Parts price list + service call types
//
// Admin:
// GET /admin/catalog/categories - full category tree
// POST /admin/catalog/categories - add category
// PUT /admin/catalog/categories/{id} - update category
// DELETE /admin/catalog/categories/{id} - delete category
//
// GET /admin/catalog/parts - list parts (?category_id=&search=&active=)
// POST /admin/catalog/parts - create part
// GET /admin/catalog/parts/{id} - single part (with cost_price)
// PUT /admin/catalog/parts/{id} - update part
// DELETE /admin/catalog/parts/{id} - deactivate part
//
// GET /admin/catalog/service-calls - list service call types
// POST /admin/catalog/service-calls - create
// PUT /admin/catalog/service-calls/{id} - update
// DELETE /admin/catalog/service-calls/{id} - deactivate
//
// Tech (no cost_price in response):
// GET /tech/catalog/categories
// GET /tech/catalog/parts
// GET /tech/catalog/parts/{id}
// GET /tech/catalog/service-calls

function getCategoryTree(PDO $db): array {
    $stmt = $db->query(
        "SELECT category_id, parent_id, name, sort_order
         FROM part_categories
         ORDER BY sort_order, name"
    );
    $all  = $stmt->fetchAll();
    $byId = [];
    foreach ($all as $c) { $byId[$c['category_id']] = $c + ['children' => []]; }

    $tree = [];
    foreach ($byId as &$cat) {
        if ($cat['parent_id']) {
            if (isset($byId[$cat['parent_id']])) {
                $byId[$cat['parent_id']]['children'][] = &$cat;
            }
        } else {
            $tree[] = &$cat;
        }
    }
    return $tree;
}

function getPartsList(PDO $db, bool $showCost, array $filters = []): array {
    $where  = ['p.is_active = 1'];
    $params = [];

    if (!empty($filters['category_id'])) {
 // Include parts in this category OR any of its children
        $catId = (int)$filters['category_id'];
        $where[] = '(p.category_id = ? OR pc.parent_id = ?)';
        $params[] = $catId;
        $params[] = $catId;
    }
    if (!empty($filters['search'])) {
        $like = '%' . $filters['search'] . '%';
        $where[] = '(p.name LIKE ? OR p.customer_description LIKE ? OR p.tech_description LIKE ? OR p.brand LIKE ? OR p.sku LIKE ?)';
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
    }
    if (isset($filters['active'])) {
        $where[0] = 'p.is_active = ?';
        $params = array_merge([(int)$filters['active']], $params);
    }

    $costCol = $showCost ? 'p.cost_price,' : '';

    $stmt = $db->prepare(
        "SELECT p.part_id, p.category_id, pc.name AS category_name,
                pcp.name AS parent_category,
                p.name, p.brand, p.sku,
                p.customer_description, p.tech_description,
                p.unit, {$costCol}
                p.sell_price, p.is_h2o2, p.is_taxable, p.is_active, p.sort_order
         FROM parts_catalog p
         JOIN part_categories pc  ON p.category_id  = pc.category_id
         LEFT JOIN part_categories pcp ON pc.parent_id = pcp.category_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY pcp.sort_order, pc.sort_order, p.sort_order, p.name
         LIMIT 500"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// MAIN HANDLER
function handleCatalog(
    PDO $db, string $method, ?string $sub, ?string $id,
    string $role
): void {
    $isAdmin  = ($role === 'admin');
    $showCost = $isAdmin;

    if ($sub === 'categories') {
        if ($method === 'GET') {
            sendJson(getCategoryTree($db));
        }

        if ($method === 'POST' && $isAdmin) {
            $body      = json_decode(file_get_contents('php://input'), true) ?? [];
            $name      = trim($body['name'] ?? '');
            $parentId  = !empty($body['parent_id']) ? (int)$body['parent_id'] : null;
            $sortOrder = (int)($body['sort_order'] ?? 0);
            if (!$name) sendError(400, 'name is required');
            $db->prepare(
                "INSERT INTO part_categories (parent_id, name, sort_order) VALUES (?, ?, ?)"
            )->execute([$parentId, $name, $sortOrder]);
            sendJson(['category_id' => $db->lastInsertId(), 'message' => 'Category created'], 201);
        }

        if ($method === 'PUT' && $id && $isAdmin) {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $db->prepare(
                "UPDATE part_categories SET name=?, sort_order=? WHERE category_id=?"
            )->execute([
                $body['name'] ?? '',
                (int)($body['sort_order'] ?? 0),
                $id
            ]);
            sendJson(['message' => 'Category updated']);
        }

        if ($method === 'DELETE' && $id && $isAdmin) {
 // Orphan any parts in this category (set to first available category) rather than failing
            $fallback = $db->query("SELECT category_id FROM part_categories WHERE category_id != " . (int)$id . " LIMIT 1")->fetchColumn();
            if ($fallback) {
                $db->prepare("UPDATE parts_catalog SET category_id = ? WHERE category_id = ?")->execute([$fallback, $id]);
            }
 // Also re-parent any child categories to top-level
            $db->prepare("UPDATE part_categories SET parent_id = NULL WHERE parent_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM part_categories WHERE category_id = ?")->execute([$id]);
            sendJson(['message' => 'Category deleted']);
        }

        sendError(405, 'Method not allowed');
    }

    if ($sub === 'parts') {

 // List / Search
        if ($method === 'GET' && !$id) {
            $filters = [];
            if (!empty($_GET['category_id'])) $filters['category_id'] = $_GET['category_id'];
            if (!empty($_GET['search']))       $filters['search']      = $_GET['search'];
            sendJson(getPartsList($db, $showCost, $filters));
        }

 // Single part
        if ($method === 'GET' && $id) {
            $costCol = $showCost ? 'p.cost_price,' : '';
            $stmt = $db->prepare(
                "SELECT p.*, {$costCol} pc.name AS category_name,
                        pcp.name AS parent_category
                 FROM parts_catalog p
                 JOIN part_categories pc  ON p.category_id = pc.category_id
                 LEFT JOIN part_categories pcp ON pc.parent_id = pcp.category_id
                 WHERE p.part_id = ?"
            );
            $stmt->execute([$id]);
            $part = $stmt->fetch();
            if (!$part) sendError(404, 'Part not found');
            if (!$showCost) unset($part['cost_price']); // belt-and-suspenders
            sendJson($part);
        }

 // Create
        if ($method === 'POST' && $isAdmin) {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $categoryId   = (int)($body['category_id'] ?? 0);
            $name         = trim($body['name'] ?? '');
            $customerDesc = trim($body['customer_description'] ?? '');
            $sellPrice    = (float)($body['sell_price'] ?? 0);

            if (!$categoryId) sendError(400, 'category_id is required');
            if (!$name)       sendError(400, 'name is required');

            $db->prepare(
                "INSERT INTO parts_catalog
                 (category_id, name, brand, sku, customer_description, tech_description,
                  unit, cost_price, sell_price, is_h2o2, is_taxable, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $categoryId,
                $name,
                trim($body['brand'] ?? '') ?: null,
                trim($body['sku'] ?? '') ?: null,
                $customerDesc ?: null,
                trim($body['tech_description'] ?? '') ?: null,
                trim($body['unit'] ?? '') ?: 'each',
                isset($body['cost_price']) ? (float)$body['cost_price'] : null,
                $sellPrice,
                isset($body['is_h2o2'])    ? (int)(bool)$body['is_h2o2']    : 0,
                isset($body['is_taxable']) ? (int)(bool)$body['is_taxable'] : 1,
                (int)($body['sort_order'] ?? 0),
            ]);
            $partId = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM parts_catalog WHERE part_id = ?");
            $stmt->execute([$partId]);
            sendJson($stmt->fetch(), 201);
        }

 // Update
        if ($method === 'PUT' && $id && $isAdmin) {
            $body    = json_decode(file_get_contents('php://input'), true) ?? [];
            $allowed = ['category_id','name','brand','sku','customer_description','tech_description',
                        'unit','cost_price','sell_price','is_h2o2','is_taxable','sort_order','is_active'];
            $fields  = [];
            $values  = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $body)) {
                    $fields[] = "$f = ?";
                    $values[] = $body[$f] === '' ? null : $body[$f];
                }
            }
            if (!empty($fields)) {
                $values[] = $id;
                $db->prepare("UPDATE parts_catalog SET " . implode(', ', $fields) . " WHERE part_id = ?")
                   ->execute($values);
            }
            $stmt = $db->prepare(
                "SELECT p.*, pc.name AS category_name FROM parts_catalog p
                 JOIN part_categories pc ON p.category_id = pc.category_id
                 WHERE p.part_id = ?"
            );
            $stmt->execute([$id]);
            sendJson($stmt->fetch());
        }

 // Deactivate (soft delete)
        if ($method === 'DELETE' && $id && $isAdmin) {
            $db->prepare("UPDATE parts_catalog SET is_active = 0 WHERE part_id = ?")->execute([$id]);
            sendJson(['message' => 'Part deactivated']);
        }

        sendError(405, 'Method not allowed');
    }

    if ($sub === 'servicecalls') {

        if ($method === 'GET' && !$id) {
            $stmt = $db->query(
                "SELECT * FROM service_call_types WHERE is_active = 1 ORDER BY sort_order, name"
            );
            sendJson($stmt->fetchAll());
        }

        if ($method === 'POST' && $isAdmin) {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $name = trim($body['name'] ?? '');
            if (!$name) sendError(400, 'name is required');
            $db->prepare(
                "INSERT INTO service_call_types (name, description, price, is_taxable, sort_order)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([
                $name,
                trim($body['description'] ?? '') ?: null,
                (float)($body['price'] ?? 0),
                isset($body['is_taxable']) ? (int)(bool)$body['is_taxable'] : 0,
                (int)($body['sort_order'] ?? 0),
            ]);
            sendJson(['sc_id' => $db->lastInsertId(), 'message' => 'Service call type created'], 201);
        }

        if ($method === 'PUT' && $id && $isAdmin) {
            $body    = json_decode(file_get_contents('php://input'), true) ?? [];
            $allowed = ['name','description','price','is_taxable','sort_order','is_active'];
            $fields  = [];
            $values  = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $body)) {
                    $fields[] = "$f = ?";
                    $values[] = $body[$f] === '' ? null : $body[$f];
                }
            }
            if (!empty($fields)) {
                $values[] = $id;
                $db->prepare("UPDATE service_call_types SET " . implode(', ', $fields) . " WHERE sc_id = ?")
                   ->execute($values);
            }
            sendJson(['message' => 'Updated']);
        }

        if ($method === 'DELETE' && $id && $isAdmin) {
            $db->prepare("UPDATE service_call_types SET is_active = 0 WHERE sc_id = ?")->execute([$id]);
            sendJson(['message' => 'Deactivated']);
        }

        sendError(405, 'Method not allowed');
    }

    sendError(404, 'Not found');
}
