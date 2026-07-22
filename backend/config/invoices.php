<?php

return [
    'default_currency' => env('INVOICE_CURRENCY', 'GBP'),
    'default_vat_rate' => (float) env('INVOICE_DEFAULT_VAT', 20),
    'number_prefix' => env('INVOICE_NUMBER_PREFIX', 'INV'),
    'invoice_disk' => env('INVOICE_STORAGE_DISK', 'invoices'),
    'completion_report_disk' => env('COMPLETION_REPORT_STORAGE_DISK', env('INVOICE_STORAGE_DISK', 'invoices')),
    'receipt_disk' => env('EXPENSE_RECEIPT_DISK', 'receipts'),
    'receipt_max_size_kb' => (int) env('EXPENSE_RECEIPT_MAX_KB', 5120),
    'proof_disk' => env('DELIVERY_PROOF_STORAGE_DISK', 'receipts'),
    'proof_max_size_kb' => (int) env('DELIVERY_PROOF_MAX_KB', 8192),
    'auction_assessment_disk' => env('AUCTION_ASSESSMENT_STORAGE_DISK', env('FILESYSTEM_DISK', 'local')),
];
