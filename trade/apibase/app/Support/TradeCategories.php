<?php

namespace App\Support;

/**
 * The product categories, one list for the whole network.
 *
 * A supplier picks from these instead of typing "Ceramic, handicraft" one
 * day and "Handicrafts" the next; a buyer browses the same list. Search
 * still runs on product names and keywords, so a category is a shelf, not
 * a cage. The HS code, where given, is the precise name customs use.
 */
final class TradeCategories
{
    public const LIST = [
        'Agriculture & Food Products',
        'Spices, Tea & Coffee',
        'Beverages',
        'Marine & Seafood',
        'Textiles & Fabrics',
        'Apparel & Garments',
        'Home Textiles',
        'Leather & Footwear',
        'Handicrafts & Home Décor',
        'Furniture & Wood Products',
        'Jewellery, Gems & Precious Metals',
        'Glass & Ceramics',
        'Building Materials & Hardware',
        'Metals & Minerals',
        'Chemicals & Petrochemicals',
        'Plastics & Rubber',
        'Paper & Packaging',
        'Pharmaceuticals & Healthcare',
        'Medical Devices & Supplies',
        'Cosmetics & Personal Care',
        'Machinery & Industrial Equipment',
        'Electrical & Electronics',
        'Automotive & Auto Parts',
        'Energy, Solar & Batteries',
        'Sports Goods & Toys',
        'Stationery & Office Supplies',
        'Services & Logistics',
        'Other',
    ];

    /** The list entry a free-text category most resembles, else null. */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach (self::LIST as $entry) {
            if (mb_strtolower($entry) === mb_strtolower($value)) {
                return $entry;
            }
        }

        return null;
    }
}
