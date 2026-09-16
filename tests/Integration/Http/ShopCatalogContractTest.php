<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

final class ShopCatalogContractTest extends HttpTestCase
{
    private UserRepository $users;
    private string $prefix;
    private array $admin;
    private array $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'http-shop-' . bin2hex(random_bytes(4));

        $this->admin = $this->users->create([
            'name' => 'Shop Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
        $this->participant = $this->users->create([
            'name' => 'Shop Participant',
            'email' => $this->prefix . '-part@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM shop_products WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM shop_categories WHERE id LIKE :id')->execute(['id' => $this->prefix . '-%']);
    }

    // ── Categories ───────────────────────────────────────────────────────────

    public function testCategoriesListIsPublicAndHidesArchivedByDefault(): void
    {
        $created = $this->createCategory();
        $categoryId = $created['id'];

        $this->request(
            'DELETE',
            '/api/v1/shop/categories/' . $categoryId,
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );

        $publicList = $this->json($this->request('GET', '/api/v1/shop/categories'));
        $ids = array_column($publicList['data'], 'id');
        self::assertNotContains($categoryId, $ids, 'archived category leaked into public listing');

        $staffList = $this->json($this->request(
            'GET',
            '/api/v1/shop/categories?includeArchived=1',
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        ));
        $staffIds = array_column($staffList['data'], 'id');
        self::assertContains($categoryId, $staffIds);
    }

    public function testCreateCategoryRequiresAdminRole(): void
    {
        $payload = ['id' => $this->prefix . '-cat', 'name' => 'Test Category'];

        $noAuth = $this->request('POST', '/api/v1/shop/categories', $payload);
        self::assertSame(401, $noAuth->getStatusCode());

        $participantResp = $this->request(
            'POST',
            '/api/v1/shop/categories',
            $payload,
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        self::assertSame(403, $participantResp->getStatusCode());
    }

    public function testCreateCategoryValidatesName(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/shop/categories',
            ['id' => $this->prefix . '-cat', 'name' => ''],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_error', $this->json($response)['error']['code']);
    }

    public function testAdminCanCreateUpdateAndArchiveCategory(): void
    {
        $created = $this->createCategory();
        self::assertSame($this->prefix . '-cat', $created['id']);
        self::assertSame('active', $created['status']);

        $patchResp = $this->request(
            'PATCH',
            '/api/v1/shop/categories/' . $created['id'],
            ['name' => 'Renamed Category'],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $patchResp->getStatusCode());
        self::assertSame('Renamed Category', $this->json($patchResp)['data']['name']);

        // Archiving a category that does not exist must not 500 — the route
        // looks the category up before archiving purely for activity-log
        // context and must tolerate a miss.
        $archiveResp = $this->request(
            'DELETE',
            '/api/v1/shop/categories/does-not-exist-' . $this->prefix,
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $archiveResp->getStatusCode());
    }

    // ── Products ─────────────────────────────────────────────────────────────

    public function testProductsListIsPublic(): void
    {
        $response = $this->request('GET', '/api/v1/shop/products');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('data', $payload);
        self::assertArrayHasKey('total', $payload);
    }

    public function testCreateProductRequiresAdminRole(): void
    {
        $payload = $this->productPayload();

        $noAuth = $this->request('POST', '/api/v1/shop/products', $payload);
        self::assertSame(401, $noAuth->getStatusCode());

        $participantResp = $this->request(
            'POST',
            '/api/v1/shop/products',
            $payload,
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        self::assertSame(403, $participantResp->getStatusCode());
    }

    public function testCreateProductValidatesRequiredFields(): void
    {
        foreach (['name', 'slug', 'description', 'category', 'brand', 'imageUrl'] as $field) {
            $payload = $this->productPayload([$field => '']);
            $response = $this->request(
                'POST',
                '/api/v1/shop/products',
                $payload,
                $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
            );
            self::assertSame(422, $response->getStatusCode(), "expected validation error for missing {$field}");
            self::assertSame('validation_error', $this->json($response)['error']['code']);
        }

        $negativePrice = $this->request(
            'POST',
            '/api/v1/shop/products',
            $this->productPayload(['price' => -5]),
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(422, $negativePrice->getStatusCode());
    }

    public function testAdminCanCreateUpdateAndArchiveProduct(): void
    {
        $created = $this->createProduct();
        self::assertSame('active', $created['status']);
        self::assertSame(['M', 'L'], $created['sizes']);
        self::assertCount(1, $created['colors']);

        $productId = $created['id'];

        $patchResp = $this->request(
            'PATCH',
            '/api/v1/shop/products/' . $productId,
            ['price' => 150.5, 'stockCount' => 3],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $patchResp->getStatusCode());
        $updated = $this->json($patchResp)['data'];
        self::assertSame(150.5, $updated['price']);
        self::assertSame(3, $updated['stockCount']);

        $archiveResp = $this->request(
            'DELETE',
            '/api/v1/shop/products/' . $productId,
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $archiveResp->getStatusCode());
    }

    public function testArchivedProductIsHiddenFromPublicListingAndDetailButVisibleToStaff(): void
    {
        $created = $this->createProduct();
        $productId = $created['id'];
        $slug = $created['slug'];

        $this->request(
            'DELETE',
            '/api/v1/shop/products/' . $productId,
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );

        // Public listing.
        $publicList = $this->json($this->request('GET', '/api/v1/shop/products?pageSize=100'));
        $ids = array_column($publicList['data'], 'id');
        self::assertNotContains($productId, $ids);

        // includeArchived is ignored for non-staff callers.
        $participantList = $this->json($this->request(
            'GET',
            '/api/v1/shop/products?includeArchived=1&pageSize=100',
            [],
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        ));
        $participantIds = array_column($participantList['data'], 'id');
        self::assertNotContains($productId, $participantIds);

        // Staff can see it when explicitly asking for archived items.
        $staffList = $this->json($this->request(
            'GET',
            '/api/v1/shop/products?includeArchived=1&pageSize=100',
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        ));
        $staffIds = array_column($staffList['data'], 'id');
        self::assertContains($productId, $staffIds);

        // Slug lookup (public product page) must 404-equivalent (null data).
        $slugResp = $this->json($this->request('GET', '/api/v1/shop/products/slug/' . $slug));
        self::assertNull($slugResp['data']);

        // Direct ID lookup must also hide the archived product from the public.
        $idResp = $this->json($this->request('GET', '/api/v1/shop/products/' . $productId));
        self::assertNull($idResp['data']);

        // ...but staff fetching by ID (e.g. the admin edit screen) still gets it.
        $staffIdResp = $this->json($this->request(
            'GET',
            '/api/v1/shop/products/' . $productId,
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        ));
        self::assertSame($productId, $staffIdResp['data']['id']);
    }

    public function testProductFilterByCategoryPriceSearchAndStock(): void
    {
        $categoryId = $this->createCategory()['id'];

        $cheapInStock = $this->createProduct([
            'name' => $this->prefix . ' Cheap Shirt',
            'slug' => $this->prefix . '-cheap',
            'category' => $categoryId,
            'price' => 20,
            'inStock' => true,
        ]);
        $expensiveOutOfStock = $this->createProduct([
            'name' => $this->prefix . ' Pricey Jacket',
            'slug' => $this->prefix . '-pricey',
            'category' => $categoryId,
            'price' => 500,
            'inStock' => false,
        ]);

        // Category filter.
        $byCategory = $this->json($this->request('GET', '/api/v1/shop/products?category=' . urlencode($categoryId) . '&pageSize=100'));
        $categoryIds = array_column($byCategory['data'], 'id');
        self::assertContains($cheapInStock['id'], $categoryIds);
        self::assertContains($expensiveOutOfStock['id'], $categoryIds);

        // Price range filter.
        $byPrice = $this->json($this->request('GET', '/api/v1/shop/products?minPrice=100&maxPrice=1000&pageSize=100'));
        $priceIds = array_column($byPrice['data'], 'id');
        self::assertContains($expensiveOutOfStock['id'], $priceIds);
        self::assertNotContains($cheapInStock['id'], $priceIds);

        // Search filter.
        $bySearch = $this->json($this->request('GET', '/api/v1/shop/products?search=' . urlencode($this->prefix . ' Pricey') . '&pageSize=100'));
        $searchIds = array_column($bySearch['data'], 'id');
        self::assertContains($expensiveOutOfStock['id'], $searchIds);
        self::assertNotContains($cheapInStock['id'], $searchIds);

        // Stock filter.
        $inStockOnly = $this->json($this->request('GET', '/api/v1/shop/products?inStock=1&category=' . urlencode($categoryId) . '&pageSize=100'));
        $inStockIds = array_column($inStockOnly['data'], 'id');
        self::assertContains($cheapInStock['id'], $inStockIds);
        self::assertNotContains($expensiveOutOfStock['id'], $inStockIds);
    }

    /** @return array<string, mixed> */
    private function createCategory(array $overrides = []): array
    {
        $payload = array_merge([
            'id' => $this->prefix . '-cat',
            'name' => 'Test Category',
        ], $overrides);

        $response = $this->request(
            'POST',
            '/api/v1/shop/categories',
            $payload,
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );

        return $this->json($response)['data'];
    }

    /** @return array<string, mixed> */
    private function createProduct(array $overrides = []): array
    {
        $response = $this->request(
            'POST',
            '/api/v1/shop/products',
            $this->productPayload($overrides),
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );

        return $this->json($response)['data'];
    }

    /** @return array<string, mixed> */
    private function productPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => $this->prefix . ' Product',
            'slug' => $this->prefix . '-product',
            'description' => 'Test description',
            'price' => 99.99,
            'category' => 'apparel',
            'brand' => 'Test Brand',
            'imageUrl' => 'https://example.test/product.jpg',
            'sizes' => ['M', 'L'],
            'colors' => [['id' => 'red', 'name' => 'Red', 'hex' => '#FF0000']],
            'inStock' => true,
            'stockCount' => 10,
        ], $overrides);
    }
}
