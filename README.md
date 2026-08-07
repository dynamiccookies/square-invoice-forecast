# Square Invoice Forecast

A standalone PHP dashboard that uses live data from Square's API to forecast outstanding and scheduled invoice payments by due date and month.

## Features

- Retrieves live invoices and business-location data directly from Square.
- Includes scheduled, unpaid, and partially paid invoices.
- Expands every outstanding deposit, milestone, installment, or balance into an individual payment row.
- Summarizes past-due, due-today, future-scheduled, and total-outstanding amounts.
- Displays expected payments by month with payment counts and calendar-year dividers.
- Filters the table by one or multiple selected chart bars.
- Sorts payment details by invoice number, client, event date, or payment due date.
- Links each invoice number to its Square invoice page or the Square Invoices dashboard.
- Highlights only past-due and due-today payment-date cells.
- Retrieves the displayed business name from Square's Locations API.

## Requirements

- PHP 8.1 or newer
- PHP cURL extension
- HTTPS-enabled web hosting
- Square access token with `INVOICES_READ` and `MERCHANT_PROFILE_READ`

## Installation

1. Download or clone this repository into an internal HTTPS-enabled web directory.
2. Copy `config.example.php` to `config.php`.
3. Replace the placeholder in `config.php` with your Square access token.
4. Adjust the environment, location IDs, API version, or timezone if needed.
5. Open the directory URL; the application entry point is `index.php`.

Example:

```php
'square_access_token' => 'YOUR_PRODUCTION_ACCESS_TOKEN',
```

`square_location_ids` is optional. Leave it empty to include every active Square location.

## Configuration security

`config.php` is intentionally excluded by `.gitignore`. Do not commit a populated configuration file or expose it through the web server. The included `.htaccess` blocks direct browser access to `config.php` on Apache and LiteSpeed hosting.

## Report logic

- Remaining payment amount = `computed_amount_money - total_completed_amount_money`.
- Invoice outstanding amount = the sum of all remaining payment requests on that invoice.
- Event date uses Square's `sale_or_service_date`.
- Paid, canceled, failed, and refunded invoices are excluded.
- Each page load requests current data from Square and displays an as-of timestamp.
- No database or scheduled job is required.

## Operational note

The dashboard displays customer and receivable information. Restrict access at the web-server, VPN, or network level. It does not include an application login.

## License

MIT
