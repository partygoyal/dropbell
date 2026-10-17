# Dropbell homepage

Responsive Next.js App Router storefront prototype using React state for search, department filters, location selection, and shopping bag controls. Cart and location are saved in browser local storage.

## Run locally

```sh
npm install
npm run dev
```

Open http://127.0.0.1:3000.

## Production check

```sh
npm run build
npm start
```

The earlier static concept remains in `dist/`. Next.js source is in `app/`, with the approved logo in `public/assets/`. Product data is illustrative; checkout is not connected. Images and fonts load from Unsplash and Google Fonts.

## Shopping demo

- `/cart`: edit quantities, remove items, apply `DROP10`, and review costs.
- `/checkout`: validated address, demo delivery and payment preferences, and order review.
- `/order/[id]`: session-saved confirmation and manually advanced simulated tracking.

Add items before opening checkout. Demo delivery costs ₹25 and is free when the items subtotal reaches ₹300. `DROP10` discounts 10% (maximum ₹50) when the items subtotal reaches ₹100. Amounts are calculated in integer paise.

No order is sent to a server, no payment is processed, and no delivery is booked. Demo order/address details are saved only in this tab's session storage; the bag remains in local storage. Confirmation links need the originating browser tab's saved order. Tracking advances only when you press the preview button.

## Account demo

- `/login`: enter a sample Indian mobile number and use the fixed demo code `123456`. No SMS is sent and phone ownership is not verified.
- `/account`: profile editing and sign-out.
- `/account/addresses`: add, edit, delete, and set a default saved address; checkout can select these addresses.
- `/account/orders`: orders placed while signed in, with detail/tracking links and reorder at current catalogue prices.
- `/help`: FAQs about the demo experience.

Accounts, saved addresses, and orders stay in this tab's session storage. Signing in with another number selects a separate demo profile and history; this is UI state, not production authentication or access control. Existing guest orders remain accessible through their confirmation links and are not automatically assigned to an account.
