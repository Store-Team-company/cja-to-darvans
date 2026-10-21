<?php

return [
    'login_username' => env('BDP_LOGIN_USERNAME', 'admin'),
    'login_password' => env('BDP_LOGIN_PASSWORD', 'change-this-password'),
    'default_brand' => 'Balenco',
    'default_status' => 'published',
    'default_categories' => 'Women\'s Flats',
    'default_tax' => '',
    'currency' => 'USD',
    'size_label_mode' => 'us', // us or raw
    'price_markup_percent' => 0,
    // If WooCommerce/CJA CSV has blank variation stock quantity but item is in stock, use this quantity.
    'default_variation_quantity' => env('BDP_DEFAULT_VARIATION_QUANTITY', 100),
    'round_price_to_99' => true,

    // CJA can connect to Data Pilot as if it were a WooCommerce store.
    // Use these values as Client Key / Client Secret in CJA.
    'cja_consumer_key' => env('BDP_WC_CONSUMER_KEY', 'ck_balenco_data_pilot'),
    'cja_consumer_secret' => env('BDP_WC_CONSUMER_SECRET', 'cs_balenco_data_pilot'),

    // Direct Shofy database push settings. Fill these in .env of the Data Pilot app.
    'shofy_db' => [
        'host' => env('SHOFY_DB_HOST', '127.0.0.1'),
        'port' => env('SHOFY_DB_PORT', '3306'),
        'database' => env('SHOFY_DB_DATABASE', ''),
        'username' => env('SHOFY_DB_USERNAME', ''),
        'password' => env('SHOFY_DB_PASSWORD', ''),
    ],
    'shofy_created_by_id' => env('SHOFY_CREATED_BY_ID', 1),
    'shofy_created_by_type' => env('SHOFY_CREATED_BY_TYPE', 'Botble\ACL\Models\User'),
    'shofy_product_model' => env('SHOFY_PRODUCT_MODEL', 'Botble\Ecommerce\Models\Product'),
    'shofy_color_attribute_set_id' => env('SHOFY_COLOR_ATTRIBUTE_SET_ID', 1),
    'shofy_size_attribute_set_id' => env('SHOFY_SIZE_ATTRIBUTE_SET_ID', 2),
    'shofy_update_existing_by_sku' => env('SHOFY_UPDATE_EXISTING_BY_SKU', true),

    // Live sync safety + local image handling.
    // Parent product images/gallery are managed manually in Shofy/Balenco.
    // Variation/color images can be copied into the Shofy public storage path before sync.
    'shofy_public_storage_path' => env('SHOFY_PUBLIC_STORAGE_PATH', ''),
    'shofy_variation_image_subdir' => env('SHOFY_VARIATION_IMAGE_SUBDIR', 'products/balenco-data-pilot'),
    'copy_variation_images_to_shofy' => env('BDP_COPY_VARIATION_IMAGES_TO_SHOFY', true),
    'sync_default_status' => env('BDP_SYNC_DEFAULT_STATUS', 'draft'),
    'shofy_headers' => [
        'ID', 'Name', 'Description', 'Slug', 'URL', 'SKU', 'Categories', 'Status', 'Is Featured', 'Brand',
        'Product Collections', 'Labels', 'Taxes', 'Image', 'Images', 'Price', 'Product Attributes', 'Import Type',
        'Is Variation Default', 'Stock Status', 'With Storehouse Management', 'Quantity', 'Allow Checkout When Out Of Stock',
        'Sale Price', 'Start Date Sale Price', 'End Date Sale Price', 'Weight', 'Length', 'Wide', 'Height',
        'Cost Per Item', 'Barcode', 'Content', 'Tags', 'Generate License Code', 'Minimum Order Quantity',
        'Maximum Order Quantity', 'Order',
    ],

    // Balenco Data Pilot image behavior
    // Keep parent/gallery images manual by default. Variation/color images remain controlled separately.
    'parent_gallery_image_sync' => env('BDP_PARENT_GALLERY_IMAGE_SYNC', false),
    'push_parent_featured_image' => env('BDP_PUSH_PARENT_FEATURED_IMAGE', false),
    'push_parent_gallery_images' => env('BDP_PUSH_PARENT_GALLERY_IMAGES', false),
];
