# Ispluka bKash Integration

## Scope

This implementation adds a provider-isolated bKash Checkout flow on top of the existing Ispluka payment-intent architecture.

The flow is:

1. Admin creates an idempotent `bkash` payment intent.
2. Server creates the bKash payment.
3. Customer completes the bKash hosted checkout.
4. bKash returns to the callback URL with a `paymentID`.
5. The callback endpoint ignores untrusted payment status/amount values and queries bKash server-to-server.
6. If the payment is not already completed and the callback signals success, the server executes the payment and queries bKash again.
7. The verified amount, transaction ID and final status are passed into the provider-neutral callback processor.
8. The Ispluka payment intent becomes `paid`, `failed` or `cancelled` according to the verified provider response.
9. Legacy `tbl_transactions` settlement remains a separate explicit operation.

bKash documents Checkout, Tokenized, Webhook/Instant Notification and other Online Payment products. This code uses the Tokenized Checkout API path and keeps the API base URL configurable. Verify the merchant-specific base URL and product contract supplied by bKash during onboarding before production activation.

## Runtime configuration

Do not commit bKash credentials to Git.

Preferred environment variables:

- `ISPLUKA_BKASH_BASE_URL`
- `ISPLUKA_BKASH_APP_KEY`
- `ISPLUKA_BKASH_APP_SECRET`
- `ISPLUKA_BKASH_USERNAME`
- `ISPLUKA_BKASH_PASSWORD`
- `ISPLUKA_BKASH_TIMEOUT` (optional, default 20)
- `ISPLUKA_BKASH_VERIFY_TLS` (optional, default true)

Alternatively, the real `config.php` can define these `$config` keys without changing the existing MySQL credentials:

```php
$config['ispluka_bkash_base_url'] = 'YOUR_BKASH_BASE_URL';
$config['ispluka_bkash_app_key'] = 'YOUR_APP_KEY';
$config['ispluka_bkash_app_secret'] = 'YOUR_APP_SECRET';
$config['ispluka_bkash_username'] = 'YOUR_USERNAME';
$config['ispluka_bkash_password'] = 'YOUR_PASSWORD';
$config['ispluka_bkash_timeout'] = 20;
$config['ispluka_bkash_verify_tls'] = true;
```

Use the exact base URL provided for the bKash product/environment assigned to the merchant. Do not assume a sandbox URL is suitable for production.

## API actions

All actions are routed through the existing API entry point:

`system/api.php?r=isplukaBkash&action=...`

### Create payment intent

`POST action=create_intent`

Fields:

- `idempotency_key`
- `amount`
- `customer_id` (optional)
- `metadata` (optional JSON)

Requires `billing.manage` and an active Ispluka tenant membership.

### Create bKash payment

`POST action=create`

Field:

- `intent_id`

The intent must belong to the active tenant, use provider `bkash`, and still be `pending`.

The response contains the bKash provider response. The frontend should use the returned hosted checkout URL supplied by the bKash response.

### Execute payment

`POST action=execute`

Field:

- `paymentID`

Requires `billing.manage`.

### Query payment

`POST action=query`

Field:

- `paymentID`

Requires `billing.manage`.

### Callback

`GET` or `POST`:

`system/api.php?r=isplukaBkash&action=callback&paymentID=...&status=...`

The callback is intentionally public. It must not trust browser-supplied amount or success status. The server uses `paymentID` to perform a bKash server-side query and, when appropriate, execution followed by another query.

The callback resolves the tenant from the stored payment intent and never accepts a tenant ID from the request.

## Security rules

- Credentials remain runtime-only.
- TLS verification is enabled by default.
- Callback payment status and amount are not trusted from the browser.
- Verified `paymentID` must match the callback `paymentID`.
- Payment amount must match the Ispluka intent amount exactly to two decimal places.
- A gateway transaction ID cannot be reused by another tenant-local intent.
- Replayed completed callbacks are idempotent.
- Paid and cancelled intents cannot be downgraded.
- Legacy billing is not automatically written by the callback.
- Existing MySQL connection credentials are unchanged.

## Read-only checks

Configuration check:

```bash
php install/ispluka_bkash_config_check.php
```

Gateway adapter smoke test:

```bash
php install/ispluka_gateway_adapter_smoke.php
```

Neither command contacts the live bKash gateway or writes payment data.

## Production activation checklist

Before enabling production payments:

1. Obtain the merchant's production bKash credentials and product/base URL from bKash.
2. Configure them outside Git.
3. Run the configuration checker.
4. Confirm PHP cURL is enabled.
5. Confirm HTTPS/TLS certificate validation is working.
6. Create a small test payment in the bKash-approved environment.
7. Verify create → customer checkout → callback → execute/query → paid intent.
8. Replay the callback and verify no duplicate payment intent transition occurs.
9. Verify an amount mismatch is rejected.
10. Verify a duplicate gateway transaction ID is rejected.
11. Only after payment verification is accepted, use the separate legacy settlement endpoint when legacy billing must be updated.

No production credentials or live gateway calls are included in the repository.
