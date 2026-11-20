<?php

namespace Tests\Support;

/** A host that lists the products it knows, as an ASE player's `BeoZone/System/Products` does. */
class FakeProductsDriver extends FakeLinkedDriver
{
    public static array $products = [];

    public static ?\Throwable $productsThrow = null;

    public static function reset(): void
    {
        parent::reset();
        self::$products = [];
        self::$productsThrow = null;
    }

    public function getProducts(): array
    {
        if (self::$productsThrow) {
            throw self::$productsThrow;
        }

        return self::$products;
    }
}
