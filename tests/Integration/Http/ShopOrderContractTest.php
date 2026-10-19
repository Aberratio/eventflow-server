<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Shop\ShopRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

final class ShopOrderContractTest extends HttpTestCase
{
    private UserRepository $users;
    private ShopRepository $shop;
    private string $prefix;
    private array $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->shop = new ShopRepository($this->pdo);
        $this->prefix = 'http-order-' . bin2hex(random_bytes(4));

        $this->buyer = $this->users->create([
            'name' => 'Order Buyer',
            'email' => $this->prefix . '-buyer@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $this->pdo->prepare('DELETE FROM shop_order_items WHERE product_id IN (SELECT id FROM shop_products WHERE slug LIKE :slug)')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM shop_orders WHERE user_id = :id')->execute(['id' => $this->buyer['id']]);
        $this->pdo->prepare('DELETE FROM shop_products WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testCreatingAnOrderPersistsItAndCanBeReadBack(): void
    {
        $product = $this->createProduct(['price' => 50, 'stockCount' => 10]);

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $this->orderPayload($product, 2),
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        $created = $this->json($response)['data'];
        self::assertNotEmpty($created['id']);

        $fetched = $this->json($this->request(
            'GET',
            '/api/v1/shop/orders/' . $created['id'],
            [],
            $this->bearer($this->buyer['id'], 'admin', $this->buyer['email'])
        ))['data'];
        self::assertSame($created['id'], $fetched['id']);
        self::assertSame(100.0, (float) $fetched['productTotal']);
    }

    public function testForgedClientPricesAreIgnoredInFavourOfTheCatalog(): void
    {
        $product = $this->createProduct(['price' => 50, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        $payload['items'][0]['price'] = 1;
        $payload['productTotal'] = 1;
        $payload['totalPrice'] = 1;
        $payload['deliveryMethod']['price'] = 0.01;

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        $created = $this->json($response)['data'];
        self::assertSame(50.0, (float) $created['items'][0]['price']);
        self::assertSame(50.0, (float) $created['productTotal']);
        // courier delivery price from the real catalog (15.99), not the forged 0.01.
        self::assertSame(65.99, (float) $created['totalPrice']);
    }

    public function testForgedUserIdIsIgnoredAndOrderIsAttributedToTheCaller(): void
    {
        $product = $this->createProduct(['price' => 20, 'stockCount' => 5]);
        $otherUser = $this->users->create([
            'name' => 'Someone Else',
            'email' => $this->prefix . '-other@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);

        $payload = $this->orderPayload($product, 1);
        $payload['userId'] = $otherUser['id'];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        $created = $this->json($response)['data'];
        self::assertSame($this->buyer['id'], $created['userId']);
        self::assertNotSame($otherUser['id'], $created['userId']);
    }

    public function testOrderingMoreThanStockRejectsAndLeavesNoTraceOrDecrement(): void
    {
        $product = $this->createProduct(['price' => 30, 'stockCount' => 2]);

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $this->orderPayload($product, 5),
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('order_insufficient_stock', $this->json($response)['error']['code']);

        self::assertSame(0, $this->countOrdersForBuyer());
        self::assertSame(2, $this->currentStockCount($product['id']));
    }

    public function testOrderingAnArchivedProductIsRejected(): void
    {
        $product = $this->createProduct(['price' => 10, 'stockCount' => 10]);
        $this->pdo->prepare("UPDATE shop_products SET status = 'archived' WHERE id = :id")->execute(['id' => $product['id']]);

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $this->orderPayload($product, 1),
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('order_invalid_product', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countOrdersForBuyer());
    }

    public function testOrderingAnOutOfStockProductIsRejectedRegardlessOfStockCount(): void
    {
        $product = $this->createProduct(['price' => 40, 'inStock' => false, 'stockCount' => 10]);

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $this->orderPayload($product, 1),
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('order_out_of_stock', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countOrdersForBuyer());
        self::assertSame(0, $this->countOrderItemsForProduct($product['id']));
    }

    public function testOrderingWithAnUnrecognizedDeliveryMethodIsRejected(): void
    {
        $product = $this->createProduct(['price' => 40, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        $payload['deliveryMethod'] = ['id' => 'teleport', 'name' => 'Teleport', 'price' => 0];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('order_invalid_delivery_method', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countOrdersForBuyer());
        self::assertSame(0, $this->countOrderItemsForProduct($product['id']));
    }

    public function testOrderingWithAnUnrecognizedPaymentMethodIsRejected(): void
    {
        $product = $this->createProduct(['price' => 40, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        $payload['paymentMethod'] = ['id' => 'crypto', 'name' => 'Crypto'];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('order_invalid_payment_method', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countOrdersForBuyer());
        self::assertSame(0, $this->countOrderItemsForProduct($product['id']));
    }

    public function testCourierOrderWithoutShippingAddressIsRejected(): void
    {
        $product = $this->createProduct(['price' => 40, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        unset($payload['shippingAddress']); // courier requires one.

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('order_invalid_address', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countOrdersForBuyer());
    }

    public function testCourierOrderWithIncompleteShippingAddressIsRejected(): void
    {
        $product = $this->createProduct(['price' => 40, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        $payload['shippingAddress'] = [
            'fullName' => 'Jan Kowalski',
            'street' => '',
            'city' => 'Warszawa',
            'postalCode' => '00-001',
        ];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('order_invalid_address', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countOrdersForBuyer());
    }

    public function testCourierOrderWithMalformedPostalCodeIsRejected(): void
    {
        $product = $this->createProduct(['price' => 40, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        $payload['shippingAddress'] = [
            'fullName' => 'Jan Kowalski',
            'street' => 'Testowa 1',
            'city' => 'Warszawa',
            'postalCode' => '00001',
        ];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('order_invalid_address', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countOrdersForBuyer());
    }

    public function testCourierOrderWithValidAddressIsPersistedAndReadableBack(): void
    {
        $product = $this->createProduct(['price' => 40, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        $payload['shippingAddress'] = [
            'fullName' => 'Jan Kowalski',
            'street' => 'Testowa 1',
            'city' => 'Warszawa',
            'postalCode' => '00-001',
            'phone' => '600123456',
        ];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        $created = $this->json($response)['data'];
        self::assertSame('Jan Kowalski', $created['shippingAddress']['fullName']);
        self::assertSame('00-001', $created['shippingAddress']['postalCode']);

        $fetched = $this->json($this->request(
            'GET',
            '/api/v1/shop/orders/' . $created['id'],
            [],
            $this->bearer($this->buyer['id'], 'admin', $this->buyer['email'])
        ))['data'];
        self::assertSame('Testowa 1', $fetched['shippingAddress']['street']);
    }

    public function testPaczkomatOrderWithoutPaczkomatIdIsRejected(): void
    {
        $product = $this->createProduct(['price' => 40, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        $payload['deliveryMethod'] = ['id' => 'paczkomat', 'name' => 'Paczkomat', 'price' => 999999];
        $payload['shippingAddress'] = [
            'fullName' => 'Jan Kowalski',
            'street' => 'Testowa 1',
            'city' => 'Warszawa',
            'postalCode' => '00-001',
        ];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('order_invalid_address', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countOrdersForBuyer());
    }

    public function testPaczkomatOrderWithPaczkomatIdIsAccepted(): void
    {
        $product = $this->createProduct(['price' => 40, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        $payload['deliveryMethod'] = ['id' => 'paczkomat', 'name' => 'Paczkomat', 'price' => 999999];
        $payload['shippingAddress'] = [
            'fullName' => 'Jan Kowalski',
            'street' => 'Testowa 1',
            'city' => 'Warszawa',
            'postalCode' => '00-001',
            'paczkomatId' => 'WAW01A',
        ];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        $created = $this->json($response)['data'];
        self::assertSame('paczkomat', $created['deliveryMethod']['id']);
        // real catalog price (12.99), not the forged 999999.
        self::assertSame(52.99, (float) $created['totalPrice']);
        self::assertSame('WAW01A', $created['shippingAddress']['paczkomatId']);
    }

    public function testPickupOrderNeverRequiresAShippingAddress(): void
    {
        $product = $this->createProduct(['price' => 40, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        $payload['deliveryMethod'] = ['id' => 'pickup', 'name' => 'Odbior osobisty', 'price' => 0];
        // no shippingAddress at all.

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('pickup', $this->json($response)['data']['deliveryMethod']['id']);
    }

    public function testOrderWithoutInvoiceDataIsUnaffectedAndPersistsNoInvoice(): void
    {
        $product = $this->createProduct(['price' => 40, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        // no invoiceData at all — the pre-E5.5 default (plain receipt).

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        $created = $this->json($response)['data'];
        self::assertNull($created['invoiceData']);
    }

    public function testOrderWithValidInvoiceDataSucceedsAndPersists(): void
    {
        $product = $this->createProduct(['price' => 40, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        $payload['invoiceData'] = [
            'companyName' => 'Testowa Sp. z o.o.',
            'nip' => '525-234-40-78',
            'street' => 'Faktury 1',
            'city' => 'Warszawa',
            'postalCode' => '00-002',
        ];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        $created = $this->json($response)['data'];
        self::assertSame('Testowa Sp. z o.o.', $created['invoiceData']['companyName']);
        self::assertSame('525-234-40-78', $created['invoiceData']['nip']);

        $fetched = $this->json($this->request(
            'GET',
            '/api/v1/shop/orders/' . $created['id'],
            [],
            $this->bearer($this->buyer['id'], 'admin', $this->buyer['email'])
        ))['data'];
        self::assertSame('Faktury 1', $fetched['invoiceData']['street']);
    }

    public function testInvoiceRequestedWithoutCompanyNameIsRejected(): void
    {
        $product = $this->createProduct(['price' => 40, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        $payload['invoiceData'] = [
            'companyName' => '',
            'nip' => '5252344078',
            'street' => 'Faktury 1',
            'city' => 'Warszawa',
            'postalCode' => '00-002',
        ];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('order_invalid_invoice_data', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countOrdersForBuyer());
    }

    public function testInvoiceRequestedWithoutBillingAddressIsRejected(): void
    {
        $product = $this->createProduct(['price' => 40, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        $payload['invoiceData'] = [
            'companyName' => 'Testowa Sp. z o.o.',
            'nip' => '5252344078',
            'street' => '',
            'city' => 'Warszawa',
            'postalCode' => '00-002',
        ];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('order_invalid_invoice_data', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countOrdersForBuyer());
    }

    public function testInvoiceRequestedWithMalformedNipIsRejected(): void
    {
        $product = $this->createProduct(['price' => 40, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        $payload['invoiceData'] = [
            'companyName' => 'Testowa Sp. z o.o.',
            'nip' => '1234567890',
            'street' => 'Faktury 1',
            'city' => 'Warszawa',
            'postalCode' => '00-002',
        ];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('order_invalid_nip', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countOrdersForBuyer());
    }

    public function testInvoiceRequestedWithWrongDigitCountNipIsRejected(): void
    {
        $product = $this->createProduct(['price' => 40, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        $payload['invoiceData'] = [
            'companyName' => 'Testowa Sp. z o.o.',
            'nip' => '12345',
            'street' => 'Faktury 1',
            'city' => 'Warszawa',
            'postalCode' => '00-002',
        ];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('order_invalid_nip', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countOrdersForBuyer());
    }

    /**
     * E5.6 (issue 16 §2): the organizer package is a `shop_products` row
     * flagged `serviceKind = 'organizer_package'` — a digital, no-delivery
     * product. Buying one grants exactly `quantity` credits on the buyer's
     * `organizer_package_credits` counter; the order needs no delivery method
     * and no shipping address at all, unlike every other product in this file.
     */
    public function testPurchasingOrganizerPackageDoesNotGrantCreditsAtCheckout(): void
    {
        $product = $this->createOrganizerPackageProduct();

        $payload = [
            'items' => [
                ['productId' => $product['id'], 'productName' => $product['name'], 'quantity' => 1, 'price' => 1],
            ],
            'paymentMethod' => ['id' => 'bank', 'name' => 'Przelew bankowy'],
            // deliberately no deliveryMethod, no shippingAddress.
        ];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        // PP.105: the order is only `pending` - nothing is granted before payment.
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('pending', $this->json($response)['data']['status']);
        self::assertSame(0, $this->organizerPackageCredits($this->buyer['id']));
        self::assertSame('participant', $this->roleOf($this->buyer['id']));
    }

    public function testMarkingNUnitsOfOrganizerPackagePaidGrantsCreditByN(): void
    {
        $product = $this->createOrganizerPackageProduct();

        $payload = [
            'items' => [
                ['productId' => $product['id'], 'productName' => $product['name'], 'quantity' => 3, 'price' => 1],
            ],
            'paymentMethod' => ['id' => 'bank', 'name' => 'Przelew bankowy'],
        ];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(0, $this->organizerPackageCredits($this->buyer['id']));

        $this->markPaid((string) $this->json($response)['data']['id']);

        self::assertSame(3, $this->organizerPackageCredits($this->buyer['id']));
    }

    /**
     * E5.6/issue 16 §5: a digital-only order never goes through a delivery
     * method — omitting it entirely still succeeds (unlike every physical
     * product in this file, where a missing/invalid delivery method is
     * `order_invalid_delivery_method`).
     */
    public function testDigitalOnlyOrderSucceedsWithoutAnyDeliveryMethod(): void
    {
        $product = $this->createOrganizerPackageProduct();

        $payload = [
            'items' => [
                ['productId' => $product['id'], 'productName' => $product['name'], 'quantity' => 1, 'price' => 1],
            ],
            'paymentMethod' => ['id' => 'bank', 'name' => 'Przelew bankowy'],
        ];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
    }

    /**
     * E5.7 (issue 16 §2 "Samoobsluga"): the point of the ticket — a
     * self-service organizer-package purchase by a plain 'participant'
     * grants both the credit (E5.6, unconditional) AND the Organizator role,
     * not just the credit.
     */
    public function testMarkingOrganizerPackagePaidAsParticipantGrantsCreditAndOrganizerRole(): void
    {
        $product = $this->createOrganizerPackageProduct();

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            [
                'items' => [
                    ['productId' => $product['id'], 'productName' => $product['name'], 'quantity' => 1, 'price' => 1],
                ],
                'paymentMethod' => ['id' => 'bank', 'name' => 'Przelew bankowy'],
            ],
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('participant', $this->roleOf($this->buyer['id']));

        $this->markPaid((string) $this->json($response)['data']['id']);

        self::assertSame(1, $this->organizerPackageCredits($this->buyer['id']));
        self::assertSame('organizer', $this->roleOf($this->buyer['id']));
    }

    /**
     * A second organizer-package purchase by an account that's already
     * 'organizer' (e.g. from a first purchase, or from the §4.4.4 manual
     * admin promotion path) must stay idempotent: more credit, same role,
     * no error, no duplicate role assignment (there's nothing to duplicate —
     * `role` is a single column — but this locks in that the UPDATE is a
     * no-op rather than something that could ever fail on a "already has
     * this role" edge case).
     */
    public function testMarkingOrganizerPackagePaidAsExistingOrganizerOnlyAddsCreditAndLeavesRoleUnchanged(): void
    {
        $this->users->update($this->buyer['id'], ['role' => 'organizer']);
        $product = $this->createOrganizerPackageProduct();

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            [
                'items' => [
                    ['productId' => $product['id'], 'productName' => $product['name'], 'quantity' => 2, 'price' => 1],
                ],
                'paymentMethod' => ['id' => 'bank', 'name' => 'Przelew bankowy'],
            ],
            $this->bearer($this->buyer['id'], 'organizer', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(0, $this->organizerPackageCredits($this->buyer['id']));

        $this->markPaid((string) $this->json($response)['data']['id']);

        self::assertSame(2, $this->organizerPackageCredits($this->buyer['id']));
        self::assertSame('organizer', $this->roleOf($this->buyer['id']));
    }

    /**
     * An admin (a staff role that already implies Organizator-level access
     * everywhere else in this app — routes.php's $isStaffRole) buying an
     * organizer package still gets the credit, but is never reassigned down
     * to plain 'organizer' — that would be destructive to a strictly higher
     * role for no reason.
     */
    public function testMarkingOrganizerPackagePaidAsAdminGrantsCreditWithoutTouchingTheHigherRole(): void
    {
        $this->users->update($this->buyer['id'], ['role' => 'admin']);
        $product = $this->createOrganizerPackageProduct();

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            [
                'items' => [
                    ['productId' => $product['id'], 'productName' => $product['name'], 'quantity' => 1, 'price' => 1],
                ],
                'paymentMethod' => ['id' => 'bank', 'name' => 'Przelew bankowy'],
            ],
            $this->bearer($this->buyer['id'], 'admin', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(0, $this->organizerPackageCredits($this->buyer['id']));

        $this->markPaid((string) $this->json($response)['data']['id']);

        self::assertSame(1, $this->organizerPackageCredits($this->buyer['id']));
        self::assertSame('admin', $this->roleOf($this->buyer['id']));
    }

    /** PP.105: staff confirmation of payment - the only door into `paid`. */
    private function markPaid(string $orderId): void
    {
        $staff = $this->users->create([
            'name' => 'Order Staff',
            'email' => $this->prefix . '-staff-' . bin2hex(random_bytes(2)) . '@example.test',
            'password' => 'demo1234',
            'role' => 'moderator',
        ]);
        $response = $this->request(
            'PATCH',
            '/api/v1/shop/orders/' . $orderId . '/status',
            ['status' => 'paid'],
            $this->bearer($staff['id'], 'moderator', $staff['email'])
        );
        self::assertSame(200, $response->getStatusCode());
    }

    private function roleOf(string $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT role FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (string) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function createOrganizerPackageProduct(array $overrides = []): array
    {
        return $this->createProduct(array_merge([
            'name' => $this->prefix . ' Pakiet Organizatora',
            'slug' => $this->prefix . '-organizer-package',
            'description' => 'Prawo do utworzenia jednego Wydarzenia.',
            'price' => 499.0,
            'category' => 'organizer',
            'brand' => 'Zmierzymy Czas',
            'inStock' => true,
            'stockCount' => null,
            'isService' => true,
            'serviceKind' => 'organizer_package',
        ], $overrides));
    }

    private function organizerPackageCredits(string $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT organizer_package_credits FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    private function countOrdersForBuyer(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM shop_orders WHERE user_id = :id');
        $stmt->execute(['id' => $this->buyer['id']]);
        return (int) $stmt->fetchColumn();
    }

    private function countOrderItemsForProduct(string $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM shop_order_items WHERE product_id = :id');
        $stmt->execute(['id' => $productId]);
        return (int) $stmt->fetchColumn();
    }

    private function currentStockCount(string $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT stock_count FROM shop_products WHERE id = :id');
        $stmt->execute(['id' => $productId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function orderPayload(array $product, int $quantity): array
    {
        return [
            'items' => [
                [
                    'productId' => $product['id'],
                    'productName' => $product['name'],
                    'quantity' => $quantity,
                    'price' => 999999,
                ],
            ],
            'deliveryMethod' => ['id' => 'courier', 'name' => 'Kurier', 'price' => 999999],
            'paymentMethod' => ['id' => 'bank', 'name' => 'Przelew bankowy'],
            // E5.3: courier requires a shipping address — pre-existing tests
            // in this file that only care about pricing/stock/product
            // validation get a valid one by default so they don't have to
            // know about it; the address-specific tests below override or
            // unset this key explicitly.
            'shippingAddress' => [
                'fullName' => 'Jan Kowalski',
                'street' => 'Testowa 1',
                'city' => 'Warszawa',
                'postalCode' => '00-001',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function createProduct(array $overrides = []): array
    {
        return $this->shop->createProduct(array_merge([
            'name' => $this->prefix . ' Product',
            'slug' => $this->prefix . '-product-' . bin2hex(random_bytes(3)),
            'description' => 'Test description',
            'price' => 99.99,
            'category' => 'apparel',
            'brand' => 'Test Brand',
            'imageUrl' => 'https://example.test/product.jpg',
            'inStock' => true,
            'stockCount' => 10,
        ], $overrides));
    }
}
