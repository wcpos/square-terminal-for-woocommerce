# Square Terminal for WooCommerce

Square Terminal for WooCommerce connects WooCommerce orders to Square Terminal devices so in-person card payments can be requested from WooCommerce and completed on paired Square hardware.

## Language

**Square Account Connection**:
The merchant's authorization configuration that lets WooCommerce call Square APIs for one Square seller account. For the initial version this means merchant-entered Square access tokens and related identifiers, not OAuth.
_Avoid_: OAuth connection, Square login, account link

**Access Token**:
A Square credential entered by the merchant in WooCommerce settings and used server-side to call Square APIs for either Sandbox or Production.
_Avoid_: API key, client secret, password

**Location ID**:
The Square business location where Terminal payments are created and reported. A Square Account Connection has one active Location ID for the initial version.
_Avoid_: store ID, merchant ID, branch

**Terminal Device**:
A Square Terminal hardware unit paired to this plugin through a Square Device Code and identified by a Square `device_id`. A Terminal Device belongs to the configured Location ID for the initial version.
_Avoid_: reader, register, card machine

**Device Code**:
A short-lived Square pairing code created by WooCommerce and entered on a Square Terminal to connect that Terminal Device to the plugin.
_Avoid_: pairing token, activation code, login code

**Storefront Checkout**:
The customer-facing WooCommerce checkout flow for remote online shoppers. Square Terminal is available here only when explicitly enabled because the buyer usually cannot access the merchant's Terminal Device.
_Avoid_: web checkout when discussing in-person POS flows

**POS Checkout**:
A staff-driven in-person WooCommerce checkout where the merchant can choose a nearby Terminal Device to collect payment. Square Terminal is primarily intended for POS Checkout.
_Avoid_: frontend checkout, online checkout

**Terminal Checkout**:
A Square request sent from WooCommerce to a Terminal Device asking it to collect payment for one WooCommerce order. A Terminal Checkout is tracked by Square `checkout_id` and linked back to WooCommerce by `reference_id`.
_Avoid_: payment intent, reader checkout, charge request

**Terminal Payment**:
The Square payment produced when a Terminal Checkout completes successfully. A WooCommerce order may store one or more Square `payment_ids` from the completed Terminal Checkout.
_Avoid_: transaction only, checkout, intent

**Square Identifier**:
A Square-generated ID stored on a WooCommerce order so future operations or support can trace the corresponding Terminal Checkout and Terminal Payment.
_Avoid_: refund handle, local transaction key

**Payment Completion Signal**:
Evidence that a Terminal Checkout reached a final state. Webhook events are authoritative; polling exists to keep the user interface responsive.
_Avoid_: browser confirmation, client success

**Payment Request Token**:
A short-lived signed token that authorizes one client session to perform payment actions for a specific WooCommerce order when a normal WordPress nonce or logged-in staff session is not reliable.
_Avoid_: nonce, API key, checkout secret

**Payment Log**:
A chronological record of Square Terminal events, API calls, webhook outcomes, and order-state decisions that helps merchants and support understand exactly what happened during payment collection.
_Avoid_: debug dump, hidden trace

**Hosted Test Site**:
An online WordPress/WooCommerce environment used to validate real Square Sandbox callbacks without a local tunnel. `dev-pro.wcpos.com` is the primary Hosted Test Site for Square Terminal development; `demo.wcpos.com` is reserved for final smoke checks.
_Avoid_: production, local dev, tunnel target

**Webhook Signature Key**:
The Square webhook verification secret used to prove incoming webhook events came from Square.
_Avoid_: webhook secret token, nonce

## Example dialogue

Dev: “Should we build Square OAuth now?”
Domain expert: “No. For the first version, the merchant creates Square credentials and enters the Access Token, Location ID, and Webhook Signature Key in WooCommerce.”
Dev: “So a Square Account Connection is settings-based, not an OAuth login?”
Domain expert: “Correct for the initial version.”
Dev: “How does a Terminal Device get connected?”
Domain expert: “WooCommerce creates a Device Code, the merchant enters it on the Square Terminal, and the paired Terminal Device is stored by its Square device_id.”
Dev: “Can the browser mark the order paid after polling?”
Domain expert: “No. Polling may show progress, but a verified Square webhook is the authoritative Payment Completion Signal.”
Dev: “Should shoppers see Square Terminal on ordinary checkout?”
Domain expert: “Not by default. Square Terminal is primarily for POS Checkout; Storefront Checkout must be explicitly enabled.”
Dev: “Can WooCommerce refund Square Terminal payments in the first version?”
Domain expert: “No. The first version collects payments only, but it stores Square Identifiers so refund support can be added later.”
Dev: “Can a client create or cancel a Terminal Checkout with only an order ID?”
Domain expert: “No. Payment actions require staff capability, an order key, or a Payment Request Token.”
Dev: “How will merchants understand payment failures?”
Domain expert: “The plugin maintains a Payment Log and order notes showing each meaningful Square Terminal step and outcome.”
Dev: “Where do real Square webhooks get tested?”
Domain expert: “Use dev-pro.wcpos.com as the primary Hosted Test Site; keep wcpos.local for fast local checks and demo.wcpos.com for final smoke checks.”
